<?php
/**
 * Register Custom Post Type for Boz News.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_CPT {

	public function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
	}

	public function register_cpt() {
		$labels = array(
			'name'               => wpnc__( 'News', 'اخبار' ),
			'singular_name'      => wpnc__( 'News Item', 'خبر' ),
			'menu_name'          => wpnc__( 'All News', 'همه اخبار' ),
			'all_items'          => wpnc__( 'All News', 'همه اخبار' ),
			'add_new_item'       => wpnc__( 'Add New News Item', 'افزودن خبر جدید' ),
			'add_new'            => wpnc__( 'Add New', 'افزودن' ),
			'edit_item'          => wpnc__( 'Edit News', 'ویرایش خبر' ),
			'update_item'        => wpnc__( 'Update News', 'بروزرسانی خبر' ),
			'view_item'          => wpnc__( 'View News', 'مشاهده خبر' ),
			'search_items'       => wpnc__( 'Search News', 'جستجوی خبر' ),
			'not_found'          => wpnc__( 'Not found', 'یافت نشد' ),
			'not_found_in_trash' => wpnc__( 'Not found in Trash', 'در زباله‌دان یافت نشد' ),
		);

		$args = array(
			'label'               => wpnc__( 'News Item', 'خبر' ),
			'description'         => wpnc__( 'News items collected from RSS feeds.', 'اخبار جمع‌آوری‌شده از فیدهای RSS.' ),
			'labels'              => $labels,
			'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
			'taxonomies'          => array( 'category', 'post_tag' ),
			'hierarchical'        => false,
			'public'              => true,
			'show_ui'             => true,
			// Attach as submenu under the main Boz News menu — no separate top-level menu.
			'show_in_menu'        => 'boz-news',
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => true,
			'can_export'          => true,
			'has_archive'         => true,
			'exclude_from_search' => false,
			'publicly_queryable'  => true,
			'capability_type'     => 'post',
			'show_in_rest'        => true,
		);

		register_post_type( 'wpnc_news', $args );
	}
}

new WPNC_CPT();
