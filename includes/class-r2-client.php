<?php
/**
 * Cloudflare R2 S3-compatible REST API client.
 *
 * Communicates with Cloudflare R2 using AWS SigV4 authorization headers
 * and WordPress HTTP API infrastructure (wp_remote_request).
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * High-performance client for Cloudflare R2 bucket and object operations.
 */
class R2_Client {

	/**
	 * Default request timeout in seconds.
	 */
	public const REQUEST_TIMEOUT = 30;

	/**
	 * Cloudflare 32-character Account ID.
	 *
	 * @var string
	 */
	private string $account_id;

	/**
	 * Cloudflare R2 Token Access Key ID.
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
	 * Custom S3 endpoint, if configured.
	 *
	 * @var string
	 */
	private string $custom_endpoint;

	/**
	 * SigV4 signer instance.
	 *
	 * @var R2_Signer
	 */
	private R2_Signer $signer;

	/**
	 * Constructor.
	 *
	 * If credentials are not provided, automatically loads them from plugin settings.
	 *
	 * @param string|null $account_id        Cloudflare Account ID.
	 * @param string|null $access_key_id     R2 Access Key ID.
	 * @param string|null $secret_access_key R2 Secret Access Key.
	 * @param string|null $custom_endpoint   Optional custom endpoint.
	 */
	public function __construct(
		?string $account_id = null,
		?string $access_key_id = null,
		?string $secret_access_key = null,
		?string $custom_endpoint = null
	) {
		if ( null === $account_id || null === $access_key_id || null === $secret_access_key ) {
			$settings = plugin()->settings();

			$this->account_id        = trim( $account_id ?? (string) ( $settings['account_id'] ?? '' ) );
			$this->access_key_id     = trim( $access_key_id ?? (string) ( $settings['access_key_id'] ?? '' ) );
			$this->secret_access_key = trim( $secret_access_key ?? (string) ( $settings['secret_access_key'] ?? '' ) );
			$this->custom_endpoint   = trim( $custom_endpoint ?? (string) ( $settings['custom_endpoint'] ?? '' ) );
		} else {
			$this->account_id        = trim( $account_id );
			$this->access_key_id     = trim( $access_key_id );
			$this->secret_access_key = trim( $secret_access_key );
			$this->custom_endpoint   = trim( $custom_endpoint ?? '' );
		}

		$this->signer = new R2_Signer(
			$this->access_key_id,
			$this->secret_access_key,
			'auto',
			's3'
		);
	}

	/**
	 * Check if all required credentials are present.
	 *
	 * @return bool True if credentials are configured; false otherwise.
	 */
	public function has_credentials(): bool {
		return '' !== $this->account_id
			&& '' !== $this->access_key_id
			&& '' !== $this->secret_access_key;
	}

	/**
	 * Get the base endpoint URL.
	 *
	 * Defaults to https://{account_id}.r2.cloudflarestorage.com.
	 *
	 * @return string Endpoint root URL.
	 */
	public function get_endpoint_base(): string {
		if ( '' !== $this->custom_endpoint ) {
			return rtrim( $this->custom_endpoint, '/' );
		}

		if ( '' === $this->account_id ) {
			throw new \RuntimeException( __( 'Cloudflare Account ID is not configured.', 'idta-r2-optimizer' ) );
		}

		return 'https://' . $this->account_id . '.r2.cloudflarestorage.com';
	}

	/**
	 * Construct a full URL for a bucket or object path with query arguments.
	 *
	 * @param string               $path  Relative path (e.g. 'bucket-name' or 'bucket-name/folder/file.jpg').
	 * @param array<string,string> $query Query arguments.
	 * @return string Full URL.
	 */
	public function build_url( string $path = '', array $query = array() ): string {
		$clean_path = '/' . ltrim( $path, '/' );
		$base_url   = $this->get_endpoint_base() . $clean_path;

		if ( empty( $query ) ) {
			return $base_url;
		}

		return add_query_arg( $query, $base_url );
	}

