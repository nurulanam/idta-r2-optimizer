<?php
/**
 * Bucket record Data Transfer Object (DTO).
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object representing a Cloudflare R2 bucket.
 */
final class Bucket_Record {

	/**
	 * Unique bucket name.
	 *
	 * @var string
	 */
	public readonly string $name;

	/**
	 * ISO 8601 creation timestamp string (e.g. 2026-08-01T12:00:00.000Z).
	 *
	 * @var string
	 */
	public readonly string $creation_date;

	/**
	 * Constructor.
	 *
	 * @param string $name          Bucket name identifier.
	 * @param string $creation_date ISO 8601 creation date string.
	 */
	public function __construct( string $name, string $creation_date = '' ) {
		$this->name          = trim( $name );
		$this->creation_date = trim( $creation_date );
	}

	/**
	 * Instantiate a record from an XML <Bucket> node.
	 *
	 * @param \SimpleXMLElement $xml XML node containing <Name> and optionally <CreationDate>.
	 * @return self
	 */
	public static function from_xml( \SimpleXMLElement $xml ): self {
		$name          = (string) ( $xml->Name ?? '' );
		$creation_date = (string) ( $xml->CreationDate ?? '' );

		return new self( $name, $creation_date );
	}

	/**
	 * Format creation date into human-readable WordPress localized format.
	 *
	 * @return string Formatted date string, or empty string if date unavailable.
	 */
	public function formatted_creation_date(): string {
		if ( '' === $this->creation_date ) {
			return '';
		}

		$timestamp = strtotime( $this->creation_date );
		if ( false === $timestamp ) {
			return $this->creation_date;
		}

		$date_format = get_option( 'date_format', 'Y-m-d' );
		$time_format = get_option( 'time_format', 'H:i' );

		return wp_date( $date_format . ' ' . $time_format, $timestamp );
	}

	/**
	 * Convert record to an associative array for JSON serialization.
	 *
	 * @return array{name: string, creation_date: string, formatted_date: string}
	 */
	public function to_array(): array {
		return array(
			'name'           => $this->name,
			'creation_date'  => $this->creation_date,
			'formatted_date' => $this->formatted_creation_date(),
		);
	}
}

