<?php
/**
 * Plugin Name:       Resenje Site Mover – Backup & Migration
 * Plugin URI:        https://github.com/stefanzeljkic/Resenje-Site-Mover
 * Description:       Back up a complete WordPress site into one file and move it to another hosting or domain. Addresses are changed automatically, also inside serialized data. Free, no limits.
 * Version:           1.0.0
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

define( 'RSMOVER_VERSION', '1.0.0' );
define( 'RSMOVER_FILE', __FILE__ );
define( 'RSMOVER_BASENAME', plugin_basename( __FILE__ ) );

require_once __DIR__ . '/includes/class-rsmover-storage.php';
require_once __DIR__ . '/includes/class-rsmover-archive.php';
require_once __DIR__ . '/includes/class-rsmover-replacer.php';
require_once __DIR__ . '/includes/class-rsmover-export.php';
require_once __DIR__ . '/includes/class-rsmover-import.php';
require_once __DIR__ . '/includes/class-rsmover-jobs.php';

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-rsmover-admin.php';
	RSMover_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/class-rsmover-cli.php';
	WP_CLI::add_command( 'site-mover', 'RSMover_CLI' );
}
