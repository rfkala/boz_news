<?php
/**
 * Elementor widget for News Collector.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPNC_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'wpnc_news_widget';
	}

	public function get_title() {
		return __( 'News Collector Bulletin', 'wp-news-collector' );
	}

	public function get_icon() {
		return 'eicon-post-list';
	}

	public function get_categories() {
		return array( 'general' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Content', 'wp-news-collector' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'   => __( 'Number of Posts', 'wp-news-collector' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'min'     => 1,
				'max'     => 50,
				'step'    => 1,
				'default' => 10,
			)
		);

		$this->add_control(
			'category',
			array(
				'label'       => __( 'Category Slug', 'wp-news-collector' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => __( 'Leave empty for all categories.', 'wp-news-collector' ),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$limit    = ! empty( $settings['limit'] ) ? max( 1, min( 50, absint( $settings['limit'] ) ) ) : 10;
		$category = ! empty( $settings['category'] ) ? sanitize_text_field( $settings['category'] ) : '';

		echo do_shortcode(
			sprintf(
				'[news_bulletin limit="%d" category="%s"]',
				$limit,
				esc_attr( $category )
			)
		);
	}
}
