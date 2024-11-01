<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Handles Refunds.
 */

class WC_Gateway_Free_Charge_Refund {

	/** @var string merchantId for refunds */
	public static $merchantId;

	/** @var string secertkey for checksum */
	public static $secertkey;

	/**
	 * Get refund request args.
	 * @param  WC_Order $order
	 * @param  float    $amount
	 * @return array
	 */
	public static function get_request($order, $amount = null) {
		$request = array(
			'txnId' => $order->get_transaction_id(),
			'refundMerchantTxnId' => 'Refund_' . $order->id,
			'merchantId' => self::$merchantId,
			'refundAmount' => $amount,
		);

		$checksum = ChecksumUtil::generateChecksumForJson(json_decode(json_encode($request), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), self::$secertkey);

		$request['checksum'] = $checksum;

		WC_Gateway_Free_Charge::log($request);

		return apply_filters('woocommerce_paypal_refund_request', $request, $order, $amount);
	}

	/**
	 * Refund an order via FreeCharge..
	 * @param  WC_Order $order
	 * @param  float    $amount
	 * @param  bool     $sandbox
	 * @return array|wp_error The parsed response from paypal, or a WP_Error object
	 */

	public static function refund_order($order, $amount = null, $sandbox = false) {
		if ($sandbox == 'yes') {
			$refundurl = "https://checkout-sandbox.freecharge.in/api/v1/co/refund";
		} else {
			$refundurl = "https://checkout.freecharge.in/api/v1/co/refund";
		}

		$response = wp_safe_remote_post($refundurl,
			array(
				'method' => 'POST',
				'headers' => array('content-type' => 'application/json'),
				'body' => json_encode(self::get_request($order, $amount)),
				'user-agent' => 'WooCommerce',
			)
		);

		if (is_wp_error($response)) {
			return $response;
		}

		if (empty($response['body'])) {
			return new WP_Error('paypal-refunds', 'Empty Response');
		}

		$response_array = json_decode($response['body'], 1);

		return $response_array;
	}
}
