<?php
/**
 * WP-Cron scheduler for Diyara AI campaigns.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cron
 */
class Cron {

	/**
	 * Singleton.
	 *
	 * @var Cron|null
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Cron
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init hooks (called from Plugin::init_ai()).
	 *
	 * @return void
	 */
	public function init() {
		// Define custom intervals.
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

		// Main cron runner.
		add_action( 'diyara_core_cron', array( $this, 'run' ) );
	}

	/**
	 * Register a custom cron interval (every 5 minutes).
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['diyara_core_five_minutes'] ) ) {
			$schedules['diyara_core_five_minutes'] = array(
				'interval' => 5 * 60,
				'display'  => __( 'Every 5 minutes (Diyara)', 'diyara-core' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule the recurring event (called on plugin activation).
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( 'diyara_core_cron' ) ) {
			wp_schedule_event( time() + 60, 'diyara_core_five_minutes', 'diyara_core_cron' );
		}
	}

	/**
	 * Clear the scheduled event (called on plugin deactivation).
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'diyara_core_cron' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'diyara_core_cron' );
		}
	}

	/**
	 * Cron runner: decides which campaigns to process and runs batches.
	 *
	 * @return void
	 */
	public function run() {
		// In case someone disables WP-Cron and triggers manually, still run.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			// No-op; but you can still call diyara_core_cron via CLI or external cron.
		}

		$now_ts       = current_time( 'timestamp' ); // site local time
		$current_hour = (int) current_time( 'G' );   // 0..23
		$weekday      = (int) current_time( 'w' );   // 0=Sun..6=Sat

		$engine = Engine::instance();
		$logs   = Logs::instance();

		// Fetch all campaigns; later you can optimize by only querying active ones.
		$campaigns = get_posts(
			array(
				'post_type'      => 'diyara_campaign',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		if ( empty( $campaigns ) ) {
			return;
		}

		// Limit how many campaigns we process per cron run to avoid overload.
		$max_campaigns_per_run = 3;
		$processed_campaigns   = 0;

		foreach ( $campaigns as $campaign ) {
			if ( $processed_campaigns >= $max_campaigns_per_run ) {
				break;
			}

			$campaign_id = $campaign->ID;

			// Status (active/paused).
			$status = get_post_meta( $campaign_id, '_diyara_campaign_status', true );
			$status = $status ? $status : 'active';
			if ( 'active' !== $status ) {
				continue;
			}

			// Schedule days.
			$schedule_days = get_post_meta( $campaign_id, '_diyara_campaign_schedule_days', true );
			if ( ! is_array( $schedule_days ) || empty( $schedule_days ) ) {
				// Default: every day.
				$schedule_days = array( 0, 1, 2, 3, 4, 5, 6 );
			}
			if ( ! in_array( $weekday, $schedule_days, true ) ) {
				continue;
			}

			// Hours window.
			$start_hour = (int) get_post_meta( $campaign_id, '_diyara_campaign_schedule_start_hour', true );
			$end_hour   = (int) get_post_meta( $campaign_id, '_diyara_campaign_schedule_end_hour', true );
			if ( $end_hour <= 0 ) {
				$end_hour = 24; // default: all day
			}
			if ( $current_hour < $start_hour || $current_hour >= $end_hour ) {
				continue;
			}

			// Min interval.
			$min_interval_minutes = (int) get_post_meta( $campaign_id, '_diyara_campaign_min_interval_minutes', true );
			if ( $min_interval_minutes <= 0 ) {
				$min_interval_minutes = 60;
			}
			$last_run_at = get_post_meta( $campaign_id, '_diyara_campaign_last_run_at', true );
			$last_run_ts = $last_run_at ? strtotime( $last_run_at ) : 0;

			if ( $last_run_ts && ( $last_run_ts + $min_interval_minutes * 60 ) > $now_ts ) {
				continue;
			}

			// Max posts limit.
			$max_posts_limit = (int) get_post_meta( $campaign_id, '_diyara_campaign_max_posts_limit', true );
			if ( $max_posts_limit > 0 ) {
				$total = $logs->get_processed_count_for_campaign( $campaign_id );
				if ( $total >= $max_posts_limit ) {
					continue;
				}
			}

			// Batch size.
			$batch_size = (int) get_post_meta( $campaign_id, '_diyara_campaign_batch_size', true );
			if ( $batch_size <= 0 ) {
				$batch_size = 1;
			}

			// Run batch for this campaign.
			$generated = $engine->run_batch_for_campaign( $campaign_id, $batch_size, 'cron' );
			if ( $generated > 0 ) {
				$processed_campaigns++;
				update_post_meta( $campaign_id, '_diyara_campaign_last_run_at', current_time( 'mysql' ) );
			}
		}
	}
}