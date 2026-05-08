<?php
/**
 * Plugin Name: Webtaru Site Options and Login Security
 * Description: Securely manage site options, logos, social links, schema.org, and enhance login security with CAPTCHA and custom login URL.
 * Version: 2.3.0
 * Author: Aaditya Sharma
 * Author URI: https://profiles.wordpress.org/aadityasharma/
 * Plugin URI: https://github.com/geekyaaditya/webtaru-site-options-login-security
 * Text Domain: webtaru-site-options-login-security
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WTOLS_VERSION', '2.3.0' );
define( 'WTOLS_PLUGIN_FILE', __FILE__ );
define( 'WTOLS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WTOLS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WTOLS_PLUGIN_DIR . 'includes/class-wtols-plugin.php';

register_activation_hook( __FILE__, array( 'WTOLS_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WTOLS_Plugin', 'deactivate' ) );

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'themes.php?page=wtols-settings' ) ) . '">' . __( 'Settings', 'webtaru-site-options-login-security' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);

add_action(
	'plugins_loaded',
	static function () {
		WTOLS_Plugin::instance();
	}
);