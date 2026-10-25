<?php
/**
 * Native AWS Signature Version 4 (SigV4) request signer for Cloudflare R2.
 *
 * Provides standalone HMAC-SHA256 request signing without requiring the AWS SDK.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Handles AWS SigV4 authorization header generation and presigned URL creation for S3/R2.
 */
final class R2_Signer {

	/**
	 * Signature algorithm name.
	 */
	public const ALGORITHM = 'AWS4-HMAC-SHA256';

	/**
	 * Cloudflare R2 Access Key ID.
	 *
	 * @var string
	 */
	private string $access_key_id;

	/**
	 * Cloudflare R2 Secret Access Key.
	 *
	 * @var string
	 */
	private string $secret_access_key;

	/**
	 * R2 region identifier. Cloudflare R2 requires 'auto'.
	 *
	 * @var string
	 */
	private string $region;

	/**
	 * S3 service identifier.
	 *
	 * @var string
	 */
	private string $service;

	/**
	 * Constructor.
	 *
	 * @param string $access_key_id     R2 Access Key ID.
	 * @param string $secret_access_key R2 Secret Access Key.
	 * @param string $region            Region identifier ('auto' for Cloudflare R2).
	 * @param string $service           Service identifier ('s3').
	 */
	public function __construct(
		string $access_key_id,
		string $secret_access_key,
		string $region = 'auto',
		string $service = 's3'
	) {
		$this->access_key_id     = trim( $access_key_id );
		$this->secret_access_key = trim( $secret_access_key );
		$this->region            = trim( $region ) ?: 'auto';
		$this->service           = trim( $service ) ?: 's3';
	}

	/**
	 * Sign an HTTP request with AWS SigV4 headers.
	 *
	 * @param string               $method    HTTP method (GET, POST, PUT, DELETE, HEAD).
	 * @param string               $url       Full URL being requested.
	 * @param array<string,string> $headers   Associative array of request headers.
	 * @param string               $body      Request body payload (empty string for GET/HEAD/DELETE without payload).
	 * @param int|null             $timestamp Request timestamp (defaults to current time).
	 * @return array<string,string> Complete headers array including Authorization, Host, and x-amz-*.
	 */
	public function sign_request(
		string $method,
		string $url,
		array $headers = array(),
		string $body = '',
		?int $timestamp = null
	): array {
		$time       = $timestamp ?? time();
		$iso_date   = gmdate( 'Ymd\THis\Z', $time );
		$date_stamp = gmdate( 'Ymd', $time );

		$parsed = wp_parse_url( $url );
		if ( false === $parsed || empty( $parsed['host'] ) ) {
			throw new \InvalidArgumentException( 'Invalid URL provided for SigV4 signing: ' . $url );
		}

		$host = $parsed['host'];
		if ( ! empty( $parsed['port'] ) && 80 !== $parsed['port'] && 443 !== $parsed['port'] ) {
			$host .= ':' . $parsed['port'];
		}

		$payload_hash = hash( 'sha256', $body );

		// Standardize required AWS headers.
		$normalized_headers = array();
		foreach ( $headers as $key => $value ) {
			$normalized_headers[ strtolower( trim( (string) $key ) ) ] = trim( (string) $value );
		}

		$normalized_headers['host']                 = $host;
		$normalized_headers['x-amz-date']           = $iso_date;
		$normalized_headers['x-amz-content-sha256'] = $payload_hash;

		// Build Canonical URI.
		$path          = $parsed['path'] ?? '/';
		$canonical_uri = $this->build_canonical_uri( $path );

		// Build Canonical Query String.
		$query_string    = $parsed['query'] ?? '';
		$canonical_query = $this->build_canonical_query_string( $query_string );

		// Build Canonical Headers and Signed Headers list.
		ksort( $normalized_headers, SORT_STRING );

		$canonical_headers_parts = array();
		$signed_headers_list     = array();

		foreach ( $normalized_headers as $h_key => $h_val ) {
			$collapsed_val             = (string) preg_replace( '/\s+/', ' ', $h_val );
			$canonical_headers_parts[] = $h_key . ':' . $collapsed_val;
			$signed_headers_list[]     = $h_key;
		}

		$canonical_headers  = implode( "\n", $canonical_headers_parts ) . "\n";
		$signed_headers_str = implode( ';', $signed_headers_list );

		// Assemble Canonical Request.
		$canonical_request = strtoupper( $method ) . "\n"
			. $canonical_uri . "\n"
			. $canonical_query . "\n"
			. $canonical_headers . "\n"
			. $signed_headers_str . "\n"
			. $payload_hash;

		// Assemble String to Sign.
		$credential_scope = $date_stamp . '/' . $this->region . '/' . $this->service . '/aws4_request';
		$string_to_sign   = self::ALGORITHM . "\n"
			. $iso_date . "\n"
			. $credential_scope . "\n"
			. hash( 'sha256', $canonical_request );

		// Calculate Signing Key and Signature.
		$signing_key = $this->derive_signing_key( $date_stamp );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		// Generate Authorization Header.
		$authorization = sprintf(
			'%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			self::ALGORITHM,
			$this->access_key_id,
			$credential_scope,
			$signed_headers_str,
			$signature
		);

		$result_headers                  = $normalized_headers;
		$result_headers['authorization'] = $authorization;

		return $result_headers;
	}

