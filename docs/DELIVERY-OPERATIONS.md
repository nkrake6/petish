# Private delivery operations

Current development candidate: Petish Fulfilment **0.7.0**, 23 October 2026.
The 0.6.0 rate preview and outbox baseline passed the staging checks recorded
below. This extends the live 0.5.0 cost sheet. It does not activate new delivery
prices or a DSers connection.

## Customer shipment view and staff email previews

The 0.7.0 candidate adds a shipment section to native WooCommerce order details.
It requires the signed-in owner, the matching My Account order endpoint or
signed-in order-received page, and the separate
`petish_shipment_customer_tracking_enabled` option set explicitly to `yes`.
That option defaults off. No records or no valid parcels means no extra section.

Customer cards show the latest saved carrier, tracking link, dispatch date and
allocated item quantities. Product labels come from the order lines. Supplier
identifiers, notification failures, private evidence and customer contact details
are not included. Physical requirements must still match before a full-dispatch
summary can be shown. Tracking information does not establish delivery.

Staff can open **Preview email** beside an event in the Shipment notifications
tab. The private, nonce-protected preview opens separately and clearly states
that no message was sent. Its customer email uses the saved event snapshot;
older previews can therefore differ from the latest corrected account view.
The staff preview label and internal event metadata sit outside the customer
message. The customer message uses the native WooCommerce wrapper and existing
Petish email branding, including the approved animal logo.

Viewing a parcel or previewing an email creates no notification attempt and
changes no order state. The customer-view option is independent of ingestion
and sending, so disabling a sender need not hide previously saved tracking.

## Staff rate preview

