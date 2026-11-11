# TBT Test Maker 0.1.0 — Core

Spec for Claude Code. Written 11 November 2026.

## What this release is

TBT Test Maker replaces the QSM plugin on thebluetree.pl. A teacher builds a test; a student takes it one question at a time and sees a result page at the end.

Release 0.1.0 is the core, multiple choice only:

- the teacher's library and builder, with bulk import of questions
- the student's test, with every answer saved as it is given
- the result page
- every attempt stored in the database
- the deploy workflow

Two rules come before everything else in this plugin. They exist because QSM broke both.

1. **A student's answers are never lost.** A student lost thirty minutes of work to a QSM error page. Here, every answer goes to the server as it is given and is mirrored in the browser. A failed request keeps the answers and retries. Nothing in the student's flow ever clears state or navigates away on an error.
2. **The answer key never reaches the browser before the attempt is finished.** The server scores every attempt.

## Out of scope for 0.1.0

Do not build these. Several have database columns in this release so that later releases need no schema change; the columns exist, the features do not.

| Later release | Feature |
| --- | --- |
| 0.2 | Attaching a test to a class and lesson (TBT Notes), open access without login, reporting to Class Progress |
| 0.3 | The teacher's Results screen |
| 0.4 | Certificate PDF and the result email |
| after | Gap-fill questions, leaderboard, AI question generation, importing the existing QSM quizzes |

In 0.1.0 every test is for logged-in students only.

## Reference material

- `docs/prototypes/student.html` — the approved student side.
- `docs/prototypes/teacher.html` — the approved teacher side. Its Results screen, and the Class, Who can take it, Certificate and Email the result settings, are later releases: **leave them out of 0.1.0.**
- `docs/prototypes/certificate.html` — release 0.4, for context only.

Open the files in a browser. Where this spec and a prototype disagree, the spec wins. Where the spec is silent, the prototype decides: layout, wording, states, spacing.

Sibling plugins to copy conventions from, all public under `github.com/aparatofan/`:

- `translation-drill` — the structural model: bootstrap file, `includes/` classes, own tables with `dbDelta`, capabilities, REST, URL helpers, settings page, Hub registration, student page and teacher page as two shortcodes, deploy workflow.
- `drag-and-drop` — the validator pattern (`sanitise()` for drafts, `validate()` for publishing) and the library page with the Admin Bar.
- `tbt-swipe` — a second Admin Bar implementation.

---

## Phase 0 — Inventory (read-only, then stop and report)

Make no edits in this phase. Clone the three siblings read-only (`git clone --depth 1`) into a scratch folder outside this repo.

Report on each point:

1. **This repo.** Confirm it holds only `README.md`, `CLAUDE.md`, `.gitignore` and `docs/`. List anything else.
2. **Structure.** From `translation-drill`: how the bootstrap wires classes, how `TBTD_DB::activate()` / `maybe_upgrade()` version the schema, how `TBTD_Capabilities` grants the capability, how `TBTD_Urls` finds the student and teacher pages, how the settings page and Hub item are registered.
3. **Tokens and fonts.** Which token file is current: `translation-drill/assets/css/tbt-tokens.css` or `drag-and-drop/assets/vendor/tbt/tbt-tokens.css`. Diff them. Report which one to vendor, under which path and style handle, and which font files it needs beside it.
4. **Admin Bar.** Find the shipped Admin Bar markup and CSS in `drag-and-drop` and `tbt-swipe`. Report the class structure and any theme-override technique (ID anchoring or pinned declarations) so this plugin follows the same one.
5. **Tool Hero.** Report the shipped hero markup and CSS, including the `[tbt_tree]` fallback.
6. **Login gate.** How `TBTD_Player` treats a logged-out visitor.
7. **Deploy workflow.** Read `translation-drill/.github/workflows/deploy.yml` in full. Confirm it can be reused with only the plugin name and main-file name changed.
8. **Caching.** Check whether any sibling marks its student page as uncacheable (`nocache_headers()`, `DONOTCACHEPAGE`). Report what exists.

**Stop and report.** Stop without proceeding if any of these is true:

- the repo already contains plugin code
- the two token files differ in values, not only in comments
- the Admin Bar in the siblings contradicts the geometry in Task 6
- anything in the siblings contradicts this spec in a way that changes a task

Otherwise end Phase 0 with the report and wait for Mariusz to say go.

---

## Naming and constants

