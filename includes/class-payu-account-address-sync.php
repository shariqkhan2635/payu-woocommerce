<?php

class PayuAccountAddressSync extends PayuPaymentGatewayAPI
{

    protected $payu_merchant_id;

    protected $payu_salt;

    protected $payu_key;

    protected $gateway_module;

    public function __construct()
    {
        $plugin_data = get_option('woocommerce_payubiz_settings');
        $this->payu_merchant_id = $plugin_data['payu_merchant_id'];
        $this->payu_salt = $plugin_data['currency1_payu_salt'];
        $this->gateway_module = $plugin_data['gateway_module'];
        $this->payu_key = $plugin_data['currency1_payu_key'];

        add_action("woocommerce_after_save_address_validation", array($this, 'schedule_account_address_push'), 1, 2);
        add_action('pass_arguments_to_save_address', array($this, 'payu_save_address_callback'), 10, 3);
        add_action('pass_arguments_to_update_address', array($this, 'payu_update_address_callback'), 10, 4);
        add_action('woocommerce_created_customer', array($this, 'custom_save_shipping_phone'));
        add_action('woocommerce_save_account_details', array($this, 'custom_save_shipping_phone'));
        add_filter('woocommerce_shipping_fields', array($this, 'custom_woocommerce_shipping_fields'), 1);
        add_action('wp_login', array($this, 'payu_address_sync_after_login'), 10, 1);
        add_action('woocommerce_receipt_payubiz',array($this,'payu_address_sync_brefore_payment'),1);
    }

