<?php
/**
 * Core plugin class.
 *
 * @package WebtaruSiteOptionsLoginSecurity
 */

if (!defined('ABSPATH')) {
	exit;
}

class WTOLS_Plugin
{
	const OPTION_KEY = 'wtols_settings';
	const TRANSIENT_REDIRECT = 'wtols_activation_redirect';
	const NONCE_ACTION_SAVE = 'wtols_save_settings';

	/**
	 * Singleton instance.
	 *
	 * @var WTOLS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether wp-login.php is being loaded through the custom endpoint.
	 *
	 * @var bool
	 */
	private $custom_login_request = false;

	/**
	 * Supported simple field keys.
	 *
	 * @var string[]
	 */
	private $field_keys = array('phone', 'phone_1', 'phone_2', 'email', 'email_1', 'email_2', 'address', 'fax');

	/**
	 * Login limiter constants.
	 */
	const LIMITER_ATTEMPTS_PREFIX = 'wtols_attempts_';
	const LIMITER_LOCKOUT_PREFIX = 'wtols_lockout_';

	public static function instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate()
	{
		$defaults = self::get_default_options();
		$current = get_option(self::OPTION_KEY, array());

		if (!is_array($current)) {
			$current = array();
		}

		update_option(self::OPTION_KEY, wp_parse_args($current, $defaults));
		set_transient(self::TRANSIENT_REDIRECT, 1, 30);
	}

	public static function deactivate()
	{
		flush_rewrite_rules();
	}

	private function __construct()
	{
		add_action('admin_menu', array($this, 'register_admin_menu'));
		add_action('admin_init', array($this, 'maybe_save_settings'));
		add_action('admin_init', array($this, 'maybe_redirect_after_activation'));
		add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
		add_filter('admin_footer_text', array($this, 'filter_admin_footer_text'));
		add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
		add_action('init', array($this, 'maybe_block_wp_admin_direct_access'), 0);
		add_action('init', array($this, 'maybe_load_custom_login'), 1);
		add_action('init', array($this, 'register_shortcodes'));
		add_action('login_init', array($this, 'maybe_block_default_login'), 1);
		add_action('login_enqueue_scripts', array($this, 'render_login_custom_styles'));
		add_filter('login_headerurl', array($this, 'filter_login_header_url'));
		add_filter('login_headertext', array($this, 'filter_login_header_text'));
		add_filter('login_errors', array($this, 'filter_login_errors'));
		add_filter('login_url', array($this, 'filter_login_url'), 10, 3);
		add_filter('lostpassword_url', array($this, 'filter_lostpassword_url'), 10, 2);
		add_filter('logout_url', array($this, 'filter_logout_url'), 10, 2);
		add_filter('register_url', array($this, 'filter_register_url'));
		add_filter('site_url', array($this, 'filter_site_login_url'), 10, 4);
		add_filter('network_site_url', array($this, 'filter_network_site_login_url'), 10, 3);
		add_action('vc_before_init', array($this, 'register_wpbakery_element'));
		add_action('elementor/widgets/register', array($this, 'register_elementor_widgets'));

		/* v2.0 — Schema.org JSON-LD */
		add_action('wp_head', array($this, 'render_schema_jsonld'));

		/* v2.0 — Login attempt limiter */
		add_action('wp_login_failed', array($this, 'track_failed_login'));
		add_filter('authenticate', array($this, 'check_login_lockout'), 30, 3);
		add_action('wp_login', array($this, 'clear_login_attempts'), 10, 2);
		add_action('wp_ajax_wtols_unlock_ip', array($this, 'ajax_unlock_ip'));

		/* v3.0 — Security & Performance */
		add_filter('xmlrpc_enabled', array($this, 'filter_xmlrpc_enabled'));
		add_action('login_enqueue_scripts', array($this, 'enqueue_captcha_scripts'));
		add_action('login_form', array($this, 'render_captcha_field'));
		add_filter('authenticate', array($this, 'verify_captcha'), 20, 3);
		add_action('admin_bar_menu', array($this, 'add_admin_bar_clear_cache'), 100);
		add_action('admin_post_wtols_clear_cache', array($this, 'handle_clear_cache'));
		add_action('updated_option', array($this, 'flush_cache_on_save'), 10, 3);
		add_action('admin_notices', array($this, 'render_admin_notices'));

		/* v4.0 — Version Hiding & Hardening */
		add_action('init', array($this, 'handle_version_hiding'));

		/* v3.1 — Styling & Utilities */
		add_action('init', array($this, 'init_utilities'), 1);
		add_action('wp_head', array($this, 'render_styling_css_vars'));
		add_filter('post_row_actions', array($this, 'add_duplicate_post_link'), 10, 2);
		add_filter('page_row_actions', array($this, 'add_duplicate_post_link'), 10, 2);
		add_action('admin_post_wtols_duplicate_post', array($this, 'handle_duplicate_post'));
		add_action('admin_post_wtols_delete_all_comments', array($this, 'handle_delete_all_comments'));
		add_action('admin_post_wtols_export_settings', array($this, 'handle_export_settings'));
		add_action('admin_post_wtols_import_settings', array($this, 'handle_import_settings'));
		add_action('admin_post_wtols_reset_settings', array($this, 'handle_reset_settings'));
		add_action('admin_post_wtols_db_cleanup', array($this, 'handle_db_cleanup'));

		/* v2.1.0 — New Features */
		add_action('template_redirect', array($this, 'handle_maintenance_mode'));
		add_action('wp_footer', array($this, 'render_sticky_whatsapp'), 98);
		add_action('wp_footer', array($this, 'render_rating_widget'), 97);
		add_action('wp_footer', array($this, 'render_back_to_top'), 96);
		add_action('wp_footer', array($this, 'render_mobile_bottom_buttons'), 95);
		add_action('wp_footer', array($this, 'render_sticky_vertical_btn'), 94);
		add_action('wp_login_failed', array($this, 'log_failed_login_attempt'));
		add_action('admin_menu', array($this, 'organize_admin_menu'), 999);
	}


	public static function get_default_options()
	{
		return array(
			'phone' => '',
			'phone_2' => '',
			'email' => get_option('admin_email'),
			'email_2' => '',
			'fax' => '',
			'address' => '',
			'address_link' => '',
			'logo_light_id' => 0,
			'logo_light_url' => '',
			'logo_dark_id' => 0,
			'logo_dark_url' => '',
			'map' => '',
			'social_links' => self::get_default_social_links(),
			'custom_social_links' => array(),
			'enable_fontawesome' => 1,
			'admin_footer_text' => '',
			'login_logo_id' => 0,
			'login_logo_url' => '',
			'login_background_id' => 0,
			'login_background_url' => '',
			'login_error_message' => 'Incorrect login details, please contact the website administrator or developer',
			'login_slug' => '',
			'logo_id' => 0,
			'logo_url' => '',
			'business_hours' => self::get_default_business_hours(),
			'business_hours_freeform' => '',
			'business_hours_label' => 'Business Hours',
			'enable_schema' => 1,
			'limiter_enabled' => 0,
			'limiter_max_attempts' => 5,
			'limiter_lockout_duration' => 30,
			'limiter_message' => 'Too many failed login attempts. Please try again later.',
			'disable_xmlrpc' => 0,
			'captcha_type' => 'none',
			'captcha_site_key' => '',
			'captcha_secret_key' => '',
			'show_contact_icons' => 0,
			'icon_placement' => 'before',
			'icon_phone' => 'fa-solid fa-phone',
			'icon_phone_2' => 'fa-solid fa-phone',
			'icon_email' => 'fa-solid fa-envelope',
			'icon_email_2' => 'fa-solid fa-envelope',
			'icon_fax' => 'fa-solid fa-fax',
			'icon_address' => 'fa-solid fa-location-dot',
			'clear_cache_on_save' => 0,
			'admin_bar_clear_cache' => 0,
			'layout_style' => 'simple',
			'color_text' => '',
			'color_background' => '',
			'color_icon' => '',
			'disable_gutenberg' => 0,
			'disable_file_editor' => 0,
			'disable_comments_sitewide' => 0,
			'hidden_plugin_updates' => array(),
			'disabled_plugin_autoupdates' => array(),
			'enable_duplicator' => 0,
			'agency_mode' => 0,
			'agency_menu_name' => 'Dynamic Options',
			'agency_hide_icon' => 0,
			'hide_wp_version' => 0,
			'enable_maintenance' => 0,
			'maintenance_message' => 'Our website is currently undergoing scheduled maintenance. We will be back shortly. Thank you for your patience!',
			'delete_data_on_uninstall' => 0,
			'hidden_admin_menus' => array(),
			'login_logs' => array(),
			'whatsapp_number' => '',
			'enable_sticky_whatsapp' => 0,
			'sticky_whatsapp_position' => 'right',
			'sticky_whatsapp_display' => 'both',
			'sticky_whatsapp_message' => '',
			'sticky_whatsapp_icon_size' => '32',
			'enable_rating_widget' => 0,
			'rating_widget_provider' => 'google',
			'rating_widget_icon_type' => 'icon',
			'rating_widget_image_url' => '',
			'rating_widget_score' => '5.0',
			'rating_widget_link' => '',
			'rating_widget_display' => 'desktop',
			'enable_back_to_top' => 0,
			'back_to_top_display' => 'both',
			'back_to_top_bg_color' => '#5cb85c',
			'back_to_top_icon_color' => '#ffffff',
			'back_to_top_shape' => 'square',
			'back_to_top_size' => '20',
			'enable_mobile_buttons' => 0,
			'mobile_button_1_text' => 'Call Now',
			'mobile_button_1_text_color' => '#ffffff',
			'mobile_button_1_link' => '',
			'mobile_button_1_color' => '#5cb85c',
			'mobile_button_2_text' => '',
			'mobile_button_2_text_color' => '#ffffff',
			'mobile_button_2_link' => '',
			'mobile_button_2_color' => '#0275d8',
			'enable_sticky_vertical_btn' => 0,
			'sticky_vertical_btn_text' => 'Feedback',
			'sticky_vertical_btn_icon' => 'fa-solid fa-comment-dots',
			'sticky_vertical_btn_id_class' => '',
			'sticky_vertical_btn_text_color' => '#ffffff',
			'sticky_vertical_btn_bg_color' => '#0073aa',
			'sticky_vertical_btn_icon_color' => '#ffffff',
			'sticky_vertical_btn_position' => '50',
		);
	}

	public static function get_default_social_links()
	{
		return array(
			'facebook' => array('label' => 'Facebook', 'url' => '', 'icon' => 'fab fa-facebook-f'),
			'instagram' => array('label' => 'Instagram', 'url' => '', 'icon' => 'fab fa-instagram'),
			'x_twitter' => array('label' => 'X / Twitter', 'url' => '', 'icon' => 'fab fa-x-twitter'),
			'linkedin' => array('label' => 'LinkedIn', 'url' => '', 'icon' => 'fab fa-linkedin-in'),
			'youtube' => array('label' => 'YouTube', 'url' => '', 'icon' => 'fab fa-youtube'),
			'whatsapp' => array('label' => 'WhatsApp', 'url' => '', 'icon' => 'fab fa-whatsapp'),
		);
	}

	public static function get_default_business_hours()
	{
		$days = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
		$hours = array();
		foreach ($days as $day) {
			$hours[$day] = array(
				'enabled' => in_array($day, array('saturday', 'sunday'), true) ? 0 : 1,
				'open' => '09:00',
				'close' => '18:00',
			);
		}
		return $hours;
	}

	public static function get_options()
	{
		$options = get_option(self::OPTION_KEY, array());

		if (!is_array($options)) {
			$options = array();
		}

		$options = wp_parse_args($options, self::get_default_options());

		// Ensure safe_mode_secret is stable and unique per site using WP AUTH_KEY
		if (empty($options['safe_mode_secret'])) {
			$seed = defined('AUTH_KEY') ? AUTH_KEY : get_option('admin_email', 'wtols');
			$options['safe_mode_secret'] = 'wtols_' . substr(md5($seed), 0, 10);
		}

		if (empty($options['logo_light_id']) && !empty($options['logo_id'])) {
			$options['logo_light_id'] = absint($options['logo_id']);
			$options['logo_light_url'] = isset($options['logo_url']) ? esc_url_raw($options['logo_url']) : '';
		}

		$options['social_links'] = wp_parse_args(
			is_array($options['social_links']) ? $options['social_links'] : array(),
			self::get_default_social_links()
		);

		return $options;
	}

	public function register_admin_menu()
	{
		$options = self::get_options();
		$menu_label = !empty($options['agency_mode']) && !empty($options['agency_menu_name'])
			? $options['agency_menu_name']
			: __('Webtaru Site Options', 'webtaru-site-options-login-security');

		add_submenu_page(
			'themes.php',
			$menu_label,
			$menu_label,
			'manage_options',
			'wtols-settings',
			array($this, 'render_settings_page')
		);
	}

	public function maybe_redirect_after_activation()
	{
		if (!is_admin() || wp_doing_ajax() || !current_user_can('manage_options')) {
			return;
		}

		if (!get_transient(self::TRANSIENT_REDIRECT)) {
			return;
		}

		delete_transient(self::TRANSIENT_REDIRECT);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (isset($_GET['activate-multi'])) {
			return;
		}

		wp_safe_redirect(admin_url('themes.php?page=wtols-settings&wtols_welcome=1'));
		exit;
	}

	public function register_dashboard_widget()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$options = self::get_options();
		$widget_title = !empty($options['agency_mode']) && !empty($options['agency_menu_name'])
			/* translators: %s: agency menu name */
			? sprintf(__('%s Details', 'webtaru-site-options-login-security'), $options['agency_menu_name'])
			: __('Dynamic Options Details', 'webtaru-site-options-login-security');