	/**
	 * Execute an authenticated HTTP request against Cloudflare R2.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $url     Target URL.
	 * @param array<string,string> $headers Custom request headers.
	 * @param string               $body    Request payload.
	 * @return array{status: int, headers: array, body: string} Response details.
	 *
	 * @throws \RuntimeException On network failure or HTTP error.
	 */
	protected function request(
		string $method,
		string $url,
		array $headers = array(),
		string $body = ''
	): array {
		if ( ! $this->has_credentials() ) {
			throw new \RuntimeException(
				__( 'Cloudflare R2 credentials (Account ID, Access Key ID, and Secret Access Key) are incomplete.', 'idta-r2-optimizer' )
			);
		}

		$signed_headers = $this->signer->sign_request( $method, $url, $headers, $body );

		$args = array(
			'method'      => strtoupper( $method ),
			'headers'     => $signed_headers,
			'body'        => '' !== $body ? $body : null,
			'timeout'     => self::REQUEST_TIMEOUT,
			'redirection' => 5,
			'sslverify'   => true,
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: Error message from WordPress HTTP API */
					__( 'R2 API connection failed: %s', 'idta-r2-optimizer' ),
					$response->get_error_message()
				)
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$res_headers = wp_remote_retrieve_headers( $response )->getAll();

		if ( $status >= 400 ) {
			$error_message = $this->parse_error_response( $body, $status );
			throw new \RuntimeException( $error_message, $status );
		}

		return array(
			'status'  => $status,
			'headers' => $res_headers,
			'body'    => $body,
		);
	}

	/**
	 * Parse an S3/R2 XML error response into a human-readable message.
	 *
	 * @param string $body   Response body string.
	 * @param int    $status HTTP status code.
	 * @return string Descriptive error message.
	 */
	private function parse_error_response( string $body, int $status ): string {
		if ( '' !== trim( $body ) && str_contains( $body, '<Error>' ) ) {
			$xml = @simplexml_load_string( $body );
			if ( false !== $xml ) {
				$code    = (string) ( $xml->Code ?? 'Error' );
				$message = (string) ( $xml->Message ?? '' );

				return match ( $code ) {
					'InvalidAccessKeyId' => __( 'Authentication failed: Invalid Cloudflare R2 Access Key ID.', 'idta-r2-optimizer' ),
					'SignatureDoesNotMatch' => __( 'Authentication failed: Secret Access Key signature mismatch.', 'idta-r2-optimizer' ),
					'AccessDenied' => __( 'Access Denied: The R2 API token does not have permission for this bucket or operation.', 'idta-r2-optimizer' ),
					'NoSuchBucket' => __( 'The specified Cloudflare R2 bucket does not exist.', 'idta-r2-optimizer' ),
					'NoSuchKey' => __( 'The specified object key was not found in R2.', 'idta-r2-optimizer' ),
					default => sprintf( 'R2 Error [%s]: %s (HTTP %d)', $code, $message ?: $body, $status ),
				};
			}
		}

		return match ( $status ) {
			401 => __( 'Unauthorized: Invalid R2 credentials.', 'idta-r2-optimizer' ),
			403 => __( 'Forbidden: R2 token lacks required permissions (read/write/list).', 'idta-r2-optimizer' ),
			404 => __( 'Endpoint or bucket not found. Please verify your Account ID and bucket name.', 'idta-r2-optimizer' ),
			default => sprintf( 'R2 HTTP Request Error (Status: %d)', $status ),
		};
	}

	/**
	 * List all buckets in the authenticated Cloudflare account.
	 *
	 * @return array<Bucket_Record> List of bucket records.
	 */
	public function list_buckets(): array {
		$url      = $this->build_url();
		$response = $this->request( 'GET', $url );

		$xml = @simplexml_load_string( $response['body'] );
		if ( false === $xml || ! isset( $xml->Buckets->Bucket ) ) {
			return array();
		}

		$buckets = array();
		foreach ( $xml->Buckets->Bucket as $bucket_node ) {
			$buckets[] = Bucket_Record::from_xml( $bucket_node );
		}

		return $buckets;
	}

