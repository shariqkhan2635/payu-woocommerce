<?php

class Payu_Register_Webhooks extends Payu_Payment_Gateway_API
{

    protected $scope = '';

    public function __construct()
    {
    }

    public function register_webhook()
    {

        $site_url = get_site_url() . '/wp-json';
        $register_refund_webhook = $site_url . '/payu/v1/refund-status-update';
        $api_url = 'https://uatoneapi.payu.in/payout/v2/webhook';
        $reseller_uuid = wp_generate_uuid4();

        $result = $this->payu_register_webhook_api($register_refund_webhook, $api_url, $reseller_uuid);
        print_r($result);
    }
}