		wp_add_dashboard_widget(
			'wtols_dashboard_settings',
			$widget_title,
			array($this, 'render_dashboard_widget')
		);
	}

	public function enqueue_admin_assets($hook_suffix)
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_plugin_page = (isset($_GET['page']) && 'wtols-settings' === $_GET['page']);
		$is_dashboard = 'index.php' === $hook_suffix;

		if (!$is_plugin_page && !$is_dashboard) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style('wp-color-picker');
		wp_enqueue_style('wtols-admin', WTOLS_PLUGIN_URL . 'assets/admin.css', array('wp-color-picker'), WTOLS_VERSION);
		wp_enqueue_script('wtols-admin', WTOLS_PLUGIN_URL . 'assets/admin.js', array('jquery', 'wp-color-picker'), WTOLS_VERSION, true);
		wp_localize_script(
			'wtols-admin',
			'wtolsAdmin',
			array(
				'chooseLogo' => __('Choose Image', 'webtaru-site-options-login-security'),
				'useLogo' => __('Use this image', 'webtaru-site-options-login-security'),
				'noLogo' => __('No image selected.', 'webtaru-site-options-login-security'),
				'labelPlaceholder' => __('Label', 'webtaru-site-options-login-security'),
				'urlPlaceholder' => __('Profile URL or #', 'webtaru-site-options-login-security'),
				'unlockNonce' => wp_create_nonce('wtols_unlock_ip'),
				'unlocking' => __('Unlocking...', 'webtaru-site-options-login-security'),
			)
		);
	}

	public function enqueue_frontend_assets()
	{
		wp_register_style('wtols-frontend', WTOLS_PLUGIN_URL . 'assets/frontend.css', array(), WTOLS_VERSION);
		wp_register_style('wtols-fontawesome', WTOLS_PLUGIN_URL . 'assets/vendor/fontawesome/css/all.min.css', array(), '6.5.2');
		wp_register_script('wtols-frontend', WTOLS_PLUGIN_URL . 'assets/frontend.js', array(), WTOLS_VERSION, true);

		$options = self::get_options();
		
		$needs_frontend_css = false;
		if (!empty($options['enable_sticky_whatsapp']) && !empty($options['whatsapp_number'])) {
			$needs_frontend_css = true;
		}
		if (!empty($options['enable_rating_widget'])) {
			$needs_frontend_css = true;
		}
		if (!empty($options['enable_back_to_top'])) {
			$needs_frontend_css = true;
			wp_enqueue_script('wtols-frontend');
		}
		if (!empty($options['enable_mobile_buttons'])) {
			$needs_frontend_css = true;
		}
		if (!empty($options['enable_sticky_vertical_btn'])) {
			$needs_frontend_css = true;
		}

		if ($needs_frontend_css) {
			wp_enqueue_style('wtols-frontend');
			if (!empty($options['enable_fontawesome'])) {
				wp_enqueue_style('wtols-fontawesome');
			}
		}
	}

	public function filter_admin_footer_text($text)
	{
		$options = self::get_options();
		$footer_text = isset($options['admin_footer_text']) ? trim((string) $options['admin_footer_text']) : '';

		return '' === $footer_text ? $text : wp_kses_post($footer_text);
	}

	public function render_login_custom_styles()
	{
		$options = self::get_options();
		$login_logo = !empty($options['login_logo_id']) ? wp_get_attachment_image_url(absint($options['login_logo_id']), 'full') : '';
		$login_bg = !empty($options['login_background_id']) ? wp_get_attachment_image_url(absint($options['login_background_id']), 'full') : '';
		
		$custom_css = 'body.login #login{background:#fff;border-radius:8px;box-shadow:0 12px 36px rgba(0,0,0,.18);box-sizing:border-box;margin:6vh auto 0;padding:28px 28px 22px;width:380px;max-width:calc(100% - 32px);}';
		$custom_css .= 'body.login #loginform,body.login #lostpasswordform,body.login #registerform{background:transparent;border:0;box-shadow:none;margin-top:18px;padding:0;}';
		$custom_css .= 'body.login #nav,body.login #backtoblog{margin-left:0;margin-right:0;text-align:center;}';
		$custom_css .= 'body.login #nav a,body.login #backtoblog a{color:#1d2327 !important;}';
		$custom_css .= 'body.login h1 a{display:block;margin:0 auto 18px;}';

		if ($login_bg) {
			$custom_css .= 'body.login{background-image:url("' . esc_url($login_bg) . '");background-size:cover;background-position:center;background-repeat:no-repeat;}';
		}

		if ($login_logo) {
			$custom_css .= 'body.login h1 a{background-image:url("' . esc_url($login_logo) . '");background-size:contain;background-position:center;background-repeat:no-repeat;width:320px;max-width:100%;height:110px;}';
		}

		wp_add_inline_style('login', $custom_css);
	}


	public function filter_login_header_url()
	{
		return home_url('/');
	}

	public function filter_login_header_text()
	{
		return get_bloginfo('name');
	}

	public function filter_login_errors($error)
	{
		$options = self::get_options();
		$message = isset($options['login_error_message']) && '' !== trim((string) $options['login_error_message'])
			? $options['login_error_message']
			: self::get_default_options()['login_error_message'];

		return esc_html($message);
	}

	public function maybe_save_settings()
	{
		if (!isset($_POST['wtols_settings_submit'])) {
			return;
		}

		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to update these settings.', 'webtaru-site-options-login-security'));
		}

		check_admin_referer(self::NONCE_ACTION_SAVE, 'wtols_nonce');

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset($_POST['wtols']) && is_array($_POST['wtols']) ? wp_unslash($_POST['wtols']) : array();

		$options = array(
			'phone' => isset($raw['phone']) ? sanitize_text_field($raw['phone']) : '',
			'phone_2' => isset($raw['phone_2']) ? sanitize_text_field($raw['phone_2']) : '',
			'email' => isset($raw['email']) ? sanitize_email($raw['email']) : '',
			'email_2' => isset($raw['email_2']) ? sanitize_email($raw['email_2']) : '',
			'fax' => isset($raw['fax']) ? sanitize_text_field($raw['fax']) : '',
			'address' => isset($raw['address']) ? sanitize_textarea_field($raw['address']) : '',
			'address_link' => isset($raw['address_link']) ? $this->sanitize_safe_url($raw['address_link']) : '',
			'logo_light_id' => isset($raw['logo_light_id']) ? absint($raw['logo_light_id']) : 0,
			'logo_dark_id' => isset($raw['logo_dark_id']) ? absint($raw['logo_dark_id']) : 0,
			'map' => isset($raw['map']) ? $this->sanitize_map_value($raw['map']) : '',
			'social_links' => isset($raw['social_links']) && is_array($raw['social_links']) ? $this->sanitize_social_links($raw['social_links']) : self::get_default_social_links(),
			'custom_social_links' => isset($raw['custom_social_links']) && is_array($raw['custom_social_links']) ? $this->sanitize_custom_social_links($raw['custom_social_links']) : array(),
			'enable_fontawesome' => isset($raw['enable_fontawesome']) ? 1 : 0,
			'admin_footer_text' => isset($raw['admin_footer_text']) ? wp_kses_post($raw['admin_footer_text']) : '',
			'login_logo_id' => isset($raw['login_logo_id']) ? absint($raw['login_logo_id']) : 0,
			'login_background_id' => isset($raw['login_background_id']) ? absint($raw['login_background_id']) : 0,
			'login_error_message' => isset($raw['login_error_message']) ? sanitize_text_field($raw['login_error_message']) : '',
			'login_slug' => isset($raw['login_slug']) ? $this->sanitize_login_slug($raw['login_slug']) : '',
			'business_hours' => isset($raw['business_hours']) && is_array($raw['business_hours']) ? $this->sanitize_business_hours($raw['business_hours']) : self::get_default_business_hours(),
			'business_hours_freeform' => isset($raw['business_hours_freeform']) ? sanitize_textarea_field($raw['business_hours_freeform']) : '',
			'business_hours_label' => isset($raw['business_hours_label']) ? sanitize_text_field($raw['business_hours_label']) : 'Business Hours',
			'enable_schema' => isset($raw['enable_schema']) ? 1 : 0,
			'limiter_enabled' => isset($raw['limiter_enabled']) ? 1 : 0,
			'limiter_max_attempts' => isset($raw['limiter_max_attempts']) ? max(1, absint($raw['limiter_max_attempts'])) : 5,
			'limiter_lockout_duration' => isset($raw['limiter_lockout_duration']) ? max(1, absint($raw['limiter_lockout_duration'])) : 30,
			'limiter_message' => isset($raw['limiter_message']) ? sanitize_text_field($raw['limiter_message']) : '',
			'disable_xmlrpc' => isset($raw['disable_xmlrpc']) ? 1 : 0,
			'captcha_type' => isset($raw['captcha_type']) && in_array($raw['captcha_type'], array('none', 'recaptcha_v3', 'turnstile'), true) ? $raw['captcha_type'] : 'none',
		);

		$options['captcha_site_key'] = ('none' === $options['captcha_type']) ? '' : (isset($raw['captcha_site_key']) ? sanitize_text_field($raw['captcha_site_key']) : '');
		$options['captcha_secret_key'] = ('none' === $options['captcha_type']) ? '' : (isset($raw['captcha_secret_key']) ? sanitize_text_field($raw['captcha_secret_key']) : '');

		$options = array_merge($options, array(
			'show_contact_icons' => isset($raw['show_contact_icons']) ? 1 : 0,
			'icon_placement' => isset($raw['icon_placement']) && 'after' === $raw['icon_placement'] ? 'after' : 'before',
			'icon_phone' => isset($raw['icon_phone']) ? sanitize_text_field($raw['icon_phone']) : 'fa-solid fa-phone',
			'icon_phone_2' => isset($raw['icon_phone_2']) ? sanitize_text_field($raw['icon_phone_2']) : 'fa-solid fa-phone',
			'icon_email' => isset($raw['icon_email']) ? sanitize_text_field($raw['icon_email']) : 'fa-solid fa-envelope',
			'icon_email_2' => isset($raw['icon_email_2']) ? sanitize_text_field($raw['icon_email_2']) : 'fa-solid fa-envelope',
			'icon_fax' => isset($raw['icon_fax']) ? sanitize_text_field($raw['icon_fax']) : 'fa-solid fa-fax',
			'icon_address' => isset($raw['icon_address']) ? sanitize_text_field($raw['icon_address']) : 'fa-solid fa-location-dot',
			'clear_cache_on_save' => isset($raw['clear_cache_on_save']) ? 1 : 0,
			'admin_bar_clear_cache' => isset($raw['admin_bar_clear_cache']) ? 1 : 0,
			'layout_style' => isset($raw['layout_style']) && in_array($raw['layout_style'], array('simple', 'pill'), true) ? $raw['layout_style'] : 'simple',
			'color_text' => isset($raw['color_text']) ? sanitize_hex_color($raw['color_text']) : '',
			'color_background' => isset($raw['color_background']) ? sanitize_hex_color($raw['color_background']) : '',
			'color_icon' => isset($raw['color_icon']) ? sanitize_hex_color($raw['color_icon']) : '',
			'disable_gutenberg' => isset($raw['disable_gutenberg']) ? 1 : 0,
			'disable_file_editor' => isset($raw['disable_file_editor']) ? 1 : 0,
			'disable_comments_sitewide' => isset($raw['disable_comments_sitewide']) ? 1 : 0,
			'hidden_plugin_updates' => isset($raw['hidden_plugin_updates']) && is_array($raw['hidden_plugin_updates']) ? array_map('sanitize_text_field', $raw['hidden_plugin_updates']) : array(),
			'disabled_plugin_autoupdates' => isset($raw['disabled_plugin_autoupdates']) && is_array($raw['disabled_plugin_autoupdates']) ? array_map('sanitize_text_field', $raw['disabled_plugin_autoupdates']) : array(),
			'enable_duplicator' => isset($raw['enable_duplicator']) ? 1 : 0,
			'agency_mode' => isset($raw['agency_mode']) ? 1 : 0,
			'agency_menu_name' => isset($raw['agency_menu_name']) ? sanitize_text_field($raw['agency_menu_name']) : 'Dynamic Options',
			'agency_hide_icon' => isset($raw['agency_hide_icon']) ? 1 : 0,
			'delete_data_on_uninstall' => isset($raw['delete_data_on_uninstall']) ? 1 : 0,
			'hide_wp_version' => isset($raw['hide_wp_version']) ? 1 : 0,
			'enable_maintenance' => isset($raw['enable_maintenance']) ? 1 : 0,
			'maintenance_message' => isset($raw['maintenance_message']) ? wp_kses_post($raw['maintenance_message']) : '',
			'hidden_admin_menus' => isset($raw['hidden_admin_menus']) && is_array($raw['hidden_admin_menus']) ? array_map('sanitize_text_field', $raw['hidden_admin_menus']) : array(),

			'whatsapp_number' => isset($raw['whatsapp_number']) ? sanitize_text_field($raw['whatsapp_number']) : '',
			'enable_sticky_whatsapp' => isset($raw['enable_sticky_whatsapp']) ? 1 : 0,
			'sticky_whatsapp_position' => isset($raw['sticky_whatsapp_position']) && in_array($raw['sticky_whatsapp_position'], array('left', 'right'), true) ? $raw['sticky_whatsapp_position'] : 'right',
			'sticky_whatsapp_display' => isset($raw['sticky_whatsapp_display']) && in_array($raw['sticky_whatsapp_display'], array('both', 'mobile', 'desktop'), true) ? $raw['sticky_whatsapp_display'] : 'both',
			'sticky_whatsapp_message' => isset($raw['sticky_whatsapp_message']) ? sanitize_text_field($raw['sticky_whatsapp_message']) : '',
			'sticky_whatsapp_icon_size' => isset($raw['sticky_whatsapp_icon_size']) ? sanitize_text_field($raw['sticky_whatsapp_icon_size']) : '32',
			'enable_rating_widget' => isset($raw['enable_rating_widget']) ? 1 : 0,
			'rating_widget_provider' => isset($raw['rating_widget_provider']) && in_array($raw['rating_widget_provider'], array('google', 'trustpilot'), true) ? $raw['rating_widget_provider'] : 'google',
			'rating_widget_icon_type' => isset($raw['rating_widget_icon_type']) && in_array($raw['rating_widget_icon_type'], array('icon', 'image'), true) ? $raw['rating_widget_icon_type'] : 'icon',
			'rating_widget_image_url' => isset($raw['rating_widget_image_url']) ? esc_url_raw($raw['rating_widget_image_url']) : '',
			'rating_widget_score' => isset($raw['rating_widget_score']) ? sanitize_text_field($raw['rating_widget_score']) : '5.0',
			'rating_widget_link' => isset($raw['rating_widget_link']) ? esc_url_raw($raw['rating_widget_link']) : '',
			'rating_widget_display' => isset($raw['rating_widget_display']) && in_array($raw['rating_widget_display'], array('both', 'mobile', 'desktop'), true) ? $raw['rating_widget_display'] : 'desktop',
			'enable_back_to_top' => isset($raw['enable_back_to_top']) ? 1 : 0,
			'back_to_top_display' => isset($raw['back_to_top_display']) && in_array($raw['back_to_top_display'], array('both', 'mobile', 'desktop'), true) ? $raw['back_to_top_display'] : 'both',
			'back_to_top_bg_color' => isset($raw['back_to_top_bg_color']) ? sanitize_hex_color($raw['back_to_top_bg_color']) : '#5cb85c',
			'back_to_top_icon_color' => isset($raw['back_to_top_icon_color']) ? sanitize_hex_color($raw['back_to_top_icon_color']) : '#ffffff',
			'back_to_top_shape' => isset($raw['back_to_top_shape']) && in_array($raw['back_to_top_shape'], array('square', 'circle'), true) ? $raw['back_to_top_shape'] : 'square',
			'back_to_top_size' => isset($raw['back_to_top_size']) ? sanitize_text_field($raw['back_to_top_size']) : '20',
			'enable_mobile_buttons' => isset($raw['enable_mobile_buttons']) ? 1 : 0,
			'mobile_button_1_text' => isset($raw['mobile_button_1_text']) ? sanitize_text_field($raw['mobile_button_1_text']) : 'Call Now',
			'mobile_button_1_text_color' => isset($raw['mobile_button_1_text_color']) ? sanitize_hex_color($raw['mobile_button_1_text_color']) : '#ffffff',
			'mobile_button_1_link' => isset($raw['mobile_button_1_link']) ? esc_url_raw($raw['mobile_button_1_link']) : '',
			'mobile_button_1_color' => isset($raw['mobile_button_1_color']) ? sanitize_hex_color($raw['mobile_button_1_color']) : '#5cb85c',
			'mobile_button_2_text' => isset($raw['mobile_button_2_text']) ? sanitize_text_field($raw['mobile_button_2_text']) : '',
			'mobile_button_2_text_color' => isset($raw['mobile_button_2_text_color']) ? sanitize_hex_color($raw['mobile_button_2_text_color']) : '#ffffff',
			'mobile_button_2_link' => isset($raw['mobile_button_2_link']) ? esc_url_raw($raw['mobile_button_2_link']) : '',
			'mobile_button_2_color' => isset($raw['mobile_button_2_color']) ? sanitize_hex_color($raw['mobile_button_2_color']) : '#0275d8',
			'enable_sticky_vertical_btn' => isset($raw['enable_sticky_vertical_btn']) ? 1 : 0,
			'sticky_vertical_btn_text' => isset($raw['sticky_vertical_btn_text']) ? sanitize_text_field($raw['sticky_vertical_btn_text']) : '',
			'sticky_vertical_btn_icon' => isset($raw['sticky_vertical_btn_icon']) ? sanitize_text_field($raw['sticky_vertical_btn_icon']) : '',
			'sticky_vertical_btn_id_class' => isset($raw['sticky_vertical_btn_id_class']) ? sanitize_text_field($raw['sticky_vertical_btn_id_class']) : '',
			'sticky_vertical_btn_bg_color' => isset($raw['sticky_vertical_btn_bg_color']) ? sanitize_hex_color($raw['sticky_vertical_btn_bg_color']) : '',
			'sticky_vertical_btn_text_color' => isset($raw['sticky_vertical_btn_text_color']) ? sanitize_hex_color($raw['sticky_vertical_btn_text_color']) : '',
			'sticky_vertical_btn_icon_color' => isset($raw['sticky_vertical_btn_icon_color']) ? sanitize_hex_color($raw['sticky_vertical_btn_icon_color']) : '',
			'sticky_vertical_btn_position' => isset($raw['sticky_vertical_btn_position']) ? sanitize_text_field($raw['sticky_vertical_btn_position']) : '50',
		));

		// Preserve logs during save
		$existing_options = self::get_options();
		$options['login_logs'] = $existing_options['login_logs'];

		$options['logo_light_url'] = $this->get_attachment_url($options['logo_light_id']);
		$options['logo_dark_url'] = $this->get_attachment_url($options['logo_dark_id']);
		$options['login_logo_url'] = $this->get_attachment_url($options['login_logo_id']);
		$options['login_background_url'] = $this->get_attachment_url($options['login_background_id']);
		$options['logo_id'] = $options['logo_light_id'];
		$options['logo_url'] = $options['logo_light_url'];

		if ('' === $options['login_error_message']) {
			$options['login_error_message'] = self::get_default_options()['login_error_message'];
		}

		if (!is_email($options['email'])) {
			$options['email'] = '';
		}

		if ('' !== $options['email_2'] && !is_email($options['email_2'])) {
			$options['email_2'] = '';
		}

		update_option(self::OPTION_KEY, wp_parse_args($options, self::get_default_options()));

		$active_tab = isset($_POST['wtols_active_tab']) ? sanitize_text_field(wp_unslash($_POST['wtols_active_tab'])) : 'contact';
		$redirect = admin_url('admin.php?page=wtols-settings&wtols_saved=1&wtols_active_tab=' . $active_tab);

		wp_safe_redirect($redirect);
		exit;
	}

	private function get_attachment_url($attachment_id)
	{
		if (!$attachment_id) {
			return '';
		}

		$url = wp_get_attachment_image_url(absint($attachment_id), 'full');
		return $url ? esc_url_raw($url) : '';
	}

	private function sanitize_map_value($value)
	{
		$value = trim((string) $value);

		if ('' === $value) {
			return '';
		}

		if (false !== stripos($value, '<iframe')) {
			return wp_kses($value, $this->get_allowed_iframe_html());
		}

		return esc_url_raw($value);
	}

	private function sanitize_social_links($social_links)
	{
		$defaults = self::get_default_social_links();
		$clean = array();

		foreach ($defaults as $key => $default) {
			$item = isset($social_links[$key]) && is_array($social_links[$key]) ? $social_links[$key] : array();

			$clean[$key] = array(
				'label' => $default['label'],
				'url' => isset($item['url']) ? $this->sanitize_safe_url($item['url']) : '',
				'icon' => isset($item['icon']) ? $this->sanitize_class_list($item['icon']) : $default['icon'],
			);

			if ('' === $clean[$key]['icon']) {
				$clean[$key]['icon'] = $default['icon'];
			}
		}

		return $clean;
	}

	private function sanitize_login_slug($value)
	{
		$value = trim((string) $value);

		if ('' === $value) {
			return '';
		}

		if (false !== strpos($value, '://')) {
			$path = wp_parse_url($value, PHP_URL_PATH);
			$value = $path ? basename(trim($path, '/')) : '';
		}

		$slug = sanitize_title(trim($value, '/'));

		if ('' === $slug) {
			return '';
		}

		$reserved = array('admin', 'admin-ajax', 'admin-post', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'wp-login-php', 'xmlrpc', 'xmlrpc-php');

		return in_array($slug, $reserved, true) ? '' : $slug;
	}

	private function sanitize_safe_url($value)
	{
		$value = trim((string) $value);

		if ('' === $value) {
			return '';
		}

		if ('#' === $value) {
			return '#';
		}

		if (0 === strpos($value, '#')) {
			return '#' . sanitize_title(substr($value, 1));
		}

		return esc_url_raw($value);
	}

	private function esc_safe_url($value)
	{
		if ($this->is_hash_url($value)) {
			return esc_attr($value);
		}

		return esc_url($value);
	}

	private function is_hash_url($value)
	{
		return '#' === $value || 0 === strpos((string) $value, '#');
	}

	private function get_login_slug()
	{
		$options = self::get_options();
		return isset($options['login_slug']) ? $this->sanitize_login_slug($options['login_slug']) : '';
	}

	private function get_custom_login_url($action = '', $args = array())
	{
		$slug = $this->get_login_slug();

		if ('' === $slug) {
			return wp_login_url();
		}

		$url = home_url(user_trailingslashit($slug));

		if ('' !== $action) {
			$args['action'] = $action;
		}

		$args = array_filter(
			$args,
			static function ($value) {
				return '' !== $value && null !== $value;
			}
		);

		return empty($args) ? $url : add_query_arg($args, $url);
	}

	public function maybe_load_custom_login()
	{
		$slug = $this->get_login_slug();

		if ('' === $slug || $slug !== $this->get_current_request_slug()) {
			return;
		}

		$this->custom_login_request = true;
		global $pagenow;
		$pagenow = 'wp-login.php';

		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	public function maybe_block_wp_admin_direct_access()
	{
		if (is_user_logged_in() || '' === $this->get_login_slug()) {
			return;
		}

		$request_path = $this->get_current_request_path();

		if ('' === $request_path || 0 !== strpos($request_path, 'wp-admin')) {
			return;
		}

		if (0 === strpos($request_path, 'wp-admin/admin-ajax.php')) {
			return;
		}

		status_header(404);
		nocache_headers();

		$template = get_404_template();
		if ($template) {
			include $template;
		} else {
			wp_die(esc_html__('Page not found.', 'webtaru-site-options-login-security'), esc_html__('Not Found', 'webtaru-site-options-login-security'), array('response' => 404));
		}

		exit;
	}

	public function maybe_block_default_login()
	{
		if ($this->custom_login_request || '' === $this->get_login_slug()) {
			return;
		}

		$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';
		if ('POST' === $method) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : 'login';
		$allowed_actions = array('logout', 'postpass', 'rp', 'resetpass', 'confirm_admin_email');

		if (in_array($action, $allowed_actions, true)) {
			return;
		}

		wp_safe_redirect(home_url('/'));
		exit;
	}

	public function filter_login_url($login_url, $redirect, $force_reauth)
	{
		if ('' === $this->get_login_slug()) {
			return $login_url;
		}

		if ($this->is_wp_admin_redirect($redirect)) {
			return $login_url;
		}

		return $this->get_custom_login_url(
			'',
			array(
				'redirect_to' => $redirect,
				'reauth' => $force_reauth ? '1' : '',
			)
		);
	}

	public function filter_lostpassword_url($lostpassword_url, $redirect)
	{
		if ('' === $this->get_login_slug()) {
			return $lostpassword_url;
		}

		return $this->get_custom_login_url(
			'lostpassword',
			array(
				'redirect_to' => $redirect,
			)
		);
	}

	public function filter_logout_url($logout_url, $redirect)
	{
		if ('' === $this->get_login_slug()) {
			return $logout_url;
		}

		return $this->get_custom_login_url(
			'logout',
			array(
				'_wpnonce' => wp_create_nonce('log-out'),
				'redirect_to' => $redirect,
			)
		);
	}

	public function filter_register_url($register_url)
	{
		if ('' === $this->get_login_slug()) {
			return $register_url;
		}

		return $this->get_custom_login_url('register');
	}

	public function filter_site_login_url($url, $path, $scheme, $blog_id)
	{
		return $this->filter_login_path_url($url, $path);
	}

	public function filter_network_site_login_url($url, $path, $scheme)
	{
		return $this->filter_login_path_url($url, $path);
	}

	private function filter_login_path_url($url, $path)
	{
		if ('' === $this->get_login_slug()) {
			return $url;
		}

		$path = ltrim((string) $path, '/');
		if (0 !== strpos($path, 'wp-login.php')) {
			return $url;
		}

		$query = wp_parse_url($path, PHP_URL_QUERY);
		$args = array();

		if ($query) {
			wp_parse_str($query, $args);
		}

		if (isset($args['redirect_to']) && $this->is_wp_admin_redirect($args['redirect_to'])) {
			return $url;
		}

		$action = isset($args['action']) ? sanitize_key($args['action']) : '';
		unset($args['action']);

		return $this->get_custom_login_url($action, $args);
	}

	private function is_wp_admin_redirect($redirect)
	{
		if (empty($redirect)) {
			return false;
		}

		$path = wp_parse_url($redirect, PHP_URL_PATH);

		if (!$path) {
			return false;
		}

		return false !== strpos(trim($path, '/'), 'wp-admin');
	}

	private function get_current_request_slug()
	{
		return sanitize_title(trim(rawurldecode($this->get_current_request_path()), '/'));
	}

	private function get_current_request_path()
	{
		$request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
		$request_path = trim((string) wp_parse_url($request_uri, PHP_URL_PATH), '/');
		$home_path = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');

		if ('' !== $home_path && 0 === strpos($request_path, $home_path . '/')) {
			$request_path = substr($request_path, strlen($home_path) + 1);
		}

		return trim(rawurldecode($request_path), '/');
	}

	public function render_settings_page()
	{
		if (!current_user_can("manage_options")) {
			return;
		}

		$options = self::get_options();
		$header_title = !empty($options['agency_mode']) && !empty($options['agency_menu_name'])
			? $options['agency_menu_name']
			: __('Webtaru Site Options and Login Security', 'webtaru-site-options-login-security');

		$wrap_class = 'wrap wtols-wrap';
		if (!empty($options['agency_hide_icon'])) {
			$wrap_class .= ' wtols-hide-header-icon';
		}
		?>
				<div class="<?php echo esc_attr($wrap_class); ?>">
					<div class="wtols-page-header">
						<h1>
							<img src="<?php echo esc_url( WTOLS_PLUGIN_URL . 'assets/webtaru-logo.png' ); ?>" class="wtols-header-logo" alt="" />
							<?php echo esc_html($header_title); ?>
						</h1>
						<button type="submit" name="wtols_settings_submit" form="wtols-main-form" class="button button-primary wtols-save-top"><?php esc_html_e("Save Settings", "webtaru-site-options-login-security"); ?></button>
					</div>
					<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<?php if (isset($_GET["wtols_welcome"])): ?>
							<div class="notice notice-info is-dismissible"><p><?php esc_html_e("Plugin activated. Add your contact details below, then use the generated shortcodes anywhere.", "webtaru-site-options-login-security"); ?></p></div>
					<?php endif; ?>
					<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<?php if (isset($_GET["wtols_comments_deleted"])): ?>
							<div class="notice notice-success is-dismissible"><p><?php esc_html_e("All comments have been successfully deleted.", "webtaru-site-options-login-security"); ?></p></div>
					<?php endif; ?>
					<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<?php if (isset($_GET["wtols_cleaned"])): ?>
							<div class="notice notice-success is-dismissible"><p><?php esc_html_e("Database cleanup successful! Revisions, auto-drafts, and expired transients removed.", "webtaru-site-options-login-security"); ?></p></div>
					<?php endif; ?>

					<div class="wtols-admin-layout">
						<nav class="wtols-sidebar-nav">
							<a class="wtols-tab" data-tab="contact"><span class="dashicons dashicons-phone"></span><span class="wtols-tab-label"><?php esc_html_e("Contact &amp; Info", "webtaru-site-options-login-security"); ?></span></a>
							<a class="wtols-tab" data-tab="floating-widgets"><span class="dashicons dashicons-admin-links"></span><span class="wtols-tab-label"><?php esc_html_e("Floating Widgets", "webtaru-site-options-login-security"); ?></span></a>
							<a class="wtols-tab" data-tab="brand"><span class="dashicons dashicons-format-image"></span><span class="wtols-tab-label"><?php esc_html_e("Visual Branding", "webtaru-site-options-login-security"); ?></span></a>
							<a class="wtols-tab" data-tab="login"><span class="dashicons dashicons-lock"></span><span class="wtols-tab-label"><?php esc_html_e("Login &amp; Security", "webtaru-site-options-login-security"); ?></span></a>
							<a class="wtols-tab" data-tab="utilities"><span class="dashicons dashicons-admin-tools"></span><span class="wtols-tab-label"><?php esc_html_e("Workflow Utilities", "webtaru-site-options-login-security"); ?></span></a>
							<a class="wtols-tab" data-tab="system"><span class="dashicons dashicons-admin-settings"></span><span class="wtols-tab-label"><?php esc_html_e("System &amp; Backup", "webtaru-site-options-login-security"); ?></span></a>
							<a class="wtols-tab" data-tab="shortcodes"><span class="dashicons dashicons-editor-code"></span><span class="wtols-tab-label"><?php esc_html_e("Shortcodes", "webtaru-site-options-login-security"); ?></span></a>
					
							<div class="wtols-sidebar-footer">
								<p>© <?php echo esc_html(gmdate("Y")); ?> Webtaru</p>
							</div>
						</nav>
						<div class="wtols-content-area">
							<?php $this->render_settings_form($options); ?>
						</div>
					</div>
				</div>
				<?php
	}

	public function render_dashboard_widget()
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (isset($_GET['wtols_saved'])) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__('Settings saved.', 'webtaru-site-options-login-security') . '</p></div>';
		}

		$this->render_settings_form(self::get_options(), true);
	}
	private function render_settings_form($options, $compact = false)
	{
		$action_url = esc_url(admin_url('admin.php'));
		$form_class = $compact ? 'wtols-settings-form wtols-compact-form' : 'wtols-settings-form';
		echo '<form method="post" id="wtols-main-form" action="' . esc_url($action_url) . '" class="' . esc_attr($form_class) . '">';
		wp_nonce_field(self::NONCE_ACTION_SAVE, 'wtols_nonce');
		echo '<input type="hidden" name="page" value="wtols-settings" />';
		$active_tab = 'contact';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		if (isset($_GET['wtols_active_tab'])) {
			$active_tab = sanitize_text_field(wp_unslash($_GET['wtols_active_tab']));
		} elseif (isset($_POST['wtols_active_tab'])) {
			$active_tab = sanitize_text_field(wp_unslash($_POST['wtols_active_tab']));
		}
		// phpcs:enable
		echo '<input type="hidden" id="wtols_active_tab" name="wtols_active_tab" value="' . esc_attr($active_tab) . '" />';

		/* ── Tab 1: Contact ── */
		echo '<div class="wtols-tab-panel" data-panel="contact"><table class="form-table" role="presentation"><tbody>';
		$this->render_section_heading(__('Contact Details', 'webtaru-site-options-login-security'));
		$this->render_text_row('wtols-phone', 'wtols[phone]', __('Phone 1', 'webtaru-site-options-login-security'), $options['phone'], 'tel');
		$this->render_text_row('wtols-phone-2', 'wtols[phone_2]', __('Phone 2', 'webtaru-site-options-login-security'), $options['phone_2'], 'tel');
		$this->render_text_row('wtols-email', 'wtols[email]', __('Email 1', 'webtaru-site-options-login-security'), $options['email'], 'email', 'email');
		$this->render_text_row('wtols-email-2', 'wtols[email_2]', __('Email 2', 'webtaru-site-options-login-security'), $options['email_2'], 'email', 'email');
		$this->render_text_row('wtols-fax', 'wtols[fax]', __('Fax Number', 'webtaru-site-options-login-security'), $options['fax']);

		$this->render_section_heading(__('Address & Map', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row"><label for="wtols-address">' . esc_html__('Address', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><textarea id="wtols-address" name="wtols[address]" rows="4" class="large-text">' . esc_textarea($options['address']) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-address-link">' . esc_html__('Address Link', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-address-link" name="wtols[address_link]" value="' . esc_attr($options['address_link']) . '" class="regular-text" placeholder="https://maps.google.com/..." />';
		echo '<p class="description">' . esc_html__('Optional. Google Maps URL, page URL, or # placeholder.', 'webtaru-site-options-login-security') . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-map">' . esc_html__('Map URL or Iframe', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><textarea id="wtols-map" name="wtols[map]" rows="4" class="large-text code">' . esc_textarea($options['map']) . '</textarea></td></tr>';

		$this->render_section_heading(__('Contact Icons', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Enable Icons', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[show_contact_icons]" value="1" ' . checked(!empty($options['show_contact_icons']), true, false) . ' /> ';
		echo esc_html__('Show Font Awesome icons next to contact details.', 'webtaru-site-options-login-security') . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-icon-placement">' . esc_html__('Icon Placement', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><select id="wtols-icon-placement" name="wtols[icon_placement]">';
		echo '<option value="before" ' . selected($options['icon_placement'], 'before', false) . '>' . esc_html__('Before Text', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="after" ' . selected($options['icon_placement'], 'after', false) . '>' . esc_html__('After Text', 'webtaru-site-options-login-security') . '</option>';
		echo '</select></td></tr>';
		$this->render_text_row('wtols-icon-phone', 'wtols[icon_phone]', __('Phone Icon', 'webtaru-site-options-login-security'), $options['icon_phone']);
		$this->render_text_row('wtols-icon-phone-2', 'wtols[icon_phone_2]', __('Phone 2 Icon', 'webtaru-site-options-login-security'), $options['icon_phone_2']);
		$this->render_text_row('wtols-icon-email', 'wtols[icon_email]', __('Email Icon', 'webtaru-site-options-login-security'), $options['icon_email']);
		$this->render_text_row('wtols-icon-email-2', 'wtols[icon_email_2]', __('Email 2 Icon', 'webtaru-site-options-login-security'), $options['icon_email_2']);
		$this->render_text_row('wtols-icon-fax', 'wtols[icon_fax]', __('Fax Icon', 'webtaru-site-options-login-security'), $options['icon_fax']);
		$this->render_text_row('wtols-icon-address', 'wtols[icon_address]', __('Address Icon', 'webtaru-site-options-login-security'), $options['icon_address']);
		$this->render_text_row('wtols-icon-hours', 'wtols[icon_hours]', __('Hours Icon', 'webtaru-site-options-login-security'), isset($options['icon_hours']) ? $options['icon_hours'] : 'far fa-clock');

		$this->render_section_heading(__('Styling & Layout', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row"><label for="wtols-layout-style">' . esc_html__('Contact Style', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><select id="wtols-layout-style" name="wtols[layout_style]">';
		echo '<option value="simple" ' . selected($options['layout_style'], 'simple', false) . '>' . esc_html__('Simple Text', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="pill" ' . selected($options['layout_style'], 'pill', false) . '>' . esc_html__('Pill/Badge Style', 'webtaru-site-options-login-security') . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-color-text">' . esc_html__('Text Color', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-color-text" name="wtols[color_text]" value="' . esc_attr($options['color_text']) . '" class="wtols-color-picker" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-color-background">' . esc_html__('Background Color', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-color-background" name="wtols[color_background]" value="' . esc_attr($options['color_background']) . '" class="wtols-color-picker" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-color-icon">' . esc_html__('Icon Color', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-color-icon" name="wtols[color_icon]" value="' . esc_attr($options['color_icon']) . '" class="wtols-color-picker" /></td></tr>';

		$this->render_section_heading(__('Business Hours', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Hours', 'webtaru-site-options-login-security') . '</th><td>';
		$this->render_business_hours_fields($options);
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="wtols-hours-freeform">' . esc_html__('Custom Text', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><textarea id="wtols-hours-freeform" name="wtols[business_hours_freeform]" rows="5" class="large-text" placeholder="' . esc_attr__("Mon - Fri: 9am - 6pm\nSat - Sun: Closed", 'webtaru-site-options-login-security') . '">' . esc_textarea($options['business_hours_freeform']) . '</textarea>';
		echo '<p class="description">' . esc_html__('If filled, [wtols_hours] shows this text instead of the schedule above. Write anything — e.g. "Mon - Sun: 10am - 8pm".', 'webtaru-site-options-login-security') . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-hours-label">' . esc_html__('Section Label', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-hours-label" name="wtols[business_hours_label]" value="' . esc_attr($options['business_hours_label']) . '" class="regular-text" /></td></tr>';

		$this->render_section_heading(__('Social Media', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Social Links', 'webtaru-site-options-login-security') . '</th><td>';
		$this->render_social_fields($options);
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Custom Links', 'webtaru-site-options-login-security') . '</th><td>';
		$this->render_custom_social_fields($options);
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Font Awesome', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[enable_fontawesome]" value="1" ' . checked(!empty($options['enable_fontawesome']), true, false) . ' /> ';
		echo esc_html__('Load Font Awesome icons for social and contact details.', 'webtaru-site-options-login-security') . '</label></td></tr>';

		echo '</tbody></table></div>';

		/* ── Tab: Floating Widgets ── */
		echo '<div class="wtols-tab-panel" data-panel="floating-widgets"><table class="form-table" role="presentation"><tbody>';
		
		$this->render_section_heading(__('WhatsApp Widget', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row"><label for="wtols-whatsapp-number">' . esc_html__('WhatsApp Number', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-whatsapp-number" name="wtols[whatsapp_number]" value="' . esc_attr($options['whatsapp_number']) . '" class="regular-text" placeholder="e.g. +1234567890" /></td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Enable Sticky Widget', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[enable_sticky_whatsapp]" value="1" ' . checked(!empty($options['enable_sticky_whatsapp']), true, false) . ' /> ';
		echo esc_html__('Display a floating WhatsApp icon at the bottom corner.', 'webtaru-site-options-login-security') . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-sticky-whatsapp-display">' . esc_html__('Display On', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><select id="wtols-sticky-whatsapp-display" name="wtols[sticky_whatsapp_display]">';
		echo '<option value="both" ' . selected($options['sticky_whatsapp_display'], 'both', false) . '>' . esc_html__('Desktop & Mobile', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="desktop" ' . selected($options['sticky_whatsapp_display'], 'desktop', false) . '>' . esc_html__('Desktop Only', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="mobile" ' . selected($options['sticky_whatsapp_display'], 'mobile', false) . '>' . esc_html__('Mobile Only', 'webtaru-site-options-login-security') . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-sticky-whatsapp-position">' . esc_html__('Widget Position', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><select id="wtols-sticky-whatsapp-position" name="wtols[sticky_whatsapp_position]">';
		echo '<option value="left" ' . selected($options['sticky_whatsapp_position'], 'left', false) . '>' . esc_html__('Bottom Left', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="right" ' . selected($options['sticky_whatsapp_position'], 'right', false) . '>' . esc_html__('Bottom Right', 'webtaru-site-options-login-security') . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-sticky-whatsapp-message">' . esc_html__('Pre-filled Message', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><textarea id="wtols-sticky-whatsapp-message" name="wtols[sticky_whatsapp_message]" rows="3" class="large-text" placeholder="' . esc_attr__('e.g. Hello, I have an inquiry...', 'webtaru-site-options-login-security') . '">' . esc_textarea($options['sticky_whatsapp_message']) . '</textarea>';
		echo '<p class="description">' . esc_html__('Optional. Default message to send when user clicks the widget.', 'webtaru-site-options-login-security') . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-sticky-whatsapp-icon-size">' . esc_html__('Icon Size (px)', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="number" id="wtols-sticky-whatsapp-icon-size" name="wtols[sticky_whatsapp_icon_size]" value="' . esc_attr($options['sticky_whatsapp_icon_size']) . '" class="small-text" /></td></tr>';

		$this->render_section_heading(__('Rating Widget', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Enable Rating Widget', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[enable_rating_widget]" value="1" ' . checked(!empty($options['enable_rating_widget']), true, false) . ' /> ';
		echo esc_html__('Display a floating rating badge in the footer.', 'webtaru-site-options-login-security') . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-rating-widget-provider">' . esc_html__('Provider', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><select id="wtols-rating-widget-provider" name="wtols[rating_widget_provider]">';
		echo '<option value="google" ' . selected($options['rating_widget_provider'], 'google', false) . '>' . esc_html__('Google', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="trustpilot" ' . selected($options['rating_widget_provider'], 'trustpilot', false) . '>' . esc_html__('Trustpilot', 'webtaru-site-options-login-security') . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-rating-widget-icon-type">' . esc_html__('Icon Type', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><select id="wtols-rating-widget-icon-type" name="wtols[rating_widget_icon_type]">';
		echo '<option value="icon" ' . selected($options['rating_widget_icon_type'], 'icon', false) . '>' . esc_html__('Built-in Icon', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="image" ' . selected($options['rating_widget_icon_type'], 'image', false) . '>' . esc_html__('Custom Image URL', 'webtaru-site-options-login-security') . '</option>';
		echo '</select></td></tr>';
		echo '<tr class="wtols-rating-image-url-row"><th scope="row"><label for="wtols-rating-widget-image-url">' . esc_html__('Custom Image URL', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="url" id="wtols-rating-widget-image-url" name="wtols[rating_widget_image_url]" value="' . esc_attr($options['rating_widget_image_url']) . '" class="regular-text" placeholder="https://" />';
		echo '<p class="description">' . esc_html__('Used if Icon Type is set to Custom Image URL.', 'webtaru-site-options-login-security') . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-rating-widget-score">' . esc_html__('Rating Score', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-rating-widget-score" name="wtols[rating_widget_score]" value="' . esc_attr($options['rating_widget_score']) . '" class="small-text" placeholder="5.0" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-rating-widget-link">' . esc_html__('Review Link', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="url" id="wtols-rating-widget-link" name="wtols[rating_widget_link]" value="' . esc_attr($options['rating_widget_link']) . '" class="regular-text" placeholder="https://" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-rating-widget-display">' . esc_html__('Display On', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><select id="wtols-rating-widget-display" name="wtols[rating_widget_display]">';
		echo '<option value="both" ' . selected($options['rating_widget_display'], 'both', false) . '>' . esc_html__('Desktop & Mobile', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="desktop" ' . selected($options['rating_widget_display'], 'desktop', false) . '>' . esc_html__('Desktop Only', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="mobile" ' . selected($options['rating_widget_display'], 'mobile', false) . '>' . esc_html__('Mobile Only', 'webtaru-site-options-login-security') . '</option>';
		echo '</select></td></tr>';

		$this->render_section_heading(__('Back to Top Button', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Enable Back to Top', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[enable_back_to_top]" value="1" ' . checked(!empty($options['enable_back_to_top']), true, false) . ' /> ';
		echo esc_html__('Display a back to top arrow on scroll.', 'webtaru-site-options-login-security') . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-back-to-top-display">' . esc_html__('Display On', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><select id="wtols-back-to-top-display" name="wtols[back_to_top_display]">';
		echo '<option value="both" ' . selected($options['back_to_top_display'], 'both', false) . '>' . esc_html__('Desktop & Mobile', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="desktop" ' . selected($options['back_to_top_display'], 'desktop', false) . '>' . esc_html__('Desktop Only', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="mobile" ' . selected($options['back_to_top_display'], 'mobile', false) . '>' . esc_html__('Mobile Only', 'webtaru-site-options-login-security') . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-back-to-top-bg-color">' . esc_html__('Background Color', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-back-to-top-bg-color" name="wtols[back_to_top_bg_color]" value="' . esc_attr($options['back_to_top_bg_color']) . '" class="wtols-color-picker" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-back-to-top-icon-color">' . esc_html__('Icon Color', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-back-to-top-icon-color" name="wtols[back_to_top_icon_color]" value="' . esc_attr($options['back_to_top_icon_color']) . '" class="wtols-color-picker" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-back-to-top-shape">' . esc_html__('Shape', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><select id="wtols-back-to-top-shape" name="wtols[back_to_top_shape]">';
		echo '<option value="square" ' . selected($options['back_to_top_shape'], 'square', false) . '>' . esc_html__('Square', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="circle" ' . selected($options['back_to_top_shape'], 'circle', false) . '>' . esc_html__('Circle', 'webtaru-site-options-login-security') . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-back-to-top-size">' . esc_html__('Arrow Size (px)', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="number" id="wtols-back-to-top-size" name="wtols[back_to_top_size]" value="' . esc_attr($options['back_to_top_size']) . '" class="small-text" /></td></tr>';

		$this->render_section_heading(__('Sticky Vertical Button', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Enable Vertical Button', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[enable_sticky_vertical_btn]" value="1" ' . checked(!empty($options['enable_sticky_vertical_btn']), true, false) . ' /> ';
		echo esc_html__('Display a fixed vertical button on the right side.', 'webtaru-site-options-login-security') . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-sticky-vertical-btn-text">' . esc_html__('Button Text', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-sticky-vertical-btn-text" name="wtols[sticky_vertical_btn_text]" value="' . esc_attr($options['sticky_vertical_btn_text']) . '" class="regular-text" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-sticky-vertical-btn-icon">' . esc_html__('Icon Class', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-sticky-vertical-btn-icon" name="wtols[sticky_vertical_btn_icon]" value="' . esc_attr($options['sticky_vertical_btn_icon']) . '" class="regular-text" placeholder="fa-solid fa-comment-dots" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-sticky-vertical-btn-id-class">' . esc_html__('Custom ID / Class', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-sticky-vertical-btn-id-class" name="wtols[sticky_vertical_btn_id_class]" value="' . esc_attr($options['sticky_vertical_btn_id_class']) . '" class="regular-text" placeholder="e.g. #my-popup or .trigger-modal" />';
		echo '<p class="description">' . esc_html__('Add an ID starting with # or a class starting with . to trigger Elementor popups.', 'webtaru-site-options-login-security') . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-sticky-vertical-btn-bg-color">' . esc_html__('Background Color', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-sticky-vertical-btn-bg-color" name="wtols[sticky_vertical_btn_bg_color]" value="' . esc_attr($options['sticky_vertical_btn_bg_color']) . '" class="wtols-color-picker" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-sticky-vertical-btn-text-color">' . esc_html__('Text Color', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-sticky-vertical-btn-text-color" name="wtols[sticky_vertical_btn_text_color]" value="' . esc_attr($options['sticky_vertical_btn_text_color']) . '" class="wtols-color-picker" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-sticky-vertical-btn-icon-color">' . esc_html__('Icon Color', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-sticky-vertical-btn-icon-color" name="wtols[sticky_vertical_btn_icon_color]" value="' . esc_attr($options['sticky_vertical_btn_icon_color']) . '" class="wtols-color-picker" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-sticky-vertical-btn-position">' . esc_html__('Vertical Position (%)', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="number" id="wtols-sticky-vertical-btn-position" name="wtols[sticky_vertical_btn_position]" value="' . esc_attr($options['sticky_vertical_btn_position']) . '" class="small-text" min="0" max="100" /></td></tr>';

		$this->render_section_heading(__('Mobile Bottom Buttons', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Enable Mobile Buttons', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[enable_mobile_buttons]" value="1" ' . checked(!empty($options['enable_mobile_buttons']), true, false) . ' /> ';
		echo esc_html__('Display a fixed button bar at the bottom of mobile screens.', 'webtaru-site-options-login-security') . '</label></td></tr>';
		
		echo '<tr><th scope="row"><label for="wtols-mobile-btn-1-text">' . esc_html__('Button 1 Text', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-mobile-btn-1-text" name="wtols[mobile_button_1_text]" value="' . esc_attr($options['mobile_button_1_text']) . '" class="regular-text" placeholder="e.g. Call Now" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-mobile-btn-1-link">' . esc_html__('Button 1 Link', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-mobile-btn-1-link" name="wtols[mobile_button_1_link]" value="' . esc_attr($options['mobile_button_1_link']) . '" class="regular-text" placeholder="e.g. tel:+1234567890" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-mobile-btn-1-color">' . esc_html__('Button 1 Background', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-mobile-btn-1-color" name="wtols[mobile_button_1_color]" value="' . esc_attr($options['mobile_button_1_color']) . '" class="wtols-color-picker" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-mobile-btn-1-text-color">' . esc_html__('Button 1 Text Color', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-mobile-btn-1-text-color" name="wtols[mobile_button_1_text_color]" value="' . esc_attr($options['mobile_button_1_text_color']) . '" class="wtols-color-picker" /></td></tr>';

		echo '<tr><th scope="row"><label for="wtols-mobile-btn-2-text">' . esc_html__('Button 2 Text', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-mobile-btn-2-text" name="wtols[mobile_button_2_text]" value="' . esc_attr($options['mobile_button_2_text']) . '" class="regular-text" placeholder="Optional" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-mobile-btn-2-link">' . esc_html__('Button 2 Link', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-mobile-btn-2-link" name="wtols[mobile_button_2_link]" value="' . esc_attr($options['mobile_button_2_link']) . '" class="regular-text" placeholder="Optional" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-mobile-btn-2-color">' . esc_html__('Button 2 Background', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-mobile-btn-2-color" name="wtols[mobile_button_2_color]" value="' . esc_attr($options['mobile_button_2_color']) . '" class="wtols-color-picker" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-mobile-btn-2-text-color">' . esc_html__('Button 2 Text Color', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-mobile-btn-2-text-color" name="wtols[mobile_button_2_text_color]" value="' . esc_attr($options['mobile_button_2_text_color']) . '" class="wtols-color-picker" /></td></tr>';

		echo '</tbody></table></div>';

		/* ── Tab 2: Visual Branding ── */
		echo '<div class="wtols-tab-panel" data-panel="brand"><table class="form-table" role="presentation"><tbody>';
		$this->render_section_heading(__('Brand Logos', 'webtaru-site-options-login-security'));
		$this->render_logo_field('light', __('Light Logo', 'webtaru-site-options-login-security'), $options);
		$this->render_logo_field('dark', __('Dark Logo', 'webtaru-site-options-login-security'), $options);
		$this->render_section_heading(__('Dashboard Branding', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row"><label for="wtols-admin-footer-text">' . esc_html__('Admin Footer Text', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-admin-footer-text" name="wtols[admin_footer_text]" value="' . esc_attr($options['admin_footer_text']) . '" class="large-text" /></td></tr>';
		echo '</tbody></table></div>';

		/* ── Tab 3: Login & Security ── */
		echo '<div class="wtols-tab-panel" data-panel="login"><table class="form-table" role="presentation"><tbody>';
		$this->render_section_heading(__('Custom Login URL', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row"><label for="wtols-login-slug">' . esc_html__('Login Slug', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><div class="wtols-inline-url"><span>' . esc_html(trailingslashit(home_url())) . '</span>';
		echo '<input type="text" id="wtols-login-slug" name="wtols[login_slug]" value="' . esc_attr($options['login_slug']) . '" class="regular-text" placeholder="secure-login" /></div>';
		if (!empty($options['login_slug'])) {
			echo '<p><strong>' . esc_html__('Active:', 'webtaru-site-options-login-security') . '</strong> <code>' . esc_html($this->get_custom_login_url()) . '</code></p>';
		}
		echo '</td></tr>';
		$this->render_image_field('login_logo_id', __('Login Logo', 'webtaru-site-options-login-security'), $options);
		$this->render_image_field('login_background_id', __('Login Background', 'webtaru-site-options-login-security'), $options);
		echo '<tr><th scope="row"><label for="wtols-login-error-message">' . esc_html__('Error Message', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-login-error-message" name="wtols[login_error_message]" value="' . esc_attr($options['login_error_message']) . '" class="large-text" /></td></tr>';
		$this->render_section_heading(__('Login CAPTCHA', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row"><label for="wtols-captcha-type">' . esc_html__('CAPTCHA Service', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><select id="wtols-captcha-type" name="wtols[captcha_type]">';
		echo '<option value="none" ' . selected($options['captcha_type'], 'none', false) . '>' . esc_html__('None', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="recaptcha_v3" ' . selected($options['captcha_type'], 'recaptcha_v3', false) . '>' . esc_html__('Google reCAPTCHA v3', 'webtaru-site-options-login-security') . '</option>';
		echo '<option value="turnstile" ' . selected($options['captcha_type'], 'turnstile', false) . '>' . esc_html__('Cloudflare Turnstile', 'webtaru-site-options-login-security') . '</option>';
		echo '</select></td></tr>';
		echo '<tr class="wtols-captcha-row"><th scope="row"><label for="wtols-captcha-site-key">' . esc_html__('Site Key', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-captcha-site-key" name="wtols[captcha_site_key]" value="' . esc_attr($options['captcha_site_key']) . '" class="regular-text" /></td></tr>';
		echo '<tr class="wtols-captcha-row"><th scope="row"><label for="wtols-captcha-secret-key">' . esc_html__('Secret Key', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-captcha-secret-key" name="wtols[captcha_secret_key]" value="' . esc_attr($options['captcha_secret_key']) . '" class="regular-text" /></td></tr>';
		$this->render_section_heading(__('Login Attempt Limiter', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Enable', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[limiter_enabled]" value="1" ' . checked(!empty($options['limiter_enabled']), true, false) . ' /> ';
		echo esc_html__('Block IPs after too many failed attempts.', 'webtaru-site-options-login-security') . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Max Attempts', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><input type="number" name="wtols[limiter_max_attempts]" value="' . esc_attr($options['limiter_max_attempts']) . '" min="1" max="20" class="small-text" /></td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Lockout (min)', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><input type="number" name="wtols[limiter_lockout_duration]" value="' . esc_attr($options['limiter_lockout_duration']) . '" min="1" max="1440" class="small-text" /></td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Lockout Message', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><input type="text" name="wtols[limiter_message]" value="' . esc_attr($options['limiter_message']) . '" class="large-text" /></td></tr>';
		$this->render_section_heading(__('XML-RPC Security', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Disable XML-RPC', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[disable_xmlrpc]" value="1" ' . checked(!empty($options['disable_xmlrpc']), true, false) . ' /> ';
		echo esc_html__('Disable XML-RPC to prevent pingback and brute-force attacks.', 'webtaru-site-options-login-security') . '</label></td></tr>';

		$this->render_section_heading(__('System Hardening', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Hide WP Version', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[hide_wp_version]" value="1" ' . checked(!empty($options['hide_wp_version']), true, false) . ' /> ';
		echo esc_html__('Remove WordPress version from frontend (Header, Scripts, and Styles) for better security.', 'webtaru-site-options-login-security') . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Disable File Editor', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[disable_file_editor]" value="1" ' . checked(!empty($options['disable_file_editor']), true, false) . ' /> ';
		echo esc_html__('Disable built-in theme and plugin file editors.', 'webtaru-site-options-login-security') . '</label></td></tr>';

		$this->render_section_heading(__('Security Logs', 'webtaru-site-options-login-security'));
		echo '<tr><td colspan="2">';
		if (empty($options['login_logs'])) {
			echo '<p class="description">' . esc_html__('No failed login attempts logged yet.', 'webtaru-site-options-login-security') . '</p>';
		} else {
			echo '<table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">';
			echo '<thead><tr><th>' . esc_html__('IP Address', 'webtaru-site-options-login-security') . '</th><th>' . esc_html__('Username', 'webtaru-site-options-login-security') . '</th><th>' . esc_html__('Time', 'webtaru-site-options-login-security') . '</th></tr></thead>';
			echo '<tbody>';
			foreach ($options['login_logs'] as $log) {
				echo '<tr>';
				echo '<td>' . esc_html($log['ip']) . '</td>';
				echo '<td>' . esc_html($log['user']) . '</td>';
				echo '<td>' . esc_html($log['time']) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__('Only the last 20 failed attempts are logged.', 'webtaru-site-options-login-security') . '</p>';
		}
		echo '</td></tr>';

		$this->render_section_heading(__('Locked IPs', 'webtaru-site-options-login-security'));
		echo '<tr><td colspan="2">';
		$this->render_locked_ips_table();
		echo '</td></tr>';
		echo '</tbody></table></div>';


		/* ── Tab 4: Workflow Utilities ── */
		echo '<div class="wtols-tab-panel" data-panel="utilities"><table class="form-table" role="presentation"><tbody>';
		$this->render_section_heading(__('Admin Workflow', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Classic Editor', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[disable_gutenberg]" value="1" ' . checked(!empty($options['disable_gutenberg']), true, false) . ' /> ';
		echo esc_html__('Disable Gutenberg block editor globally (revert to Classic Editor).', 'webtaru-site-options-login-security') . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Content Duplicator', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[enable_duplicator]" value="1" ' . checked(!empty($options['enable_duplicator']), true, false) . ' /> ';
		echo esc_html__('Enable "Duplicate" link for Posts, Pages, and Custom Post Types.', 'webtaru-site-options-login-security') . '</label></td></tr>';

		$this->render_section_heading(__('Maintenance Mode', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Enable Maintenance', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[enable_maintenance]" value="1" ' . checked(!empty($options['enable_maintenance']), true, false) . ' /> ';
		echo esc_html__('Block visitors with a maintenance message. Administrators can still access the site.', 'webtaru-site-options-login-security') . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-maintenance-message">' . esc_html__('Maintenance Message', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><textarea id="wtols-maintenance-message" name="wtols[maintenance_message]" rows="4" class="large-text">' . esc_textarea($options['maintenance_message']) . '</textarea></td></tr>';

		$this->render_section_heading(__('Sitewide Comments', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Disable Comments', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[disable_comments_sitewide]" value="1" ' . checked(!empty($options['disable_comments_sitewide']), true, false) . ' /> ';
		echo esc_html__('Disable all comments across the entire website.', 'webtaru-site-options-login-security') . '</label><br><br>';
		echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=wtols_delete_all_comments'), 'wtols_delete_all_comments')) . '" class="button" onclick="return confirm(\'' . esc_js(__('Are you sure you want to permanently delete all comments? This cannot be undone.', 'webtaru-site-options-login-security')) . '\');">' . esc_html__('Delete All Comments Now', 'webtaru-site-options-login-security') . '</a></td></tr>';
		echo '</tbody></table></div>';

		/* ── Tab 6: System & Backup ── */
		echo '<div class="wtols-tab-panel" data-panel="system"><table class="form-table" role="presentation"><tbody>';

		$this->render_section_heading(__('Agency Mode (White Label)', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Enable Agency Mode', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[agency_mode]" value="1" ' . checked(!empty($options['agency_mode']), true, false) . ' /> ';
		echo esc_html__('Hide plugin branding and enable custom menu naming.', 'webtaru-site-options-login-security') . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="wtols-agency-menu-name">' . esc_html__('Custom Menu Name', 'webtaru-site-options-login-security') . '</label></th>';
		echo '<td><input type="text" id="wtols-agency-menu-name" name="wtols[agency_menu_name]" value="' . esc_attr($options['agency_menu_name']) . '" class="regular-text" placeholder="' . esc_attr__('e.g. Client Settings', 'webtaru-site-options-login-security') . '" />';
		echo '<p class="description">' . esc_html__('This will replace "Dynamic Options" in the WordPress menu.', 'webtaru-site-options-login-security') . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Hide Header Icon', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[agency_hide_icon]" value="1" ' . checked(!empty($options['agency_hide_icon']), true, false) . ' /> ';
		echo esc_html__('Hide the plugin icon in the settings page header.', 'webtaru-site-options-login-security') . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Hide Dashboard Menus', 'webtaru-site-options-login-security') . '</th>';
		echo '<td>';
		$menus_to_hide = array(
			'edit.php?post_type=page' => __('Pages', 'webtaru-site-options-login-security'),
			'edit-comments.php' => __('Comments', 'webtaru-site-options-login-security'),
			'themes.php' => __('Appearance', 'webtaru-site-options-login-security'),
			'plugins.php' => __('Plugins', 'webtaru-site-options-login-security'),
			'tools.php' => __('Tools', 'webtaru-site-options-login-security'),
			'options-general.php' => __('Settings', 'webtaru-site-options-login-security'),
		);
		foreach ($menus_to_hide as $slug => $label) {
			echo '<label style="display:inline-block; width:120px;"><input type="checkbox" name="wtols[hidden_admin_menus][]" value="' . esc_attr($slug) . '" ' . checked(in_array($slug, $options['hidden_admin_menus'], true), true, false) . ' /> ' . esc_html($label) . '</label>';
		}
		echo '<p class="description">' . esc_html__('Hide these menus for non-administrator users to simplify their dashboard.', 'webtaru-site-options-login-security') . '</p></td></tr>';

		$this->render_section_heading(__('Performance & SEO', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Schema.org SEO', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[enable_schema]" value="1" ' . checked(!empty($options['enable_schema']), true, false) . ' /> ';
		echo esc_html__('Output LocalBusiness JSON-LD structured data for SEO.', 'webtaru-site-options-login-security') . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Cache Management', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><label><input type="checkbox" name="wtols[clear_cache_on_save]" value="1" ' . checked(!empty($options['clear_cache_on_save']), true, false) . ' /> ';
		echo esc_html__('Clear sitewide cache on save (supports WP Rocket, LSCache, W3TC, SG, Super Cache).', 'webtaru-site-options-login-security') . '</label><br>';
		echo '<label><input type="checkbox" name="wtols[admin_bar_clear_cache]" value="1" ' . checked(!empty($options['admin_bar_clear_cache']), true, false) . ' /> ';
		echo esc_html__('Add "Clear Cache" shortcut to admin bar.', 'webtaru-site-options-login-security') . '</label></td></tr>';

		$this->render_section_heading(__('Safe Database Cleanup', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Optimization', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=wtols_db_cleanup'), 'wtols_db_cleanup')) . '" class="button" onclick="return confirm(\'' . esc_js(__('This will delete all post revisions, auto-drafts, and expired transients. Proceed?', 'webtaru-site-options-login-security')) . '\');">' . esc_html__('Run Clean Up Now', 'webtaru-site-options-login-security') . '</a>';
		echo '<p class="description">' . esc_html__('Helps reduce database size and improve performance.', 'webtaru-site-options-login-security') . '</p></td></tr>';

		$this->render_section_heading(__('Plugin Management', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Auto-Updates & Notifications', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><div class="wtols-plugin-management-scroll" style="max-height: 350px; overflow-y: auto; border: 1px solid #ccd0d4;">';
		$this->render_plugin_management_table($options);
		echo '</div></td></tr>';

		$this->render_section_heading(__('Import & Export Settings', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Export', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=wtols_export_settings'), 'wtols_export_settings')) . '" class="button button-secondary">' . esc_html__('Download Configuration (JSON)', 'webtaru-site-options-login-security') . '</a></td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Import', 'webtaru-site-options-login-security') . '</th>';
		echo '<td>';
		echo '<input type="file" id="wtols-import-file" accept=".json" style="display:none;" onchange="if(this.files.length) { document.getElementById(\'wtols-import-submit\').style.display = \'inline-block\'; }" />';
		echo '<button type="button" class="button" onclick="document.getElementById(\'wtols-import-file\').click();">' . esc_html__('Choose JSON File', 'webtaru-site-options-login-security') . '</button> ';
		echo '<button type="submit" id="wtols-import-submit" name="wtols_import_settings_submit" class="button button-primary" style="display:none;" formaction="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=wtols_import_settings'), 'wtols_import_settings')) . '" formenctype="multipart/form-data" onclick="var f=document.getElementById(\'wtols-import-file\'); f.name=\'wtols_import_file\';">' . esc_html__('Import Now', 'webtaru-site-options-login-security') . '</button>';
		echo '</td></tr>';

		$this->render_section_heading(__('Danger Zone', 'webtaru-site-options-login-security'));
		echo '<tr><th scope="row">' . esc_html__('Uninstall Policy', 'webtaru-site-options-login-security') . '</th>';
		echo '<td>';
		echo '<label style="color: #d63638; font-weight: 600;"><input type="checkbox" name="wtols[delete_data_on_uninstall]" value="1" ' . checked(!empty($options['delete_data_on_uninstall']), true, false) . ' /> ';
		echo esc_html__('Delete all settings and logs on uninstall?', 'webtaru-site-options-login-security') . '</label>';
		echo '<p class="description">' . esc_html__('If checked, deleting the plugin will permanently remove all configuration from the database.', 'webtaru-site-options-login-security') . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__('Reset All Settings', 'webtaru-site-options-login-security') . '</th>';
		echo '<td>';
		echo '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=wtols_reset_settings'), 'wtols_reset_settings')) . '" class="button wtols-button-danger" onclick="return confirm(\'' . esc_js(__('WARNING: Are you sure you want to delete all settings and return to default? This action cannot be undone.', 'webtaru-site-options-login-security')) . '\');">' . esc_html__('Reset to Factory Defaults', 'webtaru-site-options-login-security') . '</a>';
		echo '<p class="description">' . esc_html__('This will wipe all contact details, social links, and security settings.', 'webtaru-site-options-login-security') . '</p>';
		echo '</td></tr>';

		echo '</tbody></table></div>';

		/* ── Tab 6: Shortcodes ── */
		echo '<div class="wtols-tab-panel" data-panel="shortcodes">';
		echo '<h2>' . esc_html__('Integration Shortcodes', 'webtaru-site-options-login-security') . '</h2>';
		echo '<p>' . esc_html__('Click any shortcode to copy it. Use in editor, Elementor, WPBakery, or do_shortcode().', 'webtaru-site-options-login-security') . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$shortcodes = array(
			'[wtols_phone]' => __('Primary Phone', 'webtaru-site-options-login-security'),
			'[wtols_phone number="2"]' => __('Secondary Phone', 'webtaru-site-options-login-security'),
			'[wtols_email]' => __('Primary Email', 'webtaru-site-options-login-security'),
			'[wtols_email number="2"]' => __('Secondary Email', 'webtaru-site-options-login-security'),
			'[wtols_fax]' => __('Fax Number', 'webtaru-site-options-login-security'),
			'[wtols_address]' => __('Physical Address', 'webtaru-site-options-login-security'),
			'[wtols_logo type="light"]' => __('Light Logo', 'webtaru-site-options-login-security'),
			'[wtols_logo type="dark"]' => __('Dark Logo', 'webtaru-site-options-login-security'),
			'[wtols_map]' => __('Google Map', 'webtaru-site-options-login-security'),
			'[wtols_social_links]' => __('Social Icons List', 'webtaru-site-options-login-security'),
			'[wtols_contact_card]' => __('Full Contact Card', 'webtaru-site-options-login-security'),
			'[wtols_hours]' => __('Business Hours Table', 'webtaru-site-options-login-security'),
			'[wtols_hours format="status"]' => __('Open/Closed Status', 'webtaru-site-options-login-security'),
		);

		foreach ($shortcodes as $code => $desc) {
			echo '<tr><th scope="row">' . esc_html($desc) . '</th>';
			echo '<td><ul class="wtols-shortcode-list"><li><code>' . esc_html($code) . '</code></li></ul></td></tr>';
		}

		echo '<tr><th scope="row">' . esc_html__('PHP Example', 'webtaru-site-options-login-security') . '</th>';
		echo '<td><code>&lt;?php echo do_shortcode(\'[wtols_contact_card]\'); ?&gt;</code></td></tr>';

		echo '</tbody></table></div>';

		echo '<p class="submit"><button type="submit" name="wtols_settings_submit" class="button button-primary">' . esc_html__('Save Settings', 'webtaru-site-options-login-security') . '</button>';
		if ($compact) {
			echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=wtols-settings')) . '">' . esc_html__('Open Full Settings', 'webtaru-site-options-login-security') . '</a>';
		}
		echo '</p></form>';
	}

	private function render_text_row($id, $name, $label, $value, $autocomplete = 'off', $type = 'text')
	{
		echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th>';
		echo '<td><input type="' . esc_attr($type) . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text" autocomplete="' . esc_attr($autocomplete) . '" /></td></tr>';
	}

	private function render_section_heading($title)
	{
		?>
				<tr class="wtols-section-row">
					<th colspan="2">
						<h2>
							<?php echo esc_html($title); ?>
							<span class="dashicons dashicons-arrow-down-alt2 wtols-accordion-toggle"></span>
						</h2>
					</th>
				</tr>
				<?php
	}

	private function render_logo_field($type, $label, $options)
	{
		$field_key = 'logo_' . $type . '_id';
		$preview_id = 'wtols-logo-preview-' . $type;
		$field_id = 'wtols-logo-' . $type . '-id';
		$logo_preview = '';

		if (!empty($options[$field_key])) {
			$logo_preview = wp_get_attachment_image(absint($options[$field_key]), 'medium', false, array('class' => 'wtols-logo-preview-image'));
		}
		?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($label); ?></label></th>
					<td>
						<input type="hidden" id="<?php echo esc_attr($field_id); ?>" name="wtols[<?php echo esc_attr($field_key); ?>]" value="<?php echo esc_attr(absint($options[$field_key])); ?>" />
						<div id="<?php echo esc_attr($preview_id); ?>" class="wtols-logo-preview"><?php echo $logo_preview ? wp_kses_post($logo_preview) : esc_html__('No logo selected.', 'webtaru-site-options-login-security'); ?></div>
						<p>
							<button type="button" class="button wtols-upload-logo" data-target="<?php echo esc_attr($field_id); ?>" data-preview="<?php echo esc_attr($preview_id); ?>"><?php esc_html_e('Choose Logo', 'webtaru-site-options-login-security'); ?></button>
							<button type="button" class="button wtols-remove-logo" data-target="<?php echo esc_attr($field_id); ?>" data-preview="<?php echo esc_attr($preview_id); ?>"><?php esc_html_e('Remove', 'webtaru-site-options-login-security'); ?></button>
						</p>
					</td>
				</tr>
				<?php
	}

	private function render_image_field($field_key, $label, $options)
	{
		$preview_id = 'wtols-preview-' . sanitize_html_class($field_key);
		$field_id = 'wtols-' . sanitize_html_class(str_replace('_', '-', $field_key));
		$image_preview = '';

		if (!empty($options[$field_key])) {
			$image_preview = wp_get_attachment_image(absint($options[$field_key]), 'medium', false, array('class' => 'wtols-logo-preview-image'));
		}
		?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($label); ?></label></th>
					<td>
						<input type="hidden" id="<?php echo esc_attr($field_id); ?>" name="wtols[<?php echo esc_attr($field_key); ?>]" value="<?php echo esc_attr(absint($options[$field_key])); ?>" />
						<div id="<?php echo esc_attr($preview_id); ?>" class="wtols-logo-preview"><?php echo $image_preview ? wp_kses_post($image_preview) : esc_html__('No image selected.', 'webtaru-site-options-login-security'); ?></div>
						<p>
							<button type="button" class="button wtols-upload-logo" data-target="<?php echo esc_attr($field_id); ?>" data-preview="<?php echo esc_attr($preview_id); ?>"><?php esc_html_e('Choose Image', 'webtaru-site-options-login-security'); ?></button>
							<button type="button" class="button wtols-remove-logo" data-target="<?php echo esc_attr($field_id); ?>" data-preview="<?php echo esc_attr($preview_id); ?>"><?php esc_html_e('Remove', 'webtaru-site-options-login-security'); ?></button>
						</p>
					</td>
				</tr>
				<?php
	}

	private function render_social_fields($options)
	{
		$social_links = isset($options['social_links']) && is_array($options['social_links']) ? $options['social_links'] : self::get_default_social_links();
		?>
				<div class="wtols-social-admin">
					<?php foreach (self::get_default_social_links() as $key => $default): ?>
							<?php $item = isset($social_links[$key]) && is_array($social_links[$key]) ? wp_parse_args($social_links[$key], $default) : $default; ?>
							<div class="wtols-social-row">
								<strong><?php echo esc_html($default['label']); ?></strong>
								<input type="text" name="wtols[social_links][<?php echo esc_attr($key); ?>][url]" value="<?php echo esc_attr($item['url']); ?>" placeholder="<?php esc_attr_e('Profile URL or #', 'webtaru-site-options-login-security'); ?>" />
								<input type="text" name="wtols[social_links][<?php echo esc_attr($key); ?>][icon]" value="<?php echo esc_attr($item['icon']); ?>" placeholder="<?php echo esc_attr($default['icon']); ?>" />
							</div>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e('Icon fields accept Font Awesome classes, for example: fab fa-instagram.', 'webtaru-site-options-login-security'); ?></p>
				</div>
				<?php
	}

	public function register_shortcodes()
	{
		add_shortcode('wtols_field', array($this, 'shortcode_field'));
		add_shortcode('wtols_phone', array($this, 'shortcode_phone'));
		add_shortcode('wtols_phone_1', array($this, 'shortcode_phone'));
		add_shortcode('wtols_phone_2', array($this, 'shortcode_phone_2'));
		add_shortcode('wtols_email', array($this, 'shortcode_email'));
		add_shortcode('wtols_email_1', array($this, 'shortcode_email'));
		add_shortcode('wtols_email_2', array($this, 'shortcode_email_2'));
		add_shortcode('wtols_fax', array($this, 'shortcode_fax'));
		add_shortcode('wtols_address', array($this, 'shortcode_address'));
		add_shortcode('wtols_logo', array($this, 'shortcode_logo'));
		add_shortcode('wtols_logo_light', array($this, 'shortcode_logo_light'));
		add_shortcode('wtols_logo_dark', array($this, 'shortcode_logo_dark'));
		add_shortcode('wtols_map', array($this, 'shortcode_map'));
		add_shortcode('wtols_social_links', array($this, 'shortcode_social_links'));
		add_shortcode('wtols_contact_card', array($this, 'shortcode_contact_card'));
		add_shortcode('wtols_hours', array($this, 'shortcode_hours'));
	}

	public function shortcode_field($atts)
	{
		$atts = shortcode_atts(
			array(
				'key' => 'phone',
				'link' => 'no',
				'class' => '',
			),
			$atts,
			'wtols_field'
		);

		$key = sanitize_key($atts['key']);
		$class = $this->sanitize_class_list($atts['class']);

		if (in_array($key, array('logo', 'logo_light'), true)) {
			return $this->shortcode_logo(array('type' => 'light', 'class' => $class));
		}

		if ('logo_dark' === $key) {
			return $this->shortcode_logo(array('type' => 'dark', 'class' => $class));
		}

		if ('map' === $key) {
			return $this->shortcode_map(array('class' => $class));
		}

		if (in_array($key, array('social', 'social_links'), true)) {
			return $this->shortcode_social_links(array('class' => $class));
		}

		return $this->render_field($key, 'yes' === strtolower($atts['link']), $class);
	}

	public function shortcode_phone($atts)
	{
		$atts = shortcode_atts(array('number' => '1', 'link' => 'yes', 'class' => ''), $atts, 'wtols_phone');
		$field = '2' === (string) $atts['number'] ? 'phone_2' : 'phone';

		return $this->render_field($field, 'yes' === strtolower($atts['link']), $this->sanitize_class_list($atts['class']));
	}

	public function shortcode_phone_2($atts)
	{
		$atts = shortcode_atts(array('link' => 'yes', 'class' => ''), $atts, 'wtols_phone_2');
		return $this->render_field('phone_2', 'yes' === strtolower($atts['link']), $this->sanitize_class_list($atts['class']));
	}

	public function shortcode_email($atts)
	{
		$atts = shortcode_atts(array('number' => '1', 'link' => 'yes', 'class' => ''), $atts, 'wtols_email');
		$field = '2' === (string) $atts['number'] ? 'email_2' : 'email';

		return $this->render_field($field, 'yes' === strtolower($atts['link']), $this->sanitize_class_list($atts['class']));
	}

	public function shortcode_email_2($atts)
	{
		$atts = shortcode_atts(array('link' => 'yes', 'class' => ''), $atts, 'wtols_email_2');
		return $this->render_field('email_2', 'yes' === strtolower($atts['link']), $this->sanitize_class_list($atts['class']));
	}

	public function shortcode_fax($atts)
	{
		$atts = shortcode_atts(array('link' => 'no', 'class' => ''), $atts, 'wtols_fax');
		return $this->render_field('fax', 'yes' === strtolower($atts['link']), $this->sanitize_class_list($atts['class']));
	}

	public function shortcode_address($atts)
	{
		$atts = shortcode_atts(array('link' => 'yes', 'class' => ''), $atts, 'wtols_address');
		return $this->render_field('address', 'yes' === strtolower($atts['link']), $this->sanitize_class_list($atts['class']));
	}

	public function shortcode_logo_light($atts)
	{
		$atts = shortcode_atts(array('size' => 'full', 'class' => '', 'alt' => get_bloginfo('name')), $atts, 'wtols_logo_light');
		$atts['type'] = 'light';
		return $this->shortcode_logo($atts);
	}

	public function shortcode_logo_dark($atts)
	{
		$atts = shortcode_atts(array('size' => 'full', 'class' => '', 'alt' => get_bloginfo('name')), $atts, 'wtols_logo_dark');
		$atts['type'] = 'dark';
		return $this->shortcode_logo($atts);
	}

	public function shortcode_logo($atts)
	{
		$atts = shortcode_atts(
			array(
				'type' => 'light',
				'size' => 'full',
				'class' => '',
				'alt' => get_bloginfo('name'),
				'link' => 'yes',
			),
			$atts,
			'wtols_logo'
		);

		$type = 'dark' === sanitize_key($atts['type']) ? 'dark' : 'light';
		$options = self::get_options();
		$key = 'logo_' . $type . '_id';

		if (empty($options[$key])) {
			return '';
		}

		$class = trim('wtols-logo wtols-logo--' . $type . ' ' . $this->sanitize_class_list($atts['class']));

		$image = wp_get_attachment_image(
			absint($options[$key]),
			sanitize_key($atts['size']),
			false,
			array(
				'class' => $class,
				'alt' => sanitize_text_field($atts['alt']),
			)
		);

		if ('no' === strtolower($atts['link'])) {
			return $image;
		}

		return '<a class="wtols-logo-link wtols-logo-link--' . esc_attr($type) . '" href="' . esc_url(home_url('/')) . '">' . $image . '</a>';
	}

	public function shortcode_map($atts)
	{
		$atts = shortcode_atts(
			array(
				'height' => '320',
				'class' => '',
			),
			$atts,
			'wtols_map'
		);

		$options = self::get_options();
		$map = trim($options['map']);

		if ('' === $map) {
			return '';
		}

		wp_enqueue_style('wtols-frontend');

		$class = trim('wtols-map ' . $this->sanitize_class_list($atts['class']));
		$height = max(160, absint($atts['height']));

		if (false !== stripos($map, '<iframe')) {
			$map = preg_replace('/\s(width|height)=["\'][^"\']*["\']/i', '', $map);
			return '<div class="' . esc_attr($class) . '" style="height:' . esc_attr($height) . 'px">' . wp_kses($map, $this->get_allowed_iframe_html()) . '</div>';
		}

		return sprintf(
			'<div class="%1$s" style="height:%2$dpx"><iframe src="%3$s" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="%4$s"></iframe></div>',
			esc_attr($class),
			absint($height),
			esc_url($map),
			esc_attr__('Location map', 'webtaru-site-options-login-security')
		);
	}

	public function shortcode_social_links($atts)
	{
		$atts = shortcode_atts(array('class' => '', 'target' => '_blank'), $atts, 'wtols_social_links');

		$options = self::get_options();
		$links = isset($options['social_links']) && is_array($options['social_links']) ? $options['social_links'] : array();

		if (empty($links)) {
			return '';
		}

		wp_enqueue_style('wtols-frontend');

		if (!empty($options['enable_fontawesome'])) {
			wp_enqueue_style('wtols-fontawesome');
		}

		$class = trim('wtols-social-links ' . $this->sanitize_class_list($atts['class']));
		$target = '_self' === $atts['target'] ? '_self' : '_blank';
		$output = '';

		foreach (self::get_default_social_links() as $key => $default) {
			$item = isset($links[$key]) && is_array($links[$key]) ? wp_parse_args($links[$key], $default) : $default;

			if (empty($item['url'])) {
				continue;
			}

			$icon = $this->sanitize_class_list($item['icon']);
			$item_target = $this->is_hash_url($item['url']) ? '_self' : $target;
			$rel = '_blank' === $item_target ? ' rel="noopener noreferrer"' : '';

			$output .= sprintf(
				'<a href="%1$s" target="%2$s"%3$s aria-label="%4$s"><i class="%5$s" aria-hidden="true"></i></a>',
				$this->esc_safe_url($item['url']),
				esc_attr($item_target),
				$rel,
				esc_attr($item['label']),
				esc_attr($icon)
			);
		}

		/* Custom social links */
		$custom = isset($options['custom_social_links']) && is_array($options['custom_social_links']) ? $options['custom_social_links'] : array();
		foreach ($custom as $citem) {
			if (empty($citem['url']) || empty($citem['label'])) {
				continue;
			}
			$icon = !empty($citem['icon']) ? $this->sanitize_class_list($citem['icon']) : 'fas fa-globe';
			$item_target = $this->is_hash_url($citem['url']) ? '_self' : $target;
			$rel = '_blank' === $item_target ? ' rel="noopener noreferrer"' : '';
			$output .= sprintf(
				'<a href="%1$s" target="%2$s"%3$s aria-label="%4$s"><i class="%5$s" aria-hidden="true"></i></a>',
				$this->esc_safe_url($citem['url']),
				esc_attr($item_target),
				$rel,
				esc_attr(sanitize_text_field($citem['label'])),
				esc_attr($icon)
			);
		}

		return '' === $output ? '' : '<div class="' . esc_attr($class) . '">' . $output . '</div>';
	}

	public function shortcode_contact_card($atts)
	{
		$atts = shortcode_atts(array('class' => ''), $atts, 'wtols_contact_card');

		wp_enqueue_style('wtols-frontend');

		$options = self::get_options();
		$style_class = isset($options['layout_style']) && 'pill' === $options['layout_style'] ? 'wtols-style-pill' : '';

		$class = trim('wtols-contact-card ' . $style_class . ' ' . $this->sanitize_class_list($atts['class']));

		ob_start();
		?>
				<div class="<?php echo esc_attr($class); ?>">
					<?php echo wp_kses_post($this->shortcode_logo(array('type' => 'light', 'size' => 'medium'))); ?>
					<?php echo wp_kses_post($this->render_field('phone', true, 'wtols-contact-card__item')); ?>
					<?php echo wp_kses_post($this->render_field('phone_2', true, 'wtols-contact-card__item')); ?>
					<?php echo wp_kses_post($this->render_field('email', true, 'wtols-contact-card__item')); ?>
					<?php echo wp_kses_post($this->render_field('email_2', true, 'wtols-contact-card__item')); ?>
					<?php echo wp_kses_post($this->render_field('fax', false, 'wtols-contact-card__item')); ?>
					<?php echo wp_kses_post($this->render_field('address', true, 'wtols-contact-card__item')); ?>
					<?php echo $this->shortcode_social_links(array()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<?php
				return ob_get_clean();
	}

	private function render_field($key, $link = false, $class = '')
	{
		$key = 'phone_1' === $key ? 'phone' : $key;
		$key = 'email_1' === $key ? 'email' : $key;

		if (!in_array($key, $this->field_keys, true)) {
			return '';
		}

		$options = self::get_options();
		$value = isset($options[$key]) ? trim((string) $options[$key]) : '';

		if ('' === $value) {
			return '';
		}

		$class_attr = '' !== $class ? ' class="' . esc_attr($class) . '"' : '';

		$icon_html = '';
		if (!empty($options['show_contact_icons']) && !empty($options['icon_' . $key])) {
			wp_enqueue_style('wtols-fontawesome');
			$icon_class = $this->sanitize_class_list($options['icon_' . $key]);
			$icon_html = '<i class="' . esc_attr($icon_class) . '" aria-hidden="true"></i>';
		}

		$placement = isset($options['icon_placement']) && 'after' === $options['icon_placement'] ? 'after' : 'before';

		if ('address' === $key) {
			$address = nl2br(esc_html($value));
			$inner_content = 'before' === $placement && $icon_html ? $icon_html . ' ' . $address : ('after' === $placement && $icon_html ? $address . ' ' . $icon_html : $address);
			$url = isset($options['address_link']) ? trim((string) $options['address_link']) : '';

			if ($link && '' !== $url) {
				$target = $this->is_hash_url($url) ? '_self' : '_blank';
				$rel = '_blank' === $target ? ' rel="noopener noreferrer"' : '';

				return '<a' . $class_attr . ' href="' . $this->esc_safe_url($url) . '" target="' . esc_attr($target) . '"' . $rel . '>' . $inner_content . '</a>';
			}

			return '<span' . $class_attr . '>' . $inner_content . '</span>';
		}

		$display_val = in_array($key, array('email', 'email_2'), true) ? esc_html(antispambot($value)) : esc_html($value);
		$inner_content = 'before' === $placement && $icon_html ? $icon_html . ' ' . $display_val : ('after' === $placement && $icon_html ? $display_val . ' ' . $icon_html : $display_val);

		if ($link && in_array($key, array('phone', 'phone_2', 'fax'), true)) {
			$href = preg_replace('/[^0-9+]/', '', $value);
			return '<a' . $class_attr . ' href="tel:' . esc_attr($href) . '">' . $inner_content . '</a>';
		}

		if ($link && in_array($key, array('email', 'email_2'), true)) {
			return '<a' . $class_attr . ' href="mailto:' . esc_attr(antispambot($value)) . '">' . $inner_content . '</a>';
		}

		return '<span' . $class_attr . '>' . $inner_content . '</span>';
	}

	private function sanitize_class_list($class_list)
	{
		$classes = preg_split('/\s+/', (string) $class_list);
		$classes = array_filter(array_map('sanitize_html_class', $classes));

		return implode(' ', $classes);
	}

	private function get_allowed_iframe_html()
	{
		return array(
			'iframe' => array(
				'src' => true,
				'width' => true,
				'height' => true,
				'style' => true,
				'allowfullscreen' => true,
				'loading' => true,
				'referrerpolicy' => true,
				'title' => true,
			),
		);
	}

	public function register_wpbakery_element()
	{
		if (!function_exists('vc_map')) {
			return;
		}

		vc_map(
			array(
				'name' => __('Dynamic Options Field', 'webtaru-site-options-login-security'),
				'base' => 'wtols_field',
				'category' => __('Content', 'webtaru-site-options-login-security'),
				'description' => __('Show dynamic phone, email, fax, address, logo, map, or social links.', 'webtaru-site-options-login-security'),
				'params' => array(
					array(
						'type' => 'dropdown',
						'heading' => __('Field', 'webtaru-site-options-login-security'),
						'param_name' => 'key',
						'value' => array(
							__('Phone 1', 'webtaru-site-options-login-security') => 'phone',
							__('Phone 2', 'webtaru-site-options-login-security') => 'phone_2',
							__('Email 1', 'webtaru-site-options-login-security') => 'email',
							__('Email 2', 'webtaru-site-options-login-security') => 'email_2',
							__('Fax', 'webtaru-site-options-login-security') => 'fax',
							__('Address', 'webtaru-site-options-login-security') => 'address',
							__('Light Logo', 'webtaru-site-options-login-security') => 'logo_light',
							__('Dark Logo', 'webtaru-site-options-login-security') => 'logo_dark',
							__('Map', 'webtaru-site-options-login-security') => 'map',
							__('Social Links', 'webtaru-site-options-login-security') => 'social_links',
						),
						'std' => 'phone',
					),
					array(
						'type' => 'checkbox',
						'heading' => __('Make clickable', 'webtaru-site-options-login-security'),
						'param_name' => 'link',
						'value' => array(__('Yes', 'webtaru-site-options-login-security') => 'yes'),
					),
					array(
						'type' => 'textfield',
						'heading' => __('CSS Class', 'webtaru-site-options-login-security'),
						'param_name' => 'class',
					),
				),
			)
		);

		vc_map(
			array(
				'name' => __('Dynamic Social Links', 'webtaru-site-options-login-security'),
				'base' => 'wtols_social_links',
				'category' => __('Content', 'webtaru-site-options-login-security'),
				'description' => __('Show configured social media links with Font Awesome icons.', 'webtaru-site-options-login-security'),
				'params' => array(
					array(
						'type' => 'textfield',
						'heading' => __('CSS Class', 'webtaru-site-options-login-security'),
						'param_name' => 'class',
					),
				),
			)
		);
	}

	public function register_elementor_widgets($widgets_manager)
	{
		if (!did_action('elementor/loaded') || !class_exists('\Elementor\Widget_Base')) {
			return;
		}

		require_once WTOLS_PLUGIN_DIR . 'includes/class-wtols-elementor-widget.php';
		$widgets_manager->register(new WTOLS_Elementor_Widget());
	}

	/* ──────────────────────────────────────────────
	 * v2.0 — Custom Social Links admin fields
	 * ────────────────────────────────────────────── */

	private function render_custom_social_fields($options)
	{
		$custom = isset($options['custom_social_links']) && is_array($options['custom_social_links']) ? $options['custom_social_links'] : array();
		echo '<div class="wtols-social-admin"><div class="wtols-custom-social-list">';
		foreach ($custom as $i => $item) {
			$item = wp_parse_args($item, array('label' => '', 'url' => '', 'icon' => ''));
			echo '<div class="wtols-custom-social-row wtols-social-row">';
			echo '<input type="text" name="wtols[custom_social_links][' . (int) $i . '][label]" value="' . esc_attr($item['label']) . '" placeholder="' . esc_attr__('Label', 'webtaru-site-options-login-security') . '" />';
			echo '<input type="text" name="wtols[custom_social_links][' . (int) $i . '][url]" value="' . esc_attr($item['url']) . '" placeholder="' . esc_attr__('Profile URL or #', 'webtaru-site-options-login-security') . '" />';
			echo '<input type="text" name="wtols[custom_social_links][' . (int) $i . '][icon]" value="' . esc_attr($item['icon']) . '" placeholder="fab fa-globe" />';
			echo '<button type="button" class="button wtols-remove-custom-social">&times;</button>';
			echo '</div>';
		}
		echo '</div>';
		echo '<button type="button" class="button wtols-add-custom-social">' . esc_html__('+ Add Custom Link', 'webtaru-site-options-login-security') . '</button>';
		echo '<p class="description">' . esc_html__('Add any platform — TikTok, Telegram, GitHub, etc. Use Font Awesome icon classes.', 'webtaru-site-options-login-security') . '</p>';
		echo '</div>';
	}

	private function sanitize_custom_social_links($raw)
	{
		$clean = array();
		if (!is_array($raw)) {
			return $clean;
		}
		foreach ($raw as $item) {
			if (!is_array($item)) {
				continue;
			}
			$label = isset($item['label']) ? sanitize_text_field($item['label']) : '';
			$url = isset($item['url']) ? $this->sanitize_safe_url($item['url']) : '';
			$icon = isset($item['icon']) ? $this->sanitize_class_list($item['icon']) : '';
			if ('' !== $label && '' !== $url) {
				$clean[] = array('label' => $label, 'url' => $url, 'icon' => $icon);
			}
		}
		return $clean;
	}

	/* ──────────────────────────────────────────────
	 * v2.0 — Business Hours
	 * ────────────────────────────────────────────── */

	private function render_business_hours_fields($options)
	{
		$hours = isset($options['business_hours']) && is_array($options['business_hours']) ? $options['business_hours'] : self::get_default_business_hours();
		$day_labels = array(
			'monday' => __('Monday', 'webtaru-site-options-login-security'),
			'tuesday' => __('Tuesday', 'webtaru-site-options-login-security'),
			'wednesday' => __('Wednesday', 'webtaru-site-options-login-security'),
			'thursday' => __('Thursday', 'webtaru-site-options-login-security'),
			'friday' => __('Friday', 'webtaru-site-options-login-security'),
			'saturday' => __('Saturday', 'webtaru-site-options-login-security'),
			'sunday' => __('Sunday', 'webtaru-site-options-login-security'),
		);
		echo '<div class="wtols-hours-grid">';
		foreach ($day_labels as $key => $label) {
			$day = isset($hours[$key]) ? wp_parse_args($hours[$key], array('enabled' => 0, 'open' => '09:00', 'close' => '18:00')) : array('enabled' => 0, 'open' => '09:00', 'close' => '18:00');
			echo '<div class="wtols-hours-day-row">';
			echo '<input type="checkbox" class="wtols-hours-enabled" name="wtols[business_hours][' . esc_attr($key) . '][enabled]" value="1" ' . checked(!empty($day['enabled']), true, false) . ' />';
			echo '<span class="wtols-hours-day-label">' . esc_html($label) . '</span>';
			echo '<input type="time" class="wtols-hours-time" name="wtols[business_hours][' . esc_attr($key) . '][open]" value="' . esc_attr($day['open']) . '" />';
			echo '<span class="wtols-hours-separator">—</span>';
			echo '<input type="time" class="wtols-hours-time" name="wtols[business_hours][' . esc_attr($key) . '][close]" value="' . esc_attr($day['close']) . '" />';
			echo '</div>';
		}
		echo '</div>';
	}

	private function sanitize_business_hours($raw)
	{
		$days = array_keys(self::get_default_business_hours());
		$clean = array();
		foreach ($days as $day) {
			$item = isset($raw[$day]) && is_array($raw[$day]) ? $raw[$day] : array();
			$clean[$day] = array(
				'enabled' => !empty($item['enabled']) ? 1 : 0,
				'open' => isset($item['open']) ? sanitize_text_field($item['open']) : '09:00',
				'close' => isset($item['close']) ? sanitize_text_field($item['close']) : '18:00',
			);
		}
		return $clean;
	}

	public function shortcode_hours($atts)
	{
		$atts = shortcode_atts(array('format' => 'full', 'class' => ''), $atts, 'wtols_hours');
		$options = self::get_options();

		wp_enqueue_style('wtols-frontend');

		$class = trim('wtols-hours ' . $this->sanitize_class_list($atts['class']));

		/* Status badge */
		if ('status' === $atts['format']) {
			$is_open = $this->is_currently_open($options);
			$label = $is_open ? __('Currently Open', 'webtaru-site-options-login-security') : __('Currently Closed', 'webtaru-site-options-login-security');
			$status = $is_open ? 'open' : 'closed';
			return '<span class="wtols-hours-status wtols-hours-status--' . esc_attr($status) . '">' . esc_html($label) . '</span>';
		}

		/* Freeform text takes priority */
		$freeform = isset($options['business_hours_freeform']) ? trim($options['business_hours_freeform']) : '';
		if ('' !== $freeform) {
			return '<div class="' . esc_attr($class) . '">' . nl2br(esc_html($freeform)) . '</div>';
		}

		/* Structured table */
		$hours = isset($options['business_hours']) && is_array($options['business_hours']) ? $options['business_hours'] : self::get_default_business_hours();
		$day_labels = array(
			'monday' => __('Mon', 'webtaru-site-options-login-security'),
			'tuesday' => __('Tue', 'webtaru-site-options-login-security'),
			'wednesday' => __('Wed', 'webtaru-site-options-login-security'),
			'thursday' => __('Thu', 'webtaru-site-options-login-security'),
			'friday' => __('Fri', 'webtaru-site-options-login-security'),
			'saturday' => __('Sat', 'webtaru-site-options-login-security'),
			'sunday' => __('Sun', 'webtaru-site-options-login-security'),
		);

		$output = '<table class="wtols-hours-table">';
		foreach ($day_labels as $key => $label) {
			$day = isset($hours[$key]) ? $hours[$key] : array('enabled' => 0);
			$output .= '<tr><td class="wtols-hours-day">' . esc_html($label) . '</td>';
			if (!empty($day['enabled'])) {
				$output .= '<td>' . esc_html($day['open']) . ' — ' . esc_html($day['close']) . '</td>';
			} else {
				$output .= '<td class="wtols-hours-closed">' . esc_html__('Closed', 'webtaru-site-options-login-security') . '</td>';
			}
			$output .= '</tr>';
		}
		$output .= '</table>';

		return '<div class="' . esc_attr($class) . '">' . $output . '</div>';
	}

	private function is_currently_open($options)
	{
		$hours = isset($options['business_hours']) && is_array($options['business_hours']) ? $options['business_hours'] : array();
		$today = strtolower(wp_date('l'));
		if (!isset($hours[$today]) || empty($hours[$today]['enabled'])) {
			return false;
		}
		$now = wp_date('H:i');
		$open = $hours[$today]['open'];
		$close = $hours[$today]['close'];
		return ($now >= $open && $now <= $close);
	}

	/* ──────────────────────────────────────────────
	 * v2.0 — Locked IPs Table
	 * ────────────────────────────────────────────── */

	private function render_locked_ips_table()
	{
		$locked = $this->get_all_locked_ips();
		if (empty($locked)) {
			echo '<p class="wtols-no-lockouts">' . esc_html__('No IPs are currently locked out.', 'webtaru-site-options-login-security') . '</p>';
			return;
		}
		echo '<table class="wtols-locked-ips-table"><thead><tr><th>' . esc_html__('IP Address', 'webtaru-site-options-login-security') . '</th><th>' . esc_html__('Action', 'webtaru-site-options-login-security') . '</th></tr></thead><tbody>';
		foreach ($locked as $ip) {
			echo '<tr><td>' . esc_html($ip) . '</td><td><button type="button" class="button wtols-unlock-ip" data-ip="' . esc_attr($ip) . '">' . esc_html__('Unlock', 'webtaru-site-options-login-security') . '</button></td></tr>';
		}
		echo '</tbody></table>';
	}

	private function get_all_locked_ips()
	{
		global $wpdb;
		$prefix = '_transient_' . self::LIMITER_LOCKOUT_PREFIX;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col($wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$prefix . '%'
		));
		$ips = array();
		foreach ($results as $name) {
			$hash = str_replace('_transient_' . self::LIMITER_LOCKOUT_PREFIX, '', $name);
			$ip = get_transient(self::LIMITER_LOCKOUT_PREFIX . $hash);
			if (false !== $ip) {
				$ips[] = $ip;
			}
		}
		return $ips;
	}

	/* ──────────────────────────────────────────────
	 * v2.0 — Schema.org JSON-LD
	 * ────────────────────────────────────────────── */

	public function render_schema_jsonld()
	{
		if (is_admin()) {
			return;
		}
		$options = self::get_options();
		if (empty($options['enable_schema'])) {
			return;
		}
		$schema = array(
			'@context' => 'https://schema.org',
			'@type' => 'LocalBusiness',
			'name' => get_bloginfo('name'),
			'url' => home_url('/'),
		);
		if (!empty($options['phone'])) {
			$schema['telephone'] = $options['phone'];
		}
		if (!empty($options['email'])) {
			$schema['email'] = $options['email'];
		}
		if (!empty($options['fax'])) {
			$schema['faxNumber'] = $options['fax'];
		}
		if (!empty($options['address'])) {
			$schema['address'] = array('@type' => 'PostalAddress', 'streetAddress' => $options['address']);
		}
		if (!empty($options['logo_light_url'])) {
			$schema['logo'] = $options['logo_light_url'];
		}
		$same_as = array();
		$social = isset($options['social_links']) && is_array($options['social_links']) ? $options['social_links'] : array();
		foreach ($social as $link) {
			if (!empty($link['url']) && !$this->is_hash_url($link['url'])) {
				$same_as[] = $link['url'];
			}
		}
		$custom_social = isset($options['custom_social_links']) && is_array($options['custom_social_links']) ? $options['custom_social_links'] : array();
		foreach ($custom_social as $link) {
			if (!empty($link['url']) && !$this->is_hash_url($link['url'])) {
				$same_as[] = $link['url'];
			}
		}
		if (!empty($same_as)) {
			$schema['sameAs'] = $same_as;
		}
		/* Opening hours from structured data */
		$hours = isset($options['business_hours']) && is_array($options['business_hours']) ? $options['business_hours'] : array();
		$day_map = array('monday' => 'Mo', 'tuesday' => 'Tu', 'wednesday' => 'We', 'thursday' => 'Th', 'friday' => 'Fr', 'saturday' => 'Sa', 'sunday' => 'Su');
		$specs = array();
		foreach ($day_map as $day => $code) {
			if (!empty($hours[$day]['enabled'])) {
				$specs[] = array(
					'@type' => 'OpeningHoursSpecification',
					'dayOfWeek' => $code,
					'opens' => $hours[$day]['open'],
					'closes' => $hours[$day]['close'],
				);
			}
		}
		if (!empty($specs)) {
			$schema['openingHoursSpecification'] = $specs;
		}
		echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>' . "\n";
	}

	/* ──────────────────────────────────────────────
	 * v2.0 — Login Attempt Limiter
	 * ────────────────────────────────────────────── */

	private function get_client_ip()
	{
		$ip = '';
		if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$forwarded = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
			$parts = explode(',', $forwarded);
			$ip = trim($parts[0]);
		} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
			$ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
		}
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
	}

	private function get_ip_hash($ip)
	{
		return md5($ip . wp_salt('auth'));
	}

	public function track_failed_login($username)
	{
		$options = self::get_options();
		if (empty($options['limiter_enabled'])) {
			return;
		}
		$ip = $this->get_client_ip();
		$hash = $this->get_ip_hash($ip);
		$max = absint($options['limiter_max_attempts']);
		$duration = absint($options['limiter_lockout_duration']) * MINUTE_IN_SECONDS;
		$attempts = (int) get_transient(self::LIMITER_ATTEMPTS_PREFIX . $hash);
		$attempts++;
		set_transient(self::LIMITER_ATTEMPTS_PREFIX . $hash, $attempts, $duration);
		if ($attempts >= $max) {
			set_transient(self::LIMITER_LOCKOUT_PREFIX . $hash, $ip, $duration);
			delete_transient(self::LIMITER_ATTEMPTS_PREFIX . $hash);
		}
	}

	public function check_login_lockout($user, $username, $password)
	{
		$options = self::get_options();
		if (empty($options['limiter_enabled'])) {
			return $user;
		}
		$ip = $this->get_client_ip();
		$hash = $this->get_ip_hash($ip);
		if (false !== get_transient(self::LIMITER_LOCKOUT_PREFIX . $hash)) {
			$message = !empty($options['limiter_message']) ? $options['limiter_message'] : self::get_default_options()['limiter_message'];
			return new \WP_Error('wtols_locked', esc_html($message));
		}
		return $user;
	}

	public function clear_login_attempts($user_login, $user)
	{
		$ip = $this->get_client_ip();
		$hash = $this->get_ip_hash($ip);
		delete_transient(self::LIMITER_ATTEMPTS_PREFIX . $hash);
	}

	public function ajax_unlock_ip()
	{
		if (!current_user_can('manage_options')) {
			wp_send_json_error();
		}
		check_ajax_referer('wtols_unlock_ip', '_wpnonce');
		$ip = isset($_POST['ip']) ? sanitize_text_field(wp_unslash($_POST['ip'])) : '';
		if ('' === $ip) {
			wp_send_json_error();
		}
		$hash = $this->get_ip_hash($ip);
		delete_transient(self::LIMITER_LOCKOUT_PREFIX . $hash);
		delete_transient(self::LIMITER_ATTEMPTS_PREFIX . $hash);
		wp_send_json_success();
	}

	/* ──────────────────────────────────────────────
	 * v3.0 — Security & Performance
	 * ────────────────────────────────────────────── */

	public function filter_xmlrpc_enabled($enabled)
	{
		$options = self::get_options();
		if (!empty($options['disable_xmlrpc'])) {
			return false;
		}
		return $enabled;
	}

	public function enqueue_captcha_scripts()
	{
		$options = self::get_options();
		if ('recaptcha_v3' === $options['captcha_type'] && !empty($options['captcha_site_key'])) {
			wp_enqueue_script('wtols-recaptcha-v3', 'https://www.google.com/recaptcha/api.js?render=' . esc_attr($options['captcha_site_key']), array(), WTOLS_VERSION, true);
			wp_add_inline_script('wtols-recaptcha-v3', '
				document.addEventListener("DOMContentLoaded", function() {
					var forms = document.querySelectorAll("#loginform, #lostpasswordform, #registerform");
					if (forms.length > 0) {
						grecaptcha.ready(function() {
							forms.forEach(function(form) {
								form.addEventListener("submit", function(e) {
									var input = form.querySelector("input[name=\'g-recaptcha-response\']");
									if (input && !input.value) {
										e.preventDefault();
										grecaptcha.execute("' . esc_js($options['captcha_site_key']) . '", {action: "login"}).then(function(token) {
											input.value = token;
											form.submit();
										});
									}
								});
							});
						});
					}
				});
			');
		} elseif ('turnstile' === $options['captcha_type'] && !empty($options['captcha_site_key'])) {
			$turnstile_url = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
			// phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent
			wp_enqueue_script('wtols-turnstile', $turnstile_url, array(), WTOLS_VERSION, true);
		}
	}

	public function render_captcha_field()
	{
		$options = self::get_options();
		if ('recaptcha_v3' === $options['captcha_type'] && !empty($options['captcha_site_key'])) {
			echo '<input type="hidden" name="g-recaptcha-response" value="" />';
		} elseif ('turnstile' === $options['captcha_type'] && !empty($options['captcha_site_key'])) {
			echo '<div class="cf-turnstile" data-sitekey="' . esc_attr($options['captcha_site_key']) . '" style="margin-bottom:15px;"></div>';
		}
	}

	public function verify_captcha($user, $username, $password)
	{
		// Only check on actual login submission
		if (empty($username) || is_wp_error($user)) {
			return $user;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if (!isset($_POST['wp-submit'])) {
			return $user;
		}

		$options = self::get_options();
		if ('none' === $options['captcha_type'] || empty($options['captcha_secret_key'])) {
			return $user;
		}

		$response = '';
		$url = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ('recaptcha_v3' === $options['captcha_type'] && isset($_POST['g-recaptcha-response'])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
			$response = wp_unslash($_POST['g-recaptcha-response']);
			$url = 'https://www.google.com/recaptcha/api/siteverify';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ('turnstile' === $options['captcha_type'] && isset($_POST['cf-turnstile-response'])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
			$response = wp_unslash($_POST['cf-turnstile-response']);
			$url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
		}

		if (empty($response)) {
			return new \WP_Error('wtols_captcha_missing', esc_html__('Please complete the CAPTCHA verification.', 'webtaru-site-options-login-security'));
		}

		$verify = wp_remote_post(
			$url,
			array(
				'body' => array(
					'secret' => $options['captcha_secret_key'],
					'response' => $response,
					'remoteip' => $this->get_client_ip(),
				),
			)
		);

		if (is_wp_error($verify)) {
			return new \WP_Error('wtols_captcha_error', esc_html__('Error verifying CAPTCHA. Please try again.', 'webtaru-site-options-login-security'));
		}

		$body = json_decode(wp_remote_retrieve_body($verify), true);
		if (!isset($body['success']) || !$body['success']) {
			return new \WP_Error('wtols_captcha_invalid', esc_html__('Invalid CAPTCHA response. Please try again.', 'webtaru-site-options-login-security'));
		}

		if ('recaptcha_v3' === $options['captcha_type'] && isset($body['score']) && $body['score'] < 0.5) {
			return new \WP_Error('wtols_captcha_bot', esc_html__('Failed security check.', 'webtaru-site-options-login-security'));
		}

		return $user;
	}

	public function flush_cache_on_save($option, $old_value, $value)
	{
		if (self::OPTION_KEY !== $option) {
			return;
		}
		if (empty($value['clear_cache_on_save'])) {
			return;
		}

		$this->execute_cache_flush();
	}

	public function add_admin_bar_clear_cache($wp_admin_bar)
	{
		$options = self::get_options();
		if (empty($options['admin_bar_clear_cache'])) {
			return;
		}

		$title = !empty($options['agency_mode'])
			? __('Clear Cache', 'webtaru-site-options-login-security')
			: __('Clear Cache ', 'webtaru-site-options-login-security');

		$wp_admin_bar->add_node(
			array(
				'id' => 'wtols-clear-cache',
				'title' => $title,
				'href' => wp_nonce_url(admin_url('admin-post.php?action=wtols_clear_cache'), 'wtols_clear_cache'),
			)
		);
	}

	public function handle_clear_cache()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to clear cache.', 'webtaru-site-options-login-security'));
		}

		check_admin_referer('wtols_clear_cache');

		$this->execute_cache_flush();

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url('admin.php?page=wtols-settings');
		$raw_referer = wp_get_raw_referer();
		$safe_redirect = $raw_referer ? esc_url_raw($raw_referer) : $redirect;
		wp_safe_redirect(add_query_arg(array('wtols_cache_cleared' => '1'), $safe_redirect));
		exit;
	}

	public function render_admin_notices()
	{
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (isset($_GET['wtols_saved'])) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'webtaru-site-options-login-security') . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (isset($_GET['wtols_cache_cleared'])) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cache successfully cleared sitewide.', 'webtaru-site-options-login-security') . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if (isset($_GET['wtols_comments_deleted'])) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('All comments have been successfully deleted.', 'webtaru-site-options-login-security') . '</p></div>';
		}
	}

	private function execute_cache_flush()
	{
		// WP Rocket
		if (function_exists('rocket_clean_domain')) {
			rocket_clean_domain();
		}
		// LiteSpeed Cache
		if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
			\LiteSpeed_Cache_API::purge_all();
		} elseif (has_action('litespeed_purge_all')) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			do_action('litespeed_purge_all');
		}
		// W3 Total Cache
		if (function_exists('w3tc_flush_all')) {
			w3tc_flush_all();
		}
		// SG Optimizer
		if (function_exists('sg_cachepress_purge_cache')) {
			sg_cachepress_purge_cache();
		}
		// WP Super Cache
		if (function_exists('wp_cache_clear_cache')) {
			wp_cache_clear_cache();
		}
	}

	public function init_utilities()
	{
		$options = self::get_options();
		if (!empty($options['disable_gutenberg'])) {
			add_filter('use_block_editor_for_post', '__return_false', 10);
			add_filter('use_widgets_block_editor', '__return_false', 10);
		}
		if (!empty($options['disable_file_editor']) && !defined('DISALLOW_FILE_EDIT')) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			define('DISALLOW_FILE_EDIT', true);
		}
		if (!empty($options['disable_comments_sitewide'])) {
			add_filter('comments_open', '__return_false', 20, 2);
			add_filter('pings_open', '__return_false', 20, 2);
			add_filter('comments_array', '__return_empty_array', 10, 2);
		}
	}

	public function render_styling_css_vars()
	{
		$options = self::get_options();
		if (empty($options['color_text']) && empty($options['color_background']) && empty($options['color_icon'])) {
			return;
		}

		$css = ':root {';
		if (!empty($options['color_text'])) {
			$css .= '--wtols-text-color: ' . esc_attr($options['color_text']) . ';';
		}
		if (!empty($options['color_background'])) {
			$css .= '--wtols-bg-color: ' . esc_attr($options['color_background']) . ';';
		}
		if (!empty($options['color_icon'])) {
			$css .= '--wtols-icon-color: ' . esc_attr($options['color_icon']) . ';';
		}
		$css .= '}';

		wp_add_inline_style('wtols-frontend', $css);
	}


	public function add_duplicate_post_link($actions, $post)
	{
		$options = self::get_options();
		if (empty($options['enable_duplicator']) || !current_user_can('edit_posts')) {
			return $actions;
		}
		$actions['wtols_duplicate'] = '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=wtols_duplicate_post&post=' . $post->ID), 'wtols_duplicate_post_' . $post->ID) . '" title="' . esc_attr__('Duplicate this item', 'webtaru-site-options-login-security') . '" rel="permalink">' . esc_html__('Duplicate', 'webtaru-site-options-login-security') . '</a>';
		return $actions;
	}

	public function handle_duplicate_post()
	{
		$options = self::get_options();
		if (empty($options['enable_duplicator']) || !current_user_can('edit_posts')) {
			wp_die(esc_html__('Duplicate feature is disabled or you do not have permission.', 'webtaru-site-options-login-security'));
		}

		$post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
		if (!$post_id) {
			wp_die(esc_html__('No post to duplicate.', 'webtaru-site-options-login-security'));
		}

		check_admin_referer('wtols_duplicate_post_' . $post_id);

		$post = get_post($post_id);
		if (!$post) {
			wp_die(esc_html__('Post not found.', 'webtaru-site-options-login-security'));
		}

		$current_user = wp_get_current_user();
		$new_post_author = $current_user->ID;

		$args = array(
			'comment_status' => $post->comment_status,
			'ping_status' => $post->ping_status,
			'post_author' => $new_post_author,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_name' => $post->post_name,
			'post_parent' => $post->post_parent,
			'post_password' => $post->post_password,
			'post_status' => 'draft',
			'post_title' => $post->post_title . ' (Copy)',
			'post_type' => $post->post_type,
			'to_ping' => $post->to_ping,
			'menu_order' => $post->menu_order,
		);

		$new_post_id = wp_insert_post($args);

		$taxonomies = get_object_taxonomies($post->post_type);
		foreach ($taxonomies as $taxonomy) {
			$post_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'slugs'));
			wp_set_object_terms($new_post_id, $post_terms, $taxonomy, false);
		}

		$post_meta_infos = get_post_meta($post_id);
		if (count($post_meta_infos) !== 0) {
			foreach ($post_meta_infos as $meta_key => $meta_values) {
				foreach ($meta_values as $meta_value) {
					add_post_meta($new_post_id, $meta_key, $meta_value);
				}
			}
		}

		wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_post_id));
		exit;
	}

	public function handle_delete_all_comments()
	{
		if (!current_user_can('moderate_comments')) {
			wp_die(esc_html__('You do not have permission to delete comments.', 'webtaru-site-options-login-security'));
		}

		check_admin_referer('wtols_delete_all_comments');

		global $wpdb;

		// Optional: Clear comment count cache
		wp_cache_delete('comment_count', 'counts');

		// Perform deletion
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query("DELETE FROM {$wpdb->comments}");
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query("DELETE FROM {$wpdb->commentmeta}");

		// Update comment count on all posts
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query("UPDATE {$wpdb->posts} SET comment_count = 0");

		$redirect = admin_url('admin.php?page=wtols-settings&wtols_comments_deleted=1&wtols_active_tab=utilities');
		wp_safe_redirect($redirect);
		exit;
	}

	private function render_plugin_management_table($options)
	{
		// Removed to satisfy WordPress.org requirements
		echo '<p>' . esc_html__('Plugin update management is not available in the WordPress.org version of this plugin.', 'webtaru-site-options-login-security') . '</p>';
	}

	public function handle_export_settings()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to export settings.', 'webtaru-site-options-login-security'));
		}

		check_admin_referer('wtols_export_settings');

		$options = self::get_options();
		$json = wp_json_encode($options);

		header('Content-Description: File Transfer');
		header('Content-Type: application/json');
		header('Content-Disposition: attachment; filename=wtols-settings-export-' . gmdate('Y-m-d') . '.json');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . strlen($json));

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private function get_user_ip()
	{
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			return sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
		} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			return sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
		} else {
			return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
		}
	}

	public function handle_import_settings()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to import settings.', 'webtaru-site-options-login-security'));
		}

		check_admin_referer('wtols_import_settings');

		$file_path = isset($_FILES['wtols_import_file']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['wtols_import_file']['tmp_name'])) : '';
		if (empty($file_path)) {
			wp_die(esc_html__('No file uploaded.', 'webtaru-site-options-login-security'));
		}
		$file_content = file_get_contents($file_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$imported_data = json_decode($file_content, true);

		if (!is_array($imported_data)) {
			wp_die(esc_html__('Invalid JSON file.', 'webtaru-site-options-login-security'));
		}

		$current_options = self::get_options();
		$merged_options = wp_parse_args($imported_data, $current_options);

		update_option(self::OPTION_KEY, $merged_options);

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url('themes.php?page=wtols-settings');
		wp_safe_redirect(add_query_arg('wtols_saved', '1', $redirect));
		exit;
	}

	public function handle_version_hiding()
	{
		$options = self::get_options();
		if (empty($options['hide_wp_version'])) {
			return;
		}

		// Remove version from header
		remove_action('wp_head', 'wp_generator');
		add_filter('the_generator', '__return_empty_string');

		// Remove version from scripts and styles
		add_filter('script_loader_src', array($this, 'remove_wp_version_strings'), 9999);
		add_filter('style_loader_src', array($this, 'remove_wp_version_strings'), 9999);
	}

	public function remove_wp_version_strings($src)
	{
		if (strpos($src, 'ver=' . get_bloginfo('version'))) {
			$src = remove_query_arg('ver', $src);
		}
		return $src;
	}

	public function handle_reset_settings()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to reset settings.', 'webtaru-site-options-login-security'));
		}

		check_admin_referer('wtols_reset_settings');

		delete_option(self::OPTION_KEY);

		$redirect = admin_url('themes.php?page=wtols-settings');
		wp_safe_redirect(add_query_arg(array('wtols_saved' => '1', 'wtols_reset' => '1'), $redirect));
		exit;
	}

	public function handle_maintenance_mode()
	{
		$options = self::get_options();
		if (empty($options['enable_maintenance']) || current_user_can('manage_options') || is_login()) {
			return;
		}

		wp_die(
			wp_kses_post($options['maintenance_message']),
			esc_html__('Maintenance Mode', 'webtaru-site-options-login-security'),
			array('response' => 503)
		);
	}

	public function render_sticky_whatsapp()
	{
		$options = self::get_options();
		if (empty($options['enable_sticky_whatsapp']) || empty($options['whatsapp_number'])) {
			return;
		}

		$number = preg_replace('/[^0-9]/', '', $options['whatsapp_number']);
		if (empty($number)) {
			return;
		}

		$message = '';
		if (!empty($options['sticky_whatsapp_message'])) {
			$message = '?text=' . rawurlencode(trim($options['sticky_whatsapp_message']));
		}

		$url = 'https://wa.me/' . $number . $message;
		
		$position_class = 'right' === $options['sticky_whatsapp_position'] ? 'wtols-wa-pos-right' : 'wtols-wa-pos-left';
		
		$display_class = '';
		if ('mobile' === $options['sticky_whatsapp_display']) {
			$display_class = 'wtols-wa-display-mobile';
		} elseif ('desktop' === $options['sticky_whatsapp_display']) {
			$display_class = 'wtols-wa-display-desktop';
		} else {
			$display_class = 'wtols-wa-display-both';
		}

		$wrapper_classes = array('wtols-sticky-whatsapp', $position_class, $display_class);
		if (!empty($options['enable_mobile_buttons'])) {
			$wrapper_classes[] = 'wtols-mbb-offset';
		}

		$icon_size = isset($options['sticky_whatsapp_icon_size']) ? (int) $options['sticky_whatsapp_icon_size'] : 32;

		echo '<a href="' . esc_url($url) . '" class="' . esc_attr(implode(' ', $wrapper_classes)) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr__('WhatsApp Chat', 'webtaru-site-options-login-security') . '">';
		echo '<i class="fab fa-whatsapp" style="font-size: ' . esc_attr($icon_size) . 'px;"></i>';
		echo '</a>';
	}

	public function render_rating_widget()
	{
		$options = self::get_options();
		if (empty($options['enable_rating_widget'])) {
			return;
		}

		$provider = $options['rating_widget_provider'];
		$score = esc_html($options['rating_widget_score']);
		$link = esc_url($options['rating_widget_link']);
		$display = $options['rating_widget_display'];

		$display_class = '';
		if ('mobile' === $display) {
			$display_class = 'wtols-rw-display-mobile';
		} elseif ('desktop' === $display) {
			$display_class = 'wtols-rw-display-desktop';
		}

		$icon_type = isset($options['rating_widget_icon_type']) ? $options['rating_widget_icon_type'] : 'icon';
		$image_url = isset($options['rating_widget_image_url']) ? $options['rating_widget_image_url'] : '';

		$wrapper_classes = array('wtols-rating-widget', 'wtols-rw-' . $provider, $display_class);
		if (!empty($options['enable_mobile_buttons'])) {
			$wrapper_classes[] = 'wtols-mbb-offset';
		}

		$icon_html = '';
		$title = '';
		if ('image' === $icon_type && !empty($image_url)) {
			$icon_html = '<div class="wtols-rw-icon wtols-rw-custom-image"><img src="' . esc_url($image_url) . '" alt="" style="max-width:32px; max-height:32px; display:block;" /></div>';
			$title = ('google' === $provider) ? __('Google rating', 'webtaru-site-options-login-security') : __('Trustpilot', 'webtaru-site-options-login-security');
		} else {
			if ('google' === $provider) {
				$icon_html = '<div class="wtols-rw-icon"><i class="fab fa-google" style="color: #4285F4;"></i></div>';
				$title = __('Google rating', 'webtaru-site-options-login-security');
			} else {
				$icon_html = '<div class="wtols-rw-icon"><i class="fab fa-trustpilot" style="color: #00b67a;"></i></div>';
				$title = __('Trustpilot', 'webtaru-site-options-login-security');
			}
		}

		$score_float = (float) $score;
		$stars_html = '<div class="wtols-rw-stars">';
		for ($i = 1; $i <= 5; $i++) {
			if ($score_float >= $i) {
				$stars_html .= '<i class="fas fa-star"></i>';
			} elseif ($score_float >= ($i - 0.5)) {
				$stars_html .= '<i class="fas fa-star-half-alt"></i>';
			} else {
				$stars_html .= '<i class="far fa-star"></i>';
			}
		}
		$stars_html .= '</div>';

		echo '<a href="' . esc_url($link) . '" class="' . esc_attr(implode(' ', $wrapper_classes)) . '" target="_blank" rel="noopener noreferrer">';
		echo '<div class="wtols-rw-inner">';
		echo wp_kses($icon_html, array('div' => array('class' => array()), 'i' => array('class' => array(), 'style' => array()), 'img' => array('src' => array(), 'alt' => array(), 'style' => array())));
		echo '<div class="wtols-rw-content">';
		echo '<div class="wtols-rw-title">' . esc_html($title) . '</div>';
		echo '<div class="wtols-rw-score-wrap"><span class="wtols-rw-score">' . esc_html($score) . '</span> ' . wp_kses($stars_html, array('div' => array('class' => array()), 'i' => array('class' => array()))) . '</div>';
		echo '</div>'; // content
		echo '</div>'; // inner
		echo '</a>';
	}

	public function render_back_to_top()
	{
		$options = self::get_options();
		if (empty($options['enable_back_to_top'])) {
			return;
		}

		$bg_color = isset($options['back_to_top_bg_color']) ? $options['back_to_top_bg_color'] : '#5cb85c';
		$icon_color = isset($options['back_to_top_icon_color']) ? $options['back_to_top_icon_color'] : '#ffffff';
		$shape = isset($options['back_to_top_shape']) ? $options['back_to_top_shape'] : 'square';
		$size  = isset($options['back_to_top_size']) ? (int) $options['back_to_top_size'] : 20;

		$display = isset($options['back_to_top_display']) ? $options['back_to_top_display'] : 'both';
		$display_class = '';
		if ('mobile' === $display) {
			$display_class = 'wtols-btt-display-mobile';
		} elseif ('desktop' === $display) {
			$display_class = 'wtols-btt-display-desktop';
		}

		$classes = array('wtols-back-to-top', $display_class, 'wtols-btt-' . $shape);
		if (!empty($options['enable_mobile_buttons'])) {
			$classes[] = 'wtols-mbb-offset';
		}

		$style = 'background-color: ' . esc_attr($bg_color) . '; color: ' . esc_attr($icon_color) . '; font-size: ' . esc_attr($size) . 'px;';

		echo '<a href="#" class="' . esc_attr(implode(' ', $classes)) . '" aria-label="' . esc_attr__('Back to top', 'webtaru-site-options-login-security') . '" style="' . esc_attr($style) . '">';
		echo '<i class="fas fa-chevron-up"></i>';
		echo '</a>';
	}

	public function render_mobile_bottom_buttons()
	{
		$options = self::get_options();
		if (empty($options['enable_mobile_buttons'])) {
			return;
		}

		$btn1_text = trim($options['mobile_button_1_text']);
		$btn1_link = trim($options['mobile_button_1_link']);
		$btn1_color = isset($options['mobile_button_1_color']) ? $options['mobile_button_1_color'] : '#5cb85c';
		$btn1_text_color = isset($options['mobile_button_1_text_color']) ? $options['mobile_button_1_text_color'] : '#ffffff';

		$btn2_text = trim($options['mobile_button_2_text']);
		$btn2_link = trim($options['mobile_button_2_link']);
		$btn2_color = isset($options['mobile_button_2_color']) ? $options['mobile_button_2_color'] : '#0275d8';
		$btn2_text_color = isset($options['mobile_button_2_text_color']) ? $options['mobile_button_2_text_color'] : '#ffffff';

		if (empty($btn1_text) && empty($btn2_text)) {
			return;
		}

		echo '<div class="wtols-mobile-bottom-bar">';
		if (!empty($btn1_text)) {
			echo '<a href="' . esc_url($btn1_link) . '" class="wtols-mbb-btn" style="background-color: ' . esc_attr($btn1_color) . '; color: ' . esc_attr($btn1_text_color) . ';">' . esc_html($btn1_text) . '</a>';
		}
		if (!empty($btn2_text)) {
			echo '<a href="' . esc_url($btn2_link) . '" class="wtols-mbb-btn" style="background-color: ' . esc_attr($btn2_color) . '; color: ' . esc_attr($btn2_text_color) . ';">' . esc_html($btn2_text) . '</a>';
		}
		echo '</div>';
	}

	public function log_failed_login_attempt($username)

	{
		$options = self::get_options();
		if (empty($options['limiter_enabled'])) {
			return;
		}

		$logs = isset($options['login_logs']) ? $options['login_logs'] : array();
		$ip = $this->get_user_ip();

		array_unshift($logs, array(
			'ip' => $ip,
			'user' => sanitize_text_field($username),
			'time' => current_time('mysql'),
		));

		// Limit to 20 entries
		$logs = array_slice($logs, 0, 20);

		$options['login_logs'] = $logs;
		update_option(self::OPTION_KEY, $options);
	}

	public function handle_db_cleanup()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to perform cleanup.', 'webtaru-site-options-login-security'));
		}

		check_admin_referer('wtols_db_cleanup');

		global $wpdb;

		// 1. Delete Revisions
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query($wpdb->prepare("DELETE FROM $wpdb->posts WHERE post_type = %s", 'revision'));

		// 2. Delete Auto-drafts
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query($wpdb->prepare("DELETE FROM $wpdb->posts WHERE post_status = %s", 'auto-draft'));

		// 3. Delete Expired Transients
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name LIKE %s AND option_value < %d", '_transient_timeout_%', time()));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_%' AND NOT EXISTS (SELECT 1 FROM $wpdb->options AS o2 WHERE o2.option_name = CONCAT('_transient_timeout_', SUBSTRING($wpdb->options.option_name, 12)))");

		wp_safe_redirect(add_query_arg(array('wtols_saved' => '1', 'wtols_cleaned' => '1'), admin_url('themes.php?page=wtols-settings&wtols_active_tab=utilities')));
		exit;
	}

	public function organize_admin_menu()
	{
		if (current_user_can('manage_options')) {
			return; // Don't lock out administrators
		}

		$options = self::get_options();
		$hidden = isset($options['hidden_admin_menus']) ? $options['hidden_admin_menus'] : array();

		if (empty($hidden)) {
			return;
		}

		foreach ($hidden as $slug) {
			remove_menu_page($slug);
		}
	}

	public function render_sticky_vertical_btn()
	{
		$options = self::get_options();
		if (empty($options['enable_sticky_vertical_btn'])) {
			return;
		}

		$text = !empty($options['sticky_vertical_btn_text']) ? $options['sticky_vertical_btn_text'] : '';
		$icon = !empty($options['sticky_vertical_btn_icon']) ? $options['sticky_vertical_btn_icon'] : '';
		$id_class = !empty($options['sticky_vertical_btn_id_class']) ? trim($options['sticky_vertical_btn_id_class']) : '';
		
		$bg_color = !empty($options['sticky_vertical_btn_bg_color']) ? $options['sticky_vertical_btn_bg_color'] : '#0073aa';
		$text_color = !empty($options['sticky_vertical_btn_text_color']) ? $options['sticky_vertical_btn_text_color'] : '#ffffff';
		$icon_color = !empty($options['sticky_vertical_btn_icon_color']) ? $options['sticky_vertical_btn_icon_color'] : '#ffffff';
		$position = isset($options['sticky_vertical_btn_position']) && $options['sticky_vertical_btn_position'] !== '' ? intval($options['sticky_vertical_btn_position']) : 50;

		$attr_id = '';
		$attr_class = 'wtols-sticky-vertical-btn';
		
		if (!empty($id_class)) {
			if (strpos($id_class, '#') === 0) {
				$attr_id = substr($id_class, 1);
			} elseif (strpos($id_class, '.') === 0) {
				$attr_class .= ' ' . substr($id_class, 1);
			} else {
				$attr_class .= ' ' . $id_class;
			}
		}

		$style = sprintf(
			'background-color: %s; color: %s; top: %d%%;',
			$bg_color,
			$text_color,
			$position
		);

		printf(
			'<div %s class="%s" style="%s">',
			!empty($attr_id) ? 'id="' . esc_attr($attr_id) . '"' : '',
			esc_attr($attr_class),
			esc_attr($style)
		);
		echo '<a href="#" class="wtols-sticky-vertical-btn-inner" style="color: ' . esc_attr($text_color) . ';" onclick="event.preventDefault();">';
		if (!empty($icon)) {
			echo '<i class="' . esc_attr($icon) . '" style="color: ' . esc_attr($icon_color) . ';"></i>';
		}
		if (!empty($text)) {
			echo '<span class="wtols-sticky-vertical-btn-text">' . esc_html($text) . '</span>';
		}
		echo '</a></div>';
	}
}