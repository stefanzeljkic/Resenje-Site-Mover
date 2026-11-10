<?php
/**
 * Removes options, cron events and the backup folder when the plugin is deleted.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-rsmover-storage.php';

foreach ( array( 'rsmover_last_success', 'rsmover_flush_rewrite', 'rsmover_schedule', 'rsmover_schedule_log' ) as $rsmover_option ) {
	delete_option( $rsmover_option );
}
wp_clear_scheduled_hook( 'rsmover_scheduled_backup' );
wp_clear_scheduled_hook( 'rsmover_cron_step' );
delete_metadata( 'user', 0, 'rsmover_donate_dismissed', '', true );
RSMover_Storage::remove_all();
