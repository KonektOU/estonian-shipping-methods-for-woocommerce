=== Estonian Shipping Methods for WooCommerce ===
Contributors: konektou, ristoniinemets
Tags: woocommerce, omniva, smartpost, dpd, cleveron
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 11.0
Stable tag: 1.13.0
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
*   Cleveron Office packrobots (Estonia)

Supports WPML for multilingual sites. Current translations:

*   English (props @ristoniinemets)
*   Estonian (props @ristoniinemets)
*   Latvian
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

= 1.13.0 =
* The terminal search can be turned off. It is on by default and stays that
  way - a parcel terminal list runs to hundreds of entries - but it is also the
  one thing this plugin adds to a checkout that a theme can reasonably want to
  do differently, and two enhancers on the same select leave a mess. Filter
  `wc_estonian_shipping_methods_terminal_search` to false and both checkouts
  hand you a plain grouped select to dress with Choices.js, or with whatever
  the theme's own fields use:
  `add_filter( 'wc_estonian_shipping_methods_terminal_search', '__return_false' );`
* The classic checkout's search now names selectWoo as a dependency rather than
  hoping WooCommerce had already loaded it for something else on the page.

= 1.12.1 =
* The chosen terminal keeps its name on the order. Only the terminal's ID was
  stored, and the name was looked up afresh every time an order was shown, so a
  terminal the carrier had since closed - or a carrier that happened to be
  unreachable at that moment - left "Chosen terminal:" with nothing after it, in
  the customer's order view, the admin order screen, the order email and the PDF
  invoice alike. The name is now written onto the order at checkout and used
  when the carrier no longer knows the terminal, with the ID shown as a last
  resort instead of an empty line.
* The Latvian translation loads. It shipped under the file name lv_LV, and
  WordPress asks for Latvian as lv, so the translation was in the plugin but
  never used.

= 1.12.0 =
* Collect.net is gone. The service's API has moved on from what this plugin
  talks to, and the method spent its time making requests that no longer lead
  anywhere - on every admin page load, and again on every shipping calculation
  at the checkout. A shop that still has it in a shipping zone will find the
  method missing from that zone after the update; its old settings stay in the
  database and nothing is removed from orders that already used it.
* Cleveron Office is a shipping zone method, priced and enabled per zone like
  the rest of them.
* A carrier that cannot be reached no longer costs a request per visitor. The
  terminal list used to be re-fetched on every checkout while the carrier was
  down, because the empty result of a failed request was written to the cache
  and an empty cache read as no cache at all. A failed fetch is now left out of
  the cache, the last list the carrier gave is served in its place, and the
  carrier is left alone for five minutes before being asked again.

= 1.11.2 =
* The terminal search box on the block checkout no longer has the "Choose
  terminal" label printed across it. WooCommerce floats that label over its
  select, and the search box was sitting underneath it.

= 1.11.1 =
* The terminal list on the block checkout is grouped again, the way the
  method's "Group terminals" setting says. The searchable version briefly
  flattened it into one long list.

= 1.11.0 =
* A method can be limited to shipping classes, or to a maximum cart weight, so
  a parcel terminal is not offered for a wardrobe. Both are per zone method and
  empty by default, which is how every existing method behaves today. Asked for
  in GitHub issues #11, #29 and #30.
* Header versions say what this has actually been tested against: WordPress 7.0,
  WooCommerce 11.0, PHP 7.4 and up.

= 1.10.0 =
* The terminal list is searchable on both checkouts. A parcel terminal list runs
  to hundreds of entries, which is a lot of scrolling to find the one down the
  road. Nothing new is loaded to do it: the block checkout uses WordPress's own
  combobox and the classic one uses selectWoo, which WooCommerce already ships
  for its country and state fields.
* Fixes the terminal dropdown not appearing on the classic checkout after 1.9.0.
  A zone method's rate is "id:instance" where it used to be just "id", so the
  check for "is this the chosen method" stopped matching.

= 1.9.0 =
* The shipping methods are shipping zone methods now: added to a zone, priced
  per zone, and enabled or disabled by the zone like every other WooCommerce
  shipping method. The same carrier can cost one thing in one zone and another
  elsewhere, which was not possible before.
* Existing sites are migrated on update: every method that was switched on is
  put into the zone for the country it delivers to - the shop's existing zone
  for that country where there is one, a new zone where there is not - carrying
  its price, free shipping threshold, title and tax status with it. Methods that
  were switched off are left alone, and the old settings stay in the database.

= 1.8.0 =
* Terminal selection works on the block checkout. The dropdown was printed by a
  hook the block checkout never fires, so a shop using it had no way to choose a
  parcel terminal at all. The terminals now come through the Store API - only
  the ones belonging to the shipping method chosen - and the choice is validated
  and saved exactly where the classic checkout saves it, so orders, emails and
  the admin screen are unchanged.
* Shipping is taxable by default, as it is in WooCommerce's own shipping
  methods. A shop that never opened the setting charged no VAT on delivery.
  Methods that already exist keep whatever they were doing: the upgrade writes
  their current answer into their settings before the default changes.
* Translations are no longer loaded before WordPress is ready for them, which
  WordPress 6.7 logs as an error on every request.

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

== Upgrade Notice ==

= 1.13.0 =
Adds a filter for turning the terminal search off. Nothing changes for a shop
that does not use it.

= 1.12.1 =
Fixes orders that showed "Chosen terminal:" with no terminal after it, and
makes the Latvian translation load. Nothing to do after updating.

= 1.12.0 =
Collect.net is removed - its API no longer answers what this plugin asked of
it. If you offer Collect.net in a shipping zone, that method disappears from
the zone when you update. Every other method is unaffected.
