# TBT Test Maker 0.5.1 — Settings clean-up

Polish only. No new feature, no REST change, no schema change. `TBTTM_DB_VERSION` stays `1.0`.

## What changes

1. **Class picker like Swipe.** The Class select becomes a text field with a list of the teacher's classes: type two letters and the list narrows. Lesson sits next to it with its own visible label.
2. **Light hints.** The grey description lines under fields ("Attach the test to a class to show it in TBT Notes.") use Roboto Light (300).
3. **Clearer choice buttons.** The two-option controls (Who can take it, Feedback, and the question type) get a blue outline, and the chosen option is solid blue with white text. The switches' Off track gets darker.
4. **Real outlined buttons.** "Import questions" becomes an outlined button like "Add question". Every outlined button on the teacher surface gets a stronger blue outline.

## Files

| File | Change |
|---|---|
| `tbt-test-maker.php` | Header `Version` and `TBTTM_VERSION` → `0.5.1` |
| `assets/js/teacher.js` | Class field markup and logic; Import questions button class |
| `assets/css/teacher.css` | Class pair layout, hint weight, segmented control, switch, outlined buttons |
| `assets/css/fonts.css` | One `@font-face` family for Roboto Light (already on this branch: see Task 2) |

Nothing else changes. `common.css` and `player.css` are not touched: the student page must look exactly as it does now.

---

## Phase 0 — read-only inventory (stop and report)

Do not edit anything. Report:

1. **Builder render.** How `render( focusId )` restores focus (`teacher.js` ~L1339–1430), and confirm the delegated `input` and `change` handlers (~L1694, ~L1792) will not catch the new class text field by accident (it must carry no `data-f`).
2. **Class flow.** Every place that reads or writes `d.class_id`, `d.lesson_id`, `lessons`, `lessonSeq` (`classFieldHtml`, `setClass`, `payload`, the post-save block ~L1526, the load path). Confirm the payload still sends `class_id`/`lesson_id` only when `CLASSES.length`.
3. **Buttons.** List every default `.tbttm-btn` (no `--primary`, `--ghost`, `--danger`, `--cta` modifier) rendered on the teacher surface (library, builder, importer, Results, share panel), and confirm all of them sit inside `.tbttm--teacher`. Confirm no student-page markup carries `.tbttm--teacher`.
4. **Segmented controls.** List every `.tbttm-seg` (expected: Who can take it, Feedback, and the per-question type).
5. **Hints.** Confirm `.tbttm-hint` is styled only in `teacher.css` and not used by `player.js` or the student-page PHP.
6. **Fonts.** Confirm `assets/fonts/roboto-light-latin.woff2`, `roboto-light-latin-ext.woff2` and `LICENSE-Roboto-Light-OFL.txt` are present on this branch, and that `fonts.css` loads on the teacher page (`tbttm-fonts` is a dependency of `tbttm-common`).

**Hard stop** if anything contradicts this spec. Wait for Mariusz's answer before Task 1.

---

## Task 1 — Class picker (teacher.js + teacher.css)

The reference is Swipe's generator (`aparatofan/tbt-swipe`, `includes/class-tbts-frontend.php` L190–225, `assets/js/frontend.js` `classOptionFor()` and the class `change` handler). Copy the behaviour, not the code: Test Maker's builder renders from state.

### Markup (`classFieldHtml()`)

Rendered only when `CLASSES.length`, as today. Replace the field with:

```html
<div class="tbttm-field tbttm-classpair">
  <div class="tbttm-classpair__cols">
    <div class="tbttm-field">
      <label class="tbttm-field__label" for="tbttm-f-class">Class</label>
      <input class="tbttm-input" id="tbttm-f-class" type="text"
             list="tbttm-f-class-list" autocomplete="off" spellcheck="false"
             placeholder="No class — type to filter" value="…">
      <datalist id="tbttm-f-class-list">
        <option value="B2 Tuesday group"></option> … (CLASSES, A–Z as now)
      </datalist>
    </div>
    <!-- only when a class is resolved -->
    <div class="tbttm-field">
      <label class="tbttm-field__label" for="tbttm-f-lesson">Lesson</label>
      <select class="tbttm-select" id="tbttm-f-lesson">
        <option value="0">Any lesson</option> … (lessons, newest first, as now)
      </select>
    </div>
  </div>
  <span class="tbttm-hint">…</span>
</div>
```

