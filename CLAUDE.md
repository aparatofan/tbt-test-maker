# TBT Test Maker — Claude Code guidance

Keep this file concise. It is loaded at the start of every Claude Code session.

## Start here

- Work from the task, not from a full-repository scan.
- The current release is specified in `docs/SPEC-0.5.0.md`. Follow it in order. Phase 0 is read-only and ends with a report; do not edit anything before Mariusz answers it.
- `docs/prototypes/*.html` are the approved designs. Where the spec and a prototype disagree, the spec wins; where the spec is silent, the prototype decides.
- First inspect `git status`, then read only the files named in the task or located by targeted search.
- Do not change unrelated code, formatting, wording, or styling.

## Project basics

- WordPress 6.x plugin; PHP 8.0+.
- Pure PHP + vanilla JavaScript. No jQuery, build system, CDN, Composer, or npm dependency.
- Main bootstrap: `tbt-test-maker.php`. Server-side logic: `includes/`. Assets: `assets/`. Standalone tests: `tests/`.
- Class and constant prefix `TBTTM_`, function and option prefix `tbttm_`, CSS class prefix `tbttm-`. TBT Hub's site-wide sheet claims unprefixed `.tbt-*` names; never use them.
- `includes/lib/tfpdf/` is the vendored PDF library (tFPDF, LGPL) and `assets/fonts/*.ttf` are the vendored certificate fonts (Apache 2.0), each with its licence text. Never edit them; what the plugin adds lives in `includes/class-tbttm-pdf.php`.
- `assets/css/tbt-tokens.css` is a vendored copy of the shared TBT tokens. Never edit a value in it. No stylesheet in this plugin defines a colour of its own. The one exception to "no colour values" is the constants in `TBTTM_Certificate` and `TBTTM_Mail`: a PDF and an email cannot read tokens.

## Architecture to preserve

- The student page (`[tbt_test]`) and the teacher page (`[tbt_test_maker]`) are separate surfaces with separate assets (`player.*` vs `teacher.*`).
- **The answer key never reaches the browser before the attempt is finished.** Scoring happens on the server. In "after each question" mode the server reveals one answer at a time, after it has stored the student's choice.
- **A student's answers are never lost.** Every answer is saved to the server as it is given and mirrored in the browser. A failed request keeps the answers and retries; it never clears state or navigates away.
- A gap question's stored body holds its answers. The student page only ever receives `TBTTM_Gaps::public_body()`. Scores count points: one per multiple choice question, one per gap.
- There is no public read route for test content. Students get questions only inside the server-rendered student page, after the access check.
- A finished attempt stores a snapshot of what the student saw, so later edits to a test never change old results.
- Results are owner-only and always read from the stored snapshot. Review entries carry the question `id` from 0.3.0; older ones do not.
- The certificate and the result email are extras: a failure in either never blocks or delays a finish. Certificates are built on demand and never stored.
- An attempt moves to finished through one conditional write (`WHERE status = 'in_progress'`). Only the request that makes it fires `tbttm_attempt_finished`.
- A guest attempt (`user_id = 0`) is owned by its token, sent in `X-TBTTM-Token`. Guests never get a nonce.
- TBT Notes and TBT Hub are optional. The plugin must work when they are absent.
- Teacher-page lists and mutations are owner-scoped, including for administrators.
- Never change `TBTTM_DB_VERSION` unless there is a real schema change. Stored column names are data contracts: add, never rename.

## Security rules

- Never expose or commit API keys, credentials, or real configuration values.
- Authenticated writes keep nonce, capability, and ownership checks. Every REST route has a real `permission_callback`.
- Sanitize input, escape output, and use `$wpdb->prepare()` for every query that contains a value.
- Do not weaken an existing security check to make a feature work.

## Validation

```bash
for f in tbt-test-maker.php includes/*.php; do php -l "$f"; done
for f in assets/js/*.js; do node --check "$f"; done
php tests/test-gaps.php
php tests/test-validator.php
php tests/test-results.php
php tests/test-certificate.php
php tests/test-finish.php
node tests/test-parse.js
```

State clearly what still needs a browser or live-site check; static checks do not verify behaviour on WordPress and Divi.

## Git and deployment

- **Never merge a PR and never push to `main`.** A merge to `main` deploys to the live site; that is Mariusz's decision. Stop at an open PR and report.
- Use a focused feature branch. Keep commits task-focused.
- Every release bumps `TBTTM_VERSION` and the plugin header version, even for CSS-only changes; the version is the cache buster.
- **Never rename `tbt-test-maker.php` or the `/tbt-test-maker/` server folder.** The FTP deploy uploads but never deletes.
- Markdown files, `.github/`, `docs/` and `tests/` are excluded from the upload.

## Context discipline

- Prefer targeted search and narrow reads over broad exploration.
- Summarise completion as: what changed, what was checked, and what still needs live-site verification.
