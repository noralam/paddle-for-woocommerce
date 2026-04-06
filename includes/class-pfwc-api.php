<?php
/**
 * Paddle API client — supports both Classic and Billing API modes.
 *
 * Classic: vendors.paddle.com/api/2.0/ (vendor_id + auth_code)
 * Billing: api.paddle.com/ (Bearer API key)
 */

defined('ABSPATH') || exit;

class PFWC_API {

	// Billing API
	const LIVE_API_URL    = 'https://api.paddle.com/';
	const SANDBOX_API_URL = 'https://sandbox-api.paddle.com/';

	// Classic API
	const CLASSIC_LIVE_API_URL    = 'https://vendors.paddle.com/api/2.0/';
	const CLASSIC_SANDBOX_API_URL = 'https://sandbox-vendors.paddle.com/api/2.0/';

	/**
	 * Get cached gateway settings.
	 */
	private static function get_settings() {
		return get_option('woocommerce_paddle_settings', []);
	}

	/**
	 * Get the current API mode (classic or billing).
	 */
	public static function get_api_mode() {
		$settings = self::get_settings();
		return isset($settings['api_mode']) ? $settings['api_mode'] : 'classic';
	}

	/**
	 * Check if Classic mode.
	 */
	public static function is_classic() {
		return self::get_api_mode() === 'classic';
	}

	/**
	 * Get the Billing API base URL based on environment.
	 */
	public static function get_api_url() {
		$settings    = self::get_settings();
		$environment = isset($settings['environment']) ? $settings['environment'] : 'sandbox';
		return $environment === 'live' ? self::LIVE_API_URL : self::SANDBOX_API_URL;
	}

	/**
	 * Get the Classic API base URL based on environment.
	 */
	public static function get_classic_api_url() {
		$settings    = self::get_settings();
		$environment = isset($settings['environment']) ? $settings['environment'] : 'sandbox';
		return $environment === 'live' ? self::CLASSIC_LIVE_API_URL : self::CLASSIC_SANDBOX_API_URL;
	}

	/**
	 * Get the Billing API key from settings.
	 */
	public static function get_api_key() {
		$settings = self::get_settings();
		return isset($settings['api_key']) ? $settings['api_key'] : '';
	}

	/**
	 * Get Classic vendor ID from settings.
	 */
	public static function get_vendor_id() {
		$settings = self::get_settings();
		return isset($settings['vendor_id']) ? absint($settings['vendor_id']) : 0;
	}

	/**
	 * Get Classic vendor auth code from settings.
	 */
	public static function get_vendor_auth_code() {
		$settings = self::get_settings();
		return isset($settings['vendor_auth_code']) ? $settings['vendor_auth_code'] : '';
	}

	/**
	 * Make an authenticated request to the Paddle Billing API.
	 *
	 * @param string $endpoint API endpoint path (e.g., 'transactions').
	 * @param array  $data     Request body data.
	 * @param string $method   HTTP method (POST, GET, PATCH).
	 * @return array|WP_Error  Parsed response data or WP_Error.
	 */
	public static function request($endpoint, $data = [], $method = 'POST') {
		$url     = self::get_api_url() . ltrim($endpoint, '/');
		$api_key = self::get_api_key();

		if (empty($api_key)) {
			return new WP_Error('pfwc_no_api_key', __('Paddle API key is not configured.', 'paddle-for-woocommerce'));
		}

		$args = [
			'method'  => $method,
			'timeout' => 45,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
		];

		if (!empty($data) && in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
			$args['body'] = wp_json_encode($data);
		}

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) {
			error_log('PFWC API connection error: ' . $response->get_error_message());
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$body        = json_decode(wp_remote_retrieve_body($response), true);

		if ($status_code >= 400) {
			$error_message = isset($body['error']['detail']) ? $body['error']['detail'] : 'Unknown Paddle API error';
			$error_code    = isset($body['error']['code']) ? $body['error']['code'] : 'pfwc_api_error';
			error_log(sprintf('PFWC API error (%d): %s - %s', $status_code, $error_code, $error_message));
			return new WP_Error('pfwc_api_error', $error_message, ['status' => $status_code, 'body' => $body]);
		}

		return isset($body['data']) ? $body['data'] : $body;
	}

