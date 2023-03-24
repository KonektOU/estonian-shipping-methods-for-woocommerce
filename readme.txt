=== Estonian Shipping Methods for WooCommerce ===
Contributors: konektou, ristoniinemets
Tags: WooCommerce, shipping method, Estonia, smartpost, dpd, pakiautomaat, courier, omniva
Requires at least: 4.1
Tested up to: 6.1.1
Stable tag: 1.7.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Extends WooCommerce with most commonly used Estonian shipping methods. All in one.

== Description ==

This plugin consists of several Estonian shipping methods:

*   DPD package shops (Estonia, Latvia, Lithuania)
*   Omniva parcel terminals (Estonia, Latvia, Lithuania)
*   Omniva post offices (Estonia)
*   SmartPOST parcel terminals (Estonia, Finland, Latvia, Lithuania)
*   SmartPOST courier
*   Collect.net packrobots (Estonia)

Supports WPML for multilingual sites. Current translations:

*   English (props @ristoniinemets)
*   Estonian (props @ristoniinemets)
*   Lithuanian (props @DomasWEB)
*   Russian (props @avramchuk)


Code is maintained and developed at Github. Contributions and discussions are very welcome at [Github](https://github.com/KonektOU/estonian-shipping-methods-for-woocommerce)


== Installation ==

1. Upload `estonian-shipping-methods-for-woocommerce` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce - Settings
4. Shipping Methods will be available to be configured in "Shipping" settings

== Screenshots ==

1. Example of Itella SmartPOST shipping method
2. WooCommerce Checkout page

== Frequently Asked Questions ==

= How to display customer selected terminal in custom locations? =
Since version 1.5.1 we have added an action that you could add to your code:
`do_action( 'wc_estonian_shipping_method_show_terminal', $order_id );`

== Changelog ==

= 1.7.2 =
* Fix Smartpost location not shown

= 1.7.1 =
* Add support for older orders locations (SmartPost)

= 1.7 =
* Use DPD API for pickup locations instead of soon-to-be-deprecated FTP json
* Use Smartpost API for pickup locations
* Add Smartpost Latvia
* Add Smartpost Lithuania

= 1.6.2 =
* Compatibility with WooCommerce CRUD, High-Performance order storage (COT)

= 1.6.0 =
* Relocate terminal methods hooks for compatibility with other plugins
* Add version tag to templates, clean up templates
* Removed use of deprecated WC property

= 1.5.9 =
* Change DPD terminals source URL

= 1.5.8 =
* Add PHP 7.4 compatbility (thanks to @lemmeV)

= 1.5.7 =
* Fix admin order preview with SmartPOST courier
* Tweak Collect.net API relationships

= 1.5.6 =
* Fix compatibility with older versions of WooCommerce. Previous version introduced conflict.

= 1.5.5 =
* Tweak free shipping amount to take discounted prices into account

= 1.5.4 =
* Fix Collect.net availability in other countries than Estonia (should not be available)

= 1.5.3 =
* Fix dropdown selection text (mixed labels)

= 1.5.2 =
* WooCommerce 3.3 compatibility and terminal information in admin order preview

= 1.5.1 =
* Compatibility with WooCommerce PDF Invoices & Packing Slips plugin
* Added custom action that developers can hook into to show the customer selected terminal

= 1.5 =
* Compatibility with servers that have "allow_url_fopen" PHP configration turned off.
* Extra option whether each shipping method allows free shipping via coupons.

= 1.4.2 =
* Fix notice with Collect.net AGAIN

= 1.4.1 =
* Fix: Sometimes terminals were not fetched and shown in customers email

= 1.4 =
* Fix notice with Collect.net while it’s not being used
* Make phone number country code validation available for all methods
* Use phone number country code validation for DPD package shops

= 1.3.2 =
* Create collect.net session only on administration interface

= 1.3.1 =
* Compatibility with WooCommerce 3.0.x

= 1.3 =
* Added Collect.net packrobots
* Cleaned up code

= 1.2.1 =
* Added Lithuanian (thanks to @DomasWEB) and Russian translations (thanks to @avramchuk)

= 1.2 =
* Fixed mixed up translations in Estonian
* Omniva Latvia, Lithuania: City name fix (thanks to @DomasWEB)
* Latvia, Lithuania: Added cities by population for "Bigger cities first, then alphabetically the rest" option to work

= 1.1 =
* Added shipping methods to readme
* Added DPD package shops for Estonia, Latvia, Lithuania

= 1.0 =
* Release
