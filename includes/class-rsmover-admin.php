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
		foreach ( array( 'start', 'step', 'cancel', 'upload', 'delete' ) as $action ) {
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
							<li><?php esc_html_e( 'Open Settings → Permalinks and click Save once.', 'resenje-site-mover' ); ?></li>
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
		} catch ( Exception $e ) {
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
		} catch ( Exception $e ) {
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
}
