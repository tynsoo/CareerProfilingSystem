<?php

require_once __DIR__ . '/../../../shared/lib/CodeInput.php';

/**
 * The temporary access code a staff member gets by email once an administrator approves
 * their sign-up. They enter it on the activation page and then create their own
 * password, so nobody but the owner of the inbox ever sets it and the approver never
 * sees a password.
 *
 * Stored in email_verification_tokens as a salted hash (never the code itself). The hash
 * is bound to the user id and a "staff-setup" label, so a code can't be used as an
 * email-verification link or for a different account.
 */
class StaffSetupCode
{
    public const HOURS_VALID = 24;
    public const MAX_WRONG_TRIES = 5;
    // No 0/O/1/I/L: easy to read out of an email.
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const LENGTH = 8;

    public static function generate(): string
    {
        $code = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }
        return $code;
    }

    /**
     * Letters/digits only, upper case: "abcd-2345" -> "ABCD2345", " A B C D 2 3 4 5 " -> "ABCD2345", and the code is
     * picked out of a pasted line such as "Temporary access code: ABCD2345" (shared/lib/CodeInput.php).
     */
    public static function normalize(string $code): string
    {
        return CodeInput::pick($code, self::LENGTH);
    }

    public static function hash(int $userId, string $code): string
    {
        return hash('sha256', 'staff-setup:' . $userId . ':' . self::normalize($code));
    }

    /** Replaces any earlier code for this user with a fresh one; returns the plain code to email. */
    public static function issue(PDO $pdo, int $userId): string
    {
        $code = self::generate();
        $pdo->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
        // A new code starts with a clean count of wrong tries.
        $pdo->prepare('DELETE FROM rate_limit_hits WHERE rate_key = ?')->execute([self::wrongKey($userId)]);
        $pdo->prepare(
            "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL '" . self::HOURS_VALID . " hours')"
        )->execute([$userId, self::hash($userId, $code)]);
        return $code;
    }

    /** True when the code is the user's current, unused, unexpired one. */
    public static function isValid(PDO $pdo, int $userId, string $code): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM email_verification_tokens WHERE user_id = ? AND token_hash = ? AND used_at IS NULL AND expires_at > NOW()');
        $stmt->execute([$userId, self::hash($userId, $code)]);
        return (bool) $stmt->fetchColumn();
    }

    private static function wrongKey(int $userId): string
    {
        return 'staff-code-wrong:' . $userId;
    }

    /**
     * Counts one wrong code for this account. On the MAX_WRONG_TRIES-th wrong try the current code is cancelled, so
     * even the right code stops working and the Head of Guidance must send a new one. Returns true when it was cancelled.
     */
    public static function recordWrongTry(PDO $pdo, int $userId): bool
    {
        $pdo->prepare('INSERT INTO rate_limit_hits (rate_key) VALUES (?)')->execute([self::wrongKey($userId)]);
        $count = $pdo->prepare("SELECT COUNT(*) FROM rate_limit_hits WHERE rate_key = ? AND created_at > NOW() - INTERVAL '" . self::HOURS_VALID . " hours'");
        $count->execute([self::wrongKey($userId)]);
        if ((int) $count->fetchColumn() < self::MAX_WRONG_TRIES) {
            return false;
        }
        self::spend($pdo, $userId);
        return true;
    }

    public static function spend(PDO $pdo, int $userId): void
    {
        $pdo->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
    }

    /** Email to send with the code (HTML + plain text). */
    public static function emailBody(string $displayName, string $username, string $code): array
    {
        $link = rtrim((string) getenv('APP_URL'), '/') . '/staff-activate?u=' . rawurlencode($username);
        $safeName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
        $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $html = EmailTemplate::renderCode(
            'Your account is approved',
            "<p style=\"margin:0 0 12px 0;\">Hi $safeName,</p>"
                . "<p style=\"margin:0 0 12px 0;\">The Head of Guidance approved your ProfilePath staff account. Your username is <strong>$safeUser</strong>.</p>"
                . "<p style=\"margin:0;\">To finish, click <strong>Set Your Password</strong>, enter this temporary access code, and create your password. You can sign in after that.</p>",
            $code,
            'This code can be used once and expires in ' . self::HOURS_VALID . ' hours. Never share it with anyone.',
            'Set Your Password',
            $link
        );
        $text = "Hi $displayName,\n\nThe Head of Guidance approved your ProfilePath staff account. Your username is $username.\n\nTemporary access code: $code\n\nSet your password here: $link
Enter the code there and create your password. You can sign in after that. The code can be used once and expires in " . self::HOURS_VALID . " hours. Never share it with anyone.";
        return [$html, $text];
    }
}
