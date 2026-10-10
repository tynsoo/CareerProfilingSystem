<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/QuestionBank.php';
require_once __DIR__ . '/../lib/CBFEngine.php';
require_once __DIR__ . '/../lib/CareerMatcher.php';

/**
 * Stand-in for the real api/*.php files while previewing the STUDENT side with no database.
 *
 * It answers the same requests with sample data. Where the real code is plain logic on arrays (the RIASEC scoring,
 * the content-based filtering recommendations, the career-to-program matching) it calls the REAL classes from lib/,
 * so what a teammate sees on the results page is what the real system would compute for those scores. Everything
 * else (the student, announcements, notifications, FAQs) is sample data. State lives in the PHP session, so each
 * browser has its own preview student. Nothing is written to a database or to disk.
 *
 * Only ever run through dev/preview.php. It is not part of the live site.
 */
class PreviewApi
{
    /** The pages that belong to the student side (clean URL names, as in router.php). */
    public const PAGES = [
        'student-login', 'student-register', 'student-forgot-password', 'verify-email',
        'assessment-instructions', 'assessment', 'riasec-assessment', 'results',
        'career-worksheet', 'worksheet-results', 'saved-careers',
        'student-help-center', 'student-notifications', 'student-settings', 'change-password',
        'terms', 'privacy', 'accessibility',
    ];

    public const ACCESS_CODE = '123456';
    public const PASSWORD = 'Preview#123';
    private const SAMPLE_SCORES = ['R' => 30, 'I' => 46, 'A' => 22, 'S' => 26, 'E' => 28, 'C' => 34];
    private const LABELS = ['R' => 'Realistic', 'I' => 'Investigative', 'A' => 'Artistic', 'S' => 'Social', 'E' => 'Enterprising', 'C' => 'Conventional'];

    // ------------------------------------------------------------------ state

    public static function state(): array
    {
        if (!isset($_SESSION['pv'])) {
            $_SESSION['pv'] = self::fresh('new');
        }
        return $_SESSION['pv'];
    }

    private static function save(array $pv): void
    {
        $_SESSION['pv'] = $pv;
    }

    /** A preview student in one of three situations. */
    public static function fresh(string $scenario, string $window = 'open'): array
    {
        $pv = [
            'scenario' => $scenario, 'window' => $window, 'unlocked' => false,
            'assessment' => null, 'worksheet' => null, 'saved' => [],
            'dismissed' => [], 'requests' => [], 'avatar' => null, 'queue' => 0,
        ];
        if ($scenario === 'done' || $scenario === 'complete') {
            $pv['assessment'] = self::assessmentFrom(self::SAMPLE_SCORES);
            $pv['unlocked'] = true;
        }
        if ($scenario === 'complete') {
            $programs = self::programs();
            $scores = self::SAMPLE_SCORES;
            $programId = CareerMatcher::resolve('Software Engineer', $programs, $scores);
            $pv['worksheet'] = ['career' => 'Software Engineer', 'programId' => $programId, 'electives' => ['Computer Programming', 'Empowerment Technologies', 'Data Analytics']];
            $rec = self::recommendation($pv);
            $pv['saved'] = array_slice(array_column($rec['top3'] ?? [], 'id'), 0, 2);
        }
        return $pv;
    }

    public static function reset(string $scenario, string $window): void
    {
        self::save(self::fresh($scenario, $window));
    }

    private static function assessmentFrom(array $scores): array
    {
        $ranked = $scores;
        arsort($ranked);
        $top = array_map(fn($code) => self::LABELS[$code], array_slice(array_keys($ranked), 0, 3));
        return ['scores' => $scores, 'topTypes' => $top, 'completedAt' => date('Y-m-d H:i:sP')];
    }

    // ------------------------------------------------------------------ data

    /** @return array<int,array<string,mixed>> the MMCL programs (same shape as api/programs.php) */
    public static function programs(): array
    {
        static $programs = null;
        if ($programs === null) {
            $raw = json_decode((string) file_get_contents(__DIR__ . '/fixtures/programs.json'), true);
            $programs = $raw['programs'];
        }
        return $programs;
    }

    private static function student(array $pv): array
    {
        return [
            'id' => 1, 'role' => 'student', 'username' => '202600000001', 'schoolId' => '202600000001',
            'firstName' => 'Juan', 'lastName' => 'Dela Cruz', 'gradeLevel' => '11', 'strand' => 'STEM', 'section' => 'S1101',
            'registeredAt' => '2026-09-20 09:00:00+08', 'avatarUrl' => $pv['avatar'],
        ];
    }

