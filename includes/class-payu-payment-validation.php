<?php
class PayuPaymentValidation
{

	public $msg;
	public $currency1PayuSalt;
	public $bypassVerifyPayment;
	public $currency1PayuKey;
	public $gatewayModule;
	public $redirect_page_id;

	public function __construct()
	{

		$plugin_data = get_option('woocommerce_payubiz_settings');
		$this->currency1PayuSalt = sanitize_text_field($plugin_data['currency1_payu_salt']);
		$this->currency1PayuKey = sanitize_text_field($plugin_data['currency1_payu_key']);
		$this->redirect_page_id = sanitize_text_field($plugin_data['redirect_page_id']);
		$this->gatewayModule = $plugin_data['gateway_module'];
		if (sanitize_text_field($plugin_data['verify_payment']) != "yes") {
			$this->bypassVerifyPayment = true;
		}
	}

	public function payuPaymentValidationAndRedirect($postdata)
	{
		$order = $this->paymentValidationAndUpdation($postdata);
		$this->manageMessages();
		$redirect_url = $this->getRedirectUrl($order);
		$this->redirectTo($redirect_url);
	}

	public function paymentValidationAndUpdation($postdata, $bypass_verify_payment = false)
	{

		if (isset($postdata['key'])) {
			$this->bypassVerifyPayment = $bypass_verify_payment;
			global $woocommerce, $wpdb;
			$payu_key = $this->currency1PayuKey;
			$payu_salt = $this->currency1PayuSalt;

			$txnid = $postdata['txnid'];
			$order_id = explode('_', $txnid);
			$order_id = (int)$order_id[0];    //get rid of time part
			payu_transaction_data_insert($postdata, $order_id);
			$order = new WC_Order($order_id);
			$order->update_meta_data('payu_bankcode', $postdata['bankcode']);
			$order->update_meta_data('payu_mode', $postdata['mode']);
			$transaction_offer = $postdata['transaction_offer'];
			if (isset($postdata['extra_charges']['method_code'])) {
				$this->update_shipping_method($order, $postdata['extra_charges']['method_code']);
			}
			$this->reconcileOfferData($transaction_offer, $order);
			return $this->payuValidatePostData($postdata, $order, $payu_key, $payu_salt);
		} else {
			return false;
		}
	}

	private function manageMessages()
	{
		if (function_exists('wc_add_notice')) {
			wc_clear_notices();
			if ($this->msg['class'] != 'success') {
				wc_add_notice($this->msg['message'], $this->msg['class']);
			}
		} else {
			global $woocommerce;
			if ($this->msg['class'] != 'success') {
				$woocommerce->add_error($this->msg['message']);
			}
			$woocommerce->set_messages();
		}
	}


	private function getRedirectUrl($order)
	{
		$redirect_url = ($this->redirect_page_id == '' || $this->redirect_page_id == 0) ?
			get_site_url() . '/' :
			get_permalink($this->redirect_page_id);
		if ($order && $this->msg['class'] == 'success') {
			$redirect_url = $order->get_checkout_order_received_url();
		}
		return $redirect_url;
	}

	private function redirectTo($redirect_url)
	{
		wp_redirect($redirect_url);
		exit;
	}

	private function payuValidatePostData($postdata, $order, $payu_key, $payu_salt)
	{

		$udf4 = $order->get_meta('udf4');
		$txnid = $postdata['txnid'];

		if ($postdata['key'] == $payu_key) {
			$amount      		= 	$postdata['amount'];
			$productInfo  		= 	$postdata['productinfo'];
			$firstname    		= 	$postdata['firstname'];
			$email        		=	$postdata['email'];
			$phone        		=	$postdata['phone'];
			$udf5				=   $postdata['udf5'];
			create_user_and_login_if_not_exist($email);
			$user = get_user_by('email', $email);
			if ($user) {
				$user_id = $user->ID;
				$order->set_customer_id($user_id);
				update_user_meta($user_id, 'payu_phone', $phone);
			}

			$keyString = $payu_key . '|' . $txnid . '|' . $amount . '|' . $productInfo . '|' . $firstname . '|' . $email
				. '||||' . $udf4 . '|' . $udf5 . '|||||';
			$keyArray 	  		= 	explode("|", $keyString);
			$reverseKeyArray 	= 	array_reverse($keyArray);
			$reverseKeyString	=	implode("|", $reverseKeyArray);
			$this->payuUpdateShippingAddress($postdata, $order);
			$order = $this->processPaymentStatus($postdata, $order, $reverseKeyString, $payu_key, $payu_salt);
		}
		return $order;
	}

