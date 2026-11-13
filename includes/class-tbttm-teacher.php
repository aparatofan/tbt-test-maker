<?php
/**
 * [tbt_test_maker] — the teacher page.
 *
 * One page, two views, as in Translation Drill: the library, and the builder
 * at ?tbttm_new=1 or ?tbttm_edit={id}. Requires TBTTM_CAP. Everything is
 * owner-scoped: a teacher sees and edits only their own tests, administrators
 * included.
 *
 * The Tool Hero is on by default; [tbt_test_maker hero="no"] switches it off
 * for a page that carries its own header row in Divi.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Teacher {

	const SHORTCODE = 'tbt_test_maker';

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'template_redirect', array( $this, 'maybe_nocache' ) );
	}

	public function maybe_nocache() {
		if ( TBTTM_Assets::page_has( self::SHORTCODE ) ) {
			TBTTM_Assets::nocache();
		}
	}

	public function maybe_enqueue() {
		if ( TBTTM_Assets::page_has( self::SHORTCODE ) && TBTTM_Capabilities::user_can_manage() ) {
			self::enqueue();
		} elseif ( TBTTM_Assets::page_has( self::SHORTCODE ) ) {
			self::enqueue_styles();
		}
	}

	/**
	 * Styles only: the login prompt and the no-access message need no script.
	 */
	private static function enqueue_styles() {
		TBTTM_Assets::register_shared();
		TBTTM_Assets::enqueue_tree();
		wp_enqueue_style( 'tbttm-teacher', TBTTM_URL . 'assets/css/teacher.css', array( 'tbttm-common' ), TBTTM_VERSION );
	}

	private static function enqueue() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		self::enqueue_styles();

		wp_enqueue_script( 'tbttm-parse', TBTTM_URL . 'assets/js/parse.js', array(), TBTTM_VERSION, true );
		// The share section's QR code: the same bundled library Translation
		// Drill uses (qrcodejs, MIT). Drawn in the browser, no request out.
		wp_enqueue_script( 'tbttm-qrcode', TBTTM_URL . 'assets/js/lib/qrcode.min.js', array(), TBTTM_VERSION, true );
		wp_enqueue_script( 'tbttm-teacher', TBTTM_URL . 'assets/js/teacher.js', array( 'tbttm-parse', 'tbttm-qrcode' ), TBTTM_VERSION, true );
		wp_localize_script(
			'tbttm-teacher',
			'TBTTM_TEACHER',
			array(
				'rest'       => esc_url_raw( rest_url( TBTTM_Rest::NS . '/' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'ajax'       => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'libraryUrl' => esc_url_raw( TBTTM_Urls::library_url() ),
				'newUrl'     => esc_url_raw( TBTTM_Urls::new_url() ),
				'editParam'  => TBTTM_Urls::EDIT_PARAM,
				'newParam'   => TBTTM_Urls::NEW_PARAM,
				'maxQuestions' => TBTTM_Validator::QUESTIONS_MAX,
				'imageMaxBytes' => TBTTM_Rest::IMAGE_MAX_BYTES,
				'imageMaxSide'  => TBTTM_Rest::IMAGE_MAX_SIDE,
				// TBT Notes: whether rows name a class at all, and the teacher's
				// own classes for the builder's Class field, A–Z.
				'notes'      => TBTTM_Classes::available(),
				'classes'    => TBTTM_Rest::classes_for( get_current_user_id() ),
				'classParam' => TBTTM_Urls::CLASS_PARAM,
				'searchParam' => TBTTM_Urls::SEARCH_PARAM,
			)
		);
	}

	/**
	 * @param array|string $atts Shortcode attributes: hero (yes|no).
	 * @return string
	 */
	public function render( $atts = array() ) {
		$atts = shortcode_atts( array( 'hero' => 'yes' ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$hero = 'no' !== strtolower( trim( (string) $atts['hero'] ) );

		$hero_html = $hero
			? TBTTM_Assets::hero(
				__( 'The Blue Tree', 'tbt-test-maker' ),
				__( 'Test Maker', 'tbt-test-maker' ),
				__( 'Build tests your students take one question at a time.', 'tbt-test-maker' )
			)
			: '';

		if ( ! is_user_logged_in() ) {
			self::enqueue_styles();
			$here = get_permalink();
			return '<div class="tbttm tbttm--teacher">' . $hero_html . TBTTM_Assets::message_card(
				__( 'Log in to build tests', 'tbt-test-maker' ),
				__( 'The Test Maker is for TBT teachers. Log in and it opens straight away.', 'tbt-test-maker' ),
				wp_login_url( $here ? $here : '' ),
				__( 'Log in', 'tbt-test-maker' )
			) . '</div>';
		}

		if ( ! TBTTM_Capabilities::user_can_manage() ) {
			self::enqueue_styles();
			return '<div class="tbttm tbttm--teacher">' . $hero_html . TBTTM_Assets::message_card(
				__( 'You don\'t have access to this page.', 'tbt-test-maker' ),
				__( 'Ask the site administrator if you should be able to build tests.', 'tbt-test-maker' )
			) . '</div>';
		}

		self::enqueue();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$edit_id    = isset( $_GET[ TBTTM_Urls::EDIT_PARAM ] ) ? absint( $_GET[ TBTTM_Urls::EDIT_PARAM ] ) : 0;
		$is_new     = isset( $_GET[ TBTTM_Urls::NEW_PARAM ] );
		$is_results = isset( $_GET[ TBTTM_Urls::RESULTS_PARAM ] );
		$results_id = $is_results ? absint( $_GET[ TBTTM_Urls::RESULTS_PARAM ] ) : 0;
		// phpcs:enable

		ob_start();
		echo '<div class="tbttm tbttm--teacher">';
		echo $hero_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

		if ( $edit_id > 0 || $is_new ) {
			$test = null;
			if ( $edit_id > 0 ) {
				$row = TBTTM_DB::get_owned_test( $edit_id, get_current_user_id() );
				if ( ! $row ) {
					$this->render_backlink();
					echo '<p class="tbttm-note tbttm-note--error">' . esc_html__( 'That test no longer exists, or it isn\'t yours.', 'tbt-test-maker' ) . '</p>';
					echo '</div>';
					return (string) ob_get_clean();
				}
				$test = TBTTM_Rest::test_payload( $row, TBTTM_DB::get_questions( $edit_id ) );
			}
			$this->render_builder( $test );
		} elseif ( $is_results ) {
			$this->render_results( $results_id );
		} else {
			$this->render_library();
		}

		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * @param array $args Query arguments to carry back: the library's filter.
	 */
	private function render_backlink( array $args = array() ) {
		$url = TBTTM_Urls::library_url();
		if ( '' !== $url ) {
			$url = $args ? add_query_arg( array_map( 'rawurlencode', $args ), $url ) : $url;
			echo '<a class="tbttm-btn tbttm-btn--ghost tbttm-backlink" href="' . esc_url( $url ) . '">' . esc_html__( '← Back to tests', 'tbt-test-maker' ) . '</a>';
		}
	}

	/**
	 * The Results screen: "Back to tests" and an empty root the script fills
	 * from GET /tests/{id}/results. Whether the test is the user's own is
	 * decided here first, so someone else's ID, or an unknown one, gets a
	 * plain message and no request is made. The route checks again.
	 *
	 * "Back to tests" carries the class filter and the search the library
	 * had, so the teacher returns to the same list.
	 *
	 * @param int $test_id Test ID from the URL.
	 */
	private function render_results( $test_id ) {
		$this->render_backlink( TBTTM_Urls::filter_args() );

		$row = $test_id > 0 ? TBTTM_DB::get_owned_test( $test_id, get_current_user_id() ) : null;
		if ( ! $row ) {
			echo '<p class="tbttm-note tbttm-note--error">' . esc_html__( 'This test is not available.', 'tbt-test-maker' ) . '</p>';
			return;
		}
		?>
		<div class="tbttm-results" data-tbttm-results data-id="<?php echo esc_attr( (int) $row->id ); ?>">
			<p class="tbttm-muted"><?php esc_html_e( 'Loading the results…', 'tbt-test-maker' ); ?></p>
		</div>
		<?php
	}

	/**
	 * The builder: an empty root the script fills from the test's JSON. The
	 * answer key is in that JSON, which is fine here: only the owner gets it.
	 *
	 * @param array|null $test Test payload, or null for a new test.
	 */
	private function render_builder( $test ) {
		?>
		<div class="tbttm-builder" data-tbttm-builder>
			<p class="tbttm-muted"><?php esc_html_e( 'Loading the builder…', 'tbt-test-maker' ); ?></p>
			<script type="application/json" data-tbttm-data><?php echo wp_json_encode( $test, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?></script>
		</div>
		<?php
	}

	/**
	 * The library: the Admin Bar and the card grid, painted by the server so
	 * the empty state is decided before the page appears. The script takes
	 * over for search, the class filter, copying links and deleting.
	 *
	 * The class filter is built as Translation Drill builds its own: "All
	 * classes", one entry per class that has a test (A–Z, with a count), then
	 * "Not in a class". The choice lives in the URL, so the server paints the
	 * filtered list on a reload.
	 */
	private function render_library() {
		$user_id = get_current_user_id();
		$all     = TBTTM_Rest::library( $user_id );
		$filter  = TBTTM_Urls::filter_args();
		$class   = $filter[ TBTTM_Urls::CLASS_PARAM ] ?? '';
		$search  = $filter[ TBTTM_Urls::SEARCH_PARAM ] ?? '';
		// Only a choice still on offer: anything else shows every test.
		$known = 'none' === $class
			? $all['unclassed'] > 0
			: in_array( $class, array_map( 'strval', array_column( $all['classes'], 'id' ) ), true );
		if ( ! $all['classes'] || ! $known ) {
			$class = '';
		}
		$filtered = '' !== $class || '' !== $search;
		$library  = $filtered ? TBTTM_Rest::library( $user_id, $search, $class ) : $all;
		// What a Results link carries, so "Back to tests" returns here.
		$carry = array();
		if ( '' !== $class ) {
			$carry[ TBTTM_Urls::CLASS_PARAM ] = $class;
		}
		if ( '' !== $search ) {
			$carry[ TBTTM_Urls::SEARCH_PARAM ] = $search;
		}
		$tests   = $library['tests'];
		$total   = $library['total'];
		$empty   = 0 === $total;
		$new_url = TBTTM_Urls::new_url();
		$uid     = wp_unique_id( 'tbttm-' );
		?>
		<div class="tbttm-library" data-tbttm-library data-total="<?php echo esc_attr( $total ); ?>">
			<div class="tbttm-libbar<?php echo $empty ? ' is-empty' : ''; ?>" data-tbttm-libbar>
				<div class="tbttm-libbar__title">
					<h2 class="tbttm-section-title"><?php esc_html_e( 'Your tests', 'tbt-test-maker' ); ?></h2>
					<span class="tbttm-libbar__line" aria-hidden="true"></span>
				</div>

				<?php if ( ! $empty ) : ?>
					<div class="tbttm-libbar__filter" role="search" data-tbttm-filter>
						<div class="tbttm-libbar__search">
							<label class="tbttm-sr-only" for="<?php echo esc_attr( $uid ); ?>-search"><?php esc_html_e( 'Search your tests', 'tbt-test-maker' ); ?></label>
							<svg class="tbttm-libbar__icon" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
								<circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2.2"/>
								<path d="m20 20-3.6-3.6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>
							</svg>
							<input type="search" id="<?php echo esc_attr( $uid ); ?>-search" class="tbttm-libbar__input" data-tbttm-search
								value="<?php echo esc_attr( $search ); ?>"
								placeholder="<?php esc_attr_e( 'Search…', 'tbt-test-maker' ); ?>" autocomplete="off" spellcheck="false">
							<button type="button" class="tbttm-libbar__clear" data-tbttm-search-clear
								aria-label="<?php esc_attr_e( 'Clear search', 'tbt-test-maker' ); ?>"<?php echo '' === $search ? ' hidden' : ''; ?>>&times;</button>
						</div>
						<?php if ( $all['classes'] ) : ?>
							<label class="tbttm-sr-only" for="<?php echo esc_attr( $uid ); ?>-class"><?php esc_html_e( 'Class', 'tbt-test-maker' ); ?></label>
							<select id="<?php echo esc_attr( $uid ); ?>-class" class="tbttm-select tbttm-libbar__class<?php echo '' !== $class ? ' is-set' : ''; ?>" data-tbttm-class>
								<option value=""><?php esc_html_e( 'All classes', 'tbt-test-maker' ); ?></option>
								<?php foreach ( $all['classes'] as $option ) : ?>
									<option value="<?php echo esc_attr( $option['id'] ); ?>"<?php selected( $class, (string) $option['id'] ); ?>>
										<?php
										/* translators: 1: class name, 2: number of tests. */
										echo esc_html( sprintf( __( '%1$s (%2$d)', 'tbt-test-maker' ), $option['title'], $option['count'] ) );
										?>
									</option>
								<?php endforeach; ?>
								<?php if ( $all['unclassed'] > 0 ) : ?>
									<option value="none"<?php selected( $class, 'none' ); ?>>
										<?php
										/* translators: %d: number of tests. */
										echo esc_html( sprintf( __( 'Not in a class (%d)', 'tbt-test-maker' ), $all['unclassed'] ) );
										?>
									</option>
								<?php endif; ?>
							</select>
						<?php endif; ?>
					</div>
					<span class="tbttm-libbar__line tbttm-libbar__line--end" aria-hidden="true"></span>
				<?php endif; ?>

				<?php if ( '' !== $new_url ) : ?>
					<a class="tbttm-btn tbttm-btn--primary tbttm-libbar__cta" href="<?php echo esc_url( $new_url ); ?>">
						<?php esc_html_e( 'Create new test', 'tbt-test-maker' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<p class="tbttm-libbar__summary" data-tbttm-summary aria-live="polite"<?php echo $filtered ? '' : ' hidden'; ?>>
				<?php if ( $filtered ) : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: tests shown, 2: all tests, as "12 tests". */
							__( '%1$d of %2$s', 'tbt-test-maker' ),
							count( $tests ),
							/* translators: %d: number of tests. */
							sprintf( _n( '%d test', '%d tests', $total, 'tbt-test-maker' ), $total )
						)
					);
					?>
					&middot; <button type="button" class="tbttm-linkbtn" data-tbttm-clear><?php esc_html_e( 'Clear filters', 'tbt-test-maker' ); ?></button>
				<?php endif; ?>
			</p>
			<p class="tbttm-note tbttm-library__notice" data-tbttm-notice role="status" aria-live="polite" hidden></p>

			<?php if ( $empty ) : ?>
				<p class="tbttm-library__empty"><?php esc_html_e( 'You have no tests yet. Create the first one.', 'tbt-test-maker' ); ?></p>
			<?php endif; ?>

			<div class="tbttm-list" data-tbttm-grid>
				<?php
				foreach ( $tests as $test ) {
					$this->render_row( $test, $carry );
				}
				?>
			</div>
			<p class="tbttm-library__empty" data-tbttm-nomatch<?php echo $filtered && ! $tests ? '' : ' hidden'; ?>>
				<?php esc_html_e( 'No tests match your search.', 'tbt-test-maker' ); ?>
				<button type="button" class="tbttm-linkbtn" data-tbttm-clear><?php esc_html_e( 'Clear filters', 'tbt-test-maker' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * One test row, in the Matching Game's library layout: title and facts on
	 * the left, OPEN · EDIT · SHARE · DUPLICATE · DELETE on the right, and the
	 * share panel unfolding underneath. teacher.js builds the same markup in
	 * rowHtml(); keep the two in step.
	 *
	 * @param array $t     Test summary.
	 * @param array $carry The library's filter, for the Results links.
	 */
	private function render_row( array $t, array $carry = array() ) {
		$published = TBTTM_DB::STATUS_PUBLISHED === $t['status'];
		// Results exist once someone has finished the test.
		$results = $t['attempts'] > 0 && '' !== $t['results_url'] ? $t['results_url'] : '';
		$results_href = '' !== $results && $carry ? add_query_arg( array_map( 'rawurlencode', $carry ), $results ) : $results;
		?>
		<article class="tbttm-test-row" data-tbttm-card data-id="<?php echo esc_attr( $t['id'] ); ?>" data-attempts="<?php echo esc_attr( $t['attempts'] ); ?>"
			data-title="<?php echo esc_attr( $t['title'] ); ?>" data-slug="<?php echo esc_attr( $t['slug'] ); ?>" data-url="<?php echo esc_attr( $t['url'] ); ?>" data-status="<?php echo esc_attr( $t['status'] ); ?>" data-access="<?php echo esc_attr( $t['access'] ); ?>">
			<div class="tbttm-test-row__main">
				<h3 class="tbttm-test-row__title"><?php echo esc_html( $t['title'] ); ?></h3>
				<p class="tbttm-test-row__meta">
					<span class="tbttm-badge <?php echo $published ? 'tbttm-badge--published' : 'tbttm-badge--draft'; ?>">
						<?php echo $published ? esc_html__( 'Published', 'tbt-test-maker' ) : esc_html__( 'Draft', 'tbt-test-maker' ); ?>
					</span>
					<span>
						<?php
						/* translators: %d: number of questions. */
						echo esc_html( sprintf( _n( '%d question', '%d questions', $t['questions'], 'tbt-test-maker' ), $t['questions'] ) );
						?>
					</span>
					<?php if ( TBTTM_Classes::available() ) : ?>
						<span><?php echo '' !== $t['class_title'] ? esc_html( $t['class_title'] ) : esc_html__( 'Not in a class', 'tbt-test-maker' ); ?></span>
					<?php endif; ?>
					<span><?php echo 'open' === $t['access'] ? esc_html__( 'Anyone with the link', 'tbt-test-maker' ) : esc_html__( 'Logged-in students', 'tbt-test-maker' ); ?></span>
					<?php if ( null !== $t['pass_mark'] ) : ?>
						<span>
							<?php
							/* translators: %d: the pass mark, a percentage. */
							echo esc_html( sprintf( __( 'Pass mark %d%%', 'tbt-test-maker' ), $t['pass_mark'] ) );
							?>
						</span>
					<?php endif; ?>
					<?php if ( $published ) : ?>
						<span>
							<?php
							/* translators: %d: number of finished attempts. */
							$attempts = sprintf( _n( '%d attempt', '%d attempts', $t['attempts'], 'tbt-test-maker' ), $t['attempts'] );
							if ( '' !== $results ) {
								echo '<a class="tbttm-test-row__count" href="' . esc_url( $results_href ) . '" data-tbttm-results-link data-href="' . esc_url( $results ) . '">' . esc_html( $attempts ) . '</a>';
							} else {
								echo esc_html( $attempts );
							}
							?>
						</span>
					<?php endif; ?>
					<?php if ( '' !== $t['edited'] ) : ?>
						<span>
							<?php
							/* translators: %s: a date. */
							echo esc_html( sprintf( __( 'Edited %s', 'tbt-test-maker' ), $t['edited'] ) );
							?>
						</span>
					<?php endif; ?>
				</p>
			</div>
			<div class="tbttm-test-row__actions" data-tbttm-actions>
				<?php if ( $published && '' !== $t['url'] ) : ?>
					<a class="tbttm-rowbtn" href="<?php echo esc_url( $t['url'] ); ?>" target="_blank" rel="noopener"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: test title. */ __( 'Open %s in a new tab', 'tbt-test-maker' ), $t['title'] ) ); ?>"><?php esc_html_e( 'Open', 'tbt-test-maker' ); ?></a>
				<?php endif; ?>
				<a class="tbttm-rowbtn" href="<?php echo esc_url( $t['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'tbt-test-maker' ); ?></a>
				<?php if ( '' !== $results ) : ?>
					<a class="tbttm-rowbtn" href="<?php echo esc_url( $results_href ); ?>" data-tbttm-results-link data-href="<?php echo esc_url( $results ); ?>"><?php esc_html_e( 'Results', 'tbt-test-maker' ); ?></a>
				<?php endif; ?>
				<button type="button" class="tbttm-rowbtn" data-tbttm-share aria-expanded="false"><?php esc_html_e( 'Share', 'tbt-test-maker' ); ?></button>
				<button type="button" class="tbttm-rowbtn" data-tbttm-duplicate><?php esc_html_e( 'Duplicate', 'tbt-test-maker' ); ?></button>
				<button type="button" class="tbttm-rowbtn tbttm-rowbtn--danger" data-tbttm-delete><?php esc_html_e( 'Delete', 'tbt-test-maker' ); ?></button>
			</div>
			<div class="tbttm-test-row__share" data-tbttm-share-panel hidden></div>
		</article>
		<?php
	}
}
