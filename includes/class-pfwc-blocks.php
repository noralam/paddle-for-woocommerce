<?php
/**
 * WooCommerce Blocks integration for Paddle payment gateway.
 *
 * Registers the Paddle payment method with the WC Blocks checkout
 * so it appears in both the classic shortcode and block-based checkout.
 */

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class PFWC_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method name — must match the gateway ID.
	 *
	 * @var string
	 */
	protected $name = 'paddle';

	/**
	 * Initialize the block integration.
	 */
	public function initialize() {
		$this->settings = get_option('woocommerce_paddle_settings', []);
	}

	/**
	 * Check if the payment method is active / ready.
	 */
	public function is_active() {
		return isset($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
	}

	/**
	 * Register and return the handles of the scripts this method needs on the frontend.
	 *
	 * @return string[] Script handle names.
	 */
	public function get_payment_method_script_handles() {
		$api_mode = isset($this->settings['api_mode']) ? $this->settings['api_mode'] : 'classic';
		$is_classic = $api_mode === 'classic';

		// Load the right Paddle.js version
		if ($is_classic) {
			wp_register_script('paddle-js', 'https://cdn.paddle.com/paddle/paddle.js', [], null, true);
		} else {
			wp_register_script('paddle-js', 'https://cdn.paddle.com/paddle/v2/paddle.js', [], null, true);
		}

		wp_register_script(
			'pfwc-blocks-checkout',
			PFWC_PLUGIN_URL . 'assets/js/pfwc-blocks-checkout.js',
			['paddle-js', 'wc-blocks-registry', 'wc-settings', 'wc-blocks-data-store', 'wp-element', 'wp-html-entities'],
			PFWC_VERSION,
			true
		);

		return ['pfwc-blocks-checkout'];
	}

	/**
	 * Data passed to the frontend JS via wc.wcSettings.getSetting('paddle_data').
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$api_mode    = isset($this->settings['api_mode']) ? $this->settings['api_mode'] : 'classic';
		$environment = isset($this->settings['environment']) ? $this->settings['environment'] : 'sandbox';
		$is_classic  = $api_mode === 'classic';

		$data = [
			'title'       => wp_specialchars_decode(isset($this->settings['title']) ? $this->settings['title'] : __('Pay with Card, PayPal & More', 'paddle-for-woocommerce'), ENT_QUOTES),
			'description' => wp_specialchars_decode(isset($this->settings['description']) ? $this->settings['description'] : '', ENT_QUOTES),
			'supports'    => ['products'],
			'api_mode'    => $api_mode,
			'environment' => $environment,
			'locale'      => isset($this->settings['locale']) ? $this->settings['locale'] : 'auto',
			'icon'        => isset($this->settings['payment_icons']) ? esc_url($this->settings['payment_icons']) : '',
			'confirm_url' => add_query_arg('wc-ajax', 'pfwc_confirm_payment', wc_get_checkout_url()),
		];

		if ($is_classic) {
			$data['vendor_id'] = isset($this->settings['vendor_id']) ? absint($this->settings['vendor_id']) : 0;
		} else {
			$data['client_token']     = isset($this->settings['client_token']) ? $this->settings['client_token'] : '';
			$data['checkout_variant'] = isset($this->settings['checkout_variant']) ? $this->settings['checkout_variant'] : 'multi-page';
			$data['checkout_theme']   = isset($this->settings['checkout_theme']) ? $this->settings['checkout_theme'] : 'light';
		}

		return $data;
	}
}
