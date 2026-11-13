# TBT Test Maker 0.4.0 — Certificate and result email

Spec for Claude Code. Written 13 November 2026. Builds on 0.3.0, which is live.

## What this release is

Two settings per test, both off by default:

1. **Certificate.** A student who finishes (or passes, when the test has a pass mark) downloads a PDF certificate with their name, the test title, their score and the date.
2. **Email the result.** The student gets their score by email when they finish, with the certificate attached when they earned one.

This is the last of the four agreed stages. The two rules still come first: a student's answers are never lost, and the answer key never reaches the browser before the attempt is finished. **Nothing in this release may delay, block or fail the finish.** A certificate that cannot be drawn or an email that cannot be sent is a missing extra, never an error on the result page.

## Out of scope for 0.4.0

Gap-fill questions, leaderboard, AI question generation, importing the QSM quizzes, exporting results to CSV, resending an email from the Results screen, storing generated PDFs.

No schema change: `certificate`, `email_result` and `guest_email` already exist. `TBTTM_DB_VERSION` stays `1.0`.

## Reference material

- `docs/prototypes/certificate.html` — the approved certificate (the "TBT fonts" lettering, not "As it is now") and the approved result email. Open it in a browser.
- `docs/prototypes/teacher.html` — the Certificate and Email the result switches in Settings, with their hints.

Where this spec and a prototype disagree, the spec wins. Where the spec is silent, the prototype decides.

---

## Phase 0 — Inventory (read-only, then stop and report)

Make no edits. Report on each point:

1. **This repo.** Confirm `main` is at 0.3.0 and the checks in `CLAUDE.md` pass.
2. **PDF library.** The plugin has no Composer dependency and keeps it that way, so the PDF writer must be a small library vendored into `includes/lib/`, as `qrcode.min.js` is vendored for JavaScript. It must:
   - be pure PHP, run on PHP 8.0 to 8.3, and need no extension beyond what WordPress itself requires
   - have a licence compatible with GPL-2.0-or-later
   - embed TrueType fonts with full Polish letters (ą ć ę ł ń ó ś ź ż)
   - place a JPEG as a full-page background and position text in millimetres or points
   - measure the width of a string, so a long line can be shrunk to fit

   Compare the realistic candidates (tFPDF and FPDF with a Unicode font add-on at least), and recommend one with its size, licence and the files to vendor. Do not download or add anything yet.
3. **Fonts.** The certificate needs Roboto Slab 700, Roboto 400 and Roboto 500 as `.ttf` (the bundled `.woff2` files cannot be embedded in a PDF). Report where the files come from, their licences, and their total size.
4. **Background.** The certificate background is `https://thebluetree.pl/wp-content/uploads/2026/03/tbt_certificate_background_tree_left_signature.jpg` (1979 × 1403). Report how a setting holding an uploads URL is turned into a local file path safely, and what happens when the file is not on this server.
5. **Finish.** Report where an attempt moves to finished, and the one place a "just finished" action can run exactly once, given that `/finish` is idempotent.
6. **Result links.** Report how the student page opens a stored result today ("See your last result"), and what is needed for a link from an email to open one result directly.
7. **Email.** Report how the site sends mail today, if that can be seen from the code (plain `wp_mail()` or an SMTP plugin), and whether any sibling plugin sends HTML mail to copy from.

**Stop and report** without proceeding if no library meets every requirement in point 2, or if the fonts' licences do not allow bundling. Otherwise end with the report and the library recommendation, and wait for Mariusz to say go.

---

## Task 1 — Settings and validation

**Builder.** Two switches at the end of Settings, after Bull pictures, as in the prototype:

- **Certificate.** Hint with a pass mark: "Students who pass can download a certificate with their name and score." Without: "Students who finish can download a certificate with their name and score."
- **Email the result.** Hint when off: the prototype's. When on: "Students get their score by email when they finish", plus ", with the certificate" when Certificate is on, plus " People without a login type their email before they start." when the test is open.

**Validator.** `certificate` and `email_result` are booleans; anything else is false. Extend `tests/test-validator.php`. The REST routes read and write both; `duplicate` copies both.

**Site settings.** One new field on the settings page: **Certificate background** (`tbttm_certificate_bg`), a URL, with the default above. Under the field, say whether the file was found: "Found on this server." or, in red, "This file is not on this server, so certificates are switched off."

## Task 2 — The certificate

