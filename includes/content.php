<?php
/**
 * Content registration.
 *
 * @package EnterpriseAPIImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the imported_item custom post type with conservative visibility.
 */
function eai_register_imported_item_cpt() {
	$labels = array(
		'name'          => __( 'Imported Items', 'enterprise-api-importer' ),
		'singular_name' => __( 'Imported Item', 'enterprise-api-importer' ),
		'menu_name'     => __( 'Imported Items', 'enterprise-api-importer' ),
		'add_new_item'  => __( 'Add Imported Item', 'enterprise-api-importer' ),
		'edit_item'     => __( 'Edit Imported Item', 'enterprise-api-importer' ),
		'view_item'     => __( 'View Imported Item', 'enterprise-api-importer' ),
		'not_found'     => __( 'No imported items found.', 'enterprise-api-importer' ),
	);

	register_post_type(
		'imported_item',
		array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'exclude_from_search' => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'has_archive'         => true,
			'rewrite'             => array( 'slug' => 'imported-item', 'with_front' => false ),
			'query_var'           => true,
			'map_meta_cap'        => true,
			'capability_type'     => 'post',
			'capabilities'        => array(
				'create_posts' => 'do_not_allow',
			),
			'menu_icon'           => 'dashicons-database-import',
			'supports'            => array( 'title', 'editor', 'custom-fields' ),
			'delete_with_user'    => false,
		)
	);
}
add_action( 'init', 'eai_register_imported_item_cpt' );

/**
 * Determines whether a post is an imported item managed by this plugin.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function eai_is_managed_imported_item( $post_id ) {
	if ( 'imported_item' !== get_post_type( $post_id ) ) {
		return false;
	}

	$import_id = get_post_meta( $post_id, '_eai_import_id', true );

	return '' !== (string) $import_id;
}

/**
 * Prevents editing and deleting plugin-managed imported items.
 *
 * @param string[] $caps Primitive caps for meta cap checks.
 * @param string   $cap  Meta capability being checked.
 * @param int      $user_id User ID.
 * @param mixed[]  $args Optional args for the capability check.
 * @return string[]
 */
function eai_lock_managed_imported_items_caps( $caps, $cap, $user_id, $args ) {
	if ( ! in_array( $cap, array( 'edit_post', 'delete_post' ), true ) ) {
		return $caps;
	}

	if ( empty( $args[0] ) ) {
		return $caps;
	}

	$post_id = (int) $args[0];

	if ( $post_id > 0 && eai_is_managed_imported_item( $post_id ) ) {
		return array( 'do_not_allow' );
	}

	return $caps;
}
add_filter( 'map_meta_cap', 'eai_lock_managed_imported_items_caps', 10, 4 );

/**
 * Removes edit affordances from managed imported items in the list table.
 *
 * @param array   $actions Row actions.
 * @param WP_Post $post    Current post object.
 * @return array
 */
function eai_filter_managed_imported_item_row_actions( $actions, $post ) {
	if ( ! $post instanceof WP_Post || ! eai_is_managed_imported_item( $post->ID ) ) {
		return $actions;
	}

	unset( $actions['edit'] );
	unset( $actions['inline hide-if-no-js'] );
	unset( $actions['trash'] );

	return $actions;
}
add_filter( 'post_row_actions', 'eai_filter_managed_imported_item_row_actions', 10, 2 );

/**
 * Removes edit links for managed imported items to avoid dead-end edit screens.
 *
 * @param string $link    Edit link.
 * @param int    $post_id Post ID.
 * @param string $context Link context.
 * @return string
 */
function eai_filter_managed_imported_item_edit_link( $link, $post_id, $context ) {
	if ( eai_is_managed_imported_item( $post_id ) ) {
		return '';
	}

	return $link;
}
add_filter( 'get_edit_post_link', 'eai_filter_managed_imported_item_edit_link', 10, 3 );

/**
 * Shows a read-only notice on Imported Items admin screens.
 */
function eai_render_imported_items_read_only_notice() {
	$screen = get_current_screen();

	if ( ! $screen || 'imported_item' !== $screen->post_type ) {
		return;
	}

	echo '<div class="notice notice-info"><p>';
	echo esc_html__( 'Imported Items are managed by Enterprise API Importer and are read-only in wp-admin.', 'enterprise-api-importer' );
	echo '</p></div>';
}
add_action( 'admin_notices', 'eai_render_imported_items_read_only_notice' );
