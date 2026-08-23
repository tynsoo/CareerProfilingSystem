<?php

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$user = Auth::requireLogin();
$body = readJsonBody();

// Explicit null clears the photo back to the default icon.
$avatarDataUrl = array_key_exists('avatarDataUrl', $body) ? $body['avatarDataUrl'] : false;
if ($avatarDataUrl === false) {
    jsonResponse(['success' => false, 'error' => 'Missing avatarDataUrl.'], 400);
}

if ($avatarDataUrl !== null) {
    if (!is_string($avatarDataUrl) || !preg_match('/^data:image\/(png|jpe?g|webp|gif);base64,([A-Za-z0-9+\/]+=*)$/', $avatarDataUrl, $m)) {
        jsonResponse(['success' => false, 'error' => 'Photo must be a PNG, JPEG, WEBP, or GIF image.'], 400);
    }
    // Decoded size cap (~750KB) — generous for a profile photo, cheap enough to store as a
    // TEXT column and send back on every session check without bloating requests.
    $decodedLength = (int) (strlen($m[2]) * 3 / 4);
    if ($decodedLength > 750 * 1024) {
        jsonResponse(['success' => false, 'error' => 'Photo is too large. Please use an image under 750KB.'], 400);
    }
}

$pdo = Database::get();
$pdo->prepare('UPDATE users SET avatar_data_url = ?, updated_at = NOW() WHERE id = ?')
    ->execute([$avatarDataUrl, $user['id']]);

// Keep the session in sync so the new photo shows immediately without re-login.
$_SESSION['user']['avatarUrl'] = $avatarDataUrl;

AuditLogger::log($user['id'], $user['role'], $avatarDataUrl === null ? 'remove_avatar' : 'update_avatar', 'user', (string) $user['id']);

jsonResponse(['success' => true, 'avatarUrl' => $avatarDataUrl]);
