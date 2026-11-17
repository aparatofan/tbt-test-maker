<?php
/**
 * Standalone tests for TBTTM_Attempts::answer_key(): the answer key Teacher
 * Mode fetches from tests/{id}/key.
 *
 * The rule under test: one entry per question, in order. A multiple choice
 * question gives `correct`; a gap question gives `gaps`, one list of
 * accepted answers per gap, in body order. A multiple choice question with
 * no key is left out. Nothing else is returned: no body, no options.
 *
 * WordPress is not loaded; answer_key() uses none of it.
 *
 * Run: php tests/test-answer-key.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

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

/** A multiple choice question as TBTTM_DB::get_questions() returns it. */
function choice( $id, $correct ) {
	return array(
		'id'      => $id,
		'type'    => 'choice',
		'body'    => 'Question ' . $id,
		'options' => array( 'yes', 'no', 'maybe' ),
		'correct' => $correct,
	);
}

/** A gap question as TBTTM_DB::get_questions() returns it. */
function gap( $id, $body ) {
	return array(
		'id'      => $id,
		'type'    => 'gap',
		'body'    => $body,
		'options' => array(),
		'correct' => null,
		'gaps'    => TBTTM_Gaps::parse( $body ),
	);
}

$questions = array(
	choice( 7, 2 ),
	gap( 3, "Pete: Really? I [don't|do not] think so." ),
	choice( 9, 0 ),
	gap( 4, 'As [far] as I\'m [concerned], the film was far too long.' ),
);
$key = TBTTM_Attempts::answer_key( $questions );

// Order.
same( array_map( function ( $k ) { return $k['id']; }, $key ), array( 7, 3, 9, 4 ), 'keeps question order' );

// Multiple choice.
same( $key[0], array( 'id' => 7, 'type' => 'choice', 'correct' => 2 ), 'choice gives correct' );
same( $key[2], array( 'id' => 9, 'type' => 'choice', 'correct' => 0 ), 'choice: index 0 is a key, not a missing one' );

// Gaps, with alternatives, one list per gap in body order.
same( $key[1], array( 'id' => 3, 'type' => 'gap', 'gaps' => array( array( "don't", 'do not' ) ) ), 'gap gives every accepted answer' );
same( $key[3]['gaps'], array( array( 'far' ), array( 'concerned' ) ), 'gap: one list per gap, in body order' );

// A choice with no key is skipped.
same( TBTTM_Attempts::answer_key( array( choice( 1, 1 ), choice( 2, null ), choice( 3, 2 ) ) ), array(
	array( 'id' => 1, 'type' => 'choice', 'correct' => 1 ),
	array( 'id' => 3, 'type' => 'choice', 'correct' => 2 ),
), 'a choice with correct = null is skipped' );

// Only id, type and correct / gaps.
foreach ( $key as $k ) {
	$keys = array_keys( $k );
	sort( $keys );
	same( $keys, 'gap' === $k['type'] ? array( 'gaps', 'id', 'type' ) : array( 'correct', 'id', 'type' ), 'question ' . $k['id'] . ': nothing beyond id, type and the key' );
}

// Types: ints for ids and indexes, strings for answers.
ok( is_int( $key[0]['id'] ) && is_int( $key[0]['correct'] ), 'choice: id and correct are ints' );
same( TBTTM_Attempts::answer_key( array( choice( '5', '1' ) ) ), array( array( 'id' => 5, 'type' => 'choice', 'correct' => 1 ) ), 'numeric strings become ints' );

// Nothing in, nothing out.
same( TBTTM_Attempts::answer_key( array() ), array(), 'an empty test gives an empty key' );

echo "{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
