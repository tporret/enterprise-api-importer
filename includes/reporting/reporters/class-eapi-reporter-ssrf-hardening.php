<?php
/**
 * Reporter: SSRF Hardening — checks endpoint allowlist configuration.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAPI_Reporter_SSRF_Hardening extends EAPI_Reporter_Base {

	protected string $id       = 'ssrf_hardening';
	protected string $category = 'Security';
	protected string $label    = 'SSRF Hardening';

	protected function calculate_metrics(): array {
		$settings       = get_option( 'eai_settings', array() );
		$allowed_hosts  = isset( $settings['allowed_endpoint_hosts'] ) ? trim( (string) $settings['allowed_endpoint_hosts'] ) : '';
		$allowed_cidrs  = isset( $settings['allowed_endpoint_cidrs'] ) ? trim( (string) $settings['allowed_endpoint_cidrs'] ) : '';
		$is_restricted  = '' !== $allowed_hosts || '' !== $allowed_cidrs;

		if ( $is_restricted ) {
			return array(
				'status' => 'green',
				'value'  => 'Restricted',
				'detail' => 'Endpoint allowlist is configured.',
			);
		}

		return array(
			'status' => 'red',
			'value'  => 'Open',
			'detail' => 'No endpoint host or CIDR restrictions are configured. Any remote URL can be fetched.',
		);
	}
}
