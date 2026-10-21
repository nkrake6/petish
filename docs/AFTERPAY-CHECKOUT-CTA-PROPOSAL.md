# Proposed Afterpay product/cart checkout button

Status: proposal only. No storefront code or payment settings have been changed for this flow.

## Recommendation

Afterpay is already available at checkout, and the Blue corner-gap brush page already includes native Afterpay instalment-message markup and configuration. WooPayments does not support Afterpay as a Google Pay-style express wallet button. Add a clearly labelled **“Continue to checkout with Afterpay”** button on eligible product and cart pages, retaining the native instalment message.

This button would open the normal checkout with Afterpay selected when available. It would not authorize payment, create an order or bypass the customer's final confirmation.

## Proposed behaviour

1. On a product page, use the customer's current selected variation and quantity. Submit through the existing WooCommerce add-to-cart validation; missing selections, unavailable stock or invalid quantities keep the customer on the product page with the normal error. Preserve anything already in the cart.
2. After successful addition, redirect to the store checkout with a narrowly scoped Afterpay selection request. On the cart page, the button opens checkout for the current cart without adding anything again. Pending cart edits must be saved before the button uses the cart.
3. Select `woocommerce_payments_afterpay_clearpay` only when WooCommerce includes it in the available gateways for the current cart/customer. Preserve every native payment, shipping, stock, currency, country and amount check. If it is unavailable, show the normal checkout and explain that Afterpay availability depends on the order and delivery details.
4. The customer reviews the order, supplies delivery details and confirms payment through the existing WooPayments/Afterpay checkout flow. A later deliberate choice of another payment method must remain selected; the request must not repeatedly force Afterpay during checkout refreshes.

Use native Afterpay artwork if a logo is included. The button text must retain “checkout”; do not label it as an express wallet, imply approval or display a payment-success state merely because it redirects.

## Evidence and implementation boundary

