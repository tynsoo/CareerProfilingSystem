<?php
/**
 * The student's latest recommendation (modules/ContentBasedFiltering/lib/RecommendationPipeline.php, computed when the
 * Career Electives Worksheet is submitted).
 *
 * Students get exactly what the results page shows:
 *   preferredCourse   the course the student selected (worksheet), shown on its own
 *   bestRiasecMatch   CBF: course(s) with the highest cosine similarity to the student's
 *                     RIASEC profile (ties listed together)
 *   alternatives      CBF: course(s) at the next-highest cosine similarity
 *   each course carries its cosine similarity (0-1), never a percentage
 *   prediction        the WEKA J48 model's course ("students with a similar RIASEC profile
 *                     chose"), shown on its own. Staff always get it; students only when
 *                     config/cbf.php decision_tree.show_to_students is true.
 *   finalBestMatch    null: needs an adviser-approved combination rule
 * Staff (studentId=...) also get `cbf`: every course's cosine calculation.
 */

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/CBFData.php';
require_once __DIR__ . '/../../CareerDataset/lib/Careers.php';
require_once __DIR__ . '/../../UserManagement/lib/StaffScope.php';
require_once __DIR__ . '/../../CareerProfiling/lib/RiasecInterpretation.php';
require_once __DIR__ . '/../lib/PredictionModel.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
$pdo = Database::get();

$requestedStudentId = isset($_GET['studentId']) ? (int) $_GET['studentId'] : null;
if ($user['role'] === 'student') {
    // A student only ever reads their own recommendation, whatever studentId the request carries.
    $studentId = (int) $user['id'];
} else {
    if ($requestedStudentId === null) {
        jsonResponse(['error' => 'studentId is required for non-student accounts.'], 400);
    }
    Rbac::requireAccess('recommendations', 'limited'); // Career recommendations (permission table): View Only sees them
    // Staff only see students in their sections (modules/UserManagement/lib/StaffScope.php); outside them, "not found".
    $scopeStmt = $pdo->prepare('SELECT strand, section FROM students WHERE user_id = ?');
    $scopeStmt->execute([$requestedStudentId]);
    $scopeRow = $scopeStmt->fetch();
    if (!$scopeRow || !StaffScope::allows(StaffScope::forUser($pdo, $user), $scopeRow['strand'], $scopeRow['section'])) {
        jsonResponse(['error' => 'Student not found.'], 404);
    }
    $studentId = $requestedStudentId;
}
$isStaff = $user['role'] !== 'student';
// The RIASEC scores behind the match (student vector, dot products) are assessment results (permission table).
$seesScores = $isStaff && Rbac::accessLevel('assessmentResults', Rbac::roleKey($user)) !== 'none';

try {
    $stmt = $pdo->prepare(
        'SELECT id, computed_at, stated_program_id, scores, source_assessment_id, source_worksheet_id, match_status, mismatch_reason,
                cbf_program_ids, final_program_ids, prediction_program_ids, model_version
         FROM recommendations WHERE student_id = ? AND source_assessment_id = (SELECT id FROM assessments WHERE student_id = recommendations.student_id AND is_latest = TRUE)
         ORDER BY computed_at DESC LIMIT 1'
    );
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();
} catch (PDOException $e) {
    // Most likely a database that has not been migrated yet (missing pipeline columns).
    error_log('[recommendations] ' . $e->getMessage());
    jsonResponse(['error' => 'Your results could not be loaded right now. Please try again later or contact the Guidance Office.',
        'setupHint' => 'Run: php db/migrate_add_recommendation_status.php && php db/migrate_add_recommendation_sets.php'], 500);
}

if (!$row) {
    jsonResponse(['hasRecommendation' => false]);
}

$electives = [];
$statedCareer = null;
if ($row['source_worksheet_id'] !== null) {
    $wsStmt = $pdo->prepare('SELECT electives, stated_career FROM worksheets WHERE id = ?');
    $wsStmt->execute([(int) $row['source_worksheet_id']]);
    $wsRow = $wsStmt->fetch();
    if ($wsRow) {
        $electives = CBFData::parseTextArray($wsRow['electives']);
        $statedCareer = $wsRow['stated_career'];
    }
}

$scores = json_decode($row['scores'], true) ?: [];
// Ties and groups are decided on the full-precision cosine (cosineExact, CBFEngine::TIE_EPSILON). Results saved before
// it was stored only have the 4-decimal value; they are grouped on that until recomputed (db/recompute_recommendations.php).
$hasExact = $scores && isset($scores[0]['cosineExact']);
$scores = array_map(fn($s) => $s + ['cosineFull' => (float) ($s['cosineExact'] ?? $s['cosine'])], $scores);
usort($scores, fn($a, $b) => abs($a['cosineFull'] - $b['cosineFull']) < CBFEngine::TIE_EPSILON
    ? $a['programId'] <=> $b['programId']
    : $b['cosineFull'] <=> $a['cosineFull']);
$cosineOf = array_column($scores, 'cosine', 'programId');
$preferredId = $row['stated_program_id'] !== null ? (int) $row['stated_program_id'] : null;