	private function payuUpdateShippingAddress($postdata, $order)
	{
		if (isset($postdata['shipping_address']) && !empty($postdata['shipping_address'])) {
			$full_name = explode(' ', $postdata['shipping_address']['name']);

			$new_address = array(
				'country' => 'IN',
				'state' => $postdata['shipping_address']['state'],
				'city' => $postdata['shipping_address']['city'],
				'email' => $postdata['shipping_address']['email'],
				'postcode' => $postdata['shipping_address']['pincode'],
				'phone' => $postdata['shipping_address']['addressPhoneNumber'],
				'address_1' => $postdata['shipping_address']['addressLine'],
				'first_name' => isset($full_name[0]) ? $full_name[0] : '',
				'last_name' => isset($full_name[1]) ? $full_name[1] : ''
			);
			$order->update_meta_data('shipping_email', $postdata['shipping_address']['email']);
			$order->set_shipping_first_name(isset($full_name[0]) ? $full_name[0] : '');
			$order->set_address($new_address, 'shipping');
			$order->set_address($new_address, 'billing');
		}
	}

	private function processPaymentStatus($postdata, $order, $reverseKeyString, $payu_key, $payu_salt)
	{

		$status = $postdata['status'];
		switch ($status) {
			case 'success':
				return $this->processSuccessPayment($postdata, $order, $reverseKeyString, $payu_key, $payu_salt);
				break;
			case 'failure':
				return $this->processFailurePayment($postdata, $order);
				break;
			default:
				return $this->processDefaultPayment($order);
		}
	}

	private function processSuccessPayment($postdata, $order, $reverseKeyString, $payu_key, $payu_salt)
	{
		global $woocommerce;
		$txnid = $postdata['txnid'];
		$order_id = $order->ID;
		$amount = $postdata['amount'];
		$additionalCharges = 0;

		if (isset($postdata["additionalCharges"])) {
			$additionalCharges = $postdata['additionalCharges'];
		}

		$saltString     = $payu_salt . '|' . $postdata['status'] . '|' . $reverseKeyString;
		if ($additionalCharges > 0) {
			$saltString = $additionalCharges . '|' . $payu_salt . '|' . $postdata['status'] . '|' . $reverseKeyString;
		}

		$sentHashString = strtolower(hash('sha512', $saltString));
		$responseHashString = $postdata['hash'];
		$this->msg['class'] = 'error';
		$thankyou_msg = 'Thank you for shopping with us. However, the transaction has been declined.';
		$this->msg['message'] = esc_html__($thankyou_msg, 'payubiz');
		if (
			$sentHashString == $responseHashString &&
			$this->verifyPayment($order, $txnid, $payu_key, $payu_salt, $this->bypassVerifyPayment)
		) {
			$thankyou_msg = 'Thank you for shopping with us. Your account has been charged and your transaction is successful'
				. ' with the following order details:';
			$this->msg['message'] = esc_html__($thankyou_msg, 'payubiz');
			$this->msg['message'] .= '<br>' . esc_html__('Order Id:' . $order_id, 'payubiz') . '<br/>';
			$this->msg['message'] .= esc_html__('Amount:' . $amount, 'payubiz') . '<br />';
			$this->msg['message'] .= esc_html__('We will be shipping your order to you soon.', 'payubiz');


			if ($additionalCharges > 0) {
				$thankyou_msg = 'Additional amount charged by PayUBiz - ' . $additionalCharges;
				$this->msg['message'] .= '<br /><br />' . esc_html__($thankyou_msg, 'payubiz');
			}


			$this->msg['class'] = 'success';

			if ($order->status == 'processing' || $order->status == 'completed') {
				//do nothing
			} else {
				//complete the order
				error_log("order marked payment completed order id $order_id");
				$order->payment_complete();
				$thankyou_msg = "PayUBiz has processed the payment. Ref Number: " . $postdata['mihpayid'];
				$order->add_order_note(esc_html__($thankyou_msg, 'payubiz'));
				$order->add_order_note($this->msg['message']);
				$order->add_order_note('Paid by PayUBiz');
				$woocommerce->cart->empty_cart();
			}
		} else {
			//tampered
			$this->msg['class'] = 'error';
			$thankyou_msg = 'Thank you for shopping with us. However, the payment failed';
			$this->msg['message'] = esc_html__($thankyou_msg);
			$order->update_status('failed');
			$order->add_order_note('Failed');
			$order->add_order_note($this->msg['message']);
			error_log("order marked failed order id $order_id");
		}
		return $order;
	}

