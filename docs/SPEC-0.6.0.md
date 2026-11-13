# TBT Test Maker 0.6.0 — Feedback phrases

In a test marked **after each question**, the bare "Correct." and "Not quite." become a rotating line, sometimes with the student's first name and sometimes funny. Swipe's ten celebration phrases are the base.

Student page only. No REST change, no schema change. `TBTTM_DB_VERSION` stays `1.0`. Tests marked at the end and the result screen do not change.

## Files

| File | Change |
|---|---|
| `tbt-test-maker.php` | Header `Version` and `TBTTM_VERSION` → `0.6.0` |
| `includes/class-tbttm-player.php` | Three phrase lists and the first name in `TBTTM_PLAYER` |
| `assets/js/player.js` | Picking a phrase; the multiple choice and gap-fill notices |

---

## Phase 0 — read-only inventory (stop and report)

1. Every place the student page writes "Correct." or "Not quite." in a notice (expected: `gapNotice()` ~L1293 and the multiple choice notice ~L1386). Confirm both appear only when `T.feedback === 'now'` and the question is checked.
2. How often the student page re-renders while a checked question is on screen (typing elsewhere, tiles, arrows, the autosave). A phrase must not change on a re-render.
3. What `writeLocal()` saves, so the new phrase memory stays out of the saved state.
4. Where the guest's name lives (`s.name`, `guest.name`) and confirm a logged-in student's name is not sent to the page today.

**Hard stop** if anything contradicts this spec.

---

## Task 1 — The phrases (class-tbttm-player.php)

Add to the `TBTTM_PLAYER` array:

- `firstName`: the logged-in user's `first_name` meta, trimmed; `''` for guests and for accounts without one (as Swipe does).
- `phrases`: the three lists below, passed through `apply_filters( 'tbttm_feedback_phrases', $phrases )` so the wording can change later without touching JavaScript. Every string through `__( …, 'tbt-test-maker' )`.

Write each phrase exactly as below, including the emoji.

### `right` — 22 plain phrases, no final punctuation (the template adds it)

Well done · Nice work · You rock · Way to go · Brilliant · Keep it up · Outstanding · Superb · That's the way · You did it · Good job · Spot on · Nailed it · Exactly right · Perfect · Excellent · Great stuff · Fantastic · Top marks · You're on fire · Bang on · Impressive

### `fun` — 12 funny phrases, complete as written

- Scientists should study your big, beautiful brain 🙂
- Shakespeare would be jealous 🙂
- Careful, you're making this look easy 🙂
- Someone has been doing their homework 🙂
- Give that brain a medal 🏅
- Your teacher is smiling right now 🙂
- Are you sure you're not a native speaker? 🙂
- The dictionary wants to hire you 🙂
- Frame that answer and hang it on the wall 🖼️
- Too easy for you, wasn't it? 😎
- Grammar ninja at work 🥷
- Your brain deserves a coffee break ☕

### `wrong` — 16 phrases, complete as written

- Not quite.
- So close.
- Nice try.
- Almost.
- Not this time.
- Don't worry, the next one is yours.
- Mistakes are how we learn.
- Good try.
- Close, but not quite.
- Keep going.
- Now you know.
- That one was tricky.
- Every expert was once a beginner.
- Even Shakespeare had bad days 🙂
- Your brain is just warming up 🙂
- Shake it off 🙂

---

## Task 2 — Picking a phrase (player.js)

1. **Shuffle bag.** Each list is shuffled when the page loads and dealt in order. A list is reshuffled only when it runs out, and never so that its first new phrase repeats the last one dealt. No phrase repeats until its list is used up.
2. **Right answer:** about one time in four (`Math.random() < 0.25`) a `fun` phrase. Otherwise a `right` phrase finished as:
   - about one time in three, and only when there is a first name: `"{phrase}, {Name}!"`;
   - otherwise `"{phrase}!"`.
3. **Wrong answer:** a `wrong` phrase, as written. No name and no extra punctuation.
4. **The name:** `TBTTM_PLAYER.firstName` for a logged-in student. For a guest, the first word of the name they typed (`cleanName()`, split on the first space). Escaped like any other text.
5. **Once per question.** Pick the phrase when the check result arrives (in `check()`'s success callback) and keep it in an in-memory map `said[ questionId ]`. Every re-render reads from the map. The map is never saved (`writeLocal()` and the server do not see it). A reloaded page picks a new phrase for an already-checked question; that is fine.
6. **Lists missing or empty** (a filter returned nothing): fall back to today's "Correct." and "Not quite." Nothing else breaks.

---

## Task 3 — The notices (player.js)

The green and red notice boxes, their roles and their colours stay as they are. Only the words change. Screen readers still hear the verdict first: each notice starts with a visually hidden `Correct. ` or `Incorrect. ` (`tbttm-sr-only`).

| Case | Now | 0.6.0 |
|---|---|---|
| Multiple choice, right | **Correct.** | **Way to go, Kasia!** |
| Multiple choice, wrong | **Not quite.** The correct answer is B. | **So close.** The correct answer is B. |
| Gap-fill, every gap right | **Correct.** | **Nailed it!** |
| Gap-fill, one gap, wrong | **Not quite.** Correct: *went* | **Nice try.** Correct: *went* |
| Gap-fill, several gaps, not all right | **1 of 2 points.** Correct: *figure, out* | **1 of 2 points.** So close. Correct: *figure, out* |

In the last row the points stay in bold, as now; the phrase follows in normal weight.

The "✓ Correct" and "✗ Your answer" tags on the options do not change.

---

## Validation

Run the standard checks from `CLAUDE.md`. All must pass unchanged.

## Acceptance checklist (live site, after merge)

- [ ] In a multiple choice test set to "After each question", a right answer shows a phrase, not "Correct."; a wrong answer shows a gentle phrase and still names the right letter.
- [ ] Going through 20 questions, no phrase repeats until its list has been used up; a funny one turns up every few right answers.
- [ ] A logged-in student with a first name sometimes sees it ("Way to go, Kasia!"); a guest sometimes sees the first word of the name they typed.
- [ ] Moving back to a checked question shows the same phrase as before.
- [ ] Gap-fill: all right shows a phrase; a two-gap sentence half right shows "1 of 2 points." then a phrase, then the right words.
- [ ] Emoji show on Windows, Mac, Android and iPhone.
- [ ] A test marked "At the end" looks exactly as before, and so does every result screen.
- [ ] Hard refresh shows 0.6.0 assets (`?ver=0.6.0`).
