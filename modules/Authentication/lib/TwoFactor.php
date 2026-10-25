<?php

require_once __DIR__ . '/../../../shared/lib/Mailer.php';
require_once __DIR__ . '/../../../shared/lib/EmailTemplate.php';

/**
 * Email-based one-time-code two-factor authentication.
 *
 * Delivery is intentionally isolated to sendCode() below — if this ever
 * moves to SMS or an authenticator app instead of email, only that one
 * method (and the phone-number/secret storage it would need) changes.
 * generate()/verify() and the two_factor_codes table stay the same either
 * way, since they only care about "a code was issued" / "a code matched",
 * not how it got to the user.
 */
class TwoFactor
{
    private const CODE_LENGTH = 6;
    private const EXPIRY_MINUTES = 5;
    private const MAX_ATTEMPTS = 5;

    /** Whether the twoFactor security policy is currently turned on. */
    public static function isEnabled(PDO $pdo): bool
    {
        $stmt = $pdo->prepare("SELECT value FROM security_policies WHERE key = 'twoFactor'");
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return $value === 'true';
    }

    /**
     * Generates a new code for the user, invalidates any earlier pending
     * codes (so only the most recently sent one is ever valid), and emails
     * it. Returns false (without exposing why) if sending failed, so the
     * caller can decide how to handle it — a password change shouldn't
     * silently "succeed" past verification just because an email bounced.
     *
     * $outCode receives the plaintext code (by reference) purely so local
     * dev — where Mailer has no real API key and doesn't actually send —
     * can surface it in the API response for testing. It is never stored
     * anywhere except hashed; callers should only read $outCode when
     * building a local-only debug response, never send it to the client
     * outside of APP_ENV=local.
     */
    public static function issue(PDO $pdo, int $userId, string $email, string $name, ?string &$outCode = null): bool
    {
        $code = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $outCode = $code;
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        $expiresAt = date('c', time() + self::EXPIRY_MINUTES * 60);

        // Invalidate anything still pending for this user — only the newest
        // code should ever be acceptable.
        $invalidate = $pdo->prepare('UPDATE two_factor_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
        $invalidate->execute([$userId]);

        $insert = $pdo->prepare('INSERT INTO two_factor_codes (user_id, code_hash, expires_at) VALUES (?, ?, ?)');
        $insert->execute([$userId, $codeHash, $expiresAt]);

        return self::sendCode($email, $name, $code);
    }

    /**
     * The swappable part. Right now this sends through Mailer (Brevo).
     * A future SMS or authenticator-app version would replace this method's
     * body — everything above and below it stays untouched.
     */
    private static function sendCode(string $email, string $name, string $code): bool
    {
        $bodyHtml = '<p style="margin:0 0 4px 0;">Use this code to confirm your password change on ProfilePath. It expires in '
            . self::EXPIRY_MINUTES . ' minutes.</p>';
        $html = EmailTemplate::renderCode(
            'Confirm your password change',
            $bodyHtml,
            $code,
            'This code expires in ' . self::EXPIRY_MINUTES . ' minutes and can only be used once.'
        );
        $text = "Your ProfilePath verification code is: $code\nThis code expires in " . self::EXPIRY_MINUTES . " minutes.";

        return Mailer::send($email, $name, 'Your ProfilePath verification code', $html, $text);
    }

    /**
     * Verifies a submitted code against the user's most recent pending
     * code. Tracks attempts per-code so a code can't be brute-forced within
     * its own expiry window, independent of the login lockout policy.
     *
     * @return string 'ok' | 'invalid' | 'expired' | 'too_many_attempts' | 'none_pending'
     */
    public static function verify(PDO $pdo, int $userId, string $submittedCode): string
    {
        $stmt = $pdo->prepare(
            'SELECT id, code_hash, expires_at, attempts FROM two_factor_codes
             WHERE user_id = ? AND used_at IS NULL
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return 'none_pending';
        }

        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return 'too_many_attempts';
        }

        if (strtotime($row['expires_at']) < time()) {
            return 'expired';
        }

        if (!password_verify($submittedCode, $row['code_hash'])) {
            $pdo->prepare('UPDATE two_factor_codes SET attempts = attempts + 1 WHERE id = ?')
                ->execute([$row['id']]);
            return 'invalid';
        }

        $pdo->prepare('UPDATE two_factor_codes SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
        return 'ok';
    }
}
