<?php

require_once __DIR__ . '/CBFEngine.php';

/**
 * Prediction model: the decision tree (J48) trained in WEKA, run inside the system.
 *
 * Dataset (adviser's format): one row per student, the six RIASEC letters as
 * attributes (x = one of the student's top 3 types, blank = not), and the course as
 * the class. At prediction time the same attributes are built from the student's
 * RIASEC Assessment, the tree is walked, and the predicted class (one course) is
 * returned.
 *
 * Feature encodings (stored as 'features' in the model file; 'top3' when absent):
 *   top3  x / blank (or 1 / 0) for the student's three highest RIASEC types.
 *   rank  each type's rank within the student, 1 = highest, tied scores share the average
 *         rank (as evaluation/model_experiment.py writes it). Scale-free, so a tree trained
 *         on another instrument's scores still applies to the web app's 10-50 scores.
 *
 * The tree is imported from WEKA's printed J48 output by db/import_weka_tree.php
 * into config/prediction_model.json, so WEKA/Java is not needed on the server.
 * When that file does not exist, the model is "not available" and predict() returns null.
 *
 * Binary lookup format ('format' => 'weka-j48-binary-lookup'), written by
 * tools/weka/ExportJ48Lookup.java from the saved Weka .model file itself: the model's six
 * numeric 0/1 inputs have exactly three 1s, so there are only 20 possible inputs, and the file
 * holds Weka's own prediction and full class distribution for each of them (class set to
 * missing). Predictions and probabilities therefore equal Weka's exactly; see predictBinary().
 */
class PredictionModel
{
    public const MODEL_FILE = __DIR__ . '/../../../config/prediction_model.json';

    private const TRUE_VALUES = ['x', 'yes', 'y', '1', 'true', 't'];
    private const FALSE_VALUES = ['no', 'n', '0', 'false', 'f', '', '?', 'blank', 'none'];
    private const DIMENSION_NAMES = [
        'REALISTIC' => 'R', 'INVESTIGATIVE' => 'I', 'ARTISTIC' => 'A',
        'SOCIAL' => 'S', 'ENTERPRISING' => 'E', 'CONVENTIONAL' => 'C',
    ];

    /**
     * The installed model, or null when none is installed.
     *
     * @throws UnexpectedValueException when an exported lookup file is damaged or does not fit
     */
    public static function load(?string $file = null): ?array
    {
        // PREDICTION_MODEL_FILE (environment) points to another model file, e.g. in tests.
        $file ??= (getenv('PREDICTION_MODEL_FILE') ?: self::MODEL_FILE);
        if (!is_file($file)) {
            return null;
        }
        $model = json_decode((string) file_get_contents($file), true);
        if (is_array($model) && ($model['format'] ?? null) === self::LOOKUP_FORMAT) {
            self::validateLookup($model);   // a broken export is refused, never half-used
            return $model;
        }
        return is_array($model) && isset($model['tree']) ? $model : null;
    }

    /** Format written by tools/weka/ExportJ48Lookup.java. */
    public const LOOKUP_FORMAT = 'weka-j48-binary-lookup';
    /** The only feature encoding the lookup serves: 1 for the three highest scores, else 0. */
    public const BINARY_ENCODING = 'top3-binary';

    /**
     * Numeric split points in a printed J48 tree ("C <= 0" -> ['C <= 0', 0.0]).
     *
     * @return array<int,array{0:string,1:float}>
     */
    public static function splitThresholds(string $treeText): array
    {
        $out = [];
        foreach (preg_split('/\R/', $treeText) as $line) {
            if (preg_match('/^[|\s]*([A-Za-z]+)\s*(<=|>=|<|>)\s*(-?[0-9.]+(?:[Ee][-+]?[0-9]+)?)/', $line, $m)) {
                $out[] = [trim($line), (float) $m[3]];
            }
        }
        return $out;
    }

