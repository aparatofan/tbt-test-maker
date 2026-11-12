/**
 * TBT Test Maker — the question importer's reader.
 *
 * parseTable() and readRows() from the approved prototype. Loads in the
 * browser (window.TBTTMParse) and under Node (module.exports), so
 * tests/test-parse.js runs it without a browser.
 *
 * Layout: one question per row; columns question, answer_a … answer_f,
 * correct. `correct` is a letter a–f, a number 1–6, or the text of the right
 * answer.
 */
( function ( root ) {
	'use strict';

	var LETTERS = 'abcdef';
	var MAX_OPTIONS = 6;

	/**
	 * Read comma-, semicolon- or tab-separated text into rows of cells.
	 *
	 * The delimiter comes from the first line: a tab wins; then a semicolon
	 * if it outnumbers commas (Polish Excel exports semicolons). Quoted cells
	 * may hold the delimiter, doubled quotes and line breaks. A BOM is
	 * stripped. Rows with nothing in them are dropped.
	 *
	 * @param {string} text
	 * @return {string[][]}
	 */
	function parseTable( text ) {
		text = String( text || '' ).replace( /^﻿/, '' ).replace( /\r\n?/g, '\n' );
		var head = firstLine( text );
		var count = function ( ch ) { return head.split( ch ).length - 1; };
		var d = count( '\t' ) ? '\t' : ( count( ';' ) > count( ',' ) ? ';' : ',' );

		var out = [];
		var row = [];
		var cell = '';
		var quoted = false;
		for ( var i = 0; i < text.length; i++ ) {
			var ch = text[ i ];
			if ( quoted ) {
				if ( ch === '"' ) {
					if ( text[ i + 1 ] === '"' ) {
						cell += '"';
						i++;
					} else {
						quoted = false;
					}
				} else {
					cell += ch;
				}
			} else if ( ch === '"' && cell === '' ) {
				quoted = true;
			} else if ( ch === d ) {
				row.push( cell );
				cell = '';
			} else if ( ch === '\n' ) {
				row.push( cell );
				out.push( row );
				row = [];
				cell = '';
			} else {
				cell += ch;
			}
		}
		if ( cell !== '' || row.length ) {
			row.push( cell );
			out.push( row );
		}
		return out.filter( function ( r ) {
			return r.some( function ( c ) { return c.trim() !== ''; } );
		} );
	}

	/**
	 * The first row of the text, up to the first line break outside quotes.
	 * The prototype took the first physical line, which is wrong when the
	 * first row is a dialogue: a quoted cell with line breaks holds no
	 * delimiter on its first line, and the tab was never seen.
	 *
	 * @param {string} text Normalised text.
	 * @return {string}
	 */
	function firstLine( text ) {
		var quoted = false;
		for ( var i = 0; i < text.length; i++ ) {
			if ( text[ i ] === '"' ) {
				quoted = ! quoted;
			} else if ( text[ i ] === '\n' && ! quoted ) {
				return text.slice( 0, i );
			}
		}
		return text;
	}

	/**
	 * Turn table text into checked question rows.
	 *
	 * A header row whose first cell is "question" is skipped. Each row gives
	 * { row, text, opts, key, problem }: `row` is the line number the teacher
	 * sees in Excel, `key` the zero-based index of the right answer in `opts`
	 * (or null), and `problem` '' or the first thing wrong.
	 *
	 * The answer letters follow the columns, so a gap in the answers (answer_b
	 * left empty) never shifts which answer the letter in `correct` means.
	 *
	 * @param {string} text
	 * @return {Array<{row:number,text:string,opts:string[],key:(number|null),problem:string}>}
	 */
	function readRows( text ) {
		var rows = parseTable( text );
		var offset = 1;
		if ( rows.length && rows[ 0 ][ 0 ].trim().toLowerCase() === 'question' ) {
			rows = rows.slice( 1 );
			offset = 2;
		}

		return rows.map( function ( r, i ) {
			var cells = r.map( function ( c ) { return c.trim(); } );
			var qtext = cells[ 0 ] || '';
			var c = cells.length > 1 ? cells[ cells.length - 1 ].toLowerCase() : '';
			var slots = cells.slice( 1, cells.length - 1 ).slice( 0, MAX_OPTIONS );

			// Position in the columns (a–f) of the answer `correct` names.
			var slot = null;
			if ( /^[a-f]$/.test( c ) ) {
				slot = LETTERS.indexOf( c );
			} else if ( /^[1-6]$/.test( c ) ) {
				slot = +c - 1;
			} else if ( c ) {
				var m = slots.findIndex( function ( o ) { return o !== '' && o.toLowerCase() === c; } );
				if ( m >= 0 ) {
					slot = m;
				}
			}

			var opts = [];
			var key = null;
			slots.forEach( function ( o, k ) {
				if ( o === '' ) {
					return;
				}
				if ( k === slot ) {
					key = opts.length;
				}
				opts.push( o );
			} );

			var problem = '';
			if ( ! qtext ) {
				problem = 'No question text';
			} else if ( opts.length < 2 ) {
				problem = 'Needs at least two answers';
			} else if ( slot === null ) {
				problem = 'Correct answer missing';
			} else if ( key === null ) {
				problem = 'Correct letter has no answer';
			}

			return { row: i + offset, text: qtext, opts: opts, key: key, problem: problem };
		} );
	}

	var api = { parseTable: parseTable, readRows: readRows, LETTERS: LETTERS };

	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.TBTTMParse = api;
	}
}( typeof self !== 'undefined' ? self : this ) );
