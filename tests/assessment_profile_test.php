<?php
/**
 * Scoring, question-set versions, ordered Holland codes and ties, flat profiles, the CBF's
 * unchanged results and the Prediction Module's encoding checks. No database.
 *
 *   php tests/assessment_profile_test.php
 *
 * SYNTHETIC TEST DATA ONLY: made-up questions, answers and scores, not student records.
 */
require_once __DIR__ . '/../modules/CareerProfiling/lib/AssessmentVersion.php';
require_once __DIR__ . '/../modules/CareerProfiling/lib/RiasecProfile.php';
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/RecommendationPipeline.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS: ' : '  FAIL: ') . $label . "\n";
}
function throwsMsg(callable $f, string $class = InvalidArgumentException::class): ?string
{
    try {
        $f();
    } catch (Throwable $e) {
        return $e instanceof $class ? $e->getMessage() : null;
    }
    return null;
}
$s = fn(int $r, int $i, int $a, int $sc, int $e, int $c) => ['R' => $r, 'I' => $i, 'A' => $a, 'S' => $sc, 'E' => $e, 'C' => $c];

echo "=== Scoring: responses 1-5 -> raw totals -> stored 10-50 ===\n";
$ok = true;
for ($raw = 5; $raw <= 25; $raw++) {
    $ok = $ok && QuestionBank::scaleScore($raw, 5) === 2 * $raw;
}
check('5 questions per type: stored = raw x 2 for every raw total 5-25', $ok);
check('5 per type: all "1" -> 10, all "5" -> 50', QuestionBank::scaleScore(5, 5) === 10 && QuestionBank::scaleScore(25, 5) === 50);
$ok = true;
for ($n = 3; $n <= 40; $n++) {
    $ok = $ok && QuestionBank::scaleScore($n, $n) === 10 && QuestionBank::scaleScore(5 * $n, $n) === 50;
    for ($raw = $n; $raw <= 5 * $n; $raw++) {
        // exact: 10 x raw / n, halves rounded up, compared with whole numbers only
        $v = QuestionBank::scaleScore($raw, $n);
        $ok = $ok && 2 * $n * $v <= 20 * $raw + $n && 20 * $raw + $n < 2 * $n * ($v + 1);
    }
}
check('3-40 questions per type: every raw total maps to 10 x raw / n rounded half up, 10-50', $ok);
check('10 per type: stored = raw', QuestionBank::scaleScore(37, 10) === 37);
check('4 per type: raw 5 -> 12.5 -> 13 (half up)', QuestionBank::scaleScore(5, 4) === 13);
check('3 per type: raw 4 -> 13.33 -> 13', QuestionBank::scaleScore(4, 3) === 13);
check('20 per type: 14.5 -> 15 and 28.5 -> 29 (floating-point defect fixed)', QuestionBank::scaleScore(29, 20) === 15 && QuestionBank::scaleScore(57, 20) === 29);

echo "\n=== Question-set versions and answers by question id ===\n";
$questions = [];
$id = 100;
foreach (['R', 'I', 'A', 'S', 'E', 'C'] as $d) {
    for ($k = 1; $k <= 5; $k++) {
        $questions[] = ['id' => $id++, 'dimension' => $d, 'text' => "Synthetic $d question $k"];
    }
}
$fp = AssessmentVersion::fingerprint($questions);
check('same questions -> same fingerprint', AssessmentVersion::fingerprint($questions) === $fp);
$edited = $questions;
$edited[3]['text'] .= ' (edited)';
check('a question\'s wording edited -> different set', AssessmentVersion::fingerprint($edited) !== $fp);
$reordered = $questions;
[$reordered[0], $reordered[1]] = [$reordered[1], $reordered[0]];
check('two questions swapped -> different set', AssessmentVersion::fingerprint($reordered) !== $fp);
$replaced = $questions;
$replaced[7]['id'] = 999;
check('a question replaced by another id -> different set', AssessmentVersion::fingerprint($replaced) !== $fp);

$answer = fn(array $qs, callable $value) => array_map(fn($q) => ['questionId' => $q['id'], 'answer' => $value($q)], $qs);
$values = ['R' => 4, 'I' => 5, 'A' => 1, 'S' => 2, 'E' => 3, 'C' => 4];
$all = $answer($questions, fn($q) => $values[$q['dimension']]);
$r = AssessmentVersion::score($questions, $all);
check('scored by question id: raw = 5 x answer, stored = raw x 2', $r['raw'] === $s(20, 25, 5, 10, 15, 20) && $r['scores'] === $s(40, 50, 10, 20, 30, 40));
$shuffled = $all;
mt_srand(7);
shuffle($shuffled);
check('answers sent in any order -> same scores; stored in the order shown', AssessmentVersion::score($questions, $shuffled)['scores'] === $r['scores']
    && array_column(AssessmentVersion::score($questions, $shuffled)['answers'], 'questionId') === array_column($questions, 'id'));
