<?php
/**
 * Standalone tests for TBTTM_Results: the hardest-questions calculation, the
 * "last line" of a question, and the time an attempt took.
 *
 * The rule under test: for each question in the test as it is now, take
 * every finished attempt whose snapshot holds it (by `id`, or for a snapshot
 * entry with no `id` by an identical `body`), and divide the correct answers
 * by those attempts. No answer is a wrong answer. The three lowest come
 * first, ties in question order; a question no attempt holds is left out;
 * fewer than three attempts and there is no list.
 *
 * WordPress is not loaded; these methods use none of it.
 *
 * Run: php tests/test-results.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../includes/class-tbttm-gaps.php';
require __DIR__ . '/../includes/class-tbttm-results.php';

$pass = 0;
$fail = 0;
function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "FAIL: {$label}\n";
	}
}
function same( $actual, $expected, $label ) {
	ok( $actual === $expected, $label . ( $actual === $expected ? '' : "\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) ) );
}

/** A question of the test as it is now. */
function q( $id, $body, $correct = 0, $options = array( 'yes', 'no', 'maybe' ) ) {
	return array(
		'id'      => $id,
		'body'    => $body,
		'options' => $options,
		'correct' => $correct,
	);
}
/** A snapshot entry written from 0.3.0: it carries the question's ID. */
function e( $id, $body, $chosen, $correct = 0 ) {
	return array(
		'id'      => $id,
		'body'    => $body,
		'options' => array( 'yes', 'no', 'maybe' ),
		'chosen'  => $chosen,
		'correct' => $correct,
	);
}
/** A snapshot entry written before 0.3.0: no ID. */
function old( $body, $chosen, $correct = 0 ) {
	$entry = e( 0, $body, $chosen, $correct );
	unset( $entry['id'] );
	return $entry;
}
function numbers( array $hardest ) {
	return array_column( $hardest, 'number' );
}
function percents( array $hardest ) {
	return array_column( $hardest, 'percent' );
}

$test = array( q( 1, 'One' ), q( 2, 'Two' ), q( 3, 'Three' ), q( 4, 'Four' ) );

/* ---------- entries with an id ---------- */

$reviews = array(
	array( e( 1, 'One', 0 ), e( 2, 'Two', 1 ), e( 3, 'Three', 0 ), e( 4, 'Four', 0 ) ),
	array( e( 1, 'One', 0 ), e( 2, 'Two', 1 ), e( 3, 'Three', 1 ), e( 4, 'Four', 0 ) ),
	array( e( 1, 'One', 0 ), e( 2, 'Two', 0 ), e( 3, 'Three', 1 ), e( 4, 'Four', 0 ) ),
	array( e( 1, 'One', 1 ), e( 2, 'Two', 1 ), e( 3, 'Three', 1 ), e( 4, 'Four', 0 ) ),
);
$h = TBTTM_Results::hardest( $test, $reviews );
same( numbers( $h ), array( 2, 3, 1 ), 'with id: the three lowest, lowest first' );
same( percents( $h ), array( 25, 25, 75 ), 'with id: correct ÷ attempts that hold the question' );
same( $h[0], array( 'number' => 2, 'line' => 'Two', 'percent' => 25, 'correct_text' => 'yes' ), 'with id: number, last line, percent, correct answer' );

/* ---------- entries without an id (attempts from before 0.3.0) ---------- */

$reviews = array(
	array( old( 'One', 0 ), old( 'Two', 1 ), old( 'Three', 0 ), old( 'Four', 0 ) ),
	array( old( 'One', 0 ), old( 'Two', 1 ), old( 'Three', 1 ), old( 'Four', 0 ) ),
	array( old( 'One', 0 ), old( 'Two', 0 ), old( 'Three', 1 ), old( 'Four', 1 ) ),
);
$h = TBTTM_Results::hardest( $test, $reviews );
same( numbers( $h ), array( 2, 3, 4 ), 'without id: matched by an identical body' );
same( percents( $h ), array( 33, 33, 67 ), 'without id: the same arithmetic' );

/* ---------- old and new attempts together ---------- */

