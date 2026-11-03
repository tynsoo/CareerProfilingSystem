/*
 * Live password checklist and "passwords match" note for the pages where a new password is created from an email
 * (verify-email.html for students, staff-activate.html for staff). The rules are the ones the server enforces:
 * api/password-reset.php?policy=1 (PasswordPolicy::rules) plus the always-on "no space at the start or end"
 * (PasswordPolicy::errors). Same look as the Forgot Password checklist (js/password-reset.js).
 *
 * Load it before the page's own script (not deferred), then:
 *   var check = PasswordChecklist.attach(newPasswordInput, confirmInput);
 *   check.missing()  ->  '' when every rule is met, otherwise one sentence naming what is still needed.
 * Works with js/password-toggle.js loaded before or after it.
 */
(function () {
  'use strict';

  var RULES = [
    { id: 'length', text: function (p) { return 'At least ' + p.minLength + ' characters'; }, on: function () { return true; }, ok: function (v, p) { return v.length >= p.minLength; } },
    { id: 'upper', text: function () { return 'One uppercase letter'; }, on: function (p) { return p.requireUpper; }, ok: function (v) { return /[A-Z]/.test(v); } },
    { id: 'lower', text: function () { return 'One lowercase letter'; }, on: function (p) { return p.requireLower; }, ok: function (v) { return /[a-z]/.test(v); } },
    { id: 'number', text: function () { return 'One number'; }, on: function (p) { return p.requireNumber; }, ok: function (v) { return /[0-9]/.test(v); } },
    { id: 'symbol', text: function () { return 'One special character (like ! @ # ?)'; }, on: function (p) { return p.requireSymbol; }, ok: function (v) { return /[^A-Za-z0-9]/.test(v); } },
    { id: 'edges', text: function () { return 'No space at the start or end'; }, on: function () { return true; }, ok: function (v) { return v !== '' && !/^\s|\s$/.test(v); } }
  ];

  // The field itself, or the wrapper js/password-toggle.js put around it (the eye button sits in that wrapper).
  function placeAfter(input, el) {
    var anchor = input.dataset.toggleReady ? input.parentNode : input;
    anchor.parentNode.insertBefore(el, anchor.nextSibling);
  }

  function attach(newInput, confirmInput) {
    var policy = { minLength: 8, requireUpper: true, requireLower: true, requireNumber: true, requireSymbol: true };

    var list = document.createElement('ul');
    list.style.cssText = 'list-style:none;margin:8px 0 0;padding:0;display:flex;flex-direction:column;gap:4px;font-size:12px;text-align:left';
    placeAfter(newInput, list);

    var note = document.createElement('p');
    note.setAttribute('aria-live', 'polite');
    note.style.cssText = 'font-size:12px;margin:6px 0 0;text-align:left';
    placeAfter(confirmInput, note);

    function active() { return RULES.filter(function (r) { return r.on(policy); }); }

    function render() {
      var v = newInput.value;
      list.innerHTML = '';
      active().forEach(function (r) {
        var ok = r.ok(v, policy);
        var li = document.createElement('li');
        li.style.cssText = 'display:flex;align-items:center;gap:8px;color:' + (ok ? '#047857' : '#6b7280');
        li.setAttribute('aria-label', r.text(policy) + (ok ? ', met' : ', not met yet'));
        var mark = document.createElement('span');
        mark.setAttribute('aria-hidden', 'true');
        mark.style.cssText = 'display:inline-flex;flex:none;width:16px;height:16px;border-radius:9999px;align-items:center;justify-content:center;font-size:11px;line-height:1;border:1.5px solid ' + (ok ? '#047857' : '#9ca3af') + ';background:' + (ok ? '#ecfdf5' : 'transparent');
        mark.textContent = ok ? '✓' : '';
        var label = document.createElement('span');
        label.textContent = r.text(policy);
        li.appendChild(mark);
        li.appendChild(label);
        list.appendChild(li);
      });
      var c = confirmInput.value;
      var match = v !== '' && v === c;
      note.textContent = c === '' ? '' : (match ? 'Passwords match.' : "Passwords don't match.");
      note.style.color = match ? '#047857' : '#ed1c24';
    }

    newInput.addEventListener('input', render);
    confirmInput.addEventListener('input', render);
    fetch('api/password-reset.php?policy=1')
      .then(function (r) { return r.json(); })
      .then(function (p) { if (p && p.minLength) { policy = p; } render(); })
      .catch(render);
    render();

    return {
      missing: function () {
        var v = newInput.value;
        var needs = active().filter(function (r) { return !r.ok(v, policy); }).map(function (r) { return r.text(policy).toLowerCase(); });
        return needs.length ? 'Your password still needs: ' + needs.join(', ') + '.' : '';
      },
      refresh: render
    };
  }

  window.PasswordChecklist = { attach: attach };
})();
