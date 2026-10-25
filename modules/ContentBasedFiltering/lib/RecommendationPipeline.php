<?php

require_once __DIR__ . '/CBFEngine.php';
require_once __DIR__ . '/PredictionModel.php';

/**
 * Recommendation sources, kept separate.
 *
 *   Source 1  CBF                "Which courses have RIASEC characteristics most similar to
 *                                 this student's RIASEC profile?" -> cosine similarity
 *                                 (modules/ContentBasedFiltering/lib/CBFEngine.php):
 *                                   Best RIASEC Match = courses tied at the highest similarity
 *                                   Alternative Courses = courses at the next-highest similarity
 *   Source 2  Worksheet          "Which course did the student select?" -> the Preferred Course.
 *                                 Shown on its own; it never changes, forces or replaces the CBF
 *                                 result, and never enters the cosine calculation.
 *   Source 3  Prediction model   "Which course did students with a similar RIASEC profile choose?"
 *                                 -> the WEKA J48 tree imported into config/prediction_model.json
 *                                 (modules/ContentBasedFiltering/lib/PredictionModel.php), one course. Shown on its own; it never
 *                                 changes the CBF result or the Preferred Course. Unavailable when
 *                                 config/cbf.php decision_tree.enabled is false or no model is imported.
 *
 *   Final Best Match (CBF + prediction model + worksheet) is NOT computed yet: it needs an
 *   adviser-approved combination rule. finalBestMatchIds is null and isComplete is false until then.
 *
 * No source is given a numeric weight.
 */
class RecommendationPipeline
{
    /**
     * Runs every source for one student.
     *
     * @param array $profile CBFData::studentProfile() (uses 'riasec')
     * @param array $programs CBFData::activePrograms()
     * @param int[] $preferredIds Preferred Course(s) from the worksheet, in the order entered
     */
    public static function run(array $profile, array $programs, array $preferredIds, ?array $config = null, ?array $model = null): array
    {
        $cbf = self::cbfRecommendation($profile['riasec'] ?? null, $programs, $config);
        $worksheet = self::worksheetRecommendation($preferredIds, $programs);
        $prediction = self::predictionRecommendation($profile['riasec'] ?? null, $programs, $config, $model);
        return ['cbf' => $cbf, 'worksheet' => $worksheet, 'prediction' => $prediction]
            + self::finalRecommendation($cbf, $worksheet, $prediction);
    }

    /** Source 1: CBF. status 'unavailable' (with reason) if the RIASEC profile is missing/invalid. */
    public static function cbfRecommendation(?array $riasecScores, array $programs, ?array $config = null): array
    {
        try {
            $r = CBFEngine::compute($riasecScores, $programs, $config);
        } catch (InvalidArgumentException $e) {
            return ['status' => 'unavailable', 'reason' => $e->getMessage(), 'candidateIds' => [], 'results' => [], 'excluded' => []];
        }
        return ['status' => 'available', 'reason' => null, 'candidateIds' => $r['candidates']] + $r;
    }

    /** Source 2: Worksheet: the Preferred Course(s) that are active programs; 'empty' if none. */
    public static function worksheetRecommendation(array $preferredIds, array $programs): array
    {
        $active = array_map('intval', array_column($programs, 'id'));
        $ids = array_values(array_filter(array_unique(array_map('intval', $preferredIds)), fn($id) => in_array($id, $active, true)));
        return ['status' => $ids ? 'available' : 'empty', 'courseIds' => $ids];
    }

