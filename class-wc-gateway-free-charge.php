<?php
/*
Plugin Name: FreeCharge Gateway for WooCommerce
Plugin URI:
Description: WooCommerce with Free Charge Indian payment gateway.
Version: 1.0
Author: Vikas Kapadiya
Author URI: https://www.kapadiya.net

Copyright: Â© 2016-2017 Vikas Kapadiya
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */
if (!defined('ABSPATH')) {
	exit;
}

include_once 'includes/checksum.php'; //checksum class

add_action('plugins_loaded', 'woocommerce_free_charge_init', 0);

function woocommerce_free_charge_init() {
	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}

	/**
	 * Gateway class
	 */

	class WC_Gateway_Free_Charge extends WC_Payment_Gateway {

		/** @var bool Whether or not logging is enabled */
		public static $log_enabled = false;

		/** @var WC_Logger Logger instance */
		public static $log = false;

		// Go wild in here
		public function __construct() {
			$this->id = 'freecharge';
			$this->method_title = 'Free Charge';
			$this->has_fields = true;

			$this->init_form_fields();
			$this->init_settings();

			$this->title = $this->settings['title'];
			$this->description = $this->settings['description'];
			$this->merchant_id = $this->settings['merchant_id'];
			$this->secret_key = $this->settings['secret_key'];
			$this->sandbox = $this->settings['sandbox'];
			$this->debug = $this->settings['debug'];

			$this->supports = array(
				'products',
				'refunds',
			);

			self::$log_enabled = $this->debug;

			if ($this->sandbox == 'yes') {
				$this->liveurl = "https://checkout-sandbox.freecharge.in/api/v1/co/pay/init";
			} else {
				$this->liveurl = "https://checkout.freecharge.in/api/v1/co/pay/init";
			}

			$this->notify_url = home_url('?wc-api=wc_gateway_free_charge');
			$this->msg['message'] = "";
			$this->msg['class'] = "";

			add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_freecharge_response'));

			add_action('valid-freecharge-request', array($this, 'successful_request'));

			if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			} else {
				add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
			}
			add_action('woocommerce_receipt_freecharge', array($this, 'receipt_page'));
		}

		/**
		 * Logging method.
		 * @param string $message
		 */
		public static function log($message) {
			if (self::$log_enabled) {
				if (empty(self::$log)) {
					self::$log = new WC_Logger();
				}
				self::$log->add('freeCharge', '--------------------');
				if (is_array($message)) {
					foreach ($message as $key => $value) {
						$data = "Field " . htmlspecialchars($key) . " is " . htmlspecialchars($value);
						self::$log->add('freeCharge', $data);
					}
				} else {
					self::$log->add('freeCharge', $message);
				}
				self::$log->add('freeCharge', '--------------------');
			}
		}

		function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title' => __('Enable/Disable', 'vikas'),
					'type' => 'checkbox',
					'label' => __('Enable Free Charge Payment Module.', 'vikas'),
					'default' => 'no'),

				'sandbox' => array(
					'title' => __('Enable Sandbox?', 'vikas'),
					'type' => 'checkbox',
					'label' => __('Enable Sandbox for Free Charge Payment.', 'vikas'),
					'default' => 'no'),

				'debug' => array(
					'title' => __('Enable Debug?', 'vikas'),
					'type' => 'checkbox',
					'label' => __('Enable Debug for Free Charge Payment.', 'vikas'),
					'default' => 'no'),

				'title' => array(
					'title' => __('Title:', 'vikas'),
					'type' => 'text',
					'description' => __('This controls the title which the user sees during checkout.', 'vikas'),
					'default' => __('Free Charge', 'vikas')),
				'description' => array(
					'title' => __('Description:', 'vikas'),
					'type' => 'textarea',
					'description' => __('This controls the description which the user sees during checkout.', 'vikas'),
					'default' => __('Pay securely by Credit or Debit card or internet banking through Free Charge Secure Servers.', 'vikas')),
				'merchant_id' => array(
					'title' => __('Merchant ID', 'vikas'),
					'type' => 'text',
					'description' => __('This id(USER ID) available at "Generate Working Key" of "Settings and Options at Free Charge."')),
				'secret_key' => array(
					'title' => __('Secret Key', 'vikas'),
					'type' => 'text',
					'description' => __('Given to Merchant by Free Charge', 'vikas'),
				),

			);
		}

		/**
		 * Admin Panel Options
		 * - Options for bits like 'title' and availability on a country-by-country basis
		 **/

		public function admin_options() {
			echo '<h3>' . __('Free Charge Payment Gateway', 'vikas') . '</h3>';
			echo '<p>' . __('Free Charge is most popular payment gateway for online shopping in India') . '</p>';
			echo '<table class="form-table">';
			$this->generate_settings_html();
			echo '</table>';

		}

		/**
		 *  There are no payment fields for Free Charge, but we want to show the description if set.
		 **/
		public function payment_fields() {
			if ($this->description) {
				echo (wptexturize($this->description));
			}

		}

		/**
		 * Receipt Page
		 **/

		public function receipt_page($order) {
			echo '<p>' . __('Thank you for your order, please click the button below to pay.', 'vikas') . '</p>';
			echo $this->generate_freecharge_form($order);
		}

		/**
		 * Process the payment and return the result
		 **/

		public function process_payment($order_id) {
			$order = new WC_Order($order_id);
			return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url(true));
		}

		/**
		 * Generate Free Charge button link
		 **/

		public function generate_freecharge_form($order_id) {
			global $woocommerce;
			$order = new WC_Order($order_id);
			$order_id = $order_id;

			$the_order_total = $order->order_total;
			$freecharge_args = array(
				'merchantTxnId' => strval($order_id),
				'amount' => $the_order_total,
				'furl' => $this->notify_url,
				'surl' => $this->notify_url,
				'merchantId' => $this->merchant_id,
				'channel' => "WEB",
			);

			$checksum = ChecksumUtil::generateChecksumForJson(json_decode(json_encode($freecharge_args), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->secret_key);

			$freecharge_args_array = array();
			foreach ($freecharge_args as $key => $value) {
				$freecharge_args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">' . "\n";
			}

			//add checksum
			$freecharge_args_array[] = '<input type="hidden" name="checksum" value="' . esc_attr($checksum) . '">' . "\n";

			$form = '';

			wc_enqueue_js('
					$.blockUI({
						message: "' . esc_js(__('Thank you for your order. We are now redirecting you to Free Charge to make payment.', 'woocommerce')) . '",
						baseZ: 99999,
						overlayCSS:
						{
							background: "#fff",
							opacity: 0.6
						},
						css: {
							padding:        "20px",
							zindex:         "9999999",
							textAlign:      "center",
							color:          "#555",
							border:         "3px solid #aaa",
							backgroundColor:"#fff",
							cursor:         "wait",
							lineHeight:     "24px",
						}
					});
				jQuery("#submit_freecharge_payment_form").click();
				');

			// Get the right URL in case the test mode is enabled
			$posturl = $this->liveurl;

			$form .= '<form action="' . esc_url($posturl) . '" method="post" id="freecharge_payment_form"  ' . $targetto . '>
				' . implode('', $freecharge_args_array) . '
				<!-- Button Fallback -->
				<div class="payment_buttons">
				<input type="submit" class="button alt" id="submit_freecharge_payment_form" value="' . __('Pay via Free Charge', 'woocommerce') . '" /> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'woocommerce') . '</a>
				</div>
				</form>';
			return $form;
		}

		/**
		 * Create Checksum for Server Response
		 **/

		public function checksum_response($args) {

			$hashargs = array(
				'txnId' => $args['txnId'],
				'status' => $args['status'],
				'metadata' => $args['metadata'],
				'merchantTxnId' => $args['merchantTxnId'],
				'authCode' => $args['authCode'],
				'amount' => $args['amount'],
			);

			$hash = ChecksumUtil::generateChecksumForJson(json_decode(json_encode($hashargs), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->secret_key);

			return $hash;
		}

		public function check_freecharge_response() {
			global $woocommerce;

			$msg['class'] = 'error';
			$msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";

			if (isset($_REQUEST['status'])) {

				//Log Request

				$this->log($_REQUEST);

				$order_id = (int) wc_clean($_REQUEST['merchantTxnId']);

				if ($order_id != '') {
					try {

						$order = new WC_Order($order_id);
						$order_status = strtolower(wc_clean($_REQUEST['status']));
						$transauthorised = false;
						if ($order->status !== 'completed') {

							// Check if User Click back to merchant link ( There is no Checksum to verfiy)
							if ($order_status === "failed" && wc_clean($_REQUEST['errorCode']) == "E704") {
								$admin_email = get_option('admin_email');
								$msg['message'] = 'Payment was cancelled. Reason: ' . wc_clean($_REQUEST['errorMessage']);
								$msg['class'] = 'error';

							} else if ($this->checksum_response(wc_clean($_REQUEST)) === wc_clean($_REQUEST['checksum'])) {

								//verify response using checksum

								if ($order_status == "completed") {
									$transauthorised = true;
									$msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.";
									$msg['class'] = 'success';
									if ($order->status != 'processing') {
										$transaction_id = wc_clean($_REQUEST['txnId']);
										$order->payment_complete($transaction_id);
										$order->add_order_note('Free Charge payment successful<br/>Ref Number: ' . $_REQUEST['txnId']);
										$woocommerce->cart->empty_cart();
									}
								} else if ($order_status === "failed" && wc_clean($_REQUEST['errorCode']) == "E005") {
									$admin_email = get_option('admin_email');
									$msg['message'] = 'Server Error , Please use other payment gateway.';
									$msg['class'] = 'error';

								} else {
									$msg['class'] = 'error';
									$msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";
								}

							} else {
								$this->msg['class'] = 'error';
								$this->msg['message'] = "Security Error. Illegal access detected.";
							}

						}

						if ($transauthorised == false) {
							$order->update_status('failed');
							$order->add_order_note('Failed');
							$order->add_order_note($msg['message']);
						}

					} catch (Exception $e) {

						$msg['class'] = 'error';
						$msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";

					}
				}
			}

			if (function_exists('wc_add_notice')) {
				wc_add_notice($msg['message'], $msg['class']);

			} else {
				if ($msg['class'] == 'success') {
					$woocommerce->add_message($msg['message']);
				} else {
					$woocommerce->add_error($msg['message']);

				}
				$woocommerce->set_messages();
			}
			//$redirect_url = get_permalink(woocommerce_get_page_id('myaccount'));
			$redirect_url = $this->get_return_url($order);
			wp_redirect($redirect_url);

			exit;

		}

		/**
		 * Can the order be refunded via FreeCharge?
		 * @param  WC_Order $order
		 * @return bool
		 */
		public function can_refund_order($order) {
			return $order && $order->get_transaction_id();
		}

		/**
		 * Process a refund if supported.
		 * @param  int    $order_id
		 * @param  float  $amount
		 * @param  string $reason
		 * @return bool True or false based on success, or a WP_Error object
		 */

		public function process_refund($order_id, $amount = null, $reason = '') {
			$order = wc_get_order($order_id);

			if (!$this->can_refund_order($order)) {
				$this->log('Refund Failed: No transaction ID');
				return new WP_Error('error', __('Refund Failed: No transaction ID', 'woocommerce'));
			}

			include_once 'includes/class-wc-gateway-free-charge-refund.php';

			WC_Gateway_Free_Charge_Refund::$merchantId = $this->merchant_id;

			WC_Gateway_Free_Charge_Refund::$secertkey = $this->secret_key;

			$result = WC_Gateway_Free_Charge_Refund::refund_order($order, $amount, $this->sandbox);

			if (is_wp_error($result)) {
				$this->log('Refund Failed: ' . $result->get_error_message());
				return new WP_Error('error', $result->get_error_message());
			}

			$this->log('Refund Result: ' . $result);

			switch (strtolower($result['status'])) {
			case 'success':
			case 'initiated':
				$order->add_order_note(sprintf(__('Refund Initiated. Amount: %s - Refund ID: %s', 'woocommerce'), $result['refundedAmount'], $result['refundTxnId']));
				return true;
				break;
			}

			return isset($result['errorCode']) ? new WP_Error('error', $result['errorMessage']) : false;
		}

	}

	/**
	 * Add the Gateway to WooCommerce
	 **/
	function woocommerce_add_free_Charge_gateway($methods) {
		$methods[] = 'WC_Gateway_Free_Charge';

		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'woocommerce_add_free_Charge_gateway');
}