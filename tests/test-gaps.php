<?php
/**
 * Standalone tests for TBTTM_Gaps: what a gap is, what the student page may
 * see of it, and when a typed word is right.
 *
 * Two rules sit here. The stored body of a gap question holds its answers,
 * so public_body() must leak none of them. And marking forgives what a
 * student should not lose a point for: capitals, extra spaces, and the kind
 * of apostrophe a phone keyboard happens to type.
 *
 * WordPress is not loaded; the class uses none of it.
 *
 * Run: php tests/test-gaps.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../includes/class-tbttm-gaps.php';

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

/* ---------- parse ---------- */

same( TBTTM_Gaps::parse( "I couldn't agree [more]." ), array( array( 'more' ) ), 'parse: one gap' );
same( TBTTM_Gaps::parse( "As [far] as I'm [concerned], the film was far too long." ), array( array( 'far' ), array( 'concerned' ) ), 'parse: several gaps, in order' );
same( TBTTM_Gaps::parse( "Really? I [don't|do not] think so." ), array( array( "don't", 'do not' ) ), 'parse: accepted alternatives, the first one first' );
same( TBTTM_Gaps::parse( 'A [  spaced   out | answer ] here.' ), array( array( 'spaced out', 'answer' ) ), 'parse: answers trimmed, inner spaces single' );
same( TBTTM_Gaps::parse( 'A [one||two|] here.' ), array( array( 'one', 'two' ) ), 'parse: empty alternatives dropped' );
same( TBTTM_Gaps::parse( 'An [] empty and [ | ] another.' ), array( array(), array() ), 'parse: an empty gap is still a gap, with no answers' );
same( TBTTM_Gaps::parse( 'No gaps at all.' ), array(), 'parse: no gap' );
same( TBTTM_Gaps::parse( '' ), array(), 'parse: empty text' );
same( TBTTM_Gaps::parse( 'Open [bracket and [closed].' ), array( array( 'closed' ) ), 'parse: an unclosed bracket is not a gap' );
same( TBTTM_Gaps::parse( 'An [[inner]] one.' ), array( array( 'inner' ) ), 'parse: brackets do not nest' );
same( TBTTM_Gaps::parse( "Zażółć [gęślą|GĘŚLĄ] jaźń.\nDruga [linia]." ), array( array( 'gęślą', 'GĘŚLĄ' ), array( 'linia' ) ), 'parse: Polish letters, across lines' );
same( TBTTM_Gaps::parse( 'I [don’t] know.' ), array( array( 'don’t' ) ), 'parse: a curly apostrophe is kept as written' );
same( count( TBTTM_Gaps::parse( '[a|b|c|d|e|f|g|h]' )[0] ), TBTTM_Gaps::ACCEPTED_MAX, 'parse: at most six accepted answers' );
same( mb_strlen( TBTTM_Gaps::parse( '[' . str_repeat( 'ż', 150 ) . ']' )[0][0] ), TBTTM_Gaps::ANSWER_MAX, 'parse: an answer is cut to 100 characters' );
same( count( TBTTM_Gaps::parse( str_repeat( '[a] ', 70 ) ) ), 70, 'parse: every gap is counted, the limit is the validator\'s' );

/* ---------- issue ---------- */

same( TBTTM_Gaps::issue( '' ), 'empty', 'issue: no text' );
same( TBTTM_Gaps::issue( "  \n " ), 'empty', 'issue: only white space is no text' );
same( TBTTM_Gaps::issue( 'Nothing to fill.' ), 'none', 'issue: no gap yet' );
same( TBTTM_Gaps::issue( 'Half [open.' ), 'none', 'issue: a lone bracket is no gap yet' );
same( TBTTM_Gaps::issue( 'One [] and [two].' ), 'hollow', 'issue: a gap is empty' );
same( TBTTM_Gaps::issue( 'One [two] and [three.' ), 'open', 'issue: a bracket is not closed' );
same( TBTTM_Gaps::issue( 'One [] and [three.' ), 'hollow', 'issue: an empty gap is named before an open bracket' );
same( TBTTM_Gaps::issue( str_repeat( '[a] ', 61 ) ), 'many', 'issue: too many gaps' );
same( TBTTM_Gaps::issue( str_repeat( '[a] ', 60 ) ), '', 'issue: sixty is fine' );
same( TBTTM_Gaps::issue( 'I see your [point].' ), '', 'issue: a good question' );

