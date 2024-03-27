<?php

// get pages
function payu_get_pages($title = false, $indent = true)
{
	$wp_pages = get_pages('sort_column=menu_order');
	$page_list = array();
	if ($title) $page_list[] = $title;
	foreach ($wp_pages as $page) {
		$prefix = '';
		// show indented child pages?
		if ($indent) {
			$has_parent = $page->post_parent;
			while ($has_parent) {
				$prefix .=  ' - ';
				$next_page = get_page($has_parent);
				$has_parent = $next_page->post_parent;
			}
		}
		// add to page list array array
		$page_list[$page->ID] = $prefix . $page->post_title;
	}
	return $page_list;
}

function payu_insert_event_logs($request_type, $method, $url, $request_header, $request_data, $status, $response_header, $response_data)
{
	global $table_prefix, $wpdb;
	$tblname = 'payu_event_logs';
	$wp_payu_table = $table_prefix . "$tblname";
	$table_data = array('request_type' => $request_type, 'request_method' => $method, 'request_url' => $url, 'request_headers' => serialize($request_header), 'request_data' => serialize($request_data), 'response_status' => $status, 'response_headers' => serialize($response_header), 'response_data' => serialize($response_data));
	if (!$wpdb->insert($wp_payu_table, $table_data)) {
		error_log('event log data insert error =', $wpdb->last_error);
	}
}

function shift_element_after_assoc($array, $keyToShift, $keyAfter)
{
	// Check if keys exist in the array
	if (!array_key_exists($keyToShift, $array) || !array_key_exists($keyAfter, $array)) {
		return $array; // Return the original array if keys do not exist
	}

	// Remove the element to be shifted
	$removedElement = array($keyToShift => $array[$keyToShift]);
	unset($array[$keyToShift]);

	// Find the new position for the element
	$newPosition = array_search($keyAfter, array_keys($array)) + 1;

	// Insert the removed element after the specified element
	$array = array_merge(
		array_slice($array, 0, $newPosition, true),
		$removedElement,
		array_slice($array, $newPosition, count($array), true)
	);

	return $array;
}

function create_user_and_login_if_not_exist($email)
{
	// Check if the user already exists
	$user = get_user_by('email', $email);
	if ($user === false) {
		// User does not exist, create a new user
		$password = wp_generate_password();
		$user_id = wp_create_user($email, $password, $email);

		// Check if user creation was successful
		if (!is_wp_error($user_id)) {
			// User created successfully, log in the user
			$user = get_user_by('id', $user_id);
			wp_set_current_user($user_id, $user->user_login);
			wp_set_auth_cookie($user_id);
			do_action('wp_login', $user->user_login, $user);

			return true;
		} else {
			return false;
		}
	} else {
		return true;
	}
}

// Calculate proportions and adjust prices
function adjust_prices_for_discount($prices, $discount)
{
	$adjustedPrices = [];
	foreach ($prices as $price) {
		// Calculate proportion of each item's price to the total price
		$totalOriginalPrice = array_sum($prices);
		$proportion = $price / $totalOriginalPrice;

		// Distribute the discount proportionally among the items based on their proportions
		$discountForItem = $proportion * $discount;

		// Subtract the adjusted discount from each item's original price to get the adjusted price
		$adjustedPrice = $price - $discountForItem;

		// Store adjusted price
		$adjustedPrices[] = number_format($adjustedPrice, 2);
	}
	return $adjustedPrices;
}

function get_state_code_by_name($state, $country = 'IN')
{
	$countries_obj = new WC_Countries();
	$wc_states_list = $countries_obj->get_states($country);
	return array_search($state, $wc_states_list);
}


function check_payu_address_sync($user_id)
{
	global $table_prefix, $wpdb;
	$tblname = 'payu_address_sync';
	$wp_payu_address_table = $table_prefix . "$tblname";

	$address_sync_data = $wpdb->get_results("select address_type from $wp_payu_address_table where user_id = $user_id and address_type IS NOT NULL");
	if ($address_sync_data && count($address_sync_data) == 1) {
		return array('sync' => true, 'address_type' => $address_sync_data[0]->address_type == 'billing' ? array('shipping') : array('billing'));
	} else if ($address_sync_data && count($address_sync_data) > 1) {
		return array('sync' => false, 'address_type' => false);
	} else if (!$address_sync_data) {
		return array('sync' => true, 'address_type' => array('billing', 'shipping'));
	}
}

function get_customer_address_payu($user_id)
{

	// Get the user object/*  */
	$user = new WC_Customer($user_id);

	// Get the billing and shipping addresses
	// Get additional address details
	$billing_first_name = $user->get_billing_first_name();
	$billing_last_name = $user->get_billing_last_name();
	$billing_address_1 = $user->get_billing_address_1();
	$billing_address_2 = $user->get_billing_address_2();
	$billing_city = $user->get_billing_city();
	$billing_state = $user->get_billing_state();
	$billing_postcode = $user->get_billing_postcode();
	$billing_country = $user->get_billing_country();
	$billing_phone = $user->get_billing_phone();
	$billing_email = $user->get_billing_email();

	$shipping_first_name = $user->get_shipping_first_name();
	$shipping_last_name = $user->get_shipping_last_name();
	$shipping_address_1 = $user->get_shipping_address_1();
	$shipping_address_2 = $user->get_shipping_address_2();
	$shipping_city = $user->get_shipping_city();
	$shipping_state = $user->get_shipping_state();
	$shipping_postcode = $user->get_shipping_postcode();
	$shipping_country = $user->get_shipping_country();
	$shipping_phone = $user->get_shipping_phone();
	//$shipping_email = $user->get_shipping
	$customer_address = array(
		'billing' => array(
			'billing_first_name' => $billing_first_name,
			'billing_last_name' => $billing_last_name,
			'billing_address_1' => $billing_address_1,
			'billing_address_2' => $billing_address_2,
			'billing_city' => $billing_city,
			'billing_state' => $billing_state,
			'billing_postcode' => $billing_postcode,
			'billing_country' => $billing_country,
			'billing_phone' => $billing_phone,
			'billing_email' => $billing_email,
		),
		'shipping' => array(
			'shipping_first_name' => $shipping_first_name,
			'shipping_last_name' =>  $shipping_last_name,
			'shipping_address_1' =>    $shipping_address_1,
			'shipping_address_2' =>   $shipping_address_2,
			'shipping_city' =>       $shipping_city,
			'shipping_state' =>      $shipping_state,
			'shipping_postcode' =>   $shipping_postcode,
			'shipping_country' => $shipping_country,
			'shipping_phone' => $shipping_phone,
			'shipping_email' => ''
		)
	);
	return $customer_address;
}

function allow_to_checkout_from_cart($mode,$current_guest_checkout = ''){
	
	if($mode == 'change'){
		add_option('woocommerce_enable_guest_checkout_old',$current_guest_checkout);
		update_option('woocommerce_enable_guest_checkout','yes');
	} else if ($mode == 'revert'){
		$woocommerce_guest_checkout_old_val = get_option('woocommerce_enable_guest_checkout_old');
		
		if($woocommerce_guest_checkout_old_val){
			update_option('woocommerce_enable_guest_checkout',$woocommerce_guest_checkout_old_val);
			delete_option('woocommerce_enable_guest_checkout_old',$current_guest_checkout);
		}

	}
	
}