**Who gets one.** A finished attempt on a test with Certificate on, where the test has no pass mark or the attempt reached it. The pass mark is the test's current one, as everywhere else. Add `TBTTM_Certificate::available( $attempt, $test )`; it is also false when the background file or a font file cannot be read.

**What it says**, in this order, as in the prototype:

| Line | Text |
| --- | --- |
| caption | THIS IS TO CERTIFY THAT |
| name | the student's name |
| caption | HAS SUCCESSFULLY COMPLETED |
| title | the test title |
| caption | ACHIEVING THE SCORE OF |
| score | `9 OUT OF 10` |
| date | `13 November 2026` — the day the attempt finished, in the site's time zone |

- **Name.** A guest's name as typed. For a logged-in student: first name and last name when both are set, otherwise the display name.
- The certificate is always in English, whatever the site language.

**The page.** One A4 landscape page. The background fills the page. Take every position and size from the prototype, where each is a share of the picture's width or height: the text column starts at 22% and is 56% wide, and the lines sit at 21.8%, 29.3%, 36.4%, 43.1%, 49%, 54.7% and 60.2% of the height, centred on those points. Fonts, weights, sizes, letter-spacing and colours as in the prototype's "TBT fonts" lettering: Roboto Slab 700 in TBT blue for name, title and score; Roboto 500 for captions; Roboto 400 in the muted colour for the date.

**Long names and titles.** A name or title wider than the column is scaled down to fit, to no less than half its size. One still too wide at half size is cut with an ellipsis. Nothing ever wraps or runs into the border.

**Colours.** A PDF cannot read CSS tokens, so the three colours live once, as constants in `TBTTM_Certificate`, with a comment naming the token each one copies (`--tbt-blue`, `--tbt-ink`, `--tbt-muted`). This is the only place in the plugin where a colour is written as a value.

**Making it.** `TBTTM_Certificate::pdf( $attempt, $test )` returns the PDF as a string, built on demand from the attempt's stored score. Nothing is written to the uploads folder. The PDF has no answers in it.

**Routes**

| Route | Does |
| --- | --- |
| `GET /attempts/{token}/certificate` | The PDF, with the same access rule as `GET /attempts/{token}/result`: the token is the credential for a guest attempt; a user's attempt needs that user logged in. 404 when `available()` is false. |
| `GET /tests/{id}/attempts/{attempt}/certificate` | The same PDF for the test's owner, from the Results screen. Same checks as the other Results routes. |

Both answer with `Content-Type: application/pdf`, `Content-Disposition: attachment`, no-cache headers, and the file name `certificate-{test title as a slug}.pdf`.

**Where the student gets it.** On the result page, when the result payload says a certificate is available (add `certificate: true|false` to the payload): a `Download certificate` button beside `TRY AGAIN`. It is a secondary button, not uppercase. The same button shows on a result opened later. If the download fails: "We couldn't make your certificate. Try again in a moment." — the result stays as it is.

**Where the teacher gets it.** On the Results screen, an open attempt that earned a certificate shows a `Certificate` link next to `Remove attempt`.

Add `tests/test-certificate.php`, standalone: who gets one (with and without a pass mark, at the mark, just below it, Certificate off), the name rule, the fit-and-cut rule for long lines, the date in the site's time zone, and the file name.

## Task 3 — The guest's email

When a test is open and has Email the result on, a logged-out visitor's start screen shows a second field under Your name:

- **Your email**, required, checked with a simple pattern in the browser and `is_email()` on the server. Hint: "We send your result here. Nothing else."
- `POST /attempts` from a guest takes `email`, stores it in `guest_email`, and refuses a missing or invalid one with 400 when the test has Email the result on. With it off, any `email` sent is ignored and nothing is stored.
- The email is kept with the name under `tbttm:{test id}:guest`, so `CONTINUE TEST` does not ask again.
- A logged-in student is never asked: their account email is used.

On the Results screen, an open guest attempt shows the email after the name. It appears nowhere else and in no student-facing payload.

## Task 4 — The result email

**When.** Once, at the moment an attempt moves to finished, on a test with Email the result on. A repeated `/finish` on a finished attempt sends nothing. `TRY AGAIN` is a new attempt and sends again when it finishes.

**To whom.** The logged-in student's account email, or the guest's `guest_email`. No address, no email. The test's owner previewing their own test gets no email.

**How.** `wp_mail()`, as HTML with a plain-text alternative if the site's mailer allows it (Phase 0, point 7). It runs after the attempt is stored and the response is ready: use `register_shutdown_function` or the equivalent Phase 0 recommends, with `fastcgi_finish_request()` when it exists, so a slow mail server never makes the student wait. Any failure is caught and logged with `error_log()` when `WP_DEBUG_LOG` is on; the student sees nothing.

