/*
 * ProfilePath — shared motion system.
 * Loaded (deferred) on every page alongside css/motion.css. Auto-detects the
 * app's existing header/main layout to choreograph a page-load reveal and
 * wires scroll-triggered reveals + a shadow-on-scroll header, all without
 * requiring per-page markup. Pages needing bespoke choreography can instead
 * hand-author elements with data-reveal="up|down|left|right|scale".
 */
(function () {
  'use strict';

  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduced) {
    document.documentElement.classList.add('motion-reduced');
  }

  /* ---------- Auto-choreographed reveal ----------
     Deliberately skips <aside> (the sidebar) — it already has its own
     transform-driven mobile drawer open/close animation, and layering a
     second transform system on the same element would fight it. */
  function markReveal(el, variant, delayMs) {
    if (!el || el.hasAttribute('data-reveal') || el.hasAttribute('data-auto-reveal')) return;
    if (el.classList.contains('hidden')) return;
    el.setAttribute('data-auto-reveal', variant);
    if (delayMs) el.style.transitionDelay = delayMs + 'ms';
  }

  var header = document.querySelector('header');
  if (header) markReveal(header, 'fade', 40);

  document.querySelectorAll('main').forEach(function (main) {
    var step = 0;
    Array.prototype.forEach.call(main.children, function (child) {
      if (child.classList.contains('hidden')) return;
      markReveal(child, 'up', 120 + Math.min(step, 5) * 70);
      step++;
    });
  });

  var revealTargets = document.querySelectorAll('[data-reveal], [data-auto-reveal]');

  if (reduced || !('IntersectionObserver' in window)) {
    revealTargets.forEach(function (el) { el.classList.add('in-view'); });
  } else {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('in-view');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1, rootMargin: '0px 0px -30px 0px' });
    revealTargets.forEach(function (el) { io.observe(el); });
  }

  /* ---------- Shadow-on-scroll header ----------
     Most pages scroll inside a <main overflow-auto> sibling of <header>
     (the sidebar dashboard layout); a few plain pages scroll the whole
     document instead. Detect which applies per page automatically. */
  document.querySelectorAll('main').forEach(function (main) {
    var overflowY = getComputedStyle(main).overflowY;
    if (overflowY !== 'auto' && overflowY !== 'scroll') return;
    var parent = main.parentElement;
    var mainHeader = parent ? parent.querySelector(':scope > header') : null;
    if (!mainHeader) return;
    mainHeader.classList.add('js-shadow-header');
    var update = function () { mainHeader.classList.toggle('is-scrolled', main.scrollTop > 4); };
    update();
    main.addEventListener('scroll', update, { passive: true });
  });

  document.querySelectorAll('header').forEach(function (h) {
    if (h.classList.contains('js-shadow-header')) return;
    h.classList.add('js-shadow-header');
    var update = function () { h.classList.toggle('is-scrolled', window.scrollY > 4); };
    update();
    window.addEventListener('scroll', update, { passive: true });
  });
})();
