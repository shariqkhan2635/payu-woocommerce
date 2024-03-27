=== PayU Multi-Currency Plugin ===
Contributors: ()
Donate Link:
Tags: payment, gateway, payu, plugin
Requires at least: 5.3
Tested up to: 5.8
Stable tag: 5.6.1
Requires PHP: 7.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-Currency payment plugin by PayU Payment Gateway (India)for WooCommerce (tested from 3.8 to 5.5.2).

== Description ==

Caution: Always keep backup of your existing WooCommerce installation including Mysql Database, before installing a new module.

Note: WooCommerce does not have built-in multi-currency feature. Hence it is important to use separate multi-currency plugin for WooCommerce. This plugin uses currency present in Order.

The plugin zip can be easily installed using Wordpress's upload plugin feature.

== Frequently Asked Questions ==

= How many different currencies can be configured? =

Maximum 10 different currencies can be configured. For each currency, key and salt needs to be entered.

= Is there any dependency on other specific plugin apart from WooCommerce? =

No. This plugin needs WooCommerce, as that is the sole purpose of the plugin to facilitate payment. Apart from that the plugin does not depend directly on any other plugin.
But if multi-currency interface needed on store front then additional plugin may be required.

== Screenshots ==

1. screenshot-1: Upload and install/activate PayU payment plugin to Wordpress.
2. screenshot-2: Configure PayU payment plugin under WooCommerce - Settings - Payments tab.
3. screenshot-3: Enable/Disable plugin, plugin description to display in checkout, Gateway Mode (Sandbox/Production), Currency 1 (Name, Key and Salt).
4. screenshot-4: Currency name, key and Salt for Currency 2.
8. screenshot-8: Return Page in case of error occured.
9. screenshot-9: Checkout page showing PayU as payment option.
10. screenshot-10: Billing/Shipping details validation error.
11. screenshot-11: PayU payment page for making payment.
12. screenshot-12: Payment error posted back by PayU payment gateway.
13. screenshot-13: After successful payment, control redirected to WooCommerce order success page.

== Changelog ==
= 3.8.3 =
* Improved session handling

= 3.8.2 =
* Adhering to latest Wordpress and WooCommerce technologies. 
* Fixing Wordpress coding standard.

= 3.8.1 =
* Adhering to latest Wordpress and WooCommerce technologies.
* Introduced inquiry api to doubly verify payment apart from previously coded signature validations.
* 'samesite' cookie parameter management introduced to take care of latest browser secuirty.

= 3.0 =
Custom order success page introduced.

= 2.0 =
Request and response signature validations introduced.

= 1.0 =
New plugin developed for WooCommerce v3.3.4.

== Upgrade Notice ==

= 3.8.0 =
Upgrade to latest version v3.8.1 to install multi-currency key,salt feature.


