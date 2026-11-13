<?php
/**
 * Contributes published tests to the TBT Notes class panel.
 *
 * Notes exposes a generic extension point, the `tbt_notes_class_extras`
 * filter, and knows nothing about tests. All the coupling lives here, on this
 * plugin's side of the line, modelled on Translation Drill's bridge: a test
 * attached to a lesson shows its icon on that lesson's row, and a test
 * attached to "Any lesson" is listed under the lessons.
 *
 * Notes is optional. The filter is Notes' own, so with Notes inactive nothing
 * in this class ever runs.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Notes_Bridge {

	/**
	 * Group slug in the extras contract. Stable: it is what Notes uses to
	 * remember which section a user expanded.
	 */
	const GROUP_KEY = 'tests';

	public function __construct() {
		// Priority 30, after Swipe (10) and Translation Drill (20).
		add_filter( 'tbt_notes_class_extras', array( $this, 'add_class_tests' ), 30, 3 );
	}

	/**
	 * Is the host plugin actually there? The filter alone is a good signal (it
	 * only fires from Notes), but a site could apply it elsewhere, so the
	 * pieces this bridge reads through are checked too.
	 *
	 * @return bool
	 */
	protected function notes_available() {
		return class_exists( 'TBT_Notes_REST' ) && TBTTM_Classes::available();
	}

	/**
	 * Append this class's published tests to the extras Notes will render.
	 * Open tests and tests for logged-in students are both listed.
	 *
	 * Scoping is by class, not by owner: the readers here are the class's
	 * students. That is safe without a check of our own because Notes runs
	 * this filter behind can_read_class: by the time we are called, the caller
	 * is already a proven member (or the owner) of the class.
	 *
	 * @param array $extras   Groups collected so far.
	 * @param int   $class_id Class being viewed.
	 * @param int   $user_id  Current user (unused: access is already settled).
	 * @return array
	 */
	public function add_class_tests( $extras, $class_id, $user_id = 0 ) {
		if ( ! is_array( $extras ) || ! $this->notes_available() ) {
			return $extras;
		}

		$class_id = (int) $class_id;
		if ( $class_id <= 0 ) {
			return $extras;
		}

		$items = array();
		foreach ( TBTTM_DB::published_tests_for_class( $class_id ) as $test ) {
			$url = TBTTM_Urls::test_url( (string) $test->slug );
			if ( '' === $url ) {
				// No student page configured: there is nothing to open.
				continue;
			}

			$n       = (int) $test->question_count;
			$items[] = array(
				'id'        => (int) $test->id,
				// 0 for "Any lesson": Notes then lists it under the lessons
				// instead of on a row.
				'lesson_id' => (int) $test->lesson_id,
				'title'     => (string) $test->title,
				/* translators: %d: number of questions in the test. */
				'subtitle'  => sprintf( _n( '%d question', '%d questions', $n, 'tbt-test-maker' ), $n ),
				'url'       => $url,
			);
		}

		if ( empty( $items ) ) {
			return $extras;
		}

		$extras[] = array(
			'key'    => self::GROUP_KEY,
			'label'  => __( 'Tests', 'tbt-test-maker' ),
			// Row tooltip, in Polish because students read it in class.
			'action' => __( 'Rozwiąż test', 'tbt-test-maker' ),
			'icon'   => self::icon_svg(),
			'items'  => $items,
		);

		return $extras;
	}

	/**
	 * The test mark, inline, for the extras contract: a numbered tile with a
	 * tick. One colour, drawn with currentColor, so it takes the colour of the
	 * row it sits on. Paths only: Notes keeps svg, g, path, circle, rect and
	 * polygon, and drops everything else.
	 *
	 * Read once per request and cached, so a panel with several classes does
	 * not re-read it.
	 *
	 * @return string SVG markup, or '' if the file cannot be read (Notes then
	 *                falls back to its own glyph).
	 */
	protected static function icon_svg() {
		static $svg = null;

		if ( null === $svg ) {
			$path = TBTTM_DIR . 'assets/img/test-icon.svg';
			$raw  = is_readable( $path ) ? file_get_contents( $path ) : '';
			$svg  = is_string( $raw ) ? trim( $raw ) : '';
		}

		return $svg;
	}
}
