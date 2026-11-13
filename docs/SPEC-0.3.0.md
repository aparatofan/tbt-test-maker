# TBT Test Maker 0.3.0 — The teacher's Results screen

Spec for Claude Code. Written 13 November 2026. Builds on 0.2.0, which is live.

## What this release is

A teacher opens a test's results from the library and sees who took it, how they scored, what each person answered, and which questions were hardest. A teacher can remove an attempt.

The answer-key rule is unchanged: results are for the test's owner only, and no new route gives a student anything.

## Out of scope for 0.3.0

| Later release | Feature |
| --- | --- |
| 0.4 | Certificate PDF and the result email |
| after | Gap-fill questions, leaderboard, AI question generation, importing the QSM quizzes, exporting results to CSV |

No schema change: `TBTTM_DB_VERSION` stays `1.0`. No edits to any other plugin.

## Reference material

- `docs/prototypes/teacher.html` — the Results screen: `results()` and `detail()`, and their styles (`.rhead`, `.stats`, `.rtools`, `.rt`, `.meter`, `.hard`, `.alist`). Open it in a browser and click Results on a test.

Where this spec and the prototype disagree, the spec wins. Where the spec is silent, the prototype decides: layout, wording, states, spacing.

---

## Phase 0 — Inventory (read-only, then stop and report)

Make no edits. Report on each point:

1. **This repo.** Confirm `main` is at 0.2.0 and the four checks in `CLAUDE.md` pass.
2. **The snapshot.** Report the exact shape of one `review` entry as `TBTTM_Attempts::finish()` writes it today, and whether it carries the question ID.
3. **The library row.** Report the row's current actions and their order, in both the server-rendered row and `rowHtml` in `teacher.js`, and how the row shows the finished-attempt count.
4. **Views.** Report how the teacher page switches between the library and the builder (`?tbttm_new`, `?tbttm_edit`), so the Results view is added the same way.
5. **Colours.** List every colour the prototype's Results styles use and the token each maps to. Report any with no token.

**Stop and report** without proceeding if a Results colour has no token and cannot be built from tokens with `color-mix()`, or if anything in the code contradicts this spec in a way that changes a task. Otherwise end with the report and wait for Mariusz to say go.

---

## Task 1 — Data

**Which attempts count.** Only finished attempts. An attempt in progress, or abandoned, appears nowhere on this screen and in no number.

**Who an attempt belongs to.**

- `user_id > 0`: the user's display name. A deleted user reads "Deleted user".
- `user_id = 0`: `guest_name`, marked "no login" as in the prototype.

A *person* is one user ID, or for guests one name (compared case- and accent-insensitive, trimmed).

**Time.** `finished_at − started_at`, shown as `12 min 40 s`. Over three hours shows "over 3 h": the student left and came back.

**Pass or not.** From the test's current pass mark, as on the student's result page. With no pass mark there is no Result column and no "passed" stat.