    /**
     * Source 3: Prediction model (WEKA J48). 'unavailable' when it is switched off, no model
     * is imported or the student has no valid RIASEC result; 'no_prediction' when the tree has
     * no branch for the input or predicts a course that is not an active program. Never
     * returns a placeholder course.
     *
     * @param array|null $model the imported model (tests pass one); null = config/prediction_model.json
     */
    public static function predictionRecommendation(?array $riasecScores = null, array $programs = [], ?array $config = null, ?array $model = null): array
    {
        $config ??= CBFEngine::config();
        $unavailable = fn(string $reason) => ['status' => 'unavailable', 'courseIds' => [], 'reason' => $reason, 'modelVersion' => null];
        if (empty($config['decision_tree']['enabled'])) {
            return $unavailable('Prediction model switched off (config/cbf.php decision_tree.enabled).');
        }
        try {
            $model ??= PredictionModel::load();
        } catch (UnexpectedValueException $e) {
            // A damaged or mismatched model file: report it; the CBF result is unaffected.
            error_log('[prediction] ' . $e->getMessage());
            return $unavailable($e->getMessage());
        }
        if ($model === null) {
            return $unavailable('No prediction model imported (php db/import_weka_tree.php).');
        }
        try {
            CBFEngine::studentVector($riasecScores, $config); // same validity rules as the CBF
            $label = PredictionModel::predictLabelFromScores($model, $riasecScores);
        } catch (InvalidArgumentException $e) {
            return $unavailable($e->getMessage());
        }
        $version = $model['version'] ?? null;
        $title = $label === null ? null : PredictionModel::resolveLabel($label, array_column($programs, 'title'));
        foreach ($programs as $p) {
            if ($title !== null && $p['title'] === $title) {
                return ['status' => 'available', 'courseIds' => [(int) $p['id']], 'reason' => null, 'modelVersion' => $version];
            }
        }
        return ['status' => 'no_prediction', 'courseIds' => [], 'modelVersion' => $version,
            'reason' => $label === null ? 'The tree has no branch for this RIASEC profile.' : "Predicted course '$label' is not an active program."];
    }

    /**
     * Combines the sources for display (pure; tested in tests/pipeline_test.php). The CBF
     * result is passed through unchanged; the Preferred Course is only compared with it.
     *
     * @return array{bestRiasecMatchIds:int[], alternativeIds:int[], preferred:array, status:string, reason:?string,
     *               predictionIds:int[], predictionInBestRiasecMatch:bool,
 *               finalBestMatchIds:null, sourcesUsed:string[], pendingSources:string[], isComplete:bool}
     */
    public static function finalRecommendation(array $cbf, array $worksheet, array $prediction): array
    {
        $available = $cbf['status'] === 'available';
        $best = $available ? $cbf['candidateIds'] : [];
        $alternatives = $available ? ($cbf['alternatives'] ?? []) : [];

        $preferred = array_map(fn($id) => ['id' => $id, 'inBestRiasecMatch' => in_array($id, $best, true)], $worksheet['courseIds']);

        // Does the student's preference agree with the RIASEC-based (CBF) result?
        if (!$available) {
            [$status, $reason] = ['mismatch', 'no_cbf_result'];
        } elseif (!$best) {
            [$status, $reason] = ['mismatch', 'no_cbf_match'];
        } elseif ($worksheet['status'] !== 'available') {
            [$status, $reason] = ['mismatch', 'no_worksheet'];
        } elseif (!$preferred[0]['inBestRiasecMatch']) {
            [$status, $reason] = ['mismatch', 'preferred_not_matched'];
        } else {
            [$status, $reason] = ['match', null];
        }

        $sourcesUsed = $worksheet['status'] === 'available' ? ['cbf', 'worksheet'] : ['cbf'];
        if ($prediction['status'] === 'available') {
            $sourcesUsed[] = 'prediction';
        }
        // FUTURE: once the adviser has approved a combination rule, compute the final Best Match
        // here from the CBF, the prediction model and the worksheet, and set isComplete = true.
        // Until then the prediction is reported on its own and changes nothing above.
        $pending = in_array($prediction['status'], ['available', 'no_prediction'], true) ? [] : ['prediction'];

        return [
            'bestRiasecMatchIds' => $best,
            'alternativeIds' => $alternatives,
            'preferred' => $preferred,
            'predictionIds' => $prediction['courseIds'],
            'predictionInBestRiasecMatch' => $prediction['courseIds'] !== [] && array_intersect($prediction['courseIds'], $best) !== [],
            'status' => $status,
            'reason' => $reason,
            'finalBestMatchIds' => null,
            'sourcesUsed' => $sourcesUsed,
            'pendingSources' => $pending,
            'isComplete' => false,
        ];
    }
}
