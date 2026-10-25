<?php

require_once __DIR__ . '/../../../shared/api/_bootstrap.php';
require_once __DIR__ . '/../../ContentBasedFiltering/lib/CBFEngine.php';
require_once __DIR__ . '/../../ContentBasedFiltering/lib/CBFData.php';
require_once __DIR__ . '/../lib/Careers.php';
require_once __DIR__ . '/../../AuditLog/lib/AuditChange.php';

$pdo = Database::get();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    Auth::requireLogin();
    $includeInactive = isset($_GET['all']);
    if ($includeInactive) {
        Rbac::requireAccess('career', 'limited');
    }

    $sql = 'SELECT p.id, p.title_enc, p.holland_code_enc, p.description_enc, p.original_holland_code_enc, p.careers, p.related_strands, p.status, p.college_id, c.code AS college_code, c.name AS college_name
            FROM programs p JOIN colleges c ON c.id = p.college_id';
    if (!$includeInactive) {
        $sql .= " WHERE p.status = 'Active'";
    }
    $sql .= ' ORDER BY c.code, p.id';
    $rows = $pdo->query($sql)->fetchAll();

    $programs = array_map(fn($r) => [
        'id' => (int) $r['id'],
        'title' => Crypto::dec($r['title_enc']),
        'hollandCode' => Crypto::dec($r['holland_code_enc']),
        // Code before the validator's revision, kept for audit (null = none recorded).
        'originalHollandCode' => $r['original_holland_code_enc'] !== null ? Crypto::dec($r['original_holland_code_enc']) : null,
        'description' => $r['description_enc'] !== null ? Crypto::dec($r['description_enc']) : '',
        // Raw list staff maintain (may be empty); `careerOptions` is what a
        // student can actually pick: that list, or the program title if empty.
        'careers' => Careers::parse($r['careers']),
        'careerOptions' => Careers::effective(Careers::parse($r['careers']), Crypto::dec($r['title_enc'])),
        'status' => $r['status'],
        // Optional reference information for staff; not used in the recommendations.
        'relatedStrands' => CBFData::parseTextArray($r['related_strands']),
        'collegeId' => (int) $r['college_id'],
        'collegeCode' => $r['college_code'],
        'collegeName' => $r['college_name'],
    ], $rows);

    $response = ['programs' => $programs];
    // Whether the signed-in user may add, edit or deactivate programs (Full on Career Profile Input), so the
    // page shows those controls only to them.
    $sessionUser = Auth::requireLogin();
    $response['canEdit'] = Rbac::accessLevel('career', Rbac::roleKey($sessionUser)) === 'full';

    if (isset($_GET['meta'])) {
        $response['colleges'] = array_map(fn($r) => [
            'id' => (int) $r['id'],
            'code' => $r['code'],
            'name' => $r['name'],
        ], $pdo->query('SELECT id, code, name FROM colleges ORDER BY code')->fetchAll());
        // Feature vocabularies from config/cbf.php, so the admin forms and the
        // Career Electives Worksheet use exactly the lists the CBF engine compares on.
        $cbfConfig = CBFEngine::config();
        $response['strands'] = $cbfConfig['strands'];
        $response['electiveClusters'] = $cbfConfig['elective_clusters'];
        $response['collegeElectives'] = $cbfConfig['college_electives'];
    }

    if (isset($_GET['stats'])) {
        // Institution-wide count of students whose CBF candidates (courses most similar to
        // their RIASEC profile) include each program; latest result per student.
        $counts = $pdo->query(
            "SELECT unnest(COALESCE(cbf_program_ids, ARRAY[top_program_id])) AS program_id, COUNT(*) AS cnt FROM (
                SELECT DISTINCT ON (student_id) student_id, top_program_id, cbf_program_ids
                FROM recommendations WHERE source_assessment_id IN (SELECT id FROM assessments WHERE is_latest = TRUE)
                ORDER BY student_id, computed_at DESC
             ) latest GROUP BY 1"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $totalStudentsWithRecs = (int) $pdo->query('SELECT COUNT(DISTINCT student_id) FROM recommendations')->fetchColumn();
        $response['stats'] = [
            'matchedCounts' => array_map('intval', $counts),
            'totalStudentsWithRecommendations' => $totalStudentsWithRecs,
        ];
    }

    jsonResponse($response);
}

