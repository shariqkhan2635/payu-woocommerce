<?php

class Payu_Webhook_Calls extends WC_Payubiz
{

	protected $scope = '';

	protected $currency1_payu_salt;

	public function __construct()
	{

		add_action('rest_api_init', array(&$this, 'get_payment_success_update'));

		add_action('rest_api_init', array(&$this, 'get_payment_failed_update'));

		$plugin_data = get_option('woocommerce_payubiz_settings');
		$this->currency1_payu_salt = sanitize_text_field($plugin_data['currency1_payu_salt']);
	}


	public function get_payment_success_update()
	{
		register_rest_route('payu/v1', '/get-payment-success-update', array(
			'methods' => ['POST'],
			'callback' => array($this, 'payu_get_payment_success_update_callback'),
			'permission_callback' => '__return_true'
		));
	}

	public function payu_get_payment_success_update_callback(WP_REST_Request $request)
	{
		$parameters = $request->get_body();
		error_log("Success payment webhook ran");
		parse_str($parameters, $response_data);
		$this->payu_order_status_update($response_data);
	}

	public function get_payment_failed_update()
	{
		register_rest_route('payu/v1', '/get-payment-failed-update', array(
			'methods' => ['POST'],
			'callback' => array($this, 'payu_get_payment_failed_update_callback'),
			'permission_callback' => '__return_true'
		));
	}

	public function payu_get_payment_failed_update_callback(WP_REST_Request $request)
	{
		$parameters = $request->get_body();
		error_log("Failed payment webhook ran");
		parse_str($parameters, $response_data);
		$this->payu_order_status_update($response_data);
	}

	private function payu_order_status_update($response)
	{
		global $table_prefix, $wpdb;
		$response = json_decode($response, true);
		$order = $this->payment_validation_and_updation($response);
		if ($order) {
			$payu_transactions_tblname = "payu_transactions";
			$payu_id = $response['mihpayid'];
			$wp_track_payu_transactions_tblname = $table_prefix . "$payu_transactions_tblname";
			$wpdb->update($wp_track_payu_transactions_tblname, array('transaction_id' => $payu_id), array('order_id' => $order->ID));
		}
	}
}
new Payu_Webhook_Calls();