**The snapshot gains the question ID.** From 0.3.0, `finish()` writes `id` into every `review` entry (the question's ID). Older attempts have no `id`; that is expected and must keep working. The student result payload must not change shape apart from this field.

**Hardest questions.** For each question in the test as it is now:

- take every finished attempt whose snapshot holds that question: matched by `id`, or for an entry with no `id` by an identical `body`
- percentage correct = attempts where `chosen === correct` ÷ attempts that hold the question. No answer counts as wrong.

List the three lowest, lowest first; ties in question order. A question held by no attempt is left out. With fewer than three attempts in total the section is not shown.

Add to `TBTTM_DB`: `get_finished_attempts( $test_id, $limit )`, newest first. All queries through `$wpdb->prepare()`.

## Task 2 — REST

Three teacher routes. Each requires `TBTTM_CAP` and re-checks inside the callback that the current user owns the test, administrators included.

| Route | Does |
| --- | --- |
| `GET /tests/{id}/results` | The summary and the list. `test`: title, question count, class title, access, pass mark. `stats`: attempts, people, average percent, best percent, passed (when a pass mark is set). `attempts`: the newest 500 finished attempts, each with `id`, `name`, `guest`, `finished_at`, `score`, `total`, `percent`, `seconds`, `passed`. `hardest`: up to three of `{number, line, percent, correct_text}`. `truncated`: true when there are more than 500. No `review` here. |
| `GET /tests/{id}/attempts/{attempt}` | One finished attempt's `review`, from its snapshot. 404 when the attempt is not finished or belongs to another test. |
| `DELETE /tests/{id}/attempts/{attempt}` | Removes the attempt, finished or not, when it belongs to this test. Returns the fresh `stats` and `hardest`. |

Stats cover every finished attempt, not only the 500 listed.

The attempt's token is never returned by any of these.

## Task 3 — Getting there

- The library row gains a `Results` action, after `Edit`, on every test that has at least one finished attempt. The row's attempt count becomes a link to the same place.
- The view lives at `?tbttm_results={id}` on the teacher page, switched the way the builder is. A test the user does not own, or an unknown ID, shows "This test is not available." with `Back to tests`.
- `Back to tests` returns to the library with the search and class filter that were set.

## Task 4 — The Results screen

Follow the prototype. In order:

1. **Head.** The test title and the summary line, the same line as the library row: `14 questions · B2 Tuesday group · Logged-in students · Pass mark 70%`.
2. **Stats.** Attempts "by N people"; average score; best score; "N of M passed" when a pass mark is set.
3. **Attempts**, a stage card.
   - Tools: `Search students…`, the `Best attempt per student` checkbox, and "12 of 37 shown" on the right. Both work in the browser on the loaded list, case- and accent-insensitive. Best = highest score per person; a tie keeps the newest.
   - Table: Student, Finished, Score (`17 / 20 · 85%` with the meter), Time, Result (only with a pass mark), and the `Answers` button. Newest first.
   - `Answers` opens the attempt under its row; one attempt is open at a time. The answers are fetched on first open and kept. While loading, the row reads "Loading answers…"; on failure, "Couldn't load these answers." with `Try again`.
   - The open attempt shows "3 wrong answers" (or "Every answer correct"), the `Show all answers` / `Show wrong answers only` switch, and the list: a tick or cross, the question number and its last line, then the answer given and the correct one. An unanswered question reads "no answer".
   - `Remove attempt` asks on the row itself: "Remove this attempt?" with `Remove attempt` and `Cancel`. No browser `confirm()`. After removal the row goes and the stats and hardest questions update without a reload.
   - When `truncated` is true, one quiet line under the table: "Showing the newest 500 attempts."
   - No match: "No students match your search."
4. **Hardest questions**, a stage card: "The three questions students got wrong most often.", each with its percentage, number, last line, and the correct answer.
5. **Nobody yet.** "Nobody has taken this test yet. Results appear here as soon as a student finishes." This also shows after the last attempt is removed.

**Question text.** A question's "last line" is the last non-empty line of its `body`, escaped. Names and answers are always escaped; a guest typed that name.

**Platform.** The screen is for a desktop. At 640px and below the table scrolls sideways inside its card; nothing else is required.

## Task 5 — Styling

- Tokens only; no stylesheet defines a colour of its own. Every class carries the `tbttm-` prefix.
- Stage cards, controls and status labels reuse what the builder and library already have. The Passed / Not passed labels use the library's Published / Draft label styles, as the prototype does.
- The table is new: build it once, in `teacher.css`.
- `!important` only where the theme forces it, each one listed in the PR.

## Task 6 — Finish

- `TBTTM_VERSION` and the plugin header read `0.3.0`.
- `CLAUDE.md`: point "The current release" at `docs/SPEC-0.3.0.md`. Add under Architecture: "Results are owner-only and always read from the stored snapshot. Review entries carry the question `id` from 0.3.0; older ones do not."
- `README.md`: one line for 0.3.0.
- Commit this spec as the first commit on the branch.
- Run the checks in `CLAUDE.md`. Add a standalone test for the hardest-questions calculation: entries with and without `id`, an edited question, unanswered questions, ties, fewer than three attempts.
- Open a PR. **Do not merge, and do not push to `main`.** The description lists what changed, what was checked, and what needs a live check.

---

## Acceptance checklist

Mariusz confirms these on the live site.

- [ ] A test with finished attempts shows `Results` on its row; a test nobody has taken does not.
- [ ] The stats match the table: attempts, people, average, best.
- [ ] A test with a pass mark shows the Result column and "N of M passed"; a test without one shows neither.
- [ ] A guest's attempt is marked "no login".
- [ ] An attempt left unfinished does not appear.
- [ ] Search narrows the list by name; `Best attempt per student` leaves one row per person.
- [ ] `Answers` shows the wrong answers, and `Show all answers` shows every question.
- [ ] Edit a question in a test students have already taken: their answers on this screen are unchanged.
- [ ] `Remove attempt` asks first, removes the row, and the stats update.
- [ ] Hardest questions lists three questions, and the percentages look right against the attempts.
- [ ] A second teacher account cannot open another teacher's results by changing the ID in the URL.
- [ ] `Back to tests` returns to the library with the filter still set.
