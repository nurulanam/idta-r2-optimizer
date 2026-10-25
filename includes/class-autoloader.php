<?php
/**
 * Dynamic autoloader for IDTA R2 Optimizer classes.
 *
 * Follows the WordPress file-naming convention and PSR-4 resolution.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Maps IDTA\R2Optimizer\Some_Class to includes/class-some-class.php.
 */
final class Autoloader {

	/**
	 * Root namespace handled by this autoloader.
	 */
	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * File prefixes checked in sequence per WordPress conventions.
	 *
	 * Includes 'enum-' for modern PHP 8.1+ backed enums.
	 */
	private const FILE_PREFIXES = array(
		'class-',
		'enum-',
		'interface-',
		'abstract-class-',
		'trait-',
	);

	/**
	 * Base directory containing the class files.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Constructor.
	 *
	 * @param string $base_dir Absolute path to the classes root.
	 */
	private function __construct( string $base_dir ) {
		$this->base_dir = rtrim( $base_dir, '/\\' ) . '/';
	}

	/**
	 * Register the autoloader with SPL.
	 *
	 * @param string $base_dir Absolute path to the classes root directory.
	 */
	public static function register( string $base_dir ): void {
		$loader = new self( $base_dir );

		spl_autoload_register( array( $loader, 'load' ) );
	}

	/**
	 * Resolve class name to file and require it if present.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public function load( string $class_name ): void {
		if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$segments = explode( '\\', $relative );
		$leaf     = array_pop( $segments );

		if ( null === $leaf ) {
			return;
		}

		$sub_path = '';
		foreach ( $segments as $segment ) {
			$sub_path .= strtolower( str_replace( '_', '-', $segment ) ) . '/';
		}

		$slug = strtolower( str_replace( '_', '-', $leaf ) );

		foreach ( self::FILE_PREFIXES as $prefix ) {
			$file = $this->base_dir . $sub_path . $prefix . $slug . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;

				return;
			}
		}
	}
}

