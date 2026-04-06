<?php
/**
 * Reporter: Active Connections — count of unique API endpoints.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAPI_Reporter_Active_Connections extends EAPI_Reporter_Base {

	protected string $id       = 'active_connections';
	protected string $category = 'Performance';
	protected string $label    = 'Active Connections';

	protected function calculate_metrics(): array {
		global $wpdb;

		$imports_table = eai_db_imports_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT( DISTINCT endpoint_url ) FROM %i',
				$imports_table
			)
		);

		return array(
			'status' => 'green',
			'value'  => number_format_i18n( $count ),
			'detail' => $count . ' unique API endpoint(s) configured.',
		);
	}
}
