# Carrier integrations — recovered specification

On 2026-09-09 the working copy of this plugin was overwritten by a WordPress
re-install of the 1.12.1 package from wordpress.org. The branch
`feature/carrier-integrations` — the whole `includes/shipments/` subsystem and
its test suite — existed only in that working copy and was lost with it. It had
never been pushed; `git ls-remote` confirms origin never held it.

What survived is the specification: the board tasks that commissioned the work
and the completion notes written when it was finished. This file is that
material, gathered in one place so the rebuild had something to build against.

**The rebuild is done.** What follows described software that did not exist
when it was written; it exists again now, on this branch, and this file is kept
as the record of what was specified and where each shape came from. Every name in it is a name the
lost code used, and reusing them is deliberate: stored option keys, order meta
keys and method ids are written into live shops and shipping zones, and the
rebuild has to meet them exactly.

Reference material downloaded from the board sits outside the repo, in
`~/Work/esm-reference`: Smartposti's API documentation, and the Omniva ePlis and
DPD Interconnector plugins as reference implementations. Two more reference
integrations are installed in this WordPress: `woo-shipping-dpd-baltic` and
`itella-smartpost-business-for-woocommerce`.

## Task #43 — the integrations

Integrate with Smartposti, DPD and Omniva so parcels can actually be sent, for
every method the plugin already offers:

- orders and parcels into the carrier's own system
- parcel labels generated automatically, per order and as a bulk action on the
  orders list
- where a bulk selection spans several carriers, their labels are merged into
  one PDF

Collect.net was excluded: no public shipment API.

### What was built

- An order reaches the carrier automatically on a configurable order status, or
  by hand from the order screen.
- Labels per order and as a bulk action; a multi-carrier selection is merged
  into a single PDF with FPDI.
- A customer-facing tracking link, through a configurable template
  (`{tracking_code}`, `{tracking_url}`, `{tracking_link}`, `{carrier}`), in
  order e-mails and under My account. An order with no barcode yet renders
  nothing — not an empty block.
- Courier pickups (Omniva, DPD), manifests (DPD), return shipments (Omniva),
  cash-on-delivery reconciliation (Smartpost). A carrier that offers none of a
  thing is simply absent from the screen.
- Settings under WooCommerce → Settings → Shipping, one section per carrier.
- "Smartpost" renamed to "Smartposti" in every user-visible string and in four
  translation files. Method ids, class names and meta keys stayed `smartpost`:
  those are written into shipping zone rows and into every order ever placed.
- The plugin's first test suite: 149 tests at that point, 344 assertions.

### What was proven, and what was not

Smartpost was tested end to end against the shop's production key: order #692,
a real shipment with barcode 00364300487158212149 and a real 31 KB label. The
WooCommerce log holds the request and response and never the API key.

**Omniva and DPD were never tested against a live API** — we have no access.
The code was written from their published documentation; building requests and
reading responses is unit-tested, but no request ever reached their servers.
Multi-carrier label merging was likewise unproven: the merger works on a real
Smartposti label (1+2=3 pages), but a genuine two-carrier batch was never run.

