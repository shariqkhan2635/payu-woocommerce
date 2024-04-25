<?php

/**
 * Payu Calculation Shipping and Tax cost.

 */

class PayuShippingTaxApiCalc
{

    protected $payu_salt;

    public function __construct()
    {

        add_action('rest_api_init', array(&$this, 'getPaymentFailedUpdate'));
        add_action('rest_api_init', array($this, 'payu_generate_get_user_token'));
    }


    public function getPaymentFailedUpdate()
    {
        register_rest_route('payu/v1', '/get-shipping-cost', array(
            'methods' => ['POST'],
            'callback' => array($this, 'payuShippingCostCallback'),
            'permission_callback' => '__return_true'
        ));
    }

    public function payuShippingCostCallback(WP_REST_Request $request)
    {
        $parameters = json_decode($request->get_body(), true);
        error_log('shipping api call request ' . $request->get_body());

        $email = $parameters['email'];
        $txnid = $parameters['txnid'];

        $auth = apache_request_headers();
        $token = $auth['Auth-Token'];

        try {
            if ($token && $this->payu_validate_authentication_token(PAYU_USER_TOKEN_EMAIL, $token)) {
                $response = $this->handleValidToken($parameters, $email, $txnid);
            } else {
                $response = [
                    'status' => 'false',
                    'data' => [],
                    'message' => 'Token is invalid'
                ];
                return new WP_REST_Response($response, 401);
            }
        } catch (Throwable $e) {
            $response = [
                'status' => 'false',
                'data' => [],
                'message' => 'Fetch Shipping Method Failed (' . $e->getMessage() . ')'
            ];
            return new WP_REST_Response($response, 500);
        }
        $response_code = $response['status'] == 'false' ? 400 : 200;
        error_log('shipping api call response ' . json_encode($response));
        return new WP_REST_Response($response, $response_code);
    }

    private function handleValidToken($parameters, $email, $txnid)
    {
        $parameters['address']['state'] = get_state_code_by_name($parameters['address']['state']);

        if (!$parameters['address']['state']) {
            return [
                'status' => 'false',
                'data' => [],
                'message' => 'The State value is wrong'
            ];
        }

        $session_key = $parameters['udf4'];
        $order_string = explode('_', $txnid);
        $order_id = (int)$order_string[0];
        $order = wc_get_order($order_id);

        $shipping_address = $parameters['address'];
        if (!$email) {
            $guest_email = $session_key . '@mailinator.com';
            $user_id = $this->payu_create_guest_user($guest_email);
            if ($user_id) {
                $this->payu_add_new_guest_user_cart_data($user_id, $session_key);
                $shipping_data = $this->update_cart_data($user_id, $order);
                require_once(ABSPATH . 'wp-admin/includes/user.php');
                wp_delete_user($user_id);
            }
        } else {
            if (email_exists($email)) {
                $user = get_user_by('email', $email);
                $user_id = $user->ID;
                $this->payu_add_new_guest_user_cart_data($user_id, $session_key);
                $this->update_order_shipping_address($order, $shipping_address, $email);
                $shipping_data = $this->update_cart_data($user_id, $order);
            } else {
                $user_id = $this->payu_create_guest_user($email);
                if ($user_id) {
                    $this->payu_add_new_guest_user_cart_data($user_id, $session_key);
                    $this->update_order_shipping_address($order, $shipping_address, $email);
                    $shipping_data = $this->update_cart_data($user_id, $order);
                }
            }
        }


        if (isset($shipping_data)) {
            return [
                'status' => 'success',
                'data' => $shipping_data,
                'message' => 'Shipping methods fetched successfully'
            ];
        } else {
            return [
                'status' => 'false',
                'data' => [],
                'message' => 'Shipping Data Not Found'
            ];
        }
    }


    // Helper function to update shipping address
    public function update_order_shipping_address($order, $new_address, $email)
    {
        // Implement your logic to update the shipping address
        // You might use the wc_update_order function or any other method

        // Example using wc_update_order:
        $order->set_shipping_address($new_address);
        $order->set_address($new_address, 'shipping');
        $order->set_address($new_address, 'billing');
        error_log('set order address ' . json_encode($new_address));
        $order->set_billing_email($email);
        $order->save();
    }

