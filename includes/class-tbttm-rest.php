<?php
/**
 * REST routes, namespace tbttm/v1.
 *
 * Teacher routes require TBTTM_CAP and are scoped to the current user's own
 * tests, administrators included. Student routes require a logged-in user and
 * act only on that user's own attempts. Every write re-checks ownership inside
 * the callback; WordPress checks the X-WP-Nonce header itself for cookie
 * authentication.
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

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$teacher = array( $this, 'can_manage' );
		$student = array( $this, 'is_logged_in' );

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
				'permission_callback' => $student,
				'callback'            => array( $this, 'start_attempt' ),
			)
		);

		register_rest_route(
			self::NS,
			'/attempts/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => $student,
				'callback'            => array( $this, 'save_answers' ),
			)
		);

		register_rest_route(
			self::NS,
			'/attempts/(?P<id>\d+)/check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $student,
				'callback'            => array( $this, 'check_answer' ),
			)
		);

		register_rest_route(
			self::NS,
			'/attempts/(?P<id>\d+)/finish',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => $student,
				'callback'            => array( $this, 'finish_attempt' ),
			)
		);

		register_rest_route(
			self::NS,
			'/attempts/(?P<token>[a-f0-9]{32})/result',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => $student,
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

	public function is_logged_in() {
		return is_user_logged_in();
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
		return array(
			'id'        => (int) $row->id,
			'slug'      => (string) $row->slug,
			'title'     => (string) $row->title,
			'status'    => (string) $row->status,
			'questions' => (int) $row->question_count,
			'attempts'  => (int) $row->attempt_count,
			'url'       => TBTTM_DB::STATUS_PUBLISHED === $row->status ? TBTTM_Urls::test_url( $row->slug ) : '',
			'edit_url'  => TBTTM_Urls::edit_url( (int) $row->id ),
			'edited'    => self::edited( (string) $row->updated_at ),
		);
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
	 * The user's tests, newest first, optionally filtered by a search that
	 * looks in the title and inside every question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_tests( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$rows    = TBTTM_DB::get_owned_tests( $user_id );
		$total   = count( $rows );
		$needle  = self::fold( trim( (string) $request->get_param( 'search' ) ) );

		if ( '' !== $needle ) {
			$bodies = TBTTM_DB::get_owned_question_bodies( $user_id );
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $needle, $bodies ) {
						if ( false !== strpos( self::fold( $row->title ), $needle ) ) {
							return true;
						}
						foreach ( $bodies[ (int) $row->id ] ?? array() as $body ) {
							if ( false !== strpos( self::fold( $body ), $needle ) ) {
								return true;
							}
						}
						return false;
					}
				)
			);
		}

		return rest_ensure_response(
			array(
				'total' => $total,
				'tests' => array_map( array( __CLASS__, 'test_summary' ), $rows ),
			)
		);
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
		$attempt   = TBTTM_DB::get_user_attempt( (int) $test->id, $user_id, TBTTM_DB::ATTEMPT_IN_PROGRESS );

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
	 * Autosave: merge the answers into the stored ones.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_answers( WP_REST_Request $request ) {
		list( $attempt, $test ) = $this->own_attempt( (int) $request['id'] );
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

		return rest_ensure_response( array( 'answers' => (object) $answers ) );
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
		list( $attempt, $test ) = $this->own_attempt( (int) $request['id'] );
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
		list( $attempt, $test ) = $this->own_attempt( (int) $request['id'] );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}
		$result = TBTTM_Attempts::finish( $attempt, $test, $request->get_param( 'answers' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * The stored result of a finished attempt, for its owner.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_result( WP_REST_Request $request ) {
		$attempt = TBTTM_DB::get_attempt_by_token( (string) $request['token'] );
		if ( ! $attempt || (int) $attempt->user_id !== get_current_user_id() || TBTTM_DB::ATTEMPT_FINISHED !== $attempt->status ) {
			return new WP_Error( 'tbttm_no_result', __( 'That result is not available.', 'tbt-test-maker' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( TBTTM_Attempts::result( $attempt, TBTTM_DB::get_test( (int) $attempt->test_id ) ) );
	}

	/**
	 * The current user's attempt and its test, or an error in the first slot.
	 *
	 * @param int $id Attempt ID.
	 * @return array{0: object|WP_Error, 1: object|null}
	 */
	private function own_attempt( $id ) {
		$attempt = TBTTM_DB::get_attempt( $id );
		if ( ! $attempt || (int) $attempt->user_id !== get_current_user_id() || (int) $attempt->user_id <= 0 ) {
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
