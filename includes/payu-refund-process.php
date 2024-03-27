<?php

class Payu_Refund_Process extends Payu_Payment_Gateway_API
{
    protected $enable_refund;

    protected $payu_enable;

    protected $payu_salt;

    protected $gateway_module;

    protected $payu_key;

    protected $data;

    public function __construct($call_order_hooks = true)
    {
        if ($call_order_hooks) {

            $plugin_data = get_option('woocommerce_payubiz_settings');
            $this->enable_refund = $plugin_data['enable_refund'];
            $this->payu_enable = $plugin_data['enabled'];
            $this->payu_salt = $plugin_data['currency1_payu_salt'];
            $this->gateway_module = $plugin_data['gateway_module'];
            $this->payu_key = $plugin_data['currency1_payu_key'];
            if ($this->enable_refund == 'yes' && $this->payu_enable == 'yes') {
                // Hook into the order details page to display the refund form
                add_action('woocommerce_order_details_after_order_table', array(&$this, 'custom_refund_form'));

                // Display refund links for each item
                add_action('woocommerce_order_item_meta_end', array(&$this, 'payu_item_display_refund_links'), 10, 3);

                // Hook into the order details page to display the partial refund form
                add_action('woocommerce_order_details_before_order_table', array(&$this, 'payu_partial_refund_submit'), 1);

                // Hook into the refund process when the form is submitted
                add_action('woocommerce_order_details_before_order_table', array(&$this, 'process_custom_refund'), 1);

                // Hook into the refund check status when the form is submitted
                add_action('woocommerce_order_details_before_order_table', array(&$this, 'check_custom_refund_status'));

                // Hook into the refund time message
                add_action('woocommerce_order_details_before_order_table', array(&$this, 'payu_refund_time_message'));

                // Hook into the refund check status update (api for webhook)
                add_action('rest_api_init', array(&$this, 'refund_status_callback'));

                add_action('init', array(&$this, 'register_refund_in_progress_order_status'));
                add_filter('wc_order_statuses', array(&$this, 'add_refund_in_progress_to_order_statuses'), 1);

                add_filter('cron_schedules', array($this, 'payu_check_refund_status_custom_schedule'), 10, 1);
                add_action('wp', array($this, 'payu_check_refund_status_scheduled_event'));
                add_action('payu_check_refund_status_check_next_scheduled', array($this, 'payu_check_refund_status_update_data_according_to_cron'));
                // add_action( 'wp_head', array($this, 'payu_check_refund_status_update_data_according_to_cron') );

            }
        }
    }

    public function custom_refund_form($order_id)
    {
        // Display a simple refund form
        $order = wc_get_order($order_id);
        echo 'test';
        $this->payu_order_detail_api();
        $payment_method = $order->get_payment_method();
        if ($payment_method != 'payubiz') {
            return;
        }
        
        $order_status  = $order->get_status(); // Get the order status
        if (!sizeof($order->get_refunds()) && ($order_status == 'processing' || $order_status == 'completed')) {
            $full_refund_form = '<form method="post" id="payu_refund_form">';
            $full_refund_form .= '<input type="hidden" name="custom_refund_order_id" value="' . esc_attr($order->ID) . '">';
            $full_refund_form .=  wp_nonce_field('payu_full_refund_payment_nonce', 'payu_full_refund_payment_nonce', true, false);
            $full_refund_form .= '<input type="submit" name="custom_refund_submit" class="payu-refund" value="Full Refund Request">';
            $full_refund_form .= '</form>';
            echo apply_filters('payu_full_refund_form', $full_refund_form, $order);
        }
        //var_dump($order_status);
        if ($order_status == 'refund-progress') {
            echo '<form method="post" id="payu_refund_status">';
            echo '<input type="hidden" name="custom_refund_order_id" value="' . esc_attr($order_id) . '">';
            echo '<input type="submit" name="custom_check_refund_submit" class="payu-refund" value="Check refund Status">';
            echo '</form>';
        }
    }

