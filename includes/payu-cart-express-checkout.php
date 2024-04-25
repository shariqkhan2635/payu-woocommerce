<?php

/**
 * Payu Calculation Shipping and Tax cost.

 */

class PayuCartExpressCheckout
{

    protected $checkout_express;

    protected $disable_checkout;

    protected $payu_enable;

    public function __construct()
    {
        $plugin_data = get_option('woocommerce_payubiz_settings');
        $this->checkout_express = $plugin_data['checkout_express'];
        $this->payu_enable = $plugin_data['enabled'];
        $this->disable_checkout = $plugin_data['disable_checkout'];
        add_filter('woocommerce_get_order_item_totals', array(&$this, 'add_custom_order_total_row'), 10, 2);
        add_filter('woocommerce_order_get_formatted_shipping_address',array($this,'woocommerce_order_get_formatted_shipping_email_added'),10,3);
        if ($this->checkout_express == 'checkout_express' && $this->payu_enable == 'yes') {
            add_filter('woocommerce_coupons_enabled', array($this, 'disable_coupon_field_on_checkout'));
            add_action('woocommerce_proceed_to_checkout', array(&$this, 'add_payu_buy_now_button'));
            add_action('woocommerce_widget_shopping_cart_buttons', array($this, 'add_payu_buy_now_button'), 20);
            add_action('template_redirect',array($this,'cart_page_checkout_callback'));
            add_action('wp_enqueue_scripts', array($this, 'checkout_nonce_enqueue_custom_scripts'));
            add_filter('woocommerce_billing_fields', array($this, 'payu_remove_required_fields_checkout'));
            add_filter('woocommerce_default_address_fields', array($this, 'filter_default_address_fields'), 20, 1);
            add_action( 'woocommerce_before_cart', array($this,'update_cart_address_on_load') );
            if ($this->disable_checkout == 'yes') {
                add_action('init', array($this, 'payu_remove_checkout_button'));
                add_action('template_redirect', array($this, 'payu_redirect_checkout_to_cart'));
                add_action('init', array($this, 'remove_proceed_to_checkout_action'), 20);
            }
        }
    }



    public function add_payu_buy_now_button()
    {
        
        wp_localize_script('custom-cart-script', 'wc_checkout_params', array(
            'ajax_url' => WC()->ajax_url(),
            'checkout_nonce' => wp_create_nonce('woocommerce-process_checkout')
            // Add other parameters as needed
        ));

        $addresses = $this->get_user_checkout_details();
        $billing_data = $addresses['billing'];

?>
        <a href="javascript:void(0);" class="checkout-button payu-checkout button alt wc-forward">Buy Now with PayU</a>
        <script>
            var site_url = '<?php echo get_site_url(); ?>';
            jQuery(document).ready(function($) {
                // Trigger the custom checkout AJAX call
                jQuery(document).unbind('click').on('click', '.payu-checkout', function() {
                    var data = {
                        billing_alt: 0,
                        billing_first_name: '<?php echo $billing_data['first_name']; ?>',
                        billing_last_name: '<?php echo $billing_data['last_name']; ?>',
                        billing_company: '<?php echo $billing_data['company']; ?>',
                        billing_country: '<?php echo $billing_data['country']; ?>',
                        billing_address_1: '<?php echo $billing_data['address_1']; ?>',
                        billing_address_2: '',
                        billing_city: '<?php echo $billing_data['city']; ?>',
                        billing_state: '<?php echo $billing_data['state']; ?>',
                        billing_postcode: '<?php echo $billing_data['postcode']; ?>',
                        billing_phone: '<?php echo $billing_data['phone']; ?>',
                        billing_email: '<?php echo $billing_data['email']; ?>',
                        <?php if (isset($addresses['shipping'])) {
                            $shipping_data = $addresses['shipping']; ?>
                            shipping_first_name: '<?php echo $shipping_data['first_name']; ?>',
                            shipping_last_name: '<?php echo $shipping_data['last_name']; ?>',
                            shipping_company: '<?php echo $shipping_data['company']; ?>',
                            shipping_country: '<?php echo $shipping_data['country']; ?>',
                            shipping_address_1: '<?php echo $shipping_data['address_1']; ?>',
                            shipping_address_2: '',
                            shipping_phone: '<?php echo $shipping_data['phone']; ?>',
                            shipping_city: '<?php echo $shipping_data['city']; ?>',
                            shipping_state: '<?php echo $shipping_data['state']; ?>',
                            shipping_postcode: '<?php echo $shipping_data['postcode']; ?>',
                            shipping_email: '<?php echo $shipping_data['email']; ?>',
                            ship_to_order_comments: '',
                            ship_to_different_address: 1,
                        <?php } ?>
                        order_comments: '',
                        payment_method: 'payubiz',
                        _wp_http_referer: '/?wc-ajax=update_order_review',
                        'woocommerce-process-checkout-nonce': wc_checkout_params.checkout_nonce, // Include the checkout nonce
                    };
                    console.log(data);
                    jQuery.ajax({
                        type: 'POST',
                        url: '?wc-ajax=checkout',
                        data: data,
                        success: function(response) {
                            // Handle the AJAX response
                            console.log(response);
                            if (response.result == 'success') {
                                window.location = response.redirect;
                            } else {

                            }
                            // You may redirect to the checkout page or perform other actions based on the response
                        },
                    });
                });
            });
        </script>
        <?php

    }

