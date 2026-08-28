<?php
/**
 * Admin panel for Boz News.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'menu_highlight_css' ) );
	}

	public function add_admin_menu() {
		// Crown SVG icon — gold fill so it stands out from every other menu item.
		$crown_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
			. '<path fill="#c9a227" d="M5 16l-2-9 6 4 3-8 3 8 6-4-2 9H5zm0 2h14v2H5v-2z"/>'
			. '</svg>';

		add_menu_page(
			wpnc__( 'Boz News', 'بُز نیوز' ),
			wpnc__( 'Boz News', 'بُز نیوز' ),
			'manage_options',
			'boz-news',
			array( $this, 'render_admin_page' ),
			'data:image/svg+xml;base64,' . base64_encode( $crown_svg ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			3  // Position 3 = right after Dashboard, top of sidebar.
		);

		// Explicitly register the main page as the first submenu so WordPress
		// keeps the parent link pointing here instead of the CPT list.
		add_submenu_page(
			'boz-news',
			wpnc__( 'Boz News', 'بُز نیوز' ),
			wpnc__( 'Dashboard', 'داشبورد' ),
			'manage_options',
			'boz-news',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Inject CSS that makes the Boz News menu item visually distinct.
	 */
	public function menu_highlight_css() {
		?>
		<style>
		/* Boz News — gold crown accent */
		#adminmenu #toplevel_page_boz-news > a,
		#adminmenu #toplevel_page_boz-news > a:hover {
			border-left: 3px solid #c9a227;
		}
		#adminmenu #toplevel_page_boz-news > a .wp-menu-name {
			font-weight: 600;
		}
		#adminmenu #toplevel_page_boz-news.wp-has-current-submenu > a,
		#adminmenu #toplevel_page_boz-news.current > a {
			background: #1e1e1e;
			border-left-color: #f0c040;
		}
		/* Submenu items */
		#adminmenu #toplevel_page_boz-news .wp-submenu a:hover {
			color: #f0c040 !important;
		}
		</style>
		<?php
	}

	public function register_settings() {
		register_setting( 'wpnc_settings_group', 'wpnc_admin_lang', 'sanitize_key' );
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

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$is_rtl     = ( 'en' !== get_option( 'wpnc_admin_lang', 'fa' ) );
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tabs       = array(
			'settings'   => wpnc__( 'Settings', 'تنظیمات' ),
			'moderation' => wpnc__( 'Moderation Queue', 'صف تأیید' ),
			'logs'       => wpnc__( 'Logs & Tools', 'لاگ و ابزارها' ),
		);

		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'settings';
		}
		?>
		<div class="wrap wpnc-wrap<?php echo $is_rtl ? ' wpnc-rtl' : ''; ?>">
			<h1 class="wpnc-page-title">
				<span class="wpnc-title-crown">♛</span>
				<?php echo esc_html( wpnc__( 'Boz News', 'بُز نیوز' ) ); ?>
			</h1>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'boz-news', 'tab' => $tab ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo $active_tab === $tab ? 'nav-tab-active' : ''; ?>">
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

	private function render_settings_tab() {
		$interval     = get_option( 'wpnc_interval', 'hourly' );
		$target_pt    = WPNC_Settings::get_target_post_type();
		$default_cat  = absint( get_option( 'wpnc_default_category', 0 ) );
		$has_openai   = '' !== (string) get_option( 'wpnc_openai_api_key', '' );
		$has_telegram = '' !== (string) get_option( 'wpnc_telegram_token', '' );
		$max_items    = absint( get_option( 'wpnc_max_items_per_feed', 20 ) );
		$timeout      = absint( get_option( 'wpnc_request_timeout', 8 ) );
		$openai_model = get_option( 'wpnc_openai_model', 'gpt-4o-mini' );
		$admin_lang   = get_option( 'wpnc_admin_lang', 'fa' );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wpnc_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpnc_admin_lang"><?php wpnc_e( 'Interface Language', 'زبان رابط کاربری' ); ?></label></th>
					<td>
						<select id="wpnc_admin_lang" name="wpnc_admin_lang">
							<option value="fa" <?php selected( $admin_lang, 'fa' ); ?>>فارسی</option>
							<option value="en" <?php selected( $admin_lang, 'en' ); ?>>English</option>
						</select>
						<p class="description"><?php wpnc_e( 'Select the language for this plugin\'s admin panel.', 'زبان نمایش پنل مدیریت این افزونه را انتخاب کنید.' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_rss_links"><?php wpnc_e( 'RSS/Atom Sources', 'منابع RSS/Atom' ); ?></label></th>
					<td>
						<textarea id="wpnc_rss_links" name="wpnc_rss_links" rows="10" class="large-text code" dir="ltr"><?php echo esc_textarea( get_option( 'wpnc_rss_links', '' ) ); ?></textarea>
						<p class="description"><?php wpnc_e( 'One source per line. Formats: URL, URL|category_id, or URL|category_id|source_key. Lines starting with # are ignored.', 'هر منبع در یک خط. فرمت‌ها: URL یا URL|category_id یا URL|category_id|source_key. خطوط شروع‌شده با # نادیده گرفته می‌شوند.' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_source_rules"><?php wpnc_e( 'Source Rules', 'قوانین منبع' ); ?></label></th>
					<td>
						<textarea id="wpnc_source_rules" name="wpnc_source_rules" rows="5" class="large-text code" dir="ltr"><?php echo esc_textarea( get_option( 'wpnc_source_rules', '' ) ); ?></textarea>
						<p class="description"><?php wpnc_e( 'Optional: source_key|content_xpath|image_xpath. Leave empty for automatic extraction.', 'اختیاری: source_key|content_xpath|image_xpath. برای استخراج خودکار خالی بگذارید.' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_interval"><?php wpnc_e( 'Update Interval', 'بازه بروزرسانی' ); ?></label></th>
					<td>
						<select id="wpnc_interval" name="wpnc_interval">
							<option value="15min"      <?php selected( $interval, '15min' ); ?>><?php wpnc_e( '15 Minutes', '۱۵ دقیقه' ); ?></option>
							<option value="hourly"     <?php selected( $interval, 'hourly' ); ?>><?php wpnc_e( '1 Hour', '۱ ساعت' ); ?></option>
							<option value="3hours"     <?php selected( $interval, '3hours' ); ?>><?php wpnc_e( '3 Hours', '۳ ساعت' ); ?></option>
							<option value="twicedaily" <?php selected( $interval, 'twicedaily' ); ?>><?php wpnc_e( '12 Hours', '۱۲ ساعت' ); ?></option>
							<option value="daily"      <?php selected( $interval, 'daily' ); ?>><?php wpnc_e( 'Daily', 'روزانه' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_max_items_per_feed"><?php wpnc_e( 'Max Items per Feed', 'حداکثر آیتم هر فید' ); ?></label></th>
					<td><input id="wpnc_max_items_per_feed" type="number" min="1" max="100" name="wpnc_max_items_per_feed" value="<?php echo esc_attr( $max_items ? $max_items : 20 ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_request_timeout"><?php wpnc_e( 'HTTP Timeout', 'زمان‌انتظار HTTP' ); ?></label></th>
					<td>
						<input id="wpnc_request_timeout" type="number" min="3" max="30" name="wpnc_request_timeout" value="<?php echo esc_attr( $timeout ? $timeout : 8 ); ?>" />
						<?php wpnc_e( 'seconds', 'ثانیه' ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_target_post_type"><?php wpnc_e( 'Target Post Type', 'نوع پست هدف' ); ?></label></th>
					<td>
						<select id="wpnc_target_post_type" name="wpnc_target_post_type">
							<option value="post"      <?php selected( $target_pt, 'post' ); ?>><?php wpnc_e( 'Standard Post', 'پست معمولی' ); ?></option>
							<option value="wpnc_news" <?php selected( $target_pt, 'wpnc_news' ); ?>><?php wpnc_e( 'News (Custom Post Type)', 'خبر (نوع پست دلخواه)' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php wpnc_e( 'Default Category', 'دسته‌بندی پیش‌فرض' ); ?></th>
					<td>
						<?php
						wp_dropdown_categories(
							array(
								'hide_empty'       => 0,
								'name'             => 'wpnc_default_category',
								'selected'         => $default_cat,
								'show_option_none' => wpnc__( 'None', 'هیچ‌کدام' ),
							)
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php wpnc_e( 'Publishing', 'انتشار' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wpnc_auto_publish" value="1" <?php checked( get_option( 'wpnc_auto_publish', 0 ), 1 ); ?> />
							<?php wpnc_e( 'Publish directly without moderation queue', 'انتشار مستقیم بدون صف تأیید' ); ?>
						</label><br>
						<label>
							<input type="checkbox" name="wpnc_extract_full_text" value="1" <?php checked( get_option( 'wpnc_extract_full_text', 0 ), 1 ); ?> />
							<?php wpnc_e( 'Attempt full-text extraction from article pages', 'استخراج متن کامل از صفحه مقاله' ); ?>
						</label><br>
						<label>
							<input type="checkbox" name="wpnc_admin_notify" value="1" <?php checked( get_option( 'wpnc_admin_notify', 0 ), 1 ); ?> />
							<?php wpnc_e( 'Email admin when new queue items are added', 'ارسال ایمیل به مدیر هنگام افزوده شدن آیتم جدید' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_default_image"><?php wpnc_e( 'Fallback Image URL', 'آدرس تصویر پیش‌فرض' ); ?></label></th>
					<td><input id="wpnc_default_image" type="url" name="wpnc_default_image" value="<?php echo esc_url( get_option( 'wpnc_default_image', '' ) ); ?>" class="large-text" dir="ltr" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_include_words"><?php wpnc_e( 'Must Include Words', 'باید شامل کلمات' ); ?></label></th>
					<td><input id="wpnc_include_words" type="text" name="wpnc_include_words" value="<?php echo esc_attr( get_option( 'wpnc_include_words', '' ) ); ?>" class="large-text" dir="auto" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_exclude_words"><?php wpnc_e( 'Exclude Words', 'کلمات مستثنا' ); ?></label></th>
					<td><input id="wpnc_exclude_words" type="text" name="wpnc_exclude_words" value="<?php echo esc_attr( get_option( 'wpnc_exclude_words', '' ) ); ?>" class="large-text" dir="auto" /></td>
				</tr>
			</table>

			<hr>
			<h3><?php wpnc_e( 'AI Rewrite (OpenAI)', 'بازنویسی هوش مصنوعی (OpenAI)' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpnc_openai_api_key"><?php wpnc_e( 'OpenAI API Key', 'کلید API اوپن‌ای‌آی' ); ?></label></th>
					<td>
						<input id="wpnc_openai_api_key" type="password" name="wpnc_openai_api_key" value=""
							placeholder="<?php echo esc_attr( $has_openai ? wpnc__( 'Saved — enter a new key to replace.', 'ذخیره شده — کلید جدید وارد کنید تا جایگزین شود.' ) : '' ); ?>"
							class="regular-text" autocomplete="off" dir="ltr" />
						<p class="description"><?php wpnc_e( 'Leave blank to keep the saved key. Enter __delete__ to remove it.', 'برای حفظ کلید فعلی خالی بگذارید. برای حذف __delete__ وارد کنید.' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_openai_model"><?php wpnc_e( 'OpenAI Model', 'مدل OpenAI' ); ?></label></th>
					<td><input id="wpnc_openai_model" type="text" name="wpnc_openai_model" value="<?php echo esc_attr( $openai_model ); ?>" class="regular-text" dir="ltr" /></td>
				</tr>
				<tr>
					<th scope="row"><?php wpnc_e( 'AI Options', 'تنظیمات AI' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wpnc_auto_rewrite" value="1" <?php checked( get_option( 'wpnc_auto_rewrite', 0 ), 1 ); ?> />
							<?php wpnc_e( 'Rewrite title & description, generate tags before queue/publish', 'بازنویسی عنوان و توضیحات، تولید تگ قبل از صف/انتشار' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_target_language"><?php wpnc_e( 'Target Language', 'زبان هدف بازنویسی' ); ?></label></th>
					<td><input id="wpnc_target_language" type="text" name="wpnc_target_language" value="<?php echo esc_attr( get_option( 'wpnc_target_language', '' ) ); ?>" class="regular-text" dir="auto" placeholder="<?php echo esc_attr( wpnc__( 'e.g. Persian, English', 'مثلاً: Persian یا Farsi' ) ); ?>" /></td>
				</tr>
			</table>

			<hr>
			<h3><?php wpnc_e( 'Telegram Auto-Post', 'ارسال خودکار به تلگرام' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpnc_telegram_token"><?php wpnc_e( 'Telegram Bot Token', 'توکن ربات تلگرام' ); ?></label></th>
					<td>
						<input id="wpnc_telegram_token" type="password" name="wpnc_telegram_token" value=""
							placeholder="<?php echo esc_attr( $has_telegram ? wpnc__( 'Saved — enter a new token to replace.', 'ذخیره شده — توکن جدید وارد کنید.' ) : '' ); ?>"
							class="regular-text" autocomplete="off" dir="ltr" />
						<p class="description"><?php wpnc_e( 'Leave blank to keep the saved token. Enter __delete__ to remove it.', 'برای حفظ توکن فعلی خالی بگذارید. برای حذف __delete__ وارد کنید.' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_telegram_chat_id"><?php wpnc_e( 'Telegram Chat ID', 'Chat ID تلگرام' ); ?></label></th>
					<td><input id="wpnc_telegram_chat_id" type="text" name="wpnc_telegram_chat_id" value="<?php echo esc_attr( get_option( 'wpnc_telegram_chat_id', '' ) ); ?>" class="regular-text" dir="ltr" /></td>
				</tr>
			</table>

			<?php submit_button( wpnc__( 'Save Settings', 'ذخیره تنظیمات' ) ); ?>
		</form>
		<?php
	}

	private function render_moderation_tab() {
		echo '<div id="wpnc-moderation-app"></div>';
	}

	private function render_logs_tab() {
		$last_run     = get_option( 'wpnc_last_run', wpnc__( 'Never', 'هرگز' ) );
		$last_count   = absint( get_option( 'wpnc_last_count', 0 ) );
		$last_summary = get_option( 'wpnc_last_summary', array() );
		?>
		<div class="wpnc-tools-panel">
			<h3><?php wpnc_e( 'Fetch Tools', 'ابزارهای دریافت' ); ?></h3>
			<p class="wpnc-fetch-actions">
				<button type="button" class="button button-primary" id="wpnc-run-fetch">
					<?php wpnc_e( 'Fetch Now', 'دریافت فوری' ); ?>
				</button>
				<button type="button" class="button" id="wpnc-clear-lock"
					title="<?php echo esc_attr( wpnc__( 'Use if a previous fetch got stuck', 'اگر دریافت قبلی گیر کرد استفاده کنید' ) ); ?>">
					<?php wpnc_e( 'Clear Lock', 'پاک کردن قفل' ); ?>
				</button>
				<span id="wpnc-fetch-status" class="wpnc-inline-status"></span>
			</p>
			<div id="wpnc-fetch-progress" class="wpnc-progress-wrap" style="display:none">
				<div class="wpnc-progress-bar">
					<div class="wpnc-progress-fill"></div>
				</div>
				<span class="wpnc-progress-text"></span>
			</div>
			<p>
				<?php wpnc_e( 'Last Update:', 'آخرین بروزرسانی:' ); ?>
				<strong><?php echo esc_html( $last_run ); ?></strong>
				&nbsp;|&nbsp;
				<?php wpnc_e( 'Items Seen:', 'آیتم‌های دیده شده:' ); ?>
				<strong><?php echo esc_html( $last_count ); ?></strong>
			</p>
			<?php if ( is_array( $last_summary ) && ! empty( $last_summary ) ) : ?>
				<div class="wpnc-summary-grid">
					<?php
					$labels = array(
						'sources_total' => wpnc__( 'Sources Total', 'کل منابع' ),
						'sources_ok'    => wpnc__( 'Sources OK', 'منابع موفق' ),
						'fetched'       => wpnc__( 'Fetched', 'دریافت‌شده' ),
						'queued'        => wpnc__( 'Queued', 'در صف' ),
						'published'     => wpnc__( 'Published', 'منتشرشده' ),
						'skipped'       => wpnc__( 'Skipped', 'رد شده' ),
						'errors'        => wpnc__( 'Errors', 'خطاها' ),
					);
					foreach ( $labels as $key => $label ) :
						$val   = absint( $last_summary[ $key ] ?? 0 );
						$extra = '';
						if ( 'errors' === $key && $val > 0 ) {
							$extra = ' wpnc-stat-error';
						} elseif ( 'published' === $key && $val > 0 ) {
							$extra = ' wpnc-stat-success';
						} elseif ( 'queued' === $key && $val > 0 ) {
							$extra = ' wpnc-stat-warning';
						}
						?>
						<div class="<?php echo esc_attr( trim( $extra ) ); ?>">
							<strong><?php echo esc_html( $val ); ?></strong>
							<span><?php echo esc_html( $label ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<hr>
		<h3><?php wpnc_e( 'Queue Statistics', 'آمار صف' ); ?></h3>
		<div id="wpnc-stats-summary"></div>
		<hr>
		<h3><?php wpnc_e( 'Recent Logs', 'لاگ‌های اخیر' ); ?></h3>
		<div id="wpnc-logs-app"></div>
		<?php
	}

	public function sanitize_rss_links( $value ) {
		$reader  = new WPNC_Feed_Reader();
		$sources = $reader->parse_sources( $value );
		$lines   = array();

		foreach ( $sources as $source ) {
			$line = WPNC_Feed_Reader::to_line( $source );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return implode( "\n", $lines );
	}

	public function sanitize_openai_key( $value ) {
		return WPNC_Settings::sanitize_secret( $value, 'wpnc_openai_api_key' );
	}

	public function sanitize_telegram_token( $value ) {
		return WPNC_Settings::sanitize_secret( $value, 'wpnc_telegram_token' );
	}
}

new WPNC_Admin();
