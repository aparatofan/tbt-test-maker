# TBT Test Maker 0.7.0: Teacher Mode

On the student page a teacher or an administrator can switch from **Learner Mode** to **Teacher Mode**, where the correct answers are already marked. A teacher who runs a lesson with a test no longer has to take the test first. Students never see the switch and never receive the answers.

Approved prototype: `docs/prototypes/teacher-mode.html`. Where this spec is silent, the prototype decides.

No schema change. `TBTTM_DB_VERSION` stays `1.0`. The teacher page, the result screen, scoring, attempts, the certificate and the email do not change.

## Decisions (approved by Mariusz, 17 Nov 2026)

1. Teacher Mode uses the same screen as Learner Mode: one question per screen, the same tiles, Back and Next.
2. The page always opens in **Learner Mode**. A teacher switches to Teacher Mode with a Learner | Teacher switch. The choice is remembered on that device. Because the page opens in Learner Mode, a test shown on a shared screen never shows the answers unexpectedly.
3. Who can use it: anyone for whom `TBTTM_Capabilities::user_can_manage()` is true (administrators, and the roles given Test Maker's manage permission in its settings). No new capability.
4. Teacher Mode saves and sends nothing. There is no attempt, no autosave, no answer check, no finish, no result, no certificate, no email, no Class Progress activity and no presence.
5. The answer key is **not** printed into the page. It is fetched from a manager-only REST route the first time the teacher switches to Teacher Mode on that page. If a cached copy of the page ever reached a student, it still would not contain the answers.

## Files

| File | Change |
|---|---|
| `tbt-test-maker.php` | Header `Version` and `TBTTM_VERSION` → `0.7.0` |
| `includes/class-tbttm-attempts.php` | New `answer_key()` |
| `includes/class-tbttm-rest.php` | New route `GET tests/{id}/key` |
| `includes/class-tbttm-player.php` | `teacher` flag in the page data |
| `assets/js/player.js` | The switch, the Teacher Mode view, fetching the key |
| `assets/css/player.css` | The switch, the frame, how answers are shown |
| `tests/test-answer-key.php` | New standalone test |
| `CLAUDE.md` | Point "Start here" to this spec; amend the answer-key rule (Task 6) |
| `docs/prototypes/teacher-mode.html` | Add the approved prototype (provided) |

---

## Phase 0: read-only inventory (stop and report)

1. **Permission.** Show what `$teacher` in `TBTTM_Rest::register_routes()` checks. Confirm that it is only capability plus nonce, and that the owner checks live inside the handlers. Then list the site roles that hold `tbttm_manage` by default, and tell Mariusz in your report that he must tick the teacher role in Test Maker's settings. Also tell him that this tick gives teachers authoring rights as well.
2. **Answer key data.** Confirm the shape `TBTTM_DB::get_questions()` returns: choice questions as `correct` (int index), and gap questions as `gaps` (one list of accepted answers per gap, in body order, from `TBTTM_Gaps::parse()`).
3. **Page data.** In `TBTTM_Player::render()`, confirm that `$is_guest`, `$is_draft` and the access checks all run before `$data` is built. The new flag goes into `$data`.
4. **Player state.** In `player.js`, list everything that sends or stores something:
   - `call()` (all routes)
   - `scheduleSave` / `saveNow`
   - `writeLocal` / `writeGuest`
   - `postActivity` / `startPresence`
   - `check`
   - `finish`

   For each one, name the trigger (render, input, change, click, timer, `online`, `beforeunload`). Teacher Mode must not reach any of them.
5. **Rendering and keys.** Report how `render()` chooses a screen (`s.view`). Report what the two existing `keydown` listeners do, so the new arrow keys cannot clash with them.
6. **Tokens.** Confirm that `--tbt-selected-bg`, `--tbt-blue`, `--tbt-success` and `--tbt-success-bg` exist in `tbt-tokens.css`. Do not use a colour that isn't a token.

**Hard stop** if anything contradicts this spec. Typical cases: `$teacher` does more than check the capability, or an existing call fires on render or on a timer in a way a separate view cannot avoid.

---

## Task 1: The answer key (class-tbttm-attempts.php)

Add `public static function answer_key( array $questions )` to `TBTTM_Attempts`. It returns an array of entries, one per question in order:

- Choice: `array( 'id' => (int) $q['id'], 'type' => 'choice', 'correct' => (int) $q['correct'] )`
- Gap: `array( 'id' => (int) $q['id'], 'type' => 'gap', 'gaps' => $q['gaps'] )`. `gaps` is a list of lists of strings: the accepted answers per gap, with the first one shown as the main answer.

Skip a choice question whose `correct` is `null`. The validator should not allow one; if one exists anyway, skip it and leave the screen to show no mark for it.

## Task 2: The route (class-tbttm-rest.php)

`GET tbttm/v1/tests/(?P<id>\d+)/key`

- `permission_callback`: `$teacher` (capability plus nonce).
- **Not owner-scoped, on purpose.** A teacher runs lessons with tests that someone else made. This is the only teacher route without owner scoping. It is read-only. Add a code comment saying so.
- Published tests only. A draft or an unknown test returns `404`, with the same message for both, so the response never reveals that a draft exists.
- Response: `{ "test": <id>, "key": answer_key( get_questions( id ) ) }`. Send `nocache_headers()`.
- Write nothing and log nothing. An attempt is never created.

## Task 3: The flag (class-tbttm-player.php)

In `$data`, add:

```php
// Teacher Mode: the switch shows only for test managers. The answer key is
// not in the page; the player fetches it from tests/{id}/key on demand.
'teacher' => ! $is_guest && ! $is_draft && TBTTM_Capabilities::user_can_manage(),
```

Nothing else changes in the page data. In particular, `questions` stays `public_questions()`.

## Task 4: Teacher Mode in the player (player.js)

### State

- `TEACHER = !! data.teacher`.
- `MODE_KEY = 'tbttm:mode'`, read and written through `store()`. The value is `'teacher'` or anything else, which means Learner. It is one key per browser, not per test.
- The page **always** opens in Learner Mode, whatever is saved (decision 2).
- In memory only: `s.mode` (`'learner'` | `'teacher'`), `s.key` (null until fetched, then a map from question id to key entry), `s.keyBusy`, `s.keyError`, `s.ki` (the question shown in Teacher Mode), `s.kpage`, and `s.back` (the learner view to return to).
- **Learner state is never touched by Teacher Mode.** `s.view`, `s.i`, `s.page`, `s.answers`, `s.dirty`, `s.checked`, the attempt and local storage all stay exactly as they were. Switching back returns to the same learner screen with nothing lost.

### The switch

Only when `TEACHER`. It sits at the top of the stage on every learner screen (start, question, result) and in Teacher Mode. It is a segmented pill with two buttons, Learner and Teacher, using `aria-pressed`. In Teacher Mode a small pill beside it reads **"Answers are showing · nothing is saved"**. On the right, a muted line reads "← → to move between questions".

- **To Teacher:** remember the current learner screen and set `s.mode = 'teacher'`. If `s.key` is null, fetch `GET tests/{id}/key` with `call()`, then show the key view. While the fetch runs, show "Loading the answers…". If it fails, show an error notice ("We couldn't load the answers. Try again.") with a Try again button, and leave Learner state untouched. Fetch only once per page load.
- **To Learner:** restore the remembered learner screen.
- **Leaving a learner question screen:** first `flush()` any unsent answer, so autosave behaves exactly as it does when the student leaves the page.
- **Presence:** while in Teacher Mode, call `stopPresence()`. When the teacher switches back to a learner question screen, call `startPresence()` again. Neither call may create an attempt or send activity.

### The key view (`s.mode === 'teacher'`)

**Start screen.** The first time Teacher Mode opens, if the remembered learner screen was the start screen, show:

- Title **"Answer key"**.
- The line "Go through the test with the correct answers already marked. Your students' results are not affected."
- One fact: N questions (or N gaps for a gap-only test, as `sizeText()` already does).
- CTA **SHOW THE ANSWERS**.

From any other learner screen, the switch opens the key view directly at the same question number as the learner's (`s.ki = s.i`), or at question 1 from the result screen.

**Question screen.** The same layout as `questionScreen()`:

- Tiles: the same paging (`per()`), with all tiles neutral. There is no done, good or bad state, only `is-here` on the current tile.
- The count line reads `Question X of N`, with no "left" figure and no save status.
- Question number and body as in Learner Mode. No bull pictures in Teacher Mode.
- **Multiple choice:** options rendered read-only as `div`s, not inputs. Each starts with its letter (a, b, c). The correct one has the existing `is-right` look and the tag **"✓ Correct"**.
- **Gap-fill:** each gap shows the first accepted answer in a green chip (success colours). Any other accepted answers follow in the same chip, lighter, after " / " (e.g. `don't / do not`). This covers both sentence and one-paragraph tests. With one question, keep the existing instruction line above the text.
- **Buttons:** Back (disabled on question 1) and Next question. On the last question, Next becomes **Back to question 1**. There is no Finish button and no Check button. A one-question test has no tiles and no Back, as in Learner Mode.
- **Keys:** ← and → move between questions only in the key view, and only when focus is not in an input, select or textarea. Use a new `keydown` listener that returns early in every other case.
- **Long tests:** 100 questions work with the same twenty-tile row and arrows.

**Nothing leaves the page.** The key view never calls anything listed in Phase 0 point 4 except the one `GET tests/{id}/key`.

## Task 5: Styles (player.css)

Tokens only. No new colour values.

- **Switch:** a pill track on `--tbt-input` with a `--tbt-border` border. The active button is `--tbt-blue` with `--tbt-surface` text. Uppercase, 13px, weight 600, letter-spacing .06em, as in the prototype.
- **Note pill:** `--tbt-selected-bg` background, `--tbt-blue-dark` text, with a small `--tbt-blue` dot.
- **Teacher frame:** the stage gets `is-teacher`, which adds a `--tbt-blue` border and an extra ring `0 0 0 4px var(--tbt-selected-bg)` on top of the existing shadow.
- **Gap chip:** `--tbt-success-bg` background, `--tbt-success` text and border, a 2px bottom border, the content font at weight 600. The other accepted answers are weight 400, opacity .8.
- **Read-only options:** `cursor: default`, no hover change.
- `prefers-reduced-motion`: no transition on the frame.

## Task 6: Tests and docs

- `tests/test-answer-key.php`, standalone like the others. Check that:
  - `answer_key()` keeps question order.
  - Choice gives `correct`.
  - Gap gives one list per gap, including alternatives (`[don't|do not]` gives `["don't","do not"]`).
  - A choice with `correct = null` is skipped.
  - Nothing beyond `id`, `type` and `correct` / `gaps` is returned.
- **CLAUDE.md:**
  - Point "Start here" to `docs/SPEC-0.7.0.md`.
  - Amend the answer-key rule to: "The answer key never reaches a student's browser before the attempt is finished. The one exception is Teacher Mode: a test manager fetches it from `tests/{id}/key`, which is published-only and never printed into the page."
- Add `docs/prototypes/teacher-mode.html` (provided).

---

## Acceptance checklist

- [ ] A student and a guest see no switch. View-source on their page contains no `correct` and no answer text. `GET tests/{id}/key` returns 401 or 403 for them.
- [ ] An administrator, and a user whose role has `tbttm_manage`, see the switch. The page opens in Learner Mode even after Teacher was chosen last time.
- [ ] Switching to Teacher fetches the key once. The second switch on the same page sends no request.
- [ ] Multiple choice: the correct option is green with "✓ Correct", and the options cannot be clicked.
- [ ] Gap-fill sentences and one-paragraph: every gap shows its answer, with alternatives after " / ".
- [ ] Tiles, Back/Next, ← →, "Back to question 1" and the twenty-tile paging on a 100-question test all work.
- [ ] Teacher Mode sends nothing (check the Network tab): no attempt, no PUT, no check, no finish, no activity and no presence ping.
- [ ] Switching back to Learner restores the same screen and answers. An attempt in progress keeps every answer and autosave carries on.
- [ ] A draft: no switch, and the route returns 404.
- [ ] Another teacher (not the owner) can open Teacher Mode on a published test.
- [ ] Results, Class Progress and the certificate show no trace of Teacher Mode use.
- [ ] Validation commands from CLAUDE.md pass, plus `php tests/test-answer-key.php`.
- [ ] Version 0.7.0 in the header and `TBTTM_VERSION`. `TBTTM_DB_VERSION` unchanged.
- [ ] Open a PR and stop. Do not merge.

**Live-site checks for Mariusz:** tick the teacher role in Test Maker settings, then open a test as a teacher and as a student. If the site uses a page cache, check that test pages are not cached; `nocache` is already sent.
