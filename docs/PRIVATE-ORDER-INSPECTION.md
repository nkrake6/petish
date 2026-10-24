# Private order and refund inspection

The diagnostic workflow reads one explicitly selected production order without
bootstrapping WordPress, contacting a payment provider or changing store data.
Customer selectors and financial reports never enter public Git or job logs as
plaintext. Do not add real customer names or order identifiers to test fixtures.

`prepare-order-refund-inspection` creates a short-lived RSA4096 request key in a
private server directory and publishes only its public key and one-hour expiry.
A fresh workspace-owned RSA4096 key receives the report; its private half stays
outside the repository. `operations/order-refund-inspection.json` contains only
the encrypted selector and public key fingerprints. The request can include an
expected billing first name to prevent inspecting a different customer or a
staging order with the same identifier.

`inspect-order-refund` consumes the request once. It verifies the fixed database
origin, then uses a read-only transaction against the authoritative legacy or
HPOS storage. Reports contain typed totals, shipping/tax allocation, cached
fee/net metadata, refund flags and classified financial events. Raw order notes,
customer details, card data, order keys and provider identifiers are excluded.
The runner publishes an authenticated RSA-OAEP-SHA256/AES256-GCM envelope only
after strict report validation and server cleanup.

Readiness checks exercised real native WooCommerce legacy and HPOS fixtures
and confirmed that MariaDB rejects writes in the read-only transaction. The
transport tests cover encryption/tamper checks, exact schemas, key expiry,
one-use ownership, cleanup and mocked SSH failures. Identical duplicate legacy
metadata is collapsed; conflicting values or missing financial fields remain
blocked and return fixed failure codes without data values.

Verified preparation receipts: `40139840045-1` and `40140611834-1`. The first
inspection `40140428759-1` stopped safely on the legacy metadata format and
cleaned up. The corrected inspection `40140767193-1`, source `e9940a4`, succeeded
with authenticated ciphertext and all temporary server files removed. No
payments, refunds, messages, website files or database rows were changed.

Cached refund records do not confirm settlement in the customer's bank.
Current order totals are not independent proof of the historical capture
amount. Line-level tax/pricing may retain fractions of a cent; strict allocation
checks can therefore be inconclusive even when the displayed order/refund
amounts reconcile. Do not interpret sub-cent residuals as an additional customer
balance.

The completed request is retired. Generate a fresh recipient and a new prepared
request for another inspection; do not reuse the recorded public recipient
after its workspace private key has been destroyed. A scanner may flag the
request's public RSA fingerprint as a generic key: verify it against the
published public-key DER SHA256 and the exact request schema. It is not an API
credential; do not ignore unrelated scanner findings.
