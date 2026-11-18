<?php
/**
 * Focused regression checks for security-sensitive plugin behavior.
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	private $code;
	private $message;

	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

class Tporapdi_Log_Repository {
	public static function insert( ...$args ): void {
	}
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function absint( $value ): int {
	return abs( (int) $value );
}

function __( string $message, string $domain = '' ): string {
	return $message;
}

function wp_salt( string $scheme = 'auth' ): string {
	return 'test-only-salt';
}

function tporapdi_validate_remote_endpoint_url( string $url ) {
	global $endpoint_validation_calls;

	$endpoint_validation_calls[] = $url;

	return new WP_Error( 'blocked_endpoint', 'Endpoint is not allowed.' );
}

function esc_url_raw( string $url ): string {
	return $url;
}

function sanitize_text_field( string $value ): string {
	return $value;
}

function wp_generate_uuid4(): string {
	return 'test-run-id';
}

function wp_json_encode( $value, int $flags = 0 ) {
	return json_encode( $value, $flags );
}

function tporapdi_get_attachment_id_by_source_url( string $url ): int {
	return 0;
}

function download_url( string $url ) {
	global $download_calls;

	++$download_calls;

	return new WP_Error( 'unexpected_download', 'The test must block before downloading.' );
}

require_once dirname( __DIR__ ) . '/includes/core.php';
require_once dirname( __DIR__ ) . '/includes/modules/class-tporapdi-media-ingestor.php';

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$no_openssl = isset( $argv[1] ) && '--no-openssl' === $argv[1];
check( $no_openssl === ! function_exists( 'openssl_encrypt' ), 'Test runtime does not match the requested OpenSSL mode.' );

$credential = tporapdi_encrypt_credential( 'test-secret' );
if ( $no_openssl ) {
	check( is_wp_error( $credential ), 'Credential encryption must fail closed without OpenSSL.' );
	check( 'tporapdi_credential_encryption_unavailable' === $credential->get_error_code(), 'Unexpected missing-OpenSSL error code.' );
} else {
	check( is_string( $credential ), 'Credential encryption unexpectedly failed.' );
	check( str_starts_with( $credential, TPORAPDI_ENCRYPTED_PREFIX ), 'Credential was not stored in encrypted form.' );
	check( 'test-secret' === tporapdi_decrypt_credential( $credential ), 'Credential encryption round-trip failed.' );
}

$download_calls           = 0;
$endpoint_validation_calls = array();
$result                   = Tporapdi_Media_Ingestor::sideload_image( 'https://unlisted.example/image.jpg', 7 );

check( false === $result, 'A media URL rejected by endpoint policy must not be sideloaded.' );
check( 1 === count( $endpoint_validation_calls ), 'The shared media path did not validate the endpoint.' );
check( 0 === $download_calls, 'Blocked media URLs must not reach download_url().' );

echo "Security regression checks passed.\n";
