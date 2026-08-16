<?php
require_once __DIR__ . '/../lib/Database.php';

$colleges = [
    ['CAS',   'College of Arts and Sciences'],
    ['CCIS',  'College of Computer and Information Sciences'],
    ['CHS',   'College of Health Sciences'],
    ['CN',    'College of Nursing'],
    ['ETYCB', 'School of Entrepreneurship, Trade, Hospitality, and Business'],
    ['MITL',  'Malayan Institute of Technology'],
    ['MIA',   'Malayan Institute of Aeronautics'],
    ['CMET',  'College of Maritime Education and Training'],
];

$pdo = Database::get();
$stmt = $pdo->prepare('INSERT INTO colleges (code, name) VALUES (?, ?) ON CONFLICT (code) DO NOTHING');

foreach ($colleges as [$code, $name]) {
    $stmt->execute([$code, $name]);
}

$count = $pdo->query('SELECT COUNT(*) FROM colleges')->fetchColumn();
echo "Colleges seeded. Total rows: $count\n";