- [Public page inspection](https://github.com/nkrake6/petish/blob/automation-status/afterpay-39542988497-1/status.json): the Blue brush has one native message container, WooPayments messaging asset/config, Afterpay in the enabled messaging list, AU/AUD context and a selected-variation amount of A$8.00. It also has the native express wrapper. This confirms server markup, not successful iframe rendering. The inspected cart was anonymous and empty.
- WooPayments **11.2.0** [express client](https://github.com/Automattic/woocommerce-payments/blob/c6ccb818dce1485b5987586f9cd9617712cb6571/client/express-checkout/shortcode-buttons-express/index.js) constructs express payment types from `card` and `amazon_pay`. Its [Afterpay definition](https://github.com/Automattic/woocommerce-payments/blob/c6ccb818dce1485b5987586f9cd9617712cb6571/includes/payment-methods/Configs/Definitions/AfterpayDefinition.php) has no express-checkout capability.
- Its pinned Stripe.js **9.4.0** [express checkout options](https://github.com/stripe/stripe-js/blob/v9.4.0/types/stripe-js/elements/express-checkout.d.ts) list Amazon Pay, Apple Pay, Google Pay, Link, PayPal and Klarna; Afterpay is absent.
- Native WooPayments [messaging hooks](https://github.com/Automattic/woocommerce-payments/blob/c6ccb818dce1485b5987586f9cd9617712cb6571/includes/class-wc-payments.php) automatically cover product, classic-cart and cart-block views when a BNPL method is enabled. There is no separate product/cart Afterpay messaging placement toggle in the inspected version. Petish currently moves the product message beneath the Add to cart form.
- The [follow-up inspection](https://github.com/nkrake6/petish/blob/automation-status/afterpay-39543337637-1/status.json) confirms Petish uses **block checkout** and the Blue product's native messaging initialize/show flags are both true.
- WooCommerce **11.2.1**, source commit `67e007c3c4cca5725e056ddaaa2ed2fb0e282045`, has a native session-to-block path: [CheckoutSchema::get_draft_response](https://github.com/woocommerce/woocommerce/blob/67e007c3c4cca5725e056ddaaa2ed2fb0e282045/plugins/woocommerce/src/StoreApi/Schemas/V1/CheckoutSchema.php) prefers an enabled `chosen_payment_method`; [block payment default state](https://github.com/woocommerce/woocommerce/blob/67e007c3c4cca5725e056ddaaa2ed2fb0e282045/plugins/woocommerce/client/blocks/packages/public-api/block-data/payment/default-state.ts) reads `checkoutData.payment_method`. Verify this path on isolated staging before release. Classic checkout also uses [session gateway selection](https://github.com/woocommerce/woocommerce/blob/67e007c3c4cca5725e056ddaaa2ed2fb0e282045/plugins/woocommerce/includes/class-wc-payment-gateways.php).
- Use a one-time server selection request, then remove its URL parameter with a clean checkout redirect. Preserve native availability checks and avoid [internal-only block payment actions](https://github.com/woocommerce/woocommerce/blob/67e007c3c4cca5725e056ddaaa2ed2fb0e282045/docs/block-development/reference/data-store/payment.md). Existing failed/pending-order retry sessions use a different response path: do not modify an existing order just to preselect Afterpay; provide a normal checkout fallback if necessary.

A direct Afterpay gateway is a different integration. A [public mirror of the official plugin's 3.8.6 README](https://github.com/common-repository/afterpay-gateway-for-woocommerce/blob/main/readme.txt) documents Afterpay-issued Merchant ID/Secret Key credentials and cart express support. That older mirror does not establish current WooCommerce 11.2.1 compatibility or a supported product-page express flow. Do not install it or change processors as part of this proposal.

## Verification before release

Check selected Blue and other variations, quantity changes, invalid selection, stock failure, existing cart contents and cart quantity updates. Verify Afterpay is selected only when available and that another method remains selectable after address, shipping or currency changes. Test both display themes, mobile/desktop and keyboard interaction on isolated staging, stopping before order creation or payment confirmation. Verify native instalment-message visibility separately from the new checkout button.

## Implementation research while the design choice is pending

**Cart page type is not yet established.** The saved public report combines classic and block empty-cart markers into one boolean; its absence of a message container is normal for either empty-cart type. Repository compatibility hooks support both. Checkout being a block does not prove Cart is also a block. Before implementation, read only the public `wcpayStripeSiteMessaging.isCartBlock` flag or classify the configured, published cart page's content; do not add a real product merely to identify the template.

### Native placement and add-to-cart behaviour

- Product: `woocommerce_after_add_to_cart_button` is inside both native simple and variable purchase forms. Render a secondary submit control with its own intent name there, leaving the primary Add to cart action unchanged. The variable form already supplies `add-to-cart`, `product_id` and `variation_id`; the simple form normally obtains `add-to-cart` from its primary submit button, so a secondary submit must supply the product ID too. Do not derive the selected variation from the URL alone or rebuild quantities in a separate unvalidated request.
- The public `woocommerce_add_to_cart_redirect` filter runs only after WooCommerce successfully adds the item and finds no error notices. Use it to redirect this explicit secondary-submit intent to checkout; failed selection/stock validation stays on the product page. Restrict the first implementation to the existing purchasable simple/variable forms. Grouped, external and subscription purchase flows require separate support.
- Classic Cart: `woocommerce_proceed_to_checkout` contains the normal checkout control and can host an additional control. Its location is outside the quantity-edit form, so a bare link does not submit unsaved quantities. Require the normal Update cart action to complete successfully before enabling the Afterpay control; show a short “Update cart to continue” hint while edits are dirty or updating.
- Block Cart: the documented `ExperimentalOrderMeta` slot renders above Proceed to Checkout and provides a `woocommerce/cart` context, so an Afterpay choice/control can be scoped to Cart without altering checkout or page content. This API is explicitly experimental and must be version-tested. The documented `proceedToCheckoutButtonLabel` and `proceedToCheckoutButtonLink` filters can route the **existing native button** through the selected Afterpay intent while retaining its loading state and proceed-to-checkout observers. Prefer this supported native-button path if a separate link cannot preserve those behaviours; do not register a pretend express payment provider just to obtain a button.

Source references at WooCommerce commit `67e007c3c4cca5725e056ddaaa2ed2fb0e282045`:

- [Native form handler](https://github.com/woocommerce/woocommerce/blob/67e007c3c4cca5725e056ddaaa2ed2fb0e282045/plugins/woocommerce/includes/class-wc-form-handler.php).
- [Simple product form](https://github.com/woocommerce/woocommerce/blob/67e007c3c4cca5725e056ddaaa2ed2fb0e282045/plugins/woocommerce/templates/single-product/add-to-cart/simple.php) and [variable purchase controls](https://github.com/woocommerce/woocommerce/blob/67e007c3c4cca5725e056ddaaa2ed2fb0e282045/plugins/woocommerce/templates/single-product/add-to-cart/variation-add-to-cart-button.php).
- [Documented slots](https://github.com/woocommerce/woocommerce/blob/67e007c3c4cca5725e056ddaaa2ed2fb0e282045/docs/block-development/extensible-blocks/cart-and-checkout-blocks/available-slot-fills.md) and [checkout-button filters](https://github.com/woocommerce/woocommerce/blob/67e007c3c4cca5725e056ddaaa2ed2fb0e282045/docs/block-development/extensible-blocks/cart-and-checkout-blocks/filters-in-cart-and-checkout/checkout-and-place-order-button.md).

### Unsaved quantities and in-flight calculations

The block [quantity selector](https://github.com/woocommerce/woocommerce/blob/67e007c3c4cca5725e056ddaaa2ed2fb0e282045/plugins/woocommerce/client/blocks/assets/js/base/components/quantity-selector/index.tsx) debounces typed changes for 600 ms and commits immediately on blur. Checking only network-idle or a pending-request flag before that blur can miss the last edit. A proposed control must let the input commit, then wait for server-resolved cart data, quantity/add/delete operations, shipping-rate changes and total calculation to settle. If validation fails or the input remains invalid, stay on Cart.

Public cart selectors expose pending item operations and shipping/customer-update state. The [native block checkout button](https://github.com/woocommerce/woocommerce/blob/67e007c3c4cca5725e056ddaaa2ed2fb0e282045/plugins/woocommerce/client/blocks/assets/js/blocks/cart/inner-blocks/proceed-to-checkout-block/block.tsx) also uses checkout calculation/loading state and dispatches proceed-to-checkout observers. Preserve those behaviours; a new raw anchor that navigates immediately is insufficient.

### One-time intent and existing orders

For a fresh checkout, set the customer's session `chosen_payment_method` only after confirming Afterpay is in the current available gateway set. Remove the intent parameter with a redirect to the clean native checkout URL. This prevents subsequent refreshes or address changes from repeatedly overriding a deliberate customer selection. Use native eligibility again at checkout; do not force availability or approval.

WooCommerce's [draft-order trait](https://github.com/woocommerce/woocommerce/blob/67e007c3c4cca5725e056ddaaa2ed2fb0e282045/plugins/woocommerce/src/StoreApi/Utilities/DraftOrderTrait.php) can reuse a session's `store_api_draft_order`, including a payable pending/failed order whose cart hash still matches. The [Checkout GET route](https://github.com/woocommerce/woocommerce/blob/67e007c3c4cca5725e056ddaaa2ed2fb0e282045/plugins/woocommerce/src/StoreApi/Routes/V1/Checkout.php) handles this differently from a fresh no-order response. Treat an existing retry/order-pay session as a fallback: open the normal checkout and let the customer select a method there, rather than changing an existing order, clearing its session reference, creating a replacement order or touching a payment intent to achieve preselection.

The staging test matrix must therefore include both a fresh cart and a synthetic failed/pending-order retry. Read-only research has not entered any customer session, added items, created orders or contacted payment APIs.
