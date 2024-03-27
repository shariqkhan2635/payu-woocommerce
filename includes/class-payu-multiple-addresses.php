<?php

/**
 * Payu Multiple Addresses.

 */

class Payu_Multiple_addresses
{

	/**
	 * Plugin version, used for cache-busting of style and script file references.
	 *
	 * @since   1.0.7.1
	 *
	 * @var     string
	 */
	const VERSION = '1.0.7.1';

	/**
	 * Unique identifier for the plugin.
	 *
	 * Use this value (not the variable name) as the text domain when internationalizing strings of text. It should
	 * match the Text Domain file header in the main plugin file.
	 *
	 * @since    1.0.0
	 *
	 * @var      string
	 */
	protected static $plugin_slug = 'woocommerce-multiple-addresses';

	/**
	 * Instance of this class.
	 *
	 * @since    1.0.0
	 *
	 * @var      object
	 */
	protected static $instance = null;
	protected $site_url;


	/**
	 * Initialize the plugin by setting filters and administration functions.
	 *
	 * @since     1.0.4
	 */
	private function __construct()
	{
		$this->site_url = get_site_url();
		// Activate plugin for newly added blog on multisite
		add_action('wpmu_new_blog', array($this, 'activate_new_site'));

		// Load public-facing style sheet.
		add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
		add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

		// Change 'edit' link on My Account page to lead on our 'edit address' page
		add_action('woocommerce_before_my_account', array($this, 'rewrite_edit_url_on_my_account'), 25);

		// Create a shortcode to show content on 'Manage addresses' page
		add_shortcode('woocommerce_multiple_shipping_addresses', array($this, 'multiple_shipping_addresses'));

		// Process saving on 'Manage addresses' page
		add_action('template_redirect', array($this, 'save_multiple_shipping_addresses'));

		// Show a 'configure addresses' button on checkout
		add_action('woocommerce_before_checkout_form', array($this, 'before_checkout_form'));

		// Save billing and shipping addresses as default when creating a new customer aco
		add_action('woocommerce_created_customer', array($this, 'created_customer_save_shipping_as_default'));

		// Add a dropdown to choose an address
		add_filter('woocommerce_checkout_fields', array($this, 'add_dd_to_checkout_fields'));

		// Add ajax handler for choosing shipping address on checkout
		add_action('wp_ajax_alt_change', array($this, 'ajax_checkout_change_shipping_address'));
		add_action('wp_ajax_nopriv_alt_change', array($this, 'ajax_checkout_change_shipping_address'));

		// Filter shipping country value
		add_filter('woocommerce_checkout_get_value', array($this, 'wma_checkout_get_value'), 10, 2);

		// Register new endpoint (manager address) for My Account page
		add_action('init', array($this, 'payu_add_multiple_address_manage_endpoint'));

		// Add content to the new tab
		add_action('woocommerce_account_manage-addresses_endpoint', array($this, 'payu_add_multiple_address_manage_content'), 10, 2);

		// Add new query var (manage Addresses)
		add_filter('query_vars', array($this, 'payu_add_multiple_address_manage_query_vars'), 0);

		// Insert the new endpoint (manage Addresses) into the My Account menu
		add_filter('woocommerce_account_menu_items', array($this, 'payu_add_multiple_address_manage_link_my_account'), 10, 2);

		// Delete shipping Address
		add_action('wp_ajax_delete_address', array($this, 'delete_address'));
		add_action('wp_ajax_nopriv_delete_address', array($this, 'delete_address'));

		// Add shipping address as billing

		add_action('init', array($this, 'set_default_shipping_address_as_billing_address'));
	}





	/**
	 * Get all blog ids of blogs in the current network that are:
	 * - not archived
	 * - not spam
	 * - not deleted
	 *
	 * @since    1.0.4
	 *
	 * @return   array|false    The blog ids, false if no matches.
	 */
	private static function get_blog_ids()
	{

		global $wpdb;

		// get an array of blog ids
		$sql = "SELECT blog_id FROM $wpdb->blogs
			WHERE archived = '0' AND spam = '0'
			AND deleted = '0'";

		return $wpdb->get_col($sql);
	}





