<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Gateway class
 */
class WC_Payubiz extends WC_Payment_Gateway
{
	protected $msg = array();

	protected $logger;

	protected $checkout_express;

	protected $gateway_module;

	protected $redirect_page_id;

	protected $currency1;

	protected $currency1_payu_key;

	protected $currency1_payu_salt;

	protected $bypass_verify_payment;

	protected $site_url;



	public function __construct()
	{
		global $wpdb;
		// Go wild in here	  
		$this->id = 'payubiz';
		$this->method_title = __('PayUBiz', 'payubiz');
		$this->icon = plugins_url('images/payubizlogo.png', dirname(__FILE__));
		$this->has_fields = false;
		$this->init_form_fields();
		$this->init_settings();
		$this->title = 'PayUBiz'; //$this -> settings['title'];
		$this->supports             = array('products', 'refunds');
		$this->description = sanitize_text_field($this->settings['description']);
		$this->checkout_express = sanitize_text_field($this->settings['checkout_express']);
		$this->gateway_module = sanitize_text_field($this->settings['gateway_module']);
		$this->redirect_page_id = sanitize_text_field($this->settings['redirect_page_id']);

		$this->currency1 = sanitize_text_field($this->settings['currency1']);
		$this->currency1_payu_key = sanitize_text_field($this->settings['currency1_payu_key']);
		$this->currency1_payu_salt = sanitize_text_field($this->settings['currency1_payu_salt']);

		$this->bypass_verify_payment = false;
		$this->site_url = get_site_url();

		if (sanitize_text_field($this->settings['verify_payment']) != "yes")
			$this->bypass_verify_payment = true;

		$this->msg['message'] = "";
		$this->msg['class'] = "";


		add_action('init', array(&$this, 'check_payubiz_response'));
		add_action('wp_head', array(&$this, 'payu_scripts'));
		//update for woocommerce >2.0
		add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_payubiz_response'));

		add_action('valid-payubiz-request', array(&$this, 'SUCCESS'));
		add_action('woocommerce_receipt_payubiz', array(&$this, 'receipt_page'));
		//add_action('woocommerce_thankyou_payubiz',array($this, 'thankyou')); 


		if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
		} else {
			add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
		}

		add_filter('woocommerce_get_order_item_totals', array(&$this, 'add_custom_order_total_row'), 10, 2);

		if ($this->checkout_express == 'checkout_express') {
			add_filter('woocommerce_coupons_enabled', array($this, 'disable_coupon_field_on_checkout'));
		}



