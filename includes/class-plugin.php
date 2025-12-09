<?php
/**
 * Main plugin bootstrap class.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore;

use DiyaraCore\Admin\Admin_Menu;
use DiyaraCore\SEO\SEO_Manager;
use DiyaraCore\SEO\Sitemap;
use DiyaraCore\AI\AI_Settings;
use DiyaraCore\AI\Campaigns;
use DiyaraCore\AI\Engine;
use DiyaraCore\AI\Sources;
use DiyaraCore\AI\Logs;
use DiyaraCore\AI\Cron;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * Initialize plugin hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Load textdomain.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Later we will bootstrap:
		// - SEO Manager
		// - AI Settings / Engine / Cron
		// - Admin pages.
    
		// SEO module (front-end + admin).
		$this->init_seo();

    // AI module.
		$this->init_ai();

    // Initialize admin area.
    // Admin-only features.
    if ( is_admin() ) {
      $this->init_admin();
      add_action( 'admin_notices', array( $this, 'render_run_message_notice' ) );
    }
	}

  /**
	 * Show notice after manual campaign run (success or error).
	 *
	 * @return void
	 */
	public function render_run_message_notice() {
		if ( empty( $_GET['diyara_message'] ) ) {
			return;
		}

		$message = wp_kses_post( wp_unslash( $_GET['diyara_message'] ) );
		$status  = isset( $_GET['diyara_status'] ) ? sanitize_text_field( wp_unslash( $_GET['diyara_status'] ) ) : 'updated';

		$class = ( 'error' === $status ) ? 'notice notice-error' : 'notice notice-success';

		echo '<div class="' . esc_attr( $class ) . ' is-dismissible">';
		echo '<p>' . $message . '</p>';
		echo '</div>';
	}
	/**
	 * Load plugin textdomain for translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'diyara-core',
			false,
			dirname( plugin_basename( DIYARA_CORE_FILE ) ) . '/languages'
		);
	}
	/**
	 * Initialize SEO manager.
	 *
	 * @return void
	 */
	protected function init_seo() {
		$seo_manager = SEO_Manager::instance();
		$seo_manager->init();

		$sitemap = new Sitemap();
		$sitemap->init();

    // Ensure breadcrumbs functions & shortcode are registered.
    // This file defines \DiyaraCore\SEO\Breadcrumbs and the [diyara_breadcrumbs] shortcode.
    if ( file_exists( DIYARA_CORE_DIR . 'includes/SEO/class-breadcrumbs.php' ) ) {
      require_once DIYARA_CORE_DIR . 'includes/SEO/class-breadcrumbs.php';
    }    
	}

  /**
	 * Initialize admin related functionality.
	 *
	 * @return void
	 */
	protected function init_admin() {
		$admin_menu = new Admin_Menu();
		$admin_menu->init();
	}

	/**
	 * Initialize AI module (settings, campaigns, engine).
	 *
	 * @return void
	 */
	protected function init_ai() {
		\DiyaraCore\AI\AI_Settings::instance()->init();
		\DiyaraCore\AI\Campaigns::instance()->init();
		\DiyaraCore\AI\Engine::instance()->init();
		\DiyaraCore\AI\Sources::instance()->init();
		\DiyaraCore\AI\Logs::instance()->init();
		\DiyaraCore\AI\Cron::instance()->init(); // NEW
	}

}