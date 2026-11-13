<?php
/**
 * Standalone tests for TBTTM_Attempts::finish(): an attempt moves to finished
 * exactly once, whoever asks and however often.
 *
 * The write is conditional (WHERE status = 'in_progress'), so when two
 * finish requests arrive together the database lets one of them through.
 *   - The request that wins stores the score and the snapshot and fires
 *     `tbttm_attempt_finished`, once. The result email hangs on that.
 *   - The request that loses writes nothing, fires nothing, and returns the
 *     result the winner stored. Never an error.
 *
 * WordPress is not loaded. The table is an array here, with the same
 * conditional write as TBTTM_DB::finish_attempt().
 *
 * Run: php tests/test-finish.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

/* ---------- WordPress stubs ---------- */

class WP_Error {
	private $code;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code;
		$this->data = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_data() {
		return $this->data;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function __( $text, $domain = null ) {
	return $text;
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}
function get_userdata( $id ) {
	return (object) array( 'display_name' => 'Student ' . $id );
}

/** Every `tbttm_attempt_finished` fired, and an optional listener that fails. */
$GLOBALS['fired']   = array();
$GLOBALS['explode'] = false;
function do_action( $hook, ...$args ) {
	if ( 'tbttm_attempt_finished' === $hook ) {
		$GLOBALS['fired'][] = array( (int) $args[0]->id, (string) $args[0]->status, (int) $args[1]->id );
		if ( $GLOBALS['explode'] ) {
			throw new RuntimeException( 'the mail server is on fire' );
		}
	}
}

/** The certificate is not under test here. */
class TBTTM_Certificate {
	public static function available( $attempt, $test ) {
		return false;
	}
}

/** The attempts table, as an array, with the real conditional write. */
class TBTTM_DB {
	const ATTEMPT_IN_PROGRESS = 'in_progress';
	const ATTEMPT_FINISHED    = 'finished';

	public static $rows   = array();
	public static $writes = 0;
	public static $broken = false;
	/** The test's questions, or null for the two multiple choice ones. */
	public static $questions = null;

	public static function now() {
		return '2026-11-13 10:12:40';
	}
	public static function get_questions( $test_id ) {
		if ( null !== self::$questions ) {
			return self::$questions;
		}
		return array(
			array( 'id' => 1, 'body' => 'One', 'options' => array( 'a', 'b' ), 'correct' => 0 ),
			array( 'id' => 2, 'body' => 'Two', 'options' => array( 'a', 'b' ), 'correct' => 1 ),
		);
	}
	/** A fresh copy, as a query returns: changing it does not change the table. */
	public static function get_attempt( $id ) {
		return isset( self::$rows[ $id ] ) ? clone self::$rows[ $id ] : null;
	}
	public static function finish_attempt( $id, array $fields ) {
		if ( self::$broken ) {
			return false;
		}
		// UPDATE … WHERE id = %d AND status = 'in_progress'
		if ( ! isset( self::$rows[ $id ] ) || self::ATTEMPT_IN_PROGRESS !== self::$rows[ $id ]->status ) {
			return 0;
		}
		foreach ( $fields as $column => $value ) {
			self::$rows[ $id ]->$column = $value;
		}
		++self::$writes;
		return 1;
	}
	public static function update_attempt( $id, array $fields ) {
		throw new LogicException( 'finish() must use the conditional write, not update_attempt()' );
	}
	public static function start( $id, array $answers = array() ) {
		self::$rows[ $id ] = (object) array(
			'id'          => $id,
			'test_id'     => 7,
			'user_id'     => 20,
			'guest_name'  => '',
			'token'       => str_repeat( 'a', 32 ),
			'status'      => self::ATTEMPT_IN_PROGRESS,
			'answers'     => json_encode( (object) $answers ),
			'checked'     => '[]',
			'review'      => null,
			'score'       => null,
			'total'       => null,
			'percent'     => null,
			'finished_at' => null,
		);
		return self::get_attempt( $id );
	}
}

require __DIR__ . '/../includes/class-tbttm-gaps.php';
require __DIR__ . '/../includes/class-tbttm-attempts.php';

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
function reset_all() {
	TBTTM_DB::$rows   = array();
	TBTTM_DB::$writes = 0;
	TBTTM_DB::$broken = false;
	TBTTM_DB::$questions = null;
	$GLOBALS['fired']   = array();
	$GLOBALS['explode'] = false;
}
$test = (object) array( 'id' => 7, 'pass_mark' => 50, 'title' => 'T' );

/* ---------- the request that finishes the attempt ---------- */

reset_all();
$attempt = TBTTM_DB::start( 1, array( 1 => 0 ) );
$result  = TBTTM_Attempts::finish( $attempt, $test, array( 2 => 1 ) );
ok( ! is_wp_error( $result ), 'winner: no error' );
same( array( $result['score'], $result['total'], $result['percent'], $result['passed'] ), array( 2, 2, 100, true ), 'winner: the result, with the last answers merged in' );
same( TBTTM_DB::$rows[1]->status, 'finished', 'winner: the row is finished' );
same( TBTTM_DB::$rows[1]->finished_at, '2026-11-13 10:12:40', 'winner: with its finish time' );
same( array_column( json_decode( TBTTM_DB::$rows[1]->review, true ), 'id' ), array( 1, 2 ), 'winner: the snapshot is stored, question ids included' );
same( TBTTM_DB::$writes, 1, 'winner: one write' );
same( $GLOBALS['fired'], array( array( 1, 'finished', 7 ) ), 'winner: tbttm_attempt_finished fired once, with the finished row and the test' );

/* ---------- a repeated finish on a finished attempt ---------- */

$again = TBTTM_Attempts::finish( TBTTM_DB::get_attempt( 1 ), $test, array( 1 => 1, 2 => 0 ) );
same( $again, $result, 'repeat: the stored result comes back unchanged, whatever answers are sent' );
same( TBTTM_DB::$writes, 1, 'repeat: nothing is written' );
same( count( $GLOBALS['fired'] ), 1, 'repeat: nothing is fired, so no second email' );

/* ---------- the request that loses the race ---------- */

// Two requests read the attempt while it is still in progress.
reset_all();
TBTTM_DB::start( 2, array( 1 => 0 ) );
$seen_by_first  = TBTTM_DB::get_attempt( 2 );
$seen_by_second = TBTTM_DB::get_attempt( 2 );
same( array( $seen_by_first->status, $seen_by_second->status ), array( 'in_progress', 'in_progress' ), 'race: both requests see an attempt in progress' );

$first = TBTTM_Attempts::finish( $seen_by_first, $test, array( 2 => 1 ) );
same( count( $GLOBALS['fired'] ), 1, 'race: the first request finishes it and fires once' );

// The second still holds its stale copy, and carries different answers.
$second = TBTTM_Attempts::finish( $seen_by_second, $test, array( 1 => 1, 2 => 0 ) );
ok( ! is_wp_error( $second ), 'loser: no error' );
same( $second, $first, 'loser: it returns the result the winner stored' );
same( $second['score'], 2, 'loser: its own answers did not replace the stored ones' );
same( TBTTM_DB::$writes, 1, 'loser: it wrote nothing' );
same( count( $GLOBALS['fired'] ), 1, 'loser: it fired nothing, so it queued no email' );
same( json_decode( TBTTM_DB::$rows[2]->answers, true ), array( '1' => 0, '2' => 1 ), 'loser: the stored answers are the winner\'s' );

// The other order: the request with the other answers gets there first.
reset_all();
TBTTM_DB::start( 3, array( 1 => 0 ) );
$a = TBTTM_DB::get_attempt( 3 );
$b = TBTTM_DB::get_attempt( 3 );
$rb = TBTTM_Attempts::finish( $b, $test, array( 1 => 1, 2 => 0 ) );
$ra = TBTTM_Attempts::finish( $a, $test, array( 2 => 1 ) );
same( array( $rb['score'], $ra['score'] ), array( 0, 0 ), 'race, other order: whoever wins, both answer with the one stored result' );
same( array( TBTTM_DB::$writes, count( $GLOBALS['fired'] ) ), array( 1, 1 ), 'race, other order: still one write and one action' );

// Many at once.
reset_all();
TBTTM_DB::start( 4, array( 1 => 0, 2 => 1 ) );
$copies = array();
for ( $i = 0; $i < 6; $i++ ) {
	$copies[] = TBTTM_DB::get_attempt( 4 );
}
$results = array();
foreach ( $copies as $copy ) {
	$results[] = TBTTM_Attempts::finish( $copy, $test, array() );
}
same( count( array_filter( $results, 'is_wp_error' ) ), 0, 'six at once: none gets an error' );
same( count( array_unique( array_map( 'serialize', $results ) ) ), 1, 'six at once: all six get the same result' );
same( array( TBTTM_DB::$writes, count( $GLOBALS['fired'] ) ), array( 1, 1 ), 'six at once: one write, one action' );

/* ---------- when the write really fails ---------- */

reset_all();
$attempt          = TBTTM_DB::start( 5, array( 1 => 0 ) );
TBTTM_DB::$broken = true;
$failed           = TBTTM_Attempts::finish( $attempt, $test, array( 2 => 1 ) );
same( is_wp_error( $failed ) ? $failed->get_error_code() : 'no error', 'tbttm_save_failed', 'database error: the page is told, so it keeps the answers and tries again' );
same( is_wp_error( $failed ) ? $failed->get_error_data()['status'] : 0, 500, 'database error: as a 500' );
same( TBTTM_DB::$rows[5]->status, 'in_progress', 'database error: the attempt is still in progress' );
same( $GLOBALS['fired'], array(), 'database error: nothing fired' );
TBTTM_DB::$broken = false;
$retry            = TBTTM_Attempts::finish( TBTTM_DB::get_attempt( 5 ), $test, array( 2 => 1 ) );
same( array( is_wp_error( $retry ), count( $GLOBALS['fired'] ) ), array( false, 1 ), 'database error: the retry finishes it and fires once' );

// The write fails for this request, but another request has finished it.
reset_all();
TBTTM_DB::start( 6, array( 1 => 0 ) );
$stale = TBTTM_DB::get_attempt( 6 );
$won   = TBTTM_Attempts::finish( TBTTM_DB::get_attempt( 6 ), $test, array() );
TBTTM_DB::$broken = true;
$lost  = TBTTM_Attempts::finish( $stale, $test, array() );
same( $lost, $won, 'write failed but the attempt is finished: the stored result, not an error' );
same( count( $GLOBALS['fired'] ), 1, 'write failed but the attempt is finished: no second action' );

// The attempt was removed (by the teacher) between the read and the write.
reset_all();
$gone = TBTTM_DB::start( 8 );
unset( TBTTM_DB::$rows[8] );
$r = TBTTM_Attempts::finish( $gone, $test, array() );
same( array( is_wp_error( $r ), $GLOBALS['fired'] ), array( true, array() ), 'attempt removed meanwhile: an error the page can show, nothing fired' );

/* ---------- an extra that fails must not undo a finish ---------- */

reset_all();
$GLOBALS['explode'] = true;
$attempt            = TBTTM_DB::start( 9, array( 1 => 0, 2 => 1 ) );
$r                  = TBTTM_Attempts::finish( $attempt, $test, array() );
ok( ! is_wp_error( $r ) && 2 === $r['score'], 'a failing listener: the student still gets the result' );
same( TBTTM_DB::$rows[9]->status, 'finished', 'a failing listener: the attempt stays finished' );

/* ---------- the result's shape ---------- */

same(
	array_keys( $r ),
	array( 'token', 'name', 'score', 'total', 'percent', 'pass_mark', 'passed', 'review', 'finished_at', 'certificate' ),
	'result: the 0.3.0 fields in order, plus certificate'
);
same( $r['certificate'], false, 'result: certificate is a boolean' );

/* ---------- fill in the gaps ---------- */

/** A gap question as TBTTM_DB::get_questions() returns it. */
function gapq( $id, $body ) {
	return array(
		'id'      => $id,
		'type'    => 'gap',
		'body'    => $body,
		'options' => array(),
		'correct' => null,
		'gaps'    => TBTTM_Gaps::parse( $body ),
	);
}
function choiceq( $id, $correct ) {
	return array( 'id' => $id, 'type' => 'choice', 'body' => 'Pick ' . $id, 'options' => array( 'a', 'b', 'c' ), 'correct' => $correct );
}
$far   = gapq( 11, "As [far] as I'm [concerned], the film was too long." );
$dont  = gapq( 12, "Really? I [don't|do not] think so." );
$gaps  = array( $far, $dont );
$mixed = array( choiceq( 21, 2 ), $far, choiceq( 23, 0 ), $dont );

// What the student page gets: the public body, the number of gaps, nothing else.
$public = TBTTM_Attempts::public_questions( $mixed );
same( $public[1], array( 'id' => 11, 'type' => 'gap', 'body' => "As [] as I'm [], the film was too long.", 'gaps' => 2 ), 'public: a gap question is its public body and its gap count' );
same( $public[0], array( 'id' => 21, 'type' => 'choice', 'body' => 'Pick 21', 'options' => array( 'a', 'b', 'c' ) ), 'public: a multiple choice question as before, with its type' );
$json = json_encode( $public );
ok( false === strpos( $json, 'far' ) && false === strpos( $json, 'concerned' ) && false === strpos( $json, 'do not' ) && false === strpos( $json, "don't" ), 'public: no accepted answer anywhere in what the page receives' );
ok( false === strpos( $json, 'correct' ), 'public: no answer key either' );

// A gap-only test: points, not questions.
reset_all();
TBTTM_DB::$questions = $gaps;
$attempt = TBTTM_DB::start( 30, array( 11 => array( 'FAR', 'concerned' ) ) );
$r       = TBTTM_Attempts::finish( $attempt, $test, array( 12 => array( 'don’t' ) ) );
same( array( $r['score'], $r['total'], $r['percent'] ), array( 3, 3, 100 ), 'gap-only: three gaps, three points' );
same(
	$r['review'][0],
	array(
		'id'    => 11,
		'type'  => 'gap',
		'body'  => "As [far] as I'm [concerned], the film was too long.",
		'gaps'  => array( array( 'far' ), array( 'concerned' ) ),
		'given' => array( 'FAR', 'concerned' ),
		'right' => array( true, true ),
	),
	'gap-only: the review entry holds the text, the gaps, the words and the verdicts'
);
same( $r['review'][1]['gaps'], array( array( "don't", 'do not' ) ), 'gap-only: every accepted answer is in the snapshot' );
same( json_decode( TBTTM_DB::$rows[30]->answers, true ), array( '11' => array( 'FAR', 'concerned' ), '12' => array( 'don’t' ) ), 'gap-only: the answers are stored as lists' );

// A half-right question and an empty gap.
reset_all();
TBTTM_DB::$questions = $gaps;
$r = TBTTM_Attempts::finish( TBTTM_DB::start( 31 ), $test, array( 11 => array( 'far', 'concern' ), 12 => array( '' ) ) );
same( array( $r['score'], $r['total'], $r['percent'] ), array( 1, 3, 33 ), 'half right: one point of three' );
same( $r['review'][0]['right'], array( true, false ), 'half right: each gap has its own verdict' );
same( array( $r['review'][1]['given'], $r['review'][1]['right'] ), array( array( '' ), array( false ) ), 'empty gap: stored as "" and marked wrong' );

reset_all();
TBTTM_DB::$questions = $gaps;
$r = TBTTM_Attempts::finish( TBTTM_DB::start( 32 ), $test, array( 11 => array( '', 'concerned' ) ) );
same( array( $r['score'], $r['total'] ), array( 1, 3 ), 'empty gap: the other gap of the question still scores' );
same( $r['review'][1]['given'], array( '' ), 'no answer at all: every gap is ""' );

// A mixed test.
reset_all();
TBTTM_DB::$questions = $mixed;
$attempt = TBTTM_DB::start( 33, array( 21 => 2, 11 => array( 'far', 'x' ) ) );
$r       = TBTTM_Attempts::finish( $attempt, $test, array( 23 => 1, 12 => array( 'Do  Not' ) ) );
same( array( $r['score'], $r['total'], $r['percent'] ), array( 3, 5, 60 ), 'mixed: one point per choice question, one per gap' );
same( array_map( static function ( $e ) { return $e['type'] ?? 'none'; }, $r['review'] ), array( 'none', 'gap', 'none', 'gap' ), 'mixed: a choice entry carries no type, as before 0.5.0' );
same( array_keys( $r['review'][0] ), array( 'id', 'body', 'options', 'chosen', 'correct' ), 'mixed: a choice entry is shaped as before' );

// Answers of the wrong kind are ignored; words are shaped and cleaned.
$merged = TBTTM_Attempts::merge( array(), array( 21 => array( 'a' ), 11 => 1, 12 => array( "  do\n not ", 'extra' ), 99 => array( 'x' ), 23 => 7 ), $mixed, array() );
same( $merged, array( 12 => array( 'do not' ) ), 'merge: a list for a choice question, an index for a gap question and unknown questions are ignored' );
$merged = TBTTM_Attempts::merge( array( 11 => array( 'far', 'concerned' ) ), array( 11 => array( '' ) ), $mixed, array() );
same( $merged, array( 11 => array( '', '' ) ), 'merge: emptying a gap is saved, and the list is padded' );
$merged = TBTTM_Attempts::merge( array(), array( 11 => array( str_repeat( 'ż', 150 ), array( 'x' ) ) ), $mixed, array() );
same( array( mb_strlen( $merged[11][0] ), $merged[11][1] ), array( 100, '' ), 'merge: a word is cut to 100 characters, junk is empty' );

same( TBTTM_Attempts::answered( array( 21 => 0, 11 => array( 'far', '' ), 12 => array( 'x' ) ), $mixed ), 2, 'answered: a gap question counts only when every gap has text' );
same( TBTTM_Attempts::answered( array( 11 => array( 'a', 'b' ), 12 => 1, 21 => array( 'x' ), 99 => 1 ), $mixed ), 1, 'answered: answers of the wrong kind and unknown questions do not count' );

// A checked question that a later save tries to change.
reset_all();
TBTTM_DB::$questions = $mixed;
$attempt          = TBTTM_DB::start( 34, array( 11 => array( 'far', 'concern' ), 21 => 0 ) );
$attempt->checked = '[11,21]';
TBTTM_DB::$rows[34]->checked = '[11,21]';
$merged = TBTTM_Attempts::merge( TBTTM_Attempts::answers( $attempt ), array( 11 => array( 'far', 'concerned' ), 21 => 2, 12 => array( "don't" ) ), $mixed, TBTTM_Attempts::checked( $attempt ) );
same( $merged, array( 11 => array( 'far', 'concern' ), 21 => 0, 12 => array( "don't" ) ), 'checked: a save cannot change a checked question, of either type' );
$state = TBTTM_Attempts::state( $attempt, $mixed );
same(
	(array) $state['revealed'],
	array( '21' => 2, '11' => array( 'right' => array( true, false ), 'correct' => array( 'far', 'concerned' ) ) ),
	'checked: state() reveals the checked questions, a gap question as verdicts and first accepted answers'
);
ok( false === strpos( json_encode( $state ), 'do not' ) && false === strpos( json_encode( $state ), "don't" ), 'checked: nothing about an unchecked question is in the state' );
same( TBTTM_Attempts::reveal( $dont, array( 'do not' ) ), array( 'right' => array( true ), 'correct' => array( "don't" ) ), 'reveal: the first accepted answer is the one shown' );
$r = TBTTM_Attempts::finish( $attempt, $test, array( 11 => array( 'far', 'concerned' ), 21 => 2 ) );
same( array( $r['score'], $r['total'] ), array( 1, 5 ), 'checked: the finish cannot change it either' );
same( $r['review'][1]['given'], array( 'far', 'concern' ), 'checked: the snapshot holds what was checked' );

// A finish with pre-0.5.0 shaped answers and questions: nothing has a type.
reset_all();
$attempt = TBTTM_DB::start( 35, array( 1 => 0 ) );
$r       = TBTTM_Attempts::finish( $attempt, $test, array( '2' => '1' ) );
same( array( $r['score'], $r['total'], $r['percent'] ), array( 2, 2, 100 ), 'pre-0.5.0: untyped questions and index answers score as before' );
same( $r['review'][0], array( 'id' => 1, 'body' => 'One', 'options' => array( 'a', 'b' ), 'chosen' => 0, 'correct' => 0 ), 'pre-0.5.0: the review entry is unchanged' );
same( TBTTM_Attempts::public_questions( TBTTM_DB::get_questions( 7 ) )[0], array( 'id' => 1, 'type' => 'choice', 'body' => 'One', 'options' => array( 'a', 'b' ) ), 'pre-0.5.0: an untyped question reads as multiple choice' );

// A question that became a gap question while an attempt held an index for it.
reset_all();
TBTTM_DB::$questions = $gaps;
$r = TBTTM_Attempts::finish( TBTTM_DB::start( 36, array( 11 => 1, 12 => 0 ) ), $test, array() );
same( array( $r['score'], $r['total'], $r['review'][0]['given'] ), array( 0, 3, array( '', '' ) ), 'type changed meanwhile: an index is no answer to a gap question' );

echo "{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
