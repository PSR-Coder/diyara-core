<?php
/**
 * Plugin Name: Diyara Core
 * Plugin URI:  https://example.com/diyara-core
 * Description: Core functionality for the Diyara theme: AI auto-blogging and SEO features.
 * Version:     0.1.0
 * Author:      Your Name
 * Author URI:  https://example.com
 * Text Domain: diyara-core
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.0
 *
 * @package DiyaraCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DIYARA_CORE_VERSION' ) ) {
	define( 'DIYARA_CORE_VERSION', '0.1.0' );
}

if ( ! defined( 'DIYARA_CORE_FILE' ) ) {
	define( 'DIYARA_CORE_FILE', __FILE__ );
}

if ( ! defined( 'DIYARA_CORE_DIR' ) ) {
	define( 'DIYARA_CORE_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'DIYARA_CORE_URL' ) ) {
	define( 'DIYARA_CORE_URL', plugin_dir_url( __FILE__ ) );
}

// Include autoloader.
require_once DIYARA_CORE_DIR . 'includes/class-autoloader.php';

// Register autoloader for namespace DiyaraCore.
DiyaraCore\Autoloader::register();
use DiyaraCore\AI\Cron;
/**
 * Bootstrap the plugin.
 */
function diyara_core_init_plugin() {
	$plugin = new DiyaraCore\Plugin();
	$plugin->init();
}
register_activation_hook(
	DIYARA_CORE_FILE,
	function () {
		if ( class_exists( '\DiyaraCore\AI\Cron' ) ) {
			\DiyaraCore\AI\Cron::activate();
		}
	}
);

register_deactivation_hook(
	DIYARA_CORE_FILE,
	function () {
		if ( class_exists( '\DiyaraCore\AI\Cron' ) ) {
			\DiyaraCore\AI\Cron::deactivate();
		}
	}
);
add_action( 'plugins_loaded', 'diyara_core_init_plugin' );