On private staging, open
[WooCommerce → Delivery operations](https://staging.petish.biz/wp-admin/admin.php?page=petish-delivery-operations).
The Rate preview tab downloads a current catalogue-bound CSV template. Fill in
the supplier evidence, destination, quantity and separately reviewed customer
charge, then upload it to preview. Each row is an independent scenario. The
page has no save or publish action; keep completed working CSVs privately.

Two pricing rules are supported:

- **Exact quantity:** the stated total applies only to that number of the exact
  product option. A quote for two items never establishes a price for three.
- **First item + additional items:** a reviewed supplier rate card must state
  the first-item and additional-item charges and the supported quantity range.
  The calculation is first + (quantity − 1) × additional, within that range.

Supplier freight stays in its stated currency, separate from the customer
charge in AUD excluding tax. Blank freight is unknown. An explicitly free
customer charge needs a separate confirmation and does not approve a supplier
free-delivery badge.

Rows pin the native product/variation, SKUs, supplier listing and current
shipping specifications. Evidence expires within seven days; a countrywide
rule requires an explicit countrywide assertion. Changed products, missing
coverage, overlapping matches and expired evidence produce no rate. Distinct
products and suppliers retain independent charges; no parcel consolidation is
assumed.

The runtime accepts a version 2 reviewed rate book through the existing native
automatic shipping method. Version 1 keeps its existing per-unit behavior.
There is no rate-book migration, default rule or automatic publication. A
version 2 book with a rejected row is blocked as a whole. Its shipping cache
token changes with the validated book, and cached rates are checked again.

## Parcel and notification records

The new ledger stores one private, versioned record on a native WooCommerce
order, using order metadata for both HPOS and the older order store. A trusted
adapter must supply a stable supplier/shipment ID, increasing revision, carrier,
tracking number/link, dispatch time and actual order-line quantities.

An exact replay does nothing. A conflicting or stale revision is rejected.
Corrections preserve other parcels and the notification history. Partial and
fully dispatched states follow physical item quantities; neither means
delivered. The first record pins the physical order lines and quantities.
Later quantity or physical/virtual changes block sending and updates until
reconciled; they never silently reduce the dispatch requirements. Recording a
parcel does not change the native order status.

Each notification retains its immutable parcel snapshot, claim, attempts and
outcome. The sender claims the event under an order lock before submission.
Only a definite failure before submission is safe to queue again. A timeout,
exception or expired sending claim becomes **uncertain**; it is never retried
automatically. Staff must check the provider evidence and that the prior
sending process has stopped before resolving it.

The Shipment notifications tab can inspect a selected order and show these
outcomes. Its nonce-protected controls require `manage_woocommerce`. Queueing
a retry does not itself send a message. A sender must separately process the
pending event. Transport acceptance is recorded as submitted; it does not
prove inbox delivery.

## Activation boundary

Both `petish_shipment_ledger_enabled` and
`petish_shipment_notifications_enabled` default off. Merely loading the plugin
does not enable either option. There is no default sender, background worker,
public ingestion route, or guessed DSers/AST metadata hook. A request must
explicitly register one sender with a stable identity. The renderer uses
WooCommerce's branded mail wrapper without sending a message.

Production continues to use its approved five-option AU/VIC/3083 trial and
existing order emails. Before any live activation:

1. Obtain current supplier evidence for each exact route, option and quantity.
2. Identify the actual DSers incoming payload and item allocation behavior.
3. Implement and rehearse that adapter and one notification owner, including
   correction, replay, concurrency, uncertain submission and split parcels.
4. Verify the matching customer account display and a separately authorized
   inbox test. Choose a carrier-event provider only if live delivery milestones
   are required.
5. Use a dedicated production release scope, matching staging proof and fresh
   recovered backup. The old three-file cost-sheet release is insufficient for
   this larger candidate.

No supplier API calls, subscriptions, real orders, payments or customer emails
are part of this development candidate.

## Verification

Focused offline tests cover quantity rules, CSV/admin boundaries and outbox
transitions. The disposable native harness in
[`scripts/test-fulfilment-native.py`](../scripts/test-fulfilment-native.py)
uses fresh WordPress/WooCommerce data and cached immutable container images.
Both database and application containers have networking disabled; they share
only a private database socket. HTTP, SMTP, cron and asynchronous work are
blocked. Synthetic products and orders never reach a hosted store.

The separate `verify-delivery-operations` GitHub branch checks the exact
installed staging source, private guard, permissions, default-off controls and
read-only runtime behavior. It does not install application code, create hosted
fixtures, activate rates or send mail. Its screened receipt is separate from
the native local persistence proof and from production release evidence.

Local validation on 23 October passed 90 quantity-rate checks, 57 staff-tool
checks, 47 outbox transition/interruption checks and the existing 67 cost-sheet
checks. All 53 delivery Python tests passed, including the 11 staging proof
boundary tests. The unchanged 0.5.0 production release rejects this broader
candidate; its positive transaction test uses the immutable reviewed 0.5.0
source. PHP syntax, workflow lint, package creation and application credential
screening also passed.

The fresh native integration run passed **102 named checks in classic order
storage and 102 in HPOS**, on WordPress 7.1.3 / WooCommerce 11.2.1 / PHP 8.3 /
MariaDB 11.4. It verified actual metadata persistence and database-lock
contention, claims saved before the capture-only transport, split-parcel email
snapshots, corrections, controlled retry and uncertainty handling, pinned
physical requirements, native CSV/admin rendering and the hosted verifier's
helper contracts. Exactly two synthetic native mail calls were captured per
mode; no SMTP delivery occurred. Fixture cleanup passed in both modes and the
owned disconnected containers, database and scratch files were removed. This
proves native Woo rendering; production Petish branding remains covered by its
existing separate email release evidence.

Private staging deployed exact source
`db103249888f356c539fad451986ec211812308d` in
[run 39918552784-1](https://github.com/nkrake6/petish/tree/automation-status/staging-39918552784-1).
Every deployment step passed. The subsequent
[read-only acceptance run 39918723658-1](https://github.com/nkrake6/petish/tree/automation-status/delivery-operations-39918723658-1)
passed all **51 checks** and matched all 15 installed Fulfilment files to that
source. A suitable existing catalogue product was available, so exact-quantity,
first/additional, currency, cache and catalogue CSV scenarios all ran in memory.
Both shipment flags remained off and no sender was configured. Orders, users,
stock, prices, settings, passports and private media were unchanged. No hosted
order transitions or external DSers tracking were exercised, and all temporary
verification tools were removed.

Source and notes are saved on
[`development/delivery-operations`](https://github.com/nkrake6/petish/tree/development/delivery-operations).
`main` and production remain on the existing approved releases.

### 0.7.0 customer presentation validation

The updated fresh native proof passed **146 checks in classic storage and 146
in HPOS**. It covered signed-in ownership, anonymous/other-account/staff denial,
exact native endpoint binding and stylesheet URL, corrected parcel links,
saved order-line names and escaping, generic wording after requirement
changes, flags-off staff previews and the real Petish header, footer, animal
logo and shared email CSS. The preview checks ran behind a strict database-write
barrier with zero mail calls. All owned fixtures and the disconnected sandbox
were removed.

All 55 delivery Python tests and both shipment tests passed. PHP syntax,
workflow lint and application credential screening passed. An independent
privacy and permission review found no blockers.

Local Chromium checks used only rendered synthetic fixtures, with all network
requests intercepted. Customer cards passed at 1100px and 390px in light and
dark mode; partial dispatch and full/correction emails also passed at mobile
width. There was no horizontal overflow, tracking actions were at least 44px
high, desktop actions aligned, and the animal logo rendered successfully. The
actual staff response also rendered its sandboxed iframe under the exact
security policy without a policy violation. These checks verify the component
and email shell, rather than the entire The7 account page.
