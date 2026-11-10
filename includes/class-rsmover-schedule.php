<?php
/**
 * Scheduled backups: settings, WP-Cron events, background runner, retention, log and e-mails.
 *
 * @package ResenjeSiteMover
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduled backups.
 */
class RSMover_Schedule {

	const OPTION    = 'rsmover_schedule';
	const LOG       = 'rsmover_schedule_log';
	const HOOK_RUN  = 'rsmover_scheduled_backup';
	const HOOK_STEP = 'rsmover_cron_step';
	const KIND      = 'auto';
	const LOG_SIZE  = 30;

	/**
	 * True while an internal update bypasses the form sanitizer.
	 *
	 * @var bool
	 */
	private static $raw_save = false;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( self::HOOK_RUN, array( __CLASS__, 'cron_run' ) );
		add_action( self::HOOK_STEP, array( __CLASS__, 'run_steps' ) );
		add_action( 'wp_ajax_nopriv_rsmover_continue', array( __CLASS__, 'ajax_continue' ) );
		add_action( 'wp_ajax_rsmover_continue', array( __CLASS__, 'ajax_continue' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'reschedule' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'reschedule' ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'frequency'     => 'off',
			'time'          => '03:00',
			'weekday'       => 1,
			'monthday'      => 1,
			'exclude_media' => false,
			'keep_local'    => 3,
			'destination'   => 'none',
			'keep_remote'   => 7,
			'notify'        => 'failure',
			'email'         => '',
			'sftp'          => array(
				'host'        => '',
				'port'        => 22,
				'user'        => '',
				'auth'        => 'password',
				'password'    => '',
				'private_key' => '',
				'passphrase'  => '',
				'path'        => '',
				'fingerprint' => '',
			),
			's3'            => array(
				'endpoint'   => '',
				'region'     => '',
				'bucket'     => '',
				'access_key' => '',
				'secret_key' => '',
				'prefix'     => '',
				'path_style' => true,
			),
			'ftp'           => array(
				'host'     => '',
				'port'     => 21,
				'user'     => '',
				'password' => '',
				'path'     => '',
				'tls'      => true,
				'verify'   => false,
				'passive'  => true,
			),
		);
	}

	/**
	 * Fields stored encrypted.
	 *
	 * @return string[]
	 */
	public static function secret_fields() {
		return array( 'password', 'private_key', 'passphrase', 'secret_key' );
	}