- The input's `value`: the text the teacher typed while it matches no class; otherwise the title of `d.class_id`, or empty.
- No `data-f` on the input.
- "Lesson" now has a visible label (it was `tbttm-sr-only`). "Any lesson" stays the default: a test may sit in a class without a lesson, unlike a Swipe deck.

### State

Add two variables next to `lessonSeq`:

- `classText` — what is in the field.
- `classMiss` — `true` while the field holds text that matches none of the teacher's classes.

On load and after every save, set `classText` to the saved class's title (or `''`) and `classMiss = false`.

### Behaviour

- **`input` on `#tbttm-f-class`:** store `classText` only. No render, no `touch()`: the list narrows natively while typing.
- **`change` on `#tbttm-f-class`** (fires when a suggestion is picked and when the field is left):
  - Trim the text. Match it against `CLASSES` titles, exact, case-insensitive; first match wins (as Swipe).
  - **Empty:** `classMiss = false`; `setClass( 0 )`.
  - **Match:** `classMiss = false`; `classText` = the class's own title (so "b2 tuesday group" becomes "B2 Tuesday group"). If it is the class already chosen, only re-render; otherwise `setClass( id )`.
  - **No match:** `classMiss = true`; `d.class_id` and `d.lesson_id` are left as they are; render with focus kept on the field.
- **Hint line** (one line under both columns):
  - `classMiss`: `<strong>No class matches that. Pick one from the list, or clear the field to save without a class.</strong>`
  - else the two existing hints, unchanged in wording.
- **Lesson column:** shown only when `d.class_id` and not `classMiss`.
- **Saving while `classMiss`:** `commit()` does not send anything. It shows the error note "Pick a class from the list, or clear the Class field to save without a class." in the save bar and focuses `#tbttm-f-class`. A typo must never detach a test from its class silently.
- `setClass()`, the lessons request, `lessonSeq` and `payload()` keep working exactly as now.

### CSS (teacher.css)

```css
.tbttm .tbttm-classpair { grid-column: 1 / -1; }
.tbttm .tbttm-classpair__cols {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}
.tbttm .tbttm-classpair .tbttm-hint strong {
	color: var(--tbttm-accent);
	font-family: var(--tbt-font-ui);
	font-weight: 600;
}
@media (max-width: 640px) {
	.tbttm .tbttm-classpair__cols { grid-template-columns: 1fr; }
}
```

The class field takes the full width of Settings; the other settings keep their grid below it.

---

## Task 2 — Light hints (fonts.css + teacher.css)

Roboto is not bundled (the UI font comes from the site), so its Light weight may not exist on the page. The two Light files are already committed on this branch from `@fontsource/roboto` 5.3.0 (SIL OFL 1.1, licence alongside). They get a family name of their own, so they can never stand in for regular Roboto anywhere else on the site.

Append to `fonts.css`:

```css
/* Roboto Light 300 only, for the teacher page's hint lines. Its own family
   name, so it never replaces the site's regular Roboto. From @fontsource/roboto
   5.3.0, SIL OFL 1.1 (LICENSE-Roboto-Light-OFL.txt). */
@font-face {
	font-family: 'TBTTM Roboto Light';
	font-style: normal;
	font-weight: 300;
	font-display: swap;
	src: url( ../fonts/roboto-light-latin.woff2 ) format( 'woff2' );
	unicode-range: <latin range, as the Roboto Slab latin rule above>;
}
@font-face {
	font-family: 'TBTTM Roboto Light';
	font-style: normal;
	font-weight: 300;
	font-display: swap;
	src: url( ../fonts/roboto-light-latin-ext.woff2 ) format( 'woff2' );
	unicode-range: <latin-ext range, as the Roboto Slab latin-ext rule above>;
}
```

In `teacher.css`, `.tbttm .tbttm-hint` gets:

```css
font-family: 'TBTTM Roboto Light', var(--tbt-font-ui);
font-weight: 300;
```

Colour (`--tbt-muted`), size (15px) and line height stay. The `strong` inside a hint stays Roboto 600 (Task 1 CSS). Stage subtitles (`.tbttm-stage__sub`) are not changed.

---

## Task 3 — Segmented controls and switches (teacher.css)

