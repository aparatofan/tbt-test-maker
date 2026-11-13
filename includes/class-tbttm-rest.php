<?php
/**
 * REST routes, namespace tbttm/v1.
 *
 * Teacher routes require TBTTM_CAP and are scoped to the current user's own
 * tests, administrators included. Student routes act only on the caller's own
 * attempts. A logged-in student owns an attempt by their user ID; WordPress
 * checks the X-WP-Nonce header itself for cookie authentication. A guest on
 * an open test owns an attempt (user_id = 0) by its token, sent in the
 * X-TBTTM-Token header, and never sends a nonce: the token is not a cookie,
 * so there is nothing for another site to forge. Every write re-checks
 * ownership inside the callback.
 *
 * There is deliberately no public read route for test content. Students only
 * ever receive questions inside the server-rendered student page, after the
 * access check, and never the answer key.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Rest {

	const NS = 'tbttm/v1';

	/** Feature image upload: the largest file accepted, in bytes. */
	const IMAGE_MAX_BYTES = 5242880; // 5 MB.

	/** Feature image: the longest side after resizing, in pixels. */
	const IMAGE_MAX_SIDE = 1600;

	/** Feature image: refuse anything larger before decoding it (40 megapixels). */
	const IMAGE_MAX_PIXELS = 40000000;

	/** Feature image uploads one teacher may make in a day. */
	const IMAGE_DAILY_LIMIT = 50;

	/** Guest attempts one IP address may start on one test in a window. */
	const GUEST_START_LIMIT = 60;

	/** That window, in seconds. */
	const GUEST_START_WINDOW = 600;

	/** A guest's name: the shortest and longest accepted, in characters. */
	const GUEST_NAME_MIN = 2;
	const GUEST_NAME_MAX = 120;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$teacher = array( $this, 'can_manage' );
		$start   = array( $this, 'can_start' );
		$attempt = array( $this, 'can_use_attempt' );
		$result  = array( $this, 'can_read_result' );

		register_rest_route(
			self::NS,
			'/tests',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => $teacher,
					'callback'            => array( $this, 'list_tests' ),
					'args'                => array(
						'search' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'class'  => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => $teacher,
					'callback'            => array( $this, 'create_test' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/tests/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => $teacher,
					'callback'            => array( $this, 'get_test' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => $teacher,
					'callback'            => array( $this, 'update_test' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => $teacher,
					'callback'            => array( $this, 'delete_test' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/tests/(?P<id>\d+)/duplicate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $teacher,
				'callback'            => array( $this, 'duplicate_test' ),
			)
		);

		register_rest_route(
			self::NS,
			'/tests/(?P<id>\d+)/results',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $teacher,
				'callback'            => array( $this, 'get_results' ),
			)
		);

		register_rest_route(
			self::NS,
			'/tests/(?P<id>\d+)/attempts/(?P<attempt>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => $teacher,
					'callback'            => array( $this, 'get_attempt_review' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => $teacher,
					'callback'            => array( $this, 'remove_attempt' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/classes/(?P<id>\d+)/lessons',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $teacher,
				'callback'            => array( $this, 'get_lessons' ),
			)
		);

		register_rest_route(
			self::NS,
			'/images',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $teacher,
				'callback'            => array( $this, 'upload_image' ),
			)
		);

		register_rest_route(
			self::NS,
			'/attempts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $start,
				'callback'            => array( $this, 'start_attempt' ),
			)
		);

		register_rest_route(
			self::NS,
			'/attempts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => $attempt,
				'callback'            => array( $this, 'save_answers' ),
			)
		);

		register_rest_route(
			self::NS,
			'/attempts/(?P<id>\d+)/check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $attempt,
				'callback'            => array( $this, 'check_answer' ),
			)
		);

		register_rest_route(
			self::NS,
			'/attempts/(?P<id>\d+)/finish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $attempt,
				'callback'            => array( $this, 'finish_attempt' ),
			)
		);

		register_rest_route(
			self::NS,
			'/attempts/(?P<token>[a-f0-9]{32})/result',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $result,
				'callback'            => array( $this, 'get_result' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------- */

	public function can_manage() {
		return TBTTM_Capabilities::user_can_manage();
	}

	/**
	 * May this request start an attempt? Any logged-in user, as before; a
	 * logged-out visitor only on a published test that is open to anyone
	 * with the link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_start( WP_REST_Request $request ) {
		if ( is_user_logged_in() ) {
			return true;
		}
		$test = TBTTM_DB::get_test( (int) $request->get_param( 'test' ) );
		if ( $test && TBTTM_DB::STATUS_PUBLISHED === $test->status && 'open' === $test->access ) {
			return true;
		}
		return self::login_required();
	}

	/**
	 * May this request touch the attempt in the URL? Whose attempt it is gets
	 * settled in the callback, by own_attempt(); this only decides whether a
	 * logged-out request may reach it at all.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_use_attempt( WP_REST_Request $request ) {
		return is_user_logged_in() ? true : self::guest_may_reach( TBTTM_DB::get_attempt( (int) $request['id'] ) );
	}

	/**
	 * May this request read the result behind the token in the URL?
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_read_result( WP_REST_Request $request ) {
		return is_user_logged_in() ? true : self::guest_may_reach( TBTTM_DB::get_attempt_by_token( (string) $request['token'] ) );
	}

	/**
	 * A logged-out request reaches an attempt when the attempt is a guest's,
	 * or its test is open. A guest attempt stays reachable after its test goes
	 * back to logged-in students: an attempt in progress can still be saved
	 * and finished. An unknown attempt goes through to the callback, which
	 * answers 404 as it does for everyone.
	 *
	 * @param object|null $attempt Attempt row.
	 * @return true|WP_Error
	 */
	private static function guest_may_reach( $attempt ) {
		if ( ! $attempt || (int) $attempt->user_id <= 0 ) {
			return true;
		}
		$test = TBTTM_DB::get_test( (int) $attempt->test_id );
		if ( $test && 'open' === $test->access ) {
			return true;
		}
		return self::login_required();
	}

	private static function login_required() {
		return new WP_Error( 'tbttm_login_required', __( 'Log in to take this test.', 'tbt-test-maker' ), array( 'status' => 401 ) );
	}

	/* ---------------------------------------------------------------------
	 * Payloads
	 * ------------------------------------------------------------------- */

	/**
	 * One library card.
	 *
	 * @param object $row Test row with question_count and attempt_count.
	 * @return array
	 */
	public static function test_summary( $row ) {
		$class_title = self::class_title( (int) $row->class_id );
		$in_class    = '' !== $class_title;
		return array(
			'id'          => (int) $row->id,
			'slug'        => (string) $row->slug,
			'title'       => (string) $row->title,
			'status'      => (string) $row->status,
			'questions'   => (int) $row->question_count,
			'attempts'    => (int) $row->attempt_count,
			'access'      => 'open' === $row->access ? 'open' : 'login',
			// A class that no longer resolves (deleted in Notes, or Notes is
			// off) reads as no class. The stored column is left alone.
			'class_id'    => $in_class ? (int) $row->class_id : 0,
			'class_title' => $class_title,
			'lesson_id'   => $in_class ? (int) $row->lesson_id : 0,
			'pass_mark'   => null === $row->pass_mark ? null : (int) $row->pass_mark,
			'url'         => TBTTM_DB::STATUS_PUBLISHED === $row->status ? TBTTM_Urls::test_url( $row->slug ) : '',
			'edit_url'    => TBTTM_Urls::edit_url( (int) $row->id ),
			'results_url' => TBTTM_Urls::results_url( (int) $row->id ),
			'edited'      => self::edited( (string) $row->updated_at ),
		);
	}

	/**
	 * A class's title, or '' when it cannot be resolved. Looked up once per
	 * request: a library of forty tests in three classes costs three queries.
	 *
	 * @param int $class_id Notes class ID.
	 * @return string
	 */
	public static function class_title( $class_id ) {
		static $titles = array();
		$class_id = (int) $class_id;
		if ( $class_id <= 0 ) {
			return '';
		}
		if ( ! isset( $titles[ $class_id ] ) ) {
			$titles[ $class_id ] = TBTTM_Classes::class_title( $class_id );
		}
		return $titles[ $class_id ];
	}

	/**
	 * Lessons as the builder's select wants them: id and title, in the order
	 * Notes returns them (newest first).
	 *
	 * @param int $class_id Notes class ID.
	 * @return array[]
	 */
	public static function lessons( $class_id ) {
		$out = array();
		foreach ( TBTTM_Classes::lessons_for_class( (int) $class_id ) as $lesson ) {
			$out[] = array(
				'id'    => (int) ( $lesson['id'] ?? 0 ),
				'title' => (string) ( $lesson['header'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * A teacher's classes for the builder's select, A–Z with accents folded,
	 * so "Łukasz" sorts under L. Empty when Notes is unavailable.
	 *
	 * @param int $user_id Teacher.
	 * @return array[] Each: id, title.
	 */
	public static function classes_for( $user_id ) {
		$out = array();
		foreach ( TBTTM_Classes::for_teacher( (int) $user_id ) as $class ) {
			$out[] = array(
				'id'    => (int) ( $class['id'] ?? 0 ),
				'title' => (string) ( $class['title'] ?? '' ),
			);
		}
		usort( $out, array( __CLASS__, 'by_title' ) );
		return $out;
	}

	/**
	 * Sort callback: by title, natural order, case and accents ignored.
	 *
	 * @param array $a Item with a title.
	 * @param array $b Item with a title.
	 * @return int
	 */
	public static function by_title( $a, $b ) {
		return strnatcasecmp( remove_accents( $a['title'] ), remove_accents( $b['title'] ) );
	}

	/**
	 * "5 Nov 2026": a stored UTC time as a date in the site's time zone.
	 *
	 * @param string $gmt MySQL datetime, UTC.
	 * @return string
	 */
	public static function edited( $gmt ) {
		if ( '' === $gmt || 0 === strpos( $gmt, '0000' ) ) {
			return '';
		}
		return (string) mysql2date( 'j M Y', get_date_from_gmt( $gmt ) );
	}

	/**
	 * A whole test for the builder, answer key included. Only ever sent to
	 * the test's owner.
	 *
	 * @param object  $row       Test row.
	 * @param array[] $questions Questions.
	 * @return array
	 */
	public static function test_payload( $row, array $questions ) {
		$image_id = (int) $row->image_id;
		$image    = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'large' ) : '';
		// The class is offered back only while the owner can still pick it.
		$class_id = TBTTM_Classes::user_owns_class( (int) $row->owner_id, (int) $row->class_id ) ? (int) $row->class_id : 0;
		return array(
			'id'        => (int) $row->id,
			'slug'      => (string) $row->slug,
			'title'     => (string) $row->title,
			'intro'     => (string) $row->intro,
			'image_id'  => $image ? $image_id : 0,
			'image_url' => $image ? (string) $image : '',
			'status'    => (string) $row->status,
			'feedback'  => (string) $row->feedback,
			'bulls'     => (bool) (int) $row->bulls,
			'pass_mark' => null === $row->pass_mark ? null : (int) $row->pass_mark,
			'access'    => 'open' === $row->access ? 'open' : 'login',
			'class_id'  => $class_id,
			'lesson_id' => $class_id ? (int) $row->lesson_id : 0,
			// The saved class's lessons, so the builder opens with the lesson
			// in place and needs no request to show it.
			'lessons'   => $class_id ? self::lessons( $class_id ) : array(),
			'url'       => TBTTM_DB::STATUS_PUBLISHED === $row->status ? TBTTM_Urls::test_url( $row->slug ) : '',
			'attempts'  => TBTTM_DB::count_finished_attempts( (int) $row->id ),
			'questions' => array_values( $questions ),
		);
	}

	/**
	 * Case- and accent-insensitive form of a string, for search. Folds Polish
	 * letters too (ł → l), which remove_accents() covers.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function fold( $text ) {
		return mb_strtolower( remove_accents( (string) $text ), 'UTF-8' );
	}

	/* ---------------------------------------------------------------------
	 * Teacher routes
	 * ------------------------------------------------------------------- */

	/**
	 * The user's library: every test's summary, newest first, narrowed by a
	 * search and by a class. The search looks in the title, in the class
	 * title and inside every question. Used by the route and by the server's
	 * first paint, so the two cannot disagree.
	 *
	 * @param int    $user_id Owner.
	 * @param string $search  Search text, or ''.
	 * @param string $class   A class ID, 'none' for tests in no class, or ''.
	 * @return array {
	 *     total   int      All of the user's tests.
	 *     tests   array[]  Matching summaries.
	 *     classes array[]  Filter options: classes that have a test, A–Z,
	 *                      each id, title, count.
	 *     unclassed int    Tests in no class.
	 * }
	 */
	public static function library( $user_id, $search = '', $class = '' ) {
		$rows   = TBTTM_DB::get_owned_tests( $user_id );
		$tests  = array_map( array( __CLASS__, 'test_summary' ), $rows );
		$needle = self::fold( trim( (string) $search ) );
		$class  = (string) $class;

		$classes   = array();
		$unclassed = 0;
		foreach ( $tests as $t ) {
			if ( ! $t['class_id'] ) {
				++$unclassed;
				continue;
			}
			if ( ! isset( $classes[ $t['class_id'] ] ) ) {
				$classes[ $t['class_id'] ] = array(
					'id'    => $t['class_id'],
					'title' => $t['class_title'],
					'count' => 0,
				);
			}
			++$classes[ $t['class_id'] ]['count'];
		}
		$classes = array_values( $classes );
		usort( $classes, array( __CLASS__, 'by_title' ) );

		if ( 'none' === $class ) {
			$tests = array_filter(
				$tests,
				static function ( $t ) {
					return ! $t['class_id'];
				}
			);
		} elseif ( ctype_digit( $class ) && (int) $class > 0 ) {
			$tests = array_filter(
				$tests,
				static function ( $t ) use ( $class ) {
					return (int) $class === $t['class_id'];
				}
			);
		}

		if ( '' !== $needle ) {
			$bodies = TBTTM_DB::get_owned_question_bodies( $user_id );
			$tests  = array_filter(
				$tests,
				static function ( $t ) use ( $needle, $bodies ) {
					if ( false !== strpos( self::fold( $t['title'] ), $needle ) ) {
						return true;
					}
					if ( '' !== $t['class_title'] && false !== strpos( self::fold( $t['class_title'] ), $needle ) ) {
						return true;
					}
					foreach ( $bodies[ $t['id'] ] ?? array() as $body ) {
						if ( false !== strpos( self::fold( $body ), $needle ) ) {
							return true;
						}
					}
					return false;
				}
			);
		}

		return array(
			'total'     => count( $rows ),
			'tests'     => array_values( $tests ),
			'classes'   => $classes,
			'unclassed' => $unclassed,
		);
	}

	/**
	 * @param WP_REST_Request $request Request: search, class.
	 * @return WP_REST_Response
	 */
	public function list_tests( WP_REST_Request $request ) {
		return rest_ensure_response(
			self::library(
				get_current_user_id(),
				(string) $request->get_param( 'search' ),
				(string) $request->get_param( 'class' )
			)
		);
	}

	/**
	 * The lessons of one of the teacher's own classes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_lessons( WP_REST_Request $request ) {
		$class_id = (int) $request['id'];
		if ( ! TBTTM_Classes::user_owns_class( get_current_user_id(), $class_id ) ) {
			return new WP_Error( 'tbttm_invalid_class', __( 'You don\'t have access to that class.', 'tbt-test-maker' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( self::lessons( $class_id ) );
	}

	public function get_test( WP_REST_Request $request ) {
		$row = TBTTM_DB::get_owned_test( (int) $request['id'], get_current_user_id() );
		if ( ! $row ) {
			return self::not_found();
		}
		return rest_ensure_response( self::test_payload( $row, TBTTM_DB::get_questions( (int) $row->id ) ) );
	}

	public function create_test( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		$status = self::requested_status( $params );
		$clean  = self::clean( $params, $status );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$clean = self::attach( $clean, $params, null );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$id = TBTTM_DB::insert_test( $clean, $status, get_current_user_id() );
		if ( ! $id ) {
			return new WP_Error( 'tbttm_save_failed', __( 'The test could not be saved. Try again.', 'tbt-test-maker' ), array( 'status' => 500 ) );
		}

		$row = TBTTM_DB::get_owned_test( $id, get_current_user_id() );
		return rest_ensure_response( self::test_payload( $row, TBTTM_DB::get_questions( $id ) ) );
	}

	public function update_test( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$row     = TBTTM_DB::get_owned_test( (int) $request['id'], $user_id );
		if ( ! $row ) {
			return self::not_found();
		}

		$params = (array) $request->get_json_params();
		$status = self::requested_status( $params );
		$clean  = self::clean( $params, $status );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$clean = self::attach( $clean, $params, $row );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		if ( ! TBTTM_DB::update_test( $row, $clean, $status ) ) {
			return new WP_Error( 'tbttm_save_failed', __( 'The test could not be saved. Try again.', 'tbt-test-maker' ), array( 'status' => 500 ) );
		}

		$row = TBTTM_DB::get_owned_test( (int) $row->id, $user_id );
		return rest_ensure_response( self::test_payload( $row, TBTTM_DB::get_questions( (int) $row->id ) ) );
	}

	/**
	 * Copy a test, with its questions, as a draft owned by the current user,
	 * as the Matching Game's Duplicate does. Attempts are not copied.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_test( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$row     = TBTTM_DB::get_owned_test( (int) $request['id'], $user_id );
		if ( ! $row ) {
			return self::not_found();
		}
		$id = TBTTM_DB::duplicate_test( $row, $user_id );
		if ( ! $id ) {
			return new WP_Error( 'tbttm_save_failed', __( 'The copy could not be made. Try again.', 'tbt-test-maker' ), array( 'status' => 500 ) );
		}
		$copy                 = TBTTM_DB::get_owned_test( $id, $user_id );
		$copy->question_count = count( TBTTM_DB::get_questions( $id ) );
		$copy->attempt_count  = 0;
		return rest_ensure_response( self::test_summary( $copy ) );
	}

	public function delete_test( WP_REST_Request $request ) {
		$row = TBTTM_DB::get_owned_test( (int) $request['id'], get_current_user_id() );
		if ( ! $row ) {
			return self::not_found();
		}
		TBTTM_DB::delete_test( (int) $row->id );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/* ---------------------------------------------------------------------
	 * Results: the owner's view of who took a test
	 * ------------------------------------------------------------------- */

	/**
	 * The Results screen's summary and list. Owner only: the capability does
	 * not open someone else's test, administrators included.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_results( WP_REST_Request $request ) {
		$test = TBTTM_DB::get_owned_test( (int) $request['id'], get_current_user_id() );
		if ( ! $test ) {
			return self::not_found();
		}
		return rest_ensure_response( TBTTM_Results::summary( $test ) );
	}

	/**
	 * One finished attempt's answers, from its snapshot. The attempt must
	 * belong to the test in the URL, and the test to the caller.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_attempt_review( WP_REST_Request $request ) {
		$test = TBTTM_DB::get_owned_test( (int) $request['id'], get_current_user_id() );
		if ( ! $test ) {
			return self::not_found();
		}
		$attempt = TBTTM_DB::get_attempt( (int) $request['attempt'] );
		if ( ! $attempt || (int) $attempt->test_id !== (int) $test->id || TBTTM_DB::ATTEMPT_FINISHED !== $attempt->status ) {
			return self::no_such_attempt();
		}
		$review = json_decode( (string) $attempt->review, true );
		return rest_ensure_response(
			array(
				'id'     => (int) $attempt->id,
				'review' => is_array( $review ) ? array_values( $review ) : array(),
			)
		);
	}

	/**
	 * Remove one attempt of the caller's own test, finished or not, and
	 * answer with the numbers as they now stand.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_attempt( WP_REST_Request $request ) {
		$test = TBTTM_DB::get_owned_test( (int) $request['id'], get_current_user_id() );
		if ( ! $test ) {
			return self::not_found();
		}
		$attempt = TBTTM_DB::get_attempt( (int) $request['attempt'] );
		if ( ! $attempt || (int) $attempt->test_id !== (int) $test->id ) {
			return self::no_such_attempt();
		}
		TBTTM_DB::delete_attempt( (int) $attempt->id );

		return rest_ensure_response(
			array(
				'deleted' => true,
				'stats'   => TBTTM_Results::stats( (int) $test->id, null === $test->pass_mark ? null : (int) $test->pass_mark ),
				'hardest' => TBTTM_Results::hardest_for( (int) $test->id ),
			)
		);
	}

	private static function no_such_attempt() {
		return new WP_Error( 'tbttm_no_attempt', __( 'That attempt no longer exists.', 'tbt-test-maker' ), array( 'status' => 404 ) );
	}

	/**
	 * The status a save asks for: published, or draft for anything else.
	 *
	 * @param array $params Body.
	 * @return string
	 */
	private static function requested_status( array $params ) {
		return isset( $params['status'] ) && TBTTM_DB::STATUS_PUBLISHED === $params['status']
			? TBTTM_DB::STATUS_PUBLISHED
			: TBTTM_DB::STATUS_DRAFT;
	}

	/**
	 * Clean a test body for saving: sanitise() for a draft, validate() for a
	 * published test. A draft still needs a title, so the library has a name
	 * to show. On failure nothing is written.
	 *
	 * @param array  $params Body.
	 * @param string $status Requested status.
	 * @return array|WP_Error
	 */
	private static function clean( array $params, $status ) {
		$validator = new TBTTM_Validator();

		if ( TBTTM_DB::STATUS_PUBLISHED === $status ) {
			$clean = $validator->validate( $params );
			if ( is_wp_error( $clean ) ) {
				$data = $clean->get_error_data();
				$clean->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => 400 ) ) );
				return $clean;
			}
		} else {
			$clean = $validator->sanitise( $params );
			if ( '' === $clean['title'] ) {
				return new WP_Error( 'tbttm_no_title', __( 'Give the test a title.', 'tbt-test-maker' ), array( 'status' => 400 ) );
			}
		}

		// The feature image must be an image the teacher uploaded, or one they
		// may edit anyway (an administrator's pick from the media library).
		if ( $clean['image_id'] > 0 && ! self::can_use_image( $clean['image_id'] ) ) {
			$clean['image_id'] = 0;
		}
		return $clean;
	}

	/**
	 * Settle a save's class and lesson. The validator has already reduced
	 * them to whole numbers; whether the teacher may use that class is checked
	 * here, through TBT Notes. On an error nothing is written.
	 *
	 * A body with no `class_id` at all comes from a builder that did not show
	 * the Class field (TBT Notes is off, or the teacher has no classes). Such
	 * a save keeps what is stored, so switching Notes off and editing a test
	 * does not detach it.
	 *
	 * @param array       $clean  Clean test.
	 * @param array       $params Raw body.
	 * @param object|null $row    The stored test, or null for a new one.
	 * @return array|WP_Error
	 */
	private static function attach( array $clean, array $params, $row ) {
		if ( ! array_key_exists( 'class_id', $params ) ) {
			$clean['class_id']  = $row && ! empty( $row->class_id ) ? (int) $row->class_id : null;
			$clean['lesson_id'] = $row && ! empty( $row->class_id ) && ! empty( $row->lesson_id ) ? (int) $row->lesson_id : null;
			return $clean;
		}

		$attachment = TBTTM_Classes::validate_attachment( get_current_user_id(), $clean['class_id'], $clean['lesson_id'] );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		$clean['class_id']  = $attachment['class_id'];
		$clean['lesson_id'] = $attachment['lesson_id'];
		return $clean;
	}

	/**
	 * May the current user put this attachment on a test?
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	private static function can_use_image( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $post ) ) {
			return false;
		}
		return (int) $post->post_author === get_current_user_id() || current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Upload a feature image.
	 *
	 * Teachers need not hold WordPress's upload_files capability: this route
	 * accepts one thing only, a picture for a test, and makes it safe itself.
	 *   - JPEG, PNG or WebP only, judged by the file's content, not its name.
	 *     SVG, GIF, HEIC and everything else are refused.
	 *   - At most 5 MB, and at most 40 megapixels before it is decoded.
	 *   - Re-encoded through WordPress's image editor, never stored as sent:
	 *     resized to 1600px on the longest side, and stripped of its metadata
	 *     (camera, location) along the way.
	 *   - At most 50 uploads per teacher per day.
	 * The result is an ordinary attachment owned by the teacher.
	 *
	 * @param WP_REST_Request $request Request with the file in `image`.
	 * @return WP_REST_Response|WP_Error { id, url }
	 */
	public function upload_image( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = isset( $files['image'] ) && is_array( $files['image'] ) ? $files['image'] : null;

		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$too_big = $file && in_array( (int) ( $file['error'] ?? 0 ), array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			return self::image_error( $too_big ? 'big' : 'none' );
		}
		if ( (int) $file['size'] > self::IMAGE_MAX_BYTES || filesize( $file['tmp_name'] ) > self::IMAGE_MAX_BYTES ) {
			return self::image_error( 'big' );
		}

		$user_id = get_current_user_id();
		$key     = 'tbttm_uploads_' . $user_id . '_' . gmdate( 'Ymd' );
		$count   = (int) get_transient( $key );
		if ( $count >= self::IMAGE_DAILY_LIMIT ) {
			return self::image_error( 'limit' );
		}

		$allowed = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
		);
		$name  = sanitize_file_name( (string) $file['name'] );
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $name, $allowed );
		$info  = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mime  = is_array( $info ) && isset( $info['mime'] ) ? $info['mime'] : '';
		if ( empty( $check['type'] ) || ! in_array( $mime, $allowed, true ) || $mime !== $check['type'] ) {
			return self::image_error( 'type' );
		}
		if ( (int) $info[0] < 1 || (int) $info[1] < 1 || (int) $info[0] * (int) $info[1] > self::IMAGE_MAX_PIXELS ) {
			return self::image_error( 'pixels' );
		}

		$editor = wp_get_image_editor( $file['tmp_name'] );
		if ( is_wp_error( $editor ) ) {
			return self::image_error( 'type' );
		}
		$size = $editor->get_size();
		if ( $size['width'] > self::IMAGE_MAX_SIDE || $size['height'] > self::IMAGE_MAX_SIDE ) {
			$resized = $editor->resize( self::IMAGE_MAX_SIDE, self::IMAGE_MAX_SIDE, false );
			if ( is_wp_error( $resized ) ) {
				return self::image_error( 'save' );
			}
		}
		$editor->set_quality( 82 );

		// Keep the format when the editor can write it, otherwise JPEG.
		$out_mime = $editor::supports_mime_type( $mime ) ? $mime : 'image/jpeg';
		$ext      = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		)[ $out_mime ];

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return self::image_error( 'save' );
		}
		$base     = sanitize_file_name( pathinfo( $name, PATHINFO_FILENAME ) );
		$base     = '' !== $base ? substr( $base, 0, 60 ) : 'test-image';
		$filename = wp_unique_filename( $uploads['path'], $base . '.' . $ext );
		$saved    = $editor->save( trailingslashit( $uploads['path'] ) . $filename, $out_mime );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			return self::image_error( 'save' );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $saved['mime-type'],
				'post_title'     => $base,
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_author'    => $user_id,
			),
			$saved['path'],
			0,
			true
		);
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $saved['path'] );
			return self::image_error( 'save' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $saved['path'] ) );
		set_transient( $key, $count + 1, DAY_IN_SECONDS );

		$url = wp_get_attachment_image_url( $attachment_id, 'large' );
		return rest_ensure_response(
			array(
				'id'  => (int) $attachment_id,
				'url' => $url ? (string) $url : (string) wp_get_attachment_url( $attachment_id ),
			)
		);
	}

	/**
	 * The teacher-facing reason an upload was refused.
	 *
	 * @param string $why none|big|type|pixels|limit|save.
	 * @return WP_Error
	 */
	private static function image_error( $why ) {
		$messages = array(
			'none'   => __( 'The picture did not arrive. Try again.', 'tbt-test-maker' ),
			/* translators: %d: megabytes. */
			'big'    => sprintf( __( 'The picture is too big. Choose one under %d MB.', 'tbt-test-maker' ), (int) ( self::IMAGE_MAX_BYTES / 1048576 ) ),
			'type'   => __( 'Use a JPG, PNG or WebP picture.', 'tbt-test-maker' ),
			'pixels' => __( 'The picture is too large to process. Choose a smaller one.', 'tbt-test-maker' ),
			'limit'  => __( 'You have uploaded a lot of pictures today. Try again tomorrow.', 'tbt-test-maker' ),
			'save'   => __( 'The picture could not be saved. Try again.', 'tbt-test-maker' ),
		);
		$status   = array(
			'none'   => 400,
			'big'    => 413,
			'type'   => 415,
			'pixels' => 413,
			'limit'  => 429,
			'save'   => 500,
		);
		return new WP_Error( 'tbttm_image_' . $why, $messages[ $why ], array( 'status' => $status[ $why ] ) );
	}

	/* ---------------------------------------------------------------------
	 * Student routes
	 * ------------------------------------------------------------------- */

	/**
	 * Start an attempt, or resume the one in progress. With `restart`, the
	 * attempt in progress is discarded first: the student asked to start again.
	 * A guest always gets a new attempt; see start_guest_attempt().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_attempt( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$test    = TBTTM_DB::get_test( (int) $request->get_param( 'test' ) );
		if ( ! $test || TBTTM_DB::STATUS_PUBLISHED !== $test->status ) {
			return new WP_Error( 'tbttm_not_available', __( 'This test is not available.', 'tbt-test-maker' ), array( 'status' => 404 ) );
		}

		$questions = TBTTM_DB::get_questions( (int) $test->id );
		if ( $user_id <= 0 ) {
			return $this->start_guest_attempt( $request, $test, $questions );
		}

		$attempt = TBTTM_DB::get_user_attempt( (int) $test->id, $user_id, TBTTM_DB::ATTEMPT_IN_PROGRESS );

		if ( $attempt && rest_sanitize_boolean( $request->get_param( 'restart' ) ) ) {
			TBTTM_DB::delete_attempt( (int) $attempt->id );
			$attempt = null;
		}

		if ( ! $attempt ) {
			$attempt = TBTTM_DB::insert_attempt( (int) $test->id, $user_id );
			if ( ! $attempt ) {
				return new WP_Error( 'tbttm_save_failed', __( 'The test could not be started. Try again.', 'tbt-test-maker' ), array( 'status' => 500 ) );
			}
		}

		return rest_ensure_response( TBTTM_Attempts::state( $attempt, $questions ) );
	}

	/**
	 * Start a guest's attempt on an open test: `name` is required and stored
	 * with user_id = 0. Always a new attempt; a guest carries on with an
	 * earlier one by holding its token, not by asking here.
	 *
	 * @param WP_REST_Request $request   Request.
	 * @param object          $test      Test row, published.
	 * @param array[]         $questions The test's questions.
	 * @return WP_REST_Response|WP_Error
	 */
	private function start_guest_attempt( WP_REST_Request $request, $test, array $questions ) {
		// The permission callback already refused this; checked again because
		// the write depends on it.
		if ( 'open' !== $test->access ) {
			return self::login_required();
		}

		$raw    = $request->get_param( 'name' );
		$name   = is_scalar( $raw ) ? trim( sanitize_text_field( (string) $raw ) ) : '';
		$length = mb_strlen( $name, 'UTF-8' );
		if ( $length < self::GUEST_NAME_MIN || $length > self::GUEST_NAME_MAX ) {
			return new WP_Error( 'tbttm_no_name', __( 'Type your name to start.', 'tbt-test-maker' ), array( 'status' => 400 ) );
		}

		// A whole class may share one school address, so the limit is generous.
		$key    = 'tbttm_gs_' . md5( (int) $test->id . '|' . self::client_ip() );
		$now    = time();
		$window = get_transient( $key );
		if ( ! is_array( $window ) || ! isset( $window['start'], $window['n'] ) || (int) $window['start'] + self::GUEST_START_WINDOW <= $now ) {
			$window = array(
				'start' => $now,
				'n'     => 0,
			);
		}
		if ( (int) $window['n'] >= self::GUEST_START_LIMIT ) {
			return new WP_Error(
				'tbttm_too_many',
				__( 'Too many people started this test at once. Wait a minute and try again.', 'tbt-test-maker' ),
				array( 'status' => 429 )
			);
		}

		$attempt = TBTTM_DB::insert_attempt( (int) $test->id, 0, $name );
		if ( ! $attempt ) {
			return new WP_Error( 'tbttm_save_failed', __( 'The test could not be started. Try again.', 'tbt-test-maker' ), array( 'status' => 500 ) );
		}

		$window['n'] = (int) $window['n'] + 1;
		set_transient( $key, $window, max( 1, (int) $window['start'] + self::GUEST_START_WINDOW - $now ) );

		return rest_ensure_response( TBTTM_Attempts::state( $attempt, $questions ) );
	}

	/**
	 * The address a request came from, for the guest throttle. The connection's
	 * own address: forwarded headers are whatever the sender typed. A site
	 * behind a proxy can supply the real one through the filter.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'tbttm_guest_ip', $ip );
	}

	/**
	 * Autosave: merge the answers into the stored ones. Answers with the
	 * attempt's whole state, as starting does, so a guest's browser can carry
	 * on with an attempt it holds the token for. `revealed` covers only the
	 * questions already checked, whose answer the student has seen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_answers( WP_REST_Request $request ) {
		list( $attempt, $test ) = $this->own_attempt( (int) $request['id'], $request );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}
		if ( TBTTM_DB::ATTEMPT_FINISHED === $attempt->status ) {
			return self::finished_error( $attempt );
		}

		$questions = TBTTM_DB::get_questions( (int) $test->id );
		$checked   = TBTTM_Attempts::checked( $attempt );
		$answers   = TBTTM_Attempts::merge( TBTTM_Attempts::answers( $attempt ), $request->get_param( 'answers' ), $questions, $checked );

		if ( ! TBTTM_DB::update_attempt( (int) $attempt->id, array( 'answers' => wp_json_encode( (object) $answers ) ) ) ) {
			return new WP_Error( 'tbttm_save_failed', __( 'Your answers could not be saved. Trying again.', 'tbt-test-maker' ), array( 'status' => 500 ) );
		}

		$attempt->answers = wp_json_encode( (object) $answers );
		return rest_ensure_response( TBTTM_Attempts::state( $attempt, $questions ) );
	}

	/**
	 * "After each question" mode: store one answer, lock the question, and
	 * reveal its correct option. Checking a question twice returns the stored
	 * answer and the same verdict, so a retried request is harmless.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function check_answer( WP_REST_Request $request ) {
		list( $attempt, $test ) = $this->own_attempt( (int) $request['id'], $request );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}
		if ( TBTTM_DB::ATTEMPT_FINISHED === $attempt->status ) {
			return self::finished_error( $attempt );
		}
		if ( 'now' !== $test->feedback ) {
			return new WP_Error( 'tbttm_no_check', __( 'This test shows the answers at the end.', 'tbt-test-maker' ), array( 'status' => 400 ) );
		}

		$qid      = (int) $request->get_param( 'question' );
		$question = null;
		foreach ( TBTTM_DB::get_questions( (int) $test->id ) as $q ) {
			if ( $q['id'] === $qid ) {
				$question = $q;
				break;
			}
		}
		if ( ! $question || null === $question['correct'] ) {
			return new WP_Error( 'tbttm_no_question', __( 'That question is not in this test.', 'tbt-test-maker' ), array( 'status' => 400 ) );
		}

		$answers = TBTTM_Attempts::answers( $attempt );
		$checked = TBTTM_Attempts::checked( $attempt );

		if ( ! in_array( $qid, $checked, true ) ) {
			$answer = $request->get_param( 'answer' );
			if ( ! is_numeric( $answer ) || (int) $answer < 0 || (int) $answer >= count( $question['options'] ) ) {
				return new WP_Error( 'tbttm_bad_answer', __( 'Choose an answer first.', 'tbt-test-maker' ), array( 'status' => 400 ) );
			}
			$answers[ $qid ] = (int) $answer;
			$checked[]       = $qid;
			$ok              = TBTTM_DB::update_attempt(
				(int) $attempt->id,
				array(
					'answers' => wp_json_encode( (object) $answers ),
					'checked' => wp_json_encode( array_values( $checked ) ),
				)
			);
			if ( ! $ok ) {
				return new WP_Error( 'tbttm_save_failed', __( 'Your answer could not be saved. Try again.', 'tbt-test-maker' ), array( 'status' => 500 ) );
			}
		}

		return rest_ensure_response(
			array(
				'question' => $qid,
				'answer'   => $answers[ $qid ] ?? null,
				'correct'  => (int) $question['correct'],
			)
		);
	}

	/**
	 * Finish: merge the last answers, score, store the snapshot. Idempotent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function finish_attempt( WP_REST_Request $request ) {
		list( $attempt, $test ) = $this->own_attempt( (int) $request['id'], $request );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}
		$result = TBTTM_Attempts::finish( $attempt, $test, $request->get_param( 'answers' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * The stored result of a finished attempt, for its owner. For a guest's
	 * attempt the token in the URL is the credential: whoever holds it took
	 * the test.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_result( WP_REST_Request $request ) {
		$attempt = TBTTM_DB::get_attempt_by_token( (string) $request['token'] );
		$mine    = $attempt && ( (int) $attempt->user_id <= 0 || (int) $attempt->user_id === get_current_user_id() );
		if ( ! $mine || TBTTM_DB::ATTEMPT_FINISHED !== $attempt->status ) {
			return new WP_Error( 'tbttm_no_result', __( 'That result is not available.', 'tbt-test-maker' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( TBTTM_Attempts::result( $attempt, TBTTM_DB::get_test( (int) $attempt->test_id ) ) );
	}

	/**
	 * The caller's attempt and its test, or an error in the first slot.
	 *
	 * A user's attempt belongs to that user. A guest's attempt (user_id = 0)
	 * belongs to whoever sends its token in X-TBTTM-Token.
	 *
	 * @param int             $id      Attempt ID.
	 * @param WP_REST_Request $request Request.
	 * @return array{0: object|WP_Error, 1: object|null}
	 */
	private function own_attempt( $id, WP_REST_Request $request ) {
		$attempt = TBTTM_DB::get_attempt( $id );
		$mine    = false;
		if ( $attempt && (int) $attempt->user_id > 0 ) {
			$mine = (int) $attempt->user_id === get_current_user_id();
		} elseif ( $attempt ) {
			$token = (string) $request->get_header( 'x_tbttm_token' );
			$mine  = '' !== $token && hash_equals( (string) $attempt->token, $token );
		}
		if ( ! $mine ) {
			return array( new WP_Error( 'tbttm_no_attempt', __( 'That attempt is not yours, or it no longer exists.', 'tbt-test-maker' ), array( 'status' => 404 ) ), null );
		}
		$test = TBTTM_DB::get_test( (int) $attempt->test_id );
		if ( ! $test ) {
			return array( new WP_Error( 'tbttm_not_available', __( 'This test is not available.', 'tbt-test-maker' ), array( 'status' => 404 ) ), null );
		}
		return array( $attempt, $test );
	}

	/**
	 * The answer to a save on an attempt already finished (on another device,
	 * say): an error that carries the token, so the page can show the result.
	 *
	 * @param object $attempt Attempt row.
	 * @return WP_Error
	 */
	private static function finished_error( $attempt ) {
		return new WP_Error(
			'tbttm_attempt_finished',
			__( 'This attempt is already finished.', 'tbt-test-maker' ),
			array(
				'status' => 409,
				'token'  => (string) $attempt->token,
			)
		);
	}

	private static function not_found() {
		return new WP_Error( 'tbttm_not_found', __( 'That test no longer exists, or it isn\'t yours.', 'tbt-test-maker' ), array( 'status' => 404 ) );
	}
}
