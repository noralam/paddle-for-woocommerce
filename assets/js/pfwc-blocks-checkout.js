/**
 * Paddle for WooCommerce — WC Blocks Checkout Integration
 *
 * Registers the Paddle payment method with WooCommerce Blocks checkout.
 * After order placement, opens the Paddle overlay checkout using data
 * returned from the server in paymentDetails.
 *
 * @version 1.0.0
 */
(function () {
	'use strict';

	var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = wc.wcSettings.getSetting;
	var createElement = wp.element.createElement;
	var useEffect = wp.element.useEffect;

	var settings = getSetting('paddle_data', {});
	var isClassic = settings.api_mode === 'classic';
	var paddleInitialized = false;
	var pendingOrderId = '';
	var pendingOrderKey = '';

	/**
	 * Initialize Paddle.js once.
	 */
	function initPaddle() {
		if (paddleInitialized || typeof Paddle === 'undefined') {
			return;
		}
		paddleInitialized = true;

		if (settings.environment === 'sandbox') {
			Paddle.Environment.set('sandbox');
		}

		if (isClassic) {
			Paddle.Setup({
				vendor: parseInt(settings.vendor_id, 10),
			});
		} else {
			var checkoutSettings = {
				variant: settings.checkout_variant || 'multi-page',
				theme: settings.checkout_theme || 'light',
			};

			if (settings.locale && settings.locale !== 'auto') {
				checkoutSettings.locale = settings.locale;
			}

			Paddle.Initialize({
				token: settings.client_token,
				checkout: { settings: checkoutSettings },
				eventCallback: function (event) {
					if (event.name === 'checkout.completed') {
						confirmAndRedirect();
					}
				},
			});
		}
	}

	/**
	 * Label component shown in the payment method list.
	 */
	var Label = function (props) {
		var PaymentMethodLabel = props.components.PaymentMethodLabel;
		var children = [createElement(PaymentMethodLabel, {
			text: settings.title || 'Pay with Card, PayPal & More',
		})];
		if (settings.icon) {
			children.push(
				createElement('img', {
					src: settings.icon,
					alt: settings.title || 'Payment Methods',
					style: { maxHeight: '24px', marginLeft: '8px', verticalAlign: 'middle', display: 'inline-block' },
				})
			);
		}
		return createElement('span', { style: { display: 'flex', alignItems: 'center', gap: '8px' } }, children);
	};

	/**
	 * Content component — initializes Paddle and handles post-checkout overlay.
	 */
	var Content = function (props) {
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var onCheckoutSuccess = eventRegistration.onCheckoutSuccess;

		// Initialize Paddle.js on mount
		useEffect(function () {
			initPaddle();
		}, []);

		// After successful checkout, open Paddle overlay
		useEffect(function () {
			var unsubscribe = onCheckoutSuccess(function (data) {
				var raw =
					data.processingResponse &&
					data.processingResponse.paymentDetails;

				if (!raw) {
					return { type: emitResponse.responseTypes.SUCCESS };
				}

				// WC Blocks passes paymentDetails as [{key, value}, ...]
				// Convert to a keyed object for openPaddleCheckout
				var details = {};
				if (Array.isArray(raw)) {
					raw.forEach(function (item) {
						if (item.key) {
							details[item.key] = item.value;
						}
					});
				} else {
					details = raw;
				}

				openPaddleCheckout(details);

				// Return SUCCESS with no redirect so WC doesn't navigate away
				return {
					type: emitResponse.responseTypes.SUCCESS,
					redirectUrl: false,
				};
			});

			return unsubscribe;
		}, [onCheckoutSuccess, emitResponse]);

		return createElement(
			'div',
			null,
			settings.description
				? createElement('p', null, settings.description)
				: null
		);
	};

	/**
	 * Open Paddle overlay checkout with data from the server.
	 */
	function openPaddleCheckout(data) {
		if (typeof Paddle === 'undefined') {
			if (data.success_url) {
				window.location.href = data.success_url;
			}
			return;
		}

		window._pfwcSuccessUrl = data.success_url || '';
		pendingOrderId = data.order_id || '';
		pendingOrderKey = data.order_key || '';

		if (isClassic && data.checkout_url) {
			Paddle.Checkout.open({
				override: data.checkout_url,
				email: data.email || '',
				successCallback: function () {
					confirmAndRedirect();
				},
				closeCallback: function () {
					// User closed — order stays pending
				},
			});
		} else if (!isClassic && data.transaction_id) {
			var config = { transactionId: data.transaction_id };

			if (data.email || data.country_code) {
				config.customer = {};
				if (data.email) {
					config.customer.email = data.email;
				}
				if (data.country_code || data.postal_code) {
					config.customer.address = {};
					if (data.country_code) {
						config.customer.address.countryCode = data.country_code;
					}
					if (data.postal_code) {
						config.customer.address.postalCode = data.postal_code;
					}
				}
			}

			Paddle.Checkout.open(config);
		} else if (data.success_url) {
			window.location.href = data.success_url;
		}
	}

	/**
	 * Confirm payment via AJAX and then redirect to the success URL.
	 * This ensures the WC order is marked paid before the customer sees the thank-you page.
	 */
	function confirmAndRedirect() {
		var url = window._pfwcSuccessUrl || '';

		if (pendingOrderId && settings.confirm_url) {
			var formData = new FormData();
			formData.append('order_id', pendingOrderId);
			formData.append('order_key', pendingOrderKey);

			fetch(settings.confirm_url, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin',
			}).finally(function () {
				if (url) {
					window.location.href = url;
				}
			});
		} else if (url) {
			window.location.href = url;
		}
	}

	/**
	 * Register the Paddle payment method with WC Blocks.
	 */
	registerPaymentMethod({
		name: 'paddle',
		label: createElement(Label, null),
		content: createElement(Content, null),
		edit: createElement(Content, null),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: settings.title || 'Paddle',
		supports: {
			features: settings.supports || ['products'],
		},
	});
})();
