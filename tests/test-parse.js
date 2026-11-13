/**
 * Standalone tests for assets/js/parse.js, the question importer's reader.
 *
 * The importer is how a teacher brings a whole test across from Excel, so
 * these are the cases that would otherwise be found one bad import at a time:
 * the delimiter, quoted cells with line breaks, the BOM and the header row,
 * the three ways of naming the correct answer, and each problem message.
 *
 * Run: node tests/test-parse.js
 */
'use strict';

const path = require( 'path' );
const { parseTable, readRows } = require( path.join( __dirname, '..', 'assets', 'js', 'parse.js' ) );

let pass = 0;
let fail = 0;
function same( actual, expected, label ) {
	const a = JSON.stringify( actual );
	const e = JSON.stringify( expected );
	if ( a === e ) {
		pass++;
	} else {
		fail++;
		console.log( `FAIL: ${ label }\n  expected: ${ e }\n  actual:   ${ a }` );
	}
}

/* ---------- delimiter detection ---------- */

same( parseTable( 'q\ta\tb\tc' ), [ [ 'q', 'a', 'b', 'c' ] ], 'tab-separated' );
same( parseTable( 'q;a;b;c' ), [ [ 'q', 'a', 'b', 'c' ] ], 'semicolon-separated (Polish Excel)' );
same( parseTable( 'q,a,b,c' ), [ [ 'q', 'a', 'b', 'c' ] ], 'comma-separated' );
same( parseTable( 'q\ta,b;c\td' ), [ [ 'q', 'a,b;c', 'd' ] ], 'tab wins over commas and semicolons' );
same( parseTable( 'Hi, there;a;b;c' ), [ [ 'Hi, there', 'a', 'b', 'c' ] ], 'semicolon wins when it outnumbers commas' );
same( parseTable( 'a,b,c;d' ), [ [ 'a', 'b', 'c;d' ] ], 'comma wins when commas outnumber semicolons' );

/* ---------- quoting ---------- */

same( parseTable( '"a, b",c' ), [ [ 'a, b', 'c' ] ], 'quoted cell holds the delimiter' );
same( parseTable( '"He said ""hi""",x' ), [ [ 'He said "hi"', 'x' ] ], 'doubled quotes become one quote' );
same(
	parseTable( '"Tom: Hello.\nJenny: Hi!\n\nWho speaks first?"\tTom\tJenny\ta' ),
	[ [ 'Tom: Hello.\nJenny: Hi!\n\nWho speaks first?', 'Tom', 'Jenny', 'a' ] ],
	'line breaks inside a quoted cell stay in the cell'
);
same( parseTable( 'a,b\r\nc,d\r\n' ), [ [ 'a', 'b' ], [ 'c', 'd' ] ], 'Windows line endings' );
same( parseTable( 'a,b\n\n,\nc,d' ), [ [ 'a', 'b' ], [ 'c', 'd' ] ], 'empty rows are dropped' );

/* ---------- BOM and header ---------- */

same( parseTable( '﻿q,a' ), [ [ 'q', 'a' ] ], 'BOM stripped' );
{
	const rows = readRows( '﻿question;answer_a;answer_b;correct\nWho?;Me;You;a' );
	same( rows.length, 1, 'header row skipped (after a BOM)' );
	same( rows[ 0 ].row, 2, 'row numbers count the header line' );
}
{
	const rows = readRows( 'Who?\tMe\tYou\ta' );
	same( rows[ 0 ].row, 1, 'no header: first row is row 1' );
}

/* ---------- the correct answer ---------- */

same( readRows( 'Q\tA\tB\tC\tb' )[ 0 ].key, 1, 'correct as a letter' );
same( readRows( 'Q\tA\tB\tC\tC' )[ 0 ].key, 2, 'correct as an uppercase letter' );
same( readRows( 'Q\tA\tB\tC\t3' )[ 0 ].key, 2, 'correct as a number' );
same( readRows( 'Q\tRed\tGreen\tBlue\tgreen' )[ 0 ].key, 1, 'correct as the answer text' );
{
	const r = readRows( 'Q\tA\tB\tC\tD\tE\tF\tf' )[ 0 ];
	same( [ r.opts.length, r.key, r.problem ], [ 6, 5, '' ], 'six answers, correct f' );
}
{
	// answer_b left empty: "c" still means the third column.
	const r = readRows( 'Q\tA\t\tC\tD\tc' )[ 0 ];
	same( [ r.opts, r.key ], [ [ 'A', 'C', 'D' ], 1 ], 'a gap in the answers does not shift the letter' );
}
{
	const r = readRows( '"Tom: This weather is horrible.\nJenny: You can say that again!\n\nCo Jenny ma na myśli?"\tMasz rację.\tNie wiem.\tPowtórz.\t\ta' )[ 0 ];
	same( r.text, 'Tom: This weather is horrible.\nJenny: You can say that again!\n\nCo Jenny ma na myśli?', 'dialogue keeps its lines' );
	same( [ r.opts.length, r.key, r.problem ], [ 3, 0, '' ], 'template row with an empty answer_d' );
}