	/**
	 * Current settings merged with the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = self::defaults();
		$settings = array_merge( $defaults, $saved );
		foreach ( array( 'sftp', 's3', 'ftp' ) as $type ) {
			$settings[ $type ] = array_merge( $defaults[ $type ], isset( $saved[ $type ] ) && is_array( $saved[ $type ] ) ? $saved[ $type ] : array() );
		}
		return $settings;
	}

	/**
	 * Sanitizes the settings form. Empty secret fields keep the saved value.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		if ( self::$raw_save ) {
			return $input;
		}
		$input = is_array( $input ) ? $input : array();
		$old   = self::get();
		$out   = self::defaults();

		$out['frequency']     = isset( $input['frequency'] ) && in_array( $input['frequency'], array( 'off', 'hourly', 'daily', 'weekly', 'monthly' ), true ) ? $input['frequency'] : 'off';
		$out['time']          = isset( $input['time'] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $input['time'] ) ? (string) $input['time'] : '03:00';
		$out['weekday']       = isset( $input['weekday'] ) ? min( 7, max( 1, absint( $input['weekday'] ) ) ) : 1;
		$out['monthday']      = isset( $input['monthday'] ) ? min( 28, max( 1, absint( $input['monthday'] ) ) ) : 1;
		$out['exclude_media'] = ! empty( $input['exclude_media'] );
		$out['keep_local']    = isset( $input['keep_local'] ) ? min( 100, absint( $input['keep_local'] ) ) : 3;
		$out['destination']   = isset( $input['destination'] ) && array_key_exists( $input['destination'], RSMover_Remote::types() ) ? $input['destination'] : 'none';
		$out['keep_remote']   = isset( $input['keep_remote'] ) ? min( 1000, max( 1, absint( $input['keep_remote'] ) ) ) : 7;
		$out['notify']        = isset( $input['notify'] ) && in_array( $input['notify'], array( 'failure', 'always', 'never' ), true ) ? $input['notify'] : 'failure';
		$out['email']         = isset( $input['email'] ) ? sanitize_email( (string) $input['email'] ) : '';
		if ( 'none' === $out['destination'] ) {
			$out['keep_local'] = max( 1, $out['keep_local'] );
		}

		foreach ( array( 'sftp', 's3', 'ftp' ) as $type ) {
			$in = isset( $input[ $type ] ) && is_array( $input[ $type ] ) ? $input[ $type ] : array();
			foreach ( $out[ $type ] as $field => $default ) {
				if ( 'fingerprint' === $field ) {
					continue;
				}
				if ( in_array( $field, self::secret_fields(), true ) ) {
					$value = isset( $in[ $field ] ) ? (string) $in[ $field ] : '';
					if ( 'private_key' !== $field ) {
						$value = trim( $value );
					}
					$out[ $type ][ $field ] = '' === $value ? $old[ $type ][ $field ] : RSMover_Crypto::encrypt( $value );
				} elseif ( is_bool( $default ) ) {
					$out[ $type ][ $field ] = ! empty( $in[ $field ] );
				} elseif ( 'port' === $field ) {
					$out[ $type ][ $field ] = isset( $in[ $field ] ) ? min( 65535, max( 1, absint( $in[ $field ] ) ) ) : $default;
				} else {
					$out[ $type ][ $field ] = isset( $in[ $field ] ) ? sanitize_text_field( (string) $in[ $field ] ) : '';
				}
			}
		}
		if ( isset( $out['sftp']['auth'] ) && ! in_array( $out['sftp']['auth'], array( 'password', 'key' ), true ) ) {
			$out['sftp']['auth'] = 'password';
		}
		// Keep the remembered SFTP host key unless the server changed or the user asked to forget it.
		$same_server                = $old['sftp']['host'] === $out['sftp']['host'] && (int) $old['sftp']['port'] === (int) $out['sftp']['port'];
		$out['sftp']['fingerprint'] = ( $same_server && empty( $input['sftp']['forget_fingerprint'] ) ) ? $old['sftp']['fingerprint'] : '';
		return $out;
	}

	/**
	 * Remembers the SFTP host key fingerprint seen on the first connection.
	 *
	 * @param string $fingerprint Fingerprint.
	 */
	public static function remember_fingerprint( $fingerprint ) {
		$raw = get_option( self::OPTION, array() );
		if ( is_array( $raw ) && isset( $raw['sftp'] ) && is_array( $raw['sftp'] ) ) {
			$raw['sftp']['fingerprint'] = (string) $fingerprint;
			// Saved without the form sanitizer so the encrypted secrets stay untouched.
			self::$raw_save = true;
			update_option( self::OPTION, $raw, false );
			self::$raw_save = false;
		}
	}

	/**
	 * Schedule settings and log of this site, read before an import replaces the database.
	 *
	 * @return array
	 */
	public static function snapshot() {
		return array(
			self::OPTION => get_option( self::OPTION, null ),
			self::LOG    => get_option( self::LOG, null ),
		);
	}

