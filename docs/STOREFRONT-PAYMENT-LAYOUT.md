# Storefront payment groups and Cart readability

Current application source: `ad6b2e6`, production release source `ceaa969`,
24 October 2026 (Australia/Melbourne). Prior baseline: `3691306` / `cff4586`.

Corrective implementation: Afterpay commit `ad24e72` and Cart commit `bcec660`.
The corrective production release succeeded in `40080832725-1`; its hosted
inspection `40081062995-1` confirmed restored native text and the rightmost
Account control, but caught a remaining badge-visibility bug. WordPress
`WP_Scripts::localize()` serializes a top-level PHP `true` as JavaScript `"1"`;
the badge guard expected boolean `true`. Commit `ad6b2e6` accepts exactly those
two enabled representations and retains every market, method and visibility
guard. The one-file follow-up release `40082256383-1` passed all 178 required
production checks. Actual live visual inspection `40082669837-1` confirms the
large badge and restored product text. The earlier baseline receipts below
do not establish that the reported live payment presentation issues were resolved.

On product pages, the existing native WooPayments express button and Afterpay
message form one group. PayPal and Pay in 4 form a separate group, with the
native PayPal instalment message beneath the buttons. Both groups use the same
400px maximum width and shrink to fit mobile screens. Categories and tags use
16px text with space above them.

## Afterpay correction

The prior implementation derived financing font sizes from PayPal's message
configuration. That customization has been removed. Commit `ad24e72` restores
WooPayments' native financing text and disclosure sizes. The actual 400px
product crop confirms See plans on the next message row. Stripe retains its
responsive wrapping and exposes no supported disclosure-layout setting.
The correction invalidates only the existing customized
product or Cart appearance cache once per browser/scope, before native message
initialization. Checkout appearance and caches are excluded.

The green message highlight remains in light and dark appearances. A separate,
decorative heading above the native Afterpay message uses the exact unchanged
SVG shipped by WooPayments: 150px wide, limited to its container width, with its
original aspect ratio and colours. The provider's own inline mark, amounts,
financing text and See plans link remain inside its message. There is no iframe
content replacement, internal CSS injection, scaling or cloned payment SDK.

The heading is limited to an initialized AU/AUD message whose only native
payment method is Afterpay/Clearpay, with a visible native iframe, no loading
placeholder and the provider asset available. Native amount, variation and
quantity eligibility remains authoritative. Mixed Klarna/Affirm messages,
other markets and unavailable messages are not labelled as Afterpay. Both
product and native Cart BNPL wrappers are covered.

## One Google Pay provider on Cart

The original policy incorrectly required the split Google Pay gateway's
regular-checkout availability and a regular-card Blocks registry entry. The
installed runtime proved both assumptions wrong: native main-gateway and Cart
wallet enablement were true, while split availability was false and the
`woocommerce_payments` registry lookup was absent. WooPayments' own source also
explicitly warns against that split-gateway availability check for Google Pay.

Commit `bcec660` selects WooPayments using the native main gateway's
`is_payment_request_enabled()` and the native helper's Cart `payment_request`
enablement. It requires neither split-gateway availability nor the regular-card
registry entry. The public WooCommerce payment-method registry removes only
PayPal's `ppcp-googlepay` Cart display integration before client configuration
is captured. PayPal's supported native Cart styling filter removes that method
from a cloned Cart display DTO for SDK v6.

This is a request-local display choice. Stored settings, actual gateways,
Store API payment handlers and SDK components are unchanged. Product, checkout,
mini-cart, AJAX, REST, admin and CLI requests retain their native behavior.
If the native WooPayments Cart wallet setting is disabled or its required APIs
are unavailable, PayPal Google Pay is retained. Already-enqueued Cart payment
scripts or captured configuration also preserve native configuration.
WooPayments still decides browser eligibility; a browser unable to use it may
have no Google Pay button on Cart. PayPal, Pay in 4 and ordinary checkout retain
their own native eligibility and processing.

Cart styles place Google Pay above PayPal. They group the existing native
PayPal, Pay in 4 and instalment message without moving mounted SDK nodes. The
message detector now supports both a v5 iframe and the native v6
`paypal-message` host. It does not search inside the host's shadow root. A
ResizeObserver watches the bounded native message/container hosts so a shadow
content height change can update the group without a light-DOM mutation.
The existing direct-Sidebar selector is retained: the filled guest Cart receipt
confirmed that structure, so no wrapper workaround was introduced.
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

Tests cover native registry/DTO behavior, unsupported contexts, disabled-native
wallet fallbacks, hook order, variation/remount behavior, appearance/cache scope
and responsive layout at 320, 390, 768 and 1280px. The corrective Cart fixture
covers main express enablement with split availability false and the card
registry absent. Its closed-shadow message fixture changes height from zero to
54px, back to zero and then 96px without a light-DOM mutation, while preserving
native host identity and handlers. These are isolated regression fixtures;
they do not prove that a live SDK rendered, authorize a wallet, make a financing
decision or create a payment or supplier order.

Private staging install `40075829709-1` passed all eight deployment steps on
application source `3691306`. Read-only email preview `40075861008-1` rendered
all seven branded native order/account message types. That baseline's browser
proof checked product metadata/group styles and populated Cart typography,
publishing finite measurements and booleans rather than account, gateway or
iframe configuration values.

