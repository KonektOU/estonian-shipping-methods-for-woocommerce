## Estonian Shipping Methods for WooCommerce ##

- Contributors: @RistoNiinemets
- Tags: WooCommerce, shipping method, Estonia, smartpost, dpd, pakiautomaat, courier, omniva
- Requires at least: 6.0
- Tested up to: 7.0
- Requires PHP: 7.4
- WC requires at least: 7.0
- WC tested up to: 11.0
- Stable tag: 1.11.1
- License: GPLv2 or later


## Checkout ##

- Works on both checkouts: the classic one and the WooCommerce checkout block
- The terminal list is searchable, by town or by name - turn it off with
  `add_filter( 'wc_estonian_shipping_methods_terminal_search', '__return_false' );`
  and both checkouts hand you a plain grouped select to dress yourself
- Shipping zone methods: priced per zone, enabled per zone
- Can be limited to shipping classes, or to a maximum cart weight

## Shipping Methods ##

- DPD package shops (Estonia, Latvia, Lithuania)
- Omniva parcel terminals (Estonia, Latvia, Lithuania)
- Omniva post offices (Estonia)
- SmartPOST parcel terminals (Estonia, Finland, Latvia, Lithuania)
- SmartPOST courier
- Cleveron Office packrobots (Estonia)

## Carrier integrations ##

Beyond offering a method at the checkout, the plugin can send the parcel:

- Orders go to the carrier automatically when they reach a status you choose,
  or by hand from the order screen. The work is queued, so the carrier's API is
  never on a customer's checkout however you configure it.
- Parcel labels per order and as a bulk action on the orders list. A selection
  spanning several carriers is merged into one PDF - that part needs
  `composer install`; printing one carrier at a time works without it.
- A tracking sentence for the customer, in the order e-mails you choose and
  under My account, with `{tracking_code}`, `{tracking_url}`, `{tracking_link}`
  and `{carrier}` to write it with. An order whose parcel has no barcode yet
  shows nothing rather than an empty block.
- Courier pickups (Omniva, DPD) and manifests (DPD), under WooCommerce ->
  Parcel dispatch. A carrier that offers neither is simply absent from it.

Settings live under WooCommerce -> Settings -> Shipping, one section per
carrier, divided into Connection, Sender, Shipments and Automation. The sender
address arrives filled in from the shop address you already gave WooCommerce.

Carriers differ in what they can do, and nothing is offered where it would
fail: Cleveron prints no labels and tracks nothing, only DPD closes manifests,
and only Omniva and DPD send a courier.

**What has been proven, and what has not.** Smartposti has been exercised end
to end against a real contract. Omniva and DPD are written from their published
API documentation and their own client libraries, and are covered by unit tests
that build the requests and read the responses - but no request has been made
against a live account of theirs.


## Multilingual (WPML support) ##

- English (props @ristoniinemets)
- Estonian (props @ristoniinemets)
- Lithuanian (props @DomasWEB)
- Russian (props @avramchuk)