    public function setData($value)
    {
        $this->data = $value;
    }

    public function getData()
    {
        return $this->data;
    }

    // Factory method to create another instance without initializing
    public static function createEmptyInstance()
    {
        return new self(); // You can pass any default data or null
    }

    public function process_custom_refund_backend($order, $amount)
    {

        $refund_result = $this->payu_process_payment_refund($order, $amount);
        return $refund_result;
    }

    public function payu_refund_time_message($order)
    {

        $order_status = $order->get_status();
        if ($order_status == 'refund-progress') {
            $refund_time_text = apply_filters('payu_refund_process_text', "<p><b>" . PAYU_REFUND_PROCESS_TIME_TEXT . "<b></p>");
            echo $refund_time_text;
        }
    }

    public function process_custom_refund($order)
    {

        global $refund_args;


        if (isset($_POST['custom_refund_submit'])) {
            // Process refund using payment gateway API
            $order_id = $order->ID;
            $refund_amount = $order->get_total();
            $refund_reason = 'Customer request';
            if (isset($_POST['payu_full_refund_payment_nonce']) && wp_verify_nonce($_POST['payu_full_refund_payment_nonce'], 'payu_full_refund_payment_nonce')) {
                $refund_id = wc_create_refund(array(
                    'amount'   => $refund_amount,
                    'reason'   => $refund_reason,
                    'order_id' => $order_id,
                    'refund_payment' => true
                ));
                $this->setData($refund_args);
                $refund_data = serialize($refund_id);
                error_log("refund data $refund_data for order id $order_id");
                if (is_wp_error($refund_id)) {
                    echo '<strong>Refund failed. Please try again. (' . $refund_id->get_error_message() . ')</strong>';
                } else {
                    $order->update_status('wc-refund-progress', 'Order Refunded is in queued');
                    error_log("order refund mark $order_id");
                    echo '<p><strong>Your request is in queued</strong><script>jQuery(".order-status").html("Refund in Progress");</script></p>';
                }
            } else {
                // Nonce is not valid, handle the error or prevent further processing
                echo 'Security check failed!';
            }
        }
    }

    public function check_custom_refund_status($order)
    {


        if (isset($_POST['custom_check_refund_submit'])) {
            // Process refund using payment gateway API
            $refund_result = $this->payu_refund_status($order);
            $order_id = $order->ID;
            if ($refund_result && $refund_result['status'] == 1) {

                foreach ($refund_result['transaction_details'] as $transaction_detail_data) {

                    foreach ($transaction_detail_data as $transaction_detail) {
                        $status = $transaction_detail['status'];
                        if ($transaction_detail['status'] == 'queued') {
                            $refund_msg = "Your request is still in the $status";
                        } else {
                            $refund_msg = "Your request is $status";
                        }
                    }
                }
            } else {
                $refund_msg = $refund_result['msg'];
            }

            echo '<p><strong>' . $refund_msg . '</strong></p>';
        }
    }

