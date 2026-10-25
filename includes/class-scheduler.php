<?php
/**
 * Automation, scheduled audits, and automated retention policies.
 *
 * Integrates with Action Scheduler and WP-Cron to perform periodic
 * background scans, threshold email notifications, and automated
 * orphan retention purging.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Handles background audit jobs, alert notifications, and retention rules.
 */
class Scheduler {

	/**
	 * Action hook for periodic background audit.
	 */
	public const AUDIT_HOOK = 'idta_r2_scheduled_audit';

	/**
	 * Action hook for automated retention cleanup.
	 */
	public const RETENTION_HOOK = 'idta_r2_scheduled_retention';

	/**
	 * Action Scheduler group name.
	 */
	public const ACTION_GROUP = 'idta-r2-optimizer';

	/**
	 * Initialize scheduled hooks and listeners.
	 */
	public function init(): void {
		add_action( self::AUDIT_HOOK, array( $this, 'run_audit' ) );
		add_action( self::RETENTION_HOOK, array( $this, 'run_auto_retention' ) );

		// Check and synchronize schedule when settings change.
		add_action( 'update_option_' . Plugin::SETTINGS_OPTION, array( $this, 'sync_schedule' ) );
	}

	/**
	 * Synchronize scheduled background tasks based on current plugin settings.
	 */
	public function sync_schedule(): void {
		$frequency = (string) plugin()->get_setting( 'schedule_frequency', 'daily' );

		if ( 'none' === $frequency ) {
			$this->clear_schedules();

			return;
		}

		$interval = ( 'weekly' === $frequency ) ? WEEK_IN_SECONDS : DAY_IN_SECONDS;

		// Use WooCommerce Action Scheduler if available.
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_next_scheduled_action( self::AUDIT_HOOK ) ) {
				as_schedule_recurring_action(
					time() + 300,
					$interval,
					self::AUDIT_HOOK,
					array(),
					self::ACTION_GROUP
				);
			}

