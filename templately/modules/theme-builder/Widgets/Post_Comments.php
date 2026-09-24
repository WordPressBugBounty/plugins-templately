<?php

namespace Templately\Modules\ThemeBuilder\Widgets;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Typography;
use Elementor\Plugin;
use Elementor\Widget_Base;

/**
 * Templately "Post Comments" widget.
 *
 * Feature parity with Elementor Pro's Post Comments widget (source: current or
 * custom post, full native WordPress comment thread — avatars, nested replies,
 * reply links, pagination and the comment form, plus a "comments are closed"
 * editor notice). Unlike the Pro widget — which delegates markup and styling to
 * the active theme via `comments_template()` — this widget renders its own markup
 * and ships a base skin (matching the Templately Figma design) that is fully
 * customisable through the Elementor Style tab.
 */
class Post_Comments extends Widget_Base {

	public function get_name() {
		return 'tl-post-comments';
	}

	public function get_title() {
		return esc_html__( 'Post Comments', 'templately' );
	}

	public function get_icon() {
		return 'eicon-comments templately-widget-icon';
	}

	public function get_categories() {
		return [ 'templately' ];
	}

	// Hidden from the widget panel by default — surfaced per-document for the
	// templately_library CPT via ThemeBuilder::elementor_document_config().
	public function show_in_panel() {
		return false;
	}

	public function get_keywords() {
		return [ 'comments', 'post', 'response', 'form', 'discussion' ];
	}

	public function get_style_depends() {
		return [ 'templately-comment-widget' ];
	}

	public function get_script_depends() {
		return [ 'comment-reply' ];
	}

	/* ---------------------------------------------------------------------------
	 * Controls
	 * ------------------------------------------------------------------------- */

	protected function register_controls() {
		$this->register_content_controls();
		$this->register_style_controls();
	}

	protected function register_content_controls() {
		// ── Comments ──────────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_comments',
			[
				'label' => esc_html__( 'Comments', 'templately' ),
			]
		);

		$this->add_control(
			'source_type',
			[
				'label'   => esc_html__( 'Source', 'templately' ),
				'type'    => Controls_Manager::SELECT,
				'options' => [
					'current' => esc_html__( 'Current Post', 'templately' ),
					'custom'  => esc_html__( 'Custom', 'templately' ),
				],
				'default' => 'current',
			]
		);

		$this->add_control(
			'source_custom',
			[
				'label'       => esc_html__( 'Search & Select', 'templately' ),
				'type'        => Controls_Manager::SELECT2,
				'label_block' => true,
				'options'     => $this->get_post_options(),
				'condition'   => [
					'source_type' => 'custom',
				],
			]
		);

