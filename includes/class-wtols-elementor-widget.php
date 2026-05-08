<?php
/**
 * Elementor widget integration.
 *
 * @package WebtaruSiteOptions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WTOLS_Elementor_Widget extends \Elementor\Widget_Base {
	public function get_name() {
		return 'wtols_dynamic_contact';
	}

	public function get_title() {
		return __( 'Webtaru Site Options', 'webtaru-site-options-login-security' );
	}

	public function get_icon() {
		return 'eicon-site-identity';
	}

	public function get_categories() {
		return array( 'general' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'wtols_content_section',
			array(
				'label' => __( 'Content', 'webtaru-site-options-login-security' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'wtols_type',
			array(
				'label'   => __( 'Type', 'webtaru-site-options-login-security' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'phone',
				'options' => array(
					'phone'        => __( 'Phone 1', 'webtaru-site-options-login-security' ),
					'phone_2'      => __( 'Phone 2', 'webtaru-site-options-login-security' ),
					'email'        => __( 'Email 1', 'webtaru-site-options-login-security' ),
					'email_2'      => __( 'Email 2', 'webtaru-site-options-login-security' ),
					'fax'          => __( 'Fax', 'webtaru-site-options-login-security' ),
					'address'      => __( 'Address', 'webtaru-site-options-login-security' ),
					'logo_light'   => __( 'Light Logo', 'webtaru-site-options-login-security' ),
					'logo_dark'    => __( 'Dark Logo', 'webtaru-site-options-login-security' ),
					'map'          => __( 'Map', 'webtaru-site-options-login-security' ),
					'social_links' => __( 'Social Links', 'webtaru-site-options-login-security' ),
					'contact_card' => __( 'Contact Card', 'webtaru-site-options-login-security' ),
				),
			)
		);

		$this->add_control(
			'wtols_clickable',
			array(
				'label'        => __( 'Clickable Links', 'webtaru-site-options-login-security' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'webtaru-site-options-login-security' ),
				'label_off'    => __( 'No', 'webtaru-site-options-login-security' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array(
					'wtols_type' => array( 'phone', 'phone_2', 'email', 'email_2', 'fax', 'address', 'logo_light', 'logo_dark' ),
				),
			)
		);

		$this->add_control(
			'wtols_css_class',
			array(
				'label' => __( 'CSS Class', 'webtaru-site-options-login-security' ),
				'type'  => \Elementor\Controls_Manager::TEXT,
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$type     = isset( $settings['wtols_type'] ) ? sanitize_key( $settings['wtols_type'] ) : 'phone';
		$class    = isset( $settings['wtols_css_class'] ) ? implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', $settings['wtols_css_class'] ) ) ) ) : '';
		$link     = ! empty( $settings['wtols_clickable'] ) ? 'yes' : 'no';

		$shortcodes = array(
			'phone'        => sprintf( '[wtols_phone link="%s" class="%s"]', esc_attr( $link ), esc_attr( $class ) ),
			'phone_2'      => sprintf( '[wtols_phone number="2" link="%s" class="%s"]', esc_attr( $link ), esc_attr( $class ) ),
			'email'        => sprintf( '[wtols_email link="%s" class="%s"]', esc_attr( $link ), esc_attr( $class ) ),
			'email_2'      => sprintf( '[wtols_email number="2" link="%s" class="%s"]', esc_attr( $link ), esc_attr( $class ) ),
			'fax'          => sprintf( '[wtols_fax link="%s" class="%s"]', esc_attr( $link ), esc_attr( $class ) ),
			'address'      => sprintf( '[wtols_address link="%s" class="%s"]', esc_attr( $link ), esc_attr( $class ) ),
			'logo_light'   => sprintf( '[wtols_logo type="light" link="%s" class="%s"]', esc_attr( $link ), esc_attr( $class ) ),
			'logo_dark'    => sprintf( '[wtols_logo type="dark" link="%s" class="%s"]', esc_attr( $link ), esc_attr( $class ) ),
			'map'          => sprintf( '[wtols_map class="%s"]', esc_attr( $class ) ),
			'social_links' => sprintf( '[wtols_social_links class="%s"]', esc_attr( $class ) ),
			'contact_card' => sprintf( '[wtols_contact_card class="%s"]', esc_attr( $class ) ),
		);

		if ( isset( $shortcodes[ $type ] ) ) {
			if ( 'map' === $type ) {
				echo do_shortcode( $shortcodes[ $type ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo wp_kses_post( do_shortcode( $shortcodes[ $type ] ) );
			}
		}
	}
}