check('positional list of numbers (old page) -> rejected', throwsMsg(fn() => AssessmentVersion::score($questions, array_fill(0, 30, 3))) !== null);
check('one answer missing -> rejected', str_contains((string) throwsMsg(fn() => AssessmentVersion::score($questions, array_slice($all, 1))), '29 of 30'));
$dup = $all;
$dup[1]['questionId'] = $dup[0]['questionId'];
check('a question answered twice -> rejected', throwsMsg(fn() => AssessmentVersion::score($questions, $dup)) !== null);
$foreign = $all;
$foreign[0]['questionId'] = 5000;
check('an answer to a question not in this set -> rejected', throwsMsg(fn() => AssessmentVersion::score($questions, $foreign)) !== null);
foreach ([0, 6, '3', 2.5, null] as $bad) {
    $x = $all;
    $x[0]['answer'] = $bad;
    check('answer ' . var_export($bad, true) . ' -> rejected', throwsMsg(fn() => AssessmentVersion::score($questions, $x)) !== null);
}
check('answers scored against a different set (edited ids) -> rejected', throwsMsg(fn() => AssessmentVersion::score($replaced, $all)) !== null);
check('unbalanced set (one type with 4 questions) -> rejected', throwsMsg(fn() => AssessmentVersion::score(array_slice($questions, 1), array_slice($all, 1))) !== null);

echo "\n=== Ordered Holland code and ties ===\n";
$p = RiasecProfile::derive($s(40, 45, 15, 20, 25, 38));
check('I45 R40 C38 -> top1 I, top2 IR, code IRC, not provisional', $p['top1'] === 'I' && $p['top2'] === 'IR' && $p['code'] === 'IRC' && !$p['codeProvisional']);
check('ranking keeps all six with scores, highest first', array_column($p['ranking'], 'letter') === ['I', 'R', 'C', 'E', 'S', 'A'] && array_column($p['ranking'], 'score') === [45, 40, 38, 25, 20, 15]);
$p = RiasecProfile::derive($s(10, 40, 12, 35, 20, 20));
check('E and C tie for 3rd -> code ISE, provisional, thirdPlaceTie', $p['code'] === 'ISE' && $p['codeProvisional'] && $p['thirdPlaceTie'] && !$p['orderTie']);
check('... tie reported with both letters and the score', $p['ties'] === [['score' => 20, 'letters' => ['E', 'C'], 'positions' => [3, 4]]]);
check('... tied letters share competition rank 3', array_column($p['ranking'], 'rank', 'letter')['E'] === 3 && array_column($p['ranking'], 'rank', 'letter')['C'] === 3);
$p = RiasecProfile::derive($s(44, 44, 20, 30, 15, 12));
check('R and I tie for 1st -> code RIS, provisional by order only', $p['code'] === 'RIS' && $p['codeProvisional'] && $p['orderTie'] && !$p['thirdPlaceTie']);
$p = RiasecProfile::derive($s(45, 40, 35, 20, 20, 20));
check('tie only among 4th-6th -> code RIA not provisional', $p['code'] === 'RIA' && !$p['codeProvisional'] && count($p['ties']) === 1);
$p = RiasecProfile::derive($s(30, 30, 30, 30, 30, 30));
check('all six equal -> flat, provisional, not all-minimum', $p['flat'] && $p['codeProvisional'] && !$p['allMinimum'] && $p['notes'][0] === 'flat');
$p = RiasecProfile::derive($s(10, 10, 10, 10, 10, 10));
check('all answers "Dislike" (all 10) -> allMinimum and flat', $p['allMinimum'] && $p['flat'] && $p['notes'][0] === 'all_minimum');
check('missing score -> invalid with a reason, no value substituted', RiasecProfile::derive(['R' => 40, 'I' => 40, 'A' => 40, 'S' => 40, 'E' => 40])['valid'] === false);
check('score 0 (outside 10-50) -> invalid', RiasecProfile::derive($s(0, 40, 40, 40, 40, 40))['valid'] === false);
check('non-whole score -> invalid', RiasecProfile::derive(['R' => 20.5, 'I' => 40, 'A' => 40, 'S' => 40, 'E' => 40, 'C' => 40])['valid'] === false);
check('no result -> invalid', RiasecProfile::derive(null)['valid'] === false);
mt_srand(2026);
$same = true;
for ($n = 0; $n < 1000; $n++) {
    $sc = $s(...array_map(fn() => 10 + 2 * mt_rand(0, 20), range(1, 6)));   // even values: many ties
    $prof = RiasecProfile::derive($sc);
    $bits = PredictionModel::binaryInputs($sc);
    $same = $same && implode('', array_slice(RiasecProfile::order($sc), 0, 3)) === $prof['code']
        && CBFEngine::topRiasecLetters($sc, 3) === str_split($prof['code'])
        && array_keys(array_filter(array_combine(RiasecProfile::DIMENSIONS, $bits))) === array_values(array_intersect(RiasecProfile::DIMENSIONS, str_split($prof['code'])));
}
check('1000 random tied profiles: results page, CBF helper, export and Weka inputs use the same top three', $same);