| Thing | Value |
| --- | --- |
| Plugin name | TBT Test Maker |
| Folder and main file | `tbt-test-maker/tbt-test-maker.php` |
| Text domain | `tbt-test-maker` |
| Version | `TBTTM_VERSION` = `0.1.0` (also in the plugin header) |
| Schema version | `TBTTM_DB_VERSION` = `1.0` |
| Capability | `TBTTM_CAP` = `tbttm_manage` |
| Class prefix | `TBTTM_` |
| Function and option prefix | `tbttm_` |
| CSS class prefix | `tbttm-` |
| REST namespace | `tbttm/v1` |
| Student shortcode | `[tbt_test]` |
| Teacher shortcode | `[tbt_test_maker]` |

Requires WordPress 6.0 and PHP 8.0. Pure PHP and vanilla JavaScript: no jQuery, no build step, no CDN, no Composer or npm dependency.

---

## Task 1 — Plugin skeleton

Create, following `translation-drill`:

```
tbt-test-maker.php
includes/class-tbttm-db.php
includes/class-tbttm-capabilities.php
includes/class-tbttm-urls.php
includes/class-tbttm-validator.php
includes/class-tbttm-attempts.php
includes/class-tbttm-rest.php
includes/class-tbttm-teacher.php
includes/class-tbttm-player.php
includes/class-tbttm-settings.php
assets/css/tbt-tokens.css        (vendored, per the Phase 0 finding, with its fonts)
assets/css/teacher.css
assets/css/player.css
assets/js/parse.js
assets/js/teacher.js
assets/js/player.js
tests/test-validator.php
tests/test-parse.js
```

- Bootstrap only in `tbt-test-maker.php`: header, constants, requires, Hub item, activation hook, `plugins_loaded` wiring.
- Hub item: slug `tbt-test-maker`, title `TBT Test Maker`, capability `TBTTM_CAP`. Must degrade safely when Hub is absent.
- `TBTTM_Capabilities` mirrors `TBTD_Capabilities`: option `tbttm_manager_roles`, default administrators, `CAPS_VERSION`, `user_can_manage()`.
- Settings page (wp-admin, `manage_options`), nested under Hub when Hub is active:
  - Student page (`tbttm_player_page_id`) and Teacher page (`tbttm_builder_page_id`), page pickers.
  - Roles that can build tests (`tbttm_manager_roles`).
  - Bull pictures (`tbttm_bull_images`): six URL fields, with the defaults in Task 5.

## Task 2 — Database

Three tables, created with `dbDelta` in `TBTTM_DB::activate()`, with `maybe_upgrade()` on `plugins_loaded`. Option `tbttm_db_version`.

**`{prefix}tbttm_tests`**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned, auto increment, primary | |
| slug | varchar(32), unique | random, URL-safe, generated on create, never changed |
| owner_id | bigint unsigned, indexed | |
| title | varchar(200) | |
| intro | text | |
| image_id | bigint unsigned, default 0 | attachment ID of the feature image |
| status | varchar(20), default `draft` | `draft` or `published` |
| feedback | varchar(10), default `end` | `end` or `now` |
| bulls | tinyint(1), default 1 | |
| pass_mark | tinyint unsigned, null | 1–100, null = not set |
| access | varchar(10), default `login` | used from 0.2 |
| class_id | bigint unsigned, null, indexed | used from 0.2 |
| lesson_id | bigint unsigned, null | used from 0.2 |
| certificate | tinyint(1), default 0 | used from 0.4 |
| email_result | tinyint(1), default 0 | used from 0.4 |
| created_at, updated_at | datetime | |
| published_at | datetime, null | |

**`{prefix}tbttm_questions`**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned, auto increment, primary | stable across saves |
| test_id | bigint unsigned, indexed | |
| position | int, default 0 | |
| type | varchar(20), default `choice` | the only type in 0.1.0 |
| body | text | the question text, plain text with line breaks |
| data | longtext | JSON. For `choice`: `{"options":["…","…"],"correct":1}` (`correct` is a zero-based index) |

**`{prefix}tbttm_attempts`**

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned, auto increment, primary | |
| test_id | bigint unsigned, indexed | |
| user_id | bigint unsigned, default 0, indexed | |
| guest_name | varchar(120), default '' | used from 0.2 |
| guest_email | varchar(190), default '' | used from 0.4 |
| token | char(32), unique | random; identifies the attempt in result links |
| status | varchar(20), default `in_progress` | `in_progress` or `finished` |
| answers | longtext | JSON map: question ID → chosen option index |
| checked | longtext | JSON list of question IDs already checked (feedback `now`) |
| review | longtext, null | JSON snapshot written at finish, see Task 4 |
| score | smallint unsigned, null | |
| total | smallint unsigned, null | |
| percent | tinyint unsigned, null | |
| started_at, updated_at | datetime | |
| finished_at | datetime, null | |

