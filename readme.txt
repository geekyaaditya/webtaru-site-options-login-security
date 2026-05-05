=== Webtaru Site Options and Login Security ===
Contributors: webtaru, aadityasharma
Tags: security, login, captcha, business hours, elementor
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage contact info, business hours, schema SEO, and harden login security with custom slugs and CAPTCHA from a single premium admin panel.

== Description ==

Webtaru Site Options and Login Security is a comprehensive toolkit for WordPress administrators to manage essential site information, business hours, and visual branding, while simultaneously hardening your site's security by allowing you to change the default login URL and add CAPTCHA protection.

== Features ==
* **Secure Login Customization:** Change your WordPress login URL to a custom slug to prevent brute-force attacks.
* **CAPTCHA Integration:** Support for Google reCAPTCHA v3 and Cloudflare Turnstile to protect your login forms.
* **Branding Management:** Easily manage site logos, login page aesthetics, and custom contact icons.
* **Business Hours:** Flexible weekly schedule management with built-in "Open/Closed" status shortcodes.
* **Schema.org Integration:** Automatic LocalBusiness JSON-LD generation for improved SEO.
* **Workflow Utilities:** Disable Gutenberg, enable content duplication, and perform database cleanup with one click.
* **Agency Mode:** White-label the plugin by customizing the menu name and hiding the default icon.

== External services ==

This plugin supports the following third-party services to enhance site security:

* **Google reCAPTCHA (v3):** Used to protect the login form from automated bot attacks.
    * **Service:** Google reCAPTCHA
    * **Usage:** Verification of human users during login.
    * **Data Sent:** User's IP address and browser interaction signals.
    * **Privacy Policy:** https://policies.google.com/privacy
    * **Terms of Service:** https://policies.google.com/terms

* **Cloudflare Turnstile:** A privacy-focused alternative to CAPTCHA for protecting login forms.
    * **Service:** Cloudflare Turnstile
    * **Usage:** Verification of human users during login.
    * **Data Sent:** Browser and device telemetry.
    * **Privacy Policy:** https://www.cloudflare.com/privacypolicy/
    * **Terms of Service:** https://www.cloudflare.com/website-terms/

== Shortcodes ==
* `[wtols_phone]` - Display primary phone.
* `[wtols_email]` - Display primary email.
* `[wtols_address]` - Display business address.
* `[wtols_logo]` - Display light/dark logo.
* `[wtols_map]` - Display embedded map.
* `[wtols_social_links]` - Display social icons.
* `[wtols_hours]` - Display business hours or open/closed status.
* `[wtols_contact_card]` - Display a complete contact info block.

== Installation ==
1. Upload the `webtaru-site-options-login-security` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Webtaru Site Options** in your admin menu to configure settings.

== Changelog ==
= 2.2.6 =
* Refactored and rebranded as "Webtaru Site Options and Login Security".
* REMOVED: Prohibited arbitrary code insertion features (Header/Footer code, PHP snippets, Custom CSS).
* HARDENED: Security through strict input sanitization and late-stage output escaping.
* IMPROVED: Standardized asset enqueuing and style injection.
* UPDATED: Documentation to disclose external service usage (Google reCAPTCHA, Cloudflare Turnstile).

= 1.0.0 =
* Initial release of the rebranded version.