    public function payu_partial_refund_submit($order)
    {
        global $refund_args;

        if (isset($_POST['custom_partial_refund_submit'])) {

            if (isset($_POST['payu_partial_refund_payment_nonce']) && wp_verify_nonce($_POST['payu_partial_refund_payment_nonce'], 'payu_partial_refund_payment_nonce')) {

                $order_id = $order->ID;
                $refund_reason = 'Customer request';
                $payu_coupon_value = $this->get_payu_coupon_value($order);
                $refunded_item_ids = $this->payu_refund_item_ids($order);
                $item_id = $_POST['custom_partial_refund_item_id'];
                if (!in_array($item_id, $refunded_item_ids)) {

                    $item_meta = $order->get_item_meta($item_id);
                    $tax_data = $item_meta['_line_tax_data'];
                    $total_value = $item_meta['_line_total'][0];
                    $refund_tax = 0;
                    $total_tax = maybe_unserialize($tax_data[0]);

                    if (is_array($total_tax)) {
                        $refund_tax = array_map('wc_format_decimal', $total_tax);
                    }
                    if ($payu_coupon_value) {

                        $item_discount_value = $this->get_a_item_discount_value($order, $item_id, abs($payu_coupon_value));
                        $total_value = $total_value - $item_discount_value;
                    }
                    $line_items[$item_id] = array(
                        'refund_total' => $total_value,
                        'refund_tax' => $refund_tax['total']
                    );

                    if ($item_meta['_qty']) {
                        $line_items[$item_id]['qty'] = $item_meta['_qty'][0];
                    }

                    $refund_amount = $total_value;
                    if (!empty($refund_tax['total'])) {
                        $refund_amount = $total_value + array_sum($refund_tax['total']);
                    }


                    $refund_args = array(
                        'amount'   => $refund_amount,
                        'reason'   => $refund_reason,
                        'order_id' => $order_id,
                        'line_items' => $line_items,
                        'refund_payment' => true
                    );

                    $this->setData($refund_args);
                    $refund_id = wc_create_refund($refund_args);

                    $refund_data = serialize($refund_id);
                    error_log("refund data $refund_data for order id $order_id");
                    if (is_wp_error($refund_id)) {
                        echo '<strong>Refund failed. Please try again. (' . $refund_id->get_error_message() . ')</strong>';
                    } else {
                        $order->update_status('wc-refund-progress', 'Order Refunded is in queued');
                        error_log("order refund mark1 $order_id");
                        echo '<p><strong>Your request is in queued</strong><script>jQuery(".order-status").html("Refund in Progress");</script></p>';
                    }
                } else {
                    echo '<p><strong>Already applied for this product</strong></p>';
                }
            }
        }


        //echo '<p><strong>' . $refund_result['msg'] . '</strong></p>';
    }


    public function payu_refund_data_insert($postdata, $order_id, $refund_type = 'full', $refund_args = array())
    {
        global $table_prefix, $wpdb;
        $tblname = 'payu_refund_transactions';
        $wp_payu_table = $table_prefix . "$tblname";
        $request_id = $postdata['request_id'];
        $status = $postdata['status'] == 1 ? 'processed' : 'failed';
        $response_data_serialize = serialize($postdata);

        $data = array('request_id' => $request_id, 'order_id' => $order_id, 'refund_type' => $refund_type, 'payu_response' => $response_data_serialize, 'status' => $status);
        if ($refund_type == 'partial' && isset($refund_args['line_items'])) {
            $line_items = serialize($refund_args['line_items']);
            $data['items'] = $line_items;
        }
        if ($wpdb->insert($wp_payu_table, $data)) {
            return true;
        } else {
            //  var_dump($wpdb->last_error,$wpdb->print_error(),$postdata);        $response_data_serialize = serialize($postdata);

            return false;
        }
    }

    public function refund_status_callback()
    {
        register_rest_route('payu/v1', '/refund-status-update', array(
            'methods' => 'POST',
            'callback' => array($this, 'refund_status_update'),
            'permission_callback' => '__return_true'
        ));
    }

    public function refund_status_update(WP_REST_Request $request)
    {
        $parameters = json_decode($request->get_body());
        global $wpdb, $table_prefix;
        $payu_refund_transactions = 'payu_refund_transactions';
        $wp_refund_transactions_table = $table_prefix . "$payu_refund_transactions ";
        error_log("refund webhook call = " . serialize($parameters));
        if ($parameters && isset($parameters->action) && $parameters->action == 'refund') {
            $status = $parameters->status;
            $request_id = $parameters->request_id;
            if ($parameters->status == 'success' && $this->payu_refund_status_check($request_id, 'success')) {
                $refund_msg = "Your request is $status";

                $order_id = $wpdb->get_var("SELECT order_id FROM $wp_refund_transactions_table WHERE request_id = '$request_id'");
                if ($order_id) {
                    $order = wc_get_order($order_id);
                    $order->update_status('refunded');
                    $curent_date = date("Y-m-d h:i:s");
                    $wpdb->query("UPDATE $wp_refund_transactions_table SET status = 'refunded', updated_at = '$curent_date' WHERE request_id = $request_id");
                } else {
                    $refund_msg = 'Order not found';
                }
            } else {
                $refund_msg = 'request is still in queue';
            }
            error_log("refund webhook status = " . $parameters->status);
        } else {
            $refund_msg = 'request not found';
        }
        error_log("refund webhook msg = " . $refund_msg);
        return $refund_msg;
    }