$reviews = array(
	array( old( 'One', 1 ), old( 'Two', 0 ), old( 'Three', 0 ), old( 'Four', 0 ) ),
	array( e( 1, 'One', 1 ), e( 2, 'Two', 0 ), e( 3, 'Three', 0 ), e( 4, 'Four', 0 ) ),
	array( e( 1, 'One', 0 ), e( 2, 'Two', 1 ), e( 3, 'Three', 0 ), e( 4, 'Four', 0 ) ),
);
$h = TBTTM_Results::hardest( $test, $reviews );
same( array( $h[0]['number'], $h[0]['percent'] ), array( 1, 33 ), 'mixed: old and new entries count towards the same question' );
same( array( $h[1]['number'], $h[1]['percent'] ), array( 2, 67 ), 'mixed: second lowest' );

/* ---------- an edited question ---------- */

// Question 2 was reworded after the first two attempts. An entry with the ID
// still belongs to it; an old entry with the former text belongs to nothing.
$edited  = array( q( 1, 'One' ), q( 2, 'Two, reworded' ), q( 3, 'Three' ) );
$reviews = array(
	array( e( 1, 'One', 0 ), e( 2, 'Two', 1 ), e( 3, 'Three', 0 ) ),
	array( old( 'One', 0 ), old( 'Two', 1 ), old( 'Three', 0 ) ),
	array( e( 1, 'One', 0 ), e( 2, 'Two, reworded', 0 ), e( 3, 'Three', 0 ) ),
);
$h = TBTTM_Results::hardest( $edited, $reviews );
same( array( $h[0]['number'], $h[0]['percent'], $h[0]['line'] ), array( 2, 50, 'Two, reworded' ), 'edited: id entries follow the question; the old-text entry without id is not held' );
same( percents( $h ), array( 50, 100, 100 ), 'edited: the other questions keep all three attempts' );

// The answer key moved from a) to b) after an attempt. The snapshot's own
// verdict stands: that student was right at the time.
$rekeyed = array( q( 1, 'One', 1 ), q( 2, 'Two' ), q( 3, 'Three' ) );
$reviews = array(
	array( e( 1, 'One', 0, 0 ), e( 2, 'Two', 0 ), e( 3, 'Three', 0 ) ),
	array( e( 1, 'One', 0, 1 ), e( 2, 'Two', 0 ), e( 3, 'Three', 0 ) ),
	array( e( 1, 'One', 1, 1 ), e( 2, 'Two', 0 ), e( 3, 'Three', 0 ) ),
);
$h = TBTTM_Results::hardest( $rekeyed, $reviews );
same( array( $h[0]['number'], $h[0]['percent'], $h[0]['correct_text'] ), array( 1, 67, 'no' ), 'edited key: each attempt is judged by its own snapshot; the answer shown is today\'s' );

// A question deleted from the test is in old snapshots and nowhere else.
$shorter = array( q( 1, 'One' ), q( 3, 'Three' ) );
$reviews = array(
	array( e( 1, 'One', 0 ), e( 2, 'Two', 1 ), e( 3, 'Three', 1 ) ),
	array( e( 1, 'One', 0 ), e( 2, 'Two', 1 ), e( 3, 'Three', 1 ) ),
	array( e( 1, 'One', 1 ), e( 2, 'Two', 1 ), e( 3, 'Three', 0 ) ),
);
$h = TBTTM_Results::hardest( $shorter, $reviews );
same( numbers( $h ), array( 2, 1 ), 'deleted question: left out, and the rest are numbered as the test is now' );

// A question added after every attempt is held by none and is left out.
$longer  = array( q( 1, 'One' ), q( 2, 'Two' ), q( 9, 'Brand new' ) );
$reviews = array(
	array( e( 1, 'One', 0 ), e( 2, 'Two', 1 ) ),
	array( e( 1, 'One', 1 ), e( 2, 'Two', 1 ) ),
	array( e( 1, 'One', 1 ), e( 2, 'Two', 0 ) ),
);
$h = TBTTM_Results::hardest( $longer, $reviews );
same( numbers( $h ), array( 1, 2 ), 'new question: held by no attempt, so left out' );

// A question only some attempts hold is rated over those attempts alone.
$reviews[] = array( e( 1, 'One', 0 ), e( 2, 'Two', 0 ), e( 9, 'Brand new', 1 ) );
$h         = TBTTM_Results::hardest( $longer, $reviews );
same( array( $h[0]['number'], $h[0]['percent'] ), array( 3, 0 ), 'partly held: 0 of the 1 attempt that holds it, not 0 of 4' );
same( array( $h[1]['number'], $h[1]['percent'] ), array( 1, 50 ), 'partly held: the others over all four' );

