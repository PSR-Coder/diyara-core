<?php
/**
 * Base admin page class.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Page_Base
 */
abstract class Admin_Page_Base {

	/**
	 * Get the menu slug for this page.
	 *
	 * @return string
	 */
	abstract public function get_menu_slug();

	/**
	 * Get the page title (browser title).
	 *
	 * @return string
	 */
	abstract public function get_page_title();

	/**
	 * Get the menu title (visible in sidebar).
	 *
	 * @return string
	 */
	abstract public function get_menu_title();

	/**
	 * Render the page content.
	 *
	 * @return void
	 */
	abstract public function render();

	/**
	 * Render a standard page header wrapper.
	 *
	 * @param string $title Title to display.
	 * @param string $subtitle Optional subtitle.
	 * @return void
	 */
	protected function render_header( $title, $subtitle = '' ) {
		?>
		<div class="wrap diyara-core-wrap">
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<?php if ( $subtitle ) : ?>
				<p class="description"><?php echo esc_html( $subtitle ); ?></p>
			<?php endif; ?>
			<hr class="wp-header-end" />
		<?php
	}

	/**
	 * Close the wrap div.
	 *
	 * @return void
	 */
	protected function render_footer() {
		?>
		</div>
		<?php
	}
}