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

  // Icons reuse the same Material paths as the sidebar so a notification's
  // icon matches the page it leads to.
  var ICONS = {
    people: 'M40-160v-112q0-34 17.5-62.5T104-378q62-31 126-46.5T360-440q66 0 130 15.5T616-378q29 15 46.5 43.5T680-272v112H40Zm720 0v-120q0-44-24.5-84.5T666-434q51 6 96 20.5t84 35.5q36 20 55 44.5t19 53.5v120H760ZM247-527q-47-47-47-113t47-113q47-47 113-47t113 47q47 47 47 113t-47 113q-47 47-113 47t-113-47Zm466 0q-47 47-113 47-11 0-28-2.5t-28-5.5q27-32 41.5-71t14.5-81q0-42-14.5-81T544-792q14-5 28-6.5t28-1.5q66 0 113 47t47 113q0 66-47 113ZM120-240h480v-32q0-11-5.5-20T580-306q-54-27-109-40.5T360-360q-56 0-111 13.5T140-306q-9 5-14.5 14t-5.5 20v32Zm296.5-343.5Q440-607 440-640t-23.5-56.5Q393-720 360-720t-56.5 23.5Q280-673 280-640t23.5 56.5Q327-560 360-560t56.5-23.5ZM360-240Zm0-400Z',
    flag: 'M120-120v-80l80-80v160h-80Zm160 0v-240l80-80v320h-80Zm160 0v-320l80 81v239h-80Zm160 0v-239l80-80v319h-80Zm160 0v-400l80-80v480h-80ZM120-327v-113l280-280 160 160 280-280v113L560-447 400-607 120-327Z',
    help: 'M440-120v-80h320v-284q0-117-81.5-198.5T480-764q-117 0-198.5 81.5T200-484v244h-40q-33 0-56.5-23.5T80-320v-80q0-21 10.5-39.5T120-469l3-53q8-68 39.5-126t79-101q47.5-43 109-67T480-840q68 0 129 24t109 66.5Q766-707 797-649t40 126l3 52q19 9 29.5 27t10.5 38v92q0 20-10.5 38T840-249v49q0 33-23.5 56.5T760-120H440ZM331.5-411.5Q320-423 320-440t11.5-28.5Q343-480 360-480t28.5 11.5Q400-457 400-440t-11.5 28.5Q377-400 360-400t-28.5-11.5Zm240 0Q560-423 560-440t11.5-28.5Q583-480 600-480t28.5 11.5Q640-457 640-440t-11.5 28.5Q617-400 600-400t-28.5-11.5ZM241-462q-7-106 64-182t177-76q89 0 156.5 56.5T720-519q-91-1-167.5-49T435-698q-16 80-67.5 142.5T241-462Z',
    announce: 'M720-440v-80h160v80H720Zm48 280-128-96 48-64 128 96-48 64Zm-80-480-48-64 128-96 48 64-128 96ZM200-200v-160h-40q-33 0-56.5-23.5T80-440v-80q0-33 23.5-56.5T160-600h160l200-120v480L320-360h-40v160h-80Zm240-182v-196l-98 58H160v80h182l98 58Zm120 36v-268q27 24 43.5 58.5T620-480q0 41-16.5 75.5T560-346ZM300-480Z',
    exam: 'M200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Zm0 0v-80 80Zm280 240q-17 0-28.5-11.5T440-440q0-17 11.5-28.5T480-480q17 0 28.5 11.5T520-440q0 17-11.5 28.5T480-400Zm-188.5-11.5Q280-423 280-440t11.5-28.5Q303-480 320-480t28.5 11.5Q360-457 360-440t-11.5 28.5Q337-400 320-400t-28.5-11.5ZM640-400q-17 0-28.5-11.5T600-440q0-17 11.5-28.5T640-480q17 0 28.5 11.5T680-440q0 17-11.5 28.5T640-400ZM480-240q-17 0-28.5-11.5T440-280q0-17 11.5-28.5T480-320q17 0 28.5 11.5T520-280q0 17-11.5 28.5T480-240Zm-188.5-11.5Q280-263 280-280t11.5-28.5Q303-320 320-320t28.5 11.5Q360-297 360-280t-11.5 28.5Q337-240 320-240t-28.5-11.5ZM640-240q-17 0-28.5-11.5T600-280q0-17 11.5-28.5T640-320q17 0 28.5 11.5T680-280q0 17-11.5 28.5T640-240Z'
  };
  var TYPE_STYLE = {
    registration: { icon: 'people', bg: '#dbeafe', fg: '#1d4ed8' },
    flag: { icon: 'flag', bg: '#fee2e2', fg: '#b91c1c' },
    help_request: { icon: 'help', bg: '#ffedd5', fg: '#c2410c' },
    help_resolved: { icon: 'help', bg: '#dcfce7', fg: '#15803d' },
    flag_resolved: { icon: 'flag', bg: '#dcfce7', fg: '#15803d' },
    flag_escalated: { icon: 'flag', bg: '#ffedd5', fg: '#c2410c' },
    announcement: { icon: 'announce', bg: '#ede9fe', fg: '#6d28d9' },
    schedule_published: { icon: 'exam', bg: '#dbeafe', fg: '#1d4ed8' },
    retake_request: { icon: 'help', bg: '#ffedd5', fg: '#c2410c' },
    retake_decision: { icon: 'help', bg: '#dcfce7', fg: '#15803d' },
    counseling_invite: { icon: 'help', bg: '#dbeafe', fg: '#1d4ed8' }
  };

  function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function iconFor(type) {
    var s = TYPE_STYLE[type];
    if (!s) return '';
    return '<span class="shrink-0 w-8 h-8 rounded-full flex items-center justify-center" style="background:' + s.bg + ';color:' + s.fg + '" aria-hidden="true">' +
      '<svg class="w-4 h-4" viewBox="0 -960 960 960" fill="currentColor"><path d="' + ICONS[s.icon] + '"/></svg></span>';
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

  var markLink = null;
  function ensureMarkLink(show) {
    if (!countLabel) return;
    if (!markLink) {
      markLink = document.createElement('button');
      markLink.type = 'button';
      markLink.className = 'hidden text-xs font-medium text-[#ed1c24] hover:underline ml-2';
      markLink.textContent = 'Mark all as read';
      var wrap = document.createElement('span');
      wrap.className = 'flex items-center';
      countLabel.parentNode.insertBefore(wrap, countLabel);
      wrap.appendChild(countLabel);
      wrap.appendChild(markLink);
      markLink.addEventListener('click', function (e) {
        e.stopPropagation();
        fetch('api/notifications-read.php', { method: 'POST' }).then(function () { load(); });
      });
    }
    markLink.classList.toggle('hidden', !show);
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
    ensureMarkLink(!!data.tracksRead && unread > 0);

    list.innerHTML = items.length
      ? items.map(function (item) {
          var isUnread = data.tracksRead ? item.unread !== false : false;
          var heading = item.title
            ? '<p class="text-sm ' + (isUnread ? 'font-semibold text-gray-900' : 'font-medium text-gray-700') + '">' + escapeHtml(item.title) + '</p>' +
              '<p class="text-sm text-gray-600 mt-0.5 break-words">' + escapeHtml(item.text) + '</p>'
            : '<p class="text-sm text-gray-800">' + escapeHtml(item.text) + '</p>';
          return '<li class="' + (isUnread ? 'bg-red-50/40 ' : '') + 'hover:bg-gray-50">' +
            '<a href="' + escapeHtml(item.link) + '" class="flex items-start gap-3 px-4 py-3">' +
            iconFor(item.type) +
            '<div class="min-w-0 flex-1">' + heading +
            '<p class="text-xs text-gray-400 mt-1">' + timeAgo(item.ts) + '</p></div>' +
            (isUnread ? '<span class="mt-2 w-2 h-2 rounded-full bg-[#ed1c24] shrink-0" aria-label="Unread"></span>' : '') +
            '</a></li>';
        }).join('')
      : '<li class="px-4 py-8 text-center text-sm text-gray-400">You\'re all caught up.</li>';
  }

  function load() {
    return fetch('api/notifications.php')
      .then(function (res) { return res.ok ? res.json() : Promise.reject(); })
      .then(render)
      .catch(function () {
        list.innerHTML = '<li class="px-4 py-6 text-center text-sm text-gray-400">Unable to load notifications.' +
          ' <button type="button" id="notifRetry" class="text-[#ed1c24] font-medium hover:underline">Try again</button></li>';
        if (countLabel) countLabel.textContent = '';
        var retry = document.getElementById('notifRetry');
        if (retry) retry.addEventListener('click', function (e) { e.stopPropagation(); load(); });
      });
  }
  load();

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
  window.ProfilePathNotifications = { reload: load };
})();
