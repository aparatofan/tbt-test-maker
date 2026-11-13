# TBT Test Maker 0.5.0 — Fill in the gaps

Spec for Claude Code. Written 13 November 2026. Builds on 0.4.0, which is live.

## What this release is

A second question type. The teacher writes a sentence, a dialogue or a whole paragraph and puts each answer in square brackets. The student types the missing words into the text.

```
I couldn't agree [more].
As [far] as I'm [concerned], the film was far too long.
Really? I [don't|do not] think so.
```

Three decisions are already made:

1. **Every gap is one point.** A sentence with two gaps is worth two points. A multiple choice question is still worth one. `score` and `total` on an attempt count points from now on.
2. **A tile is still one question.** It turns blue when every gap in the question has something typed.
3. **One paragraph is one question** with many gaps. A test with a single question shows no tiles and no question number.

The two rules still come first: a student's answers are never lost, and the answer key never reaches the browser before the attempt is finished. A gap-fill body holds the answers, so **the stored body of a gap question is never sent to the student page**.

## Out of scope for 0.5.0

Importing the QSM quizzes, leaderboard, AI question generation, exporting results to CSV, gaps chosen from a list or a word bank, hints inside a gap, counting two gaps as one point.

No schema change: `questions.type` and `questions.data` already exist, and `attempts.answers` is JSON. `TBTTM_DB_VERSION` stays `1.0`.

## Reference material

- `docs/prototypes/student-gapfill.html` — the approved student screen. Use the "Example" switch for sentences and for one paragraph, and the "Feedback" switch for both modes.
- `docs/prototypes/teacher-gapfill.html` — the approved Questions part of the builder: the type switch, `Make a gap`, the answer key, the points count, and gap rows in the importer.

Where this spec and a prototype disagree, the spec wins. Where the spec is silent, the prototype decides. The teacher prototype's importer is cut down to show gap rows; the real importer keeps its three steps, its template and its CSV button.

---

## Phase 0 — Inventory (read-only, then stop and report)

Make no edits. Report on each point:

1. **This repo.** Confirm `main` is at 0.4.0 and the checks in `CLAUDE.md` pass.
2. **Every place that assumes a question is multiple choice.** List each one with file and function: reading and writing `data`, `public_questions()`, `answers()`, `merge()`, `state()`, `score()`, the check route, the result payload, `TBTTM_Results::hardest()`, the validator, `duplicate`, and the same in `player.js`, `teacher.js` and `parse.js`.
3. **Every place that shows or uses a count.** The start screen facts, "Question x of N · y left", the unanswered warning, the result headline, the result email, the certificate, and the Results screen's stats. Say for each whether it reads questions or `score`/`total`.
4. **Old data.** Confirm that questions and review entries stored before 0.5.0 carry no `type`, and that reading them as `choice` needs no migration.
5. **Autosave.** Report how an answer is queued, sent and retried today, and what changes when an answer is typed text that changes on every key press.
6. **The question body.** Report how `formatBody()` builds a body (speaker names in bold, line breaks), and where a text field can be placed inside it without breaking either.

**Stop and report** without proceeding if any stored column would have to be renamed or changed, or if a gap body cannot be kept out of the student page source. Otherwise end with the report and wait for Mariusz to say go.

---

## Task 1 — What a gap question is

**In the teacher's text.** A gap is a pair of square brackets with the answer inside. `|` separates answers that are all accepted; the first one is the one shown as "the correct answer". Brackets do not nest. Square brackets in a multiple choice question stay ordinary text: the question's type decides, never its content.

**Stored**

| Column | Value |
| --- | --- |
| `type` | `gap` |
| `body` | the text exactly as the teacher wrote it, brackets and all |
| `data` | `{"gaps":[["far"],["concerned"]]}` — one list of accepted answers per gap, in order, read from `body` on every save |

`get_questions()` returns `type` for every question (`choice` when the stored value is empty or unknown) and `gaps` for a gap question.

**One class owns the rules.** Add `TBTTM_Gaps`, using only plain PHP so it runs in a standalone test:

- `parse( $body )` — the list of gaps, each a list of accepted answers, trimmed, empty ones dropped.
- `public_body( $body )` — the body with every `[…]` replaced by `[]`. This is the only form of a gap body the student page may receive.
- `normalise( $text )` — lower case (multibyte), the apostrophes `’ ‘ ` ´` turned into `'`, runs of white space turned into one space, trimmed.
- `right( $given, $accepted )` — true when the normalised answer equals one of the normalised accepted answers. An empty answer is never right.
- `filled( $body, $words )` — the body with each gap replaced by the matching word, for the Results screen and the email.

**Limits.** A gap body up to 6000 characters (a multiple choice body stays at 2000). Up to 60 gaps in a question, 6 accepted answers in a gap, 100 characters in an answer. A typed answer is cut to 100 characters.

