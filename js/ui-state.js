/*
 * ProfilePath — loading, error and busy states shared by every page, plus a small cache for the
 * signed-in person's profile. Loaded right after js/csrf.js (not deferred) so its fetch wrapper
 * sits on top of the CSRF one.
 *
 *  1. Profile cache: the first api/session.php call a page makes is answered from sessionStorage
 *     (name, role, photo, section) so the sidebar and header paint at once. The real answer is
 *     fetched anyway; if it says the person signed out, switched accounts or changed their profile,
 *     the cache is dropped and the page reloads once. Only that profile is cached. The CSRF token,
 *     permissions and everything else always come from the server. The cache is cleared on login
 *     and logout and lives in sessionStorage, so it ends with the tab.
 *  2. window.PP: skeleton markup, an error state with a Retry button, a fetch helper that fails
 *     loudly, a busy-button spinner and a stale-while-revalidate helper for data that rarely changes.
 */
(function () {
  'use strict';

  var PROFILE_KEY = 'pp.profile.v1';
  var RELOAD_KEY = 'pp.profile.reloaded';
  var SWR_PREFIX = 'pp.swr.';
  var patchedFetch = window.fetch;
  var cacheServed = false; // the cached answer is used once per page load; later session calls go to the server

  function storage() {
    try { return window.sessionStorage; } catch (e) { return null; }
  }
  function isLoginPage() {
    return /^\/?$|\/?(staff-|student-)?login(\.html)?$/.test(window.location.pathname);
  }
  function loginUrl() {
    var portal = null;
    try { portal = localStorage.getItem('pp_portal'); } catch (e) {}
    return (portal === 'staff' ? 'staff-login' : 'student-login') + '?expired=1';
  }

  // ---------- profile cache ----------
  function readProfile() {
    var s = storage();
    if (!s) return null;
    try {
      var v = JSON.parse(s.getItem(PROFILE_KEY));
      return v && v.user ? v : null;
    } catch (e) { return null; }
  }
  function writeProfile(data) {
    var s = storage();
    if (!s) return;
    try {
      if (data && data.user) s.setItem(PROFILE_KEY, JSON.stringify({ user: data.user, sessionTimeout: data.sessionTimeout || null }));
      else s.removeItem(PROFILE_KEY);
    } catch (e) {}
  }
  function clearProfile() {
    var s = storage();
    if (!s) return;
    try { s.removeItem(PROFILE_KEY); } catch (e) {}
  }
  function clearAllCaches() {
    var s = storage();
    if (!s) return;
    try {
      var drop = [];
      for (var i = 0; i < s.length; i++) {
        var k = s.key(i);
        if (k === PROFILE_KEY || (k && k.indexOf(SWR_PREFIX) === 0)) drop.push(k);
      }
      drop.forEach(function (k) { s.removeItem(k); });
    } catch (e) {}
  }

  function reconcile(cached, fresh) {
    if (!fresh || !fresh.user) {
      clearProfile();
      if (!isLoginPage()) window.location.replace(loginUrl());
      return;
    }
    if (JSON.stringify(fresh.user) === JSON.stringify(cached.user)) return;
    writeProfile(fresh);
    // Something changed (a new name or photo, another account): show the page again with the right data, once.
    var s = storage(), last = 0;
    try { last = Number(s && s.getItem(RELOAD_KEY)) || 0; } catch (e) {}
    if (Date.now() - last < 10000) return;
    try { if (s) s.setItem(RELOAD_KEY, String(Date.now())); } catch (e) {}
    window.location.reload();
  }

  // Staff header: name and role beside the avatar, for the pages that do not fill them in themselves.
  function fillStaffHeader(user) {
    if (!user || user.role === 'student') return;
    function apply() {
      var nameEl = document.getElementById('headerUserName');
      var roleEl = document.getElementById('headerUserRole');
      if (nameEl && !nameEl.textContent.trim()) nameEl.textContent = user.displayName || user.username || '';
      if (roleEl && !roleEl.textContent.trim()) roleEl.textContent = user.positionLabel || ({ admin: 'Administrator', counselor: 'Guidance Counselor' })[user.role] || '';
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(apply, 0); });
    else setTimeout(apply, 0);
  }

  function sessionCall(input, init) {
    var cached = cacheServed ? null : readProfile();
    cacheServed = true;
    var network = patchedFetch(input, init);
    if (!cached) {
      return network.then(function (res) {
        res.clone().json().then(function (data) { writeProfile(data); fillStaffHeader(data && data.user); }).catch(function () {});
        return res;
      });
    }
    fillStaffHeader(cached.user);
    network.then(function (res) { return res.clone().json(); })
      .then(function (fresh) { reconcile(cached, fresh); })
      .catch(function () { /* offline or a server error: keep what is on screen */ });
    return Promise.resolve(new Response(JSON.stringify({ user: cached.user, sessionTimeout: cached.sessionTimeout, csrfToken: null, fromCache: true }), {
      status: 200, headers: { 'Content-Type': 'application/json' }
    }));
  }

  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    var method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
    if (/(^|\/)api\/(login|logout)\.php/.test(url)) clearAllCaches();
    if (method === 'GET' && /(^|\/)api\/session\.php(\?|$)/.test(url)) return sessionCall(input, init);
    return patchedFetch(input, init);
  };

  // ---------- PP helpers ----------
  var ERROR_ICON = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>';
  var DEFAULT_ERROR = 'Could not load this. Check your connection and try again.';

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function bar(width, cls) {
    return '<span class="sk sk-line ' + (cls || '') + '" style="width:' + width + '"></span>';
  }

  var PP = window.PP = window.PP || {};

  PP.skeleton = {
    line: function (width) { return bar(width || '100%'); },
    // n placeholder table rows with `cols` cells.
    rows: function (cols, n) {
      var widths = ['70%', '45%', '60%', '35%', '55%', '40%', '65%', '50%'];
      var out = '';
      for (var r = 0; r < (n || 5); r++) {
        out += '<tr class="sk-row" aria-hidden="true">';
        for (var c = 0; c < cols; c++) out += '<td class="px-5 py-4">' + bar(widths[(r + c) % widths.length]) + '</td>';
        out += '</tr>';
      }
      return out;
    },
    // a stack of text lines (for lists and paragraphs)
    lines: function (n, gap) {
      var widths = ['100%', '82%', '91%', '64%', '76%'];
      var out = '<div aria-hidden="true" style="display:flex;flex-direction:column;gap:' + (gap || 10) + 'px;padding:8px 0">';
      for (var i = 0; i < (n || 4); i++) out += bar(widths[i % widths.length]);
      return out + '</div>';
    },
    // a grid-friendly card placeholder
    card: function () {
      return '<div class="sk-card" aria-hidden="true">' + bar('55%', 'sk-line-lg') + bar('100%') + bar('80%') + '</div>';
    },
    cards: function (n) {
      var out = '';
      for (var i = 0; i < (n || 3); i++) out += PP.skeleton.card();
      return out;
    },
    // a statistic: small label over a large number
    stat: function () {
      return '<span style="display:flex;flex-direction:column;gap:8px" aria-hidden="true">' + bar('55%') + bar('38%', 'sk-line-lg') + '</span>';
    },
    // a chart: placeholder bars (default) or a plain block
    chart: function (kind) {
      if (kind === 'block') return '<span class="sk sk-chart" aria-hidden="true"></span>';
      var heights = [55, 80, 40, 95, 65, 50, 75];
      return '<div class="sk-bars" aria-hidden="true">' + heights.map(function (h) { return '<span class="sk" style="height:' + h + '%"></span>'; }).join('') + '</div>';
    }
  };

  // Puts a skeleton into an element. For a tbody pass the markup of PP.skeleton.rows().
  PP.showSkeleton = function (target, html) {
    if (typeof target === 'string') target = document.getElementById(target);
    if (!target) return;
    target.setAttribute('aria-busy', 'true');
    target.innerHTML = html;
  };

  // Shows the error state in `target` with a Retry button. A tbody gets a full-width row.
  PP.showError = function (target, message, retry, colspan) {
    if (typeof target === 'string') target = document.getElementById(target);
    if (!target) return;
    target.removeAttribute('aria-busy');
    var inner = '<div class="pp-error" role="alert">' + ERROR_ICON + '<p class="pp-error-text">' + esc(message || DEFAULT_ERROR) + '</p>' +
      (retry ? '<button type="button" class="pp-retry">Try again</button>' : '') + '</div>';
    if (target.tagName === 'TBODY') target.innerHTML = '<tr><td colspan="' + (colspan || 99) + '">' + inner + '</td></tr>';
    else target.innerHTML = inner;
    var btn = target.querySelector('.pp-retry');
    if (btn && retry) btn.addEventListener('click', function () { btn.disabled = true; retry(); });
  };

  // Chart placeholder: covers `box` (the element around the <canvas>) with grey bars until PP.chartLoaded(box).
  PP.chartLoading = function (box) {
    if (typeof box === 'string') box = document.getElementById(box);
    if (!box) return;
    PP.chartLoaded(box);
    if (window.getComputedStyle(box).position === 'static') box.style.position = 'relative';
    var overlay = document.createElement('div');
    overlay.className = 'pp-chart-cover';
    overlay.setAttribute('aria-busy', 'true');
    overlay.innerHTML = PP.skeleton.chart();
    box.appendChild(overlay);
  };
  PP.chartLoaded = function (box) {
    if (typeof box === 'string') box = document.getElementById(box);
    if (!box) return;
    var old = box.querySelectorAll('.pp-chart-cover');
    for (var i = 0; i < old.length; i++) old[i].remove();
  };
  PP.chartError = function (box, message, retry) {
    if (typeof box === 'string') box = document.getElementById(box);
    if (!box) return;
    PP.chartLoading(box);
    var cover = box.querySelector('.pp-chart-cover');
    cover.removeAttribute('aria-busy');
    PP.showError(cover, message, retry);
  };
  // A statistic (a big number): grey bar while loading, "—" if it could not load.
  PP.statLoading = function (el) {
    if (typeof el === 'string') el = document.getElementById(el);
    if (el) el.innerHTML = '<span class="sk" style="display:inline-block;width:56px;height:1em;vertical-align:-2px"></span>';
  };
  PP.statFailed = function (el) {
    if (typeof el === 'string') el = document.getElementById(el);
    if (el) el.textContent = '—';
  };

  // fetch + JSON that rejects on a network failure, a timeout, a non-2xx answer or a body that is not JSON.
  PP.fetchJson = function (url, options, timeoutMs) {
    options = options || {};
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = null;
    if (controller && !options.signal) {
      options = Object.assign({}, options, { signal: controller.signal });
      timer = setTimeout(function () { controller.abort(); }, timeoutMs || 25000);
    }
    return fetch(url, options).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (data) {
        if (!res.ok) {
          var err = new Error((data && data.error) || DEFAULT_ERROR);
          err.status = res.status;
          throw err;
        }
        if (data === null) throw new Error(DEFAULT_ERROR);
        return data;
      });
    }).catch(function (e) {
      if (e && e.name === 'AbortError') throw new Error('This is taking too long. Try again.');
      if (e instanceof TypeError) throw new Error(DEFAULT_ERROR);
      throw e;
    }).finally(function () { if (timer) clearTimeout(timer); });
  };

  // A whole-page placeholder (heading, a chart and a text card) for pages that stay blank until their data arrives.
  // Call PP.pageReady() when the real content is shown.
  PP.pageSkeleton = function () {
    if (document.getElementById('ppPageSkeleton')) return;
    var host = document.querySelector('main') || document.body;
    var box = document.createElement('div');
    box.id = 'ppPageSkeleton';
    box.setAttribute('aria-busy', 'true');
    box.innerHTML = '<div style="margin-bottom:24px;display:flex;flex-direction:column;gap:12px"><span class="sk sk-line-lg" style="width:260px;max-width:70%"></span>' + bar('420px', '') + '</div>' +
      '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px">' +
      '<div class="sk-card">' + PP.skeleton.chart() + '</div>' +
      '<div class="sk-card">' + PP.skeleton.lines(6, 18) + '</div></div>';
    host.insertBefore(box, host.firstChild);
  };
  PP.pageReady = function () {
    var box = document.getElementById('ppPageSkeleton');
    if (box) box.remove();
  };

  // The page's main data could not be loaded: show an error with a Retry (reloads the page) at the top of the content area.
  PP.pageError = function (message) {
    PP.pageReady();
    var host = document.querySelector('main') || document.body;
    var box = document.getElementById('ppPageError');
    if (!box) {
      box = document.createElement('div');
      box.id = 'ppPageError';
      box.className = 'bg-white border border-gray-200 rounded-xl shadow-sm mb-6';
      host.insertBefore(box, host.firstChild);
    }
    PP.showError(box, message, function () { window.location.reload(); });
  };

  // Runs `fn` (returns a promise) while showing `skeletonHtml` in target; on failure shows the error state with a retry.
  PP.load = function (target, skeletonHtml, fn, opts) {
    opts = opts || {};
    if (typeof target === 'string') target = document.getElementById(target);
    function run() {
      if (skeletonHtml != null) PP.showSkeleton(target, skeletonHtml);
      return Promise.resolve().then(fn).then(function (r) {
        if (target) target.removeAttribute('aria-busy');
        return r;
      }).catch(function (e) {
        PP.showError(target, opts.message || (e && e.message), run, opts.colspan);
        if (opts.onError) opts.onError(e);
      });
    }
    return run();
  };

  // Turns a button into "spinner + label" until the returned function is called.
  PP.busy = function (btn, label) {
    if (!btn) return function () {};
    var html = btn.innerHTML, disabled = btn.disabled;
    btn.disabled = true;
    btn.classList.add('pp-busy');
    btn.setAttribute('aria-busy', 'true');
    btn.innerHTML = '<span class="pp-spinner" aria-hidden="true"></span>' + esc(label || 'Please wait…');
    return function () {
      btn.innerHTML = html;
      btn.disabled = disabled;
      btn.classList.remove('pp-busy');
      btn.removeAttribute('aria-busy');
    };
  };

  // A student's initials from their name (Juana Tester -> JT): the first letter of the first name and of the last name,
  // upper-cased. Extra spaces, dots and digits are skipped, middle names are ignored ("Juana Marie" + "Tester" -> JT),
  // one name gives one letter, and no name gives '' (the caller keeps the default person icon). When only a full name
  // is known, "Last, First" (the staff lists' format) or "First ... Last" is split from it.
  PP.initials = function (first, last, fullName) {
    function words(s) {
      return String(s == null ? '' : s).trim().split(/\s+/).filter(function (w) { return /\p{L}/u.test(w); });
    }
    function letter(w) {
      return (w.match(/\p{L}/u) || [''])[0].toLocaleUpperCase();
    }
    var f = words(first), l = words(last);
    if (!f.length && !l.length && fullName) {
      var parts = String(fullName).split(',');
      if (parts.length > 1) { l = words(parts[0]); f = words(parts.slice(1).join(' ')); }
      else { f = words(fullName); }
    }
    if (f.length && l.length) return letter(f[0]) + letter(l[0]);
    var all = f.concat(l);
    if (all.length > 1) return letter(all[0]) + letter(all[all.length - 1]);
    return all.length ? letter(all[0]) : '';
  };

  // Fills a round avatar: the uploaded photo when there is one, else the initials in white on the avatar's own navy
  // circle (its size and colour stay as the page set them). Returns false, leaving the element as it was, when there is
  // neither a photo nor a name. Built with DOM nodes, so a name never becomes markup.
  PP.renderAvatar = function (el, opts) {
    if (!el) return false;
    opts = opts || {};
    if (opts.url) {
      var img = document.createElement('img');
      img.src = opts.url;
      img.alt = '';
      img.className = 'w-full h-full object-cover';
      el.replaceChildren(img);
      return true;
    }
    var text = PP.initials(opts.first, opts.last, opts.name);
    if (!text) return false;
    var span = document.createElement('span');
    span.className = 'pp-initials';
    span.setAttribute('aria-hidden', 'true'); // the name is written right beside the avatar
    span.textContent = text;
    span.style.cssText = 'color:#fff;font-weight:600;line-height:1;letter-spacing:0.02em;user-select:none;font-size:' + (opts.size || 13) + 'px';
    el.replaceChildren(span);
    return true;
  };

  // Stale-while-revalidate for data that rarely changes: render(data, fromCache) runs with the saved copy at once
  // (if any), then again with the fresh copy. Resolves with the fresh data; rejects if the server could not be reached
  // and nothing was cached.
  PP.swr = function (url, render, ttlMs) {
    var s = storage(), key = SWR_PREFIX + url, shown = false;
    try {
      var saved = s && JSON.parse(s.getItem(key));
      if (saved && Date.now() - saved.at < (ttlMs || 600000)) { render(saved.data, true); shown = true; }
    } catch (e) {}
    return PP.fetchJson(url).then(function (data) {
      try { if (s) s.setItem(key, JSON.stringify({ at: Date.now(), data: data })); } catch (e) {}
      render(data, false);
      return data;
    }).catch(function (e) {
      if (!shown) throw e;
    });
  };
  PP.clearCache = clearAllCaches;

  // ---------- Pop-up messages (the same on the staff pages and the student portal) ----------
  // PP.toast(text, isError): a small message in the bottom-right corner for 5 seconds, green for success, red for a problem.
  // PP.confirm(text, { okLabel, danger }): a "Cancel / Confirm" dialog; resolves true or false. danger = a delete, so the button is red.
  PP.toast = function (message, isError) {
    var container = document.getElementById('toastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toastContainer';
      container.className = 'fixed bottom-4 right-4 z-[60] flex flex-col gap-2 pointer-events-none';
      document.body.appendChild(container);
    }
    var toast = document.createElement('div');
    toast.className = 'pointer-events-auto max-w-sm px-4 py-3 rounded-lg shadow-lg text-sm font-medium whitespace-pre-wrap ' + (isError ? 'bg-[#ed1c24] text-white' : 'bg-green-600 text-white');
    toast.setAttribute('role', isError ? 'alert' : 'status');
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 5000);
  };

  PP.confirm = function (message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var modal = document.createElement('div');
      modal.className = 'modal-backdrop hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4';
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      modal.innerHTML =
        '<div class="modal-card bg-white rounded-xl shadow-lg w-full max-w-sm p-6 flex flex-col gap-4">' +
          '<p class="text-sm text-gray-700" style="white-space:pre-line"></p>' +
          '<div class="flex justify-end gap-2 pt-1">' +
            '<button type="button" data-cancel class="px-4 py-2 text-sm font-medium text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50">Cancel</button>' +
            '<button type="button" data-ok class="px-4 py-2 text-sm font-medium text-white bg-[#ed1c24] rounded-md hover:opacity-90' + (opts.danger ? ' btn-danger' : '') + '"></button>' +
          '</div>' +
        '</div>';
      modal.querySelector('p').textContent = message;
      var okBtn = modal.querySelector('[data-ok]');
      var cancelBtn = modal.querySelector('[data-cancel]');
      okBtn.textContent = opts.okLabel || 'Confirm';
      document.body.appendChild(modal);
      modal.classList.remove('hidden');
      setTimeout(function () { modal.classList.add('is-open'); (opts.danger ? cancelBtn : okBtn).focus(); }, 16);

      function done(result) {
        document.removeEventListener('keydown', onKey, true);
        modal.classList.remove('is-open');
        setTimeout(function () { modal.remove(); }, 260);
        resolve(result);
      }
      function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); done(false); } }
      document.addEventListener('keydown', onKey, true);
      okBtn.addEventListener('click', function () { done(true); });
      cancelBtn.addEventListener('click', function () { done(false); });
      modal.addEventListener('click', function (e) { if (e.target === modal) done(false); });
    });
  };

  // Pages ship a plain "Loading…" placeholder in their tables and lists. Swap those for skeletons the moment the page
  // is parsed; the page's own script replaces them with real rows (or the error state) when its data arrives.
  function upgradePlaceholders() {
    var nodes = document.querySelectorAll('td[colspan], p, li, span, div');
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      var text = el.textContent.replace(/\s+/g, ' ').trim();
      if (el.children.length || (text !== 'Loading…' && text !== 'Loading audit log…')) continue;
      if (el.id === 'notifCountLabel' || el.closest('#notifPanel')) continue;
      if (el.tagName === 'TD') {
        var tbody = el.closest('tbody');
        if (tbody && tbody.children.length === 1) {
          tbody.innerHTML = PP.skeleton.rows(Number(el.getAttribute('colspan')) || 1, 5);
          tbody.setAttribute('aria-busy', 'true');
        }
      } else if (/Summary$/.test(el.id)) {
        el.innerHTML = PP.skeleton.line('200px');
      } else {
        el.innerHTML = PP.skeleton.lines(3);
      }
    }
  }
  // Screen readers announce the highlighted sidebar link as the current page (the staff menu already sets this).
  function markCurrentNavLink() {
    var links = document.querySelectorAll('#sidebar a.text-\\[\\#ed1c24\\].border-\\[\\#ed1c24\\]:not([aria-current])');
    for (var i = 0; i < links.length; i++) links[i].setAttribute('aria-current', 'page');
  }
  // Icon-only header buttons get a name screen readers can announce.
  function nameIconButtons() {
    var bell = document.getElementById('notifBtn');
    if (bell && !bell.getAttribute('aria-label')) bell.setAttribute('aria-label', 'Notifications');
    var profile = document.getElementById('profileBtn');
    if (profile && !profile.getAttribute('aria-haspopup')) profile.setAttribute('aria-haspopup', 'menu');
  }
  // Form fields are announced with their visible label. A <label> written just before its field (without for="")
  // is linked to that field; a field with no label at all falls back to its placeholder as its name.
  function linkLabels() {
    var CONTROL = 'input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea';
    var labels = document.querySelectorAll('label:not([for])');
    for (var i = 0; i < labels.length; i++) {
      var label = labels[i];
      if (label.querySelector(CONTROL + ', input')) continue; // already wraps its field
      var next = label.nextElementSibling, field = null;
      if (next && next.matches(CONTROL)) field = next;
      else if (next && next.querySelectorAll(CONTROL).length === 1) field = next.querySelector(CONTROL);
      if (field && field.id && !document.querySelector('label[for="' + field.id + '"]')) label.htmlFor = field.id;
    }
    var fields = document.querySelectorAll(CONTROL);
    for (var j = 0; j < fields.length; j++) {
      var f = fields[j];
      if (f.labels && f.labels.length) continue;
      if (f.getAttribute('aria-label') || f.getAttribute('aria-labelledby') || f.title) continue;
      if (f.placeholder) f.setAttribute('aria-label', f.placeholder.replace(/…|\.\.\.$/, '').trim());
    }
  }
  function onReady() { upgradePlaceholders(); markCurrentNavLink(); nameIconButtons(); linkLabels(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', onReady);
  else onReady();
})();
