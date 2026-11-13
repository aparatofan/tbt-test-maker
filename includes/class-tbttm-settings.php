<?php
/**
 * Settings page: the two pages, the roles that can build tests, the bull
 * pictures, the certificate background. Nested under TBT Hub when Hub is active, otherwise under the
 * wp-admin Settings menu.
 */

defined( 'ABSPATH' ) || exit;

class TBTTM_Settings {

	const GROUP = 'tbttm_settings';
	const SLUG  = 'tbt-test-maker-settings';

	/** The six pictures, in the order the settings page lists them. */
	const BULLS = array( 'start', 'run', 'attack', 'jump', 'pass', 'fail' );

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * The default bull pictures.
	 *
	 * @return array<string, string>
	 */
	public static function default_bulls() {
		$base = 'https://thebluetree.pl/wp-content/uploads/2019/07/';
		return array(
			'start'  => $base . '1_start.jpg',
			'run'    => $base . '2_run.jpg',
			'attack' => $base . '3_attack.jpg',
			'jump'   => $base . '4_jump.jpg',
			'pass'   => $base . 'byczek_pass.jpg',
			'fail'   => $base . 'byczek_fail.jpg',
		);
	}

	/**
	 * The bull pictures in use: each saved URL, or its default when empty.
	 *
	 * @return array<string, string>
	 */
	public static function bulls() {
		$saved = get_option( 'tbttm_bull_images', array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( self::default_bulls() as $key => $url ) {
			$out[ $key ] = ! empty( $saved[ $key ] ) ? (string) $saved[ $key ] : $url;
		}
		return $out;
	}

	public function register_menu() {
		if ( defined( 'TBT_HUB_SLUG' ) ) {
			add_submenu_page(
				TBT_HUB_SLUG,
				__( 'Test Maker Settings', 'tbt-test-maker' ),
				__( 'Test Maker Settings', 'tbt-test-maker' ),
				'manage_options',
				self::SLUG,
				array( $this, 'render' )
			);
		} else {
			add_options_page(
				__( 'Test Maker Settings', 'tbt-test-maker' ),
				__( 'TBT Test Maker', 'tbt-test-maker' ),
				'manage_options',
				self::SLUG,
				array( $this, 'render' )
			);
		}
	}

	public function register_settings() {
		register_setting( self::GROUP, 'tbttm_player_page_id', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );
		register_setting( self::GROUP, 'tbttm_builder_page_id', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );
		register_setting( self::GROUP, 'tbttm_manager_roles', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_manager_roles' ),
			'default'           => array( 'administrator' ),
		) );
		register_setting( self::GROUP, 'tbttm_bull_images', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_bulls' ),
			'default'           => self::default_bulls(),
		) );
		register_setting( self::GROUP, 'tbttm_certificate_bg', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_background' ),
			'default'           => TBTTM_Certificate::DEFAULT_BACKGROUND,
		) );
	}

	/**
	 * One URL; an empty field falls back to the default picture.
	 *
	 * @param mixed $value Submitted URL.
	 * @return string
	 */
	public function sanitize_background( $value ) {
		$url = is_string( $value ) ? esc_url_raw( trim( $value ) ) : '';
		return '' !== $url ? $url : TBTTM_Certificate::DEFAULT_BACKGROUND;
	}

	/**
	 * Keep only real roles, always including administrators, and sync the
	 * capability to match. As in Translation Drill.
	 *
	 * @param mixed $value Submitted role slugs.
	 * @return string[]
	 */
	public function sanitize_manager_roles( $value ) {
		$selected = array_values( array_unique( array_map( 'sanitize_key', (array) $value ) ) );
		$selected = array_values( array_filter( $selected, function ( $slug ) {
			return (bool) get_role( $slug );
		} ) );
		if ( ! in_array( 'administrator', $selected, true ) ) {
			$selected[] = 'administrator';
		}

		TBTTM_Capabilities::sync_role_caps( $selected );

		return $selected;
	}

	/**
	 * Six URLs; an empty field falls back to its default.
	 *
	 * @param mixed $value Submitted URLs.
	 * @return array<string, string>
	 */
	public function sanitize_bulls( $value ) {
		$value = is_array( $value ) ? $value : array();
		$out   = array();
		foreach ( self::default_bulls() as $key => $default ) {
			$url         = isset( $value[ $key ] ) && is_string( $value[ $key ] ) ? esc_url_raw( trim( $value[ $key ] ) ) : '';
			$out[ $key ] = '' !== $url ? $url : $default;
		}
		return $out;
	}

	/**
	 * A page dropdown with its help line.
	 */
	private function page_row( $option, $label, $help ) {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php
				wp_dropdown_pages( array(
					'name'              => $option,
					'id'                => $option,
					'selected'          => (int) get_option( $option, 0 ),
					'show_option_none'  => __( '— Select a page —', 'tbt-test-maker' ),
					'option_none_value' => 0,
				) );
				?>
				<p class="description"><?php echo esc_html( $help ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$manager_roles = TBTTM_Capabilities::managing_roles();
		$all_roles     = wp_roles()->roles;
		$bulls         = self::bulls();
		$bull_labels   = array(
			'start'  => __( 'Start (first question)', 'tbt-test-maker' ),
			'run'    => __( 'Run (middle question)', 'tbt-test-maker' ),
			'attack' => __( 'Attack (near the end)', 'tbt-test-maker' ),
			'jump'   => __( 'Jump (last question)', 'tbt-test-maker' ),
			'pass'   => __( 'Pass (result)', 'tbt-test-maker' ),
			'fail'   => __( 'Fail (result)', 'tbt-test-maker' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Test Maker Settings', 'tbt-test-maker' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'Pages', 'tbt-test-maker' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->page_row(
						'tbttm_player_page_id',
						__( 'Student page', 'tbt-test-maker' ),
						__( 'The published page with the [tbt_test] shortcode. Every test link points here.', 'tbt-test-maker' )
					);
					$this->page_row(
						'tbttm_builder_page_id',
						__( 'Teacher page', 'tbt-test-maker' ),
						__( 'The published page with the [tbt_test_maker] shortcode, where teachers build tests.', 'tbt-test-maker' )
					);
					?>
				</table>

				<h2 class="title"><?php esc_html_e( 'Who can build tests', 'tbt-test-maker' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Roles', 'tbt-test-maker' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Roles that can build tests', 'tbt-test-maker' ); ?></legend>
								<?php foreach ( $all_roles as $slug => $role ) : ?>
									<label style="display:block">
										<input type="checkbox" name="tbttm_manager_roles[]" value="<?php echo esc_attr( $slug ); ?>"
											<?php checked( in_array( $slug, $manager_roles, true ) || 'administrator' === $slug ); ?>
											<?php disabled( 'administrator' === $slug ); ?>>
										<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
									</label>
								<?php endforeach; ?>
								<input type="hidden" name="tbttm_manager_roles[]" value="administrator">
								<p class="description"><?php esc_html_e( 'Administrators can always build tests. Each teacher sees only their own tests.', 'tbt-test-maker' ); ?></p>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Bull pictures', 'tbt-test-maker' ); ?></h2>
				<p><?php esc_html_e( 'Shown on four questions of a test with bull pictures on, and on the result when the test has a pass mark. Leave a field empty to use the default picture.', 'tbt-test-maker' ); ?></p>
				<table class="form-table" role="presentation">
					<?php foreach ( self::BULLS as $key ) : ?>
						<tr>
							<th scope="row"><label for="tbttm_bull_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $bull_labels[ $key ] ); ?></label></th>
							<td>
								<input type="url" class="large-text" id="tbttm_bull_<?php echo esc_attr( $key ); ?>"
									name="tbttm_bull_images[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $bulls[ $key ] ); ?>">
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<h2 class="title"><?php esc_html_e( 'Certificate', 'tbt-test-maker' ); ?></h2>
				<p><?php esc_html_e( 'The picture behind every certificate: a JPG in this site\'s media library. The name, the test title, the score and the date are printed over it. Leave the field empty to use the default picture.', 'tbt-test-maker' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tbttm_certificate_bg"><?php esc_html_e( 'Certificate background', 'tbt-test-maker' ); ?></label></th>
						<td>
							<input type="url" class="large-text" id="tbttm_certificate_bg" name="tbttm_certificate_bg"
								value="<?php echo esc_attr( TBTTM_Certificate::background_url() ); ?>">
							<?php $background = TBTTM_Certificate::background_path(); ?>
							<?php if ( '' !== $background ) : ?>
								<p class="description"><?php esc_html_e( 'Found on this server.', 'tbt-test-maker' ); ?></p>
								<p class="description">
									<?php
									/* translators: %s: a file size, such as "412 KB". */
									echo esc_html( sprintf( __( 'The picture is %s. Every certificate carries it whole, so each PDF is about that size.', 'tbt-test-maker' ), size_format( (int) filesize( $background ) ) ) );
									?>
								</p>
							<?php else : ?>
								<?php // wp-ui-text-notification is the admin's own red text, so no colour is written here. ?>
								<p class="description"><span class="wp-ui-text-notification"><strong><?php esc_html_e( 'This file is not on this server, so certificates are switched off.', 'tbt-test-maker' ); ?></strong></span></p>
							<?php endif; ?>
							<?php if ( 'mbstring' === TBTTM_Certificate::problem() ) : ?>
								<p class="description"><span class="wp-ui-text-notification"><strong><?php esc_html_e( 'This server\'s PHP has no mbstring extension, so certificates are switched off. Ask the host to turn mbstring on.', 'tbt-test-maker' ); ?></strong></span></p>
							<?php elseif ( in_array( TBTTM_Certificate::problem(), array( 'library', 'font' ), true ) ) : ?>
								<p class="description"><span class="wp-ui-text-notification"><strong><?php esc_html_e( 'A file the certificate needs is missing from the plugin, so certificates are switched off. Upload the plugin again.', 'tbt-test-maker' ); ?></strong></span></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
