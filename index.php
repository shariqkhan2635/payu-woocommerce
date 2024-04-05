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

function activatePayu()
{
	require_once plugin_dir_path(__FILE__) . 'includes/class-payu-activator.php';
	PayuActivator::activate();
}

register_activation_hook(__FILE__, 'activatePayu');

require_once plugin_dir_path(__FILE__) . 'includes/constant.php';

require_once plugin_dir_path(__FILE__) . 'includes/helper.php';

require_once plugin_dir_path(__FILE__) . 'includes/payu-payment-gateway-api.php';

require_once plugin_dir_path(__FILE__) . 'includes/payu-refund-process.php';

require_once plugin_dir_path(__FILE__) . 'includes/payu-cart-express-checkout.php';

add_action('plugins_loaded', 'woocommercePayubizInit', 0);

function woocommercePayubizInit()
{

	if (!class_exists('WC_Payment_Gateway')) {
		return null;
	}

	/**
	 * Localisation
	 */

	if (isset($_GET['msg']) && sanitize_text_field($_GET['msg']) != '') {
		add_action('the_content', 'showpayubizMessage');
	}

	function showpayubizMessage($content)
	{
		return '<div class="box ' . sanitize_text_field($_GET['type']) . '-box">' .
			esc_html__(sanitize_text_field($_GET['msg']), 'payubiz') .
			'</div>' . $content;
	}
	static $plugin;

	if (!isset($plugin)) {

		class WcPayu
		{

			/**
			 * The *Singleton* instance of this class
			 *
			 * @var WcPayu
			 */

			public function __construct()
			{

				$this->init();
			}

			private static $instance;

			/**
			 * Returns the *Singleton* instance of this class.
			 *
			 * @return WcPayu The *Singleton* instance.
			 */
			public static function getInstance()
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
				require_once dirname(__FILE__) . '/includes/class-payu-payment-validation.php';
				require_once dirname(__FILE__) . '/includes/class-wc-gateway-payu.php';
				add_filter('woocommerce_payment_gateways', [$this, 'addGateways']);
				require_once dirname(__FILE__) . '/includes/class-payu-shipping-tax-api-calculation.php';
				require_once plugin_dir_path(__FILE__) . 'includes/class-payu-verify-payment.php';
				require_once plugin_dir_path(__FILE__) . 'includes/class-payu-account-address-sync.php';
				require_once plugin_dir_path(__FILE__) . 'includes/admin/payu-webhook-calls.php';
			}

			/**
			 * Add the gateways to WooCommerce.
			 */

			public function addGateways($methods)
			{
				$methods[] = WcPayubiz::class;

				return $methods;
			}
		}

		$plugin = WcPayu::getInstance();
	}

	return $plugin;
}