	/**
	 * Return an instance of this class.
	 *
	 * @since     1.0.0
	 *
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance()
	{

		// If the single instance hasn't been set, set it now.
		if (null == self::$instance) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Register and enqueue public-facing style sheet.
	 *
	 * @since    1.0.4
	 */
	public function enqueue_styles()
	{
		wp_enqueue_style(self::$plugin_slug . '-plugin-styles', plugins_url('assets/css/public.css', dirname(__FILE__)), array(), self::VERSION);
	}

	/**
	 * Register and enqueues public-facing JavaScript files.
	 *
	 * @since    1.0.6
	 */
	public function enqueue_scripts()
	{
		wp_enqueue_script('payu-country-select', WP_CONTENT_URL . '/plugins/woocommerce/assets/js/frontend/country-select.min.js', array('jquery'), self::VERSION, true);
		wp_enqueue_script(self::$plugin_slug . '-plugin-script', plugins_url('assets/js/public.js', dirname(__FILE__)), array('jquery'), self::VERSION);
		wp_localize_script(
			self::$plugin_slug . '-plugin-script',
			'WCMA_Ajax',
			array(
				'ajaxurl'               => admin_url('admin-ajax.php'),
				'id'                    => 0,
				'payu_multiple_addresses' => wp_create_nonce('payu-multiple-addresses-ajax-nonce')
			)
		);
	}


