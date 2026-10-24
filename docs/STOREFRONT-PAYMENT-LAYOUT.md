# Storefront payment groups and Cart readability

Live application source: `3691306`, 24 October 2026
(Australia/Melbourne). Production release source: `cff4586`.

On product pages, the existing native WooPayments express button and Afterpay
message form one group. PayPal and Pay in 4 form a separate group, with the
native PayPal instalment message beneath the buttons. Both groups use the same
400px maximum width and shrink to fit mobile screens. Categories and tags use
16px text with space above them.

Afterpay's native message has a green highlight in light and dark appearances.
The official `wcpay_elements_appearance` event matches its typography to the
native PayPal message configuration, including a Cart block's text-size
override. Its original logo, amounts, eligibility and See plans link stay with
the provider. The native SDK controls the contents of its cross-origin iframe;
the storefront does not replace that content or inject CSS into it. Checkout
appearance and caches are excluded.

## One Google Pay provider on Cart

The Cart request selects WooPayments for Google Pay when its native merchant
configuration enables Cart wallets, its Google Pay gateway is available for
express checkout and its Blocks integration is active. The public WooCommerce
payment-method registry removes only PayPal's `ppcp-googlepay` Cart display
integration before client configuration is captured. PayPal's supported native
Cart styling filter covers its SDK v6 display gate as well.

This is a request-local display choice. Stored settings, actual gateways,
Store API payment handlers and SDK components are unchanged. Product, checkout,
mini-cart, AJAX, REST, admin and CLI requests retain their native behavior.
If WooPayments is unconfigured or unavailable, PayPal Google Pay is retained.
WooPayments still decides browser eligibility; a browser unable to use it may
have no Google Pay button on Cart, while PayPal, Pay in 4 and ordinary checkout
remain available.

Cart styles place Google Pay above PayPal. They group the existing native
PayPal, Pay in 4 and instalment message without moving mounted SDK nodes.
Keyboard order remains the native provider DOM order. No client-side guesses
about iframe readiness hide a payment button.

## Larger item information

Desktop Cart product names and line totals use 20px text, unit prices and
quantity text use 18px, and variation/product details use 16px. Product names
use 18px on narrow screens. The mobile layout wraps long names and details and
keeps the original native quantity, removal and checkout controls.

## Source and verification boundary

The integration was checked against WooPayments 11.2.0, PayPal Payments 4.1.3
and WooCommerce 11.2.1. Native provider source confirms that product renderers
register after the main query, and Cart selection runs at `wp` priority 1,
before PayPal SDK v6's priority-10 display checks. Merely registered scripts do
not prevent selection; already-enqueued scripts or captured configuration
cause it to preserve native behavior.

Tests cover actual native registry/DTO behavior, unsupported contexts,
provider-unavailable fallbacks, native hook order, variation/remount behavior,
appearance/cache scope and responsive layout at 320, 390, 768 and 1280px.
Provider fixtures keep their original nodes and handlers. These checks do not
exercise a real wallet, financing decision, payment or supplier order.

Private staging install `40075829709-1` passed all eight deployment steps on
application source `3691306`. Read-only email preview `40075861008-1` rendered
all seven branded native order/account message types. The full storefront
browser proof additionally checks actual product metadata/group styles and
populated Cart typography, publishing finite measurements and booleans rather
than account, gateway or iframe configuration values.

## Hosted release evidence

| Step | Receipt | Result |
|---|---|---|
| Staging application install | [40075829709-1](https://github.com/nkrake6/petish/tree/automation-status/staging-40075829709-1) | All eight steps passed |
| Native email regression preview | [40075861008-1](https://github.com/nkrake6/petish/tree/email-preview/40075861008-1) | Seven branded message types rendered |
| Full 30-file native/browser staging proof | [40076124849-1](https://github.com/nkrake6/petish/tree/automation-status/homepage-40076124849-1) | All eight phases passed, including product/Cart checks and cleanup |
| Production apply and verification | [40076690180-1](https://github.com/nkrake6/petish/tree/automation-status/homepage-40076690180-1) | 16 files changed; native, cache, public health, all asset checks and commit passed |

The release matched all 30 fixed application hashes, preserved the original
account/email assets and store data, and removed maintenance. No rollback was
needed. The encrypted full-site backup and matching restored-application proof
were `40070351575-1` and `40070594356-1`.

Staging deliberately isolates payment-provider execution. Its actual product
metadata/group-style and populated Cart typography checks passed; provider
presence was reported as an observation. Native registry/DTO and responsive
provider fixtures test the display policy and controls. This is not a claim of
live wallet authorisation, financing eligibility or a real payment test.

The same release installs the live [hero-image editor](https://petish.biz/wp-admin/admin.php?page=petish-hero-images)
and right-aligned account dropdown. Photo choices are preserved; administrators
can select/upload images and save changes through the Media Library.
