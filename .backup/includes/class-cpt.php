<?php
/**
 * Register Custom Post Type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_CPT {

	/**
	 * Initialize the class.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
	}

	/**
	 * Register the "News" custom post type.
	 */
	public function register_cpt() {
		$labels = array(
			'name'                  => _x( 'News', 'Post Type General Name', 'wp-news-collector' ),
			'singular_name'         => _x( 'News Item', 'Post Type Singular Name', 'wp-news-collector' ),
			'menu_name'             => __( 'Collected News', 'wp-news-collector' ),
			'name_admin_bar'        => __( 'Collected News', 'wp-news-collector' ),
			'archives'              => __( 'News Archives', 'wp-news-collector' ),
			'attributes'            => __( 'News Attributes', 'wp-news-collector' ),
			'parent_item_colon'     => __( 'Parent News:', 'wp-news-collector' ),
			'all_items'             => __( 'All News', 'wp-news-collector' ),
			'add_new_item'          => __( 'Add New News Item', 'wp-news-collector' ),
			'add_new'               => __( 'Add New', 'wp-news-collector' ),
			'new_item'              => __( 'New News', 'wp-news-collector' ),
			'edit_item'             => __( 'Edit News', 'wp-news-collector' ),
			'update_item'           => __( 'Update News', 'wp-news-collector' ),
			'view_item'             => __( 'View News', 'wp-news-collector' ),
			'view_items'            => __( 'View News', 'wp-news-collector' ),
			'search_items'          => __( 'Search News', 'wp-news-collector' ),
			'not_found'             => __( 'Not found', 'wp-news-collector' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'wp-news-collector' ),
		);
		$args = array(
			'label'                 => __( 'News Item', 'wp-news-collector' ),
			'description'           => __( 'News items collected from RSS feeds.', 'wp-news-collector' ),
			'labels'                => $labels,
			'supports'              => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
			'taxonomies'            => array( 'category', 'post_tag' ),
			'hierarchical'          => false,
			'public'                => true,
			'show_ui'               => true,
			'show_in_menu'          => true,
			'menu_position'         => 31,
			'menu_icon'             => 'dashicons-media-document',
			'show_in_admin_bar'     => true,
			'show_in_nav_menus'     => true,
			'can_export'            => true,
			'has_archive'           => true,
			'exclude_from_search'   => false,
			'publicly_queryable'    => true,
			'capability_type'       => 'post',
			'show_in_rest'          => true, // Enable Gutenberg
		);
		register_post_type( 'wpnc_news', $args );
	}
}

// Initialize
new WPNC_CPT();
