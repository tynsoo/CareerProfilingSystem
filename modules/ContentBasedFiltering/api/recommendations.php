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
 *                     intended"), shown on its own, to staff only. Students never receive it.
 *   finalBestMatch    null: needs an adviser-approved combination rule
 * Staff (studentId=...) also get `cbf`: every course's cosine calculation, and
 *   predictionDetail  the Prediction Module (WEKA J48) run on the student's latest RIASEC scores
 *                     with the current model: predicted course, top three, every class
 *                     probability, the six 0/1 inputs and the model version. Probabilities say
 *                     how past students with the same top three were distributed over their
 *                     intended courses; they are not suitability percentages. Students never
 *                     receive it. If it fails, status is 'unavailable' and the CBF result is
 *                     returned as usual.
 */

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../lib/CBFData.php';
require_once __DIR__ . '/../../CareerDataset/lib/Careers.php';
require_once __DIR__ . '/../../CareerProfiling/lib/RiasecProfile.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
$pdo = Database::get();

$requestedStudentId = isset($_GET['studentId']) ? (int) $_GET['studentId'] : null;
if ($requestedStudentId !== null && $requestedStudentId !== (int) $user['id']) {
    Rbac::requireAccess('recommendations', 'full');
    $studentId = $requestedStudentId;
} else {
    if ($user['role'] !== 'student') {
        jsonResponse(['error' => 'studentId is required for non-student accounts.'], 400);
    }
    $studentId = (int) $user['id'];
}
$isStaff = $user['role'] !== 'student';

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

// The student's ranked interests and ordered Holland code (modules/CareerProfiling/lib/RiasecProfile.php), from the
// assessment this recommendation was computed from.
$aStmt = $pdo->prepare('SELECT score_r, score_i, score_a, score_s, score_e, score_c FROM assessments WHERE id = ?');
$aStmt->execute([(int) $row['source_assessment_id']]);
$aRow = $aStmt->fetch();
$studentScores = $aRow ? ['R' => $aRow['score_r'], 'I' => $aRow['score_i'], 'A' => $aRow['score_a'],
    'S' => $aRow['score_s'], 'E' => $aRow['score_e'], 'C' => $aRow['score_c']] : null;
$profile = RiasecProfile::derive($studentScores);
// Exact ordered-code match: an explanatory note only. It never changes cosine values, the order or
// the CBF result, and is given only when the student's code is not decided by tied scores (null otherwise).
$exactMatch = fn(string $code) => $profile['valid'] && !$profile['codeProvisional'] ? strtoupper($code) === $profile['code'] : null;

$scores = json_decode($row['scores'], true) ?: [];
usort($scores, fn($a, $b) => [$b['cosine'], $a['programId']] <=> [$a['cosine'], $b['programId']]);
$cosineOf = array_column($scores, 'cosine', 'programId');
$preferredId = $row['stated_program_id'] !== null ? (int) $row['stated_program_id'] : null;

// Results saved by this CBF version store each course's dot product; older results
// (before the CBF revision) should be recomputed (php db/recompute_recommendations.php).
$isCurrent = isset($scores[0]['dotProduct']);
// Best RIASEC Match: courses tied at the highest cosine similarity (CBF only).
$bestIds = $isCurrent && $row['cbf_program_ids'] !== null
    ? CBFData::parseIntArray($row['cbf_program_ids'])
    : array_map('intval', array_column(CBFEngine::selectCandidates($scores, 'cosine'), 'programId'));
