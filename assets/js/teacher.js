/**
 * TBT Test Maker — the teacher page ([tbt_test_maker]).
 *
 * Three views on one page: the library (painted by the server; this script
 * adds search, Copy link and Delete), the builder (built here from the test's
 * JSON) and a test's Results (built here from GET /tests/{id}/results).
 * Follows docs/prototypes/teacher.html, minus the parts that belong to later
 * releases.
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

	/** Case- and accent-insensitive form of a string, for searching names. */
	function fold( text ) {
		var t = String( text == null ? '' : text );
		if ( t.normalize ) {
			t = t.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
		}
		return t.replace( /ł/g, 'l' ).replace( /Ł/g, 'L' ).toLowerCase().trim();
	}

	/** "14 questions · B2 Tuesday group · Logged-in students · Pass mark 70%", as spans or as one line. */
	function factsOf( t ) {
		var facts = [ plural( t.questions, 'question', 'questions' ) ];
		if ( CFG.notes ) {
			facts.push( t.class_title || 'Not in a class' );
		}
		facts.push( t.access === 'open' ? 'Anyone with the link' : 'Logged-in students' );
		if ( t.pass_mark ) {
			facts.push( 'Pass mark ' + t.pass_mark + '%' );
		}
		return facts;
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

	/**
	 * Fetch a PDF from a REST route. Resolves with { blob, name }; rejects
	 * on anything that is not a PDF. An expired nonce is refreshed once.
	 */
	function fetchPdf( url, retried ) {
		return fetch( url, { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': CFG.nonce || '' } } ).then( function ( res ) {
			var type = res.headers.get( 'Content-Type' ) || '';
			if ( res.ok && type.indexOf( 'application/pdf' ) === 0 ) {
				var match = /filename="?([^";]+)"?/i.exec( res.headers.get( 'Content-Disposition' ) || '' );
				return res.blob().then( function ( blob ) {
					return { blob: blob, name: match ? match[ 1 ] : 'certificate.pdf' };
				} );
			}
			return res.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( data && data.code === 'rest_cookie_invalid_nonce' && ! retried ) {
					return refreshNonce().then( function () {
						return fetchPdf( url, true );
					} );
				}
				throw new Error( 'certificate' );
			} );
		} );
	}

	/** Hand a file to the browser as a download. */
	function saveFile( blob, name ) {
		var url = window.URL.createObjectURL( blob );
		var a = document.createElement( 'a' );
		a.href = url;
		a.download = name;
		a.style.display = 'none';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		setTimeout( function () { window.URL.revokeObjectURL( url ); }, 60000 );
	}

	/* ---------------------------------------------------------------------
	 * Library
	 * ------------------------------------------------------------------- */

	/**
	 * The share panel, used by the builder's Share stage and by a library
	 * row's SHARE button: the QR code beside the student link (Translation
	 * Drill), and the shortcode that puts the test in a lesson (Matching Game).
	 *
	 * @param {{slug:string,url:string,access:string}} t Test.
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
				+ ( t.access === 'open' ? '<span class="tbttm-hint">Anyone with this link can take the test.</span>' : '' )
				+ '<span class="tbttm-hint">' + ( t.access === 'open' ? '' : 'Students log in to TBT to open it. ' ) + 'The link stays the same when you edit the test later.</span></div>';
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
		// Results exist once someone has finished the test.
		var results = t.attempts > 0 && t.results_url ? t.results_url : '';
		return '<article class="tbttm-test-row" data-tbttm-card data-id="' + t.id + '" data-attempts="' + t.attempts + '"'
			+ ' data-title="' + esc( t.title ) + '" data-slug="' + esc( t.slug ) + '" data-url="' + esc( t.url ) + '" data-status="' + esc( t.status ) + '" data-access="' + esc( t.access ) + '">'
			+ '<div class="tbttm-test-row__main"><h3 class="tbttm-test-row__title">' + esc( t.title ) + '</h3>'
			+ '<p class="tbttm-test-row__meta"><span class="tbttm-badge ' + ( pub ? 'tbttm-badge--published' : 'tbttm-badge--draft' ) + '">' + ( pub ? 'Published' : 'Draft' ) + '</span>'
			+ factsOf( t ).map( function ( f ) { return '<span>' + esc( f ) + '</span>'; } ).join( '' )
			+ ( pub ? '<span>' + ( results
				? '<a class="tbttm-test-row__count" href="' + esc( results ) + '" data-tbttm-results-link data-href="' + esc( results ) + '">' + plural( t.attempts, 'attempt', 'attempts' ) + '</a>'
				: plural( t.attempts, 'attempt', 'attempts' ) ) + '</span>' : '' )
			+ ( t.edited ? '<span>Edited ' + esc( t.edited ) + '</span>' : '' ) + '</p></div>'
			+ '<div class="tbttm-test-row__actions" data-tbttm-actions>'
			+ ( pub && t.url ? '<a class="tbttm-rowbtn" href="' + esc( t.url ) + '" target="_blank" rel="noopener" aria-label="Open ' + esc( t.title ) + ' in a new tab">Open</a>' : '' )
			+ '<a class="tbttm-rowbtn" href="' + esc( t.edit_url ) + '">Edit</a>'
			+ ( results ? '<a class="tbttm-rowbtn" href="' + esc( results ) + '" data-tbttm-results-link data-href="' + esc( results ) + '">Results</a>' : '' )
			+ '<button type="button" class="tbttm-rowbtn" data-tbttm-share aria-expanded="false">Share</button>'
			+ '<button type="button" class="tbttm-rowbtn" data-tbttm-duplicate>Duplicate</button>'
			+ '<button type="button" class="tbttm-rowbtn tbttm-rowbtn--danger" data-tbttm-delete>Delete</button>'
			+ '</div><div class="tbttm-test-row__share" data-tbttm-share-panel hidden></div></article>';
	}

	function Library( root ) {
		var input = root.querySelector( '[data-tbttm-search]' );
		var classSelect = root.querySelector( '[data-tbttm-class]' );
		var clearBtn = root.querySelector( '[data-tbttm-search-clear]' );
		var grid = root.querySelector( '[data-tbttm-grid]' );
		var summary = root.querySelector( '[data-tbttm-summary]' );
		var nomatch = root.querySelector( '[data-tbttm-nomatch]' );
		var notice = root.querySelector( '[data-tbttm-notice]' );
		var total = +root.dataset.total || 0;
		var timer = null;
		var seq = 0;

		/**
		 * Rebuild the class dropdown from the server's counts, so a delete or
		 * a copy is reflected at once. A class whose last test went loses its
		 * entry; with no class left the dropdown goes too.
		 *
		 * @return {boolean} False when the chosen class is no longer on offer.
		 */
		function paintClasses( res ) {
			if ( ! classSelect ) {
				return true;
			}
			var chosen = classSelect.value;
			var html = '<option value="">All classes</option>';
			( res.classes || [] ).forEach( function ( c ) {
				html += '<option value="' + c.id + '">' + esc( c.title ) + ' (' + c.count + ')</option>';
			} );
			if ( res.unclassed > 0 ) {
				html += '<option value="none">Not in a class (' + res.unclassed + ')</option>';
			}
			classSelect.innerHTML = html;
			classSelect.hidden = ! ( res.classes && res.classes.length );
			classSelect.value = chosen;
			var kept = ! classSelect.hidden && classSelect.value === chosen;
			if ( ! kept ) {
				classSelect.value = '';
			}
			classSelect.classList.toggle( 'is-set', !! classSelect.value );
			return kept || ! chosen;
		}

		/** Write the filter into a URL: the class and the search, each only when set. */
		function withFilter( href ) {
			var url = new URL( href, window.location.href );
			var cls = classSelect ? classSelect.value : '';
			var term = input ? input.value.trim() : '';
			if ( CFG.classParam ) {
				if ( cls ) {
					url.searchParams.set( CFG.classParam, cls );
				} else {
					url.searchParams.delete( CFG.classParam );
				}
			}
			if ( CFG.searchParam ) {
				if ( term ) {
					url.searchParams.set( CFG.searchParam, term );
				} else {
					url.searchParams.delete( CFG.searchParam );
				}
			}
			return url.toString();
		}

		/**
		 * Keep the filter in the address, so a reload or a shared link keeps
		 * it, and on every Results link, so "Back to tests" returns to it.
		 */
		function syncUrl() {
			try {
				root.querySelectorAll( '[data-tbttm-results-link]' ).forEach( function ( a ) {
					a.href = withFilter( a.getAttribute( 'data-href' ) );
				} );
				if ( window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', withFilter( window.location.href ) );
				}
			} catch ( e ) {
				// The filter still works; only the address is left behind.
			}
		}

		function runSearch() {
			var term = input ? input.value.trim() : '';
			var cls = classSelect ? classSelect.value : '';
			var mine = ++seq;
			if ( classSelect ) {
				classSelect.classList.toggle( 'is-set', !! cls );
			}
			syncUrl();
			api( 'GET', 'tests' + ( CFG.rest.indexOf( '?' ) < 0 ? '?' : '&' ) + 'search=' + encodeURIComponent( term ) + '&class=' + encodeURIComponent( cls ) ).then( function ( res ) {
				if ( mine !== seq ) {
					return;
				}
				if ( ! paintClasses( res ) ) {
					// The chosen class has no tests left: show them all again.
					runSearch();
					return;
				}
				total = res.total;
				grid.innerHTML = res.tests.map( rowHtml ).join( '' );
				syncUrl();
				if ( term || cls ) {
					summary.innerHTML = res.tests.length + ' of ' + plural( total, 'test', 'tests' )
						+ ' · <button type="button" class="tbttm-linkbtn" data-tbttm-clear>Clear filters</button>';
					summary.hidden = false;
				} else {
					summary.hidden = true;
				}
				nomatch.hidden = ! ( ( term || cls ) && ! res.tests.length );
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

		/** Clear the search box; with `all`, the class filter too. */
		function clearSearch( all ) {
			if ( input ) {
				input.value = '';
			}
			if ( clearBtn ) {
				clearBtn.hidden = true;
			}
			if ( all && classSelect ) {
				classSelect.value = '';
			}
			clearTimeout( timer );
			runSearch();
			if ( input ) {
				input.focus();
			}
		}

		if ( classSelect ) {
			classSelect.addEventListener( 'change', function () {
				clearTimeout( timer );
				runSearch();
			} );
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
				// The × empties the search box; "Clear filters" resets both.
				clearSearch( b !== clearBtn );
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
						panel.innerHTML = shareBodyHtml( { slug: card.dataset.slug, url: card.dataset.url, access: card.dataset.access }, 'r' + card.dataset.id );
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
						return;
					}
					// The counts in the class dropdown and the summary follow.
					runSearch();
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
	 * Results
	 * ------------------------------------------------------------------- */

	/** "12 min 40 s"; "over 3 h" when the student left and came back. */
	function fmtTime( seconds ) {
		if ( seconds === null || seconds === undefined ) {
			return '';
		}
		if ( seconds > 10800 ) {
			return 'over 3 h';
		}
		var m = Math.floor( seconds / 60 );
		return ( m ? m + ' min ' : '' ) + ( seconds % 60 ) + ' s';
	}

	/** The last non-empty line of a question's text. */
	function lastLine( body ) {
		var lines = String( body == null ? '' : body ).split( '\n' ).filter( function ( l ) { return l.trim(); } );
		return lines.length ? lines[ lines.length - 1 ].trim() : '';
	}

	/**
	 * A test's Results: the stats, the attempts and the hardest questions.
	 * Everything shown comes from stored snapshots, for the test's owner
	 * only. Names and answers are always escaped: a guest typed that name.
	 */
	function Results( root ) {
		var testId = root.dataset.id;
		var data = null;        // GET /tests/{id}/results
		var loadError = '';
		var search = '';
		var best = false;
		var open = null;        // the attempt whose answers are showing
		var showAll = false;    // every answer, or the wrong ones only
		var del = null;         // the attempt being asked about
		var delBusy = false;
		var delError = '';
		var answers = {};       // attempt ID → { state: 'loading' | 'ok' | 'error', review, email, certificate }
		var certBusy = false;
		var certError = null;   // the attempt whose certificate failed

		function load() {
			loadError = '';
			api( 'GET', 'tests/' + testId + '/results' ).then( function ( res ) {
				data = res;
				render();
			}, function ( err ) {
				loadError = err.message;
				render();
			} );
		}

		/** The attempts after the search and "Best attempt per student". */
		function shown() {
			var term = fold( search );
			var list = data.attempts.filter( function ( a ) {
				return ! term || fold( a.name ).indexOf( term ) >= 0;
			} );
			if ( best ) {
				// The list is newest first, so on a tie the newest is kept.
				var top = {};
				list.forEach( function ( a ) {
					if ( ! top[ a.person ] || a.score > top[ a.person ].score ) {
						top[ a.person ] = a;
					}
				} );
				list = list.filter( function ( a ) { return top[ a.person ] === a; } );
			}
			return list;
		}

		function fetchAnswers( id ) {
			answers[ id ] = { state: 'loading' };
			api( 'GET', 'tests/' + testId + '/attempts/' + id ).then( function ( res ) {
				answers[ id ] = { state: 'ok', review: Array.isArray( res.review ) ? res.review : [], email: res.email || '', certificate: !! res.certificate };
				paintTable();
			}, function () {
				answers[ id ] = { state: 'error' };
				paintTable();
			} );
		}

		function toggle( id ) {
			open = open === id ? null : id;
			showAll = false;
			del = null;
			delError = '';
			certError = null;
			if ( open !== null && ! answers[ open ] ) {
				fetchAnswers( open );
			}
			paintTable();
		}

		/* ----- rendering ----- */

		function isGapItem( item ) {
			return item.type === 'gap';
		}

		/** Points lost on one question: wrong gaps, or one for a wrong choice. */
		function lost( item ) {
			if ( isGapItem( item ) ) {
				return ( Array.isArray( item.right ) ? item.right : [] ).filter( function ( r ) { return ! r; } ).length;
			}
			return isRight( item ) ? 0 : 1;
		}

		// An entry without a type is multiple choice: everything stored
		// before 0.5.0 reads that way.
		function isRight( item ) {
			if ( isGapItem( item ) ) {
				return Array.isArray( item.right ) && item.right.length > 0 && item.right.every( Boolean );
			}
			return item.chosen !== null && item.chosen !== undefined && item.chosen === item.correct;
		}

		function option( item, k ) {
			return k === null || k === undefined || ! item.options || item.options[ k ] === undefined ? '' : item.options[ k ];
		}

		function detailHtml( a ) {
			var got = answers[ a.id ];
			if ( ! got || got.state === 'loading' ) {
				return '<p class="tbttm-muted" role="status">Loading answers…</p>';
			}
			if ( got.state === 'error' ) {
				return '<p class="tbttm-rowline" role="alert"><span>Couldn’t load these answers.</span>'
					+ '<button type="button" class="tbttm-btn tbttm-btn--small" data-act="retry" data-att="' + a.id + '">Try again</button></p>';
			}
			var wrong = got.review.reduce( function ( sum, item ) { return sum + lost( item ); }, 0 );
			var html = '<div class="tbttm-rowline tbttm-answers__head"><strong>' + ( wrong ? plural( wrong, 'wrong answer', 'wrong answers' ) : 'Every answer correct' ) + '</strong>'
				+ '<button type="button" class="tbttm-linkbtn" data-act="all">' + ( showAll ? 'Show wrong answers only' : 'Show all answers' ) + '</button>'
				+ '<span class="tbttm-spacer"></span>';
			if ( del === a.id ) {
				html += '<span class="tbttm-confirm" role="alert"><span>Remove this attempt?</span>'
					+ '<button type="button" class="tbttm-btn tbttm-btn--small tbttm-btn--danger" data-act="del-yes" data-att="' + a.id + '"' + ( delBusy ? ' disabled' : '' ) + '>Remove attempt</button>'
					+ '<button type="button" class="tbttm-btn tbttm-btn--small tbttm-btn--ghost" data-act="del-no"' + ( delBusy ? ' disabled' : '' ) + '>Cancel</button>'
					+ ( delError ? '<span class="tbttm-note tbttm-note--error">' + esc( delError ) + '</span>' : '' ) + '</span>';
			} else {
				html += ( got.certificate
					? '<button type="button" class="tbttm-linkbtn" data-act="cert" data-att="' + a.id + '"' + ( certBusy ? ' disabled' : '' ) + '>Certificate</button>'
					: '' )
					+ '<button type="button" class="tbttm-btn tbttm-btn--small tbttm-btn--danger" data-act="del" data-att="' + a.id + '">Remove attempt</button>';
			}
			if ( certError === a.id ) {
				html += '<span class="tbttm-note tbttm-note--error tbttm-answers__note" role="alert">The certificate could not be made. Try again in a moment.</span>';
			}
			html += '</div><ul class="tbttm-alist">';
			got.review.forEach( function ( item, i ) {
				var ok = isRight( item );
				if ( ok && ! showAll ) {
					return;
				}
				if ( isGapItem( item ) ) {
					// The same filled-in text as the student's result page.
					var verdicts = Array.isArray( item.right ) ? item.right : [];
					html += '<li><span class="tbttm-alist__mark ' + ( ok ? 'is-ok' : 'is-bad' ) + '" aria-hidden="true">' + ( ok ? '✓' : '✗' ) + '</span>'
						+ '<div class="tbttm-alist__q tbttm-alist__q--gaps"><span class="tbttm-sr-only">' + ( ok ? 'Correct. ' : 'Incorrect. ' ) + 'Question ' + ( i + 1 ) + '. </span>'
						+ '<span class="tbttm-alist__n" aria-hidden="true">' + ( i + 1 ) + '.</span>' + filledHtml( item ) + '</div>'
						+ ( verdicts.length > 1 ? '<span class="tbttm-alist__a">' + verdicts.filter( Boolean ).length + ' of ' + verdicts.length + ' points</span>' : '' )
						+ '</li>';
					return;
				}
				var unanswered = item.chosen === null || item.chosen === undefined;
				html += '<li><span class="tbttm-alist__mark ' + ( ok ? 'is-ok' : 'is-bad' ) + '" aria-hidden="true">' + ( ok ? '✓' : '✗' ) + '</span>'
					+ '<span class="tbttm-alist__q"><span class="tbttm-sr-only">' + ( ok ? 'Correct. ' : 'Incorrect. ' ) + '</span>' + ( i + 1 ) + '. ' + esc( lastLine( item.body ) ) + '</span>'
					+ '<span class="tbttm-alist__a">' + ( ok
						? '<b class="is-right">' + esc( option( item, item.chosen ) ) + '</b>'
						: 'Answered: <b class="is-yours">' + ( unanswered ? 'no answer' : esc( option( item, item.chosen ) ) ) + '</b> · Correct: <b class="is-right">' + esc( option( item, item.correct ) ) + '</b>' )
					+ '</span></li>';
			} );
			return html + '</ul>';
		}

		function tableHtml( list ) {
			var pass = data.test.pass_mark;
			var cols = pass ? 6 : 5;
			var html = '<div class="tbttm-rtwrap"><table class="tbttm-rt"><thead><tr><th scope="col">Student</th><th scope="col">Finished</th><th scope="col">Score</th><th scope="col">Time</th>'
				+ ( pass ? '<th scope="col">Result</th>' : '' ) + '<th scope="col"><span class="tbttm-sr-only">Answers</span></th></tr></thead><tbody>';
			list.forEach( function ( a ) {
				var isOpen = open === a.id;
				html += '<tr class="tbttm-rt__att' + ( isOpen ? ' is-open' : '' ) + '" data-att="' + a.id + '">'
					+ '<td class="tbttm-rt__name">' + esc( a.name ) + ( a.guest ? '<span class="tbttm-rt__guest">no login</span>' : '' )
					// A guest's email, once their attempt is open and loaded.
					+ ( isOpen && answers[ a.id ] && answers[ a.id ].email ? '<span class="tbttm-rt__email">' + esc( answers[ a.id ].email ) + '</span>' : '' ) + '</td>'
					+ '<td>' + esc( a.finished ) + '</td>'
					+ '<td class="tbttm-rt__num">' + a.score + ' / ' + a.total + ' · ' + a.percent + '%'
					+ '<span class="tbttm-rmeter" aria-hidden="true"><i style="width:' + Math.max( 0, Math.min( 100, +a.percent || 0 ) ) + '%"></i></span></td>'
					+ '<td class="tbttm-rt__num">' + esc( fmtTime( a.seconds ) ) + '</td>'
					+ ( pass ? '<td><span class="tbttm-badge ' + ( a.passed ? 'tbttm-badge--published' : 'tbttm-badge--draft' ) + '">' + ( a.passed ? 'Passed' : 'Not passed' ) + '</span></td>' : '' )
					+ '<td class="tbttm-rt__act"><button type="button" class="tbttm-btn tbttm-btn--small tbttm-btn--ghost" data-act="answers" data-att="' + a.id + '" aria-expanded="' + isOpen + '">' + ( isOpen ? 'Close' : 'Answers' ) + '</button></td></tr>';
				if ( isOpen ) {
					html += '<tr class="tbttm-rt__detail"><td colspan="' + cols + '">' + detailHtml( a ) + '</td></tr>';
				}
			} );
			return html + '</tbody></table></div>'
				+ ( list.length ? '' : '<p class="tbttm-library__empty">No students match your search.</p>' )
				+ ( data.truncated ? '<p class="tbttm-hint">Showing the newest ' + data.attempts.length + ' attempts.</p>' : '' );
		}

		function statsHtml() {
			var st = data.stats;
			function stat( value, label ) {
				return '<div class="tbttm-stat"><div class="tbttm-stat__v">' + value + '</div><div class="tbttm-stat__k">' + label + '</div></div>';
			}
			return stat( st.attempts, ( st.attempts === 1 ? 'attempt' : 'attempts' ) + ' by ' + plural( st.people, 'person', 'people' ) )
				+ stat( st.average + '%', 'average score' )
				+ stat( st.best + '%', 'best score' )
				+ ( st.passed !== null && st.passed !== undefined ? stat( st.passed + ' of ' + st.attempts, 'passed' ) : '' );
		}

		function hardestHtml() {
			if ( ! data.hardest || ! data.hardest.length ) {
				return '';
			}
			return '<section class="tbttm-stage tbttm-stage--numbered"><div class="tbttm-stage__head"><h2 class="tbttm-stage__title">Hardest questions</h2>'
				+ '<p class="tbttm-stage__sub">The three questions students got wrong most often.</p></div><ul class="tbttm-hard">'
				+ data.hardest.map( function ( h ) {
					return '<li><span class="tbttm-hard__p">' + h.percent + '%</span><span class="tbttm-hard__t">' + h.number + '. ' + esc( h.line )
						+ '<span>' + h.percent + '% answered correctly. Correct answer: ' + esc( h.correct_text ) + '</span></span></li>';
				} ).join( '' ) + '</ul></section>';
		}

		/** The table and its count: repainted alone, so the search box keeps its caret. */
		function paintTable() {
			var box = root.querySelector( '[data-tbttm-rlist]' );
			var count = root.querySelector( '[data-tbttm-rcount]' );
			if ( ! box || ! count ) {
				return;
			}
			var list = shown();
			box.innerHTML = tableHtml( list );
			count.textContent = list.length + ' of ' + data.attempts.length + ' shown';
		}

		/** The stats and the hardest questions, after an attempt is removed. */
		function paintNumbers() {
			var stats = root.querySelector( '[data-tbttm-rstats]' );
			var hard = root.querySelector( '[data-tbttm-rhard]' );
			if ( stats ) {
				stats.innerHTML = statsHtml();
			}
			if ( hard ) {
				hard.innerHTML = hardestHtml();
			}
		}

		function render() {
			if ( ! data ) {
				root.innerHTML = loadError
					? '<p class="tbttm-note tbttm-note--error" role="alert">' + esc( loadError ) + '</p>'
						+ '<div class="tbttm-rowline"><button type="button" class="tbttm-btn" data-act="reload">Try again</button></div>'
					: '<p class="tbttm-muted">Loading the results…</p>';
				return;
			}
			var html = '<div class="tbttm-rhead"><h2 class="tbttm-rhead__title">' + esc( data.test.title ) + '</h2>'
				+ '<p class="tbttm-rhead__facts">' + factsOf( data.test ).map( esc ).join( ' · ' ) + '</p></div>';
			if ( ! data.stats.attempts ) {
				root.innerHTML = html + '<p class="tbttm-library__empty">Nobody has taken this test yet. Results appear here as soon as a student finishes.</p>';
				return;
			}
			html += '<div class="tbttm-stats" data-tbttm-rstats>' + statsHtml() + '</div>'
				+ '<section class="tbttm-stage tbttm-stage--numbered"><div class="tbttm-stage__head"><h2 class="tbttm-stage__title">Attempts</h2></div>'
				+ '<div class="tbttm-rtools"><div class="tbttm-rtools__search"><label class="tbttm-sr-only" for="tbttm-rsearch">Search students</label>'
				+ '<input class="tbttm-input" id="tbttm-rsearch" type="search" placeholder="Search students…" autocomplete="off" spellcheck="false" value="' + esc( search ) + '"></div>'
				+ '<label class="tbttm-check" for="tbttm-rbest"><input id="tbttm-rbest" type="checkbox"' + ( best ? ' checked' : '' ) + '> Best attempt per student</label>'
				+ '<span class="tbttm-spacer"></span><span class="tbttm-hint" data-tbttm-rcount aria-live="polite"></span></div>'
				+ '<div data-tbttm-rlist></div></section>'
				+ '<div data-tbttm-rhard>' + hardestHtml() + '</div>';
			root.innerHTML = html;
			paintTable();
		}

		/* ----- the certificate ----- */

		/** The same PDF the student downloads, for the test's owner. */
		function downloadCertificate( id ) {
			if ( certBusy ) {
				return;
			}
			certBusy = true;
			certError = null;
			paintTable();
			fetchPdf( CFG.rest + 'tests/' + testId + '/attempts/' + id + '/certificate', false ).then( function ( file ) {
				certBusy = false;
				paintTable();
				saveFile( file.blob, file.name );
			}, function () {
				certBusy = false;
				certError = id;
				paintTable();
			} );
		}

		/* ----- removing ----- */

		function remove( id ) {
			if ( delBusy ) {
				return;
			}
			delBusy = true;
			delError = '';
			paintTable();
			api( 'DELETE', 'tests/' + testId + '/attempts/' + id ).then( function ( res ) {
				delBusy = false;
				del = null;
				open = null;
				delete answers[ id ];
				data.attempts = data.attempts.filter( function ( a ) { return a.id !== id; } );
				data.stats = res.stats;
				data.hardest = res.hardest;
				if ( data.truncated ) {
					// More attempts wait beyond the listed 500: fetch the list again.
					load();
					return;
				}
				if ( ! data.stats.attempts ) {
					render();
					return;
				}
				paintNumbers();
				paintTable();
			}, function ( err ) {
				delBusy = false;
				delError = err.message;
				paintTable();
			} );
		}

		/* ----- events ----- */

		root.addEventListener( 'input', function ( e ) {
			if ( e.target.id === 'tbttm-rsearch' ) {
				search = e.target.value;
				paintTable();
			}
		} );

		root.addEventListener( 'change', function ( e ) {
			if ( e.target.id === 'tbttm-rbest' ) {
				best = e.target.checked;
				paintTable();
			}
		} );

		root.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( 'button' );
			if ( b && root.contains( b ) ) {
				if ( b.disabled ) {
					return;
				}
				var id = b.dataset.att ? +b.dataset.att : null;
				switch ( b.dataset.act ) {
					case 'reload':
						render();
						load();
						break;
					case 'answers':
						toggle( id );
						break;
					case 'retry':
						fetchAnswers( id );
						paintTable();
						break;
					case 'all':
						showAll = ! showAll;
						paintTable();
						break;
					case 'cert':
						downloadCertificate( id );
						break;
					case 'del':
						del = id;
						delError = '';
						paintTable();
						break;
					case 'del-no':
						del = null;
						delError = '';
						paintTable();
						break;
					case 'del-yes':
						remove( id );
						break;
				}
				// A button never also toggles the row it sits in.
				return;
			}
			// A click anywhere else on an attempt's row opens its answers, as
			// the Answers button does. The open answers are not part of it.
			var row = e.target.closest( 'tr.tbttm-rt__att' );
			if ( row && root.contains( row ) && ! e.target.closest( 'a, input, label, select, textarea' ) ) {
				toggle( +row.dataset.att );
			}
		} );

		render();
		load();
	}

	/* ---------------------------------------------------------------------
	 * Fill in the gaps
	 * ------------------------------------------------------------------- */

	// One gap: brackets with no bracket inside. As TBTTM_Gaps::PATTERN.
	var GAP = /\[([^\[\]]*)\]/g;
	var GAP_BODY_MAX = 6000;
	// A name and a colon at the start of a line, as on the student page.
	var SPEAKER = /^(\p{Lu}[\p{L} .'’\-]{0,29}): (.*)$/u;

	/** The gaps of a text: a list of accepted answers per gap. */
	function gapsOf( text ) {
		return PARSE ? PARSE.gaps( text ) : [];
	}

	// The validator's problems for a gap question, in its words.
	var GAP_PROBLEMS = {
		empty: 'no text',
		none: 'no gap yet',
		hollow: 'a gap is empty',
		open: 'a bracket is not closed',
		many: 'too many gaps',
	};

	/**
	 * A text with gaps as HTML: one paragraph per line with text, a
	 * speaker's name in bold, and fill( accepted, g ) in place of each gap.
	 */
	function gapLines( text, fill ) {
		var g = 0;
		return String( text == null ? '' : text ).split( '\n' ).filter( function ( l ) { return l.trim(); } ).map( function ( line ) {
			var m = line.match( SPEAKER );
			var html = m ? '<strong>' + esc( m[ 1 ] ) + ':</strong> ' + esc( m[ 2 ] ) : esc( line );
			return '<p>' + html.replace( GAP, function ( all, inside ) {
				// Escaped text holds no "|" of its own, so the split is the teacher's.
				return fill( inside.split( '|' ).map( function ( a ) { return a.trim(); } ).filter( Boolean ), g++ );
			} ) + '</p>';
		} ).join( '' );
	}

	/** The answer key: each gap as a blue word, accepted answers joined by "or". */
	function keyHtml( text ) {
		return gapLines( text, function ( accepted ) {
			return '<span class="tbttm-gchip">' + ( accepted.length ? accepted.join( ' <i>or</i> ' ) : '?' ) + '</span>';
		} );
	}

	/**
	 * A finished gap question with the student's words put back in, marked
	 * as on the student's result page: a right word in green; a wrong word
	 * crossed out in red, followed by the right one in green; an empty gap
	 * as "(empty)" crossed out.
	 */
	function filledHtml( item ) {
		var given = Array.isArray( item.given ) ? item.given : [];
		var right = Array.isArray( item.right ) ? item.right : [];
		return gapLines( item.body, function ( accepted, g ) {
			var word = String( given[ g ] == null ? '' : given[ g ] );
			if ( right[ g ] ) {
				return '<b class="tbttm-word is-right">' + esc( word ) + '</b>';
			}
			return '<s class="tbttm-word is-wrong">' + ( word.trim() ? esc( word ) : '(empty)' ) + '</s> <b class="tbttm-word is-right">' + ( accepted[ 0 ] || '' ) + '</b>';
		} );
	}

	/** The line that stands for a question in a list, with _____ for each gap. */
	function summaryLine( q ) {
		var lines = q.body.split( '\n' ).filter( function ( l ) { return l.trim(); } );
		var last = lines.length ? lines[ lines.length - 1 ] : '';
		if ( q.type !== 'gap' ) {
			return last;
		}
		var withGap = lines.find( function ( l ) { return gapsOf( l ).length > 0; } );
		return ( withGap || last ).trim().replace( GAP, '_____' );
	}

	/** Points: one per multiple choice question, one per gap. */
	function pointsOf( q ) {
		return q.type === 'gap' ? gapsOf( q.body ).length : 1;
	}

	/** What a closed question says beside its text when nothing is wrong. */
	function tagOf( q ) {
		return q.type === 'gap'
			? plural( gapsOf( q.body ).length, 'gap', 'gaps' )
			: plural( q.options.filter( function ( o ) { return o.trim(); } ).length, 'answer', 'answers' );
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
		if ( q.type === 'gap' ) {
			return GAP_PROBLEMS[ PARSE ? PARSE.gapIssue( q.body ) : '' ] || '';
		}
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
				// Answers typed for the other type are kept while the
				// builder is open and dropped on save.
				type: q && q.type === 'gap' ? 'gap' : 'choice',
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
			access: src && src.access === 'open' ? 'open' : 'login',
			// What the saved test says: the Share stage describes the link
			// as it works now, not as the unsaved form would make it.
			savedAccess: src && src.access === 'open' ? 'open' : 'login',
			class_id: src && src.class_id ? +src.class_id : 0,
			lesson_id: src && src.lesson_id ? +src.lesson_id : 0,
			certificate: !! ( src && src.certificate ),
			email_result: !! ( src && src.email_result ),
			questions: src ? src.questions.map( mkq ) : [],
		};
		// The teacher's own classes, printed by the server. Without TBT Notes,
		// or without a class, the Class field is not shown and not sent.
		var CLASSES = Array.isArray( CFG.classes ) ? CFG.classes : [];
		var lessons = src && Array.isArray( src.lessons ) ? src.lessons : [];
		var lessonSeq = 0;
		// The Class field is a text field with a list: what is in it, and
		// whether that text matches none of the teacher's classes.
		var classText = classTitle( d.class_id );
		var classMiss = false;
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
			var last = summaryLine( q );
			var gap = q.type === 'gap';
			var n = d.questions.length;
			var html = '<div class="tbttm-q' + ( open ? ' is-open' : '' ) + ( showBad && p ? ' is-bad' : '' ) + '" id="tbttm-q-' + q.key + '">'
				+ '<div class="tbttm-q__head"><span class="tbttm-q__n">' + ( i + 1 ) + '</span>'
				+ '<button type="button" class="tbttm-q__sum' + ( last ? '' : ' is-blank' ) + '" data-act="toggle" data-q="' + q.key + '" aria-expanded="' + open + '" data-sum="' + q.key + '">'
				+ ( last ? esc( last ) : 'New question' ) + '</button>'
				+ '<span class="tbttm-q__status' + ( p && showBad ? ' is-miss' : '' ) + '" data-status="' + q.key + '">' + esc( p || tagOf( q ) ) + '</span>'
				+ '<button type="button" class="tbttm-icon" data-act="up" data-q="' + q.key + '"' + ( i === 0 ? ' disabled' : '' ) + ' aria-label="Move question ' + ( i + 1 ) + ' up">↑</button>'
				+ '<button type="button" class="tbttm-icon" data-act="down" data-q="' + q.key + '"' + ( i === n - 1 ? ' disabled' : '' ) + ' aria-label="Move question ' + ( i + 1 ) + ' down">↓</button>'
				+ '<button type="button" class="tbttm-icon tbttm-icon--del" data-act="delq" data-q="' + q.key + '" aria-label="Remove question ' + ( i + 1 ) + '">×</button>'
				+ '</div>';
			if ( open ) {
				html += '<div class="tbttm-q__body">'
					+ '<div class="tbttm-seg" role="radiogroup" aria-label="Question type">'
					+ '<label><input type="radio" name="tbttm-type-' + q.key + '" id="tbttm-type-' + q.key + '-choice" data-q="' + q.key + '" data-type="choice"' + ( gap ? '' : ' checked' ) + '>Multiple choice</label>'
					+ '<label><input type="radio" name="tbttm-type-' + q.key + '" id="tbttm-type-' + q.key + '-gap" data-q="' + q.key + '" data-type="gap"' + ( gap ? ' checked' : '' ) + '>Fill in the gaps</label>'
					+ '</div>';
			}
			if ( open && gap ) {
				html += '<div class="tbttm-field"><label class="tbttm-field__label" for="tbttm-qt-' + q.key + '">Text</label>'
					+ '<textarea class="tbttm-input tbttm-input--content" id="tbttm-qt-' + q.key + '" data-q="' + q.key + '" data-f="body" rows="5" maxlength="' + GAP_BODY_MAX + '"'
					+ ' placeholder="I couldn’t agree [more].">' + esc( q.body ) + '</textarea>'
					+ '<div class="tbttm-rowline"><button type="button" class="tbttm-btn tbttm-btn--small" data-act="mkgap" data-q="' + q.key + '">Make a gap</button>'
					+ '<span class="tbttm-hint">Select a word and click, or type the square brackets yourself. Two accepted answers: [don’t|do not]. A sentence, a dialogue or a whole paragraph all work.</span></div></div>'
					+ '<div class="tbttm-field"><span class="tbttm-field__label">Answer key</span>'
					+ '<div class="tbttm-key" data-key="' + q.key + '">' + ( keyHtml( q.body ) || '<p class="tbttm-hint">Nothing yet.</p>' ) + '</div>'
					+ '<span class="tbttm-hint">The student sees an empty box in place of each blue word. Each gap is one point. Capitals, extra spaces and the kind of apostrophe do not matter.</span></div>'
					+ '</div>';
			} else if ( open ) {
				html += '<div class="tbttm-field"><label class="tbttm-field__label" for="tbttm-qt-' + q.key + '">Question</label>'
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

		/** "5 questions, 7 points. Click a question to open it." */
		function countLine() {
			var points = d.questions.reduce( function ( sum, q ) { return sum + pointsOf( q ); }, 0 );
			return plural( d.questions.length, 'question', 'questions' ) + ', ' + plural( points, 'point', 'points' ) + '. Click a question to open it.';
		}

		function importerHtml() {
			var rows = imp.rows;
			var good = rows ? rows.filter( function ( r ) { return ! r.problem; } ) : [];
			var bad = rows ? rows.length - good.length : 0;
			var html = '<div class="tbttm-imp" id="tbttm-imp">'
				+ '<h3 class="tbttm-imp__title">Import questions</h3>'
				+ '<div class="tbttm-field"><span class="tbttm-field__label">1. Use this layout</span>'
				+ '<span class="tbttm-hint">One question per row. Up to six answers (answer_a to answer_f). In “correct”, put the letter of the right answer. For a dialogue, put each line on a new line inside the question cell (Alt+Enter in Excel). For fill in the gaps, use only the question column: one sentence per row, with each answer in square brackets.</span>'
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
							var isGap = r.type === 'gap';
							// A gap row shows its answer key and its gaps; Correct stays empty.
							return '<tr' + ( r.problem ? ' class="is-bad"' : '' ) + '><td>' + r.row + '</td>'
								+ '<td class="tbttm-table__q' + ( isGap ? ' tbttm-table__key' : '' ) + '">' + ( isGap ? keyHtml( r.text ) : esc( r.text ) ) + '</td>'
								+ '<td>' + ( isGap ? plural( r.gaps.length, 'gap', 'gaps' ) : r.opts.map( function ( o, k ) { return LETTERS[ k ] + ') ' + esc( o ); } ).join( '\n' ) ) + '</td>'
								+ '<td>' + ( isGap || r.key === null ? '' : LETTERS[ r.key ] ) + '</td>'
								+ '<td>' + ( r.problem ? '<span class="tbttm-prob">' + esc( r.problem ) + '</span>' : '<span class="tbttm-fine">✓</span>' ) + '</td></tr>';
						} ).join( '' )
						+ '</tbody></table></div>';
				} else {
					html += '<p class="tbttm-note tbttm-note--error">No rows found. Paste at least one row with a question, two answers and the correct letter, or a sentence with an answer in square brackets.</p>';
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
			return html + shareBodyHtml( { slug: d.slug, url: d.url, access: d.savedAccess }, 'b' ) + '</section>';
		}

		/** A class's title by its ID, or '' when it is not one of the teacher's. */
		function classTitle( id ) {
			var title = '';
			CLASSES.some( function ( c ) {
				if ( +c.id === +id ) {
					title = String( c.title );
					return true;
				}
				return false;
			} );
			return title;
		}

		/**
		 * The Class field: a text field with the teacher's classes A–Z as its
		 * list, and once one is chosen its lessons, newest first, under
		 * "Any lesson".
		 */
		function classFieldHtml() {
			if ( ! CLASSES.length ) {
				return '';
			}
			var html = '<div class="tbttm-field tbttm-classpair"><div class="tbttm-classpair__cols">'
				+ '<div class="tbttm-field"><label class="tbttm-field__label" for="tbttm-f-class">Class</label>'
				+ '<input class="tbttm-input" id="tbttm-f-class" type="text" list="tbttm-f-class-list" autocomplete="off" spellcheck="false"'
				+ ' placeholder="No class — type to filter" value="' + esc( classText ) + '">'
				+ '<datalist id="tbttm-f-class-list">'
				+ CLASSES.map( function ( c ) {
					return '<option value="' + esc( c.title ) + '"></option>';
				} ).join( '' ) + '</datalist></div>';
			if ( d.class_id && ! classMiss ) {
				html += '<div class="tbttm-field"><label class="tbttm-field__label" for="tbttm-f-lesson">Lesson</label>'
					+ '<select class="tbttm-select" id="tbttm-f-lesson"><option value="0">Any lesson</option>'
					+ lessons.map( function ( l ) {
						return '<option value="' + l.id + '"' + ( +l.id === d.lesson_id ? ' selected' : '' ) + '>' + esc( l.title ) + '</option>';
					} ).join( '' ) + '</select></div>';
			}
			var hint = 'Attach the test to a class to show it in TBT Notes.';
			if ( classMiss ) {
				hint = '<strong>No class matches that. Pick one from the list, or clear the field to save without a class.</strong>';
			} else if ( d.class_id ) {
				hint = 'The test shows in this class in TBT Notes, like a Swipe deck or a drill.';
			}
			return html + '</div><span class="tbttm-hint">' + hint + '</span></div>';
		}

		/**
		 * Redraw only the Class and Lesson pair, in place. The class field's
		 * change event also fires when the field is left, so a full render()
		 * would swallow the click that left it and pull focus back on Tab.
		 * The two fields themselves are kept, never replaced: a field that
		 * has focus, or is about to get it, must still be there.
		 */
		function redrawClass() {
			var box = root.querySelector( '.tbttm-classpair' );
			if ( ! box ) {
				return;
			}
			var tmp = document.createElement( 'div' );
			tmp.innerHTML = classFieldHtml();
			var input = document.getElementById( 'tbttm-f-class' );
			if ( input.value !== classText ) {
				input.value = classText;
			}
			var cols = box.querySelector( '.tbttm-classpair__cols' );
			var oldLesson = document.getElementById( 'tbttm-f-lesson' );
			var newLesson = tmp.querySelector( '#tbttm-f-lesson' );
			if ( oldLesson && newLesson ) {
				oldLesson.innerHTML = newLesson.innerHTML;
			} else if ( oldLesson ) {
				cols.removeChild( oldLesson.parentNode );
			} else if ( newLesson ) {
				cols.appendChild( newLesson.parentNode );
			}
			box.lastElementChild.innerHTML = tmp.firstChild.lastElementChild.innerHTML;
		}

		/** Choose a class: the lesson is cleared and the new class's lessons are loaded. */
		function setClass( id ) {
			var mine = ++lessonSeq;
			d.class_id = id;
			d.lesson_id = 0;
			lessons = [];
			touch();
			redrawClass();
			if ( ! id ) {
				return;
			}
			api( 'GET', 'classes/' + id + '/lessons' ).then( function ( list ) {
				// The teacher picked another class while this was in flight.
				if ( mine !== lessonSeq ) {
					return;
				}
				lessons = Array.isArray( list ) ? list : [];
				redrawClass();
			}, function () {
				// The class still saves, with "Any lesson".
			} );
		}

		/**
		 * The class field changed: a suggestion was picked, or the field was
		 * left. The text must be one of the teacher's classes (any capitals)
		 * or empty; anything else keeps the class the test has and blocks
		 * saving until it is put right.
		 */
		function classChanged( value ) {
			var text = value.trim();
			var hit = null;
			CLASSES.some( function ( c ) {
				if ( String( c.title ).trim().toLowerCase() === text.toLowerCase() ) {
					hit = c;
					return true;
				}
				return false;
			} );
			if ( ! text ) {
				classMiss = false;
				classText = '';
				if ( d.class_id ) {
					setClass( 0 );
				} else {
					redrawClass();
				}
				return;
			}
			if ( ! hit ) {
				classMiss = true;
				classText = value;
				redrawClass();
				return;
			}
			classMiss = false;
			classText = String( hit.title );
			if ( +hit.id === d.class_id ) {
				redrawClass();
			} else {
				setClass( +hit.id );
			}
		}

		/** The Certificate hint follows the pass mark: who passes, or who finishes. */
		function certHint() {
			return String( d.pass_mark ).trim()
				? 'Students who pass can download a certificate with their name and score.'
				: 'Students who finish can download a certificate with their name and score.';
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
				+ stageHead( 2, 'Settings', 'Who the test is for, when students see the answers, what counts as a pass, the bull pictures, and what students get when they finish.' )
				+ '<div class="tbttm-settings">'
				+ classFieldHtml()
				+ '<div class="tbttm-field"><span class="tbttm-field__label" id="tbttm-acc-label">Who can take it</span>'
				+ '<div class="tbttm-seg" role="radiogroup" aria-labelledby="tbttm-acc-label">'
				+ '<label><input type="radio" name="tbttm-acc" id="tbttm-acc-login" value="login"' + ( d.access === 'login' ? ' checked' : '' ) + '>Logged-in students</label>'
				+ '<label><input type="radio" name="tbttm-acc" id="tbttm-acc-open" value="open"' + ( d.access === 'open' ? ' checked' : '' ) + '>Anyone with the link</label></div>'
				+ '<span class="tbttm-hint">' + ( d.access === 'login' ? 'Students log in first. Results are saved under their name.' : 'No login. People type their name before they start.' ) + '</span></div>'
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
				+ '<div class="tbttm-field"><span class="tbttm-field__label">Certificate</span>'
				+ '<label class="tbttm-switch" for="tbttm-f-cert"><input id="tbttm-f-cert" type="checkbox"' + ( d.certificate ? ' checked' : '' ) + '><span>' + ( d.certificate ? 'On' : 'Off' ) + '</span></label>'
				+ '<span class="tbttm-hint" data-tbttm-cert-hint>' + certHint() + '</span></div>'
				+ '<div class="tbttm-field"><span class="tbttm-field__label">Email the result</span>'
				+ '<label class="tbttm-switch" for="tbttm-f-mail"><input id="tbttm-f-mail" type="checkbox"' + ( d.email_result ? ' checked' : '' ) + '><span>' + ( d.email_result ? 'On' : 'Off' ) + '</span></label>'
				+ '<span class="tbttm-hint">' + ( d.email_result
					? 'Students get their score by email when they finish' + ( d.certificate ? ', with the certificate' : '' ) + '.'
						+ ( d.access === 'open' ? ' People without a login type their email before they start.' : '' )
					: 'Results are always saved for you. Turn this on to send them to the student too.' ) + '</span></div>'
				+ '</div></section>';

			// 3. Questions
			html += '<section class="tbttm-stage tbttm-stage--numbered">'
				+ stageHead( 3, 'Questions', '<span data-tbttm-count>' + countLine() + '</span>' )
				+ d.questions.map( questionHtml ).join( '' )
				+ '<div class="tbttm-rowline"><button type="button" class="tbttm-btn" data-act="addq" id="tbttm-addq"' + ( n >= MAX_Q ? ' disabled' : '' ) + '>Add question</button>'
				+ ( imp ? '' : '<button type="button" class="tbttm-btn" data-act="imp-open"' + ( n >= MAX_Q ? ' disabled' : '' ) + '>Import questions</button>' )
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
			var body = basePayload( status );
			// Sent only when the Class field is on the page: a save without
			// it keeps whatever class the test already has.
			if ( CLASSES.length ) {
				body.class_id = d.class_id || 0;
				body.lesson_id = d.class_id ? d.lesson_id || 0 : 0;
			}
			return body;
		}

		function basePayload( status ) {
			return {
				title: d.title,
				intro: d.intro,
				image_id: d.image_id || 0,
				feedback: d.feedback,
				bulls: d.bulls,
				pass_mark: d.pass_mark.trim(),
				access: d.access,
				certificate: !! d.certificate,
				email_result: !! d.email_result,
				status: status,
				questions: d.questions.map( function ( q ) {
					// Only what the question's type uses is sent: answers
					// typed for the other type are dropped on save.
					return q.type === 'gap'
						? { id: q.id || 0, type: 'gap', body: q.body }
						: { id: q.id || 0, type: 'choice', body: q.body, options: q.options, correct: q.correct };
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
			// A typo in the Class field must never detach the test from its class.
			if ( classMiss ) {
				msg = { type: 'err', text: 'Pick a class from the list, or clear the Class field to save without a class.' };
				render( 'tbttm-f-class' );
				var classField = document.getElementById( 'tbttm-f-class' );
				if ( classField ) {
					classField.scrollIntoView( { block: 'center' } );
				}
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
				d.access = res.access === 'open' ? 'open' : 'login';
				d.savedAccess = d.access;
				d.certificate = !! res.certificate;
				d.email_result = !! res.email_result;
				if ( CLASSES.length ) {
					lessonSeq++;
					d.class_id = res.class_id ? +res.class_id : 0;
					d.lesson_id = res.lesson_id ? +res.lesson_id : 0;
					lessons = Array.isArray( res.lessons ) ? res.lessons : [];
					classText = classTitle( d.class_id );
					classMiss = false;
				}
				// The server answers in the same order; take its question IDs
				// and its cleaned content (published tests lose empty answers).
				res.questions.forEach( function ( sq, i ) {
					var q = d.questions[ i ];
					if ( q ) {
						q.id = sq.id;
						q.body = sq.body;
						q.type = sq.type === 'gap' ? 'gap' : 'choice';
						q.options = q.type === 'gap' ? [ '', '', '' ] : sq.options.slice();
						q.correct = q.type === 'gap' ? null : sq.correct;
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
				d.questions.push( r.type === 'gap' ? mkq( { type: 'gap', body: r.text.slice( 0, GAP_BODY_MAX ) } ) : mkq( { body: r.text, options: r.opts, correct: r.key } ) );
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
			if ( t.id === 'tbttm-f-class' ) {
				// The list narrows by itself; the change event does the rest.
				classText = t.value;
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
				if ( t.dataset.f === 'pass_mark' ) {
					// Typing a pass mark changes who the certificate is for.
					var hint = root.querySelector( '[data-tbttm-cert-hint]' );
					if ( hint ) {
						hint.textContent = certHint();
					}
				}
			}
		} );

		/**
		 * Update a question's closed-row summary and status while typing,
		 * and for a gap question its answer key and the points count. The
		 * field itself is never redrawn, so the cursor never jumps.
		 */
		function refreshHead( q ) {
			var last = summaryLine( q );
			var sum = root.querySelector( '[data-sum="' + q.key + '"]' );
			if ( sum ) {
				sum.textContent = last || 'New question';
				sum.classList.toggle( 'is-blank', ! last );
			}
			var st = root.querySelector( '[data-status="' + q.key + '"]' );
			if ( st ) {
				var p = problems( q );
				st.textContent = p || tagOf( q );
				st.classList.toggle( 'is-miss', !! ( p && showBad ) );
			}
			var key = root.querySelector( '[data-key="' + q.key + '"]' );
			if ( key ) {
				key.innerHTML = keyHtml( q.body ) || '<p class="tbttm-hint">Nothing yet.</p>';
			}
			var count = root.querySelector( '[data-tbttm-count]' );
			if ( count ) {
				count.textContent = countLine();
			}
		}

		/**
		 * Make a gap: wrap the selected text in brackets, leaving the spaces
		 * around the selection outside them. Nothing happens when nothing is
		 * selected or the selection already holds a bracket.
		 */
		function makeGap( q ) {
			var field = document.getElementById( 'tbttm-qt-' + q.key );
			if ( ! field ) {
				return;
			}
			var from = field.selectionStart;
			var to = field.selectionEnd;
			var picked = field.value.slice( from, to );
			var core = picked.trim();
			field.focus();
			var before = field.value.slice( 0, from );
			// Nothing selected, a bracket in the selection, or a selection
			// that already sits inside a gap: brackets do not nest.
			if ( from === to || ! core || /[\[\]]/.test( core ) || before.lastIndexOf( '[' ) > before.lastIndexOf( ']' ) ) {
				return;
			}
			var lead = picked.match( /^\s*/ )[ 0 ];
			var trail = picked.match( /\s*$/ )[ 0 ];
			var next = field.value.slice( 0, from ) + lead + '[' + core + ']' + trail + field.value.slice( to );
			if ( next.length > GAP_BODY_MAX ) {
				return;
			}
			field.value = next;
			q.body = next;
			var at = from + lead.length + core.length + 2;
			field.setSelectionRange( at, at );
			refreshHead( q );
			touch();
		}

		root.addEventListener( 'change', function ( e ) {
			var t = e.target;
			if ( t.name === 'tbttm-fb' ) {
				d.feedback = t.value;
				touch();
				render( t.id );
				return;
			}
			if ( t.name === 'tbttm-acc' ) {
				d.access = t.value === 'open' ? 'open' : 'login';
				touch();
				render( t.id );
				return;
			}
			if ( t.id === 'tbttm-f-class' ) {
				classChanged( t.value );
				return;
			}
			if ( t.id === 'tbttm-f-lesson' ) {
				d.lesson_id = +t.value || 0;
				touch();
				return;
			}
			if ( t.id === 'tbttm-f-bulls' ) {
				d.bulls = t.checked;
				touch();
				render( t.id );
				return;
			}
			if ( t.id === 'tbttm-f-cert' || t.id === 'tbttm-f-mail' ) {
				d[ t.id === 'tbttm-f-cert' ? 'certificate' : 'email_result' ] = t.checked;
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
			if ( t.type === 'radio' && t.dataset.type && t.dataset.q ) {
				// Switching type keeps the text.
				findQ( t.dataset.q ).type = t.dataset.type === 'gap' ? 'gap' : 'choice';
				touch();
				render( t.id );
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
					// A new question takes the type of the question before
					// it; the first question of a test is multiple choice.
					var before = d.questions[ d.questions.length - 1 ];
					var nq = mkq( before ? { type: before.type } : null );
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
				case 'mkgap':
					makeGap( q );
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
		document.querySelectorAll( '[data-tbttm-results]' ).forEach( Results );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