All queries with values go through `$wpdb->prepare()`. Deleting a test deletes its questions and its attempts.

## Task 3 — Validation

`TBTTM_Validator`, following Drag & Drop's split:

- `sanitise( array $raw )` cleans a test payload without enforcing completeness. Used for draft saves.
- `validate( array $raw )` runs `sanitise()` and then the publishing rules. Returns the clean array or a `WP_Error` whose message names the problem and the question numbers.

Limits:

| Field | Limit |
| --- | --- |
| Title | required, 200 characters |
| Introduction | 1,000 characters |
| Questions per test | 200 |
| Question body | 2,000 characters; line breaks kept |
| Options per question | 2 to 6 |
| Option text | 300 characters |
| Pass mark | empty, or a whole number 1–100 |
| Feedback | `end` or `now` |

Publishing rules: at least one question; every question has a body, at least two non-empty options, and a `correct` index that points at a non-empty option.

`tests/test-validator.php` is a standalone script in the style of `translation-drill/tests/test-markup.php`. Cover: a valid test, each publishing rule failing, each limit, a draft with an unfinished question passing `sanitise()`.

## Task 4 — REST API

Namespace `tbttm/v1`. Every route has a real `permission_callback`. Every write re-checks ownership inside the callback.

**Teacher routes** — require `TBTTM_CAP`; scoped to the current user's own tests, administrators included.

| Route | Does |
| --- | --- |
| `GET /tests?search=` | The user's tests, newest first: id, slug, title, status, question count, finished-attempt count, student URL. `search` matches the title and question bodies, case- and accent-insensitive. |
| `GET /tests/{id}` | One test with its questions, including the answer key. |
| `POST /tests` | Create. Body: the whole test and `status`. |
| `PUT /tests/{id}` | Save the whole test. Questions arrive as an ordered list; a question with an `id` is updated, one without is inserted, and stored questions missing from the list are deleted. IDs stay stable. |
| `DELETE /tests/{id}` | Delete the test, its questions and its attempts. |

`status: draft` saves through `sanitise()`. `status: published` saves through `validate()`; on failure nothing is written and the error is returned.

**Student routes** — require a logged-in user. An attempt belongs to its `user_id`; every route checks that.

| Route | Does |
| --- | --- |
| `POST /attempts` `{test}` | Start, or resume. If the user has an `in_progress` attempt for this test, return it. Otherwise create one. Returns attempt id, token, `answers`, `checked`. Only for published tests. |
| `PUT /attempts/{id}` `{answers}` | Autosave. Merge the map into the stored answers. Ignore answers for question IDs not in the test, and for questions already in `checked`. Rejected once the attempt is finished. |
| `POST /attempts/{id}/check` `{question, answer}` | Feedback `now` only. Store the answer, add the question to `checked`, return `{correct}` (the correct index). A checked question cannot be answered again. |
| `POST /attempts/{id}/finish` `{answers}` | Merge the payload (same rules as autosave), score, write `review`, set `finished`. Returns the result. **Idempotent:** finishing a finished attempt returns the stored result again. |
| `GET /attempts/{token}/result` | The stored result of a finished attempt, for its owner. |

Scoring and the snapshot live in `TBTTM_Attempts`:

- `score` = questions answered correctly, `total` = questions in the test at finish, `percent` = rounded.
- `review` = an ordered list, one entry per question: `{body, options, chosen, correct}` (`chosen` is null when unanswered). Results are always rendered from this snapshot, never from the live questions, so editing a test later does not change old results.
- Result payload: `score`, `total`, `percent`, `pass_mark`, `passed` (null when no pass mark), `review`, `finished_at`.

## Task 5 — The student page

Shortcode `[tbt_test]`. Two ways to name the test:

- `[tbt_test]` on the Student page reads `?test={slug}`.
- `[tbt_test test="{slug}"]` embeds one test in any page or post. The attribute wins over the query string.

Follow `docs/prototypes/student.html` for every screen. The prototype's dashed "Prototype" strip and its name field are not part of the product.

**Access.** A logged-out visitor sees the login prompt with a link back, as in Translation Drill. A draft is visible only to its owner. An unknown slug shows a plain "This test is not available." message.

