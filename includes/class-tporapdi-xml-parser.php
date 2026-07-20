<?php
/**
 * XML and RSS payload parser for import extraction.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts matched XML elements into import records.
 */
class TPORAPDI_XML_Parser {
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native XMLReader and DOM extension properties use camelCase names.

	/**
	 * Parses XML and extracts records from matching element nodes.
	 *
	 * @param string $raw_payload      Raw XML payload.
	 * @param string $xml_node_element Repeating element name to extract.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function parse( string $raw_payload, string $xml_node_element ) {
		$records = array();
		$result  = self::for_each_record(
			$raw_payload,
			static function ( array $record ) use ( &$records ): bool {
				$records[] = $record;
				return true;
			},
			$xml_node_element
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $records;
	}

	/**
	 * Walks matching XML records one at a time.
	 *
	 * @param string   $raw_payload      Raw XML payload.
	 * @param callable $callback         Callback invoked with each parsed record.
	 * @param string   $xml_node_element Repeating element name to extract.
	 *
	 * @return array{row_count: int}|WP_Error
	 */
	public static function for_each_record( string $raw_payload, callable $callback, string $xml_node_element ) {
		$xml_node_element = trim( $xml_node_element );

		if ( '' === trim( $raw_payload ) ) {
			return new WP_Error( 'tporapdi_empty_xml_payload', __( 'XML payload is empty.', 'tporret-api-data-importer' ) );
		}

		if ( '' === $xml_node_element ) {
			return new WP_Error( 'tporapdi_xml_node_missing', __( 'XML Node Element is required for XML/RSS payloads.', 'tporret-api-data-importer' ) );
		}

		if ( ! class_exists( 'XMLReader' ) ) {
			return new WP_Error( 'tporapdi_xmlreader_unavailable', __( 'XMLReader is unavailable on this server.', 'tporret-api-data-importer' ) );
		}

		$reader = new XMLReader();
		$opened = $reader->XML( $raw_payload, null, LIBXML_NONET | LIBXML_COMPACT );

		if ( ! $opened ) {
			return new WP_Error( 'tporapdi_invalid_xml', __( 'Unable to open XML payload for parsing.', 'tporret-api-data-importer' ) );
		}

		$row_count = 0;

		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType || ! self::matches_node_name( $reader, $xml_node_element ) ) {
				continue;
			}

			$node = $reader->expand();
			if ( ! $node instanceof DOMNode ) {
				continue;
			}

			$record = self::element_to_value( $node );
			if ( ! is_array( $record ) ) {
				$record = array( 'value' => $record );
			}

			++$row_count;

			$callback_result = $callback( $record );
			if ( is_wp_error( $callback_result ) ) {
				$reader->close();
				return $callback_result;
			}

			if ( false === $callback_result ) {
				$reader->close();
				return array( 'row_count' => $row_count );
			}
		}

		$reader->close();

		if ( 0 === $row_count ) {
			return new WP_Error(
				'tporapdi_xml_records_missing',
				sprintf(
					/* translators: %s is the XML element name configured for extraction. */
					__( 'XML payload does not contain any <%s> records.', 'tporret-api-data-importer' ),
					$xml_node_element
				)
			);
		}

		return array( 'row_count' => $row_count );
	}

	/**
	 * Determines whether the reader is positioned on the configured node name.
	 *
	 * @param XMLReader $reader           XML stream reader.
	 * @param string    $xml_node_element Configured element name.
	 *
	 * @return bool
	 */
	private static function matches_node_name( XMLReader $reader, string $xml_node_element ): bool {
		return $reader->localName === $xml_node_element || $reader->name === $xml_node_element;
	}

	/**
	 * Converts a DOM node subtree into arrays and scalar strings.
	 *
	 * @param DOMNode $node XML node.
	 *
	 * @return array<string, mixed>|string
	 */
	private static function element_to_value( DOMNode $node ) {
		$record       = array();
		$text_content = '';

		if ( $node instanceof DOMElement && $node->hasAttributes() ) {
			$attributes = array();

			foreach ( $node->attributes as $attribute ) {
				$attributes[ $attribute->nodeName ] = $attribute->nodeValue;
			}

			if ( ! empty( $attributes ) ) {
				$record['@attributes'] = $attributes;
			}
		}

		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType || XML_CDATA_SECTION_NODE === $child->nodeType ) {
				$text_content .= $child->nodeValue;
				continue;
			}

			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			$key   = '' !== $child->localName ? $child->localName : $child->nodeName;
			$value = self::element_to_value( $child );

			if ( array_key_exists( $key, $record ) ) {
				if ( ! is_array( $record[ $key ] ) || ! self::is_list( $record[ $key ] ) ) {
					$record[ $key ] = array( $record[ $key ] );
				}

				$record[ $key ][] = $value;
				continue;
			}

			$record[ $key ] = $value;
		}

		$text_content = trim( preg_replace( '/\s+/', ' ', $text_content ) ?? '' );
		if ( empty( $record ) ) {
			return $text_content;
		}

		if ( '' !== $text_content ) {
			$record['@value'] = $text_content;
		}

		return $record;
	}

	/**
	 * Checks whether an array uses sequential integer keys.
	 *
	 * @param array<mixed> $value Value to check.
	 *
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}
