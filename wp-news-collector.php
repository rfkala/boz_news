<?php
/**
 * Plugin Name: Boz News
 * Plugin URI: https://example.com
 * Description: Fetch, moderate, rewrite, and publish news from RSS/Atom sources.
 * Version: 1.27.2
 * Author: Arash
 * Text Domain: wp-news-collector
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPNC_VERSION', '1.27.2' );
define( 'WPNC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPNC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPNC_PLUGIN_FILE', __FILE__ );

require_once WPNC_PLUGIN_DIR . 'includes/class-settings.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-db.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-logger.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-filter.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-link.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-similarity.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-template.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-image-picker.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-scheduler.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-publish-options.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-queue-repository.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-feed-reader.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-image-service.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-ai-providers.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-ai-keys.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-ai-rewriter.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-diagnostics.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-channels.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-messenger.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-alerts.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-source-policy.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-seo.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-bulletin.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-roles.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-history.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-publisher.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-cpt.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-fetcher.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-ajax.php';
require_once WPNC_PLUGIN_DIR . 'includes/class-shortcode.php';

// Messages for a post that was scheduled are sent when it goes live, not when
// it was approved - its link does not work until then.
add_action( 'future_to_publish', array( 'WPNC_Publisher', 'deliver_deferred' ) );

// The moment the last AI key goes down is when the assistant stops working.
add_action( 'wpnc_ai_pool_exhausted', array( 'WPNC_Alerts', 'pool_exhausted' ) );

WPNC_SEO::boot();

// Before admin_menu and before any AJAX capability check on the same request.
add_action( 'admin_init', array( 'WPNC_Roles', 'maybe_install' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once WPNC_PLUGIN_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'boz-news', 'WPNC_CLI' );
}

if ( is_admin() ) {
	require_once WPNC_PLUGIN_DIR . 'includes/class-admin.php';
}

/**
 * Cache-busting version for a bundled asset.
 *
 * WPNC_VERSION alone is not enough: shipping a changed script under an
 * unchanged version leaves browsers on the cached copy, which shows up as a
 * panel that renders its markup and then does nothing.
 *
 * @param string $relative Path relative to the plugin root.
 * @return string
 */
function wpnc_asset_version( $relative ) {
	$path = WPNC_PLUGIN_DIR . ltrim( $relative, '/' );

	if ( ! file_exists( $path ) ) {
		return WPNC_VERSION;
	}

	$modified = filemtime( $path );

	return $modified ? WPNC_VERSION . '.' . $modified : WPNC_VERSION;
}

/**
 * Load plugin translations.
 */
function wpnc_load_textdomain() {
	load_plugin_textdomain( 'wp-news-collector', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'wpnc_load_textdomain' );

/**
 * Register the stylesheet the news list and the block share.
 *
 * On init rather than wp_enqueue_scripts: the block editor asks for a block's
 * style before that hook runs, and a handle it cannot find is dropped without
 * a word, leaving the editor preview unstyled.
 */
function wpnc_register_shared_style() {
	wp_register_style( 'wpnc-frontend-style', WPNC_PLUGIN_URL . 'assets/frontend.css', array(), wpnc_asset_version( 'assets/frontend.css' ) );
}
add_action( 'init', 'wpnc_register_shared_style' );

/**
 * The news list as a block for the block editor.
 *
 * Server-rendered through the same code as the shortcode, so the two cannot
 * show different lists for the same choices.
 */
function wpnc_register_block() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	// A variable rather than an inline array: tools/check_enqueue.py reads the
	// argument after the first comma past the asset path as the version, and
	// an inline list of dependencies would put a dependency in that position.
	$block_deps = array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render' );

	wp_register_script( 'wpnc-block', WPNC_PLUGIN_URL . 'assets/block.js', $block_deps, wpnc_asset_version( 'assets/block.js' ), true );

	register_block_type(
		'boz-news/bulletin',
		array(
			'editor_script'   => 'wpnc-block',
			'style'           => 'wpnc-frontend-style',
			'render_callback' => array( 'WPNC_Shortcode', 'render_block' ),
			'attributes'      => array(
				'limit'    => array(
					'type'    => 'number',
					'default' => 10,
				),
				'category' => array(
					'type'    => 'string',
					'default' => '',
				),
				'layout'   => array(
					'type'    => 'string',
					'default' => 'list',
				),
				'image'    => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'excerpt'  => array(
					'type'    => 'number',
					'default' => 30,
				),
				'source'   => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
		)
	);
}
add_action( 'init', 'wpnc_register_block', 20 );

/**
 * Labels and categories for the block, in the editor only.
 *
 * Kept off every other page: the category list is a query, and the front end
 * has no use for it.
 */
function wpnc_block_editor_data() {
	$categories = array(
		array(
			'value' => '',
			'label' => __( 'All categories', 'wp-news-collector' ),
		),
	);

	$terms = get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'number'     => 200,
			'orderby'    => 'name',
		)
	);

	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$categories[] = array(
				'value' => $term->slug,
				'label' => $term->name,
			);
		}
	}

	wp_localize_script(
		'wpnc-block',
		'wpnc_block',
		array(
			'labels'     => array(
				'title'       => __( 'News bulletin', 'wp-news-collector' ),
				'description' => __( 'The latest news this site published, with pictures.', 'wp-news-collector' ),
				'settings'    => __( 'Bulletin settings', 'wp-news-collector' ),
				'limit'       => __( 'Number of items', 'wp-news-collector' ),
				'layout'      => __( 'Layout', 'wp-news-collector' ),
				'list'        => __( 'List', 'wp-news-collector' ),
				'grid'        => __( 'Grid', 'wp-news-collector' ),
				'category'    => __( 'Category', 'wp-news-collector' ),
				'image'       => __( 'Show pictures', 'wp-news-collector' ),
				'source'      => __( 'Show the source', 'wp-news-collector' ),
				'excerpt'     => __( 'Summary length (words)', 'wp-news-collector' ),
			),
			'categories' => $categories,
		)
	);
}
add_action( 'enqueue_block_editor_assets', 'wpnc_block_editor_data' );

