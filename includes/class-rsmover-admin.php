<?php
/**
 * Admin screen, AJAX handlers, downloads and donation prompts.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin integration.
 */
class RSMover_Admin {

	const PAGE  = 'resenje-site-mover';
	const NONCE = 'rsmover_action';

	const SCHEDULE_PAGE = 'resenje-site-mover-schedule';

	/**
	 * Donation links.
	 *
	 * @return array label => URL.
	 */
	public static function donate_links() {
		return array(
			__( 'Buy Me a Coffee', 'resenje-site-mover' )  => 'https://buymeacoffee.com/stefanzeljkic',
			__( 'Support on Ko-fi', 'resenje-site-mover' ) => 'https://ko-fi.com/stefanzeljkic',
			__( 'Donate via Patreon', 'resenje-site-mover' ) => 'https://www.patreon.com/user?u=76138587',
		);
	}

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'donate_notice' ) );
		add_filter( 'plugin_action_links_' . RSMOVER_BASENAME, array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_rsmover_download', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_rsmover_dismiss', array( __CLASS__, 'dismiss_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		foreach ( array( 'start', 'step', 'cancel', 'upload', 'delete', 'test_remote', 'run_now' ) as $action ) {
			add_action( 'wp_ajax_rsmover_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	/**
	 * Adds the admin page.
	 */
	public static function menu() {
		add_menu_page(
			__( 'Resenje Site Mover', 'resenje-site-mover' ),
			__( 'Site Mover', 'resenje-site-mover' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' ),
			'dashicons-migrate',
			80
		);
		add_submenu_page( self::PAGE, __( 'Backup & Migration', 'resenje-site-mover' ), __( 'Backup & Migration', 'resenje-site-mover' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
		add_submenu_page( self::PAGE, __( 'Scheduled backups', 'resenje-site-mover' ), __( 'Scheduled backups', 'resenje-site-mover' ), 'manage_options', self::SCHEDULE_PAGE, array( __CLASS__, 'schedule_page' ) );
	}

	/**
	 * Settings / Donate links on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Backup & Migrate', 'resenje-site-mover' ) . '</a>' );
		return $links;
	}

	/**
	 * Donate links in the plugin row.
	 *
	 * @param array  $meta Row meta.
	 * @param string $file Plugin file.
	 * @return array
	 */
	public static function row_meta( $meta, $file ) {
		if ( RSMOVER_BASENAME === $file ) {
			foreach ( self::donate_links() as $label => $url ) {
				$meta[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">&#9749; ' . esc_html( $label ) . '</a>';
			}
		}
		return $meta;
	}

	/**
	 * Loads the admin assets on the plugin page.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'rsmover-admin', plugins_url( 'assets/admin.css', RSMOVER_FILE ), array(), RSMOVER_VERSION );
		wp_enqueue_script( 'rsmover-admin', plugins_url( 'assets/admin.js', RSMOVER_FILE ), array(), RSMOVER_VERSION, true );
		$chunk = (int) max( 262144, min( 8388608, wp_max_upload_size() - 65536 ) );
		wp_localize_script(
			'rsmover-admin',
			'rsmoverData',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'chunk'    => $chunk,
				'loginUrl' => wp_login_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
				'i18n'     => array(
					'confirmImport' => __( 'The import REPLACES all content, users and settings of this site with the content of the backup. This cannot be undone. Continue?', 'resenje-site-mover' ),
					'confirmDelete' => __( 'Delete this backup file?', 'resenje-site-mover' ),
					'confirmCancel' => __( 'Cancel the running job?', 'resenje-site-mover' ),
					'chooseFile'    => __( 'Choose a .rsm backup file first.', 'resenje-site-mover' ),
					/* translators: %d: upload progress percent */
					'uploading'     => __( 'Uploading backup: %d%%', 'resenje-site-mover' ),
					'starting'      => __( 'Starting…', 'resenje-site-mover' ),
					'error'         => __( 'Error:', 'resenje-site-mover' ),
					'network'       => __( 'The server did not answer correctly. Reload the page; if the problem persists check the PHP error log.', 'resenje-site-mover' ),
					'exportDone'    => __( 'Backup complete. Download it below and import it on the new site.', 'resenje-site-mover' ),
					'importDone'    => __( 'Import complete! You were logged out because the users now come from the backup. Log in with a user and password of the SOURCE site.', 'resenje-site-mover' ),
					'login'         => __( 'Log in', 'resenje-site-mover' ),
				),
			)
		);
	}

	/**
	 * Prints the donation box.
	 *
	 * @param string $title Title.
	 * @param string $text  Text.
	 */
	public static function donate_box( $title, $text ) {
		?>
		<div class="rsmover-donate">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p><?php echo esc_html( $text ); ?></p>
			<p class="rsmover-donate-buttons">
				<?php foreach ( self::donate_links() as $label => $url ) : ?>
					<a href="<?php echo esc_url( $url ); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">&#9749; <?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the admin page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$archives = RSMover_Storage::list_archives();
		$job      = RSMover_Storage::get_job();
		?>
		<div class="wrap rsmover">
			<h1><?php esc_html_e( 'Resenje Site Mover – Backup & Migration', 'resenje-site-mover' ); ?></h1>
			<p class="rsmover-free"><?php esc_html_e( 'This plugin is 100% free – no premium version, no limits. It is kept alive by donations from people like you.', 'resenje-site-mover' ); ?></p>

			<?php if ( is_multisite() ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Multisite networks are not supported.', 'resenje-site-mover' ); ?></p></div>
			<?php endif; ?>

			<div class="rsmover-grid">
				<div class="rsmover-main">
					<div id="rsmover-progress" class="rsmover-card" <?php echo $job ? '' : 'hidden'; ?> data-running="<?php echo $job ? '1' : '0'; ?>">
						<h2><?php esc_html_e( 'Working…', 'resenje-site-mover' ); ?></h2>
						<div class="rsmover-bar"><span style="width:0"></span></div>
						<p class="rsmover-message"><?php esc_html_e( 'Keep this page open until the job is finished.', 'resenje-site-mover' ); ?></p>
						<p><button type="button" class="button" id="rsmover-cancel"><?php esc_html_e( 'Cancel', 'resenje-site-mover' ); ?></button></p>
					</div>

					<div id="rsmover-result" class="rsmover-card" hidden>
						<p class="rsmover-result-text"></p>
						<p class="rsmover-result-login" hidden><a class="button button-primary" href="<?php echo esc_url( wp_login_url( admin_url( 'admin.php?page=' . self::PAGE ) ) ); ?>"><?php esc_html_e( 'Log in', 'resenje-site-mover' ); ?></a></p>
						<?php self::donate_box( __( 'Did this save you time?', 'resenje-site-mover' ), __( 'Resenje Site Mover is free and will stay free. If it saved you hours of work (or a hosting migration fee), please buy me a coffee – it really helps keep the plugin maintained.', 'resenje-site-mover' ) ); ?>
					</div>

					<div class="rsmover-card">
						<h2><?php esc_html_e( '1. Create a backup', 'resenje-site-mover' ); ?></h2>
						<p><?php esc_html_e( 'Packs the database and the whole wp-content folder (themes, plugins, media) into one .rsm file.', 'resenje-site-mover' ); ?></p>
						<p><label><input type="checkbox" id="rsmover-exclude-media"> <?php esc_html_e( 'Leave out the media library (uploads folder)', 'resenje-site-mover' ); ?></label></p>
						<p><button type="button" class="button button-primary button-hero" id="rsmover-export"><?php esc_html_e( 'Create backup', 'resenje-site-mover' ); ?></button></p>
					</div>

					<div class="rsmover-card">
						<h2><?php esc_html_e( '2. Import / migrate to this site', 'resenje-site-mover' ); ?></h2>
						<p><?php esc_html_e( 'On the new hosting: install a fresh WordPress, install Resenje Site Mover, then upload the .rsm file here. Addresses and paths are changed to this site automatically (also inside serialized data).', 'resenje-site-mover' ); ?></p>
						<p class="rsmover-warning"><?php esc_html_e( 'Warning: the import replaces all content, users and settings of THIS site.', 'resenje-site-mover' ); ?></p>
						<p><input type="file" id="rsmover-file" accept=".rsm"></p>
						<p><button type="button" class="button button-primary" id="rsmover-upload"><?php esc_html_e( 'Upload and import', 'resenje-site-mover' ); ?></button></p>
						<p class="description">
							<?php
							/* translators: %s: maximum chunk size */
							echo esc_html( sprintf( __( 'Large files are uploaded in parts, so the hosting upload limit (%s) does not matter. You can also copy a .rsm file into the backup folder by FTP; it then appears in the list below.', 'resenje-site-mover' ), size_format( wp_max_upload_size() ) ) );
							?>
						</p>
					</div>

					<div class="rsmover-card">
						<h2><?php esc_html_e( 'Backups on this server', 'resenje-site-mover' ); ?></h2>
						<?php if ( empty( $archives ) ) : ?>
							<p><?php esc_html_e( 'No backups yet.', 'resenje-site-mover' ); ?></p>
						<?php else : ?>
							<table class="widefat striped">
								<thead><tr>
									<th><?php esc_html_e( 'File', 'resenje-site-mover' ); ?></th>
									<th><?php esc_html_e( 'Size', 'resenje-site-mover' ); ?></th>
									<th><?php esc_html_e( 'Date', 'resenje-site-mover' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'resenje-site-mover' ); ?></th>
								</tr></thead>
								<tbody>
								<?php foreach ( $archives as $item ) : ?>
									<?php
									$download = wp_nonce_url(
										add_query_arg(
											array(
												'action' => 'rsmover_download',
												'file'   => $item['name'],
											),
											admin_url( 'admin-post.php' )
										),
										self::NONCE
									);
									?>
									<tr>
										<td><code><?php echo esc_html( $item['name'] ); ?></code></td>
										<td><?php echo esc_html( size_format( $item['size'], 1 ) ); ?></td>
										<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['time'] ) ); ?></td>
										<td>
											<a class="button" href="<?php echo esc_url( $download ); ?>"><?php esc_html_e( 'Download', 'resenje-site-mover' ); ?></a>
											<button type="button" class="button rsmover-restore" data-file="<?php echo esc_attr( $item['name'] ); ?>"><?php esc_html_e( 'Import', 'resenje-site-mover' ); ?></button>
											<button type="button" class="button-link-delete rsmover-delete" data-file="<?php echo esc_attr( $item['name'] ); ?>"><?php esc_html_e( 'Delete', 'resenje-site-mover' ); ?></button>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
						<p class="description">
							<?php
							/* translators: %s: folder path */
							echo esc_html( sprintf( __( 'Folder: %s (protected against direct web access). Download your backups and keep a copy somewhere else.', 'resenje-site-mover' ), wp_normalize_path( RSMover_Storage::dir() ) ) );
							?>
						</p>
					</div>
				</div>

				<div class="rsmover-side">
					<?php self::donate_box( __( 'Support development', 'resenje-site-mover' ), __( 'Free plugins need coffee too. If Resenje Site Mover helps you, a small donation keeps updates coming.', 'resenje-site-mover' ) ); ?>
					<div class="rsmover-card">
						<h2><?php esc_html_e( 'How to move a site', 'resenje-site-mover' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Old site: create a backup and download it.', 'resenje-site-mover' ); ?></li>
							<li><?php esc_html_e( 'New hosting: install WordPress and this plugin.', 'resenje-site-mover' ); ?></li>
							<li><?php esc_html_e( 'New site: upload the backup under "Import".', 'resenje-site-mover' ); ?></li>
							<li><?php esc_html_e( 'Log in with the old site\'s user and password.', 'resenje-site-mover' ); ?></li>
							<li><?php esc_html_e( 'Done. Permalinks are rebuilt automatically; on nginx servers ask the host to use the standard WordPress rules.', 'resenje-site-mover' ); ?></li>
						</ol>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Shows a dismissible donation notice on the Dashboard and Plugins screens after a successful job.
	 */
	public static function donate_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! get_option( 'rsmover_last_success' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), 'rsmover_donate_dismissed', true ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=rsmover_dismiss' ), 'rsmover_dismiss' );
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Resenje Site Mover finished your backup / migration.', 'resenje-site-mover' ); ?></strong>
				<?php esc_html_e( 'The plugin is free; if it helped you, please consider a small donation.', 'resenje-site-mover' ); ?>
			</p>
			<p>
				<?php foreach ( self::donate_links() as $label => $url ) : ?>
					<a href="<?php echo esc_url( $url ); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">&#9749; <?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
				<a href="<?php echo esc_url( $dismiss ); ?>" class="button"><?php esc_html_e( 'Don\'t show again', 'resenje-site-mover' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Permanently hides the donation notice for the current user.
	 */
	public static function dismiss_notice() {
		check_admin_referer( 'rsmover_dismiss' );
		if ( current_user_can( 'manage_options' ) ) {
			update_user_meta( get_current_user_id(), 'rsmover_donate_dismissed', 1 );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Capability check for AJAX requests (the nonce is checked in each handler).
	 */
	private static function ajax_guard() {
		if ( ! current_user_can( 'manage_options' ) || is_multisite() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'resenje-site-mover' ) ), 403 );
		}
	}

	/**
	 * Sends the job status.
	 *
	 * @param array $job Job.
	 */
	private static function send_job( array $job ) {
		$progress = RSMover_Jobs::progress( $job );
		wp_send_json_success(
			array(
				'type'    => $job['type'],
				'done'    => 'done' === $job['phase'],
				'percent' => $progress['percent'],
				'message' => $progress['message'],
				'archive' => isset( $job['archive'] ) ? $job['archive'] : '',
			)
		);
	}

	/**
	 * Starts an export or import.
	 */
	public static function ajax_start() {
		check_ajax_referer( self::NONCE, 'nonce' );
		self::ajax_guard();
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		try {
			if ( 'export' === $type ) {
				$job = RSMover_Jobs::start( 'export', array( 'exclude_media' => ! empty( $_POST['exclude_media'] ) ) );
			} else {
				$archive = isset( $_POST['archive'] ) ? sanitize_file_name( wp_unslash( $_POST['archive'] ) ) : '';
				$job     = RSMover_Jobs::start( 'import', array( 'archive' => $archive ) );
			}
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		self::send_job( $job );
	}

	/**
	 * Runs one step.
	 */
	public static function ajax_step() {
		check_ajax_referer( self::NONCE, 'nonce' );
		self::ajax_guard();
		try {
			$job = RSMover_Jobs::step();
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		self::send_job( $job );
	}

	/**
	 * Cancels the running job.
	 */
	public static function ajax_cancel() {
		check_ajax_referer( self::NONCE, 'nonce' );
		self::ajax_guard();
		$job = RSMover_Storage::get_job();
		if ( $job && ! empty( $job['auto'] ) ) {
			RSMover_Schedule::log( 'skipped', __( 'Scheduled backup cancelled by a user.', 'resenje-site-mover' ) );
		}
		RSMover_Jobs::cancel();
		wp_send_json_success();
	}

	/**
	 * Deletes a backup.
	 */
	public static function ajax_delete() {
		check_ajax_referer( self::NONCE, 'nonce' );
		self::ajax_guard();
		$name = isset( $_POST['archive'] ) ? sanitize_file_name( wp_unslash( $_POST['archive'] ) ) : '';
		if ( ! RSMover_Storage::delete_archive( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Backup file not found.', 'resenje-site-mover' ) ) );
		}
		wp_send_json_success();
	}

	/**
	 * Receives one part of an uploaded backup.
	 */
	public static function ajax_upload() {
		check_ajax_referer( self::NONCE, 'nonce' );
		self::ajax_guard();
		// Large archives are appended part by part; WP_Filesystem has no append API.
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$final  = ! empty( $_POST['final'] );
		$name   = isset( $_POST['archive'] ) ? sanitize_file_name( wp_unslash( $_POST['archive'] ) ) : '';
		$tmp    = isset( $_FILES['chunk']['tmp_name'] ) ? sanitize_text_field( $_FILES['chunk']['tmp_name'] ) : '';

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'Upload failed (no data received). The part may be larger than the server allows.', 'resenje-site-mover' ) ) );
		}
		if ( 0 === $offset ) {
			$original = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
			if ( '.rsm' !== strtolower( substr( $original, -4 ) ) ) {
				wp_send_json_error( array( 'message' => __( 'Only .rsm files made by Resenje Site Mover can be imported.', 'resenje-site-mover' ) ) );
			}
			$name = RSMover_Storage::new_archive_name( 'upload' );
			$path = RSMover_Storage::archive_path( $name, false );
			file_put_contents( $path, '' );
		} else {
			$path = RSMover_Storage::archive_path( $name );
		}
		if ( ! $path ) {
			wp_send_json_error( array( 'message' => __( 'Upload failed (unknown file).', 'resenje-site-mover' ) ) );
		}
		clearstatcache( true, $path );
		if ( filesize( $path ) !== $offset ) {
			wp_send_json_error( array( 'message' => __( 'Upload failed (parts out of order). Please try again.', 'resenje-site-mover' ) ) );
		}
		$in  = fopen( $tmp, 'rb' );
		$out = fopen( $path, 'ab' );
		if ( ! $in || ! $out ) {
			wp_send_json_error( array( 'message' => __( 'Upload failed (cannot write file).', 'resenje-site-mover' ) ) );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		if ( $final && ! RSMover_Archive::is_archive( $path ) ) {
			wp_delete_file( $path );
			wp_send_json_error( array( 'message' => __( 'This is not a Resenje Site Mover backup file.', 'resenje-site-mover' ) ) );
		}
		clearstatcache( true, $path );
		wp_send_json_success(
			array(
				'archive' => $name,
				'size'    => filesize( $path ),
			)
		);
	}

	/**
	 * Streams a backup file to the browser.
	 */
	public static function download() {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'resenje-site-mover' ), 403 );
		}
		$name = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$path = RSMover_Storage::archive_path( $name );
		if ( ! $path ) {
			wp_die( esc_html__( 'Backup file not found.', 'resenje-site-mover' ), 404 );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		// Streams a large binary file in chunks; WP_Filesystem would load it into memory.
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$h = fopen( $path, 'rb' );
		if ( $h ) {
			while ( ! feof( $h ) ) {
				echo fread( $h, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file download.
				flush();
			}
			fclose( $h );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Registers the schedule settings.
	 */
	public static function register_settings() {
		register_setting(
			'rsmover_schedule',
			RSMover_Schedule::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'RSMover_Schedule', 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Loads the assets of the schedule page.
	 */
	private static function schedule_assets() {
		wp_enqueue_style( 'rsmover-admin', plugins_url( 'assets/admin.css', RSMOVER_FILE ), array(), RSMOVER_VERSION );
		wp_enqueue_script( 'rsmover-schedule', plugins_url( 'assets/schedule.js', RSMOVER_FILE ), array(), RSMOVER_VERSION, true );
		wp_localize_script(
			'rsmover-schedule',
			'rsmoverSchedule',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'mainPage' => admin_url( 'admin.php?page=' . self::PAGE ),
				'i18n'     => array(
					'testing' => __( 'Testing the connection…', 'resenje-site-mover' ),
					'error'   => __( 'Error:', 'resenje-site-mover' ),
					'network' => __( 'The server did not answer correctly.', 'resenje-site-mover' ),
				),
			)
		);
	}

	/**
	 * Renders a text input of the schedule form.
	 *
	 * @param string $name        Field name below the option (e.g. "sftp][host").
	 * @param string $value       Value.
	 * @param string $type        Input type.
	 * @param string $placeholder Placeholder.
	 * @param string $autocomplete Autocomplete attribute.
	 */
	private static function field( $name, $value, $type = 'text', $placeholder = '', $autocomplete = '' ) {
		printf(
			'<input type="%1$s" name="%2$s" value="%3$s" class="regular-text" placeholder="%4$s"%5$s>',
			esc_attr( $type ),
			esc_attr( RSMover_Schedule::OPTION . '[' . $name . ']' ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			'' === $autocomplete ? '' : ' autocomplete="' . esc_attr( $autocomplete ) . '"'
		);
	}

	/**
	 * Placeholder text for a secret field.
	 *
	 * @param string $stored   Stored (encrypted) value.
	 * @param string $fallback Placeholder when nothing is saved.
	 * @return string
	 */
	private static function secret_hint( $stored, $fallback = '' ) {
		if ( '' === (string) $stored ) {
			return $fallback;
		}
		return '' === RSMover_Crypto::decrypt( $stored )
			? __( 'Saved value cannot be read (site keys changed) – enter it again', 'resenje-site-mover' )
			: __( 'Saved – leave empty to keep', 'resenje-site-mover' );
	}

	/**
	 * Prints one table row with a label.
	 *
	 * @param string $classes Row classes.
	 * @param string $label   Label.
	 * @param string $html    Cell HTML (already escaped by the caller).
	 */
	private static function row( $classes, $label, $html ) {
		echo '<tr class="' . esc_attr( $classes ) . '"><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is built from escaped parts.
	}

	/**
	 * Captures the output of a field() call.
	 *
	 * @param mixed ...$args field() arguments.
	 * @return string
	 */
	private static function capture_field( ...$args ) {
		ob_start();
		self::field( ...$args );
		return (string) ob_get_clean();
	}

	/**
	 * Checkbox HTML.
	 *
	 * @param string $name    Field name below the option.
	 * @param bool   $checked Checked.
	 * @param string $label   Label.
	 * @return string
	 */
	private static function checkbox( $name, $checked, $label ) {
		return '<label><input type="checkbox" name="' . esc_attr( RSMover_Schedule::OPTION . '[' . $name . ']' ) . '" value="1"' . checked( $checked, true, false ) . '> ' . esc_html( $label ) . '</label>';
	}

	/**
	 * Port input HTML.
	 *
	 * @param string $type  Destination.
	 * @param int    $value Port.
	 * @return string
	 */
	private static function port( $type, $value ) {
		return ' <label>' . esc_html__( 'Port', 'resenje-site-mover' ) . ' <input type="number" min="1" max="65535" class="small-text" name="' . esc_attr( RSMover_Schedule::OPTION . '[' . $type . '][port]' ) . '" value="' . esc_attr( $value ) . '"></label>';
	}

	/**
	 * Renders the schedule page.
	 */
	public static function schedule_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::schedule_assets();
		$s           = RSMover_Schedule::get();
		$opt         = RSMover_Schedule::OPTION;
		$next        = wp_next_scheduled( RSMover_Schedule::HOOK_RUN );
		$log         = get_option( RSMover_Schedule::LOG, array() );
		$days        = array(
			1 => __( 'Monday', 'resenje-site-mover' ),
			2 => __( 'Tuesday', 'resenje-site-mover' ),
			3 => __( 'Wednesday', 'resenje-site-mover' ),
			4 => __( 'Thursday', 'resenje-site-mover' ),
			5 => __( 'Friday', 'resenje-site-mover' ),
			6 => __( 'Saturday', 'resenje-site-mover' ),
			7 => __( 'Sunday', 'resenje-site-mover' ),
		);
		$freq        = array(
			'off'     => __( 'Off', 'resenje-site-mover' ),
			'hourly'  => __( 'Every hour', 'resenje-site-mover' ),
			'daily'   => __( 'Every day', 'resenje-site-mover' ),
			'weekly'  => __( 'Every week', 'resenje-site-mover' ),
			'monthly' => __( 'Every month', 'resenje-site-mover' ),
		);
		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap rsmover">
			<h1><?php esc_html_e( 'Scheduled backups', 'resenje-site-mover' ); ?></h1>
			<p class="rsmover-free"><?php esc_html_e( 'Automatic backups and remote storage are free too – no premium version. Donations keep the plugin alive.', 'resenje-site-mover' ); ?></p>
			<div class="rsmover-grid">
				<div class="rsmover-main">
					<div class="rsmover-card">
						<h2><?php esc_html_e( 'Status', 'resenje-site-mover' ); ?></h2>
						<p>
							<?php
							if ( $next ) {
								/* translators: %s: date and time */
								echo esc_html( sprintf( __( 'Next scheduled backup: %s', 'resenje-site-mover' ), wp_date( $date_format, $next ) ) );
							} else {
								esc_html_e( 'Scheduled backups are off.', 'resenje-site-mover' );
							}
							?>
						</p>
						<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
							<p class="rsmover-warning"><?php esc_html_e( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Make sure a server cron job calls wp-cron.php, otherwise scheduled backups do not run.', 'resenje-site-mover' ); ?></p>
						<?php endif; ?>
						<p>
							<button type="button" class="button" id="rsmover-run-now"><?php esc_html_e( 'Run scheduled backup now', 'resenje-site-mover' ); ?></button>
							<button type="button" class="button" id="rsmover-test"><?php esc_html_e( 'Test remote storage', 'resenje-site-mover' ); ?></button>
						</p>
						<p id="rsmover-schedule-message" aria-live="polite"></p>
						<p class="description"><?php esc_html_e( 'The test uses the saved settings – save first.', 'resenje-site-mover' ); ?></p>
						<p class="description">
							<?php
							/* translators: %s: example cron line */
							echo esc_html( sprintf( __( 'WordPress runs schedules when someone visits the site. For exact times on quiet sites add a server cron job, for example: %s', 'resenje-site-mover' ), '*/5 * * * * wget -q -O - ' . site_url( 'wp-cron.php?doing_wp_cron' ) . ' >/dev/null 2>&1' ) );
							?>
						</p>
					</div>

					<form method="post" action="options.php">
						<?php settings_fields( 'rsmover_schedule' ); ?>
						<div class="rsmover-card">
							<h2><?php esc_html_e( 'When', 'resenje-site-mover' ); ?></h2>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="rsmover-frequency"><?php esc_html_e( 'Back up', 'resenje-site-mover' ); ?></label></th>
									<td>
										<select id="rsmover-frequency" name="<?php echo esc_attr( $opt ); ?>[frequency]">
											<?php foreach ( $freq as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['frequency'], $key ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr class="rsmover-when rsmover-when-hourly rsmover-when-daily rsmover-when-weekly rsmover-when-monthly">
									<th scope="row"><label for="rsmover-time"><?php esc_html_e( 'Time', 'resenje-site-mover' ); ?></label></th>
									<td>
										<input type="time" id="rsmover-time" name="<?php echo esc_attr( $opt ); ?>[time]" value="<?php echo esc_attr( $s['time'] ); ?>">
										<p class="description">
											<?php
											/* translators: %s: time zone name */
											echo esc_html( sprintf( __( 'Site time zone: %s. For hourly backups only the minutes are used.', 'resenje-site-mover' ), wp_timezone_string() ) );
											?>
										</p>
									</td>
								</tr>
								<tr class="rsmover-when rsmover-when-weekly">
									<th scope="row"><label for="rsmover-weekday"><?php esc_html_e( 'Day of the week', 'resenje-site-mover' ); ?></label></th>
									<td>
										<select id="rsmover-weekday" name="<?php echo esc_attr( $opt ); ?>[weekday]">
											<?php foreach ( $days as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>" <?php selected( (int) $s['weekday'], $key ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr class="rsmover-when rsmover-when-monthly">
									<th scope="row"><label for="rsmover-monthday"><?php esc_html_e( 'Day of the month', 'resenje-site-mover' ); ?></label></th>
									<td><input type="number" min="1" max="28" id="rsmover-monthday" name="<?php echo esc_attr( $opt ); ?>[monthday]" value="<?php echo esc_attr( $s['monthday'] ); ?>" class="small-text"></td>
								</tr>
								<?php
								self::row( '', __( 'Content', 'resenje-site-mover' ), self::checkbox( 'exclude_media', $s['exclude_media'], __( 'Leave out the media library (uploads folder)', 'resenje-site-mover' ) ) );
								self::row(
									'',
									__( 'Keep on this server', 'resenje-site-mover' ),
									'<input type="number" min="0" max="100" name="' . esc_attr( $opt . '[keep_local]' ) . '" value="' . esc_attr( $s['keep_local'] ) . '" class="small-text"> ' . esc_html__( 'newest scheduled backups', 'resenje-site-mover' )
									. '<p class="description">' . esc_html__( '0 = delete the local file after a successful upload (only with remote storage). Manual backups are never deleted automatically.', 'resenje-site-mover' ) . '</p>'
								);
								?>
							</table>
						</div>

						<div class="rsmover-card">
							<h2><?php esc_html_e( 'Where', 'resenje-site-mover' ); ?></h2>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="rsmover-destination"><?php esc_html_e( 'Copy backups to', 'resenje-site-mover' ); ?></label></th>
									<td>
										<select id="rsmover-destination" name="<?php echo esc_attr( $opt ); ?>[destination]">
											<?php foreach ( RSMover_Remote::types() as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['destination'], $key ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<?php
								self::row(
									'rsmover-dest rsmover-dest-sftp rsmover-dest-s3 rsmover-dest-ftp',
									__( 'Keep on remote storage', 'resenje-site-mover' ),
									'<input type="number" min="1" max="1000" name="' . esc_attr( $opt . '[keep_remote]' ) . '" value="' . esc_attr( $s['keep_remote'] ) . '" class="small-text"> ' . esc_html__( 'newest backups (older backups of this site are deleted there)', 'resenje-site-mover' )
								);

								// SFTP.
								$c = 'rsmover-dest rsmover-dest-sftp';
								self::row( $c, __( 'Server', 'resenje-site-mover' ), self::capture_field( 'sftp][host', $s['sftp']['host'], 'text', 'backup.example.com' ) . self::port( 'sftp', $s['sftp']['port'] ) );
								self::row( $c, __( 'User name', 'resenje-site-mover' ), self::capture_field( 'sftp][user', $s['sftp']['user'], 'text', '', 'off' ) );
								self::row(
									$c,
									__( 'Log in with', 'resenje-site-mover' ),
									'<label><input type="radio" name="' . esc_attr( $opt . '[sftp][auth]' ) . '" value="password"' . checked( $s['sftp']['auth'], 'password', false ) . '> ' . esc_html__( 'Password', 'resenje-site-mover' ) . '</label> &nbsp; '
									. '<label><input type="radio" name="' . esc_attr( $opt . '[sftp][auth]' ) . '" value="key"' . checked( $s['sftp']['auth'], 'key', false ) . '> ' . esc_html__( 'Private key', 'resenje-site-mover' ) . '</label>'
								);
								self::row( $c, __( 'Password', 'resenje-site-mover' ), self::capture_field( 'sftp][password', '', 'password', self::secret_hint( $s['sftp']['password'] ), 'new-password' ) );
								self::row(
									$c,
									__( 'Private key', 'resenje-site-mover' ),
									'<textarea name="' . esc_attr( $opt . '[sftp][private_key]' ) . '" rows="4" class="large-text code" autocomplete="off" placeholder="' . esc_attr( self::secret_hint( $s['sftp']['private_key'], '-----BEGIN OPENSSH PRIVATE KEY-----' ) ) . '"></textarea>'
									. self::capture_field( 'sftp][passphrase', '', 'password', self::secret_hint( $s['sftp']['passphrase'], __( 'Key passphrase (if any)', 'resenje-site-mover' ) ), 'new-password' )
									. '<p class="description">' . esc_html__( 'Tip: create a separate user and key only for backups.', 'resenje-site-mover' ) . '</p>'
								);
								self::row( $c, __( 'Folder', 'resenje-site-mover' ), self::capture_field( 'sftp][path', $s['sftp']['path'], 'text', 'backups/my-site' ) . '<p class="description">' . esc_html__( 'Created if missing. Empty = home folder of the user.', 'resenje-site-mover' ) . '</p>' );
								$fp = '<code>' . esc_html( '' !== $s['sftp']['fingerprint'] ? $s['sftp']['fingerprint'] : __( 'not known yet – remembered on the first connection', 'resenje-site-mover' ) ) . '</code>';
								if ( '' !== $s['sftp']['fingerprint'] ) {
									$fp .= '<br>' . self::checkbox( 'sftp][forget_fingerprint', false, __( 'Forget it (only if the server was reinstalled)', 'resenje-site-mover' ) );
								}
								self::row( $c, __( 'Server host key', 'resenje-site-mover' ), $fp );

								// S3.
								$c = 'rsmover-dest rsmover-dest-s3';
								self::row( $c, __( 'Endpoint', 'resenje-site-mover' ), self::capture_field( 's3][endpoint', $s['s3']['endpoint'], 'text', 'https://s3.eu-central-003.backblazeb2.com' ) . '<p class="description">' . esc_html__( 'Leave empty for Amazon S3. For other providers enter their S3 endpoint.', 'resenje-site-mover' ) . '</p>' );
								self::row( $c, __( 'Region', 'resenje-site-mover' ), self::capture_field( 's3][region', $s['s3']['region'], 'text', 'eu-central-1' ) . '<p class="description">' . esc_html__( 'Cloudflare R2: auto. MinIO: us-east-1 unless configured otherwise.', 'resenje-site-mover' ) . '</p>' );
								self::row( $c, __( 'Bucket', 'resenje-site-mover' ), self::capture_field( 's3][bucket', $s['s3']['bucket'] ) );
								self::row( $c, __( 'Access key', 'resenje-site-mover' ), self::capture_field( 's3][access_key', $s['s3']['access_key'], 'text', '', 'off' ) );
								self::row( $c, __( 'Secret key', 'resenje-site-mover' ), self::capture_field( 's3][secret_key', '', 'password', self::secret_hint( $s['s3']['secret_key'] ), 'new-password' ) );
								self::row( $c, __( 'Folder (prefix)', 'resenje-site-mover' ), self::capture_field( 's3][prefix', $s['s3']['prefix'], 'text', 'my-site' ) );
								self::row( $c, __( 'Addressing', 'resenje-site-mover' ), self::checkbox( 's3][path_style', $s['s3']['path_style'], __( 'Path-style URLs (needed for MinIO; only used with a custom endpoint)', 'resenje-site-mover' ) ) );

								// FTP.
								$c = 'rsmover-dest rsmover-dest-ftp';
								self::row( $c, __( 'Server', 'resenje-site-mover' ), self::capture_field( 'ftp][host', $s['ftp']['host'], 'text', 'ftp.example.com' ) . self::port( 'ftp', $s['ftp']['port'] ) );
								self::row( $c, __( 'User name', 'resenje-site-mover' ), self::capture_field( 'ftp][user', $s['ftp']['user'], 'text', '', 'off' ) );
								self::row( $c, __( 'Password', 'resenje-site-mover' ), self::capture_field( 'ftp][password', '', 'password', self::secret_hint( $s['ftp']['password'] ), 'new-password' ) );
								self::row( $c, __( 'Folder', 'resenje-site-mover' ), self::capture_field( 'ftp][path', $s['ftp']['path'], 'text', 'backups/my-site' ) );
								self::row(
									$c,
									__( 'Options', 'resenje-site-mover' ),
									self::checkbox( 'ftp][tls', $s['ftp']['tls'], __( 'Use TLS (FTPS) – strongly recommended, plain FTP sends the password unencrypted', 'resenje-site-mover' ) ) . '<br>'
									. self::checkbox( 'ftp][verify', $s['ftp']['verify'], __( 'Verify the TLS certificate (turn on if the server has a valid certificate for this address)', 'resenje-site-mover' ) ) . '<br>'
									. self::checkbox( 'ftp][passive', $s['ftp']['passive'], __( 'Passive mode', 'resenje-site-mover' ) )
								);
								?>
							</table>
							<p class="description"><?php esc_html_e( 'Passwords and keys are stored encrypted with the security keys of this site (wp-config.php).', 'resenje-site-mover' ); ?></p>
						</div>

						<div class="rsmover-card">
							<h2><?php esc_html_e( 'Notifications', 'resenje-site-mover' ); ?></h2>
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="rsmover-notify"><?php esc_html_e( 'Send an e-mail', 'resenje-site-mover' ); ?></label></th>
									<td>
										<select id="rsmover-notify" name="<?php echo esc_attr( $opt ); ?>[notify]">
											<option value="failure" <?php selected( $s['notify'], 'failure' ); ?>><?php esc_html_e( 'Only when a backup fails', 'resenje-site-mover' ); ?></option>
											<option value="always" <?php selected( $s['notify'], 'always' ); ?>><?php esc_html_e( 'After every backup', 'resenje-site-mover' ); ?></option>
											<option value="never" <?php selected( $s['notify'], 'never' ); ?>><?php esc_html_e( 'Never', 'resenje-site-mover' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="rsmover-email"><?php esc_html_e( 'E-mail address', 'resenje-site-mover' ); ?></label></th>
									<td><input type="email" id="rsmover-email" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[email]" value="<?php echo esc_attr( $s['email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td>
								</tr>
							</table>
						</div>
						<?php submit_button( __( 'Save settings', 'resenje-site-mover' ) ); ?>
					</form>

					<div class="rsmover-card">
						<h2><?php esc_html_e( 'Recent scheduled backups', 'resenje-site-mover' ); ?></h2>
						<?php if ( empty( $log ) || ! is_array( $log ) ) : ?>
							<p><?php esc_html_e( 'Nothing yet.', 'resenje-site-mover' ); ?></p>
						<?php else : ?>
							<table class="widefat striped">
								<thead><tr><th><?php esc_html_e( 'Date', 'resenje-site-mover' ); ?></th><th><?php esc_html_e( 'Result', 'resenje-site-mover' ); ?></th><th><?php esc_html_e( 'Details', 'resenje-site-mover' ); ?></th></tr></thead>
								<tbody>
								<?php foreach ( $log as $entry ) : ?>
									<?php
									$labels = array(
										'ok'      => __( 'OK', 'resenje-site-mover' ),
										'error'   => __( 'Failed', 'resenje-site-mover' ),
										'skipped' => __( 'Skipped', 'resenje-site-mover' ),
									);
									$status = isset( $labels[ $entry['status'] ] ) ? $entry['status'] : 'skipped';
									?>
									<tr>
										<td><?php echo esc_html( wp_date( $date_format, (int) $entry['time'] ) ); ?></td>
										<td class="rsmover-log-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $labels[ $status ] ); ?></td>
										<td><?php echo esc_html( $entry['message'] ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</div>
				<div class="rsmover-side">
					<?php self::donate_box( __( 'Daily backups, free forever', 'resenje-site-mover' ), __( 'Other plugins charge a yearly fee for scheduled and remote backups. This one does not. If it protects your site, please support it with a coffee.', 'resenje-site-mover' ) ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Tests the saved remote storage settings.
	 */
	public static function ajax_test_remote() {
		check_ajax_referer( self::NONCE, 'nonce' );
		self::ajax_guard();
		try {
			$remote = RSMover_Remote::from_settings( RSMover_Schedule::get() );
			if ( ! $remote ) {
				wp_send_json_error( array( 'message' => __( 'No remote storage is selected.', 'resenje-site-mover' ) ) );
			}
			$message = $remote->test();
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		wp_send_json_success( array( 'message' => $message ) );
	}

	/**
	 * Starts the scheduled backup now (it runs in the background).
	 */
	public static function ajax_run_now() {
		check_ajax_referer( self::NONCE, 'nonce' );
		self::ajax_guard();
		try {
			$job = RSMover_Schedule::start();
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		RSMover_Schedule::kick( $job );
		wp_send_json_success();
	}
}