    public function update_cart_data($user_id, $order)
    {
        global $table_prefix, $wpdb;
        $user_session_table = $table_prefix . "woocommerce_sessions";
        $shipping_data = array();
        if ($order) {
            include_once WP_PLUGIN_DIR . '/woocommerce/includes/wc-cart-functions.php';
            include_once WP_PLUGIN_DIR . '/woocommerce/includes/wc-notice-functions.php';
            include_once WP_PLUGIN_DIR . '/woocommerce/includes/wc-template-hooks.php';
            WC()->session = new WC_Session_Handler();
            WC()->session->init();

            $session = WC()->session->get_session($user_id);
            $customer_data = maybe_unserialize($session['customer']);
            $customer_data['state'] = $order->get_shipping_state();
            $customer_data['shipping_state'] = $order->get_shipping_state();
            $customer_data['country'] = $order->get_shipping_country();
            $customer_data['shipping_country'] = $order->get_shipping_country();
            $customer_data['city'] = $order->get_shipping_city();
            $customer_data['shipping_city'] = $order->get_shipping_city();
            $customer_data['postcode'] = $order->get_shipping_postcode();
            $customer_data['shipping_postcode'] = $order->get_shipping_postcode();
            $customer_data['address_1'] = $order->get_shipping_address_1();
            $customer_data['shipping_address_1'] = $order->get_shipping_address_1();
            $session['customer'] = maybe_serialize($customer_data);
            $wpdb->update(
                $user_session_table,
                array(
                    'session_value' => maybe_serialize($session),
                ),
                array(
                    'session_key' => $user_id,
                ),
            );

            WC()->customer = new WC_Customer($user_id, true);
            // create new Cart Object
            WC()->customer->set_shipping_country($order->get_shipping_country());
            WC()->customer->set_shipping_state($order->get_shipping_state());
            WC()->customer->set_billing_state($order->get_shipping_state());
            WC()->customer->set_shipping_state($order->get_shipping_state());
            WC()->customer->set_shipping_city($order->get_shipping_city());
            WC()->customer->set_shipping_postcode($order->get_shipping_postcode());
            WC()->customer->set_shipping_address_1($order->get_shipping_address_1());
            WC()->cart = new WC_Cart();
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);
            WC()->cart->calculate_totals();
            // Loop through shipping packages from WC_Session (They can be multiple in some cases)
            $shipping_method_count = 0;
            foreach (WC()->cart->get_shipping_packages() as $package_id => $package) {
                // Check if a shipping for the current package exist
                if (WC()->session->__isset('shipping_for_package_' . $package_id)) {
                    // Loop through shipping rates for the current package
                    foreach (WC()->session->get('shipping_for_package_' . $package_id)['rates'] as $shipping_rate) {
                        $tax_amount = 0;
                        WC()->session->set('chosen_shipping_methods', array($shipping_rate->id));
                        WC()->cart->calculate_totals();
                        foreach (WC()->cart->get_tax_totals() as $tax) {
                            $tax_amount   = $tax->amount + $tax_amount;
                        }

                        $shipping_data[$shipping_method_count]['carrier_code']   = $shipping_rate->id;
                        $shipping_data[$shipping_method_count]['method_code']   = $shipping_rate->get_method_id();
                        $shipping_data[$shipping_method_count]['carrier_title']  = $shipping_rate->get_label();
                        $shipping_data[$shipping_method_count]['amount']        = $shipping_rate->get_cost();
                        $shipping_data[$shipping_method_count]['error_message']        = "";
                        $shipping_data[$shipping_method_count]['tax_price']    = $tax_amount;
                        $shipping_data[$shipping_method_count]['subtotal']   = WC()->cart->get_subtotal();
                        $shipping_data[$shipping_method_count]['grand_total']   = WC()->cart->get_subtotal() + $shipping_rate->get_cost() + $tax_amount;
                        $shipping_method_count++;
                    }
                } else {
                    foreach (WC()->cart->get_tax_totals() as $tax) {
                        $tax_amount   = $tax->amount + $tax_amount;
                    }
                    $shipping_data[0]['carrier_code']   = '';
                    $shipping_data[0]['method_code']   = '';
                    $shipping_data[0]['carrier_title']  = '';
                    $shipping_data[0]['amount']        = '';
                    $shipping_data[0]['error_message']        = "";
                    $shipping_data[0]['tax_price']    = $tax_amount;
                    $shipping_data[0]['subtotal']   = WC()->cart->get_subtotal();
                    $shipping_data[0]['grand_total']   = WC()->cart->get_subtotal() + $tax_amount;
                }
            }
        }
        return $shipping_data;
    }

    private function payu_create_guest_user($email)
    {

        $user_id = wp_create_user($email, wp_generate_password(), $email);
        if (!is_wp_error($user_id)) {
            return $user_id;
        } else {
            return false;
        }
    }

    private function payu_add_new_guest_user_cart_data($user_id, $session_key)
    {
        global $wpdb;
        global $table_prefix, $wpdb;
        $woocommerce_sessions = 'woocommerce_sessions';
        $wp_woocommerce_sessions_table = $table_prefix . "$woocommerce_sessions ";
        // Prepare the SQL query with a placeholder for the session key
        $query = $wpdb->prepare("SELECT session_value FROM $wp_woocommerce_sessions_table
        WHERE session_key = %s", $session_key);

        // Execute the prepared statement
        $wc_session_data = $wpdb->get_var($query);

        $cart_data['cart'] = maybe_unserialize(maybe_unserialize($wc_session_data)['cart']);
        update_user_meta($user_id, '_woocommerce_persistent_cart_1', $cart_data);
    }


    public function payu_generate_get_user_token()
    {
        register_rest_route('payu/v1', '/generate-user-token', array(
            'methods' => ['POST'],
            'callback' => array($this, 'payu_generate_user_token_callback'),
            'permission_callback' => '__return_true'
        ));
    }

    public function payu_generate_user_token_callback()
    {
        $email = PAYU_USER_TOKEN_EMAIL;
        $plugin_data = get_option('woocommerce_payubiz_settings');
        $this->payu_salt = $plugin_data['currency1_payu_salt'];
        if (email_exists($email)) {
            $user = get_user_by('email', $email);
            $user_id = $user->ID;
            $token = $this->payu_generate_authentication_token($user_id);
            $response = [
                'status' => true,
                'data' => array('token' => $token),
                'message' => 'Token Generated'
            ];
        } else {
            $response = [
                'status' => false,
                'data' => [],
                'message' => "Account not exist from this email $email"
            ];
        }
        return new WP_REST_Response($response);
    }

    private function payu_generate_authentication_token($user_id)
    {

        $expiration = get_user_meta($user_id, 'payu_auth_token_expiration', true);
        $stored_token = get_user_meta($user_id, 'payu_auth_token', true);

        if ($expiration >= time() && $stored_token) {
            return $stored_token;
        }

        $token = wp_generate_password(12, false); // Generate a secure password
        $hashed_token = wp_hash_password($token); // Hash the password securely

        // Set the expiration time to 24 hours from now
        $expiration = time() + 24 * 60 * 60;
        // Save the token and expiration time in user meta
        update_user_meta($user_id, 'payu_auth_token', $hashed_token);
        update_user_meta($user_id, 'payu_auth_token_expiration', $expiration);

        return $hashed_token;
    }

    private function payu_validate_authentication_token($email, $token)
    {
        $user_id = get_user_by('email', $email)->ID;
        // Get the stored token and expiration time from user meta
        $stored_token = get_user_meta($user_id, 'payu_auth_token', true);
        $expiration = get_user_meta($user_id, 'payu_auth_token_expiration', true);
        // Check if the stored token matches the provided token and is not expired
        return ($stored_token === $token && $expiration >= time()) ? true : false;
    }
}
$payu_shipping_tax_api_calc = new PayuShippingTaxApiCalc();
