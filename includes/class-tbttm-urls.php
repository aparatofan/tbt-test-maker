<?php
/**
 * The plugin's two page addresses and the links built from them.
 *
 * One place decides every URL, so the library, the builder and the student
 * page cannot disagree about where a test lives.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Urls {

	/** Query parameter carrying a test's slug on the student page. */
	const TEST_PARAM = 'test';

	/** Query parameter opening a test in the builder. */
	const EDIT_PARAM = 'tbttm_edit';

	/** Query parameter opening an empty builder. */
	const NEW_PARAM = 'tbttm_new';

	/**
	 * The student page: the published page holding [tbt_test], or ''.
	 *
	 * @return string
	 */
	public static function player_page() {
		return self::page_url( (int) get_option( 'tbttm_player_page_id', 0 ) );
	}

	/**
	 * The teacher page: the setting, else the current page, else ''.
	 *
	 * The current-page fallback is what lets an unconfigured site work the
	 * moment the shortcode is placed: the page links back to itself.
	 *
	 * @return string
	 */
	public static function builder_page() {
		$url = self::page_url( (int) get_option( 'tbttm_builder_page_id', 0 ) );
		if ( '' !== $url ) {
			return $url;
		}
		$post = get_post();
		if ( $post && is_singular() ) {
			$permalink = get_permalink( $post );
			return $permalink ? (string) $permalink : '';
		}
		return '';
	}

	/**
	 * A test's student link, or '' when no student page is configured.
	 *
	 * @param string $slug Test slug.
	 * @return string
	 */
	public static function test_url( $slug ) {
		$base = self::player_page();
		if ( '' === $base || '' === (string) $slug ) {
			return '';
		}
		return add_query_arg( self::TEST_PARAM, rawurlencode( (string) $slug ), $base );
	}

	/**
	 * Link opening one test in the builder.
	 *
	 * @param int $test_id Test ID.
	 * @return string
	 */
	public static function edit_url( $test_id ) {
		$base = self::builder_page();
		return '' === $base ? '' : add_query_arg( self::EDIT_PARAM, (int) $test_id, $base );
	}

	/**
	 * Link opening an empty builder.
	 *
	 * @return string
	 */
	public static function new_url() {
		$base = self::builder_page();
		return '' === $base ? '' : add_query_arg( self::NEW_PARAM, '1', $base );
	}

	/**
	 * Link back to the library view of the builder page.
	 *
	 * @return string
	 */
	public static function library_url() {
		$base = self::builder_page();
		return '' === $base ? '' : remove_query_arg( array( self::EDIT_PARAM, self::NEW_PARAM ), $base );
	}

	/**
	 * Permalink of a published page, or ''.
	 *
	 * @param int $page_id Page ID.
	 * @return string
	 */
	private static function page_url( $page_id ) {
		if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}
		$url = get_permalink( $page_id );
		return $url ? (string) $url : '';
	}
}
