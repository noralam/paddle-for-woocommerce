<?php
/**
 * Paddle Payment Gateway for WooCommerce.
 *
 * Extends WC_Payment_Gateway to integrate Paddle Billing overlay checkout.
 * Supports 30+ currencies, Apple Pay, Google Pay, PayPal, and local payment methods.
 */

defined('ABSPATH') || exit;

class PFWC_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'paddle';
		$this->method_title       = __('Paddle', 'paddle-for-woocommerce');
		$this->method_description = __('Accept payments via Paddle — credit cards, PayPal, Apple Pay, Google Pay, and 15+ local payment methods with automatic multi-currency support.', 'paddle-for-woocommerce');
		$this->has_fields         = false;
		$this->supports           = ['products'];
		// Load form fields and settings
		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option('title');
		$this->description = $this->get_option('description');
		$this->enabled     = $this->get_option('enabled');

		// Set gateway icon from uploaded image
		$icon_url = $this->get_option('payment_icons', '');
		$this->icon = apply_filters('pfwc_gateway_icon', $icon_url);

		// Save admin settings
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
		// Clear cached Classic public key when settings are saved (vendor may have changed)
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, function () {
			delete_option('pfwc_classic_public_key');
		});

		if ($this->enabled === 'yes') {
			add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_scripts']);

			// NOTE: wc_ajax hooks for pfwc_pay_order and pfwc_confirm_payment
			// are registered at plugin level in pfwc_init() (paddle-for-woocommerce.php).
			// This is critical because WC does not auto-load payment gateways during
			// wc-ajax requests, so hooks in the constructor would never fire.

			// Verify payment when thank-you page loads (fallback if webhook is delayed)
			add_action('woocommerce_thankyou_paddle', [$this, 'verify_payment_on_thankyou']);
		}

		// Admin: toggle Classic vs Billing fields
		add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
	}

	/**
	 * Define admin settings fields.
	 */
	public function init_form_fields() {
		$webhook_url = get_bloginfo('url') . '/wc-api/pfwc_webhook';

		$this->form_fields = [
			'enabled' => [
				'title'   => __('Enable/Disable', 'paddle-for-woocommerce'),
				'type'    => 'checkbox',
				'label'   => __('Enable Paddle Payment Gateway', 'paddle-for-woocommerce'),
				'default' => 'no',
			],
			'title' => [
				'title'       => __('Title', 'paddle-for-woocommerce'),
				'type'        => 'text',
				'description' => __('Payment method title displayed at checkout.', 'paddle-for-woocommerce'),
				'default'     => __('Pay with Card, PayPal & More', 'paddle-for-woocommerce'),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __('Description', 'paddle-for-woocommerce'),
				'type'        => 'textarea',
				'description' => __('Payment method description displayed at checkout.', 'paddle-for-woocommerce'),
				'default'     => __('Pay securely using your credit card, PayPal, Apple Pay, Google Pay, or local payment methods.', 'paddle-for-woocommerce'),
				'desc_tip'    => true,
			],
			'payment_icons' => [
				'title'       => __('Payment Icons', 'paddle-for-woocommerce'),
				'type'        => 'pfwc_image_upload',
				'description' => __('Upload an image showing accepted payment methods (e.g. Visa, Mastercard, PayPal badges). Displayed next to the payment method title at checkout.', 'paddle-for-woocommerce'),
				'default'     => '',
				'desc_tip'    => true,
			],

			// Paddle Credentials
			'credentials_heading' => [
				'title' => __('Paddle API Credentials', 'paddle-for-woocommerce'),
				'type'  => 'title',
				'description' => __('Get your credentials from <a href="https://vendors.paddle.com/authentication" target="_blank">Paddle &gt; Developer Tools &gt; Authentication</a>.', 'paddle-for-woocommerce'),
			],
			'api_mode' => [
				'title'       => __('API Mode', 'paddle-for-woocommerce'),
				'type'        => 'select',
				'description' => __('<strong>Classic</strong>: Uses Vendor ID + API Key (vendors.paddle.com). <strong>Billing</strong>: Uses the newer API Key + Client Token (api.paddle.com). Choose Classic if you have a Paddle account with Vendor ID.', 'paddle-for-woocommerce'),
				'default'     => 'classic',
				'options'     => [
					'classic' => __('Paddle Classic (Vendor ID + API Key)', 'paddle-for-woocommerce'),
					'billing' => __('Paddle Billing (API Key + Client Token)', 'paddle-for-woocommerce'),
				],
			],
			'environment' => [
				'title'       => __('Environment', 'paddle-for-woocommerce'),
				'type'        => 'select',
				'description' => __('<strong>Sandbox</strong>: Use credentials from <code>sandbox-vendors.paddle.com</code> (Classic) or sandbox keys starting with <code>test_</code> (Billing). <strong>Live</strong>: Use credentials from <code>vendors.paddle.com</code> (Classic) or live keys starting with <code>pdl_</code> / <code>live_</code> (Billing).', 'paddle-for-woocommerce'),
				'default'     => 'sandbox',
				'options'     => [
					'sandbox' => __('Sandbox (Testing)', 'paddle-for-woocommerce'),
					'live'    => __('Live (Production)', 'paddle-for-woocommerce'),
				],
			],

			// Classic mode fields
			'vendor_id' => [
				'title'       => __('Vendor ID', 'paddle-for-woocommerce'),
				'type'        => 'text',
				'description' => __('Your Paddle Vendor ID. Find it at Paddle &gt; Developer Tools &gt; Authentication.', 'paddle-for-woocommerce'),
				'default'     => '',
				'class'       => 'pfwc-classic-field',
				'desc_tip'    => true,
			],
			'vendor_auth_code' => [
				'title'       => __('API Key (Auth Code)', 'paddle-for-woocommerce'),
				'type'        => 'password',
				'description' => __('Your Paddle API Auth Code. Click "Reveal Auth Code" next to your integration in Paddle &gt; Developer Tools &gt; Authentication.', 'paddle-for-woocommerce'),
				'default'     => '',
				'class'       => 'pfwc-classic-field',
			],

			// Billing mode fields
			'api_key' => [
				'title'       => __('API Key (Server-side)', 'paddle-for-woocommerce'),
				'type'        => 'password',
				'description' => __('Your Paddle Billing API key for server-side requests. Starts with <code>pdl_</code> (live) or <code>test_</code> (sandbox).', 'paddle-for-woocommerce'),
				'default'     => '',
				'class'       => 'pfwc-billing-field',
			],
			'client_token' => [
				'title'       => __('Client-Side Token', 'paddle-for-woocommerce'),
				'type'        => 'text',
				'description' => __('Client-side token for Paddle.js. Starts with <code>live_</code> or <code>test_</code>. Create at Paddle &gt; Developer Tools &gt; Authentication &gt; Client-side Tokens.', 'paddle-for-woocommerce'),
				'default'     => '',
				'class'       => 'pfwc-billing-field',
			],

			// Webhook / Verification
			'webhook_heading' => [
				'title'       => __('Webhook / Verification', 'paddle-for-woocommerce'),
				'type'        => 'title',
				'description' => sprintf(
					__('Paddle sends payment notifications to your site. Set the webhook URL in Paddle to: <code>%s</code>', 'paddle-for-woocommerce'),
					esc_html($webhook_url)
				),
			],

			// Classic: Public Key (textarea for the RSA key)
			'classic_public_key' => [
				'title'       => __('Public Key', 'paddle-for-woocommerce'),
				'type'        => 'textarea',
				'description' => __('Copy your full Public Key from Paddle &gt; Developer Tools &gt; Public Key. Paste the entire key including <code>-----BEGIN PUBLIC KEY-----</code> and <code>-----END PUBLIC KEY-----</code>. This is used to verify webhook signatures.', 'paddle-for-woocommerce'),
				'default'     => '',
				'class'       => 'pfwc-classic-field',
				'css'         => 'min-height:120px; font-family:monospace; font-size:12px;',
			],

			// Billing: Webhook Secret (HMAC key)
			'webhook_secret' => [
				'title'       => __('Webhook Secret Key', 'paddle-for-woocommerce'),
				'type'        => 'password',
				'description' => __('Your Billing webhook secret (starts with <code>pdl_ntfset_</code>). Create at Paddle &gt; Developer Tools &gt; Notifications &gt; Edit destination &gt; Secret key.', 'paddle-for-woocommerce'),
				'default'     => '',
				'class'       => 'pfwc-billing-field',
			],

			// Checkout Options
			'checkout_heading' => [
				'title' => __('Checkout Options', 'paddle-for-woocommerce'),
				'type'  => 'title',
			],
			'send_product_names' => [
				'title'       => __('Send Product Names', 'paddle-for-woocommerce'),
				'type'        => 'checkbox',
				'label'       => __('Show product names on the Paddle checkout overlay', 'paddle-for-woocommerce'),
				'default'     => 'yes',
				'desc_tip'    => true,
			],
			'tax_mode' => [
				'title'       => __('Tax Handling', 'paddle-for-woocommerce'),
				'type'        => 'select',
				'description' => __('<strong>Paddle handles tax</strong>: Sends WooCommerce totals as-is; Paddle calculates and adds tax at checkout. <strong>WC prices include tax</strong>: WooCommerce tax is stripped before sending to Paddle.', 'paddle-for-woocommerce'),
				'default'     => 'paddle',
				'options'     => [
					'paddle'   => __('Paddle handles tax (recommended)', 'paddle-for-woocommerce'),
					'internal' => __('WooCommerce prices include tax', 'paddle-for-woocommerce'),
				],
			],
			'checkout_variant' => [
				'title'       => __('Checkout Style', 'paddle-for-woocommerce'),
				'type'        => 'select',
				'description' => __('Single-page shows all fields at once. Multi-page guides customers step-by-step. (Billing mode only)', 'paddle-for-woocommerce'),
				'default'     => 'multi-page',
				'options'     => [
					'multi-page' => __('Multi-page (recommended)', 'paddle-for-woocommerce'),
					'one-page'   => __('One-page', 'paddle-for-woocommerce'),
				],
				'class'    => 'pfwc-billing-field',
				'desc_tip' => true,
			],
			'checkout_theme' => [
				'title'       => __('Checkout Theme', 'paddle-for-woocommerce'),
				'type'        => 'select',
				'description' => __('Color theme for the Paddle checkout overlay. (Billing mode only)', 'paddle-for-woocommerce'),
				'default'     => 'light',
				'options'     => [
					'light' => __('Light', 'paddle-for-woocommerce'),
					'dark'  => __('Dark', 'paddle-for-woocommerce'),
				],
				'class'    => 'pfwc-billing-field',
				'desc_tip' => true,
			],
			'locale' => [
				'title'       => __('Checkout Language', 'paddle-for-woocommerce'),
				'type'        => 'select',
				'description' => __("Language for the Paddle checkout. Auto uses the customer's browser language.", 'paddle-for-woocommerce'),
				'default'     => 'auto',
				'options'     => self::get_locale_options(),
				'desc_tip'    => true,
			],
		];
	}

	/**
	 * Enqueue admin scripts for conditional field display.
	 */
	public function admin_enqueue_scripts($hook) {
		if ($hook !== 'woocommerce_page_wc-settings') {
			return;
		}
		if (!isset($_GET['section']) || sanitize_text_field(wp_unslash($_GET['section'])) !== $this->id) {
			return;
		}
		wp_enqueue_media();
		wp_add_inline_script('jquery-migrate', $this->get_admin_toggle_js());
		wp_add_inline_script('jquery-migrate', $this->get_admin_media_uploader_js());
	}

	/**
	 * Generate HTML for the custom image upload field type.
	 */
	public function generate_pfwc_image_upload_html($key, $data) {
		$field_key = $this->get_field_key($key);
		$defaults  = [
			'title'       => '',
			'description' => '',
			'desc_tip'    => false,
			'default'     => '',
		];
		$data = wp_parse_args($data, $defaults);
		$value = $this->get_option($key);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?><?php echo $this->get_tooltip_html($data); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<input type="text" name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" value="<?php echo esc_attr($value); ?>" style="display:none;" />
					<div id="pfwc-icon-preview" style="margin-bottom:10px;">
						<?php if (!empty($value)) : ?>
							<img src="<?php echo esc_url($value); ?>" style="max-height:50px;" />
						<?php endif; ?>
					</div>
					<button type="button" class="button" id="pfwc-upload-icon"><?php esc_html_e('Upload Image', 'paddle-for-woocommerce'); ?></button>
					<button type="button" class="button" id="pfwc-remove-icon" style="<?php echo empty($value) ? 'display:none;' : ''; ?>"><?php esc_html_e('Remove', 'paddle-for-woocommerce'); ?></button>
					<?php echo $this->get_description_html($data); ?>
				</fieldset>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/**
	 * Validate/sanitize the image upload field value.
	 */
	public function validate_pfwc_image_upload_field($key, $value) {
		return esc_url_raw(wp_unslash((string) $value));
	}

	/**
	 * Inline JS to show/hide Classic vs Billing fields based on api_mode dropdown.
	 *
	 * WooCommerce puts the CSS class on the <input>/<select>/<textarea> element.
	 * We traverse up to the nearest <tr> to show/hide the entire row.
	 */
	private function get_admin_toggle_js() {
		return <<<'JS'
		jQuery(function($){
			function pfwcToggle(){
				var mode=$('#woocommerce_paddle_api_mode').val();
				var env=$('#woocommerce_paddle_environment').val();
				var isClassic=(mode==='classic');

				$('.pfwc-classic-field').each(function(){$(this).closest('tr').toggle(isClassic);});
				$('.pfwc-billing-field').each(function(){$(this).closest('tr').toggle(!isClassic);});

				var link=(env==='sandbox')?'https://sandbox-vendors.paddle.com/authentication':'https://vendors.paddle.com/authentication';
				var label=(env==='sandbox')?'Sandbox':'Live';
				var d=$('#woocommerce_paddle_credentials_heading').next('p');
				if(d.length){
					if(isClassic){
						d.html('Get your '+label+' credentials from <a href="'+link+'" target="_blank">Paddle &gt; Developer Tools &gt; Authentication</a>.');
					}else{
						var pfx=(env==='sandbox')?'<code>test_</code>':'<code>pdl_</code>';
						d.html('Get your '+label+' credentials from <a href="'+link+'" target="_blank">Paddle Dashboard</a>. API keys start with '+pfx+'.');
					}
				}
			}
			$('#woocommerce_paddle_api_mode,#woocommerce_paddle_environment').on('change',pfwcToggle);
			setTimeout(pfwcToggle,10);
		});
JS;
	}

	/**
	 * Inline JS for the payment icons media uploader.
	 */
	private function get_admin_media_uploader_js() {
		$field_key = $this->get_field_key('payment_icons');
		return "
		jQuery(function($){
			var pfwcFrame;
			$('#pfwc-upload-icon').on('click',function(e){
				e.preventDefault();
				if(pfwcFrame){pfwcFrame.open();return;}
				pfwcFrame=wp.media({
					title:'Select Payment Icons Image',
					button:{text:'Use This Image'},
					multiple:false,
					library:{type:'image'}
				});
				pfwcFrame.on('select',function(){
					var attachment=pfwcFrame.state().get('selection').first().toJSON();
					$('#" . esc_js($field_key) . "').val(attachment.url).trigger('change');
					$('#pfwc-icon-preview').html('<img src=\"'+attachment.url+'\" style=\"max-height:50px;\" />');
					$('#pfwc-remove-icon').show();
				});
				pfwcFrame.open();
			});
			$('#pfwc-remove-icon').on('click',function(e){
				e.preventDefault();
				$('#" . esc_js($field_key) . "').val('').trigger('change');
				$('#pfwc-icon-preview').html('');
				$(this).hide();
			});
		});
		";
	}

	/**
	 * Get the current API mode (classic or billing).
	 */
	public function get_api_mode() {
		return $this->get_option('api_mode', 'classic');
	}

	/**
	 * Check if using Classic mode.
	 */
	public function is_classic_mode() {
		return $this->get_api_mode() === 'classic';
	}

	/**
	 * Check if the gateway is available for use.
	 */
	public function is_available() {
		if (!parent::is_available()) {
			return false;
		}

		if (!$this->has_credentials()) {
			return false;
		}

		$supported = $this->is_classic_mode()
			? ['USD', 'GBP', 'EUR']
			: PFWC_API::get_supported_currencies();

		if (!in_array(get_woocommerce_currency(), $supported, true)) {
			return false;
		}

		return true;
	}

	/**
	 * Check if the required credentials are filled in for the current mode.
	 */
	private function has_credentials() {
		if ($this->is_classic_mode()) {
			return !empty($this->get_option('vendor_id')) && !empty($this->get_option('vendor_auth_code'));
		}
		return !empty($this->get_option('api_key')) && !empty($this->get_option('client_token'));
	}

	/**
	 * Migrate credentials from the old paddle-woo-checkout plugin if present.
	 *
	 * The old plugin stores paddle_vendor_id and paddle_api_key inside the
	 * same woocommerce_paddle_settings option. If our own fields are empty
	 * we copy those values across so the user doesn't have to re-enter them.
	 */
	private function maybe_migrate_old_settings() {
		if ($this->is_classic_mode()
			&& empty($this->get_option('vendor_id'))
			&& empty($this->get_option('vendor_auth_code'))
		) {
			$settings = get_option('woocommerce_paddle_settings', []);
			$old_vendor_id  = isset($settings['paddle_vendor_id']) ? $settings['paddle_vendor_id'] : '';
			$old_api_key    = isset($settings['paddle_api_key']) ? $settings['paddle_api_key'] : '';

			if (!empty($old_vendor_id) && !empty($old_api_key)) {
				$this->update_option('vendor_id', $old_vendor_id);
				$this->update_option('vendor_auth_code', $old_api_key);
			}
		}
	}

	/**
	 * Display admin options with connection status and helpful notices.
	 */
	public function admin_options() {
		// Attempt migration from old paddle-woo-checkout plugin if our fields are empty
		$this->maybe_migrate_old_settings();

		$currency  = get_woocommerce_currency();
		$supported = $this->is_classic_mode() ? ['USD', 'GBP', 'EUR'] : PFWC_API::get_supported_currencies();

		// Connection status indicator
		$has_credentials = $this->has_credentials();
		if ($has_credentials) {
			$connection = PFWC_API::test_connection();
			if ($connection === true) {
				echo '<div class="notice notice-success" style="border-left-color:#46b450;"><p>';
				echo '<strong style="color:#46b450;">&#10004; ' . esc_html__('Connected', 'paddle-for-woocommerce') . '</strong> &mdash; ';
				printf(
					esc_html__('Your Paddle %s account is connected and ready to accept payments.', 'paddle-for-woocommerce'),
					esc_html(ucfirst($this->get_api_mode()))
				);
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>';
				echo '<strong style="color:#dc3232;">&#10006; ' . esc_html__('Not Connected', 'paddle-for-woocommerce') . '</strong> &mdash; ';
				printf(
					esc_html__('Could not connect to Paddle %1$s API: %2$s. Please check your credentials and environment setting.', 'paddle-for-woocommerce'),
					esc_html(ucfirst($this->get_api_mode())),
					esc_html($connection->get_error_message())
				);
				echo '</p></div>';
			}
		}

		if (!in_array($currency, $supported, true)) {
			echo '<div class="notice notice-error"><p>';
			printf(
				esc_html__('Paddle does not support your store currency (%1$s) in %2$s mode. Supported: %3$s', 'paddle-for-woocommerce'),
				esc_html($currency),
				esc_html(ucfirst($this->get_api_mode())),
				esc_html(implode(', ', $supported))
			);
			echo '</p></div>';
		}

		if ($this->get_option('enabled') === 'yes') {
			if (!$has_credentials) {
				if ($this->is_classic_mode()) {
					echo '<div class="notice notice-warning"><p>';
					esc_html_e('Please enter your Vendor ID and API Key (Auth Code) to activate Paddle payments.', 'paddle-for-woocommerce');
					echo '</p></div>';
				} else {
					echo '<div class="notice notice-warning"><p>';
					esc_html_e('Please enter both your API Key and Client-Side Token to activate Paddle payments.', 'paddle-for-woocommerce');
					echo '</p></div>';
				}
			}
				$missing_webhook = $this->is_classic_mode()
				? empty($this->get_option('classic_public_key'))
				: empty($this->get_option('webhook_secret'));
			if ($missing_webhook) {
				$verify_field = $this->is_classic_mode() ? __('Public Key', 'paddle-for-woocommerce') : __('Webhook Secret Key', 'paddle-for-woocommerce');
				echo '<div class="notice notice-warning"><p>';
				printf(
					/* translators: 1: field name, 2: webhook URL */
					__('Webhook verification not configured. Please enter your <strong>%1$s</strong> and set the webhook URL in Paddle to: <code>%2$s</code>', 'paddle-for-woocommerce'),
					esc_html($verify_field),
					esc_html(get_bloginfo('url') . '/wc-api/pfwc_webhook')
				);
				echo '</p></div>';
			}
		}

		parent::admin_options();
	}

	/**
	 * Enqueue Paddle.js and checkout scripts on checkout pages.
	 */
	public function enqueue_checkout_scripts() {
		if (!is_checkout() && !is_wc_endpoint_url('order-pay')) {
			return;
		}

		$is_classic = $this->is_classic_mode();

		// Load the right version of Paddle.js
		if ($is_classic) {
			wp_enqueue_script('paddle-js', 'https://cdn.paddle.com/paddle/paddle.js', [], null, true);
		} else {
			wp_enqueue_script('paddle-js', 'https://cdn.paddle.com/paddle/v2/paddle.js', [], null, true);
		}

		// Our checkout handler
		wp_enqueue_script(
			'pfwc-checkout',
			PFWC_PLUGIN_URL . 'assets/js/pfwc-checkout.js',
			['jquery', 'paddle-js'],
			PFWC_VERSION,
			true
		);

		$params = [
			'api_mode'     => $this->get_api_mode(),
			'environment'  => $this->get_option('environment', 'sandbox'),
			'locale'       => $this->get_option('locale', 'auto'),
			'gateway_id'   => $this->id,
			'confirm_url'  => $this->get_ajax_url('pfwc_confirm_payment'),
		];

		// ajax_url is only needed for the order-pay page (custom AJAX endpoint)
		if (is_wc_endpoint_url('order-pay')) {
			$params['ajax_url'] = $this->get_ajax_url('pfwc_pay_order');
		}

		if ($is_classic) {
			$params['vendor_id'] = absint($this->get_option('vendor_id'));
		} else {
			$params['client_token']     = sanitize_text_field($this->get_option('client_token'));
			$params['checkout_variant'] = $this->get_option('checkout_variant', 'multi-page');
			$params['checkout_theme']   = $this->get_option('checkout_theme', 'light');
		}

		wp_localize_script('pfwc-checkout', 'pfwc_params', $params);
	}

	/**
	 * AJAX: Create Paddle transaction for an existing pending order (order-pay page).
	 */
	public function ajax_pay_order() {
		try {
			if (!isset($_POST['woocommerce-pay-nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woocommerce-pay-nonce'])), 'woocommerce-pay')) {
				throw new Exception(__('Security check failed. Please refresh and try again.', 'paddle-for-woocommerce'));
			}

			$order_id = 0;

			if (!empty($_POST['order_id'])) {
				$order_id = absint($_POST['order_id']);
			} elseif (WC()->session) {
				$order_id = absint(WC()->session->get('order_awaiting_payment', 0));
			}

			if (!$order_id) {
				throw new Exception(__('Invalid order.', 'paddle-for-woocommerce'));
			}

			$order = wc_get_order($order_id);
			if (!$order || !is_a($order, 'WC_Order')) {
				throw new Exception(__('Order not found.', 'paddle-for-woocommerce'));
			}

			$order_key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
			if ($order_key && $order->get_order_key() !== $order_key) {
				throw new Exception(__('Invalid order key.', 'paddle-for-woocommerce'));
			}

			if ($this->is_classic_mode()) {
				$pay_link = PFWC_API::generate_pay_link($order);

				if (is_wp_error($pay_link)) {
					throw new Exception($pay_link->get_error_message());
				}

				$order->update_meta_data('_pfwc_paddle_checkout_url', esc_url_raw($pay_link));
				$order->save();

				wp_send_json([
					'result'       => 'success',
					'checkout_url' => $pay_link,
					'order_id'     => $order->get_id(),
					'order_key'    => $order->get_order_key(),
					'email'        => $order->get_billing_email(),
					'success_url'  => $this->get_return_url($order),
				]);
			} else {
				$transaction = PFWC_API::create_transaction($order);

				if (is_wp_error($transaction)) {
					throw new Exception($transaction->get_error_message());
				}

				$txn_id = sanitize_text_field($transaction['id']);

				$order->update_meta_data('_pfwc_paddle_transaction_id', $txn_id);
				$order->add_order_note(
					sprintf(__('Paddle transaction created: %s', 'paddle-for-woocommerce'), $txn_id)
				);
				$order->save();

				wp_send_json([
					'result'         => 'success',
					'transaction_id' => $txn_id,
					'order_id'       => $order->get_id(),
					'order_key'      => $order->get_order_key(),
					'email'          => $order->get_billing_email(),
					'country_code'   => $order->get_billing_country(),
					'postal_code'    => $order->get_billing_postcode(),
					'success_url'    => $this->get_return_url($order),
				]);
			}

		} catch (Exception $e) {
			wp_send_json([
				'result'   => 'failure',
				'messages' => $e->getMessage(),
			]);
		}
	}

	/**
	 * AJAX: Confirm payment after Paddle checkout completes on the client side.
	 *
	 * Called by the frontend JS when Paddle fires checkout.completed.
	 * Verifies the transaction status with Paddle API and completes the order.
	 * This ensures the order is marked as paid even if the webhook is delayed.
	 */
	public function ajax_confirm_payment() {
		$order_id  = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$order_key = isset($_POST['order_key']) ? sanitize_text_field(wp_unslash($_POST['order_key'])) : '';

		$order = wc_get_order($order_id);
		if (!$order || !is_a($order, 'WC_Order') || $order->get_order_key() !== $order_key) {
			wp_send_json_error(['message' => 'Invalid order.']);
			return;
		}

		if ($order->is_paid()) {
			wp_send_json_success(['already_paid' => true]);
			return;
		}

		// Classic mode: no server-side transaction API to verify.
		// The successCallback in Paddle.js only fires after real payment.
		// Complete the order as a fallback in case the webhook is delayed or fails.
		if ($this->is_classic_mode()) {
			error_log('PFWC Confirm: Classic confirm called for order #' . $order_id);
			$checkout_id = $order->get_meta('_pfwc_paddle_checkout_id');
			$order->update_meta_data('_pfwc_classic_confirmed', 'yes');
			$order->payment_complete($checkout_id ?: '');
			$order->add_order_note(__('Paddle Classic payment confirmed via client-side callback.', 'paddle-for-woocommerce'));
			$order->save();
			error_log('PFWC Confirm: Order #' . $order_id . ' marked as paid.');
			wp_send_json_success(['completed' => true]);
			return;
		}

		$txn_id = $order->get_meta('_pfwc_paddle_transaction_id');
		if (empty($txn_id)) {
			wp_send_json_error(['message' => 'No transaction ID found.']);
			return;
		}

		$transaction = PFWC_API::get_transaction($txn_id);
		if (is_wp_error($transaction)) {
			error_log('PFWC Confirm: API error verifying transaction ' . $txn_id . ': ' . $transaction->get_error_message());
			wp_send_json_error(['message' => 'Could not verify transaction.']);
			return;
		}

		$status = isset($transaction['status']) ? sanitize_text_field($transaction['status']) : '';

		if (in_array($status, ['completed', 'paid'], true)) {
			$this->complete_order_from_transaction($order, $transaction, $txn_id);
			wp_send_json_success(['completed' => true]);
		} else {
			wp_send_json_success(['status' => $status, 'pending' => true]);
		}
	}

	/**
	 * Verify payment when the thank-you page loads.
	 *
	 * Acts as a final fallback: if both the webhook and the JS confirmation
	 * failed to complete the order, this hook checks the Paddle API directly.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function verify_payment_on_thankyou($order_id) {
		$order = wc_get_order($order_id);
		if (!$order || $order->is_paid()) {
			return;
		}

		if ($order->get_payment_method() !== $this->id) {
			return;
		}

		// Classic mode: check if the AJAX confirm already recorded success.
		// The _pfwc_classic_confirmed meta is set by ajax_confirm_payment().
		// This handles the case where payment_complete() failed in the AJAX
		// handler (e.g. due to a transient error) but the flag was set.
		if ($this->is_classic_mode()) {
			if ($order->get_meta('_pfwc_classic_confirmed') === 'yes') {
				$checkout_id = $order->get_meta('_pfwc_paddle_checkout_id');
				$order->payment_complete($checkout_id ?: '');
				$order->add_order_note(__('Paddle Classic payment completed on thank-you page (was confirmed by client callback).', 'paddle-for-woocommerce'));
				$order->save();
			}
			return;
		}

		$txn_id = $order->get_meta('_pfwc_paddle_transaction_id');
		if (empty($txn_id)) {
			return;
		}

		$transaction = PFWC_API::get_transaction($txn_id);
		if (is_wp_error($transaction)) {
			error_log('PFWC Thankyou: API error verifying transaction ' . $txn_id . ': ' . $transaction->get_error_message());
			return;
		}

		$status = isset($transaction['status']) ? sanitize_text_field($transaction['status']) : '';

		if (in_array($status, ['completed', 'paid'], true)) {
			$this->complete_order_from_transaction($order, $transaction, $txn_id);
		}
	}

	/**
	 * Complete a WC order using Paddle Billing transaction data.
	 *
	 * Shared by the webhook handler, AJAX confirmation, and thank-you page verification.
	 *
	 * @param WC_Order $order       WooCommerce order.
	 * @param array    $transaction Paddle transaction data from the API.
	 * @param string   $txn_id      Paddle transaction ID.
	 */
	private function complete_order_from_transaction($order, $transaction, $txn_id) {
		if ($order->is_paid()) {
			return;
		}

		$currency = sanitize_text_field($transaction['currency_code'] ?? '');
		$order->update_meta_data('_pfwc_paddle_currency', $currency);

		if (isset($transaction['details']['totals']['total'])) {
			$paddle_total = PFWC_API::from_paddle_amount($transaction['details']['totals']['total'], $currency);
			$order->update_meta_data('_pfwc_paddle_total', $paddle_total);
		}

		if (isset($transaction['details']['totals']['tax'])) {
			$paddle_tax = PFWC_API::from_paddle_amount($transaction['details']['totals']['tax'], $currency);
			$order->update_meta_data('_pfwc_paddle_tax', $paddle_tax);
		}

		if (isset($transaction['payments'][0]['method_details']['type'])) {
			$payment_type = sanitize_text_field($transaction['payments'][0]['method_details']['type']);
			$order->update_meta_data('_pfwc_payment_method_type', $payment_type);
		}

		if (!empty($transaction['customer_id'])) {
			$order->update_meta_data('_pfwc_paddle_customer_id', sanitize_text_field($transaction['customer_id']));
		}

		$order->payment_complete($txn_id);

		$note = sprintf(
			__('Paddle payment verified via API. Transaction: %1$s | Currency: %2$s', 'paddle-for-woocommerce'),
			$txn_id,
			$currency
		);
		$order->add_order_note($note);
		$order->save();
	}

	/**
	 * Process payment — called by WC_Checkout::process_checkout().
	 *
	 * Creates a Paddle Billing transaction and returns the transaction ID
	 * to the frontend JS for opening the overlay checkout.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function process_payment($order_id) {
		$order = wc_get_order($order_id);

		if ($this->is_classic_mode()) {
			return $this->process_classic_payment($order);
		}

		return $this->process_billing_payment($order);
	}

	/**
	 * Process payment via Paddle Classic API.
	 * Generates a pay link and returns it to the JS overlay.
	 *
	 * Returns the full result array. WC's process_checkout() sends it via
	 * wp_send_json(), and our JS intercepts checkout_place_order_success
	 * to open the Paddle overlay instead of following WC's redirect.
	 */
	private function process_classic_payment($order) {
		$pay_link = PFWC_API::generate_pay_link($order);

		if (is_wp_error($pay_link)) {
			wc_add_notice($pay_link->get_error_message(), 'error');
			return ['result' => 'failure'];
		}

		$order->update_meta_data('_pfwc_paddle_checkout_url', esc_url_raw($pay_link));
		$order->add_order_note(__('Paddle Classic checkout link generated.', 'paddle-for-woocommerce'));
		$order->save();

		return [
			'result'       => 'success',
			'redirect'     => '#pfwc-checkout-pending',
			'checkout_url' => $pay_link,
			'order_id'     => $order->get_id(),
			'order_key'    => $order->get_order_key(),
			'email'        => $order->get_billing_email(),
			'success_url'  => $this->get_return_url($order),
		];
	}

	/**
	 * Process payment via Paddle Billing API.
	 * Creates a transaction and returns the ID for the overlay.
	 *
	 * Returns the full result array. WC's process_checkout() sends it via
	 * wp_send_json(), and our JS intercepts checkout_place_order_success
	 * to open the Paddle overlay instead of following WC's redirect.
	 */
	private function process_billing_payment($order) {
		$transaction = PFWC_API::create_transaction($order);

		if (is_wp_error($transaction)) {
			wc_add_notice($transaction->get_error_message(), 'error');
			return ['result' => 'failure'];
		}

		$txn_id = sanitize_text_field($transaction['id']);

		$order->update_meta_data('_pfwc_paddle_transaction_id', $txn_id);
		$order->add_order_note(
			sprintf(__('Paddle transaction created: %s', 'paddle-for-woocommerce'), $txn_id)
		);
		$order->save();

		return [
			'result'         => 'success',
			'redirect'       => '#pfwc-checkout-pending',
			'transaction_id' => $txn_id,
			'order_id'       => $order->get_id(),
			'order_key'      => $order->get_order_key(),
			'email'          => $order->get_billing_email(),
			'country_code'   => $order->get_billing_country(),
			'postal_code'    => $order->get_billing_postcode(),
			'success_url'    => $this->get_return_url($order),
		];
	}

	/**
	 * Build the AJAX endpoint URL for our custom handlers.
	 *
	 * @param string $endpoint Endpoint name.
	 * @return string Full URL.
	 */
	private function get_ajax_url($endpoint) {
		return add_query_arg('wc-ajax', $endpoint, wc_get_checkout_url());
	}

	/**
	 * Available locale/language options for Paddle checkout.
	 */
	private static function get_locale_options() {
		return [
			'auto'    => __('Auto-detect (browser)', 'paddle-for-woocommerce'),
			'en'      => 'English',
			'es'      => 'Español',
			'fr'      => 'Français',
			'de'      => 'Deutsch',
			'it'      => 'Italiano',
			'pt'      => 'Português',
			'pt-BR'   => 'Português (Brasil)',
			'nl'      => 'Nederlands',
			'ja'      => '日本語',
			'ko'      => '한국어',
			'zh-Hans' => '中文 (简体)',
			'zh-Hant' => '中文 (繁體)',
			'ar'      => 'العربية',
			'da'      => 'Dansk',
			'sv'      => 'Svenska',
			'no'      => 'Norsk',
			'fi'      => 'Suomi',
			'pl'      => 'Polski',
			'cs'      => 'Čeština',
			'hu'      => 'Magyar',
			'ro'      => 'Română',
			'th'      => 'ไทย',
			'tr'      => 'Türkçe',
			'uk'      => 'Українська',
			'vi'      => 'Tiếng Việt',
			'hi'      => 'हिन्दी',
			'id'      => 'Bahasa Indonesia',
			'ms'      => 'Bahasa Melayu',
		];
	}
}
