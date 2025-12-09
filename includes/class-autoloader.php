<?php
/**
 * PSR-4 style autoloader for Diyara Core.
 *
 * @package DiyaraCore
 */

namespace DiyaraCore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autoloader {

	/**
	 * Namespace prefix.
	 *
	 * @var string
	 */
	protected static $prefix = 'DiyaraCore\\';

	/**
	 * Base directory for the namespace prefix.
	 *
	 * @var string
	 */
	protected static $base_dir;

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		self::$base_dir = DIYARA_CORE_DIR . 'includes/';

		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload callback.
	 *
	 * @param string $class Class name.
	 * @return void
	 */
	public static function autoload( $class ) {
		// Does the class use the namespace prefix?
		$len = strlen( self::$prefix );
		if ( 0 !== strncmp( self::$prefix, $class, $len ) ) {
			return;
		}

		// Get the relative class name.
		$relative_class = substr( $class, $len );

		// Replace namespace separators with directory separators, lowercase file name.
		$relative_path = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class );

		// Convert class name to file path: Class_Name -> class-name.php.
		$parts      = explode( DIRECTORY_SEPARATOR, $relative_path );
		$last_index = count( $parts ) - 1;

		$parts[ $last_index ] = 'class-' . strtolower( str_replace( '_', '-', $parts[ $last_index ] ) ) . '.php';

		$file = self::$base_dir . implode( DIRECTORY_SEPARATOR, $parts );

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
}