**What it says**, following the prototype:

- Subject: `Your result: {test title}`
- "Hi {first name}," — the first word of the name
- "You finished **{test title}**. Here is your result."
- The score, large: `9 / 10 · 90%`, with a `Passed` or `Not passed yet` label only when the test has a pass mark
- With a certificate: "Your certificate is attached. To see which answers were right and which were wrong, open your result page." Without: "To see which answers were right and which were wrong, open your result page."
- A `See my answers` button
- Footer: "The Blue Tree · The Place Where People Grow"

HTML email cannot use the stylesheet or its tokens: use inline styles and a simple table layout, with the same three colour constants as the certificate plus the success green, declared once in `TBTTM_Mail`. No images, no web fonts.

**The link.** `See my answers` opens the student page with that result showing: `{student page}?test={slug}&result={token}`. The player reads `result`, and shows that result through the existing result route. For a guest attempt the token is enough. For a logged-in student's attempt the usual login prompt comes first and returns to the same link. A token that does not resolve shows the ordinary start screen. Embedded tests (`[tbt_test test="…"]`) link to the Student page, not to the page they were embedded in.

**The attachment.** When `available()` is true, write the PDF to a temporary file (`wp_tempnam()`), attach it under the certificate's file name, and delete it after sending, also when sending fails.

**Abuse.** The guest email field must not become a way to send mail to strangers at will: no more than 3 result emails to one address per test in any hour, counted with a transient. Over the limit the attempt still finishes and the email is skipped.

## Task 5 — Styling

- The two switches, the email field and the two buttons reuse existing controls. Tokens only, `tbttm-` prefix, as before.
- The colour constants in `TBTTM_Certificate` and `TBTTM_Mail` are the one stated exception, for the reason given.
- `!important` only where the theme forces it, each one listed in the PR.

## Task 6 — Finish

- `TBTTM_VERSION` and the plugin header read `0.4.0`. Update the plugin description.
- `CLAUDE.md`: point "The current release" at `docs/SPEC-0.4.0.md`. Add `php tests/test-certificate.php` to Validation (and the 0.3.0 test if it is missing). Add under Architecture: "The certificate and the result email are extras: a failure in either never blocks or delays a finish. Certificates are built on demand and never stored." Under Project basics, note the vendored PDF library and fonts in `includes/lib/` and `assets/fonts/`, and that they are never edited.
- `README.md`: one line for 0.4.0.
- Deploy: confirm the `.ttf` files and `includes/lib/` are uploaded by the workflow and not caught by an `exclude` pattern.
- Commit this spec as the first commit on the branch.
- Run the checks. Open a PR. **Do not merge, and do not push to `main`.** The description lists what changed, what was checked, the library and fonts added with their licences, and what needs a live check.

---

## Acceptance checklist

Mariusz confirms these on the live site.

**Certificate**

- [ ] The settings page says the background was found on this server.
- [ ] Certificate on, no pass mark: every finished student sees `Download certificate`.
- [ ] Certificate on, pass mark 70%: a student at 70% gets it; a student at 65% does not.
- [ ] The PDF matches the approved prototype: positions, fonts, colours, date.
- [ ] A name with Polish letters (Żaneta Świątek-Wójcik) prints correctly.
- [ ] A very long name and a very long test title shrink to fit and stay inside the border.
- [ ] The teacher downloads the same certificate from the Results screen.
- [ ] Certificate off: no button, and the certificate link answers "not found".

**Email**

- [ ] A logged-in student finishes: the email arrives at their account address, with the score and the right Passed label.
- [ ] With a certificate earned, the PDF is attached and opens.
- [ ] `See my answers` opens that result, after login for a logged-in student.
- [ ] An open test with Email the result on asks a guest for an email and will not start without a valid one; the email arrives.
- [ ] An open test with Email the result off does not ask for an email.
- [ ] The guest's email shows on the Results screen under that attempt.
- [ ] Finishing is just as fast as before with the email on.
- [ ] The teacher taking their own test gets no email.
- [ ] Check the email in Gmail and on a phone: it reads well and did not land in spam.

**Still true**

- [ ] Turn off Wi-Fi and press `FINISH TEST` on a test with both settings on: the red notice appears, nothing is lost, and `Try again` works once the connection is back.
- [ ] View the page source during a test: the correct answers are not in it.
