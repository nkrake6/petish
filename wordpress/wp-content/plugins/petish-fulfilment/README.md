# Petish delivery costs and supplier shipping

## 0.6.0 development candidate

The new **WooCommerce → Delivery operations** page previews reviewed
exact-quantity or first-item/additional-item delivery rules from private CSVs.
It cannot save or activate rates. The existing version 1 per-unit book remains
unchanged; version 2 adds quantity-scoped rules with current catalogue identity,
destination, explicit supplier evidence and expiry checks.

A private native-order parcel ledger and notification outbox retain replay,
correction, item coverage and sending outcomes. Both feature flags default off;
there is no default sender, worker or DSers receiver. The staff page can inspect
records and queue only confirmed-unsent retries. An uncertain submission needs
explicit evidence-based resolution before a retry can be queued. Queueing does
not itself send mail, and submitted does not establish inbox delivery.

See [operation, activation and verification details](../../../../docs/DELIVERY-OPERATIONS.md).
This candidate is for private staging. Production remains on 0.5.0 until a
dedicated release and the actual supplier/tracking inputs are verified.

The plugin supports merchant-set automatic delivery prices, a separate reviewed
quote workflow, and a private delivery cost worksheet. It does not contact DSers
or synchronise supplier freight automatically. Loading the plugin creates no
requests, orders, zones or rates.

The current automatic trial is limited to the five approved product options in
Australia / Victoria / postcode 3083. Its merchant delivery fees are separate
from supplier freight. Automatic mode intentionally hides the historical
customer quote-request flow described below.

## Private delivery cost sheet

Open **WooCommerce → Delivery cost sheet**. A user needs `manage_woocommerce`;
CSV template downloads and previews require a WordPress nonce. The page makes
no pricing, rate-book, product, order, email or supplier changes.

1. Download a current catalogue template. Each numbered batch scans up to 500
   published product records and includes only available physical simple
   products or variations. Variable parent rows are omitted. Download the next
   batch for the remaining records.
2. Fill in the exact route, quantity, supplier evidence and financial inputs.
   Product/option names help identify rows; IDs, SKUs, supplier URL and the
   current shipping fingerprint remain the identity checks.
3. Upload the completed UTF-8 CSV to preview up to 500 scenarios / 1 MiB.
   Uploads and results are held only for the current response. Keep the working
   CSV privately; the tool does not store the costs in WordPress or Git.

Each row is **one independent product/quantity/destination scenario**, not one
line in an automatically combined cart. The sheet does not assume supplier
parcel consolidation or additional-item discounts. Do not sum scenario rows as
a multi-supplier order: that could count fixed payment fees more than once.

| Input group | Columns / meaning |
|---|---|
| Current identity | `product_id`, `variation_id`, `product_label`, `expected_sku`, `expected_parent_sku`, `supplier_product_url`, `shipping_fingerprint`. The server replaces the display label with the current catalogue label; title changes alone do not invalidate identity. |
| Route | `country`, `state`, `postcode`, `quantity`, `service`. These describe this scenario only; one postcode or quantity never implies broader coverage. |
| Supplier evidence | `evidence_kind`: `estimate`, `supplier_quote` or `rate_card`; `evidence_reference`; exact UTC `observed_utc` and `expires_utc`, no more than seven days apart. The kind records the source supplied by the operator, not independent verification. |
| Supplier amounts | `supplier_unit_cost` is per unit; `supplier_freight_total` is the total for the entered quantity. Both use `supplier_currency`. |
| Currency | `supplier_to_aud_rate` means AUD per one unit of supplier currency. `fx_observed_utc` must be within seven days when using a foreign-currency rate. AUD-to-AUD is always 1. |
| Customer revenue | `retail_unit_ex_tax_aud`, `customer_shipping_ex_tax_aud` and total `customer_tax_total_aud`. Customer revenue is recorded in AUD and kept separate from supplier costs. |
| Other costs and fees | `other_cost_aud`, `payment_fee_percent`, `payment_fee_fixed_aud`. Use a consistent tax treatment; the tool does not infer input-tax recovery, duties or the payment provider's fee contract. Percentage fees apply to the amount charged including customer tax; the fixed fee applies once per scenario. |
| Planning | `freight_buffer_percent` is an internal freight allowance, not a customer charge. `target_margin_percent` is optional. `promotion_note` records first-order, coupon, minimum-spend or other conditions. |