/* ---------- unanswered questions ---------- */

$three   = array( q( 1, 'One' ), q( 2, 'Two' ), q( 3, 'Three' ) );
$reviews = array(
	array( e( 1, 'One', null ), e( 2, 'Two', 0 ), e( 3, 'Three', 0 ) ),
	array( e( 1, 'One', null ), e( 2, 'Two', 0 ), e( 3, 'Three', 0 ) ),
	array( e( 1, 'One', 0 ), old( 'Two', null ), e( 3, 'Three', 0 ) ),
);
$h = TBTTM_Results::hardest( $three, $reviews );
same( percents( $h ), array( 33, 67, 100 ), 'unanswered: no answer counts as wrong, and still holds the question' );
same( numbers( $h ), array( 1, 2, 3 ), 'unanswered: order follows the rates' );

// Option 0 chosen and no answer are different things.
$reviews = array(
	array( e( 1, 'One', 0 ) ),
	array( e( 1, 'One', null ) ),
	array( e( 1, 'One', null ) ),
);
same( percents( TBTTM_Results::hardest( array( q( 1, 'One' ) ), $reviews ) ), array( 33 ), 'unanswered: choosing the first option is an answer, null is not' );

/* ---------- ties ---------- */

$reviews = array(
	array( e( 1, 'One', 1 ), e( 2, 'Two', 1 ), e( 3, 'Three', 1 ), e( 4, 'Four', 1 ) ),
	array( e( 1, 'One', 0 ), e( 2, 'Two', 0 ), e( 3, 'Three', 0 ), e( 4, 'Four', 0 ) ),
	array( e( 1, 'One', 0 ), e( 2, 'Two', 0 ), e( 3, 'Three', 0 ), e( 4, 'Four', 0 ) ),
);
same( numbers( TBTTM_Results::hardest( $test, $reviews ) ), array( 1, 2, 3 ), 'ties: all equal, so question order, and only three' );

$reviews = array(
	array( e( 1, 'One', 0 ), e( 2, 'Two', 1 ), e( 3, 'Three', 0 ), e( 4, 'Four', 1 ) ),
	array( e( 1, 'One', 0 ), e( 2, 'Two', 1 ), e( 3, 'Three', 0 ), e( 4, 'Four', 1 ) ),
	array( e( 1, 'One', 0 ), e( 2, 'Two', 0 ), e( 3, 'Three', 0 ), e( 4, 'Four', 0 ) ),
);
same( numbers( TBTTM_Results::hardest( $test, $reviews ) ), array( 2, 4, 1 ), 'ties: the tied pair in question order, then the next rate in question order' );

/* ---------- fewer than three attempts ---------- */

$one = array( e( 1, 'One', 1 ), e( 2, 'Two', 1 ), e( 3, 'Three', 1 ), e( 4, 'Four', 1 ) );
same( TBTTM_Results::hardest( $test, array() ), array(), 'few attempts: none' );
same( TBTTM_Results::hardest( $test, array( $one ) ), array(), 'few attempts: one' );
same( TBTTM_Results::hardest( $test, array( $one, $one ) ), array(), 'few attempts: two' );
same( count( TBTTM_Results::hardest( $test, array( $one, $one, $one ) ) ), 3, 'few attempts: three is enough' );

// The count is of attempts, not of attempts holding a given question.
$reviews = array( $one, $one, array( e( 77, 'Gone', 0 ) ) );
same( count( TBTTM_Results::hardest( $test, $reviews ) ), 3, 'few attempts: an attempt holding none of today\'s questions still counts as an attempt' );

/* ---------- fewer than three questions, and odd input ---------- */

same( numbers( TBTTM_Results::hardest( array( q( 1, 'One' ), q( 2, 'Two' ) ), array( array( e( 1, 'One', 1 ), e( 2, 'Two', 0 ) ), array( e( 1, 'One', 0 ), e( 2, 'Two', 0 ) ), array( e( 1, 'One', 1 ), e( 2, 'Two', 0 ) ) ) ) ), array( 1, 2 ), 'two questions: both listed' );
same( TBTTM_Results::hardest( array(), array( $one, $one, $one ) ), array(), 'no questions: nothing to list' );
same( count( TBTTM_Results::hardest( $test, array( $one, null, 'junk', array( 'junk', null ), $one ) ) ), 3, 'a snapshot that cannot be read is skipped, not fatal' );