    private static function windowState(array $pv): array
    {
        $today = date('Y-m-d');
        switch ($pv['window']) {
            case 'open':
                return ['state' => 'open', 'examDate' => $today, 'startTime' => '08:00', 'endTime' => '17:00', 'room' => 'Room 204'];
            case 'upcoming':
                return ['state' => 'upcoming', 'examDate' => date('Y-m-d', strtotime('+2 days')), 'startTime' => '08:00', 'endTime' => '10:00', 'room' => 'Room 204'];
            case 'ended':
                return ['state' => 'ended'];
            default:
                return ['state' => 'none'];
        }
    }

    private static function schedules(array $pv): array
    {
        $w = self::windowState($pv);
        if (!isset($w['examDate'])) {
            return [];
        }
        return [['examDate' => $w['examDate'], 'startTime' => $w['startTime'], 'endTime' => $w['endTime'], 'room' => $w['room'], 'scheduleType' => 'assessment']];
    }

    /** The real content-based filtering, run on the preview student's scores. */
    private static function recommendation(array $pv): array
    {
        if ($pv['assessment'] === null || $pv['worksheet'] === null) {
            return ['hasRecommendation' => false];
        }
        $programs = self::programs();
        $active = array_map(fn($p) => ['id' => (int) $p['id'], 'hollandCode' => $p['hollandCode']], $programs);
        $statedId = $pv['worksheet']['programId'];
        $rec = CBFEngine::recommend($pv['assessment']['scores'], $active, $statedId);

        $byId = [];
        foreach ($programs as $p) {
            $byId[(int) $p['id']] = $p;
        }
        $enrich = function (?array $entry) use ($byId): ?array {
            if ($entry === null || !isset($byId[$entry['id']])) {
                return null;
            }
            $p = $byId[$entry['id']];
            return [
                'id' => (int) $p['id'], 'title' => $p['title'], 'hollandCode' => $p['hollandCode'],
                'description' => $p['description'], 'careers' => $p['careers'],
                'collegeCode' => $p['collegeCode'], 'collegeName' => $p['collegeName'], 'isActive' => $p['status'] === 'Active',
                'cosine' => (float) $entry['cosine'], 'score' => (float) $entry['score'], 'matchPercent' => (int) round($entry['score'] * 100),
            ];
        };

        $stated = null;
        if ($statedId !== null) {
            foreach ($rec['all'] as $s) {
                if ($s['id'] === $statedId) {
                    $stated = $s;
                    break;
                }
            }
        }
        return [
            'hasRecommendation' => true, 'computedAt' => date('Y-m-d H:i:sP'),
            'statedProgramId' => $statedId, 'statedCareer' => $pv['worksheet']['career'],
            'statedProgram' => $enrich($stated), 'electives' => $pv['worksheet']['electives'],
            'topProgramId' => (int) $rec['top3'][0]['id'], 'topScore' => (float) $rec['top3'][0]['score'],
            'top3' => array_values(array_filter(array_map($enrich, $rec['top3']))),
            'statedOutsideTop3' => $enrich($rec['statedOutsideTop3']),
        ];
    }

    private static function notifications(array $pv, bool $full): array
    {
        $now = time();
        $items = [
            ['key' => 'ann:1', 'type' => 'announcement', 'title' => 'Assessment Notice', 'text' => 'Your career interest assessment is scheduled. Check My Assessment Schedule for the date, time and room.', 'link' => 'assessment', 'ts' => date('Y-m-d H:i:sP', $now - 3600)],
            ['key' => 'exam:1', 'type' => 'schedule_published', 'title' => 'Assessment scheduled', 'text' => date('Y-m-d', $now + 86400) . ' in Room 204.', 'link' => 'assessment', 'ts' => date('Y-m-d H:i:sP', $now - 7200)],
            ['key' => 'help:1', 'type' => 'help_resolved', 'title' => 'Counseling request resolved', 'text' => 'Your counseling request "Request for Academic Advising" has been resolved.', 'link' => 'student-help-center', 'ts' => date('Y-m-d H:i:sP', $now - 86400)],
        ];
        $items = array_values(array_filter($items, fn($i) => !in_array($i['key'], $pv['dismissed'], true)));
        return ['items' => $items, 'count' => count($items), 'unreadCount' => count($items), 'tracksRead' => false];
    }