echo "\n=== CBF calculation unchanged; ties and flat profiles ===\n";
$progs = [];
foreach (require __DIR__ . '/../db/program_codes.php' as $i => [, $title, , $code]) {
    $progs[] = ['id' => $i + 1, 'title' => $title, 'hollandCode' => $code];
}
$r = CBFEngine::compute($s(40, 45, 15, 20, 25, 38), $progs);
$levels = array_values(array_unique(array_column($r['results'], 'cosine')));
check('worked example: student vector [4, 4.5, 1.5, 2, 2.5, 3.8], |S| 7.9492', $r['studentVector'] === [4.0, 4.5, 1.5, 2.0, 2.5, 3.8] && $r['studentMagnitude'] === 7.9492);
check('worked example: similarity levels 0.8933, 0.7844, 0.7626 ... 0.4358 (unchanged)', array_slice($levels, 0, 3) === [0.8933, 0.7844, 0.7626] && end($levels) === 0.4358);
check('worked example: 11 programs tied (IRC, RIC, ICR) as Best RIASEC Match, 5 Alternatives', count($r['candidates']) === 11 && count($r['alternatives']) === 5);
$codes = array_column($progs, 'hollandCode', 'id');
check('... the tie includes different ordered codes with the same letters', count(array_unique(array_map(fn($id) => $codes[$id], $r['candidates']))) === 3);
$flat = CBFEngine::compute($s(30, 30, 30, 30, 30, 30), $progs);
check('flat profile: every program at cosine 0.7071, all 32 tied, no alternatives', count(array_unique(array_column($flat['results'], 'cosine'))) === 1
    && $flat['results'][0]['cosine'] === 0.7071 && count($flat['candidates']) === 32 && $flat['alternatives'] === []);
$min = CBFEngine::compute($s(10, 10, 10, 10, 10, 10), $progs);
check('all-minimum profile: same as flat (all 32 tied); the record is still scored, not excluded', count($min['candidates']) === 32);
check('invalid scores -> CBF refuses (no substitute)', throwsMsg(fn() => CBFEngine::compute(['R' => 40], $progs)) !== null);
$a = RecommendationPipeline::run(['riasec' => $s(40, 45, 15, 20, 25, 38)], $progs, [10], ['decision_tree' => ['enabled' => false]] + CBFEngine::config());
$b = RecommendationPipeline::run(['riasec' => $s(40, 45, 15, 20, 25, 38)], $progs, [], ['decision_tree' => ['enabled' => false]] + CBFEngine::config());
check('Preferred Course never changes the CBF result', $a['bestRiasecMatchIds'] === $b['bestRiasecMatchIds'] && array_column($a['cbf']['results'], 'cosine') === array_column($b['cbf']['results'], 'cosine'));

echo "\n=== Prediction Module: incompatible encodings are refused ===\n";
$model = PredictionModel::load(PredictionModel::MODEL_FILE);
check('installed model declares top3-binary, target and training metadata', $model['feature_encoding'] === 'top3-binary'
    && str_contains($model['target_definition'], 'intended/preferred') && $model['training']['records'] === 393);
$bad = function (callable $edit) use ($model): bool {
    $m = $model;
    $edit($m);
    return throwsMsg(fn() => PredictionModel::validateLookup($m), UnexpectedValueException::class) !== null;
};
check('declared raw-score encoding -> refused', $bad(function (&$m) { $m['feature_encoding'] = 'raw-scores'; }));
check('tree with a raw-score split (C <= 12) -> refused', $bad(function (&$m) { $m['tree'] = str_replace('C <= 0', 'C <= 12', $m['tree']); }));
check('tree with a negative split -> refused', $bad(function (&$m) { $m['tree'] = str_replace('R <= 0', 'R <= -1', $m['tree']); }));
$legacy = $model;
unset($legacy['feature_encoding'], $legacy['target_definition'], $legacy['training'], $legacy['evaluation'], $legacy['exporter_version']);
check('earlier export without encoding metadata but binary splits -> still accepted', throwsMsg(fn() => PredictionModel::validateLookup($legacy), UnexpectedValueException::class) === null);
check('12 split lines in the installed tree (6 splits), all at 0', count(PredictionModel::splitThresholds($model['tree'])) === 12
    && array_unique(array_column(PredictionModel::splitThresholds($model['tree']), 1)) === [0.0]);

echo "\n=== Summary: $passed passed, $failed failed ===\n";
exit($failed ? 1 : 0);
