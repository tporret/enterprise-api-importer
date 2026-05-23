<?php
/**
 * Media Ingestor – sideload and deduplication for import media.
 *
 * Single responsibility: download remote images into the media library,
 * deduplicate by source URL, rewrite HTML content image sources in-place.
 * All callers ask "ingest this image" – no SQL, no DOM parsing leaks out.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encapsulates every media-ingestion concern for the import pipeline.
 *
 * Idempotency guarantee: if an attachment already exists with a matching
 * `_tporapdi_source_url` meta value, the existing ID is returned immediately
 * and no re-download occurs.
 */
class Tporapdi_Media_Ingestor {

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Downloads and sideloads one remote image into the media library.
	 *
	 * @param mixed $image_url   Absolute remote image URL.
	 * @param mixed $post_id     Parent post ID.
	 * @param mixed $is_featured Whether to assign the image as the post featured image.
	 *
	 * @return int|false Attachment ID on success, false on failure.
	 */
	public static function sideload_image( $image_url, $post_id, $is_featured = false ) {
		$image_url   = is_string( $image_url ) ? trim( $image_url ) : '';
		$post_id     = absint( $post_id );
		$is_featured = (bool) $is_featured;

		if ( '' === $image_url || $post_id <= 0 || ! wp_http_validate_url( $image_url ) ) {
			self::log_media_error(
				'Invalid Media URL',
				$image_url,
				$post_id,
				__( 'Invalid media URL or post ID supplied for sideload.', 'tporret-api-data-importer' )
			);

			return false;
		}

		$source_url = esc_url_raw( $image_url );

		// Deduplication: return existing attachment if source URL already ingested.
		$existing = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Attachment dedupe is keyed by plugin-owned source URL meta.
					array(
						'key'     => '_tporapdi_source_url',
						'value'   => $source_url,
						'compare' => '=',
					),
				),
			)
		);

		if ( ! empty( $existing->posts ) ) {
			$existing_attachment_id = (int) $existing->posts[0];
			if ( $is_featured ) {
				set_post_thumbnail( $post_id, $existing_attachment_id );
			}

			return $existing_attachment_id;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temp_file = download_url( $source_url );

		if ( is_wp_error( $temp_file ) ) {
			self::log_media_error(
				'Media Download Error',
				$source_url,
				$post_id,
				$temp_file->get_error_message()
			);

			return false;
		}

		$url_path  = wp_parse_url( $source_url, PHP_URL_PATH );
		$file_name = is_string( $url_path ) ? basename( $url_path ) : '';

		if ( '' === $file_name ) {
			$file_name = 'eapi-image-' . wp_generate_password( 12, false ) . '.jpg';
		}

		$file_array = array(
			'name'     => sanitize_file_name( rawurldecode( $file_name ) ),
			'tmp_name' => $temp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			if ( isset( $file_array['tmp_name'] ) && is_string( $file_array['tmp_name'] ) ) {
				wp_delete_file( $file_array['tmp_name'] );
			}

			self::log_media_error(
				'Media Sideload Error',
				$source_url,
				$post_id,
				$attachment_id->get_error_message()
			);

			return false;
		}

		$attachment_id = (int) $attachment_id;
		update_post_meta( $attachment_id, '_tporapdi_source_url', $source_url );

		if ( $is_featured ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		return $attachment_id;
	}

	/**
	 * Applies configured media mappings to one imported post.
	 *
	 * @param array<string, mixed> $item       Item payload.
	 * @param mixed                $post_id    Target post ID.
	 * @param mixed                $import_id  Import job ID.
	 * @param mixed                $mappings   Media mapping definitions.
	 *
	 * @return bool True when mapped media changed post/attachment state.
	 */
	public static function apply_media_mappings( array $item, $post_id, $import_id, $mappings ): bool {
		$post_id   = absint( $post_id );
		$import_id = absint( $import_id );

		if ( $post_id <= 0 || empty( $mappings ) || ! is_array( $mappings ) ) {
			return false;
		}

		if ( isset( $mappings['role'] ) ) {
			$mappings = array( $mappings );
		}

		$changed = false;

		foreach ( $mappings as $mapping ) {
			if ( ! is_array( $mapping ) ) {
				continue;
			}

			$role = isset( $mapping['role'] ) ? sanitize_key( (string) $mapping['role'] ) : '';
			if ( ! in_array( $role, array( 'featured', 'gallery', 'meta' ), true ) ) {
				continue;
			}

			$ingested       = self::ingest_mapping_attachments( $item, $post_id, $import_id, $mapping, false );
			$attachment_ids = isset( $ingested['attachment_ids'] ) && is_array( $ingested['attachment_ids'] ) ? $ingested['attachment_ids'] : array();
			$changed        = $changed || ! empty( $ingested['changed'] );
			if ( empty( $attachment_ids ) ) {
				continue;
			}

			if ( 'featured' === $role ) {
				$current_thumbnail_id = (int) get_post_thumbnail_id( $post_id );
				$attachment_id        = absint( $attachment_ids[0] );

				if ( $attachment_id > 0 && $current_thumbnail_id !== $attachment_id ) {
					set_post_thumbnail( $post_id, $attachment_id );
					$changed = true;
				}

				continue;
			}

			$meta_key = isset( $mapping['meta_key'] ) ? sanitize_key( (string) $mapping['meta_key'] ) : '';
			if ( '' === $meta_key && 'gallery' === $role ) {
				$meta_key = '_tporapdi_gallery_attachment_ids';
			}

			if ( '' === $meta_key ) {
				continue;
			}

			$attachment_ids = array_values( array_unique( array_map( 'absint', $attachment_ids ) ) );
			$previous_ids   = get_post_meta( $post_id, $meta_key, true );
			$previous_ids   = is_array( $previous_ids ) ? array_values( array_map( 'absint', $previous_ids ) ) : array();

			if ( $previous_ids !== $attachment_ids ) {
				update_post_meta( $post_id, $meta_key, $attachment_ids );
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * Parses rendered HTML, sideloads external images, and rewrites img src attributes.
	 *
	 * External images whose src does not belong to the current site host are downloaded
	 * and replaced with local attachment URLs. The returned string is safe to store as
	 * post_content.
	 *
	 * @param mixed $html_content Rendered HTML that may contain external IMG tags.
	 * @param mixed $post_id      Target post ID used as attachment parent.
	 *
	 * @return string Updated HTML with local image URLs where ingestion succeeded.
	 */
	public static function parse_and_sideload_content_images( $html_content, $post_id ) {
		$html_content = is_string( $html_content ) ? $html_content : '';
		$post_id      = absint( $post_id );

		if ( '' === $html_content || $post_id <= 0 || ! class_exists( 'DOMDocument' ) ) {
			return $html_content;
		}

		$dom               = new DOMDocument();
		$previous_internal = libxml_use_internal_errors( true );
		$wrapped_html      = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html_content . '</body></html>';
		$loaded            = $dom->loadHTML( $wrapped_html, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED );

		if ( false === $loaded ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_internal );

			return $html_content;
		}

		$site_host = wp_parse_url( site_url(), PHP_URL_HOST );
		$site_host = is_string( $site_host ) ? strtolower( $site_host ) : '';
		$images    = $dom->getElementsByTagName( 'img' );

		foreach ( $images as $image_node ) {
			if ( ! $image_node instanceof DOMElement ) {
				continue;
			}

			$src = trim( (string) $image_node->getAttribute( 'src' ) );

			if ( '' === $src ) {
				continue;
			}

			$src_host = wp_parse_url( $src, PHP_URL_HOST );
			$src_host = is_string( $src_host ) ? strtolower( $src_host ) : '';

			// Skip relative URLs and images already hosted locally.
			if ( '' === $src_host || ( '' !== $site_host && $src_host === $site_host ) ) {
				continue;
			}

			$attachment_id = self::sideload_image( $src, $post_id );

			if ( false === $attachment_id ) {
				continue;
			}

			$new_src = wp_get_attachment_url( absint( $attachment_id ) );

			if ( ! is_string( $new_src ) || '' === $new_src ) {
				continue;
			}

			$image_node->setAttribute( 'src', esc_url_raw( $new_src ) );

			// Add wp-image-{id} CSS class for block editor compatibility.
			$class_attr = trim( (string) $image_node->getAttribute( 'class' ) );
			$classes    = '' === $class_attr ? array() : preg_split( '/\s+/', $class_attr );

			if ( ! is_array( $classes ) ) {
				$classes = array();
			}

			$classes  = array_filter( $classes, 'is_string' );
			$wp_class = 'wp-image-' . absint( $attachment_id );

			if ( ! in_array( $wp_class, $classes, true ) ) {
				$classes[] = $wp_class;
			}

			$image_node->setAttribute( 'class', implode( ' ', $classes ) );
		}

		$rewritten = $dom->saveHTML();
		$rewritten = is_string( $rewritten ) ? $rewritten : $html_content;
		$rewritten = preg_replace( '/\A\s*<!DOCTYPE[^>]*>\s*/i', '', $rewritten );
		$rewritten = is_string( $rewritten ) ? $rewritten : $html_content;
		$rewritten = preg_replace( '/\A\s*<html[^>]*>\s*<head>.*?<\/head>\s*<body[^>]*>/is', '', $rewritten );
		$rewritten = is_string( $rewritten ) ? $rewritten : $html_content;
		$rewritten = preg_replace( '/<\/body>\s*<\/html>\s*\z/is', '', $rewritten );
		$rewritten = is_string( $rewritten ) ? $rewritten : $html_content;

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_internal );

		return $rewritten;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Ingests all attachments for one mapping row.
	 *
	 * @param array<string, mixed> $item        Item payload.
	 * @param int                  $post_id     Target post ID.
	 * @param int                  $import_id   Import job ID.
	 * @param array<string, mixed> $mapping     Media mapping row.
	 * @param bool                 $is_featured Whether this mapping controls the post thumbnail.
	 *
	 * @return array{attachment_ids: array<int, int>, changed: bool} Attachment ingest result.
	 */
	private static function ingest_mapping_attachments( array $item, int $post_id, int $import_id, array $mapping, bool $is_featured ): array {
		$sources        = self::resolve_media_sources( $item, $mapping );
		$attachment_ids = array();
		$changed        = false;

		foreach ( $sources as $source ) {
			$image_url = isset( $source['url'] ) ? esc_url_raw( (string) $source['url'] ) : '';
			if ( '' === $image_url || is_wp_error( tporapdi_validate_remote_endpoint_url( $image_url ) ) ) {
				continue;
			}

			$attachment_id = self::sideload_image( $image_url, $post_id, $is_featured );
			if ( false === $attachment_id ) {
				continue;
			}

			$attachment_id    = absint( $attachment_id );
			$attachment_ids[] = $attachment_id;

			$context = isset( $source['context'] ) && is_array( $source['context'] ) ? $source['context'] : $item;
			$changed = self::apply_attachment_metadata( $attachment_id, $context, $item, $mapping, $import_id ) || $changed;

			if ( $is_featured ) {
				break;
			}
		}

		return array(
			'attachment_ids' => $attachment_ids,
			'changed'        => $changed,
		);
	}

	/**
	 * Resolves source URLs and metadata contexts for one mapping row.
	 *
	 * @param array<string, mixed> $item    Item payload.
	 * @param array<string, mixed> $mapping Media mapping row.
	 *
	 * @return array<int, array{url: string, context: array<string, mixed>}>
	 */
	private static function resolve_media_sources( array $item, array $mapping ): array {
		$source_path = isset( $mapping['source_path'] ) ? trim( (string) $mapping['source_path'] ) : '';
		if ( '' === $source_path ) {
			return array();
		}

		$source_value = tporapdi_get_item_value_by_path( $item, $source_path );
		if ( is_scalar( $source_value ) ) {
			$url = trim( (string) $source_value );
			return '' === $url ? array() : array(
				array(
					'url'     => $url,
					'context' => $item,
				),
			);
		}

		if ( ! is_array( $source_value ) ) {
			return array();
		}

		$sources  = array();
		$url_path = isset( $mapping['url_path'] ) ? trim( (string) $mapping['url_path'] ) : '';

		if ( self::array_is_list( $source_value ) ) {
			foreach ( $source_value as $candidate ) {
				if ( is_scalar( $candidate ) ) {
					$url = trim( (string) $candidate );
					if ( '' !== $url ) {
						$sources[] = array(
							'url'     => $url,
							'context' => $item,
						);
					}
					continue;
				}

				if ( ! is_array( $candidate ) ) {
					continue;
				}

				$url = self::resolve_url_from_context( $candidate, $url_path );
				if ( '' !== $url ) {
					$sources[] = array(
						'url'     => $url,
						'context' => $candidate,
					);
				}
			}

			return $sources;
		}

		$url = self::resolve_url_from_context( $source_value, $url_path );
		if ( '' !== $url ) {
			$sources[] = array(
				'url'     => $url,
				'context' => $source_value,
			);
		}

		return $sources;
	}

	/**
	 * Resolves a URL from a media object context.
	 *
	 * @param array<string, mixed> $context  Media object context.
	 * @param string               $url_path Optional URL path inside the context.
	 *
	 * @return string
	 */
	private static function resolve_url_from_context( array $context, string $url_path ): string {
		$url = '';

		if ( '' !== $url_path ) {
			$value = tporapdi_get_item_value_by_path( $context, $url_path );
			$url   = is_scalar( $value ) ? trim( (string) $value ) : '';
		}

		if ( '' === $url && isset( $context['url'] ) && is_scalar( $context['url'] ) ) {
			$url = trim( (string) $context['url'] );
		}

		return $url;
	}

	/**
	 * Applies mapped attachment metadata.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $context       Mapping-local source context.
	 * @param array<string, mixed> $item          Full item payload.
	 * @param array<string, mixed> $mapping       Media mapping row.
	 * @param int                  $import_id     Import job ID.
	 *
	 * @return bool True when attachment metadata changed.
	 */
	private static function apply_attachment_metadata( int $attachment_id, array $context, array $item, array $mapping, int $import_id ): bool {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return false;
		}

		$changed = false;
		$alt     = self::resolve_metadata_value( $context, $item, $mapping['alt_path'] ?? '' );

		if ( '' !== $alt && (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			$changed = true;
		}

		$attachment_update = array( 'ID' => $attachment_id );
		$attachment        = get_post( $attachment_id );
		$title             = self::resolve_metadata_value( $context, $item, $mapping['title_path'] ?? '' );
		$caption           = self::resolve_metadata_value( $context, $item, $mapping['caption_path'] ?? '' );
		$description       = self::resolve_metadata_value( $context, $item, $mapping['description_path'] ?? '' );

		if ( $attachment instanceof WP_Post && '' !== $title ) {
			$sanitized_title = sanitize_text_field( $title );
			if ( (string) $attachment->post_title !== $sanitized_title ) {
				$attachment_update['post_title'] = $sanitized_title;
			}
		}

		if ( $attachment instanceof WP_Post && '' !== $caption ) {
			$sanitized_caption = sanitize_text_field( $caption );
			if ( (string) $attachment->post_excerpt !== $sanitized_caption ) {
				$attachment_update['post_excerpt'] = $sanitized_caption;
			}
		}

		if ( $attachment instanceof WP_Post && '' !== $description ) {
			$sanitized_description = wp_kses_post( $description );
			if ( (string) $attachment->post_content !== $sanitized_description ) {
				$attachment_update['post_content'] = $sanitized_description;
			}
		}

		if ( count( $attachment_update ) > 1 ) {
			$updated_attachment_id = wp_update_post( $attachment_update, true );
			$changed               = $changed || ( ! is_wp_error( $updated_attachment_id ) && $updated_attachment_id > 0 );
		}

		update_post_meta( $attachment_id, '_tporapdi_import_id', $import_id );

		return $changed;
	}

	/**
	 * Resolves one metadata value from mapping-local context, then full item fallback.
	 *
	 * @param array<string, mixed> $context Mapping-local source context.
	 * @param array<string, mixed> $item    Full item payload.
	 * @param mixed                $path    Metadata path.
	 *
	 * @return string
	 */
	private static function resolve_metadata_value( array $context, array $item, $path ): string {
		$path = is_string( $path ) ? trim( $path ) : '';
		if ( '' === $path ) {
			return '';
		}

		$value = tporapdi_get_item_value_by_path( $context, $path );
		if ( null === $value ) {
			$value = tporapdi_get_item_value_by_path( $item, $path );
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Checks whether an array is list-like for PHP versions before array_is_list().
	 *
	 * @param array<mixed> $value Array value.
	 *
	 * @return bool
	 */
	private static function array_is_list( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Writes a media-processing error row to the import logs table.
	 *
	 * @param string $status    Log status label.
	 * @param string $image_url Source image URL.
	 * @param int    $post_id   Target post ID.
	 * @param string $message   Error message.
	 *
	 * @return void
	 */
	private static function log_media_error( string $status, string $image_url, int $post_id, string $message ): void {
		$now        = gmdate( 'Y-m-d H:i:s', time() );
		$run_id     = wp_generate_uuid4();
		$error_json = wp_json_encode(
			array(
				'media_error' => true,
				'image_url'   => esc_url_raw( $image_url ),
				'post_id'     => $post_id,
				'message'     => sanitize_text_field( $message ),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if ( false === $error_json ) {
			$error_json = '{"media_error":true,"message":"JSON encoding failed"}';
		}

		Tporapdi_Log_Repository::insert( 0, $run_id, sanitize_text_field( $status ), 0, 0, 0, (string) $error_json, $now );
	}
}
