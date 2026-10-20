# Petish delivery pricing and shipment notifications

Working recommendation, 21 October 2026 (Australia/Melbourne).

## Recommendation

Keep instant delivery pricing at checkout. Feed it with a private, reviewed
supplier cost book, rather than treating a generic carrier calculator or an
AliExpress estimate as a guaranteed supplier charge.

Use one parcel record and one notification owner for each shipment event.
Prove the DSers inbound connection separately from email delivery. Hostinger
SMTP already works; it cannot create missing shipment information.

## What is working, and what is missing

| Area | Verified position | Gap |
|---|---|---|
| Customer delivery charges | The automatic method supports explicitly configured product variants and destinations. | The current trial covers five variants in AU/VIC/3083 only. |
| Supplier freight | The trial's A$4 per paid unit is a merchant charge. | No general supplier rate sheet or synchronized supplier-freight feed exists. |
| Customer order emails | SMTP, native WooCommerce messages, and Petish branding have passed their checks. | Shipment messages need actual parcel information and a reliable event source. |
| DSers connection | A WooCommerce API key and outbound webhooks were found. | These do not prove tracking returning from DSers. |
| Tracking receiver | AST Free rendered native partial/completed emails and two parcels in an isolated rehearsal. | Its tested API allowed duplicate records/emails, blank numbers, destructive replacement, and whole-order completion without item coverage. |

The owner previously chose automatic checkout rates instead of customer quote
requests. This work does not restore the manual quote-request journey.

## Freight: use the correct supplier's costs

Each costing scenario needs an exact product and variation, supplier listing,
destination, quantity, and shipping service. Record the supplier's product cost
and **total freight for that quantity** separately. Also record the currency,
exchange-rate assumptions, source reference, observation time, and expiry.
Postcode restrictions, warehouse, minimum order quantities, remote-area charges,
and combined-shipping conditions must be respected when they apply.

Use these sources in order of confidence:

1. A final payable supplier quote or an agreed supplier/agent rate card.
2. An authenticated supplier freight API for that supplier's actual SKU and
   warehouse, after its response and exclusions are validated.
3. A supplier calculator estimate, clearly marked as an estimate until checked.

CJ's freight calculator or API can be useful for CJ-mapped products. It does not
price an unrelated AliExpress seller's parcel. Generic Australia Post, DHL, or
other carrier rates do not establish what a dropshipping supplier will charge.
AliExpress promotions, first-order discounts, shipping thresholds, and
consolidation can change the payable amount.

Start with ten popular products and the exact variants being sold. Compare
**product plus freight**, not freight alone. Confirm Australia first, then add
other destinations as their rates are established. Do not contact suppliers,
purchase samples, or subscribe to a service merely from this plan.

## The private delivery cost sheet