    private function get_user_checkout_details()
    {

        $customer_id = get_current_user_id();
        $current_user_data = get_userdata($customer_id);
        $wc_customer = WC()->cart->get_customer();
        $billing_first_name = $wc_customer->get_billing_first_name();
        $billing_last_name = $wc_customer->get_billing_last_name();
        $billing_address = $wc_customer->get_billing_address();
        $billing_company = $wc_customer->get_billing_company();
        $billing_email = $wc_customer->get_billing_email();
        $billing_phone = $wc_customer->get_billing_phone();
        $billing_city = $wc_customer->get_billing_city();
        $billing_country = $wc_customer->get_billing_country();
        $billing_state = $wc_customer->get_billing_state();
        $billing_postcode = $wc_customer->get_billing_postcode();

        $shipping_first_name = $wc_customer->get_shipping_first_name();
        $shipping_last_name = $wc_customer->get_shipping_last_name();
        $shipping_address = $wc_customer->get_shipping_address();
        $shipping_company = $wc_customer->get_shipping_company();
        $shipping_email = get_user_meta($customer_id,'shipping_email',true);
        $shipping_phone = $wc_customer->get_shipping_phone();
        $shipping_city = $wc_customer->get_shipping_city();
        $shipping_country = $wc_customer->get_shipping_country();
        $shipping_state = $wc_customer->get_shipping_state();
        $shipping_postcode = $wc_customer->get_shipping_postcode();

        $first_name = $billing_first_name ? $billing_first_name : $shipping_first_name;
        $last_name = $billing_last_name;
        $address_1 = $billing_address ? $billing_address : $shipping_address;
        $company = $billing_company;
        $email = $billing_email ? $billing_email : $current_user_data->user_email;
        $city = $billing_city;
        $country = $billing_country;
        $state = $billing_state;
        $postcode = $billing_postcode;
        $billing_data = array(
            'first_name' => $first_name ? $first_name : 'test',
            'last_name' => $last_name,
            'address_1' => $address_1 ? $address_1 : 'address',
            'company' => $company,
            'email' => $email ? $email : '',
            'phone' => $billing_phone ? $billing_phone : '9810263726',
            'city' => $city ? $city : '',
            'country' => $country ? $country : 'IN',
            'state' => $state ? $state : '',
            'postcode' => $postcode ? $postcode : '',
            'logged_in' => $customer_id ? true : false
        );
        $addresses['billing'] = $billing_data;
        if ($shipping_first_name) {
            $shipping_data = array(
                'first_name' => $shipping_first_name,
                'last_name' => $shipping_last_name,
                'address_1' => $shipping_address ? $shipping_address : $billing_address,
                'company' => $shipping_company,
                'email' => $shipping_email,
                'phone' => $shipping_phone,
                'city' => $shipping_city,
                'country' => $shipping_country,
                'state' => $shipping_state,
                'postcode' => $shipping_postcode
            );
            $addresses['shipping'] = $shipping_data;
        }



        return $addresses;
    }

