<?php
/**
 * CSV and TSV payload parser for import extraction.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts delimited text payloads into flat import records.
 */
class TPORAPDI_CSV_Parser {
	/**
	 * Parses a CSV/TSV payload into header-keyed records.
	 *
	 * @param string $raw_payload   Raw CSV or TSV payload.
	 * @param string $csv_delimiter Optional delimiter override key.
	 *
	 * @return array<int, array<string, string>>|WP_Error
	 */
	public static function parse( string $raw_payload, string $csv_delimiter = '' ) {
		$records = array();
		$result  = self::for_each_record(
			$raw_payload,
			static function ( array $record ) use ( &$records ): bool {
				$records[] = $record;
				return true;
			},
			$csv_delimiter
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $records;
	}

	/**
	 * Walks CSV/TSV records one at a time without materializing the full record set.
	 *
	 * @param string   $raw_payload   Raw CSV or TSV payload.
	 * @param callable $callback      Callback invoked with each parsed record.
	 * @param string   $csv_delimiter Optional delimiter override key.
	 *
	 * @return array{row_count: int}|WP_Error
	 */
	public static function for_each_record( string $raw_payload, callable $callback, string $csv_delimiter = '' ) {
		if ( '' === trim( $raw_payload ) ) {
			return new WP_Error( 'tporapdi_empty_csv_payload', __( 'CSV payload is empty.', 'tporret-api-data-importer' ) );
		}

		$delimiter = self::resolve_delimiter( $raw_payload, $csv_delimiter );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp stream is used for fgetcsv parsing, not filesystem access.
		$handle = fopen( 'php://temp', 'r+' );

		if ( false === $handle ) {
			return new WP_Error( 'tporapdi_csv_stream_unavailable', __( 'Unable to open an in-memory CSV parser stream.', 'tporret-api-data-importer' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://temp stream is used for fgetcsv parsing, not filesystem access.
		fwrite( $handle, $raw_payload );
		rewind( $handle );

		$headers   = null;
		$row_count = 0;

		$row = fgetcsv( $handle, 0, $delimiter );
		while ( false !== $row ) {
			$row = self::trim_row_values( $row );

			if ( self::is_empty_row( $row ) ) {
				$row = fgetcsv( $handle, 0, $delimiter );
				continue;
			}

			if ( null === $headers ) {
				$headers = self::normalize_headers( $row );
				$row     = fgetcsv( $handle, 0, $delimiter );
				continue;
			}

			$callback_result = $callback( self::combine_row( $headers, $row ) );
			if ( is_wp_error( $callback_result ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://temp stream is used for fgetcsv parsing, not filesystem access.
				return $callback_result;
			}

			++$row_count;
			$row = fgetcsv( $handle, 0, $delimiter );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://temp stream is used for fgetcsv parsing, not filesystem access.

		if ( null === $headers ) {
			return new WP_Error( 'tporapdi_csv_headers_missing', __( 'CSV payload does not contain a header row.', 'tporret-api-data-importer' ) );
		}

		if ( 0 === $row_count ) {
			return new WP_Error( 'tporapdi_csv_records_missing', __( 'CSV payload does not contain any data rows.', 'tporret-api-data-importer' ) );
		}

		return array( 'row_count' => $row_count );
	}

	/**
	 * Resolves a configured delimiter key into the actual delimiter character.
	 *
	 * @param string $raw_payload   Raw CSV or TSV payload.
	 * @param string $csv_delimiter Optional delimiter override key.
	 *
	 * @return string
	 */
	private static function resolve_delimiter( string $raw_payload, string $csv_delimiter ): string {
		$delimiter_map = array(
			'comma'     => ',',
			'tab'       => "\t",
			'semicolon' => ';',
			'pipe'      => '|',
		);

		$csv_delimiter = sanitize_key( $csv_delimiter );
		if ( isset( $delimiter_map[ $csv_delimiter ] ) ) {
			return $delimiter_map[ $csv_delimiter ];
		}

		return self::detect_delimiter( $raw_payload );
	}

	/**
	 * Detects the most likely delimiter from the first non-empty rows.
	 *
	 * @param string $raw_payload Raw CSV or TSV payload.
	 *
	 * @return string
	 */
	private static function detect_delimiter( string $raw_payload ): string {
		$candidates = array( ',', "\t", ';', '|' );
		$lines      = preg_split( '/\R/', $raw_payload );
		$lines      = is_array( $lines ) ? array_values( array_filter( $lines, 'strlen' ) ) : array();
		$lines      = array_slice( $lines, 0, 10 );
		$best       = ',';
		$best_score = 0;

		foreach ( $candidates as $candidate ) {
			$score = 0;

			foreach ( $lines as $line ) {
				$columns = str_getcsv( $line, $candidate );
				$count   = is_array( $columns ) ? count( $columns ) : 0;

				if ( $count > 1 ) {
					$score += $count;
				}
			}

			if ( $score > $best_score ) {
				$best       = $candidate;
				$best_score = $score;
			}
		}

		return $best;
	}

	/**
	 * Trims all scalar row values and strips a UTF-8 BOM from the first column.
	 *
	 * @param array<int, string|null> $row CSV row.
	 *
	 * @return array<int, string>
	 */
	private static function trim_row_values( array $row ): array {
		$trimmed = array();

		foreach ( $row as $index => $value ) {
			$value = null === $value ? '' : trim( (string) $value );

			if ( 0 === $index ) {
				$value = preg_replace( '/^\xEF\xBB\xBF/', '', $value );
				$value = null === $value ? '' : $value;
			}

			$trimmed[] = $value;
		}

		return $trimmed;
	}

	/**
	 * Determines whether a parsed row has no meaningful values.
	 *
	 * @param array<int, string> $row CSV row.
	 *
	 * @return bool
	 */
	private static function is_empty_row( array $row ): bool {
		foreach ( $row as $value ) {
			if ( '' !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalizes header names and guarantees each key is unique.
	 *
	 * @param array<int, string> $headers Raw header row.
	 *
	 * @return array<int, string>
	 */
	private static function normalize_headers( array $headers ): array {
		$normalized = array();
		$seen       = array();

		foreach ( $headers as $index => $header ) {
			$key = trim( $header );

			if ( '' === $key ) {
				$key = 'column_' . ( $index + 1 );
			}

			$base_key = $key;
			$suffix   = 2;

			while ( isset( $seen[ $key ] ) ) {
				$key = $base_key . '_' . $suffix;
				++$suffix;
			}

			$seen[ $key ] = true;
			$normalized[] = $key;
		}

		return $normalized;
	}

	/**
	 * Combines one data row with the normalized headers.
	 *
	 * @param array<int, string> $headers Normalized header keys.
	 * @param array<int, string> $row     CSV row values.
	 *
	 * @return array<string, string>
	 */
	private static function combine_row( array $headers, array $row ): array {
		$record = array();

		foreach ( $headers as $index => $header ) {
			$record[ $header ] = $row[ $index ] ?? '';
		}

		return $record;
	}
}
