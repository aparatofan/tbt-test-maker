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
			answers: {},        // question ID → option index
			dirty: {},          // question IDs the server has not confirmed
			checked: {},        // question IDs checked ("after each question")
			revealed: {},       // question ID → correct index, once checked
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
					? { attempt: +v.attempt, token: String( v.token ), name: String( v.name || '' ) }
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
			return Q.filter( function ( q ) { return answers[ q.id ] !== undefined; } ).length;
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

		function answered() {
			return Q.filter( function ( q ) { return s.answers[ q.id ] !== undefined; } ).length;
		}

		function missing() {
			var out = [];
			Q.forEach( function ( q, i ) {
				if ( s.answers[ q.id ] === undefined ) {
					out.push( i + 1 );
				}
			} );
			return out;
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
					sent[ id ] = s.answers[ id ];
				}
			} );
			s.saving = true;
			updateStatus();
			call( 'PUT', 'attempts/' + s.attempt.id, { answers: sent } ).then( function () {
				s.saving = false;
				s.offline = false;
				clearTimeout( retryTimer );
				Object.keys( sent ).forEach( function ( id ) {
					if ( s.answers[ id ] === sent[ id ] ) {
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
			Object.keys( state.answers || {} ).forEach( function ( id ) {
				s.answers[ id ] = state.answers[ id ];
			} );

			var local = readLocal();
			if ( local && local.attempt === state.id ) {
				var unsent = {};
				( local.dirty || [] ).forEach( function ( id ) { unsent[ id ] = true; } );
				Object.keys( local.answers ).forEach( function ( id ) {
					var known = Q.some( function ( q ) { return String( q.id ) === id; } );
					if ( ! known || s.checked[ id ] ) {
						return;
					}
					if ( s.answers[ id ] === undefined || ( unsent[ id ] && s.answers[ id ] !== local.answers[ id ] ) ) {
						s.answers[ id ] = local.answers[ id ];
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
				return T.feedback === 'now' ? ! s.checked[ q.id ] : s.answers[ q.id ] === undefined;
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

		function focusName() {
			var field = document.getElementById( NAME_ID );
			if ( field ) {
				field.focus( { preventScroll: true } );
			}
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
			if ( name.length < 2 ) {
				// Try again on a result opened later: back to the start
				// screen, where the name is asked, without scolding.
				s.nameError = s.view === 'start';
				s.view = 'start';
				render();
				focusName();
				return;
			}
			newGuestAttempt( name, null, !! restart );
		}

		/**
		 * A new attempt under this name. `carry` holds answers from an attempt
		 * the server no longer knows; they go to the new one at once.
		 */
		function newGuestAttempt( name, carry, restart ) {
			s.busy = true;
			s.startError = '';
			s.nameError = false;
			render();
			call( 'POST', 'attempts', { test: T.id, name: name } ).then( function ( state ) {
				guest = { attempt: state.id, token: state.token, name: name };
				writeGuest();
				s.name = name;
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
					newGuestAttempt( guest.name, answers, false );
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
				s.resume.answered = Math.max( s.resume.answered, Object.keys( local.answers ).length );
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

		function check() {
			var id = qid( s.i );
			if ( s.busy || s.answers[ id ] === undefined || s.checked[ id ] ) {
				return;
			}
			s.busy = true;
			s.checkFailed = false;
			render();
			call( 'POST', 'attempts/' + s.attempt.id + '/check', { question: +id, answer: s.answers[ id ] } ).then( function ( res ) {
				s.busy = false;
				s.checked[ id ] = true;
				s.revealed[ id ] = res.correct;
				if ( res.answer !== null && res.answer !== undefined ) {
					s.answers[ id ] = res.answer;
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
				+ '<p class="tbttm-eyebrow">Test · ' + plural( n, 'question', 'questions' ) + '</p>'
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
				html += '<p class="tbttm-lead">Read each question and choose one answer. ' + ( T.feedback === 'now'
					? 'You see the correct answer after each question.'
					: 'You can go back and change an answer at any time before you finish.' ) + '</p>';
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
			html += '<div class="tbttm-row tbttm-start__actions">'
				+ '<button class="tbttm-btn tbttm-btn--cta" type="button" data-act="start"' + ( s.busy ? ' disabled' : '' ) + '>'
				+ ( resume ? 'Continue test' : 'Start test' ) + '</button>'
				+ ( resume ? '<button class="tbttm-btn" type="button" data-act="restart"' + ( s.busy ? ' disabled' : '' ) + '>Start again</button>' : '' )
				+ ( s.lastResult ? '<span class="tbttm-spacer"></span><button class="tbttm-linkbtn" type="button" data-act="last">See your last result</button>' : '' )
				+ '</div>';
			return html;
		}

		function facts( n ) {
			var tick = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
			return '<ul class="tbttm-facts">'
				+ '<li class="tbttm-chip"><strong>' + n + '</strong> ' + ( n === 1 ? 'question' : 'questions' ) + '</li>'
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
				var done = s.answers[ id ] !== undefined;
				var cls = '';
				if ( T.feedback === 'now' && s.checked[ id ] ) {
					cls = s.answers[ id ] === s.revealed[ id ] ? ' is-good' : ' is-bad';
				} else if ( done ) {
					cls = ' is-done';
				}
				html += '<button type="button" class="tbttm-tile' + cls + ( i === s.i ? ' is-here' : '' ) + ( i === pop ? ' is-pop' : '' ) + '" data-go="' + i + '"'
					+ ' aria-label="Question ' + ( i + 1 ) + ( done ? ', answered' : '' ) + '"' + ( i === s.i ? ' aria-current="step"' : '' ) + '>' + ( i + 1 ) + '</button>';
			}
			html += '</div>';
			if ( pages > 1 ) {
				html += '<button type="button" class="tbttm-tiles__page" data-page="' + ( page + 1 ) + '"' + ( page === pages - 1 ? ' disabled' : '' ) + ' aria-label="Later questions">›</button>';
			}
			return html + '</div>';
		}

		function questionScreen() {
			var q = Q[ s.i ];
			var id = qid( s.i );
			var a = s.answers[ id ];
			var now = T.feedback === 'now';
			var lock = now && !! s.checked[ id ];
			var right = s.revealed[ id ];
			var last = s.i === N - 1;
			var pic = T.bulls ? stageAt( s.i ) : '';
			var gaps = missing();

			var html = '<div class="tbttm-prog">' + tiles()
				+ '<div class="tbttm-count"><span>Question ' + ( s.i + 1 ) + ' of ' + N + ' · ' + ( N - answered() ) + ' left</span>'
				+ '<span class="tbttm-spacer"></span><span data-tbttm-status>' + statusHtml() + '</span></div></div>';

			if ( pic && bulls[ pic ] ) {
				html += '<img class="tbttm-bull" src="' + esc( bulls[ pic ] ) + '" alt="">';
			}
			html += '<p class="tbttm-qnum">' + ( s.i + 1 ) + '.</p>';
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
			if ( s.checkFailed ) {
				html += '<div class="tbttm-notice tbttm-notice--error" role="alert"><p><strong>We couldn’t check your answer.</strong> Nothing is lost. Check your connection and try again.</p>'
					+ '<button class="tbttm-btn" type="button" data-act="check">Try again</button></div>';
			}
			if ( s.warn && gaps.length ) {
				html += '<div class="tbttm-notice"><p>' + ( gaps.length === 1 ? 'Question' : 'Questions' ) + ' <strong>' + gaps.join( ', ' ) + '</strong> '
					+ ( gaps.length === 1 ? 'has' : 'have' ) + ' no answer yet.</p>'
					+ '<button class="tbttm-btn" type="button" data-go="' + ( gaps[ 0 ] - 1 ) + '">Go to question ' + gaps[ 0 ] + '</button>'
					+ '<button class="tbttm-btn" type="button" data-act="force">Finish anyway</button></div>';
			}
			if ( s.failed ) {
				html += '<div class="tbttm-notice tbttm-notice--error" role="alert"><p><strong>We couldn’t send your answers.</strong> Nothing is lost. All '
					+ answered() + ' answers are saved on this device. Check your connection and try again.</p>'
					+ '<button class="tbttm-btn" type="button" data-act="force"' + ( s.busy ? ' disabled' : '' ) + '>Try again</button></div>';
			}

			var next = last
				? '<button class="tbttm-btn tbttm-btn--cta" type="button" data-act="finish"' + ( s.busy ? ' disabled' : '' ) + '>Finish test</button>'
				: '<button class="tbttm-btn tbttm-btn--primary" type="button" data-act="next">Next question</button>';
			if ( now && ! lock ) {
				next = '<button class="tbttm-btn tbttm-btn--primary" type="button" data-act="check"' + ( a === undefined || s.busy ? ' disabled' : '' ) + '>Check answer</button>';
			}
			html += '<div class="tbttm-row"><button class="tbttm-btn" type="button" data-act="back"' + ( s.i === 0 ? ' disabled' : '' ) + '>Back</button>'
				+ '<span class="tbttm-spacer"></span>' + next + '</div>';
			return html;
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
			( r.review || [] ).forEach( function ( item, i ) {
				var chosen = item.chosen;
				var ok = chosen !== null && chosen !== undefined && chosen === item.correct;
				var yours = chosen === null || chosen === undefined ? 'no answer' : esc( item.options[ chosen ] );
				var correct = item.correct === null || item.correct === undefined ? '' : esc( item.options[ item.correct ] );
				html += '<li class="tbttm-review__item ' + ( ok ? 'is-ok' : 'is-bad' ) + '">'
					+ '<span class="tbttm-review__mark" aria-hidden="true">' + ( ok ? '✓' : '✗' ) + '</span>'
					+ '<div class="tbttm-review__q"><span class="tbttm-sr-only">' + ( ok ? 'Correct. ' : 'Incorrect. ' ) + '</span>'
					+ formatBody( ( i + 1 ) + '. ' + item.body ) + '</div>'
					+ '<span class="tbttm-review__a">' + ( ok
						? 'Your answer: <b>' + yours + '</b>'
						: 'Your answer: <b class="is-yours">' + yours + '</b> · Correct: <b class="is-right">' + correct + '</b>' ) + '</span></li>';
			} );
			html += '</ol>';

			if ( s.startError ) {
				html += '<div class="tbttm-notice tbttm-notice--error" role="alert"><p>' + esc( s.startError ) + '</p></div>';
			}
			html += '<div class="tbttm-row"><button class="tbttm-btn tbttm-btn--cta" type="button" data-act="start"' + ( s.busy ? ' disabled' : '' ) + '>Try again</button></div>';
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
			if ( ! t.hasAttribute || ! t.hasAttribute( 'data-tbttm-name' ) ) {
				return;
			}
			s.name = t.value;
			if ( s.nameError ) {
				s.nameError = false;
				t.removeAttribute( 'aria-invalid' );
				var box = root.querySelector( '[data-tbttm-namebox]' );
				var hint = root.querySelector( '[data-tbttm-namehint]' );
				if ( box ) {
					box.classList.remove( 'is-bad' );
				}
				if ( hint ) {
					hint.removeAttribute( 'role' );
					hint.textContent = NAME_HINT;
				}
			}
		} );

		root.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && e.target.hasAttribute && e.target.hasAttribute( 'data-tbttm-name' ) ) {
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
				go( +b.dataset.go );
				s.warn = false;
				s.checkFailed = false;
				render();
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
				case 'check':
					check();
					break;
				case 'next':
					go( s.i + 1 );
					s.warn = false;
					s.checkFailed = false;
					render();
					break;
				case 'back':
					go( s.i - 1 );
					s.warn = false;
					s.checkFailed = false;
					render();
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
