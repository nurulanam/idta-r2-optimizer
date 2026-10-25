<?php
/**
 * Order asset record DTO.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object holding indexed WooCommerce order metadata for an asset folder.
 */
final class Order_Asset_Record {

	/**
	 * WooCommerce Order ID.
	 *
	 * @var int
	 */
	public readonly int $order_id;

	/**
	 * Order status slug (e.g. 'completed', 'processing', 'pending').
	 *
	 * @var string
	 */
	public readonly string $status;

	/**
	 * Formatted customer name.
	 *
	 * @var string
	 */
	public readonly string $customer_name;

	/**
	 * Customer billing email address.
	 *
	 * @var string
	 */
	public readonly string $billing_email;

	/**
	 * Front-end source identifier (_idp_order_from, e.g. 'idta', 'idpa').
	 *
	 * @var string
	 */
	public readonly string $order_from;

	/**
	 * Normalized asset folder path (e.g. '2026/08/01/063701-213').
	 *
	 * @var string
	 */
	public readonly string $asset_folder;

	/**
	 * GMT creation date string.
	 *
	 * @var string
	 */
	public readonly string $date_created_gmt;

	/**
	 * Constructor.
	 *
	 * @param int    $order_id         WooCommerce Order ID.
	 * @param string $status           Order status without 'wc-' prefix.
	 * @param string $customer_name    Customer full name.
	 * @param string $billing_email    Billing email.
	 * @param string $order_from       Order source.
	 * @param string $asset_folder     Normalized folder path.
	 * @param string $date_created_gmt Creation timestamp string.
	 */
	public function __construct(
		int $order_id,
		string $status,
		string $customer_name,
		string $billing_email,
		string $order_from,
		string $asset_folder,
		string $date_created_gmt
	) {
		$this->order_id         = $order_id;
		$this->status           = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
		$this->customer_name    = trim( $customer_name );
		$this->billing_email    = trim( $billing_email );
		$this->order_from       = strtolower( trim( $order_from ) );
		$this->asset_folder     = trim( $asset_folder, '/' );
		$this->date_created_gmt = trim( $date_created_gmt );
	}

	/**
	 * Get direct admin URL to edit this WooCommerce order.
	 *
	 * @return string Edit order URL.
	 */
	public function get_admin_url(): string {
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $this->order_id );
			if ( $order instanceof \WC_Order ) {
				return $order->get_edit_order_url();
			}
		}

		return admin_url( 'post.php?post=' . $this->order_id . '&action=edit' );
	}

	/**
	 * Localized human-readable creation date.
	 *
	 * @return string Formatted date string.
	 */
	public function get_formatted_date(): string {
		if ( '' === $this->date_created_gmt ) {
			return '';
		}

		$timestamp = strtotime( $this->date_created_gmt . ' UTC' );
		if ( false === $timestamp ) {
			return $this->date_created_gmt;
		}

		$date_format = get_option( 'date_format', 'Y-m-d' );
		$time_format = get_option( 'time_format', 'H:i' );

		return wp_date( $date_format . ' ' . $time_format, $timestamp );
	}

	/**
	 * Serialize record to an associative array for JSON payloads.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'order_id'       => $this->order_id,
			'status'         => $this->status,
			'status_label'   => function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $this->status ) : ucfirst( $this->status ),
			'customer_name'  => $this->customer_name ?: __( 'Guest', 'idta-r2-optimizer' ),
			'billing_email'  => $this->billing_email,
			'order_from'     => $this->order_from ?: 'idta',
			'asset_folder'   => $this->asset_folder,
			'date_created'   => $this->date_created_gmt,
			'formatted_date' => $this->get_formatted_date(),
			'admin_url'      => $this->get_admin_url(),
		);
	}
}