    /**
     * Checks an exported lookup before it is used: inputs R, I, A, S, E, C in that order,
     * numeric; all 20 three-of-six profiles present; one probability per class label; each
     * distribution sums to 1; Weka's predicted class is a valid label.
     *
     * @throws UnexpectedValueException describing the first problem found
     */
    public static function validateLookup(array $model): void
    {
        $fail = fn(string $m) => throw new UnexpectedValueException("Prediction model file: $m");
        if (($model['attributes'] ?? null) !== CBFEngine::DIMENSIONS) {
            $fail('inputs must be R, I, A, S, E, C in that order.');
        }
        if (($model['attribute_type'] ?? null) !== 'numeric') {
            $fail('inputs must be numeric 0/1.');
        }
        // Only the top-three binary encoding can be served by the 20-input lookup. Exports made
        // before the encoding was recorded (exporter v1) have none; for every export the tree's
        // splits must also fit 0/1 inputs, which a raw-score model's splits (e.g. "C <= 12") do not.
        $encoding = $model['feature_encoding'] ?? null;
        if ($encoding !== null && $encoding !== self::BINARY_ENCODING) {
            $fail("feature encoding '$encoding' cannot be served by the binary lookup (only " . self::BINARY_ENCODING . ').');
        }
        foreach (self::splitThresholds((string) ($model['tree'] ?? '')) as [$line, $t]) {
            if ($t < 0 || $t >= 1) {
                $fail("the tree splits at '$line'; top-three binary inputs are 0 or 1, so this is not a binary model.");
            }
        }
        $labels = $model['class_labels'] ?? null;
        if (!is_array($labels) || $labels === [] || count(array_unique($labels)) !== count($labels)) {
            $fail('class labels are missing or repeated.');
        }
        if (($model['class_index'] ?? null) !== 6) {
            $fail('the class must be the 7th attribute.');
        }
        $profiles = $model['profiles'] ?? [];
        $expected = array_map(fn($bits) => implode(',', $bits), self::allBinaryProfiles());
        if (array_diff($expected, array_keys($profiles)) || count($profiles) !== 20) {
            $fail('it must contain exactly the 20 profiles with three 1s.');
        }
        foreach ($profiles as $key => $p) {
            $dist = $p['distribution'] ?? null;
            if (!is_array($dist) || count($dist) !== count($labels)) {
                $fail("profile $key has no probability for every class.");
            }
            if (abs(array_sum($dist) - 1.0) > 1e-9 || min($dist) < 0) {
                $fail("profile $key: probabilities do not sum to 1.");
            }
            $i = $p['predicted_index'] ?? null;
            if ($i !== null && (!is_int($i) || !isset($labels[$i]) || $labels[$i] !== $p['predicted'])) {
                $fail("profile $key: predicted class does not match the class labels.");
            }
        }
    }

    /** The 20 inputs with exactly three 1s, in R, I, A, S, E, C order (RIA, RIS, ... SEC). */
    public static function allBinaryProfiles(): array
    {
        $out = [];
        for ($a = 0; $a < 6; $a++) {
            for ($b = $a + 1; $b < 6; $b++) {
                for ($c = $b + 1; $c < 6; $c++) {
                    $bits = array_fill(0, 6, 0);
                    $bits[$a] = $bits[$b] = $bits[$c] = 1;
                    $out[] = $bits;
                }
            }
        }
        return $out;
    }

    /**
     * Raw RIASEC scores -> the model's six inputs: 1 for the three highest-scoring types, 0 for
     * the rest; equal scores are ordered R, I, A, S, E, C (the rule used to prepare the training
     * data; a project rule, not an O*NET rule). Exactly three inputs are 1.
     *
     * The scores must follow the assessment's scoring rules (config/cbf.php: whole numbers from
     * items x min answer to items x max answer, i.e. 10-50); anything else is rejected, never
     * replaced by 0.
     *
     * @param array<string,int|float|string|null>|null $scores keyed R..C
     * @return int[] six 0/1 values in R, I, A, S, E, C order
     * @throws InvalidArgumentException for missing or invalid scores
     */
    public static function binaryInputs(?array $scores, ?array $config = null): array
    {
        CBFEngine::studentVector($scores, $config);     // presence, numeric and range checks
        foreach (CBFEngine::DIMENSIONS as $d) {
            if ((float) $scores[$d] != floor((float) $scores[$d])) {
                throw new InvalidArgumentException("The RIASEC score for $d ({$scores[$d]}) is not a whole number.");
            }
        }
        $top = CBFEngine::topRiasecLetters($scores, 3);   // shared ranking (modules/CareerProfiling/lib/RiasecProfile.php)
        return array_map(fn($d) => in_array($d, $top, true) ? 1 : 0, CBFEngine::DIMENSIONS);
    }

    /**
     * Prediction from an exported lookup model: Weka's predicted class and its full class
     * distribution for this student's inputs.
     *
     * ranked: every class, highest probability first; equal probabilities keep the model's
     * class-label order (the order Weka declares the classes). That display rule is
     * deterministic but arbitrary for ties: tie-averaged evaluation metrics (Hit@3, MRR) do
     * not describe it exactly. The predicted class is Weka's own classifyInstance() result
     * (for a J48 leaf, the first class with the highest probability).
     *
     * @return array{inputs:int[], predicted:?string, distribution:array<int,array{label:string,probability:float}>,
     *               ranked:array<int,array{label:string,probability:float}>}
     * @throws InvalidArgumentException for invalid scores (see binaryInputs())
     */
    public static function predictBinary(array $model, ?array $scores, ?array $config = null): array
    {
        $inputs = self::binaryInputs($scores, $config);
        $entry = $model['profiles'][implode(',', $inputs)];
        $distribution = [];
        foreach ($model['class_labels'] as $i => $label) {
            $distribution[] = ['label' => $label, 'probability' => (float) $entry['distribution'][$i], 'index' => $i];
        }
        $ranked = $distribution;
        usort($ranked, fn($a, $b) => ($b['probability'] <=> $a['probability']) ?: ($a['index'] <=> $b['index']));
        return ['inputs' => $inputs, 'predicted' => $entry['predicted'], 'distribution' => $distribution, 'ranked' => $ranked];
    }

