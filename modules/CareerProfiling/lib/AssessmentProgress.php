<?php

require_once __DIR__ . '/../../../shared/lib/Database.php';
require_once __DIR__ . '/../../Encryption/lib/Crypto.php';
require_once __DIR__ . '/RetakeRequest.php';

/**
 * A student's RIASEC assessment in progress, kept on the server so nothing is lost when they are
 * signed out, their PC crashes or they move to another PC.
 *
 * The row is made when the access code (or an approved retake) unlocks the assessment, and it
 * remembers which exam session unlocked it. While that session is open, plus GRACE_MINUTES to
 * submit, the student can sign in again anywhere and continue without the code. Answers are
 * assessment data (sensitive under RA 10173), so they are stored encrypted.
 *
 * Needs the assessment_progress table (db/migrate_add_assessment_progress.php). Until it exists,
 * everything here does nothing and the assessment works as before (session flag only).
 */
class AssessmentProgress
{
    /** Minutes after a session ends during which an assessment can still be finished and submitted. */
    public const GRACE_MINUTES = 15;

    /** Same order as api/assessment-questions.php, so answers[i] lines up with the i-th question. */
    private const ACTIVE_IDS_SQL = "SELECT id FROM assessment_questions WHERE is_active = TRUE
        ORDER BY array_position(ARRAY['R','I','A','S','E','C'], dimension), order_index, id";

    private static ?bool $available = null;

    /** Whether the table exists (checked once per request). */
    public static function available(PDO $pdo): bool
    {
        if (self::$available === null) {
            self::$available = $pdo->query("SELECT to_regclass('assessment_progress') IS NOT NULL")->fetchColumn() === true;
        }
        return self::$available;
    }

    /** Called when the code (or an approved retake) unlocks the assessment. Saved answers are kept. */
    public static function unlock(PDO $pdo, int $studentId, ?int $scheduleId, bool $retake): void
    {
        if (!self::available($pdo)) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO assessment_progress (student_id, schedule_id, is_retake) VALUES (?, ?, ?)
             ON CONFLICT (student_id) DO UPDATE SET schedule_id = EXCLUDED.schedule_id, is_retake = EXCLUDED.is_retake, updated_at = NOW()'
        )->execute([$studentId, $scheduleId, (int) $retake]);
    }

    /**
     * Whether the student may answer and submit right now, and until when.
     *
     * closed: unlocked by an exam session that is over (or was deleted). Then even the session flag
     * from api/verify-access-code.php no longer allows a submit.
     *
     * @return array{unlocked:bool,closed:bool,retake:bool,endsAt:?string,submitUntil:?string}
     */
    public static function access(PDO $pdo, int $studentId, ?DateTimeImmutable $now = null): array
    {
        $locked = ['unlocked' => false, 'closed' => false, 'retake' => false, 'endsAt' => null, 'submitUntil' => null];
        if (!self::available($pdo)) {
            return $locked;
        }
        $stmt = $pdo->prepare(
            'SELECT p.is_retake, s.exam_date::text AS exam_date, s.end_time::text AS end_time
             FROM assessment_progress p LEFT JOIN exam_schedules s ON s.id = p.schedule_id
             WHERE p.student_id = ?'
        );
        $stmt->execute([$studentId]);
        $row = $stmt->fetch();
        if (!$row) {
            return $locked;
        }
        // An approved retake has no exam session and no time limit.
        if ($row['is_retake']) {
            $approved = RetakeRequest::isApproved($pdo, $studentId);
            return ['unlocked' => $approved, 'closed' => false, 'retake' => $approved, 'endsAt' => null, 'submitUntil' => null];
        }
        if ($row['exam_date'] === null) {
            return ['closed' => true] + $locked;   // the session was deleted
        }
        $window = self::window($row['exam_date'], $row['end_time'], $now);
        return ['unlocked' => $window['open'], 'closed' => !$window['open'], 'retake' => false, 'endsAt' => $window['endsAt'], 'submitUntil' => $window['submitUntil']];
    }

    /**
     * When the session ends and until when it can still be submitted (school time, Asia/Manila).
     *
     * @return array{open:bool,endsAt:string,submitUntil:string} times in ISO 8601
     */
    public static function window(string $examDate, string $endTime, ?DateTimeImmutable $now = null): array
    {
        $tz = new DateTimeZone('Asia/Manila');
        $now = $now ?? new DateTimeImmutable('now', $tz);
        $end = new DateTimeImmutable($examDate . ' ' . substr($endTime, 0, 8), $tz);
        $until = $end->modify('+' . self::GRACE_MINUTES . ' minutes');
        return ['open' => $now < $until, 'endsAt' => $end->format(DATE_ATOM), 'submitUntil' => $until->format(DATE_ATOM)];
    }

    /**
     * Answers as sent by the page: one entry per active question, each null (not answered yet) or a
     * whole number from 1 to 5. Anything else is refused (null).
     *
     * @return array<int,?int>|null
     */
    public static function clean(mixed $answers, int $total): ?array
    {
        if (!is_array($answers) || !array_is_list($answers) || count($answers) !== $total) {
            return null;
        }
        foreach ($answers as $a) {
            if ($a !== null && (!is_int($a) || $a < 1 || $a > 5)) {
                return null;
            }
        }
        return $answers;
    }

    /** The active question ids in order, as one text ("12,13,..."): saved answers only count for this exact set. */
    public static function activeSet(PDO $pdo): string
    {
        return implode(',', $pdo->query(self::ACTIVE_IDS_SQL)->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array{set:string,answers:array<int,?int>}|null */
    public static function load(PDO $pdo, int $studentId): ?array
    {
        if (!self::available($pdo)) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT answers_enc FROM assessment_progress WHERE student_id = ?');
        $stmt->execute([$studentId]);
        $enc = $stmt->fetchColumn();
        if (!is_string($enc) || $enc === '') {
            return null;
        }
        try {
            $saved = json_decode(Crypto::dec($enc), true);
        } catch (Throwable $e) {
            error_log('[assessment-progress] saved answers could not be read: ' . $e->getMessage());
            return null;   // the page starts from what the browser still has
        }
        return is_array($saved) && is_string($saved['set'] ?? null) && is_array($saved['answers'] ?? null) ? $saved : null;
    }

    /** Saves the answers on the student's row (made at unlock). */
    public static function save(PDO $pdo, int $studentId, string $set, array $answers): void
    {
        $pdo->prepare('UPDATE assessment_progress SET answers_enc = ?, updated_at = NOW() WHERE student_id = ?')
            ->execute([Crypto::enc(json_encode(['set' => $set, 'answers' => $answers])), $studentId]);
    }

    /** After a submit or an admin reset: nothing in progress any more. */
    public static function clear(PDO $pdo, int $studentId): void
    {
        if (self::available($pdo)) {
            $pdo->prepare('DELETE FROM assessment_progress WHERE student_id = ?')->execute([$studentId]);
        }
    }
}
