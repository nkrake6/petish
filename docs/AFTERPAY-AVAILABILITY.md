# Afterpay availability inspection

Afterpay remains enabled on Petish. A read-only inspection found no disabled-setting or cached merchant-capability blocker. It did not reproduce the customer's missing Afterpay message or payment option.

## Product/cart follow-up, 2026-10-21

The user clarified that Google Pay is available on product/cart pages while Afterpay appears as a payment option at checkout. The [follow-up report](https://github.com/nkrake6/petish/blob/automation-status/afterpay-39543337637-1/status.json), generated from probe commit `03140fc1f7a7c107c295c0ec63960328e9265f65` and published at **04:19:01 UTC**, confirms:

- The configured WooCommerce checkout page contains a **checkout block**.
- The [Blue corner-gap cleaning brush](https://petish.biz/product/corner-gap-cleaning-brush/?attribute_color=Blue) has one native Afterpay messaging container, the WooPayments messaging asset/configuration, and Afterpay in its enabled messaging list.
- Public messaging context is AU/AUD, with both base and selected Blue amounts **A$8.00**. Native `shouldInitializePMME` and `shouldShowPMME` flags are both true.
- The product also has the native express-checkout wrapper. These markup/configuration facts do not establish that a particular browser rendered the Stripe message iframe successfully.
- The anonymous empty-cart page loads messaging configuration/assets but has no message container or express wrapper. A populated shopper cart was not inspected.
- Live WooPayments/Afterpay settings remained enabled with active cached capability; provider cache age was 3,088 seconds at this inspection.

This separates native instalment messaging from an express wallet button. WooPayments 11.2.0 does not offer Afterpay in its product/cart express checkout implementation. See the [checkout-button proposal](AFTERPAY-CHECKOUT-CTA-PROPOSAL.md) for an explicitly labelled normal-checkout alternative; it remains a proposal, with no application or payment-setting changes made for that flow.

## Evidence

- Inspection source: [`17e881d52980b8246c6756be3a03af4d8ec6e2f2`](https://github.com/nkrake6/petish/commit/17e881d52980b8246c6756be3a03af4d8ec6e2f2).
- [Workflow run 39541532452, attempt 1](https://github.com/nkrake6/petish/actions/runs/39541532452).
- [Bounded configuration report](https://github.com/nkrake6/petish/blob/automation-status/afterpay-39541532452-1/status.json), published at **2026-10-21 03:59:14 UTC** (report commit time).
- Report commit: `296c3c6fd0c2f564e7c442935574523049bef972`.

The probe reads six fixed WordPress options directly and hashes four plugin files. It does not bootstrap WordPress, contact the payment provider, create orders, change settings or publish credentials/customer/account identifiers. Cached provider status was approximately 32 minutes old (1,905 seconds) when read.

## Observed configuration

| Check | Observed value |
| --- | --- |
| Store base country and currency | AU / AUD |
| WooPayments | Active, version 11.2.0, enabled |
| Payment mode | Live (`test_mode=no`) |
| Capture | Automatic (`manual_capture=no`) |
| Main enabled payment methods | `afterpay_clearpay`, `card` |
| Split Afterpay gateway | Enabled; its enabled-method list also includes Afterpay |
| Cached merchant country/default currency | AU / AUD |
| Cached merchant payments enabled | Yes |
| Cached Afterpay capability | Active |
| Cached Afterpay capability requirements | None present |
| Cache error flag | False |
| Other checked payment plugins | PayPal Payments active; Stripe and separately named Afterpay/Clearpay plugins not detected as active |
| Inspected eligibility/messaging source files | All four match the public WooPayments 11.2.0 source |

## Eligibility rules in the matching source

The inspected source is WooPayments tag **11.2.0**, commit `c6ccb818dce1485b5987586f9cd9617712cb6571`:

- [Afterpay definition](https://github.com/Automattic/woocommerce-payments/blob/c6ccb818dce1485b5987586f9cd9617712cb6571/includes/payment-methods/Configs/Definitions/AfterpayDefinition.php): domestic transactions; Australian range **A$1–A$2,000 inclusive**. These are plugin limits, not a guarantee that a particular buyer will be approved.
- [Payment method eligibility](https://github.com/Automattic/woocommerce-payments/blob/c6ccb818dce1485b5987586f9cd9617712cb6571/includes/payment-methods/class-upe-payment-method.php): evaluates order/cart amount, currency and subscription context. The currency must match the merchant's domestic currency. Afterpay cannot provide automatic subscription renewal payments; non-reusable methods can appear when manual renewals are allowed.
- [Gateway availability](https://github.com/Automattic/woocommerce-payments/blob/c6ccb818dce1485b5987586f9cd9617712cb6571/includes/class-wc-payment-gateway-wcpay.php): checks enabled methods, active capability, capture mode and other checkout prerequisites. Main and split settings can disagree, but they agree in the inspected Petish configuration.
- [Product/cart messaging](https://github.com/Automattic/woocommerce-payments/blob/c6ccb818dce1485b5987586f9cd9617712cb6571/includes/class-wc-payments-payment-method-messaging-element.php): calculates eligibility using country, currency and product/variation price or cart total. A product-page instalment message is distinct from the payment method offered at checkout.

The four matching files do not establish that every product or every buyer qualifies. No broad product-category restriction was inferred from them.

## Remaining reproduction

The product and selected variation are now known. If the native instalment message itself is missing, inspect browser loading/rendering for that Blue product in the shopper's country/currency and display theme. A populated cart's message and totals also remain to be checked. The absence of a Google Pay-style Afterpay express button is explained by the installed integration's capabilities, rather than by a disabled Afterpay setting.

This inspection does not verify a live buyer's approval, a completed payment, current provider status beyond the saved cache, or the shopper's exact checkout session. No payment configuration changes are indicated by the evidence collected so far.