		$this->logger = wc_get_logger();
	}

	/**
	 * Session patch CSRF Samesite=None; Secure
	 **/
	function manage_session()
	{
		$context = array('source' => $this->id);
		try {
			if (PHP_VERSION_ID >= 70300) {
				$options = session_get_cookie_params();
				$options['samesite'] = 'None';
				$options['secure'] = true;
				unset($options['lifetime']);
				$cookies = $_COOKIE;
				foreach ($cookies as $key => $value) {
					if (!preg_match('/cart/', sanitize_key($key)))
						setcookie(sanitize_key($key), sanitize_text_field($value), $options);
				}
			} else {
				$this->logger->error("PayU payment plugin does not support this PHP version for cookie management. 
				Required PHP v7.3 or higher.", $context);
			}
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), $context);
		}
	}


	function init_form_fields()
	{
		$this->form_fields = require dirname(__FILE__) . '/admin/payu-admin-settings.php';
	}

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 **/
	public function admin_options()
	{
		echo '<h3>' . esc_html__('PayUBiz payment', 'payubiz') . '</h3>';
		echo '<p>' . esc_html__('PayUBiz most popular payment gateways for online shopping.', 'payubiz') . '</p>';
		if (PHP_VERSION_ID < 70300)
			echo "<h1 style=\"color:red;\">" . esc_html__('**Notice: PayU payment plugin requires PHP v7.3 or higher.<br />
		  Plugin will not work properly below PHP v7.3 due to SameSite cookie restriction.', 'payubiz') . "</h1>";
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	/**
	 *  There are no payment fields for Citrus, but we want to show the description if set.
	 **/
	function payment_fields()
	{
		if ($this->description) echo wpautop(wptexturize($this->description));
	}

	/**
	 * Receipt Page
	 **/
	public function receipt_page($order)
	{
		$this->manage_session(); //Update cookies with samesite 
		echo '<p>' . esc_html__('Thank you for your order, please wait as you will be automatically redirected to PayUBiz.', 'payubiz') . '</p>';
		echo $this->generate_payubiz_form($order);
	}

	/**
	 * Process the payment and return the result
	 **/

	public function payment_scripts()
	{

		// we need JavaScript to process a token only on cart/checkout pages, right?
		if (!is_cart() && !is_checkout() && !isset($_GET['pay_for_order'])) {
			return;
		}

		// if our payment gateway is disabled, we do not have to enqueue JS too
		if ('no' === $this->enabled) {
			return;
		}

		// no reason to enqueue JavaScript if API keys are not set
		if (empty($this->currency1_payu_key) || empty($this->currency1_payu_salt)) {
			return;
		}

		// do not work with card detailes without SSL unless your website is in a test mode
		// if( ! $this->testmode && ! is_ssl() ) {
		// 	return;
		// }


	}
	public function process_payment($order_id)
	{
		$order = new WC_Order($order_id);

		if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
			return array(
				'result' => 'success',
				'redirect' => add_query_arg(
					'order',
					$order->id,
					add_query_arg('key', $order->get_order_key(), $order->get_checkout_payment_url(true))
				)
			);
		} else {
			return array(
				'result' => 'success',
				'redirect' => add_query_arg(
					'order',
					$order->id,
					add_query_arg('key', $order->get_order_key(), get_permalink(get_option('woocommerce_pay_page_id')))
				)
			);
		}
	}


	/**
	 * Check for valid PayU server callback
	 **/
	public function check_payubiz_response()
	{
		global $woocommerce, $wpdb;
		$submit_form = false;
		if (isset($_GET['wc-api'])) {
			if (sanitize_text_field($_GET['wc-api']) == get_class($this)) {
				$postdata = array();
				//sanitize entire response

				if (isset($_POST['payu_resp'])) {
					$payu_request = json_decode(stripslashes($_POST['payu_request']), true);
					$_POST = json_decode(stripslashes($_POST['payu_resp']), true);
					error_log("call by submit form");
					$submit_form = true;
				}
				if (!$submit_form) {
					error_log("call by payu");
				}

				if (!$_POST) {
					if ('sandbox' == $this->gateway_module) {
						$bolt_url = MERCHANT_HOSTED_PAYMENT_JS_LINK_UAT;
					} else {
						$bolt_url = MERCHANT_HOSTED_PAYMENT_JS_LINK_PRODUCTION;
					}
					payu_insert_event_logs('outgoing', 'post', $bolt_url, 'null', $payu_request, 200, $_POST, 'null');
				}

				foreach ($_POST as $key => $val) {
					if ($key == 'transaction_offer' || $key == 'cart_details' || $key == 'shipping_address') {
						$postdata[$key] = $val;
					} else {
						$postdata[$key] = sanitize_text_field($val);
					}
				}
				$order = $this->payment_validation_and_updation($postdata);


				//manage msessages
				if (function_exists('wc_add_notice')) {
					wc_clear_notices();
					if ($this->msg['class'] != 'success') {
						wc_add_notice($this->msg['message'], $this->msg['class']);
					}
				} else {
					if ($this->msg['class'] != 'success') {
						$woocommerce->add_error($this->msg['message']);
					} else {
						//$woocommerce->add_message($this->msg['message']);
					}
					$woocommerce->set_messages();
				}

				$redirect_url = ($this->redirect_page_id == '' || $this->redirect_page_id == 0) ? get_site_url() . '/' : get_permalink($this->redirect_page_id);
				if ($order && $this->msg['class'] == 'success')
					$redirect_url = $order->get_checkout_order_received_url();

				//For wooCoomerce 2.0
				//$redirect_url = add_query_arg( array('msg'=> urlencode($this -> msg['message']), 'type'=>$this -> msg['class']), $redirect_url );
				//$wpdb->insert('wp_insert_payu_response', array('response'=>$redirect_url));
				wp_redirect($redirect_url);
				exit;
			}
		}
	}

	// Adding Meta container admin shop_order pages
	public function verify_payment($order, $txnid, $payu_key, $payu_salt, $bypass = false)
	{
		global $woocommerce, $wpdb;
		if ($bypass) return true; //bypass verification

		try {
			$datepaid = $order->get_date_paid();
			$fields = array(
				'key' => sanitize_key($payu_key),
				'command' => 'verify_payment',
				'var1' => $txnid,
				'hash' => ''
			);
			# Change this code to not log user sensitive data - 'salt'
			error_log("hash data " . $fields['key'] . '|' . $fields['command'] . '|' . $fields['var1'] . '|' . $payu_salt);
			# Change this code to not log user sensitive data - 'salt'
			$hash = hash("sha512", $fields['key'] . '|' . $fields['command'] . '|' . $fields['var1'] . '|' . $payu_salt);
			$fields['hash'] = sanitize_text_field($hash);
			error_log('mode =' . $this->gateway_module);
			//$fields_string = http_build_query($fields);
			$url = esc_url(PAYU_POSTSERVICE_FORM_2_URL_PRODUCTION);
			if ($this->gateway_module == 'sandbox')
				$url = esc_url(PAYU_POSTSERVICE_FORM_2_URL_UAT);

			$args = array(
				'body' => $fields,
				'timeout' => '5',
				'redirection' => '5',
				'httpversion' => '1.1',
				'blocking'    => true,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'accept' => 'application/json'
				)
			);
			error_log(serialize($args));
			$response = wp_remote_post($url, $args);
			$response_code = wp_remote_retrieve_response_code($response);
			$headerResult = wp_remote_retrieve_headers($response);
			error_log('headerResult ' . json_encode($headerResult));
			$cookies = wp_remote_retrieve_header($response, 'set-cookie');
			error_log('cookies ' . serialize($cookies));
			error_log($response['body']);
			//$wpdb->insert('wp_insert_payu_response', array('response'=>serialize($args)));
			//$wpdb->insert('wp_insert_payu_response', array('response'=>serialize($response['body'])));
			if (!isset($response['body'])) {
				payu_insert_event_logs('outgoing', 'post', $url, $args['headers'], $fields, $response_code, $headerResult, 'null');
				return false;
			} else {
				$res = json_decode(sanitize_text_field($response['body']), true);
				payu_insert_event_logs('outgoing', 'post', $url, $args['headers'], $fields, $response_code, $headerResult, $res);
				if (!isset($res['status']))
					return false;
				else {
					$res = $res['transaction_details'];
					$res = $res[$txnid];

					// reconcile offer data
					$transaction_offer = json_decode($res['transactionOffer']);
					if (!is_array($transaction_offer)) {
						error_log("verify offer " . str_replace('\"', '"', $transaction_offer));
						$transaction_offer = json_decode(str_replace('\"', '"', $transaction_offer), true);
					}
					if (isset($transaction_offer['offer_data'])) {

						foreach ($transaction_offer['offer_data'] as $offer_data) {

							if ($offer_data['status'] == 'SUCCESS') {
								$offer_title = $offer_data['offer_title'];
								$discount = $offer_data['discount'];
								$this->wc_update_order_add_discount($order, $offer_title, $discount);
								$offer_key = $offer_data['offer_key'];
								$offer_type = $offer_data['offer_type'];
								$order->update_meta_data('payu_offer_key', $offer_key);
								$order->update_meta_data('payu_offer_type', $offer_type);
							}
						}
					}
					if (sanitize_text_field($res['status']) == 'success')
						return true;
					elseif (sanitize_text_field($res['status']) == 'pending' || sanitize_text_field($res['status']) == 'failure')
						return false;
				}
			}
		} catch (Exception $e) {
			return false;
		}
	}


	/*
     //Removed For WooCommerce 2.0
    function showMessage($content){
         return '<div class="box '.$this -> msg['class'].'-box">'.$this -> msg['message'].'</div>'.$content;
     }*/

	/**
	 * Generate PayUBiz button link
	 **/
	public function generate_payubiz_form($order_id)
	{

		global $woocommerce;
		$payu_key = "";
		$payu_salt = "";
		$sku_details_array = array();
		$logo = 'https://payu.in/demo/checkoutExpress/utils/images/MicrosoftTeams-image%20(31).png';
		$order = new WC_Order($order_id);
		$wc_order_id = $order_id;
		$session_cookie_data = WC()->session->get_session_cookie();
		$udf4 = $session_cookie_data[0];
		$order->update_meta_data('udf4', $udf4);
		$order_currency = sanitize_text_field($order->get_currency());
		$payu_key = $this->currency1_payu_key;
		$payu_salt = $this->currency1_payu_salt;
		$site_url = get_site_url();
		$cart_url = wc_get_cart_url();

		$redirect_url = ($this->redirect_page_id == "" || $this->redirect_page_id == 0) ? get_site_url() . "/" : get_permalink($this->redirect_page_id);
		//For wooCoomerce 2.0
		$redirect_url = add_query_arg('wc-api', get_class($this), $redirect_url);
		WC()->session->set('orderid_awaiting_payubiz', $order_id);
		$txnid = $order_id . '_' . date("ymd") . ':' . rand(1, 100);
		update_post_meta($order_id, 'order_txnid', $txnid);
		$order->set_shipping_total(0);

		$order->calculate_totals();
		//do we have a phone number?
		//get currency
		$address = sanitize_text_field($order->billing_address_1);
		if ($order->billing_address_2 != "")
			$address = $address . ' ' . sanitize_text_field($order->billing_address_2);

		$productInfo = '';
		foreach ($order->get_items() as $item) {
			$product = wc_get_product($item->get_product_id());
			$productInfo .= $product->get_sku() . ':';
			$sku_details_array[] = array('offer_key' => array(), 'amount_per_sku' => (string)number_format($product->get_price(), 2), 'quantity' => (string)$item->get_quantity(), 'sku_id' => $product->get_sku(), 'sku_name' => $product->get_name(), 'logo' => $logo);
		}

		$item_count = $order->get_item_count();

		$productInfo = rtrim($productInfo, ':');
		if ('' == $productInfo)
			$productInfo = "Product Information";
		elseif (100 < strlen($productInfo))
			$productInfo = substr($productInfo, 0, 100);

		$action = esc_url(PAYU_HOSTED_PAYMENT_URL_PRODUCTION);

		if ('sandbox' == $this->gateway_module)
			$action = esc_url(PAYU_HOSTED_PAYMENT_URL_UAT);

		$amount = sanitize_text_field($order->order_total);
		$firstname = sanitize_text_field($order->billing_first_name);
		$lastname = sanitize_text_field($order->billing_last_name);
		$zipcode = sanitize_text_field($order->billing_postcode);
		$edit_email_allowed = true;
		if (is_user_logged_in()) $edit_email_allowed = false;

		$user_id = get_current_user_id();
		if ($user_id && $this->checkout_express == 'checkout_express') {
			$current_user_data = get_userdata($user_id);
			$user_email = $current_user_data->user_email;
			$payu_phone = get_user_meta($user_id, 'payu_phone', true);
		}
		if ($this->checkout_express == 'checkout_express') {
			allow_to_checkout_from_cart('revert');
		}

		$email_required = true;
		$guest_checkout_enabled = get_option('woocommerce_enable_guest_checkout');
		if ($guest_checkout_enabled == 'yes') $email_required = false;

		$email = $user_email ? $user_email : ($order->billing_email ? sanitize_email($order->billing_email) : 'yashurj26@gmail.com');
		$phone = $payu_phone ? $payu_phone : sanitize_text_field($order->billing_phone);
		$state = sanitize_text_field($order->billing_state);
		$city = sanitize_text_field($order->billing_city);
		$country = sanitize_text_field($order->billing_country);
		$Pg = '';
		$udf5 = 'WooCommerce';
		$hash = $this->generate_hash_token($txnid, $amount, $productInfo, $firstname, $email, $udf4, $udf5, $payu_salt);
		$shipping_total = $order->get_shipping_total();
		$shipping_tax   = $order->get_shipping_tax();
		$order_subtotal = $order->get_subtotal();
		$cod_fee = apply_filters('payu_express_checkout_cod_fee', 0);
		$other_changes = apply_filters('payu_express_checkout_other_charges', 0);
		$tax_vat = apply_filters('payu_express_checkout_tax_vat', 0);
		$payu_payment_nonce = wp_nonce_field('payu_payment_nonce', 'payu_payment_nonce', true, false);
		if ($this->checkout_express == 'redirect') {
			$html = '<form action="' . $action . '" method="post" id="payu_form" name="payu_form">
				' . $payu_payment_nonce . '
				<input type="hidden" name="key" value="' . $payu_key . '" />
				<input type="hidden" name="txnid" value="' . $txnid . '" />
				<input type="hidden" name="amount" value="' . $amount . '" />
				<input type="hidden" name="productinfo" value="' . $productInfo . '" />
				<input type="hidden" name="firstname" value="' . $firstname . '" />
				<input type="hidden" name="Lastname" value="' . $lastname . '" />
				<input type="hidden" name="Zipcode" value="' . $zipcode . '" />
				<input type="hidden" name="email" value="' . $email . '" />
				<input type="hidden" name="phone" value="' . $phone . '" />
				<input type="hidden" name="surl" value="' . esc_url($redirect_url) . '" />
				<input type="hidden" name="furl" value="' . esc_url($redirect_url) . '" />
				<input type="hidden" name="curl" value="' . esc_url($redirect_url) . '" />
				<input type="hidden" name="Hash" value="' . $hash . '" />
				<input type="hidden" name="Pg" value="' . $Pg . '" />						
				<input type="hidden" name="address1" value="' . $address . '" />
		        <input type="hidden" name="address2" value="" />
			    <input type="hidden" name="city" value="' . $city . '" />
		        <input type="hidden" name="country" value="' . $country . '" />
		        <input type="hidden" name="state" value="' . $state . '" />
				<input type="hidden" name="udf3" value="" />
				<input type="hidden" name="udf4" value="' . $udf4 . '" />
				<input type="hidden" name="udf5" value="' . $udf5 . '" />
		        <button style="display:none" id="submit_payubiz_payment_form" name="submit_payubiz_payment_form">Pay Now</button>
				</form>
				<script type="text/javascript">document.getElementById("payu_form").submit();</script>';
		} else if ($this->checkout_express == 'checkout_express') {
			$ramdom_str = substr(str_shuffle(md5(microtime())), 0, 5);
			$c_date = gmdate('D, d M Y H:i:s T');

			$receive_page_url = $order->get_checkout_order_received_url();
			$data_array = array(
				'key' => $payu_key,
				'hash' => $hash,
				'txnid' => $txnid,
				'amount' => $amount,
				'phone' => $phone,
				'firstname' => $firstname,
				'lastname' => $lastname,
				'email' => $email,
				'udf1' => '',
				'udf2' => '',
				'udf3' => '',
				'udf4' => $udf4,
				'udf5' => $udf5,
				'drop_category' => '',
				'enforce_paymethod' => '',
				'isCheckoutExpress' => true,
				'icp_source' => 'express',
				'platform' => 'WooCommerce',
				'productinfo' => $productInfo,
				'email_required' => $email_required,
				'edit_email_allowed' => $edit_email_allowed,
				'edit_phone_allowed' => true,
				'surl' => $site_url,
				'furl' => $site_url,
				'orderid' => $ramdom_str,
				'extra_charges' => array(
					'totalAmount' => $amount, // this amount adding extra charges + cart Amount
					'shipping_charges' => $shipping_total, // static shipping charges
					'cod_fee' => $cod_fee, // cash on delivery fee.
					'other_charges' => $other_changes,
					'tax_info' => array(
						'breakup' => array(
							'GST' => $shipping_tax,
							'VAT' => $tax_vat
						),
						'total' => ($shipping_tax + $tax_vat),
					),
				),
				'cart_details' => array(
					'amount' => $order_subtotal,
					'items' => (string)$item_count,
					'sku_details' => $sku_details_array,
				)

			);
			$data_array_json = json_encode($data_array, JSON_UNESCAPED_SLASHES);
			$v2hash = hash('sha512', $data_array_json . "|" . $c_date . "|" . $payu_salt);
			$username = "hmac username=\"$payu_key\"";
			$algorithm = "algorithm=\"sha512\"";
			$headers = "headers=\"$c_date\"";
			$signature = "signature=\"$v2hash\"";

			// Concatenating the parts together
			$auth_header_string = $username . ', ' . $algorithm . ', ' . $headers . ', ' . $signature;

			$html = "<form method='post' action='$redirect_url' id='payu_express_checkout_form'>
			<input type='hidden' name='payu_resp'>$payu_payment_nonce
			<input type='hidden' name='payu_request'>
			</form>
			";

			$html .= "<script>
			function handleSubmit() {
				const  expressRequestObj = {
					data: '$data_array_json',
					date: '$c_date',
					isCheckoutExpress: true,
					v2Hash: '$auth_header_string'
				}
				var handlers = {
					responseHandler: function (BOLT) {
						console.log(BOLT.response);
							if (BOLT.response.txnStatus == 'FAILED') {
							alert('Payment failed. Please try again.');
							}
							if(BOLT.response.txnStatus == 'CANCEL'){
							alert('Payment cancelled. Please try again.');
							}
							var payu_frm = document.getElementById('payu_express_checkout_form');
							payu_frm.elements.namedItem('payu_resp').value = JSON.stringify(BOLT.response);
							payu_frm.elements.namedItem('payu_request').value = JSON.stringify(expressRequestObj);
							payu_frm.submit();
						},
					catchException: function (BOLT) {
						console.log(BOLT);
						if(typeof BOLT.message !== 'undefined')
						{
							alert('Payment failed. Please try again. (' + BOLT.message +')');
						}
						window.location = '$cart_url';
				}};
				bolt.launch( expressRequestObj , handlers);
			}
			handleSubmit();
			</script>";
		} else {
			$html = "<form method='post' action='$redirect_url' id='payu_bolt_form'>
			<input type='hidden' name='payu_resp'>
			</form>
			";
			$requestArr = [
				'key' => $payu_key,
				'Hash' => $hash,
				'txnid' => $txnid,
				'amount' => $amount,
				'firstname' => $firstname,
				'Lastname' => $lastname,
				'email' => $email,
				'phone' => $phone,
				'productinfo' => $productInfo,
				'udf4' => $udf4,
				'udf5' => $udf5,
				'surl' => $site_url,
				'furl' => $site_url,
				'enforce_paymethod' => 'creditcard|debitcard|UPI|cashcard|SODEXO|qr|emi|neftrtgs|HDFB|AXIB'
			];
?>
			<script type='text/javascript'>
				function boltSubmit() {
					var data = <?php echo json_encode($requestArr, JSON_UNESCAPED_SLASHES); ?>;
					var handlers = {
						responseHandler: function(BOLT) {
							if (BOLT.response.txnStatus == "FAILED") {
								console.log('Payment failed. Please try again.');
							}
							if (BOLT.response.txnStatus == "CANCEL") {
								console.log('Payment failed. Please try again.');
							}
							var payu_frm = document.getElementById('payu_bolt_form');
							payu_frm.elements.namedItem('payu_resp').value = JSON.stringify(BOLT.response);
							payu_frm.submit();
						},
						catchException: function(BOLT) {
							console.log('Payment failed. Please try again.');
						}
					};
					bolt.launch(data, handlers);
					//return false;
				}
				boltSubmit();
			</script>";
<?php }


		return $html;
	}


	public function payu_scripts()
	{

		if ('sandbox' == $this->gateway_module) {
			echo '<script src="' . MERCHANT_HOSTED_PAYMENT_JS_LINK_UAT . '"></script>';
		} else {
			echo '<script src="' . MERCHANT_HOSTED_PAYMENT_JS_LINK_PRODUCTION . '"></script>';
		}
	}

	private function generate_hash_token($txnid, $amount, $productInfo, $firstname, $email, $udf4, $udf5, $payu_salt)
	{
		$payu_key = $this->currency1_payu_key;
		$payu_salt = $this->currency1_payu_salt;
		return hash('sha512', $payu_key . '|' . $txnid . '|' . $amount . '|' . $productInfo . '|' . $firstname . '|' . $email . '||||' . $udf4 . '|' . $udf5 . '||||||' . $payu_salt);
	}

	public function payu_transaction_data_insert($postdata, $order_id)
	{
		global $table_prefix, $wpdb;
		$tblname = 'payu_transactions';
		$wp_payu_table = $table_prefix . "$tblname";
		$check_order_id = $wpdb->get_var("select order_id from $wp_payu_table where order_id = $order_id");
		if (!$check_order_id) {
			$transaction_id = $postdata['mihpayid'];
			$status = $postdata['status'];
			$response_data_serialize = serialize($postdata);
			// $transection_exist = $wpdb->get_row("select payu_response from $wp_payu_table where order_id = '$order_id'");
			// if($transection_exist){
			// return unserialize($transection_exist->payu_response);
			// }
			$data = array('transaction_id' => $transaction_id, 'order_id' => $order_id, 'payu_response' => $response_data_serialize, 'status' => $status);
			if ($wpdb->insert($wp_payu_table, $data)) {
				return $postdata;
			} else {
				return false;
			}
		} else {
			return true;
		}
	}

	public function wc_update_order_add_discount($order, $title, $amount, $tax_class = '')
	{
		error_log("discount added = " . $title . ' :' . $amount);
		$subtotal = $order->get_subtotal();
		$optional_fee_exists = false;
		foreach ($order->get_fees() as $item_fee) {
			$fee_name = $item_fee->get_name();
			error_log('verify offer name ' . $fee_name);
			error_log('verify offer title' . $title);
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

			$item->set_tax_class($tax_class);
			$item->set_name($title);
			$item->set_amount($discount);
			$item->set_total($discount);

			if ('0' !== $item->get_tax_class() && 'taxable' === $item->get_tax_status() && wc_tax_enabled()) {
				$tax_for   = array(
					'country'   => $order->get_shipping_country(),
					'state'     => $order->get_shipping_state(),
					'postcode'  => $order->get_shipping_postcode(),
					'city'      => $order->get_shipping_city(),
					'tax_class' => $item->get_tax_class(),
				);
				$tax_rates = WC_Tax::find_rates($tax_for);
				$taxes     = WC_Tax::calc_tax($item->get_total(), $tax_rates, false);
				// print_pr($taxes);

				if (method_exists($item, 'get_subtotal')) {
					$subtotal_taxes = WC_Tax::calc_tax($item->get_subtotal(), $tax_rates, false);
					$item->set_taxes(array('total' => $taxes, 'subtotal' => $subtotal_taxes));
					$item->set_total_tax(array_sum($taxes));
				} else {
					$item->set_taxes(array('total' => $taxes));
					$item->set_total_tax(array_sum($taxes));
				}
				$has_taxes = true;
			} else {
				$item->set_taxes(false);
				$has_taxes = false;
			}
			$item->save();
			$item_id = $item->get_id();
			$order->update_meta_data('payu_discount_item_id', $item_id);
			$order->add_item($item);
			$order->calculate_totals($has_taxes);
			$order->save();
		}
	}

	public function register_refund_in_progress_order_status()
	{
		register_post_status('wc-refund-in-progress', array(
			'label'                     => 'Refund In-Progress',
			'public'                    => true,
			'show_in_admin_status_list' => true,
			'show_in_admin_all_list'    => true,
			'exclude_from_search'       => false,
			'label_count'               => _n_noop('Refund In-Progress (%s)', 'Refund In-Progress (%s)')
		));
	}
	// Add custom status to order status list
	public function add_refund_in_progress_to_order_statuses($order_statuses)
	{
		$new_order_statuses = array();
		foreach ($order_statuses as $key => $status) {
			$new_order_statuses[$key] = $status;
			if ('wc-on-hold' === $key) {
				$new_order_statuses['wc-refund-in-progress'] = 'Refund In-Progress';
			}
		}
		return $new_order_statuses;
	}


	public function add_custom_order_total_row($total_rows, $order)
	{
		if ($total_rows['payment_method']['value'] == 'PayUBiz') {
			$payment_mode['payment_mode'] = array(
				'label' => __('Payment Mode', 'your-text-domain'),
				'value' => $order->get_meta('payu_mode'),
			);
			$payment_mode['payment_bank_code'] = array(
				'label' => __('Bank Code', 'your-text-domain'),
				'value' => $order->get_meta('payu_bankcode'),
			);

			$payu_offer_key = $order->get_meta('payu_offer_key');
			if ($payu_offer_key) {
				$payment_mode['payment_offer_key'] = array(
					'label' => __('Offer Key', 'your-text-domain'),
					'value' => $payu_offer_key,
				);
			}

			$payu_offer_type = $order->get_meta('payu_offer_type');
			if ($payu_offer_type) {
				$payment_mode['payment_offer_type'] = array(
					'label' => __('Offer Type', 'your-text-domain'),
					'value' => $payu_offer_type,
				);
			}

			$this->payment_array_insert($total_rows, 'payment_method', $payment_mode);
		}
		return $total_rows;
	}

	private function payment_array_insert(&$array, $position, $insert)
	{
		if (is_int($position)) {
			array_splice($array, $position, 0, $insert);
		} else {
			$pos   = array_search($position, array_keys($array));
			$array = array_merge(
				array_slice($array, 0, $pos),
				$insert,
				array_slice($array, $pos)
			);
		}
	}

	public function process_refund($order_id, $amount = null, $reason = '')
	{
		$refund = new Payu_Refund_Process(false);
		global $refund_args;
		$order = new WC_Order($order_id);
		$refund_type = 'partial';
		if ($order->get_total() == $amount) {
			$refund_type = 'full';
		}
		$refund_result = $refund->process_custom_refund_backend($order, $amount);
		error_log('refund call : ' . serialize($refund_result));

		error_log('amount :' . $amount);
		if ($refund_result && $refund_result['status'] == 1) {
			$refund->payu_refund_data_insert($refund_result, $order_id, $refund_type, $refund_args);
			return true;
		}
		return false;
	}

	public function payment_validation_and_updation($postdata, $bypass_verify_payment = false)
	{

		if (isset($postdata['key'])) {
			$this->bypass_verify_payment = $bypass_verify_payment;
			global $woocommerce, $wpdb;
			$payu_key = $postdata['key'];
			$payu_salt = $this->currency1_payu_salt;

			$txnid = $postdata['txnid'];
			$order_id = explode('_', $txnid);
			$order_id = (int)$order_id[0];    //get rid of time part
			$this->payu_transaction_data_insert($postdata, $order_id);
			$order = new WC_Order($order_id);
			$total_discount = $postdata['discount'];
			$order->update_meta_data('payu_bankcode', $postdata['bankcode']);
			$order->update_meta_data('payu_mode', $postdata['mode']);
			$udf4 = $order->get_meta('udf4');
			$order_currency = sanitize_text_field($order->get_currency());
			$transaction_offer = $postdata['transaction_offer'];
			if (!is_array($postdata['transaction_offer'])) {
				$transaction_offer = json_decode(str_replace('\"', '"', $postdata['transaction_offer']), true);
			}

			if (isset($transaction_offer['offer_data'])) {


				foreach ($transaction_offer['offer_data'] as $offer_data) {

					if ($offer_data['status'] == 'SUCCESS') {
						$offer_title = $offer_data['offer_title'];
						$discount = $offer_data['discount'];
						$this->wc_update_order_add_discount($order, $offer_title, $discount);
						$offer_key = $offer_data['offer_key'];
						$offer_type = $offer_data['offer_type'];
						$order->update_meta_data('payu_offer_key', $offer_key);
						$order->update_meta_data('payu_offer_type', $offer_type);
					}
				}
			}

			if ($postdata['key'] == $payu_key) {
				//WC()->session->set('orderid_awaiting_payubiz', '');
				$amount      		= 	$postdata['amount'];
				$productInfo  		= 	$postdata['productinfo'];
				$firstname    		= 	$postdata['firstname'];
				$email        		=	$postdata['email'];
				$phone        		=	$postdata['phone'];
				$udf5				=   $postdata['udf5'];
				$additionalCharges 	= 	0;
				create_user_and_login_if_not_exist($email);
				$user = get_user_by('email', $email);
				if ($user) {
					$user_id = $user->ID;
					$order->set_customer_id($user_id);
					update_user_meta($user_id, 'payu_phone', $phone);
				}
				if (isset($postdata["additionalCharges"])) $additionalCharges = $postdata['additionalCharges'];

				$keyString 	  		=  	$payu_key . '|' . $txnid . '|' . $amount . '|' . $productInfo . '|' . $firstname . '|' . $email . '||||' . $udf4 . '|' . $udf5 . '|||||';
				$keyArray 	  		= 	explode("|", $keyString);
				$reverseKeyArray 	= 	array_reverse($keyArray);
				$reverseKeyString	=	implode("|", $reverseKeyArray);

				if (isset($postdata['shipping_address']) && !empty($postdata['shipping_address'])) {
					$full_name = explode(' ', $postdata['shipping_address']['name']);

					$new_address = array(
						'country' => 'IN',
						'state' => $postdata['shipping_address']['state'],
						'city' => $postdata['shipping_address']['city'],
						'postcode' => $postdata['shipping_address']['pincode'],
						'phone' => $postdata['shipping_address']['addressPhoneNumber'],
						'address_1' => $postdata['shipping_address']['addressLine'],
						'first_name' => isset($full_name[0]) ? $full_name[0] : '',
						'last_name' => isset($full_name[1]) ? $full_name[1] : ''
					);
					error_log("shipping address updated " . $order_id);
					//var_dump($order->set_shipping_address($new_address));
					$order->set_shipping_first_name(isset($full_name[0]) ? $full_name[0] : '');
					//update_post_meta( $order->ID, '_shipping_first_name', isset($full_name[0])?$full_name[0]:'' );
					$order->set_address($new_address, 'shipping');
					$order->set_address($new_address, 'billing');
				}


				if (isset($postdata['status']) && $postdata['status'] == 'success') {

					$saltString     = $payu_salt . '|' . $postdata['status'] . '|' . $reverseKeyString;
					if ($additionalCharges > 0)
						$saltString     = $additionalCharges . '|' . $payu_salt . '|' . $postdata['status'] . '|' . $reverseKeyString;

					$sentHashString = strtolower(hash('sha512', $saltString));
					$responseHashString = $postdata['hash'];
					$this->msg['class'] = 'error';
					$this->msg['message'] = esc_html__('Thank you for shopping with us. However, the transaction has been declined.', 'payubiz');
					if ($sentHashString == $responseHashString && $this->verify_payment($order, $txnid, $payu_key, $payu_salt, $this->bypass_verify_payment)) {

						$this->msg['message'] = esc_html__('Thank you for shopping with us. Your account has been charged and your transaction is successful with following order details:', 'payubiz');
						$this->msg['message'] .= '<br>' . esc_html__('Order Id:' . $order_id, 'payubiz') . '<br/>' . esc_html__('Amount:' . $amount, 'payubiz') . '<br />' . esc_html__('We will be shipping your order to you soon.', 'payubiz');

						if ($additionalCharges > 0)
							$this->msg['message'] .= '<br /><br />' . esc_html__('Additional amount charged by PayUBiz - ' . $additionalCharges, 'payubiz');

						$this->msg['class'] = 'success';

						if ($order->status == 'processing' || $order->status == 'completed') {
							//do nothing
						} else {
							//complete the order
							error_log("order marked payment completed order id $order_id");
							$order->payment_complete();
							$order->add_order_note(esc_html__('PayUBiz has processed the payment. Ref Number: ' . $postdata['mihpayid'], 'payubiz'));
							$order->add_order_note($this->msg['message']);
							$order->add_order_note('Paid by PayUBiz');
							$woocommerce->cart->empty_cart();
						}
					} else {
						//tampered
						$this->msg['class'] = 'error';
						$this->msg['message'] = esc_html__('Thank you for shopping with us. However, the payment failed');
						$order->update_status('failed');
						$order->add_order_note('Failed');
						$order->add_order_note($this->msg['message']);
						error_log("order marked failed order id $order_id");
					}
				} else if (isset($postdata['status']) && $postdata['status'] == 'failure') {
					$this->msg['class'] = 'error';
					$this->msg['message'] = esc_html__('Thank you for shopping with us. However, the payment failed (' . $postdata['field9'] . ')');
					$order->update_status('failed');
					$order->add_order_note('Failed');
					$order->add_order_note($this->msg['message']);
					error_log("order marked failed order id $order_id");
				} else {
					$this->msg['class'] = 'error';
					$this->msg['message'] = esc_html__('Thank you for shopping with us. However, the transaction has been declined.', 'payubiz');

					//Here you need to put in the routines for a failed
					//transaction such as sending an email to customer
					//setting database status etc etc			
				}
			}

			return $order;
		} else {
			return false;
		}
	}

	public function disable_coupon_field_on_checkout($enabled)
	{
		return false;
	}
}
