<?php
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';

// Verbatim from riasec-assessment.html's QUESTION_BANK (the existing 60-item bank).
$questionBank = [
    'R' => [
        'I enjoy repairing broken equipment.',
        'I like working with tools and machinery.',
        'I enjoy building or assembling things.',
        'I prefer practical tasks over theoretical discussions.',
        'I like working outdoors.',
        'I enjoy operating vehicles or heavy equipment.',
        'I am interested in construction projects.',
        'I enjoy fixing electrical or mechanical problems.',
        'I like working with plants, animals, or natural resources.',
        'I prefer physical activities to office work.',
    ],
    'I' => [
        'I enjoy solving complex problems.',
        'I like conducting experiments.',
        'I enjoy learning about scientific discoveries.',
        'I like analyzing information before making decisions.',
        'I enjoy researching topics in depth.',
        'I like finding patterns in data.',
        'I enjoy mathematics and logical reasoning.',
        'I am curious about how things work.',
        'I enjoy investigating causes of problems.',
        'I like studying subjects that require critical thinking.',
    ],
    'A' => [
        'I enjoy drawing, painting, or designing.',
        'I like expressing myself creatively.',
        'I enjoy writing stories, poems, or articles.',
        'I like creating original ideas.',
        'I enjoy performing music, dance, or theater.',
        'I prefer flexible environments over strict rules.',
        'I enjoy designing visual materials.',
        'I like exploring different forms of art.',
        'I enjoy imaginative activities.',
        'I prefer creative projects to routine tasks.',
    ],
    'S' => [
        'I enjoy helping people solve their problems.',
        'I like teaching others new skills.',
        'I enjoy working in teams.',
        'I like volunteering for community activities.',
        "I enjoy listening to people's concerns.",
        'I am interested in counseling or mentoring others.',
        'I enjoy caring for people.',
        'I like participating in group discussions.',
        'I enjoy motivating others.',
        'I prefer jobs that involve interacting with people.',
    ],
    'E' => [
        'I enjoy leading a group.',
        'I like convincing others to support my ideas.',
        'I enjoy organizing projects and events.',
        'I like taking initiative in group situations.',
        'I enjoy setting goals and achieving them.',
        'I am interested in starting a business.',
        'I enjoy making decisions for a team.',
        'I like negotiating with others.',
        'I enjoy selling products or services.',
        'I am comfortable taking risks to achieve success.',
    ],
    'C' => [
        'I enjoy organizing files and records.',
        'I like following established procedures.',
        'I enjoy working with numbers and data.',
        'I prefer clear instructions when completing tasks.',
        'I enjoy planning schedules and timelines.',
        'I like maintaining accurate records.',
        'I enjoy administrative or office work.',
        'I pay attention to details.',
        'I enjoy tasks that require precision.',
        'I prefer structured environments over unpredictable ones.',
    ],
];

$pdo = Database::get();

$existing = (int) $pdo->query('SELECT COUNT(*) FROM assessment_questions')->fetchColumn();
if ($existing > 0) {
    echo "Questions already seeded ($existing rows). Skipping.\n";
    exit;
}

$stmt = $pdo->prepare(
    'INSERT INTO assessment_questions (dimension, question_text_enc, order_index, is_active) VALUES (?, ?, ?, TRUE)'
);

foreach ($questionBank as $dimension => $questions) {
    foreach ($questions as $i => $text) {
        $stmt->execute([$dimension, Crypto::enc($text), $i + 1]);
    }
}

$count = $pdo->query('SELECT COUNT(*) FROM assessment_questions')->fetchColumn();
echo "Questions seeded. Total rows: $count\n";