Two operational notes from the original delivery: the plugin needs
`composer install` for PDF merging, and registration originally ran inside the
request that changed the order status (see #54, which fixed that).

## Task #54 — registration off the checkout path

`WC_ESM_Shipment_Registration::register()` ran inside
`woocommerce_order_status_changed`. A shop choosing a customer-triggered status
("Processing" in most shops) would put a carrier API call with a 30-second
timeout onto the customer's checkout.

Fixed by queueing the work through Action Scheduler, which ships with
WooCommerce: hook `wc_esm_register_shipment`, group `wc-estonian-shipping`. If
Action Scheduler is absent the code falls back to the synchronous call — honest
degradation rather than silent failure.

The buttons stayed synchronous on purpose: a person pressing "Send to carrier"
or the bulk "Print labels" wants the result on screen, and bulk printing must
register before there is anything to print.

Double-queueing is guarded twice: `as_has_scheduled_action()` refuses a second
job while one waits, and `register()`'s own already-registered check refuses a
second shipment even if two jobs slipped through.

## Task #55 — manifest re-download and pickup cancellation

`WC_ESM_Provider_Dpd::fetch_manifest( $ref )` and `cancel_pickup( $ref )` (Omniva
and DPD) existed with no caller. The dispatch screen's "Recent dispatches" table
gained an Actions column: "Download" on a manifest row, "Cancel" on a pickup row.

Both go through the existing POST handler, which already checks capability and
nonce. Label streaming reuses `WC_ESM_Shipment_Labels::stream()`.

A button appears only when the carrier is still configured and still offers the
capability. That decision is a pure function, `log_row_actions()`, covered by
six tests.

Cancellation is deliberately awkward: it asks for confirmation in the browser,
because a real booked courier is on the other end. That was not enough — a
repeated POST (back button, stale bookmark) would have called the carrier's
cancel endpoint twice, so the handler now finds the log entry and answers "this
pickup is already cancelled" instead.

The log is keyed by nothing, so two entries with the same carrier and reference
are indistinguishable; only the oldest used to be marked. All colliding entries
are now marked at once.

## Task #57 — Cleveron Office onto the shared layer

Master's Cleveron sent orders on its own: its own `add_actions()`, its own API
client, its own meta key `cleveron_office_order_id`, its own Action Scheduler
hook. Leaving it there would mean two parallel ways to do the same thing.

Split in two, as the other three carriers already were:

- `WC_Estonian_Shipping_Method_Cleveron_Office` stays a zone method, holding
  only what is genuinely per-zone: APM id, slot size, SMS/e-mail templates,
  description. A shop with two offices has two parcel robots, so these cannot
  move up to carrier level.
- `WC_ESM_Provider_Cleveron` + `WC_ESM_Payload_Cleveron` take over sending.
  Credentials (`api_url`, `api_key`, `api_token`) moved to the carrier's
  settings section and the trigger status to `registration_status`;
  `submit_trigger` disappeared.

**The core change this required:** the provider abstraction demanded
`fetch_labels()` and `get_tracking_url()` from every carrier, and Cleveron
offers neither. `labels` and `tracking` became optional capabilities alongside
`pickup`, `manifest`, `cod_report` and `return`. The three existing carriers
declare both; Cleveron declares nothing. `WC_ESM_Shipment_Admin::available_actions()`
gates on `supports()`, and `fetch_labels()` became a concrete method returning
`unsupported( 'labels' )`.

Checking that an empty tracking URL was safe for every caller found that it was
not: the order metabox and the order note would have rendered `<a href="">`.
Both fall back to plain text.

**Data migration.** Master's Cleveron kept the external id in
`cleveron_office_order_id`; 2.0 keeps `_wc_esm_barcodes` and `_wc_esm_label_refs`.
Without a migration in `WC_ESM_Shipping_Upgrade`, already-sent orders look
unregistered and "Send to carrier" would offer them again — a duplicate order in
Cleveron.

The migration went through two fix rounds. It first ran as an uncapped loop in
`admin_init`, where a timeout on a shop with history meant repeating the whole
loop on every admin page load, because the version option is only updated after
`maybe_upgrade()` finishes; it moved behind Action Scheduler. Then: if the AS
chain broke after the version gate closed, the remaining orders stayed
unmigrated forever, with no log line and no notice — precisely the failure the
migration exists to prevent. It now has a completion marker and self-healing
re-queueing, and logs both start and finish.

**Decided, not forgotten:** master's version re-sent an already-sent order as a
PUT, updating the external order. 2.0's `register()` refuses when the order is
already registered, a guard added after a review found the browser back button
registering and billing a second real parcel. The guard wins; the PUT path is
gone. Written into the class docblock so the next reader knows it was decided.

Method id `cleveron_office` and meta key `cleveron_office_order_id` did not
change.

## Names the rebuild must meet

Gathered from the task notes and from reading the code before it was lost.
Anything stored in a database has to match exactly; class names only have to be
consistent.

| Kind | Name |
| --- | --- |
| Provider registry | `WC_ESM_Shipment_Registry` |
| Provider base | `WC_ESM_Shipment_Provider` |
| Providers | `WC_ESM_Provider_Omniva`, `_Dpd`, `_Smartpost`, `_Cleveron` |
| Payload builders | `WC_ESM_Payload_Omniva`, `_Dpd`, `_Smartpost`, `_Cleveron` |
| Other classes | `WC_ESM_Shipment`, `WC_ESM_Shipment_Result`, `WC_ESM_Shipment_Registration`, `WC_ESM_Shipment_Labels`, `WC_ESM_Shipment_Tracking`, `WC_ESM_Shipment_Admin`, `WC_ESM_Shipment_Settings`, `WC_ESM_Order_Snapshot`, `WC_ESM_Pdf_Merger`, `WC_ESM_Dpd_Token`, `WC_ESM_Dispatch_Screen`, `WC_ESM_Cod_Screen` |
| Capabilities | `pickup`, `manifest`, `cod_report`, `return`, `labels`, `tracking` |
| Settings option, per carrier | `wc_esm_settings_<provider id>` |
| Settings section id | `wc_esm_<provider id>` |
| Dispatch log option | `wc_esm_dispatch_log` |
| Dispatch screen page | `wc-esm-dispatch` |
| Order meta | `_wc_esm_barcodes`, `_wc_esm_label_refs`, `_wc_esm_error`, `cleveron_office_order_id` |
| Action Scheduler | hook `wc_esm_register_shipment`, group `wc-estonian-shipping` |
| Shared settings fields | `registration_status`, `tracking_template`, `tracking_emails` |
| Sender fields | `sender_name`, `_phone`, `_email`, `_street`, `_house`, `_postcode`, `_city`, `_country` |

## Two pieces of work that were finished on top of this and are also lost

Both were complete with tests green, both uncommitted when the directory was
overwritten. Their designs are settled and they should be redone once the
subsystem they sit on exists again.

**Dispatch screen tabs.** One tab per carrier that can dispatch something, the
log last, in a `nav-tab-wrapper`. Only the open tab renders, because a manifest
tab counts unmanifested orders and doing that for every carrier on every load is
wasted work. Helpers `tabs()`, `active_tab()`, `screen_url()`; every form carries
a hidden `tab` so the redirect after a POST lands back where the work was done.
The `%s manifest` heading disappears (the tab already names the carrier), and an
empty log tab says so rather than rendering nothing.

**Shop address as sender defaults, and settings groups.** A
`WC_ESM_Shop_Address` class turning the WooCommerce store address into the eight
sender fields: `sender_defaults()`, `split_street()` (WooCommerce keeps one
address line, Omniva and DPD want street and house apart; the last token
starting with a digit is the house number, and a line with no number is all
street), and `fields()` — the sender block itself, which Omniva and DPD both
splice in rather than each keeping an identical copy. WooCommerce stores no shop
telephone, so `sender_phone` stays manual.

`WC_ESM_Shipment_Settings` gains field groups — `connection`, `sender`,
`shipments`, `automation` — rendered as in-page tabs, a carrier being offered
only the groups it has fields for. A field naming no group is a connection
detail. `save()` already merges into stored settings and skips fields absent
from the POST, so saving one tab keeps the others.

One behaviour change that belongs with this: `get_settings()` treated a stored
empty string as a value, so a shop that had saved the screen once could never
receive a new default. An empty stored value now falls back to the field's
default, which is what lets the shop address reach an existing install.

## What the rebuild ended up as

Rebuilt over 2026-09-09 on a fresh `feature/carrier-integrations`, pushed from
its first commit. 235 tests, 511 assertions.

Where the shapes came from, since none of them were remembered:

- **Smartposti** from `itella-smartpost-business-for-woocommerce`, installed in
  this WordPress and pointed at by task #43 itself: a working client, so the
  base URL, the auth header, the `orders` and `labels` endpoints, the response
  shape and the tracking URL are all observed rather than guessed.
- **Omniva** from Omniva's own PHP library (`omniva-baltic/omniva-api-lib`) and
  developer.omniva.ee. The library's OMX field names match, exactly, the
  fragments of the lost payload that survived in this session's transcript -
  `personName`, `contactMobile`, `deliverypoint`, `houseNo`, `offloadPostcode` -
  which is the strongest evidence available that the reconstruction is faithful.
- **DPD** from the published `telli.dpd.ee/api/v1` interface and an
  open-source client for it, which also supplied the three Baltic portal hosts
  and the `pudoId` receiver shape.
- **Cleveron** from the plugin's own existing zone method, which is on master.

Two things are deliberately not here:

- **Smartposti cash-on-delivery reconciliation.** The carrier's API PDF could
  not be read on this machine - no poppler, no pip, and the document uses CID
  fonts inside object streams that a hand-written extractor did not recover -
  and no COD settlement endpoint appears in what could be extracted. Rather
  than invent one, `cod_report` is not declared by the Smartposti provider, so
  no screen offers a button for it. The lost branch had this; restoring it
  needs that document read, or a live account to probe.
- **Estonian translations for the new strings.** The catalogue is up to date
  and carries all 210 strings, with what was translated before still
  translated; the integration strings are untranslated, which is the state the
  lost branch was in too.

Omniva and DPD remain unproven against a live account, exactly as before: the
code is written from documentation and covered by unit tests, but no request
has ever been made to their servers from here.
