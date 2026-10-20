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

Hosted run
[`39387940791`](https://github.com/nkrake6/petish/actions/runs/39387940791)
passed on 20 October 2026 using Petish commit `3e35a83`.
[Screened results](https://github.com/nkrake6/petish/blob/tracking-rehearsal/39387940791-1/status.json)
confirm the native REST permission checks, partial and completed emails with the
approved animal logo, two-parcel account display and removal of both the order
and carrier fixture. No supplier or payment action occurred. Stored plugin
configuration remained unchanged; the temporary source was removed. The normal
four-plugin staging preflight also passed in run `39387940751`.

The risk observations were **true**: a replay appended another tracking record
and repeated the partial-shipment email, and the native API accepted a blank
number. This is a successful characterization test, not release approval.
External DSers push verification remains **false**. Live tracking is not enabled
by this rehearsal.

The workflow [Rehearse Petish shipment tracking on private staging](https://github.com/nkrake6/petish/actions/workflows/tracking-rehearsal.yml)
evaluates Advanced Shipment Tracking Free 4.0.4 from the
[official source](https://github.com/zorem/woo-advanced-shipment-tracking/tree/c22a8dad01045779ec247fe830943eaddeaa266f),
pinned to commit `c22a8dad01045779ec247fe830943eaddeaa266f` and archive SHA256
`353805462f5d88f064fb032c016fb947c3610531d0b59eae026d808be86f3736`.
Its compatibility list includes DSers, but neither that list nor this test
identifies the transport used by Petish's DSers account.

The runner verifies every plugin file against the reviewed archive, loads it
only in the isolated CLI request, and removes its temporary files afterwards.
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

The test uses the real AST REST controller internally, with normal capability
checks, then native status/email and My Account rendering:

1. Anonymous and customer requests must be denied without adding tracking.
2. First parcel leaves the order partially shipped and generates a captured,
   branded partial-shipment email with its tracking link.
3. Replayed updates are observed for duplicate parcels and repeated email.
   The exact synthetic duplicate is removed before continuing.
4. Final parcel retains both tracking numbers, completes the synthetic order,
   and includes both links in the captured completed email and account view.
5. Blank tracking input is characterized and all owned fixtures are removed.

Only strictly validated boolean evidence is published to
`tracking-rehearsal/<run>-<attempt>/status.json`. Private order identifiers,
messages, customer data and credentials are excluded. A successful rehearsal
means these native checks executed, even if its descriptive risk observations
show unsafe default behavior. It does not mean production tracking is enabled.

## Remaining release checks

- Establish whether DSers writes shared `_wc_shipment_tracking_items` metadata,
  uses a shipment-tracking REST route, or sends only order notes. Verify the
  actual DSers account settings or one incoming sample shipment; do not invent
  a dashboard setting or assume the AST REST test proves DSers authentication.
- Repeated updates must not duplicate customer messages or parcel records.
  Free AST's append path does not deduplicate; its README lists API duplicate
  protection as a PRO feature. Decide the remedy against the actual DSers
  payload and update behavior before deploying.
- Reject empty numbers and preserve existing parcels on corrections. In the
  reviewed AST API, `replace_tracking=1` clears every existing parcel.
- An order with outstanding parcels must stay partial. `status_shipped=1`
  completes the whole order; free AST does not map quantities to individual
  parcels. Confirm DSers' status behavior with the scheduled split shipment.
- Match real carrier names and links, then verify customer inbox delivery and
  the account view for the real sample order.

AST provides shipment numbers and carrier links. Automatic in-transit,
out-for-delivery and delivered messages require a separate carrier-event
service such as TrackShip; none is purchased or enabled by this work.

Production rollout and a fresh backup/recovery check remain separate work after
the receiver choice and release checks pass. See [ORDER-EMAIL.md](ORDER-EMAIL.md)
for SMTP and [STAGING-REHEARSAL.md](STAGING-REHEARSAL.md) for the completed payment
and refund rehearsal.
