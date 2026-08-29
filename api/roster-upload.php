<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Rbac::requireAccess('rac', 'full');
$pdo = Database::get();

if (!isset($_FILES['roster']) || $_FILES['roster']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['success' => false, 'error' => 'No CSV file was uploaded.'], 400);
}

$currentAy = (string) $pdo->query("SELECT value FROM security_policies WHERE key = 'academicYear.current'")->fetchColumn();
if ($currentAy === '') {
    jsonResponse(['success' => false, 'error' => 'Set the current Academic Year in Security Configuration before uploading a roster.'], 400);
}

$handle = fopen($_FILES['roster']['tmp_name'], 'r');
if ($handle === false) {
    jsonResponse(['success' => false, 'error' => 'Could not read the uploaded file.'], 400);
}

$header = fgetcsv($handle);
if ($header === false) {
    fclose($handle);
    jsonResponse(['success' => false, 'error' => 'The CSV file is empty.'], 400);
}
$header = array_map(fn($h) => strtolower(trim((string) $h)), $header);

$colIndex = function (array $candidates) use ($header): ?int {
    foreach ($candidates as $c) {
        $i = array_search($c, $header, true);
        if ($i !== false) {
            return $i;
        }
    }
    return null;
};
$idxSchoolId = $colIndex(['school id', 'schoolid', 'student number']);
$idxFirstName = $colIndex(['first name', 'firstname']);
$idxLastName = $colIndex(['last name', 'lastname']);
$idxStrand = $colIndex(['strand']);
$idxSection = $colIndex(['section']);

if ($idxSchoolId === null || $idxFirstName === null || $idxLastName === null || $idxStrand === null || $idxSection === null) {
    fclose($handle);
    jsonResponse(['success' => false, 'error' => 'CSV must have columns: School ID, First Name, Last Name, Strand, Section.'], 400);
}

$validStrands = ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL'];
$rows = [];
$errors = [];
$lineNum = 1;
while (($line = fgetcsv($handle)) !== false) {
    $lineNum++;
    if (count(array_filter($line, fn($v) => trim((string) $v) !== '')) === 0) {
        continue; // skip blank lines
    }
    $schoolId = trim((string) ($line[$idxSchoolId] ?? ''));
    $firstName = trim((string) ($line[$idxFirstName] ?? ''));
    $lastName = trim((string) ($line[$idxLastName] ?? ''));
    $strand = strtoupper(trim((string) ($line[$idxStrand] ?? '')));
    $section = trim((string) ($line[$idxSection] ?? ''));

    if ($schoolId === '' || $firstName === '' || $lastName === '' || $section === '') {
        $errors[] = "Row $lineNum: missing a required value.";
        continue;
    }
    if (!in_array($strand, $validStrands, true)) {
        $errors[] = "Row $lineNum: invalid strand \"$strand\".";
        continue;
    }
    if (mb_strlen($section) > 20) {
        $errors[] = "Row $lineNum: section too long.";
        continue;
    }
    $rows[$schoolId] = [$schoolId, "$lastName, $firstName", $strand, $section];
}
fclose($handle);

if ($errors) {
    jsonResponse(['success' => false, 'error' => 'CSV had ' . count($errors) . ' invalid row(s).', 'details' => array_slice($errors, 0, 20)], 400);
}
if (!$rows) {
    jsonResponse(['success' => false, 'error' => 'No valid rows found in the CSV.'], 400);
}

$pdo->beginTransaction();
try {
    // Replace the current AY's roster wholesale — a re-upload is meant to
    // supersede the previous list for that period, not merge with it.
    $pdo->prepare('DELETE FROM assessment_roster WHERE academic_year = ?')->execute([$currentAy]);

    $insert = $pdo->prepare(
        'INSERT INTO assessment_roster (academic_year, school_id, name_enc, strand, section, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($rows as $r) {
        $insert->execute([$currentAy, $r[0], Crypto::enc($r[1]), $r[2], $r[3], $user['id']]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonResponse(['success' => false, 'error' => 'Failed to save the roster. Please try again.'], 500);
}

AuditLogger::log($user['id'], $user['role'], 'upload_roster', 'assessment_roster', $currentAy, count($rows) . ' student(s) for AY ' . $currentAy);

jsonResponse(['success' => true, 'count' => count($rows), 'academicYear' => $currentAy]);
