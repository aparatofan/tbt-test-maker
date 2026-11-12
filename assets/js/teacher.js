/**
 * TBT Test Maker — the teacher page ([tbt_test_maker]).
 *
 * Two views on one page, as in Translation Drill: the library (painted by the
 * server; this script adds search, Copy link and Delete) and the builder
 * (built here from the test's JSON). Follows docs/prototypes/teacher.html,
 * minus the parts that belong to later releases.
 */
( function () {
	'use strict';

	var CFG = window.TBTTM_TEACHER || {};
	var PARSE = window.TBTTMParse || null;
	var LETTERS = 'abcdef';
	var MAX_Q = CFG.maxQuestions || 200;
	var MAX_OPTIONS = 6;

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	function esc( t ) {
		return String( t == null ? '' : t ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function plural( n, one, many ) {
		return n + ' ' + ( n === 1 ? one : many );
	}

	function listNumbers( nums ) {
		return nums.slice( 0, 8 ).join( ', ' ) + ( nums.length > 8 ? '…' : '' );
	}

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			try {
				document.execCommand( 'copy' ) ? resolve() : reject();
			} catch ( e ) {
				reject( e );
			}
			document.body.removeChild( ta );
		} );
	}

	var noncePromise = null;

	function refreshNonce() {
		if ( ! noncePromise ) {
			noncePromise = fetch( CFG.ajax + ( CFG.ajax.indexOf( '?' ) < 0 ? '?' : '&' ) + 'action=tbttm_nonce', {
				credentials: 'same-origin',
				cache: 'no-store',
			} ).then( function ( res ) {
				return res.json();
			} ).then( function ( data ) {
				if ( ! data || ! data.success ) {
					throw new Error( 'nonce' );
				}
				CFG.nonce = data.data.nonce;
			} ).finally( function () {
				noncePromise = null;
			} );
		}
		return noncePromise;
	}

	/**
	 * One REST call. Rejects with { code, message, data }. An expired nonce
	 * is refreshed and the call retried once.
	 */
	function api( method, path, body, retried ) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'X-WP-Nonce': CFG.nonce || '' },
		};
		if ( body !== undefined ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return fetch( CFG.rest + path, opts ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( res.ok ) {
					return data;
				}
				if ( data && data.code === 'rest_cookie_invalid_nonce' && ! retried ) {
					return refreshNonce().then( function () {
						return api( method, path, body, true );
					} );
				}
				throw {
					code: ( data && data.code ) || 'http_' + res.status,
					message: ( data && data.message ) || 'Something went wrong. Try again.',
					data: ( data && data.data ) || {},
				};
			} );
		}, function () {
			throw { code: 'network', message: 'We couldn’t reach the site. Check your connection and try again.', data: {} };
		} );
	}

	/* ---------------------------------------------------------------------
	 * Library
	 * ------------------------------------------------------------------- */

	/**
	 * The share panel, used by the builder's Share stage and by a library
	 * row's SHARE button: the QR code beside the student link (Translation
	 * Drill), and the shortcode that puts the test in a lesson (Matching Game).
	 *
	 * @param {{slug:string,url:string}} t   Test.
	 * @param {string}                   uid Suffix that keeps the field IDs unique.
	 */
	function shareBodyHtml( t, uid ) {
		var code = '[tbt_test test="' + t.slug + '"]';
		var html = '<div class="tbttm-share">';
		if ( t.url ) {
			html += '<figure class="tbttm-qr" data-tbttm-qr="' + esc( t.url ) + '" aria-label="QR code for the student link"></figure>';
		}
		html += '<div class="tbttm-share__body">';
		if ( t.url ) {
			html += '<div class="tbttm-field"><label class="tbttm-field__label" for="tbttm-share-url-' + uid + '">Student link</label>'
				+ '<div class="tbttm-copyrow"><input class="tbttm-input" id="tbttm-share-url-' + uid + '" type="text" readonly value="' + esc( t.url ) + '">'
				+ '<button type="button" class="tbttm-btn" data-act="copy" data-copy="' + esc( t.url ) + '" data-label="Copy link">Copy link</button>'
				+ '<a class="tbttm-btn tbttm-btn--ghost" href="' + esc( t.url ) + '" target="_blank" rel="noopener">Open</a></div>'
				+ '<span class="tbttm-hint">Students log in to TBT to open it. The link stays the same when you edit the test later.</span></div>';
		} else {
			html += '<p class="tbttm-note">No student page is set yet, so the test has no link. An administrator can choose it in Test Maker Settings. The shortcode below works already.</p>';
		}
		html += '<div class="tbttm-field"><label class="tbttm-field__label" for="tbttm-share-code-' + uid + '">Put it in a lesson</label>'
			+ '<div class="tbttm-copyrow"><input class="tbttm-input" id="tbttm-share-code-' + uid + '" type="text" readonly value="' + esc( code ) + '">'
			+ '<button type="button" class="tbttm-btn" data-act="copy" data-copy="' + esc( code ) + '" data-label="Copy shortcode">Copy shortcode</button></div>'
			+ '<span class="tbttm-hint">Paste it into any page or post, such as a lesson in Divi, and the test appears there.</span></div>';
		return html + '</div></div>';
	}

	/** Draw every QR code inside a container, with the token colours. */
	function drawQrs( container ) {
		container.querySelectorAll( '[data-tbttm-qr]' ).forEach( function ( target ) {
			var url = target.getAttribute( 'data-tbttm-qr' );
			target.innerHTML = '';
			if ( ! url || typeof window.QRCode === 'undefined' ) {
				target.hidden = true;
				return;
			}
			var css = window.getComputedStyle( target );
			var opts = { text: url, width: 176, height: 176, correctLevel: window.QRCode.CorrectLevel.M };
			var dark = css.getPropertyValue( '--tbt-ink' ).trim();
			var light = css.getPropertyValue( '--tbt-surface' ).trim();
			if ( dark && light ) {
				opts.colorDark = dark;
				opts.colorLight = light;
			}
			/* eslint-disable-next-line no-new */
			new window.QRCode( target, opts );
		} );
	}

	/** A Copy button: copy its data-copy, read "Copied" for a moment. */
	function copyButton( b ) {
		copyText( b.dataset.copy ).then( function () {
			b.textContent = 'Copied';
		}, function () {
			var field = b.parentNode.querySelector( 'input' );
			if ( field ) {
				field.select();
			}
		} ).then( function () {
			setTimeout( function () { b.textContent = b.dataset.label; }, 1600 );
		} );
	}

	/**
	 * One library row, in the Matching Game's layout. Keep in step with
	 * TBTTM_Teacher::render_row().
	 */
	function rowHtml( t ) {
		var pub = t.status === 'published';
		return '<article class="tbttm-test-row" data-tbttm-card data-id="' + t.id + '" data-attempts="' + t.attempts + '"'
			+ ' data-title="' + esc( t.title ) + '" data-slug="' + esc( t.slug ) + '" data-url="' + esc( t.url ) + '" data-status="' + esc( t.status ) + '">'
			+ '<div class="tbttm-test-row__main"><h3 class="tbttm-test-row__title">' + esc( t.title ) + '</h3>'
			+ '<p class="tbttm-test-row__meta"><span class="tbttm-badge ' + ( pub ? 'tbttm-badge--published' : 'tbttm-badge--draft' ) + '">' + ( pub ? 'Published' : 'Draft' ) + '</span>'
			+ '<span>' + plural( t.questions, 'question', 'questions' ) + '</span>'
			+ ( pub ? '<span>' + plural( t.attempts, 'attempt', 'attempts' ) + '</span>' : '' )
			+ ( t.edited ? '<span>Edited ' + esc( t.edited ) + '</span>' : '' ) + '</p></div>'
			+ '<div class="tbttm-test-row__actions" data-tbttm-actions>'
			+ ( pub && t.url ? '<a class="tbttm-rowbtn" href="' + esc( t.url ) + '" target="_blank" rel="noopener" aria-label="Open ' + esc( t.title ) + ' in a new tab">Open</a>' : '' )
			+ '<a class="tbttm-rowbtn" href="' + esc( t.edit_url ) + '">Edit</a>'
			+ '<button type="button" class="tbttm-rowbtn" data-tbttm-share aria-expanded="false">Share</button>'
			+ '<button type="button" class="tbttm-rowbtn" data-tbttm-duplicate>Duplicate</button>'
			+ '<button type="button" class="tbttm-rowbtn tbttm-rowbtn--danger" data-tbttm-delete>Delete</button>'
			+ '</div><div class="tbttm-test-row__share" data-tbttm-share-panel hidden></div></article>';
	}

	function Library( root ) {
		var input = root.querySelector( '[data-tbttm-search]' );
		var clearBtn = root.querySelector( '[data-tbttm-search-clear]' );
		var grid = root.querySelector( '[data-tbttm-grid]' );
		var summary = root.querySelector( '[data-tbttm-summary]' );
		var nomatch = root.querySelector( '[data-tbttm-nomatch]' );
		var notice = root.querySelector( '[data-tbttm-notice]' );
		var total = +root.dataset.total || 0;
		var timer = null;
		var seq = 0;

		function runSearch() {
			var term = input ? input.value.trim() : '';
			var mine = ++seq;
			api( 'GET', 'tests' + ( CFG.rest.indexOf( '?' ) < 0 ? '?' : '&' ) + 'search=' + encodeURIComponent( term ) ).then( function ( res ) {
				if ( mine !== seq ) {
					return;
				}
				total = res.total;
				grid.innerHTML = res.tests.map( rowHtml ).join( '' );
				if ( term ) {
					summary.innerHTML = res.tests.length + ' of ' + plural( total, 'test', 'tests' )
						+ ' · <button type="button" class="tbttm-linkbtn" data-tbttm-clear>Clear filters</button>';
					summary.hidden = false;
				} else {
					summary.hidden = true;
				}
				nomatch.hidden = ! ( term && ! res.tests.length );
			}, function ( err ) {
				if ( mine !== seq ) {
					return;
				}
				summary.textContent = err.message;
				summary.hidden = false;
			} );
		}

		function notify( text, isError ) {
			if ( ! notice ) {
				return;
			}
			notice.textContent = text;
			notice.className = 'tbttm-note tbttm-library__notice ' + ( isError ? 'tbttm-note--error' : 'tbttm-note--ok' );
			notice.hidden = false;
		}

		function onInput() {
			if ( clearBtn ) {
				clearBtn.hidden = ! input.value;
			}
			clearTimeout( timer );
			timer = setTimeout( runSearch, 300 );
		}

		function clearSearch() {
			if ( ! input ) {
				return;
			}
			input.value = '';
			clearBtn.hidden = true;
			clearTimeout( timer );
			runSearch();
			input.focus();
		}

		if ( input ) {
			input.addEventListener( 'input', onInput );
			input.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' && input.value ) {
					e.preventDefault();
					clearSearch();
				}
			} );
		}

		// "/" focuses the search unless a text field or a dialog has the keyboard.
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== '/' || ! input || e.ctrlKey || e.metaKey || e.altKey ) {
				return;
			}
			var a = document.activeElement;
			var typing = a && ( /^(INPUT|TEXTAREA|SELECT)$/.test( a.tagName ) || a.isContentEditable );
			if ( typing || document.querySelector( 'dialog[open], [role="dialog"]:not([hidden]), .media-modal' ) ) {
				return;
			}
			e.preventDefault();
			input.focus();
		} );

		root.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( 'button' );
			if ( ! b || ! root.contains( b ) || b.disabled ) {
				return;
			}
			var card = b.closest( '[data-tbttm-card]' );

			if ( b === clearBtn || b.hasAttribute( 'data-tbttm-clear' ) ) {
				clearSearch();
				return;
			}

			if ( b.dataset.act === 'copy' ) {
				copyButton( b );
				return;
			}

			// SHARE: the panel unfolds under the row, built on first open.
			if ( b.hasAttribute( 'data-tbttm-share' ) && card ) {
				var panel = card.querySelector( '[data-tbttm-share-panel]' );
				var opening = panel.hidden;
				if ( opening && ! panel.firstChild ) {
					if ( card.dataset.status === 'published' ) {
						panel.innerHTML = shareBodyHtml( { slug: card.dataset.slug, url: card.dataset.url }, 'r' + card.dataset.id );
						drawQrs( panel );
					} else {
						panel.innerHTML = '<p class="tbttm-muted">This test is a draft, so it has no link yet. Publish it to share it with your students.</p>';
					}
				}
				panel.hidden = ! opening;
				b.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );
				return;
			}

			// DUPLICATE: a draft copy, as in the Matching Game.
			if ( b.hasAttribute( 'data-tbttm-duplicate' ) && card ) {
				b.disabled = true;
				api( 'POST', 'tests/' + card.dataset.id + '/duplicate' ).then( function () {
					notify( 'A draft copy was created.' );
					runSearch();
				}, function ( err ) {
					b.disabled = false;
					notify( err.message, true );
				} );
				return;
			}

			if ( b.hasAttribute( 'data-tbttm-delete' ) && card ) {
				var n = +card.dataset.attempts || 0;
				var actions = card.querySelector( '[data-tbttm-actions]' );
				actions.hidden = true;
				var confirm = document.createElement( 'div' );
				confirm.className = 'tbttm-confirm';
				confirm.setAttribute( 'data-tbttm-confirm', '' );
				confirm.innerHTML = '<span>Delete this test' + ( n ? ' and its ' + plural( n, 'result', 'results' ) : '' ) + '?</span>'
					+ '<button type="button" class="tbttm-rowbtn tbttm-rowbtn--danger-solid" data-tbttm-delete-yes>Delete test</button>'
					+ '<button type="button" class="tbttm-rowbtn" data-tbttm-delete-no>Cancel</button>';
				actions.parentNode.insertBefore( confirm, actions.nextSibling );
				confirm.querySelector( '[data-tbttm-delete-no]' ).focus();
				return;
			}

			if ( b.hasAttribute( 'data-tbttm-delete-no' ) && card ) {
				card.querySelector( '[data-tbttm-confirm]' ).remove();
				card.querySelector( '[data-tbttm-actions]' ).hidden = false;
				return;
			}

			if ( b.hasAttribute( 'data-tbttm-delete-yes' ) && card ) {
				b.disabled = true;
				api( 'DELETE', 'tests/' + card.dataset.id ).then( function () {
					card.remove();
					total--;
					if ( total <= 0 ) {
						// The server decides the empty state: let it paint it.
						window.location.href = CFG.libraryUrl || window.location.href;
					}
				}, function ( err ) {
					b.disabled = false;
					var box = card.querySelector( '[data-tbttm-confirm]' );
					var note = box.querySelector( '.tbttm-note' ) || document.createElement( 'p' );
					note.className = 'tbttm-note tbttm-note--error';
					note.textContent = err.message;
					box.appendChild( note );
				} );
			}
		} );
	}

	/* ---------------------------------------------------------------------
	 * Builder
	 * ------------------------------------------------------------------- */

	var TEMPLATE = [
		[ 'question', 'answer_a', 'answer_b', 'answer_c', 'answer_d', 'correct' ],
		[ 'Tom: This weather is just horrible today.\nJenny: You can say that again!\n\nCo Jenny ma na myśli?', 'Masz całkowitą rację.', 'Nie jestem pewien.', 'Powtórz to głośniej.', '', 'a' ],
		[ 'Które zdanie jest poprawne?', 'I am agree with you.', 'I agree to you.', 'I agree with you.', 'I agree you.', 'c' ],
	];

	/** The first thing that keeps a question from being published, or ''. */
	function problems( q ) {
		if ( ! q.body.trim() ) {
			return 'no question text';
		}
		if ( q.options.filter( function ( o ) { return o.trim(); } ).length < 2 ) {
			return 'needs two answers';
		}
		if ( q.correct === null || ! ( q.options[ q.correct ] || '' ).trim() ) {
			return 'no correct answer';
		}
		return '';
	}

	function Builder( root ) {
		var dataEl = root.querySelector( '[data-tbttm-data]' );
		var src = null;
		try {
			src = JSON.parse( dataEl ? dataEl.textContent : 'null' );
		} catch ( e ) {
			src = null;
		}

		var uid = 1;
		function mkq( q ) {
			return {
				key: uid++,
				id: q && q.id ? q.id : 0,
				body: q && q.body ? q.body : '',
				options: q && q.options ? q.options.slice() : [ '', '', '' ],
				correct: q && q.correct !== undefined && q.correct !== null ? q.correct : null,
			};
		}

		var d = {
			id: src ? src.id : 0,
			title: src ? src.title : '',
			intro: src ? src.intro : '',
			image_id: src ? src.image_id : 0,
			image_url: src ? src.image_url : '',
			status: src ? src.status : 'draft',
			slug: src ? src.slug : '',
			url: src ? src.url : '',
			feedback: src ? src.feedback : 'end',
			bulls: src ? !! src.bulls : true,
			pass_mark: src && src.pass_mark ? String( src.pass_mark ) : '',
			questions: src ? src.questions.map( mkq ) : [],
		};
		var openQ = null;
		var showBad = false;
		var msg = null;
		var imp = null;
		var dirty = false;
		var saving = false;
		var leaving = false;
		var uploading = false;
		var imageError = '';

		function findQ( key ) {
			return d.questions.find( function ( q ) { return q.key === +key; } );
		}

		function touch() {
			dirty = true;
		}

		/* ----- rendering ----- */

		function questionHtml( q, i ) {
			var open = openQ === q.key;
			var p = problems( q );
			var lines = q.body.split( '\n' ).filter( function ( l ) { return l.trim(); } );
			var last = lines.length ? lines[ lines.length - 1 ] : '';
			var filled = q.options.filter( function ( o ) { return o.trim(); } ).length;
			var n = d.questions.length;
			var html = '<div class="tbttm-q' + ( open ? ' is-open' : '' ) + ( showBad && p ? ' is-bad' : '' ) + '" id="tbttm-q-' + q.key + '">'
				+ '<div class="tbttm-q__head"><span class="tbttm-q__n">' + ( i + 1 ) + '</span>'
				+ '<button type="button" class="tbttm-q__sum' + ( last ? '' : ' is-blank' ) + '" data-act="toggle" data-q="' + q.key + '" aria-expanded="' + open + '" data-sum="' + q.key + '">'
				+ ( last ? esc( last ) : 'New question' ) + '</button>'
				+ '<span class="tbttm-q__status' + ( p && showBad ? ' is-miss' : '' ) + '" data-status="' + q.key + '">' + esc( p || plural( filled, 'answer', 'answers' ) ) + '</span>'
				+ '<button type="button" class="tbttm-icon" data-act="up" data-q="' + q.key + '"' + ( i === 0 ? ' disabled' : '' ) + ' aria-label="Move question ' + ( i + 1 ) + ' up">↑</button>'
				+ '<button type="button" class="tbttm-icon" data-act="down" data-q="' + q.key + '"' + ( i === n - 1 ? ' disabled' : '' ) + ' aria-label="Move question ' + ( i + 1 ) + ' down">↓</button>'
				+ '<button type="button" class="tbttm-icon tbttm-icon--del" data-act="delq" data-q="' + q.key + '" aria-label="Remove question ' + ( i + 1 ) + '">×</button>'
				+ '</div>';
			if ( open ) {
				html += '<div class="tbttm-q__body">'
					+ '<div class="tbttm-field"><label class="tbttm-field__label" for="tbttm-qt-' + q.key + '">Question</label>'
					+ '<textarea class="tbttm-input tbttm-input--content" id="tbttm-qt-' + q.key + '" data-q="' + q.key + '" data-f="body" rows="4" maxlength="2000"'
					+ ' placeholder="Tom: This weather is just horrible today.&#10;Jenny: You can say that again!&#10;&#10;Co Jenny ma na myśli?">' + esc( q.body ) + '</textarea>'
					+ '<span class="tbttm-hint">Start a line with a name and a colon, like “Tom:”, and the name shows in bold. Put *stars* around words to show them in italics.</span></div>'
					+ '<div class="tbttm-field"><span class="tbttm-field__label">Answers</span><div class="tbttm-answers">';
				q.options.forEach( function ( o, k ) {
					html += '<div class="tbttm-arow' + ( q.correct === k ? ' is-right' : '' ) + '">'
						+ '<input type="radio" id="tbttm-key-' + q.key + '-' + k + '" name="tbttm-key-' + q.key + '" data-q="' + q.key + '" data-k="' + k + '"' + ( q.correct === k ? ' checked' : '' ) + ' aria-label="Answer ' + LETTERS[ k ] + ' is correct">'
						+ '<span class="tbttm-arow__let">' + LETTERS[ k ] + ')</span>'
						+ '<input class="tbttm-input tbttm-input--content" id="tbttm-qo-' + q.key + '-' + k + '" type="text" maxlength="300" data-q="' + q.key + '" data-o="' + k + '" value="' + esc( o ) + '" aria-label="Answer ' + LETTERS[ k ] + '">'
						+ '<button type="button" class="tbttm-icon tbttm-icon--del" data-act="delo" data-q="' + q.key + '" data-k="' + k + '"' + ( q.options.length <= 2 ? ' disabled' : '' ) + ' aria-label="Remove answer ' + LETTERS[ k ] + '">×</button></div>';
				} );
				html += '</div><div class="tbttm-rowline"><button type="button" class="tbttm-btn tbttm-btn--small tbttm-btn--ghost" data-act="addo" data-q="' + q.key + '"' + ( q.options.length >= MAX_OPTIONS ? ' disabled' : '' ) + '>Add answer</button></div></div>'
					+ '</div>';
			}
			return html + '</div>';
		}

		function importerHtml() {
			var rows = imp.rows;
			var good = rows ? rows.filter( function ( r ) { return ! r.problem; } ) : [];
			var bad = rows ? rows.length - good.length : 0;
			var html = '<div class="tbttm-imp" id="tbttm-imp">'
				+ '<h3 class="tbttm-imp__title">Import questions</h3>'
				+ '<div class="tbttm-field"><span class="tbttm-field__label">1. Use this layout</span>'
				+ '<span class="tbttm-hint">One question per row. Up to six answers (answer_a to answer_f). In “correct”, put the letter of the right answer. For a dialogue, put each line on a new line inside the question cell (Alt+Enter in Excel).</span>'
				+ '<div class="tbttm-tblwrap"><table class="tbttm-table"><thead><tr>'
				+ TEMPLATE[ 0 ].map( function ( c ) { return '<th>' + c + '</th>'; } ).join( '' ) + '</tr></thead><tbody>'
				+ TEMPLATE.slice( 1 ).map( function ( r ) {
					return '<tr>' + r.map( function ( c, i ) { return '<td' + ( i === 0 ? ' class="tbttm-table__q"' : '' ) + '>' + esc( c ) + '</td>'; } ).join( '' ) + '</tr>';
				} ).join( '' )
				+ '</tbody></table></div>'
				+ '<div class="tbttm-rowline"><button type="button" class="tbttm-btn tbttm-btn--small" data-act="imp-tpl">' + ( imp.copied ? 'Template copied' : 'Copy template' ) + '</button>'
				+ '<span class="tbttm-hint">Then paste it into an empty Excel sheet.</span></div></div>'
				+ '<div class="tbttm-field"><label class="tbttm-field__label" for="tbttm-imp-text">2. Paste your rows here</label>'
				+ '<textarea class="tbttm-input" id="tbttm-imp-text" placeholder="Select the rows in Excel, copy, and paste them here.">' + esc( imp.text ) + '</textarea>'
				+ '<div class="tbttm-rowline"><label class="tbttm-btn tbttm-btn--small" for="tbttm-imp-file">Choose a CSV file</label>'
				+ '<input class="tbttm-sr-only" id="tbttm-imp-file" type="file" accept=".csv,.txt,text/csv">'
				+ '<span class="tbttm-hint">Commas, semicolons and tabs all work.</span></div></div>';

			if ( rows ) {
				html += '<div class="tbttm-field"><span class="tbttm-field__label">3. Check the result</span>';
				if ( rows.length ) {
					html += '<span class="tbttm-hint">' + plural( good.length, 'question', 'questions' ) + ' ready'
						+ ( bad ? ', ' + plural( bad, 'row', 'rows' ) + ' with a problem. Rows with a problem are left out.' : '.' ) + '</span>'
						+ '<div class="tbttm-tblwrap"><table class="tbttm-table"><thead><tr><th>Row</th><th>Question</th><th>Answers</th><th>Correct</th><th>Check</th></tr></thead><tbody>'
						+ rows.map( function ( r ) {
							return '<tr' + ( r.problem ? ' class="is-bad"' : '' ) + '><td>' + r.row + '</td><td class="tbttm-table__q">' + esc( r.text ) + '</td>'
								+ '<td>' + r.opts.map( function ( o, k ) { return LETTERS[ k ] + ') ' + esc( o ); } ).join( '\n' ) + '</td>'
								+ '<td>' + ( r.key === null ? '' : LETTERS[ r.key ] ) + '</td>'
								+ '<td>' + ( r.problem ? '<span class="tbttm-prob">' + esc( r.problem ) + '</span>' : '<span class="tbttm-fine">✓</span>' ) + '</td></tr>';
						} ).join( '' )
						+ '</tbody></table></div>';
				} else {
					html += '<p class="tbttm-note tbttm-note--error">No rows found. Paste at least one row with a question, two answers and the correct letter.</p>';
				}
				html += '</div>';
			}

			html += '<div class="tbttm-rowline">'
				+ ( rows && good.length
					? '<button type="button" class="tbttm-btn tbttm-btn--primary" data-act="imp-add">Add ' + plural( good.length, 'question', 'questions' ) + '</button>'
						+ '<button type="button" class="tbttm-btn" data-act="imp-check">Check again</button>'
					: '<button type="button" class="tbttm-btn tbttm-btn--primary" data-act="imp-check">Check rows</button>' )
				+ '<button type="button" class="tbttm-btn tbttm-btn--ghost" data-act="imp-close">Cancel</button></div></div>';
			return html;
		}

		/**
		 * A stage card's heading, the Translation Drill way: the stage number
		 * in the plugin colour, the name in capitals, a supporting line.
		 */
		function stageHead( num, title, sub ) {
			return '<div class="tbttm-stage__head"><h2 class="tbttm-stage__title"><b class="tbttm-stage__num">' + num + '</b>' + esc( title ) + '</h2>'
				+ ( sub ? '<p class="tbttm-stage__sub">' + sub + '</p>' : '' ) + '</div>';
		}

		/**
		 * "Create another test": the TBT generator rule, as in Swipe and
		 * Translation Drill. Starting the next test ends the whole flow rather
		 * than the Share stage, so the button sits centred under the stages,
		 * outside any card, once the test is published and shareable.
		 * Unsaved changes still raise the browser's own "leave site?" prompt.
		 */
		function anotherHtml() {
			if ( d.status !== 'published' || ! CFG.newUrl ) {
				return '';
			}
			return '<div class="tbttm-another"><a class="tbttm-btn tbttm-btn--primary" href="' + esc( CFG.newUrl ) + '">Create another test</a></div>';
		}

		/** The builder's Share stage card. A draft has nothing to share yet. */
		function shareHtml() {
			var html = '<section class="tbttm-stage tbttm-stage--numbered" id="tbttm-share">'
				+ stageHead( 4, 'Share', 'Send students the link or the QR code, or put the test in a lesson page.' );
			if ( d.status !== 'published' || ! d.slug ) {
				return html + '<p class="tbttm-muted">' + ( d.id
					? 'This test is a draft, so it has no link yet. Publish it to share it with your students.'
					: 'Save and publish the test to share it with your students.' ) + '</p></section>';
			}
			return html + shareBodyHtml( d, 'b' ) + '</section>';
		}

		function render( focusId ) {
			var pub = d.status === 'published';
			var n = d.questions.length;
			var html = '<div class="tbttm-rowline">'
				+ '<a class="tbttm-btn tbttm-btn--ghost tbttm-backlink" href="' + esc( CFG.libraryUrl ) + '" data-act="back">← Back to tests</a>'
				+ ( leaving ? '<span class="tbttm-confirm" role="alert"><span>Leave without saving?</span>'
					+ '<button type="button" class="tbttm-btn tbttm-btn--small tbttm-btn--danger" data-act="leave-yes" id="tbttm-leave-yes">Leave</button>'
					+ '<button type="button" class="tbttm-btn tbttm-btn--small tbttm-btn--ghost" data-act="leave-no">Stay</button></span>' : '' )
				+ '</div>';

			html += '<div class="tbttm-stages">';

			// 1. About the test
			html += '<section class="tbttm-stage tbttm-stage--numbered">'
				+ stageHead( 1, 'About the test', 'Name the test and tell students what it practises. The picture is optional.' )
				+ '<div class="tbttm-field"><label class="tbttm-field__label" for="tbttm-f-title">Title</label>'
				+ '<input class="tbttm-input tbttm-input--content" id="tbttm-f-title" type="text" maxlength="200" data-f="title" value="' + esc( d.title ) + '" placeholder="e.g. Expressing Opinions in English"></div>'
				+ '<div class="tbttm-field"><label class="tbttm-field__label" for="tbttm-f-intro">Introduction</label>'
				+ '<textarea class="tbttm-input tbttm-input--content" id="tbttm-f-intro" maxlength="1000" data-f="intro" placeholder="One or two sentences the student reads before starting.">' + esc( d.intro ) + '</textarea></div>'
				+ '<div class="tbttm-field"><span class="tbttm-field__label">Feature image</span>'
				+ ( d.image_url ? '<img class="tbttm-feat" src="' + esc( d.image_url ) + '" alt="Feature image preview">' : '' )
				+ '<div class="tbttm-rowline"><label class="tbttm-btn tbttm-btn--small' + ( uploading ? ' is-busy' : '' ) + '" for="tbttm-img" aria-disabled="' + uploading + '">'
				+ ( uploading ? 'Uploading…' : ( d.image_url ? 'Change image' : 'Choose image' ) ) + '</label>'
				+ '<input class="tbttm-sr-only" id="tbttm-img" type="file" accept="image/jpeg,image/png,image/webp"' + ( uploading ? ' disabled' : '' ) + '>'
				+ ( d.image_url && ! uploading ? '<button type="button" class="tbttm-btn tbttm-btn--small tbttm-btn--danger" data-act="img-del">Remove image</button>' : '' ) + '</div>'
				+ ( imageError ? '<p class="tbttm-note tbttm-note--error" role="alert">' + esc( imageError ) + '</p>' : '' )
				+ '<span class="tbttm-hint">Optional. Shown on the start screen. A JPG, PNG or WebP picture up to 5 MB; big pictures are made smaller automatically.</span></div>'
				+ '</section>';

			// 2. Settings
			html += '<section class="tbttm-stage tbttm-stage--numbered">'
				+ stageHead( 2, 'Settings', 'When students see the answers, what counts as a pass, and the bull pictures.' )
				+ '<div class="tbttm-settings">'
				+ '<div class="tbttm-field"><span class="tbttm-field__label" id="tbttm-fb-label">Feedback</span>'
				+ '<div class="tbttm-seg" role="radiogroup" aria-labelledby="tbttm-fb-label">'
				+ '<label><input type="radio" name="tbttm-fb" id="tbttm-fb-end" value="end"' + ( d.feedback === 'end' ? ' checked' : '' ) + '>At the end</label>'
				+ '<label><input type="radio" name="tbttm-fb" id="tbttm-fb-now" value="now"' + ( d.feedback === 'now' ? ' checked' : '' ) + '>After each question</label></div>'
				+ '<span class="tbttm-hint">' + ( d.feedback === 'end' ? 'Students see what they got right on the result screen.' : 'Students check each answer before moving on.' ) + '</span></div>'
				+ '<div class="tbttm-field"><label class="tbttm-field__label" for="tbttm-f-pass">Pass mark</label>'
				+ '<div class="tbttm-pct"><input class="tbttm-input" id="tbttm-f-pass" type="number" min="1" max="100" step="1" inputmode="numeric" data-f="pass_mark" value="' + esc( d.pass_mark ) + '" placeholder="Not set"><span>%</span></div>'
				+ '<span class="tbttm-hint">Leave empty for a test with a score but no pass or fail.</span></div>'
				+ '<div class="tbttm-field"><span class="tbttm-field__label">Bull pictures</span>'
				+ '<label class="tbttm-switch" for="tbttm-f-bulls"><input id="tbttm-f-bulls" type="checkbox"' + ( d.bulls ? ' checked' : '' ) + '><span>' + ( d.bulls ? 'On' : 'Off' ) + '</span></label>'
				+ '<span class="tbttm-hint">At the start, in the middle, near the end and at the end.</span></div>'
				+ '</div></section>';

			// 3. Questions
			html += '<section class="tbttm-stage tbttm-stage--numbered">'
				+ stageHead( 3, 'Questions', plural( n, 'question', 'questions' ) + '. Click a question to open it. Mark the correct answer with the circle.' )
				+ d.questions.map( questionHtml ).join( '' )
				+ '<div class="tbttm-rowline"><button type="button" class="tbttm-btn" data-act="addq" id="tbttm-addq"' + ( n >= MAX_Q ? ' disabled' : '' ) + '>Add question</button>'
				+ ( imp ? '' : '<button type="button" class="tbttm-btn tbttm-btn--ghost" data-act="imp-open"' + ( n >= MAX_Q ? ' disabled' : '' ) + '>Import questions</button>' )
				+ ( n >= MAX_Q ? '<span class="tbttm-hint">A test holds at most ' + MAX_Q + ' questions.</span>' : '' ) + '</div>'
				+ ( imp ? importerHtml() : '' )
				+ '</section>';

			// Save bar
			html += '<div class="tbttm-savebar">'
				+ ( msg ? '<p class="tbttm-note tbttm-note--' + ( msg.type === 'err' ? 'error' : 'ok' ) + '" role="' + ( msg.type === 'err' ? 'alert' : 'status' ) + '">' + esc( msg.text ) + '</p>' : '' )
				+ '<span class="tbttm-spacer"></span>'
				+ ( pub ? '' : '<button type="button" class="tbttm-btn" data-act="save"' + ( saving ? ' disabled' : '' ) + '>Save draft</button>' )
				+ '<button type="button" class="tbttm-btn tbttm-btn--primary" data-act="publish"' + ( saving ? ' disabled' : '' ) + '>' + ( pub ? 'Save changes' : 'Publish test' ) + '</button>'
				+ '</div>'
				+ shareHtml()
				+ anotherHtml()
				+ '</div>';

			root.innerHTML = html;
			drawQrs( root );
			if ( focusId ) {
				var el = document.getElementById( focusId );
				if ( el ) {
					el.focus( { preventScroll: true } );
				}
			}
		}

		/* ----- saving ----- */

		function payload( status ) {
			return {
				title: d.title,
				intro: d.intro,
				image_id: d.image_id || 0,
				feedback: d.feedback,
				bulls: d.bulls,
				pass_mark: d.pass_mark.trim(),
				status: status,
				questions: d.questions.map( function ( q ) {
					return { id: q.id || 0, body: q.body, options: q.options, correct: q.correct };
				} ),
			};
		}

		function fail( text, numbers ) {
			msg = { type: 'err', text: text };
			if ( numbers && numbers.length ) {
				showBad = true;
				var first = d.questions[ numbers[ 0 ] - 1 ];
				openQ = first ? first.key : openQ;
				render();
				var row = first && document.getElementById( 'tbttm-q-' + first.key );
				if ( row ) {
					row.scrollIntoView( { block: 'center' } );
				}
				return;
			}
			render();
		}

		function commit( status ) {
			if ( saving ) {
				return;
			}
			d.title = d.title.trim();
			if ( ! d.title ) {
				fail( 'Give the test a title.' );
				return;
			}
			if ( status === 'published' ) {
				if ( ! d.questions.length ) {
					fail( 'Add at least one question before publishing.' );
					return;
				}
				var bad = [];
				d.questions.forEach( function ( q, i ) {
					if ( problems( q ) ) {
						bad.push( i + 1 );
					}
				} );
				if ( bad.length ) {
					fail( ( bad.length === 1 ? 'Question ' : 'Questions ' ) + listNumbers( bad ) + ( bad.length === 1 ? ' is' : ' are' ) + ' not finished.', bad );
					return;
				}
			}

			var wasPublished = d.status === 'published';
			saving = true;
			msg = null;
			render();
			api( d.id ? 'PUT' : 'POST', d.id ? 'tests/' + d.id : 'tests', payload( status ) ).then( function ( res ) {
				saving = false;
				var firstSave = ! d.id;
				d.id = res.id;
				d.status = res.status;
				d.slug = res.slug;
				d.url = res.url;
				d.image_id = res.image_id;
				d.image_url = res.image_url;
				d.pass_mark = res.pass_mark ? String( res.pass_mark ) : '';
				// The server answers in the same order; take its question IDs
				// and its cleaned content (published tests lose empty answers).
				res.questions.forEach( function ( sq, i ) {
					var q = d.questions[ i ];
					if ( q ) {
						q.id = sq.id;
						q.body = sq.body;
						q.options = sq.options.slice();
						q.correct = sq.correct;
					}
				} );
				dirty = false;
				showBad = false;
				msg = { type: 'ok', text: res.status === 'published'
					? ( wasPublished ? 'Changes saved.' : 'Published. Share it with your students below.' )
					: 'Draft saved.' };
				if ( firstSave && window.history && window.history.replaceState ) {
					var url = new URL( window.location.href );
					url.searchParams.delete( CFG.newParam );
					url.searchParams.set( CFG.editParam, String( d.id ) );
					window.history.replaceState( null, '', url.toString() );
				}
				render();
				if ( res.status === 'published' && ! wasPublished ) {
					var shareBox = document.getElementById( 'tbttm-share' );
					if ( shareBox ) {
						shareBox.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					}
				}
			}, function ( err ) {
				saving = false;
				fail( err.message, err.data && err.data.questions );
			} );
		}

		/* ----- feature image ----- */

		/**
		 * Make a picture smaller in the browser before it is sent: at most
		 * 1600px on the longest side. Saves the teacher's upload time; the
		 * server checks and resizes again whatever arrives. Resolves with the
		 * original file when the browser cannot do it.
		 */
		function shrink( file ) {
			var max = CFG.imageMaxSide || 1600;
			if ( typeof window.createImageBitmap !== 'function' || ! window.HTMLCanvasElement ) {
				return Promise.resolve( file );
			}
			return window.createImageBitmap( file ).then( function ( bmp ) {
				var scale = Math.min( 1, max / Math.max( bmp.width, bmp.height ) );
				if ( scale === 1 && file.size <= ( CFG.imageMaxBytes || 5242880 ) ) {
					return file;
				}
				var canvas = document.createElement( 'canvas' );
				canvas.width = Math.round( bmp.width * scale );
				canvas.height = Math.round( bmp.height * scale );
				canvas.getContext( '2d' ).drawImage( bmp, 0, 0, canvas.width, canvas.height );
				var type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
				return new Promise( function ( resolve ) {
					canvas.toBlob( function ( blob ) {
						resolve( blob ? new File( [ blob ], file.name.replace( /\.[^.]+$/, '' ) + ( type === 'image/png' ? '.png' : '.jpg' ), { type: type } ) : file );
					}, type, 0.85 );
				} );
			} ).catch( function () {
				return file;
			} );
		}

		function uploadImage( file ) {
			imageError = '';
			if ( ! /^image\/(jpeg|png|webp)$/.test( file.type ) ) {
				imageError = 'Use a JPG, PNG or WebP picture.';
				render( 'tbttm-img' );
				return;
			}
			uploading = true;
			render();
			shrink( file ).then( function ( small ) {
				if ( small.size > ( CFG.imageMaxBytes || 5242880 ) ) {
					throw { message: 'The picture is too big. Choose one under 5 MB.' };
				}
				var form = new FormData();
				form.append( 'image', small, small.name );
				return send( form, false );
			} ).then( function ( res ) {
				uploading = false;
				d.image_id = res.id;
				d.image_url = res.url;
				touch();
				render( 'tbttm-img' );
			}, function ( err ) {
				uploading = false;
				imageError = ( err && err.message ) || 'The picture could not be uploaded. Try again.';
				render( 'tbttm-img' );
			} );
		}

		/** POST a form to /images, refreshing an expired nonce once. */
		function send( form, retried ) {
			return fetch( CFG.rest + 'images', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': CFG.nonce || '' },
				body: form,
			} ).then( function ( res ) {
				return res.json().catch( function () { return {}; } ).then( function ( data ) {
					if ( res.ok ) {
						return data;
					}
					if ( data && data.code === 'rest_cookie_invalid_nonce' && ! retried ) {
						return refreshNonce().then( function () { return send( form, true ); } );
					}
					throw { message: ( data && data.message ) || ( res.status === 413 ? 'The picture is too big. Choose one under 5 MB.' : 'The picture could not be uploaded. Try again.' ) };
				} );
			}, function () {
				throw { message: 'We couldn’t reach the site. Check your connection and try again.' };
			} );
		}

		/* ----- import ----- */

		/**
		 * A CSV file's text. UTF-8 first; a file that is not valid UTF-8 is
		 * read as Windows-1250, the encoding Polish Excel uses for plain CSV.
		 */
		function readFile( file ) {
			return file.arrayBuffer().then( function ( buf ) {
				try {
					return new TextDecoder( 'utf-8', { fatal: true } ).decode( buf );
				} catch ( e ) {
					try {
						return new TextDecoder( 'windows-1250' ).decode( buf );
					} catch ( e2 ) {
						return new TextDecoder( 'utf-8' ).decode( buf );
					}
				}
			} );
		}

		function checkRows() {
			imp.rows = PARSE ? PARSE.readRows( imp.text ) : [];
			render();
		}

		function addImported() {
			var good = imp.rows.filter( function ( r ) { return ! r.problem; } );
			var room = Math.max( 0, MAX_Q - d.questions.length );
			var add = good.slice( 0, room );
			var left = good.length - add.length;
			add.forEach( function ( r ) {
				d.questions.push( mkq( { body: r.text, options: r.opts, correct: r.key } ) );
			} );
			imp = null;
			touch();
			msg = { type: 'ok', text: plural( add.length, 'question', 'questions' ) + ' added. Save to keep them.'
				+ ( left ? ' ' + plural( left, 'row was', 'rows were' ) + ' left out: a test holds at most ' + MAX_Q + ' questions.' : '' ) };
			render();
		}

		/* ----- events ----- */

		root.addEventListener( 'input', function ( e ) {
			var t = e.target;
			if ( t.id === 'tbttm-imp-text' ) {
				if ( imp ) {
					imp.text = t.value;
				}
				return;
			}
			if ( t.dataset.o !== undefined ) {
				var qo = findQ( t.dataset.q );
				qo.options[ +t.dataset.o ] = t.value;
				refreshHead( qo );
				touch();
				return;
			}
			if ( t.dataset.q && t.dataset.f === 'body' ) {
				var qb = findQ( t.dataset.q );
				qb.body = t.value;
				refreshHead( qb );
				touch();
				return;
			}
			if ( t.dataset.f ) {
				d[ t.dataset.f ] = t.value;
				touch();
			}
		} );

		/** Update a question's closed-row summary and status while typing. */
		function refreshHead( q ) {
			var lines = q.body.split( '\n' ).filter( function ( l ) { return l.trim(); } );
			var last = lines.length ? lines[ lines.length - 1 ] : '';
			var sum = root.querySelector( '[data-sum="' + q.key + '"]' );
			if ( sum ) {
				sum.textContent = last || 'New question';
				sum.classList.toggle( 'is-blank', ! last );
			}
			var st = root.querySelector( '[data-status="' + q.key + '"]' );
			if ( st ) {
				var p = problems( q );
				st.textContent = p || plural( q.options.filter( function ( o ) { return o.trim(); } ).length, 'answer', 'answers' );
				st.classList.toggle( 'is-miss', !! ( p && showBad ) );
			}
		}

		root.addEventListener( 'change', function ( e ) {
			var t = e.target;
			if ( t.name === 'tbttm-fb' ) {
				d.feedback = t.value;
				touch();
				render( t.id );
				return;
			}
			if ( t.id === 'tbttm-f-bulls' ) {
				d.bulls = t.checked;
				touch();
				render( t.id );
				return;
			}
			if ( t.id === 'tbttm-img' && t.files && t.files[ 0 ] ) {
				uploadImage( t.files[ 0 ] );
				t.value = '';
				return;
			}
			if ( t.id === 'tbttm-imp-file' && t.files && t.files[ 0 ] ) {
				readFile( t.files[ 0 ] ).then( function ( text ) {
					if ( ! imp ) {
						return;
					}
					imp.text = text;
					checkRows();
				} );
				return;
			}
			if ( t.type === 'radio' && t.dataset.k !== undefined ) {
				findQ( t.dataset.q ).correct = +t.dataset.k;
				touch();
				render( t.id );
			}
		} );

		root.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( 'button, a[data-act]' );
			if ( ! b || ! root.contains( b ) || ! b.dataset.act || b.disabled ) {
				return;
			}
			var act = b.dataset.act;
			var q = b.dataset.q ? findQ( b.dataset.q ) : null;
			var qi = q ? d.questions.indexOf( q ) : -1;

			switch ( act ) {
				case 'back':
					if ( dirty ) {
						e.preventDefault();
						leaving = true;
						render( 'tbttm-leave-yes' );
					}
					return;
				case 'leave-yes':
					dirty = false;
					window.location.href = CFG.libraryUrl;
					return;
				case 'leave-no':
					leaving = false;
					render();
					return;
				case 'toggle':
					openQ = openQ === q.key ? null : q.key;
					render( 'tbttm-qt-' + q.key );
					return;
				case 'addq': {
					if ( d.questions.length >= MAX_Q ) {
						return;
					}
					var nq = mkq( null );
					d.questions.push( nq );
					openQ = nq.key;
					msg = null;
					touch();
					render( 'tbttm-qt-' + nq.key );
					return;
				}
				case 'delq':
					d.questions.splice( qi, 1 );
					touch();
					render();
					return;
				case 'up':
					d.questions.splice( qi - 1, 0, d.questions.splice( qi, 1 )[ 0 ] );
					touch();
					render();
					return;
				case 'down':
					d.questions.splice( qi + 1, 0, d.questions.splice( qi, 1 )[ 0 ] );
					touch();
					render();
					return;
				case 'addo':
					if ( q.options.length < MAX_OPTIONS ) {
						q.options.push( '' );
						touch();
						render( 'tbttm-qo-' + q.key + '-' + ( q.options.length - 1 ) );
					}
					return;
				case 'delo': {
					var k = +b.dataset.k;
					q.options.splice( k, 1 );
					q.correct = q.correct === k ? null : ( q.correct !== null && q.correct > k ? q.correct - 1 : q.correct );
					touch();
					render();
					return;
				}
				case 'img-del':
					d.image_id = 0;
					d.image_url = '';
					imageError = '';
					touch();
					render( 'tbttm-img' );
					return;
				case 'imp-open':
					imp = { text: '', rows: null, copied: false };
					render( 'tbttm-imp-text' );
					return;
				case 'imp-close':
					imp = null;
					render();
					return;
				case 'imp-check':
					checkRows();
					return;
				case 'imp-tpl': {
					var tsv = TEMPLATE.map( function ( r ) {
						return r.map( function ( c ) {
							return /[\n\t"]/.test( c ) ? '"' + c.replace( /"/g, '""' ) + '"' : c;
						} ).join( '\t' );
					} ).join( '\n' );
					copyText( tsv ).then( function () {
						if ( imp ) {
							imp.copied = true;
							render();
						}
					}, function () {} );
					return;
				}
				case 'copy':
					copyButton( b );
					return;
				case 'imp-add':
					addImported();
					return;
				case 'save':
					commit( 'draft' );
					return;
				case 'publish':
					commit( 'published' );
					return;
			}
		} );

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );

		render();
	}

	function init() {
		document.querySelectorAll( '[data-tbttm-library]' ).forEach( Library );
		document.querySelectorAll( '[data-tbttm-builder]' ).forEach( Builder );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
