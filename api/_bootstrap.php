<?php

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Rbac.php';
require_once __DIR__ . '/../lib/AuditLogger.php';

header('Content-Type: application/json');
Auth::start();

/** @return array<string,mixed> */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function jsonResponse(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}