/* ---------- public_body ---------- */

same( TBTTM_Gaps::public_body( "I couldn't agree [more]." ), "I couldn't agree []." , 'public: one gap emptied' );
same( TBTTM_Gaps::public_body( "As [far] as I'm [concerned]." ), "As [] as I'm [].", 'public: every gap emptied' );
same( TBTTM_Gaps::public_body( "Pete: Really? I [don't|do not] think so.\n\nKat: I see your [point]." ), "Pete: Really? I [] think so.\n\nKat: I see your [].", 'public: speaker names and line breaks stay' );
same( TBTTM_Gaps::public_body( 'No gaps.' ), 'No gaps.', 'public: text without gaps unchanged' );

$secret = "In Kat's [opinion], the office is a [thing|matter] of the [past]. Zażółć [gęślą|GĘŚLĄ] jaźń. A [very long answer indeed] and a [x].";
$public = TBTTM_Gaps::public_body( $secret );
$leaked = array();
foreach ( TBTTM_Gaps::parse( $secret ) as $accepted ) {
	foreach ( $accepted as $answer ) {
		if ( false !== mb_stripos( $public, $answer ) && 'x' !== $answer ) {
			$leaked[] = $answer;
		}
	}
}
same( $leaked, array(), 'public: no accepted answer is in the public body' );
same( substr_count( $public, '[]' ), count( TBTTM_Gaps::parse( $secret ) ), 'public: one marker per gap' );
ok( ! preg_match( '/\[[^\]]/', $public ), 'public: nothing is left inside any bracket' );
same( $public, "In Kat's [], the office is a [] of the []. Zażółć [] jaźń. A [] and a [].", 'public: every marker is the same, whatever the answer\'s length' );
same( TBTTM_Gaps::parse( $public ), array( array(), array(), array(), array(), array(), array() ), 'public: parsing the public body gives no answers' );

/* ---------- normalise ---------- */

same( TBTTM_Gaps::normalise( "  Don't  " ), "don't", 'normalise: lower case and trimmed' );
same( TBTTM_Gaps::normalise( 'do    not' ), 'do not', 'normalise: runs of spaces become one' );
same( TBTTM_Gaps::normalise( "do\t\n not" ), 'do not', 'normalise: tabs and line breaks are spaces' );
same( TBTTM_Gaps::normalise( "do\u{00A0}not" ), 'do not', 'normalise: a non-breaking space is a space' );
same( TBTTM_Gaps::normalise( 'ŻÓŁĆ' ), 'żółć', 'normalise: Polish capitals' );
foreach ( array( '’', '‘', '`', '´', "'" ) as $mark ) {
	same( TBTTM_Gaps::normalise( "don{$mark}t" ), "don't", "normalise: apostrophe {$mark}" );
}

/* ---------- right ---------- */

$accepted = array( "don't", 'do not' );
foreach ( array( "Don't", "don't", 'don’t', 'DON‘T', 'do not', ' Do   Not ', 'don`t', 'don´t' ) as $typed ) {
	ok( TBTTM_Gaps::right( $typed, $accepted ), "right: {$typed}" );
}
foreach ( array( '', '   ', 'dont', 'do  n ot', 'does not', "don't.", null, array( "don't" ) ) as $typed ) {
	ok( ! TBTTM_Gaps::right( $typed, $accepted ), 'wrong: ' . var_export( $typed, true ) );
}
ok( TBTTM_Gaps::right( 'gęślą', array( 'GĘŚLĄ' ) ), 'right: Polish letters, any case' );
ok( ! TBTTM_Gaps::right( 'gesla', array( 'gęślą' ) ), 'wrong: Polish letters without their marks' );
ok( TBTTM_Gaps::right( "I'm", array( 'I’m' ) ), 'right: a curly apostrophe in the key, a straight one typed' );
ok( ! TBTTM_Gaps::right( '', array( '' ) ), 'wrong: an empty answer is never right' );
ok( ! TBTTM_Gaps::right( 'more', array() ), 'wrong: a gap with no accepted answer' );

/* ---------- clean, shape, complete, mark, first ---------- */

