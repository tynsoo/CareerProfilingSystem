<?php
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified_at TIMESTAMPTZ");
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS email_verification_tokens (
        id          SERIAL PRIMARY KEY,
        user_id     INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        token_hash  VARCHAR(255) NOT NULL,
        expires_at  TIMESTAMPTZ NOT NULL,
        used_at     TIMESTAMPTZ,
        created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )"
);

// Existing accounts (created before this feature existed) shouldn't get
// retroactively locked out — treat everyone already in the table as verified.
$pdo->exec("UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL");

echo "email_verified_at column + email_verification_tokens table created (or already present); existing users backfilled as verified.\n";
