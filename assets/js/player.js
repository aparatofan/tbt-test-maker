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
				var err = { code: ( data && data.code ) || 'http_' + res.status, status: res.status, data: ( data && data.data ) || {} };
				if ( err.code === 'rest_cookie_invalid_nonce' && ! retried ) {
					return refreshNonce().then( function () {
						return api( method, path, body, true );
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
		};
		var saveTimer = null;
		var retryTimer = null;

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
			api( 'PUT', 'attempts/' + s.attempt.id, { answers: sent } ).then( function () {
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

		function start( restart ) {
			if ( s.busy ) {
				return;
			}
			s.busy = true;
			s.startError = '';
			if ( restart ) {
				clearLocal();
			}
			render();
			api( 'POST', 'attempts', { test: T.id, restart: !! restart } ).then( function ( state ) {
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
				render();
				scrollToTop();
			}, function () {
				s.busy = false;
				s.startError = 'We couldn’t start the test. Check your connection and try again.';
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
			api( 'POST', 'attempts', { test: T.id } ).then( function ( state ) {
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
			api( 'POST', 'attempts/' + s.attempt.id + '/check', { question: +id, answer: s.answers[ id ] } ).then( function ( res ) {
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
			api( 'POST', 'attempts/' + s.attempt.id + '/finish', { answers: s.answers } ).then( function ( result ) {
				s.busy = false;
				s.failed = false;
				s.dirty = {};
				clearTimeout( retryTimer );
				// Only now, with the result stored on the server.
				clearLocal();
				s.resume = null;
				s.lastResult = result.token || s.lastResult;
				s.result = result;
				s.view = 'result';
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
			api( 'GET', 'attempts/' + token + '/result' ).then( function ( result ) {
				s.busy = false;
				s.result = result;
				s.view = 'result';
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

		function startScreen() {
			if ( T.draft ) {
				return '<div><h2 class="tbttm-stage__title">This test is a draft</h2>'
					+ '<p class="tbttm-lead">Students can’t see it yet. Publish it on the teacher page to take it or share it.</p></div>'
					+ facts( data.count || 0 );
			}
			if ( ! N ) {
				return '<p class="tbttm-muted">This test has no questions yet.</p>';
			}
			var resume = s.resume && s.resume.answered > 0;
			var lead = resume
				? 'You answered ' + s.resume.answered + ' of ' + N + ' questions last time. Your answers are still here.'
				: 'Read each question and choose one answer.' + ( T.feedback === 'now'
					? ' You see the correct answer after each question.'
					: ' You can go back and change an answer at any time.' );
			var html = '';
			if ( T.image ) {
				html += '<img class="tbttm-feature" src="' + esc( T.image ) + '" alt="">';
			}
			html += '<div><h2 class="tbttm-stage__title">' + ( resume ? 'Welcome back' : 'Before you start' ) + '</h2>'
				+ '<p class="tbttm-lead">' + esc( lead ) + '</p></div>';
			html += facts( N );
			if ( s.startError ) {
				html += '<div class="tbttm-notice tbttm-notice--error" role="alert"><p>' + esc( s.startError ) + '</p></div>';
			}
			if ( s.resultError ) {
				html += '<div class="tbttm-notice tbttm-notice--error" role="alert"><p>' + esc( s.resultError ) + '</p></div>';
			}
			html += '<div class="tbttm-row">'
				+ '<button class="tbttm-btn tbttm-btn--cta" type="button" data-act="start"' + ( s.busy ? ' disabled' : '' ) + '>'
				+ ( resume ? 'Continue test' : 'Start test' ) + '</button>'
				+ ( resume ? '<button class="tbttm-btn" type="button" data-act="restart"' + ( s.busy ? ' disabled' : '' ) + '>Start again</button>' : '' )
				+ '</div>';
			if ( s.lastResult ) {
				html += '<p><button class="tbttm-linkbtn" type="button" data-act="last">See your last result</button></p>';
			}
			return html;
		}

		function facts( n ) {
			return '<ul class="tbttm-facts">'
				+ '<li><strong>' + n + '</strong> ' + ( n === 1 ? 'question' : 'questions' ) + '</li>'
				+ ( T.passMark ? '<li><strong>' + T.passMark + '%</strong> to pass</li>' : '' )
				+ '<li>Answers are <strong>saved as you go</strong></li>'
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
		if ( ! T.draft ) {
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
