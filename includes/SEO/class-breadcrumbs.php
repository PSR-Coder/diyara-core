<?php
/**
 * Simple breadcrumbs for Diyara.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Breadcrumbs
 */
class Breadcrumbs {

	/**
	 * Output breadcrumbs HTML.
	 *
	 * @param array $args Arguments.
	 * @return void
	 */
	public static function output( $args = array() ) {
		$defaults = array(
			'separator'   => ' / ',
			'home_label'  => __( 'Home', 'diyara-core' ),
			'wrapper_tag' => 'nav',
			'wrapper_class' => 'diyara-breadcrumbs',
		);

		$args = wp_parse_args( $args, $defaults );

		$items = self::build_items( $args['home_label'] );

		if ( empty( $items ) ) {
			return;
		}

		$tag   = tag_escape( $args['wrapper_tag'] );
		$class = esc_attr( $args['wrapper_class'] );

		echo '<' . $tag . ' class="' . $class . '" aria-label="Breadcrumbs">';
		echo wp_kses_post( implode( $args['separator'], $items ) );
		echo '</' . $tag . '>';
	}

	/**
	 * Build breadcrumb items array.
	 *
	 * @param string $home_label Home label.
	 * @return array
	 */
	protected static function build_items( $home_label ) {
		$items = array();

		$home_url = home_url( '/' );
		$items[]  = '<a href="' . esc_url( $home_url ) . '">' . esc_html( $home_label ) . '</a>';

		if ( is_front_page() ) {
			return $items;
		}

		if ( is_home() ) {
			$items[] = esc_html__( 'Blog', 'diyara-core' );
			return $items;
		}

		if ( is_singular( 'post' ) ) {
			$cats = get_the_category();
			if ( ! empty( $cats ) ) {
				$primary = $cats[0];
				$items[] = '<a href="' . esc_url( get_category_link( $primary ) ) . '">' . esc_html( $primary->name ) . '</a>';
			}
			$items[] = esc_html( get_the_title() );
			return $items;
		}

		if ( is_page() ) {
			global $post;
			$ancestors = array_reverse( get_post_ancestors( $post->ID ) );
			foreach ( $ancestors as $ancestor_id ) {
				$items[] = '<a href="' . esc_url( get_permalink( $ancestor_id ) ) . '">' . esc_html( get_the_title( $ancestor_id ) ) . '</a>';
			}
			$items[] = esc_html( get_the_title() );
			return $items;
		}

		if ( is_category() ) {
			$items[] = esc_html( single_cat_title( '', false ) );
			return $items;
		}

		if ( is_tag() ) {
			$items[] = esc_html( single_tag_title( '', false ) );
			return $items;
		}

		if ( is_search() ) {
			$items[] = sprintf(
				/* translators: %s: search query. */
				esc_html__( 'Search results for "%s"', 'diyara-core' ),
				get_search_query()
			);
			return $items;
		}

		if ( is_404() ) {
			$items[] = esc_html__( '404 Not Found', 'diyara-core' );
			return $items;
		}

		return $items;
	}
}

/**
 * Template tag wrapper.
 *
 * @param array $args Optional arguments.
 * @return void
 */
function diyara_core_breadcrumbs( $args = array() ) {
	Breadcrumbs::output( $args );
}

// Shortcode [diyara_breadcrumbs].
if ( ! function_exists( 'diyara_core_register_breadcrumbs_shortcode' ) ) {
	/**
	 * Register shortcode on init.
	 *
	 * @return void
	 */
	function diyara_core_register_breadcrumbs_shortcode() {
		add_shortcode(
			'diyara_breadcrumbs',
			function () {
				ob_start();
				Breadcrumbs::output();
				return ob_get_clean();
			}
		);
	}
	add_action( 'init', __NAMESPACE__ . '\\diyara_core_register_breadcrumbs_shortcode' );
}