    private static function faqs(): array
    {
        $rows = [
            ['What is the RIASEC Career Interest Assessment?', "It's an interest inventory based on Holland's RIASEC theory. Your answers show how strongly you lean toward each of six interest areas, which are then used to suggest careers that fit you."],
            ['Can I pause and come back later?', 'Yes. Your answers are saved as you go, so you can close the page and continue from where you left off.'],
            ['What is the Career Worksheet?', "It unlocks after you finish the assessment. You'll write down a career you're considering and pick electives aligned to your strand and results."],
            ['How do I change my password?', 'Open My Profile from the menu at the top right, then click Edit next to Password. Enter your current password and choose a new one.'],
        ];
        $out = [];
        foreach ($rows as $i => [$q, $a]) {
            $out[] = ['id' => $i + 1, 'audience' => 'student', 'question' => $q, 'answer' => $a, 'sortOrder' => $i + 1];
        }
        return ['audience' => 'student', 'canEdit' => false, 'faqs' => $out];
    }

    /** Same rules the real password change checks. @return array<int,string> */
    private static function passwordProblems(string $pw): array
    {
        $errors = [];
        if (strlen($pw) < 8) { $errors[] = 'Password must be at least 8 characters.'; }
        if (!preg_match('/[A-Z]/', $pw)) { $errors[] = 'Password must contain an uppercase letter.'; }
        if (!preg_match('/[a-z]/', $pw)) { $errors[] = 'Password must contain a lowercase letter.'; }
        if (!preg_match('/[0-9]/', $pw)) { $errors[] = 'Password must contain a number.'; }
        if (!preg_match('/[^A-Za-z0-9]/', $pw)) { $errors[] = 'Password must contain a symbol.'; }
        return $errors;
    }

    // ------------------------------------------------------------------ requests

    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function body(): array
    {
        $raw = file_get_contents('php://input');
        $d = $raw ? json_decode($raw, true) : null;
        return is_array($d) ? $d : [];
    }

    public static function handle(string $name): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $body = $method === 'GET' ? [] : self::body();
        $pv = self::state();

