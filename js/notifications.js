/*
 * ProfilePath — shared header notification dropdown.
 * Populates #notifPanel's #notifList/#notifCountLabel/#notifBadge from
 * api/notifications.php on any page that has them. No-ops on pages without
 * that markup (e.g. pages that don't have a notification bell yet).
 *
 * Pages that carry their own open/close wiring leave #notifBtn alone; pages
 * that mark the button with data-shared-toggle get the open/close behaviour
 * from here so it doesn't have to be copied into every page.
 */
(function () {
  'use strict';

  var list = document.getElementById('notifList');
  if (!list) return;

  var countLabel = document.getElementById('notifCountLabel');
  var badge = document.getElementById('notifBadge');

  function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function timeAgo(iso) {
    // Postgres timestamptz values may come back as "YYYY-MM-DD HH:MM:SS[.ffffff][+HH]"
    // — already carrying a UTC offset. Blindly appending 'Z' after swapping the
    // space for 'T' would double up the timezone (e.g. "...+08Z") and fail to
    // parse. Only assume UTC when no offset/Z is present, and pad a bare
    // "+HH"/"-HH" offset to "+HH:00" since Date can't parse it otherwise.
    var normalized = iso.replace(' ', 'T');
    if (/(Z|[+-]\d{2}(:?\d{2})?)$/.test(normalized)) {
      normalized = normalized.replace(/([+-]\d{2})$/, '$1:00');
    } else {
      normalized += 'Z';
    }
    var seconds = Math.max(0, Math.floor((Date.now() - new Date(normalized).getTime()) / 1000));
    if (seconds < 60) return 'Just now';
    var minutes = Math.floor(seconds / 60);
    if (minutes < 60) return minutes + ' minute' + (minutes === 1 ? '' : 's') + ' ago';
    var hours = Math.floor(minutes / 60);
    if (hours < 24) return hours + ' hour' + (hours === 1 ? '' : 's') + ' ago';
    var days = Math.floor(hours / 24);
    return days + ' day' + (days === 1 ? '' : 's') + ' ago';
  }

  function render(data) {
    var items = data.items || [];
    var unread = typeof data.unreadCount === 'number'
      ? data.unreadCount
      : items.filter(function (i) { return i.unread !== false; }).length;

    if (badge) {
      if (unread > 0) {
        badge.textContent = unread > 9 ? '9+' : String(unread);
        badge.classList.remove('hidden');
      } else {
        badge.classList.add('hidden');
      }
    }
    if (countLabel) {
      countLabel.textContent = unread > 0 ? (unread + ' new') : 'All caught up';
    }

    list.innerHTML = items.length
      ? items.map(function (item) {
          var isUnread = data.tracksRead ? item.unread !== false : false;
          var heading = item.title
            ? '<p class="text-sm ' + (isUnread ? 'font-semibold text-gray-900' : 'font-medium text-gray-600') + '">' + escapeHtml(item.title) + '</p>' +
              '<p class="text-sm text-gray-600 mt-0.5">' + escapeHtml(item.text) + '</p>'
            : '<p class="text-sm text-gray-800">' + escapeHtml(item.text) + '</p>';
          return '<li class="' + (isUnread ? 'bg-red-50/40 ' : '') + 'hover:bg-gray-50">' +
            '<a href="' + escapeHtml(item.link) + '" class="block px-4 py-3">' +
            heading +
            '<p class="text-xs text-gray-400 mt-1">' + timeAgo(item.ts) + '</p>' +
            '</a></li>';
        }).join('')
      : '<li class="px-4 py-6 text-center text-sm text-gray-400">No notifications right now.</li>';
  }

  fetch('api/notifications.php')
    .then(function (res) { return res.ok ? res.json() : Promise.reject(); })
    .then(render)
    .catch(function () {
      list.innerHTML = '<li class="px-4 py-6 text-center text-sm text-gray-400">Unable to load notifications.</li>';
      if (countLabel) countLabel.textContent = '';
    });

  var btn = document.getElementById('notifBtn');
  var panel = document.getElementById('notifPanel');
  if (btn && panel && btn.hasAttribute('data-shared-toggle')) {
    var profilePanel = document.getElementById('profilePanel');
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (profilePanel) profilePanel.classList.add('hidden');
      panel.classList.toggle('hidden');
    });
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () { panel.classList.add('hidden'); });
    window.addEventListener('scroll', function () { panel.classList.add('hidden'); }, { passive: true });
    // Opening the profile menu should close the bell (the profile handler on
    // these pages only toggles its own panel).
    var profileBtn = document.getElementById('profileBtn');
    if (profileBtn) profileBtn.addEventListener('click', function () { panel.classList.add('hidden'); });
  }
})();
