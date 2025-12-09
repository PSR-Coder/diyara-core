<?php
/**
 * ProcessedPost logs for Diyara campaigns.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logs
 *
 * Uses a private custom post type "diyara_processed" to store
 * processed-post records (one per generated article or failed run).
 */
class Logs {

	/**
	 * Singleton.
	 *
	 * @var Logs|null
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Logs
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_cpt' ) );
	}

	/**
	 * Register custom post type for logs.
	 *
	 * @return void
	 */
	public function register_cpt() {
		$labels = array(
			'name'          => __( 'Diyara Logs', 'diyara-core' ),
			'singular_name' => __( 'Diyara Log', 'diyara-core' ),
		);

		$args = array(
			'labels'      => $labels,
			'public'      => false,
			'show_ui'     => false, // UI will be provided by Admin_Page_Logs.
			'supports'    => array( 'title' ),
			'has_archive' => false,
		);

		register_post_type( 'diyara_processed', $args );
	}

	/**
	 * Add a processed-post log entry.
	 *
	 * @param array $data {
	 *   @type int      $campaign_id
	 *   @type int      $wordpress_post_id
	 *   @type string   $title
	 *   @type string   $source_url
	 *   @type string   $target_url
	 *   @type string   $status        published|draft|failed|skipped|...
	 *   @type int      $tokens_used
	 *   @type string   $created_at    MySQL datetime (optional; will fallback to now).
	 *   @type string[] $messages      Optional log lines.
	 * }
	 * @return int|\WP_Error Log post ID or error.
	 */
	public function add_processed_post( array $data ) {
		$title = isset( $data['title'] ) ? $data['title'] : __( 'Processed Post', 'diyara-core' );

		$log_id = wp_insert_post(
			array(
				'post_type'   => 'diyara_processed',
				'post_status' => 'private',
				'post_title'  => wp_trim_words( $title, 12, '…' ),
			),
			true
		);

		if ( is_wp_error( $log_id ) ) {
			return $log_id;
		}

		$map = array(
			'campaign_id',
			'wordpress_post_id',
			'source_url',
			'target_url',
			'status',
			'tokens_used',
			'created_at',
		);

		foreach ( $map as $key ) {
			if ( isset( $data[ $key ] ) && '' !== $data[ $key ] ) {
				update_post_meta( $log_id, '_diyara_log_' . $key, $data[ $key ] );
			}
		}

		if ( empty( $data['created_at'] ) ) {
			update_post_meta( $log_id, '_diyara_log_created_at', current_time( 'mysql' ) );
		}

		if ( ! empty( $data['messages'] ) && is_array( $data['messages'] ) ) {
			update_post_meta( $log_id, '_diyara_log_messages', $data['messages'] );
		}

		return $log_id;
	}

	/**
	 * Check if a given source URL has already been processed for a campaign.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $source_url  Source URL.
	 * @return bool
	 */
	public function has_url_been_processed( $campaign_id, $source_url ) {
		$q = new \WP_Query(
			array(
				'post_type'      => 'diyara_processed',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_diyara_log_campaign_id',
						'value' => $campaign_id,
					),
					array(
						'key'   => '_diyara_log_source_url',
						'value' => $source_url,
					),
				),
			)
		);

		return $q->have_posts();
	}

	/**
	 * Get count of processed posts for a campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return int
	 */
	public function get_processed_count_for_campaign( $campaign_id ) {
		$q = new \WP_Query(
			array(
				'post_type'      => 'diyara_processed',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_diyara_log_campaign_id',
						'value' => $campaign_id,
					),
				),
			)
		);

		return (int) $q->found_posts;
	}

	/* -------------------------------------------------------------------------
	 * Query helpers for Logs UI
	 * ---------------------------------------------------------------------- */

	/**
	 * Get logs with filters and pagination.
	 *
	 * @param array $filters {
	 *   @type int    $campaign_id Filter by campaign.
	 *   @type string $status      Filter by status (published|draft|failed|skipped).
	 *   @type string $from        MySQL datetime string (created_at >=).
	 *   @type string $to          MySQL datetime string (created_at <=).
	 * }
	 * @param int   $per_page Number of items per page.
	 * @param int   $paged    Page number (1-based).
	 * @return array {
	 *   @type \WP_Post[] $posts
	 *   @type int        $total
	 *   @type int        $max_num_pages
	 * }
	 */
	public function get_logs( $filters = array(), $per_page = 20, $paged = 1 ) {
		$meta_query = array();

		if ( ! empty( $filters['campaign_id'] ) ) {
			$meta_query[] = array(
				'key'   => '_diyara_log_campaign_id',
				'value' => (int) $filters['campaign_id'],
			);
		}

		if ( ! empty( $filters['status'] ) && 'any' !== $filters['status'] ) {
			$meta_query[] = array(
				'key'   => '_diyara_log_status',
				'value' => sanitize_text_field( $filters['status'] ),
			);
		}

		if ( ! empty( $filters['from'] ) || ! empty( $filters['to'] ) ) {
			$range = array( 'key' => '_diyara_log_created_at' );
			if ( ! empty( $filters['from'] ) ) {
				$range['value'][] = $filters['from'];
				$range['compare'] = '>=';
				$range['type']    = 'DATETIME';
				$meta_query[]     = $range;
			}
			// For simplicity, we only handle "from" for now; "to" can be added similarly if needed.
		}

		$args = array(
			'post_type'      => 'diyara_processed',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => max( 1, (int) $paged ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query;
		}

		$q = new \WP_Query( $args );

		return array(
			'posts'        => $q->posts,
			'total'        => (int) $q->found_posts,
			'max_num_pages'=> (int) $q->max_num_pages,
		);
	}

	/**
	 * Get basic stats for a given campaign.
	 *
	 * @param int $campaign_id Campaign ID.
	 * @return array {
	 *   @type int    $total
	 *   @type int    $published
	 *   @type int    $failed
	 *   @type string $last_status
	 *   @type string $last_run_at
	 * }
	 */
	public function get_campaign_stats( $campaign_id ) {
		$campaign_id = (int) $campaign_id;

		// Total.
		$total = $this->get_processed_count_for_campaign( $campaign_id );

		// Published.
		$published_q = new \WP_Query(
			array(
				'post_type'      => 'diyara_processed',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_diyara_log_campaign_id',
						'value' => $campaign_id,
					),
					array(
						'key'   => '_diyara_log_status',
						'value' => 'published',
					),
				),
			)
		);
		$published = (int) $published_q->found_posts;

		// Failed.
		$failed_q = new \WP_Query(
			array(
				'post_type'      => 'diyara_processed',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_diyara_log_campaign_id',
						'value' => $campaign_id,
					),
					array(
						'key'   => '_diyara_log_status',
						'value' => 'failed',
					),
				),
			)
		);
		$failed = (int) $failed_q->found_posts;

		// Last log.
		$last_status = '';
		$last_run_at = '';

		$last_q = new \WP_Query(
			array(
				'post_type'      => 'diyara_processed',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_diyara_log_campaign_id',
						'value' => $campaign_id,
					),
				),
			)
		);

		if ( $last_q->have_posts() ) {
			$log_id      = $last_q->posts[0]->ID;
			$last_status = get_post_meta( $log_id, '_diyara_log_status', true );
			$last_run_at = get_post_meta( $log_id, '_diyara_log_created_at', true );
		}

		return array(
			'total'      => $total,
			'published'  => $published,
			'failed'     => $failed,
			'last_status'=> $last_status,
			'last_run_at'=> $last_run_at,
		);
	}
}