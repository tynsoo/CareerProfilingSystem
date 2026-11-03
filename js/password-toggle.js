/*
 * Adds a show/hide (eye) button to every password field on the page, using the
 * same eye icons as the login page. Include it with <script src="js/password-toggle.js" defer>.
 * A field is skipped if it opts out with data-no-toggle, or already has a toggle. Fields that get a toggle also get
 * autocapitalize/autocorrect/spellcheck off, so a shown password is not changed by a phone keyboard.
 */
(function () {
  var EYE = 'images/login/icon-eye.svg';
  var EYE_OPEN = 'images/login/icon-eye-open.svg';

  function addToggle(input) {
    if (input.dataset.toggleReady || input.hasAttribute('data-no-toggle')) return;
    input.dataset.toggleReady = '1';
    // Shown as plain text, a phone keyboard would capitalize the first letter, autocorrect, or add a space after a
    // suggested word. These keep the typed password exactly as typed (no effect while it is hidden).
    input.setAttribute('autocapitalize', 'none');
    input.setAttribute('autocorrect', 'off');
    input.spellcheck = false;

    var wrap = document.createElement('div');
    wrap.style.position = 'relative';
    // A gap above the field (e.g. a top margin under its label) moves to the wrapper, so the button stays centred on the field.
    var marginTop = window.getComputedStyle(input).marginTop;
    if (marginTop && marginTop !== '0px') {
      wrap.style.marginTop = marginTop;
      input.style.marginTop = '0';
    }
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);
    input.style.paddingRight = '44px'; // keep typed text clear of the button

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Show password');
    btn.setAttribute('aria-pressed', 'false');
    btn.style.cssText = 'position:absolute;top:0;bottom:0;right:0;width:44px;display:flex;align-items:center;justify-content:center;background:none;border:0;cursor:pointer;padding:0';
    var icon = document.createElement('img');
    icon.src = EYE;
    icon.alt = '';
    icon.style.cssText = 'width:20px;height:16px;pointer-events:none';
    btn.appendChild(icon);
    wrap.appendChild(btn);

    btn.addEventListener('click', function () {
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      icon.src = show ? EYE_OPEN : EYE;
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('input[type="password"]'), addToggle);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
