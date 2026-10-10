<?php

// Non-sensitive settings any logged-in user (any role) can read — unlike
// api/security-config.php, which is admin/counselor only. Keep this list
// short and deliberately limited to display-only values.

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/AcademicYear.php';
require_once __DIR__ . '/../lib/BookingLink.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

Auth::requireLogin();
$pdo = Database::get();

$rows = $pdo->query(
    "SELECT key, value FROM security_policies WHERE key IN ('officeHours.text', 'booking.url')"
)->fetchAll(PDO::FETCH_KEY_PAIR);

jsonResponse([
    'officeHours' => ['text' => $rows['officeHours.text'] ?? 'Mon–Fri, 8:00 AM–5:00 PM'],
    // The CGC's own booking page, if the Head of Guidance saved one (empty: the button sends a request instead).
    'bookingUrl' => BookingLink::validate((string) ($rows[BookingLink::KEY] ?? ''))['url'],
    // Worked out from today's date (AcademicYear), not typed in by an admin.
    'academicYear' => ['current' => AcademicYear::current()],
]);
