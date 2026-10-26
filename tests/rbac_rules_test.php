<?php

// The permission table's needs rules (modules/Authentication/lib/Rbac.php NEEDS, ACTION_ONLY): a feature counts only
// while what it needs is on, and an action is Full or No Access. Role names and levels only. No database needed.
//   php tests\rbac_rules_test.php

require_once __DIR__ . '/../modules/Authentication/lib/Rbac.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failures++;
        echo "  FAIL: $label" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    }
}

/** Every feature at No Access, then the given levels. */
function levels(array $set): array
{
    return array_merge(array_fill_keys(Rbac::modules(), 'none'), $set);
}

echo "=== the rules themselves ===\n";
$features = Rbac::modules();
$bad = [];
foreach (Rbac::NEEDS as $m => $needs) {
    if (!in_array($m, $features, true) || !$needs || array_diff($needs, $features) || in_array($m, $needs, true)) {
        $bad[] = $m;
    }
}
check('every rule names real features, never itself', !$bad, implode(', ', $bad));
check('no chains: a needed feature has no needs of its own (one pass is enough)', !array_intersect(array_merge(...array_values(Rbac::NEEDS)), array_keys(Rbac::NEEDS)));
check('action-only features are real features', !array_diff(Rbac::ACTION_ONLY, $features));

echo "\n=== the defaults already follow every rule (Table 5 does not change) ===\n";
foreach (['counselor', 'facilitator'] as $role) {
    $d = array_map(fn($x) => $x[$role], Rbac::DEFAULTS);
    check("$role defaults: nothing unmet", Rbac::unmetNeeds($d) === [], json_encode(Rbac::unmetNeeds($d)));
    $actionLimited = array_filter(Rbac::ACTION_ONLY, fn($m) => $d[$m] === 'limited');
    check("$role defaults: no action at View Only", !$actionLimited, implode(', ', $actionLimited));
    check("$role defaults: the rules change nothing", Rbac::effectiveLevels($d) === $d);
}

echo "\n=== what counts ===\n";
$e = Rbac::effectiveLevels(levels(['analyticsEmail' => 'full']));
check('Send analytics report without Analytics does not count', $e['analyticsEmail'] === 'none');
$e = Rbac::effectiveLevels(levels(['analyticsEmail' => 'full', 'analytics' => 'limited']));
check('Send analytics report with Analytics (View Only) counts', $e['analyticsEmail'] === 'full' && $e['analytics'] === 'limited');
$e = Rbac::effectiveLevels(levels(['studentAccounts' => 'full', 'notesCreate' => 'full']));
check('account management and notes without Student information do not count', $e['studentAccounts'] === 'none' && $e['notesCreate'] === 'none');
$e = Rbac::effectiveLevels(levels(['studentAccounts' => 'limited', 'notesCreate' => 'full', 'studentInfo' => 'limited']));
check('with Student information they count', $e['studentAccounts'] === 'limited' && $e['notesCreate'] === 'full');
$e = Rbac::effectiveLevels(levels(['assessmentResults' => 'full', 'dashboard' => 'limited']));
check('Assessment results count with Dashboard alone (Course Match chart)', $e['assessmentResults'] === 'full');
$e = Rbac::effectiveLevels(levels(['assessmentResults' => 'full']));
check('Assessment results with neither Student information nor Dashboard do not count', $e['assessmentResults'] === 'none');
$e = Rbac::effectiveLevels(levels(['exportResults' => 'limited', 'analytics' => 'limited']));
check('Export student results count with Analytics alone (report PDF)', $e['exportResults'] === 'limited');
$e = Rbac::effectiveLevels(levels(['exportResults' => 'full']));
check('Export student results with neither do not count', $e['exportResults'] === 'none');
$e = Rbac::effectiveLevels(levels(['analyticsEmail' => 'limited', 'analytics' => 'full', 'notesCreate' => 'limited', 'studentInfo' => 'full', 'counselingStatus' => 'limited']));
check('an action at View Only does not count (send, notes, counseling status)', $e['analyticsEmail'] === 'none' && $e['notesCreate'] === 'none' && $e['counselingStatus'] === 'none');
$e = Rbac::effectiveLevels(levels(['counselingStatus' => 'full']));
check('Update counseling status alone still counts (invite from Student Information and Monitoring)', $e['counselingStatus'] === 'full');
check('the features that are needed are never turned off by the rules', Rbac::effectiveLevels(levels(['analytics' => 'limited']))['analytics'] === 'limited');
$u = Rbac::unmetNeeds(levels(['exportResults' => 'full', 'analyticsEmail' => 'full']));
check('unmetNeeds names what is missing', ($u['exportResults'] ?? null) === ['studentInfo', 'analytics'] && ($u['analyticsEmail'] ?? null) === ['analytics'], json_encode($u));

echo "\n=== what the table offers ===\n";
foreach (Rbac::ACTION_ONLY as $m) {
    $offer = Rbac::editableLevels('admin', $m, 'counselor', []);
    check("$m: Full or No Access only", $offer === ['full', 'none'], json_encode($offer));
}
check('other features keep all three levels', Rbac::editableLevels('admin', 'analytics', 'counselor', []) === Rbac::LEVELS
    && Rbac::editableLevels('admin', 'studentAccounts', 'facilitator', []) === Rbac::LEVELS);
check('Counseling Requests opens with View counseling history only', Rbac::PAGES['counseling-requests'] === ['counselingHistory']);

echo "\n=== Summary: $passed passed, $failures failed ===\n";
exit($failures > 0 ? 1 : 0);
