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
			'menu_icon'           => 'dashicons-database-import',
			'supports'            => array( 'title', 'editor', 'custom-fields' ),
			'delete_with_user'    => false,
		)
	);
}
add_action( 'init', 'eai_register_imported_item_cpt' );
