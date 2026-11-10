<?php
/**
 * Plugin Name:       Resenje Site Mover – Backup & Migration
 * Plugin URI:        https://github.com/stefanzeljkic/Resenje-Site-Mover
 * Description:       Back up a complete WordPress site into one file and move it to another hosting or domain. Addresses are changed automatically, also inside serialized data. Free, no limits.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Stefan Zeljkic
 * Author URI:        https://github.com/stefanzeljkic
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       resenje-site-mover
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'RSMOVER_VERSION', '1.1.0' );
define( 'RSMOVER_FILE', __FILE__ );
define( 'RSMOVER_BASENAME', plugin_basename( __FILE__ ) );

require_once __DIR__ . '/includes/class-rsmover-storage.php';
require_once __DIR__ . '/includes/class-rsmover-archive.php';
require_once __DIR__ . '/includes/class-rsmover-replacer.php';
require_once __DIR__ . '/includes/class-rsmover-export.php';
require_once __DIR__ . '/includes/class-rsmover-import.php';
require_once __DIR__ . '/includes/class-rsmover-jobs.php';
require_once __DIR__ . '/includes/class-rsmover-crypto.php';
require_once __DIR__ . '/includes/class-rsmover-remote.php';
require_once __DIR__ . '/includes/class-rsmover-schedule.php';

RSMover_Schedule::init();
register_deactivation_hook( __FILE__, 'rsmover_deactivate' );

/**
 * Removes the cron events when the plugin is deactivated.
 */
function rsmover_deactivate() {
	wp_clear_scheduled_hook( RSMover_Schedule::HOOK_RUN );
	wp_clear_scheduled_hook( RSMover_Schedule::HOOK_STEP );
}

add_action( 'init', 'rsmover_flush_rewrite_after_import', 99 );

/**
 * After an import, rebuilds the permalink rules and writes them to .htaccess (Apache/LiteSpeed),
 * so pages other than the home page work without saving the permalink settings by hand.
 */
function rsmover_flush_rewrite_after_import() {
	// .htaccess can only be written from a web request (WP-CLI does not know the web server).
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ! get_option( 'rsmover_flush_rewrite' ) ) {
		return;
	}
	delete_option( 'rsmover_flush_rewrite' );
	if ( ! function_exists( 'save_mod_rewrite_rules' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}
	flush_rewrite_rules( true );
}

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-rsmover-admin.php';
	RSMover_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-rsmover-cli.php';
	WP_CLI::add_command( 'site-mover', 'RSMover_CLI' );
}
