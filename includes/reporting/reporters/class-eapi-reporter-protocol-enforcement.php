<?php
/**
 * Reporter: Protocol Enforcement — HTTPS vs HTTP ratio in configured endpoints.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAPI_Reporter_Protocol_Enforcement extends EAPI_Reporter_Base {

	protected string $id       = 'protocol_enforcement';
	protected string $category = 'Security';
	protected string $label    = 'Protocol Enforcement';

	protected function calculate_metrics(): array {
		global $wpdb;

		$imports_table = eai_db_imports_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$urls = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT endpoint_url FROM %i',
				$imports_table
			)
		);

		if ( empty( $urls ) ) {
			return array(
				'status' => 'green',
				'value'  => 'No Data',
				'detail' => 'No import endpoints configured.',
			);
		}

		$https_count = 0;
		$http_count  = 0;

		foreach ( $urls as $url ) {
			if ( str_starts_with( strtolower( $url ), 'https://' ) ) {
				++$https_count;
			} else {
				++$http_count;
			}
		}

		$total  = count( $urls );
		$status = 0 === $http_count ? 'green' : 'red';
		$ratio  = $this->format_percentage( $https_count, $total );

		return array(
			'status' => $status,
			'value'  => $ratio . ' HTTPS',
			'detail' => sprintf(
				'%d HTTPS, %d HTTP out of %d endpoint(s).',
				$https_count,
				$http_count,
				$total
			),
		);
	}
}