	/**
	 * Point edit address button on my account to edit multiple shipping addresses
	 *
	 * @since    1.0.6
	 */
	public function rewrite_edit_url_on_my_account()
	{
		$page_id  = wc_get_page_id('multiple_shipping_addresses');
		$page_url = get_permalink($page_id);
?>
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				$('.woocommerce-account .col-2.address .title a').attr('href', '<?php echo $page_url; ?>');
			});
		</script>
	<?php
	}


	/**
	 * Filter shipping country value
	 *
	 * @param $null
	 * @param $input
	 *
	 * @since    1.0.6
	 *
	 * @return mixed
	 */
	public function wma_checkout_get_value($null, $input)
	{
		global $wma_current_address;

		if (!empty($wma_current_address)) {
			foreach ($wma_current_address as $key => $value) {
				if ($input == $key) {
					return $value;
				}
			}
		}
	}

	/**
	 * Multiple shipping addresses page
	 *
	 * @since    1.0.7.1
	 */
	public function multiple_shipping_addresses()
	{
		global $woocommerce;

		$GLOBALS['wma_current_address'] = '';

		if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
			require_once $woocommerce->plugin_path() . '/classes/class-wc-checkout.php';
		} else {
			require_once $woocommerce->plugin_path() . '/includes/class-wc-checkout.php';
		}

		$user     = wp_get_current_user();
		$checkout = WC()->checkout();
		$shipFields = $checkout->checkout_fields['shipping'];

		if ($user->ID == 0) {
			return;
		}

		$otherAddr = get_user_meta($user->ID, 'payu_multiple_shipping_addresses', true);
		echo '<div class="woocommerce">';
		echo '<form action="" method="post" id="address_form">';
		if (!empty($otherAddr)) {
			echo '<div id="addresses">';

			global $wma_current_address;
			echo '<div class="tab">';
			foreach ($otherAddr as $idx => $address) {
				$addr_tag_value = ($address['addr_tag']) ? $address['addr_tag'] : 'other';
				echo '<button class="tablinks" onclick="showAdd(event, ' . $idx . ')">' . $address['shipping_first_name'] . ' ' . $address['shipping_last_name'] . '<sup><span class="tag">' . $addr_tag_value . '</span></sup></button>';
			}
			echo '</div>';

			foreach ($otherAddr as $idx => $address) {
				$wma_current_address = $address;
				$is_home_selected = ($address['addr_tag'] == 'home') ? "checked" : "";
				$is_work_selected = ($address['addr_tag'] == 'work') ? "checked" : "";
				$is_other_selected = ($address['addr_tag'] == 'other' || $address['addr_tag'] == '') ? "checked" : "";

				echo '<div class="shipping_address address_block tabcontent" id="shipping_address_' . $idx . '" data-addid="' . $idx . '" data-id="' . $idx . '">';
				echo '<p class="form-row address-tag" id="addr_tag_field" data-priority="">
						<span class="woocommerce-input-wrapper">
						<input type="hidden" value="' . $idx . '"/>
						<input type="radio" class="input-radio " value="home" name="addr_tag[' . $idx . '][]" id="addr_tag_home" ' . $is_home_selected . '>
						<label for="addr_tag_home" class="radio ">Home</label>

						<input type="radio" class="input-radio " value="work" name="addr_tag[' . $idx . '][]" id="addr_tag_work" ' . $is_work_selected . '>
						<label for="addr_tag_work" class="radio ">Work</label>

						<input type="radio" class="input-radio " value="other" name="addr_tag[' . $idx . '][]" id="addr_tag_other" ' . $is_other_selected . '>
						<label for="addr_tag_other" class="radio ">Other</label>
						</span>
					</p>';
				echo '<p class="delBtn" align="right"><a href="#" class="delete aa">' . __('delete', self::$plugin_slug) . '</a></p>';
				do_action('woocommerce_before_checkout_shipping_form', $checkout);

				// print_r($address);
				$label['id'] = 'label';
				$label['label'] = __('Label', self::$plugin_slug);
				woocommerce_form_field('label[]', $label, $address['label']);

				foreach ($shipFields as $key => $field) {

					if ('shipping_alt' == $key) {
						continue;
					}

					$val = '';
					if (isset($address[$key])) {
						$val = $address[$key];
					}

					$field['id'] = $key;
					$key .= '[]';
					woocommerce_form_field($key, $field, $val);
				}

				if (!wc_ship_to_billing_address_only() && get_option('woocommerce_calc_shipping') !== 'no') {
					$is_checked = $address['shipping_address_is_default'] == 'true' ? "checked" : "";
					echo '<input type="checkbox" class="default_shipping_address" ' . $is_checked . ' value="' . $address['shipping_address_is_default'] . '"> ' . __('Mark this address as default', self::$plugin_slug);
					echo '<input type="hidden" class="hidden_default_shipping_address" name="shipping_address_is_default[]" value="' . $address['shipping_address_is_default'] . '" />';
				}

				do_action('woocommerce_after_checkout_shipping_form', $checkout);
				echo '</div>';
			}
			echo '</div>';
		} else {

			echo '<div id="addresses">';

			foreach ($shipFields as $key => $field) :
				$field['id'] = $key;
				$key .= '[]';
				woocommerce_form_field($key, $field, $checkout->get_value($field['id']));
			endforeach;

			if (!wc_ship_to_billing_address_only() && get_option('woocommerce_calc_shipping') !== 'no') {
				echo '<input type="checkbox" class="default_shipping_address" checked value="true"> ' . __('Mark this shipping address as default', self::$plugin_slug);
				echo '<input type="hidden" class="hidden_default_shipping_address" name="shipping_address_is_default[]" value="true" />';
			}

			echo '</div>';
		}
		echo '<div class="form-row right-align">
                <input type="hidden" name="shipping_account_address_action" value="save" />
                <input type="submit" name="set_addresses" value="' . __('Save Addresses', self::$plugin_slug) . '" class="button alt" />
                <a class="add_address" href="#">' . __('Add another', self::$plugin_slug) . '</a>
            </div>';
		echo '</form>';
		echo '</div>';
	?>
		<!-- Changes in Address display and Myaccount navigations -->
		<style>
			* {
				box-sizing: border-box;
			}

			.woocommerce-account .woocommerce-MyAccount-navigation {
				float: left;
				width: 100%;
			}

			.woocommerce-account .woocommerce-MyAccount-content {
				float: right;
				width: 100%;
			}

			.woocommerce-account .woocommerce-MyAccount-navigation ul {
				margin: 0 0 2rem;
				padding: 0;
				display: flex;
				flex-wrap: wrap;
				flex-direction: row;
				justify-content: center;
				gap: 20px;
			}

			.woocommerce-account .woocommerce-MyAccount-navigation li {
				list-style: none;
				padding: 10px;
			}

			#addresses>div {
				border-bottom: none;
			}

			.tab {
				float: left;
				background-color: #f1f1f1;
				width: 30%;
				height: 100%;
			}

			.tab button {
				display: block;
				background-color: inherit;
				color: black;
				padding: 22px 16px;
				width: 100%;
				border: none;
				outline: none;
				text-align: left;
				cursor: pointer;
				transition: 0.3s;
			}

			.tab button:hover {
				background-color: #ddd;
			}

			.tab button.active {
				background-color: #ccc;
			}

			.tabcontent {
				float: left;
				padding: 0px 12px;
				width: 70%;
				border-left: none;
				height: 100%;
			}

			.form-row.right-align {
				text-align: right;
				justify-content: flex-end;
			}

			a.add_address {
				margin: 0px 20px;
			}

			.address-tag label.radio {
				margin: 0px;
			}

			.address-tag span.woocommerce-input-wrapper {
				display: inline-flex;
				justify-content: center;
				align-items: center;
				gap: 10px;
			}

			span.tag {
				margin-left: 5px;
				background: yellow;
				padding: 2px 6px;
				border-radius: 25px;
				font-size: 10px;
			}
		</style>
		<script type="text/javascript">
			var tmpl = '<div class="shipping_address address_block"><p align="right"><a href="#" class="delete tt"><?php _e("delete", self::$plugin_slug); ?></a></p>';

			tmpl += '<?php $label['id'] = 'label';
						$label['label'] = __('Label', self::$plugin_slug);
						$row = woocommerce_form_field('label[]', $label, '');
						if ($row) {
							echo str_replace("\n", "\\\n", str_replace("'", "\'", $row));
						}

						?>';

			tmpl += '<?php foreach ($shipFields as $key => $field) :
							if ('shipping_alt' == $key) {
								continue;
							}
							$field['return'] = true;
							$val = '';
							$field['id'] = $key;
							$key .= '[]';
							$row = woocommerce_form_field($key, $field, $val);
							echo str_replace("\n", "\\\n", str_replace("'", "\'", $row));
						endforeach; ?>';

			<?php if (!wc_ship_to_billing_address_only() && get_option('woocommerce_calc_shipping') !== 'no') : ?>
				tmpl += '<input type="checkbox" class="default_shipping_address" value="false"> <?php _e("Mark this shipping address as default", self::$plugin_slug); ?>';
				tmpl += '<input type="hidden" class="hidden_default_shipping_address" name="shipping_address_is_default[]" value="false" />';
			<?php endif; ?>

			tmpl += '</div>';
			jQuery(".add_address").click(function(e) {
				e.preventDefault();

				jQuery("#addresses").append(tmpl);
				console.log(tmpl);
				jQuery('html,body').animate({
						scrollTop: jQuery('#addresses .shipping_address:last').offset().top
					},
					'slow');
			});



			jQuery(document).ready(function() {
				jQuery(".delete").on("click", function(e) {
					e.preventDefault();
					let addr_block = jQuery(this).parents('div.address_block');
					let addressId = addr_block.data('id');
					// console.log(addressId);
					var data = {
						action: "delete_address",
						addressId: addressId
					};
					let url = '<?php echo $this->site_url; ?>/wp-admin/admin-ajax.php';
					jQuery.post(url, data, function(result) {
						addr_block.remove();
						// console.log(result);
					});

				});
				jQuery(document).on("click", ".default_shipping_address", function() {
					if (this.checked) {
						jQuery("input.default_shipping_address").not(this).removeAttr("checked");
						jQuery("input.default_shipping_address").not(this).val("false");
						jQuery("input.hidden_default_shipping_address").val("false");
						jQuery(this).next().val('true');
						jQuery(this).val('true');
					} else {
						jQuery("input.default_shipping_address").val("false");
						jQuery("input.hidden_default_shipping_address").val("false");
					}
				});

				jQuery("#address_form").submit(function() {
					var valid = true;
					jQuery("input[type=text],select").each(function() {
						if (jQuery(this).prev("label").children("abbr").length == 1 && jQuery(this).val() == "") {
							jQuery(this).focus();
							valid = false;
							return false;
						}
					});
					return valid;
				});

				// Make "Other as default value for address tag"
				jQuery("#addr_tag_other").prop("checked", true);
			});

			// Change Address form
			function showAdd(evt, address) {
				var i, tabcontent, tablinks;
				evt.preventDefault();
				tabcontent = document.getElementsByClassName("tabcontent");
				for (i = 0; i < tabcontent.length; i++) {
					tabcontent[i].style.display = "none";
				}
				tablinks = document.getElementsByClassName("tablinks");
				for (i = 0; i < tablinks.length; i++) {
					tablinks[i].className = tablinks[i].className.replace(" active", "");
				}
				document.querySelector(`[data-addid="${address}"]`).style.display = "block";
				evt.currentTarget.className += " active";
			}

			// to make first address active 
			window.onload = function() {
				tabcontent = document.getElementsByClassName("tabcontent");
				tablinks = document.getElementsByClassName("tablinks");

				for (i = 0; i < tabcontent.length; i++) {
					tabcontent[i].style.display = "none";
				}
				tabcontent[0].style.display = "block";
				tablinks[0].className += " active";
			};
		</script>
