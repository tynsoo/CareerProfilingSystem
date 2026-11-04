<?php

/**
 * Rules for a Help Center FAQ, shared by api/faqs.php and its tests. FAQs are plain text:
 * the pages escape them, so nothing typed here can run as a script on a student's screen.
 */
class Faq
{
    public const AUDIENCES = ['student', 'staff'];
    public const QUESTION_MAX = 300;
    public const ANSWER_MAX = 2000;
    public const PER_AUDIENCE_MAX = 50;

    /** Tidies a question or answer: no stray control characters, trimmed; line breaks in an answer are kept. */
    public static function clean(string $text, bool $multiline): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\P{C}\n]/u', '', $text) ?? ''; // drop control characters except the newline
        if (!$multiline) {
            $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        } else {
            $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';
        }
        return trim($text);
    }

    /** @return ?string what is wrong, or null when the question and answer are fine */
    public static function validate(string $question, string $answer): ?string
    {
        if ($question === '' || $answer === '') {
            return 'Write both the question and its answer.';
        }
        if (mb_strlen($question) > self::QUESTION_MAX) {
            return 'The question must be ' . self::QUESTION_MAX . ' characters or fewer.';
        }
        if (mb_strlen($answer) > self::ANSWER_MAX) {
            return 'The answer must be ' . self::ANSWER_MAX . ' characters or fewer.';
        }
        return null;
    }

    /** security_policies key set once the panel's FAQ entries (S18) were added, so entries staff delete later stay deleted. */
    public const PANEL_ENTRIES_KEY = 'faq.panelEntriesAdded';

    /**
     * FAQ entries the panel asked for (S18): assessment scheduling and the CGC booking link, for students and for staff.
     * They are added once to the end of each list (api/faqs.php), and staff can edit, move or delete them like any FAQ.
     *
     * @return array<string,array<int,array{0:string,1:string}>>
     */
    public static function panelEntries(): array
    {
        return [
            'student' => [
                ['When can I take the RIASEC assessment?', 'The Guidance Office schedules an assessment session for your grade, strand, and section, with a date, a time, and a room. Your Assessments page shows when your session opens. During the session, enter the access code the Guidance Office gives you to start. Your answers are saved as you go, and if the session ends while you are answering, you have 15 minutes to finish and submit.'],
                ['What if I miss my assessment session?', 'Contact the Guidance Office. They can schedule a make-up session for you and the other students who missed it, with its own date, time, and access code.'],
                ['How do I book a counseling session?', 'Click Avail Counseling on this Help Center. The CGC\'s booking link is sent to the email on your account; open it to choose a time in the CGC booking system. If a guidance counselor invites you to counseling, the invitation email and your notifications have the same link. ProfilePath cannot see your booking, so the Guidance Office records it after checking their booking system.'],
            ],
            'staff' => [
                ['How do I schedule an assessment session?', 'Open Assessment Scheduling and create a session for a grade, strand, and section, with the date, the start and end time, the room, and the access code. Students of that section can start the assessment only while the session is open and after entering the code, and they have 15 minutes after the end time to submit. For students who missed it, create a make-up session and choose the students.'],
                ['How do students use the CGC booking link?', 'Students click Avail Counseling on the Student Help Center and get the link by email, or you send it with Invite to counseling from Student Information or from a flag in the Counseling Queue. The Administrator saves the link under CGC Counseling Booking Link on this page. Students book in the CGC booking system, which ProfilePath cannot see, so after checking it, record the booking status of each invitation (invited, booked, attended, did not attend, declined, or cancelled) and the session date.'],
            ],
        ];
    }

    /**
     * Adds the panel's FAQ entries once: each one goes to the end of its list unless a FAQ with the same question is
     * already there, and a marker keeps this from running again. Does nothing while the faqs table is missing.
     *
     * @return int how many entries were added
     */
    public static function addPanelEntries(PDO $pdo): int
    {
        $done = $pdo->prepare('SELECT 1 FROM security_policies WHERE key = ?');
        $done->execute([self::PANEL_ENTRIES_KEY]);
        if ($done->fetchColumn() || $pdo->query("SELECT to_regclass('faqs') IS NULL")->fetchColumn() === true) {
            return 0;
        }
        $added = 0;
        $pdo->beginTransaction();
        try {
            $exists = $pdo->prepare('SELECT 1 FROM faqs WHERE audience = ? AND LOWER(question) = LOWER(?)');
            $insert = $pdo->prepare(
                'INSERT INTO faqs (audience, question, answer, sort_order)
                 VALUES (?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM faqs WHERE audience = ?))'
            );
            foreach (self::panelEntries() as $audience => $items) {
                foreach ($items as [$question, $answer]) {
                    $exists->execute([$audience, $question]);
                    if ($exists->fetchColumn()) {
                        continue;
                    }
                    $insert->execute([$audience, $question, $answer, $audience]);
                    $added++;
                }
            }
            $pdo->prepare('INSERT INTO security_policies (key, value) VALUES (?, CURRENT_DATE::text) ON CONFLICT (key) DO NOTHING')
                ->execute([self::PANEL_ENTRIES_KEY]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[faq] panel entries not added: ' . $e->getMessage());
            return 0;
        }
        return $added;
    }
}