Applies to every `.tbttm-seg` (Who can take it, Feedback, question type).

| Part | Now | 0.5.1 |
|---|---|---|
| Group outline | 1px `--tbt-border` | 1.5px `--tbt-blue` |
| Divider between options | none | `label + label`: 1.5px left border `--tbt-blue` |
| Option, not chosen | `--tbt-muted`, 500 | `--tbt-blue-dark`, 500, background `--tbt-surface` |
| Option, hover (not chosen) | — | background `--tbt-selected-bg` |
| Option, chosen | `--tbt-selected-bg` / `--tbt-blue-dark`, 600 | background `--tbt-blue`, colour `--tbttm-white`, 600 |
| Keyboard focus | inset focus ring | not chosen: `inset 0 0 0 2px var(--tbt-blue-dark)`; chosen: `inset 0 0 0 2px var(--tbttm-white)` |

Chosen = solid blue, the same signal the switches already give when On.

Switch, Off track: `--tbt-border` → `--tbttm-faint`. On stays `--tbt-blue`.

---

## Task 4 — Outlined buttons (teacher.js + teacher.css)

1. In `render()`, "Import questions" changes from `tbttm-btn tbttm-btn--ghost` to `tbttm-btn`, so it matches "Add question" next to it. Its `data-act`, disabled rule and position stay.
2. In `teacher.css`, every default button on the teacher surface gets a stronger outline:

```css
.tbttm.tbttm--teacher .tbttm-btn:not(.tbttm-btn--primary, .tbttm-btn--ghost, .tbttm-btn--danger, .tbttm-btn--cta) {
	border: 1.5px solid var(--tbt-blue);
	background: var(--tbt-surface);
	color: var(--tbt-blue-dark);
}
.tbttm.tbttm--teacher .tbttm-btn:not(.tbttm-btn--primary, .tbttm-btn--ghost, .tbttm-btn--danger, .tbttm-btn--cta):hover {
	border-color: var(--tbt-blue-dark);
	background: var(--tbt-selected-bg);
}
```

Use the selector shape Phase 0 confirms (if `.tbttm` and `.tbttm--teacher` are on the same element, as `class-tbttm-teacher.php` suggests, the compound selector above is right). Primary, ghost ("← Back to tests", "Cancel", "Add answer"), danger and the library row buttons (`.tbttm-rowbtn`) do not change. Disabled buttons keep `opacity: .5`.

---

## Task 5 — Version and pointer

- `TBTTM_VERSION` and the plugin header → `0.5.1`.
- `CLAUDE.md` "Start here" already points to this spec on this branch; leave it.

---

## Validation

Run the standard checks from `CLAUDE.md` (php -l, node --check, the PHP and JS tests). All must pass unchanged.

## Acceptance checklist (live site, after merge)

**Class picker**
- [ ] Settings → Class is a text field; clicking it shows the teacher's classes; typing two letters narrows the list.
- [ ] Picking a class shows "Lesson" next to it with "Any lesson" first, then the class's lessons, newest first.
- [ ] Typing a class name in lower case and leaving the field picks the class and corrects the capitals.
- [ ] Typing a name that matches no class shows the bold hint; Save draft / Publish shows the error and does not save.
- [ ] Clearing the field and saving stores the test with no class.
- [ ] A test saved with a class and lesson reopens with both in place.
- [ ] Changing the class clears the lesson.
- [ ] Under 640px Class and Lesson stack.

**Look**
- [ ] Hints under fields are visibly lighter (Roboto Light); Polish characters in a hint render in the same light face.
- [ ] Who can take it / Feedback / question type: blue outline, chosen option solid blue with white text, keyboard focus visible on both states.
- [ ] Switch Off is clearly grey, On is blue.
- [ ] "Import questions" is an outlined button next to "Add question".
- [ ] Choose image, Add question, Import questions, Make a gap, Copy template, Choose a CSV file, Save draft, Results' "Try again": blue outline.
- [ ] "← Back to tests", "Add answer", "Remove image" unchanged.

**Nothing else moved**
- [ ] The student page (start screen, tiles, result screen) looks exactly as before.
- [ ] Library toolbar and row buttons unchanged.
- [ ] Hard refresh shows 0.5.1 assets (`?ver=0.5.1`).