	/**
	 * List objects and common prefixes inside an R2 bucket (ListObjectsV2).
	 *
	 * @param string      $bucket             Bucket name.
	 * @param string      $prefix             Key prefix filter (e.g. '2026/08/').
	 * @param string      $delimiter          Hierarchy delimiter (typically '/').
	 * @param string|null $continuation_token Token from a previous truncated call.
	 * @param int         $max_keys           Maximum keys to return (up to 1000).
	 * @return array{prefixes: string[], objects: array<int, array{key: string, size: int, etag: string, last_modified: string}>, is_truncated: bool, next_token: ?string}
	 */
	public function list_objects(
		string $bucket,
		string $prefix = '',
		string $delimiter = '/',
		?string $continuation_token = null,
		int $max_keys = 1000
	): array {
		$query = array(
			'list-type' => '2',
			'max-keys'  => (string) min( max( 1, $max_keys ), 1000 ),
		);

		if ( '' !== $prefix ) {
			$query['prefix'] = $prefix;
		}

		if ( '' !== $delimiter ) {
			$query['delimiter'] = $delimiter;
		}

		if ( ! empty( $continuation_token ) ) {
			$query['continuation-token'] = $continuation_token;
		}

		$url      = $this->build_url( $bucket, $query );
		$response = $this->request( 'GET', $url );

		$xml = @simplexml_load_string( $response['body'] );
		if ( false === $xml ) {
			throw new \RuntimeException( __( 'Failed to parse S3 ListObjectsV2 XML response from R2.', 'idta-r2-optimizer' ) );
		}

		$prefixes = array();
		if ( isset( $xml->CommonPrefixes ) ) {
			foreach ( $xml->CommonPrefixes as $common_prefix ) {
				$prefix_str = (string) ( $common_prefix->Prefix ?? '' );
				if ( '' !== $prefix_str ) {
					$prefixes[] = $prefix_str;
				}
			}
		}

		$objects = array();
		if ( isset( $xml->Contents ) ) {
			foreach ( $xml->Contents as $content ) {
				$objects[] = array(
					'key'           => (string) ( $content->Key ?? '' ),
					'size'          => (int) ( $content->Size ?? 0 ),
					'etag'          => trim( (string) ( $content->ETag ?? '' ), '"' ),
					'last_modified' => (string) ( $content->LastModified ?? '' ),
				);
			}
		}

		$is_truncated = strtolower( (string) ( $xml->IsTruncated ?? 'false' ) ) === 'true';
		$next_token   = $is_truncated ? (string) ( $xml->NextContinuationToken ?? null ) : null;

		return array(
			'prefixes'     => $prefixes,
			'objects'      => $objects,
			'is_truncated' => $is_truncated,
			'next_token'   => $next_token ?: null,
		);
	}

	/**
	 * Retrieve metadata for a single object in R2 (via HEAD request).
	 *
	 * @param string $bucket Bucket name.
	 * @param string $key    Object key.
	 * @return array{size: int, content_type: string, etag: string, last_modified: string}|null Headers or null if 404.
	 */
	public function get_object_metadata( string $bucket, string $key ): ?array {
		$url = $this->build_url( $bucket . '/' . ltrim( $key, '/' ) );

		try {
			$response = $this->request( 'HEAD', $url );
		} catch ( \RuntimeException $e ) {
			if ( 404 === $e->getCode() ) {
				return null;
			}
			throw $e;
		}

		$headers = $response['headers'];

		return array(
			'size'          => (int) ( $headers['content-length'] ?? 0 ),
			'content_type'  => (string) ( $headers['content-type'] ?? 'application/octet-stream' ),
			'etag'          => trim( (string) ( $headers['etag'] ?? '' ), '"' ),
			'last_modified' => (string) ( $headers['last-modified'] ?? '' ),
		);
	}

	/**
	 * Delete a single object key from R2.
	 *
	 * @param string $bucket Bucket name.
	 * @param string $key    Object key to delete.
	 * @return bool True if deleted successfully.
	 */
	public function delete_object( string $bucket, string $key ): bool {
		$url      = $this->build_url( $bucket . '/' . ltrim( $key, '/' ) );
		$response = $this->request( 'DELETE', $url );

		return $response['status'] >= 200 && $response['status'] < 300;
	}

