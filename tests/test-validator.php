<?php
/**
 * Standalone tests for TBTTM_Validator.
 *
 * The validator is the one definition of what a test may hold: a draft must
 * always save, and a published test must be complete. These are the rules a
 * teacher would otherwise meet one refused publish at a time.
 *
 * WordPress is not loaded; the handful of WordPress functions the validator
 * calls are stubbed below with their documented behaviour.
 *
 * Run: php tests/test-validator.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

/* ---------- WordPress stubs ---------- */

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
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
function sanitize_text_field( $str ) {
	$str = preg_replace( '/<[^>]*>/', '', (string) $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( $str );
}
function sanitize_textarea_field( $str ) {
	$str = preg_replace( '/<[^>]*>/', '', (string) $str );
	return trim( $str );
}

require __DIR__ . '/../includes/class-tbttm-gaps.php';
require __DIR__ . '/../includes/class-tbttm-validator.php';

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
function error_code( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() : 'no error';
}

$v = new TBTTM_Validator();

function question( $body = 'Which is right?', $options = array( 'go', 'goes', 'going' ), $correct = 1, $id = 0 ) {
	return array(
		'id'      => $id,
		'body'    => $body,
		'options' => $options,
		'correct' => $correct,
	);
}
function test( array $over = array() ) {
	return array_merge(
		array(
			'title'     => 'Present Simple',
			'intro'     => 'Ten quick questions.',
			'image_id'  => 0,
			'feedback'  => 'end',
			'bulls'     => true,
			'pass_mark' => '',
			'questions' => array( question(), question( 'She ___ to work.', array( 'go', 'goes' ), 1, 7 ) ),
		),
		$over
	);
}

/* ---------- a valid test ---------- */

$clean = $v->validate( test() );
ok( ! is_wp_error( $clean ), 'valid test passes validate()' );
same( $clean['title'], 'Present Simple', 'valid: title kept' );
same( count( $clean['questions'] ), 2, 'valid: both questions kept' );
same( $clean['questions'][1]['id'], 7, 'valid: question ID kept for a stable update' );
same( $clean['questions'][0]['correct'], 1, 'valid: correct index kept' );
same( $clean['pass_mark'], null, 'valid: empty pass mark is null' );
same( $clean['feedback'], 'end', 'valid: feedback end' );
same( $clean['bulls'], true, 'valid: bulls on' );

/* ---------- publishing rules ---------- */

same( error_code( $v->validate( test( array( 'title' => '   ' ) ) ) ), 'tbttm_no_title', 'publish: title required' );
same( error_code( $v->validate( test( array( 'questions' => array() ) ) ) ), 'tbttm_no_questions', 'publish: at least one question' );

$r = $v->validate( test( array( 'questions' => array( question(), question( '' ) ) ) ) );
same( error_code( $r ), 'tbttm_unfinished_questions', 'publish: question needs a body' );
same( is_wp_error( $r ) ? $r->get_error_message() : '', 'Question 2 is not finished: no question text.', 'publish: message names question 2 and the problem' );
same( is_wp_error( $r ) ? $r->get_error_data()['questions'] : null, array( 2 ), 'publish: error data lists question 2' );

$r = $v->validate( test( array( 'questions' => array( question( 'Q', array( 'only', '', '' ), 0 ) ) ) ) );
same( is_wp_error( $r ) ? $r->get_error_message() : '', 'Question 1 is not finished: needs two answers.', 'publish: two non-empty options' );

$r = $v->validate( test( array( 'questions' => array( question( 'Q', array( 'a', 'b' ), null ) ) ) ) );
same( is_wp_error( $r ) ? $r->get_error_message() : '', 'Question 1 is not finished: no correct answer.', 'publish: correct index required' );

$r = $v->validate( test( array( 'questions' => array( question( 'Q', array( 'a', 'b', '' ), 2 ) ) ) ) );
same( is_wp_error( $r ) ? $r->get_error_message() : '', 'Question 1 is not finished: no correct answer.', 'publish: correct must point at a non-empty option' );

$r = $v->validate( test( array( 'questions' => array( question( 'Q', array( 'a', 'b' ), 5 ) ) ) ) );
same( error_code( $r ), 'tbttm_unfinished_questions', 'publish: correct past the options is refused' );

$r = $v->validate( test( array( 'questions' => array( question(), question( '' ), question(), question( 'Q', array( 'a' ), 0 ) ) ) ) );
same(
	is_wp_error( $r ) ? $r->get_error_message() : '',
	'Questions 2, 4 are not finished. Question 2: no question text. Question 4: needs two answers.',
	'publish: several unfinished questions are all named'
);
same( is_wp_error( $r ) ? $r->get_error_data()['questions'] : null, array( 2, 4 ), 'publish: error data lists 2 and 4' );

$many = array();
for ( $i = 0; $i < 12; $i++ ) {
	$many[] = question( '' );
}
$r = $v->validate( test( array( 'questions' => $many ) ) );
ok( is_wp_error( $r ) && 0 === strpos( $r->get_error_message(), 'Questions 1, 2, 3, 4, 5, 6, 7, 8… are not finished.' ), 'publish: long list is cut with …' );

$r = $v->validate( test( array( 'questions' => array( question( 'Q', array( 'a', '', 'c', 'd' ), 2 ) ) ) ) );
same( is_wp_error( $r ) ? null : $r['questions'][0], array( 'id' => 0, 'type' => 'choice', 'body' => 'Q', 'options' => array( 'a', 'c', 'd' ), 'correct' => 1 ), 'publish: empty options dropped, key follows its option' );

/* ---------- limits ---------- */

$r = $v->sanitise( test( array( 'title' => str_repeat( 'ą', 250 ) ) ) );
same( mb_strlen( $r['title'] ), 200, 'limit: title 200 characters (multibyte)' );

$r = $v->sanitise( test( array( 'intro' => str_repeat( 'x', 1200 ) ) ) );
same( strlen( $r['intro'] ), 1000, 'limit: introduction 1,000 characters' );

$r = $v->sanitise( test( array( 'questions' => array( question( str_repeat( 'y', 2100 ) ) ) ) ) );
same( strlen( $r['questions'][0]['body'] ), 2000, 'limit: question body 2,000 characters' );

$r = $v->sanitise( test( array( 'questions' => array( question( "Tom: Hi.\r\nJenny: Hello!\r\n\r\nWho speaks?" ) ) ) ) );
same( $r['questions'][0]['body'], "Tom: Hi.\nJenny: Hello!\n\nWho speaks?", 'limit: body keeps its line breaks' );

$r = $v->sanitise( test( array( 'questions' => array( question( 'Q', array( '1', '2', '3', '4', '5', '6', '7', '8' ), 0 ) ) ) ) );
same( count( $r['questions'][0]['options'] ), 6, 'limit: at most six options' );

$r = $v->sanitise( test( array( 'questions' => array( question( 'Q', array( str_repeat( 'o', 400 ), 'b' ), 0 ) ) ) ) );
same( strlen( $r['questions'][0]['options'][0] ), 300, 'limit: option text 300 characters' );

$two_hundred_one = array_fill( 0, 201, question() );
same( count( $v->sanitise( test( array( 'questions' => $two_hundred_one ) ) )['questions'] ), 200, 'limit: sanitise keeps at most 200 questions' );
same( error_code( $v->validate( test( array( 'questions' => $two_hundred_one ) ) ) ), 'tbttm_too_many_questions', 'limit: validate refuses 201 questions' );
ok( ! is_wp_error( $v->validate( test( array( 'questions' => array_fill( 0, 200, question() ) ) ) ) ), 'limit: 200 questions publish' );

foreach ( array( '60' => 60, 60 => 60, '1' => 1, '100' => 100, ' 75 ' => 75 ) as $in => $out ) {
	same( $v->validate( test( array( 'pass_mark' => is_int( $in ) ? $in : (string) $in ) ) )['pass_mark'], $out, "limit: pass mark '{$in}' accepted" );
}
foreach ( array( '0', '101', '50.5', 'abc', '-5' ) as $bad ) {
	same( error_code( $v->validate( test( array( 'pass_mark' => $bad ) ) ) ), 'tbttm_bad_pass_mark', "limit: pass mark '{$bad}' refused" );
	same( $v->sanitise( test( array( 'pass_mark' => $bad ) ) )['pass_mark'], null, "limit: draft pass mark '{$bad}' becomes not set" );
}
same( $v->validate( test( array( 'pass_mark' => null ) ) )['pass_mark'], null, 'limit: null pass mark is not set' );

same( $v->sanitise( test( array( 'feedback' => 'now' ) ) )['feedback'], 'now', 'limit: feedback now' );
same( $v->sanitise( test( array( 'feedback' => 'later' ) ) )['feedback'], 'end', 'limit: unknown feedback becomes end' );
same( $v->sanitise( test( array( 'bulls' => false ) ) )['bulls'], false, 'bulls: off' );
same( $v->sanitise( array( 'title' => 'T' ) )['bulls'], true, 'bulls: on by default' );
same( $v->sanitise( test( array( 'title' => '<b>Bold</b> title' ) ) )['title'], 'Bold title', 'title: tags stripped' );

/* ---------- access, class and lesson ---------- */

same( $v->sanitise( array( 'title' => 'T' ) )['access'], 'login', 'access: logged-in students by default' );
same( $v->sanitise( test( array( 'access' => 'open' ) ) )['access'], 'open', 'access: open kept' );
same( $v->sanitise( test( array( 'access' => 'login' ) ) )['access'], 'login', 'access: login kept' );
same( $v->sanitise( test( array( 'access' => 'public' ) ) )['access'], 'login', 'access: anything else becomes login' );
same( $v->sanitise( test( array( 'access' => array( 'open' ) ) ) )['access'], 'login', 'access: junk becomes login' );
same( $v->validate( test( array( 'access' => 'open' ) ) )['access'], 'open', 'access: validate() carries it' );

same( $v->sanitise( array( 'title' => 'T' ) )['class_id'], null, 'class: none by default' );
same( $v->sanitise( test( array( 'class_id' => 12 ) ) )['class_id'], 12, 'class: a whole number kept' );
same( $v->sanitise( test( array( 'class_id' => '12' ) ) )['class_id'], 12, 'class: a numeric string kept' );
same( $v->sanitise( test( array( 'class_id' => '' ) ) )['class_id'], null, 'class: empty is no class' );
same( $v->sanitise( test( array( 'class_id' => 0 ) ) )['class_id'], null, 'class: zero is no class' );
same( $v->sanitise( test( array( 'class_id' => -3 ) ) )['class_id'], null, 'class: negative is no class' );
same( $v->sanitise( test( array( 'class_id' => '1.5' ) ) )['class_id'], null, 'class: a fraction is no class' );
same( $v->sanitise( test( array( 'class_id' => 1.5 ) ) )['class_id'], null, 'class: a float is no class' );
same( $v->sanitise( test( array( 'class_id' => '12 OR 1=1' ) ) )['class_id'], null, 'class: text is no class' );
same( $v->sanitise( test( array( 'class_id' => array( 12 ) ) ) )['class_id'], null, 'class: an array is no class' );

same( $v->sanitise( test( array( 'class_id' => 12, 'lesson_id' => 40 ) ) )['lesson_id'], 40, 'lesson: kept with a class' );
same( $v->sanitise( test( array( 'class_id' => 12, 'lesson_id' => '' ) ) )['lesson_id'], null, 'lesson: empty is any lesson' );
same( $v->sanitise( test( array( 'class_id' => 12, 'lesson_id' => 0 ) ) )['lesson_id'], null, 'lesson: zero is any lesson' );
same( $v->sanitise( test( array( 'class_id' => 12, 'lesson_id' => 'x' ) ) )['lesson_id'], null, 'lesson: text is any lesson' );
same( $v->sanitise( test( array( 'lesson_id' => 40 ) ) )['lesson_id'], null, 'lesson: cleared without a class' );
same( $v->sanitise( test( array( 'class_id' => '', 'lesson_id' => 40 ) ) )['lesson_id'], null, 'lesson: cleared when the class is empty' );
$r = $v->validate( test( array( 'class_id' => 12, 'lesson_id' => 40 ) ) );
same( array( $r['class_id'], $r['lesson_id'] ), array( 12, 40 ), 'class and lesson: validate() carries both' );

/* ---------- certificate and email the result ---------- */

foreach ( array( 'certificate', 'email_result' ) as $field ) {
	same( $v->sanitise( array( 'title' => 'T' ) )[ $field ], false, "{$field}: off by default" );
	same( $v->sanitise( test( array( $field => true ) ) )[ $field ], true, "{$field}: true turns it on" );
	same( $v->sanitise( test( array( $field => false ) ) )[ $field ], false, "{$field}: false keeps it off" );
	foreach ( array( 1, '1', 'true', 'on', 'yes', array( true ), null, 0, '' ) as $junk ) {
		same( $v->sanitise( test( array( $field => $junk ) ) )[ $field ], false, "{$field}: anything but true is false: " . var_export( $junk, true ) );
	}
	same( $v->validate( test( array( $field => true ) ) )[ $field ], true, "{$field}: validate() carries it" );
}
$r = $v->sanitise( test( array( 'certificate' => true ) ) );
same( array( $r['certificate'], $r['email_result'] ), array( true, false ), 'certificate and email are independent' );

/* ---------- drafts ---------- */

$draft = test(
	array(
		'questions' => array(
			question(),
			question( '', array( '', '', '' ), null ),
			question( 'Half done', array( 'a', '' ), null ),
		),
	)
);
$r = $v->sanitise( $draft );
same( count( $r['questions'] ), 3, 'draft: unfinished questions pass sanitise()' );
same( $r['questions'][1], array( 'id' => 0, 'type' => 'choice', 'body' => '', 'options' => array( '', '', '' ), 'correct' => null ), 'draft: an empty question is kept as it is' );
same( $r['questions'][2]['options'], array( 'a', '' ), 'draft: empty option fields kept in place' );
same( error_code( $v->validate( $draft ) ), 'tbttm_unfinished_questions', 'draft: the same test cannot be published' );
same( $v->sanitise( array() )['questions'], array(), 'draft: an empty payload sanitises' );
same( $v->sanitise( array( 'questions' => 'nonsense' ) )['questions'], array(), 'draft: junk questions ignored' );

/* ---------- fill in the gaps ---------- */

function gapq( $body, $id = 0 ) {
	return array(
		'id'      => $id,
		'type'    => 'gap',
		'body'    => $body,
		// What the builder still holds from the other type: dropped on save.
		'options' => array( 'left', 'over' ),
		'correct' => 1,
	);
}
function gap_problem( $body ) {
	global $v;
	$r = $v->validate( test( array( 'questions' => array( gapq( $body ) ) ) ) );
	return is_wp_error( $r ) ? $r->get_error_message() : '';
}

$r = $v->sanitise( test( array( 'questions' => array( gapq( "I couldn't agree [more].", 9 ) ) ) ) );
same(
	$r['questions'][0],
	array( 'id' => 9, 'type' => 'gap', 'body' => "I couldn't agree [more].", 'gaps' => array( array( 'more' ) ) ),
	'gap: body kept, gaps read from it, options and correct dropped'
);

$r = $v->validate( test( array( 'questions' => array( gapq( "As [far] as I'm [concerned], it was too long." ) ) ) ) );
ok( ! is_wp_error( $r ), 'gap: a question with two gaps can be published' );
same( $r['questions'][0]['gaps'], array( array( 'far' ), array( 'concerned' ) ), 'gap: several gaps, in order' );
ok( ! array_key_exists( 'options', $r['questions'][0] ), 'gap: a published gap question has no options' );

$r = $v->validate( test( array( 'questions' => array( gapq( "Really? I [don't | do not] think so." ) ) ) ) );
same( $r['questions'][0]['gaps'], array( array( "don't", 'do not' ) ), 'gap: accepted alternatives, trimmed' );
same( $r['questions'][0]['body'], "Really? I [don't | do not] think so.", 'gap: the body stays as the teacher wrote it' );

$r = $v->validate( test( array( 'questions' => array( gapq( 'Zażółć [gęślą] jaźń.' ) ) ) ) );
same( $r['questions'][0]['gaps'], array( array( 'gęślą' ) ), 'gap: Polish letters' );

$r = $v->validate( test( array( 'questions' => array( question(), gapq( 'I see your [point].' ) ) ) ) );
ok( ! is_wp_error( $r ), 'gap: gap and multiple choice questions sit in one test' );
same( array( $r['questions'][0]['type'], $r['questions'][1]['type'] ), array( 'choice', 'gap' ), 'gap: each question keeps its type' );

same( gap_problem( '' ), 'Question 1 is not finished: no text.', 'gap: no text' );
same( gap_problem( 'I could not agree more.' ), 'Question 1 is not finished: no gap yet.', 'gap: no gap yet' );
same( gap_problem( 'I could not [ ] agree [more].' ), 'Question 1 is not finished: a gap is empty.', 'gap: a gap is empty' );
same( gap_problem( 'I could not [|] agree.' ), 'Question 1 is not finished: a gap is empty.', 'gap: only separators is empty' );
same( gap_problem( 'I could not agree [more] or [less.' ), 'Question 1 is not finished: a bracket is not closed.', 'gap: a bracket is not closed' );
same( gap_problem( 'I could ] not agree [more].' ), 'Question 1 is not finished: a bracket is not closed.', 'gap: a closing bracket alone' );
same( gap_problem( 'An [[inner]] gap.' ), 'Question 1 is not finished: a bracket is not closed.', 'gap: brackets do not nest' );
same( gap_problem( str_repeat( '[a] ', TBTTM_Gaps::GAPS_MAX + 1 ) ), 'Question 1 is not finished: too many gaps.', 'gap: too many gaps' );
same( gap_problem( str_repeat( '[a] ', TBTTM_Gaps::GAPS_MAX ) ), '', 'gap: sixty gaps are allowed' );

$broken = test( array( 'questions' => array( gapq( 'No gap here' ), gapq( '' ), gapq( 'Open [bracket' ) ) ) );
same( count( $v->sanitise( $broken )['questions'] ), 3, 'gap: a draft saves with any of these' );
same( error_code( $v->validate( $broken ) ), 'tbttm_unfinished_questions', 'gap: the same draft cannot be published' );

// The type decides, never the content.
$r = $v->validate( test( array( 'questions' => array( question( 'Choose: I [more] agree.' ) ) ) ) );
ok( ! is_wp_error( $r ), 'choice: a bracket in a multiple choice question is ordinary text' );
same( $r['questions'][0]['type'], 'choice', 'choice: it stays multiple choice' );
same( $r['questions'][0]['body'], 'Choose: I [more] agree.', 'choice: brackets kept in the text' );
ok( ! array_key_exists( 'gaps', $r['questions'][0] ), 'choice: no gaps read from it' );
same( $v->sanitise( test( array( 'questions' => array( array_merge( question(), array( 'type' => 'essay' ) ) ) ) ) )['questions'][0]['type'], 'choice', 'choice: an unknown type is multiple choice' );

// Limits: a gap body is longer than a multiple choice one.
$long = str_repeat( 'word ', 1100 ) . '[gap]';
$r    = $v->sanitise( test( array( 'questions' => array( gapq( $long ), question( $long ) ) ) ) );
same( $r['questions'][0]['body'], $long, 'gap: a body up to 6000 characters is kept whole' );
same( mb_strlen( $r['questions'][1]['body'] ) <= TBTTM_Validator::BODY_MAX, true, 'choice: a body is still cut at 2000' );
same( mb_strlen( $v->sanitise( test( array( 'questions' => array( gapq( str_repeat( 'x', 7000 ) ) ) ) ) )['questions'][0]['body'] ), TBTTM_Gaps::BODY_MAX, 'gap: a longer body is cut at 6000' );

echo "{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
