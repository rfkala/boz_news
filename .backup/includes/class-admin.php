<?php
/**
 * Admin panel and settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register admin menu.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'News Collector', 'wp-news-collector' ),
			__( 'News Collector', 'wp-news-collector' ),
			'manage_options',
			'wpnc-news-collector',
			array( $this, 'render_admin_page' ),
			'dashicons-rss',
			30
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting( 'wpnc_settings_group', 'wpnc_rss_links', array( $this, 'sanitize_rss_links' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_source_rules', 'sanitize_textarea_field' );
		register_setting( 'wpnc_settings_group', 'wpnc_interval', array( 'WPNC_Settings', 'sanitize_interval' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_target_post_type', array( 'WPNC_Settings', 'sanitize_post_type' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_default_category', 'absint' );
		register_setting( 'wpnc_settings_group', 'wpnc_auto_publish', array( 'WPNC_Settings', 'sanitize_checkbox' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_default_image', 'esc_url_raw' );
		register_setting( 'wpnc_settings_group', 'wpnc_extract_full_text', array( 'WPNC_Settings', 'sanitize_checkbox' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_include_words', 'sanitize_text_field' );
		register_setting( 'wpnc_settings_group', 'wpnc_exclude_words', 'sanitize_text_field' );
		register_setting( 'wpnc_settings_group', 'wpnc_max_items_per_feed', array( 'WPNC_Settings', 'sanitize_max_items' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_request_timeout', array( 'WPNC_Settings', 'sanitize_timeout' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_openai_api_key', array( $this, 'sanitize_openai_key' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_openai_model', 'sanitize_text_field' );
		register_setting( 'wpnc_settings_group', 'wpnc_auto_rewrite', array( 'WPNC_Settings', 'sanitize_checkbox' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_target_language', 'sanitize_text_field' );
		register_setting( 'wpnc_settings_group', 'wpnc_telegram_token', array( $this, 'sanitize_telegram_token' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_telegram_chat_id', 'sanitize_text_field' );
		register_setting( 'wpnc_settings_group', 'wpnc_admin_notify', array( 'WPNC_Settings', 'sanitize_checkbox' ) );
	}

	/**
	 * Render admin page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tabs       = array(
			'settings'   => __( 'Settings', 'wp-news-collector' ),
			'moderation' => __( 'Moderation Queue', 'wp-news-collector' ),
			'logs'       => __( 'Logs & Tools', 'wp-news-collector' ),
		);

		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'settings';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP News Collector', 'wp-news-collector' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wpnc-news-collector', 'tab' => $tab ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo $active_tab === $tab ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<div class="wpnc-tab-content">
				<?php
				if ( 'settings' === $active_tab ) {
					$this->render_settings_tab();
				} elseif ( 'moderation' === $active_tab ) {
					$this->render_moderation_tab();
				} elseif ( 'logs' === $active_tab ) {
					$this->render_logs_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render settings tab.
	 */
	private function render_settings_tab() {
		$interval      = get_option( 'wpnc_interval', 'hourly' );
		$target_pt     = WPNC_Settings::get_target_post_type();
		$default_cat   = absint( get_option( 'wpnc_default_category', 0 ) );
		$has_openai    = '' !== (string) get_option( 'wpnc_openai_api_key', '' );
		$has_telegram  = '' !== (string) get_option( 'wpnc_telegram_token', '' );
		$max_items     = absint( get_option( 'wpnc_max_items_per_feed', 20 ) );
		$timeout       = absint( get_option( 'wpnc_request_timeout', 8 ) );
		$openai_model  = get_option( 'wpnc_openai_model', 'gpt-4o-mini' );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wpnc_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpnc_rss_links"><?php esc_html_e( 'RSS/Atom Sources', 'wp-news-collector' ); ?></label></th>
					<td>
						<textarea id="wpnc_rss_links" name="wpnc_rss_links" rows="10" class="large-text code"><?php echo esc_textarea( get_option( 'wpnc_rss_links', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One source per line. Formats: https://example.com/feed, https://example.com/feed|category_id, or https://example.com/feed|category_id|source_key. Lines starting with # are ignored.', 'wp-news-collector' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_source_rules"><?php esc_html_e( 'Source Rules', 'wp-news-collector' ); ?></label></th>
					<td>
						<textarea id="wpnc_source_rules" name="wpnc_source_rules" rows="5" class="large-text code"><?php echo esc_textarea( get_option( 'wpnc_source_rules', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Optional advanced rules: source_key|content_xpath|image_xpath. Leave empty for automatic extraction.', 'wp-news-collector' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_interval"><?php esc_html_e( 'Update Interval', 'wp-news-collector' ); ?></label></th>
					<td>
						<select id="wpnc_interval" name="wpnc_interval">
							<option value="15min" <?php selected( $interval, '15min' ); ?>><?php esc_html_e( '15 Minutes', 'wp-news-collector' ); ?></option>
							<option value="hourly" <?php selected( $interval, 'hourly' ); ?>><?php esc_html_e( '1 Hour', 'wp-news-collector' ); ?></option>
							<option value="3hours" <?php selected( $interval, '3hours' ); ?>><?php esc_html_e( '3 Hours', 'wp-news-collector' ); ?></option>
							<option value="twicedaily" <?php selected( $interval, 'twicedaily' ); ?>><?php esc_html_e( '12 Hours', 'wp-news-collector' ); ?></option>
							<option value="daily" <?php selected( $interval, 'daily' ); ?>><?php esc_html_e( 'Daily', 'wp-news-collector' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_max_items_per_feed"><?php esc_html_e( 'Max Items per Feed', 'wp-news-collector' ); ?></label></th>
					<td><input id="wpnc_max_items_per_feed" type="number" min="1" max="100" name="wpnc_max_items_per_feed" value="<?php echo esc_attr( $max_items ? $max_items : 20 ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_request_timeout"><?php esc_html_e( 'HTTP Timeout', 'wp-news-collector' ); ?></label></th>
					<td><input id="wpnc_request_timeout" type="number" min="3" max="30" name="wpnc_request_timeout" value="<?php echo esc_attr( $timeout ? $timeout : 8 ); ?>" /> <?php esc_html_e( 'seconds', 'wp-news-collector' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_target_post_type"><?php esc_html_e( 'Target Post Type', 'wp-news-collector' ); ?></label></th>
					<td>
						<select id="wpnc_target_post_type" name="wpnc_target_post_type">
							<option value="post" <?php selected( $target_pt, 'post' ); ?>><?php esc_html_e( 'Standard Post', 'wp-news-collector' ); ?></option>
							<option value="wpnc_news" <?php selected( $target_pt, 'wpnc_news' ); ?>><?php esc_html_e( 'News (Custom Post Type)', 'wp-news-collector' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Category', 'wp-news-collector' ); ?></th>
					<td>
						<?php
						wp_dropdown_categories(
							array(
								'hide_empty'       => 0,
								'name'             => 'wpnc_default_category',
								'selected'         => $default_cat,
								'show_option_none' => __( 'None', 'wp-news-collector' ),
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Publishing', 'wp-news-collector' ); ?></th>
					<td>
						<label><input type="checkbox" name="wpnc_auto_publish" value="1" <?php checked( get_option( 'wpnc_auto_publish', 0 ), 1 ); ?> /> <?php esc_html_e( 'Publish directly without moderation queue', 'wp-news-collector' ); ?></label><br>
						<label><input type="checkbox" name="wpnc_extract_full_text" value="1" <?php checked( get_option( 'wpnc_extract_full_text', 0 ), 1 ); ?> /> <?php esc_html_e( 'Attempt full-text extraction from article pages', 'wp-news-collector' ); ?></label><br>
						<label><input type="checkbox" name="wpnc_admin_notify" value="1" <?php checked( get_option( 'wpnc_admin_notify', 0 ), 1 ); ?> /> <?php esc_html_e( 'Email admin when new queue items are added', 'wp-news-collector' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_default_image"><?php esc_html_e( 'Fallback Image URL', 'wp-news-collector' ); ?></label></th>
					<td><input id="wpnc_default_image" type="url" name="wpnc_default_image" value="<?php echo esc_url( get_option( 'wpnc_default_image', '' ) ); ?>" class="large-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_include_words"><?php esc_html_e( 'Must Include Words', 'wp-news-collector' ); ?></label></th>
					<td><input id="wpnc_include_words" type="text" name="wpnc_include_words" value="<?php echo esc_attr( get_option( 'wpnc_include_words', '' ) ); ?>" class="large-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_exclude_words"><?php esc_html_e( 'Exclude Words', 'wp-news-collector' ); ?></label></th>
					<td><input id="wpnc_exclude_words" type="text" name="wpnc_exclude_words" value="<?php echo esc_attr( get_option( 'wpnc_exclude_words', '' ) ); ?>" class="large-text" /></td>
				</tr>
			</table>

			<hr>
			<h3><?php esc_html_e( 'AI Rewrite (OpenAI)', 'wp-news-collector' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpnc_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'wp-news-collector' ); ?></label></th>
					<td>
						<input id="wpnc_openai_api_key" type="password" name="wpnc_openai_api_key" value="" placeholder="<?php echo esc_attr( $has_openai ? __( 'Saved. Enter a new key to replace it.', 'wp-news-collector' ) : '' ); ?>" class="regular-text" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Leave blank to keep the saved key. Enter __delete__ to remove it.', 'wp-news-collector' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_openai_model"><?php esc_html_e( 'OpenAI Model', 'wp-news-collector' ); ?></label></th>
					<td><input id="wpnc_openai_model" type="text" name="wpnc_openai_model" value="<?php echo esc_attr( $openai_model ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'AI Options', 'wp-news-collector' ); ?></th>
					<td>
						<label><input type="checkbox" name="wpnc_auto_rewrite" value="1" <?php checked( get_option( 'wpnc_auto_rewrite', 0 ), 1 ); ?> /> <?php esc_html_e( 'Rewrite title, description, and generate tags before queue/publish', 'wp-news-collector' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_target_language"><?php esc_html_e( 'Target Language', 'wp-news-collector' ); ?></label></th>
					<td><input id="wpnc_target_language" type="text" name="wpnc_target_language" value="<?php echo esc_attr( get_option( 'wpnc_target_language', '' ) ); ?>" class="regular-text" /></td>
				</tr>
			</table>

			<hr>
			<h3><?php esc_html_e( 'Telegram Auto-Post', 'wp-news-collector' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpnc_telegram_token"><?php esc_html_e( 'Telegram Bot Token', 'wp-news-collector' ); ?></label></th>
					<td>
						<input id="wpnc_telegram_token" type="password" name="wpnc_telegram_token" value="" placeholder="<?php echo esc_attr( $has_telegram ? __( 'Saved. Enter a new token to replace it.', 'wp-news-collector' ) : '' ); ?>" class="regular-text" autocomplete="off" />
						<p class="description"><?php esc_html_e( 'Leave blank to keep the saved token. Enter __delete__ to remove it.', 'wp-news-collector' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_telegram_chat_id"><?php esc_html_e( 'Telegram Chat ID', 'wp-news-collector' ); ?></label></th>
					<td><input id="wpnc_telegram_chat_id" type="text" name="wpnc_telegram_chat_id" value="<?php echo esc_attr( get_option( 'wpnc_telegram_chat_id', '' ) ); ?>" class="regular-text" /></td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render moderation tab.
	 */
	private function render_moderation_tab() {
		echo '<div id="wpnc-moderation-app"></div>';
	}

	/**
	 * Render logs tab.
	 */
	private function render_logs_tab() {
		$last_run     = get_option( 'wpnc_last_run', __( 'Never', 'wp-news-collector' ) );
		$last_count   = absint( get_option( 'wpnc_last_count', 0 ) );
		$last_summary = get_option( 'wpnc_last_summary', array() );
		?>
		<div class="wpnc-tools-panel">
			<h3><?php esc_html_e( 'Fetch Tools', 'wp-news-collector' ); ?></h3>
			<p>
				<button type="button" class="button button-primary" id="wpnc-run-fetch"><?php esc_html_e( 'Run fetch now', 'wp-news-collector' ); ?></button>
				<span id="wpnc-fetch-status" class="wpnc-inline-status"></span>
			</p>
			<p>
				<?php esc_html_e( 'Last Update:', 'wp-news-collector' ); ?>
				<strong><?php echo esc_html( $last_run ); ?></strong>
				&nbsp; | &nbsp;
				<?php esc_html_e( 'Items Seen:', 'wp-news-collector' ); ?>
				<strong><?php echo esc_html( $last_count ); ?></strong>
			</p>
			<?php if ( is_array( $last_summary ) && ! empty( $last_summary ) ) : ?>
				<div class="wpnc-summary-grid">
					<?php foreach ( array( 'sources_total', 'sources_ok', 'fetched', 'queued', 'published', 'skipped', 'errors' ) as $key ) : ?>
						<div><strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ); ?></strong><span><?php echo esc_html( absint( $last_summary[ $key ] ?? 0 ) ); ?></span></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<hr>
		<h3><?php esc_html_e( 'Queue Statistics', 'wp-news-collector' ); ?></h3>
		<div id="wpnc-stats-summary"></div>
		<hr>
		<h3><?php esc_html_e( 'Recent Logs', 'wp-news-collector' ); ?></h3>
		<div id="wpnc-logs-app"></div>
		<?php
	}

	/**
	 * Sanitize RSS links.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_rss_links( $value ) {
		$reader  = new WPNC_Feed_Reader();
		$sources = $reader->parse_sources( $value );
		$lines   = array();

		foreach ( $sources as $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}

			$line = esc_url_raw( $source['url'] );
			if ( ! empty( $source['category_id'] ) ) {
				$line .= '|' . absint( $source['category_id'] );
			}
			if ( ! empty( $source['source_key'] ) ) {
				$line .= '|' . sanitize_key( $source['source_key'] );
			}
			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Sanitize OpenAI key.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_openai_key( $value ) {
		return WPNC_Settings::sanitize_secret( $value, 'wpnc_openai_api_key' );
	}

	/**
	 * Sanitize Telegram token.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function sanitize_telegram_token( $value ) {
		return WPNC_Settings::sanitize_secret( $value, 'wpnc_telegram_token' );
	}
}

new WPNC_Admin();