<?php
	}

	public function delete_address()
	{
		$addressId = $_POST['addressId'];
		$user     = wp_get_current_user();
		$user_id = $user->ID;
		$address_list = get_user_meta($user_id, 'payu_multiple_shipping_addresses', true);
		if ($address_list[$addressId]['shipping_address_is_default'] == 'true') {
			$args = array(

				'shipping_address_1' => '',
				'shipping_address_2' => '',
				'shipping_city' => '',
				'shipping_company' => '',
				'shipping_country' => '',
				'shipping_email' => '',
				'shipping_first_name' => '',
				'shipping_last_name' => '',
				'shipping_postcode' => '',
				'shipping_state' => '',

			);
			foreach ($args as $key => $value) {
				update_user_meta($user_id, $key, $value);
			}
		}
		unset($address_list[$addressId]);
		$existing_addresses = array_values($address_list);
		update_user_meta($user_id, 'payu_multiple_shipping_addresses', $existing_addresses);
	}
	public function set_default_shipping_address_as_billing_address()
	{
		$user     = wp_get_current_user();

		if (!$user || is_wp_error($user)) {
			return; // Return if the user is not available.
		}
		$user_id = $user->ID;
		$address_list = get_user_meta($user_id, 'payu_multiple_shipping_addresses', true);
		if (!$address_list) {
			$customer = new WC_Customer($user_id);
			$args = array(

				'shipping_address_1' => $customer->get_billing_address_1(),
				'shipping_address_2' => $customer->get_billing_address_2(),
				'shipping_city' => $customer->get_billing_city(),
				'shipping_company' => $customer->get_billing_company(),
				'shipping_country' => $customer->get_billing_country(),
				'shipping_email' => $customer->get_billing_email(),
				'shipping_first_name' => $customer->get_billing_first_name(),
				'shipping_last_name' => $customer->get_billing_last_name(),
				'shipping_postcode' => $customer->get_billing_postcode(),
				'shipping_state' => $customer->get_billing_state(),

			);
			foreach ($args as $key => $value) {
				update_user_meta($user_id, $key, $value);
			}
			$default_address[0] = $args;
			$default_address[0]['shipping_address_is_default'] = 'true';
			$default_address[0]['label'] = '';


			update_user_meta($user_id, 'payu_multiple_shipping_addresses', $default_address);
		}
	}


	/**
	 * Save multiple shipping addresses
	 *
	 * @since    1.0.3
	 */
	public function save_multiple_shipping_addresses()
	{

		if (isset($_POST['shipping_account_address_action']) && $_POST['shipping_account_address_action'] == 'save') {
			unset($_POST['shipping_account_address_action']);

			$addresses  = array();
			$is_default = false;
			foreach ($_POST as $key => $values) {
				if ($key == 'shipping_address_is_default') {
					foreach ($values as $idx => $val) {
						if ($val == 'true') {
							$is_default = $idx;
						}
					}
				}
				if (!is_array($values)) {
					continue;
				}

				foreach ($values as $idx => $val) {
					if ($key == 'addr_tag') {
						$addresses[$idx][$key] = $val[0];
					} else {
						$addresses[$idx][$key] = $val;
					}
				}
			}

			$user = wp_get_current_user();

			if ($is_default !== false) {
				$default_address = $addresses[$is_default];

				$args = array(
					'billing_first_name' => $default_address['shipping_first_name'],
					'billing_last_name' => $default_address['shipping_last_name'],
					'billing_city' => $default_address['shipping_city'],
					'billing_company' => $default_address['shipping_company'],
					'billing_country' => $default_address['shipping_country'],
					'billing_address_1' => $default_address['shipping_address_1'],
					'billing_address_2' => $default_address['shipping_address_2'],
					'billing_postcode' => $default_address['shipping_postcode'],
					'billing_state' => $default_address['shipping_state'],
				);

				// Update Billing Address as Default shipping address
				foreach ($args as $key => $field) :
					update_user_meta($user->ID, $key, $field);
				endforeach;

				foreach ($default_address as $key => $field) :
					if ($key == 'shipping_address_is_default') {
						continue;
					}
					update_user_meta($user->ID, $key, $field);
				endforeach;
			}
			update_user_meta($user->ID, 'payu_multiple_shipping_addresses', $addresses);

			if (version_compare(WOOCOMMERCE_VERSION, '2.1', '<')) {
				global $woocommerce;
				$woocommerce->add_message(__('Addresses have been saved', self::$plugin_slug));
			} else {
				wc_add_notice(__('Addresses have been saved', self::$plugin_slug), $notice_type = 'success');
			}

			$page_id = wc_get_page_id('myaccount');
			wp_redirect(get_permalink($page_id));
			exit;
		}
	}

	/**
	 * Add possibility to configure addresses on checkout page
	 *
	 * @since    1.0.4
	 */
	public function before_checkout_form()
	{
		global $woocommerce;

		$page_id = wc_get_page_id('multiple_shipping_addresses');
		if (is_user_logged_in()) {
			echo '<p class="woocommerce-info woocommerce_message">
	                ' . __('If you have more than one shipping address, then you may choose a default one here.', self::$plugin_slug) . '
	                <a class="button" href="' . get_permalink($page_id) . '">' . __('Configure Address', self::$plugin_slug) . '</a>
	              </p>';
		}
	}

	/**
	 * Helper function to prepend value to an array with custom key
	 *
	 * @param $arr
	 * @param $key
	 * @param $val
	 *
	 * @since    1.0.4
	 *
	 * @return array
	 */
	public function array_unshift_assoc(&$arr, $key, $val)
	{
		$arr         = array_reverse($arr, true);
		$arr[$key] = $val;

		return array_reverse($arr, true);
	}

	/**
	 * Creating the same default shipping for newly created customer
	 *
	 * @since    1.0.0
	 *
	 * @param    integer $current_user_id
	 */
	public function created_customer_save_shipping_as_default($current_user_id)
	{
		global $woocommerce;
		if ($current_user_id == 0) {
			return;
		}

		$checkout        = $woocommerce->checkout->posted;
		$default_address = array();
		if ($checkout['shiptobilling'] == 0) {
			$default_address[0]['shipping_country']    = $checkout['shipping_country'];
			$default_address[0]['shipping_first_name'] = $checkout['shipping_first_name'];
			$default_address[0]['shipping_last_name']  = $checkout['shipping_last_name'];
			$default_address[0]['shipping_company']    = $checkout['shipping_company'];
			$default_address[0]['shipping_address_1']  = $checkout['shipping_address_1'];
			$default_address[0]['shipping_address_2']  = $checkout['shipping_address_2'];
			$default_address[0]['shipping_city']       = $checkout['shipping_city'];
			$default_address[0]['shipping_state']      = $checkout['shipping_state'];
			$default_address[0]['shipping_postcode']   = $checkout['shipping_postcode'];
		} elseif ($checkout['shiptobilling'] == 1) {
			$default_address[0]['shipping_country']    = $checkout['billing_country'];
			$default_address[0]['shipping_first_name'] = $checkout['billing_first_name'];
			$default_address[0]['shipping_last_name']  = $checkout['billing_last_name'];
			$default_address[0]['shipping_company']    = $checkout['billing_company'];
			$default_address[0]['shipping_address_1']  = $checkout['billing_address_1'];
			$default_address[0]['shipping_address_2']  = $checkout['billing_address_2'];
			$default_address[0]['shipping_city']       = $checkout['billing_city'];
			$default_address[0]['shipping_state']      = $checkout['billing_state'];
			$default_address[0]['shipping_postcode']   = $checkout['billing_postcode'];
		}
		$default_address[0]['shipping_address_is_default'] = 'true';
		$default_address[0]['label'] = '';
		update_user_meta($current_user_id, 'payu_multiple_shipping_addresses', $default_address);
	}

	/**
	 * Add dropdown above shipping address at checkout
	 *
	 * @param    $fields
	 *
	 * @since    1.0.7
	 *
	 * @return   mixed
	 */
	public function add_dd_to_checkout_fields($fields)
	{
		global $current_user;

		$otherAddrs = get_user_meta($current_user->ID, 'payu_multiple_shipping_addresses', true);
		if (!$otherAddrs) {
			return $fields;
		}

		$addresses    = array();
		$addresses[0] = __('Choose an address...', self::$plugin_slug);
		// print_r($otherAddrs);
		for ($i = 1; $i <= count($otherAddrs); ++$i) {
			if (!empty($otherAddrs[$i - 1]['label'])) {
				$addresses[$i] = $otherAddrs[$i - 1]['label'] . ' ' . $otherAddrs[$i - 1]['shipping_postcode'];
			} else {
				$addresses[$i] = $otherAddrs[$i - 1]['shipping_first_name'] . ' ' . $otherAddrs[$i - 1]['shipping_last_name'] . ', ' . $otherAddrs[$i - 1]['shipping_postcode'] . ' ' . $otherAddrs[$i - 1]['shipping_city'];
			}
		}

		$alt_field = array(
			'label'    => __('Predefined addresses', self::$plugin_slug),
			'required' => false,
			'class'    => array('form-row'),
			'clear'    => true,
			'type'     => 'select',
			'options'  => $addresses
		);

		$fields['shipping'] = $this->array_unshift_assoc($fields['shipping'], 'shipping_alt', $alt_field);
		$fields['billing'] = $this->array_unshift_assoc($fields['billing'], 'billing_alt', $alt_field);

		return $fields;
	}

	/**
	 * Handles ajax action call on choosing shipping address on checkout
	 *
	 * @since    1.0.4
	 */
	public function ajax_checkout_change_shipping_address()
	{

		// check nonce
		// $nonce = $_POST['payu_multiple_addresses'];
		$nonce = $_POST['wc_multiple_addresses'];
		if (!wp_verify_nonce($nonce, 'payu-multiple-addresses-ajax-nonce')) {
			die('Busted!');
		}

		$address_id = $_POST['id'] - 1;
		if ($address_id < 0) {
			return;
		}

		// get address
		global $current_user;
		$otherAddr = get_user_meta($current_user->ID, 'payu_multiple_shipping_addresses', true);

		global $woocommerce;
		$addr                          = $otherAddr[$address_id];
		$addr['shipping_country_text'] = $woocommerce->countries->countries[$addr['shipping_country']];
		$response                      = json_encode($addr);

		// response output
		header("Content-Type: application/json");
		echo $response;

		exit;
	}

	public function payu_add_multiple_address_manage_link_my_account($items)
	{
		$items['manage-addresses'] = 'Manage Your Addresses';
		return $items;
	}

	public function payu_add_multiple_address_manage_endpoint()
	{
		add_rewrite_endpoint('manage-addresses', EP_ROOT | EP_PAGES);
	}

	public function payu_add_multiple_address_manage_query_vars($vars)
	{
		$vars[] = 'manage-addresses';
		return $vars;
	}

	public function payu_add_multiple_address_manage_content()
	{
		echo '<h3>Manage Your Addresses</h3>';
		echo do_shortcode('[woocommerce_multiple_shipping_addresses]');
	}
}
