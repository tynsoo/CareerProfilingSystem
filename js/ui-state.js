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

  // The server's answer about who is signed in, for parts of the page that draw before the page's own script asks
  // (the staff sidebar grays out the pages closed to this person: js/admin-nav.js).
  function announceSession(data) {
    if (!data || !data.user) return;
    try { window.dispatchEvent(new CustomEvent('pp:session', { detail: data.user })); } catch (e) {}
  }

  function sessionCall(input, init) {
    var cached = cacheServed ? null : readProfile();
    cacheServed = true;
    var network = patchedFetch(input, init);
    if (!cached) {
      return network.then(function (res) {
        res.clone().json().then(function (data) { writeProfile(data); fillStaffHeader(data && data.user); announceSession(data); }).catch(function () {});
        return res;
      });
    }
    fillStaffHeader(cached.user);
    network.then(function (res) { return res.clone().json(); })
      .then(function (fresh) { announceSession(fresh); reconcile(cached, fresh); })
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
  // options.signal (optional) cancels the request; the timeout still applies. A cancelled request rejects with
  // an error whose `cancelled` is true, so the caller can ignore it.
  PP.fetchJson = function (url, options, timeoutMs) {
    options = options || {};
    var outer = options.signal || null;
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    var timer = null, onOuterAbort = null;
    if (controller) {
      if (outer) {
        onOuterAbort = function () { controller.abort(); };
        if (outer.aborted) controller.abort();
        else outer.addEventListener('abort', onOuterAbort);
      }
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
      if (outer && outer.aborted) {
        var cancelled = new Error('Cancelled');
        cancelled.cancelled = true;
        throw cancelled;
      }
      if (e && e.name === 'AbortError') throw new Error('This is taking too long. Try again.');
      if (e instanceof TypeError) throw new Error(DEFAULT_ERROR);
      throw e;
    }).finally(function () {
      if (timer) clearTimeout(timer);
      if (onOuterAbort) outer.removeEventListener('abort', onOuterAbort);
    });
  };

  // ---------- Lists: the same behavior on every page ----------
  // Filters apply the moment they change (no Apply button), the server sends one page at a time, and a new
  // request cancels the one still on its way, so a slow old answer can never replace a newer one.

  // Returns a function that loads JSON like PP.fetchJson but first cancels the previous call it made.
  // A cancelled call rejects with e.cancelled === true: return quietly in that case. .cancel() drops the
  // call still on its way without starting a new one (for a page that can decide not to load at all,
  // such as an invalid date range).
  PP.latestJson = function () {
    var current = null;
    function run(url, options, timeoutMs) {
      if (current) current.abort();
      current = typeof AbortController === 'function' ? new AbortController() : null;
      return PP.fetchJson(url, Object.assign({}, options || {}, current ? { signal: current.signal } : {}), timeoutMs);
    }
    run.cancel = function () { if (current) { current.abort(); current = null; } };
    return run;
  };

  // Calls fn after the user stops typing for `ms` (default 300). .cancel() drops a call still waiting.
  PP.debounce = function (fn, ms) {
    var timer = null;
    function run() {
      var args = arguments, self = this;
      clearTimeout(timer);
      timer = setTimeout(function () { fn.apply(self, args); }, ms || 300);
    }
    run.cancel = function () { clearTimeout(timer); };
    return run;
  };

  // Keeps a list's filters in the page address (?strand=STEM&page=2), so Reload, Back and a shared link show the
  // same list. Only values that differ from `defaults` are written. Keys in `privateKeys` (a search, which can be
  // a student's name) never go in the address: they are kept in this tab's sessionStorage instead, which ends
  // with the tab.
  PP.listState = function (defaults, privateKeys) {
    privateKeys = privateKeys || [];
    var storeKey = 'pp.list.' + window.location.pathname;
    function isPrivate(k) { return privateKeys.indexOf(k) !== -1; }
    return {
      read: function () {
        var out = Object.assign({}, defaults);
        var params = new URLSearchParams(window.location.search);
        var saved = {};
        try { saved = JSON.parse((storage() && storage().getItem(storeKey)) || '{}') || {}; } catch (e) {}
        Object.keys(defaults).forEach(function (k) {
          var v = isPrivate(k) ? saved[k] : params.get(k);
          if (v == null) return;
          out[k] = typeof defaults[k] === 'number' ? (Number(v) || defaults[k]) : String(v);
        });
        return out;
      },
      save: function (state) {
        var params = new URLSearchParams(window.location.search);
        var priv = {};
        Object.keys(defaults).forEach(function (k) {
          if (isPrivate(k)) { if (state[k]) priv[k] = state[k]; return; }
          if (state[k] === defaults[k] || state[k] === '' || state[k] == null) params.delete(k);
          else params.set(k, state[k]);
        });
        var query = params.toString();
        try { window.history.replaceState(window.history.state, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash); } catch (e) {}
        try { if (storage()) storage().setItem(storeKey, JSON.stringify(priv)); } catch (e) {}
      }
    };
  };

  // The footer under a list: "Showing 1 to 50 of 308 students", Rows per page (25, 50, 100) and page buttons.
  // el: the footer element (it is filled here). opts: { noun: ['student', 'students'], label: 'Student list pages',
  // pageSize: 50, emptyText: 'No students to show', onChange: function (page, pageSize, focusRole) }.
  // The summary keeps the id "paginationSummary", so a page can still write a short message there.
  PP.pager = function (el, opts) {
    if (typeof el === 'string') el = document.getElementById(el);
    opts = opts || {};
    var sizes = [25, 50, 100];
    var noun = opts.noun || ['item', 'items'];
    var cur = { page: 1, pageSize: sizes.indexOf(opts.pageSize) !== -1 ? opts.pageSize : 50, totalPages: 1 };
    el.classList.add('pp-pager-bar');
    el.innerHTML = '<p class="pp-pager-summary" id="paginationSummary" aria-live="polite"></p>' +
      '<div class="pp-pager-right"><label class="pp-pager-size">Rows per page <select class="pp-filter-select">' +
      sizes.map(function (n) { return '<option value="' + n + '">' + n + '</option>'; }).join('') +
      '</select></label><nav class="pp-pager" aria-label="' + esc(opts.label || 'Pages') + '"></nav></div>';
    var summary = el.querySelector('.pp-pager-summary');
    var select = el.querySelector('select');
    var nav = el.querySelector('nav');
    select.value = String(cur.pageSize);

    function change(page, focusRole) { if (opts.onChange) opts.onChange(page, cur.pageSize, focusRole); }
    // A new page size keeps the first row you were looking at on screen.
    select.addEventListener('change', function () {
      var size = Number(select.value);
      var page = Math.floor((cur.page - 1) * cur.pageSize / size) + 1;
      cur.pageSize = size;
      change(page, null);
    });
    nav.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-page]');
      if (!btn || btn.disabled || Number(btn.dataset.page) === cur.page) return;
      change(Number(btn.dataset.page), btn.dataset.role);
    });

    // First, last, and up to two pages either side of the current one, with "..." for the gaps. Hidden for one page.
    function buttons(page, totalPages) {
      if (totalPages <= 1) return '';
      var html = '<button type="button" class="pp-page-btn" data-role="prev" data-page="' + (page - 1) + '" aria-label="Previous page"' + (page <= 1 ? ' disabled' : '') + '>' +
        '<img src="images/audit/icon-page-prev.svg" alt="" class="pp-page-arrow"></button>';
      var previous = 0;
      for (var p = 1; p <= totalPages; p++) {
        if (p !== 1 && p !== totalPages && Math.abs(p - page) > 2) continue;
        if (previous && p - previous > 1) html += '<span class="pp-page-gap" aria-hidden="true">...</span>';
        html += '<button type="button" class="pp-page-btn pp-page-num" data-role="page" data-page="' + p + '" aria-label="Page ' + p + '"' + (p === page ? ' aria-current="page"' : '') + '>' + p + '</button>';
        previous = p;
      }
      html += '<button type="button" class="pp-page-btn" data-role="next" data-page="' + (page + 1) + '" aria-label="Next page"' + (page >= totalPages ? ' disabled' : '') + '>' +
        '<img src="images/audit/icon-page-next.svg" alt="" class="pp-page-arrow"></button>';
      return html + '<span class="pp-page-of">Page ' + page + ' of ' + totalPages + '</span>';
    }

    return {
      // data: the list answer's { page, pageSize, total, totalPages, startIndex } and `shown` (rows on this page).
      render: function (data) {
        cur.page = data.page;
        cur.totalPages = data.totalPages;
        if (data.pageSize && sizes.indexOf(data.pageSize) !== -1) { cur.pageSize = data.pageSize; select.value = String(data.pageSize); }
        if (!data.total) {
          summary.textContent = opts.emptyText || 'No ' + noun[1] + ' to show';
        } else {
          var first = data.startIndex, last = data.startIndex + data.shown - 1;
          summary.innerHTML = 'Showing <b>' + first.toLocaleString() + ' to ' + last.toLocaleString() + '</b> of <b>' +
            Number(data.total).toLocaleString() + '</b> ' + (data.total === 1 ? noun[0] : noun[1]);
        }
        nav.innerHTML = buttons(data.page, data.totalPages);
      },
      loading: function () { summary.innerHTML = PP.skeleton.line('220px'); },
      clear: function () { summary.textContent = ''; nav.innerHTML = ''; },
      // The pager is redrawn on every page turn, which drops keyboard focus: put it back on the same kind of button.
      focus: function (role) {
        if (!role) return;
        var preferred = role === 'page' ? nav.querySelector('[aria-current="page"]') : nav.querySelector('[data-role="' + role + '"]');
        var all = [preferred].concat(Array.prototype.slice.call(nav.querySelectorAll('button')));
        for (var i = 0; i < all.length; i++) {
          if (all[i] && !all[i].disabled && all[i].offsetParent !== null) { all[i].focus(); return; }
        }
      },
      pageSize: function () { return cur.pageSize; }
    };
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
  // College colors are defined once, in css/components.css (--college-<code>). Badges and legends use them
  // through data-college; charts read the same variables here. A college not listed there gets its grey.
  PP.college = {
    key: function (code) { return String(code == null ? '' : code).toLowerCase().replace(/[^a-z0-9]/g, ''); },
    color: function (code) {
      var css = getComputedStyle(document.documentElement);
      return css.getPropertyValue('--college-' + PP.college.key(code)).trim() || css.getPropertyValue('--college-other').trim() || '#94a3b8';
    },
    badge: function (code) {
      var span = document.createElement('span');
      span.className = 'college-badge';
      span.dataset.college = String(code == null ? '' : code);
      span.textContent = String(code == null ? '' : code);
      return span.outerHTML;
    },
    // colleges: codes, or { code, name } objects (the name shows on hover).
    legend: function (el, colleges) {
      if (!el) return;
      el.replaceChildren.apply(el, colleges.map(function (c) {
        var item = document.createElement('span');
        item.className = 'college-legend-item';
        item.dataset.college = typeof c === 'string' ? c : c.code;
        item.textContent = item.dataset.college;
        if (typeof c !== 'string' && c.name) item.title = c.name;
        return item;
      }));
    }
  };

  // The signed-in person as this tab last saw them (the profile cache), or null: lets the sidebar draw the right state at
  // once. The fresh answer follows as the 'pp:session' event.
  PP.cachedUser = function () {
    var p = readProfile();
    return p ? p.user : null;
  };

  // Whether the signed-in staff member holds at least `level` ('limited' = View Only, 'full') on `feature` in the
  // permission table (user.access from api/session.php). Only for showing or hiding buttons: the server checks every request.
  PP.may = function (user, feature, level) {
    var rank = { none: 0, limited: 1, full: 2 };
    var have = user && user.access ? user.access[feature] : null;
    return (rank[have] || 0) >= (rank[level || 'limited'] || 1);
  };

  // One status badge on every page (css/components.css .pp-badge). The color says what kind of status it is, so the
  // same word is never two colors: green done or OK, amber waiting or needs a look, blue not started or next step,
  // red a problem, gray off, closed or none. Returns HTML; the label is escaped. opts.large: the bigger student size.
  PP.badge = function (label, tone, opts) {
    return '<span class="pp-badge pp-badge-' + (tone || 'gray') + (opts && opts.large ? ' pp-badge-lg' : '') + '">' + esc(label) + '</span>';
  };
  // The Course Match result as a badge, the same colors on every page: Match green, Close match blue, Mismatch amber
  // (a reason to visit Guidance, not an error, so not red). A low-similarity flag is a separate thing (Monitoring).
  PP.matchBadge = function (label) {
    var tone = { 'Match': 'green', 'Close match': 'blue', 'Mismatch': 'amber' }[label] || 'gray';
    return PP.badge(label || 'Not computed', tone);
  };

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

  // ---------- Code boxes (assessment access code, emailed 6-digit codes, staff activation code) ----------
  // PP.codeInput(input, { digits: true, length: 6 }): a pasted or typed code keeps only its letters and digits, upper
  // case. A code copied from an email or chat often brings a space, a line break, a non-breaking space, a dash, or the
  // words around it ("Access code: 7C3E91") along. With maxlength on the box, the browser cut the paste before it was
  // cleaned, so a leading space or label pushed characters out and a correct code read as "invalid". The box now
  // takes the whole paste, cleans it, picks the code out of any words around it (length), and only then applies its
  // maxlength. The server cleans it again (shared/lib/CodeInput.php).
  var INVISIBLE = /[\s\u00A0\u200B-\u200D\u2060\uFEFF]+/g;
  PP.cleanCode = function (text, digitsOnly) {
    var cleaned = String(text || '').replace(INVISIBLE, '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    return digitsOnly ? cleaned.replace(/\D/g, '') : cleaned;
  };
  // Same rule as CodeInput::pick(): if the cleaned text is not exactly `length` long, use the last pasted word of
  // exactly that length, preferring one with a digit in it, so "Access code: 7C3E91" gives "7C3E91".
  PP.pickCode = function (text, length, digitsOnly) {
    var cleaned = PP.cleanCode(text, digitsOnly);
    if (!length || cleaned.length === length) return cleaned;
    var words = String(text || '').replace(INVISIBLE, ' ').split(/[^A-Za-z0-9]+/).map(function (w) {
      return PP.cleanCode(w, digitsOnly);
    }).filter(function (w) { return w.length === length; });
    var withDigit = words.filter(function (w) { return /\d/.test(w); });
    if (withDigit.length) return withDigit[withDigit.length - 1];
    return words.length ? words[words.length - 1] : cleaned;
  };
  PP.codeInput = function (input, opts) {
    if (!input || input.dataset.ppCode) return input;
    input.dataset.ppCode = '1';
    var digitsOnly = !!(opts && opts.digits);
    var length = (opts && opts.length) || 0;
    var max = parseInt(input.getAttribute('maxlength'), 10) || 0;
    // The browser would cut a paste or a phone's clipboard suggestion at maxlength before it is cleaned, so the
    // limit is applied here instead, after cleaning.
    input.removeAttribute('maxlength');
    input.setAttribute('autocorrect', 'off');
    input.setAttribute('autocapitalize', digitsOnly ? 'off' : 'characters');
    input.setAttribute('spellcheck', 'false');
    function tidy(text) {
      var code = PP.pickCode(text, length, digitsOnly);
      return max ? code.slice(0, max) : code;
    }
    input.addEventListener('paste', function (e) {
      var data = e.clipboardData || window.clipboardData;
      var text = data ? data.getData('text') : null;
      if (text == null) return;
      e.preventDefault();
      input.value = tidy(text);
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    input.addEventListener('input', function () {
      var code = tidy(input.value);
      if (code !== input.value) input.value = code;
    });
    return input;
  };

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

  // The one pop-up for confirming an action (css/components.css .pp-dialog). Resolves true for the action, false
  // for Cancel, the x or Escape. opts: { title, okLabel, danger }. title is the short question ("Delete this
  // question?"), message the details under it, okLabel what the button does ("Delete", never "Confirm").
  // danger: a red button for delete, deactivate and remove, with keyboard focus starting on Cancel.
  // A click on the dark background does nothing (same rule as the Filters panel). Tab stays inside the pop-up,
  // and focus goes back to whatever opened it.
  var dialogSeq = 0;
  // Keeps Tab inside a pop-up: from the last control back to the first, and the other way round.
  function keepTabInside(e, box) {
    var items = Array.prototype.filter.call(
      box.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
      function (el) { return !el.disabled && el.offsetParent !== null; });
    if (!items.length) return;
    var first = items[0], last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    else if (!box.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
  }
  var X_ICON = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>';
  PP.confirm = function (message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var opener = document.activeElement;
      var id = 'ppDialog' + (++dialogSeq);
      var modal = document.createElement('div');
      modal.className = 'modal-backdrop hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4';
      modal.innerHTML =
        '<div class="modal-card pp-dialog" role="' + (opts.danger ? 'alertdialog' : 'dialog') + '" aria-modal="true" aria-labelledby="' + id + '-t" aria-describedby="' + id + '-b">' +
          '<div class="pp-dialog-head"><h2 class="pp-dialog-title" id="' + id + '-t"></h2>' +
            '<button type="button" data-cancel class="pp-dialog-x" aria-label="Close">' + X_ICON + '</button></div>' +
          '<p class="pp-dialog-body" id="' + id + '-b"></p>' +
          '<div class="pp-dialog-foot">' +
            '<button type="button" data-cancel class="pp-btn pp-btn-secondary">Cancel</button>' +
            '<button type="button" data-ok class="pp-btn ' + (opts.danger ? 'pp-btn-danger btn-danger' : 'pp-btn-primary') + '"></button>' +
          '</div>' +
        '</div>';
      var title = modal.querySelector('.pp-dialog-title');
      var body = modal.querySelector('.pp-dialog-body');
      // Without a title (older calls), the message is the question itself and is shown as the title.
      title.textContent = opts.title || message;
      if (opts.title && message) body.textContent = message;
      else { body.remove(); modal.querySelector('.pp-dialog').removeAttribute('aria-describedby'); }
      var okBtn = modal.querySelector('[data-ok]');
      var cancelBtn = modal.querySelector('.pp-dialog-foot [data-cancel]');
      okBtn.textContent = opts.okLabel || 'OK';
      document.body.appendChild(modal);
      modal.classList.remove('hidden');
      setTimeout(function () { modal.classList.add('is-open'); (opts.danger ? cancelBtn : okBtn).focus(); }, 16);

      function done(result) {
        document.removeEventListener('keydown', onKey, true);
        modal.classList.remove('is-open');
        setTimeout(function () { modal.remove(); }, 260);
        if (opener && opener.focus && document.contains(opener)) opener.focus();
        resolve(result);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.stopPropagation(); done(false); return; }
        if (e.key === 'Tab') keepTabInside(e, modal);
      }
      document.addEventListener('keydown', onKey, true);
      okBtn.addEventListener('click', function () { done(true); });
      modal.querySelectorAll('[data-cancel]').forEach(function (b) { b.addEventListener('click', function () { done(false); }); });
    });
  };

  // A page's own pop-up (a form) with the same behaviour as PP.confirm. el: the .modal-backdrop element, holding a
  // .pp-dialog card with a .pp-dialog-title. Buttons marked data-dialog-close (the x, Cancel) close it, so does
  // Escape; a click on the dark background does nothing. Tab stays inside, and focus goes back to whatever opened it.
  // opts.onClose: runs after it closes (any way). open(focusEl): focus that element, else the first field.
  PP.dialog = function (el, opts) {
    if (typeof el === 'string') el = document.getElementById(el);
    opts = opts || {};
    var card = el.querySelector('.pp-dialog');
    var title = el.querySelector('.pp-dialog-title');
    if (title && !title.id) title.id = el.id + 'Title';
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-modal', 'true');
    if (title) card.setAttribute('aria-labelledby', title.id);
    var opener = null, openNow = false;
    function onKey(e) {
      if (e.key === 'Escape') { e.stopPropagation(); api.close(); return; }
      if (e.key === 'Tab') keepTabInside(e, card);
    }
    var api = {
      open: function (focusEl) {
        opener = document.activeElement;
        openNow = true;
        el.classList.remove('hidden');
        document.addEventListener('keydown', onKey, true);
        setTimeout(function () {
          el.classList.add('is-open');
          var target = focusEl || card.querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])') ||
            card.querySelector('.pp-dialog-foot button:not([disabled])');
          if (target) target.focus();
        }, 16);
      },
      close: function () {
        if (!openNow) return;
        openNow = false;
        document.removeEventListener('keydown', onKey, true);
        el.classList.remove('is-open');
        setTimeout(function () { el.classList.add('hidden'); }, 260);
        if (opener && opener.focus && document.contains(opener)) opener.focus();
        if (opts.onClose) opts.onClose();
      },
      isOpen: function () { return openNow; }
    };
    el.querySelectorAll('[data-dialog-close]').forEach(function (b) { b.addEventListener('click', api.close); });
    return api;
  };

  // Log out, the same on every page: asks first, then signs out and goes to the page's sign-in page.
  // beforeSignOut (optional) runs once the user says yes, before signing out (e.g. clearing a tab-only copy).
  PP.logout = function (signInPage, beforeSignOut) {
    PP.confirm('You will need to sign in again to continue.', { title: 'Log out?', okLabel: 'Log out' }).then(function (ok) {
      if (!ok) return;
      if (beforeSignOut) beforeSignOut();
      fetch('api/logout.php', { method: 'POST' }).finally(function () { window.location.href = signInPage; });
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
  // router.php sent this person here from a page closed to them (?denied=1): say so once, then drop it from the address.
  function noteDenied() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('denied') !== '1') return;
    params.delete('denied');
    var query = params.toString();
    try { window.history.replaceState(window.history.state, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash); } catch (e) {}
    PP.toast('You do not have access to that page. Ask the Head of Guidance if you need it.', true);
  }
  function onReady() { upgradePlaceholders(); markCurrentNavLink(); nameIconButtons(); linkLabels(); noteDenied(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', onReady);
  else onReady();
})();
