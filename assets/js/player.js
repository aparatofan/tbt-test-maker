/**
 * TBT Test Maker — the student page ([tbt_test]).
 *
 * Follows docs/prototypes/student.html. Two rules come first:
 *
 * 1. A student's answers are never lost. Every answer goes into the page
 *    state and localStorage at once, and to the server (debounced). A failed
 *    request keeps everything and retries; nothing here clears state or
 *    navigates away on an error. The local copy is cleared only after the
 *    server confirms the finish.
 * 2. The answer key is not in this page. The server scores the attempt, and
 *    in "after each question" mode reveals one answer at a time, after it has
 *    stored the student's choice.
 *
 * A guest on an open test has no login and no nonce. Their attempt is owned
 * by its token, which this page keeps in localStorage and sends in the
 * X-TBTTM-Token header; that is also how the same browser carries on later.
 */
( function () {
	'use strict';

	var CFG = window.TBTTM_PLAYER || {};
	var LETTERS = 'abcdef';
	var PHONE = window.matchMedia( '(max-width: 640px)' );
	var SAVE_DELAY = 800;
	var RETRY_DELAY = 10000;

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

	/** `*text*` in italics, on already escaped text. */
	function inline( escaped ) {
		return escaped.replace( /\*([^*\n]+)\*/g, '<em>$1</em>' );
	}

	// A name and a colon at the start of a line: a capital letter, then up to
	// 29 more letters, spaces, dots, apostrophes or hyphens, then ": ".
	var SPEAKER = /^(\p{Lu}[\p{L} .'’\-]{0,29}): (.*)$/u;

	/**
	 * Question text: plain text, escaped, line breaks kept, a speaker's name
	 * in bold, *text* in italics.
	 */
	function formatBody( body ) {
		var html = '';
		var lastBlank = false;
		String( body || '' ).split( '\n' ).forEach( function ( line ) {
			if ( line.trim() === '' ) {
				if ( ! lastBlank && html ) {
					html += '<p class="tbttm-qbody__gap" aria-hidden="true"></p>';
				}
				lastBlank = true;
				return;
			}
			lastBlank = false;
			var m = line.match( SPEAKER );
			html += '<p>' + ( m
				? '<strong>' + esc( m[ 1 ] ) + ':</strong> ' + inline( esc( m[ 2 ] ) )
				: inline( esc( line ) ) ) + '</p>';
		} );
		return html;
	}

	// One gap in a stored gap body: brackets with no bracket inside. The
	// same pattern as TBTTM_Gaps::PATTERN.
	var GAP = /\[([^\[\]]*)\]/g;

	/**
	 * A gap question's text with something put in each gap. `body` holds
	 * `[]` (the student page) or `[answers]` (a finished attempt's review);
	 * fill( g ) returns the HTML for gap g. The text is formatted first, as
	 * any body is, and the gaps are put in last: a `[]` passes through
	 * escaping, the speaker's name and the italics untouched, so bold names,
	 * line breaks and italics that span a gap all stay.
	 */
	function formatGaps( body, count, fill ) {
		var g = 0;
		return formatBody( String( body || '' ).replace( GAP, '[]' ) ).replace( /\[\]/g, function ( marker ) {
			return g < count ? fill( g++ ) : marker;
		} );
	}

	/** Two answers compared by value: an option index, or a list of words. */
	function sameAnswer( a, b ) {
		if ( Array.isArray( a ) && Array.isArray( b ) ) {
			return a.length === b.length && a.every( function ( w, k ) { return w === b[ k ]; } );
		}
		return a === b;
	}

	function copyAnswer( a ) {
		return Array.isArray( a ) ? a.slice() : a;
	}

	/** Run fn with storage, never letting a storage error reach the test. */
	function store( fn ) {
		try {
			return fn( window.localStorage );
		} catch ( e ) {
			return null;
		}
	}

	/* ---------------------------------------------------------------------
	 * Server
	 * ------------------------------------------------------------------- */

	var noncePromise = null;

	/** A fresh REST nonce from the logged-in admin-ajax action. */
	function refreshNonce() {
		if ( ! noncePromise ) {
			noncePromise = fetch( CFG.ajax + ( CFG.ajax.indexOf( '?' ) < 0 ? '?' : '&' ) + 'action=tbttm_nonce', {
				credentials: 'same-origin',
				cache: 'no-store',
			} ).then( function ( res ) {
				return res.json();
			} ).then( function ( data ) {
				if ( ! data || ! data.success || ! data.data || ! data.data.nonce ) {
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
	 * One REST call. Rejects with { code, status, data } — code 'network' when
	 * the request never got an answer. An expired nonce is refreshed and the
	 * call retried once, so a page left open for hours can still save.
	 *
	 * A logged-in student sends the nonce. A guest sends no X-WP-Nonce header
	 * at all (an empty one would be checked and refused), and the attempt's
	 * token instead, once there is one.
	 */
	function api( method, path, body, retried, token ) {
		var opts = {
			method: method,
			credentials: 'same-origin',
			cache: 'no-store',
			headers: {},
		};
		if ( CFG.nonce ) {
			opts.headers[ 'X-WP-Nonce' ] = CFG.nonce;
		}
		if ( token ) {
			opts.headers[ 'X-TBTTM-Token' ] = token;
		}
		if ( body !== undefined ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}
		return fetch( CFG.rest + path, opts ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( res.ok ) {
					return data;
				}
				var err = { code: ( data && data.code ) || 'http_' + res.status, status: res.status, message: ( data && data.message ) || '', data: ( data && data.data ) || {} };
				if ( err.code === 'rest_cookie_invalid_nonce' && ! retried && CFG.nonce ) {
					return refreshNonce().then( function () {
						return api( method, path, body, true, token );
					} );
				}
				throw err;
			} );
		}, function () {
			throw { code: 'network', status: 0, data: {} };
		} );
	}

	/**
	 * Fetch a PDF from a REST route. Resolves with { blob, name }; rejects
	 * on anything that is not a PDF. An expired nonce is refreshed once.
	 */
	function fetchPdf( url, retried ) {
		var headers = {};
		if ( CFG.nonce ) {
			headers[ 'X-WP-Nonce' ] = CFG.nonce;
		}
		return fetch( url, { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: headers } ).then( function ( res ) {
			var type = res.headers.get( 'Content-Type' ) || '';
			if ( res.ok && type.indexOf( 'application/pdf' ) === 0 ) {
				var match = /filename="?([^";]+)"?/i.exec( res.headers.get( 'Content-Disposition' ) || '' );
				return res.blob().then( function ( blob ) {
					return { blob: blob, name: match ? match[ 1 ] : 'certificate.pdf' };
				} );
			}
			return res.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( data && data.code === 'rest_cookie_invalid_nonce' && ! retried && CFG.nonce ) {
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
	 * One test on the page
	 * ------------------------------------------------------------------- */

	function Player( root ) {
		var dataEl = root.querySelector( '[data-tbttm-data]' );
		var data = {};
		try {
			data = JSON.parse( dataEl ? dataEl.textContent : '{}' ) || {};
		} catch ( e ) {
			data = {};
		}

		var T = data.test || {};
		var Q = data.questions || [];
		var N = Q.length;
		// A test with one question, of either type: no tiles, no question
		// number and no Back button.
		var ONE = N === 1;
		// Points: one per multiple choice question, one per gap.
		var POINTS = Q.reduce( function ( n, q ) { return n + ( isGap( q ) ? q.gaps : 1 ); }, 0 );
		var ONLY_GAPS = N > 0 && Q.every( isGap );
		var GAP_MAX = 100;
		var KEY = 'tbttm:' + T.id + ':' + ( CFG.userId || 0 );
		var bulls = CFG.bulls || {};

		// A guest: a logged-out visitor on an open test. Their attempt id,
		// token and name live under GKEY from the moment the server answers
		// the start; the token of their last finished attempt under LKEY.
		var GUEST = !! data.guest;
		var GKEY = 'tbttm:' + T.id + ':guest';
		var LKEY = 'tbttm:' + T.id + ':last';
		var NAME_ID = 'tbttm-name-' + T.id;
		var NAME_HINT = 'Shown on your result.';
		var NAME_MISSING = 'Type your name to start.';
		// On a test that emails results, a guest types an email as well.
		var ASK_EMAIL = GUEST && !! data.askEmail;
		var EMAIL_ID = 'tbttm-email-' + T.id;
		var EMAIL_HINT = 'We send your result here. Nothing else.';
		var EMAIL_MISSING = 'Type your email to start.';
		var guest = GUEST ? readGuest() : null;

		// Class Progress in TBT Notes: only for a logged-in student who is
		// not the owner, on a published test. Without the block, or without
		// the endpoint, nothing is sent.
		var PRESENCE_EVERY = 20000;
		var activity = data.activity || null;
		var presenceTimer = null;
		var startedAt = 0;

		var s = {
			view: 'start',
			i: 0,
			page: 0,
			attempt: null,      // { id, token } once the server has it
			answers: {},        // question ID → option index, or a list of typed words
			dirty: {},          // question IDs the server has not confirmed
			checked: {},        // question IDs checked ("after each question")
			revealed: {},       // question ID → correct index, or { right, correct } for gaps
			resume: data.resume || null,
			lastResult: data.lastResult || '',
			result: null,
			warn: false,
			failed: false,
			checkFailed: false,
			startError: '',
			resultError: '',
			busy: false,
			saving: false,
			offline: false,
			justDone: -1,
			name: guest ? guest.name : '',
			nameError: false,
			email: guest ? guest.email : '',
			emailError: false,
			certBusy: false,
			certError: false,
		};
		var saveTimer = null;
		var retryTimer = null;

		if ( GUEST ) {
			s.lastResult = readLast();
			s.resume = null;
			if ( guest ) {
				var kept = readLocal();
				s.resume = { id: guest.attempt, answered: kept && kept.attempt === guest.attempt ? countKnown( kept.answers ) : 0 };
			}
		}

		/** One REST call for this test; a guest's carries the attempt's token. */
		function call( method, path, body ) {
			var token = GUEST ? ( s.attempt ? s.attempt.token : ( guest ? guest.token : '' ) ) : '';
			return api( method, path, body, false, token );
		}

		/* ----- guest: the attempt this browser holds ----- */

		function readGuest() {
			return store( function ( ls ) {
				var v = JSON.parse( ls.getItem( GKEY ) || 'null' );
				return v && typeof v === 'object' && v.attempt && /^[a-f0-9]{32}$/.test( String( v.token ) )
					? { attempt: +v.attempt, token: String( v.token ), name: String( v.name || '' ), email: String( v.email || '' ) }
					: null;
			} ) || null;
		}

		function writeGuest() {
			store( function ( ls ) {
				ls.setItem( GKEY, JSON.stringify( guest ) );
			} );
		}

		function readLast() {
			var token = store( function ( ls ) {
				return ls.getItem( LKEY );
			} );
			return /^[a-f0-9]{32}$/.test( String( token ) ) ? String( token ) : '';
		}

		/** The server holds the guest's result: forget the attempt, keep the result's token. */
		function guestDone( token ) {
			guest = null;
			store( function ( ls ) {
				ls.removeItem( GKEY );
				if ( token ) {
					ls.setItem( LKEY, token );
				}
			} );
			if ( token ) {
				s.lastResult = token;
			}
		}

		/** How many of these answers belong to questions still in the test. */
		function countKnown( answers ) {
			return Q.filter( function ( q ) { return doneIn( answers, q ); } ).length;
		}

		/** A simple shape check; the server decides with is_email(). */
		function validEmail( text ) {
			return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( String( text || '' ).trim() );
		}

		/** Is the email still to be asked? Not once this browser holds one. */
		function needsEmail() {
			return ASK_EMAIL && ! ( guest && validEmail( guest.email ) );
		}

		function cleanName( text ) {
			return String( text || '' ).replace( /\s+/g, ' ' ).trim();
		}

		/* ----- Class Progress ----- */

		/** A side effect of taking the test, never a gate on it: every failure is swallowed. */
		function postActivity( path, body ) {
			if ( ! CFG.activityBase || ! activity ) {
				return;
			}
			var nonce = CFG.nonce || CFG.activityNonce;
			if ( ! nonce ) {
				return;
			}
			try {
				fetch( CFG.activityBase + path, {
					method: 'POST',
					credentials: 'same-origin',
					cache: 'no-store',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify( body || {} ),
					// A student who closes the tab right after finishing is still recorded.
					keepalive: true,
				} ).catch( function () {} );
			} catch ( e ) {
				// Nothing in the test waits for this.
			}
		}

		/** Present now, then every 20 seconds while a question is showing. */
		function startPresence() {
			if ( presenceTimer || ! CFG.activityBase || ! activity ) {
				return;
			}
			postActivity( '/presence', {} );
			presenceTimer = window.setInterval( function () {
				if ( s.view === 'quiz' ) {
					postActivity( '/presence', {} );
				}
			}, PRESENCE_EVERY );
		}

		function stopPresence() {
			if ( presenceTimer ) {
				window.clearInterval( presenceTimer );
				presenceTimer = null;
			}
		}

		/* ----- local copy ----- */

		function readLocal() {
			return store( function ( ls ) {
				var v = JSON.parse( ls.getItem( KEY ) || 'null' );
				return v && typeof v === 'object' && v.answers ? v : null;
			} );
		}

		function writeLocal() {
			if ( ! s.attempt ) {
				return;
			}
			store( function ( ls ) {
				ls.setItem( KEY, JSON.stringify( {
					attempt: s.attempt.id,
					answers: s.answers,
					dirty: Object.keys( s.dirty ),
				} ) );
			} );
		}

		function clearLocal() {
			store( function ( ls ) {
				ls.removeItem( KEY );
			} );
		}

		/* ----- derived ----- */

		function qid( i ) {
			return String( Q[ i ].id );
		}

		function isGap( q ) {
			return !! q && q.type === 'gap';
		}

		/** An answer as a gap question takes it: one string per gap. */
		function shape( q, a ) {
			var out = [];
			for ( var g = 0; g < q.gaps; g++ ) {
				out.push( Array.isArray( a ) && typeof a[ g ] === 'string' ? a[ g ].slice( 0, GAP_MAX ) : '' );
			}
			return out;
		}

		/** The words typed into a gap question so far. */
		function words( q ) {
			return shape( q, s.answers[ q.id ] );
		}

		/** An answer of the kind its question takes, or undefined. */
		function fit( q, a ) {
			if ( isGap( q ) ) {
				return Array.isArray( a ) ? shape( q, a ) : undefined;
			}
			return typeof a === 'number' && a >= 0 && a < q.options.length ? a : undefined;
		}

		/**
		 * Done: a multiple choice question with an option chosen, a gap
		 * question with text in every gap.
		 */
		function doneIn( answers, q ) {
			var a = answers[ q.id ];
			if ( isGap( q ) ) {
				return q.gaps > 0 && Array.isArray( a ) && shape( q, a ).every( function ( w ) { return w.trim() !== ''; } );
			}
			return a !== undefined && ! Array.isArray( a );
		}

		function done( q ) {
			return doneIn( s.answers, q );
		}

		/** Something to check: an option chosen, or text in at least one gap. */
		function touched( q ) {
			if ( isGap( q ) ) {
				return words( q ).some( function ( w ) { return w.trim() !== ''; } );
			}
			return done( q );
		}

		/** A checked question with every point won. */
		function allRight( q ) {
			var r = s.revealed[ q.id ];
			if ( isGap( q ) ) {
				return !! r && Array.isArray( r.right ) && r.right.length > 0 && r.right.every( Boolean );
			}
			return s.answers[ q.id ] === r;
		}

		function answered() {
			return Q.filter( done ).length;
		}

		function gapsFilled() {
			return Q.reduce( function ( n, q ) {
				return n + ( isGap( q ) ? words( q ).filter( function ( w ) { return w.trim() !== ''; } ).length : 0 );
			}, 0 );
		}

		function missing() {
			var out = [];
			Q.forEach( function ( q, i ) {
				if ( ! done( q ) ) {
					out.push( i + 1 );
				}
			} );
			return out;
		}

		/** The quiet line above the question. */
		function countText() {
			if ( ONE ) {
				return isGap( Q[ 0 ] ) ? gapsFilled() + ' of ' + POINTS + ( POINTS === 1 ? ' gap filled' : ' gaps filled' ) : '';
			}
			return 'Question ' + ( s.i + 1 ) + ' of ' + N + ' · ' + ( N - answered() ) + ' left';
		}

		function hasUnsent() {
			return Object.keys( s.dirty ).length > 0;
		}

		/** Tiles per row: all up to 25, else 20; 10 on a phone. */
		function per() {
			return PHONE.matches ? 10 : ( N <= 25 ? N : 20 );
		}

		function go( i ) {
			s.i = Math.max( 0, Math.min( N - 1, i ) );
			s.page = Math.floor( s.i / per() );
		}

		/**
		 * The bull picture on question i: start on the first, run on
		 * ceil(N/2), attack on min(N−1, round(N×0.85)), jump on the last
		 * (1-based). When two land on one question, the later picture wins.
		 */
		function stageAt( i ) {
			var pic = '';
			if ( i === 0 ) {
				pic = 'start';
			}
			if ( i === Math.ceil( N / 2 ) - 1 ) {
				pic = 'run';
			}
			if ( i === Math.min( N - 2, Math.round( N * 0.85 ) - 1 ) ) {
				pic = 'attack';
			}
			if ( i === N - 1 ) {
				pic = 'jump';
			}
			return pic;
		}

		/* ----- saving ----- */

		function scheduleSave( delay ) {
			clearTimeout( saveTimer );
			saveTimer = setTimeout( saveNow, delay );
			updateStatus();
		}

		function saveNow() {
			clearTimeout( saveTimer );
			if ( ! s.attempt || ! hasUnsent() || s.saving ) {
				updateStatus();
				return;
			}
			var sent = {};
			Object.keys( s.dirty ).forEach( function ( id ) {
				if ( s.answers[ id ] !== undefined ) {
					// A copy: a list of words goes on changing as the student types.
					sent[ id ] = copyAnswer( s.answers[ id ] );
				}
			} );
			s.saving = true;
			updateStatus();
			call( 'PUT', 'attempts/' + s.attempt.id, { answers: sent } ).then( function () {
				s.saving = false;
				s.offline = false;
				clearTimeout( retryTimer );
				Object.keys( sent ).forEach( function ( id ) {
					if ( sameAnswer( s.answers[ id ], sent[ id ] ) ) {
						delete s.dirty[ id ];
					}
				} );
				writeLocal();
				updateStatus();
				if ( hasUnsent() ) {
					scheduleSave( SAVE_DELAY );
				}
			}, function ( err ) {
				s.saving = false;
				if ( err.code === 'tbttm_attempt_finished' ) {
					finishedElsewhere( err.data.token );
					return;
				}
				goOffline();
			} );
		}

		/** Leaving a question: what is waiting goes now, not when the timer says. */
		function flush() {
			if ( hasUnsent() ) {
				saveNow();
			}
		}

		function goOffline() {
			s.offline = true;
			updateStatus();
			clearTimeout( retryTimer );
			retryTimer = setTimeout( saveNow, RETRY_DELAY );
		}

		/** The attempt was finished on another device: show its result. */
		function finishedElsewhere( token ) {
			s.dirty = {};
			clearLocal();
			stopPresence();
			if ( GUEST ) {
				guestDone( token );
			}
			if ( token ) {
				showResult( token );
			}
		}

		function statusHtml() {
			if ( s.offline ) {
				return '<span class="tbttm-saved is-off">Offline · saved on this device</span>';
			}
			if ( s.saving || hasUnsent() ) {
				return '<span class="tbttm-saved">Saving…</span>';
			}
			return '<span class="tbttm-saved">✓ Saved</span>';
		}

		function updateStatus() {
			var el = root.querySelector( '[data-tbttm-status]' );
			if ( el ) {
				el.innerHTML = statusHtml();
			}
		}

		/* ----- starting ----- */

		/**
		 * Merge the server's state with the browser's copy of the same
		 * attempt. Answers the server does not hold, and answers changed here
		 * but never confirmed, win; they are marked unsent and sent at once.
		 */
		function adopt( state ) {
			s.attempt = { id: state.id, token: state.token };
			s.answers = {};
			s.dirty = {};
			s.checked = {};
			s.revealed = state.revealed || {};
			( state.checked || [] ).forEach( function ( id ) { s.checked[ id ] = true; } );
			var byId = {};
			Q.forEach( function ( q ) { byId[ q.id ] = q; } );
			Object.keys( state.answers || {} ).forEach( function ( id ) {
				var a = byId[ id ] ? fit( byId[ id ], state.answers[ id ] ) : undefined;
				if ( a !== undefined ) {
					s.answers[ id ] = a;
				}
			} );

			var local = readLocal();
			if ( local && local.attempt === state.id ) {
				var unsent = {};
				( local.dirty || [] ).forEach( function ( id ) { unsent[ id ] = true; } );
				Object.keys( local.answers ).forEach( function ( id ) {
					var mine = byId[ id ] ? fit( byId[ id ], local.answers[ id ] ) : undefined;
					if ( mine === undefined || s.checked[ id ] ) {
						return;
					}
					if ( s.answers[ id ] === undefined || ( unsent[ id ] && ! sameAnswer( s.answers[ id ], mine ) ) ) {
						s.answers[ id ] = mine;
						s.dirty[ id ] = true;
					}
				} );
			}
			writeLocal();
			if ( hasUnsent() ) {
				scheduleSave( 0 );
			}
		}

		/** The server has the attempt: show its first open question. */
		function enterQuiz( state ) {
			s.busy = false;
			adopt( state );
			s.result = null;
			s.warn = false;
			s.failed = false;
			s.view = 'quiz';
			// Resume at the first question still open: unanswered, or in
			// "after each question" mode, not yet checked.
			var first = Q.findIndex( function ( q ) {
				return T.feedback === 'now' ? ! s.checked[ q.id ] : ! done( q );
			} );
			go( first < 0 ? N - 1 : first );
			startedAt = Date.now();
			startPresence();
			render();
			scrollToTop();
		}

		function start( restart ) {
			if ( s.busy ) {
				return;
			}
			s.certError = false;
			if ( GUEST ) {
				startGuest( restart );
				return;
			}
			s.busy = true;
			s.startError = '';
			if ( restart ) {
				clearLocal();
			}
			render();
			call( 'POST', 'attempts', { test: T.id, restart: !! restart } ).then( enterQuiz, function () {
				s.busy = false;
				s.startError = 'We couldn’t start the test. Check your connection and try again.';
				render();
			} );
		}

		/* ----- starting, as a guest ----- */

		function focusField( id ) {
			var field = document.getElementById( id );
			if ( field ) {
				field.focus( { preventScroll: true } );
			}
		}

		function focusName() {
			focusField( NAME_ID );
		}

		/**
		 * START TEST, CONTINUE TEST, Start again and the result's Try again,
		 * for a guest. With an attempt in this browser: carry on with it, or
		 * replace it. Without one: the name comes first.
		 */
		function startGuest( restart ) {
			if ( guest && ! restart ) {
				continueGuest();
				return;
			}
			var name = cleanName( guest ? guest.name : s.name );
			var email = ASK_EMAIL ? String( guest && validEmail( guest.email ) ? guest.email : s.email ).trim() : '';
			var badName = name.length < 2;
			var badEmail = ASK_EMAIL && ! validEmail( email );
			if ( badName || badEmail ) {
				// Try again on a result opened later: back to the start
				// screen, where they are asked, without scolding.
				s.nameError = badName && s.view === 'start';
				s.emailError = badEmail && s.view === 'start';
				s.view = 'start';
				render();
				focusField( badName ? NAME_ID : EMAIL_ID );
				return;
			}
			newGuestAttempt( name, null, !! restart, email );
		}

		/**
		 * A new attempt under this name. `carry` holds answers from an attempt
		 * the server no longer knows; they go to the new one at once.
		 */
		function newGuestAttempt( name, carry, restart, email ) {
			email = ASK_EMAIL ? String( email || '' ).trim() : '';
			s.busy = true;
			s.startError = '';
			s.nameError = false;
			s.emailError = false;
			render();
			var body = { test: T.id, name: name };
			if ( email ) {
				body.email = email;
			}
			call( 'POST', 'attempts', body ).then( function ( state ) {
				guest = { attempt: state.id, token: state.token, name: name, email: email };
				writeGuest();
				s.name = name;
				s.email = email;
				s.attempt = null;
				if ( carry ) {
					store( function ( ls ) {
						ls.setItem( KEY, JSON.stringify( { attempt: state.id, answers: carry, dirty: Object.keys( carry ) } ) );
					} );
				} else if ( restart ) {
					clearLocal();
				}
				enterQuiz( state );
			}, function ( err ) {
				s.busy = false;
				if ( err.code === 'tbttm_no_name' ) {
					s.nameError = true;
					s.view = 'start';
					render();
					focusName();
					return;
				}
				if ( err.code === 'tbttm_no_email' ) {
					// The email this browser holds, if any, is no good: ask again.
					if ( guest ) {
						guest.email = '';
						writeGuest();
					}
					s.emailError = true;
					s.view = 'start';
					render();
					focusField( EMAIL_ID );
					return;
				}
				s.startError = err.status === 401
					? 'This test is now for logged-in students. Reload the page to log in. Your answers are still on this device.'
					: err.status === 429 && err.message
						? err.message
						: 'We couldn’t start the test. Check your connection and try again.';
				render();
			} );
		}

		/**
		 * CONTINUE TEST: carry on with the attempt this browser holds. Its
		 * answers here go to the server first, and the server answers with
		 * the attempt's state. If the server no longer knows the attempt, a
		 * new one starts under the stored name and takes the stored answers.
		 */
		function continueGuest() {
			var local = readLocal();
			var answers = local && local.attempt === guest.attempt ? local.answers : {};
			s.busy = true;
			s.startError = '';
			render();
			call( 'PUT', 'attempts/' + guest.attempt, { answers: answers } ).then( enterQuiz, function ( err ) {
				s.busy = false;
				if ( err.code === 'tbttm_attempt_finished' ) {
					finishedElsewhere( err.data.token );
					return;
				}
				if ( err.code === 'tbttm_no_attempt' ) {
					newGuestAttempt( guest.name, answers, false, guest.email );
					return;
				}
				s.startError = 'We couldn’t open your test. Check your connection and try again.';
				render();
			} );
		}

		/**
		 * On load with an attempt in progress and answers in this browser
		 * that the server may not have: fetch the attempt and send them, so
		 * another device sees them too.
		 */
		function syncOnLoad() {
			var local = readLocal();
			if ( ! local ) {
				return;
			}
			if ( ! s.resume || local.attempt !== s.resume.id ) {
				// A finished, restarted or unknown attempt: nothing to carry over.
				if ( ! s.resume ) {
					clearLocal();
				}
				return;
			}
			call( 'POST', 'attempts', { test: T.id } ).then( function ( state ) {
				if ( s.view !== 'start' || s.busy ) {
					return;
				}
				adopt( state );
				s.resume.answered = answered();
				render();
			}, function () {
				// Offline: the answers stay in this browser and go with CONTINUE.
				s.resume.answered = Math.max( s.resume.answered, countKnown( local.answers ) );
				render();
			} );
		}

		/* ----- answering ----- */

		function choose( k ) {
			var id = qid( s.i );
			if ( s.checked[ id ] ) {
				return;
			}
			var first = s.answers[ id ] === undefined;
			s.answers[ id ] = k;
			s.dirty[ id ] = true;
			s.checkFailed = false;
			writeLocal();
			if ( first && T.feedback !== 'now' ) {
				s.justDone = s.i;
			}
			scheduleSave( SAVE_DELAY );
			render();
			var radio = root.querySelector( '#tbttm-o-' + T.id + '-' + k );
			if ( radio ) {
				radio.focus( { preventScroll: true } );
			}
		}

		/**
		 * A word typed into a gap. The answer is mirrored in this browser at
		 * once and sent with the delayed save. Nothing is redrawn: the tile,
		 * the count and the Check button are updated in place, so the cursor
		 * and the phone keyboard stay where they are.
		 */
		function type( field ) {
			var q = Q[ s.i ];
			var id = qid( s.i );
			var g = +field.dataset.g;
			if ( ! isGap( q ) || s.checked[ id ] || ! ( g >= 0 && g < q.gaps ) ) {
				return;
			}
			var was = done( q );
			var list = words( q );
			list[ g ] = String( field.value ).slice( 0, GAP_MAX );
			s.answers[ id ] = list;
			s.dirty[ id ] = true;
			writeLocal();
			scheduleSave( SAVE_DELAY );

			field.style.width = gapWidth( list[ g ] );
			var now = done( q );
			var tile = root.querySelector( '.tbttm-tile[data-go="' + s.i + '"]' );
			if ( tile ) {
				tile.classList.toggle( 'is-done', now );
				tile.classList.toggle( 'is-pop', now && ! was && T.feedback !== 'now' );
				tile.setAttribute( 'aria-label', 'Question ' + ( s.i + 1 ) + ( now ? ', answered' : '' ) );
			}
			var count = root.querySelector( '[data-tbttm-count]' );
			if ( count ) {
				count.textContent = countText();
			}
			var button = root.querySelector( '[data-act="check"][data-tbttm-main]' );
			if ( button ) {
				button.disabled = ! touched( q ) || s.busy;
			}
			var warn = root.querySelector( '[data-tbttm-warn]' );
			if ( warn ) {
				warn.innerHTML = warnInner();
				warn.hidden = warn.innerHTML === '';
			}
		}

		/** A gap is at least 9 characters wide and grows with what is typed. */
		function gapWidth( text ) {
			return Math.max( 9, String( text ).length + 3 ) + 'ch';
		}

		function focusGap() {
			var field = root.querySelector( '.tbttm-gap:not(:disabled)' );
			if ( field ) {
				field.focus( { preventScroll: true } );
			}
		}

		/** Move to another question; what is waiting to be saved goes now. */
		function leave( i ) {
			flush();
			go( i );
			s.warn = false;
			s.checkFailed = false;
			render();
			focusGap();
		}

		function check() {
			var id = qid( s.i );
			var q = Q[ s.i ];
			if ( s.busy || ! touched( q ) || s.checked[ id ] ) {
				return;
			}
			s.busy = true;
			s.checkFailed = false;
			render();
			call( 'POST', 'attempts/' + s.attempt.id + '/check', { question: +id, answer: isGap( q ) ? words( q ) : s.answers[ id ] } ).then( function ( res ) {
				s.busy = false;
				s.checked[ id ] = true;
				s.revealed[ id ] = isGap( q ) ? { right: res.right || [], correct: res.correct || [] } : res.correct;
				var stored = fit( q, res.answer );
				if ( stored !== undefined ) {
					s.answers[ id ] = stored;
				}
				delete s.dirty[ id ];
				writeLocal();
				s.justDone = s.i;
				render();
			}, function ( err ) {
				s.busy = false;
				if ( err.code === 'tbttm_attempt_finished' ) {
					finishedElsewhere( err.data.token );
					return;
				}
				s.checkFailed = true;
				render();
			} );
		}

		/* ----- finishing ----- */

		function finish( force ) {
			if ( s.busy ) {
				return;
			}
			if ( ! force && missing().length ) {
				s.warn = true;
				s.failed = false;
				render();
				return;
			}
			s.busy = true;
			s.warn = false;
			render();
			clearTimeout( saveTimer );
			call( 'POST', 'attempts/' + s.attempt.id + '/finish', { answers: s.answers } ).then( function ( result ) {
				s.busy = false;
				s.failed = false;
				s.dirty = {};
				clearTimeout( retryTimer );
				// Only now, with the result stored on the server.
				clearLocal();
				if ( GUEST ) {
					guestDone( result.token );
				}
				s.resume = null;
				s.lastResult = result.token || s.lastResult;
				s.result = result;
				s.view = 'result';
				// Class Progress, once, with the server's own score. Presence
				// ends here and does not start again on the result screen.
				stopPresence();
				if ( activity ) {
					postActivity( '', {
						tool: 'test',
						object_ref: activity.objectRef,
						object_title: activity.objectTitle,
						score: result.score,
						score_max: result.total,
						duration_seconds: startedAt ? Math.max( 0, Math.round( ( Date.now() - startedAt ) / 1000 ) ) : null,
					} );
				}
				render();
				scrollToTop();
			}, function () {
				// Stay on the question; clear nothing.
				s.busy = false;
				s.failed = true;
				render();
			} );
		}

		function showResult( token ) {
			s.busy = true;
			s.resultError = '';
			render();
			call( 'GET', 'attempts/' + token + '/result' ).then( function ( result ) {
				s.busy = false;
				s.result = result;
				s.view = 'result';
				stopPresence();
				render();
				scrollToTop();
			}, function () {
				s.busy = false;
				s.resultError = 'We couldn’t load your result. Check your connection and try again.';
				render();
			} );
		}

		/**
		 * Download the certificate of the result on screen. An extra: if it
		 * cannot be made, a line says so and the result stays as it is.
		 */
		function downloadCertificate() {
			var token = s.result && s.result.token;
			if ( ! token || s.certBusy ) {
				return;
			}
			s.certBusy = true;
			s.certError = false;
			render();
			fetchPdf( CFG.rest + 'attempts/' + token + '/certificate', false ).then( function ( file ) {
				s.certBusy = false;
				render();
				saveFile( file.blob, file.name );
			}, function () {
				s.certBusy = false;
				s.certError = true;
				render();
			} );
		}

		function scrollToTop() {
			var top = root.getBoundingClientRect().top + window.pageYOffset - 120;
			if ( window.pageYOffset > top ) {
				window.scrollTo( { top: Math.max( 0, top ) } );
			}
		}

		/* ----- screens ----- */

		/** Plain text with its line breaks kept. */
		function lines( text ) {
			return esc( text ).replace( /\n/g, '<br>' );
		}

		/**
		 * The start screen is the test's cover: the feature picture across
		 * the top of the card, then the eyebrow, the title and the
		 * introduction, then where the student stands, the facts, and the
		 * one main action.
		 */
		function startScreen() {
			var n = T.draft ? ( data.count || 0 ) : N;
			var html = '';
			if ( T.image ) {
				html += '<figure class="tbttm-cover"><img src="' + esc( T.image ) + '" alt=""></figure>';
			}
			html += '<header class="tbttm-start">'
				+ '<p class="tbttm-eyebrow">Test · ' + sizeText( n ) + '</p>'
				+ '<h2 class="tbttm-section-title tbttm-start__title">' + esc( T.title ) + '</h2>'
				+ ( T.intro ? '<p class="tbttm-start__intro">' + lines( T.intro ) + '</p>' : '' )
				+ '</header>';

			if ( T.draft ) {
				return html + '<div class="tbttm-notice"><p><strong>This test is a draft.</strong> Students can’t see it yet. Publish it on the teacher page to take it or share it.</p></div>';
			}
			if ( ! N ) {
				return html + '<p class="tbttm-muted">This test has no questions yet.</p>';
			}

			// A guest with an attempt in this browser always carries on with
			// it; a logged-in student once they have answered something.
			var done = s.resume ? s.resume.answered : 0;
			var resume = GUEST ? !! guest : done > 0;
			if ( resume ) {
				var pct = Math.round( done / N * 100 );
				html += '<div class="tbttm-resume">'
					+ '<p><strong>Welcome back.</strong> You answered ' + done + ' of ' + N + ' questions last time. Your answers are still here.</p>'
					+ '<div class="tbttm-meter" role="progressbar" aria-label="Answered" aria-valuemin="0" aria-valuemax="' + N + '" aria-valuenow="' + done + '"><i style="width:' + pct + '%"></i></div>'
					+ '</div>';
			} else {
				var some = Q.some( isGap );
				html += '<p class="tbttm-lead">' + ( ONLY_GAPS ? 'Type the missing words into the text. '
					: some ? 'Read each question and choose one answer or type the missing words. '
					: 'Read each question and choose one answer. ' ) + ( T.feedback === 'now'
					? ( ONLY_GAPS ? 'You see the correct answers when you check them.' : 'You see the correct answer after each question.' )
					: ( ONE ? 'You can change an answer at any time before you finish.' : 'You can go back and change an answer at any time before you finish.' ) ) + '</p>';
			}

			html += facts( N );
			if ( s.startError ) {
				html += '<div class="tbttm-notice tbttm-notice--error" role="alert"><p>' + esc( s.startError ) + '</p></div>';
			}
			if ( s.resultError ) {
				html += '<div class="tbttm-notice tbttm-notice--error" role="alert"><p>' + esc( s.resultError ) + '</p></div>';
			}
			if ( GUEST && ! guest ) {
				html += '<div class="tbttm-name' + ( s.nameError ? ' is-bad' : '' ) + '" data-tbttm-namebox>'
					+ '<label class="tbttm-name__label" for="' + NAME_ID + '">Your name</label>'
					+ '<input class="tbttm-name__input" id="' + NAME_ID + '" type="text" autocomplete="name" maxlength="120" data-tbttm-name'
					+ ' value="' + esc( s.name ) + '" placeholder="e.g. Milena" aria-describedby="' + NAME_ID + '-hint"' + ( s.nameError ? ' aria-invalid="true"' : '' ) + '>'
					+ '<span class="tbttm-name__hint" id="' + NAME_ID + '-hint" data-tbttm-namehint' + ( s.nameError ? ' role="alert"' : '' ) + '>'
					+ ( s.nameError ? NAME_MISSING : NAME_HINT ) + '</span></div>';
			}
			if ( needsEmail() ) {
				html += '<div class="tbttm-name' + ( s.emailError ? ' is-bad' : '' ) + '" data-tbttm-emailbox>'
					+ '<label class="tbttm-name__label" for="' + EMAIL_ID + '">Your email</label>'
					+ '<input class="tbttm-name__input" id="' + EMAIL_ID + '" type="email" inputmode="email" autocomplete="email" maxlength="190" data-tbttm-email'
					+ ' value="' + esc( s.email ) + '" placeholder="e.g. milena@example.com" aria-describedby="' + EMAIL_ID + '-hint"' + ( s.emailError ? ' aria-invalid="true"' : '' ) + '>'
					+ '<span class="tbttm-name__hint" id="' + EMAIL_ID + '-hint" data-tbttm-emailhint' + ( s.emailError ? ' role="alert"' : '' ) + '>'
					+ ( s.emailError ? EMAIL_MISSING : EMAIL_HINT ) + '</span></div>';
			}
			html += '<div class="tbttm-row tbttm-start__actions">'
				+ '<button class="tbttm-btn tbttm-btn--cta" type="button" data-act="start"' + ( s.busy ? ' disabled' : '' ) + '>'
				+ ( resume ? 'Continue test' : 'Start test' ) + '</button>'
				+ ( resume ? '<button class="tbttm-btn" type="button" data-act="restart"' + ( s.busy ? ' disabled' : '' ) + '>Start again</button>' : '' )
				+ ( s.lastResult ? '<span class="tbttm-spacer"></span><button class="tbttm-linkbtn" type="button" data-act="last">See your last result</button>' : '' )
				+ '</div>';
			return html;
		}

		/**
		 * What the student will do: "7 gaps" for a test with only gap
		 * questions, "N questions" for every other test. A draft sends no
		 * questions, so the server says how many gaps it holds.
		 */
		function sizeText( n ) {
			var gaps = T.draft ? ( data.gaps || 0 ) : ( ONLY_GAPS ? POINTS : 0 );
			return gaps ? plural( gaps, 'gap', 'gaps' ) : plural( n, 'question', 'questions' );
		}

		function facts( n ) {
			var size = sizeText( n ).split( ' ' );
			var tick = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
			return '<ul class="tbttm-facts">'
				+ '<li class="tbttm-chip"><strong>' + size[ 0 ] + '</strong> ' + size[ 1 ] + '</li>'
				+ ( T.passMark ? '<li class="tbttm-chip"><strong>' + T.passMark + '%</strong> to pass</li>' : '' )
				+ '<li class="tbttm-chip">' + tick + 'Answers saved as you go</li>'
				+ '</ul>';
		}

		function tiles() {
			var n = per();
			var pages = Math.ceil( N / n );
			var page = Math.min( pages - 1, Math.max( 0, s.page ) );
			var from = page * n;
			var to = Math.min( N, from + n );
			var pop = s.justDone;
			s.justDone = -1;
			var html = '<div class="tbttm-tiles">';
			if ( pages > 1 ) {
				html += '<button type="button" class="tbttm-tiles__page" data-page="' + ( page - 1 ) + '"' + ( page === 0 ? ' disabled' : '' ) + ' aria-label="Earlier questions">‹</button>';
			}
			html += '<div class="tbttm-tiles__row" style="grid-template-columns:repeat(' + n + ',minmax(0,1fr))" aria-label="Questions ' + ( from + 1 ) + ' to ' + to + ' of ' + N + '">';
			for ( var k = 0; k < n; k++ ) {
				var i = from + k;
				if ( i >= N ) {
					html += '<span class="tbttm-tile tbttm-tile--gap" aria-hidden="true"></span>';
					continue;
				}
				var id = qid( i );
				var isDone = done( Q[ i ] );
				var cls = '';
				if ( T.feedback === 'now' && s.checked[ id ] ) {
					// Green only when every gap is right.
					cls = allRight( Q[ i ] ) ? ' is-good' : ' is-bad';
				} else if ( isDone ) {
					cls = ' is-done';
				}
				html += '<button type="button" class="tbttm-tile' + cls + ( i === s.i ? ' is-here' : '' ) + ( i === pop ? ' is-pop' : '' ) + '" data-go="' + i + '"'
					+ ' aria-label="Question ' + ( i + 1 ) + ( isDone ? ', answered' : '' ) + '"' + ( i === s.i ? ' aria-current="step"' : '' ) + '>' + ( i + 1 ) + '</button>';
			}
			html += '</div>';
			if ( pages > 1 ) {
				html += '<button type="button" class="tbttm-tiles__page" data-page="' + ( page + 1 ) + '"' + ( page === pages - 1 ? ' disabled' : '' ) + ' aria-label="Later questions">›</button>';
			}
			return html + '</div>';
		}

		/** The gap question on screen: its text with a field in each gap. */
		function gapBody( q, lock ) {
			var list = words( q );
			var verdict = lock && s.revealed[ q.id ] ? s.revealed[ q.id ].right || [] : [];
			return formatGaps( q.body, q.gaps, function ( g ) {
				return '<input class="tbttm-gap' + ( lock ? ( verdict[ g ] ? ' is-right' : ' is-wrong' ) : '' ) + '" type="text"'
					+ ' id="tbttm-g-' + T.id + '-' + g + '" data-g="' + g + '" value="' + esc( list[ g ] ) + '" maxlength="' + GAP_MAX + '"'
					+ ' style="width:' + gapWidth( list[ g ] ) + '"' + ( lock ? ' disabled' : '' )
					+ ' autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"'
					+ ' aria-label="Gap ' + ( g + 1 ) + ' of ' + q.gaps + ( lock ? ( verdict[ g ] ? ', correct' : ', incorrect' ) : '' ) + '">';
			} );
		}

		/** What a checked gap question says: "Correct." or the points and the right words. */
		function gapNotice( q ) {
			var r = s.revealed[ q.id ] || {};
			var verdict = r.right || [];
			var correct = r.correct || [];
			var won = verdict.filter( Boolean ).length;
			if ( won === q.gaps ) {
				return '<div class="tbttm-notice tbttm-notice--ok" role="status"><p><strong>Correct.</strong></p></div>';
			}
			var wrong = [];
			for ( var g = 0; g < q.gaps; g++ ) {
				if ( ! verdict[ g ] ) {
					wrong.push( '<strong>' + esc( correct[ g ] ) + '</strong>' );
				}
			}
			return '<div class="tbttm-notice tbttm-notice--error" role="status"><p><strong>'
				+ ( q.gaps > 1 ? won + ' of ' + q.gaps + ' points.' : 'Not quite.' ) + '</strong> Correct: ' + wrong.join( ', ' ) + '</p></div>';
		}

		/** The unanswered warning's content, or '' when nothing is missing. */
		function warnInner() {
			var open = missing();
			if ( ! s.warn || ! open.length ) {
				return '';
			}
			var force = '<button class="tbttm-btn" type="button" data-act="force">Finish anyway</button>';
			if ( ONE && isGap( Q[ 0 ] ) ) {
				var empty = POINTS - gapsFilled();
				return '<p><strong>' + empty + '</strong> ' + ( empty === 1 ? 'gap is' : 'gaps are' ) + ' still empty.</p>' + force;
			}
			if ( ONE ) {
				return '<p>The question has no answer yet.</p>' + force;
			}
			return '<p>' + ( open.length === 1 ? 'Question' : 'Questions' ) + ' <strong>' + open.join( ', ' ) + '</strong> '
				+ ( open.length === 1 ? 'has' : 'have' ) + ' no answer yet.</p>'
				+ '<button class="tbttm-btn" type="button" data-go="' + ( open[ 0 ] - 1 ) + '">Go to question ' + open[ 0 ] + '</button>'
				+ force;
		}

		function questionScreen() {
			var q = Q[ s.i ];
			var id = qid( s.i );
			var gap = isGap( q );
			var a = s.answers[ id ];
			var now = T.feedback === 'now';
			var lock = now && !! s.checked[ id ];
			var right = s.revealed[ id ];
			var last = s.i === N - 1;
			var pic = T.bulls ? stageAt( s.i ) : '';

			var html = '<div class="tbttm-prog">' + ( ONE ? '' : tiles() )
				+ '<div class="tbttm-count"><span data-tbttm-count>' + countText() + '</span>'
				+ '<span class="tbttm-spacer"></span><span data-tbttm-status>' + statusHtml() + '</span></div></div>';

			if ( pic && bulls[ pic ] ) {
				html += '<img class="tbttm-bull" src="' + esc( bulls[ pic ] ) + '" alt="">';
			}
			if ( ! ONE ) {
				html += '<p class="tbttm-qnum">' + ( s.i + 1 ) + '.</p>';
			}

			if ( gap ) {
				// With one question the instruction sits above the text.
				html += ( ONE ? '<p class="tbttm-muted tbttm-ask">Type the missing ' + ( q.gaps === 1 ? 'word' : 'words' ) + ' into the text.</p>' : '' )
					+ '<div class="tbttm-qbody tbttm-qbody--gaps' + ( lock ? ' is-locked' : '' ) + '">' + gapBody( q, lock ) + '</div>';
				if ( lock ) {
					html += gapNotice( q );
				}
			} else {
				html += '<div class="tbttm-qbody">' + formatBody( q.body ) + '</div>';

				html += '<fieldset class="tbttm-opts' + ( lock ? ' is-locked' : '' ) + '"><legend class="tbttm-sr-only">Answers</legend>';
				q.options.forEach( function ( o, k ) {
					var cls = '';
					var tag = '';
					if ( lock ) {
						if ( k === right ) {
							cls = ' is-right';
							tag = '<span class="tbttm-opt__tag">✓ Correct</span>';
						} else if ( k === a ) {
							cls = ' is-wrong';
							tag = '<span class="tbttm-opt__tag">✗ Your answer</span>';
						}
					} else if ( k === a ) {
						cls = ' is-chosen';
					}
					var rid = 'tbttm-o-' + T.id + '-' + k;
					html += '<label class="tbttm-opt' + cls + '" for="' + rid + '">'
						+ '<input type="radio" name="tbttm-ans-' + T.id + '" id="' + rid + '" value="' + k + '"' + ( a === k ? ' checked' : '' ) + ( lock ? ' disabled' : '' ) + '>'
						+ '<span>' + LETTERS[ k ] + ') ' + esc( o ) + '</span>' + tag + '</label>';
				} );
				html += '</fieldset>';

				if ( lock ) {
					html += a === right
						? '<div class="tbttm-notice tbttm-notice--ok" role="status"><p><strong>Correct.</strong></p></div>'
						: '<div class="tbttm-notice tbttm-notice--error" role="status"><p><strong>Not quite.</strong> The correct answer is ' + LETTERS[ right ] + '.</p></div>';
				}
			}
			if ( s.checkFailed ) {
				html += '<div class="tbttm-notice tbttm-notice--error" role="alert"><p><strong>We couldn’t check your ' + ( gap && q.gaps > 1 ? 'answers' : 'answer' ) + '.</strong> Nothing is lost. Check your connection and try again.</p>'
					+ '<button class="tbttm-btn" type="button" data-act="check">Try again</button></div>';
			}
			// Kept in the page even when empty, so typing can update it in place.
			var warn = warnInner();
			html += '<div class="tbttm-notice" data-tbttm-warn' + ( warn ? '' : ' hidden' ) + '>' + warn + '</div>';
			if ( s.failed ) {
				var kept = ONLY_GAPS ? gapsFilled() : answered();
				html += '<div class="tbttm-notice tbttm-notice--error" role="alert"><p><strong>We couldn’t send your answers.</strong> Nothing is lost. All '
					+ kept + ' answers are saved on this device. Check your connection and try again.</p>'
					+ '<button class="tbttm-btn" type="button" data-act="force"' + ( s.busy ? ' disabled' : '' ) + '>Try again</button></div>';
			}

			var next = last
				? '<button class="tbttm-btn tbttm-btn--cta" type="button" data-act="finish" data-tbttm-main' + ( s.busy ? ' disabled' : '' ) + '>Finish test</button>'
				: '<button class="tbttm-btn tbttm-btn--primary" type="button" data-act="next" data-tbttm-main>Next question</button>';
			if ( now && ! lock ) {
				// Enabled as soon as one gap has text: a student who cannot
				// fill every gap is never stuck, and an empty gap is marked wrong.
				next = '<button class="tbttm-btn tbttm-btn--primary" type="button" data-act="check" data-tbttm-main' + ( ! touched( q ) || s.busy ? ' disabled' : '' ) + '>'
					+ ( gap && q.gaps > 1 ? 'Check answers' : 'Check answer' ) + '</button>';
			}
			html += '<div class="tbttm-row">' + ( ONE ? '' : '<button class="tbttm-btn" type="button" data-act="back"' + ( s.i === 0 ? ' disabled' : '' ) + '>Back</button>' )
				+ '<span class="tbttm-spacer"></span>' + next + '</div>';
			return html;
		}

		/**
		 * A gap question in the result: its text with the student's words
		 * put back in. A right word in green; a wrong word crossed out in
		 * red, followed by the right one in green; an empty gap as "(empty)"
		 * crossed out.
		 */
		function gapReview( item, number ) {
			var gaps = Array.isArray( item.gaps ) ? item.gaps : [];
			var given = Array.isArray( item.given ) ? item.given : [];
			var right = Array.isArray( item.right ) ? item.right : [];
			return formatGaps( number + String( item.body || '' ), gaps.length, function ( g ) {
				var word = String( given[ g ] == null ? '' : given[ g ] );
				if ( right[ g ] ) {
					return '<b class="tbttm-word is-right">' + esc( word ) + '</b>';
				}
				var key = Array.isArray( gaps[ g ] ) && gaps[ g ].length ? String( gaps[ g ][ 0 ] ) : '';
				return '<s class="tbttm-word is-wrong">' + ( word.trim() ? esc( word ) : '(empty)' ) + '</s> <b class="tbttm-word is-right">' + esc( key ) + '</b>';
			} );
		}

		function resultScreen() {
			var r = s.result || {};
			var total = r.total || 0;
			var pct = r.percent || 0;
			var set = r.pass_mark !== null && r.pass_mark !== undefined;
			var passed = !! r.passed;
			var msg = pct === 100 ? 'Perfect. Every answer correct.'
				: pct >= 80 ? 'Very good. Just a detail or two to polish.'
				: set && passed ? 'You passed. Look at the answers below to see what to polish.'
				: set ? 'These need more practice. Read the answers below, then try again.'
				: 'Look at the answers below to see what to polish.';

			var html = '';
			if ( set && T.bulls ) {
				var pic = bulls[ passed ? 'pass' : 'fail' ];
				if ( pic ) {
					html += '<img class="tbttm-bull" src="' + esc( pic ) + '" alt="">';
				}
			}
			html += '<div>'
				+ ( set ? '<span class="tbttm-pill ' + ( passed ? 'tbttm-pill--pass' : 'tbttm-pill--fail' ) + '">' + ( passed ? 'Passed' : 'Not passed yet' ) + '</span>' : '' )
				+ '<h2 class="tbttm-stage__title">Here is your result</h2>'
				+ ( r.name ? '<p class="tbttm-result__name">' + esc( r.name ) + '</p>' : '' )
				+ '<div class="tbttm-score"><span class="tbttm-score__big">' + ( r.score || 0 ) + ' / ' + total + '</span><span class="tbttm-score__pct">' + pct + '%</span></div>'
				+ '<p class="tbttm-comment">' + esc( msg ) + '</p></div>';

			html += '<ol class="tbttm-review">';
			var review = r.review || [];
			review.forEach( function ( item, i ) {
				// A test with one question shows no question number.
				var number = review.length === 1 ? '' : ( i + 1 ) + '. ';
				// An entry without a type is multiple choice: every result
				// stored before 0.5.0 reads that way.
				if ( item.type === 'gap' ) {
					var verdicts = Array.isArray( item.right ) ? item.right : [];
					var won = verdicts.filter( Boolean ).length;
					var all = verdicts.length > 0 && won === verdicts.length;
					html += '<li class="tbttm-review__item ' + ( all ? 'is-ok' : 'is-bad' ) + '">'
						+ '<span class="tbttm-review__mark" aria-hidden="true">' + ( all ? '✓' : '✗' ) + '</span>'
						+ '<div class="tbttm-review__q tbttm-review__q--gaps"><span class="tbttm-sr-only">' + ( all ? 'Correct. ' : 'Incorrect. ' ) + '</span>'
						+ gapReview( item, number ) + '</div>'
						+ ( verdicts.length > 1 ? '<span class="tbttm-review__a">· ' + won + ' of ' + verdicts.length + ' points</span>' : '' ) + '</li>';
					return;
				}
				var chosen = item.chosen;
				var ok = chosen !== null && chosen !== undefined && chosen === item.correct;
				var yours = chosen === null || chosen === undefined ? 'no answer' : esc( item.options[ chosen ] );
				var correct = item.correct === null || item.correct === undefined ? '' : esc( item.options[ item.correct ] );
				html += '<li class="tbttm-review__item ' + ( ok ? 'is-ok' : 'is-bad' ) + '">'
					+ '<span class="tbttm-review__mark" aria-hidden="true">' + ( ok ? '✓' : '✗' ) + '</span>'
					+ '<div class="tbttm-review__q"><span class="tbttm-sr-only">' + ( ok ? 'Correct. ' : 'Incorrect. ' ) + '</span>'
					+ formatBody( number + item.body ) + '</div>'
					+ '<span class="tbttm-review__a">' + ( ok
						? 'Your answer: <b>' + yours + '</b>'
						: 'Your answer: <b class="is-yours">' + yours + '</b> · Correct: <b class="is-right">' + correct + '</b>' ) + '</span></li>';
			} );
			html += '</ol>';

			if ( s.startError ) {
				html += '<div class="tbttm-notice tbttm-notice--error" role="alert"><p>' + esc( s.startError ) + '</p></div>';
			}
			if ( s.certError ) {
				html += '<div class="tbttm-notice tbttm-notice--error" role="alert"><p>We couldn’t make your certificate. Try again in a moment.</p></div>';
			}
			html += '<div class="tbttm-row"><button class="tbttm-btn tbttm-btn--cta" type="button" data-act="start"' + ( s.busy ? ' disabled' : '' ) + '>Try again</button>'
				+ ( r.certificate && r.token
					? '<button class="tbttm-btn" type="button" data-act="certificate"' + ( s.certBusy ? ' disabled' : '' ) + '>' + ( s.certBusy ? 'Making your certificate…' : 'Download certificate' ) + '</button>'
					: '' )
				+ '</div>';
			return html;
		}

		function render() {
			var shell = root.closest( '.tbttm' );
			if ( shell ) {
				shell.classList.toggle( 'is-started', s.view !== 'start' );
			}
			root.innerHTML = s.view === 'quiz' ? questionScreen() : s.view === 'result' ? resultScreen() : startScreen();
		}

		/* ----- events ----- */

		root.addEventListener( 'change', function ( e ) {
			var t = e.target;
			if ( t.type === 'radio' && t.name === 'tbttm-ans-' + T.id ) {
				choose( +t.value );
			}
		} );

		// The name field keeps its own state between renders, so typing never
		// loses the caret. The red mark goes as soon as the guest types.
		root.addEventListener( 'input', function ( e ) {
			var t = e.target;
			if ( ! t.hasAttribute ) {
				return;
			}
			if ( t.classList && t.classList.contains( 'tbttm-gap' ) ) {
				type( t );
				return;
			}
			var isName = t.hasAttribute( 'data-tbttm-name' );
			var isEmail = t.hasAttribute( 'data-tbttm-email' );
			if ( ! isName && ! isEmail ) {
				return;
			}
			if ( isName ) {
				s.name = t.value;
			} else {
				s.email = t.value;
			}
			if ( isName ? s.nameError : s.emailError ) {
				if ( isName ) {
					s.nameError = false;
				} else {
					s.emailError = false;
				}
				t.removeAttribute( 'aria-invalid' );
				var box = root.querySelector( isName ? '[data-tbttm-namebox]' : '[data-tbttm-emailbox]' );
				var hint = root.querySelector( isName ? '[data-tbttm-namehint]' : '[data-tbttm-emailhint]' );
				if ( box ) {
					box.classList.remove( 'is-bad' );
				}
				if ( hint ) {
					hint.removeAttribute( 'role' );
					hint.textContent = isName ? NAME_HINT : EMAIL_HINT;
				}
			}
		} );

		// Enter in a gap moves to the next gap; in the last gap it does what
		// the main button does.
		root.addEventListener( 'keydown', function ( e ) {
			var t = e.target;
			if ( e.key !== 'Enter' || e.isComposing || ! t.classList || ! t.classList.contains( 'tbttm-gap' ) ) {
				return;
			}
			e.preventDefault();
			var fields = Array.prototype.slice.call( root.querySelectorAll( '.tbttm-gap:not(:disabled)' ) );
			var at = fields.indexOf( t );
			if ( at >= 0 && at < fields.length - 1 ) {
				fields[ at + 1 ].focus();
				return;
			}
			var main = root.querySelector( '[data-tbttm-main]' );
			if ( main && ! main.disabled ) {
				main.click();
			}
		} );

		root.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && e.target.hasAttribute && ( e.target.hasAttribute( 'data-tbttm-name' ) || e.target.hasAttribute( 'data-tbttm-email' ) ) ) {
				e.preventDefault();
				start( false );
			}
		} );

		root.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( 'button' );
			if ( ! b || ! root.contains( b ) || b.disabled ) {
				return;
			}
			if ( b.dataset.page !== undefined ) {
				s.page = +b.dataset.page;
				render();
				return;
			}
			if ( b.dataset.go !== undefined ) {
				leave( +b.dataset.go );
				return;
			}
			switch ( b.dataset.act ) {
				case 'start':
					start( false );
					break;
				case 'restart':
					start( true );
					break;
				case 'last':
					showResult( s.lastResult );
					break;
				case 'certificate':
					downloadCertificate();
					break;
				case 'check':
					check();
					break;
				case 'next':
					leave( s.i + 1 );
					break;
				case 'back':
					leave( s.i - 1 );
					break;
				case 'finish':
					finish( false );
					break;
				case 'force':
					finish( true );
					break;
			}
		} );

		window.addEventListener( 'online', function () {
			if ( hasUnsent() ) {
				saveNow();
			}
		} );

		window.addEventListener( 'beforeunload', function ( e ) {
			if ( hasUnsent() ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );

		PHONE.addEventListener( 'change', function () {
			if ( s.view === 'quiz' ) {
				go( s.i );
				render();
			}
		} );

		render();
		// A guest's attempt is fetched when they press CONTINUE TEST, with
		// their token; there is nothing to look up for them before that.
		if ( ! T.draft && ! GUEST ) {
			syncOnLoad();
		}
		// A link from the result email: the server has checked the token is
		// a finished attempt of this test that this visitor may see.
		if ( ! T.draft && /^[a-f0-9]{32}$/.test( String( data.openResult || '' ) ) ) {
			showResult( data.openResult );
		}
	}

	function init() {
		document.querySelectorAll( '[data-tbttm-player]' ).forEach( function ( root ) {
			if ( ! root.dataset.tbttmReady ) {
				root.dataset.tbttmReady = '1';
				Player( root );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
