<?php
/**
 * Core AI Engine: generates posts for campaigns.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\AI;

use DiyaraCore\AI\Provider\Gemini_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Engine
 */
class Engine {

	/**
	 * Singleton.
	 *
	 * @var Engine|null
	 */
	protected static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return Engine
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
		// Handle manual run from campaign screen.
		add_action( 'admin_post_diyara_run_campaign', array( $this, 'handle_manual_run' ) );
	}

	/* -------------------------------------------------------------------------
	 *  Manual run handler
	 * ---------------------------------------------------------------------- */

	/**
	 * Handle manual "Run now" action from campaign edit screen.
	 *
	 * @return void
	 */
	public function handle_manual_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to run campaigns.', 'diyara-core' ) );
		}

		$campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
		$nonce       = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $campaign_id || ! wp_verify_nonce( $nonce, 'diyara_run_campaign_' . $campaign_id ) ) {
			wp_die( esc_html__( 'Invalid campaign run request.', 'diyara-core' ) );
		}

		$result = $this->generate_post_for_campaign( $campaign_id );

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array(
					'post'          => $campaign_id,
					'action'        => 'edit',
					'diyara_message'=> rawurlencode( $result->get_error_message() ),
					'diyara_status' => 'error',
				),
				admin_url( 'post.php' )
			);
		} else {
			$redirect = add_query_arg(
				array(
					'post'          => $campaign_id,
					'action'        => 'edit',
					'diyara_message'=> rawurlencode( __( 'AI generated a new post successfully.', 'diyara-core' ) ),
					'diyara_status' => 'updated',
				),
				admin_url( 'post.php' )
			);
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/* -------------------------------------------------------------------------
	 *  Main generation method
	 * ---------------------------------------------------------------------- */

	/**
	 * Generate a single WordPress post for a campaign.
	 *
	 * Flow:
	 *  - Read campaign meta.
	 *  - Fetch candidates (RSS or sitemap) via Sources.
	 *  - Filter by start_date, URL keywords, and already-processed URLs.
	 *  - Scrape article content (for all modes).
	 *  - AS_IS: use scraped HTML.
	 *  - AI_REWRITE / AI_URL_DIRECT: call Gemini_Provider::rewrite_content() with
	 *    per-campaign model, tone, audience, brand voice, language, and category context.
	 *  - Insert WP post, apply SEO, and log a ProcessedPost entry.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return int|\WP_Error   New post ID or error.
	 */
	public function generate_post_for_campaign( $campaign_id ) {
		$campaign = get_post( $campaign_id );
		if ( ! $campaign || 'diyara_campaign' !== $campaign->post_type ) {
			return new \WP_Error( 'diyara_bad_campaign', __( 'Invalid campaign.', 'diyara-core' ) );
		}

		// ---------------------------------------------------------------------
		// Read campaign meta
		// ---------------------------------------------------------------------
		$source_url     = $this->get_meta( $campaign_id, 'source_url', '' );
		$source_type    = strtoupper( $this->get_meta( $campaign_id, 'source_type', 'RSS' ) ); // RSS / DIRECT.
		$start_date     = $this->get_meta( $campaign_id, 'start_date', '' );
		$url_keywords   = $this->get_meta( $campaign_id, 'url_keywords', '' );

		$processing_mode = strtoupper( $this->get_meta( $campaign_id, 'processing_mode', 'AS_IS' ) ); // AS_IS, AI_REWRITE, AI_URL_DIRECT, TRANSLATOR_SPIN.
		$ai_model        = $this->get_meta( $campaign_id, 'ai_model', 'gemini-2.5-flash' );
		$prompt_type     = $this->get_meta( $campaign_id, 'prompt_type', 'default' );
		$custom_prompt   = $this->get_meta( $campaign_id, 'custom_prompt', '' );
		$min_words       = (int) $this->get_meta( $campaign_id, 'min_word_count', 600 );
		$max_words       = (int) $this->get_meta( $campaign_id, 'max_word_count', 1000 );
		$ai_temperature  = (float) $this->get_meta( $campaign_id, 'ai_temperature', 0.7 );
		$ai_max_tokens   = (int) $this->get_meta( $campaign_id, 'ai_max_tokens', 2048 ); // Not directly used now, reserved.

		$tone        = $this->get_meta( $campaign_id, 'tone', 'enthusiastic and conversational' );
		$audience    = $this->get_meta( $campaign_id, 'audience', 'general online readers' );
		$brand_voice = $this->get_meta( $campaign_id, 'brand_voice', '' );
		$language    = $this->get_meta( $campaign_id, 'language', 'English' );

		$max_headings          = (int) $this->get_meta( $campaign_id, 'max_headings', 1 );
		$match_length          = (bool) $this->get_meta( $campaign_id, 'match_source_length', '' );
		$match_headings        = (bool) $this->get_meta( $campaign_id, 'match_source_headings', '' );
		$match_tone            = (bool) $this->get_meta( $campaign_id, 'match_source_tone', '' );
		$match_brand_voice     = (bool) $this->get_meta( $campaign_id, 'match_source_brand_voice', '' );

		$target_cat_id = (int) $this->get_meta( $campaign_id, 'target_category_id', 0 );
		$post_status   = $this->get_meta( $campaign_id, 'post_status', 'draft' );
		$seo_plugin    = $this->get_meta( $campaign_id, 'seo_plugin', 'none' ); // none/yoast/rank_math.
		$max_headings = (int) $this->get_meta( $campaign_id, 'max_headings', 1 );

		$rewrite_mode = $this->get_meta( $campaign_id, 'rewrite_mode', 'normal' ); // loose|normal|strict

		if ( ! $source_url ) {
			return new \WP_Error( 'diyara_no_source', __( 'Campaign source URL is empty.', 'diyara-core' ) );
		}

		// Derive a category/niche label for AI prompts.
		$post_category = 'general';
		if ( $target_cat_id ) {
			$cat = get_category( $target_cat_id );
			if ( $cat && ! is_wp_error( $cat ) ) {
				$post_category = $cat->name; // e.g., "Movie News", "Tech Deals"
			}
		}

		// For now we only wire Gemini in Engine.
		$provider = new Gemini_Provider();

		// Prepare logger messages array (for storing in log record).
		$log_messages = array();
		$logger       = function ( $msg, $level = 'info' ) use ( &$log_messages ) {
			$log_messages[] = '[' . strtoupper( $level ) . '] ' . $msg;
		};

		// ---------------------------------------------------------------------
		// 1. Fetch candidates from source (RSS or sitemap)
		// ---------------------------------------------------------------------
		$sources    = Sources::instance();
		$candidates = $sources->fetch_candidates_from_source( $source_url, $source_type, 50, $logger );

		if ( empty( $candidates ) ) {
			return new \WP_Error( 'diyara_no_candidates', __( 'No candidates found in source.', 'diyara-core' ) );
		}

		// ---------------------------------------------------------------------
		// 2. Filter candidates by start_date, URL keywords, and duplicates
		// ---------------------------------------------------------------------
		$start_ts = 0;
		if ( $start_date ) {
			$start_ts = strtotime( $start_date );
		}

		$keywords = array();
		if ( $url_keywords ) {
			$parts = explode( ',', $url_keywords );
			foreach ( $parts as $p ) {
				$p = trim( strtolower( $p ) );
				if ( '' !== $p ) {
					$keywords[] = $p;
				}
			}
		}

		$logs  = Logs::instance();
		$valid = array();

		foreach ( $candidates as $cand ) {
			$link = isset( $cand['link'] ) ? (string) $cand['link'] : '';
			if ( ! $link ) {
				continue;
			}

			// Date filter.
			if ( $start_ts > 0 && ! empty( $cand['pubDate'] ) ) {
				$pub_ts = strtotime( $cand['pubDate'] );
				if ( $pub_ts && $pub_ts < $start_ts ) {
					continue;
				}
			}

			// Keyword filter.
			if ( ! empty( $keywords ) ) {
				$l     = strtolower( $link );
				$match = false;
				foreach ( $keywords as $kw ) {
					if ( false !== strpos( $l, $kw ) ) {
						$match = true;
						break;
					}
				}
				if ( ! $match ) {
					continue;
				}
			}

			// Duplicate filter.
			if ( $logs->has_url_been_processed( $campaign_id, $link ) ) {
				continue;
			}

			$valid[] = $cand;
		}

		if ( empty( $valid ) ) {
			return new \WP_Error( 'diyara_no_new_candidates', __( 'No new candidates to process for this campaign.', 'diyara-core' ) );
		}

		// Sort by pubDate ascending (oldest first).
		usort(
			$valid,
			function ( $a, $b ) {
				$ta = isset( $a['pubDate'] ) ? strtotime( $a['pubDate'] ) : 0;
				$tb = isset( $b['pubDate'] ) ? strtotime( $b['pubDate'] ) : 0;
				if ( $ta === $tb ) {
					return 0;
				}
				return ( $ta < $tb ) ? -1 : 1;
			}
		);

		$candidate   = $valid[0];
		$article_url = $candidate['link'];

		$logger( sprintf( 'Selected candidate: %s', $article_url ), 'info' );

		// ---------------------------------------------------------------------
		// 3. Scrape article content (for all modes)
		// ---------------------------------------------------------------------
		$base = $source_url;
		$url_parts = wp_parse_url( $source_url );
		if ( $url_parts && ! empty( $url_parts['scheme'] ) && ! empty( $url_parts['host'] ) ) {
			$base = $url_parts['scheme'] . '://' . $url_parts['host'] . '/';
		}

		$original_title   = '';
		$original_content = '';
		$original_image   = '';

		$scraped = $sources->scrape_single_page( $base, $article_url, $logger );
		if ( ! $scraped ) {
			return new \WP_Error( 'diyara_scrape_failed', __( 'Failed to scrape article content.', 'diyara-core' ) );
		}
		$original_title   = $scraped['title'];
		$original_content = $scraped['content'];
		$original_image   = $scraped['image_url'];
		$logger( 'Scraped title: "' . $original_title . '"', 'success' );

		// Analyze source article length & structure.
		$source_plain = trim( wp_strip_all_tags( $original_content ) );
		$source_word_count = $source_plain ? str_word_count( $source_plain ) : 0;

		// Count h2 + h3 headings in the source content.
		$source_heading_count = 0;
		if ( preg_match_all( '#<h[23][^>]*>#i', $original_content, $m ) ) {
			$source_heading_count = count( $m[0] );
		}

		// Extract paragraphs (Strict mode will use these).
		$para_parts = preg_split( '#\s*</p>\s*#i', $original_content, -1, PREG_SPLIT_NO_EMPTY );
		$source_paragraph_count = 0;
		$source_paragraphs_text = '';

		if ( $para_parts && is_array( $para_parts ) ) {
			foreach ( $para_parts as $p_html ) {
				$p_plain = trim( wp_strip_all_tags( $p_html ) );
				if ( '' === $p_plain ) {
					continue;
				}
				$source_paragraph_count++;
				$source_paragraphs_text .= "PARAGRAPH {$source_paragraph_count}:\n{$p_plain}\n\n";
			}
		}
		// ---------------------------------------------------------------------
		// 4. AI processing based on mode
		// ---------------------------------------------------------------------
		$final_title   = '';
		$final_content = '';
		$final_slug    = '';
		$final_status  = 'fetched';

		$seo_data = array(
			'focus_keyphrase'  => '',
			'seo_title'        => '',
			'meta_description' => '',
			'image_alt'        => '',
			'synonyms'         => '',
		);

		$tokens_used = 0;

		try {
			if ( 'AS_IS' === $processing_mode ) {

				// Use scraped HTML as-is.
				$final_title   = $original_title;
				$final_content = $original_content;
				$final_status  = 'fetched';

			} elseif ( 'AI_REWRITE' === $processing_mode || 'AI_URL_DIRECT' === $processing_mode ) {

				// For WP we treat both as "scrape + rewrite_content" for stability.
				$logger( 'Mode ' . $processing_mode . ': rewriting scraped content via ' . $ai_model, 'info' );

				$context = $this->build_existing_posts_context();

				// Determine target word range.
				// If "match source length" is checked and we know source length, use S ± 30.
				$target_min_words = $min_words;
				$target_max_words = $max_words;

				if ( $match_length && $source_word_count > 0 ) {
					$tolerance = 30;
					$target_min_words = max( 50, $source_word_count - $tolerance );
					$target_max_words = $source_word_count + $tolerance;
				}

				// Determine effective max headings.
				// If match_source_headings is checked, copy the source heading count.
				$effective_max_headings = $max_headings;
				if ( $match_headings ) {
					$effective_max_headings = $source_heading_count;
				}

				$opts = array(
					'min_words'           => $target_min_words,
					'max_words'           => $target_max_words,
					'custom_prompt'       => ( 'custom' === $prompt_type ) ? $custom_prompt : '',
					'model'               => $ai_model,
					'temperature'         => $ai_temperature,
					'tone'                => $tone,
					'audience'            => $audience,
					'brand_voice'         => $brand_voice,
					'language'            => $language,
					'post_category'       => $post_category,
					'max_headings'        => $effective_max_headings,
					'match_length'        => $match_length,
					'source_word_count'   => $source_word_count,
					'match_headings'      => $match_headings,
					'match_tone'          => $match_tone,
					'match_brand_voice'   => $match_brand_voice,
					'rewrite_mode'        => $rewrite_mode, // loose|normal|strict
					'source_paragraph_count'=> $source_paragraph_count,
					'source_paragraphs_text'=> $source_paragraphs_text,
				);

				$ai_result = $provider->rewrite_content(
					$original_content,
					$original_title,
					$context,
					$opts
				);

				if ( is_wp_error( $ai_result ) ) {
					throw new \Exception( $ai_result->get_error_message() );
				}

				$final_title   = $ai_result['title'] ?: $original_title;
				$final_content = $ai_result['content'] ?: $original_content;
				$final_slug    = $ai_result['slug'] ?: '';
				$seo_data      = array(
					'focus_keyphrase'  => $ai_result['focus_keyphrase'],
					'seo_title'        => $ai_result['title'],
					'meta_description' => $ai_result['meta_description'],
					'image_alt'        => $ai_result['image_alt'],
					'synonyms'         => $ai_result['synonyms'],
				);

				$tokens_used  = (int) ( strlen( wp_strip_all_tags( $final_content ) ) / 4 ) + 150;
				$final_status = 'rewritten';
				$logger( 'AI rewrite successful.', 'success' );

			} else {
				// TRANSLATOR_SPIN or unknown modes: fallback.
				$final_title   = $original_title;
				$final_content = $original_content;
				$final_status  = 'fetched';
				$logger( 'Unsupported processing mode; using AS_IS.', 'warning' );
			}

			if ( ! $final_content ) {
				throw new \Exception( __( 'Final content is empty after processing.', 'diyara-core' ) );
			}

			// -----------------------------------------------------------------
			// 5. Insert WordPress post
			// -----------------------------------------------------------------
			$post_data = array(
				'post_title'   => $final_title ?: $original_title,
				'post_content' => $final_content,
				'post_status'  => ( 'publish' === $post_status ) ? 'publish' : 'draft',
				'post_type'    => 'post',
			);

			if ( $target_cat_id ) {
				$post_data['post_category'] = array( $target_cat_id );
			}

			$post_id = wp_insert_post( $post_data, true );
			if ( is_wp_error( $post_id ) ) {
				throw new \Exception( $post_id->get_error_message() );
			}

			// Apply slug if AI suggested one.
			if ( $final_slug ) {
				wp_update_post(
					array(
						'ID'        => $post_id,
						'post_name' => sanitize_title( $final_slug ),
					)
				);
			}
			// 5.1 Set featured image if we have a primary image URL.
			if ( $original_image ) {
				$alt_text = ! empty( $seo_data['image_alt'] )
					? $seo_data['image_alt']
					: ( $final_title ?: $original_title );

				$this->maybe_set_featured_image( $post_id, $original_image, $alt_text );
			}			

			// -----------------------------------------------------------------
			// 6. SEO meta (Diyara SEO + optional plugin compatibility)
			// -----------------------------------------------------------------
			if ( ! empty( $seo_data['meta_description'] ) ) {
				update_post_meta( $post_id, '_diyara_seo_description', sanitize_textarea_field( $seo_data['meta_description'] ) );
			}
			if ( ! empty( $seo_data['focus_keyphrase'] ) ) {
				update_post_meta( $post_id, '_diyara_seo_focus_keyword', sanitize_text_field( $seo_data['focus_keyphrase'] ) );
			}
			if ( ! empty( $seo_data['seo_title'] ) ) {
				update_post_meta( $post_id, '_diyara_seo_title', sanitize_text_field( $seo_data['seo_title'] ) );
			}

			// Optional: map to Yoast / RankMath if user chose a plugin.
			if ( 'yoast' === $seo_plugin ) {
				if ( ! empty( $seo_data['meta_description'] ) ) {
					update_post_meta( $post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field( $seo_data['meta_description'] ) );
				}
				if ( ! empty( $seo_data['seo_title'] ) ) {
					update_post_meta( $post_id, '_yoast_wpseo_title', sanitize_text_field( $seo_data['seo_title'] ) );
				}
				if ( ! empty( $seo_data['focus_keyphrase'] ) ) {
					update_post_meta( $post_id, '_yoast_wpseo_focuskw', sanitize_text_field( $seo_data['focus_keyphrase'] ) );
				}
			} elseif ( 'rank_math' === $seo_plugin ) {
				if ( ! empty( $seo_data['meta_description'] ) ) {
					update_post_meta( $post_id, 'rank_math_description', sanitize_textarea_field( $seo_data['meta_description'] ) );
				}
				if ( ! empty( $seo_data['seo_title'] ) ) {
					update_post_meta( $post_id, 'rank_math_title', sanitize_text_field( $seo_data['seo_title'] ) );
				}
				if ( ! empty( $seo_data['focus_keyphrase'] ) ) {
					update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field( $seo_data['focus_keyphrase'] ) );
				}
			}

			// -----------------------------------------------------------------
			// 7. Log processed post
			// -----------------------------------------------------------------
			$logs->add_processed_post(
				array(
					'campaign_id'       => $campaign_id,
					'wordpress_post_id' => $post_id,
					'title'             => $original_title ?: $final_title,
					'source_url'        => $article_url,
					'target_url'        => get_permalink( $post_id ),
					'status'            => ( 'publish' === $post_status ) ? 'published' : 'draft',
					'tokens_used'       => $tokens_used,
					'created_at'        => current_time( 'mysql' ),
					'messages'          => $log_messages,
				)
			);

			// Update campaign last_run_at.
			update_post_meta( $campaign_id, '_diyara_campaign_last_run_at', current_time( 'mysql' ) );

			return $post_id;

		} catch ( \Exception $e ) {
			$logger( 'Error: ' . $e->getMessage(), 'error' );
			error_log( '[Diyara Engine] Campaign ' . $campaign_id . ' error: ' . $e->getMessage() );
			return new \WP_Error( 'diyara_engine_error', $e->getMessage() );
		}
	}

		/**
	 * Run multiple generate_post_for_campaign() calls for one campaign.
	 *
	 * Used by Cron to process batches without duplicating logic.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param int    $limit       Max number of posts to generate.
	 * @param string $context     Context string (e.g., 'cron', 'manual').
	 * @return int                Number of posts successfully generated.
	 */
	public function run_batch_for_campaign( $campaign_id, $limit = 1, $context = 'cron' ) {
		$campaign_id = (int) $campaign_id;
		$limit       = max( 1, (int) $limit );
		$count       = 0;

		for ( $i = 0; $i < $limit; $i++ ) {
			$result = $this->generate_post_for_campaign( $campaign_id );

			if ( is_wp_error( $result ) ) {
				$code = $result->get_error_code();

				// For "no candidates" type errors, safely stop for this campaign.
				if ( in_array( $code, array( 'diyara_no_new_candidates', 'diyara_no_candidates', 'diyara_bad_campaign' ), true ) ) {
					break;
				}

				// For other errors, also break to avoid spamming; they are logged inside generate_post_for_campaign.
				break;
			}

			$count++;
		}

		return $count;
	}
	/* -------------------------------------------------------------------------
	 *  Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Helper to get campaign meta.
	 *
	 * @param int    $campaign_id Campaign ID.
	 * @param string $key         Meta key without prefix.
	 * @param mixed  $default     Default.
	 * @return mixed
	 */
	protected function get_meta( $campaign_id, $key, $default = '' ) {
		$val = get_post_meta( $campaign_id, '_diyara_campaign_' . $key, true );
		return ( '' === $val || null === $val ) ? $default : $val;
	}

	/**
	 * Build simple existing posts context string for AI internal linking.
	 *
	 * @param int $limit Number of posts.
	 * @return string
	 */
	protected function build_existing_posts_context( $limit = 5 ) {
		$q = new \WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		if ( ! $q->have_posts() ) {
			return 'No existing posts available.';
		}

		$lines = array();
		foreach ( $q->posts as $post_id ) {
			$lines[] = sprintf(
				'- %s (%s)',
				get_the_title( $post_id ),
				get_permalink( $post_id )
			);
		}
		return implode( "\n", $lines );
	}
	/**
	 * Attempt to sideload a remote image and set it as featured image.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $image_url Remote image URL.
	 * @param string $alt       Alt text for the image.
	 * @return void
	 */
	protected function maybe_set_featured_image( $post_id, $image_url, $alt ) {
		if ( ! $image_url || ! $post_id ) {
			return;
		}

		// Load required WP media functions.
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		// media_sideload_image returns HTML or attachment ID when 'id' is passed as 4th arg.
		$attachment_id = media_sideload_image( $image_url, $post_id, $alt, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			error_log( '[Diyara Engine] Failed to sideload image: ' . $attachment_id->get_error_message() );
			return;
		}

		// Set featured image.
		set_post_thumbnail( $post_id, $attachment_id );
	}
}