    function checkout_nonce_enqueue_custom_scripts()
    {
        if (!is_checkout()) {
            // Enqueue your script
            wp_enqueue_script('custom-cart-script', plugins_url('assets/js/script.js', dirname(__FILE__)), array('jquery'), '');
            // Pass parameters to the script
            wp_localize_script('custom-cart-script', 'wc_checkout_params', array(
                'ajax_url' => WC()->ajax_url(),
                'checkout_nonce' => wp_create_nonce('woocommerce-process_checkout')
                // Add other parameters as needed
            ));
        }
    }

    public function payu_remove_checkout_button()
    {
        // Optional: remove proceed to checkout button
        remove_action('woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20);
    }

    public function payu_redirect_checkout_to_cart()
    {

        if (is_checkout()) {
            // Get the current URL
            $current_url = home_url(add_query_arg(array(), $_SERVER['REQUEST_URI']));
            $cart_url = wc_get_cart_url();
            // Check if "/order-pay" is not present in the URL
            if (strpos($current_url, '/order-pay') === false && strpos($current_url, '/order-received') === false) {
                // User is on the checkout page without "/order-pay" in the URL
                wp_redirect($cart_url);
                exit;
        ?>
            <?php
            }
        }
    }


    public function filter_default_address_fields($address_fields)
    {
        // Only on checkout page

        // All field keys in this array
        if ( is_cart() ) {
        $key_fields = array('country', 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode');

        // Loop through each address fields (billing and shipping)
        foreach ($key_fields as $key_field) {
            $address_fields[$key_field]['required'] = false;
        }
        }
        return $address_fields;
    }

    public function remove_proceed_to_checkout_action()
    {
        remove_action('woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_proceed_to_checkout', 20);
    }

    public function payu_remove_required_fields_checkout($fields)
    {
        if (is_cart()) :
            $fields['billing_first_name']['required'] = false;
            $fields['billing_last_name']['required'] = false;
            $fields['billing_phone']['required'] = false;
            $fields['billing_email']['required'] = false;
            $fields['billing_country']['required'] = false;
            $fields['billing_state']['required'] = false;
            $fields['billing_postcode']['required'] = false;
            $fields['billing_address_1']['required'] = false;
            $fields['billing_city']['required'] = false;
        endif;
        return $fields;
    }

    public function cart_page_checkout_callback(){
        if ( is_page( 'cart' ) || is_cart() ) {
            // Pass parameters to the script
            $guest_checkout_enabled = get_option('woocommerce_enable_guest_checkout');
            if ($guest_checkout_enabled == 'no') {
                allow_to_checkout_from_cart('change', $guest_checkout_enabled);
            }
        }
    }


	public function disable_coupon_field_on_checkout($enabled)
	{
        error_log($enabled);
		return false;
	}


	public function add_custom_order_total_row($total_rows, $order)
	{
		if ($total_rows['payment_method']['value'] == 'PayUBiz') {
			$payment_mode['payment_mode'] = array(
				'label' => __('Payment Mode', 'your-text-domain'),
				'value' => $order->get_meta('payu_mode'),
			);

			$payu_offer_type = $order->get_meta('payu_offer_type');
			if ($payu_offer_type) {
				$payment_mode['payment_offer_type'] = array(
					'label' => __('Offer Type', 'your-text-domain'),
					'value' => $payu_offer_type,
				);
			}

			payment_array_insert($total_rows, 'payment_method', $payment_mode);
		}
		return $total_rows;
	}


    public function update_cart_address_on_load() {
        // Get current user's billing address
        $current_user = wp_get_current_user();
        // Update cart address
        // Update cart billing address fields
        if ( WC()->customer->get_id() > 0 && $current_user) {
            WC()->customer->set_shipping_country($current_user->billing_country);
            WC()->customer->set_billing_state($current_user->billing_state);
            WC()->customer->set_shipping_state($current_user->shipping_state);
            WC()->customer->set_shipping_city($current_user->shipping_city);
            WC()->customer->set_shipping_postcode($current_user->shipping_postcode);
            WC()->customer->set_shipping_address_1($current_user->shipping_address_1);
            // Set other billing address fields as needed
        }
    }

    public function woocommerce_order_get_formatted_shipping_email_added($address,$raw_address,$order){
       $shipping_email = $order->get_meta('shipping_email');
        if($shipping_email){
            $address .= "<br><p class='woocommerce-customer-details--email'>$shipping_email</p>";
        }
       
        return $address;
    }
}

$payu_cart_express_checkout = new PayuCartExpressCheckout();
