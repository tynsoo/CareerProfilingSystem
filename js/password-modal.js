/*
 * "Edit" password pop-up for the staff My Profile and student My Profile pages.
 *
 * The page only needs a button with id="editPasswordBtn". This script adds the pop-up (current password, new
 * password, confirm, a live checklist of the password rules set in Security Configuration), sends it to
 * api/change-password.php, and, for staff when the verification setting is on, asks for the 6-digit code that was
 * emailed (api/verify-password-change.php) before the change takes effect.
 *
 * Include it BEFORE js/password-toggle.js so the eye buttons are added to the pop-up's fields:
 *   <script src="js/password-modal.js" defer></script>
 *   <script src="js/password-toggle.js" defer></script>
 */
(function () {
  var INPUT = 'w-full border border-gray-300 rounded-md px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-[#ed1c24] focus:border-transparent';
  var LABEL = 'block text-xs font-medium text-gray-600 mb-1';

  var html =
    '<div id="pwModal" class="modal-backdrop hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">' +
    '  <div class="modal-card bg-white rounded-xl shadow-lg w-full max-w-md p-6 flex flex-col gap-4" role="dialog" aria-modal="true" aria-labelledby="pwModalTitle">' +

    '    <form id="pwStepForm" class="flex flex-col gap-4" novalidate>' +
    '      <div>' +
    '        <h3 id="pwModalTitle" class="text-lg font-semibold text-gray-900">Change Password</h3>' +
    '        <p class="text-sm text-gray-500 mt-1">Enter your current password, then choose a new one.</p>' +
    '      </div>' +
    '      <div><label class="' + LABEL + '" for="pwCurrent">Current Password</label>' +
    '        <input type="password" id="pwCurrent" autocomplete="current-password" class="' + INPUT + '"></div>' +
    '      <div><label class="' + LABEL + '" for="pwNew">New Password</label>' +
    '        <input type="password" id="pwNew" autocomplete="new-password" class="' + INPUT + '"></div>' +
    '      <ul id="pwRules" style="list-style:none;margin:-4px 0 0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;font-size:12px"></ul>' +
    '      <div><label class="' + LABEL + '" for="pwConfirm">Confirm New Password</label>' +
    '        <input type="password" id="pwConfirm" autocomplete="new-password" class="' + INPUT + '">' +
    '        <p id="pwMatch" class="hidden" style="font-size:12px;margin-top:4px"></p></div>' +
    '      <div id="pwError" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-md px-4 py-2.5" role="alert"></div>' +
    '      <div class="flex justify-end gap-2 pt-1">' +
    '        <button type="button" id="pwCancel" class="px-4 py-2 text-sm font-medium text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50">Cancel</button>' +
    '        <button type="submit" id="pwSave" class="px-4 py-2 text-sm font-medium text-white bg-[#ed1c24] rounded-md hover:opacity-90">Save Password</button>' +
    '      </div>' +
    '    </form>' +

    '    <div id="pwStepCode" class="hidden flex flex-col gap-4">' +
    '      <div>' +
    '        <h3 class="text-lg font-semibold text-gray-900">Verify It&rsquo;s You</h3>' +
    '        <p class="text-sm text-gray-500 mt-1">Enter the 6-digit code we emailed you to confirm this change. This code lasts 5 minutes<span id="pwCodeUntil"></span>. It is not the 24-hour access code from your approval email.</p>' +
    '      </div>' +
    '      <div id="pwCodeError" class="hidden bg-red-50 border border-red-200 text-red-700 text-sm rounded-md px-4 py-2.5" role="alert"></div>' +
    '      <div id="pwCodeDebug" class="hidden bg-amber-50 border border-amber-200 text-amber-700 text-sm rounded-md px-4 py-2.5"></div>' +
    '      <input id="pwCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" aria-label="6-digit code" ' +
    '        class="w-full max-w-[220px] text-center tracking-[0.5em] text-lg py-3 border border-gray-300 rounded-md placeholder-gray-300 focus:outline-none focus:ring-2 focus:ring-[#ed1c24] focus:border-transparent">' +
    '      <div class="flex items-center gap-3">' +
    '        <button type="button" id="pwVerify" class="px-4 py-2 text-sm font-medium text-white bg-[#ed1c24] rounded-md hover:opacity-90">Verify &amp; Save</button>' +
    '        <button type="button" id="pwResend" class="text-[#ed1c24] text-sm font-medium hover:underline">Resend code</button>' +
    '        <button type="button" id="pwCodeCancel" class="text-gray-500 text-sm hover:underline">Cancel</button>' +
    '      </div>' +
    '    </div>' +

    '    <div id="pwStepDone" class="hidden flex flex-col items-center text-center gap-3 py-2">' +
    '      <span style="width:52px;height:52px;border-radius:9999px;background:#f0fdf4;display:flex;align-items:center;justify-content:center">' +
    '        <svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" style="width:26px;height:26px"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>' +
    '      </span>' +
    '      <h3 class="text-lg font-semibold text-gray-900">Password updated</h3>' +
    '      <p class="text-sm text-gray-500">Use your new password the next time you sign in.</p>' +
    '      <button type="button" id="pwDone" class="mt-1 px-4 py-2 text-sm font-medium text-white bg-[#ed1c24] rounded-md hover:opacity-90">Done</button>' +
    '    </div>' +
    '  </div>' +
    '</div>';

  var wrap = document.createElement('div');
  wrap.innerHTML = html;
  document.body.appendChild(wrap.firstChild);

  function $(id) { return document.getElementById(id); }
  var modal = $('pwModal');
  var steps = { form: $('pwStepForm'), code: $('pwStepCode'), done: $('pwStepDone') };
  var policy = null;
  var busy = false;

  function show(step) {
    Object.keys(steps).forEach(function (k) { steps[k].classList.toggle('hidden', k !== step); });
  }
  function showMsg(el, text) { el.textContent = text; el.classList.remove('hidden'); }
  function hideMsg(el) { el.classList.add('hidden'); }

  function loadPolicy() {
    if (policy) return Promise.resolve(policy);
    return fetch('api/password-reset.php?policy=1').then(function (r) { return r.json(); }).then(function (p) {
      policy = p;
      return p;
    }).catch(function () { policy = { minLength: 8, requireUpper: true, requireLower: true, requireNumber: true, requireSymbol: true }; return policy; });
  }

  var RULES = [
    { id: 'len', text: function (p) { return 'At least ' + p.minLength + ' characters'; }, on: function () { return true; }, ok: function (v, p) { return v.length >= p.minLength; } },
    { id: 'up', text: function () { return 'An uppercase letter'; }, on: function (p) { return p.requireUpper; }, ok: function (v) { return /[A-Z]/.test(v); } },
    { id: 'low', text: function () { return 'A lowercase letter'; }, on: function (p) { return p.requireLower; }, ok: function (v) { return /[a-z]/.test(v); } },
    { id: 'num', text: function () { return 'A number'; }, on: function (p) { return p.requireNumber; }, ok: function (v) { return /[0-9]/.test(v); } },
    { id: 'sym', text: function () { return 'A symbol (like ! @ # ?)'; }, on: function (p) { return p.requireSymbol; }, ok: function (v) { return /[^A-Za-z0-9]/.test(v); } },
    // Always on, like the server (PasswordPolicy::errors): a space a phone adds at either end is easy to miss.
    { id: 'edge', text: function () { return 'No space at the start or end'; }, on: function () { return true; }, ok: function (v) { return v !== '' && !/^\s|\s$/.test(v); } }
  ];

  function renderRules() {
    var v = $('pwNew').value;
    var list = $('pwRules');
    list.innerHTML = RULES.filter(function (r) { return r.on(policy); }).map(function (r) {
      var good = r.ok(v, policy);
      return '<li style="color:' + (good ? '#15803d' : '#6b7280') + '">' + (good ? '&#10003;' : '&#9675;') + ' ' + r.text(policy) + '</li>';
    }).join('');
    var m = $('pwMatch'), c = $('pwConfirm').value;
    if (c === '') { m.classList.add('hidden'); return; }
    var same = c === v;
    m.textContent = same ? 'Passwords match' : 'Passwords do not match yet';
    m.style.color = same ? '#15803d' : '#b91c1c';
    m.classList.remove('hidden');
  }

  function open() {
    ['pwCurrent', 'pwNew', 'pwConfirm', 'pwCode'].forEach(function (id) { $(id).value = ''; });
    hideMsg($('pwError')); hideMsg($('pwCodeError')); hideMsg($('pwCodeDebug'));
    show('form');
    loadPolicy().then(renderRules);
    modal.classList.remove('hidden');
    setTimeout(function () { modal.classList.add('is-open'); $('pwCurrent').focus(); }, 16);
  }
  function close() {
    if (busy) return;
    modal.classList.remove('is-open');
    setTimeout(function () { modal.classList.add('hidden'); }, 260);
  }

  function post(url, body) {
    return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) })
      .then(function (r) { return r.json(); });
  }
  function setBusy(btn, on, label) {
    busy = on; btn.disabled = on;
    if (label) btn.textContent = on ? label : btn.dataset.label;
  }

  function finished() {
    show('done');
    document.dispatchEvent(new CustomEvent('pp:password-changed'));
  }

  $('pwNew').addEventListener('input', renderRules);
  $('pwConfirm').addEventListener('input', renderRules);
  $('pwCancel').addEventListener('click', close);
  $('pwCodeCancel').addEventListener('click', close);
  $('pwDone').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });

  $('pwStepForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var err = $('pwError'); hideMsg(err);
    var cur = $('pwCurrent').value, next = $('pwNew').value, conf = $('pwConfirm').value;
    if (!cur || !next) { showMsg(err, 'Enter your current password and a new password.'); return; }
    var missing = loadedMissing(next);
    if (missing) { showMsg(err, missing); return; }
    if (next !== conf) { showMsg(err, 'New password and confirmation do not match.'); return; }
    var btn = $('pwSave'); btn.dataset.label = 'Save Password';
    setBusy(btn, true, 'Saving…');
    post('api/change-password.php', { currentPassword: cur, newPassword: next }).then(function (data) {
      setBusy(btn, false, 'x');
      if (!data.success) { showMsg(err, data.error || 'Could not change the password. Please try again.'); return; }
      if (data.verificationRequired) {
        show('code');
        codeSent();
        if (data.debugCode) { showMsg($('pwCodeDebug'), 'Local dev (no email provider configured) — your code is: ' + data.debugCode); }
        else { hideMsg($('pwCodeDebug')); }
        $('pwCode').focus();
        return;
      }
      finished();
    }).catch(function () { setBusy(btn, false, 'x'); showMsg(err, 'Could not reach the server. Please try again.'); });
  });

  function loadedMissing(pw) {
    var problems = RULES.filter(function (r) { return r.on(policy) && !r.ok(pw, policy); });
    return problems.length ? 'Your new password still needs: ' + problems.map(function (r) { return r.text(policy).toLowerCase(); }).join(', ') + '.' : '';
  }

  // When the newest code stops working, shown as a clock time ("until 10:42 AM") so nobody has to count minutes.
  function codeSent() {
    var until = new Date(Date.now() + 5 * 60 * 1000);
    $('pwCodeUntil').textContent = ' (until ' + until.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + ')';
    $('pwCode').value = '';
  }
  if (window.PP && PP.codeInput) PP.codeInput($('pwCode'), { digits: true });

  $('pwVerify').addEventListener('click', function () {
    var err = $('pwCodeError'); hideMsg(err);
    var btn = $('pwVerify'); btn.dataset.label = 'Verify & Save';
    setBusy(btn, true, 'Checking…');
    post('api/verify-password-change.php', { code: $('pwCode').value.replace(/\D/g, '') }).then(function (data) {
      setBusy(btn, false, 'x');
      if (!data.success) {
        showMsg(err, data.error || 'Incorrect code. Please try again.');
        // Expired: stay here. Resend code sends a fresh one for the same new password, so nothing has to be typed again.
        if (data.expired) { $('pwResend').focus(); }
        return;
      }
      finished();
    }).catch(function () { setBusy(btn, false, 'x'); showMsg(err, 'Could not reach the server. Please try again.'); });
  });

  $('pwResend').addEventListener('click', function () {
    var btn = $('pwResend');
    btn.disabled = true; btn.textContent = 'Sending…';
    fetch('api/resend-password-change-code.php', { method: 'POST' }).then(function (r) { return r.json(); }).then(function (data) {
      if (data && data.debugCode) { showMsg($('pwCodeDebug'), 'Local dev (no email provider configured) — your code is: ' + data.debugCode); }
      btn.textContent = 'Sent — check your inbox';
      if (data && data.success !== false) { codeSent(); hideMsg($('pwCodeError')); }
    }).catch(function () {}).then(function () {
      setTimeout(function () { btn.textContent = 'Resend code'; btn.disabled = false; }, 3000);
    });
  });

  var trigger = document.getElementById('editPasswordBtn');
  if (trigger) trigger.addEventListener('click', open);
  window.ProfilePathPasswordModal = { open: open };
})();