**Content.** Questions are printed into the server-rendered page for the JavaScript to use: `id`, `body`, `options` only. **The `correct` index must not appear anywhere in the page source.** There is no public read route.

**No caching.** On `template_redirect`, when the current singular page contains the shortcode, call `nocache_headers()` and define `DONOTCACHEPAGE`.

**Screens**

1. **Start.** Tool Hero (eyebrow `The Blue Tree · Test`, the test title, the introduction as the support line), the feature image when set, the facts line (number of questions; the pass mark when set; "Answers are saved as you go"), and the `START TEST` button. When the student has an attempt in progress: "You answered N of M questions last time", `CONTINUE TEST`, and a secondary `Start again`. When they have a finished attempt: a quiet link "See your last result".
2. **Question.** One question per screen, in this order: the tile row, the quiet count line, the bull picture when this question has one, the question number, the question text, the options, `Back` and `Next question` (`FINISH TEST` on the last question).
3. **Result.** The pass or fail bull when a pass mark is set, the Passed / Not passed yet label when a pass mark is set, the score and percentage, one sentence of comment, the review of every question, `TRY AGAIN`. With no pass mark there is no label and no result bull.

**Tiles.** Numbered tiles, equal width, across the full width of the card.

- Up to 25 questions: all tiles in one row.
- More than 25: one row of 20, with ‹ and › buttons at the ends. The row follows the current question.
- At 640px and below: rows of 10.
- An answered question's tile is filled with the pale blue wash (`--tbt-blue-wash`) with a periwinkle border. The current question's tile has a blue ring. A tile pops once when its question is first answered, and lifts slightly on hover. Honour `prefers-reduced-motion`.
- With feedback `now`, a checked tile is pale green or pale red instead.
- Clicking a tile goes to that question.

**Count line.** One quiet line under the tiles, small and faint: `Question 44 of 100 · 57 left` on the left, the save status on the right.

**Question text.** `body` is plain text. Escape it, keep line breaks, then apply two formatting rules:

- A line that starts with a name and a colon (`Tom:`, `Mr Smith:` — a capital letter, up to 30 letters, spaces, dots, apostrophes or hyphens, then `: `) shows the name in bold.
- `*text*` shows in italics.

**Feedback modes**

- `end` (default): the student can change any answer until they finish. Nothing is marked right or wrong before the result.
- `now`: choosing an option enables `Check answer`. Checking calls `/check`, marks the options (correct in green, a wrong choice in red), shows "Correct." or "Not quite. The correct answer is b.", and locks the question. Then `Next question`.

**Finishing with gaps.** If questions are unanswered, show the notice naming them, with `Go to question N` and `Finish anyway`.

**Bull pictures.** When the test has bulls on, show a picture above the question on four questions only:

| Picture | Question |
| --- | --- |
| start | the first |
| run | `ceil(N / 2)` |
| attack | `min(N − 1, round(N × 0.85))` |
| jump | the last |

Use the `stageAt()` function in the prototype. If two of these land on the same question in a short test, the later picture wins. The pass and fail pictures belong to the result screen.

Defaults for the `tbttm_bull_images` setting:

```
start   https://thebluetree.pl/wp-content/uploads/2019/07/1_start.jpg
run     https://thebluetree.pl/wp-content/uploads/2019/07/2_run.jpg
attack  https://thebluetree.pl/wp-content/uploads/2019/07/3_attack.jpg
jump    https://thebluetree.pl/wp-content/uploads/2019/07/4_jump.jpg
pass    https://thebluetree.pl/wp-content/uploads/2019/07/byczek_pass.jpg
fail    https://thebluetree.pl/wp-content/uploads/2019/07/byczek_fail.jpg
```

**Saving answers — the part that must not fail**

1. `START TEST` calls `POST /attempts`. The answers it returns are the starting state, so a student who comes back on another device carries on where they stopped.
2. Every answer updates the page state and is written at once to `localStorage` under `tbttm:{test id}:{user id}`. Wrap every storage call in `try/catch`; the test must work without it.
3. Answers are sent with `PUT /attempts/{id}`, debounced by about 800 ms.
4. The save status reads `✓ Saved` only after the server has answered 200. While a save is failing it reads `Offline · saved on this device`, in red. Retry every 10 seconds and on the browser's `online` event.
5. `FINISH TEST` sends all answers with `/finish`. If the request fails, show the red notice from the prototype ("We couldn't send your answers. Nothing is lost…") with `Try again`. Stay on the question. Clear nothing.
6. Clear the `localStorage` entry only after the server confirms the finish.
7. When the page loads with an attempt in progress and the browser holds answers the server does not, send them.
8. **Expired nonce.** A REST call that fails with `rest_cookie_invalid_nonce` fetches a fresh nonce from a small logged-in `admin-ajax` action (`tbttm_nonce`) and retries once. A page left open for hours must still be able to save.
9. Leaving the page with unsent answers triggers the browser's own "leave site?" prompt (`beforeunload`).