	// =========================================================================
	// CLASSIC API METHODS
	// =========================================================================

	/**
	 * Make a request to the Paddle Classic API (vendors.paddle.com).
	 *
	 * @param string $endpoint API endpoint path (e.g., 'product/generate_pay_link').
	 * @param array  $data     POST body data.
	 * @return array|WP_Error  Parsed response or WP_Error.
	 */
	public static function classic_request($endpoint, $data = []) {
		$url       = self::get_classic_api_url() . ltrim($endpoint, '/');
		$vendor_id = self::get_vendor_id();
		$auth_code = self::get_vendor_auth_code();

		if (empty($vendor_id) || empty($auth_code)) {
			return new WP_Error('pfwc_no_classic_credentials', __('Paddle Classic credentials (Vendor ID / Auth Code) are not configured.', 'paddle-for-woocommerce'));
		}

		$data['vendor_id']        = $vendor_id;
		$data['vendor_auth_code'] = $auth_code;

		$response = wp_remote_post($url, [
			'timeout' => 45,
			'body'    => $data,
		]);

		if (is_wp_error($response)) {
			error_log('PFWC Classic API connection error: ' . $response->get_error_message());
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$body        = json_decode(wp_remote_retrieve_body($response), true);

		if (!isset($body['success']) || $body['success'] !== true) {
			$error_message = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown Paddle Classic API error';
			error_log(sprintf('PFWC Classic API error (%d): %s', $status_code, $error_message));
			return new WP_Error('pfwc_classic_api_error', $error_message, ['status' => $status_code, 'body' => $body]);
		}

		return isset($body['response']) ? $body['response'] : $body;
	}

	/**
	 * Generate a Paddle Classic pay link for a WooCommerce order.
	 *
	 * @param WC_Order $order The WooCommerce order.
	 * @return string|WP_Error  Checkout URL string or WP_Error.
	 */
	public static function generate_pay_link($order) {
		$settings  = self::get_settings();
		$tax_mode  = isset($settings['tax_mode']) ? $settings['tax_mode'] : 'paddle';

		// Build product title from order items
		$product_names = [];
		$send_names    = isset($settings['send_product_names']) ? $settings['send_product_names'] : 'yes';

		if ($send_names === 'yes') {
			foreach ($order->get_items() as $item) {
				$product_names[] = $item->get_name() . ' x' . $item->get_quantity();
			}
		}

		$title = !empty($product_names)
			? mb_substr(implode(', ', $product_names), 0, 200)
			: sprintf(__('Order #%s', 'paddle-for-woocommerce'), $order->get_order_number());

		// Calculate total
		$total = (float) $order->get_total();
		if ($tax_mode === 'internal') {
			$total -= (float) $order->get_total_tax();
		}

		$currency = strtoupper($order->get_currency());

		$webhook_url = add_query_arg([
			'wc_order_id'  => $order->get_id(),
			'wc_order_key' => $order->get_order_key(),
		], get_bloginfo('url') . '/wc-api/pfwc_webhook');

		$data = [
			'title'              => $title,
			'webhook_url'        => $webhook_url,
			'prices'             => [$currency . ':' . number_format($total, 2, '.', '')],
			'customer_email'     => $order->get_billing_email(),
			'customer_country'   => $order->get_billing_country(),
			'customer_postcode'  => $order->get_billing_postcode(),
			'passthrough'        => wp_json_encode([
				'wc_order_id'  => (string) $order->get_id(),
				'wc_order_key' => $order->get_order_key(),
			]),
			'return_url'         => $order->get_checkout_order_received_url(),
			'quantity_variable'  => 0,
		];

		$response = self::classic_request('product/generate_pay_link', $data);

		if (is_wp_error($response)) {
			return $response;
		}

		if (!isset($response['url'])) {
			return new WP_Error('pfwc_no_pay_link', __('Paddle did not return a checkout URL.', 'paddle-for-woocommerce'));
		}

		return $response['url'];
	}

	/**
	 * Retrieve a Paddle Billing transaction by ID.
	 *
	 * Used to verify payment status as a fallback when webhooks are delayed.
	 *
	 * @param string $txn_id Paddle transaction ID (e.g., txn_...).
	 * @return array|WP_Error Transaction data or WP_Error.
	 */
	public static function get_transaction($txn_id) {
		return self::request('transactions/' . sanitize_text_field($txn_id), [], 'GET');
	}

	/**
	 * Create a Paddle transaction for a WooCommerce order.
	 *
	 * Uses non-catalog (inline) items so WooCommerce products don't need
	 * to be synced to the Paddle product catalog.
	 *
	 * @param WC_Order $order The WooCommerce order.
	 * @return array|WP_Error  Transaction data or WP_Error.
	 */
	public static function create_transaction($order) {
		$settings      = get_option('woocommerce_paddle_settings', []);
		$currency_code = strtoupper($order->get_currency());
		$tax_mode      = isset($settings['tax_mode']) ? $settings['tax_mode'] : 'paddle';
		$items         = [];

		// Build line items from order products
		foreach ($order->get_items() as $item) {
			$product  = $item->get_product();
			$quantity = max(1, $item->get_quantity());

			// Calculate unit price
			$line_total = (float) $item->get_total();
			$unit_price = $line_total / $quantity;

			// If WC prices include tax and we want Paddle to handle tax separately
			if ($tax_mode === 'internal') {
				$line_tax  = (float) $item->get_total_tax();
				$unit_tax  = $line_tax / $quantity;
				$unit_price = $unit_price - $unit_tax;
			}

			$product_name = $product ? $product->get_name() : $item->get_name();

			$items[] = [
				'quantity' => $quantity,
				'price'    => [
					'name'        => mb_substr($product_name, 0, 255),
					'description' => mb_substr($product_name, 0, 1000),
					'unit_price'  => [
						'amount'        => self::to_paddle_amount($unit_price, $currency_code),
						'currency_code' => $currency_code,
					],
					'product' => [
						'name'         => mb_substr($product_name, 0, 255),
						'tax_category' => 'standard',
					],
					'quantity' => [
						'minimum' => 1,
						'maximum' => max($quantity, 100),
					],
				],
			];
		}

		// Add shipping as a line item
		$shipping_total = (float) $order->get_shipping_total();
		if ($shipping_total > 0) {
			if ($tax_mode === 'internal') {
				$shipping_total -= (float) $order->get_shipping_tax();
			}

			$shipping_label = $order->get_shipping_method() ?: __('Shipping', 'paddle-for-woocommerce');

			$items[] = [
				'quantity' => 1,
				'price'    => [
					'name'        => mb_substr($shipping_label, 0, 255),
					'description' => mb_substr($shipping_label, 0, 1000),
					'unit_price'  => [
						'amount'        => self::to_paddle_amount($shipping_total, $currency_code),
						'currency_code' => $currency_code,
					],
					'product' => [
						'name'         => __('Shipping', 'paddle-for-woocommerce'),
						'tax_category' => 'standard',
					],
					'quantity' => [
						'minimum' => 1,
						'maximum' => 1,
					],
				],
			];
		}

		// Add order fees as line items
		foreach ($order->get_fees() as $fee) {
			$fee_total = (float) $fee->get_total();
			if (abs($fee_total) < 0.01) {
				continue;
			}

			$fee_name = $fee->get_name() ?: __('Fee', 'paddle-for-woocommerce');

			$items[] = [
				'quantity' => 1,
				'price'    => [
					'name'        => mb_substr($fee_name, 0, 255),
					'description' => mb_substr($fee_name, 0, 1000),
					'unit_price'  => [
						'amount'        => self::to_paddle_amount(abs($fee_total), $currency_code),
						'currency_code' => $currency_code,
					],
					'product' => [
						'name'         => mb_substr($fee_name, 0, 255),
						'tax_category' => 'standard',
					],
					'quantity' => [
						'minimum' => 1,
						'maximum' => 1,
					],
				],
			];
		}

		if (empty($items)) {
			return new WP_Error('pfwc_no_items', __('No items found in order.', 'paddle-for-woocommerce'));
		}

		$transaction_data = [
			'items'       => $items,
			'custom_data' => [
				'wc_order_id'  => (string) $order->get_id(),
				'wc_order_key' => $order->get_order_key(),
			],
			'checkout' => [
				'url' => wc_get_checkout_url(),
			],
		];

		return self::request('transactions', $transaction_data);
	}

	/**
	 * Convert a WooCommerce decimal amount to Paddle's lowest denomination string.
	 *
	 * Paddle uses the smallest currency unit (e.g., cents for USD).
	 * Most currencies have 2 decimal places; JPY, KRW, VND have 0.
	 *
	 * @param float  $amount        Decimal amount (e.g., 10.50).
	 * @param string $currency_code ISO 4217 currency code.
	 * @return string Amount in lowest denomination (e.g., "1050").
	 */
	public static function to_paddle_amount($amount, $currency_code) {
		$zero_decimal_currencies = ['JPY', 'KRW', 'VND'];

		if (in_array(strtoupper($currency_code), $zero_decimal_currencies, true)) {
			return (string) intval(round($amount));
		}

		return (string) intval(round($amount * 100));
	}

	/**
	 * Convert a Paddle lowest denomination amount back to decimal.
	 *
	 * @param string|int $amount        Amount in lowest denomination.
	 * @param string     $currency_code ISO 4217 currency code.
	 * @return float Decimal amount.
	 */
	public static function from_paddle_amount($amount, $currency_code) {
		$zero_decimal_currencies = ['JPY', 'KRW', 'VND'];

		if (in_array(strtoupper($currency_code), $zero_decimal_currencies, true)) {
			return (float) $amount;
		}

		return round((float) $amount / 100, 2);
	}

	/**
	 * Verify a Paddle Billing webhook signature (HMAC-SHA256).
	 *
	 * @param string $raw_body         Raw request body.
	 * @param string $signature_header Paddle-Signature header value (ts=...;h1=...).
	 * @return bool True if valid.
	 */
	public static function verify_webhook_signature($raw_body, $signature_header) {
		$settings       = self::get_settings();
		$webhook_secret = isset($settings['webhook_secret']) ? $settings['webhook_secret'] : '';

		if (empty($webhook_secret) || empty($signature_header)) {
			return false;
		}

		$parts = [];
		foreach (explode(';', $signature_header) as $segment) {
			$kv = explode('=', $segment, 2);
			if (count($kv) === 2) {
				$parts[trim($kv[0])] = trim($kv[1]);
			}
		}

		if (!isset($parts['ts'], $parts['h1'])) {
			return false;
		}

		$timestamp     = $parts['ts'];
		$expected_hash = $parts['h1'];

		if (abs(time() - intval($timestamp)) > 300) {
			error_log('PFWC Webhook: Timestamp too old, possible replay attack.');
			return false;
		}

		$signed_payload = $timestamp . ':' . $raw_body;
		$computed_hash  = hash_hmac('sha256', $signed_payload, $webhook_secret);

		return hash_equals($computed_hash, $expected_hash);
	}

	/**
	 * Verify a Paddle Classic webhook signature (p_signature + openssl RSA).
	 *
	 * @param array $post_data The $_POST superglobal data from Classic webhook.
	 * @return bool True if valid.
	 */
	public static function verify_classic_webhook_signature($post_data) {
		$settings   = self::get_settings();
		$public_pem = isset($settings['classic_public_key']) ? trim($settings['classic_public_key']) : '';

		// Auto-fetch public key from Paddle API if not manually configured
		// (mirrors old paddle-woo-checkout plugin's getPaddleVendorKey behavior)
		if (empty($public_pem)) {
			error_log('PFWC Webhook: classic_public_key not set in settings, auto-fetching from API...');
			$public_pem = self::fetch_classic_public_key();
		}

		if (empty($public_pem)) {
			error_log('PFWC Webhook: No public key available (manual or auto-fetched). Cannot verify.');
			return false;
		}

		if (!isset($post_data['p_signature'])) {
			error_log('PFWC Webhook: No p_signature in POST data.');
			return false;
		}

		$public_key = openssl_get_publickey(trim($public_pem));
		if (!$public_key) {
			error_log('PFWC Webhook: openssl_get_publickey() failed. Key starts with: ' . substr($public_pem, 0, 40));
			return false;
		}

		$signature = base64_decode($post_data['p_signature']);

		// Replicate exact approach from old paddle-woo-checkout plugin:
		// Remove p_signature, sort by key, serialize, verify.
		$fields = $post_data;
		unset($fields['p_signature']);
		ksort($fields);

		foreach ($fields as $k => $v) {
			if (!in_array(gettype($v), ['object', 'array'])) {
				$fields[$k] = "$v";
			}
		}

		$data = serialize($fields);

		$verification = openssl_verify($data, $signature, $public_key, OPENSSL_ALGO_SHA1);

		if ($verification !== 1) {
			error_log('PFWC Webhook: openssl_verify returned ' . var_export($verification, true) . '. Signature mismatch.');
		}

		return $verification === 1;
	}

	/**
	 * Fetch the vendor public key from Paddle Classic API.
	 *
	 * Mirrors the old paddle-woo-checkout plugin behavior: auto-retrieves
	 * the public key using vendor_id + auth_code so the user doesn't have
	 * to paste it manually. Caches it in a WP option for subsequent calls.
	 *
	 * @return string PEM-encoded public key, or empty string on failure.
	 */
	public static function fetch_classic_public_key() {
		// Check cache first
		$cached = get_option('pfwc_classic_public_key', '');
		if (!empty($cached)) {
			return $cached;
		}

		$vendor_id = self::get_vendor_id();
		$auth_code = self::get_vendor_auth_code();

		if (empty($vendor_id) || empty($auth_code)) {
			return '';
		}

		$response = self::classic_request('user/get_public_key');

		if (is_wp_error($response)) {
			error_log('PFWC: Failed to fetch Classic public key: ' . $response->get_error_message());
			return '';
		}

		$public_key = isset($response['public_key']) ? trim($response['public_key']) : '';

		if (!empty($public_key)) {
			update_option('pfwc_classic_public_key', $public_key);
		}

		return $public_key;
	}

	/**
	 * Get list of currencies supported by Paddle Billing.
	 *
	 * @return array ISO 4217 currency codes.
	 */
	public static function get_supported_currencies() {
		return [
			'USD', 'EUR', 'GBP', 'JPY', 'AUD', 'CAD', 'CHF', 'HKD', 'SGD', 'SEK',
			'ARS', 'BRL', 'CNY', 'COP', 'CZK', 'DKK', 'HUF', 'ILS', 'INR', 'KRW',
			'MXN', 'NOK', 'NZD', 'PLN', 'THB', 'TRY', 'TWD', 'UAH', 'ZAR',
			'RON', 'PEN', 'MYR', 'PHP', 'PKR', 'QAR', 'SAR', 'VND', 'EGP', 'KES',
			'NGN', 'BGN',
		];
	}

	/**
	 * Test the current API connection by making a lightweight request.
	 *
	 * Classic: calls user/get_public_key to verify vendor_id + auth_code.
	 * Billing: calls /event-types (lightweight GET) to verify the API key.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function test_connection() {
		if (self::is_classic()) {
			$result = self::classic_request('user/get_public_key');
		} else {
			$result = self::request('event-types', [], 'GET');
		}

		if (is_wp_error($result)) {
			return $result;
		}

		return true;
	}
}
