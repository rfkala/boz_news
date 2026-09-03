<?php
/**
 * Admin panel for Boz News.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Admin {

	/**
	 * Gold crown menu icon, pre-encoded so it is not rebuilt on every request.
	 */
	const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCI+PHBhdGggZmlsbD0iI2M5YTIyNyIgZD0iTTUgMTZsLTItOSA2IDQgMy04IDMgOCA2LTQtMiA5SDV6bTAgMmgxNHYySDV2LTJ6Ii8+PC9zdmc+';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'menu_highlight_css' ) );
		add_action( 'admin_notices', array( $this, 'render_health_notice' ) );
		add_action( 'admin_post_wpnc_export_queue', array( $this, 'export_queue' ) );
	}

	public function add_admin_menu() {
		add_menu_page(
			wpnc__( 'Boz News', 'بُز نیوز' ),
			wpnc__( 'Boz News', 'بُز نیوز' ),
			'manage_options',
			'boz-news',
			array( $this, 'render_admin_page' ),
			self::MENU_ICON,
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
		register_setting( 'wpnc_settings_group', 'wpnc_admin_lang', array( 'WPNC_Settings', 'sanitize_language' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_rss_links', array( $this, 'sanitize_rss_links' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_interval', array( 'WPNC_Settings', 'sanitize_interval' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_target_post_type', array( 'WPNC_Settings', 'sanitize_post_type' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_default_category', array( 'WPNC_Settings', 'sanitize_category' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_post_author', array( 'WPNC_Settings', 'sanitize_post_author' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_post_status', array( 'WPNC_Settings', 'sanitize_post_status' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_content_template', array( $this, 'sanitize_template' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_stagger_enabled', array( 'WPNC_Settings', 'sanitize_checkbox' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_stagger_minutes', array( 'WPNC_Settings', 'sanitize_stagger_minutes' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_auto_publish', array( 'WPNC_Settings', 'sanitize_checkbox' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_default_image', array( 'WPNC_Settings', 'sanitize_image_url' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_extract_full_text', array( 'WPNC_Settings', 'sanitize_checkbox' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_include_words', 'sanitize_text_field' );
		register_setting( 'wpnc_settings_group', 'wpnc_exclude_words', 'sanitize_text_field' );
		register_setting( 'wpnc_settings_group', 'wpnc_max_items_per_feed', array( 'WPNC_Settings', 'sanitize_max_items' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_request_timeout', array( 'WPNC_Settings', 'sanitize_timeout' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_queue_retention_days', array( 'WPNC_Settings', 'sanitize_retention' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_log_retention_days', array( 'WPNC_Settings', 'sanitize_retention' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_ai_provider', array( 'WPNC_Settings', 'sanitize_ai_provider' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_ai_models', array( 'WPNC_Settings', 'sanitize_ai_models' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_ai_keys', array( 'WPNC_Settings', 'sanitize_ai_keys' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_auto_rewrite', array( 'WPNC_Settings', 'sanitize_checkbox' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_target_language', 'sanitize_text_field' );
		register_setting( 'wpnc_settings_group', 'wpnc_telegram_token', array( $this, 'sanitize_telegram_token' ) );
		register_setting( 'wpnc_settings_group', 'wpnc_telegram_chat_id', 'sanitize_text_field' );
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html( wpnc__( 'You do not have permission to open Boz News.', 'شما اجازه دسترسی به بُز نیوز را ندارید.' ) ),
				esc_html( wpnc__( 'Boz News', 'بُز نیوز' ) ),
				array( 'response' => 403 )
			);
		}

		$is_rtl     = ( 'en' !== get_option( 'wpnc_admin_lang', 'fa' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$tabs       = $this->tabs();

		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'dashboard';
		}

		$stats = $this->queue_counts();
		?>
		<div class="wrap wpnc-wrap<?php echo $is_rtl ? ' wpnc-rtl' : ''; ?>">
			<div class="wpnc-appbar">
				<div class="wpnc-shell wpnc-appbar-inner">
					<div class="wpnc-brandmark">
						<span class="wpnc-title-crown" aria-hidden="true">♛</span>
						<div class="wpnc-brandmark-text">
							<h1 class="wpnc-brandmark-name"><?php echo esc_html( wpnc__( 'Boz News', 'بُز نیوز' ) ); ?></h1>
							<p class="wpnc-brandmark-sub"><?php echo esc_html( $tabs[ $active_tab ]['sub'] ); ?></p>
						</div>
					</div>
					<div class="wpnc-appbar-meta">
						<div class="wpnc-chips">
							<?php $this->render_header_chips( $stats ); ?>
						</div>
						<?php if ( 'moderation' === $active_tab ) : ?>
							<div class="wpnc-appbar-actions">
								<?php $this->render_export_link(); ?>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<nav class="wpnc-shell wpnc-tabs" aria-label="<?php echo esc_attr( wpnc__( 'Boz News sections', 'بخش‌های بُز نیوز' ) ); ?>">
					<?php foreach ( $tabs as $tab => $meta ) : ?>
						<?php
						$is_active = ( $active_tab === $tab );
						$count     = isset( $meta['count'] ) ? (int) $stats[ $meta['count'] ] : 0;
						?>
						<a
							href="<?php echo esc_url( $this->tab_url( $tab ) ); ?>"
							class="wpnc-tab<?php echo $is_active ? ' is-active' : ''; ?>"
							<?php echo $is_active ? 'aria-current="page"' : ''; ?>
						>
							<span class="wpnc-tab-label"><?php echo esc_html( $meta['label'] ); ?></span>
							<?php if ( isset( $meta['count'] ) && $count > 0 ) : ?>
								<span class="wpnc-tab-count is-live"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</nav>
			</div>

			<div class="wpnc-shell wpnc-tab-content">
				<?php
				if ( 'dashboard' === $active_tab ) {
					$this->render_dashboard_tab();
				} elseif ( 'settings' === $active_tab ) {
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
	 * The four sections, with the one-line description each one shows in the
	 * header.
	 *
	 * The description is not decoration: the panel has four screens and their
	 * names alone ('Logs & Tools') do not say what is on them.
	 *
	 * @return array
	 */
	private function tabs() {
		return array(
			'dashboard'  => array(
				'label' => wpnc__( 'Dashboard', 'داشبورد' ),
				'sub'   => wpnc__( 'What the collector has been doing', 'کارنامهٔ جمع‌آورندهٔ خبر' ),
			),
			'moderation' => array(
				'label' => wpnc__( 'Moderation Queue', 'صف تأیید' ),
				'sub'   => wpnc__( 'Read, edit and approve before anything is published', 'خواندن، ویرایش و تأیید پیش از انتشار' ),
				'count' => 'pending',
			),
			'settings'   => array(
				'label' => wpnc__( 'Settings', 'تنظیمات' ),
				'sub'   => wpnc__( 'Sources, filters, publishing and AI', 'منابع، فیلترها، انتشار و هوش مصنوعی' ),
			),
			'logs'       => array(
				'label' => wpnc__( 'Logs & Tools', 'لاگ و ابزارها' ),
				'sub'   => wpnc__( 'Fetch now, check source health, read the log', 'دریافت فوری، بررسی سلامت منابع، خواندن لاگ' ),
			),
		);
	}

	/**
	 * Link to one section of the panel.
	 *
	 * @param string $tab   Tab key.
	 * @param array  $extra Extra query arguments.
	 * @return string
	 */
	private function tab_url( $tab, $extra = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'boz-news',
					'tab'  => $tab,
				),
				$extra
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Queue counts by status, never fatal.
	 *
	 * The header reads these on every page load, so a missing table has to
	 * degrade to zeroes rather than take the whole panel down with it.
	 *
	 * @return array
	 */
	private function queue_counts() {
		$empty = array(
			'approved' => 0,
			'pending'  => 0,
			'rejected' => 0,
			'error'    => 0,
		);

		$repository = new WPNC_Queue_Repository();
		$stats      = $repository->get_stats();

		return is_array( $stats ) ? array_merge( $empty, $stats ) : $empty;
	}

	/**
	 * The header's status chips.
	 *
	 * Two questions were previously answerable only by opening a tab and
	 * reading a paragraph: how much is waiting for me, and when does it fetch
	 * again. Both belong where they are visible from every screen.
	 *
	 * @param array $stats Queue counts by status.
	 */
	private function render_header_chips( $stats ) {
		$chips = array();

		$chips[] = array(
			'tone'  => $stats['pending'] > 0 ? 'brand' : 'muted',
			'label' => wpnc__( 'Awaiting review', 'در انتظار بررسی' ),
			'value' => number_format_i18n( $stats['pending'] ),
			'href'  => $this->tab_url( 'moderation' ),
		);

		if ( $stats['error'] > 0 ) {
			$chips[] = array(
				'tone'  => 'danger',
				'label' => wpnc__( 'Failed', 'ناموفق' ),
				'value' => number_format_i18n( $stats['error'] ),
				'href'  => $this->tab_url( 'moderation', array( 'status' => 'error' ) ),
			);
		}

		$chips[] = $this->next_fetch_chip();

		foreach ( $chips as $chip ) {
			$class = 'wpnc-chip wpnc-chip-' . $chip['tone'];
			$inner = sprintf(
				'<span class="wpnc-chip-label">%s</span><span class="wpnc-chip-value">%s</span>',
				esc_html( $chip['label'] ),
				esc_html( $chip['value'] )
			);

			if ( empty( $chip['href'] ) ) {
				printf( '<span class="%s">%s</span>', esc_attr( $class ), wp_kses_post( $inner ) );
				continue;
			}

			printf(
				'<a class="%s" href="%s">%s</a>',
				esc_attr( $class ),
				esc_url( $chip['href'] ),
				wp_kses_post( $inner )
			);
		}
	}

	/**
	 * The countdown chip, which also has to admit when there is no countdown.
	 *
	 * @return array
	 */
	private function next_fetch_chip() {
		$label = wpnc__( 'Next fetch', 'دریافت بعدی' );

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return array(
				'tone'  => 'warn',
				'label' => $label,
				'value' => wpnc__( 'cron off', 'کران خاموش' ),
				'href'  => $this->tab_url( 'logs' ),
			);
		}

		$next = wp_next_scheduled( 'wpnc_fetch_news_event' );

		if ( ! $next ) {
			return array(
				'tone'  => 'warn',
				'label' => $label,
				'value' => wpnc__( 'not scheduled', 'زمان‌بندی نشده' ),
				'href'  => $this->tab_url( 'logs' ),
			);
		}

		if ( $next <= time() ) {
			return array(
				'tone'  => 'muted',
				'label' => $label,
				'value' => wpnc__( 'due now', 'همین حالا' ),
				'href'  => $this->tab_url( 'logs' ),
			);
		}

		return array(
			'tone'  => 'muted',
			'label' => $label,
			'value' => human_time_diff( time(), $next ),
			'href'  => $this->tab_url( 'logs' ),
		);
	}

	/**
	 * Surface a failed table creation. dbDelta cannot report one, so without
	 * this the plugin looks fine and then throws DB errors on every request.
	 */
	public function render_health_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$missing = (string) get_option( WPNC_DB::HEALTH_OPTION, '' );
		if ( '' === $missing ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html( wpnc__( 'Boz News:', 'بُز نیوز:' ) ),
			esc_html(
				sprintf(
					/* translators: %s: comma separated table names */
					wpnc__(
						'could not create its database tables (%s). Deactivate and reactivate the plugin, or check the database user permissions.',
						'نتوانست جدول‌های پایگاه داده خود را بسازد (%s). افزونه را غیرفعال و دوباره فعال کنید یا دسترسی کاربر پایگاه داده را بررسی کنید.'
					),
					$missing
				)
			)
		);
	}

	/**
	 * Render queued settings notices, including a save confirmation.
	 */
	private function render_settings_notices() {
		if ( isset( $_GET['settings-updated'] ) && empty( get_settings_errors( WPNC_Settings::NOTICE_SLUG ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			WPNC_Settings::notify(
				'wpnc_saved',
				'Settings saved.',
				'تنظیمات ذخیره شد.',
				'success'
			);
		}

		settings_errors( WPNC_Settings::NOTICE_SLUG );
	}

	private function render_settings_tab() {
		$this->render_settings_notices();

		$interval        = get_option( 'wpnc_interval', 'hourly' );
		$target_pt       = WPNC_Settings::get_target_post_type();
		$default_cat     = absint( get_option( 'wpnc_default_category', 0 ) );
		$ai_provider     = WPNC_AI_Rewriter::provider();
		$ai_models       = get_option( 'wpnc_ai_models', array() );
		$ai_models       = is_array( $ai_models ) ? $ai_models : array();
		$has_telegram    = '' !== (string) get_option( 'wpnc_telegram_token', '' );
		$max_items       = absint( get_option( 'wpnc_max_items_per_feed', 20 ) );
		$timeout         = absint( get_option( 'wpnc_request_timeout', WPNC_Settings::DEFAULT_TIMEOUT ) );
		$admin_lang      = get_option( 'wpnc_admin_lang', 'fa' );
		$post_status     = WPNC_Settings::sanitize_post_status( get_option( 'wpnc_post_status', 'publish' ) );
		$post_author     = absint( get_option( 'wpnc_post_author', 0 ) );
		$auto_publish    = absint( get_option( 'wpnc_auto_publish', 0 ) );
		$queue_retention = absint( get_option( 'wpnc_queue_retention_days', WPNC_Settings::DEFAULT_QUEUE_RETENTION ) );
		$stagger_on      = absint( get_option( 'wpnc_stagger_enabled', 0 ) );
		$stagger_minutes = WPNC_Scheduler::interval_minutes();
		$stagger_pending = $stagger_on ? WPNC_Scheduler::pending_count() : 0;
		$log_retention   = absint( get_option( 'wpnc_log_retention_days', WPNC_Settings::DEFAULT_LOG_RETENTION ) );
		?>
		<?php $this->render_settings_nav(); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wpnc_settings_group' ); ?>
			<section id="wpnc-set-sources" class="wpnc-settings-group">
				<h3 class="wpnc-settings-group-title"><?php wpnc_e( 'Sources & fetching', 'منابع و دریافت' ); ?></h3>
				<p class="wpnc-settings-group-hint"><?php wpnc_e( 'Where the news comes from and how often it is collected.', 'خبرها از کجا می‌آیند و هر چند وقت یک‌بار جمع‌آوری می‌شوند.' ); ?></p>
				<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpnc_rss_links"><?php wpnc_e( 'RSS/Atom Sources', 'منابع RSS/Atom' ); ?></label></th>
					<td>
						<textarea id="wpnc_rss_links" name="wpnc_rss_links" rows="10" class="large-text code" dir="ltr"><?php echo esc_textarea( get_option( 'wpnc_rss_links', '' ) ); ?></textarea>
						<p class="description">
							<?php wpnc_e( 'One source per line. Formats: URL, URL|category_id, or URL|category_id|source_key.', 'هر منبع در یک خط. فرمت‌ها: URL یا URL|category_id یا URL|category_id|source_key.' ); ?>
							<br>
							<?php wpnc_e( 'Start a line with # to comment it out, or with ! to keep the source but pause it.', 'برای غیرفعال کردن کامل خط، آن را با # شروع کنید؛ برای نگه داشتن منبع ولی توقف موقت آن، با ! شروع کنید.' ); ?>
							<br>
							<?php wpnc_e( 'A source that keeps failing is paused automatically and resumes on its own once it responds.', 'منبعی که پیاپی خطا بدهد به‌طور خودکار موقتاً متوقف می‌شود و به‌محض پاسخ‌دهی دوباره فعال می‌شود.' ); ?>
						</p>
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
						<p class="description"><?php echo esc_html( $this->cron_status_text() ); ?></p>
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
				</table>
			</section>

			<section id="wpnc-set-filters" class="wpnc-settings-group">
				<h3 class="wpnc-settings-group-title"><?php wpnc_e( 'Filters', 'فیلترها' ); ?></h3>
				<p class="wpnc-settings-group-hint"><?php wpnc_e( 'Decide what never reaches the queue in the first place.', 'تعیین می‌کند چه چیزی از اساس وارد صف نشود.' ); ?></p>
				<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpnc_include_words"><?php wpnc_e( 'Must Include Words', 'باید شامل کلمات' ); ?></label></th>
					<td>
						<input id="wpnc_include_words" type="text" name="wpnc_include_words" value="<?php echo esc_attr( get_option( 'wpnc_include_words', '' ) ); ?>" class="large-text" dir="auto" />
						<p class="description"><?php wpnc_e( 'Comma separated. An item is kept if the title or description contains any one of these. Leave empty to keep everything.', 'با کاما جدا کنید. خبری نگه داشته می‌شود که عنوان یا توضیحاتش دست‌کم یکی از این کلمات را داشته باشد. برای نگه داشتن همه، خالی بگذارید.' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_exclude_words"><?php wpnc_e( 'Exclude Words', 'کلمات مستثنا' ); ?></label></th>
					<td>
						<input id="wpnc_exclude_words" type="text" name="wpnc_exclude_words" value="<?php echo esc_attr( get_option( 'wpnc_exclude_words', '' ) ); ?>" class="large-text" dir="auto" />
						<p class="description"><?php wpnc_e( 'Comma separated. Any match drops the item, and this wins over the include list. Matching is case-insensitive and matches inside words.', 'با کاما جدا کنید. هر تطابق باعث حذف خبر می‌شود و بر فهرست بالا اولویت دارد. تطابق به حروف کوچک و بزرگ حساس نیست و داخل کلمات هم بررسی می‌شود.' ); ?></p>
					</td>
				</tr>
				</table>
			</section>

			<section id="wpnc-set-publishing" class="wpnc-settings-group">
				<h3 class="wpnc-settings-group-title"><?php wpnc_e( 'Publishing', 'انتشار' ); ?></h3>
				<p class="wpnc-settings-group-hint"><?php wpnc_e( 'What an approved item turns into, and how fast it goes out.', 'یک آیتم تأییدشده به چه چیزی تبدیل می‌شود و با چه سرعتی منتشر می‌گردد.' ); ?></p>
				<table class="form-table" role="presentation">
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
					<th scope="row"><label for="wpnc_post_status"><?php wpnc_e( 'New Post Status', 'وضعیت پست جدید' ); ?></label></th>
					<td>
						<select id="wpnc_post_status" name="wpnc_post_status">
							<option value="publish" <?php selected( $post_status, 'publish' ); ?>><?php wpnc_e( 'Published', 'منتشرشده' ); ?></option>
							<option value="draft"   <?php selected( $post_status, 'draft' ); ?>><?php wpnc_e( 'Draft', 'پیش‌نویس' ); ?></option>
							<option value="pending" <?php selected( $post_status, 'pending' ); ?>><?php wpnc_e( 'Pending Review', 'در انتظار بازبینی' ); ?></option>
							<option value="private" <?php selected( $post_status, 'private' ); ?>><?php wpnc_e( 'Private', 'خصوصی' ); ?></option>
						</select>
						<p class="description"><?php wpnc_e( 'Approved items are created with this status. Choose Draft to review each item in the post editor before it goes live.', 'آیتم‌های تأییدشده با این وضعیت ساخته می‌شوند. برای بازبینی هر خبر در ویرایشگر پیش از انتشار، «پیش‌نویس» را انتخاب کنید.' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_post_author"><?php wpnc_e( 'Post Author', 'نویسنده پست' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_users(
							array(
								'id'               => 'wpnc_post_author',
								'name'             => 'wpnc_post_author',
								'selected'         => $post_author,
								'show_option_none' => wpnc__( 'First administrator', 'اولین مدیر' ),
								'option_none_value' => 0,
								'capability'       => array( 'edit_posts' ),
							)
						);
						?>
						<p class="description"><?php wpnc_e( 'Scheduled imports have no logged-in user, so they are attributed to this author.', 'دریافت زمان‌بندی‌شده کاربر واردشده ندارد، پس پست‌ها به نام این نویسنده ثبت می‌شوند.' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php wpnc_e( 'Publishing', 'انتشار' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="wpnc_auto_publish" name="wpnc_auto_publish" value="1" <?php checked( $auto_publish, 1 ); ?> />
							<?php wpnc_e( 'Publish directly without moderation queue', 'انتشار مستقیم بدون صف تأیید' ); ?>
						</label>
						<p class="description wpnc-warning-text">
							<?php wpnc_e( 'With this on, every matching item goes straight to your site on the next scheduled run, with no review and no undo. The queue tabs will stay empty.', 'با فعال بودن این گزینه، هر خبر منطبق در اجرای زمان‌بندی بعدی مستقیماً روی سایت منتشر می‌شود؛ بدون بازبینی و بدون امکان بازگشت. صف تأیید خالی می‌ماند.' ); ?>
						</p>
						<label>
							<input type="checkbox" name="wpnc_extract_full_text" value="1" <?php checked( get_option( 'wpnc_extract_full_text', 0 ), 1 ); ?> />
							<?php wpnc_e( 'Attempt full-text extraction from article pages', 'استخراج متن کامل از صفحه مقاله' ); ?>
						</label>
						<p class="description"><?php wpnc_e( 'Fetches each article page and keeps its paragraphs as plain text. Images and links inside the article are not preserved.', 'صفحه هر مقاله را دریافت و پاراگراف‌هایش را به‌صورت متن ساده نگه می‌دارد. تصاویر و لینک‌های داخل مقاله حفظ نمی‌شوند.' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_content_template"><?php wpnc_e( 'Post Template', 'قالب پست' ); ?></label></th>
					<td>
						<textarea id="wpnc_content_template" name="wpnc_content_template" rows="6" class="large-text code" dir="ltr"><?php echo esc_textarea( get_option( 'wpnc_content_template', WPNC_Template::DEFAULT_TEMPLATE ) ); ?></textarea>
						<p class="description">
							<?php wpnc_e( 'How the body of each published post is assembled. Leave empty for the default.', 'بدنه هر پست منتشرشده چطور ساخته شود. برای حالت پیش‌فرض خالی بگذارید.' ); ?>
						</p>
						<div class="wpnc-placeholders">
							<?php foreach ( WPNC_Template::placeholders() as $token => $label ) : ?>
								<span><code><?php echo esc_html( $token ); ?></code> <?php echo esc_html( $label ); ?></span>
							<?php endforeach; ?>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php wpnc_e( 'Publication Pacing', 'سرعت انتشار' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wpnc_stagger_enabled" value="1" <?php checked( $stagger_on, 1 ); ?> />
							<?php wpnc_e( 'Space published items out instead of publishing them all at once', 'انتشار خبرها با فاصله، به‌جای انتشار هم‌زمان همه' ); ?>
						</label>
						<p>
							<label for="wpnc_stagger_minutes"><?php wpnc_e( 'One item every', 'هر خبر با فاصله' ); ?></label>
							<input id="wpnc_stagger_minutes" type="number" min="1" max="1440" name="wpnc_stagger_minutes" value="<?php echo esc_attr( $stagger_minutes ); ?>" />
							<?php wpnc_e( 'minutes', 'دقیقه' ); ?>
						</p>
						<p class="description">
							<?php wpnc_e( 'Approving twenty items would otherwise put twenty posts on the site in the same second. With pacing on they are scheduled ahead instead; the first one still goes out immediately.', 'بدون این گزینه، تأیید بیست خبر یعنی بیست پست در یک لحظه روی سایت. با فعال بودن آن، خبرها زمان‌بندی می‌شوند؛ اولی همچنان فوری منتشر می‌شود.' ); ?>
							<?php if ( $stagger_on && $stagger_pending ) : ?>
								<br>
								<strong><?php echo esc_html( sprintf( wpnc__( '%d items are currently scheduled and waiting.', 'هم‌اکنون %d خبر زمان‌بندی شده و در انتظار است.' ), $stagger_pending ) ); ?></strong>
							<?php endif; ?>
						</p>
						<p class="description wpnc-warning-text">
							<?php wpnc_e( 'Scheduled posts need WP-Cron to fire, so a site with DISABLE_WP_CRON must have a real cron job calling wp-cron.php.', 'پست‌های زمان‌بندی‌شده برای انتشار به WP-Cron نیاز دارند؛ اگر DISABLE_WP_CRON فعال است باید یک کران واقعی wp-cron.php را صدا بزند.' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_default_image"><?php wpnc_e( 'Fallback Image URL', 'آدرس تصویر پیش‌فرض' ); ?></label></th>
					<td><input id="wpnc_default_image" type="url" name="wpnc_default_image" value="<?php echo esc_url( get_option( 'wpnc_default_image', '' ) ); ?>" class="large-text" dir="ltr" /></td>
				</tr>
				</table>
			</section>

			<section id="wpnc-set-housekeeping" class="wpnc-settings-group">
				<h3 class="wpnc-settings-group-title"><?php wpnc_e( 'Housekeeping', 'نگهداری' ); ?></h3>
				<p class="wpnc-settings-group-hint"><?php wpnc_e( 'How long processed items and log lines are kept before deletion.', 'آیتم‌های پردازش‌شده و خطوط لاگ چه مدت پیش از حذف نگه داشته می‌شوند.' ); ?></p>
				<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpnc_queue_retention_days"><?php wpnc_e( 'Keep Processed Items', 'نگهداری آیتم‌های پردازش‌شده' ); ?></label></th>
					<td>
						<input id="wpnc_queue_retention_days" type="number" min="1" max="365" name="wpnc_queue_retention_days" value="<?php echo esc_attr( $queue_retention ); ?>" />
						<?php wpnc_e( 'days', 'روز' ); ?>
						<p class="description wpnc-warning-text"><?php wpnc_e( 'Approved and rejected queue rows are permanently deleted after this many days. Published posts are never touched.', 'ردیف‌های تأییدشده و ردشده صف پس از این تعداد روز برای همیشه حذف می‌شوند. پست‌های منتشرشده دست نمی‌خورند.' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_log_retention_days"><?php wpnc_e( 'Keep Logs', 'نگهداری لاگ‌ها' ); ?></label></th>
					<td>
						<input id="wpnc_log_retention_days" type="number" min="1" max="365" name="wpnc_log_retention_days" value="<?php echo esc_attr( $log_retention ); ?>" />
						<?php wpnc_e( 'days', 'روز' ); ?>
					</td>
				</tr>
				</table>
			</section>

			<section id="wpnc-set-general" class="wpnc-settings-group">
				<h3 class="wpnc-settings-group-title"><?php wpnc_e( 'Panel', 'پنل' ); ?></h3>
				<p class="wpnc-settings-group-hint"><?php wpnc_e( 'Settings for this admin panel itself.', 'تنظیمات مربوط به خود همین پنل مدیریت.' ); ?></p>
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
				</table>
			</section>

			<section id="wpnc-set-ai" class="wpnc-settings-group">
			<h3 class="wpnc-settings-group-title"><?php wpnc_e( 'AI Assistant', 'دستیار هوش مصنوعی' ); ?></h3>
			<p class="wpnc-settings-group-hint"><?php wpnc_e( 'Which model rewrites an article, and the keys it rotates through.', 'کدام مدل خبر را بازنویسی می‌کند و کلیدهایی که به‌نوبت مصرف می‌شوند.' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wpnc_ai_provider"><?php wpnc_e( 'Provider', 'ارائه‌دهنده' ); ?></label></th>
					<td>
						<select id="wpnc_ai_provider" name="wpnc_ai_provider">
							<?php foreach ( WPNC_AI_Providers::all() as $slug => $provider ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $ai_provider, $slug ); ?>>
									<?php echo esc_html( $provider['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php wpnc_e( 'Only the selected provider is used. Keys for the others stay saved, so you can switch back without re-entering them.', 'فقط ارائه‌دهنده انتخاب‌شده استفاده می‌شود. کلیدهای بقیه ذخیره می‌مانند تا بدون وارد کردن دوباره بتوانید برگردید.' ); ?></p>
					</td>
				</tr>
			</table>

			<?php foreach ( WPNC_AI_Providers::all() as $slug => $provider ) : ?>
				<?php $status = WPNC_AI_Keys::status( $slug ); ?>
				<div class="wpnc-provider" data-provider="<?php echo esc_attr( $slug ); ?>" <?php echo $ai_provider === $slug ? '' : 'hidden'; ?>>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wpnc_ai_model_<?php echo esc_attr( $slug ); ?>"><?php wpnc_e( 'Model', 'مدل' ); ?></label>
							</th>
							<td>
								<input id="wpnc_ai_model_<?php echo esc_attr( $slug ); ?>"
									type="text"
									name="wpnc_ai_models[<?php echo esc_attr( $slug ); ?>]"
									value="<?php echo esc_attr( isset( $ai_models[ $slug ] ) ? $ai_models[ $slug ] : '' ); ?>"
									placeholder="<?php echo esc_attr( $provider['default_model'] ); ?>"
									class="regular-text" dir="ltr" />
								<p class="description">
									<?php
									printf(
										/* translators: %s: default model name */
										esc_html( wpnc__( 'Leave blank to use %s.', 'برای استفاده از %s خالی بگذارید.' ) ),
										'<code>' . esc_html( $provider['default_model'] ) . '</code>'
									);
									?>
									<?php wpnc_e( 'A model name that does not exist makes every request fail; the reason is recorded in the logs.', 'نام مدل نادرست باعث می‌شود هر درخواست شکست بخورد؛ دلیل در لاگ‌ها ثبت می‌شود.' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php wpnc_e( 'API Keys', 'کلیدهای API' ); ?></th>
							<td>
								<div class="wpnc-keys" data-provider="<?php echo esc_attr( $slug ); ?>">
									<?php foreach ( $status as $id => $info ) : ?>
										<div class="wpnc-key-row<?php echo $info['resting'] ? ' is-resting' : ''; ?>">
											<input type="password"
												name="wpnc_ai_keys[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $id ); ?>]"
												value=""
												placeholder="<?php echo esc_attr( $info['masked'] ); ?>"
												class="regular-text" autocomplete="off" dir="ltr" />
											<?php if ( $info['resting'] ) : ?>
												<span class="wpnc-key-flag" title="<?php echo esc_attr( $info['reason'] ); ?>">
													<?php
													printf(
														/* translators: %s: human readable duration */
														esc_html( wpnc__( 'resting, retries in %s', 'موقتاً کنار گذاشته شده، تلاش بعدی تا %s دیگر' ) ),
														esc_html( human_time_diff( time(), $info['until'] ) )
													);
													?>
												</span>
											<?php endif; ?>
											<button type="button" class="button button-small wpnc-key-remove">
												<?php wpnc_e( 'Remove', 'حذف' ); ?>
											</button>
										</div>
									<?php endforeach; ?>
								</div>

								<p class="wpnc-keys-actions">
									<button type="button" class="button wpnc-key-add" data-provider="<?php echo esc_attr( $slug ); ?>">
										<?php wpnc_e( 'Add a key', 'افزودن کلید' ); ?>
									</button>
									<a href="<?php echo esc_url( $provider['keys_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="wpnc-keys-link">
										<?php wpnc_e( 'Where to get one', 'کلید را از کجا بگیرم' ); ?>
									</a>
								</p>

								<p class="description">
									<?php wpnc_e( 'Keys are tried in order. When one reports no credit or a rate limit, the next is used and the spent one is set aside for half an hour.', 'کلیدها به ترتیب امتحان می‌شوند. وقتی یکی اعتبار نداشته باشد یا به محدودیت نرخ بخورد، کلید بعدی استفاده می‌شود و کلید تمام‌شده نیم ساعت کنار گذاشته می‌شود.' ); ?>
									<br>
									<?php wpnc_e( 'An existing key shows only its first and last characters. Leave a row blank to keep it, or press Remove to delete it.', 'از کلید ذخیره‌شده فقط چند نویسه اول و آخر نمایش داده می‌شود. برای حفظ آن، خانه را خالی بگذارید؛ برای حذف، «حذف» را بزنید.' ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>
			<?php endforeach; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php wpnc_e( 'AI Options', 'تنظیمات AI' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wpnc_auto_rewrite" value="1" <?php checked( get_option( 'wpnc_auto_rewrite', 0 ), 1 ); ?> />
							<?php wpnc_e( 'Rewrite title & description, generate tags before queue/publish', 'بازنویسی عنوان و توضیحات، تولید تگ قبل از صف/انتشار' ); ?>
						</label>
						<p class="description wpnc-warning-text"><?php wpnc_e( 'This calls the provider once per imported item, which costs money on every fetch. The editor buttons cost nothing until you press them.', 'این گزینه برای هر خبر واردشده یک درخواست می‌فرستد، یعنی هر دریافت هزینه دارد. دکمه‌های داخل ویرایشگر تا وقتی نزنید هزینه‌ای ندارند.' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpnc_target_language"><?php wpnc_e( 'Target Language', 'زبان هدف بازنویسی' ); ?></label></th>
					<td><input id="wpnc_target_language" type="text" name="wpnc_target_language" value="<?php echo esc_attr( get_option( 'wpnc_target_language', '' ) ); ?>" class="regular-text" dir="auto" placeholder="<?php echo esc_attr( wpnc__( 'e.g. Persian, English', 'مثلاً: Persian یا Farsi' ) ); ?>" /></td>
				</tr>
			</table>

			</section>

			<section id="wpnc-set-telegram" class="wpnc-settings-group">
			<h3 class="wpnc-settings-group-title"><?php wpnc_e( 'Telegram Auto-Post', 'ارسال خودکار به تلگرام' ); ?></h3>
			<p class="wpnc-settings-group-hint"><?php wpnc_e( 'Optional. Mirror every published item to a Telegram chat or channel.', 'اختیاری. هر خبر منتشرشده را در یک چت یا کانال تلگرام هم بازنشر می‌کند.' ); ?></p>
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
					<td>
						<input id="wpnc_telegram_chat_id" type="text" name="wpnc_telegram_chat_id" value="<?php echo esc_attr( get_option( 'wpnc_telegram_chat_id', '' ) ); ?>" class="regular-text" dir="ltr" />
						<p class="description"><?php wpnc_e( 'Numeric id for a chat, or @channelname for a public channel. Both token and chat id must be set or nothing is sent.', 'شناسه عددی چت، یا @نام‌کانال برای کانال عمومی. تا هر دو مقدار توکن و شناسه پر نشوند چیزی ارسال نمی‌شود.' ); ?></p>
					</td>
				</tr>
			</table>

			</section>

			<div class="wpnc-save-bar">
				<?php submit_button( wpnc__( 'Save Settings', 'ذخیره تنظیمات' ), 'primary', 'submit', false ); ?>
				<span class="wpnc-save-hint"><?php wpnc_e( 'Nothing on this screen takes effect until it is saved.', 'هیچ‌کدام از تنظیمات این صفحه تا زمانی که ذخیره نشوند اعمال نمی‌شوند.' ); ?></span>
			</div>
		</form>
		<?php
	}

	/**
	 * Jump links across the settings sections.
	 *
	 * The screen is one long form by necessity - it posts to options.php in a
	 * single request - so the only way to make it navigable is to say up front
	 * what is on it and let people jump.
	 */
	private function render_settings_nav() {
		$sections = array(
			'wpnc-set-sources'      => wpnc__( 'Sources & fetching', 'منابع و دریافت' ),
			'wpnc-set-filters'      => wpnc__( 'Filters', 'فیلترها' ),
			'wpnc-set-publishing'   => wpnc__( 'Publishing', 'انتشار' ),
			'wpnc-set-housekeeping' => wpnc__( 'Housekeeping', 'نگهداری' ),
			'wpnc-set-general'      => wpnc__( 'Panel', 'پنل' ),
			'wpnc-set-ai'           => wpnc__( 'AI Assistant', 'دستیار هوش مصنوعی' ),
			'wpnc-set-telegram'     => wpnc__( 'Telegram Auto-Post', 'ارسال خودکار به تلگرام' ),
		);
		?>
		<nav class="wpnc-settings-nav" aria-label="<?php echo esc_attr( wpnc__( 'Settings sections', 'بخش‌های تنظیمات' ) ); ?>">
			<?php foreach ( $sections as $anchor => $label ) : ?>
				<a href="#<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * One line describing whether scheduling actually works and when it next
	 * runs. 'When does it fetch again?' had no answer anywhere in the UI.
	 *
	 * @return string
	 */
	private function cron_status_text() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return wpnc__(
				'WP-Cron is disabled on this site (DISABLE_WP_CRON), so scheduled fetches only run if a real cron job calls wp-cron.php. Use Fetch Now meanwhile.',
				'WP-Cron در این سایت غیرفعال است (DISABLE_WP_CRON)، پس دریافت زمان‌بندی‌شده فقط زمانی اجرا می‌شود که یک کران واقعی wp-cron.php را صدا بزند. تا آن زمان از «دریافت فوری» استفاده کنید.'
			);
		}

		$next = wp_next_scheduled( 'wpnc_fetch_news_event' );
		if ( ! $next ) {
			return wpnc__(
				'No fetch is scheduled yet. It is registered on the next page load.',
				'هنوز دریافتی زمان‌بندی نشده است. در بارگذاری بعدی صفحه ثبت می‌شود.'
			);
		}

		if ( $next <= time() ) {
			return wpnc__(
				'The next fetch is due and will run on the next visit to the site.',
				'زمان دریافت بعدی رسیده و با اولین بازدید از سایت اجرا می‌شود.'
			);
		}

		return sprintf(
			/* translators: 1: human readable duration, 2: local date and time */
			wpnc__( 'Next scheduled fetch in %1$s (%2$s).', 'دریافت زمان‌بندی‌شده بعدی تا %1$s دیگر (%2$s).' ),
			human_time_diff( time(), $next ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next )
		);
	}

	private function render_dashboard_tab() {
		?>
		<div class="wpnc-dash">
			<div class="wpnc-dash-head">
				<div>
					<h2 class="wpnc-dash-title"><?php wpnc_e( 'Overview', 'نمای کلی' ); ?></h2>
					<p class="wpnc-dash-sub" id="wpnc-dash-subtitle"></p>
				</div>
				<p class="wpnc-dash-actions">
					<button type="button" class="button button-primary" id="wpnc-dash-fetch">
						<?php wpnc_e( 'Fetch Now', 'دریافت فوری' ); ?>
					</button>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'boz-news', 'tab' => 'moderation' ), admin_url( 'admin.php' ) ) ); ?>">
						<?php wpnc_e( 'Open the queue', 'رفتن به صف تأیید' ); ?>
					</a>
				</p>
			</div>

			<div id="wpnc-dash-status" class="wpnc-dash-status"></div>
			<div id="wpnc-dash-app"></div>
		</div>
		<?php
	}

	private function render_moderation_tab() {
		?>
		<div id="wpnc-moderation-app"></div>
		<?php
	}

	/**
	 * The CSV export link.
	 *
	 * It lives in the page header rather than above the queue: it acts on the
	 * whole view, and sitting in its own strip between the tabs and the
	 * filters it exports made it look like part of neither.
	 *
	 * Kept outside #wpnc-moderation-app because the queue redraws that whole
	 * element, and syncExportLink() has to find this anchor afterwards.
	 */
	private function render_export_link() {
		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'wpnc_export_queue',
					'status' => 'pending',
				),
				admin_url( 'admin-post.php' )
			),
			'wpnc_export_queue',
			'wpnc_nonce'
		);
		?>
		<a class="button" id="wpnc-export-queue" href="<?php echo esc_url( $export_url ); ?>">
			<?php wpnc_e( 'Export this view (CSV)', 'خروجی این نما (CSV)' ); ?>
		</a>
		<?php
	}

	/**
	 * Stream the current queue view as CSV.
	 *
	 * The queue had no export at all, so the only way data left this plugin
	 * was the retention job deleting it.
	 */
	public function export_queue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html( wpnc__( 'You do not have permission to export the queue.', 'شما اجازه گرفتن خروجی از صف را ندارید.' ) ),
				esc_html( wpnc__( 'Boz News', 'بُز نیوز' ) ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'wpnc_export_queue', 'wpnc_nonce' );

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending';
		$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header(
			'Content-Disposition: attachment; filename=boz-news-' . $status . '-' .
			gmdate( 'Ymd-His' ) . '.csv'
		);

		$out = fopen( 'php://output', 'w' );

		// BOM so Excel opens the Persian columns as UTF-8 rather than mojibake.
		fwrite( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv(
			$out,
			array( 'id', 'status', 'source_name', 'source_key', 'title', 'main_link', 'image_url', 'tags', 'pub_date_utc', 'post_id', 'error_message' )
		);

		$queue = new WPNC_Queue_Repository();
		$escape = array( 'WPNC_Queue_Repository', 'csv_cell' );
		$queue->each_for_export(
			array(
				'status' => $status,
				'search' => $search,
			),
			function ( $row ) use ( $out, $escape ) {
				fputcsv(
					$out,
					array_map(
						$escape,
						array(
							$row['id'],
							$row['status'],
							$row['source_name'],
							$row['source_key'],
							$row['title'],
							$row['main_link'],
							$row['image_url'],
							$row['tags'],
							$row['pub_date'],
							$row['post_id'],
							$row['error_message'],
						)
					)
				);
			}
		);

		fclose( $out );
		exit;
	}

	private function render_logs_tab() {
		$last_run_raw = (string) get_option( 'wpnc_last_run', '' );
		$last_run     = '' === $last_run_raw
			? wpnc__( 'Never', 'هرگز' )
			: WPNC_Time::for_display( $last_run_raw );
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
		<div class="wpnc-panel">
			<h3><?php wpnc_e( 'Source Health', 'وضعیت منابع' ); ?></h3>
			<?php $this->render_source_health(); ?>
		</div>

		<div class="wpnc-panel">
			<h3><?php wpnc_e( 'Queue Statistics', 'آمار صف' ); ?></h3>
			<div id="wpnc-stats-summary"></div>
		</div>

		<div class="wpnc-panel">
			<h3><?php wpnc_e( 'Recent Logs', 'لاگ‌های اخیر' ); ?></h3>
			<p class="wpnc-tab-toolbar">
				<label for="wpnc-log-level"><?php wpnc_e( 'Level:', 'سطح:' ); ?></label>
				<select id="wpnc-log-level">
					<option value=""><?php wpnc_e( 'All', 'همه' ); ?></option>
					<option value="error"><?php wpnc_e( 'Errors only', 'فقط خطاها' ); ?></option>
					<option value="warning"><?php wpnc_e( 'Warnings only', 'فقط هشدارها' ); ?></option>
					<option value="info"><?php wpnc_e( 'Info only', 'فقط اطلاعات' ); ?></option>
				</select>
			</p>
			<div id="wpnc-logs-app"></div>
		</div>
		<?php
	}

	/**
	 * Per-source state: enabled, last result, and whether a backoff is active.
	 */
	private function render_source_health() {
		$fetcher = new WPNC_Fetcher();
		$sources = $fetcher->get_sources();

		if ( empty( $sources ) ) {
			printf(
				'<div class="wpnc-state wpnc-state-empty"><p class="wpnc-state-title">%s</p><p class="wpnc-state-hint">%s</p></div>',
				esc_html( wpnc__( 'No sources configured yet.', 'هنوز منبعی تنظیم نشده است.' ) ),
				esc_html( wpnc__( 'Add one RSS or Atom URL per line under Settings.', 'در تب تنظیمات، هر آدرس RSS یا Atom را در یک خط اضافه کنید.' ) )
			);
			return;
		}

		$health = $fetcher->get_source_health();
		?>
		<div class="wpnc-table-scroll">
			<table class="widefat striped wpnc-health-table">
				<thead>
					<tr>
						<th><?php wpnc_e( 'Source', 'منبع' ); ?></th>
						<th><?php wpnc_e( 'State', 'وضعیت' ); ?></th>
						<th><?php wpnc_e( 'Last Success', 'آخرین موفقیت' ); ?></th>
						<th><?php wpnc_e( 'Last Result', 'آخرین نتیجه' ); ?></th>
						<th><?php wpnc_e( 'Actions', 'عملیات' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $sources as $index => $source ) : ?>
					<?php
					$id      = $source['id'];
					$record  = isset( $health[ $id ] ) && is_array( $health[ $id ] ) ? $health[ $id ] : array();
					$fails   = absint( isset( $record['fails'] ) ? $record['fails'] : 0 );
					$last_ok = absint( isset( $record['last_ok'] ) ? $record['last_ok'] : 0 );
					$cooling = $fetcher->cooldown_remaining( $id );

					if ( empty( $source['valid'] ) ) {
						$state = wpnc__( 'Unsafe URL', 'آدرس ناامن' );
						$class = 'wpnc-health-bad';
					} elseif ( empty( $source['enabled'] ) ) {
						$state = wpnc__( 'Paused by you', 'متوقف‌شده توسط شما' );
						$class = 'wpnc-health-off';
					} elseif ( $cooling > 0 ) {
						$state = sprintf(
							/* translators: %s: human readable duration */
							wpnc__( 'Backing off, retry in %s', 'توقف موقت، تلاش بعدی تا %s دیگر' ),
							human_time_diff( time(), time() + $cooling )
						);
						$class = 'wpnc-health-bad';
					} elseif ( $fails > 0 ) {
						$state = sprintf(
							/* translators: %d: consecutive failure count */
							wpnc__( 'Failing (%d in a row)', 'خطا (%d بار پیاپی)' ),
							$fails
						);
						$class = 'wpnc-health-warn';
					} elseif ( $last_ok ) {
						$state = wpnc__( 'OK', 'سالم' );
						$class = 'wpnc-health-ok';
					} else {
						$state = wpnc__( 'Not fetched yet', 'هنوز دریافت نشده' );
						$class = 'wpnc-health-off';
					}

					$result = '';
					if ( ! empty( $record['last_error'] ) ) {
						$result = (string) $record['last_error'];
					} elseif ( $last_ok ) {
						$result = sprintf(
							/* translators: %d: item count */
							wpnc__( '%d items', '%d آیتم' ),
							absint( isset( $record['last_items'] ) ? $record['last_items'] : 0 )
						);
					}
					?>
					<tr>
						<td dir="ltr" class="wpnc-health-url"><?php echo esc_html( $source['source_key'] ? $source['source_key'] . ' — ' . $source['url'] : $source['url'] ); ?></td>
						<td><span class="wpnc-badge <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $state ); ?></span></td>
						<td><?php echo esc_html( $last_ok ? WPNC_Time::for_display( gmdate( 'Y-m-d H:i:s', $last_ok ) ) : '—' ); ?></td>
						<td dir="auto"><?php echo esc_html( $result ); ?></td>
						<td class="wpnc-health-actions"
							data-index="<?php echo esc_attr( $index ); ?>"
							data-source-id="<?php echo esc_attr( $id ); ?>"
							data-enabled="<?php echo esc_attr( empty( $source['enabled'] ) ? '0' : '1' ); ?>">
							<button type="button" class="button button-small wpnc-test-source"><?php wpnc_e( 'Test', 'تست' ); ?></button>
							<button type="button" class="button button-small wpnc-toggle-source">
								<?php echo esc_html( empty( $source['enabled'] ) ? wpnc__( 'Resume', 'فعال‌سازی' ) : wpnc__( 'Pause', 'توقف' ) ); ?>
							</button>
							<?php if ( $fails > 0 ) : ?>
								<button type="button" class="button button-small wpnc-reset-health"><?php wpnc_e( 'Reset', 'پاک کردن خطا' ); ?></button>
							<?php endif; ?>
							<span class="wpnc-health-result" dir="auto"></span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Keep the template's markup but warn about the one mistake that silently
	 * publishes empty posts.
	 *
	 * @param string $value Raw template.
	 * @return string
	 */
	public function sanitize_template( $value ) {
		$value = wp_kses_post( (string) $value );

		if ( WPNC_Template::omits_content( $value ) ) {
			WPNC_Settings::notify(
				'wpnc_template_no_content',
				'The template has no {content} placeholder, so published posts will not contain the article.',
				'قالب جای‌نگهدار {content} ندارد، پس پست‌های منتشرشده متن خبر را نخواهند داشت.',
				'warning'
			);
		}

		return $value;
	}

	public function sanitize_rss_links( $value ) {
		$reader  = new WPNC_Feed_Reader();
		$sources = $reader->parse_sources( $value );
		$lines   = array();
		$dropped = array();
		$unsafe  = array();

		foreach ( $sources as $source ) {
			$line = WPNC_Feed_Reader::to_line( $source );

			if ( '' === $line ) {
				// esc_url_raw could make nothing of it, so it cannot be kept.
				$dropped[] = isset( $source['raw_url'] ) ? $source['raw_url'] : '';
				continue;
			}

			if ( empty( $source['valid'] ) ) {
				// Kept on purpose: the admin needs to see and fix the line
				// rather than have it vanish out of the textarea.
				$unsafe[] = $source['url'];
			}

			$lines[] = $line;
		}

		if ( ! empty( $dropped ) ) {
			WPNC_Settings::notify(
				'wpnc_sources_dropped',
				sprintf(
					'These source lines were not valid URLs and were removed: %s',
					implode( ', ', array_map( 'sanitize_text_field', $dropped ) )
				),
				sprintf(
					'این خطوط منبع آدرس معتبری نبودند و حذف شدند: %s',
					implode( '، ', array_map( 'sanitize_text_field', $dropped ) )
				)
			);
		}

		if ( ! empty( $unsafe ) ) {
			WPNC_Settings::notify(
				'wpnc_sources_unsafe',
				sprintf(
					'These sources point at a private or unreachable host and will be skipped: %s',
					implode( ', ', $unsafe )
				),
				sprintf(
					'این منابع به میزبان خصوصی یا در دسترس نیستند و نادیده گرفته می‌شوند: %s',
					implode( '، ', $unsafe )
				),
				'warning'
			);
		}

		return implode( "\n", $lines );
	}

	public function sanitize_telegram_token( $value ) {
		return WPNC_Settings::sanitize_secret( $value, 'wpnc_telegram_token' );
	}
}

new WPNC_Admin();