	private function processFailurePayment($postdata, $order)
	{
		$this->msg['class'] = 'error';
		$thankyou_msg = 'Thank you for shopping with us. However, the payment failed (' . $postdata['field9'] . ')';
		$this->msg['message'] = esc_html__($thankyou_msg);
		$order->update_status('failed');
		$order->add_order_note('Failed');
		$order->add_order_note($this->msg['message']);

		return $order;
	}

	private function processDefaultPayment($order)
	{
		$this->msg['class'] = 'error';
		$thankyou_msg = 'Thank you for shopping with us. However, the payment failed';
		$this->msg['message'] = esc_html__($thankyou_msg);
		$order->update_status('failed');
		$order->add_order_note('Failed');
		$order->add_order_note($this->msg['message']);
		return $order;
	}

	private function reconcileOfferData($transaction_offer, $order)
	{

		if (!is_array($transaction_offer)) {
			$transaction_offer = json_decode(str_replace('\"', '"', $transaction_offer), true);
		}
		if (isset($transaction_offer['offer_data'])) {

			foreach ($transaction_offer['offer_data'] as $offer_data) {

				if ($offer_data['status'] == 'SUCCESS') {
					$offer_title = $offer_data['offer_title'];
					$discount = $offer_data['discount'];
					if ($offer_data['offer_type'] != 'CASHBACK') {
						$this->wcUpdateOrderAddDiscount($order, $offer_title, $discount);
					}
					$offer_key = $offer_data['offer_key'];
					$offer_type = $offer_data['offer_type'];
					$order->update_meta_data('payu_offer_key', $offer_key);
					$order->update_meta_data('payu_offer_type', $offer_type);
				}
			}
		}
	}

	private function wcUpdateOrderAddDiscount($order, $title, $amount)
	{
		$subtotal = $order->get_subtotal();
		$optional_fee_exists = false;
		foreach ($order->get_fees() as $item_fee) {
			$fee_name = $item_fee->get_name();
			if ($fee_name == $title) {
				$optional_fee_exists = true;
			}
		}
		if (!$optional_fee_exists) {
			$item     = new WC_Order_Item_Fee();

			if (strpos($amount, '%') !== false) {
				$percentage = (float) str_replace(array('%', ' '), array('', ''), $amount);
				$percentage = $percentage > 100 ? -100 : -$percentage;
				$discount   = $percentage * $subtotal / 100;
			} else {
				$discount = (float) str_replace(' ', '', $amount);
				$discount = $discount > $subtotal ? -$subtotal : -$discount;
			}

			$item->set_name($title);
			$item->set_total_tax(0);
			$item->set_tax_class(false);
			$item->set_tax_status('none');
			$item->set_taxes(false);
			$item->set_amount($discount);
			$item->set_total($discount);


			$item->save();
			$item_id = $item->get_id();
			$order->update_meta_data('payu_discount_item_id', $item_id);
			$order->add_item($item);
			$order->calculate_totals(false);
			$order->save();
		}
	}