// Alternative Courses: courses at the next-highest cosine similarity (CBF only).
$alternativeIds = array_map('intval', array_column(CBFEngine::selectAlternatives($scores, 'cosine'), 'programId'));
$candidateIds = array_merge($bestIds, $alternativeIds);
// Prediction model (WEKA): NULL = did not run; {} = ran but predicted no active program.
$predictionIds = CBFData::parseIntArray($row['prediction_program_ids'] ?? null);
$showPrediction = $isStaff;   // the Prediction Module is staff-only
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
            // The approved code's letters, in order, by name (e.g. IRC -> Investigative, Realistic, Conventional).
            'hollandCodeNames' => array_values(array_map(fn($l) => RiasecProfile::NAMES[$l] ?? $l, str_split(strtoupper(Crypto::dec($r['holland_code_enc']))))),
            'exactOrderedCodeMatch' => $exactMatch(Crypto::dec($r['holland_code_enc'])),
            'description' => $r['description_enc'] !== null ? Crypto::dec($r['description_enc']) : '',
            // Careers that fit this program (its title if staff haven't listed any).
            'careers' => Careers::effective(Careers::parse($r['careers']), $title),
            'collegeCode' => $r['college_code'],
            'collegeName' => $r['college_name'],
            // Programs are never hard-deleted (see modules/CareerDataset/api/programs.php), so an existing result
            // still resolves after staff deactivate one; this lets the UI flag it.
            'isActive' => $r['status'] === 'Active',
            // Cosine similarity between the student's RIASEC profile and this course (0-1).
            'cosineSimilarity' => isset($cosineOf[(int) $r['id']]) ? (float) $cosineOf[(int) $r['id']] : null,
        ];
    }
}
// Inside each tie group, the counselors' 1-2-3 letter ranking decides the order (CBFEngine::letterOrderMatch);
// results saved before this rule are ordered the same way here. Membership and cosine values are unchanged.
if ($profile['valid']) {
    $codes = array_map(fn($p) => (string) ($p['hollandCode'] ?? ''), $programs);
    $bestIds = CBFEngine::orderByLetterRank($bestIds, $codes, $studentScores);
    $alternativeIds = CBFEngine::orderByLetterRank($alternativeIds, $codes, $studentScores);
    foreach ($programs as $id => $p) {
        $programs[$id]['letterOrderMatch'] = CBFEngine::letterOrderMatch($studentScores, $codes[$id]);
    }
}
$programsFor = fn(array $ids) => array_values(array_filter(array_map(fn($id) => $programs[$id] ?? null, $ids)));

$response = [
    'hasRecommendation' => true,
    'computedAt' => $row['computed_at'],
    // CBF (RIASEC profile + course RIASEC codes + cosine similarity) only:
    'bestRiasecMatch' => $programsFor($bestIds),         // tied courses all listed, in 1-2-3 letter order
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
    // Ranked interests, ordered code, ties and flat-profile checks. When the profile is flat or
    // ties decide the code, the CBF still lists every tied program; the page adds a guidance note.
    'riasecProfile' => $profile,
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
    ];
}
if ($isStaff) {
    $response['cbf'] = [
        'bestRiasecMatch' => array_column($programsFor($bestIds), 'title'),
        'alternatives' => array_column($programsFor($alternativeIds), 'title'),
        'studentVector' => $scores[0]['studentVector'] ?? null,
        'studentMagnitude' => $scores[0]['studentMagnitude'] ?? null,
        'results' => array_map(fn($sc) => [
            'title' => $programs[(int) $sc['programId']]['title'] ?? (string) $sc['programId'],
            'hollandCode' => $sc['hollandCode'] ?? ($programs[(int) $sc['programId']]['hollandCode'] ?? null),
            'courseVector' => $sc['courseVector'] ?? null,
            'dotProduct' => $sc['dotProduct'] ?? null,
            'courseMagnitude' => $sc['courseMagnitude'] ?? null,
            'cosineSimilarity' => (float) $sc['cosine'],
        ], $scores),
    ];
}
if ($isStaff) {
    $response['predictionDetail'] = predictionDetail($pdo, $row, $programs, $bestIds, $alternativeIds);
}
jsonResponse($response);

/**
 * Prediction Module for staff (modules/ContentBasedFiltering/lib/PredictionModel.php). Never throws: any failure becomes
 * status 'unavailable' with a reason, so the CBF recommendation is always shown.
 */
