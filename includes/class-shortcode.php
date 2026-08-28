<?php
/**
 * Frontend shortcode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Shortcode {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_shortcode( 'news_bulletin', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'    => 10,
				'category' => '',
			),
			$atts,
			'news_bulletin'
		);

		$limit    = max( 1, min( 50, absint( $atts['limit'] ) ) );
		$category = sanitize_text_field( $atts['category'] );
		$post_type = WPNC_Settings::get_target_post_type();
		$uid      = 'wpnc-news-list-' . wp_rand( 1000, 999999 );

		wp_enqueue_style( 'wpnc-frontend-style' );
		wp_enqueue_script( 'wpnc-frontend-script' );

		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => $limit,
			'post_status'    => 'publish',
			'no_found_rows'  => false,
		);

		if ( ! empty( $category ) ) {
			$args['category_name'] = $category;
		}

		$query = new WP_Query( $args );

		if ( ! $query->have_posts() ) {
			return '<p>' . esc_html__( 'No news found.', 'wp-news-collector' ) . '</p>';
		}

		ob_start();
		?>
		<div class="wpnc-news-container" data-wpnc-container="<?php echo esc_attr( $uid ); ?>">
			<div class="wpnc-news-list" id="<?php echo esc_attr( $uid ); ?>">
				<?php
				while ( $query->have_posts() ) {
					$query->the_post();
					self::render_news_item();
				}
				wp_reset_postdata();
				?>
			</div>
			<?php if ( $query->max_num_pages > 1 ) : ?>
				<div class="wpnc-load-more-wrapper">
					<button class="wpnc-load-more-btn" type="button" data-page="1" data-limit="<?php echo esc_attr( $limit ); ?>" data-category="<?php echo esc_attr( $category ); ?>" data-max-pages="<?php echo esc_attr( $query->max_num_pages ); ?>">
						<?php esc_html_e( 'Load More News', 'wp-news-collector' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render one news item.
	 */
	public static function render_news_item() {
		?>
		<article class="wpnc-news-item">
			<h3 class="wpnc-news-title"><a href="<?php echo esc_url( get_permalink() ); ?>"><?php echo esc_html( get_the_title() ); ?></a></h3>
			<div class="wpnc-news-meta">
				<span class="wpnc-news-date"><?php echo esc_html( get_the_date() ); ?></span>
			</div>
			<div class="wpnc-news-excerpt">
				<?php echo wp_kses_post( wp_trim_words( get_the_content(), 55 ) ); ?>
			</div>
		</article>
		<?php
	}
}

new WPNC_Shortcode();
