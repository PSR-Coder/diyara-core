<?php
/**
 * Simple XML sitemap for Diyara Core.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sitemap
 */
class Sitemap {

	/**
	 * Init hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'render_sitemap' ) );
	}

	/**
	 * Add rewrite rule for /sitemap.xml.
	 *
	 * @return void
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^sitemap\.xml$',
			'index.php?diyara_sitemap=1',
			'top'
		);
	}

	/**
	 * Register custom query var.
	 *
	 * @param array $vars Existing vars.
	 * @return array
	 */
	public function register_query_var( $vars ) {
		$vars[] = 'diyara_sitemap';
		return $vars;
	}

	/**
	 * Render sitemap XML when requested.
	 *
	 * @return void
	 */
	public function render_sitemap() {
		$value = get_query_var( 'diyara_sitemap' );

		if ( empty( $value ) ) {
			return;
		}

		// Very simple sitemap: posts + pages + categories + tags.
		header( 'Content-Type: application/xml; charset=UTF-8' );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		// Home page.
		$this->render_url( home_url( '/' ), time() );

		// Posts + pages.
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		foreach ( $posts as $post_id ) {
			$loc     = get_permalink( $post_id );
			$lastmod = get_post_modified_time( 'c', true, $post_id );
			$this->render_url( $loc, $lastmod );
		}

		// Categories + tags.
		$terms = get_terms(
			array(
				'taxonomy'   => array( 'category', 'post_tag' ),
				'hide_empty' => true,
			)
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$loc = get_term_link( $term );
				if ( ! is_wp_error( $loc ) ) {
					$this->render_url( $loc, time() );
				}
			}
		}

		echo "</urlset>\n";

		exit;
	}

	/**
	 * Helper to render a single <url> entry.
	 *
	 * @param string       $loc     URL.
	 * @param int|string   $lastmod Timestamp or date string.
	 * @return void
	 */
	protected function render_url( $loc, $lastmod ) {
		if ( ! $loc ) {
			return;
		}

		if ( is_numeric( $lastmod ) ) {
			$lastmod = gmdate( 'c', (int) $lastmod );
		}

		echo '<url>' . "\n";
		echo '  <loc>' . esc_url( $loc ) . "</loc>\n";
		if ( $lastmod ) {
			echo '  <lastmod>' . esc_html( $lastmod ) . "</lastmod>\n";
		}
		echo "</url>\n";
	}
}