function predictionDetail(PDO $pdo, array $row, array $programs, array $bestIds, array $alternativeIds): array
{
    $unavailable = fn(string $reason) => ['status' => 'unavailable', 'reason' => $reason];
    if (empty(CBFEngine::config()['decision_tree']['enabled'])) {
        return $unavailable('The Prediction Module is switched off (config/cbf.php decision_tree.enabled).');
    }
    try {
        $model = PredictionModel::load();
        if ($model === null) {
            return $unavailable('No prediction model is installed.');
        }
        if (($model['format'] ?? null) !== PredictionModel::LOOKUP_FORMAT) {
            return $unavailable('The installed model has no class probabilities; export it with tools/weka/ExportJ48Lookup.');
        }
        $stmt = $pdo->prepare('SELECT score_r, score_i, score_a, score_s, score_e, score_c FROM assessments WHERE id = ?');
        $stmt->execute([(int) $row['source_assessment_id']]);
        $a = $stmt->fetch();
        if (!$a) {
            return $unavailable('The RIASEC result for this recommendation was not found.');
        }
        $scores = ['R' => $a['score_r'], 'I' => $a['score_i'], 'A' => $a['score_a'], 'S' => $a['score_s'], 'E' => $a['score_e'], 'C' => $a['score_c']];
        $result = PredictionModel::predictBinary($model, $scores);
    } catch (InvalidArgumentException $e) {
        return $unavailable('Invalid RIASEC result: ' . $e->getMessage());
    } catch (UnexpectedValueException $e) {
        error_log('[prediction] ' . $e->getMessage());
        return $unavailable($e->getMessage() . ' Re-export it with tools/weka/ExportJ48Lookup.');
    } catch (Throwable $e) {
        error_log('[prediction] ' . $e->getMessage());
        return $unavailable('The Prediction Module could not run.');
    }

    // Class labels -> programs (config/course_aliases.php covers the dataset's spellings).
    $titles = array_column($programs, 'title', 'id');
    $course = function (array $c) use ($titles, $bestIds, $alternativeIds): array {
        $title = PredictionModel::resolveLabel($c['label'], array_values($titles));
        $id = $title !== null ? array_search($title, $titles, true) : false;
        return [
            'label' => $c['label'],                 // exactly as in the model
            'title' => $title,                      // null if no program matches
            'programId' => $id === false ? null : $id,
            'probability' => $c['probability'],
            'inBestRiasecMatch' => $id !== false && in_array($id, $bestIds, true),
            'inAlternatives' => $id !== false && in_array($id, $alternativeIds, true),
        ];
    };
    $ranked = array_map($course, $result['ranked']);
    foreach ($ranked as $i => &$c) {
        // Equal probabilities keep the model's class order (display rule); flag them.
        $c['tied'] = count(array_filter($ranked, fn($o) => abs($o['probability'] - $c['probability']) < 1e-12)) > 1;
    }
    unset($c);
    $predicted = $result['predicted'] === null ? null : $ranked[array_search($result['predicted'], array_column($ranked, 'label'), true)];
    return [
        'status' => $predicted === null ? 'no_prediction' : 'available',
        'reason' => $predicted === null ? 'The model gives no class for this input.' : null,
        'inputs' => array_combine(CBFEngine::DIMENSIONS, $result['inputs']),
        'predicted' => $predicted,
        'top3' => array_slice($ranked, 0, 3),
        'distribution' => array_map($course, $result['distribution']),   // model's class order
        'model' => [
            'version' => $model['version'] ?? null,
            'wekaVersion' => $model['weka_version'] ?? null,
            'classifier' => trim(($model['classifier'] ?? '') . ' ' . ($model['options'] ?? '')),
            'classAttribute' => $model['class_attribute'] ?? null,
            'classes' => count($model['class_labels']),
            'sha256' => $model['model_sha256'] ?? null,
            // What the model is, as recorded at export; null = not recorded (shown as unknown, never filled in).
            'featureEncoding' => $model['feature_encoding'] ?? null,
            'featureEncodingDescription' => $model['feature_encoding_description'] ?? null,
            'targetDefinition' => $model['target_definition'] ?? null,
            'trainingRecords' => $model['training']['records'] ?? null,
            'trainingDataVersion' => $model['training']['data_version'] ?? null,
            'evaluationProtocol' => $model['evaluation']['protocol'] ?? null,
        ],
        'savedPredictionDiffers' => $predicted !== null && ($row['prediction_program_ids'] ?? null) !== null
            && CBFData::parseIntArray($row['prediction_program_ids']) !== ($predicted['programId'] !== null ? [$predicted['programId']] : []),
    ];
}
