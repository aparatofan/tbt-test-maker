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

console.log( `${ pass } passed, ${ fail } failed` );
process.exit( fail ? 1 : 0 );
