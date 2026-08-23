/*
 * ProfilePath — shared header notification dropdown.
 * Populates #notifPanel's #notifList/#notifCountLabel/#notifBadge from
 * api/notifications.php on any page that has them. No-ops on pages without
 * that markup (e.g. pages that don't have a notification bell yet).
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
    var seconds = Math.max(0, Math.floor((Date.now() - new Date(iso.replace(' ', 'T') + 'Z').getTime()) / 1000));
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

    if (badge) {
      if (items.length > 0) {
        badge.textContent = items.length > 9 ? '9+' : String(items.length);
        badge.classList.remove('hidden');
      } else {
        badge.classList.add('hidden');
      }
    }
    if (countLabel) {
      countLabel.textContent = items.length > 0 ? (items.length + ' new') : 'All caught up';
    }

    list.innerHTML = items.length
      ? items.map(function (item) {
          return '<li class="px-4 py-3 hover:bg-gray-50">' +
            '<a href="' + escapeHtml(item.link) + '" class="block">' +
            '<p class="text-sm text-gray-800">' + escapeHtml(item.text) + '</p>' +
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
})();
