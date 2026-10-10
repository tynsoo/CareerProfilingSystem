<?php
/** The preview home: pick a student situation, then open any student page. Included by dev/preview.php. */
$pv = PreviewApi::state();
$scenarios = [
    'new' => ['A student who has not taken the assessment', 'Start at the instructions, enter the access code, answer the questions.'],
    'done' => ['Assessment done, worksheet not yet', 'Results page with scores; the worksheet is still to do.'],
    'complete' => ['Assessment and worksheet done', 'Results, recommendations, Saved Careers and the worksheet results.'],
];
$windows = ['open' => 'Open now', 'upcoming' => 'Starts in 2 days', 'ended' => 'Ended', 'none' => 'Not scheduled'];
$pages = [
    'Account' => ['student-login' => 'Sign in', 'student-register' => 'Create an account', 'student-forgot-password' => 'Forgot password', 'verify-email' => 'Verify email'],
    'Assessment' => ['assessment' => 'Assessments home', 'assessment-instructions' => 'Instructions and access code', 'riasec-assessment' => 'The assessment'],
    'Results' => ['results' => 'Career results', 'career-worksheet' => 'Career worksheet', 'worksheet-results' => 'Worksheet results', 'saved-careers' => 'Saved careers'],
    'Help and profile' => ['student-help-center' => 'Help Center', 'student-notifications' => 'Notifications', 'student-settings' => 'My Profile', 'change-password' => 'Change password'],
];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ProfilePath student preview</title>
<style>
  body { font-family: Inter, Arial, sans-serif; background:#f8fafc; color:#001c43; margin:0; }
  main { max-width: 860px; margin: 0 auto; padding: 40px 24px 80px; }
  h1 { margin: 0 0 4px; font-size: 28px; } h2 { font-size: 15px; letter-spacing:.08em; text-transform:uppercase; color:#64748b; margin: 32px 0 12px; }
  p { color:#475569; line-height:1.55; margin: 4px 0; }
  .grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; }
  .card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:16px; }
  .card strong { display:block; margin-bottom:4px; }
  .on { border-color:#ed1c24; box-shadow:0 0 0 2px #fde8e9; }
  a.btn, button { display:inline-block; margin-top:10px; background:#ed1c24; color:#fff; font:600 13px Inter,Arial,sans-serif; padding:8px 14px; border:0; border-radius:8px; text-decoration:none; cursor:pointer; }
  a.link { display:block; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:10px 14px; color:#001c43; text-decoration:none; font-weight:600; font-size:14px; }
  .tag { font-size:12px; color:#64748b; font-weight:400; } code { background:#e2e8f0; padding:1px 6px; border-radius:4px; }
  select { padding:8px; border-radius:8px; border:1px solid #cbd5e1; font:14px Inter,Arial,sans-serif; }
</style></head>
<body><main>
  <h1>ProfilePath student preview</h1>
  <p>Sample data only. No database is used. Sign in with anything; the access code is <code><?= PreviewApi::ACCESS_CODE ?></code>, the current password is <code><?= PreviewApi::PASSWORD ?></code>, and the forgot-password code is <code>123456</code>.</p>

  <h2>1. Pick the student</h2>
  <form method="get" action="/preview/set">
    <div class="grid">
      <?php foreach ($scenarios as $key => [$title, $text]): ?>
        <label class="card <?= $pv['scenario'] === $key ? 'on' : '' ?>">
          <input type="radio" name="scenario" value="<?= $key ?>" <?= $pv['scenario'] === $key ? 'checked' : '' ?>>
          <strong style="display:inline"><?= htmlspecialchars($title) ?></strong>
          <p><?= htmlspecialchars($text) ?></p>
        </label>
      <?php endforeach; ?>
    </div>
    <p style="margin-top:14px">Assessment schedule:
      <select name="window">
        <?php foreach ($windows as $k => $label): ?><option value="<?= $k ?>" <?= $pv['window'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
      </select>
    </p>
    <input type="hidden" name="go" value="/preview">
    <button type="submit">Apply (this also resets the preview student)</button>
  </form>

  <h2>2. Open a page</h2>
  <?php foreach ($pages as $group => $list): ?>
    <p class="tag"><?= $group ?></p>
    <div class="grid" style="margin-bottom:12px">
      <?php foreach ($list as $slug => $label): ?><a class="link" href="/<?= $slug ?>"><?= htmlspecialchars($label) ?> <span class="tag">/<?= $slug ?></span></a><?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</main></body></html>
