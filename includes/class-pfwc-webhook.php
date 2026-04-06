<?php
/**
 * Paddle Webhook Handler — supports both Classic and Billing webhooks.
 *
 * Classic webhooks: form-encoded POST with p_signature, alert_name events.
 * Billing webhooks: JSON POST with Paddle-Signature header, event_type events.
 *
 * Webhook URL: {site_url}/wc-api/pfwc_webhook
 */

defined('ABSPATH') || exit;

class PFWC_Webhook {

	public function __construct() {
		add_action('woocommerce_api_pfwc_webhook', [$this, 'handle_webhook']);
	}

	/**
	 * Auto-detect webhook type and dispatch accordingly.
	 */
	public function handle_webhook() {
		// Classic webhooks send p_signature as POST field
		if (!empty($_POST['alert_name']) && !empty($_POST['p_signature'])) {
			$this->handle_classic_webhook();
			return;
		}

		// Billing webhooks send JSON body with Paddle-Signature header
		$this->handle_billing_webhook();
	}

	// =========================================================================
	// BILLING WEBHOOK HANDLING
	// =========================================================================

	private function handle_billing_webhook() {
		$raw_body  = file_get_contents('php://input');
		$signature = isset($_SERVER['HTTP_PADDLE_SIGNATURE'])
			? sanitize_text_field(wp_unslash($_SERVER['HTTP_PADDLE_SIGNATURE']))
			: '';

		if (!PFWC_API::verify_webhook_signature($raw_body, $signature)) {
			error_log('PFWC Webhook: Billing signature verification failed.');
			status_header(403);
			exit('Invalid signature');
		}

		$payload = json_decode($raw_body, true);

		if (!is_array($payload) || !isset($payload['event_type'])) {
			status_header(400);
			exit('Invalid payload');
		}

		$event_type = sanitize_text_field($payload['event_type']);
		$data       = isset($payload['data']) ? $payload['data'] : [];

		error_log('PFWC Webhook: Received Billing event ' . $event_type);

		switch ($event_type) {
			case 'transaction.paid':
			case 'transaction.completed':
				$this->handle_billing_completed($data);
				break;

			case 'transaction.payment_failed':
				$this->handle_billing_failed($data);
				break;

			case 'transaction.updated':
				$this->handle_billing_updated($data);
				break;
		}

		status_header(200);
		exit('OK');
	}

	private function handle_billing_completed($data) {
		$order = $this->get_order_from_billing_data($data);
		if (!$order) {
			error_log('PFWC Webhook: Could not find WC order for completed Billing transaction.');
			return;
		}

		if ($order->is_paid()) {
			return;
		}

		$txn_id   = sanitize_text_field($data['id'] ?? '');
		$currency = sanitize_text_field($data['currency_code'] ?? '');

		$order->update_meta_data('_pfwc_paddle_currency', $currency);

		if (isset($data['details']['totals']['total'])) {
			$paddle_total = PFWC_API::from_paddle_amount($data['details']['totals']['total'], $currency);
			$order->update_meta_data('_pfwc_paddle_total', $paddle_total);
		}

		if (isset($data['details']['totals']['tax'])) {
			$paddle_tax = PFWC_API::from_paddle_amount($data['details']['totals']['tax'], $currency);
			$order->update_meta_data('_pfwc_paddle_tax', $paddle_tax);
		}

		if (isset($data['payments'][0]['method_details']['type'])) {
			$payment_type = sanitize_text_field($data['payments'][0]['method_details']['type']);
			$order->update_meta_data('_pfwc_payment_method_type', $payment_type);
		}

		if (!empty($data['customer_id'])) {
			$order->update_meta_data('_pfwc_paddle_customer_id', sanitize_text_field($data['customer_id']));
		}

		$order->payment_complete($txn_id);

		$note = sprintf(
			__('Paddle payment completed. Transaction: %1$s | Currency: %2$s', 'paddle-for-woocommerce'),
			$txn_id,
			$currency
		);

		if (!empty($data['payments'][0]['method_details']['type'])) {
			$note .= ' | ' . sprintf(
				__('Method: %s', 'paddle-for-woocommerce'),
				sanitize_text_field($data['payments'][0]['method_details']['type'])
			);
		}

		$order->add_order_note($note);
		$order->save();
	}

	private function handle_billing_failed($data) {
		$order = $this->get_order_from_billing_data($data);
		if (!$order || $order->is_paid()) {
			return;
		}

		$txn_id = sanitize_text_field($data['id'] ?? '');

		$order->update_status(
			'failed',
			sprintf(__('Paddle payment failed. Transaction: %s', 'paddle-for-woocommerce'), $txn_id)
		);
	}

	private function handle_billing_updated($data) {
		$order = $this->get_order_from_billing_data($data);
		if (!$order) {
			return;
		}

		$status = sanitize_text_field($data['status'] ?? 'unknown');
		$txn_id = sanitize_text_field($data['id'] ?? '');

		$order->add_order_note(
			sprintf(__('Paddle transaction updated: %1$s — Status: %2$s', 'paddle-for-woocommerce'), $txn_id, $status)
		);
		$order->save();
	}

