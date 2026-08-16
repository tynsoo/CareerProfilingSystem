<?php
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';

// Verbatim from RIASEC_Program_Crosswalk.docx.
$programs = [
    ['CAS',   'BA Communication', 'AES'],
    ['CAS',   'BS Multimedia Arts', 'AER'],
    ['CCIS',  'BS Computer Science', 'IRC'],
    ['CCIS',  'BS Information Technology', 'IRC'],
    ['CHS',   'BS Biology', 'IRS'],
    ['CHS',   'BS Medical Technology', 'IRC'],
    ['CHS',   'BS Pharmacy', 'ISC'],
    ['CHS',   'BS Physical Therapy', 'SIR'],
    ['CHS',   'BS Psychology', 'SIA'],
    ['CN',    'BS Nursing', 'SIR'],
    ['ETYCB', 'BS Accountancy', 'CEI'],
    ['ETYCB', 'BS Accounting Information System', 'CIE'],
    ['ETYCB', 'BS Business Administration Major in Financial Management', 'ECI'],
    ['ETYCB', 'BS Business Administration Major in Operations Management', 'EIC'],
    ['ETYCB', 'BS Business Administration Major in Sustainability Management', 'EIS'],
    ['ETYCB', 'BS Hospitality Management', 'ESC'],
    ['ETYCB', 'BS Tourism Management', 'EAS'],
    ['ETYCB', 'BS International Business', 'ECS'],
    ['ETYCB', 'BS Business Analytics with Artificial Intelligence', 'IEC'],
    ['ETYCB', 'BS Marketing', 'EAS'],
    ['MITL',  'BS Architecture', 'ARI'],
    ['MITL',  'BS Chemical Engineering', 'IRC'],
    ['MITL',  'BS Civil Engineering', 'RIC'],
    ['MITL',  'BS Mechanical Engineering', 'RIC'],
    ['MITL',  'BS Electrical Engineering', 'RIC'],
    ['MITL',  'BS Electronics Engineering', 'RIC'],
    ['MITL',  'BS Industrial Engineering', 'REC'],
    ['MITL',  'BS Computer Engineering', 'RIC'],
    ['MIA',   'BS Aeronautical Engineering', 'RIC'],
    ['MIA',   'BS Aviation Management', 'ERC'],
    ['CMET',  'BS Marine Engineering', 'RIC'],
    ['CMET',  'BS Marine Transportation', 'REC'],
];

$pdo = Database::get();
$collegeIds = $pdo->query('SELECT code, id FROM colleges')->fetchAll(PDO::FETCH_KEY_PAIR);

$existing = (int) $pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn();
if ($existing > 0) {
    echo "Programs already seeded ($existing rows). Skipping.\n";
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO programs (college_id, title_enc, holland_code_enc, status) VALUES (?, ?, ?, ?)'
);

foreach ($programs as [$collegeCode, $title, $hollandCode]) {
    if (!isset($collegeIds[$collegeCode])) {
        throw new RuntimeException("Unknown college code: $collegeCode — run seed_colleges.php first");
    }
    $stmt->execute([
        $collegeIds[$collegeCode],
        Crypto::enc($title),
        Crypto::enc($hollandCode),
        'Active',
    ]);
}

$count = $pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn();
echo "Programs seeded. Total rows: $count\n";
