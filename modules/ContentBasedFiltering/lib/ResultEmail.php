<?php

require_once __DIR__ . '/../../../shared/lib/Mailer.php';
require_once __DIR__ . '/../../../shared/lib/EmailTemplate.php';
require_once __DIR__ . '/../../../shared/lib/RateLimiter.php';
require_once __DIR__ . '/Mismatch.php';

/**
 * The email a student gets automatically once their worksheet is in, with no staff action.
 * A match (the program their career leads to is also one of their Best RIASEC Match programs)
 * gets a thank-you listing the Best RIASEC Match programs; a mismatch gets a request to visit
 * the Guidance Office and no course is named. It is sent once a day at most, and a failure to send never stops the
 * student from submitting.
 */
class ResultEmail
{
    /** The text of the email: ['subject','heading','message','cta','ctaPath']. */
    public static function build(bool $mismatch, array $topTitles, bool $allProgramsTied = false): array
    {
        if ($mismatch) {
            return [
                'subject' => 'Please visit the Guidance Office',
                'heading' => 'Please visit the Guidance Office',
                'message' => 'Thank you for completing the RIASEC career assessment and the Career Worksheet. Please visit the Guidance Office so a counselor can talk with you about your results and your career options.',
                'cta' => 'Go to ProfilePath',
                'ctaPath' => '/student-login',
            ];
        }
        if ($allProgramsTied) {
            return [
                'subject' => 'Your career assessment results',
                'heading' => 'Thank you for completing your assessment',
                'message' => 'Thank you for completing the RIASEC career assessment and the Career Worksheet. Your interest scores were equal, so every MMCL program was equally similar to your RIASEC profile and ProfilePath cannot put one program ahead of another. Please talk with a Guidance Counselor about your interests and options. You can see your results under Career Results in ProfilePath.',
                'cta' => 'View My Results',
                'ctaPath' => '/results',
            ];
        }
        sort($topTitles, SORT_NATURAL | SORT_FLAG_CASE); // no ranking is implied
        return [
            'subject' => 'Your career assessment results',
            'heading' => 'Thank you for completing your assessment',
            'message' => 'Thank you for completing the RIASEC career assessment and the Career Worksheet. Your Best RIASEC Match (the MMCL programs most similar to your RIASEC profile): ' . implode(', ', $topTitles) . '. You can see the careers that fit each program under Career Results in ProfilePath.',
            'cta' => 'View My Results',
            'ctaPath' => '/results',
        ];
    }

    /** @return bool true if an email went out */
    public static function send(PDO $pdo, int $studentId, bool $mismatch, array $topTitles, bool $allProgramsTied = false): bool
    {
        try {
            $row = $pdo->prepare(
                'SELECT u.email, s.first_name_enc FROM users u JOIN students s ON s.user_id = u.id
                 WHERE u.id = ? AND u.email IS NOT NULL AND u.email_verified_at IS NOT NULL AND u.is_active = TRUE'
            );
            $row->execute([$studentId]);
            $r = $row->fetch();
            if (!$r) {
                return false;
            }
            $key = 'result-email:' . $studentId;
            if (RateLimiter::tooMany($key, 1, 1440)) {
                return false; // already emailed in the last day
            }
            $first = (string) Crypto::dec($r['first_name_enc']);
            $mail = self::build($mismatch, $topTitles, $allProgramsTied);
            $link = rtrim((string) getenv('APP_URL'), '/') . $mail['ctaPath'];
            $safeFirst = htmlspecialchars($first, ENT_QUOTES, 'UTF-8');
            $safeMsg = htmlspecialchars($mail['message'], ENT_QUOTES, 'UTF-8');
            $html = EmailTemplate::render($mail['heading'], "<p style=\"margin:0 0 12px 0;\">Hi $safeFirst,</p><p style=\"margin:0;\">$safeMsg</p>", $mail['cta'], $link, '');
            $ok = Mailer::send($r['email'], $first, $mail['subject'], $html, "Hi $first,\n\n" . $mail['message'] . "\n\n$link");
            if (!$ok) {
                $pdo->prepare('DELETE FROM rate_limit_hits WHERE rate_key = ?')->execute([$key]);
            }
            return $ok;
        } catch (Throwable $e) {
            error_log('[result-email] failed: ' . $e->getMessage());
            return false;
        }
    }
}