// An attempt cannot hold one question twice.
$twice = array( e( 1, 'One', 1 ), e( 1, 'One', 0 ) );
same( percents( TBTTM_Results::hardest( array( q( 1, 'One' ) ), array( $twice, $twice, $twice ) ) ), array( 0 ), 'a repeated entry counts once, by its first answer' );

// Two questions with the same text, in old snapshots: matched in order.
$twins   = array( q( 1, 'Same' ), q( 2, 'Same' ) );
$reviews = array_fill( 0, 3, array( old( 'Same', 0 ), old( 'Same', 1 ) ) );
same( percents( TBTTM_Results::hardest( $twins, $reviews ) ), array( 0, 100 ), 'same text twice, no ids: first entry to the first question, second to the second' );
same( numbers( TBTTM_Results::hardest( $twins, $reviews ) ), array( 2, 1 ), 'same text twice: and ranked as usual' );

// The reviews may come from a generator, as they do from the database.
$stream = ( static function () use ( $one ) {
	yield $one;
	yield $one;
	yield $one;
} )();
same( count( TBTTM_Results::hardest( $test, $stream ) ), 3, 'reviews can be streamed' );

/* ---------- the last line ---------- */

same( TBTTM_Results::last_line( "Tom: Horrible weather.\nJenny: You can say that again!\n\nCo Jenny ma na myśli?" ), 'Co Jenny ma na myśli?', 'last line: of a dialogue' );
same( TBTTM_Results::last_line( "Which is right?\n\n  \n" ), 'Which is right?', 'last line: trailing blank lines ignored' );
same( TBTTM_Results::last_line( "One\r\nTwo  " ), 'Two', 'last line: Windows line ends, trimmed' );
same( TBTTM_Results::last_line( '' ), '', 'last line: empty text' );
same( TBTTM_Results::last_line( 'A <b>bold</b> & "quoted" line' ), 'A <b>bold</b> & "quoted" line', 'last line: returned as plain text; the screen escapes it' );
$h = TBTTM_Results::hardest( array( q( 1, "Line one\nLine two" ) ), array_fill( 0, 3, array( e( 1, 'x', 0 ) ) ) );
same( $h[0]['line'], 'Line two', 'hardest: shows the last line of the question as it is now' );
$h = TBTTM_Results::hardest( array( q( 1, 'One', null ) ), array_fill( 0, 3, array( e( 1, 'One', 0, null ) ) ) );
same( array( $h[0]['percent'], $h[0]['correct_text'] ), array( 0, '' ), 'hardest: a question with no answer key is never correct and names no answer' );

/* ---------- time ---------- */

same( TBTTM_Results::seconds( '2026-11-13 10:00:00', '2026-11-13 10:12:40' ), 760, 'time: finished − started' );
same( TBTTM_Results::seconds( '2026-11-13 23:59:30', '2026-11-14 00:00:10' ), 40, 'time: across midnight' );
same( TBTTM_Results::seconds( '2026-11-13 10:00:00', '2026-11-13 10:00:00' ), 0, 'time: zero' );
same( TBTTM_Results::seconds( '2026-11-13 10:00:00', '2026-11-13 09:00:00' ), null, 'time: the wrong way round is unknown' );
same( TBTTM_Results::seconds( '2026-11-13 10:00:00', '' ), null, 'time: a missing end is unknown' );

/* ---------- fill in the gaps ---------- */

/** A gap question of the test as it is now. */
function gq( $id, $body ) {
	return array( 'id' => $id, 'type' => 'gap', 'body' => $body, 'options' => array(), 'correct' => null, 'gaps' => TBTTM_Gaps::parse( $body ) );
}
/** A gap question's snapshot entry: the verdict on each gap. */
function ge( $id, $body, array $right ) {
	return array( 'id' => $id, 'type' => 'gap', 'body' => $body, 'gaps' => TBTTM_Gaps::parse( $body ), 'given' => array_fill( 0, count( $right ), 'x' ), 'right' => $right );
}
$far  = "Pete: The film was long.\nAlita: As [far] as I'm [concerned|aware], it was too long.\n\nComplete the phrase.";
$dont = "Really? I [don't|do not] think so.";
$gtest = array( q( 1, 'One' ), gq( 2, $far ), gq( 3, $dont ) );

