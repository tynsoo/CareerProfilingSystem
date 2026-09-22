/*
 * ProfilePath — admin/staff sidebar profile card.
 * Populates #sidebarProfileName/#sidebarProfileEmail/#sidebarProfileAvatarWrap
 * from api/session.php on any page that has them. No-ops on pages without
 * that markup (e.g. student pages, which use their own header profile block).
 */
(function () {
  'use strict';

  var nameEl = document.getElementById('sidebarProfileName');
  if (!nameEl) return;

  var emailEl = document.getElementById('sidebarProfileEmail');
  var avatarWrap = document.getElementById('sidebarProfileAvatarWrap');
  var roleLabels = { admin: 'Administrator', counselor: 'Guidance Counselor' };

  fetch('api/session.php')
    .then(function (res) { return res.ok ? res.json() : Promise.reject(); })
    .then(function (data) {
      var user = data.user;
      if (!user) return;
      nameEl.textContent = user.username;
      if (emailEl) emailEl.textContent = user.email || roleLabels[user.role] || user.role;
      if (avatarWrap && user.avatarUrl) {
        avatarWrap.innerHTML = '<img src="' + user.avatarUrl + '" alt="" class="w-full h-full object-cover">';
      }
    })
    .catch(function () {});
})();