	/**
	 * Find WC order from Billing webhook data.
	 */
	private function get_order_from_billing_data($data) {
		if (isset($data['custom_data']['wc_order_id'])) {
			$order_id = absint($data['custom_data']['wc_order_id']);
			$order    = wc_get_order($order_id);

			if ($order && is_a($order, 'WC_Order')) {
				if (isset($data['custom_data']['wc_order_key'])) {
					if ($order->get_order_key() !== $data['custom_data']['wc_order_key']) {
						error_log('PFWC Webhook: Order key mismatch for order #' . $order_id);
						return null;
					}
				}
				return $order;
			}
		}

		$txn_id = sanitize_text_field($data['id'] ?? '');
		if (!empty($txn_id)) {
			$orders = wc_get_orders([
				'meta_key'   => '_pfwc_paddle_transaction_id',
				'meta_value' => $txn_id,
				'limit'      => 1,
			]);

			if (!empty($orders)) {
				return $orders[0];
			}
		}

		return null;
	}

	// =========================================================================
	// CLASSIC WEBHOOK HANDLING
	// =========================================================================

	private function handle_classic_webhook() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Paddle webhook, no nonce
		$post_data = wp_unslash($_POST);
		// phpcs:enable

		if (!PFWC_API::verify_classic_webhook_signature($post_data)) {
			error_log('PFWC Webhook: Classic p_signature verification failed.');
			status_header(403);
			exit('Invalid signature');
		}

		$alert_name = sanitize_text_field($post_data['alert_name']);

		error_log('PFWC Webhook: Received Classic event ' . $alert_name);

		switch ($alert_name) {
			case 'payment_succeeded':
				$this->handle_classic_payment_succeeded($post_data);
				break;

			case 'payment_refunded':
				$this->handle_classic_payment_refunded($post_data);
				break;
		}

		status_header(200);
		exit('OK');
	}

	/**
	 * Handle Classic payment_succeeded alert.
	 */
	private function handle_classic_payment_succeeded($data) {
		$order = $this->get_order_from_classic_data($data);
		if (!$order) {
			error_log('PFWC Webhook: Could not find WC order for Classic payment_succeeded.');
			return;
		}

		if ($order->is_paid()) {
			return;
		}

		$checkout_id = sanitize_text_field($data['checkout_id'] ?? '');
		$order_id_paddle = sanitize_text_field($data['order_id'] ?? '');
		$currency    = sanitize_text_field($data['currency'] ?? '');
		$sale_gross  = sanitize_text_field($data['sale_gross'] ?? '');
		$fee         = sanitize_text_field($data['fee'] ?? '');
		$payment_method = sanitize_text_field($data['payment_method'] ?? '');

		$order->update_meta_data('_pfwc_paddle_checkout_id', $checkout_id);
		$order->update_meta_data('_pfwc_paddle_order_id', $order_id_paddle);
		$order->update_meta_data('_pfwc_paddle_currency', $currency);
		$order->update_meta_data('_pfwc_paddle_fee', $fee);
		$order->update_meta_data('_pfwc_payment_method_type', $payment_method);

		$order->payment_complete($checkout_id);

		$order->add_order_note(
			sprintf(
				__('Paddle Classic payment completed. Checkout: %1$s | Gross: %2$s %3$s | Fee: %4$s | Method: %5$s', 'paddle-for-woocommerce'),
				$checkout_id,
				$sale_gross,
				$currency,
				$fee,
				$payment_method
			)
		);
		$order->save();
	}

	/**
	 * Handle Classic payment_refunded alert.
	 */
	private function handle_classic_payment_refunded($data) {
		$order = $this->get_order_from_classic_data($data);
		if (!$order) {
			return;
		}

		$refund_amount = sanitize_text_field($data['gross_refund'] ?? '');
		$currency      = sanitize_text_field($data['currency'] ?? '');

		$order->update_status(
			'refunded',
			sprintf(
				__('Paddle Classic refund: %1$s %2$s', 'paddle-for-woocommerce'),
				$refund_amount,
				$currency
			)
		);
	}

	/**
	 * Find WC order from Classic webhook data.
	 * Classic webhooks pass the order info in the 'passthrough' field.
	 * Falls back to GET params in the webhook URL (like the old paddle-woo-checkout plugin).
	 */
	private function get_order_from_classic_data($data) {
		// 1. Try passthrough JSON field
		if (!empty($data['passthrough'])) {
			$passthrough = json_decode(wp_unslash($data['passthrough']), true);

			if (isset($passthrough['wc_order_id'])) {
				$order_id = absint($passthrough['wc_order_id']);
				$order    = wc_get_order($order_id);

				if ($order && is_a($order, 'WC_Order')) {
					if (isset($passthrough['wc_order_key'])) {
						if ($order->get_order_key() !== $passthrough['wc_order_key']) {
							error_log('PFWC Webhook: Classic order key mismatch for order #' . $order_id);
							return null;
						}
					}
					return $order;
				}
			}
		}

		// 2. Fallback: order_id in webhook URL query params (like old paddle-woo-checkout plugin)
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if (!empty($_GET['wc_order_id'])) {
			$order_id  = absint($_GET['wc_order_id']);
			$order_key = isset($_GET['wc_order_key']) ? sanitize_text_field(wp_unslash($_GET['wc_order_key'])) : '';
			$order     = wc_get_order($order_id);

			if ($order && is_a($order, 'WC_Order')) {
				if (!empty($order_key) && $order->get_order_key() !== $order_key) {
					error_log('PFWC Webhook: Classic GET order key mismatch for order #' . $order_id);
					return null;
				}
				return $order;
			}
		}
		// phpcs:enable

		// Fallback: search by checkout_id
		$checkout_id = sanitize_text_field($data['checkout_id'] ?? '');
		if (!empty($checkout_id)) {
			$orders = wc_get_orders([
				'meta_key'   => '_pfwc_paddle_checkout_id',
				'meta_value' => $checkout_id,
				'limit'      => 1,
			]);

			if (!empty($orders)) {
				return $orders[0];
			}
		}

		return null;
	}
}
