<?php
/**
 * Prediction Module (WEKA J48, binary top-three inputs): the installed model file, the
 * conversion of raw RIASEC scores, the predictions and probabilities, and failure handling.
 *
 *   php tests/prediction_module_test.php
 *
 * Uses the installed config/prediction_model.json (exported from models/ProfilePath_J48_Binary_393.model)
 * and tests/fixtures/weka_predictions_ProfilePath_J48_Binary_393.csv, which is Weka 3.9.6's own
 * command-line output for the 20 possible inputs (see tools/weka/README.md). Score values below
 * are made-up test inputs, not student records.
 */
require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/RecommendationPipeline.php';

$passed = 0;
$failed = 0;
function check(string $label, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? '  PASS: ' : '  FAIL: ') . $label . "\n";
}
function throwsInvalid(callable $f): bool
{
    try {
        $f();
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
}
function throwsUnexpected(callable $f): bool
{
    try {
        $f();
    } catch (UnexpectedValueException $e) {
        return true;
    }
    return false;
}
$s = fn(int $r, int $i, int $a, int $sc, int $e, int $c) => ['R' => $r, 'I' => $i, 'A' => $a, 'S' => $sc, 'E' => $e, 'C' => $c];

echo "=== Installed model: header and class order ===\n";
$model = PredictionModel::load(PredictionModel::MODEL_FILE);
check('format is the Weka binary lookup', ($model['format'] ?? null) === PredictionModel::LOOKUP_FORMAT);
check('inputs R, I, A, S, E, C in that order, numeric', $model['attributes'] === ['R', 'I', 'A', 'S', 'E', 'C'] && $model['attribute_type'] === 'numeric');
check('class attribute "Actual Course" at index 6', $model['class_attribute'] === 'Actual Course' && $model['class_index'] === 6);
check('J48 -C 0.1 -M 10, exported with Weka 3.9.6', $model['classifier'] === 'weka.classifiers.trees.J48' && $model['options'] === '-C 0.1 -M 10' && $model['weka_version'] === '3.9.6');
// The 28 class labels in the order Weka read from the .model header (spelling exactly as trained).
$wekaOrder = ['BA Communication', 'B Multimedia Arts', 'BS Computer Science', 'BS Information Technology', 'BS Biology',
    'BS Medical Technology', 'BS Pharmacy', 'BS Physical Therapy', 'BS Psychology', 'BS Nursing', 'BS Accountancy',
    'BSBA Major in Financial Management', 'BSBA Major in Operations Management', 'BS Hospitality Management', 'BS Tourism Management',
    'BS Marketing', 'BS Architecture', 'BS Chemical Engineering', 'BS Civil Engineering', 'BS Mechanical Engineering',
    'BS Electrical Engineering', 'BS Electronics Engineering', 'BS Industrial Engineering', 'BS Computer Engineering',
    'BS Aeronautical Engineering', 'BS Aviation Management', 'BS Marine Engineering', 'BS Marine Transportation'];
check('trained on 393 records (top-3 binary)', $model['training']['records'] === 393 && $model['feature_encoding'] === 'top3-binary');
check('28 class labels in the model header order', $model['class_labels'] === $wekaOrder);
$modelFile = __DIR__ . '/../models/ProfilePath_J48_Binary_393.model';
check('exported from this exact .model file (SHA-256 matches)', is_file($modelFile) && hash_file('sha256', $modelFile) === $model['model_sha256']);
$titles = array_map(fn($p) => $p[1], require __DIR__ . '/../db/program_codes.php');
$mapped = array_map(fn($l) => PredictionModel::resolveLabel($l, $titles), $model['class_labels']);
check('every class label maps to a program (short names via config/course_aliases.php)', !in_array(null, $mapped, true));
check('... and to 28 different programs', count(array_unique($mapped)) === 28);
check('programs outside the 28 classes get no probability (4 programs)', count(array_diff($titles, $mapped)) === 4);

echo "\n=== Raw scores -> six 0/1 inputs (top three = 1) ===\n";
check('R40 I45 A15 S20 E25 C38 -> I, C, R', PredictionModel::binaryInputs($s(40, 45, 15, 20, 25, 38)) === [1, 1, 0, 0, 0, 1]);
check('all six equal -> R, I, A (ties keep R,I,A,S,E,C order)', PredictionModel::binaryInputs($s(30, 30, 30, 30, 30, 30)) === [1, 1, 1, 0, 0, 0]);
check('tie for 3rd between E and C -> E', PredictionModel::binaryInputs($s(10, 40, 12, 35, 20, 20)) === [0, 1, 0, 1, 1, 0]);
check('tie for 1st among four types -> first three in R,I,A,S,E,C order', PredictionModel::binaryInputs($s(10, 44, 10, 44, 44, 44)) === [0, 1, 0, 1, 1, 0]);
check('numeric strings from the database are accepted', PredictionModel::binaryInputs(['R' => '40', 'I' => '45', 'A' => '15', 'S' => '20', 'E' => '25', 'C' => '38']) === [1, 1, 0, 0, 0, 1]);
mt_srand(2026);
$alwaysThree = true;
for ($n = 0; $n < 2000; $n++) {
    $bits = PredictionModel::binaryInputs($s(...array_map(fn() => mt_rand(10, 50), range(1, 6))));
    $alwaysThree = $alwaysThree && array_sum($bits) === 3;
}
check('exactly three 1s for 2000 random valid score sets', $alwaysThree);
check('missing score -> rejected (no 0 substituted)', throwsInvalid(fn() => PredictionModel::binaryInputs(['R' => 40, 'I' => 45, 'A' => 15, 'S' => 20, 'E' => 25])));
check('null score -> rejected', throwsInvalid(fn() => PredictionModel::binaryInputs(['R' => 40, 'I' => null, 'A' => 15, 'S' => 20, 'E' => 25, 'C' => 38])));
check('non-numeric score -> rejected', throwsInvalid(fn() => PredictionModel::binaryInputs(['R' => 'abc', 'I' => 45, 'A' => 15, 'S' => 20, 'E' => 25, 'C' => 38])));
check('score below 10 -> rejected', throwsInvalid(fn() => PredictionModel::binaryInputs($s(9, 45, 15, 20, 25, 38))));
check('score above 50 -> rejected', throwsInvalid(fn() => PredictionModel::binaryInputs($s(51, 45, 15, 20, 25, 38))));
check('non-whole score -> rejected', throwsInvalid(fn() => PredictionModel::binaryInputs(['R' => 25.5, 'I' => 45, 'A' => 15, 'S' => 20, 'E' => 25, 'C' => 38])));
check('no assessment at all -> rejected', throwsInvalid(fn() => PredictionModel::binaryInputs(null)));

echo "\n=== Predictions and probabilities (installed model) ===\n";
$okSums = true;
$okArgmax = true;
foreach (PredictionModel::allBinaryProfiles() as $bits) {
    $p = PredictionModel::predictBinary($model, array_combine(CBFEngine::DIMENSIONS, array_map(fn($b) => $b ? 40 : 20, $bits)));
    $probs = array_column($p['distribution'], 'probability');
    $okSums = $okSums && abs(array_sum($probs) - 1) < 1e-9 && count($probs) === 28;
    // Weka (J48 leaf): the first class with the highest probability; the display's first entry is the same.
    $first = array_search(max($probs), $probs, true);
    $okArgmax = $okArgmax && $p['predicted'] === $model['class_labels'][$first] && $p['ranked'][0]['label'] === $p['predicted'];
}
check('all 20 inputs: 28 probabilities summing to 1', $okSums);
check('all 20 inputs: predicted class = first highest probability = first displayed', $okArgmax);
// Leaf shares from the tree (correct/total at the leaf): hand-checkable.
$ise = PredictionModel::predictBinary($model, $s(20, 40, 20, 40, 40, 20));
check('I,S,E: leaf R<=0, C<=0, I>0, A<=0 (33 students, 18 not Nursing) -> BS Nursing, 15/33 = 0.4545',
    $ise['predicted'] === 'BS Nursing' && abs($ise['ranked'][0]['probability'] - 15 / 33) < 1e-12);
$rae = PredictionModel::predictBinary($model, $s(40, 20, 40, 20, 40, 20));
check('R,A,E: leaf R>0, A>0, E>0 (27 students) -> BS Architecture, 5/27 = 0.1852',
    $rae['predicted'] === 'BS Architecture' && abs($rae['ranked'][0]['probability'] - 5 / 27) < 1e-12);
$predicted = array_unique(array_map(fn($b) => PredictionModel::predictBinary($model, array_combine(CBFEngine::DIMENSIONS, array_map(fn($x) => $x ? 40 : 20, $b)))['predicted'], PredictionModel::allBinaryProfiles()));
check('the tree predicts 7 different courses over the 20 inputs (7 leaves)', count($predicted) === 7);

echo "\n=== Application vs Weka's own output, all 20 inputs ===\n";
$fixture = __DIR__ . '/fixtures/weka_predictions_ProfilePath_J48_Binary_393.csv';
exec('php ' . escapeshellarg(__DIR__ . '/../db/verify_prediction_model.php') . ' ' . escapeshellarg($fixture) . ' 2>&1', $out, $code);
check('db/verify_prediction_model.php: 20 of 20 identical (class and all 28 probabilities)', $code === 0 && str_contains(implode("\n", $out), '20 of 20 inputs identical'));
// The comparison must notice a difference: shift 1e-10 between two classes of one input.
$tampered = $model;
$nonZero = array_keys(array_filter($model['profiles']['0,0,1,1,1,0']['distribution'], fn($v) => $v > 1e-6));
$tampered['profiles']['0,0,1,1,1,0']['distribution'][$nonZero[0]] += 1e-10;
$tampered['profiles']['0,0,1,1,1,0']['distribution'][$nonZero[1]] -= 1e-10;
$tmp = tempnam(sys_get_temp_dir(), 'pm');
file_put_contents($tmp, json_encode($tampered));
$out = [];
exec('PREDICTION_MODEL_FILE=' . escapeshellarg($tmp) . ' php ' . escapeshellarg(__DIR__ . '/../db/verify_prediction_model.php') . ' ' . escapeshellarg($fixture) . ' 2>&1', $out, $code);
check('... and reports a model that differs from Weka by 1e-10', $code === 1 && str_contains(implode("\n", $out), '19 of 20 inputs identical'));

echo "\n=== A damaged or mismatched model file is refused ===\n";
$bad = function (callable $edit) use ($model): bool {
    $m = $model;
    $edit($m);
    return throwsUnexpected(fn() => PredictionModel::validateLookup($m));
};
check('inputs in another order', $bad(function (&$m) { $m['attributes'] = ['I', 'R', 'A', 'S', 'E', 'C']; }));
check('nominal instead of numeric inputs', $bad(function (&$m) { $m['attribute_type'] = 'nominal'; }));
check('a profile missing', $bad(function (&$m) { unset($m['profiles']['1,1,1,0,0,0']); }));
check('a probability missing', $bad(function (&$m) { array_pop($m['profiles']['1,1,1,0,0,0']['distribution']); }));
check('probabilities not summing to 1', $bad(function (&$m) { $m['profiles']['1,1,1,0,0,0']['distribution'][0] += 0.01; }));
check('predicted class not matching the labels', $bad(function (&$m) { $m['profiles']['1,1,1,0,0,0']['predicted'] = 'BS Nursing'; }));
check('class labels repeated', $bad(function (&$m) { $m['class_labels'][1] = $m['class_labels'][0]; }));

echo "\n=== Failure never stops the content-based recommendation ===\n";
$broken = tempnam(sys_get_temp_dir(), 'pm');
$m = $model;
unset($m['profiles']['1,1,1,0,0,0']);
file_put_contents($broken, json_encode($m));
putenv('PREDICTION_MODEL_FILE=' . $broken);
$progs = [];
foreach (require __DIR__ . '/../db/program_codes.php' as $i => [, $title, , $code]) {
    $progs[] = ['id' => $i + 1, 'title' => $title, 'hollandCode' => $code];
}
$run = RecommendationPipeline::run(['riasec' => $s(40, 45, 15, 20, 25, 38)], $progs, [10]);
check('broken model file -> prediction unavailable with a reason', $run['prediction']['status'] === 'unavailable' && $run['prediction']['reason'] !== null && $run['predictionIds'] === []);
check('... while the CBF Best RIASEC Match is still produced', $run['cbf']['status'] === 'available' && $run['bestRiasecMatchIds'] !== []);
putenv('PREDICTION_MODEL_FILE=/nonexistent/model.json');
$run = RecommendationPipeline::run(['riasec' => $s(40, 45, 15, 20, 25, 38)], $progs, [10]);
check('no model file -> unavailable, CBF unaffected', $run['prediction']['status'] === 'unavailable' && $run['bestRiasecMatchIds'] !== []);
putenv('PREDICTION_MODEL_FILE');

echo "\n=== The preferred course is never an input ===\n";
$a = RecommendationPipeline::run(['riasec' => $s(40, 45, 15, 20, 25, 38)], $progs, [10]);
$b = RecommendationPipeline::run(['riasec' => $s(40, 45, 15, 20, 25, 38)], $progs, [3]);
$c = RecommendationPipeline::run(['riasec' => $s(40, 45, 15, 20, 25, 38)], $progs, []);
check('same scores, different or no Preferred Course -> same prediction', $a['predictionIds'] === $b['predictionIds'] && $b['predictionIds'] === $c['predictionIds'] && $a['predictionIds'] !== []);
$params = array_map(fn($p) => $p->getName(), (new ReflectionMethod('PredictionModel', 'predictBinary'))->getParameters());
check('predictBinary() takes only the model, the scores and the scoring rules', $params === ['model', 'scores', 'config']);

echo "\n=== Summary: $passed passed, $failed failed ===\n";
exit($failed ? 1 : 0);