// Results saved by this CBF version store each course's dot product; older results
// (before the CBF revision) should be recomputed (php db/recompute_recommendations.php).
$isCurrent = isset($scores[0]['dotProduct']);
// Best RIASEC Match: courses tied at the highest cosine similarity (CBF only).
$bestIds = $isCurrent && $row['cbf_program_ids'] !== null
    ? CBFData::parseIntArray($row['cbf_program_ids'])
    : array_map('intval', array_column(CBFEngine::selectCandidates($scores, 'cosineFull'), 'programId'));
// Alternative Courses: courses at the next-highest cosine similarity after the Best RIASEC Match (CBF only). None when
// every program is in the Best RIASEC Match: no lower group is made up.
$rest = array_values(array_filter($scores, fn($s) => !in_array((int) $s['programId'], $bestIds, true)));
$alternativeIds = $bestIds ? array_map('intval', array_column(CBFEngine::selectCandidates($rest, 'cosineFull'), 'programId')) : [];
// How the Best RIASEC Match is tied: every valid program (the profile cannot put one program ahead of another) or a
// smaller group at the highest value.
$tie = [
    'bestCount' => count($bestIds),
    'programsCompared' => count($scores),
    'allProgramsTied' => count($bestIds) > 1 && count($bestIds) === count($scores),
    'tolerance' => CBFEngine::TIE_EPSILON,
    'fullPrecision' => $hasExact,
];
// The assessment this result was computed from, read here so every page explains the same record.
$assessmentStmt = $pdo->prepare('SELECT score_r, score_i, score_a, score_s, score_e, score_c FROM assessments WHERE id = ?');
$assessmentStmt->execute([(int) $row['source_assessment_id']]);
$sourceAssessment = $assessmentStmt->fetch();
$interpretation = RiasecInterpretation::interpret($sourceAssessment ? [
    'R' => $sourceAssessment['score_r'], 'I' => $sourceAssessment['score_i'], 'A' => $sourceAssessment['score_a'],
    'S' => $sourceAssessment['score_s'], 'E' => $sourceAssessment['score_e'], 'C' => $sourceAssessment['score_c'],
] : null);
$candidateIds = array_merge($bestIds, $alternativeIds);
// Prediction model (WEKA): NULL = did not run; {} = ran but predicted no active program.
$predictionIds = CBFData::parseIntArray($row['prediction_program_ids'] ?? null);
$showPrediction = $isStaff || !empty(CBFEngine::config()['decision_tree']['show_to_students']);
if ($showPrediction && $predictionIds) {
    $candidateIds = array_merge($candidateIds, $predictionIds);
}

$neededIds = array_merge($candidateIds, $preferredId !== null ? [$preferredId] : [], $isStaff ? array_column($scores, 'programId') : []);
$programs = [];
if ($neededIds = array_values(array_unique(array_map('intval', $neededIds)))) {
    $placeholders = implode(',', array_fill(0, count($neededIds), '?'));
    $programStmt = $pdo->prepare(
        "SELECT p.id, p.title_enc, p.holland_code_enc, p.description_enc, p.careers, p.status, c.code AS college_code, c.name AS college_name
         FROM programs p JOIN colleges c ON c.id = p.college_id WHERE p.id IN ($placeholders)"
    );
    $programStmt->execute($neededIds);
    foreach ($programStmt->fetchAll() as $r) {
        $title = Crypto::dec($r['title_enc']);
        $programs[(int) $r['id']] = [
            'id' => (int) $r['id'],
            'title' => $title,
            'hollandCode' => Crypto::dec($r['holland_code_enc']),
            'description' => $r['description_enc'] !== null ? Crypto::dec($r['description_enc']) : '',
            // Careers that fit this program (its title if staff haven't listed any).
            'careers' => Careers::effective(Careers::parse($r['careers']), $title),
            'collegeCode' => $r['college_code'],
            'collegeName' => $r['college_name'],
            // Programs are never hard-deleted (see api/programs.php), so an existing result
            // still resolves after staff deactivate one; this lets the UI flag it.
            'isActive' => $r['status'] === 'Active',
            // Cosine similarity between the student's RIASEC profile and this course (0-1).
            'cosineSimilarity' => isset($cosineOf[(int) $r['id']]) ? (float) $cosineOf[(int) $r['id']] : null,
        ];
    }
}
$programsFor = fn(array $ids) => array_values(array_filter(array_map(fn($id) => $programs[$id] ?? null, $ids)));