/* ---------- problems ---------- */

same( readRows( '\tA\tB\ta' )[ 0 ].problem, 'No question text', 'problem: no question text' );
same( readRows( 'Q\tA\t\ta' )[ 0 ].problem, 'Needs at least two answers', 'problem: one answer' );
same( readRows( 'Q\tA\tB\t' )[ 0 ].problem, 'Correct answer missing', 'problem: correct empty' );
same( readRows( 'Q\tA\tB\tzebra' )[ 0 ].problem, 'Correct answer missing', 'problem: correct text matches no answer' );
same( readRows( 'Q\tA\tB\t\td' )[ 0 ].problem, 'Correct letter has no answer', 'problem: letter points at an empty answer' );
same( readRows( 'Q\tA\tB\t5' )[ 0 ].problem, 'Correct letter has no answer', 'problem: number past the answers' );
same( readRows( 'Q\tA\tB\ta' )[ 0 ].key !== null, true, 'a good row has a key' );

/* ---------- a mixed paste ---------- */
{
	const rows = readRows( [
		'question\tanswer_a\tanswer_b\tanswer_c\tcorrect',
		'One?\tx\ty\tz\tb',
		'\tx\ty\tz\ta',
		'Three?\tx\ty\tz\t2',
	].join( '\n' ) );
	same( rows.map( ( r ) => r.problem ), [ '', 'No question text', '' ], 'mixed paste: each row checked on its own' );
	same( rows.map( ( r ) => r.row ), [ 2, 3, 4 ], 'mixed paste: Excel row numbers' );
}

/* ---------- fill in the gaps ---------- */

const { gaps, gapIssue } = require( '../assets/js/parse.js' );

same( gaps( 'As [far] as I’m [concerned].' ), [ [ 'far' ], [ 'concerned' ] ], 'gaps: several, in order' );
same( gaps( 'I [don’t | do  not||] think so.' ), [ [ 'don’t', 'do not' ] ], 'gaps: accepted answers trimmed, empty ones dropped' );
same( gaps( 'An [] and [ | ].' ), [ [], [] ], 'gaps: an empty gap is still a gap' );
same( gaps( 'Open [bracket and [[inner]].' ), [ [ 'inner' ] ], 'gaps: brackets do not nest, an open one is no gap' );
same( gaps( '[a|b|c|d|e|f|g|h]' )[ 0 ].length, 6, 'gaps: at most six accepted answers' );
same(
	[ '', 'No gap.', 'One [] here.', 'One [two] and [three.', '[a] '.repeat( 61 ), '[a] '.repeat( 60 ), 'I see your [point].' ].map( gapIssue ),
	[ 'empty', 'none', 'hollow', 'open', 'many', '', '' ],
	'gapIssue: the validator\'s problems, in its order'
);