		$this->add_control(
			'heading_text',
			[
				'label'     => esc_html__( 'Title', 'templately' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Comments', 'templately' ),
				'separator' => 'before',
			]
		);

		$this->add_control(
			'title_tag',
			[
				'label'   => esc_html__( 'Title HTML Tag', 'templately' ),
				'type'    => Controls_Manager::SELECT,
				'options' => [
					'h1'   => 'H1',
					'h2'   => 'H2',
					'h3'   => 'H3',
					'h4'   => 'H4',
					'h5'   => 'H5',
					'h6'   => 'H6',
					'div'  => 'div',
					'span' => 'span',
					'p'    => 'p',
				],
				'default' => 'h3',
			]
		);

		$this->add_control(
			'show_count',
			[
				'label'        => esc_html__( 'Show Count', 'templately' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Show', 'templately' ),
				'label_off'    => esc_html__( 'Hide', 'templately' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$this->add_control(
			'count_position',
			[
				'label'     => esc_html__( 'Count Position', 'templately' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => [
					'after'  => esc_html__( 'After title — Comments (2)', 'templately' ),
					'before' => esc_html__( 'Before title — 2 Comments', 'templately' ),
				],
				'default'   => 'after',
				'condition' => [
					'show_count' => 'yes',
				],
			]
		);

		$this->add_control(
			'count_include_replies',
			[
				'label'        => esc_html__( 'Count Replies', 'templately' ),
				'description'  => esc_html__( 'Include nested replies in the count. When off, only top-level comments are counted (matches the number of visible comment cards).', 'templately' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'templately' ),
				'label_off'    => esc_html__( 'No', 'templately' ),
				'return_value' => 'yes',
				'default'      => '',
				'condition'    => [
					'show_count' => 'yes',
				],
			]
		);

		$this->add_control(
			'show_avatar',
			[
				'label'        => esc_html__( 'Show Avatar', 'templately' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$this->add_control(
			'avatar_size',
			[
				'label'     => esc_html__( 'Avatar Size', 'templately' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 65,
				'min'       => 16,
				'max'       => 200,
				'condition' => [
					'show_avatar' => 'yes',
				],
			]
		);

		$this->add_control(
			'show_reply',
			[
				'label'        => esc_html__( 'Show Reply Link', 'templately' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$this->add_control(
			'order',
			[
				'label'   => esc_html__( 'Order', 'templately' ),
				'type'    => Controls_Manager::SELECT,
				'options' => [
					'ASC'  => esc_html__( 'Oldest First', 'templately' ),
					'DESC' => esc_html__( 'Newest First', 'templately' ),
				],
				'default' => 'ASC',
			]
		);

		$this->end_controls_section();

		// ── Comment Form ──────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_form',
			[
				'label' => esc_html__( 'Comment Form', 'templately' ),
			]
		);

		$this->add_control(
			'show_form',
			[
				'label'        => esc_html__( 'Show Form', 'templately' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$this->add_control(
			'form_title',
			[
				'label'     => esc_html__( 'Form Title', 'templately' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Leave a comment', 'templately' ),
				'condition' => [
					'show_form' => 'yes',
				],
			]
		);

		$this->add_control(
			'form_layout',
			[
				'label'     => esc_html__( 'Form Layout', 'templately' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => [
					'inline'  => esc_html__( 'Inline — Name & Email side by side', 'templately' ),
					'stacked' => esc_html__( 'Stacked — one field per row', 'templately' ),
				],
				'default'   => 'inline',
				'selectors' => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-form-col: {{VALUE}};',
				],
				'selectors_dictionary' => [
					'inline'  => 'calc(50% - 8px)',
					'stacked' => '100%',
				],
				'condition' => [
					'show_form' => 'yes',
				],
			]
		);

		$this->add_control(
			'button_text',
			[
				'label'     => esc_html__( 'Submit Button Text', 'templately' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => esc_html__( 'Post Comment', 'templately' ),
				'condition' => [
					'show_form' => 'yes',
				],
			]
		);

		$this->end_controls_section();
	}

	protected function register_style_controls() {
		// ── Comment Box ───────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_style_box',
			[
				'label' => esc_html__( 'Comment Box', 'templately' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'box_bg_color',
			[
				'label'     => esc_html__( 'Background Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-comment-box-bg: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'box_padding',
			[
				'label'      => esc_html__( 'Padding', 'templately' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem' ],
				'selectors'  => [
					'{{WRAPPER}} .tl-comment__inner' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'box_radius',
			[
				'label'      => esc_html__( 'Border Radius', 'templately' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .tl-comment__inner' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => 'box_border',
				'selector' => '{{WRAPPER}} .tl-comment__inner',
			]
		);

		$this->add_responsive_control(
			'box_gap',
			[
				'label'      => esc_html__( 'Space Between Comments', 'templately' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [
					'px' => [ 'min' => 0, 'max' => 100 ],
				],
				'selectors'  => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-comment-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		// ── Title ─────────────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_style_title',
			[
				'label' => esc_html__( 'Title', 'templately' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'title_color',
			[
				'label'     => esc_html__( 'Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-post-comments__title' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .tl-post-comments__title',
			]
		);

		$this->add_responsive_control(
			'title_spacing',
			[
				'label'      => esc_html__( 'Spacing', 'templately' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [
					'px' => [ 'min' => 0, 'max' => 100 ],
				],
				'selectors'  => [
					'{{WRAPPER}} .tl-post-comments__title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		// ── Avatar ────────────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_style_avatar',
			[
				'label'     => esc_html__( 'Avatar', 'templately' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'show_avatar' => 'yes',
				],
			]
		);

		$this->add_responsive_control(
			'avatar_radius',
			[
				'label'      => esc_html__( 'Border Radius', 'templately' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', '%' ],
				'selectors'  => [
					'{{WRAPPER}} .tl-comment__avatar img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'avatar_gap',
			[
				'label'      => esc_html__( 'Spacing', 'templately' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [
					'px' => [ 'min' => 0, 'max' => 100 ],
				],
				'selectors'  => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-comment-avatar-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		// ── Author ────────────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_style_author',
			[
				'label' => esc_html__( 'Author Name', 'templately' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'author_color',
			[
				'label'     => esc_html__( 'Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-comment__author, {{WRAPPER}} .tl-comment__author a' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'author_typography',
				'selector' => '{{WRAPPER}} .tl-comment__author',
			]
		);

		$this->end_controls_section();

		// ── Date / Meta ───────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_style_date',
			[
				'label' => esc_html__( 'Date', 'templately' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'date_color',
			[
				'label'     => esc_html__( 'Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-comment__date' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'date_typography',
				'selector' => '{{WRAPPER}} .tl-comment__date',
			]
		);

		$this->end_controls_section();

		// ── Comment Text ──────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_style_text',
			[
				'label' => esc_html__( 'Comment Text', 'templately' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'text_color',
			[
				'label'     => esc_html__( 'Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-comment__text' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'text_typography',
				'selector' => '{{WRAPPER}} .tl-comment__text',
			]
		);

		$this->end_controls_section();

		// ── Reply Link ────────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_style_reply',
			[
				'label'     => esc_html__( 'Reply Link', 'templately' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'show_reply' => 'yes',
				],
			]
		);

		$this->add_control(
			'reply_color',
			[
				'label'     => esc_html__( 'Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-comment__reply a' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'reply_hover_color',
			[
				'label'     => esc_html__( 'Hover Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-comment__reply a:hover' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'reply_typography',
				'selector' => '{{WRAPPER}} .tl-comment__reply a',
			]
		);

		$this->end_controls_section();

		// ── Form ──────────────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_style_form',
			[
				'label'     => esc_html__( 'Form', 'templately' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'show_form' => 'yes',
				],
			]
		);

		$this->add_control(
			'form_bg_color',
			[
				'label'     => esc_html__( 'Background Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-form-bg: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'form_padding',
			[
				'label'      => esc_html__( 'Padding', 'templately' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem' ],
				'selectors'  => [
					'{{WRAPPER}} .comment-respond, {{WRAPPER}} .tl-post-comments__form' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'form_title_heading',
			[
				'label'     => esc_html__( 'Form Title', 'templately' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$this->add_control(
			'form_title_color',
			[
				'label'     => esc_html__( 'Title Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .comment-reply-title, {{WRAPPER}} .tl-post-comments__form-title' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'form_title_typography',
				'selector' => '{{WRAPPER}} .comment-reply-title, {{WRAPPER}} .tl-post-comments__form-title',
			]
		);

		$this->add_control(
			'label_heading',
			[
				'label'     => esc_html__( 'Field Labels', 'templately' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$this->add_control(
			'label_color',
			[
				'label'     => esc_html__( 'Label Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .comment-form label' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .comment-form label',
			]
		);

		$this->add_control(
			'input_heading',
			[
				'label'     => esc_html__( 'Inputs', 'templately' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$this->add_control(
			'input_bg_color',
			[
				'label'     => esc_html__( 'Background Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-input-bg: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'input_text_color',
			[
				'label'     => esc_html__( 'Text Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-input-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'input_placeholder_color',
			[
				'label'     => esc_html__( 'Placeholder Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-input-placeholder: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'input_border_color',
			[
				'label'     => esc_html__( 'Border Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-input-border-color: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'input_radius',
			[
				'label'      => esc_html__( 'Border Radius', 'templately' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [
					'px' => [ 'min' => 0, 'max' => 50 ],
				],
				'selectors'  => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-input-radius: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		// ── Submit Button ─────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_style_button',
			[
				'label'     => esc_html__( 'Submit Button', 'templately' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'show_form' => 'yes',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .tl-post-comments__submit, {{WRAPPER}} .form-submit input[type="submit"]',
			]
		);

		$this->add_responsive_control(
			'button_padding',
			[
				'label'      => esc_html__( 'Padding', 'templately' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem' ],
				'selectors'  => [
					'{{WRAPPER}} .tl-post-comments__submit, {{WRAPPER}} .form-submit input[type="submit"]' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'button_radius',
			[
				'label'      => esc_html__( 'Border Radius', 'templately' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => [ 'px', '%' ],
				'range'      => [
					'px' => [ 'min' => 0, 'max' => 50 ],
				],
				'selectors'  => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-btn-radius: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->start_controls_tabs( 'button_tabs' );

		$this->start_controls_tab(
			'button_tab_normal',
			[
				'label' => esc_html__( 'Normal', 'templately' ),
			]
		);

		$this->add_control(
			'button_color',
			[
				'label'     => esc_html__( 'Text Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-btn-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'button_bg_color',
			[
				'label'     => esc_html__( 'Background Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-btn-bg: {{VALUE}};',
				],
			]
		);

		$this->end_controls_tab();

		$this->start_controls_tab(
			'button_tab_hover',
			[
				'label' => esc_html__( 'Hover', 'templately' ),
			]
		);

		$this->add_control(
			'button_color_hover',
			[
				'label'     => esc_html__( 'Text Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-btn-color-hover: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'button_bg_color_hover',
			[
				'label'     => esc_html__( 'Background Color', 'templately' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .tl-post-comments' => '--tl-btn-bg-hover: {{VALUE}};',
				],
			]
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/* ---------------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------------- */

	/**
	 * Build a small option list of recent posts/pages for the "Custom" source.
	 *
	 * @return array<int|string, string>
	 */
	protected function get_post_options() {
		$options = [ '' => esc_html__( 'Select a post…', 'templately' ) ];

		$posts = get_posts( [
			'post_type'   => [ 'post', 'page' ],
			'post_status' => 'publish',
			'numberposts' => 100,
			'orderby'     => 'date',
			'order'       => 'DESC',
		] );

		foreach ( $posts as $post ) {
			$options[ $post->ID ] = $post->post_title !== '' ? $post->post_title : sprintf( '#%d', $post->ID );
		}

		return $options;
	}

	/**
	 * Resolve the post whose comments should be rendered.
	 *
	 * Mirrors the dynamic-preview pattern used by the other Templately theme
	 * builder widgets: in the editor / library preview we fall back to the latest
	 * post so there is something to display.
	 *
	 * @return int
	 */
	protected function get_target_post_id() {
		$settings = $this->get_settings_for_display();

		if ( 'custom' === ( $settings['source_type'] ?? 'current' ) && ! empty( $settings['source_custom'] ) ) {
			return (int) $settings['source_custom'];
		}

		$is_edit_mode = Plugin::$instance->editor->is_edit_mode();
		$is_preview   = isset( $_GET['templately_library'], $_GET['preview_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $is_edit_mode || $is_preview ) {
			$latest = get_posts( 'post_type=post&numberposts=1' );
			if ( ! empty( $latest ) ) {
				return (int) $latest[0]->ID;
			}
		}

		return (int) get_the_ID();
	}

	/* ---------------------------------------------------------------------------
	 * Render
	 * ------------------------------------------------------------------------- */

	protected function render() {
		$settings = $this->get_settings_for_display();
		$post_id  = $this->get_target_post_id();

		$is_editor = Plugin::$instance->editor->is_edit_mode() || Plugin::$instance->preview->is_preview_mode();

		echo '<div class="tl-post-comments">';

		if ( $is_editor && ! comments_open( $post_id ) ) {
			// Feature parity with Elementor Pro: notify the editor when comments are off.
			$this->render_closed_notice();
			echo '</div>';
			return;
		}

		global $post, $wp_query;
		$original_post     = $post;
		$original_comments = $wp_query->comments ?? null;

		// Establish post context for comment_form() / template tags.
		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $post instanceof \WP_Post ) {
			setup_postdata( $post );
		}

		$comments = get_comments( [
			'post_id' => $post_id,
			'status'  => 'approve',
			'order'   => 'ASC', // Threading walker re-orders; top-level order applied below.
		] );

		// By default the heading counts only top-level comments so the number
		// matches the visible comment cards — nested replies are threaded inside a
		// card, not counted as separate responses. The "Count Replies" control
		// opts into WordPress' behaviour of counting every approved comment.
		if ( 'yes' === ( $settings['count_include_replies'] ?? '' ) ) {
			$count = count( $comments );
		} else {
			$count = 0;
			foreach ( $comments as $_comment ) {
				if ( 0 === (int) $_comment->comment_parent ) {
					$count++;
				}
			}
		}

		$this->render_title( $settings, $count );

		if ( ! empty( $comments ) ) {
			$this->render_list( $settings, $comments );
			$this->render_pagination();
		}

		if ( 'yes' === ( $settings['show_form'] ?? 'yes' ) ) {
			if ( comments_open( $post_id ) ) {
				// Inside the Elementor editor canvas the form is a preview only —
				// disable interaction so fields can't be typed into or submitted by
				// accident. It stays fully functional on the live site.
				if ( $is_editor ) {
					echo '<div class="tl-post-comments__form-preview" style="pointer-events:none;" aria-hidden="true" tabindex="-1">';
				}

				$this->render_form( $settings, $post_id );

				if ( $is_editor ) {
					echo '</div>';
				}
			} else {
				printf(
					'<p class="tl-post-comments__notice">%s</p>',
					esc_html__( 'Comments are closed.', 'templately' )
				);
			}
		}

		// Restore globals.
		wp_reset_postdata();
		$post = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( null !== $original_comments ) {
			$wp_query->comments = $original_comments; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		echo '</div>';
	}

	protected function render_closed_notice() {
		?>
		<div class="elementor-alert elementor-alert-danger" role="alert">
			<span class="elementor-alert-title">
				<?php echo esc_html__( 'Comments are closed.', 'templately' ); ?>
			</span>
			<span class="elementor-alert-description">
				<?php echo esc_html__( 'Switch on comments from the discussion box on the post edit screen or from the WordPress discussion settings.', 'templately' ); ?>
			</span>
		</div>
		<?php
	}

	protected function render_title( $settings, $count ) {
		$text = $settings['heading_text'] ?? esc_html__( 'Comments', 'templately' );

		if ( '' === trim( (string) $text ) ) {
			return;
		}

		$tag = $settings['title_tag'] ?? 'h3';
		$tag = \Elementor\Utils::validate_html_tag( $tag );

		$title_html = esc_html( $text );

		if ( 'yes' === ( $settings['show_count'] ?? 'yes' ) ) {
			$position = ( 'before' === ( $settings['count_position'] ?? 'after' ) ) ? 'before' : 'after';

			if ( 'before' === $position ) {
				// e.g. "2 Comments"
				$count_html = sprintf( '<span class="tl-post-comments__title-count">%d</span> ', (int) $count );
				$title_html = $count_html . $title_html;
			} else {
				// e.g. "Comments (2)" — WordPress' standard parenthesised format.
				$count_html = sprintf( ' <span class="tl-post-comments__title-count">(%d)</span>', (int) $count );
				$title_html = $title_html . $count_html;
			}
		}

		printf(
			'<%1$s class="tl-post-comments__title">%2$s</%1$s>',
			$tag, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- validated tag.
			$title_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html text + numeric count.
		);
	}

	protected function render_list( $settings, $comments ) {
		$this->render_settings = $settings; // Passed to the walker callback.

		$max_depth = get_option( 'thread_comments' ) ? (int) get_option( 'thread_comments_depth' ) : 1;

		echo '<ol class="tl-post-comments__list">';
		wp_list_comments(
			[
				'callback'   => [ $this, 'render_comment' ],
				'style'      => 'ol',
				'max_depth'  => $max_depth,
				'reverse_top_level' => ( 'DESC' === ( $settings['order'] ?? 'ASC' ) ),
			],
			$comments
		);
		echo '</ol>';
	}

	/**
	 * Walker callback — renders a single comment. Opens the list item; the walker
	 * closes it (and injects nested `.children` before the close).
	 *
	 * @param \WP_Comment $comment
	 * @param array       $args
	 * @param int         $depth
	 */
	public function render_comment( $comment, $args, $depth ) {
		$settings = $this->render_settings ?? [];
		$tag      = ( 'div' === $args['style'] ) ? 'div' : 'li';

		$classes = comment_class( 'tl-comment', $comment, null, false );
		printf( '<%1$s id="comment-%2$s" %3$s>', esc_attr( $tag ), esc_attr( get_comment_ID() ), $classes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- comment_class output.

		echo '<div class="tl-comment__inner">';

		if ( 'yes' === ( $settings['show_avatar'] ?? 'yes' ) ) {
			$avatar_size = ! empty( $settings['avatar_size'] ) ? (int) $settings['avatar_size'] : 65;
			$avatar      = get_avatar( $comment, $avatar_size );
			if ( $avatar ) {
				echo '<div class="tl-comment__avatar">' . $avatar . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar output.
			}
		}

		echo '<div class="tl-comment__body">';

		echo '<div class="tl-comment__head">';
		printf( '<span class="tl-comment__author">%s</span>', get_comment_author_link( $comment ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP link output.
		printf(
			'<span class="tl-comment__date">%s</span>',
			esc_html( get_comment_date( get_option( 'date_format' ), $comment ) )
		);
		echo '</div>';

		if ( '0' === $comment->comment_approved ) {
			printf(
				'<p class="tl-comment__moderation">%s</p>',
				esc_html__( 'Your comment is awaiting moderation.', 'templately' )
			);
		}

		echo '<div class="tl-comment__text">';
		comment_text( $comment );
		echo '</div>';

		if ( 'yes' === ( $settings['show_reply'] ?? 'yes' ) ) {
			$reply = get_comment_reply_link(
				array_merge(
					$args,
					[
						'add_below' => 'comment',
						'depth'     => $depth,
						'max_depth' => $args['max_depth'] ?? 5,
						'reply_text' => esc_html__( 'Reply', 'templately' ),
					]
				),
				$comment
			);
			if ( $reply ) {
				echo '<div class="tl-comment__reply">' . $reply . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP reply link.
			}
		}

		echo '</div>'; // .tl-comment__body
		echo '</div>'; // .tl-comment__inner
		// Note: the list item is intentionally left open — Walker_Comment closes it.
	}

	protected function render_pagination() {
		if ( ! get_option( 'page_comments' ) || get_comment_pages_count() <= 1 ) {
			return;
		}

		$links = paginate_comments_links(
			[
				'echo'      => false,
				'type'      => 'list',
				'prev_text' => esc_html__( '&laquo; Previous', 'templately' ),
				'next_text' => esc_html__( 'Next &raquo;', 'templately' ),
			]
		);

		if ( $links ) {
			echo '<div class="tl-post-comments__pagination">' . $links . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP pagination markup.
		}
	}

	protected function render_form( $settings, $post_id ) {
		$form_title  = $settings['form_title'] ?? esc_html__( 'Leave a comment', 'templately' );
		$button_text = $settings['button_text'] ?? esc_html__( 'Post Comment', 'templately' );

		$commenter = wp_get_current_commenter();

		$fields = [
			'author' => sprintf(
				'<p class="comment-form-author"><label for="author">%1$s</label><input id="author" name="author" type="text" placeholder="%2$s" value="%3$s" size="30" /></p>',
				esc_html__( 'Name', 'templately' ),
				esc_attr__( 'Your name', 'templately' ),
				esc_attr( $commenter['comment_author'] )
			),
			'email'  => sprintf(
				'<p class="comment-form-email"><label for="email">%1$s</label><input id="email" name="email" type="email" placeholder="%2$s" value="%3$s" size="30" /></p>',
				esc_html__( 'Email', 'templately' ),
				esc_attr__( 'your@email.com', 'templately' ),
				esc_attr( $commenter['comment_author_email'] )
			),
		];

		$args = [
			'title_reply'          => $form_title,
			'title_reply_before'   => '<h3 class="tl-post-comments__form-title comment-reply-title">',
			'title_reply_after'    => '</h3>',
			'comment_field'        => sprintf(
				'<p class="comment-form-comment"><label for="comment">%1$s</label><textarea id="comment" name="comment" placeholder="%2$s" rows="4" required></textarea></p>',
				esc_html__( 'Message', 'templately' ),
				esc_attr__( 'Share your thoughts…', 'templately' )
			),
			'fields'               => $fields,
			'class_submit'         => 'tl-post-comments__submit',
			'label_submit'         => $button_text,
			'comment_notes_before' => '',
			'comment_notes_after'  => '',
		];

		// WordPress renders the comment textarea first by default; the Figma design
		// places Name/Email above the Message, so reorder the fields for this form.
		$reorder = static function ( $fields ) {
			$order     = [ 'author', 'email', 'url', 'comment', 'cookies' ];
			$reordered = [];
			foreach ( $order as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$reordered[ $key ] = $fields[ $key ];
				}
			}
			// Preserve any custom fields not covered above.
			foreach ( $fields as $key => $value ) {
				if ( ! isset( $reordered[ $key ] ) ) {
					$reordered[ $key ] = $value;
				}
			}
			return $reordered;
		};

		add_filter( 'comment_form_fields', $reorder, 20 );
		comment_form( $args, $post_id );
		remove_filter( 'comment_form_fields', $reorder, 20 );
	}

	/**
	 * Settings snapshot shared with the walker callback (which receives only the
	 * comment + walker args, not the widget settings).
	 *
	 * @var array
	 */
	protected $render_settings = [];
}