    public function register_refund_in_progress_order_status()
    {
        register_post_status('wc-refund-progress', array(
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
            if ('wc-processing' === $key) {
                $new_order_statuses['wc-refund-progress'] = _x('Refund in Progress', 'Refund In-Progress', 'textdomain');
            }
        }
        // echo '<pre>';
        // print_r($new_order_statuses);
        return $new_order_statuses;
    }

    public function initiate_refund_after_verify($order)
    {
        $result = $this->payu_process_payment_refund($order);
        return $result;
    }

    public function get_product_wise_discount($product_data, $total_discount)
    {

        // Calculate the total price for all products
        $total_price = 0;
        foreach ($product_data as $product_id => $product_info) {
            $price = $product_info[0];
            $quantity = $product_info[1];
            $total_price += $price * $quantity;
        }

        // Initialize an array to store the adjusted discount for each product
        $adjusted_discounts = [];

        // Calculate the adjusted discount for each product based on their proportion of the total price
        foreach ($product_data as $product_id => $product_info) {
            $price = $product_info[0];
            $quantity = $product_info[1];

            $product_price = $price * $quantity;
            $proportion = ($product_price > 0) ? ($product_price / $total_price) : 0;
            $adjusted_discount = round($total_discount * $proportion);
            $adjusted_discounts[$product_id] = $adjusted_discount;
        }

        // Verify the total adjusted discount doesn't exceed the total discount
        $adjusted_total = array_sum($adjusted_discounts);
        if ($adjusted_total !== $total_discount) {
            // Adjust the discount for the last product to ensure the total matches
            end($adjusted_discounts);
            $last_product_id = key($adjusted_discounts);
            $adjusted_discounts[$last_product_id] += $total_discount - $adjusted_total;
        }

        return $adjusted_discounts;
    }

    public function get_a_item_discount_value($order, $selected_item_id, $total_discount)
    {
        $order_items = $order->get_items(array('line_item'));

        if ($order_items) {
            foreach ($order_items as $item_id => $item) {
                $unit_price = $item->get_subtotal() / $item->get_quantity();
                $item_quantity = $item->get_quantity();
                $item_price_details[$item_id] = array($unit_price, $item_quantity);
            }
            $adjusted_discounts = $this->get_product_wise_discount($item_price_details, $total_discount);
            if ($adjusted_discounts && isset($adjusted_discounts[$selected_item_id])) {
                return round($adjusted_discounts[$selected_item_id], 2);
            }
        }
        return false;
    }

    // Display refund links for each item
    public function payu_item_display_refund_links($item_id, $item, $order)
    {
        // Check if the item is eligible for a refund (you can customize this condition)
        $payment_method = $order->get_payment_method();
        if ($payment_method != 'payubiz') {
            return;
        }
        $refunded_item_ids = $this->payu_refund_item_ids($order);
        $order_status  = $order->get_status(); // Get the order status
        if ($item['product_id'] > 0 && $item['qty'] > 0 && !in_array($item_id, $refunded_item_ids) && ($order_status == 'processing' || $order_status == 'completed')) {
            $partial_refund_form  = '<form method="post" id="payu_partial_refund_form">';
            $partial_refund_form .= '<input type="hidden" name="custom_partial_refund_item_id" value="' . esc_attr($item_id) . '">';
            $partial_refund_form .= wp_nonce_field('payu_partial_refund_payment_nonce', 'payu_partial_refund_payment_nonce', true, false);
            $partial_refund_form .= '<input type="submit" name="custom_partial_refund_submit" class="payu-refund" value="Refund Request for this Item">';
            $partial_refund_form .= '</form>';
            echo apply_filters('payu_partial_refund_form', $partial_refund_form, $item_id, $item, $order);
        }
    }

    public function get_payu_coupon_value($order)
    {
        // Payu Coupon Discount value
        $payu_discount_value = 0;

        $fee_items = $order->get_items(array('fee'));
        $payu_discount_item_id = $order->get_meta('payu_discount_item_id');
        foreach ($fee_items as $fee_id => $fee) {

            if (isset($payu_discount_item_id) && $fee_id == $payu_discount_item_id) {
                $payu_discount_value = $fee->get_total();
            }
        }

        return $payu_discount_value;
    }

    public function payu_refund_item_ids($order)
    {
        $order_refunds = $order->get_refunds();
        $refunded_item_ids = array();
        foreach ($order_refunds as $refund) {
            // Loop through the order refund line items

            foreach ($refund->get_items() as $item_id => $item) {
                $refunded_item_ids[] = $item->get_meta('_refunded_item_id'); // line subtotal: zero or negative number
            }
        }
        return $refunded_item_ids;
    }

    /**
     * Set refund status event time.
     */

    public function payu_check_refund_status_custom_schedule($schedules)
    {
        $time = 3600; // seconds
        $schedules['payu_check_refund_status_set_crone_time'] = array(
            'interval' => $time,
            'display'  => __('Payu refund status check Every Hour', 'payu'),
        );
        return $schedules;
    }
    /**
     * Schedule event.
     *
     * @return void
     */
    public function payu_check_refund_status_scheduled_event()
    {
        // Schedule an action if it's not already scheduled.
        if (!wp_next_scheduled('payu_check_refund_status_check_next_scheduled')) {
            wp_schedule_event(time(), 'payu_check_refund_status_set_crone_time', 'payu_check_refund_status_check_next_scheduled');
        }
    }
    /**
     * Update price according to crone.
     *
     * @return void
     */
    public function payu_check_refund_status_update_data_according_to_cron()
    {
        global $table_prefix, $wpdb;
        $wc_orders = 'wc_orders';
        $wp_order_table = $table_prefix . "$wc_orders ";
        $payu_refund_transactions = 'payu_refund_transactions';
        $wp_refund_transactions_table = $table_prefix . "$payu_refund_transactions ";
        $request_data = $wpdb->get_results("SELECT rf.* FROM $wp_order_table as wo join $wp_refund_transactions_table as rf on rf.order_id = wo.id WHERE wo.status LIKE 'wc-refund-progress' AND wo.type LIKE 'shop_order' AND rf.status = 'processed'");
        if ($request_data) {
            foreach ($request_data as $request) {
                $refund_result = $this->payu_refund_all_status($request->request_id);
                if ($refund_result && $refund_result['status'] == 1) {

                    foreach ($refund_result['transaction_details'] as $transaction_detail_data) {

                        foreach ($transaction_detail_data as $transaction_detail) {
                            $status = $transaction_detail['status'];
                            if ($transaction_detail['status'] == 'success') {
                                $refund_msg = "Your request is still in the $status";
                                $order = wc_get_order($request->order_id);
                                $order->update_status('refund');
                                $wpdb->query("UPDATE $payu_refund_transactions SET status = 'refunded' WHERE id = $request->id");
                            } else {
                                $refund_msg = 'request is still in queue';
                            }
                        }
                    }
                } else {
                    $refund_msg = 'request not found';
                }

                error_log('check refund status run ' . $refund_msg);
            }
        }
    }
}

$payu_refund_process = new Payu_Refund_Process();
