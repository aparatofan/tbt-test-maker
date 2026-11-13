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

	public static function now() {
		return '2026-11-13 10:12:40';
	}
	public static function get_questions( $test_id ) {
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

echo "{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