    public function schedule_account_address_push($user_id, $address_type)
    {
        global $wpdb, $table_prefix;
        date_default_timezone_set('Asia/Kolkata');


        $schedule_time = time() + 10;

        $payu_address_table = 'payu_address_sync';
        $wp_payu_address_table = $table_prefix . "$payu_address_table";
        $payu_address_data = $wpdb->get_row("select payu_address_id,payu_user_id from $wp_payu_address_table
         where user_id = $user_id and address_type = '$address_type'");

        if ($payu_address_data) {
            error_log("addrees run update");
            $args = array($user_id, $_POST, $address_type, $payu_address_data);
            if (!wp_next_scheduled('pass_arguments_to_update_address', $args)) {
                wp_schedule_single_event($schedule_time, 'pass_arguments_to_update_address', $args);
            }
        } else {
            error_log("addrees run insert");
            $args = array($user_id, $_POST, $address_type);
            if (!wp_next_scheduled('pass_arguments_to_save_address', $args)) {
                wp_schedule_single_event($schedule_time, 'pass_arguments_to_save_address', $args);
            }
        }
    }
    public function payu_save_address_callback($user_id, $address, $address_type)
    {
        error_log('user cron save address =' . serialize($address));
        $result = $this->payu_save_address($address, $address_type, $user_id);
        if ($result && isset($result->status) && $result->status == 1) {
            $this->payu_insert_saved_address($user_id, $result, $address_type);
        }
    }

    public function payu_update_address_callback($user_id, $address, $address_type, $payu_address_data)
    {
        error_log('user cron address =' . serialize($address));
        $this->payu_update_address($address, $address_type, $payu_address_data, $user_id);
    }

    public function payu_insert_saved_address($user_id,  $address, $address_type)
    {
        global $table_prefix, $wpdb;
        $tblname = 'payu_address_sync';
        $payu_address_id = $address->result->shippingAddress->id ?? null;
        $payu_user_id = $address->result->userId ?? null;
        $wp_payu_table = $table_prefix . "$tblname";
        $table_data = array(
            'user_id' => $user_id,
            'payu_address_id' => $payu_address_id,
            'payu_user_id' => $payu_user_id,
            'address_type' => $address_type
        );
        error_log("address table insert query " . serialize($table_data));
        if (!$wpdb->insert($wp_payu_table, $table_data)) {
            error_log('event log data insert error = '. $wpdb->last_error);
        }
    }

    // Add phone number field to WooCommerce shipping address
    public function custom_woocommerce_shipping_fields($fields)
    {
        $fields['shipping_phone'] = array(
            'label'     => __('Phone Number', 'woocommerce'),
            'required'  => true,
            'class'     => array('form-row-wide'),
            'clear'     => true,
        );

        $fields = shift_element_after_assoc($fields, 'shipping_phone', 'shipping_address_2');

        return $fields;
    }

    public function check_payu_address_sync($user_id)
    {
        global $table_prefix, $wpdb;
        $tblname = 'payu_address_sync';
        $wp_payu_address_table = $table_prefix . "$tblname";

        $address_sync_data = $wpdb->get_results("select address_type from $wp_payu_address_table
         where user_id = $user_id and address_type IS NOT NULL");
        if($address_sync_data && count($address_sync_data) == 1){
            return array(
                'sync' => true,
                'address_type' => $address_sync_data[0]->address_type=='billing'?array('shipping'):array('billing'));
        } elseif ($address_sync_data && count($address_sync_data) > 1) {
            return array('sync' => false,'address_type' => false);
        } elseif (!$address_sync_data) {
            return array('sync' => true,'address_type' => array('billing','shipping')); 
        }
    }

    public function payu_address_sync_brefore_payment() {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            return; // Exit early if no user ID
        }
        
        error_log("address sync run before payment");
        
        $sync_data = check_payu_address_sync($user_id);
        
        if (!$sync_data['sync']) {
            return; // Exit early if sync is not required
        }
        
        $addresses = get_customer_address_payu($user_id);
        
        if (!$addresses) {
            return; // Exit early if no addresses found
        }
        
        if (isset($addresses['billing']) && in_array('billing', $sync_data['address_type'])) {
            $this->process_address_sync($addresses['billing'], 'billing', $user_id);
        }
        
        if (isset($addresses['shipping']) && in_array('shipping', $sync_data['address_type'])) {
            $this->process_address_sync($addresses['shipping'], 'shipping', $user_id);
        }
    }
    
    private function process_address_sync($address, $type, $user_id) {
        $result = $this->payu_save_address($address, $type, $user_id);
        
        if ($result && isset($result->status) && $result->status == 1) {
            $this->payu_insert_saved_address($user_id, $result, $type);
        }
        
        return $result;
    }
    

    public function payu_address_sync_after_login($user_login) {
        $user = get_user_by('login', $user_login);
        if($user){
            $user_id = $user->ID;
            $sync_data = check_payu_address_sync($user_id);
            
            
            if (!$sync_data['sync']) {
                return; // Exit early if sync is not required
            }
            
            $addresses = get_customer_address_payu($user_id);
            
            if (!$addresses) {
                return; // Exit early if no addresses found
            }
            
            $schedule_time = time() + 10;
            
            $this->schedule_address_sync($user_id, $addresses, $sync_data, 'billing', $schedule_time);
            $this->schedule_address_sync($user_id, $addresses, $sync_data, 'shipping', $schedule_time);
        }
        
    }
    
    private function schedule_address_sync($user_id, $addresses, $sync_data, $type, $schedule_time) {
        if (isset($addresses[$type]) && in_array($type, $sync_data['address_type'])) {
            $args = array($user_id, $addresses[$type], $type);
            if (!wp_next_scheduled('pass_arguments_to_save_address', $args)) {
                wp_schedule_single_event($schedule_time, 'pass_arguments_to_save_address', $args);
            }
        }
    }
    

    // Save the phone number to the user meta
    function custom_save_shipping_phone($user_id)
    {
        if (isset($_POST['shipping_phone'])) {
            update_user_meta($user_id, 'shipping_phone', sanitize_text_field($_POST['shipping_phone']));
        }
    }
}

$payu_account_address_sync = new PayuAccountAddressSync();
