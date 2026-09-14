<?php
/**
 * The news list on the site: shortcode, block, and the items both render.
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
	 * Render the shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		return self::render( shortcode_atts( WPNC_Bulletin::defaults(), $atts, 'news_bulletin' ) );
	}

	/**
	 * Server render for the News bulletin block.
	 *
	 * @param array $attributes Block attributes, typed.
	 * @return string
	 */
	public static function render_block( $attributes ) {
		return self::render( is_array( $attributes ) ? $attributes : array() );
	}

	/**
	 * The list itself, for either caller.
	 *
	 * @param array $raw Options, in either the shortcode's or the block's form.
	 * @return string
	 */
	public static function render( $raw ) {
		$args      = WPNC_Bulletin::args( $raw );
		$post_type = WPNC_Settings::get_target_post_type();
		$uid       = 'wpnc-news-list-' . wp_rand( 1000, 999999 );

		wp_enqueue_style( 'wpnc-frontend-style' );
		wp_enqueue_script( 'wpnc-frontend-script' );

		$query_args = array(
			'post_type'      => $post_type,
			'posts_per_page' => $args['limit'],
			'post_status'    => 'publish',
			'no_found_rows'  => false,
		);

		if ( '' !== $args['category'] ) {
			$query_args['category_name'] = $args['category'];
		}

		$query = new WP_Query( $query_args );

		if ( ! $query->have_posts() ) {
			return '<p class="wpnc-news-empty">' . esc_html__( 'No news found.', 'wp-news-collector' ) . '</p>';
		}

		ob_start();
		?>
		<div class="wpnc-news-container" data-wpnc-container="<?php echo esc_attr( $uid ); ?>">
			<div class="wpnc-news-list is-<?php echo esc_attr( $args['layout'] ); ?>" id="<?php echo esc_attr( $uid ); ?>">
				<?php
				while ( $query->have_posts() ) {
					$query->the_post();
					self::render_news_item( $args );
				}
				wp_reset_postdata();
				?>
			</div>
			<?php if ( $query->max_num_pages > 1 ) : ?>
				<div class="wpnc-load-more-wrapper">
					<?php // The list's own display choices travel with Load More, so what loads in matches what is already there. ?>
					<button class="wpnc-load-more-btn" type="button"
						data-page="1"
						data-limit="<?php echo esc_attr( $args['limit'] ); ?>"
						data-category="<?php echo esc_attr( $args['category'] ); ?>"
						data-layout="<?php echo esc_attr( $args['layout'] ); ?>"
						data-image="<?php echo esc_attr( $args['image'] ? '1' : '0' ); ?>"
						data-excerpt="<?php echo esc_attr( $args['excerpt'] ); ?>"
						data-source="<?php echo esc_attr( $args['source'] ? '1' : '0' ); ?>"
						data-max-pages="<?php echo esc_attr( $query->max_num_pages ); ?>">
						<?php esc_html_e( 'Load More News', 'wp-news-collector' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
		<?php

		return ob_get_clean();
	}

	/**
	 * One item in the list, for the post currently in the loop.
	 *
	 * The picture is a link hidden from assistive technology: the headline
	 * next to it is the same link, and announcing it twice helps nobody.
	 *
	 * @param array $raw Options.
	 */
	public static function render_news_item( $raw = array() ) {
		$args    = WPNC_Bulletin::args( $raw );
		$post_id = get_the_ID();
		$image   = $args['image'] && has_post_thumbnail( $post_id );
		$source  = $args['source'] ? (string) get_post_meta( $post_id, '_wpnc_source_name', true ) : '';
		$text    = has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : strip_shortcodes( get_the_content( null, false, $post_id ) );
		$summary = WPNC_Bulletin::excerpt( $text, $args['excerpt'] );
		?>
		<article class="wpnc-news-item<?php echo $image ? ' has-image' : ''; ?>">
			<?php if ( $image ) : ?>
				<a class="wpnc-news-thumb" href="<?php echo esc_url( get_permalink( $post_id ) ); ?>" tabindex="-1" aria-hidden="true">
					<?php echo get_the_post_thumbnail( $post_id, 'medium_large', array( 'loading' => 'lazy', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built markup. ?>
				</a>
			<?php endif; ?>
			<div class="wpnc-news-body">
				<h3 class="wpnc-news-title"><a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a></h3>
				<div class="wpnc-news-meta">
					<time class="wpnc-news-date" datetime="<?php echo esc_attr( get_the_date( 'c', $post_id ) ); ?>"><?php echo esc_html( get_the_date( '', $post_id ) ); ?></time>
					<?php if ( '' !== $source ) : ?>
						<span class="wpnc-news-source"><?php echo esc_html( $source ); ?></span>
					<?php endif; ?>
				</div>
				<?php if ( '' !== $summary ) : ?>
					<p class="wpnc-news-excerpt"><?php echo esc_html( $summary ); ?></p>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}
}

new WPNC_Shortcode();