	/**
	 * After an import: puts back this site's own schedule (the imported one belongs to the source
	 * site, and its secrets cannot be decrypted here) and re-creates the cron events.
	 *
	 * @param array $snapshot Result of snapshot().
	 */
	public static function restore( array $snapshot ) {
		self::$raw_save = true;
		foreach ( $snapshot as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value, false );
			}
		}
		self::$raw_save = false;
		wp_clear_scheduled_hook( self::HOOK_STEP );
		self::reschedule();
	}

	/**
	 * File name prefix of this site's scheduled backups.
	 *
	 * @return string
	 */
	public static function prefix() {
		$name = RSMover_Storage::new_archive_name( self::KIND );
		return substr( $name, 0, strlen( $name ) - strlen( '-YYYYMMDD-HHMMSS-xxxxxxxx.rsm' ) ) . '-';
	}

	/**
	 * Next run time for the settings.
	 *
	 * @param array    $s   Settings.
	 * @param int|null $now Unix time.
	 * @return int|null Unix time, or null when scheduling is off.
	 */
	public static function next_run( array $s, $now = null ) {
		if ( empty( $s['frequency'] ) || 'off' === $s['frequency'] ) {
			return null;
		}
		$now                   = null === $now ? time() : (int) $now;
		$base                  = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );
		list( $hour, $minute ) = array_map( 'intval', explode( ':', $s['time'] ) );
		switch ( $s['frequency'] ) {
			case 'hourly':
				$next = $base->setTime( (int) $base->format( 'G' ), $minute, 0 );
				if ( $next->getTimestamp() <= $now ) {
					$next = $next->modify( '+1 hour' );
				}
				break;
			case 'weekly':
				$next = $base->setTime( $hour, $minute, 0 );
				$diff = ( (int) $s['weekday'] - (int) $next->format( 'N' ) + 7 ) % 7;
				$next = $next->modify( '+' . $diff . ' days' );
				if ( $next->getTimestamp() <= $now ) {
					$next = $next->modify( '+7 days' );
				}
				break;
			case 'monthly':
				$next = $base->setDate( (int) $base->format( 'Y' ), (int) $base->format( 'n' ), (int) $s['monthday'] )->setTime( $hour, $minute, 0 );
				if ( $next->getTimestamp() <= $now ) {
					$month = $base->modify( 'first day of next month' );
					$next  = $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), (int) $s['monthday'] )->setTime( $hour, $minute, 0 );
				}
				break;
			default:
				$next = $base->setTime( $hour, $minute, 0 );
				if ( $next->getTimestamp() <= $now ) {
					$next = $next->modify( '+1 day' );
				}
		}
		return $next->getTimestamp();
	}

	/**
	 * Re-creates the cron event after the settings changed.
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::HOOK_RUN );
		$next = self::next_run( self::get() );
		if ( $next ) {
			wp_schedule_single_event( $next, self::HOOK_RUN );
		}
	}

	/**
	 * Cron: starts the scheduled backup.
	 */
	public static function cron_run() {
		// Plan the next run first, so one failure never stops the schedule.
		self::reschedule();
		try {
			self::start();
		} catch ( Throwable $e ) {
			self::log( 'skipped', $e->getMessage() );
			return;
		}
		self::run_steps();
	}

	/**
	 * Starts a scheduled backup job (also used by "Run now" and WP-CLI).
	 *
	 * @return array Job.
	 * @throws RuntimeException When another job is running.
	 */
	public static function start() {
		$s            = self::get();
		$job          = RSMover_Jobs::start(
			'export',
			array(
				'exclude_media' => ! empty( $s['exclude_media'] ),
				'kind'          => self::KIND,
			)
		);
		$job['auto']  = true;
		$job['token'] = wp_generate_password( 32, false );
		RSMover_Storage::set_job( $job );
		return $job;
	}

	/**
	 * Works on the scheduled job for one time budget, then hands over to the next request.
	 */
	public static function run_steps() {
		$job = RSMover_Storage::get_job();
		if ( ! $job || empty( $job['auto'] ) ) {
			return;
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		$end = microtime( true ) + RSMover_Jobs::budget();
		try {
			do {
				$job = RSMover_Jobs::step( max( 1, $end - microtime( true ) ) );
			} while ( $job && 'done' !== $job['phase'] && ! empty( $job['auto'] ) && microtime( true ) < $end && empty( $job['busy'] ) );
		} catch ( Throwable $e ) {
			// Logged and reported by the job runner.
			return;
		}
		if ( $job && 'done' !== $job['phase'] ) {
			// Fallback in case the loopback request below is blocked by the hosting.
			if ( ! wp_next_scheduled( self::HOOK_STEP ) ) {
				wp_schedule_single_event( time() + 60, self::HOOK_STEP );
			}
			// After an error (retry) or while another request works, wait for the cron fallback instead.
			if ( empty( $job['busy'] ) ) {
				self::kick( $job );
			}
		}
	}

	/**
	 * Starts the next background request right away (non-blocking request to this site).
	 *
	 * @param array $job Job.
	 */
	public static function kick( array $job ) {
		if ( empty( $job['token'] ) ) {
			return;
		}
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				'body'      => array(
					'action' => 'rsmover_continue',
					'token'  => $job['token'],
				),
			)
		);
	}

	/**
	 * Background request: continues the scheduled job. Authorized by the random job token.
	 */
	public static function ajax_continue() {
		// Not a user action: the request comes from this site and carries the secret job token instead of a nonce.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$job   = RSMover_Storage::get_job();
		if ( '' === $token || ! $job || empty( $job['auto'] ) || empty( $job['token'] ) || ! hash_equals( (string) $job['token'], $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		self::run_steps();
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Called by the job runner when the export of a scheduled backup is complete.
	 *
	 * @param array $job Finished export job.
	 * @return array|null Upload job, or null when the scheduled backup is finished.
	 */
	public static function export_finished( array $job ) {
		$s = self::get();
		if ( 'none' !== $s['destination'] ) {
			return array(
				'type'    => 'upload',
				'phase'   => 'upload',
				'auto'    => true,
				'token'   => $job['token'],
				'archive' => $job['archive'],
				'size'    => (int) filesize( RSMover_Storage::archive_path( $job['archive'] ) ),
				'offset'  => 0,
				'state'   => array(),
				'tries'   => 0,
				'started' => $job['started'],
				'files'   => isset( $job['files'] ) ? $job['files'] : 0,
			);
		}
		self::finish( $job, '' );
		return null;
	}

	/**
	 * Finishes a scheduled backup: local retention, log and e-mail.
	 *
	 * @param array  $job    Last job (export or upload).
	 * @param string $remote Note about the remote copy.
	 */
	public static function finish( array $job, $remote ) {
		$s    = self::get();
		$path = RSMover_Storage::archive_path( $job['archive'] );
		$size = $path ? (int) filesize( $path ) : ( isset( $job['size'] ) ? (int) $job['size'] : 0 );
		self::prune_local( (int) $s['keep_local'] );
		/* translators: 1: file name, 2: size, 3: duration in seconds */
		$message = sprintf( __( '%1$s (%2$s) in %3$d s.', 'resenje-site-mover' ), $job['archive'], size_format( $size, 1 ), time() - (int) $job['started'] );
		if ( '' !== $remote ) {
			$message .= ' ' . $remote;
		}
		self::log( 'ok', $message );
		if ( 'always' === $s['notify'] ) {
			self::mail(
				/* translators: %s: site name */
				sprintf( __( '[%s] Backup completed', 'resenje-site-mover' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				__( 'The scheduled backup completed successfully.', 'resenje-site-mover' ) . "\n\n" . $message
			);
		}
	}

	/**
	 * Called by the job runner when a scheduled job failed.
	 *
	 * @param array  $job   Job.
	 * @param string $error Error message.
	 */
	public static function failed( array $job, $error ) {
		$error = html_entity_decode( (string) $error, ENT_QUOTES );
		self::log( 'error', $error );
		if ( 'never' !== self::get()['notify'] ) {
			$body = __( 'The scheduled backup FAILED.', 'resenje-site-mover' ) . "\n\n" . $error . "\n\n";
			if ( 'upload' === $job['type'] ) {
				/* translators: %s: file name */
				$body .= sprintf( __( 'The backup file %s was kept on the server.', 'resenje-site-mover' ), $job['archive'] ) . "\n\n";
			}
			$body .= admin_url( 'admin.php?page=resenje-site-mover-schedule' );
			self::mail(
				/* translators: %s: site name */
				sprintf( __( '[%s] Backup FAILED', 'resenje-site-mover' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
				$body
			);
		}
	}

	/**
	 * Sends a notification e-mail.
	 *
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 */
	private static function mail( $subject, $body ) {
		$s     = self::get();
		$to    = is_email( $s['email'] ) ? $s['email'] : get_option( 'admin_email' );
		$body .= "\n\n-- \n" . __( 'Resenje Site Mover is free. If it protects your site, please consider a small donation:', 'resenje-site-mover' ) . "\n" . 'https://buymeacoffee.com/stefanzeljkic';
		wp_mail( $to, $subject, $body );
	}

	/**
	 * Keeps only the newest scheduled backups on this server (manual backups are never touched).
	 *
	 * @param int $keep Files to keep (0 = delete all scheduled backups after the upload).
	 */
	public static function prune_local( $keep ) {
		self::prune_local_names( $keep );
	}

	/**
	 * Whether a file name is a scheduled backup of this site (prefix + date + random part).
	 *
	 * @param string $name   File name.
	 * @param string $prefix Prefix from prefix().
	 * @return bool
	 */
	public static function is_own( $name, $prefix ) {
		return (bool) preg_match( '/^' . preg_quote( $prefix, '/' ) . '\d{8}-\d{6}-[a-z0-9]{8}\.rsm$/', (string) $name );
	}

	/**
	 * Deletes old local scheduled backups.
	 *
	 * @param int $keep Files to keep.
	 */
	private static function prune_local_names( $keep ) {
		$prefix = self::prefix();
		$ours   = array();
		foreach ( RSMover_Storage::list_archives() as $item ) {
			if ( self::is_own( $item['name'], $prefix ) ) {
				$ours[] = $item['name'];
			}
		}
		rsort( $ours, SORT_STRING );
		foreach ( array_slice( $ours, max( 0, (int) $keep ) ) as $name ) {
			RSMover_Storage::delete_archive( $name );
		}
	}

	/**
	 * Adds a log entry.
	 *
	 * @param string $status  ok, error or skipped.
	 * @param string $message Message.
	 */
	public static function log( $status, $message ) {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'status'  => $status,
				'message' => wp_strip_all_tags( html_entity_decode( (string) $message, ENT_QUOTES ) ),
			)
		);
		update_option( self::LOG, array_slice( $log, 0, self::LOG_SIZE ), false );
	}

	/**
	 * Upload job: one step.
	 *
	 * @param array $job      Job.
	 * @param float $deadline Deadline.
	 * @return array
	 * @throws RuntimeException When the backup file or the settings are missing.
	 * @throws Throwable On repeated upload failure.
	 */
	public static function upload_step( array $job, $deadline ) {
		$s      = self::get();
		$remote = RSMover_Remote::from_settings( $s );
		$path   = RSMover_Storage::archive_path( $job['archive'] );
		if ( ! $remote || ! $path ) {
			throw new RuntimeException( 'The backup file or the remote storage settings are missing.' );
		}
		try {
			if ( 'upload' === $job['phase'] ) {
				$state         = (array) $job['state'];
				$job['offset'] = $remote->upload( $path, $job['archive'], (int) $job['offset'], $state, $deadline );
				$job['state']  = $state;
				$job['tries']  = 0;
				if ( $job['offset'] >= $job['size'] ) {
					$job['phase'] = 'prune';
				}
			} elseif ( 'prune' === $job['phase'] ) {
				$deleted = $remote->prune( self::prefix(), (int) $s['keep_remote'] );
				/* translators: 1: destination, 2: number of old backups deleted there */
				self::finish( $job, sprintf( __( 'Uploaded to %1$s; %2$d old remote backup(s) removed.', 'resenje-site-mover' ), RSMover_Remote::short_label( $s['destination'] ), $deleted ) );
				$job['phase'] = 'done';
			}
		} catch ( Throwable $e ) {
			// Network problems are common: retry a few times before giving up.
			++$job['tries'];
			if ( $job['tries'] >= 4 ) {
				throw $e;
			}
			$job['last_error'] = $e->getMessage();
			$job['busy']       = true;
		}
		return $job;
	}

	/**
	 * Upload job progress.
	 *
	 * @param array $job Job.
	 * @return array percent, message.
	 */
	public static function upload_progress( array $job ) {
		$percent = $job['size'] > 0 ? (int) floor( 100 * $job['offset'] / $job['size'] ) : 100;
		if ( 'upload' === $job['phase'] ) {
			/* translators: 1: uploaded size, 2: total size */
			$message = sprintf( __( 'Uploading to remote storage: %1$s of %2$s', 'resenje-site-mover' ), size_format( $job['offset'], 1 ), size_format( $job['size'], 1 ) );
			if ( ! empty( $job['last_error'] ) ) {
				/* translators: %s: error message */
				$message .= ' – ' . sprintf( __( 'retrying after: %s', 'resenje-site-mover' ), $job['last_error'] );
			}
		} else {
			$message = __( 'Removing old remote backups…', 'resenje-site-mover' );
		}
		return array(
			'percent' => min( 100, $percent ),
			'message' => $message,
		);
	}

	/**
	 * Upload job cleanup (the local backup file is kept).
	 *
	 * @param array $job Job.
	 */
	public static function upload_cleanup( array $job ) {
		if ( 'done' === $job['phase'] ) {
			return;
		}
		try {
			$remote = RSMover_Remote::from_settings( self::get() );
			if ( $remote ) {
				$remote->abort( $job['archive'], (array) $job['state'] );
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}
}