    /**
     * Predicted course (class label as written in the dataset) for a student whose
     * top RIASEC types are $topLetters (e.g. ['C', 'I', 'E']). null if the tree has no
     * branch for this input.
     */
    public static function predictLabel(array $tree, array $topLetters): ?string
    {
        $selected = array_fill_keys(array_map('strtoupper', $topLetters), true);
        $node = $tree;
        while (!isset($node['class'])) {
            $letter = self::dimension((string) ($node['attribute'] ?? ''));
            if ($letter === null) {
                return null;
            }
            $isSelected = isset($selected[$letter]);
            $next = null;
            foreach ($node['branches'] ?? [] as $branch) {
                if (self::branchMatches($branch['op'], (string) $branch['value'], $isSelected)) {
                    $next = $branch['node'];
                    break;
                }
            }
            if ($next === null) {
                return null;
            }
            $node = $next;
        }
        return (string) $node['class'];
    }

    /**
     * Rank of each RIASEC type within the student, keyed R..C: 1 = highest score, tied
     * scores share the average of their positions (e.g. two types tied for 2nd get 2.5).
     *
     * @param array<string,int|float> $scores
     * @return array<string,float>
     */
    public static function ranks(array $scores): array
    {
        $dims = CBFEngine::DIMENSIONS;
        $order = $dims;
        usort($order, fn($a, $b) => ((float) $scores[$b] <=> (float) $scores[$a]) ?: (array_search($a, $dims) <=> array_search($b, $dims)));
        $out = [];
        for ($i = 0, $n = count($order); $i < $n; $i = $j + 1) {
            $j = $i;
            while ($j + 1 < $n && abs((float) $scores[$order[$j + 1]] - (float) $scores[$order[$i]]) < 1e-9) {
                $j++;
            }
            for ($k = $i; $k <= $j; $k++) {
                $out[$order[$k]] = ($i + $j) / 2 + 1;
            }
        }
        return $out;
    }

    /**
     * Predicted class label from the student's RIASEC scores (R..C), using the feature
     * encoding the model was trained on. null if the tree has no branch for the input.
     */
    public static function predictLabelFromScores(array $model, array $scores): ?string
    {
        if (($model['format'] ?? null) === self::LOOKUP_FORMAT) {
            return self::predictBinary($model, $scores)['predicted'];
        }
        $features = $model['features'] ?? 'top3';
        if ($features === 'top3') {
            return self::predictLabel($model['tree'], CBFEngine::topRiasecLetters($scores, 3));
        }
        if ($features !== 'rank') {
            throw new InvalidArgumentException("Unknown feature encoding '$features' in the prediction model.");
        }
        $rank = self::ranks($scores);
        $node = $model['tree'];
        while (!isset($node['class'])) {
            $letter = self::dimension((string) ($node['attribute'] ?? ''));
            if ($letter === null) {
                return null;
            }
            $x = $rank[$letter];
            $next = null;
            foreach ($node['branches'] ?? [] as $branch) {
                $t = (float) $branch['value'];
                $ok = match ($branch['op']) {
                    '<=' => $x <= $t, '<' => $x < $t, '>' => $x > $t, '>=' => $x >= $t,
                    '=' => abs($x - $t) < 1e-9, '!=' => abs($x - $t) >= 1e-9, default => false,
                };
                if ($ok) {
                    $next = $branch['node'];
                    break;
                }
            }
            if ($next === null) {
                return null;
            }
            $node = $next;
        }
        return (string) $node['class'];
    }

    /**
     * Prediction stage of the recommendation pipeline: program ids the model predicts
     * for the student (one course), or null when no model has been imported.
     *
     * @param array<int,array{id:int,title:string}> $programs active programs
     * @return int[]|null
     */
    public static function predict(array $topLetters, array $programs, ?array $model = null): ?array
    {
        $model ??= self::load();
        if ($model === null) {
            return null;
        }
        $label = self::predictLabel($model['tree'], $topLetters);
        if ($label === null) {
            return [];
        }
        $title = self::resolveLabel($label, array_column($programs, 'title'));
        foreach ($programs as $p) {
            if ($p['title'] === $title) {
                return [(int) $p['id']];
            }
        }
        return [];
    }