The tool is live at
[WooCommerce → Delivery cost sheet](https://petish.biz/wp-admin/admin.php?page=petish-delivery-cost-sheet).
Download a current product template, fill in the exact supplier quote and cost
inputs, then upload the completed CSV to preview the results. Keep the working
CSV privately; uploaded costs are not stored between requests.

This WooCommerce administration tool downloads a catalogue-bound CSV template
and previews completed costing rows. Cost
inputs stay in the private request/download; they are not published to Git,
sent to suppliers, or saved as active checkout rates.

The calculation distinguishes:

- Supplier product cost and freight, converted to AUD using the entered rate.
- Other stated costs and any chosen freight allowance.
- Retail revenue and customer delivery revenue, excluding customer tax.
- Payment fees calculated on the actual amount charged, including customer tax.
- Contribution in AUD and contribution margin.

Contribution is not net profit: overheads, returns, unrecovered taxes, duties,
and other expenses must be entered where relevant. An unknown cost stays
unknown; a blank freight cell never means free delivery. Each row is one
standalone product/quantity scenario. Do not sum per-order fixed payment fees
across rows belonging to the same mixed order.

The preview must reject stale product mappings, changed shipping specifications,
invalid quantities and expired evidence. It must distinguish estimates from
reviewed quotes. A calculated amount is not automatically approved for sale.

### Moving from costing to automatic checkout

The existing automatic rate book uses a **per-unit customer charge**. Its
quantity behavior was deliberately approved for the small trial.

A supplier quote for two items must not be divided by two and extrapolated to
any quantity. Before activating new supplier-derived prices, add and test an
exact-quantity schedule or an explicitly supported first-unit/additional-unit
rule. Apply combined shipping only where the supplier actually supports it.
Mixed-supplier baskets must retain separate parcel costs.

Reviewed rates should retain their supplier evidence, destination/quantity
scope, specification fingerprint, and expiry. Refresh rates in the background
where an approved API is available; checkout should use a current validated
snapshot. Missing or expired coverage must not silently become free shipping.

The existing trial remains unchanged during cost-sheet preparation.

## Tracking: separate shipment registration from delivery events

| Event | Authoritative source | Customer message |
|---|---|---|
| Order paid/processing | WooCommerce payment/order state | Existing processing email |
| A parcel dispatched | Validated supplier shipment record | One shipment/partial-shipment email with carrier link |
| All physical items dispatched | Verified item quantities allocated across parcels | Completed/shipped message, according to the chosen notification owner |
| In transit, out for delivery, delivered | A real carrier-event provider | Optional milestone notifications |

A tracking number does not prove delivery. Multiple tracking numbers do not
prove that every ordered item has shipped.

For each parcel, retain a stable upstream shipment identifier when available,
carrier, tracking number, dispatch date, and item quantities. Repeated delivery
of the same update must not append another parcel or send another message.
Corrections should update the intended parcel and preserve the others.

Serialize concurrent updates for an order. Reject blank tracking numbers and
destructive “replace everything” behavior. Keep partial fulfilment until the
allocated quantities cover every physical item. The customer's account and
emails should read the same parcel records.

Use one sender for each notification type. Do not enable overlapping AST,
carrier-service, and custom dispatch messages without checking duplication.
Record submission outcomes. An SMTP timeout after acceptance can be ambiguous,
so blind retries cannot guarantee exactly-once inbox delivery.

### Work that can be proved before the real sample shipment

The isolated AST rehearsal now includes a Petish guard around the tested REST
route. Hosted run
[`39442113022`](https://github.com/nkrake6/petish/actions/runs/39442113022)
passed: it runs after native permission checks, suppresses exact replays,
rejects invalid/destructive input, and requires the fixture's actual item
quantities before completing its order. Both branded shipment messages and
the two-parcel account display passed.

This guard is an isolated receiver proof. It does not activate AST in
production and does not establish that DSers uses that REST route. Direct
metadata writes or order notes would need a different adapter.

### The remaining DSers check

Observe one genuine scheduled shipment update, or verify the actual DSers
account integration configuration and supported payload. Establish whether it
uses the shipment REST route, shared tracking metadata, or order notes.
Configure the adapter against that evidence, then verify:

1. First parcel: correct number/link, partial state, one customer notification.
2. Repeated update: no duplicate parcel or email.
3. Correction: one parcel changes; others remain.
4. Final parcel: all item quantities accounted for and correct order state.
5. Customer inbox and My Account: matching parcel details.

Keep addresses, tracking numbers, credentials, and raw order payloads in the
private order system. Publish only bounded verification results.

If full carrier milestones are wanted, evaluate a service such as TrackShip
against the real supplier carriers, parcel volume, integration behavior, and
current price. No subscription or carrier-event service is purchased or enabled
by this work.

## Release boundary

The cost-sheet tool was released in
[`39443058877`](https://github.com/nkrake6/petish/actions/runs/39443058877)
from release commit `278dbf1`, after native staging proof `39442751242`.
Exactly three application files changed. All 26 native checks passed in staging
and production, including permissions, catalogue identity, CSV handling,
calculations and unchanged orders, accounts, stock, prices and settings.
Public page/logo checks passed, protected files were preserved, and the release
was committed without rollback. The reviewed backup and private rollback
snapshot were retained.

Local verification also passed 67 cost-sheet functional checks, 150 independent
decimal comparisons and 25 release/verifier boundary tests.

Activating supplier-derived rates requires verified rate data and the matching
quantity-aware checkout behavior.

Tracking safeguards can be rehearsed now. Production shipment notifications
still require the actual DSers input path and split-shipment behavior to be
verified. The existing order-email delivery and branding remain in place.

Related evidence: [tracking readiness](TRACKING-READINESS.md),
[sample order plan](SAMPLE-ORDER-PLAN.md), and
[order-email verification](ORDER-EMAIL.md).
