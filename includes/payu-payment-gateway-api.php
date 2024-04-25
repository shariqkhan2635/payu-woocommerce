<?php

class PayuPaymentGatewayAPI
{
    protected $payu_merchant_id;

    protected $payu_salt;

    protected $payu_key;

    protected $gateway_module;

    protected $phone_number;

    protected $email;

    public function __construct()
    {
        $plugin_data = get_option('woocommerce_payubiz_settings');
        $this->payu_merchant_id = $plugin_data['payu_merchant_id'];
        $this->payu_salt = $plugin_data['currency1_payu_salt'];
        $this->gateway_module = $plugin_data['gateway_module'];
        $this->payu_key = $plugin_data['currency1_payu_key'];
    }

    protected function payu_process_payment_refund($order, $amount = false)
    {
        global $table_prefix, $wpdb;
        $result = false;

        try {
            $plugin_data = get_option('woocommerce_payubiz_settings');
            $this->payu_salt = $plugin_data['currency1_payu_salt'];
            $this->gateway_module = $plugin_data['gateway_module'];
            $this->payu_key = $plugin_data['currency1_payu_key'];
            $order_id = $order->ID;
            if (!$amount) {
                $amount = $order->get_total();
            }

            $tblname = 'payu_transactions';
            $wp_track_table = $table_prefix . "$tblname ";
            $transaction_id = $wpdb->get_var("select transaction_id from $wp_track_table where order_id = '$order_id'");

            if ($transaction_id) {
                $payu_transaction_id = $transaction_id;
                $fields = array(
                    'key' => sanitize_key($this->payu_key),
                    'command' => 'cancel_refund_transaction',
                    'var1' => $payu_transaction_id,
                    'var2' => uniqid(),
                    'var3' => $amount,
                    'hash' => ''
                );

                $hash = hash(
                    "sha512",
                    $fields['key'] . '|' .
                        $fields['command'] . '|' .
                        $fields['var1'] . '|' .
                        $this->payu_salt
                );

                $fields['hash'] = sanitize_text_field($hash);
                $url = esc_url(PAYU_POSTSERVICE_FORM_2_URL_PRODUCTION);
                if ($this->gateway_module == 'sandbox') {
                    $url = esc_url(PAYU_POSTSERVICE_FORM_2_URL_UAT);
                }


                $args = array(
                    'body' => $fields,
                    'timeout' => '5',
                    'redirection' => '5',
                    'headers'     => array('Content-Type' => 'application/x-www-form-urlencoded'),
                );
                error_log('refund request payload : ' . serialize($args));
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
                    error_log("refund error body: " . serialize($response));

                    payu_insert_event_logs($args_log);
                    $result = false;
                } else {
                    $res = json_decode(sanitize_text_field($response['body']), true);
                    $args_log['response_data'] = $res;
                    payu_insert_event_logs($args_log);
                    $result = $res;
                }
            } else {
                $result = false;
            }
        } catch (Throwable $e) {
            error_log("refund error: " . $e->getMessage());
            $result = false;
        }
        return $result;
    }

    protected function payu_refund_status($order)
    {
        global $table_prefix, $wpdb;
        try {

            $order_id = $order->ID;
            $wc_orders = 'wc_orders';
            $wp_order_table = $table_prefix . "$wc_orders ";
            $payu_refund_transactions = 'payu_refund_transactions';
            $wp_refund_transactions_table = $table_prefix . "$payu_refund_transactions ";

            $request_id = $wpdb->get_var(
                "SELECT rf.request_id
                FROM $wp_order_table as wo
                join $wp_refund_transactions_table as rf
                on rf.order_id = wo.id
                WHERE wo.id = '$order_id'
                AND wo.status LIKE 'wc-refund-progress'
                AND wo.type LIKE 'shop_order'
                AND rf.status = 'processed'"
            );

            if ($request_id) {
                $fields = array(
                    'key' => sanitize_key($this->payu_key),
                    'command' => 'check_action_status',
                    'var1' => $request_id,
                    'var2' => uniqid(),
                    'hash' => ''
                );
                $hash = hash(
                    "sha512",
                    $fields['key'] . '|' .
                        $fields['command'] . '|' .
                        $fields['var1'] . '|' .
                        $this->payu_salt
                );
                $fields['hash'] = sanitize_text_field($hash);
                $url = esc_url(PAYU_POSTSERVICE_FORM_2_URL_PRODUCTION);
                if ($this->gateway_module == 'sandbox') {
                    $url = esc_url(PAYU_POSTSERVICE_FORM_2_URL_UAT);
                }


                $args = array(
                    'body' => $fields,
                    'timeout' => '5',
                    'redirection' => '5',
                    'headers'     => array('Content-Type' => CURL_CONTENT_TYPE),
                );

                $response = wp_remote_post($url, $args);
                if (!isset($response['body'])) {
                    return false;
                } else {
                    return json_decode(sanitize_text_field($response['body']), true);
                }
            }
        } catch (Throwable $e) {
            return false;
        }
    }

    protected function payu_refund_status_check($request_id)
    {
        global $wpdb;
        $result = false;
        try {
            if (!$request_id) {
                $result = false;
            }

            $fields = array(
                'key' => sanitize_key($this->payu_key),
                'command' => 'check_action_status',
                'var1' => $request_id,
                'var2' => uniqid(),
                'hash' => ''
            );

            $hash = hash("sha512", $fields['key'] . '|' .
                $fields['command'] . '|' .
                $fields['var1'] . '|' .
                $this->payu_salt);
            $fields['hash'] = sanitize_text_field($hash);
            $url = esc_url($this->gateway_module == 'sandbox' ?
                PAYU_POSTSERVICE_FORM_2_URL_UAT :
                PAYU_POSTSERVICE_FORM_2_URL_PRODUCTION);

            $args = array(
                'body' => $fields,
                'timeout' => 5,
                'redirection' => 5,
                'headers' => array('Content-Type' => CURL_CONTENT_TYPE),
            );

            $response = wp_remote_post($url, $args);

            if (!isset($response['body'])) {
                $result = false;
            }

            $refund_result = json_decode(sanitize_text_field($response['body']), true);

            if ($refund_result && $refund_result['status'] == 1) {
                foreach ($refund_result['transaction_details'] as $transaction_detail_data) {
                    foreach ($transaction_detail_data as $transaction_detail) {
                        $refund_status = $transaction_detail['status'];
                        $result = $transaction_detail['status'] == $refund_status;
                    }
                }
            } else {
                $result = false;
            }
        } catch (Throwable $e) {
            $result = false;
        }
        return $result;
    }


    protected function payu_refund_all_status($request_id)
    {
        try {

            $fields = array(
                'key' => sanitize_key($this->payu_key),
                'command' => 'check_action_status',
                'var1' => $request_id,
                'var2' => uniqid(),
                'hash' => ''
            );
            $hash = hash(
                "sha512",
                $fields['key'] . '|' .
                    $fields['command'] . '|' .
                    $fields['var1'] . '|' .
                    $this->payu_salt
            );
            $fields['hash'] = sanitize_text_field($hash);
            $url = esc_url(PAYU_POSTSERVICE_FORM_2_URL_PRODUCTION);
            if ($this->gateway_module == 'sandbox') {
                $url = esc_url(PAYU_POSTSERVICE_FORM_2_URL_UAT);
            }


            $args = array(
                'body' => $fields,
                'timeout' => '5',
                'redirection' => '5',
                'headers'     => array('Content-Type' => CURL_CONTENT_TYPE),
            );
            $response = wp_remote_post($url, $args);
            if (!isset($response['body'])) {
                return false;
            } else {
                return json_decode(sanitize_text_field($response['body']), true);
            }
        } catch (Throwable $e) {
            return false;
        }
    }


    protected function payu_save_address($address, $address_type, $user_id)
    {
        $scope = 'client_save_user_address';
        $token_data = $this->payu_get_token_api($scope);
        $user_info = get_userdata($user_id);
        $this->email = $user_info->user_email;
        $this->phone_number = get_user_meta($user_id, 'billing_phone', true);
        try {
            if (!empty($token_data)) {
                // // URL for the POST request
                $url = PAYU_ADDRESS_API_URL;
                if ($this->gateway_module == 'sandbox') {
                    $url = PAYU_ADDRESS_API_URL_UAT;
                }


                $token = $token_data->access_token;
                // Request headers
                $headers = array(
                    'Content-Type' => CURL_CONTENT_TYPE_JSON,
                    'Authorization' => 'Bearer ' . $token,
                );

                $get_state_list = get_state_list();
		        $state = $get_state_list[$address[$address_type . '_state']];

                // Request body
                $body = json_encode(array(
                    'merchantId' => $this->payu_merchant_id,
                    "email" => $this->email,
                    'shippingAddress' => array(
                        'name' => $address[$address_type . '_first_name'] . ' ' . $address[$address_type . '_last_name'],
                        'email' => $address[$address_type . '_email'],
                        'addressLine' => $address[$address_type . '_address_1'],
                        'addressLine2' => $address[$address_type . '_address_2'],
                        'addressPhoneNumber' => $address[$address_type . '_phone'],
                        'houseNumber' => '',
                        'landmark' => '',
                        'locality' => '',
                        'subLocality' => '',
                        'street' => '',
                        'village' => '',
                        'pincode' => $address[$address_type . '_postcode'],
                        'city' => $address[$address_type . '_city'],
                        'state' => $state,
                        'country' => $address[$address_type . '_country'],
                        'tag' => 'Home',
                        'source' => 'woocommerce',
                        'isDefault' => true,
                    ),
                ));

                // WP Remote Post
                $response = wp_remote_post($url, array(
                    'headers' => $headers,
                    'body' => $body,
                ));
                $response_code = wp_remote_retrieve_response_code($response);
                $headerResult = wp_remote_retrieve_headers($response);
                // Check for errors
                $args_log = array(
                    'request_type' => 'outgoing',
                    'method' => 'post',
                    'url' => $url,
                    'request_headers' => $headers,
                    'request_data' => $body,
                    'status' => $response_code,
                    'response_headers' => $headerResult,
                    'response_data' => 'null'
                );
                if (is_wp_error($response)) {
                    echo 'Error: ' . $response->get_error_message();
                    payu_insert_event_logs($args_log);
                } else {
                    // Process response
                    $response_body = wp_remote_retrieve_body($response);
                    $args_log['response_data'] = $response_body;
                    payu_insert_event_logs($args_log);
                    error_log('request address payu =' . $body);
                    error_log('save address payu =' . $response_body);
                    error_log('token =' . $token);
                    return json_decode($response_body);
                }
            }
        } catch (Throwable $e) {
            error_log('save address error ' . $e->getMessage());
            return false;
        }
    }


    protected function payu_update_address($address, $address_type, $payu_address_data, $user_id)
    {
        $scope = 'client_update_user_address';
        $payu_user_id = $payu_address_data->payu_user_id;
        $payu_address_id = $payu_address_data->payu_address_id;
        $this->phone_number = get_user_meta($user_id, 'billing_phone', true);
        $user_info = get_userdata($user_id);
        $this->email = $user_info->user_email;
        $token_data = $this->payu_get_token_api($scope);
        try {
            if (!empty($token_data)) {
                $url = PAYU_ADDRESS_API_URL;
                if ($this->gateway_module == 'sandbox') {
                    $url = PAYU_ADDRESS_API_URL_UAT;
                }


                $token = $token_data->access_token;
                // Request headers
                $headers = array(
                    'Content-Type' => CURL_CONTENT_TYPE_JSON,
                    'Authorization' => 'Bearer ' . $token,
                );
                $get_state_list = get_state_list();
		        $state = $get_state_list[$address[$address_type . '_state']];

                $name = $address[$address_type . '_first_name'] . ' ' . $address[$address_type . '_last_name'];
                // Request body
                $body = json_encode(array(
                    'merchantId' => $this->payu_merchant_id,
                    "email" => $this->email,
                    'userId' => $payu_user_id,
                    'shippingAddress' => array(
                        'id' => $payu_address_id,
                        'name' => $name,
                        'email' => $address[$address_type . '_email'],
                        'addressLine' => $address[$address_type . '_address_1'],
                        'addressLine2' => $address[$address_type . '_address_2'],
                        'addressPhoneNumber' => $address[$address_type . '_phone'],
                        'houseNumber' => '',
                        'landmark' => '',
                        'locality' => '',
                        'subLocality' => '',
                        'street' => '',
                        'village' => '',
                        'pincode' => $address[$address_type . '_postcode'],
                        'city' => $address[$address_type . '_city'],
                        'state' => $state,
                        'country' => $address[$address_type . '_country'],
                        'tag' => 'Home',
                        'source' => 'woocommerce',
                        'isDefault' => true,
                    ),
                ));

                // WP Remote Post
                $response = wp_remote_request($url, array(
                    'headers' => $headers,
                    'body' => $body,
                    'method'    => 'PUT'
                ));
                $response_code = wp_remote_retrieve_response_code($response);
                $headerResult = wp_remote_retrieve_headers($response);
                // Check for errors
                $args_log = array(
                    'request_type' => 'outgoing',
                    'method' => 'put',
                    'url' => $url,
                    'request_headers' => $headers,
                    'request_data' => $body,
                    'status' => $response_code,
                    'response_headers' => $headerResult,
                    'response_data' => 'null'
                );
                if (is_wp_error($response)) {
                    payu_insert_event_logs($args_log);
                    echo 'Error: ' . $response->get_error_message();
                } else {
                    // Process response
                    $response_body = wp_remote_retrieve_body($response);
                    $args_log['response_data'] = $response_body;
                    payu_insert_event_logs($args_log);
                    error_log('save address payu =' . serialize($response_body));
                    error_log('phone =' . $this->phone_number);
                    return json_decode($response_body);
                }
            }
        } catch (Throwable $e) {
            error_log('error1 =' . $e->getMessage());
            return false;
        }
    }

    protected function payu_get_token_api($scope)
    {

        $client_id = PAYU_CLIENT_ID;
        $client_secret = PAYU_CLIENT_SECRET_ID;
        $plugin_data = get_option('woocommerce_payubiz_settings');
        $this->payu_salt = $plugin_data['currency1_payu_salt'];
        $this->gateway_module = $plugin_data['gateway_module'];

        $url = PAYU_GENERATE_API_URL;
        if ($this->gateway_module == 'sandbox') {
            $url = PAYU_GENERATE_API_URL_UAT;
        }


        $headers = array(
            'Content-Type' => CURL_CONTENT_TYPE,
            'accept' => CURL_CONTENT_TYPE_JSON,
        );

        $body = array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'grant_type' => 'client_credentials',
            'scope' => $scope
        );

        $response = wp_remote_post(
            $url,
            array(
                'headers' => $headers,
                'body' => $body,
            )
        );
        $response_code = wp_remote_retrieve_response_code($response);
        $headerResult = wp_remote_retrieve_headers($response);
        $args_log = array(
            'request_type' => 'outgoing',
            'method' => 'post',
            'url' => $url,
            'request_headers' => $headers,
            'request_data' => $body,
            'status' => $response_code,
            'response_headers' => $headerResult,
            'response_data' => 'null'
        );
        if (is_wp_error($response)) {
            payu_insert_event_logs($args_log);
            error_log('error2 =' . $response->get_error_message());
            return false;
        } else {
            $response_body = wp_remote_retrieve_body($response);
            $args_log['response_data'] = $response_body;
            payu_insert_event_logs($args_log);
            return json_decode($response_body);
        }
    }

    protected function payu_order_detail_api()
    {

        $plugin_data = get_option('woocommerce_payubiz_settings');
        $this->payu_salt = $plugin_data['currency1_payu_salt'];
        $this->gateway_module = $plugin_data['gateway_module'];
        $this->payu_key = $plugin_data['currency1_payu_key'];

        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $hashString = "|" . $date . "|" . $this->payu_salt;
        $hash = $this->getSha512Hash($hashString);

        $url = 'https://apitest.payu.in/cart/order/996_240321:12';
        $authorization = 'hmac username="' .
            $this->payu_key . '", algorithm="sha512", headers="date", signature="' .
            $hash . '"';

        $request_args = array(
            'method'      => 'GET', // Method must be POST for wp_remote_post
            'headers'     => array(
                'Date'          => $date,
                'Authorization' => $authorization
            ),
        );
        $response = wp_remote_post($url, $request_args);
        if (is_wp_error($response)) {
            return false;
        } else {
            // If you're expecting JSON response, you can decode it
            $decoded_response = json_decode(wp_remote_retrieve_body($response));
            if (
                isset($decoded_response->data->address[0]) &&
                isset($decoded_response->data->address[0]->shippingAddress)
            ) {
                return $decoded_response->data->address[0]->shippingAddress;
            }
        }
        return false;
    }


    private function getSha512Hash($hashString)
    {
        $messageDigest = hash('sha512', $hashString, true);
        $hashtext = bin2hex($messageDigest);
        $hashtext = str_pad($hashtext, 128, '0', STR_PAD_LEFT); // Pad to 128 characters
        return $hashtext;
    }
}
