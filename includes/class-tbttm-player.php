<?php
/**
 * [tbt_test] — the student page.
 *
 * The test is named by the shortcode attribute (`[tbt_test test="slug"]`, for
 * embedding in any page or post) or, on the Student page, by ?test={slug}.
 * The attribute wins.
 *
 * In 0.1.0 every test is for logged-in students only. The questions are
 * printed into the page only after that check, without the answer key, and
 * there is no public route that returns them. A draft is visible only to its
 * owner, as a preview without a START button.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Player {

	const SHORTCODE = 'tbt_test';

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'template_redirect', array( $this, 'maybe_nocache' ) );
		add_action( 'wp_ajax_tbttm_nonce', array( $this, 'fresh_nonce' ) );
	}

	/**
	 * The page differs per visitor and per test, so no shared cache may keep
	 * a copy of it.
	 */
	public function maybe_nocache() {
		if ( TBTTM_Assets::page_has( self::SHORTCODE ) ) {
			TBTTM_Assets::nocache();
		}
	}

	public function maybe_enqueue() {
		if ( TBTTM_Assets::page_has( self::SHORTCODE ) ) {
			self::enqueue();
		}
	}

	public static function enqueue() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		TBTTM_Assets::register_shared();
		TBTTM_Assets::enqueue_tree();
		wp_enqueue_style( 'tbttm-player', TBTTM_URL . 'assets/css/player.css', array( 'tbttm-common' ), TBTTM_VERSION );
		wp_enqueue_script( 'tbttm-player', TBTTM_URL . 'assets/js/player.js', array(), TBTTM_VERSION, true );
		wp_localize_script(
			'tbttm-player',
			'TBTTM_PLAYER',
			array(
				'rest'    => esc_url_raw( rest_url( TBTTM_Rest::NS . '/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'ajax'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'userId'  => get_current_user_id(),
				'bulls'   => TBTTM_Settings::bulls(),
			)
		);
	}

	/**
	 * A fresh REST nonce for a page left open long enough for its nonce to
	 * expire. Logged-in users only (wp_ajax_, not wp_ajax_nopriv_): a logged-out
	 * visitor gets WordPress's own refusal and the page keeps its answers.
	 */
	public function fresh_nonce() {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * @param array|string $atts Shortcode attributes: test (slug).
	 * @return string
	 */
	public function render( $atts = array() ) {
		self::enqueue();

		$atts = shortcode_atts( array( 'test' => '' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$slug = sanitize_key( (string) $atts['test'] );
		$here = get_permalink();
		if ( '' === $slug ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup by slug.
			$slug = isset( $_GET[ TBTTM_Urls::TEST_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ TBTTM_Urls::TEST_PARAM ] ) ) : '';
			if ( '' !== $slug && $here ) {
				$here = add_query_arg( TBTTM_Urls::TEST_PARAM, $slug, $here );
			}
		}

		$eyebrow = __( 'The Blue Tree · Test', 'tbt-test-maker' );

		if ( '' === $slug ) {
			return $this->wrap(
				TBTTM_Assets::hero( $eyebrow, __( 'Test', 'tbt-test-maker' ), '' )
				. TBTTM_Assets::message_card(
					__( 'This test is not available.', 'tbt-test-maker' ),
					__( 'Open a test from the link your teacher shared.', 'tbt-test-maker' )
				)
			);
		}

		if ( ! is_user_logged_in() ) {
			return $this->wrap(
				TBTTM_Assets::hero( $eyebrow, __( 'Test', 'tbt-test-maker' ), '' )
				. TBTTM_Assets::message_card(
					__( 'Log in to take this test', 'tbt-test-maker' ),
					__( 'This test is for TBT students. Log in and it opens straight away.', 'tbt-test-maker' ),
					wp_login_url( $here ? $here : '' ),
					__( 'Log in', 'tbt-test-maker' )
				)
			);
		}

		$user_id  = get_current_user_id();
		$test     = TBTTM_DB::get_test_by_slug( $slug );
		$is_owner = $test && (int) $test->owner_id === $user_id;
		if ( ! $test || ( TBTTM_DB::STATUS_PUBLISHED !== $test->status && ! $is_owner ) ) {
			return $this->wrap(
				TBTTM_Assets::hero( $eyebrow, __( 'Test', 'tbt-test-maker' ), '' )
				. TBTTM_Assets::message_card(
					__( 'This test is not available.', 'tbt-test-maker' ),
					__( 'The link may be old, or your teacher hasn\'t published the test yet.', 'tbt-test-maker' )
				)
			);
		}

		$questions = TBTTM_DB::get_questions( (int) $test->id );
		$is_draft  = TBTTM_DB::STATUS_PUBLISHED !== $test->status;

		// Where the student stands: an attempt in progress, a finished one.
		$resume      = null;
		$last_result = '';
		if ( ! $is_draft ) {
			$open = TBTTM_DB::get_user_attempt( (int) $test->id, $user_id, TBTTM_DB::ATTEMPT_IN_PROGRESS );
			if ( $open ) {
				$ids    = array_column( $questions, 'id' );
				$given  = array_intersect( array_keys( TBTTM_Attempts::answers( $open ) ), $ids );
				$resume = array(
					'id'       => (int) $open->id,
					'answered' => count( $given ),
				);
			}
			$done = TBTTM_DB::get_user_attempt( (int) $test->id, $user_id, TBTTM_DB::ATTEMPT_FINISHED );
			if ( $done ) {
				$last_result = (string) $done->token;
			}
		}

		$image_id  = (int) $test->image_id;
		$image_url = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'large' ) : '';

		$data = array(
			'test'       => array(
				'id'       => (int) $test->id,
				'title'    => (string) $test->title,
				'feedback' => 'now' === $test->feedback ? 'now' : 'end',
				'bulls'    => (bool) (int) $test->bulls,
				'passMark' => null === $test->pass_mark ? null : (int) $test->pass_mark,
				'image'    => $image_url ? (string) $image_url : '',
				'draft'    => $is_draft,
			),
			// id, body and options only: the answer key never reaches the page.
			'questions'  => $is_draft ? array() : TBTTM_Attempts::public_questions( $questions ),
			'count'      => count( $questions ),
			'resume'     => $resume,
			'lastResult' => $last_result,
		);

		$hero = TBTTM_Assets::hero( $eyebrow, (string) $test->title, (string) $test->intro );
		$app  = '<section class="tbttm-stage tbttm-player" data-tbttm-player aria-live="polite">'
			. '<p class="tbttm-muted">' . esc_html__( 'Loading the test…', 'tbt-test-maker' ) . '</p>'
			. '<noscript><p>' . esc_html__( 'This test needs JavaScript. Turn it on in your browser and reload the page.', 'tbt-test-maker' ) . '</p></noscript>'
			. '<script type="application/json" data-tbttm-data>' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . '</script>'
			. '</section>';

		return $this->wrap( $hero . $app );
	}

	/**
	 * The outer wrapper every student-page state shares.
	 *
	 * @param string $inner Markup.
	 * @return string
	 */
	private function wrap( $inner ) {
		return '<div class="tbttm tbttm--player">' . $inner . '</div>';
	}
}