Amounts accept up to six decimal places, with no currency symbols or thousands
separators. **Blank is unknown; explicit zero is a deliberate input.** Missing
costs or foreign-exchange inputs prevent a complete contribution calculation.
Zero supplier freight does not mark customer delivery free, and zero customer
delivery may be a merchant subsidy. Neither sets the storefront's free-delivery
badge.

The preview shows supplier goods and freight in AUD, freight allowance,
customer revenue, tax-inclusive amount charged, payment fees, modelled costs,
contribution and contribution margin. Fixed-decimal arithmetic rounds at six
decimal places; display amounts use two decimals. Contribution is **not net
profit**: unentered overheads, returns and losses remain outside the model.
Expired evidence, unavailable products, changed mappings/specifications,
invalid destinations and invalid amounts are rejected.

The current automatic rate book stores **per-unit** customer charges. An exact
quantity freight quote must not be divided into a per-unit charge and silently
applied to other quantities. This worksheet has no publish/save-rates action.
Specific new routes need a separate reviewed activation step and appropriate
quantity rules.

## Customer shipping requests

The managed `/shipping-quote/` page uses `[petish_shipping_quote]`. Customers sign in, keep their intended items in the native Woo cart and submit their delivery address. Requests appear in **My account → Shipping quotes**. They are private, owned by the requesting account and excluded from public queries, search and REST. Repeated pending requests for the same cart/address are reused; each account may submit ten requests per 24 hours.

In WordPress, open **WooCommerce → Delivery quote requests**, check the exact variants, quantities and delivery address with the supplier, and enter the **total customer delivery charge excluding tax in AUD**. Also enter the tracked service, private freight evidence/reference, estimated delivery window and an exact UTC expiry no more than seven days ahead. Optional customer delivery notes are separate from private evidence. Confirm the review and approve or decline the request.

After approval, the customer chooses **Use quote at checkout** from their request. This updates only the native Woo checkout-session delivery address, preserves their saved profile and returns them to checkout. Native Woo displays the approved delivery charge, applicable tax and full order total before payment. The method requires the same account, complete cart, exact destination, current stock availability and shipping specifications; changed or expired quotes receive no rate. A normal stock-count change that still leaves sufficient stock does not invalidate the price.

Submitting or approving a request creates no Woo order, supplier booking, payment or email, and does not reserve stock. Updates are available in the customer's account and the private admin queue. Before re-enabling this historical flow, separately verify product availability and shared parent-stock handling. The full-cart approval supports the native single shipping package; a separate package-splitting extension needs its own integration before using these approvals.

Run `tools/setup-shipping-quote-workflow.php` with WP-CLI to create the two managed pages and append the Delivery & shipping footer link. It accepts the reviewed delivery HTML path, preserves existing menu items and saves a private setup checkpoint. The workflow and generic quote book below keep their source amounts in AUD; foreign destinations are permitted where the supplier service has been reviewed. Supported WooPayments checkout currencies use the existing currency adapter.

## Generic supplier quote book

The separate generic quote book has no dashboard editor. Keep it empty until the exact route, variant, quantity, tracked service and evidence have been reviewed and approved. Customer-specific approvals take precedence and are stored privately as request records, never copied into this generic book.

Add `petish_supplier_shipping` to a native shipping-zone instance. Its settings are Enabled and Title; the customer label defaults to **Tracked supplier delivery**. Remove unrelated fallback rates separately if those rates are not supported by evidence. This plugin does not change other methods.

## Quote option

The private option `petish_supplier_shipping_quotes` contains `schema_version: 1` and a `quotes` list. Administrators may save a reviewed book through authenticated WP-CLI or trusted server code; there is no public write endpoint. A deliberately empty book is:

```json
{"schema_version": 1, "quotes": []}
```

The following is a **schema example only**, with invalid placeholders. It is not an active quote and must not be saved as production evidence:

```json
{
  "schema_version": 1,
  "quotes": [
    {
      "product_id": 123,
      "variation_id": 456,
      "expected_sku": "EXACT_CHILD_SKU",
      "expected_parent_sku": "EXACT_PARENT_SKU",
      "quantity": 1,
      "shipping_fingerprint": "REPLACE_WITH_FINGERPRINT_FROM_CURRENT_PRODUCT",
      "country": "AU",
      "destination_scope": {
        "states": ["VIC"],
        "cities": ["Melbourne"],
        "postcodes": ["3000"]
      },
      "customer_shipping_ex_tax_aud": "REPLACE_WITH_VERIFIED_EX_TAX_AMOUNT",
      "quote_utc": "YYYY-MM-DDTHH:MM:SSZ",
      "expires_utc": "YYYY-MM-DDTHH:MM:SSZ",
      "carrier": "VERIFIED_TRACKED_SERVICE",
      "source_url": "https://supplier.example/quoted-listing",
      "reference": "PRIVATE_EVIDENCE_REFERENCE"
    }
  ]
}
```

