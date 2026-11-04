<?php
/**
 * A demo Administrator sign-in, for a panelist or adviser who reviews the system (panel revision S19).
 * It has the same access as the Head of Guidance, so turn it off once the review is over. Account Management
 * lists only counselors and facilitators, so this script is the way to turn it on and off.
 *
 *   php db/seed_demo_admin.php        creates the account, or gives it a new password and turns it back on
 *   php db/seed_demo_admin.php --off  turns it off and signs it out
 *
 * Each run prints a new random password, so no password is stored in this file.
 */
require_once __DIR__ . '/../shared/lib/Database.php';
require_once __DIR__ . '/../modules/Authentication/lib/PasswordPolicy.php';
require_once __DIR__ . '/../modules/Authentication/lib/SessionEnd.php';

$pdo = Database::get();

$username = 'demoadmin';
$name = 'Demo Administrator';
$email = 'demoadmin@profilepath.local';

function demoAdminPassword(PDO $pdo): string
{
    $sets = [['ABCDEFGHJKLMNPQRSTUVWXYZ', 3], ['abcdefghijkmnpqrstuvwxyz', 5], ['23456789', 3], ['!@#$%*?', 1]];
    do {
        $chars = [];
        foreach ($sets as [$set, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $chars[] = $set[random_int(0, strlen($set) - 1)];
            }
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        $password = implode('', $chars);
    } while (PasswordPolicy::errors($pdo, $password) !== []);
    return $password;
}

$find = $pdo->prepare('SELECT id, role FROM users WHERE LOWER(username) = LOWER(?)');
$find->execute([$username]);
$existing = $find->fetch();
if ($existing && $existing['role'] !== 'admin') {
    echo "Stopped: the username $username already belongs to a non-admin account.\n";
    exit(1);
}

if (in_array('--off', $argv, true)) {
    if (!$existing) {
        echo "There is no $username account.\n";
        exit;
    }
    $pdo->prepare('UPDATE users SET is_active = FALSE, updated_at = NOW() WHERE id = ?')->execute([$existing['id']]);
    SessionEnd::endFor($pdo, (int) $existing['id'], 'deactivated');
    echo "$username is turned off and signed out. Run the script without --off to turn it back on with a new password.\n";
    exit;
}

$password = demoAdminPassword($pdo);
$hash = password_hash($password, PASSWORD_BCRYPT);
if ($existing) {
    $pdo->prepare(
        "UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW()), is_active = TRUE,
                approval_status = 'approved', failed_login_attempts = 0, locked_until = NULL, updated_at = NOW()
         WHERE id = ?"
    )->execute([$hash, $existing['id']]);
    $status = 'password reset';
} else {
    $pdo->prepare(
        "INSERT INTO users (role, username, password_hash, email, email_verified_at, full_name, is_active, approval_status)
         VALUES ('admin', ?, ?, ?, NOW(), ?, TRUE, 'approved')"
    )->execute([$username, $hash, $email, $name]);
    $status = 'created';
}

echo "\nSign in at /staff-login\n\n";
echo sprintf("%-15s %-12s %s\n", 'Account', 'Username', 'Password');
echo sprintf("%-15s %-12s %-14s (%s)\n\n", 'Administrator', $username, $password, $status);
