<?php

/**
 * Who may create a Guidance Counselor or Guidance Facilitator account (panel revision S1).
 *
 *   'invite' (the default): nobody can sign up on their own. The administrator or a co-admin pre-registers the person in
 *            Account Management ("Invite staff", api/staff-approvals.php type invite), choosing the position. The
 *            person only receives the emailed temporary access code and creates their own password.
 *   'open':  the public staff sign-up form works as before, but every account still starts 'pending' with no usable
 *            password, and has no access at all until the administrator approves it and the person activates it.
 *
 * Changed by the administrator only, on Security Configuration (api/security-config.php type staffSignup), and every
 * change is written to the Audit Log.
 */
class StaffSignup
{
    public const KEY = 'staffSignup.mode';
    public const MODES = ['invite', 'open'];
    public const DEFAULT = 'invite';

    public const CLOSED_MESSAGE = 'Staff accounts are created by the Head of Guidance. Ask them to invite you from Account Management; you will get an email with an access code to set your password.';

    public static function mode(?string $stored): string
    {
        return in_array($stored, self::MODES, true) ? $stored : self::DEFAULT;
    }

    public static function current(PDO $pdo): string
    {
        $stmt = $pdo->prepare('SELECT value FROM security_policies WHERE key = ?');
        $stmt->execute([self::KEY]);
        $value = $stmt->fetchColumn();
        return self::mode($value === false ? null : (string) $value);
    }

    public static function isOpen(PDO $pdo): bool
    {
        return self::current($pdo) === 'open';
    }

    /** A username for an invited account, made from the email ("j.delacruz@mcl.edu.ph" -> "jdelacruz"), unique. */
    public static function usernameFor(PDO $pdo, string $email): string
    {
        $local = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', strstr($email, '@', true) ?: ''));
        $base = substr($local, 0, 16);
        if (!preg_match('/[a-z]/', $base)) {
            $base = 'staff' . substr($base, 0, 11);
        }
        if (strlen($base) < 3) {
            $base = str_pad($base, 3, 'x');
        }
        $check = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(username) = ?');
        $candidate = $base;
        for ($n = 2; $n < 1000; $n++) {
            $check->execute([$candidate]);
            if ($check->fetchColumn() === false) {
                return $candidate;
            }
            $candidate = $base . $n;
        }
        return $base . bin2hex(random_bytes(2));
    }
}