/**
 * Register shared frontend assets.
 */
function wpnc_register_frontend_assets() {
	wp_register_script( 'wpnc-frontend-script', WPNC_PLUGIN_URL . 'assets/frontend.js', array( 'jquery' ), wpnc_asset_version( 'assets/frontend.js' ), true );
	wp_localize_script(
		'wpnc-frontend-script',
		'wpnc_frontend_ajax',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'i18n'     => array(
				'loading'   => __( 'Loading...', 'wp-news-collector' ),
				'load_more' => __( 'Load More News', 'wp-news-collector' ),
				'no_more'   => __( 'No more news', 'wp-news-collector' ),
				'error'     => __( 'Could not load more news. Please try again.', 'wp-news-collector' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'wpnc_register_frontend_assets' );

/**
 * Post types an item may be published into.
 *
 * Public types only: publishing a news item into a hidden internal type is
 * not something to offer by accident.
 *
 * @return array slug => label.
 */
function wpnc_admin_post_type_choices() {
	$choices = array();

	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $type ) {
		if ( 'attachment' === $slug ) {
			continue;
		}

		$choices[ $slug ] = $type->labels->singular_name;
	}

	return $choices;
}

/**
 * Users who could be given a post.
 *
 * Capped, because a site with thousands of subscribers should not ship all of
 * them to the browser on every page load. Anyone who can be an author has the
 * capability; subscribers do not.
 *
 * @return array id => display name.
 */
function wpnc_admin_author_choices() {
	$choices = array();

	$users = get_users(
		array(
			'capability' => 'edit_posts',
			'number'     => 100,
			'orderby'    => 'display_name',
			'fields'     => array( 'ID', 'display_name' ),
		)
	);

	foreach ( $users as $user ) {
		$choices[ (int) $user->ID ] = $user->display_name;
	}

	return $choices;
}

/**
 * Categories an item may be filed under.
 *
 * @return array id => name.
 */
function wpnc_admin_category_choices() {
	$choices = array();

	$terms = get_terms(
		array(
			'taxonomy'   => 'category',
			'hide_empty' => false,
			'number'     => 200,
			'orderby'    => 'name',
		)
	);

	if ( is_wp_error( $terms ) ) {
		return $choices;
	}

	foreach ( $terms as $term ) {
		$choices[ (int) $term->term_id ] = $term->name;
	}

	return $choices;
}

/**
 * Enqueue admin assets on plugin pages.
 *
 * @param string $hook Admin hook.
 */
function wpnc_enqueue_admin_assets( $hook ) {
	if ( 'toplevel_page_boz-news' !== $hook ) {
		return;
	}

	// Brings in TinyMCE and Quicktags so wp.editor.initialize() works on the
	// textarea the moderation modal creates at runtime.
	wp_enqueue_editor();

	// And the media modal behind it. The editor is initialised with
	// mediaButtons: true, which draws an Add Media button that opens
	// wp.media - a global that only exists once this has run. Without it the
	// button renders and does nothing at all when clicked.
	wp_enqueue_media();

	wp_enqueue_style( 'wpnc-admin-style', WPNC_PLUGIN_URL . 'assets/admin.css', array(), wpnc_asset_version( 'assets/admin.css' ) );
	wp_enqueue_script( 'wpnc-admin-script', WPNC_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), wpnc_asset_version( 'assets/admin.js' ), true );
	wp_localize_script(
		'wpnc-admin-script',
		'wpnc_ajax',
		array(
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'wpnc_admin_nonce' ),
			'lang'           => get_option( 'wpnc_admin_lang', 'fa' ),
			'post_edit_base' => admin_url( 'post.php?action=edit&post=' ),
			// So an empty state can offer the thing that would fill it, rather
			// than only naming it.
			'panel_url'      => admin_url( 'admin.php?page=boz-news&tab=' ),
			'ai_enabled'     => WPNC_AI_Rewriter::is_configured(),
			// A moderator is not shown what the server would refuse them.
			'can_admin'      => current_user_can( 'manage_options' ),
			'ai_actions'     => WPNC_AI_Rewriter::actions(),
			// Which destinations may be offered as a button, and why.
			'channels'       => WPNC_Channels::status(),
			// The settings an item may override at publish time, plus what
			// it inherits when it overrides nothing.
			'publish'        => array(
				'defaults'      => WPNC_Publish_Options::defaults(),
				'post_types'    => wpnc_admin_post_type_choices(),
				'statuses'      => WPNC_Publish_Options::statuses(),
				'authors'       => wpnc_admin_author_choices(),
				'categories'    => wpnc_admin_category_choices(),
				// So an item without a picture can say what it will get instead.
				'default_image' => esc_url_raw( (string) get_option( 'wpnc_default_image', '' ) ),
			),
			// What the preview needs to draw a post the moment a key is
			// pressed, rather than after a round trip through admin-ajax. The
			// allowlist is the server's own, so the two cannot drift apart.
			'preview'        => array(
				'template'     => '' !== trim( (string) get_option( 'wpnc_content_template', '' ) )
					? (string) get_option( 'wpnc_content_template', '' )
					: WPNC_Template::DEFAULT_TEMPLATE,
				'source_label' => wpnc__( 'Source:', 'منبع:' ),
				'allowed'      => array_map( 'array_keys', WPNC_AI_Rewriter::allowed_html() ),
			),
			'i18n'           => array(
				'loading'                => 'Loading...',
				'processing'             => 'Processing...',
				'done'                   => 'Done.',
				'saved'                  => 'Saved.',
				'retry'                  => 'Try again',
				'search'                 => 'Search...',
				'previous'               => 'Previous',
				'next'                   => 'Next',
				'of'                     => 'of',
				'pending_opt'            => 'Pending',
				'error_opt'              => 'Error',
				'approved_opt'           => 'Approved',
				'rejected_opt'           => 'Rejected',
				'filter_status'          => 'Filter by status',
				'select_all'             => 'Select All',
				'select_item'            => 'Select this item',
				'select_something'       => 'Select at least one item first.',
				'approve'                => 'Approve',
				'edit'                   => 'Edit',
				'reject'                 => 'Reject',
				'approve_selected'       => 'Approve Selected',
				'reject_selected'        => 'Reject Selected',
				'confirm_reject'         => 'Reject selected item(s)?',
				'view_post'              => 'View post',
				'no_image'               => 'No Image',
				'tags'                   => 'Tags',
				'edit_item'              => 'Edit News Item',
				'field_title'            => 'Title',
				'field_description'      => 'Description',
				'field_tags'             => 'Tags (comma separated)',
				'advanced'               => 'Advanced',
				'advanced_hint'          => 'These start from Settings. Change one here and it applies to this item only.',
				'inherit'                => 'Use the default',
				'inherit_named'          => 'Default:',
				'field_post_type'        => 'Post type',
				'field_post_status'      => 'Status',
				'field_post_author'      => 'Author',
				'field_category'         => 'Category',
				'title_required'         => 'Title is required.',
				'save'                   => 'Save',
				'cancel'                 => 'Cancel',
				'no_pending'             => 'No pending news in the queue.',
				'empty_pending_hint'     => 'Add RSS sources under Settings, then run Fetch Now from Logs & Tools.',
				'empty_search_hint'      => 'No item matches this search. Clear the search box to see the whole queue.',
				'empty_status_hint'      => 'Nothing has reached this status yet.',
				'no_logs'                => 'No logs yet.',
				'empty_logs_hint'        => 'Run Fetch Now above and the result will appear here.',
				'empty_stats_hint'       => 'The queue is empty. Add sources under Settings, then run Fetch Now.',
				'delete'                 => 'Delete',
				'delete_selected'        => 'Delete Selected',
				'undo_approve'           => 'Undo approve',
				'confirm_delete'         => 'Permanently delete this item from the queue? Any post it already published stays on the site.',
				'confirm_delete_bulk'    => 'Permanently delete the selected items from the queue? This cannot be undone.',
				'confirm_unpublish'      => 'Move the published post to Trash and return this item to the queue?',
				'pause_source'           => 'Pause',
				'resume_source'          => 'Resume',
				'empty_level_hint'       => 'Nothing was logged at this level. Choose All to see every entry.',
				'dash_activity'          => 'Last 14 days',
				'dash_by_source'         => 'By source',
				'dash_outcome'           => 'What happens to what you collect',
				'dash_sources'           => 'Sources',
				'dash_next_run'          => 'Next fetch',
				'dash_last_run'          => 'Last run',
				'dash_awaiting'          => 'awaiting your review',
				'dash_published_note'    => 'published to the site',
				'dash_errors_note'       => 'failed to publish',
				'dash_failing'           => 'failing',
				'dash_paused'            => 'paused',
				'dash_unsafe'            => 'unsafe',
				'dash_all_healthy'       => 'all healthy',
				'dash_empty'             => 'Nothing has been collected yet.',
				'dash_no_activity'       => 'No items were collected in this period.',
				'dash_no_sources_yet'    => 'No source has produced an item yet.',
				'dash_approved_of_total' => 'approved of total',
				'load_full_text'         => 'Load full article',
				'featured_image'         => 'Featured image',
				'featured_hint'          => 'Kept apart from the article. It becomes the featured image of the post and is not repeated inside the text.',
				'featured_none'          => 'No featured image. Paste an address, choose one from the library, or find one in the source.',
				'featured_default'       => 'None set for this item, so the default image from Settings will be used.',
				'featured_broken'        => 'Your browser could not load this address as an image. The server may still manage it, but check the address.',
				'featured_broken_short'  => 'Preview unavailable',
				'image_library'          => 'Choose from library',
				'image_library_title'    => 'Choose the featured image',
				'image_library_button'   => 'Use this image',
				'image_detect'           => 'Find in source',
				'image_detecting'        => 'Looking for an image in the source...',
				'image_remove'           => 'Remove',
				'media_unavailable'      => 'The media library is not available on this page.',
				'open_original'          => 'Open the original',
				'undo'                   => 'Undo',
				'ai_badge'               => 'AI',
				'ai_title'               => 'Assistant',
				'ai_apply'               => 'Apply',
				'ai_working'             => 'The assistant is working on it...',
				'ai_undo_hint'           => 'Use Undo to go back.',
				'ai_need_instruction'    => 'Tell the assistant what to change.',
				'ai_instruction_label'   => 'What should the assistant change?',
				'ai_placeholder'         => 'e.g. add a short intro paragraph explaining the background',
				'ai_disabled'            => 'The assistant needs an API key for the chosen AI provider. An administrator adds it under Settings.',
				'remove'                 => 'Remove',
				'key_placeholder'        => 'Paste a new key',
				'confirm_remove_key'     => 'Remove this key?',
				'preview'                => 'Preview',
				'words'                  => 'words',
				'read_minutes'           => 'min read',
				'send_to'                => 'Send to',
				'send_all'               => 'All',
				'save_and_send'          => 'Save and send',
				'approved'               => 'Approved.',
				'channel_ready'          => 'Tested and ready',
				'channel_untested'       => 'Not tested yet',
				'destination'            => 'Destination',
				'suggested_titles'       => 'Suggested headlines',
				'suggested_tags'         => 'Suggested tags',
				'apply_to_title'         => 'Apply to title',
				'apply_to_tags'          => 'Add to tags',
				'dismiss'                => 'Dismiss',
				'confirm_discard'        => 'Discard the changes you made to this item?',
				'error_network'          => 'Could not reach the server. Check your connection and try again.',
				'error_server'           => 'The server returned an error. Check Logs & Tools for details.',
				'error_forbidden'        => 'Your session expired or you lack permission. Reload the page and sign in again.',
				'error_timeout'          => 'The request timed out. Try again.',
				'error_parse'            => 'The server sent an unreadable response. Check Logs & Tools.',
				'col_time'               => 'Time',
				'col_level'              => 'Level',
				'col_source'             => 'Source',
				'col_message'            => 'Message',
				'fetch_done'             => 'Fetch completed.',
				'fetching_source'        => 'Fetching source',
				'no_sources'             => 'No RSS sources configured.',
				'lock_cleared'           => 'Lock cleared.',
				'confirm_clear_lock'     => 'Clear the fetch lock? Only do this if a previous run is stuck.',
				'fetched'                => 'Fetched',
				'queued_lc'              => 'queued',
				'published_lc'           => 'published',
				'skipped_lc'             => 'skipped',
				'errors_lc'              => 'errors',
				'dismiss'                => 'Dismiss',
				'go_to_tools'            => 'Fetch now',
				'clear_search'           => 'Clear the search',
				'diagnose_running' => 'Testing outbound requests. This can take up to a minute.',
				'diagnose_answered' => 'answered',
				'diagnose_failed' => 'no answer',
				'diagnose_allowed' => 'Allowed per request',
				'diagnose_ai_timeout' => 'Assistant timeout',
				'diagnose_php_limit' => 'PHP time limit',
				'diagnose_none' => 'none',
				'probe_running' => 'Asking each address whether it answers. This can take a minute.',
				'shortcuts_title' => 'Keyboard shortcuts',
				'shortcuts_hint' => 'Keyboard shortcuts (?)',
				'shortcut_move' => 'Next / previous item',
				'shortcut_edit' => 'Edit',
				'shortcut_approve' => 'Approve to the site',
				'shortcut_reject' => 'Reject',
				'shortcut_select' => 'Select for bulk actions',
				'shortcut_search' => 'Search',
				'shortcut_help' => 'Show or hide this list',
				'shortcut_note' => 'A sends to the site only. A message in Telegram or Bale cannot be taken back, so those stay a click.',
				'field_publish_at' => 'Publish at',
				'publish_at_hint' => 'Leave empty to publish on approval. Telegram and Bale wait until the post is live.',
				'scheduled_for' => 'Scheduled for',
				'preview_unconfirmed' => 'Could not confirm with the server',
				'field_caption' => 'Channel caption',
				'caption_hint' => 'Used for Telegram and Bale. Leave empty to use the opening of the article.',
				'group_also' => 'Same story from',
				'group_more' => 'more',
				'group_show' => 'Show them',
				'group_hide' => 'Hide them',
				'group_reject' => 'Reject the others',
				'group_confirm' => 'Reject the other copies of this story? The one shown stays in the queue.',
				'list_sep' => ', ',
				'confirm_policy_publish' => 'Items from this source will be published without anyone reading them first. Continue?',
				'field_seo_description' => 'Meta description',
				'field_seo_keyword' => 'Focus keyword',
				'seo_hint' => 'For search engines. Leave the description empty to use the opening of the article. Yoast or Rank Math receive both when installed.',
				'history' => 'History',
				'history_title' => 'History',
				'history_empty' => 'Nothing has been recorded for this item yet.',
			),
			'i18n_fa'        => array(
				'loading'                => 'در حال بارگذاری...',
				'processing'             => 'در حال پردازش...',
				'done'                   => 'انجام شد.',
				'saved'                  => 'ذخیره شد.',
				'retry'                  => 'تلاش دوباره',
				'search'                 => 'جستجو...',
				'previous'               => 'قبلی',
				'next'                   => 'بعدی',
				'of'                     => 'از',
				'pending_opt'            => 'در انتظار',
				'error_opt'              => 'خطا',
				'approved_opt'           => 'تأییدشده',
				'rejected_opt'           => 'ردشده',
				'filter_status'          => 'فیلتر بر اساس وضعیت',
				'select_all'             => 'انتخاب همه',
				'select_item'            => 'انتخاب این آیتم',
				'select_something'       => 'ابتدا حداقل یک آیتم را انتخاب کنید.',
				'approve'                => 'تأیید',
				'edit'                   => 'ویرایش',
				'reject'                 => 'رد',
				'approve_selected'       => 'تأیید انتخاب‌شده‌ها',
				'reject_selected'        => 'رد انتخاب‌شده‌ها',
				'confirm_reject'         => 'آیتم(های) انتخاب‌شده رد شوند؟',
				'view_post'              => 'مشاهده پست',
				'no_image'               => 'بدون تصویر',
				'tags'                   => 'برچسب‌ها',
				'edit_item'              => 'ویرایش خبر',
				'field_title'            => 'عنوان',
				'field_description'      => 'توضیحات',
				'field_tags'             => 'برچسب‌ها (با کاما جدا کنید)',
				'advanced'               => 'تنظیمات پیشرفته',
				'advanced_hint'          => 'مقدار پیش‌فرض از تنظیمات می‌آید. هر کدام را اینجا تغییر دهید فقط روی همین خبر اثر دارد.',
				'inherit'                => 'مطابق تنظیمات',
				'inherit_named'          => 'پیش‌فرض:',
				'field_post_type'        => 'نوع پست',
				'field_post_status'      => 'وضعیت انتشار',
				'field_post_author'      => 'نویسنده',
				'field_category'         => 'دسته‌بندی',
				'title_required'         => 'عنوان الزامی است.',
				'save'                   => 'ذخیره',
				'cancel'                 => 'انصراف',
				'no_pending'             => 'خبری در صف وجود ندارد.',
				'empty_pending_hint'     => 'ابتدا در تب تنظیمات منابع RSS را اضافه کنید، سپس از تب لاگ و ابزارها «دریافت فوری» را بزنید.',
				'empty_search_hint'      => 'هیچ آیتمی با این جستجو مطابقت ندارد. کادر جستجو را خالی کنید تا کل صف نمایش داده شود.',
				'empty_status_hint'      => 'هنوز چیزی به این وضعیت نرسیده است.',
				'no_logs'                => 'هنوز لاگی ثبت نشده است.',
				'empty_logs_hint'        => 'دکمه «دریافت فوری» بالا را بزنید تا نتیجه اینجا نمایش داده شود.',
				'empty_stats_hint'       => 'صف خالی است. در تنظیمات منبع اضافه کنید و سپس «دریافت فوری» را بزنید.',
				'delete'                 => 'حذف',
				'delete_selected'        => 'حذف انتخاب‌شده‌ها',
				'undo_approve'           => 'لغو تأیید',
				'confirm_delete'         => 'این آیتم برای همیشه از صف حذف شود؟ پستی که قبلاً منتشر شده روی سایت باقی می‌ماند.',
				'confirm_delete_bulk'    => 'آیتم‌های انتخاب‌شده برای همیشه از صف حذف شوند؟ این کار قابل بازگشت نیست.',
				'confirm_unpublish'      => 'پست منتشرشده به زباله‌دان منتقل و این آیتم به صف بازگردانده شود؟',
				'pause_source'           => 'توقف',
				'resume_source'          => 'فعال‌سازی',
				'empty_level_hint'       => 'در این سطح چیزی ثبت نشده است. برای دیدن همه موارد گزینه «همه» را انتخاب کنید.',
				'dash_activity'          => '۱۴ روز گذشته',
				'dash_by_source'         => 'به تفکیک منبع',
				'dash_outcome'           => 'سرنوشت خبرهایی که جمع می‌کنی',
				'dash_sources'           => 'منابع',
				'dash_next_run'          => 'دریافت بعدی',
				'dash_last_run'          => 'آخرین اجرا',
				'dash_awaiting'          => 'در انتظار بررسی شما',
				'dash_published_note'    => 'منتشرشده روی سایت',
				'dash_errors_note'       => 'انتشارشان ناموفق بود',
				'dash_failing'           => 'خطادار',
				'dash_paused'            => 'متوقف',
				'dash_unsafe'            => 'ناامن',
				'dash_all_healthy'       => 'همه سالم',
				'dash_empty'             => 'هنوز خبری جمع‌آوری نشده است.',
				'dash_no_activity'       => 'در این بازه خبری جمع‌آوری نشده است.',
				'dash_no_sources_yet'    => 'هنوز هیچ منبعی خبری تولید نکرده است.',
				'dash_approved_of_total' => 'تأییدشده از کل',
				'load_full_text'         => 'دریافت متن کامل',
				'featured_image'         => 'تصویر شاخص',
				'featured_hint'          => 'جدا از متن خبر نگهداری می‌شود: تصویر شاخص پست می‌شود و داخل متن تکرار نمی‌شود.',
				'featured_none'          => 'تصویر شاخصی ندارد. آدرس یک تصویر را وارد کنید، از کتابخانه انتخاب کنید یا از منبع پیدا کنید.',
				'featured_default'       => 'برای این خبر تصویری تعیین نشده، پس تصویر پیش‌فرض تنظیمات استفاده می‌شود.',
				'featured_broken'        => 'مرورگر شما نتوانست این آدرس را به‌صورت تصویر باز کند. ممکن است سرور بتواند، اما آدرس را بررسی کنید.',
				'featured_broken_short'  => 'پیش‌نمایش در دسترس نیست',
				'image_library'          => 'انتخاب از کتابخانه',
				'image_library_title'    => 'انتخاب تصویر شاخص',
				'image_library_button'   => 'استفاده از این تصویر',
				'image_detect'           => 'یافتن از منبع',
				'image_detecting'        => 'در حال جست‌وجوی تصویر در منبع...',
				'image_remove'           => 'حذف',
				'media_unavailable'      => 'کتابخانهٔ رسانه در این صفحه در دسترس نیست.',
				'open_original'          => 'مشاهده اصل خبر',
				'undo'                   => 'بازگردانی',
				'ai_badge'               => 'هوش مصنوعی',
				'ai_title'               => 'دستیار',
				'ai_apply'               => 'اعمال',
				'ai_working'             => 'دستیار در حال کار است...',
				'ai_undo_hint'           => 'برای بازگشت، «بازگردانی» را بزنید.',
				'ai_need_instruction'    => 'به دستیار بگویید چه تغییری می‌خواهید.',
				'ai_instruction_label'   => 'دستیار چه تغییری بدهد؟',
				'ai_placeholder'         => 'مثلاً: یک پاراگراف مقدمه کوتاه دربارهٔ پیشینه اضافه کن',
				'ai_disabled'            => 'دستیار به کلید API ارائه‌دهندهٔ هوش مصنوعی انتخاب‌شده نیاز دارد. مدیر سایت آن را در تنظیمات وارد می‌کند.',
				'remove'                 => 'حذف',
				'key_placeholder'        => 'کلید جدید را اینجا بچسبانید',
				'confirm_remove_key'     => 'این کلید حذف شود؟',
				'preview'                => 'پیش‌نمایش',
				'words'                  => 'کلمه',
				'read_minutes'           => 'دقیقه مطالعه',
				'send_to'                => 'ارسال به',
				'send_all'               => 'همه',
				'save_and_send'          => 'ذخیره و ارسال',
				'approved'               => 'ارسال شد.',
				'channel_ready'          => 'تست‌شده و آماده',
				'channel_untested'       => 'هنوز تست نشده',
				'destination'            => 'مقصد',
				'suggested_titles'       => 'عنوان‌های پیشنهادی',
				'suggested_tags'         => 'برچسب‌های پیشنهادی',
				'apply_to_title'         => 'اعمال در عنوان',
				'apply_to_tags'          => 'افزودن به برچسب‌ها',
				'dismiss'                => 'بستن',
				'confirm_discard'        => 'تغییراتی که روی این خبر داده‌اید دور ریخته شود؟',
				'error_network'          => 'ارتباط با سرور برقرار نشد. اتصال خود را بررسی و دوباره تلاش کنید.',
				'error_server'           => 'سرور خطا برگرداند. برای جزئیات به تب لاگ و ابزارها مراجعه کنید.',
				'error_forbidden'        => 'نشست شما منقضی شده یا دسترسی ندارید. صفحه را تازه کنید و دوباره وارد شوید.',
				'error_timeout'          => 'زمان درخواست به پایان رسید. دوباره تلاش کنید.',
				'error_parse'            => 'پاسخ سرور قابل خواندن نبود. به تب لاگ و ابزارها مراجعه کنید.',
				'col_time'               => 'زمان',
				'col_level'              => 'سطح',
				'col_source'             => 'منبع',
				'col_message'            => 'پیام',
				'fetch_done'             => 'دریافت کامل شد.',
				'fetching_source'        => 'دریافت منبع',
				'no_sources'             => 'هیچ منبع RSS تنظیم نشده است.',
				'lock_cleared'           => 'قفل پاک شد.',
				'confirm_clear_lock'     => 'قفل دریافت پاک شود؟ فقط زمانی این کار را بکنید که اجرای قبلی گیر کرده باشد.',
				'fetched'                => 'دریافت‌شده',
				'queued_lc'              => 'در صف',
				'published_lc'           => 'منتشرشده',
				'skipped_lc'             => 'رد شده',
				'errors_lc'              => 'خطا',
				'dismiss'                => 'بستن',
				'go_to_tools'            => 'دریافت فوری',
				'clear_search'           => 'پاک کردن جستجو',
				'diagnose_running' => 'در حال آزمایش درخواست‌های خروجی. ممکن است تا یک دقیقه طول بکشد.',
				'diagnose_answered' => 'پاسخ داد',
				'diagnose_failed' => 'بدون پاسخ',
				'diagnose_allowed' => 'مجاز برای هر درخواست',
				'diagnose_ai_timeout' => 'زمان‌انتظار دستیار',
				'diagnose_php_limit' => 'محدودیت زمانی PHP',
				'diagnose_none' => 'ندارد',
				'probe_running' => 'در حال پرسیدن از هر آدرس که پاسخ می‌دهد یا نه. ممکن است یک دقیقه طول بکشد.',
				'shortcuts_title' => 'میانبرهای صفحه‌کلید',
				'shortcuts_hint' => 'میانبرهای صفحه‌کلید (?)',
				'shortcut_move' => 'خبر بعدی / قبلی',
				'shortcut_edit' => 'ویرایش',
				'shortcut_approve' => 'تأیید و انتشار در سایت',
				'shortcut_reject' => 'رد کردن',
				'shortcut_select' => 'انتخاب برای کار گروهی',
				'shortcut_search' => 'جست‌وجو',
				'shortcut_help' => 'نمایش یا پنهان کردن این فهرست',
				'shortcut_note' => 'کلید A فقط در سایت منتشر می‌کند. پیام تلگرام یا بله قابل بازگشت نیست، پس ارسال به آن‌ها با کلیک می‌ماند.',
				'field_publish_at' => 'زمان انتشار',
				'publish_at_hint' => 'خالی بگذارید تا هنگام تأیید منتشر شود. تلگرام و بله تا منتشر شدن پست صبر می‌کنند.',
				'scheduled_for' => 'زمان‌بندی‌شده برای',
				'preview_unconfirmed' => 'تأیید با سرور ممکن نشد',
				'field_caption' => 'کپشن کانال',
				'caption_hint' => 'برای تلگرام و بله. خالی بگذارید تا ابتدای متن خبر استفاده شود.',
				'group_also' => 'همین خبر از',
				'group_more' => 'منبع دیگر',
				'group_show' => 'نمایش',
				'group_hide' => 'پنهان کردن',
				'group_reject' => 'رد کردن بقیه',
				'group_confirm' => 'نسخه‌های دیگر این خبر رد شوند؟ نسخهٔ نمایش‌داده‌شده در صف می‌ماند.',
				'list_sep' => '، ',
				'confirm_policy_publish' => 'خبرهای این منبع بدون اینکه کسی آن‌ها را بخواند منتشر خواهند شد. ادامه می‌دهید؟',
				'field_seo_description' => 'توضیحات متا',
				'field_seo_keyword' => 'کلمهٔ کلیدی',
				'seo_hint' => 'برای موتورهای جست‌وجو. توضیحات را خالی بگذارید تا ابتدای متن خبر استفاده شود. اگر Yoast یا Rank Math نصب باشد، هر دو به آن داده می‌شوند.',
				'history' => 'سابقه',
				'history_title' => 'سابقهٔ خبر',
				'history_empty' => 'هنوز چیزی برای این خبر ثبت نشده است.',
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'wpnc_enqueue_admin_assets' );

/**
 * Add privacy policy content.
 *
 * Suggested text for the site's privacy policy page. It names every service
 * the plugin can send data to, so it has to change whenever one is added.
 */
function wpnc_add_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$paragraphs = array(
		__( 'Boz News imports items from the RSS and Atom feeds an administrator sets up and keeps them in a moderation queue. It may also download the full article page and its images from the source site.', 'wp-news-collector' ),
		__( 'When the AI assistant or automatic rewriting is turned on, the title and text of an article are sent to the AI provider chosen in the settings: OpenAI, Groq, Google Gemini, Anthropic Claude, GapGPT, or an address an administrator enters.', 'wp-news-collector' ),
		__( 'When Telegram or Bale channels are turned on, the title, summary, tags, link and featured image of each published item are sent to those services. Alerts about problems, such as a source that stopped answering, go to a chat an administrator chooses.', 'wp-news-collector' ),
		__( 'For each queued item, Boz News records which logged-in user edited it, rewrote it with the assistant, approved, rejected or unpublished it, and when. This history is deleted after the log retention period set in the settings.', 'wp-news-collector' ),
		__( 'The news list shown to visitors does not collect information about them.', 'wp-news-collector' ),
	);

	$content = '';
	foreach ( $paragraphs as $paragraph ) {
		$content .= '<p>' . esc_html( $paragraph ) . '</p>';
	}

	wp_add_privacy_policy_content( __( 'Boz News', 'wp-news-collector' ), wp_kses_post( $content ) );
}
add_action( 'admin_init', 'wpnc_add_privacy_policy_content' );
