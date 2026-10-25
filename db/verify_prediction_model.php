<?php
/**
 * Checks that the application predicts exactly what Weka predicts, for all 20 possible inputs.
 *
 *   php db/verify_prediction_model.php --write-arff profiles20.arff
 *       writes the 20 inputs (class = ?) with the model's own class labels, for Weka's -T option
 *   php db/verify_prediction_model.php weka_predictions.csv
 *       compares Weka's CSV predictions (with -distribution) on that file with the application
 *
 * The application side goes through the same code the system uses for a student: raw scores ->
 * validation -> top-three 0/1 inputs -> PredictionModel::predictBinary(). Weka's side comes from
 * Weka's own command line, independent of tools/weka/ExportJ48Lookup.java. See tools/weka/README.md.
 */

require_once __DIR__ . '/../modules/ContentBasedFiltering/lib/PredictionModel.php';

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$modelFile = getenv('PREDICTION_MODEL_FILE') ?: PredictionModel::MODEL_FILE;
try {
    $model = PredictionModel::load($modelFile);
} catch (UnexpectedValueException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
if (($model['format'] ?? null) !== PredictionModel::LOOKUP_FORMAT) {
    fwrite(STDERR, "$modelFile is not an exported binary model (tools/weka/ExportJ48Lookup.java).\n");
    exit(1);
}
$profiles = PredictionModel::allBinaryProfiles();
$labels = $model['class_labels'];
$quote = fn(string $v) => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";

if (($argv[1] ?? '') === '--write-arff') {
    $out = $argv[2] ?? 'profiles20.arff';
    $arff = "% The 20 possible ProfilePath inputs (exactly three 1s), class unknown\n@RELATION profilepath_profiles20\n\n";
    foreach ($model['attributes'] as $a) {
        $arff .= "@ATTRIBUTE $a NUMERIC\n";
    }
    $arff .= '@ATTRIBUTE ' . $quote($model['class_attribute']) . ' {' . implode(',', array_map($quote, $labels)) . "}\n\n@DATA\n";
    foreach ($profiles as $bits) {
        $arff .= implode(',', $bits) . ",?\n";
    }
    file_put_contents($out, $arff);
    exit("Wrote $out (20 inputs, " . count($labels) . " class labels in the model's order).\n");
}

$csv = $argv[1] ?? null;
if ($csv === null || !is_file($csv)) {
    exit("Usage: php db/verify_prediction_model.php --write-arff profiles20.arff | <weka_predictions.csv>\n");
}

// Weka's CSV prediction output: inst#,actual,predicted,error,distribution...
$rows = [];
$started = false;
foreach (file($csv, FILE_IGNORE_NEW_LINES) as $line) {
    if (str_starts_with(trim($line), 'inst#')) {
        $started = true;
        continue;
    }
    if ($started && preg_match('/^\s*\d+,/', $line)) {
        $rows[] = str_getcsv($line, ',', '"', '\\');
    }
}
if (count($rows) !== 20) {
    exit("Expected Weka predictions for 20 inputs, found " . count($rows) . ". Use -classifications \"weka.classifiers.evaluation.output.prediction.CSV -distribution -decimals 16\".\n");
}

// Raw scores that produce each input: 40 for the selected types, 20 for the others (valid 10-50 scores).
$failed = 0;
$maxDiff = 0.0;
printf("%-4s %-13s %-40s %-40s %s\n", '#', 'R I A S E C', 'Weka predicted', 'Application predicted', 'Result');
foreach ($profiles as $n => $bits) {
    $scores = array_combine(CBFEngine::DIMENSIONS, array_map(fn($b) => $b ? 40 : 20, $bits));
    $app = PredictionModel::predictBinary($model, $scores);
    $wekaPred = preg_replace('/^\d+:/', '', $rows[$n][2]);
    $wekaDist = array_map(fn($v) => (float) ltrim(trim($v), '*'), array_slice($rows[$n], 4, count($labels)));
    $problems = [];
    if ($app['inputs'] !== $bits) {
        $problems[] = 'input conversion';
    }
    if ($app['predicted'] !== $wekaPred) {
        $problems[] = 'predicted class';
    }
    if (count($wekaDist) !== count($labels)) {
        $problems[] = 'distribution length';
    } else {
        foreach ($app['distribution'] as $i => $d) {
            $diff = abs($d['probability'] - $wekaDist[$i]);
            $maxDiff = max($maxDiff, $diff);
            if ($diff > 1e-12) {
                $problems[] = "probability of {$d['label']}";
            }
        }
    }
    $failed += $problems ? 1 : 0;
    printf("%-4d %-13s %-40s %-40s %s\n", $n + 1, implode(' ', $bits), $wekaPred, (string) $app['predicted'], $problems ? 'DIFFERENT: ' . implode(', ', $problems) : 'same');
}
printf("\n%d of 20 inputs identical (predicted class and all %d class probabilities; largest difference %.1e).\n",
    20 - $failed, count($labels), $maxDiff);
printf("Model: %s (Weka %s, %s %s, SHA-256 %s)\n", $model['version'], $model['weka_version'], $model['classifier'], $model['options'], $model['model_sha256']);
exit($failed ? 1 : 0);
