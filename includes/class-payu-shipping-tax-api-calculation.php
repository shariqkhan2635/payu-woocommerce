<?php

/**
 * Payu Calculation Shipping and Tax cost.

 */

class Payu_Shipping_Tax_Api_Calc
{

    protected $payu_salt;

    public function __construct()
    {

        add_action('rest_api_init', array(&$this, 'get_payment_failed_update'));
        add_action('rest_api_init', array($this, 'payu_generate_get_user_token'));
    }


    public function get_payment_failed_update()
    {
        register_rest_route('payu/v1', '/get-shipping-cost', array(
            'methods' => ['POST'],
            'callback' => array($this, 'payu_shipping_cost_callback'),
            'permission_callback' => '__return_true'
        ));
    }

    public function payu_shipping_cost_callback(WP_REST_Request $request)
    {
        $parameters = json_decode($request->get_body(), true);
        $email = $parameters['email'];
        $txnid = $parameters['txnid'];
        $auth = apache_request_headers();
        $token = $auth['Auth-Token'];
        try{

        
        if ($token && $this->payu_validate_authentication_token($email, $token)) {
            $parameters['address']['state'] = get_state_code_by_name($parameters['address']['state']);
            if (!$parameters['address']['state']) {
                $response = [
                    'status' => 'false',
                    'data' => [],
                    'message' => 'The State value is wrong'
                ];
            } else {
                $session_key = $parameters['udf5'];
                $order_string = explode('_', $txnid);
                $order_id = (int)$order_string[0];
                $order = wc_get_order($order_id);
                $shipping_address = $parameters['address'];
                if (email_exists($email)) {
                    $user = get_user_by('email', $email);
                    $user_id = $user->ID;
                    $this->update_order_shipping_address($order, $shipping_address, $email);
                    $shipping_data = $this->update_cart_data($user_id, $order);
                } else {
                    $user_id = $this->payu_create_guest_user($email);
                    if ($user_id) {
                        $this->payu_add_new_guest_user_cart_data($user_id, $session_key);
                        $shipping_data = $this->update_cart_data($user_id, $order);
                    }
                }
                if (isset($shipping_data)) {
                    $response = [
                        'status' => 'success',
                        'data' => $shipping_data,
                        'message' => 'Shipping methods fetched successfully'
                    ];
                } else {
                    $response = [
                        'status' => 'false',
                        'data' => [],
                        'message' => 'Shipping Data Not Found'
                    ];
                }
            }
        } else {
            $response = [
                'status' => 'false',
                'data' => [],
                'message' => 'Token is invalid'
            ];
            return new WP_REST_Response($response,401);
        } 
        return new WP_REST_Response($response);
        } catch (Throwable $e) {
            $response = [
                'status' => 'false',
                'data' => [],
                'message' => 'Fetch Shipping Method Failed ('.$e->getMessage().')'
            ];
            return new WP_REST_Response($response,500);
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
        $order->set_billing_email($email);
        $order->save();
    }

    public function update_cart_data($user_id, $order)
    {
        global $table_prefix, $wpdb;
        $user_session_table = $table_prefix . "woocommerce_sessions";
        $cart_data = array();
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
            //var_dump(WC()->cart->show_shipping());
            $cart = WC()->cart;
            $shipping_packages =  WC()->cart->get_shipping_packages();
            $shipping_zone = wc_get_shipping_zone(reset($shipping_packages));
            $zone_id   = $shipping_zone->get_id(); // Get the zone ID
            $zone_name = $shipping_zone->get_zone_name(); // Get the zone name
            // Loop through shipping packages from WC_Session (They can be multiple in some cases)
            $shipping_method_count = 0;
            foreach (WC()->cart->get_shipping_packages() as $package_id => $package) {
                // Check if a shipping for the current package exist
                if (WC()->session->__isset('shipping_for_package_' . $package_id)) {
                    // Loop through shipping rates for the current package
                    foreach (WC()->session->get('shipping_for_package_' . $package_id)['rates'] as $shipping_rate_id => $shipping_rate) {

                        $shipping_data[$shipping_method_count]['carrier_code']   = $shipping_rate->get_method_id(); // The shipping method slug
                        $shipping_data[$shipping_method_count]['method_code']   = $shipping_rate->get_method_id(); // The shipping method slug
                        $shipping_data[$shipping_method_count]['carrier_title']  = $shipping_rate->get_label(); // The label name of the method
                        $shipping_data[$shipping_method_count]['amount']        = $shipping_rate->get_cost(); // The cost without tax
                        $shipping_data[$shipping_method_count]['error_message']        = "";
                        $shipping_data[$shipping_method_count]['tax_price']    = $shipping_rate->get_shipping_tax(); // The tax cost
                        $shipping_data[$shipping_method_count]['subtotal']   = WC()->cart->get_subtotal();
                        $shipping_data[$shipping_method_count]['grand_total']   = WC()->cart->total;
                    $shipping_method_count++;
                    }
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
        $table_name = 'wp_woocommerce_sessions';
        # Change this code to not construct SQL queries directly from user-controlled data which can lead to SQL injection.
        $wc_session_data = $wpdb->get_var("select session_value from $table_name where session_key = '$session_key'");
        $cart_data['cart'] = maybe_unserialize(maybe_unserialize($wc_session_data)['cart']);

        add_user_meta($user_id, '_woocommerce_persistent_cart_1', $cart_data);
    }

    private function payu_create_guest_user_cart_data($user_id, $session_key)
    {

        try {
            include_once WP_PLUGIN_DIR . '/woocommerce/includes/wc-cart-functions.php';
            include_once WP_PLUGIN_DIR . '/woocommerce/includes/wc-notice-functions.php';
            include_once WP_PLUGIN_DIR . '/woocommerce/includes/wc-template-hooks.php';
            global $wpdb;
            $table_name = 'wp_woocommerce_sessions';
            WC()->session = new WC_Session_Handler();
            // WC()->session->init();

            // $wc_session_data = WC()->session->get_session($session_key);
            $wc_session_data = $wpdb->get_var("select session_value from $table_name where session_key = '$session_key'");
            // var_dump(maybe_unserialize($wc_session_data));
            // die;
            $cart_data = maybe_unserialize(maybe_unserialize($wc_session_data)['cart']);

            // Get the persistent cart may be _woocommerce_persistent_cart can be in your case check in user_meta table
            WC()->customer = new WC_Customer($user_id, true);
            // create new Cart Object
            WC()->cart = new WC_Cart();
            //WC()->session->set('cart', array());
            // return $full_user_meta;
            // Add old cart data to newly created cart object
            $this->payu_set_current_user($user_id);
            if ($cart_data) {
                foreach ($cart_data as $sinle_user_meta) {
                    $product_cart_id = WC()->cart->generate_cart_id($sinle_user_meta['product_id']);
                    if (!WC()->cart->find_product_in_cart($product_cart_id)) {
                        $item_key = WC()->cart->add_to_cart($sinle_user_meta['product_id'], $sinle_user_meta['quantity']);
                        if ($item_key) {
                            $data = WC()->cart->get_cart_item($item_key);

                            do_action('wc_cart_rest_add_to_cart', $item_key, $data);
                        }
                        $product_cart_ids[] = $product_cart_id;
                    }
                }
            }


            $updatedCart = [];


            foreach (WC()->cart->cart_contents as $key => $val) {
                unset($val['data']);
                $updatedCart[$key] = $val;
                $updatedCart[$key]['file'] = "hello file herer";
            }
            // If there is a current session cart, overwrite it with the new cart
            //  if($wc_session_data) {
            // $wc_session_data['cart'] = maybe_serialize($updatedCart);
            // $serializedObj = maybe_serialize($wc_session_data);

            // Update the wp_session table with updated cart data
            // $sql ="UPDATE $table_name SET 'session_value'= '".$serializedObj."', WHERE  'session_key' = '".$user_id."'";

            // // Execute the query
            // $rez = $wpdb->query($sql);

            // $wpdb->query(
            // 	$wpdb->prepare(
            // 		"INSERT INTO $table_name (`session_key`, `session_value`, `session_expiry`) VALUES (%s, %s, %d) ON DUPLICATE KEY UPDATE `session_value` = VALUES(`session_value`), `session_expiry` = VALUES(`session_expiry`)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // 		$user_id,
            // 		$serializedObj,
            // 		'1703228373'
            // 	)
            // );


            // }
            // Overwrite the persistent cart with the new cart data
            if ($updatedCart) {
                $full_user_meta = array();
                $full_user_meta['cart'] = $updatedCart;
                $meta_added = add_user_meta($user_id, '_woocommerce_persistent_cart_1', $full_user_meta);
            }
            WC()->cart->calculate_totals();
            var_dump($product_cart_ids);
            unset(WC()->session);
            unset(WC()->cart);
            if ($meta_added) {
                return true;
            } else {
                return false;
            }
        } catch (Error $e) {
            require_once(ABSPATH . 'wp-admin/includes/user.php');
            wp_delete_user($user_id);
            return false;
        }
    }



    private function payu_set_current_user(int $user_id)
    {
        $curr_user = wp_set_current_user($user_id, '');
        wp_set_auth_cookie($user_id);
        do_action('wp_login', ...array($curr_user->user_login, $curr_user));
    }


    public function payu_generate_get_user_token()
    {
        register_rest_route('payu/v1', '/generate-user-token', array(
            'methods' => ['POST'],
            'callback' => array($this, 'payu_generate_user_token_callback'),
            'permission_callback' => '__return_true'
        ));
    }

    public function payu_generate_user_token_callback(WP_REST_Request $request)
    {
        $parameters = json_decode($request->get_body(), true);
        $email = $parameters['email'];
        $session_key = $parameters['udf5'];
        $plugin_data = get_option('woocommerce_payubiz_settings');
        $this->payu_salt = $plugin_data['currency1_payu_salt'];
        if (email_exists($email)) {
            $user = get_user_by('email', $email);
            $user_id = $user->ID;
            $token = $this->payu_generate_authentication_token($user_id);
        } else {
            $user_id = $this->payu_create_guest_user($email);
            $this->payu_add_new_guest_user_cart_data($user_id, $session_key);
            if ($user_id) {
                $token = $this->payu_generate_authentication_token($user_id);
            }
        }

        if (isset($token) && !empty($token)) {
            $response = [
                'status' => true,
                'data' => array('token' => $token),
                'message' => 'Token Generated'
            ];
        } else {
            $response = [
                'status' => false,
                'data' => [],
                'message' => 'Enter the valid email and try again.'
            ];
        }
        return new WP_REST_Response($response);
    }

    private function payu_generate_authentication_token($user_id)
    {
        // Generate a random password (token)
        $token = wp_generate_password(100, false);

        // Set the expiration time to 24 hours from now
        $expiration = time() + 24 * 60 * 60;
        // Save the token and expiration time in user meta
        update_user_meta($user_id, 'payu_auth_token', $token);
        update_user_meta($user_id, 'payu_auth_token_expiration', $expiration);

        return $token;
    }

    private function payu_validate_authentication_token($email, $token)
    {
        $user_id = get_user_by('email', $email)->ID;
        // Get the stored token and expiration time from user meta
        $stored_token = get_user_meta($user_id, 'payu_auth_token', true);
        $expiration = get_user_meta($user_id, 'payu_auth_token_expiration', true);
        // Check if the stored token matches the provided token and is not expired
        if ($stored_token === $token && $expiration >= time()) {
            return true;
        } else {
            // Token is invalid or expired
            return false;
        }
    }
}
new Payu_Shipping_Tax_Api_Calc();
