/*
 * Forgot password: the four screens (email, verify code, new password, success) behind forgot-password.html (students)
 * and staff-forgot-password.html (guidance staff). Same behaviour for both; the page's #main-content says which one
 * it is (data-portal) and where "sign in" is (data-signin). Talks to api/password-reset.php.
 */
(function () {
  'use strict';

  var card = document.getElementById('main-content');
  var portal = card.dataset.portal;
  var signInUrl = card.dataset.signin;
  var API = 'api/password-reset.php';

  function $(id) { return document.getElementById(id); }
  var steps = { 1: $('stepEmail'), 2: $('stepCode'), 3: $('stepPassword'), 4: $('stepDone') };
  var state = { email: '', masked: '', token: '', codeExpiresAt: 0, resendAt: 0, policy: null };
  var timer = null;

  function post(payload) {
    payload.portal = portal;
    return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
      .then(function (res) { return res.json().catch(function () { return {}; }).then(function (data) { return { status: res.status, data: data }; }); })
      .catch(function () { return { status: 0, data: { error: 'Unable to reach the server. Please check your connection and try again.' } }; });
  }

  function say(el, text) {
    el.textContent = text || '';
    el.classList.toggle('hidden', !text);
  }
  function busy(btn, on, label) {
    if (on) { btn.dataset.label = btn.textContent; btn.textContent = label; } else if (btn.dataset.label) { btn.textContent = btn.dataset.label; }
    btn.disabled = on;
  }

  // ---------------------------------------------------------------- navigation

  function show(n, push) {
    Object.keys(steps).forEach(function (k) { steps[k].classList.toggle('hidden', Number(k) !== n); });
    if (push) history.pushState({ step: n }, '');
    var focusTarget = { 1: $('email'), 2: document.querySelector('.otp'), 3: $('newPassword'), 4: $('backToSignIn') }[n];
    if (focusTarget) setTimeout(function () { focusTarget.focus(); }, 30);
  }
  history.replaceState({ step: 1 }, '');
  window.addEventListener('popstate', function () {
    // Back from a later step starts again at the first: a used code can't be re-entered.
    state.token = '';
    stopTimer();
    show(1, false);
  });

  // ---------------------------------------------------------------- step 1: email

  var emailForm = $('emailForm');
  emailForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var email = $('email').value.trim().toLowerCase();
    say($('emailError'), '');
    if (!email) { say($('emailError'), 'Enter your email address.'); return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { say($('emailError'), 'Enter a valid email address.'); return; }
    sendCode(email, $('sendBtn'), 'Sending…', function (message) { say($('emailError'), message); }, true);
  });

  /** Asks for a code. Used by "Send Verification Code" and "Resend Code". */
  function sendCode(email, button, busyLabel, onError, fromStep1) {
    busy(button, true, busyLabel);
    post({ action: 'request', email: email }).then(function (r) {
      busy(button, false);
      if (r.data.success) {
        state.email = email;
        state.masked = r.data.maskedEmail;
        state.codeExpiresAt = Date.now() + r.data.expiresInSeconds * 1000;
        state.resendAt = Date.now() + r.data.resendInSeconds * 1000;
        $('maskedEmail').textContent = state.masked;
        clearBoxes();
        say($('codeError'), '');
        say($('codeNotice'), fromStep1 ? '' : 'A new code was sent.');
        startTimer();
        if (fromStep1) show(2, true);
        return;
      }
      if (r.data.reason === 'cooldown' && fromStep1 && state.email === email && state.codeExpiresAt > Date.now()) {
        // They went back a moment after asking: the code already sent is still good.
        say($('codeNotice'), 'A code was already sent a moment ago. Enter it below.');
        startTimer();
        show(2, true);
        return;
      }
      if (r.data.reason === 'cooldown') { state.resendAt = Date.now() + (r.data.retryAfter || 60) * 1000; startTimer(); }
      onError(r.data.error || 'Something went wrong. Please try again.');
    });
  }

  // ---------------------------------------------------------------- step 2: code

  var boxes = Array.prototype.slice.call(document.querySelectorAll('.otp'));
  function codeValue() { return boxes.map(function (b) { return b.value; }).join(''); }
  function clearBoxes() { boxes.forEach(function (b) { b.value = ''; b.classList.remove('otp-bad'); }); updateVerify(); }
  function updateVerify() { $('verifyBtn').disabled = codeValue().length !== 6 || Date.now() >= state.codeExpiresAt; }

  boxes.forEach(function (box, i) {
    box.addEventListener('input', function () {
      box.value = box.value.replace(/\D/g, '').slice(-1);
      box.classList.remove('otp-bad');
      if (box.value && i < boxes.length - 1) boxes[i + 1].focus();
      updateVerify();
    });
    box.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !box.value && i > 0) { boxes[i - 1].focus(); boxes[i - 1].value = ''; updateVerify(); }
      if (e.key === 'ArrowLeft' && i > 0) boxes[i - 1].focus();
      if (e.key === 'ArrowRight' && i < boxes.length - 1) boxes[i + 1].focus();
    });
    box.addEventListener('paste', function (e) {
      var digits = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
      if (!digits) return;
      e.preventDefault();
      boxes.forEach(function (b, k) { b.value = digits[k] || ''; });
      boxes[Math.min(digits.length, 5)].focus();
      updateVerify();
    });
    box.addEventListener('focus', function () { box.select(); });
  });

  function fmt(ms) {
    var s = Math.max(0, Math.ceil(ms / 1000));
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
  }
  function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }
  function tick() {
    var now = Date.now();
    var left = state.codeExpiresAt - now;
    $('expiresText').textContent = left > 0 ? 'Code expires in ' + fmt(left) : 'This code has expired.';
    $('expiresText').style.color = left > 0 && left < 60000 ? '#b45309' : (left > 0 ? '#6b7280' : '#ed1c24');
    var wait = state.resendAt - now;
    var resend = $('resendBtn');
    resend.disabled = wait > 0;
    resend.textContent = wait > 0 ? 'Resend code in ' + fmt(wait) : 'Resend Code';
    if (left <= 0) { $('verifyBtn').disabled = true; }
    if (left <= 0 && wait <= 0) stopTimer();
  }
  function startTimer() { stopTimer(); tick(); timer = setInterval(tick, 1000); }

  $('codeForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var code = codeValue();
    say($('codeError'), ''); say($('codeNotice'), '');
    if (code.length !== 6) { say($('codeError'), 'Enter all 6 digits.'); return; }
    busy($('verifyBtn'), true, 'Verifying…');
    post({ action: 'verify', email: state.email, code: code }).then(function (r) {
      $('verifyBtn').disabled = false;
      busy($('verifyBtn'), false);
      if (r.data.success) {
        state.token = r.data.resetToken;
        stopTimer();
        show(3, true);
        return;
      }
      say($('codeError'), r.data.error || 'Something went wrong. Please try again.');
      boxes.forEach(function (b) { b.classList.add('otp-bad'); });
      if (r.data.reason === 'wrong') { boxes.forEach(function (b) { b.value = ''; }); boxes[0].focus(); }
      if (r.data.reason === 'expired' || r.data.reason === 'locked') { state.codeExpiresAt = 0; tick(); }
      updateVerify();
    });
  });

  $('resendBtn').addEventListener('click', function () {
    say($('codeError'), ''); say($('codeNotice'), '');
    var btn = $('resendBtn');
    sendCode(state.email, btn, 'Sending…', function (message) { say($('codeError'), message); }, false);
    // sendCode restores the label itself; the timer text takes over on the next tick
  });
  $('codeBack').addEventListener('click', function () {
    stopTimer();
    $('email').value = state.email;
    history.back();
  });

  // ---------------------------------------------------------------- step 3: new password

  var RULES = [
    { id: 'length', text: function (p) { return 'At least ' + p.minLength + ' characters'; }, on: function () { return true; }, ok: function (v, p) { return v.length >= p.minLength; } },
    { id: 'upper', text: function () { return 'One uppercase letter'; }, on: function (p) { return p.requireUpper; }, ok: function (v) { return /[A-Z]/.test(v); } },
    { id: 'lower', text: function () { return 'One lowercase letter'; }, on: function (p) { return p.requireLower; }, ok: function (v) { return /[a-z]/.test(v); } },
    { id: 'number', text: function () { return 'One number'; }, on: function (p) { return p.requireNumber; }, ok: function (v) { return /[0-9]/.test(v); } },
    { id: 'symbol', text: function () { return 'One special character (like ! @ # ?)'; }, on: function (p) { return p.requireSymbol; }, ok: function (v) { return /[^A-Za-z0-9]/.test(v); } },
    // Always on, like the server (PasswordPolicy::errors): a space a phone adds at either end is easy to miss.
    { id: 'edges', text: function () { return 'No space at the start or end'; }, on: function () { return true; }, ok: function (v) { return v !== '' && !/^\s|\s$/.test(v); } }
  ];
  var policy = { minLength: 8, requireUpper: true, requireLower: true, requireNumber: true, requireSymbol: true };
  fetch(API + '?policy=1').then(function (r) { return r.json(); }).then(function (p) { if (p && p.minLength) { policy = p; } renderChecklist(); }).catch(renderChecklist);

  function renderChecklist() {
    $('rules').innerHTML = RULES.filter(function (r) { return r.on(policy); }).map(function (r) {
      return '<li data-rule="' + r.id + '" style="display:flex;align-items:center;gap:8px;color:#6b7280"><span class="mark" aria-hidden="true" style="display:inline-flex;width:16px;height:16px;border-radius:9999px;border:1.5px solid #9ca3af;align-items:center;justify-content:center;font-size:11px;line-height:1"></span><span>' + r.text(policy) + '</span></li>';
    }).join('');
    checkPassword();
  }
  function checkPassword() {
    var v = $('newPassword').value;
    var allOk = true;
    RULES.filter(function (r) { return r.on(policy); }).forEach(function (r) {
      var li = document.querySelector('#rules [data-rule="' + r.id + '"]');
      if (!li) return;
      var ok = r.ok(v, policy);
      if (!ok) allOk = false;
      li.style.color = ok ? '#047857' : '#6b7280';
      var mark = li.querySelector('.mark');
      mark.textContent = ok ? '✓' : '';
      mark.style.borderColor = ok ? '#047857' : '#9ca3af';
      mark.style.background = ok ? '#ecfdf5' : 'transparent';
      li.setAttribute('aria-label', r.text(policy) + (ok ? ', met' : ', not met yet'));
    });
    var c = $('confirmPassword').value;
    var match = v !== '' && v === c;
    var note = $('matchNote');
    note.textContent = c === '' ? '' : (match ? 'Passwords match.' : "Passwords don't match.");
    note.style.color = match ? '#047857' : '#ed1c24';
    $('resetBtn').disabled = !(allOk && match);
  }
  $('newPassword').addEventListener('input', checkPassword);
  $('confirmPassword').addEventListener('input', checkPassword);

  $('passwordForm').addEventListener('submit', function (e) {
    e.preventDefault();
    say($('passwordError'), '');
    busy($('resetBtn'), true, 'Resetting…');
    post({ action: 'reset', resetToken: state.token, password: $('newPassword').value }).then(function (r) {
      busy($('resetBtn'), false);
      if (r.data.success) {
        state.token = '';
        $('newPassword').value = ''; $('confirmPassword').value = '';
        show(4, true);
        return;
      }
      say($('passwordError'), r.data.error || 'Something went wrong. Please try again.');
      $('startAgain').classList.toggle('hidden', r.data.reason !== 'expired');
      checkPassword();
    });
  });
  $('startAgain').addEventListener('click', function (e) { e.preventDefault(); history.back(); });

  $('backToSignIn').setAttribute('href', signInUrl);
  renderChecklist();
})();