same( TBTTM_Gaps::clean( "  far\n away\t " ), 'far away', 'clean: one line of text' );
same( TBTTM_Gaps::clean( "a\x00b" ), 'a b', 'clean: control characters go' );
same( TBTTM_Gaps::clean( '<3 & co' ), '<3 & co', 'clean: text is kept as typed; the page escapes it' );
same( mb_strlen( TBTTM_Gaps::clean( str_repeat( 'ż', 300 ) ) ), 100, 'clean: cut to 100 characters' );
same( TBTTM_Gaps::clean( array( 'x' ) ), '', 'clean: not text' );
same( TBTTM_Gaps::clean( "\xff\xfe" ), '', 'clean: broken text' );
same( TBTTM_Gaps::clean( 7 ), '7', 'clean: a number is its text' );

same( TBTTM_Gaps::shape( array( 'far' ), 2 ), array( 'far', '' ), 'shape: padded to the number of gaps' );
same( TBTTM_Gaps::shape( array( 'a', 'b', 'c' ), 2 ), array( 'a', 'b' ), 'shape: cut to the number of gaps' );
same( TBTTM_Gaps::shape( array( 5 => 'a', 9 => ' b ' ), 2 ), array( 'a', 'b' ), 'shape: keys ignored, words cleaned' );
same( TBTTM_Gaps::shape( 3, 2 ), array( '', '' ), 'shape: anything but a list is empty' );
same( TBTTM_Gaps::shape( array( array( 'x' ), null ), 2 ), array( '', '' ), 'shape: junk inside is empty' );

ok( TBTTM_Gaps::complete( array( 'a', 'b' ), 2 ), 'complete: every gap has text' );
ok( ! TBTTM_Gaps::complete( array( 'a', '' ), 2 ), 'complete: one empty gap' );
ok( ! TBTTM_Gaps::complete( array( 'a', '  ' ), 2 ), 'complete: spaces are not text' );
ok( ! TBTTM_Gaps::complete( array( 'a' ), 2 ), 'complete: a short list' );
ok( ! TBTTM_Gaps::complete( 1, 1 ), 'complete: an option index is not a gap answer' );
ok( ! TBTTM_Gaps::complete( array(), 0 ), 'complete: a question with no gap is never done' );

$gaps = array( array( 'far' ), array( 'concerned', 'aware' ) );
same( TBTTM_Gaps::mark( $gaps, array( 'Far', 'concern' ) ), array( true, false ), 'mark: one right, one wrong' );
same( TBTTM_Gaps::mark( $gaps, array( 'far' ) ), array( true, false ), 'mark: a missing word is wrong' );
same( TBTTM_Gaps::mark( $gaps, array( '', 'AWARE' ) ), array( false, true ), 'mark: the second accepted answer counts' );
same( TBTTM_Gaps::first( $gaps ), array( 'far', 'concerned' ), 'first: the first accepted answer of each gap' );
same( TBTTM_Gaps::first( array( array(), array( 'x' ) ) ), array( '', 'x' ), 'first: an empty gap has none' );

/* ---------- filled, line, blank, keyed ---------- */

same( TBTTM_Gaps::filled( "As [far] as I'm [concerned].", array( 'far', 'concern' ) ), "As far as I'm concern.", 'filled: words put back in order' );
same( TBTTM_Gaps::filled( 'A [one] and [two].', array( 'x' ) ), 'A x and .', 'filled: a missing word is empty' );
same( TBTTM_Gaps::filled( 'A [one].', array( '$1 \\0' ) ), 'A $1 \\0.', 'filled: a word is put in as typed' );

$dialogue = "Pete: Pineapple on pizza is a crime.\nAlita: Really? I [don't|do not] think so.\n\nComplete Alita's answer.";
same( TBTTM_Gaps::line( $dialogue ), "Alita: Really? I [don't|do not] think so.", 'line: the first line that has a gap' );
same( TBTTM_Gaps::line( "One.\nTwo.\n" ), 'Two.', 'line: without a gap, the last line with text' );
same( TBTTM_Gaps::line( '' ), '', 'line: empty text' );
same( TBTTM_Gaps::blank( TBTTM_Gaps::line( $dialogue ) ), 'Alita: Really? I _____ think so.', 'blank: _____ for each gap' );
same( TBTTM_Gaps::keyed( TBTTM_Gaps::line( $dialogue ) ), "Alita: Really? I don't think so.", 'keyed: the first accepted answer put in' );

echo "{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