    /** Program title a dataset class label refers to (title, alias or abbreviation), or null. */
    public static function resolveLabel(string $label, array $titles): ?string
    {
        $key = self::key($label);
        $aliases = require __DIR__ . '/../../../config/course_aliases.php';
        foreach ($titles as $title) {
            if (self::key($title) === $key) {
                return $title;
            }
            foreach ($aliases[$title] ?? [] as $alias) {
                if (self::key($alias) === $key) {
                    return $title;
                }
            }
        }
        return null;
    }

    /**
     * Parses WEKA's printed J48 tree (the "J48 pruned tree" block of the classifier
     * output, or just the tree lines) into nested nodes:
     *   ['attribute' => 'C', 'branches' => [['op' => '=', 'value' => 'x', 'node' => ...], ...]]
     *   ['class' => 'BSIT']
     */
    public static function parseJ48(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $start = 0;
        foreach ($lines as $i => $line) {
            if (preg_match('/^J48 (pruned|unpruned) tree/i', trim($line))) {
                $start = $i + 1;
                break;
            }
        }

        $root = null;
        $decisionAt = [];
        for ($i = $start, $n = count($lines); $i < $n; $i++) {
            $raw = rtrim($lines[$i]);
            $trimmed = trim($raw);
            if ($trimmed === '' || preg_match('/^-+$/', $trimmed)) {
                if ($root !== null) {
                    break; // blank line after the tree ends it
                }
                continue;
            }
            if (preg_match('/^(Number of Leaves|Size of the tree)/i', $trimmed)) {
                break;
            }
            // Single-leaf tree: ": BSIT (10.0/2.0)"
            if ($root === null && preg_match('/^:\s*(.+?)\s*(\([^()]*\))?$/', $trimmed, $m)) {
                return ['class' => trim($m[1], " '\"")];
            }

            preg_match('/^((?:\|\s*)*)(.*)$/', $raw, $m);
            $depth = substr_count($m[1], '|');
            $content = $m[2];
            $class = null;
            if (preg_match('/^(.*?)\s*:\s*(.+?)\s*(\([^()]*\))?$/', $content, $mm)) {
                $content = $mm[1];
                $class = trim($mm[2], " '\"");
            }
            if (!preg_match('/^(.+?)\s*(<=|>=|!=|=|<|>)\s*(.*)$/', $content, $c)) {
                throw new InvalidArgumentException('Line ' . ($i + 1) . " is not a J48 tree line: \"$trimmed\"");
            }
            [$attr, $op, $value] = [trim($c[1]), $c[2], trim($c[3], " \t'\"")];

            if ($depth === 0) {
                $root ??= (object) ['attribute' => $attr, 'branches' => []];
                $decisionAt[0] = $root;
            }
            $decision = $decisionAt[$depth] ?? null;
            if ($decision === null) {
                throw new InvalidArgumentException('Line ' . ($i + 1) . ' is indented without a parent branch.');
            }
            $decision->attribute ??= $attr;

            $branch = (object) ['op' => $op, 'value' => $value, 'node' => null];
            if ($class !== null) {
                $branch->node = (object) ['class' => $class];
            } else {
                $branch->node = (object) ['attribute' => null, 'branches' => []];
                $decisionAt[$depth + 1] = $branch->node;
            }
            $decision->branches[] = $branch;
        }
        if ($root === null) {
            throw new InvalidArgumentException('No J48 tree found in the file.');
        }
        return json_decode(json_encode($root), true);
    }

    /** Every class label used in a tree's leaves. */
    public static function leafLabels(array $node): array
    {
        if (isset($node['class'])) {
            return [$node['class']];
        }
        $labels = [];
        foreach ($node['branches'] ?? [] as $b) {
            $labels = array_merge($labels, self::leafLabels($b['node']));
        }
        return array_values(array_unique($labels));
    }

    private static function branchMatches(string $op, string $value, bool $isSelected): bool
    {
        $v = strtolower(trim($value));
        if ($op === '=' || $op === '!=') {
            if (in_array($v, self::TRUE_VALUES, true)) {
                $eq = $isSelected;
            } elseif (in_array($v, self::FALSE_VALUES, true)) {
                $eq = !$isSelected;
            } else {
                return false;
            }
            return $op === '=' ? $eq : !$eq;
        }
        // Numeric attributes (1 = selected, 0 = not), as written by evaluation/cbf_eval.py.
        $x = $isSelected ? 1.0 : 0.0;
        $t = (float) $value;
        return match ($op) {
            '<=' => $x <= $t,
            '<' => $x < $t,
            '>' => $x > $t,
            '>=' => $x >= $t,
            default => false,
        };
    }

    private static function dimension(string $attribute): ?string
    {
        $a = strtoupper(trim($attribute, " \t'\""));
        if (in_array($a, CBFEngine::DIMENSIONS, true)) {
            return $a;
        }
        return self::DIMENSION_NAMES[$a] ?? null;
    }

    private static function key(string $text): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($text));
    }
}
