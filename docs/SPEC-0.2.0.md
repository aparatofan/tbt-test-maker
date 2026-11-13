# TBT Test Maker 0.2.0 — Classes and access

Spec for Claude Code. Written 13 November 2026. Builds on 0.1.6, which is live.

## What this release is

Three things, all of which already have their database columns:

1. **Class and lesson.** A teacher attaches a test to one of their TBT Notes classes, and optionally to a lesson. The test then shows in that class in Notes, like a Swipe deck or a drill.
2. **Open access.** A test is either for logged-in students (the default, and the only mode until now) or for anyone with the link. A visitor without a login types their name before they start.
3. **Class Progress.** A logged-in student who finishes a test is reported to TBT Notes with their score.

The two rules from 0.1.0 still come before everything else, and now cover guests too:

1. **A student's answers are never lost.**
2. **The answer key never reaches the browser before the attempt is finished.**

## Out of scope for 0.2.0

| Later release | Feature |
| --- | --- |
| 0.3 | The teacher's Results screen |
| 0.4 | Certificate PDF and the result email (`guest_email` stays unused) |
| after | Gap-fill questions, leaderboard, AI question generation, importing the QSM quizzes |

No schema change: `TBTTM_DB_VERSION` stays `1.0`. No edits to TBT Notes or any other plugin from this repo.

## Reference material

- `docs/prototypes/teacher.html` — the Class and Who can take it fields in Settings, and the library summary line. The Results screen, Certificate and Email the result stay out.
- `docs/prototypes/student.html` — the Your name field on the start screen.
- `translation-drill` (public) — the model for everything Notes-related:
  - `includes/class-tbtd-classes.php` — the read-only bridge to `TBT_Notes_DB`, including `validate_attachment()`
  - `includes/class-tbtd-notes-bridge.php` — the `tbt_notes_class_extras` filter
  - `includes/class-tbtd-player.php` and `assets/js/player.js` — presence and completion reporting
  - `assets/js/teacher.js` — the class picker in the builder and the class filter in the library
- `tbt_notes` (public, 1.21.1 at the time of writing) — `includes/class-tbt-notes-activity-rest.php` for the `/activity` contract.

Where this spec and a prototype disagree, the spec wins. Where the spec is silent, the prototype decides.

---

## Phase 0 — Inventory (read-only, then stop and report)

Make no edits. Clone `translation-drill` and `tbt_notes` read-only into a scratch folder outside this repo. Report on each point:

1. **This repo.** Confirm `main` is at 0.1.6 and the four checks in `CLAUDE.md` pass.
2. **Notes bridge.** Confirm `TBT_Notes_DB` still exposes `get_classes_for_teacher`, `get_lessons_for_class`, `get_class`, `get_lesson`, with the fields Drill reads (`id`, `title`, `teacher_id`; lesson `id`, `header`, `class_id`).
3. **Class extras.** Report the `tbt_notes_class_extras` contract as Notes consumes it today: group keys, item fields, how `icon` is handled and whether Notes recolours a `currentColor` icon.
4. **Activity.** Report the `/activity` and `/activity/presence` contracts: accepted fields, how the class is resolved, the duplicate guard. Then report how the Class Progress panel names and draws a tool, and whether a new tool slug `test` shows correctly with no change to Notes.
5. **Drill's class UI.** Report how Drill's builder picks a class and lesson (markup, how the lesson list is loaded) and how its library filters by class, so this plugin does the same.
6. **Guests and nonces.** Report how `TBTTM_REST` and `player.js` send the nonce today, and confirm a request with no `X-WP-Nonce` header reaches a route as a logged-out user.

**Stop and report.** Stop without proceeding if:

- a `TBT_Notes_DB` method or field in point 2 is missing or renamed
- the tool slug `test` needs a change in Notes to show in Class Progress
- anything in Notes or Drill contradicts this spec in a way that changes a task

Otherwise end Phase 0 with the report and wait for Mariusz to say go.

---

## Task 1 — The Notes bridge

Add `includes/class-tbttm-classes.php`, a `tbttm`-prefixed copy of `TBTD_Classes`: `available()`, `for_teacher()`, `lessons_for_class()`, `user_owns_class()`, `lesson_belongs_to_class()`, `class_title()`, `lesson_title()`, `validate_attachment()`.

- Notes is optional. With Notes inactive every method returns a safe empty value, the Class field is not rendered, and nothing else changes.
- A test may belong to a class without naming a lesson.
- A stored `class_id` that no longer resolves (class deleted in Notes) is shown as "Not in a class". The column is left as it is.

## Task 2 — Validation and REST

**Validator.** `sanitise()` and `validate()` accept three more fields:

| Field | Rule |
| --- | --- |
| `access` | `login` or `open`; anything else becomes `login` |
| `class_id` | a positive whole number, or empty |
| `lesson_id` | a positive whole number, or empty; cleared when `class_id` is empty |

Ownership of the class is checked in the REST callback with `TBTTM_Classes::validate_attachment()`, not in the validator. A class the teacher does not own returns 403 "You don't have access to that class."; a lesson from another class returns 400. Nothing is written on either error.

