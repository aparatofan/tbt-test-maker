<?php
/**
 * Stylesheets shared by the student and teacher pages, and the Tool Hero.
 *
 * The design tokens are TBT Hub's, under the shared handle 'tbt-tokens'. When
 * Hub is inactive, the vendored copy in assets/css/tbt-tokens.css is
 * registered under that same handle, so a later Hub activation replaces it
 * wholesale and no page ever loads two copies of the vocabulary. This is the
 * Drag & Drop pattern (ensure_shared_styles()).
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Assets {

	/** The flat white logo, shown in the hero only when Hub's [tbt_tree] is missing. */
	const LOGO_URL = 'https://thebluetree.pl/wp-content/uploads/2020/12/TBT-white-logo.png';

	/**
	 * Register the shared stylesheets. Safe to call more than once.
	 *
	 * Called from the enqueue paths rather than earlier: Hub registers
	 * 'tbt-tokens' on wp_enqueue_scripts at priority 5, so checking at that
	 * moment or later is what lets Hub's copy win.
	 */
	public static function register_shared() {
		if ( ! wp_style_is( 'tbt-tokens', 'registered' ) ) {
			wp_register_style(
				'tbt-tokens',
				TBTTM_URL . 'assets/css/tbt-tokens.css',
				array(),
				self::file_version( 'assets/css/tbt-tokens.css' )
			);
		}
		if ( ! wp_style_is( 'tbttm-fonts', 'registered' ) ) {
			wp_register_style( 'tbttm-fonts', TBTTM_URL . 'assets/css/fonts.css', array(), TBTTM_VERSION );
		}
		if ( ! wp_style_is( 'tbttm-common', 'registered' ) ) {
			wp_register_style( 'tbttm-common', TBTTM_URL . 'assets/css/common.css', array( 'tbt-tokens', 'tbttm-fonts' ), TBTTM_VERSION );
		}
	}

	/**
	 * Hub's tree mark stylesheet, when Hub provides it. Not declared as a
	 * dependency: an unregistered dependency would make WordPress skip the
	 * dependent stylesheet entirely.
	 */
	public static function enqueue_tree() {
		if ( wp_style_is( 'tbt-tree', 'registered' ) ) {
			wp_enqueue_style( 'tbt-tree' );
		}
	}

	/**
	 * Cache buster for the vendored token file, which is resynced from Hub on
	 * its own schedule: its modification time, as in Drag & Drop.
	 *
	 * @param string $relative Path relative to the plugin folder.
	 * @return string
	 */
	private static function file_version( $relative ) {
		$mtime = @filemtime( TBTTM_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $mtime ? TBTTM_VERSION . '.' . $mtime : TBTTM_VERSION;
	}

	/**
	 * Send the headers that keep a per-visitor page out of every cache:
	 * WordPress's own, LiteSpeed's (which ignores Cache-Control), and the
	 * DONOTCACHEPAGE constant other page caches read.
	 */
	public static function nocache() {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * Is the current singular page one that contains this shortcode?
	 *
	 * @param string $shortcode Shortcode tag.
	 * @return bool
	 */
	public static function page_has( $shortcode ) {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return $post && has_shortcode( (string) $post->post_content, $shortcode );
	}

	/**
	 * The Tool Hero (Style Book §6B), as Drag & Drop ships it: blue fading to
	 * white, Hub's [tbt_tree] on the right, the white logo when Hub is absent.
	 *
	 * @param string $eyebrow Small line above the title.
	 * @param string $title   Title.
	 * @param string $support Support line under the title; '' for none.
	 * @return string
	 */
	public static function hero( $eyebrow, $title, $support ) {
		ob_start();
		?>
		<header class="tbttm-hero">
			<div class="tbttm-hero__content">
				<p class="tbttm-hero__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
				<h1 class="tbttm-hero__title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( '' !== trim( (string) $support ) ) : ?>
					<p class="tbttm-hero__support"><?php echo nl2br( esc_html( $support ) ); ?></p>
				<?php endif; ?>
			</div>
			<?php
			// animate="no": the page is a workspace, not an arrival — the same
			// choice Drag & Drop makes for its tool heroes.
			if ( shortcode_exists( 'tbt_tree' ) ) {
				echo do_shortcode( '[tbt_tree width="190px" animate="no"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hub's own markup.
			} else {
				printf(
					'<img class="tbttm-hero__logo" src="%1$s" alt="%2$s" loading="lazy" decoding="async">',
					esc_url( self::LOGO_URL ),
					esc_attr__( 'The Blue Tree', 'tbt-test-maker' )
				);
			}
			?>
		</header>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * A plain message card: the login prompt, "not available", "no access".
	 *
	 * @param string $title       Heading.
	 * @param string $text        One line.
	 * @param string $button_url  Optional button link.
	 * @param string $button_text Optional button label.
	 * @return string
	 */
	public static function message_card( $title, $text, $button_url = '', $button_text = '' ) {
		ob_start();
		?>
		<section class="tbttm-stage tbttm-message">
			<h2 class="tbttm-stage__title"><?php echo esc_html( $title ); ?></h2>
			<p class="tbttm-muted"><?php echo esc_html( $text ); ?></p>
			<?php if ( '' !== $button_url ) : ?>
				<p><a class="tbttm-btn tbttm-btn--primary" href="<?php echo esc_url( $button_url ); ?>"><?php echo esc_html( $button_text ); ?></a></p>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
