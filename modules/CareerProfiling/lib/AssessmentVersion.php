<?php

require_once __DIR__ . '/QuestionBank.php';
require_once __DIR__ . '/../../Encryption/lib/Crypto.php';

/**
 * Makes every assessment attempt reproducible.
 *
 * A question set is an immutable snapshot of the active questions (id, type and exact wording, in
 * the order shown). It is identified by the SHA-256 fingerprint of that snapshot, so editing,
 * adding, hiding or reordering a question produces a different set; an existing set never
 * changes. The student is given the id of the set they are shown, answers are sent per question
 * id, and the server scores them only against that same set, while it is still the active one.
 * Each attempt stores the set id, the answers (encrypted, like other student data) and the
 * scoring version, which is enough to recompute its scores.
 *
 * Attempts saved before this existed have no set or answers; they are "legacy" records and are
 * never given a made-up set or answers.
 */
class AssessmentVersion
{
    /** How stored scores are computed from answers: QuestionBank::scaleScore (10 x raw / n, halves up). */
    public const SCORING_VERSION = 'riasec-10-50-v1';

    /**
     * Canonical snapshot of an ordered question list.
     *
     * @param array<int,array{id:int,dimension:string,text:string}> $questions in the order shown
     * @return array<int,array{id:int,dimension:string,text:string}>
     */
    public static function snapshot(array $questions): array
    {
        return array_values(array_map(fn($q) => ['id' => (int) $q['id'], 'dimension' => (string) $q['dimension'], 'text' => (string) $q['text']], $questions));
    }

    public static function fingerprint(array $questions): string
    {
        return hash('sha256', json_encode(self::snapshot($questions), JSON_UNESCAPED_UNICODE));
    }

    /**
     * Scores answers against a question set. Pure: no database.
     *
     * @param array<int,array{id:int,dimension:string,text:string}> $questions the set's snapshot
     * @param mixed $answers list of ['questionId' => int, 'answer' => int 1-5], one per question
     * @return array{raw:array<string,int>, scores:array<string,int>, answers:array<int,array{questionId:int,answer:int}>}
     * @throws InvalidArgumentException naming what is wrong (nothing is guessed or filled in)
     */
    public static function score(array $questions, $answers): array
    {
        $perType = array_count_values(array_column($questions, 'dimension'));
        if (!QuestionBank::balance($perType)['ok']) {
            throw new InvalidArgumentException('This question set does not have the same number of questions (at least ' . QuestionBank::MIN_PER_TYPE . ') for every type.');
        }
        if (!is_array($answers) || !array_is_list($answers)) {
            throw new InvalidArgumentException('Answers must be a list of {questionId, answer}.');
        }
        $byId = array_column($questions, 'dimension', 'id');
        $given = [];
        foreach ($answers as $a) {
            $qid = $a['questionId'] ?? null;
            $value = $a['answer'] ?? null;
            if (!is_int($qid) || !isset($byId[$qid])) {
                throw new InvalidArgumentException('An answer refers to a question that is not in this assessment.');
            }
            if (isset($given[$qid])) {
                throw new InvalidArgumentException('A question was answered more than once.');
            }
            if (!is_int($value) || $value < 1 || $value > QuestionBank::MAX_ANSWER) {
                throw new InvalidArgumentException('Each answer must be an integer from 1 to 5.');
            }
            $given[$qid] = $value;
        }
        if (count($given) !== count($byId)) {
            throw new InvalidArgumentException('Every question must be answered (' . count($given) . ' of ' . count($byId) . ' answered).');
        }
        $raw = array_fill_keys(QuestionBank::DIMENSIONS, 0);
        $ordered = [];
        foreach ($questions as $q) {   // stored in the order the questions were shown
            $raw[$q['dimension']] += $given[(int) $q['id']];
            $ordered[] = ['questionId' => (int) $q['id'], 'answer' => $given[(int) $q['id']]];
        }
        $scores = [];
        foreach ($raw as $d => $sum) {
            $scores[$d] = QuestionBank::scaleScore($sum, (int) $perType[$d]);
        }
        return ['raw' => $raw, 'scores' => $scores, 'answers' => $ordered];
    }

