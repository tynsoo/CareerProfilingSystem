<?php
/**
 * Imports the decision tree trained in WEKA into the system.
 *
 *   php db/import_weka_tree.php path/to/j48_output.txt [--version "J48 v1 2026-10-07"] [--features top3|rank]
 *
 * --features says which inputs the tree was trained on (default top3 = x/blank for the top 3
 * types). Use rank for a tree trained on the 'rank' encoding of evaluation/model_experiment.py.
 *
 * In WEKA Explorer, after training J48, right-click the result -> "Save result buffer"
 * and pass that file here (only the "J48 pruned tree" block is read). The tree is
 * saved to config/prediction_model.json, which modules/ContentBasedFiltering/lib/PredictionModel.php runs for each
 * student. Then set 'decision_tree' => ['enabled' => true] in config/cbf.php and run
 * php db/recompute_recommendations.php.
 *
 * The script checks that every predicted course in the tree is one of the system's
 * programs, and prints the prediction for all 20 possible top-3 RIASEC combinations
 * so it can be compared with WEKA.
 */

require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/PredictionModel.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$file = $argv[1] ?? null;
if ($file === null || !is_file($file)) {
    exit("Usage: php db/import_weka_tree.php path/to/j48_output.txt [--version \"name\"]\n");
}
$version = 'J48 ' . date('Y-m-d');
$features = 'top3';
if (($i = array_search('--features', $argv, true)) !== false && isset($argv[$i + 1])) {
    $features = $argv[$i + 1];
}
if (!in_array($features, ['top3', 'rank'], true)) {
    exit("--features must be top3 or rank.\n");
}
if (($i = array_search('--version', $argv, true)) !== false && isset($argv[$i + 1])) {
    $version = $argv[$i + 1];
}

try {
    $tree = PredictionModel::parseJ48((string) file_get_contents($file));
} catch (InvalidArgumentException $e) {
    exit('Could not read the tree: ' . $e->getMessage() . "\n");
}

$titles = array_map(fn($p) => $p[1], require __DIR__ . '/program_codes.php');
$unknown = [];
echo "Predicted courses in the tree:\n";
foreach (PredictionModel::leafLabels($tree) as $label) {
    $title = PredictionModel::resolveLabel($label, $titles);
    printf("  %-20s -> %s\n", $label, $title ?? 'NOT A SYSTEM PROGRAM');
    if ($title === null) {
        $unknown[] = $label;
    }
}
if ($unknown) {
    exit("\nStopped: add these names to config/course_aliases.php (or fix them in the dataset): " . implode(', ', $unknown) . "\n");
}

if ($features === 'rank') {
    // 720 orderings of R..C without ties: report how many courses the tree can reach.
    $dims = CBFEngine::DIMENSIONS;
    $perms = [[]];
    foreach ($dims as $_) {
        $next = [];
        foreach ($perms as $p) {
            foreach ($dims as $d) {
                if (!in_array($d, $p, true)) {
                    $next[] = [...$p, $d];
                }
            }
        }
        $perms = $next;
    }
    $seen = [];
    $none = 0;
    foreach ($perms as $order) {
        $scores = array_combine($order, [50, 40, 30, 20, 15, 10]);
        $label = PredictionModel::predictLabelFromScores(['tree' => $tree, 'features' => 'rank'], $scores);
        $label === null ? $none++ : $seen[PredictionModel::resolveLabel($label, $titles)] = true;
    }
    printf("\nRank features: %d of 720 type orderings get a prediction; %d different courses can be predicted.\n", 720 - $none, count($seen));
    $model = ['version' => $version, 'source' => basename($file), 'imported_at' => date('c'), 'features' => 'rank', 'tree' => $tree];
    file_put_contents(PredictionModel::MODEL_FILE, json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit("\nSaved to config/prediction_model.json ($version, rank features).\n");
}

echo "\nPrediction for every top-3 RIASEC combination (compare with WEKA):\n";
$letters = CBFEngine::DIMENSIONS;
$noBranch = 0;
for ($a = 0; $a < 6; $a++) {
    for ($b = $a + 1; $b < 6; $b++) {
        for ($c = $b + 1; $c < 6; $c++) {
            $top = [$letters[$a], $letters[$b], $letters[$c]];
            $label = PredictionModel::predictLabel($tree, $top);
            $noBranch += $label === null ? 1 : 0;
            printf("  %s -> %s\n", implode('', $top), $label === null ? '(no branch: no prediction)' : PredictionModel::resolveLabel($label, $titles));
        }
    }
}
if ($noBranch) {
    echo "\nNote: $noBranch combination(s) have no matching branch. This happens when the tree was trained with\n"
        . "blank (missing) values. Build the WEKA file with evaluation/cbf_eval.py (x -> 1, blank -> 0) to avoid it.\n";
}

$model = ['version' => $version, 'source' => basename($file), 'imported_at' => date('c'), 'features' => 'top3', 'tree' => $tree];
file_put_contents(PredictionModel::MODEL_FILE, json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "\nSaved to config/prediction_model.json ($version).\n"
    . "Next: check config/cbf.php decision_tree.enabled, then run php db/recompute_recommendations.php\n";
