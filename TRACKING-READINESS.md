# Petish shipment tracking

The owner has scheduled supplier/stock/freight confirmation and the real sample
order. This work prepares the tracking receiver and notification path without
placing an order or contacting suppliers.

## Current connection

Read-only production audit
[`39386682602`](https://github.com/nkrake6/petish/actions/runs/39386682602),
20 October 2026, found one previously accessed DSers REST key with read/write
permission and five active DSers webhooks. The hooks send WooCommerce order and
product events to DSers. They do not demonstrate tracking returning to the store.
No recognized tracking plugin or known shipment-tracking metadata was present.
This does not rule out DSers writing tracking in order notes.

## Isolated native receiver rehearsal

The initial hosted run
[`39387940791`](https://github.com/nkrake6/petish/actions/runs/39387940791)
passed on 20 October 2026 using Petish commit `3e35a83`.
[Screened results](https://github.com/nkrake6/petish/blob/tracking-rehearsal/39387940791-1/status.json)
confirm the native REST permission checks, partial and completed emails with the
approved animal logo, two-parcel account display and removal of both the order
and carrier fixture. No supplier or payment action occurred. Stored plugin
configuration remained unchanged; the temporary source was removed. The normal
four-plugin staging preflight also passed in run `39387940751`.

That initial run's risk observations were **true**: a replay appended another tracking record
and repeated the partial-shipment email, and the native API accepted a blank
number. It characterized the unguarded API; those results remain part of the
history.

The protected hosted run
[`39442113022`](https://github.com/nkrake6/petish/actions/runs/39442113022),
attempt 1, passed using Petish commit
`ca1b8006d3a6207c38a2b9640ae7f385282892f9`.
Its [screened results](https://github.com/nkrake6/petish/blob/tracking-rehearsal/39442113022-1/status.json)
contain all 43 required **true** checks and five required **false** checks.
With the private receiver guard installed for the synthetic fixture, replays
neither appended parcels nor repeated emails, blank numbers were rejected,
destructive replacement was rejected, and premature completion was rejected.
The existing native REST permission checks, branded partial and completed
emails, two-parcel account view, isolation and fixture cleanup all passed.

The three earlier risk observations are now **false in this guarded
rehearsal**. `account_is_live` and `tracking_external_dsers_push_verified`
are also **false**. No production tracking receiver was installed or activated,
and no real customer email was sent. This establishes a protected synthetic
AST path, not a verified DSers connection.

The workflow [Rehearse Petish shipment tracking on private staging](https://github.com/nkrake6/petish/actions/workflows/tracking-rehearsal.yml)
evaluates Advanced Shipment Tracking Free 4.0.4 from the
[official source](https://github.com/zorem/woo-advanced-shipment-tracking/tree/c22a8dad01045779ec247fe830943eaddeaa266f),
pinned to commit `c22a8dad01045779ec247fe830943eaddeaa266f` and archive SHA256
`353805462f5d88f064fb032c016fb947c3610531d0b59eae026d808be86f3736`.
Its compatibility list includes DSers, but neither that list nor this test
identifies the transport used by Petish's DSers account.

The runner verifies every plugin file against the reviewed archive and checks
the uploaded rehearsal files against their source SHA256 hashes. It loads the
plugin only in the isolated CLI request and removes its temporary files afterwards.
The stored active-plugin list remains unchanged. Settings are request-local;
the synthetic carrier table must be absent beforehand and is removed afterwards.
The normal activation and old migrations are not run. This source contains an
old migration that writes sample tracking to post 1, and a direct remote image
download outside WordPress's HTTP API. Migration callbacks are suppressed and
PHP remote file access is disabled for the request.

The existing staging guard, separate database, non-live payment account, zero
active webhooks and private mail capture remain required. All outbound HTTP is
blocked throughout the test. No payment, supplier order or real carrier query
is performed. The only order is a newly tagged synthetic customer order with
product ID zero; cleanup can delete only that owned, unpaid fixture.

The private [receiver guard](scripts/petish-tracking-receiver-guard.php) registers
nothing merely by being included. The rehearsal installs it explicitly only
after creating and verifying its owned synthetic order. It intercepts the
native AST create route after WordPress permission checks, rechecks the native
permission and exact order ID, and serializes parcel reads and writes using a
database lock. Its carrier and tracking-link rules accept only the synthetic
fixture.

The protected test uses the real AST REST controller internally, with normal
capability checks, then native status/email and My Account rendering:

1. Anonymous and customer requests must be denied without adding tracking.
2. First parcel leaves the order partially shipped and generates a captured,
   branded partial-shipment email with its tracking link.
3. An exact authenticated replay returns the same tracking record without
   appending a parcel, changing status or generating another email.
4. Blank tracking input and `replace_tracking=1` are rejected while preserving
   the first parcel and partial status.
5. A final-parcel request is rejected until a trusted CLI-only allocation plan
   covers the actual synthetic order line and its quantities. Request fields
   cannot grant that permission. The plan is a staging fixture, not DSers
   allocation data or a production parcel ledger.
6. The accepted final parcel retains both tracking numbers, completes the
   synthetic order, and includes both links in the captured completed email
   and account view. Its exact replay also creates no duplicate or email.
7. The database lock is verified released, and the owned order and carrier
   fixtures are removed.

Only strictly validated boolean evidence is published to
`tracking-rehearsal/<run>-<attempt>/status.json`. Private order identifiers,
messages, customer data and credentials are excluded. The protected workflow
requires its safety checks to pass and the duplicate/blank-input observations
to be false. It does not mean production tracking is enabled.

## Remaining release checks

Fulfilment 0.6.0 now provides a development implementation of the private native
order parcel ledger and durable notification outbox, with staff lookup,
evidence-based uncertainty resolution and confirmed-unsent retry queueing.
There is no sender/worker or DSers adapter, and both feature flags default off.
See [Delivery operations](docs/DELIVERY-OPERATIONS.md) for its separate local
native and read-only hosted verification scope. This does not activate AST or
replace the real-sample DSers checks below.

The 0.7.0 candidate adds the customer presentation layer: owner-only native
order shipment cards and staff email previews using the existing Petish animal
logo and branded shell. The customer-view flag also defaults off. Previews
create no send attempt; current account tracking and historical notification
snapshots remain distinct. These features can be tested with owned synthetic
orders before the real supplier update arrives.

- Establish whether DSers writes shared `_wc_shipment_tracking_items` metadata,
  uses a shipment-tracking REST route, or sends only order notes. Verify the
  actual DSers account settings or one incoming sample shipment; do not invent
  a dashboard setting or assume the AST REST test proves DSers authentication.
  If temporary observation is needed, restrict it to the approved sample order
  and retain only the route/method and field names/types needed to identify
  the integration; exclude credentials, raw bodies and customer data.
- Repeated updates must not duplicate customer messages or parcel records.
  Free AST's append path does not deduplicate; its README lists API duplicate
  protection as a PRO feature. Exact replay protection is now demonstrated
  with the private guard, but it covers only the tested AST REST path. Decide
  the production remedy against the actual DSers payload and update behavior;
  metadata or order-note writes would bypass this guard.
- Reject empty numbers and preserve existing parcels on corrections. In the
  reviewed unguarded AST API, `replace_tracking=1` clears every existing parcel.
  The rehearsal now rejects that operation; it does not implement safe
  correction of one existing parcel.
- An order with outstanding parcels must stay partial. `status_shipped=1`
  completes the whole order; free AST does not map quantities to individual
  parcels. The rehearsal proves a completion gate using actual fixture
  quantities. Production still needs reliable supplier parcel allocations and
  confirmation of DSers' status behavior with the scheduled split shipment.
- Connect and rehearse the new persistent notification state and operator
  retry path against the actual sender and incoming adapter. Suppressing
  successful request replays or claiming an event is not proof of inbox
  delivery or exactly-once delivery.
- Match real carrier names and links, then verify customer inbox delivery and
  the account view for the real sample order.

AST provides shipment numbers and carrier links. Automatic in-transit,
out-for-delivery and delivered messages require a separate carrier-event
service such as TrackShip; none is purchased or enabled by this work.

Production rollout and a fresh backup/recovery check remain separate work after
the receiver choice and release checks pass. See [ORDER-EMAIL.md](ORDER-EMAIL.md)
for SMTP and [STAGING-REHEARSAL.md](STAGING-REHEARSAL.md) for the completed payment
and refund rehearsal.