        switch ($name) {
            case 'session':
                self::json(['user' => self::student($pv), 'csrfToken' => 'preview', 'sessionTimeout' => ['enabled' => false, 'minutes' => 30]]);
                return;

            case 'login':
                self::json(['success' => true, 'role' => 'student', 'redirect' => $pv['assessment'] ? 'results' : 'assessment', 'user' => self::student($pv)]);
                return;

            case 'logout':
                self::json(['success' => true]);
                return;

            case 'register':
                self::json(['success' => true, 'message' => 'Preview only: no account was created.']);
                return;

            case 'resend-verification':
                self::json(['success' => true]);
                return;

            case 'verify-email':
                self::json(['success' => true]);
                return;

            case 'password-reset':
                if ($method === 'GET') {
                    self::json(['minLength' => 8, 'requireUpper' => true, 'requireLower' => true, 'requireNumber' => true, 'requireSymbol' => true]);
                    return;
                }
                $action = (string) ($body['action'] ?? '');
                if ($action === 'request') {
                    $email = (string) ($body['email'] ?? '');
                    $at = strpos($email, '@');
                    self::json(['success' => true, 'maskedEmail' => $at ? substr($email, 0, 1) . str_repeat("\u{2022}", 6) . substr($email, $at) : $email, 'expiresInSeconds' => 600, 'resendInSeconds' => 10, 'debugCode' => '123456']);
                    return;
                }
                if ($action === 'verify') {
                    if ((string) ($body['code'] ?? '') === '123456') {
                        self::json(['success' => true, 'resetToken' => 'preview-token', 'expiresInSeconds' => 600]);
                    } else {
                        self::json(['success' => false, 'reason' => 'wrong', 'error' => 'That code is not right. In preview the code is 123456.'], 400);
                    }
                    return;
                }
                if ($action === 'reset') {
                    $problems = self::passwordProblems((string) ($body['password'] ?? ''));
                    $problems ? self::json(['success' => false, 'reason' => 'weak', 'error' => implode(' ', $problems)], 400) : self::json(['success' => true]);
                    return;
                }
                self::json(['success' => false, 'error' => 'Something went wrong.'], 400);
                return;

            case 'assessment-status':
                if ($pv['assessment'] === null) {
                    self::json(['completed' => false, 'window' => self::windowState($pv)]);
                } else {
                    self::json(['completed' => true, 'completedAt' => $pv['assessment']['completedAt'], 'scores' => $pv['assessment']['scores'], 'topTypes' => $pv['assessment']['topTypes']]);
                }
                return;

            case 'verify-access-code':
                if ($pv['assessment'] !== null) {
                    self::json(['success' => false, 'alreadyTaken' => true, 'error' => 'You have already taken this assessment. Please seek the assistance of the Guidance Office.'], 403);
                    return;
                }
                $w = self::windowState($pv);
                if ($w['state'] === 'none' || $w['state'] === 'ended') {
                    self::json(['success' => false, 'error' => 'No assessment is scheduled for your group yet. Please ask your guidance counselor.'], 403);
                    return;
                }
                if ($w['state'] === 'upcoming') {
                    self::json(['success' => false, 'error' => 'This assessment has not started yet. It opens on ' . $w['examDate'] . '.'], 403);
                    return;
                }
                if (strtoupper(trim((string) ($body['code'] ?? ''))) !== self::ACCESS_CODE) {
                    self::json(['success' => false, 'error' => 'Incorrect access code. In preview the code is ' . self::ACCESS_CODE . '.'], 403);
                    return;
                }
                $pv['unlocked'] = true;
                self::save($pv);
                self::json(['success' => true]);
                return;

            case 'assessment-questions':
                $questions = [];
                $id = 0;
                foreach (QuestionBank::ONET_MINI_IP as $dimension => $texts) {
                    foreach ($texts as $i => $text) {
                        $questions[] = ['id' => ++$id, 'dimension' => $dimension, 'orderIndex' => $i + 1, 'text' => $text, 'isActive' => true];
                    }
                }
                self::json(['questions' => $questions]);
                return;

            case 'assessment-submit':
                $answers = $body['answers'] ?? null;
                if (!is_array($answers) || count($answers) !== 30) {
                    self::json(['success' => false, 'error' => 'The questions were updated while you were answering. Please start the assessment again.'], 409);
                    return;
                }
                if ($pv['assessment'] !== null) {
                    self::json(['success' => false, 'error' => 'You have already completed the assessment.'], 403);
                    return;
                }
                if (!$pv['unlocked']) {
                    self::json(['success' => false, 'error' => 'Enter the assessment access code before submitting.'], 403);
                    return;
                }
                $raw = array_fill_keys(QuestionBank::DIMENSIONS, 0);
                $i = 0;
                foreach (QuestionBank::ONET_MINI_IP as $dimension => $texts) {
                    foreach ($texts as $_) {
                        $raw[$dimension] += (int) ($answers[$i++] ?? 0);
                    }
                }
                $scores = [];
                foreach ($raw as $dimension => $sum) {
                    $scores[$dimension] = QuestionBank::scaleScore($sum, 5);
                }
                $pv['assessment'] = self::assessmentFrom($scores);
                self::save($pv);
                self::json(['success' => true, 'scores' => $scores, 'topTypes' => $pv['assessment']['topTypes']]);
                return;

            case 'exam-schedules':
                self::json(['schedules' => self::schedules($pv)]);
                return;

            case 'announcements':
                self::json(['announcements' => [
                    ['id' => 1, 'title' => 'Assessment Notice', 'body' => "Your career interest assessment is scheduled. Check My Assessment Schedule for the date, time and room, and get the access code from your guidance counselor on the day.", 'targetType' => 'all', 'publishAt' => date('Y-m-d H:i:sP', time() - 3600), 'createdAt' => date('Y-m-d H:i:sP', time() - 3600)],
                ]]);
                return;

            case 'recommendations':
                self::json(self::recommendation($pv));
                return;

            case 'programs':
                self::json(['programs' => self::programs()]);
                return;

            case 'career-match':
                $typed = CareerMatcher::sanitize((string) ($_GET['q'] ?? ''));
                if ($typed === null) {
                    self::json(['career' => null, 'matched' => false]);
                    return;
                }
                $programs = self::programs();
                $id = CareerMatcher::resolve($typed, $programs, $pv['assessment']['scores'] ?? null);
                foreach ($programs as $p) {
                    if ($id !== null && (int) $p['id'] === $id) {
                        self::json(['career' => $typed, 'matched' => true, 'program' => ['id' => (int) $p['id'], 'title' => $p['title'], 'collegeCode' => $p['collegeCode'], 'collegeName' => $p['collegeName']]]);
                        return;
                    }
                }
                self::json(['career' => $typed, 'matched' => false]);
                return;

            case 'worksheet-submit':
                if ($pv['assessment'] === null) {
                    self::json(['success' => false, 'error' => 'Complete the RIASEC assessment before submitting the worksheet.'], 400);
                    return;
                }
                $career = CareerMatcher::sanitize((string) ($body['career'] ?? ''));
                $electives = $body['electives'] ?? [];
                if ($career === null) {
                    self::json(['success' => false, 'error' => 'Please type the career you want to take (letters and numbers only, up to 80 characters).'], 400);
                    return;
                }
                if (!is_array($electives) || count($electives) === 0) {
                    self::json(['success' => false, 'error' => 'Please select at least one elective.'], 400);
                    return;
                }
                $pv['worksheet'] = ['career' => $career, 'programId' => CareerMatcher::resolve($career, self::programs(), $pv['assessment']['scores']), 'electives' => array_values(array_map('strval', $electives))];
                self::save($pv);
                self::json(['success' => true, 'worksheetId' => 1, 'recommendationId' => 1]);
                return;

            case 'saved-programs':
                if ($method === 'POST') {
                    $id = (int) ($body['programId'] ?? 0);
                    if ($id <= 0) {
                        self::json(['success' => false, 'error' => 'Missing programId.'], 400);
                        return;
                    }
                    $now = in_array($id, $pv['saved'], true);
                    $pv['saved'] = $now ? array_values(array_diff($pv['saved'], [$id])) : array_merge([$id], $pv['saved']);
                    self::save($pv);
                    self::json(['success' => true, 'saved' => !$now]);
                    return;
                }
                if (isset($_GET['details'])) {
                    $byId = [];
                    foreach (self::programs() as $p) {
                        $byId[(int) $p['id']] = $p;
                    }
                    $list = [];
                    foreach ($pv['saved'] as $id) {
                        if (isset($byId[$id])) {
                            $p = $byId[$id];
                            $list[] = ['id' => (int) $p['id'], 'title' => $p['title'], 'hollandCode' => $p['hollandCode'], 'description' => $p['description'], 'isActive' => $p['status'] === 'Active', 'collegeCode' => $p['collegeCode'], 'collegeName' => $p['collegeName'], 'savedAt' => date('Y-m-d H:i:sP')];
                        }
                    }
                    self::json(['programs' => $list]);
                    return;
                }
                self::json(['programIds' => array_map('intval', $pv['saved'])]);
                return;

            case 'faqs':
                self::json(self::faqs());
                return;

            case 'public-settings':
                self::json(['officeHours' => ['text' => 'Mon–Thu, 8:00 AM–5:00 PM; Sat, 8:00 AM–5:00 PM'], 'academicYear' => ['current' => '2026-2027']]);
                return;

            case 'help-requests':
                if ($method === 'POST') {
                    $subject = trim((string) ($body['subject'] ?? ''));
                    $message = trim((string) ($body['message'] ?? ''));
                    if ($subject === '' || $message === '') {
                        self::json(['success' => false, 'error' => 'Subject and message are required.'], 400);
                        return;
                    }
                    $pv['queue']++;
                    self::save($pv);
                    self::json(['success' => true, 'id' => $pv['queue'], 'queueNumber' => $pv['queue']]);
                    return;
                }
                self::json(['requests' => [], 'openCount' => 0]);
                return;

            case 'notifications':
                self::json(self::notifications($pv, isset($_GET['full'])));
                return;

            case 'notifications-read':
                self::json(['success' => true]);
                return;

            case 'notifications-dismiss':
                $pv['dismissed'] = array_values(array_unique(array_merge($pv['dismissed'], array_map('strval', (array) ($body['keys'] ?? [])))));
                self::save($pv);
                self::json(['success' => true]);
                return;

            case 'my-account':
                self::json(['success' => true, 'email' => 'juan.delacruz@live.mcl.edu.ph', 'memberSince' => '2026-09-20 09:00:00+08']);
                return;

            case 'update-avatar':
                $pv['avatar'] = array_key_exists('avatarDataUrl', $body) ? $body['avatarDataUrl'] : null;
                self::save($pv);
                self::json(['success' => true, 'avatarUrl' => $pv['avatar']]);
                return;

            case 'change-password':
                if ((string) ($body['currentPassword'] ?? '') !== self::PASSWORD) {
                    self::json(['success' => false, 'error' => 'Current password is incorrect. In preview it is ' . self::PASSWORD . '.'], 400);
                    return;
                }
                $problems = self::passwordProblems((string) ($body['newPassword'] ?? ''));
                $problems ? self::json(['success' => false, 'error' => implode(' ', $problems)], 400) : self::json(['success' => true]);
                return;
        }

        self::json(['error' => 'This request is not part of the student preview.'], 404);
    }
}
