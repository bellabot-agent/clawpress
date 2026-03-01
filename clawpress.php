<?php
/**
 * Plugin Name: ClawPress
 * Plugin URI:  https://openclaw.com/clawpress
 * Description: One-click wizard to connect OpenClaw to your WordPress site via Application Passwords.
 * Version:     2.0.0
 * Author:      OpenClaw
 * Author URI:  https://openclaw.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: clawpress
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CLAWPRESS_VERSION', '2.0.0' );
define( 'CLAWPRESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLAWPRESS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CLAWPRESS_APP_PASSWORD_NAME', 'OpenClaw' );

require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-api.php';
require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-admin.php';
require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-tracker.php';
require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-manifest.php';
require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-handshake.php';
require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-assistant.php';
require_once CLAWPRESS_PLUGIN_DIR . 'includes/class-clawpress-assistant-admin.php';

/**
 * Initialize the plugin.
 */
function clawpress_init() {
	$api       = new ClawPress_API();
	$admin     = new ClawPress_Admin( $api );
	$tracker   = new ClawPress_Tracker();
	$manifest  = new ClawPress_Manifest();
	$handshake = new ClawPress_Handshake();
	$assistant = new ClawPress_Assistant();
	$assistant_admin = new ClawPress_Assistant_Admin( $assistant );

	$admin->init();
	$tracker->init();
	$manifest->init();
	$handshake->init();
	$assistant->init();
	$assistant_admin->init();
}
add_action( 'plugins_loaded', 'clawpress_init' );