Extend `tests/test-validator.php` for the three fields.

**Teacher routes**

| Route | Change |
| --- | --- |
| `GET /tests` | Each test also returns `access`, `class_id`, `class_title`, `lesson_id`. `search` also matches the class title. New optional `class` parameter: a class ID, or `none` for tests in no class. |
| `GET /tests/{id}`, `POST /tests`, `PUT /tests/{id}` | Read and write `access`, `class_id`, `lesson_id`. |
| `POST /tests/{id}/duplicate` | The copy keeps `access` and `class_id`; `lesson_id` is cleared. |
| `GET /classes/{id}/lessons` | New. The lessons of a class the teacher owns: `id`, `title` (Notes' `header`). 403 for anyone else. |

The teacher's own classes are printed into the teacher page by the server, as Drill does; there is no list route.

**Student routes — guests**

Until now every student route required a login. From 0.2.0:

- The permission callback allows a logged-out request only when the test (for `POST /attempts`) or the attempt's test (for the others) has `access = open`, or the attempt is an existing guest attempt (see "Switching access" below). Every route keeps a real `permission_callback`.
- `POST /attempts` from a guest takes `{test, name}`. `name` is required, 2 to 120 characters after `sanitize_text_field()`, stored in `guest_name` with `user_id = 0`. It always creates a new attempt and returns the attempt id, the token, `answers` and `checked`.
- A guest attempt is identified by its token. Guests send it on every later request (`PUT`, `/check`, `/finish`) as the header `X-TBTTM-Token`. `own_attempt()` becomes:
  - `user_id > 0`: the current user must be that user, as now.
  - `user_id = 0`: the header must match the stored token, compared with `hash_equals()`.
- Guests send no `X-WP-Nonce`. The token is not a cookie, so there is nothing for another site to forge.
- `GET /attempts/{token}/result`: for a guest attempt the token in the URL is the credential. For a user's attempt the rule is unchanged.
- A logged-in student on an open test behaves exactly as on a logged-in test: the attempt is theirs, no name is asked.
- **Throttle.** New guest attempts are limited to 60 per test per IP address in any 10 minutes (a transient counter; a whole class may share one school address). Over the limit: 429 "Too many people started this test at once. Wait a minute and try again."
- **Switching access.** When a teacher changes an open test back to logged-in, no new guest attempt can start, but a guest attempt already in progress can still be saved and finished. Rule 1 wins.

Result payload: add `name` — the guest's name, or the user's display name.

## Task 3 — The builder

Settings now has five fields in this order: **Class, Who can take it, Feedback, Pass mark, Bull pictures.** Follow the prototype for both new fields.

- **Class.** A select: `Not in a class`, then the teacher's classes. Once a class is chosen a second select appears: `Any lesson`, then that class's lessons, loaded from `GET /classes/{id}/lessons`. Changing the class clears the lesson. Hint: "The test shows in this class in TBT Notes, like a Swipe deck or a drill." / "Attach the test to a class to show it in TBT Notes." The field is not rendered when Notes is unavailable or the teacher has no classes.
- **Who can take it.** Two choices: `Logged-in students` (default) and `Anyone with the link`. Hint: "Students log in first. Results are saved under their name." / "No login. People type their name before they start."
- **Share stage.** For an open test add one line under the link: "Anyone with this link can take the test."

## Task 4 — The library

- Each row's summary line follows the prototype: `14 questions · B2 Tuesday group · Logged-in students · Pass mark 70%`. A test in no class reads `Not in a class`; an open test reads `Anyone with the link`.
- **Class filter.** Add the Admin Bar dropdown, built the way Drill builds its class filter (Phase 0, point 5): `All classes`, one entry per class that has a test, and `Not in a class`. It is not rendered when the teacher has no test in a class. It works together with search; the summary line and `Clear filters` cover both. The choice is kept in the URL (`?tbttm_class=`).

## Task 5 — The student page

**Access check**, in this order:

1. Unknown slug: "This test is not available."
2. Draft: only its owner sees it, as now.
3. `access = login` and logged out: the login prompt, as now.
4. Otherwise: the test.

A test attached to a class is **not** limited to that class's students. The class decides where the test appears in Notes; the link works for any logged-in student, as with a drill.

**Guest start screen.** A logged-out visitor on an open test sees the start screen with the **Your name** field from the prototype above the `START TEST` button. Hint: "Shown on your result." An empty or one-letter name keeps the button from starting and marks the field in red: "Type your name to start." A logged-in visitor never sees the field.

**Guest state in the browser.** Wrap every storage call in `try/catch`, as now.

- Answers mirror to `localStorage` under `tbttm:{test id}:0`.
- The attempt id, token and name are kept under `tbttm:{test id}:guest` from the moment `POST /attempts` answers. With that entry present the start screen shows "You answered N of M questions last time", `CONTINUE TEST` and `Start again`, and no name field. `CONTINUE TEST` carries on with the stored attempt; if the server no longer knows it, start a new attempt with the stored name and send the stored answers.
- After the server confirms the finish, clear both entries and keep the token under `tbttm:{test id}:last`, so the start screen can show "See your last result".
- A guest resumes only in the same browser. Say nothing about other devices on the guest's start screen.
- Everything else in "Saving answers" from the 0.1.0 spec applies to guests unchanged: debounced saves, the red offline status, retry, the failed-finish notice, `beforeunload`. The expired-nonce step does not apply to guests.

**Result.** Show the name above the score for guests and logged-in students alike.

**The answer key.** Unchanged: `correct` never appears in the page source, for guests either. Check this again in the PR.

## Task 6 — Tests in TBT Notes

Add `includes/class-tbttm-notes-bridge.php`, following `TBTD_Notes_Bridge`:

- Hook `tbt_notes_class_extras` at priority 30, after Swipe (10) and Drill (20).
- Add `TBTTM_DB::published_tests_for_class( $class_id )`: published tests with that `class_id` and their question counts, newest first.
- One group: key `tests`, label `Tests`, action `Rozwiąż test`. Each item: `id`, `lesson_id` (0 for "Any lesson"), `title`, `subtitle` ("14 questions"), `url` (the student link). Skip items when no Student page is configured.
- Icon: `assets/img/test-icon.svg`, a simple single-colour mark drawn with `currentColor` (a numbered tile with a tick). Return `''` if the file cannot be read.
- Both open and logged-in tests are listed.

## Task 7 — Class Progress

Follow Drill's reporting exactly; only the payload differs.

- The endpoint and nonce are localised only when the viewer is logged in and `TBT_Notes_Activity_REST` and `TBT_NOTES_REST_NAMESPACE` exist. The per-test `activity` block (`objectRef` = test id, `objectTitle` = title) is printed only for a logged-in visitor who is **not the owner**, on a **published** test. Guests report nothing.
- **Presence.** `POST /activity/presence` when the student starts or continues, then every 20 seconds while a question screen is showing. Stop on the result screen and do not start again there, whatever the student clicks in the review.
- **Completion.** After the server confirms `/finish`, post once:

  ```
  tool: 'test', object_ref, object_title,
  score, score_max (= total), duration_seconds
  ```

  `score` and `total` come from the server's result, never from the browser's own count. Opening an old result with "See your last result" reports nothing.
- Reporting is a side effect: `keepalive: true`, every failure swallowed, nothing in the test flow waits for it.
- Notes files the completion under the student's own class, whichever class the test is attached to. That is Notes' rule and stays as it is.

## Task 8 — Finish

- `TBTTM_VERSION` and the plugin header read `0.2.0`. Update the plugin description: tests can be open or for logged-in students.
- `CLAUDE.md`: point "The current release" at `docs/SPEC-0.2.0.md`, and add under Architecture: "A guest attempt (`user_id = 0`) is owned by its token, sent in `X-TBTTM-Token`. Guests never get a nonce."
- `README.md`: one line for 0.2.0.
- Run the checks in `CLAUDE.md`.
- Open a PR. **Do not merge, and do not push to `main`.** The description lists what changed, what was checked, every new `!important`, and what needs a live check.

---

## Acceptance checklist

Mariusz confirms these on the live site.

**Teacher**

- [ ] Settings opens with Class and Who can take it first.
- [ ] Choosing a class shows its lessons; changing the class clears the lesson.
- [ ] A test saved with a class and lesson reopens with both in place.
- [ ] The library row names the class and who can take the test.
- [ ] The class filter narrows the library, works together with search, and `Clear filters` resets both.
- [ ] Search finds a test by its class name.
- [ ] With TBT Notes switched off (staging only) the Class field is gone and the builder still saves.

**Notes**

- [ ] A published test attached to a lesson shows on that lesson's row in Notes; attached to "Any lesson" it shows under the lessons.
- [ ] A draft does not show in Notes.
- [ ] A student of the class opens the test from Notes and takes it.

**Open access**

- [ ] A logged-in test still asks a logged-out visitor to log in.
- [ ] An open test in a private window: the name field shows, and the test cannot start without a name.
- [ ] A guest answers three questions, closes the tab and reopens the link: `CONTINUE TEST`, with the three answers in place.
- [ ] A guest turns off Wi-Fi mid-test: red status, then Saved again when it is back. `FINISH TEST` offline shows the red notice and loses nothing.
- [ ] The guest's result shows their name, and "See your last result" works afterwards.
- [ ] View the page source as a guest during a test: the correct answers are not in it.
- [ ] A logged-in student on an open test is not asked for a name.
- [ ] Switch an open test to logged-in while a guest is mid-test: the guest can still finish; a new private window gets the login prompt.

**Class Progress**

- [ ] A student in a class finishes a test: Class Progress shows it with a tick and the score.
- [ ] While the student is on a question they show as present; on the result screen, clicking around the review sends no `/activity/presence`.
- [ ] The teacher taking their own test does not show in Class Progress.
- [ ] A guest finishing an open test sends nothing to Notes.