Use the parent ID in `product_id`. For a simple product, set `variation_id` to `0` and use its SKU for both expected SKU fields. For a variation, use the actual child ID and child SKU, plus its actual parent SKU. The current parent must already have a valid HTTPS `_petish_supplier_product_url`.

Create the fingerprint from the selected **simple or variation object**, then validate the completed row and book without writing anything:

```php
$product = wc_get_product($variation_id ?: $product_id);
$fingerprint = Petish_Supplier_Quotes::fingerprint($product);
// Stop if is_wp_error($fingerprint); copy the hash into the reviewed row.
$validated_row = Petish_Supplier_Quotes::validate_quote($row);
$diagnostics = Petish_Supplier_Quotes::validate_book($book);
// Stop on row errors; diagnostics lists valid quotes and rejected row indexes/codes.
```

`fingerprint()` pins parent/child identity and SKUs, attributes and taxonomy meanings, dimensions, weight, shipping class, virtual status, recorded supplier URLs, and the store's dimension/weight units. Price, description, images and private pet data are excluded. Recheck evidence and generate a fresh fingerprint after relevant specifications change; never copy a hash from a different product.

## Exact destination, quantity and currency

Quotes match the package country and every populated scope allowlist. Country uses an uppercase two-letter code; state uses the native Woo state code. City/postcode comparisons trim and normalise case/spacing, but allow no wildcards or postcode ranges. A missing or different pinned city, state or postcode produces no rate. Empty lists leave that field unconstrained, so only omit a field when the reviewed evidence covers the resulting broader scope.

A country-only row with no populated allowlists is rejected unless it explicitly sets `"country_scope_verified": true`. A supplier quote for one state/city or an incomplete address does not establish countrywide delivery. Do not add that assertion by inference.

Quantity is exact. A quote for one item does not apply to two; duplicate lines for the same parent/variation are combined before matching. Distinct quoted lines sum as separate parcels, with no assumed combined-shipping discount. Any unknown line or ambiguous duplicate matching quote blocks this method for the entire package. Only simple products and actual variations are supported.

The store base currency must be AUD. Quote records remain AUD. The existing
`Petish_Quote_Currency` adapter converts the native rate for enabled, supported
WooPayments checkout currencies without retail rounding, and excludes a second
WooPayments shipping conversion. An unsupported currency receives no rate.

## Amount, expiry and tax

`customer_shipping_ex_tax_aud` is the **customer shipping charge excluding tax**, as a non-negative decimal string with up to six decimal places. It is not automatically the supplier's raw freight figure: any customer charge must be explicitly reviewed. Zero is accepted only as an explicit complete quote record, never as a fallback for missing freight. Store the supporting supplier cost separately in the private review evidence.

Dates must be UTC RFC3339 (`Z` or `+00:00`). The quote cannot be in the future; expiry must be after the quote and the current time, and no more than **seven days after the quote**. Replace or remove expired records after rechecking the supplier. Shipping cache tokens and a post-cache guard prevent expired or specification-mismatched rates from surviving in native Woo shipping packages.

The method is taxable and passes its ex-tax amount to native WooCommerce. Configured destination tax rules calculate shipping tax, including AU GST when applicable; the plugin does not hardcode GST or determine international tax obligations. A third-party filter that makes all shipping prices tax-inclusive is incompatible and disables this method. Configure native Woo tax and checkout separately.

## Verification

`tools/test-fulfilment-shipping.php` runs read-only integration checks using existing draft simple/variation objects. Quotes and the customer address are in memory; no products, customers, orders, settings or tax rows are saved. After AU shipping GST is configured, run it with `require-gst` to require a native 10% shipping-tax calculation. It also checks unknown destinations, state/city/postcode scope, quantity, identity, changed specifications, duplicates, expiry, zero freight, aggregation, currency and cached-rate rejection.

This method does not approve products, publish drafts, establish supplier reliability, guarantee stock or delivery dates, authorise image use, place orders or fulfil purchases. Those launch checks remain separate.