	/**
	 * Generate a presigned S3/R2 URL with SigV4 query authentication.
	 *
	 * @param string   $method     HTTP method (GET, HEAD).
	 * @param string   $url        Full object URL.
	 * @param int      $expires_in Duration in seconds before expiration (maximum 604800 / 7 days).
	 * @param int|null $timestamp  Creation timestamp.
	 * @return string Full presigned URL.
	 */
	public function get_presigned_url(
		string $method,
		string $url,
		int $expires_in = 3600,
		?int $timestamp = null
	): string {
		$time       = $timestamp ?? time();
		$iso_date   = gmdate( 'Ymd\THis\Z', $time );
		$date_stamp = gmdate( 'Ymd', $time );

		$parsed = wp_parse_url( $url );
		if ( false === $parsed || empty( $parsed['host'] ) ) {
			throw new \InvalidArgumentException( 'Invalid URL provided for presigning: ' . $url );
		}

		$host = $parsed['host'];
		if ( ! empty( $parsed['port'] ) && 80 !== $parsed['port'] && 443 !== $parsed['port'] ) {
			$host .= ':' . $parsed['port'];
		}

		$credential_scope = $date_stamp . '/' . $this->region . '/' . $this->service . '/aws4_request';

		$query_params = array();
		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $query_params );
		}

		$query_params['X-Amz-Algorithm']     = self::ALGORITHM;
		$query_params['X-Amz-Credential']    = $this->access_key_id . '/' . $credential_scope;
		$query_params['X-Amz-Date']          = $iso_date;
		$query_params['X-Amz-Expires']       = (string) min( max( 1, $expires_in ), 604800 );
		$query_params['X-Amz-SignedHeaders'] = 'host';

		// Sort query parameters.
		ksort( $query_params, SORT_STRING );
		$canonical_query_parts = array();
		foreach ( $query_params as $k => $v ) {
			$canonical_query_parts[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
		}
		$canonical_query = implode( '&', $canonical_query_parts );

		$path          = $parsed['path'] ?? '/';
		$canonical_uri = $this->build_canonical_uri( $path );

		$canonical_headers = 'host:' . $host . "\n";
		$payload_hash      = 'UNSIGNED-PAYLOAD';

		$canonical_request = strtoupper( $method ) . "\n"
			. $canonical_uri . "\n"
			. $canonical_query . "\n"
			. $canonical_headers . "\n"
			. 'host' . "\n"
			. $payload_hash;

		$string_to_sign = self::ALGORITHM . "\n"
			. $iso_date . "\n"
			. $credential_scope . "\n"
			. hash( 'sha256', $canonical_request );

		$signing_key = $this->derive_signing_key( $date_stamp );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$scheme = $parsed['scheme'] ?? 'https';
		$base   = $scheme . '://' . $host . $path;

		return $base . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
	}

	/**
	 * Derive the AWS SigV4 HMAC-SHA256 signing key.
	 *
	 * @param string $date_stamp Date in Ymd format (e.g. 20261025).
	 * @return string Binary derived signing key.
	 */
	private function derive_signing_key( string $date_stamp ): string {
		$k_secret  = 'AWS4' . $this->secret_access_key;
		$k_date    = hash_hmac( 'sha256', $date_stamp, $k_secret, true );
		$k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $this->service, $k_region, true );

		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}

	/**
	 * Build normalized canonical URI per RFC 3986 without encoding path slashes.
	 *
	 * @param string $path Raw URL path.
	 * @return string Canonical URI path.
	 */
	private function build_canonical_uri( string $path ): string {
		if ( '' === $path || '/' === $path ) {
			return '/';
		}

		$segments = explode( '/', $path );
		$encoded  = array();

		foreach ( $segments as $segment ) {
			$encoded[] = rawurlencode( $segment );
		}

		$result = implode( '/', $encoded );

		return str_starts_with( $result, '/' ) ? $result : '/' . $result;
	}

	/**
	 * Build normalized, sorted canonical query string.
	 *
	 * @param string $query Raw query string.
	 * @return string Canonical query string.
	 */
	private function build_canonical_query_string( string $query ): string {
		if ( '' === trim( $query ) ) {
			return '';
		}

		$pairs = explode( '&', $query );
		$map   = array();

		foreach ( $pairs as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			$parts = explode( '=', $pair, 2 );
			$key   = rawurlencode( urldecode( $parts[0] ) );
			$val   = isset( $parts[1] ) ? rawurlencode( urldecode( $parts[1] ) ) : '';

			$map[ $key ] = $val;
		}

		ksort( $map, SORT_STRING );

		$canonical = array();
		foreach ( $map as $k => $v ) {
			$canonical[] = $k . '=' . $v;
		}

		return implode( '&', $canonical );
	}
}

