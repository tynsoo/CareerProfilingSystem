# ProfilePath demo script (panel revision S7)

One scenario, told start to finish on **one device**, with real school accounts. Every account, code and link is
checked the day before, so nothing has to be typed or requested live.

The story: *a new Guidance Counselor joins the CGC, a Grade 12 STEM student takes the assessment, the result does not
match the program the student wants, and the counselor follows it up until the flag is closed.*

## The day before (checklist)

| # | Check | How |
|---|-------|-----|
| 1 | Admin account signs in | `/staff-login` with the Head of Guidance account. Change the password if it is still the seeded one. |
| 2 | Staff account creation is **Invite only** | Security Configuration, Staff Account Creation. |
| 3 | The counselor account is invited **and activated** | Account Management, Invite staff, with a real `@mcl.edu.ph` address. Open the email, Set Your Password with the code, sign in once. Write the password on the presenter's card, not in a file. |
| 4 | A second invitation is ready to show live | Invite a second real staff address the morning of the demo, so the code is fresh (it lasts 24 hours). |
| 5 | The student account is registered and verified | A real `@live.mcl.edu.ph` student on the roster, email verified. |
| 6 | An exam session is open for the student's section | Assessment Scheduling. Copy the 6-character access code onto the card. Check the start and end time cover the demo slot. |
| 7 | The CGC booking link is saved | Staff Help Center (or Security Configuration). Open it once to see that it loads. |
| 8 | Session timeout is 30 minutes | Security Configuration, Automatic Session Timeout. A long demo will not sign anyone out. |
| 9 | Browser | One browser, two profiles or one normal and one private window: staff in one, student in the other. Zoom 110%. Clear saved passwords for the demo site. |
| 10 | Dry run | Run the whole script once, timed. Reset the student's assessment afterwards (Student Information, Reset assessment). |

## The scenario (about 12 minutes)

1. **Who can get in (2 min).** Open `/staff-register`: it says staff accounts are created by the Head of Guidance, so
   nobody can sign up on their own. Sign in as the admin, open Account Management, and show Invite staff: the admin
   picks the position (Guidance Counselor or Guidance Facilitator), the person only gets an emailed access code. Show
   the second invitation's email arriving. Point out that until the person sets a password with the code, the account
   cannot sign in or open any page.
2. **Activation (1 min).** Copy the code from the email, including the space around it, and paste it on Set Your
   Password. It is accepted: spaces, line breaks and dashes are dropped. Say that this code lasts 24 hours, and that the
   6-digit code for a later password change is a different one that lasts 5 minutes.
3. **The student takes the assessment (3 min).** In the student window, sign in, open the assessment, paste the access
   code from the card, answer a few questions (or continue a prepared, almost finished attempt), submit.
4. **The result (2 min).** Show the RIASEC profile, the top three letters, the program list from cosine similarity,
   and the worksheet where the student's preferred program **differs** from the best RIASEC match. Show the
   "talk to a guidance counselor" message.
5. **The follow-up (3 min).** In the staff window, Monitoring shows the flag in Needs Review. Review it and escalate it
   to the Counseling Queue. Invite the student to counseling (booking link by email and notification). In the queue,
   record the booking: *Booked a session*, with the date. Then Record the counseling outcome, for example *Counseled,
   student kept their chosen program*, with a note.
6. **Traceability (1 min).** Open the History tab: the closed flag with the outcome, when it was closed and by whom.
   Open the Audit Log and filter by today: the invitation, the escalation, the booking status, the outcome, and a
   blocked attempt (for example a facilitator trying to send an invitation).

## Who does what (for questions)

| Role | Can | Cannot |
|------|-----|--------|
| Administrator (Head of Guidance) | Everything, including the permission table, security settings, invitations and co-admins | |
| Co-admin (a Guidance Counselor chosen by the admin, at most 3) | Invite, approve, unlock and deactivate staff; reset assessments; read the Audit Log | Change the permission table or security settings; make co-admins |
| Guidance Counselor (licensed) | Review and escalate flags, invite students to counseling, record booking status and counseling outcomes, write notes | Security settings |
| Guidance Facilitator (non-licensed support) | View flags and student information, write notes | Send counseling invitations, record booking status or counseling outcomes, close a flag in the Counseling Queue |

## If something fails live

- **"Invalid code"**: check that the newest email is used; an earlier code stops working when a new one is sent.
- **Signed out**: the session timeout ended it. Sign in again; the student's answers are saved.
- **Email does not arrive**: use the prepared, already activated account from step 3 of the checklist and continue.
