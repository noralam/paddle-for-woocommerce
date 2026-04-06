<?php
/**
 * Plugin Name: Paddle for WooCommerce
 * Plugin URI: https://developer.paddle.com/
 * Description: Paddle Billing payment gateway for WooCommerce. Accept credit cards, PayPal, Apple Pay, Google Pay, and 15+ local payment methods with multi-currency support in 30+ currencies.
 * Version: 1.0.6
 * Author: Noor Alam
 * Author URI: https://wpthemespace.com/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 10.5
 * Text Domain: paddle-for-woocommerce
 * Domain Path: /languages
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

define('PFWC_VERSION', '1.0.6');
define('PFWC_PLUGIN_FILE', __FILE__);
define('PFWC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PFWC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PFWC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Initialize the plugin after all plugins are loaded.
 */
add_action('plugins_loaded', 'pfwc_init');

function pfwc_init() {
	if (!class_exists('WooCommerce')) {
		add_action('admin_notices', 'pfwc_woocommerce_missing_notice');
		return;
	}

	// Load includes
	require_once PFWC_PLUGIN_DIR . 'includes/class-pfwc-api.php';
	require_once PFWC_PLUGIN_DIR . 'includes/class-pfwc-gateway.php';
	require_once PFWC_PLUGIN_DIR . 'includes/class-pfwc-webhook.php';

	// Register payment gateway
	add_filter('woocommerce_payment_gateways', 'pfwc_register_gateway');

	// Initialize webhook listener
	new PFWC_Webhook();

	// Register AJAX hooks at plugin level.
	// CRITICAL: The gateway constructor only runs when WC lazy-loads gateways
	// (e.g. on checkout page render). During wc-ajax requests, gateways are NOT
	// auto-loaded, so hooks registered in the constructor never fire.
	// This mirrors how the old paddle-woo-checkout plugin registers its AJAX
	// endpoints in Paddle_WC_Checkout (bootstrap), not in the gateway class.
	add_action('wc_ajax_pfwc_confirm_payment', 'pfwc_ajax_confirm_payment');
	add_action('wc_ajax_nopriv_pfwc_confirm_payment', 'pfwc_ajax_confirm_payment');
	add_action('wc_ajax_pfwc_pay_order', 'pfwc_ajax_pay_order');
	add_action('wc_ajax_nopriv_pfwc_pay_order', 'pfwc_ajax_pay_order');

	// Add settings link on plugins page
	add_filter('plugin_action_links_' . PFWC_PLUGIN_BASENAME, 'pfwc_plugin_action_links');
}

/**
 * Register the Paddle gateway with WooCommerce.
 */
function pfwc_register_gateway($gateways) {
	$gateways[] = 'PFWC_Gateway';
	return $gateways;
}

/**
 * Add Settings link on the plugins page.
 */
function pfwc_plugin_action_links($links) {
	$settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=paddle');
	array_unshift($links, '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'paddle-for-woocommerce') . '</a>');
	return $links;
}

/**
 * AJAX: Confirm payment — forces gateway loading and delegates.
 * Registered at plugin level so it works even when WC hasn't lazy-loaded gateways.
 */
function pfwc_ajax_confirm_payment() {
	$gateways = WC()->payment_gateways()->payment_gateways();
	if (isset($gateways['paddle'])) {
		$gateways['paddle']->ajax_confirm_payment();
	} else {
		wp_send_json_error(['message' => 'Gateway not available.']);
	}
}

/**
 * AJAX: Pay order — forces gateway loading and delegates.
 */
function pfwc_ajax_pay_order() {
	$gateways = WC()->payment_gateways()->payment_gateways();
	if (isset($gateways['paddle'])) {
		$gateways['paddle']->ajax_pay_order();
	} else {
		wp_send_json_error(['message' => 'Gateway not available.']);
	}
}

/**
 * Show admin notice if WooCommerce is not active.
 */
function pfwc_woocommerce_missing_notice() {
	echo '<div class="error"><p><strong>' . esc_html__('Paddle for WooCommerce', 'paddle-for-woocommerce') . '</strong> ' . esc_html__('requires WooCommerce to be installed and active.', 'paddle-for-woocommerce') . '</p></div>';
}

/**
 * Declare compatibility with WooCommerce HPOS (High-Performance Order Storage).
 */
add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

/**
 * Register Paddle payment method with WooCommerce Blocks checkout.
 */
add_action('woocommerce_blocks_loaded', function () {
	if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
		return;
	}

	require_once PFWC_PLUGIN_DIR . 'includes/class-pfwc-blocks.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ($registry) {
			$registry->register(new PFWC_Blocks());
		}
	);
});
