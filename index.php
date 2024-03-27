<?php
/*
Plugin Name: PayU India
Plugin URI: https://payu.in/
Description: Extends WooCommerce with PayU.
Version: 3.8.3
Author: PayU
Author URI: https://payu.in/
Copyright: © 2020, PayU. All rights reserved.
*/
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

/**
 * The code that runs during payu activation.
 * This action is documented in includes/class-payu-activator.php
 */

function activate_payu()
{
	require_once plugin_dir_path(__FILE__) . 'includes/class-payu-activator.php';
	Payu_Activator::activate();
}

register_activation_hook(__FILE__, 'activate_payu');

include_once(plugin_dir_path(__FILE__) . 'includes/constant.php');

include_once(plugin_dir_path(__FILE__) . 'includes/helper.php');

include_once(plugin_dir_path(__FILE__) . 'includes/payu-payment-gateway-api.php');

include_once(plugin_dir_path(__FILE__) . 'includes/payu-refund-process.php');

include_once(plugin_dir_path(__FILE__) . 'includes/payu-cart-express-checkout.php');


//$payu_register_webhook->register_webhook();

add_action('plugins_loaded', 'woocommerce_payubiz_init', 0);

function woocommerce_payubiz_init()
{

	if (!class_exists('WC_Payment_Gateway')) return;

	/**
	 * Localisation
	 */

	if (isset($_GET['msg'])) {
		if (sanitize_text_field($_GET['msg']) != '')
			add_action('the_content', 'showpayubizMessage');
	}

	function showpayubizMessage($content)
	{
		return '<div class="box ' . sanitize_text_field($_GET['type']) . '-box">' . esc_html__(sanitize_text_field($_GET['msg']), 'payubiz') . '</div>' . $content;
	}
	static $plugin;

	if (!isset($plugin)) {

		class WC_Payu
		{

			/**
			 * The *Singleton* instance of this class
			 *
			 * @var WC_Payu
			 */

			public function __construct()
			{

				$this->init();
			}

			private static $instance;

			/**
			 * Returns the *Singleton* instance of this class.
			 *
			 * @return WC_Payu The *Singleton* instance.
			 */
			public static function get_instance()
			{
				if (null === self::$instance) {
					self::$instance = new self();
				}
				return self::$instance;
			}


			/**
			 * Init the plugin after plugins_loaded so environment variables are set.
			 *
			 */
			public function init()
			{
				require_once dirname(__FILE__) . '/includes/class-wc-gateway-payu.php';
				add_filter('woocommerce_payment_gateways', [$this, 'add_gateways']);
				require_once dirname(__FILE__) . '/includes/class-payu-shipping-tax-api-calculation.php';
				require_once(plugin_dir_path(__FILE__) . 'includes/class-payu-verify-payment.php');
				require_once(plugin_dir_path(__FILE__) . 'includes/class-payu-account-address-sync.php');
				require_once(plugin_dir_path(__FILE__) . 'includes/admin/payu-webhook-calls.php');
			}

			/**
			 * Add the gateways to WooCommerce.
			 */

			public function add_gateways($methods)
			{
				$methods[] = WC_Payubiz::class;

				return $methods;
			}
		}

		$plugin = WC_Payu::get_instance();
	}

	return $plugin;
}
