<?php
/**
 * Reporter: Queue Depth — unprocessed rows in the staging table.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EAPI_Reporter_Queue_Depth extends EAPI_Reporter_Base {

	protected string $id       = 'queue_depth';
	protected string $category = 'Health';
	protected string $label    = 'Queue Depth';

	protected function calculate_metrics(): array {
		global $wpdb;

		$temp_table = eai_db_temp_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE is_processed = 0',
				$temp_table
			)
		);

		$status = $this->get_status_color(
			$count,
			array(
				'green'  => 50,
				'yellow' => 200,
			)
		);

		return array(
			'status' => $status,
			'value'  => number_format_i18n( $count ),
			'detail' => $count . ' unprocessed row(s) in the staging queue.',
		);
	}
}
