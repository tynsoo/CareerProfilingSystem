/*
 * The permission table's rows, for the pages that show them: the editable table on Security Configuration (administrator)
 * and My Access (Guidance Counselors and Facilitators, their own levels). Each feature is a key of Rbac::DEFAULTS in
 * modules/Authentication/lib/Rbac.php, shown under the sidebar page it lives on, in the sidebar's groups and order
 * (PP.navMenu from js/admin-nav.js, so load this after the sidebar). "desc" says what View Only and Full allow. The levels
 * themselves always come from the server.
 */
(function () {
  var FEATURES = {
    dashboard: { label: 'Dashboard', desc: 'The summary cards and progress charts.' },
    studentInfo: { label: 'Student information', desc: 'The student list and profiles. Full: also change a student\'s section.' },
    studentAccounts: { label: 'Student account management', desc: 'See if an account is active or locked. Full: deactivate, unlock, invite to register, send reminders. Needs Student information.' },
    assessmentResults: { label: 'Assessment results', desc: 'The RIASEC code and top interest areas on the profile (no scores), and the Course Match chart. Needs Student information or Dashboard.' },
    recommendations: { label: 'Career recommendations', desc: 'Recommended programs on the profile and CBF Candidate Programs.' },
    notesCreate: { label: 'Create notes', desc: 'Write notes on a student profile (Full or No Access). Needs Student information.' },
    exportResults: { label: 'Export student results', desc: 'View Only: summary downloads (totals and percentages). Full: lists with student names. Needs Student information or Analytics and reports.' },
    analytics: { label: 'Analytics and reports', desc: 'The charts and tables on Analytics & Report.' },
    analyticsEmail: { label: 'Send analytics report', desc: 'Email the Analytics Report to an @mcl.edu.ph address (Full or No Access). Needs Analytics and reports.' },
    programs: { label: 'Program Mapping Crosswalk', desc: 'MMCL programs and their RIASEC codes. Full: add, edit, turn on or off.' },
    questionBank: { label: 'Question Bank', desc: 'The assessment questions. Full: add, edit, hide, delete, load the O*NET set.' },
    examinations: { label: 'Assessment Scheduling', desc: 'Schedules, rooms and access codes. Full: create and change them.' },
    monitoring: { label: 'Flagged student monitoring', desc: 'Flagged recommendations. Full: approve, escalate or dismiss them.' },
    announcements: { label: 'Announcements', desc: 'Read the announcements sent. Full: post, edit, delete, send reminders.' },
    counselingHistory: { label: 'View counseling history', desc: 'Notes, requests and retakes. View Only: who, when and the subject, not the message.' },
    counselingStatus: { label: 'Update counseling status', desc: 'Resolve requests and decide retakes (with View counseling history), invite to counseling (Full or No Access). Never for Guidance Facilitators.' },
    helpCenter: { label: 'Help Center', desc: 'The FAQs. Full: edit the FAQs, office hours and booking link.' },
    roster: { label: 'Student roster management', desc: 'Class rosters and sections. Full: upload rosters, add, deactivate, delete sections.' },
    staffAccounts: { label: 'Manage staff accounts', desc: 'View Only: the staff list. Full: approve sign-ups, send access codes, assign sections, deactivate.' }
  };
  // Which features are shown under which sidebar page. (Which features open a page is decided by Rbac::PAGES on the server.)
  var PAGE_FEATURES = {
    'admin-dashboard': ['dashboard', 'studentInfo', 'studentAccounts', 'assessmentResults', 'recommendations', 'notesCreate', 'exportResults'],
    'analytics': ['analytics', 'analyticsEmail'],
    'career-dataset': ['programs'],
    'question-bank': ['questionBank'],
    'assessment-scheduling': ['examinations'],
    'monitoring': ['monitoring'],
    'announcements': ['announcements'],
    'counseling-requests': ['counselingHistory', 'counselingStatus'],
    'staff-help-center': ['helpCenter'],
    'account-management': ['roster', 'staffAccounts']
  };
  // (Security Configuration and the Audit Log, Rbac::ADMIN_PAGES, are the administrator's own pages: never rows here.)
  var MODULES = Object.keys(FEATURES).map(function (id) { return { id: id, label: FEATURES[id].label, desc: FEATURES[id].desc }; });

  // The sidebar's groups and pages, each with its features. A feature not under any page (should not happen) goes last.
  function groups() {
    var menu = (window.PP && PP.navMenu) || [];
    var placed = {};
    var out = menu.map(function (g) {
      return {
        title: g.title,
        pages: g.items.filter(function (it) { return PAGE_FEATURES[it.id]; }).map(function (it) {
          PAGE_FEATURES[it.id].forEach(function (id) { placed[id] = true; });
          return { id: it.id, label: it.label, icon: it.icon, features: PAGE_FEATURES[it.id].map(function (id) { return MODULES.find(function (m) { return m.id === id; }); }) };
        })
      };
    }).filter(function (g) { return g.pages.length; });
    var rest = MODULES.filter(function (m) { return !placed[m.id]; });
    if (rest.length) out.push({ title: 'Other', pages: [{ id: 'other', label: 'Other', icon: '', features: rest }] });
    return out;
  }

  if (window.PP) PP.rbacFeatures = { features: FEATURES, pageFeatures: PAGE_FEATURES, modules: MODULES, groups: groups };
})();
