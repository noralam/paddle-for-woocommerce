/**
 * Paddle for WooCommerce — Classic Shortcode Checkout JS
 *
 * Supports both Paddle Classic (paddle.js v1) and Paddle Billing (paddle.js v2).
 * Classic: Paddle.Setup() + Paddle.Checkout.open({ override }) with pay link URL.
 * Billing: Paddle.Initialize() + Paddle.Checkout.open({ transactionId }).
 *
 * Uses WooCommerce's native checkout AJAX flow. The gateway's process_payment()
 * returns Paddle data (checkout_url or transaction_id) in the standard WC result.
 * We intercept WC's checkout_place_order_success event (via triggerHandler) to
 * open the Paddle overlay and return false to prevent WC's default redirect.
 *
 * @version 2.0.0
 */
jQuery(function ($) {
	'use strict';

	if (typeof Paddle === 'undefined' || typeof pfwc_params === 'undefined') {
		return;
	}

	// Skip if no classic checkout form is present (WC Blocks has its own JS)
	var $checkoutForm = $('form.checkout');
	var $orderReviewForm = $('form#order_review');
	if (!$checkoutForm.length && !$orderReviewForm.length) {
		return;
	}

	var isClassic = pfwc_params.api_mode === 'classic';

	// ——— Initialize Paddle.js ———

	if (isClassic) {
		if (pfwc_params.environment === 'sandbox') {
			Paddle.Environment.set('sandbox');
		}
		Paddle.Setup({
			vendor: parseInt(pfwc_params.vendor_id, 10),
		});
	} else {
		if (pfwc_params.environment === 'sandbox') {
			Paddle.Environment.set('sandbox');
		}

		var checkoutSettings = {
			variant: pfwc_params.checkout_variant || 'multi-page',
			theme: pfwc_params.checkout_theme || 'light',
		};

		if (pfwc_params.locale && pfwc_params.locale !== 'auto') {
			checkoutSettings.locale = pfwc_params.locale;
		}

		Paddle.Initialize({
			token: pfwc_params.client_token,
			checkout: {
				settings: checkoutSettings,
			},
			eventCallback: function (event) {
				if (event.name === 'checkout.completed') {
					handleCheckoutCompleted();
				}
				if (event.name === 'checkout.closed') {
					handleCheckoutClosed();
				}
			},
		});
	}

	// ——— State ———

	var successUrl = '';
	var pendingOrderId = '';
	var pendingOrderKey = '';

	// ——— WC Checkout Success Interception ———
	//
	// WC's checkout.js fires checkout_place_order_success via triggerHandler()
	// after a successful process_payment(). The result object (passed by reference)
	// contains our custom Paddle data (checkout_url / transaction_id).
	//
	// IMPORTANT: We must NOT return false — WC treats that as "Invalid response"
	// and shows an error. Instead, we open the Paddle overlay and overwrite
	// result.redirect with a hash fragment. WC then does:
	//   window.location = '#pfwc-checkout-pending'
	// which only changes the URL hash — no page reload, no error.

	$checkoutForm.on('checkout_place_order_success', function (event, result) {
		if (!result) {
			return true;
		}

		if (isClassic && result.checkout_url) {
			openClassicCheckout(result);
			result.redirect = '#pfwc-checkout-pending';
			return true;
		}

		if (!isClassic && result.transaction_id) {
			openBillingCheckout(result);
			result.redirect = '#pfwc-checkout-pending';
			return true;
		}

		// No Paddle data in response — let WC handle normally
		return true;
	});

	// ——— Order-Pay Page ———
	//
	// The order-pay page (for failed/pending orders) uses a separate form
	// and doesn't go through WC's standard checkout AJAX, so we keep the
	// custom AJAX approach for this endpoint only.

	$orderReviewForm.on('submit', function (e) {
		if (!isPaddleSelected()) {
			return;
		}

		e.preventDefault();
		blockForm($orderReviewForm);

		var formData = $orderReviewForm.serialize();

		var orderIdField = $('input[name="order_id"]').val();
		if (orderIdField) {
			formData += '&order_id=' + encodeURIComponent(orderIdField);
		}

		var urlParams = new URLSearchParams(window.location.search);
		var orderKey = urlParams.get('key');
		if (orderKey) {
			formData += '&key=' + encodeURIComponent(orderKey);
		}

		$.ajax({
			type: 'POST',
			url: pfwc_params.ajax_url,
			data: formData,
			dataType: 'json',
			success: function (response) {
				if (response.result === 'success') {
					if (isClassic && response.checkout_url) {
						openClassicCheckout(response);
					} else if (!isClassic && response.transaction_id) {
						openBillingCheckout(response);
					} else {
						handleError($orderReviewForm, response);
					}
				} else {
					handleError($orderReviewForm, response);
				}
			},
			error: function (jqXHR, textStatus) {
				handleError($orderReviewForm, {
					messages:
						'Unable to process your order. Please try again. (' +
						textStatus +
						')',
				});
			},
		});

		return false;
	});

	// ——— Core Functions ———

	function isPaddleSelected() {
		return (
			$('#payment_method_' + pfwc_params.gateway_id).is(':checked') ||
			$orderReviewForm.length > 0
		);
	}

	/**
	 * Open Paddle Classic overlay checkout with a pay link URL.
	 */
	function openClassicCheckout(data) {
		successUrl = data.success_url || '';
		pendingOrderId = data.order_id || '';
		pendingOrderKey = data.order_key || '';

		Paddle.Checkout.open({
			override: data.checkout_url,
			email: data.email || '',
			successCallback: function () {
				handleCheckoutCompleted();
			},
			closeCallback: function () {
				handleCheckoutClosed();
			},
		});

		unblockForm($checkoutForm.add($orderReviewForm));
	}

	/**
	 * Open Paddle Billing overlay checkout with a transactionId.
	 */
	function openBillingCheckout(data) {
		successUrl = data.success_url || '';
		pendingOrderId = data.order_id || '';
		pendingOrderKey = data.order_key || '';

		var checkoutConfig = {
			transactionId: data.transaction_id,
		};

		if (data.email || data.country_code) {
			checkoutConfig.customer = {};

			if (data.email) {
				checkoutConfig.customer.email = data.email;
			}

			if (data.country_code || data.postal_code) {
				checkoutConfig.customer.address = {};

				if (data.country_code) {
					checkoutConfig.customer.address.countryCode = data.country_code;
				}
				if (data.postal_code) {
					checkoutConfig.customer.address.postalCode = data.postal_code;
				}
			}
		}

		Paddle.Checkout.open(checkoutConfig);

		unblockForm($checkoutForm.add($orderReviewForm));
	}

	function handleCheckoutCompleted() {
		if (pendingOrderId && pfwc_params.confirm_url) {
			$.ajax({
				type: 'POST',
				url: pfwc_params.confirm_url,
				data: {
					order_id: pendingOrderId,
					order_key: pendingOrderKey,
				},
				dataType: 'json',
				complete: function () {
					if (successUrl) {
						window.location.href = successUrl;
					}
				},
			});
		} else if (successUrl) {
			window.location.href = successUrl;
		}
	}

	function handleCheckoutClosed() {
		// Clean up the hash fragment we set to prevent WC redirect
		if (window.location.hash === '#pfwc-checkout-pending') {
			history.replaceState(null, '', window.location.pathname + window.location.search);
		}
		unblockForm($checkoutForm.add($orderReviewForm));
	}

	function handleError($form, response) {
		unblockForm($form);

		$(
			'.woocommerce-error, .woocommerce-message, .woocommerce-NoticeGroup'
		).remove();

		var message =
			response.messages || 'An error occurred. Please try again.';

		if (typeof message === 'string' && message.indexOf('<') === -1) {
			message =
				'<ul class="woocommerce-error" role="alert"><li>' +
				$('<span>').text(message).html() +
				'</li></ul>';
		}

		$form.prepend(
			'<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' +
				message +
				'</div>'
		);

		$('html, body').animate(
			{
				scrollTop: $form.offset().top - 100,
			},
			500
		);
	}

	function blockForm($form) {
		$form.addClass('processing');
		if ($.fn.block) {
			$form.block({
				message: null,
				overlayCSS: { background: '#fff', opacity: 0.6 },
			});
		}
	}

	function unblockForm($form) {
		$form.removeClass('processing');
		if ($.fn.unblock) {
			$form.unblock();
		}
	}
});