			if ( ! as_next_scheduled_action( self::RETENTION_HOOK ) ) {
				as_schedule_recurring_action(
					time() + 600,
					DAY_IN_SECONDS,
					self::RETENTION_HOOK,
					array(),
					self::ACTION_GROUP
				);
			}
		} else {
			// Fallback to WP-Cron.
			if ( ! wp_next_scheduled( self::AUDIT_HOOK ) ) {
				$recurrence = ( 'weekly' === $frequency ) ? 'weekly' : 'daily';
				wp_schedule_event( time() + 300, $recurrence, self::AUDIT_HOOK );
			}

			if ( ! wp_next_scheduled( self::RETENTION_HOOK ) ) {
				wp_schedule_event( time() + 600, 'daily', self::RETENTION_HOOK );
			}
		}
	}

	/**
	 * Clear all scheduled background jobs (e.g. on plugin deactivation).
	 */
	public function clear_schedules(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::AUDIT_HOOK, array(), self::ACTION_GROUP );
			as_unschedule_all_actions( self::RETENTION_HOOK, array(), self::ACTION_GROUP );
		}

		$audit_timestamp = wp_next_scheduled( self::AUDIT_HOOK );
		if ( $audit_timestamp ) {
			wp_unschedule_event( $audit_timestamp, self::AUDIT_HOOK );
		}

		$retention_timestamp = wp_next_scheduled( self::RETENTION_HOOK );
		if ( $retention_timestamp ) {
			wp_unschedule_event( $retention_timestamp, self::RETENTION_HOOK );
		}
	}

	/**
	 * Execute background audit scan and evaluate storage thresholds.
	 */
	public function run_audit(): void {
		$client = plugin()->r2();
		$conn   = $client->test_connection();
		if ( ! $conn['success'] ) {
			return;
		}

		// Determine target bucket.
		$bucket = (string) plugin()->get_setting( 'default_bucket', '' );
		if ( '' === $bucket ) {
			$buckets = $client->list_buckets();
			if ( ! empty( $buckets ) ) {
				$bucket = $buckets[0]->name;
			}
		}

		if ( '' === $bucket ) {
			return;
		}

		// Perform full scan across current and past years.
		$analyzer = plugin()->connection_analyzer();
		$current_year = (int) gmdate( 'Y' );
		$years = array( (string) $current_year, (string) ( $current_year - 1 ) );

		$all_folders = array();
		foreach ( $years as $yr ) {
			$folders = $analyzer->scan_and_analyze( $bucket, $yr . '/' );
			$all_folders = array_merge( $all_folders, $folders );
		}

		// Calculate metrics and save to transient for instant dashboard loading.
		$metrics = $analyzer->calculate_metrics( $all_folders );
		$state   = array(
			'bucket'      => $bucket,
			'prefix'      => '',
			'is_complete' => true,
			'metrics'     => $metrics,
			'folders'     => array(),
		);

		foreach ( $all_folders as $folder ) {
			$state['folders'][ $folder->prefix ] = $folder->to_array();
		}

		set_transient( Connection_Analyzer::PROGRESS_TRANSIENT, $state, 86400 );

		// Check alert threshold.
		$threshold_gb = (float) plugin()->get_setting( 'alert_threshold_gb', 5.0 );
		$orphaned_bytes = (int) ( $metrics['orphaned_size'] ?? 0 );
		$orphaned_gb    = $orphaned_bytes / ( 1024 * 1024 * 1024 );

		if ( $threshold_gb > 0 && $orphaned_gb >= $threshold_gb ) {
			$this->send_alert_email(
				$orphaned_gb,
				(float) ( $metrics['estimated_monthly_savings'] ?? 0 ),
				(int) ( $metrics['orphaned_count'] ?? 0 ),
				$bucket
			);
		}
	}

	/**
	 * Execute automated retention policy purge.
	 */
	public function run_auto_retention(): void {
		$purge_days = (int) plugin()->get_setting( 'auto_purge_days', 0 );
		if ( $purge_days <= 0 ) {
			return;
		}

		$state = get_transient( Connection_Analyzer::PROGRESS_TRANSIENT );
		if ( ! is_array( $state ) || empty( $state['folders'] ) ) {
			return;
		}

		$bucket = (string) ( $state['bucket'] ?? '' );
		if ( '' === $bucket ) {
			return;
		}

		$cutoff_time = time() - ( $purge_days * DAY_IN_SECONDS );
		$orphans_to_purge = array();

		foreach ( $state['folders'] as $f_data ) {
			if ( 'orphaned' !== ( $f_data['status'] ?? '' ) ) {
				continue;
			}

			$mod_time = strtotime( (string) ( $f_data['last_modified'] ?? '' ) );
			if ( false !== $mod_time && $mod_time < $cutoff_time ) {
				$orphans_to_purge[] = (string) $f_data['prefix'];
			}
		}

		if ( ! empty( $orphans_to_purge ) ) {
			plugin()->folder_manager()->bulk_delete_orphaned(
				$bucket,
				$orphans_to_purge,
				false
			);
		}
	}

	/**
	 * Send alert email notification to the site administrator.
	 *
	 * @param float  $orphaned_gb    Total orphaned volume in Gigabytes.
	 * @param float  $savings        Estimated monthly dollar savings upon cleanup.
	 * @param int    $orphaned_count Number of orphaned folders detected.
	 * @param string $bucket         R2 bucket name.
	 */
	protected function send_alert_email(
		float $orphaned_gb,
		float $savings,
		int $orphaned_count,
		string $bucket
	): void {
		$to = (string) get_option( 'admin_email' );
		if ( '' === $to ) {
			return;
		}

		$site_name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$subject   = sprintf(
			/* translators: 1: Site name, 2: Orphaned storage in GB */
			__( '[%1$s] Cloudflare R2 Storage Alert: %2$s GB Orphaned Data Found', 'idta-r2-optimizer' ),
			$site_name,
			number_format( $orphaned_gb, 2 )
		);

		$dashboard_url = admin_url( 'admin.php?page=idta-r2-optimizer' );

		$message = sprintf(
			/* translators: 1: Site name, 2: Orphaned GB, 3: Folder count, 4: Bucket name, 5: Monthly savings, 6: Dashboard URL */
			__(
				"Hello Administrator,\n\nThe IDTA R2 Optimizer scheduled audit detected that orphaned file storage has exceeded your alert threshold on %1\$s.\n\nSummary of Findings:\n--------------------\n• Target Bucket: %4\$s\n• Orphaned Volume: %2\$s GB\n• Orphaned Folders: %3\$d folders\n• Estimated Monthly Savings: \$%5\$.2f / month\n\nThese folders represent abandoned checkout uploads and unattached customer verification documents taking up storage on Cloudflare R2.\n\nYou can review, simulate, and clean these orphaned files directly from your WordPress dashboard:\n%6\$s\n\nBest regards,\nIDTA R2 Optimizer",
				'idta-r2-optimizer'
			),
			$site_name,
			number_format( $orphaned_gb, 2 ),
			$orphaned_count,
			$bucket,
			$savings,
			$dashboard_url
		);

		wp_mail( $to, $subject, $message );
	}
}