// Question 2: 3 right gaps of 6. Question 3: 2 of 3. Question 1: 3 of 3.
$greviews = array(
	array( e( 1, 'One', 0 ), ge( 2, $far, array( true, false ) ), ge( 3, $dont, array( true ) ) ),
	array( e( 1, 'One', 0 ), ge( 2, $far, array( true, true ) ), ge( 3, $dont, array( false ) ) ),
	array( e( 1, 'One', 0 ), ge( 2, $far, array( false, false ) ), ge( 3, $dont, array( true ) ) ),
);
$h = TBTTM_Results::hardest( $gtest, $greviews );
same( numbers( $h ), array( 2, 3, 1 ), 'gap: questions ranked by their share of right gaps' );
same( percents( $h ), array( 50, 67, 100 ), 'gap: the share is right gaps out of all gaps, over every attempt' );
same( $h[0]['line'], "Alita: As _____ as I'm _____, it was too long.", 'gap: the line is the first line with a gap, with _____' );
same( $h[0]['correct_text'], "Alita: As far as I'm concerned, it was too long.", 'gap: the correct text is that line with the first accepted answers' );
same( array( $h[1]['line'], $h[1]['correct_text'] ), array( 'Really? I _____ think so.', "Really? I don't think so." ), 'gap: a one-line question' );
same( array( $h[2]['line'], $h[2]['correct_text'] ), array( 'One', 'yes' ), 'gap: a multiple choice question beside them reads as before' );

// The verdict is the snapshot's, even if the accepted answers changed since.
$edited = array( q( 1, 'One' ), gq( 2, 'As [near] as I [know].' ), gq( 3, $dont ) );
same( percents( TBTTM_Results::hardest( $edited, $greviews ) ), array( 50, 67, 100 ), 'gap: editing a question does not re-mark old attempts' );

// Old and new entries together: no id, no type, and typed gap entries.
$mix = array(
	array( old( 'One', 1 ), ge( 2, $far, array( true, true ) ) ),
	array( e( 1, 'One', 0 ), ge( 2, $far, array( false, true ) ), ge( 3, $dont, array( false ) ) ),
	array( old( 'One', 0 ) ),
	array( e( 1, 'One', 1 ), ge( 3, $dont, array( false ) ) ),
);
$h = TBTTM_Results::hardest( $gtest, $mix );
same( numbers( $h ), array( 3, 1, 2 ), 'old and new: entries without id or type count beside gap entries' );
same( percents( $h ), array( 0, 50, 75 ), 'old and new: each question over the attempts that hold it' );

// A question that changed type: entries of the other type are left out.
$was_choice = array( array( e( 2, 'Old text', 0 ) ), array( e( 2, 'Old text', 0 ) ), array( ge( 2, $far, array( true, false ) ) ) );
$h = TBTTM_Results::hardest( array( gq( 2, $far ) ), $was_choice );
same( percents( $h ), array( 50 ), 'type changed: a gap question is rated on its gap entries only' );
$was_gap = array( array( ge( 1, $far, array( true, true ) ) ), array( ge( 1, $far, array( true, true ) ) ), array( e( 1, 'One', 1 ) ) );
same( percents( TBTTM_Results::hardest( array( q( 1, 'One' ) ), $was_gap ) ), array( 0 ), 'type changed: a multiple choice question is rated on its choice entries only' );
same( TBTTM_Results::hardest( array( gq( 2, $far ) ), array( array( e( 2, 'x', 0 ) ), array( e( 2, 'x', 0 ) ), array( e( 2, 'x', 0 ) ) ) ), array(), 'type changed: with no entry of its type a question is left out' );

// A gap entry with broken verdicts counts nothing and breaks nothing.
$broken = array( array( array( 'id' => 2, 'type' => 'gap', 'right' => 'yes' ) ), array( ge( 2, $far, array( true, true ) ) ), array( ge( 2, $far, array( true, true ) ) ) );
same( percents( TBTTM_Results::hardest( array( gq( 2, $far ) ), $broken ) ), array( 100 ), 'gap: an entry without verdicts is ignored' );

echo "{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