**Validator.** `sanitise_question()` reads `type` (`gap`, otherwise `choice`). For a gap question it keeps `body`, drops `options` and `correct`, and sets `gaps` from `TBTTM_Gaps::parse()`. `problem()` for a gap question, in this order: `no text`, `no gap yet`, `a gap is empty`, `a bracket is not closed`, `too many gaps`. A draft still saves with any of these.

Add `tests/test-gaps.php` and extend `tests/test-validator.php`: one and several gaps, accepted alternatives, an empty gap, an unclosed bracket, a bracket in a multiple choice question, Polish letters, curly and straight apostrophes, capitals, extra spaces, `public_body()` leaking nothing.

## Task 2 — The attempt

**Public questions.** A gap question goes to the student page as `{ id, type: 'gap', body: public_body, gaps: 2 }`. No `options`, no accepted answers, no answer lengths.

**Answers.** `answers` stays one JSON map, question ID → answer. A multiple choice answer is the option index, as now. A gap answer is a list of strings, one per gap, `""` for an empty gap. `answers()` and `merge()` accept both: a gap answer counts only when its question is a gap question in this test and is not checked yet; the list is padded or cut to the number of gaps and every string is cleaned as one line of text and cut to 100 characters.

**Scoring.** `score()` counts points: one for a right multiple choice answer, one for each right gap. `total` is the sum of points in the test. `percent` is worked out from the two, as now. A review entry for a gap question:

```
{ "id": 12, "type": "gap", "body": "As [far] as I'm [concerned], …",
  "gaps": [["far"],["concerned"]], "given": ["far","concern"], "right": [true,false] }
```

A review entry without `type` is a multiple choice entry. Nothing stored before 0.5.0 is rewritten.

**After each question.** `POST /attempts/{id}/check` on a gap question stores the answer, locks the question, and answers with `{ right: [true,false], correct: ["far","concerned"] }` — the first accepted answer of each gap. `state()` returns the same for every checked gap question, so a reload shows what was already revealed. Nothing about an unchecked question is ever returned.

Extend `tests/test-finish.php`: a gap-only test, a mixed test, a half-right question, an empty gap, a checked question that a later save tries to change, and a finish with pre-0.5.0 shaped answers.

## Task 3 — The student page

Follow `student-gapfill.html`.

- **The gap.** A text field inside the sentence, in the body's own font, at least 9 characters wide and growing with what is typed. The width never depends on the answer. `autocomplete`, `autocapitalize`, `autocorrect` and `spellcheck` are off. Speaker names stay bold and line breaks stay, as in a multiple choice body.
- **Typing never redraws the question.** The tile, the counter and the `Check` button are updated in place, so the cursor and the phone keyboard stay where they are.
- **Saving.** Each change is mirrored in the browser at once and sent with the existing delayed save. A failed save keeps the text and retries, as now. Leaving the question or pressing `FINISH TEST` sends what is waiting.
- **Enter** moves to the next gap; in the last gap it does what the main button does.
- **Done.** A gap question is done when every gap has text. The tile turns the lighter blue then, and loses it again if a gap is emptied.
- **A test with one question** shows no tiles, no question number and no `Back` button. The quiet line reads `3 of 7 gaps filled`, and the instruction sits above the text. A test with more questions keeps "Question 2 of 5 · 3 left".
- **Unanswered warning.** With several questions, as now. With one question: "**2** gaps are still empty." and `Finish anyway`.
- **After each question.** The button reads `Check answers` when the question has more than one gap. After the check the fields are locked, right ones green and wrong ones red. The notice reads "Correct." or "1 of 2 points. Correct: **concerned**", listing the first accepted answer of each wrong gap. The tile is green only when every gap is right.
- **Result page.** The headline is points: `5 / 7`. A gap question shows its text with the student's words put back in: a right word in green; a wrong word crossed out in red, followed by the right one in green; an empty gap as "(empty)" crossed out. A question with more than one gap adds "· 1 of 2 points".
- **Start screen.** The facts count what the student will do: a test with only gap questions says `7 gaps` in place of `N questions`. Other tests are unchanged. The time estimate uses points.

## Task 4 — The builder

Follow `teacher-gapfill.html`.

- **Type.** An open question starts with a switch: `Multiple choice` / `Fill in the gaps`. A new question takes the type of the question before it; the first question of a test is multiple choice. Switching type keeps the text. Answers typed for the other type are kept while the builder is open and dropped on save.
- **Text.** One field. Placeholder: `I couldn't agree [more].` Under it, `Make a gap` and the hint: "Select a word and click, or type the square brackets yourself. Two accepted answers: [don't|do not]. A sentence, a dialogue or a whole paragraph all work."
- **Make a gap** wraps the selected text in brackets, leaving spaces around the selection outside them. It does nothing when nothing is selected or the selection already holds a bracket.
- **Answer key.** Under the text, the text again with each gap as a blue word, accepted answers joined by "or". It follows the typing without redrawing the field. Hint: "The student sees an empty box in place of each blue word. Each gap is one point. Capitals, extra spaces and the kind of apostrophe do not matter."
- **Closed question.** The summary line shows the first line that has a gap, with `_____` for each gap. The tag reads `2 gaps`, or the problem.
- **Count.** The line under Questions reads "5 questions, 7 points. Click a question to open it."
- **Publishing.** The same message as now, with the gap problems from Task 1.

