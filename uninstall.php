<?php
/**
 * Removes options and the backup folder when the plugin is deleted.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-rsmover-storage.php';

delete_option( 'rsmover_last_success' );
delete_metadata( 'user', 0, 'rsmover_donate_dismissed', '', true );
RSMover_Storage::remove_all();