## Baseline hosted release evidence

| Step | Receipt | Result |
|---|---|---|
| Staging application install | [40075829709-1](https://github.com/nkrake6/petish/tree/automation-status/staging-40075829709-1) | All eight steps passed |
| Native email regression preview | [40075861008-1](https://github.com/nkrake6/petish/tree/email-preview/40075861008-1) | Seven branded message types rendered |
| Full 30-file native/browser staging proof | [40076124849-1](https://github.com/nkrake6/petish/tree/automation-status/homepage-40076124849-1) | Eight phases passed; provider execution was isolated; cleanup passed |
| Production apply and verification | [40076690180-1](https://github.com/nkrake6/petish/tree/automation-status/homepage-40076690180-1) | 16 files changed; deployment, native, cache, public health and asset checks passed |

The release matched all 30 fixed application hashes, preserved the original
account/email assets and store data, and removed maintenance. No rollback was
needed. The encrypted full-site backup and matching restored-application proof
were `40070351575-1` and `40070594356-1`.

Staging deliberately isolates payment-provider execution. Its actual product
metadata/group-style and populated Cart typography checks passed; provider
presence was reported as an observation. Native registry/DTO and responsive
provider fixtures test the display policy and controls. This is not a claim of
live wallet authorisation, financing eligibility or a real payment test.

## Live diagnostics and remaining verification

| Inspection | Receipt | Established facts |
|---|---|---|
| Installed native policy and public markup | [40078717098-1](https://github.com/nkrake6/petish/tree/automation-status/storefront-runtime-40078717098-1) | Main express and Cart wallet settings enabled; split Google Pay unavailable and absent from enabled checkout methods; regular-card registry lookup absent; prior selection flag false |
| Isolated filled guest Cart | [40079103626-1](https://github.com/nkrake6/petish/tree/automation-status/storefront-runtime-40079103626-1) | One native message placeholder, inside and directly under the Sidebar; temporary guest Cart cleanup verified |

The filled guest run blocked 52 provider POSTs. The Stripe Afterpay frame did
render; the blocked count can include telemetry and does not establish which
initializers failed. Wallets and the v6 message host were absent in that run.
That result is inconclusive for their live rendering; it is neither absence proof nor evidence that the
corrective policy or message group works on the hosted site. The direct
Sidebar/message structure and the native guard failures were nevertheless
observed from installed code and actual Cart markup.

The earlier assumption that placeholder presence, isolated provider fixtures
or staging typography checks proved live wallet/message presentation was not
valid. The next browser proof must permit reviewed passive SDK bootstrap
requests while continuing to block order, checkout, authorization and payment
actions, and must distinguish an unavailable provider from blocked execution.

| Corrective release step | State |
|---|---|
| Final application source, including header correction | `442af87` |
| Staging install and native/browser verification receipt | `40080198298-1`; all eight full verification phases in `40080482597-1` (`cb0fee0`) |
| Hosted native wallet/message render and typography proof | `40081062995-1`: native text 16.74px, original product cache 17px; rightmost Account and Cart provider policy verified; badge type mismatch found; native SDK wallet rendering remains inconclusive in headless inspection |
| Production application and verification receipt | [40080832725-1](https://github.com/nkrake6/petish/tree/automation-status/homepage-40080832725-1), source `3e6c147`; nine files changed, all 30 hashes verified; native/cache/health/assets/commit passed; maintenance removed without rollback |
| Localized eligibility follow-up | Staging install `40081705741-1` (`ad6b2e6`), all 226 required full-staging checks in `40081839182-1` (`05c3de2`), production [40082256383-1](https://github.com/nkrake6/petish/tree/automation-status/homepage-40082256383-1) (`ceaa969`); one file changed, all 30 hashes and 178 required production checks passed; maintenance removed without rollback |
| Actual final native product/Cart visuals | [40082669837-1](https://github.com/nkrake6/petish/tree/automation-status/storefront-runtime-40082669837-1), diagnostic source `3c1ac7b` with unchanged `ceaa969` application bytes; 150×93.75px branding visible, native product appearance matches its original 17px source, guest Cart and temporary tools removed |

Both exact public payment crops were hash-verified and visually inspected:
[product, 400×172px](https://github.com/nkrake6/petish/blob/4002c721ae729453a7a16ce1d43065c571c6afbd/product-afterpay.png)
and [Cart, 310×167px](https://github.com/nkrake6/petish/blob/4002c721ae729453a7a16ce1d43065c571c6afbd/cart-payments.png).
They show the separate large official badge above the unchanged native message
and inline mark. Product text retains its restored readable size; the final
13.39px frame measurement belongs to Cart and is not a product-font measurement.
Native header ordering and Cart DTO selection also remain correct. Headless
wallet and PayPal message-host rendering remains inconclusive, so this does not
claim a real wallet, financing or payment test. The preceding visual run
`40082411731-1` stopped on public HTTP loading before creating a Cart; the retry
changed no application files.

The baseline also installed the live [hero-image editor](https://petish.biz/wp-admin/admin.php?page=petish-hero-images)
and account dropdown. Photo choices remain preserved. The corrective header
places the account widget after currency while retaining native visibility and
breakpoint behavior; hosted provider-specific observations are recorded separately.