**Platform.** The student page works on a phone. Follow the prototype's 640px rules.

## Task 6 — The teacher page

Shortcode `[tbt_test_maker]`. One page, two views, as in Translation Drill: the library, and the builder at `?tbttm_new=1` / `?tbttm_edit={id}`. Requires `TBTTM_CAP`; anyone else sees the login prompt or a plain "You don't have access to this page."

Follow `docs/prototypes/teacher.html`, minus the parts listed under Reference material.

**Library**

- Tool Hero: eyebrow `The Blue Tree`, title `TEST MAKER`, support "Build tests your students take one question at a time."
- The Admin Bar, Style Book v2.0 §13, built the way the siblings build it (Phase 0, point 4): title `Your tests`; search; no dropdown; button `CREATE NEW TEST`.
  - Geometry: 10px gap either side of every item; title zone minimum 234px; search 300px fixed; button 250 × 53px pill, uppercase Roboto 16/600, 0.08em tracking; 24px below the bar; one connecting line each side of the search.
  - Search is server-side (`GET /tests?search=`), waits about 300 ms, and also looks inside question text. `×` and Escape clear it; `/` focuses it unless a text field or dialog has the keyboard. While searching, show the summary line "3 of 24 tests · Clear filters". No matches: "No tests match your search."
  - Empty library: title, one line, button. No search box. The empty state is decided by the server before the page paints.
  - At 1100px and below the lines hide, title and button share the first line and the search takes the second.
- Object-card grid, 24px gap. Each card: title, Published / Draft label, question count, finished-attempt count for a published test, and the actions `Edit`, `Copy link` (published only; copies the student URL and reads "Link copied" for a moment), `Delete`.
- Delete asks on the card itself, naming what goes with it: "Delete this test and its 14 results?" with `Delete test` and `Cancel`. No browser `confirm()`.

**Builder** — three stage cards in this order, then the save bar.

1. **About the test.** Title. Introduction. Feature image: `Choose image` opens the WordPress media library (`wp_enqueue_media()`), with a preview, `Change image` and `Remove image`.
2. **Settings.** In 0.1.0 exactly three: Feedback (`At the end` / `After each question`), Pass mark (a number and `%`, empty by default, placeholder "Not set"), Bull pictures (on by default).
3. **Questions.** Each question is a closed row: its number, the last line of its text, a quiet status ("3 answers", or the first problem), and move up, move down and remove buttons. Clicking a row opens it; one question is open at a time. An open question has the question text area and the answers, each with a circle to mark it correct, a letter, a text field and a remove button. `Add answer` up to six. Below the list: `Add question` and `Import questions`.

Content fields (title, introduction, question, answers) use Roboto Slab; everything else is Roboto.

**Save bar.** A draft has `Save draft` and `Publish test`. A published test has `Save changes` only. Publishing checks the rules in Task 3; on failure the message names the unfinished questions and the first one opens with a red border. After a first save the URL becomes `?tbttm_edit={id}` without a reload.

**Unsaved changes.** Leaving the builder with unsaved changes asks first: the browser's own prompt on unload, and an inline "Leave without saving?" on `Back to tests`.

**Import questions.** The panel from the prototype, three steps:

1. The layout: one question per row; columns `question`, `answer_a` … `answer_f`, `correct`. `Copy template` copies a tab-separated template to the clipboard.
2. A text area to paste rows copied from Excel, and `Choose a CSV file`.
3. `Check rows` lists every row with a tick or the problem named. Rows with a problem are left out. `Add N questions` appends the good rows to the question list; they are saved with the test, not before.

The reader lives in `assets/js/parse.js` and is the `parseTable()` and `readRows()` pair from the prototype:

- detects tab, semicolon or comma from the first line (tab wins; then semicolon if it outnumbers commas — Polish Excel exports semicolons)
- handles quoted cells, doubled quotes, and line breaks inside a quoted cell
- strips a BOM; skips a header row whose first cell is `question`
- `correct` may be a letter `a`–`f`, a number `1`–`6`, or the text of the right answer
- problems: "No question text", "Needs at least two answers", "Correct answer missing", "Correct letter has no answer"

