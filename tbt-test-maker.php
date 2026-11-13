<?php
/**
 * Plugin Name:       TBT Test Maker
 * Description:       Tests for The Blue Tree, with multiple choice and fill-in-the-gaps questions. Teachers build a test or import its questions from Excel, and can attach it to a TBT Notes class. A test is for logged-in students or open to anyone with the link; either way it is taken one question at a time, with every answer saved as it is given, and the result shown at the end, in points: one per multiple choice question, one per gap. A test can give a PDF certificate and email the student their result. Scoring happens on the server.
 * Version:           0.6.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            TBT
 * License:           GPL-2.0-or-later
 * Text Domain:       tbt-test-maker
 */

defined( 'ABSPATH' ) || exit;

define( 'TBTTM_VERSION', '0.6.0' );
define( 'TBTTM_DB_VERSION', '1.0' );
define( 'TBTTM_PLUGIN_FILE', __FILE__ );
define( 'TBTTM_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBTTM_URL', plugin_dir_url( __FILE__ ) );

/**
 * The one capability that gates building tests. Site configuration (pages,
 * role grants, bull pictures) stays on manage_options — see TBTTM_Capabilities.
 */
define( 'TBTTM_CAP', 'tbttm_manage' );

require_once TBTTM_DIR . 'includes/class-tbttm-capabilities.php';
require_once TBTTM_DIR . 'includes/class-tbttm-gaps.php';
require_once TBTTM_DIR . 'includes/class-tbttm-db.php';
require_once TBTTM_DIR . 'includes/class-tbttm-urls.php';
require_once TBTTM_DIR . 'includes/class-tbttm-assets.php';
require_once TBTTM_DIR . 'includes/class-tbttm-classes.php';
require_once TBTTM_DIR . 'includes/class-tbttm-validator.php';
require_once TBTTM_DIR . 'includes/class-tbttm-attempts.php';
require_once TBTTM_DIR . 'includes/class-tbttm-results.php';
require_once TBTTM_DIR . 'includes/class-tbttm-certificate.php';
require_once TBTTM_DIR . 'includes/class-tbttm-mail.php';
require_once TBTTM_DIR . 'includes/class-tbttm-rest.php';
require_once TBTTM_DIR . 'includes/class-tbttm-teacher.php';
require_once TBTTM_DIR . 'includes/class-tbttm-player.php';
require_once TBTTM_DIR . 'includes/class-tbttm-settings.php';
require_once TBTTM_DIR . 'includes/class-tbttm-notes-bridge.php';

/**
 * Register this plugin on the TBT Hub Overview page.
 *
 * A filter on Hub's own hook: when Hub is absent nothing ever applies it, so
 * this costs nothing and breaks nothing.
 *
 * @param array $items Existing hub items.
 * @return array
 */
function tbttm_register_hub_item( $items ) {
	$items[] = array(
		'slug'        => 'tbt-test-maker',
		'title'       => 'TBT Test Maker',
		'description' => 'Multiple-choice tests taken one question at a time, with every answer saved as it is given.',
		'capability'  => TBTTM_CAP,
	);
	return $items;
}
add_filter( 'tbt_hub_items', 'tbttm_register_hub_item' );

/**
 * Create the tables and grant the management capability.
 */
function tbttm_activate() {
	TBTTM_DB::activate();
	TBTTM_Capabilities::add_caps();
	update_option( 'tbttm_caps_version', TBTTM_Capabilities::CAPS_VERSION );
}
register_activation_hook( __FILE__, 'tbttm_activate' );

add_action( 'plugins_loaded', function () {
	TBTTM_DB::maybe_upgrade();
	TBTTM_Capabilities::maybe_add_caps();

	new TBTTM_Rest();
	new TBTTM_Teacher();
	new TBTTM_Player();
	new TBTTM_Notes_Bridge();
	new TBTTM_Mail();

	if ( is_admin() ) {
		new TBTTM_Settings();
	}
} );
