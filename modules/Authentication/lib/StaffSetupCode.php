<?php

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

    /** Letters/digits only, upper case: "abcd-2345" -> "ABCD2345". */
    public static function normalize(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
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
                . "<p style=\"margin:0;\">To finish, open <a href=\"$link\">$link</a>, enter this temporary access code, and create your password.</p>",
            $code,
            'This code can be used once and expires in ' . self::HOURS_VALID . ' hours. Never share it with anyone.'
        );
        $text = "Hi $displayName,\n\nThe Head of Guidance approved your ProfilePath staff account. Your username is $username.\n\nTemporary access code: $code\n\nOpen $link, enter the code and create your password. The code can be used once and expires in " . self::HOURS_VALID . " hours. Never share it with anyone.";
        return [$html, $text];
    }
}