{
	const r = readRows( 'I couldn’t agree [more].' )[ 0 ];
	same( r, { row: 1, type: 'gap', text: 'I couldn’t agree [more].', gaps: [ [ 'more' ] ], problem: '' }, 'gap row: a sentence with a gap' );
}
{
	const r = readRows( 'If you [ask] me\t\t\t\t\t' )[ 0 ];
	same( [ r.type, r.text, r.gaps, r.problem ], [ 'gap', 'If you [ask] me', [ [ 'ask' ] ], '' ], 'gap row: trailing empty cells' );
	same( readRows( 'question\tanswer_a\tanswer_b\tcorrect\nIf you [ask] me\t\t\t' ).map( ( x ) => [ x.row, x.type ] ), [ [ 2, 'gap' ] ], 'gap row: under the template header' );
	same( readRows( 'Really? I [don’t|do not] think so.;;;' )[ 0 ].type, 'gap', 'gap row: trailing empty cells in a CSV' );
}
{
	const para = 'Last night Kat and Sven talked. In Kat’s [opinion], the office is a thing of the [past].\nSven did not agree [with] her.\n\n“I see your [point],” he said.';
	const rows = readRows( '"' + para + '"\t\t\n' );
	same( rows.length, 1, 'paragraph: one quoted cell with line breaks is one question' );
	same( [ rows[ 0 ].type, rows[ 0 ].text, rows[ 0 ].gaps.length, rows[ 0 ].problem ], [ 'gap', para, 4, '' ], 'paragraph: its text and its four gaps' );
	const ten = '"' + Array.from( { length: 10 }, ( _, k ) => 'Word [w' + k + '], and more.' ).join( '\n' ) + '"';
	same( readRows( ten ).map( ( x ) => [ x.type, x.gaps.length ] ), [ [ 'gap', 10 ] ], 'paragraph: ten gaps in one cell, commas and all' );
}
{
	// Five sentences pasted as plain lines: commas are not columns here.
	const rows = readRows( [
		'If you [ask] me, the old logo was better.',
		'I see your [point], but people need to meet face to [face].',
		'In the end they agreed to [disagree|differ].',
		'As [far] as I’m [concerned], the film was far too long, really.',
		'Really? I [don’t|do not] think so; not at all.',
	].join( '\n' ) );
	same( rows.map( ( x ) => x.type ), [ 'gap', 'gap', 'gap', 'gap', 'gap' ], 'plain lines: five sentences are five gap questions' );
	same( rows.map( ( x ) => x.gaps.length ), [ 1, 2, 1, 2, 1 ], 'plain lines: the right number of gaps each' );
	same( rows[ 1 ].text, 'I see your [point], but people need to meet face to [face].', 'plain lines: a sentence keeps its commas' );
	same( rows.map( ( x ) => x.problem ), [ '', '', '', '', '' ], 'plain lines: no problems' );
}
{
	const rows = readRows( [
		'question\tanswer_a\tanswer_b\tanswer_c\tcorrect',
		'One?\tx\ty\tz\tb',
		'I couldn’t agree [more].\t\t\t\t',
		'Choose [the] right one\tx\ty\t\ta',
		'As [far] as I’m [concerned].',
	].join( '\n' ) );
	same( rows.map( ( x ) => x.type ), [ 'choice', 'gap', 'choice', 'gap' ], 'mixed paste: gap rows and multiple choice rows in one paste' );
	same( rows.map( ( x ) => x.problem ), [ '', '', '', '' ], 'mixed paste: all fine' );
	same( rows.map( ( x ) => x.row ), [ 2, 3, 4, 5 ], 'mixed paste: Excel row numbers' );
}
{
	// Brackets and answers: the answers decide, the brackets stay text.
	const r = readRows( 'Which word fits? I [___] agree.\tmore\tless\ta' )[ 0 ];
	same( [ r.type, r.text, r.opts, r.key, r.problem ], [ 'choice', 'Which word fits? I [___] agree.', [ 'more', 'less' ], 0, '' ], 'brackets and answers: a multiple choice row, exactly as before' );
	same( 'gaps' in r, false, 'brackets and answers: no gaps read from it' );
	const half = readRows( 'I [more] agree.\tonly one\t' )[ 0 ];
	same( [ half.type, half.problem ], [ 'choice', 'Needs at least two answers' ], 'brackets and one answer: still a multiple choice row, with its problem' );
	const csv = readRows( 'Pick [one],yes,no,b' )[ 0 ];
	same( [ csv.type, csv.text, csv.key ], [ 'choice', 'Pick [one]', 1 ], 'brackets and answers in a CSV: a complete multiple choice row stays one' );
}
{
	same( readRows( 'An empty [] gap.' )[ 0 ], { row: 1, type: 'gap', text: 'An empty [] gap.', gaps: [ [] ], problem: 'A gap is empty' }, 'problem: an empty gap' );
	same( readRows( 'Only [ | ] bars.' )[ 0 ].problem, 'A gap is empty', 'problem: a gap with only separators' );
	same( readRows( 'One [two] and [three.' )[ 0 ].problem, 'A bracket is not closed', 'problem: a bracket is not closed' );
	same( readRows( '[a] '.repeat( 61 ) )[ 0 ].problem, 'Too many gaps', 'problem: too many gaps' );
	const none = readRows( 'No gap in this sentence.' )[ 0 ];
	same( [ none.type, none.problem ], [ 'choice', 'Needs at least two answers' ], 'a row with no gap and no answers is read as before' );
}
same( readRows( 'One?\tx\ty\tb' )[ 0 ].type, 'choice', 'every other row gets type choice' );

console.log( `${ pass } passed, ${ fail } failed` );
process.exit( fail ? 1 : 0 );