	/**
	 * Delete up to 1000 objects in a single batch request (POST /?delete).
	 *
	 * @param string        $bucket Bucket name.
	 * @param array<string> $keys   Array of object keys to delete.
	 * @return array{deleted: string[], errors: array<int, array{key: string, code: string, message: string}>}
	 */
	public function delete_objects_batch( string $bucket, array $keys ): array {
		if ( empty( $keys ) ) {
			return array(
				'deleted' => array(),
				'errors'  => array(),
			);
		}

		// Ensure no more than 1000 keys per S3 multi-object delete batch.
		$keys = array_slice( array_values( array_unique( $keys ) ), 0, 1000 );

		$xml_doc = new \DOMDocument( '1.0', 'UTF-8' );
		$delete_node = $xml_doc->createElement( 'Delete' );
		$xml_doc->appendChild( $delete_node );

		$quiet_node = $xml_doc->createElement( 'Quiet', 'false' );
		$delete_node->appendChild( $quiet_node );

		foreach ( $keys as $key ) {
			$object_node = $xml_doc->createElement( 'Object' );
			$key_node    = $xml_doc->createElement( 'Key', htmlspecialchars( $key, ENT_XML1, 'UTF-8' ) );
			$object_node->appendChild( $key_node );
			$delete_node->appendChild( $object_node );
		}

		$body = (string) $xml_doc->saveXML();
		$url  = $this->build_url( $bucket, array( 'delete' => '' ) );

		$headers = array(
			'Content-Type' => 'application/xml',
		);

		$response = $this->request( 'POST', $url, $headers, $body );

		$res_xml = @simplexml_load_string( $response['body'] );
		if ( false === $res_xml ) {
			throw new \RuntimeException( __( 'Failed to parse S3 DeleteObjects response XML.', 'idta-r2-optimizer' ) );
		}

		$deleted = array();
		if ( isset( $res_xml->Deleted ) ) {
			foreach ( $res_xml->Deleted as $del_node ) {
				$deleted[] = (string) ( $del_node->Key ?? '' );
			}
		}

		$errors = array();
		if ( isset( $res_xml->Error ) ) {
			foreach ( $res_xml->Error as $err_node ) {
				$errors[] = array(
					'key'     => (string) ( $err_node->Key ?? '' ),
					'code'    => (string) ( $err_node->Code ?? 'Error' ),
					'message' => (string) ( $err_node->Message ?? '' ),
				);
			}
		}

		return array(
			'deleted' => $deleted,
			'errors'  => $errors,
		);
	}

	/**
	 * Copy or rename an object inside Cloudflare R2 using standard S3 PutObject copy.
	 *
	 * @param string $bucket     Bucket name.
	 * @param string $source_key Source key to copy from.
	 * @param string $dest_key   Destination key to create.
	 * @return bool True if copied successfully.
	 */
	public function copy_object( string $bucket, string $source_key, string $dest_key ): bool {
		$url = $this->build_url( $bucket . '/' . ltrim( $dest_key, '/' ) );

		// Standard S3 x-amz-copy-source format: /bucket-name/source-key
		$copy_source = '/' . $bucket . '/' . ltrim( $source_key, '/' );

		$headers = array(
			'x-amz-copy-source' => $copy_source,
		);

		$response = $this->request( 'PUT', $url, $headers );

		return $response['status'] >= 200 && $response['status'] < 300;
	}

	/**
	 * Test connection and validate credentials against Cloudflare R2.
	 *
	 * @return array{success: bool, message: string, buckets: string[]}
	 */
	public function test_connection(): array {
		if ( ! $this->has_credentials() ) {
			return array(
				'success' => false,
				'message' => __( 'Missing required credentials. Please provide Account ID, Access Key ID, and Secret Access Key.', 'idta-r2-optimizer' ),
				'buckets' => array(),
			);
		}

		try {
			$bucket_records = $this->list_buckets();
			$bucket_names   = array_map( static fn( Bucket_Record $b ) => $b->name, $bucket_records );

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: Number of buckets discovered */
					__( 'Connection successful! Discovered %d R2 bucket(s).', 'idta-r2-optimizer' ),
					count( $bucket_names )
				),
				'buckets' => $bucket_names,
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
				'buckets' => array(),
			);
		}
	}
}
