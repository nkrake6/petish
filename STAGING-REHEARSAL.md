# Petish private staging rehearsal

Status: isolation preflight
[`39365001006`](https://github.com/nkrake6/petish/actions/runs/39365001006)
passed. The full checkout, native test refund and order-email rehearsal is
prepared but has not yet been verified by an actual run.

The rehearsal uses `https://staging.petish.biz`, its separate database and the
existing synthetic customer. It must not use the production checkout, real
payment details, customer accounts or supplier ordering.

## Run the reviewed workflow

Open [Rehearse Petish checkout on private staging](https://github.com/nkrake6/petish/actions/workflows/rehearse-staging.yml),
select **Run workflow**, branch `main`, and mode `preflight` or `rehearse`.
Ordinary relevant `main` pushes run preflight only. The dedicated
`staging-rehearsal` branch selects the full rehearsal. Staging code deployments
and this workflow share a concurrency group.

Preflight checks the canonical staging paths, private configuration, separate
database, inspected guard, native WooPayments development/test modes and a
non-live provider account. It requires supplier calls, webhooks and outbound
mail to be blocked. It does not place an order.

The full mode repeats those checks, then:

1. Installs a temporary fixture limited to the exact synthetic customer, run,
   one blue Corner & Gap Cleaning Brush (variation `246`, parent `242`) and
   Australia/postcode `3083`. Its shipping rate is labelled **STAGING
   SIMULATION** and costs A$6 before tax. This is a test fixture, not a carrier
   quote or production shipping change.
2. Uses a real browser and the native WooPayments checkout. It verifies a test
   publishable key, submits a Stripe decline test card, then retries once with
   a success test card. It does not mark an order paid through application code.
3. Verifies the fresh, tagged QA order against the provider's test charge,
   amount, currency and captured status. Checks stock stayed unchanged after
   decline and fell by exactly one after successful payment.
4. Checks the native new-order and customer-processing emails in the staging
   mail capture. Adds a clearly synthetic tracking note, completes the test
   order, and checks the customer note/completed emails. The synthetic note
   does not test a carrier or DSers tracking round trip.
5. Requests one full refund through the native test gateway, including item
   restocking. Verifies the provider refund, refunded order, captured refund
   email and stock restored to its recorded baseline.
6. Removes the fixture and clears the rehearsal session while restoring the
   saved QA cart. Existing orders and production settings remain outside its
   scope.

The browser permits only staging and the payment frontend hosts needed for
test checkout. Server provider requests are limited to the guarded verification
and refund operations. All order emails remain captured locally on staging.
Credentials, order keys, receipt URLs and message bodies stay private.

## Review the result

The final report is `status.json` on
`automation-status/rehearsal-<run>-<attempt>`. Intermediate reports use
`automation-progress/rehearsal-<run>-<attempt>-<phase>`. They contain validated
booleans, finite failure categories and selected metadata only.

A passed preflight is not a passed checkout. For a full pass, inspect the
browser result and the `prepare`, `verify`, `refund` and `cleanup` phase reports:
native checkout must reach a paid receipt after the verified decline; provider,
stock and captured-email checks must pass; the native full refund must pass;
cleanup must report `recovery_required: false` and confirm fixture removal,
cart cleanup and refund before cleanup.

An unavailable or failed phase leaves that claim unverified. A
`validation_stage` value identifies the rejected report section without
publishing its private contents. Do not paste raw logs, browser input, captured
mail or provider responses into Git or chat.

If payment or refund is uncertain, the private journal and helpers are retained
for recovery. Inspect the exact recorded QA order before taking another action;
do not rerun checkout or refund automatically. The refund journal prevents a
second refund attempt after an uncertain response.

Local fixtures test the privacy boundary, staging scope, browser flow, email
markers, operation locking and refund retry protection. They support the
implementation; only the actual staging run can establish the end-to-end result.