`parse.js` must load in the browser and under Node. `tests/test-parse.js` covers each bullet above.

Importing past the 200-question limit adds what fits and says how many rows were left out.

## Task 7 — Styling

- All colour, radius, shadow and font values come from the vendored tokens. No stylesheet in this plugin defines a colour of its own.
- Every class carries the `tbttm-` prefix.
- Reset rules use `:where()` so they carry no specificity.
- Follow whichever theme-override technique the siblings use (Phase 0, point 4); do not introduce a new one.
- Style Book v2.0 rules that apply: 22px stage cards with 40px padding on the `#F6F8FC` canvas; 16px object cards; 12px controls; uppercase only for the one main action in a view (`CREATE NEW TEST`, `START TEST`, `FINISH TEST`, `TRY AGAIN`); selected is blue, success is green, error is `#C62828`.

## Task 8 — Deploy workflow

Add `.github/workflows/deploy.yml`, copied from `translation-drill` with these changes only:

- every `translation-drill` in names, comments and the preflight check becomes `tbt-test-maker`; the main file it looks for is `tbt-test-maker.php`
- add `docs/**` to both `exclude` lists

The workflow uses four repository secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_SERVER_DIR`. Mariusz adds them. `FTP_SERVER_DIR` is the path as the deploy account sees it and ends in `/tbt-test-maker/`.

**First deploy.** The preflight refuses to upload into a folder that does not already hold the plugin. For the first install Mariusz runs the workflow by hand from the Actions tab with `allow_create` ticked. Say so in the PR description.

## Task 9 — Finish

- `TBTTM_VERSION` and the header read `0.1.0`.
- Run the checks in `CLAUDE.md`.
- Open a PR. **Do not merge, and do not push to `main`.**
- The PR description lists what changed, what was checked, and what needs a live check. Include the one-time setup: activate the plugin; create a Student page containing `[tbt_test]` and a Teacher page containing `[tbt_test_maker]`; choose both in the settings; run the first deploy with `allow_create`.

---

## Acceptance checklist

Mariusz confirms these on the live site.

**Setup**

- [ ] The plugin activates with no errors and appears under TBT Hub.
- [ ] The three tables exist.
- [ ] Deploy: a merge to `main` uploads the plugin; a Markdown-only commit does not start the workflow.

**Teacher**

- [ ] The library shows the Admin Bar, lined up with the other tools.
- [ ] Creating a test, saving it as a draft, and reopening it keeps everything.
- [ ] Publishing with an unfinished question is refused and names the question.
- [ ] Import: rows pasted from Excel arrive as questions; a dialogue with line breaks in the question cell keeps its lines; a semicolon CSV from Polish Excel works; a bad row is named and left out.
- [ ] Search finds a test by a word that appears only inside one of its questions.
- [ ] Leaving the builder with unsaved changes asks first.
- [ ] Delete asks on the card and removes the test.
- [ ] A second teacher account sees only its own tests.

**Student**

- [ ] A logged-out visitor is asked to log in.
- [ ] `[tbt_test test="…"]` embedded in a lesson page works.
- [ ] View the page source during a test: the correct answers are not in it.
- [ ] Tiles: 10 and 20 questions show one row; 100 questions show 20 at a time with arrows; a phone shows 10.
- [ ] Answer three questions, close the browser, reopen the test: `CONTINUE TEST`, with the three answers in place.
- [ ] Answer three questions, then open the test on another device as the same student: the same three answers are there.
- [ ] Turn off Wi-Fi mid-test and keep answering: the status turns red. Turn it back on: it returns to Saved without a reload.
- [ ] Turn off Wi-Fi and press `FINISH TEST`: the red notice appears, nothing is lost, and `Try again` works once the connection is back.
- [ ] Leave the test open for a day, then answer and finish: it saves.
- [ ] Feedback `After each question`: `Check answer` marks and locks each question.
- [ ] Bulls appear on the first, middle, near-last and last questions, and can be switched off for a test.
- [ ] The result shows the score and every answer. With a pass mark it shows Passed or Not passed yet and the right bull; without one, neither.
- [ ] Edit a published test after a student has finished it: that student's result page is unchanged.
- [ ] The general grammar diagnostic test, rebuilt here with 100 questions, can be taken from start to finish.
