<?php

// The FAQs that were written into help-center.html and admin-help-center.html before they became
// editable. db/migrate_add_faqs.php starts the faqs table with these, so nothing is lost.
return [
    'student' => [
        ['What is the RIASEC Career Interest Assessment?', 'It\'s an interest inventory based on Holland\'s RIASEC theory. Your answers show how strongly you lean toward each of six interest areas — Realistic, Investigative, Artistic, Social, Enterprising, and Conventional — which are then used to suggest careers that fit you.'],
        ['Can I pause and come back later?', 'Yes. Your answers are saved automatically as you go, so you can close the page and continue from where you left off.'],
        ['Can I retake the assessment?', 'Yes, if the Guidance Office approves it. On the Assessments page, choose Request a retake and tell them why. You will be notified when it is approved, and your earlier result stays on your record.'],
        ['What is the Career Worksheet?', 'It unlocks after you finish the RIASEC assessment. You\'ll write down a career you\'re considering and pick electives aligned to your strand and results.'],
        ['How do I change my password?', 'Open My Profile from the menu at the top right, then click Edit next to Password. Enter your current password and choose a new one.'],
    ],
    'staff' => [
        ['How do I resolve a counseling request?', 'Open the request card and click "Mark Resolved" once you’ve followed up with the student. It moves to the Resolved tab and the student gets a notification that their request was resolved.'],
        ['What does "Availed" counseling status mean on a student’s profile?', 'It comes from the student’s latest counseling invitation. "Invited" means staff sent Invite to counseling or the student clicked Avail Counseling (it does not mean a session was booked). The Guidance Counselor or the Administrator then records Booked, Availed (attended), Did Not Avail (did not attend), Declined or Cancelled on the Student Profile. "No request" means there is no invitation yet.'],
        ['How is a counseling request different from a Monitoring escalation?', 'Monitoring automatically flags a student when their top career match falls below the confidence threshold, for a counselor to review. A counseling request here is initiated directly by the student asking for help — the two are tracked independently.'],
        ['Can I tell which staff member responded to a request?', 'Not from this page currently — resolving a request marks it complete and notifies the student, but doesn’t record which staff member handled it. Check the Audit Log for a full history of resolve actions.'],
    ],
];