## Task 5 — Import

`parse.js`: a row is a gap row when its first cell holds at least one `[…]` and every other cell is empty. It gives `{ row, type: 'gap', text, gaps, problem }`; the problems are Task 1's, worded as the importer words its own. Every other row is read exactly as today and gets `type: 'choice'`, so a bracket in a multiple choice question changes nothing. Gap rows and multiple choice rows may sit in one paste. A paragraph is one quoted cell with line breaks, which the reader already handles.

**The importer.** Step 1's hint gains: "For fill in the gaps, use only the question column: one sentence per row, with each answer in square brackets." In step 3 a gap row shows its answer key in the Question column and `3 gaps` in the Answers column; Correct stays empty.

Extend `tests/test-parse.js`: a gap row, a gap row with trailing empty cells, a paragraph in one cell, a mixed paste, a row with brackets and answers, an empty gap.

## Task 6 — Results screen, email, certificate

- **Attempt detail.** A gap question shows the same filled-in text as the student's result page, right and wrong marked the same way.
- **Hardest questions.** A gap question's share is right gaps out of all gaps, over every finished attempt. Its line is the first line with a gap, with `_____`; its correct text is that line with the first accepted answers put in.
- **Stats, the result email and the certificate** read `score`, `total` and `percent` and need no change beyond what Phase 0 point 3 finds. The certificate still reads `5 OUT OF 7`.

Extend `tests/test-results.php` for a gap question in `hardest()` and for a mix of old and new review entries.

## Task 7 — Styling

- The gap field, the answer key and the type switch use tokens only, with the `tbttm-` prefix. No new colour values.
- The type switch reuses the existing segmented control.
- `!important` only where the theme forces it, each one listed in the PR. Divi styles text inputs heavily: check the gap field inside the theme, not only in the prototype.

## Task 8 — Finish

- `TBTTM_VERSION` and the plugin header read `0.5.0`. Update the plugin description.
- `CLAUDE.md`: point "The current release" at `docs/SPEC-0.5.0.md`. Add `php tests/test-gaps.php` to Validation. Add under Architecture: "A gap question's stored body holds its answers. The student page only ever receives `TBTTM_Gaps::public_body()`. Scores count points: one per multiple choice question, one per gap."
- `README.md`: one line for 0.5.0.
- Commit this spec and the two prototypes as the first commit on the branch.
- Run the checks. Open a PR. **Do not merge, and do not push to `main`.** The description lists what changed, what was checked, and what needs a live check.

---

## Acceptance checklist

Mariusz confirms these on the live site.

**Builder**

- [ ] A new question switches to `Fill in the gaps`; the next new question starts as gap-fill.
- [ ] Select a word, click `Make a gap`: brackets appear and the answer key shows the word in blue.
- [ ] `[don't|do not]` shows as "don't or do not" in the answer key.
- [ ] A question without a gap cannot be published and is named in the message; it still saves as a draft.
- [ ] The count line shows questions and points.
- [ ] Paste five sentences with brackets into the importer: five gap questions, with the right number of gaps each.
- [ ] Paste one paragraph with ten gaps as one cell: one question, ten gaps.

**Student**

- [ ] A sentence test: typing fills the gap, the tile turns blue, Enter moves on.
- [ ] A one-paragraph test: no tiles, no question number, "3 of 7 gaps filled".
- [ ] `Don't`, `don't`, `don’t` and `do not` are all marked right.
- [ ] Type half the answers, reload the page: every word is still there.
- [ ] Turn off Wi-Fi, type three answers and press `FINISH TEST`: the red notice appears, nothing is lost, and `Try again` works once the connection is back.
- [ ] After each question: right gaps green, wrong gaps red, the right word shown, "1 of 2 points".
- [ ] The result shows points, with wrong words crossed out and the right ones beside them.
- [ ] On a phone: the keyboard stays open while typing, and gaps wrap with the text.
- [ ] View the page source during a gap test: none of the answers are in it.

**Teacher results and extras**

- [ ] The Results screen shows the attempt with the same filled-in text.
- [ ] Hardest questions lists a gap question with its sentence.
- [ ] The result email and the certificate show the score in points.

**Still true**

- [ ] An existing multiple choice test works exactly as before, and its old results open unchanged.