$response = [
    'hasRecommendation' => true,
    'computedAt' => $row['computed_at'],
    // CBF (RIASEC profile + course RIASEC codes + cosine similarity) only:
    'bestRiasecMatch' => $programsFor($bestIds),         // tied courses all listed, never tie-broken
    'alternatives' => $programsFor($alternativeIds),     // next-highest similarity
    // The student's own selection (Career Electives Worksheet), shown separately; it never
    // changes the CBF result.
    'preferredCourse' => $preferredId !== null && isset($programs[$preferredId])
        ? $programs[$preferredId] + ['inBestRiasecMatch' => in_array($preferredId, $bestIds, true)]
        : null,
    // The final Best Match (CBF + prediction model + worksheet) needs an adviser-approved
    // combination rule, so it is not computed.
    'finalBestMatch' => null,
    'sources' => ['cbf' => 'available', 'worksheet' => $preferredId !== null ? 'available' : 'empty',
        'prediction' => $predictionIds === null ? 'unavailable' : ($predictionIds ? 'available' : 'no_prediction')],
    'isComplete' => false,
    // What the student typed on the Career Worksheet, and the program it was linked to (if any).
    'statedProgramId' => $preferredId,
    'statedCareer' => $statedCareer,
    'electives' => $electives,
    'matchStatus' => $row['match_status'],
    'mismatchReason' => $row['mismatch_reason'],
    'needsRecompute' => !$isCurrent,
    'tie' => $tie,
    'assessmentId' => (int) $row['source_assessment_id'],
    // The student's profile from that same assessment (modules/CareerProfiling/lib/RiasecInterpretation.php).
    'interpretation' => $interpretation,
];
if (!$showPrediction) {
    $response['sources']['prediction'] = 'staff_only';
} else {
    // Source 3, on its own: never changes the Best RIASEC Match or the Preferred Course.
    $predicted = $predictionIds ? ($programs[$predictionIds[0]] ?? null) : null;
    $response['prediction'] = [
        'status' => $response['sources']['prediction'],
        'course' => $predicted ? $predicted + ['inBestRiasecMatch' => in_array($predicted['id'], $bestIds, true),
            'inAlternatives' => in_array($predicted['id'], $alternativeIds, true)] : null,
        'modelVersion' => $row['model_version'],
        'inputTie' => null,
    ];
    // The J48 model (features "top3") reads which three types are the student's top three. When types are tied at
    // the cutoff, CBFEngine::topRiasecLetters takes them in R, I, A, S, E, C order. The input is not changed here;
    // staff are shown which top-three sets the scores allow and what the model gives for each.
    $model = $isStaff && !$interpretation['uniqueTopSet'] && $interpretation['status'] !== 'incomplete' ? PredictionModel::load() : null;
    if ($model !== null && ($model['features'] ?? 'top3') === 'top3') {
        $s = $interpretation['scores'];
        $sorted = array_values($s);
        rsort($sorted);
        $third = $sorted[2];
        $required = array_values(array_filter(RiasecInterpretation::DIMENSIONS, fn($d) => $s[$d] > $third));
        $atCutoff = array_values(array_filter(RiasecInterpretation::DIMENSIONS, fn($d) => $s[$d] === $third));
        $need = 3 - count($required);
        $sets = [[]];
        foreach ($atCutoff as $d) {   // every way to take $need of the types tied at the cutoff
            foreach ($sets as $set) {
                if (count($set) < $need) {
                    $sets[] = array_merge($set, [$d]);
                }
            }
        }
        $active = CBFData::activePrograms($pdo);
        $titleOf = array_column($active, 'title', 'id');
        $used = CBFEngine::topRiasecLetters($s, 3);
        $displayOrder = array_column($interpretation['ranked'], 'letter');
        $outcomes = [];
        foreach (array_filter($sets, fn($set) => count($set) === $need) as $set) {
            $letters = array_values(array_intersect($displayOrder, array_merge($required, $set)));
            $ids = PredictionModel::predict($letters, $active, $model) ?? [];
            $outcomes[] = ['letters' => implode('', $letters), 'program' => $ids ? ($titleOf[$ids[0]] ?? null) : null,
                'usedBySystem' => count(array_diff($letters, $used)) === 0];
        }
        $response['prediction']['inputTie'] = [
            'rule' => 'Types tied at the top-three cutoff are taken in the order R, I, A, S, E, C (CBFEngine::topRiasecLetters); the scores do not choose between them.',
            'usedLetters' => implode('', $used),
            'outcomes' => $outcomes,
            'sameForAll' => count(array_unique(array_map(fn($o) => (string) $o['program'], $outcomes))) === 1,
        ];
    }
}
if ($isStaff) {
    $response['cbf'] = [
        'bestRiasecMatch' => array_column($programsFor($bestIds), 'title'),
        'alternatives' => array_column($programsFor($alternativeIds), 'title'),
        'studentVector' => $seesScores ? ($scores[0]['studentVector'] ?? null) : null,
        'studentMagnitude' => $seesScores ? ($scores[0]['studentMagnitude'] ?? null) : null,
        'results' => array_map(fn($sc) => [
            'title' => $programs[(int) $sc['programId']]['title'] ?? (string) $sc['programId'],
            'hollandCode' => $sc['hollandCode'] ?? ($programs[(int) $sc['programId']]['hollandCode'] ?? null),
            'courseVector' => $sc['courseVector'] ?? null,
            'dotProduct' => $seesScores ? ($sc['dotProduct'] ?? null) : null,
            'courseMagnitude' => $sc['courseMagnitude'] ?? null,
            'cosineSimilarity' => (float) $sc['cosine'],
        ], $scores),
    ];
}
jsonResponse($response);