    /** The active questions, decrypted, in the order students see them. */
    public static function activeQuestions(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT id, dimension, question_text_enc FROM assessment_questions WHERE is_active = TRUE
             ORDER BY array_position(ARRAY['R','I','A','S','E','C'], dimension), order_index, id"
        )->fetchAll();
        return array_map(fn($r) => ['id' => (int) $r['id'], 'dimension' => $r['dimension'], 'text' => Crypto::dec($r['question_text_enc'])], $rows);
    }

    /**
     * The id of the immutable set for these questions, created the first time it is seen.
     *
     * @return array{id:int, fingerprint:string}
     */
    public static function setFor(PDO $pdo, array $questions): array
    {
        $snapshot = self::snapshot($questions);
        $fingerprint = self::fingerprint($snapshot);
        $perType = array_count_values(array_column($snapshot, 'dimension'));
        $pdo->prepare(
            'INSERT INTO assessment_question_sets (fingerprint, question_count, items_per_type, scoring_version, snapshot_enc)
             VALUES (?, ?, ?, ?, ?) ON CONFLICT (fingerprint) DO NOTHING'
        )->execute([$fingerprint, count($snapshot), (int) max($perType ?: [0]), self::SCORING_VERSION,
            Crypto::enc(json_encode($snapshot, JSON_UNESCAPED_UNICODE))]);
        $stmt = $pdo->prepare('SELECT id FROM assessment_question_sets WHERE fingerprint = ?');
        $stmt->execute([$fingerprint]);
        return ['id' => (int) $stmt->fetchColumn(), 'fingerprint' => $fingerprint];
    }

    /** A stored set with its snapshot, or null. The snapshot is checked against its fingerprint. */
    public static function loadSet(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT id, fingerprint, scoring_version, snapshot_enc FROM assessment_question_sets WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $questions = json_decode(Crypto::dec($row['snapshot_enc']), true);
        if (!is_array($questions) || self::fingerprint($questions) !== $row['fingerprint']) {
            throw new RuntimeException("Question set $id does not match its fingerprint.");
        }
        return ['id' => (int) $row['id'], 'fingerprint' => $row['fingerprint'], 'scoringVersion' => $row['scoring_version'], 'questions' => $questions];
    }

    /**
     * Recomputes a stored attempt's scores from its saved answers and question set.
     *
     * @return array{status:string, scores?:array<string,int>, matchesStored?:bool}
     *         status 'legacy' when the attempt predates versioning (nothing to recompute)
     */
    public static function reproduce(PDO $pdo, int $assessmentId): array
    {
        $stmt = $pdo->prepare('SELECT question_set_id, answers_enc, score_r, score_i, score_a, score_s, score_e, score_c FROM assessments WHERE id = ?');
        $stmt->execute([$assessmentId]);
        $a = $stmt->fetch();
        if (!$a) {
            throw new InvalidArgumentException("No assessment $assessmentId.");
        }
        if ($a['question_set_id'] === null || $a['answers_enc'] === null) {
            return ['status' => 'legacy'];
        }
        $set = self::loadSet($pdo, (int) $a['question_set_id']);
        $result = self::score($set['questions'], json_decode(Crypto::dec($a['answers_enc']), true));
        $stored = ['R' => (int) $a['score_r'], 'I' => (int) $a['score_i'], 'A' => (int) $a['score_a'],
            'S' => (int) $a['score_s'], 'E' => (int) $a['score_e'], 'C' => (int) $a['score_c']];
        return ['status' => 'versioned', 'scores' => $result['scores'], 'matchesStored' => $result['scores'] === $stored];
    }
}