	// Adding Meta container admin shop_order pages
	public function verifyPayment($order, $txnid, $payu_key, $payu_salt, $bypass = false)
	{
		$verify_flag = false;
		if ($bypass) {
			$verify_flag = true;
		}

		try {
			$url = ($this->gatewayModule == 'sandbox') ?
				PAYU_POSTSERVICE_FORM_2_URL_UAT :
				PAYU_POSTSERVICE_FORM_2_URL_PRODUCTION;
			$response = $this->sendVerificationRequest($url, $payu_key, $txnid, $payu_salt);

			if (!$response || !isset($response['body'])) {
				$verify_flag = false;
			}

			$res = json_decode(sanitize_text_field($response['body']), true);
			if (!isset($res['status'])) {
				$verify_flag = false;
			}

			$transaction_details = $res['transaction_details'][$txnid] ?? null;
			if (!$transaction_details) {
				$verify_flag = false;
			}

			// reconcile offer data
			$transaction_offer = json_decode($transaction_details['transactionOffer']);
			$this->reconcileOfferData($transaction_offer, $order);
			$verify_flag = strtolower($transaction_details['status']) == 'success';
		} catch (Exception $e) {
			$verify_flag = false;
		}
		return $verify_flag;
	}

	private function sendVerificationRequest($url, $payu_key, $txnid, $payu_salt)
	{
		$fields = [
			'key' => sanitize_key($payu_key),
			'command' => 'verify_payment',
			'var1' => $txnid,
			'hash' => ''
		];
		$hash = hash("sha512", $fields['key'] . '|' . $fields['command'] . '|' . $fields['var1'] . '|' . $payu_salt);
		$fields['hash'] = sanitize_text_field($hash);
		$args = [
			'body' => $fields,
			'timeout' => 5,
			'redirection' => 5,
			'httpversion' => '1.1',
			'blocking' => true,
			'headers' => [
				'Content-Type' => 'application/x-www-form-urlencoded',
				'accept' => 'application/json'
			]
		];
		$response = wp_remote_post($url, $args);
		$response_code = wp_remote_retrieve_response_code($response);
		$headerResult = wp_remote_retrieve_headers($response);
		$args_log = array(
			'request_type' => 'outgoing',
			'method' => 'post',
			'url' => $url,
			'request_headers' => $args['headers'],
			'request_data' => $fields,
			'status' => $response_code,
			'response_headers' => $headerResult,
			'response_data' => 'null'
		);
		if (!isset($response['body'])) {

			payu_insert_event_logs($args_log);
			return false;
		} else {
			$res = json_decode(sanitize_text_field($response['body']), true);
			$args_log['response_data'] = $res;
			payu_insert_event_logs($args_log);
		}
		return $response;
	}


	private function update_shipping_method($order, $new_method_id)
	{
		// Array for tax calculations
		$calculate_tax_for = array(
			'country'  => $order->get_shipping_country(),
			'state'    => $order->get_shipping_state(), // (optional value)
			'postcode' => $order->get_shipping_postcode(), // (optional value)
			'city'     => $order->get_shipping_city(), // (optional value)
		);

		// Loop through order shipping items
		foreach ($order->get_items('shipping') as $item) {

				// Retrieve the customer shipping zone
				$shipping_zone = WC_Shipping_Zones::get_zone_by('instance_id', $item->get_instance_id());

				// Get an array of available shipping methods for the current shipping zone
				$shipping_methods = $shipping_zone->get_shipping_methods();

				// Loop through available shipping methods
				foreach ($shipping_methods as $shipping_method) {
					// Targeting specific shipping method
					if ($shipping_method->is_enabled() && $shipping_method->id === $new_method_id) {

						// Set an existing shipping method for customer zone
						$item->set_method_title($shipping_method->get_title());
						$item->set_method_id($shipping_method->get_rate_id()); // set an existing Shipping method rate ID
						$item->set_total($shipping_method->cost);

						$item->calculate_taxes($calculate_tax_for);
						$item->save();
						break; // stop the loop
					}
				}
			
		}


		// Calculate totals and save
		$order->calculate_totals(); // the save() method is included

	}
}
