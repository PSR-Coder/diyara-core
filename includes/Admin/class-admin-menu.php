<?php
/**
 * Registers Diyara admin menu and submenu pages.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin_Menu
 */
class Admin_Menu {

	/**
	 * Initialize admin menu hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register top-level menu and submenus.
	 *
	 * @return void
	 */
	public function register_menu() {

		// Dashboard page (top-level).
		$dashboard_page = new Admin_Page_Dashboard();

		add_menu_page(
			__( 'Diyara', 'diyara-core' ),
			__( 'Diyara', 'diyara-core' ),
			'manage_options',
			$dashboard_page->get_menu_slug(),
			array( $dashboard_page, 'render' ),
			'dashicons-admin-site-alt3',
			58
		);

		// Campaigns page.
		$campaigns_page = new Admin_Page_Campaigns();
		add_submenu_page(
			$dashboard_page->get_menu_slug(),
			$campaigns_page->get_page_title(),
			$campaigns_page->get_menu_title(),
			'manage_options',
			$campaigns_page->get_menu_slug(),
			array( $campaigns_page, 'render' )
		);

		// Settings page.
		$settings_page = new Admin_Page_Settings();
		add_submenu_page(
			$dashboard_page->get_menu_slug(),
			$settings_page->get_page_title(),
			$settings_page->get_menu_title(),
			'manage_options',
			$settings_page->get_menu_slug(),
			array( $settings_page, 'render' )
		);

		// Logs page.
		$logs_page = new Admin_Page_Logs();
		add_submenu_page(
			$dashboard_page->get_menu_slug(),
			$logs_page->get_page_title(),
			$logs_page->get_menu_title(),
			'manage_options',
			$logs_page->get_menu_slug(),
			array( $logs_page, 'render' )
		);
	}

	/**
	 * Enqueue admin assets for Diyara pages.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		// Only load on Diyara pages to avoid conflicts.
		$allowed_hooks = array(
			'toplevel_page_diyara-dashboard',
			'diyara_page_diyara-campaigns',
			'diyara_page_diyara-settings',
			'diyara_page_diyara-logs',
		);
		echo $hook;

		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		// Enqueue Main Admin CSS
		wp_enqueue_style(
			'diyara-core-admin',
			DIYARA_CORE_URL . 'assets/css/admin.css'
		);

		// Enqueue Main Admin JS
		wp_enqueue_script(
			'diyara-core-admin',
			DIYARA_CORE_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			DIYARA_CORE_VERSION,
			true
		);

		// Localize Script for AJAX actions
		wp_localize_script( 'diyara-core-admin', 'diyara_vars', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'diyara_admin_nonce' ),
		) );
	}
}