function readProgramInput(array $body): array
{
    $title = trim((string) ($body['title'] ?? ''));
    $hollandCode = strtoupper(trim((string) ($body['hollandCode'] ?? '')));
    $description = trim((string) ($body['description'] ?? ''));
    $collegeId = (int) ($body['collegeId'] ?? 0);
    $status = ($body['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

    if ($title === '') {
        jsonResponse(['success' => false, 'error' => 'Program title is required.'], 400);
    }
    if (!preg_match('/^[RIASEC]{3}$/', $hollandCode)) {
        jsonResponse(['success' => false, 'error' => 'Holland code must be exactly 3 distinct letters from R, I, A, S, E, C.'], 400);
    }
    if (count(array_unique(str_split($hollandCode))) !== 3) {
        jsonResponse(['success' => false, 'error' => 'Holland code letters must be distinct.'], 400);
    }
    if (mb_strlen($description) > 1000) {
        jsonResponse(['success' => false, 'error' => 'Description must be 1000 characters or fewer.'], 400);
    }
    if ($collegeId <= 0) {
        jsonResponse(['success' => false, 'error' => 'A college is required.'], 400);
    }
    // null = the caller didn't send a careers list at all (PUT then leaves the
    // stored list untouched rather than wiping it).
    $careers = null;
    try {
        if (array_key_exists('careers', $body)) {
            $careers = Careers::normalize($body['careers']);
        }
    } catch (InvalidArgumentException $e) {
        jsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
    }

    // Related SHS strands: optional reference information, not used in the recommendations.
    // null = not sent (PUT then keeps the stored list). Only the strands in config/cbf.php are allowed.
    $relatedStrands = null;
    if (array_key_exists('relatedStrands', $body)) {
        $allowed = CBFEngine::config()['strands'];
        $given = is_array($body['relatedStrands']) ? $body['relatedStrands'] : [];
        $relatedStrands = array_values(array_intersect($allowed, array_map(fn($x) => strtoupper(trim((string) $x)), $given)));
        if (count($relatedStrands) !== count(array_unique(array_map(fn($x) => strtoupper(trim((string) $x)), $given)))) {
            jsonResponse(['success' => false, 'error' => 'Related SHS strands must be from: ' . implode(', ', $allowed) . '.'], 400);
        }
    }

    return [$title, $hollandCode, $description, $collegeId, $status, $careers, $relatedStrands];
}

if ($method === 'POST') {
    $user = Rbac::requireAccess('career', 'full');
    $body = readJsonBody();

    if (($body['type'] ?? '') === 'toggleActive') {
        $id = (int) ($body['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT status FROM programs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            jsonResponse(['success' => false, 'error' => 'Program not found.'], 404);
        }
        $newStatus = $row['status'] === 'Active' ? 'Inactive' : 'Active';
        $pdo->prepare('UPDATE programs SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$newStatus, $id]);
        AuditLogger::log(
            $user['id'], $user['role'], $newStatus === 'Active' ? 'activate_program' : 'deactivate_program',
            'program', (string) $id
        );
        jsonResponse(['success' => true, 'status' => $newStatus]);
    }

    [$title, $hollandCode, $description, $collegeId, $status, $careers, $relatedStrands] = readProgramInput($body);

    $exists = $pdo->prepare('SELECT id FROM colleges WHERE id = ?');
    $exists->execute([$collegeId]);
    if (!$exists->fetch()) {
        jsonResponse(['success' => false, 'error' => 'Unknown college.'], 400);
    }

    $insert = $pdo->prepare(
        'INSERT INTO programs (college_id, title_enc, holland_code_enc, description_enc, careers, related_strands, status) VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $insert->execute([$collegeId, Crypto::enc($title), Crypto::enc($hollandCode), $description !== '' ? Crypto::enc($description) : null, Careers::toLiteral($careers ?? []),
        CBFData::textArrayLiteral($relatedStrands ?? []), $status]);
    $id = (int) $insert->fetchColumn();

    AuditLogger::log($user['id'], $user['role'], 'create_program', 'program', (string) $id, $title);

    jsonResponse(['success' => true, 'id' => $id]);
}

if ($method === 'PUT') {
    $user = Rbac::requireAccess('career', 'full');
    $body = readJsonBody();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'error' => 'Missing program id.'], 400);
    }

    $existing = $pdo->prepare(
        'SELECT p.title_enc, p.holland_code_enc, p.description_enc, p.careers, p.related_strands, p.status, c.name AS college_name
         FROM programs p JOIN colleges c ON c.id = p.college_id WHERE p.id = ?'
    );
    $existing->execute([$id]);
    $old = $existing->fetch();
    if (!$old) {
        jsonResponse(['success' => false, 'error' => 'Program not found.'], 404);
    }

    [$title, $hollandCode, $description, $collegeId, $status, $careers, $relatedStrands] = readProgramInput($body);

    $collegeCheck = $pdo->prepare('SELECT name FROM colleges WHERE id = ?');
    $collegeCheck->execute([$collegeId]);
    $collegeName = $collegeCheck->fetchColumn();
    if ($collegeName === false) {
        jsonResponse(['success' => false, 'error' => 'Unknown college.'], 400);
    }
    $oldCareers = Careers::parse($old['careers']);
    $oldStrands = CBFData::parseTextArray($old['related_strands']);
    $before = [
        'title' => Crypto::dec($old['title_enc']), 'hollandCode' => Crypto::dec($old['holland_code_enc']),
        'college' => $old['college_name'], 'status' => $old['status'],
        'description' => $old['description_enc'] !== null ? Crypto::dec($old['description_enc']) : '',
        'careers' => implode('; ', $oldCareers),
        'relatedStrands' => implode(', ', $oldStrands),
    ];
    $after = [
        'title' => $title, 'hollandCode' => $hollandCode, 'college' => $collegeName, 'status' => $status,
        'description' => $description, 'careers' => implode('; ', $careers ?? $oldCareers),
        'relatedStrands' => implode(', ', $relatedStrands ?? $oldStrands),
    ];

    $update = $pdo->prepare(
        'UPDATE programs SET college_id = ?, title_enc = ?, holland_code_enc = ?, description_enc = ?, careers = COALESCE(?::text[], careers),
                related_strands = COALESCE(?::text[], related_strands), status = ?, updated_at = NOW() WHERE id = ?'
    );
    $update->execute([$collegeId, Crypto::enc($title), Crypto::enc($hollandCode), $description !== '' ? Crypto::enc($description) : null, $careers !== null ? Careers::toLiteral($careers) : null,
        $relatedStrands !== null ? CBFData::textArrayLiteral($relatedStrands) : null, $status, $id]);

    // What changed, old and new, shown on the Audit Log page (e.g. "Holland code: SEA -> ESA").
    AuditLogger::log($user['id'], $user['role'], 'update_program', 'program', (string) $id, AuditChange::detail(
        'Program: ' . $title, $before, $after,
        ['title' => 'Title', 'hollandCode' => 'Holland code', 'college' => 'College', 'status' => 'Status',
         'description' => 'Description', 'careers' => 'Careers', 'relatedStrands' => 'Related SHS strands']
    ));

    // The CBF compares every student with every program's Holland code, so a new code changes saved
    // results. Recompute them now, so no student or staff member sees results built on the old code.
    if ($hollandCode !== $before['hollandCode']) {
        $result = CBFData::recomputeAll($pdo);
        AuditLogger::log($user['id'], $user['role'], 'recompute_recommendations', 'program', (string) $id,
            "Holland code of {$title} changed {$before['hollandCode']} -> {$hollandCode}: {$result['recomputed']} students' recommendations recomputed"
            . ($result['failed'] ? ", {$result['failed']} failed" : ''));
        jsonResponse(['success' => true, 'hollandCodeChanged' => true, 'previousHollandCode' => $before['hollandCode'],
            'hollandCode' => $hollandCode, 'recomputed' => $result['recomputed'], 'recomputeFailed' => $result['failed']]);
    }

    jsonResponse(['success' => true, 'hollandCodeChanged' => false]);
}

jsonResponse(['error' => 'Method not allowed'], 405);
