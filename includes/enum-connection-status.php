<?php
/**
 * Connection status enumeration for R2 folder assets.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Health and linkage status of an R2 asset folder.
 */
enum Connection_Status: string {

	/**
	 * Folder matches an active WooCommerce order and has all required assets present.
	 */
	case CONNECTED = 'connected';

	/**
	 * Folder exists in Cloudflare R2, but no corresponding WooCommerce order references it.
	 */
	case ORPHANED = 'orphaned';

	/**
	 * Order references the folder, but one or more required verification documents are missing in R2.
	 */
	case BROKEN = 'broken';

	/**
	 * Human-readable label for UI badges and table rows.
	 *
	 * @return string Translated status label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::CONNECTED => __( 'Connected', 'idta-r2-optimizer' ),
			self::ORPHANED  => __( 'Orphaned', 'idta-r2-optimizer' ),
			self::BROKEN    => __( 'Incomplete Assets', 'idta-r2-optimizer' ),
		};
	}

	/**
	 * Hex color code associated with the status indicator.
	 *
	 * @return string CSS color value.
	 */
	public function color(): string {
		return match ( $this ) {
			self::CONNECTED => '#16a34a', // Green-600
			self::ORPHANED  => '#dc2626', // Red-600
			self::BROKEN    => '#d97706', // Amber-600
		};
	}

	/**
	 * CSS modifier class for status badges.
	 *
	 * @return string CSS class name.
	 */
	public function badge_class(): string {
		return match ( $this ) {
			self::CONNECTED => 'status-connected',
			self::ORPHANED  => 'status-orphaned',
			self::BROKEN    => 'status-broken',
		};
	}

	/**
	 * Detailed explanation of the status for tooltips and help text.
	 *
	 * @return string Status description.
	 */
	public function description(): string {
		return match ( $this ) {
			self::CONNECTED => __( 'Folder is verified and attached to a valid WooCommerce order with all document assets intact.', 'idta-r2-optimizer' ),
			self::ORPHANED  => __( 'Folder exists in R2 with files, but has no linked WooCommerce order. Safe to delete or remap.', 'idta-r2-optimizer' ),
			self::BROKEN    => __( 'An order references this folder, but one or more required document files are missing in R2.', 'idta-r2-optimizer' ),
		};
	}
}

