<?php

class PayuWebhookCalls
{

	protected $scope = '';

	protected $currency1_payu_salt;

	public function __construct()
	{

		add_action('rest_api_init', array(&$this, 'getPaymentSuccessUpdate'));

		add_action('rest_api_init', array(&$this, 'getPaymentFailedUpdate'));

		$plugin_data = get_option('woocommerce_payubiz_settings');
		$this->currency1_payu_salt = sanitize_text_field($plugin_data['currency1_payu_salt']);
	}


	public function getPaymentSuccessUpdate()
	{
		register_rest_route('payu/v1', '/get-payment-success-update', array(
			'methods' => ['POST'],
			'callback' => array($this, 'payuGetPaymentSuccessUpdateCallback'),
			'permission_callback' => '__return_true'
		));
	}

	public function payuGetPaymentSuccessUpdateCallback(WP_REST_Request $request)
	{
		$parameters = $request->get_body();
		error_log("Success payment webhook ran");
		parse_str($parameters, $response_data);
		$this->payuOrderStatusUpdate($response_data);
	}

	public function getPaymentFailedUpdate()
	{
		register_rest_route('payu/v1', '/get-payment-failed-update', array(
			'methods' => ['POST'],
			'callback' => array($this, 'payuGetPaymentFailedUpdateCallback'),
			'permission_callback' => '__return_true'
		));
	}

	public function payuGetPaymentFailedUpdateCallback(WP_REST_Request $request)
	{
		$parameters = $request->get_body();
		error_log("Failed payment webhook ran");
		parse_str($parameters, $response_data);
		$this->payuOrderStatusUpdate($response_data);
	}

	private function payuOrderStatusUpdate($response)
	{
		global $table_prefix, $wpdb;
		if ($response) {
			$response = json_decode($response, true);
			$payuPaymentValidation = new PayuPaymentValidation();
			$order = $payuPaymentValidation->payuPaymentValidationAndRedirect($response);
			if ($order) {
				$payu_transactions_tblname = "payu_transactions";
				$payu_id = $response['mihpayid'];
				$wp_track_payu_transactions_tblname = $table_prefix . "$payu_transactions_tblname";
				$wpdb->update(
					$wp_track_payu_transactions_tblname,
					array(
						'transaction_id' => $payu_id
					),
					array(
						'order_id' => $order->ID
					)
				);
			}
		}
	}
}
$payu_webhook_calls = new PayuWebhookCalls();
