# Student-side preview (no database needed)

Edit and see the student pages without PostgreSQL, a `.env` file or any keys.

## What you need
- PHP 8.4 (check with `php -v`).
- The project folder (clone the repo and switch to your branch).

## Run it
From the project folder:

```bash
php -S localhost:8080 dev/preview.php
```

Open <http://localhost:8080/>. Press `Ctrl+C` in the terminal to stop it.

## Using it
1. On the preview home, pick a student: has not taken the assessment, assessment done, or assessment and worksheet done. You can also set the assessment schedule (open, upcoming, ended, not scheduled).
2. Open any student page from the list. A small bar at the bottom left shows you are in preview and takes you back to the home page.
3. Sample values: access code `123456`, current password `Preview#123`, forgot-password code `123456`. You can sign in with anything.

Edit the `.html`, `.css` and `.js` files as usual and refresh the browser.

## What is real and what is sample
- **Real:** the 30 assessment questions, the scoring, the career-to-program matching and the content-based filtering recommendations (they use the real code in `lib/`, run on the preview student's scores and the 30 MMCL programs in `dev/fixtures/programs.json`).
- **Sample:** the student, announcements, notifications, FAQs and help requests. Nothing is saved to a database or a file. Each browser has its own preview student, and "Apply" on the home page resets it.

## What is not covered
The staff pages (administrator, guidance counselor, facilitator) are not part of this preview. A request the preview does not know answers with `This request is not part of the student preview.`

## Changing what the server sends
If your change needs a new or different server response, do not edit `api/` or `lib/` (those are reviewed by the project owner). Edit `dev/PreviewApi.php` to try your idea in preview, and describe the response you need in your pull request.

## Safety
`dev/` is never served by the live site: `router.php` blocks `/dev/` and the Docker image leaves the folder out.
