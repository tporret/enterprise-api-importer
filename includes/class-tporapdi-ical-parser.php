<?php
/**
 * ICal payload parser for import extraction.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts RFC 5545 calendar payloads into flat import records.
 */
class TPORAPDI_Ical_Parser {
	/**
	 * Parses and expands iCal events into normalized import records.
	 *
	 * @param string $raw_ics_payload    Raw iCal payload.
	 * @param string $start_date_modifier Relative extraction window start.
	 * @param string $end_date_modifier   Relative extraction window end.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function parse_and_expand( string $raw_ics_payload, string $start_date_modifier = '-1 month', string $end_date_modifier = '+1 year' ) {
		if ( '' === trim( $raw_ics_payload ) ) {
			return new WP_Error( 'tporapdi_empty_ical_payload', __( 'iCal payload is empty.', 'tporret-api-data-importer' ) );
		}

		$timezone = new DateTimeZone( 'UTC' );
		$window   = self::resolve_expansion_window( $start_date_modifier, $end_date_modifier, $timezone );

		if ( is_wp_error( $window ) ) {
			return $window;
		}

		try {
			$vcalendar = Sabre\VObject\Reader::read( $raw_ics_payload );
			$expanded  = $vcalendar->expand( $window['start'], $window['end'], $timezone );
		} catch ( Sabre\VObject\ParseException $e ) {
			return new WP_Error(
				'tporapdi_invalid_ical',
				sprintf(
					/* translators: %s is the iCal parser error message. */
					__( 'Unable to parse iCal payload: %s', 'tporret-api-data-importer' ),
					$e->getMessage()
				)
			);
		} catch ( Throwable $e ) {
			return new WP_Error(
				'tporapdi_ical_expand_failed',
				sprintf(
					/* translators: %s is the iCal expansion error message. */
					__( 'Unable to expand iCal events: %s', 'tporret-api-data-importer' ),
					$e->getMessage()
				)
			);
		}

		$records = array();

		foreach ( $expanded->children() as $component ) {
			if ( ! $component instanceof Sabre\VObject\Component || 'VEVENT' !== $component->name ) {
				continue;
			}

			$record = self::normalize_event( $component, $timezone );
			if ( is_wp_error( $record ) ) {
				return $record;
			}

			$records[] = $record;
		}

		return $records;
	}

	/**
	 * Resolves expansion window bounds from relative modifiers.
	 *
	 * @param string       $start_date_modifier Relative start modifier.
	 * @param string       $end_date_modifier   Relative end modifier.
	 * @param DateTimeZone $timezone            Window timezone.
	 *
	 * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|WP_Error
	 */
	private static function resolve_expansion_window( string $start_date_modifier, string $end_date_modifier, DateTimeZone $timezone ) {
		$now   = new DateTimeImmutable( 'now', $timezone );
		$start = $now->modify( $start_date_modifier );
		$end   = $now->modify( $end_date_modifier );

		if ( false === $start || false === $end ) {
			return new WP_Error( 'tporapdi_invalid_ical_window', __( 'Invalid iCal recurrence expansion window.', 'tporret-api-data-importer' ) );
		}

		if ( $start >= $end ) {
			return new WP_Error( 'tporapdi_invalid_ical_window', __( 'iCal recurrence expansion start must be before the end date.', 'tporret-api-data-importer' ) );
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Converts one expanded VEVENT component into a flat import record.
	 *
	 * @param Sabre\VObject\Component $event    Expanded VEVENT.
	 * @param DateTimeZone            $timezone Output timezone.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function normalize_event( Sabre\VObject\Component $event, DateTimeZone $timezone ) {
		$dtstart = self::get_property( $event, 'DTSTART' );

		if ( null === $dtstart ) {
			return new WP_Error( 'tporapdi_ical_event_missing_start', __( 'iCal event is missing DTSTART.', 'tporret-api-data-importer' ) );
		}

		$uid        = self::get_property_value( $event, 'UID' );
		$start      = $dtstart->getDateTime( $timezone );
		$end        = self::get_event_end( $event, $start, $timezone );
		$is_all_day = ! $dtstart->hasTime();

		if ( '' === $uid ) {
			$uid = md5( $start->format( DATE_ATOM ) . '|' . self::get_property_value( $event, 'SUMMARY' ) );
		}

		return array(
			'uid'          => $uid,
			'instance_uid' => $uid . '|' . $start->format( 'Ymd\THisP' ),
			'title'        => self::get_property_value( $event, 'SUMMARY' ),
			'description'  => self::get_property_value( $event, 'DESCRIPTION' ),
			'location'     => self::get_property_value( $event, 'LOCATION' ),
			'start_date'   => $start->format( DATE_ATOM ),
			'end_date'     => $end->format( DATE_ATOM ),
			'is_all_day'   => $is_all_day,
		);
	}

	/**
	 * Determines an event end date using DTEND, DURATION, or RFC defaults.
	 *
	 * @param Sabre\VObject\Component $event    Expanded VEVENT.
	 * @param DateTimeImmutable       $start    Event start.
	 * @param DateTimeZone            $timezone Output timezone.
	 *
	 * @return DateTimeImmutable
	 */
	private static function get_event_end( Sabre\VObject\Component $event, DateTimeImmutable $start, DateTimeZone $timezone ): DateTimeImmutable {
		$dtend = self::get_property( $event, 'DTEND' );
		if ( null !== $dtend ) {
			return $dtend->getDateTime( $timezone );
		}

		$duration = self::get_property( $event, 'DURATION' );
		if ( null !== $duration ) {
			return $start->add( Sabre\VObject\DateTimeParser::parseDuration( (string) $duration ) );
		}

		$dtstart = self::get_property( $event, 'DTSTART' );
		if ( null !== $dtstart && ! $dtstart->hasTime() ) {
			return $start->modify( '+1 day' );
		}

		return $start;
	}

	/**
	 * Reads a scalar iCal property as a string.
	 *
	 * @param Sabre\VObject\Component $event Event component.
	 * @param string                  $name  Property name.
	 *
	 * @return string
	 */
	private static function get_property_value( Sabre\VObject\Component $event, string $name ): string {
		$property = self::get_property( $event, $name );

		if ( null === $property ) {
			return '';
		}

		return trim( (string) $property );
	}

	/**
	 * Gets the first property matching a name from a VObject component.
	 *
	 * @param Sabre\VObject\Component $event Event component.
	 * @param string                  $name  Property name.
	 *
	 * @return Sabre\VObject\Property|null
	 */
	private static function get_property( Sabre\VObject\Component $event, string $name ): ?Sabre\VObject\Property {
		$properties = $event->select( $name );

		if ( empty( $properties ) ) {
			return null;
		}

		$property = reset( $properties );

		return $property instanceof Sabre\VObject\Property ? $property : null;
	}
}
