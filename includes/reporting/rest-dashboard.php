<?php
/**
 * REST endpoint for the reporting dashboard.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the dashboard REST route.
 */
function eai_register_dashboard_rest_route() {
	register_rest_route(
		'eapi/v1',
		'/dashboard',
		array(
			'methods'             => 'GET',
			'callback'            => 'eai_rest_dashboard_callback',
			'permission_callback' => static function () {
				return eai_current_user_can_manage_imports();
			},
			'args'                => array(
				'refresh' => array(
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		'eapi/v1',
		'/dashboard/history',
		array(
			'methods'             => 'GET',
			'callback'            => 'eai_rest_dashboard_history_callback',
			'permission_callback' => static function () {
				return eai_current_user_can_manage_imports();
			},
		)
	);
}
add_action( 'rest_api_init', 'eai_register_dashboard_rest_route' );

/**
 * Serve aggregated dashboard data.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function eai_rest_dashboard_callback( WP_REST_Request $request ): WP_REST_Response {
	if ( $request->get_param( 'refresh' ) ) {
		eai_flush_reporting_transients();
	}

	$aggregator = EAPI_Reporting_Aggregator::get_instance();
	$data       = $aggregator->get_dashboard_data();

	return new WP_REST_Response( $data, 200 );
}

/**
 * Serve recent log history for charts (sparklines, latency timeline, audit feed).
 *
 * @return WP_REST_Response
 */
function eai_rest_dashboard_history_callback(): WP_REST_Response {
	global $wpdb;

	$logs_table = eai_db_logs_table();

	$transient_key = 'eapi_dashboard_history';
	$cached        = get_transient( $transient_key );
	if ( false !== $cached ) {
		return new WP_REST_Response( $cached, 200 );
	}

	// Last 7 daily success rates for sparkline.
	$daily_rates = array();
	for ( $i = 6; $i >= 0; $i-- ) {
		$day_start = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$i} days" ) );
		$day_end   = gmdate( 'Y-m-d 23:59:59', strtotime( "-{$i} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$day_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS cnt FROM %i WHERE created_at BETWEEN %s AND %s AND status NOT IN (%s) GROUP BY status',
				$logs_table,
				$day_start,
				$day_end,
				'template_audit'
			)
		);

		$success = 0;
		$total   = 0;
		foreach ( $day_rows as $row ) {
			$cnt    = (int) $row->cnt;
			$total += $cnt;
			if ( in_array( $row->status, array( 'completed', 'success' ), true ) ) {
				$success += $cnt;
			}
		}

		$daily_rates[] = array(
			'date' => gmdate( 'M j', strtotime( "-{$i} days" ) ),
			'rate' => $total > 0 ? round( ( $success / $total ) * 100, 1 ) : null,
		);
	}

	// Last 20 latency data points.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$latency_rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT errors, created_at FROM %i WHERE status NOT IN (%s, %s) AND errors IS NOT NULL ORDER BY created_at DESC LIMIT 20',
			$logs_table,
			'template_audit',
			'no_data'
		)
	);

	$latency_points = array();
	foreach ( array_reverse( $latency_rows ) as $row ) {
		$details = json_decode( $row->errors, true );
		if ( ! is_array( $details ) || empty( $details['start_time'] ) || empty( $details['end_time'] ) ) {
			continue;
		}
		$start = strtotime( $details['start_time'] );
		$end   = strtotime( $details['end_time'] );
		if ( false === $start || false === $end || $end < $start ) {
			continue;
		}
		$latency_points[] = array(
			'time'    => gmdate( 'M j H:i', strtotime( $row->created_at ) ),
			'seconds' => $end - $start,
		);
	}

	// Last 5 audit entries for the footer marquee.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$audit_rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT errors, created_at FROM %i WHERE status = %s ORDER BY created_at DESC LIMIT 5',
			$logs_table,
			'template_audit'
		)
	);

	$audit_entries = array();
	foreach ( $audit_rows as $row ) {
		$details = json_decode( $row->errors, true );
		$audit_entries[] = array(
			'time'   => gmdate( 'M j, g:ia', strtotime( $row->created_at ) ),
			'type'   => isset( $details['audit_type'] ) ? $details['audit_type'] : 'unknown',
			'user'   => isset( $details['actor_user_login'] ) ? $details['actor_user_login'] : 'system',
			'import' => isset( $details['template_import_id'] ) ? (int) $details['template_import_id'] : 0,
		);
	}

	// Last 7 throughput data points (daily).
	$throughput_points = array();
	for ( $i = 6; $i >= 0; $i-- ) {
		$day_start = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$i} days" ) );
		$day_end   = gmdate( 'Y-m-d 23:59:59', strtotime( "-{$i} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sum = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE( SUM( rows_processed ), 0 ) FROM %i WHERE created_at BETWEEN %s AND %s AND status NOT IN (%s)',
				$logs_table,
				$day_start,
				$day_end,
				'template_audit'
			)
		);

		$throughput_points[] = array(
			'date'  => gmdate( 'M j', strtotime( "-{$i} days" ) ),
			'rows'  => $sum,
		);
	}

	$history = array(
		'daily_rates'       => $daily_rates,
		'latency_points'    => $latency_points,
		'audit_entries'     => $audit_entries,
		'throughput_points' => $throughput_points,
	);

	set_transient( $transient_key, $history, 600 );

	return new WP_REST_Response( $history, 200 );
}

/**
 * Flush all reporting transients for a forced refresh.
 */
function eai_flush_reporting_transients(): void {
	$reporter_ids = array(
		'cron_heartbeat',
		'queue_depth',
		'daily_success_rate',
		'ssrf_hardening',
		'audit_integrity',
		'protocol_enforcement',
		'api_latency',
		'active_connections',
		'throughput',
	);

	foreach ( $reporter_ids as $id ) {
		delete_transient( 'eapi_report_' . $id );
	}

	delete_transient( 'eapi_dashboard_history' );
}
