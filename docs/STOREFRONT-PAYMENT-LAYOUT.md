# Storefront payment groups and Cart readability

Current application source: `c369c24`, production release source `ad8a010`,
25 October 2026 (Australia/Melbourne). Prior baseline: `3691306` / `cff4586`.

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
decorative badge beside the native Afterpay message uses the exact unchanged
SVG shipped by WooPayments: 64px wide and 40px tall, limited to its container width, with its
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

Cart styles place Google Pay above Afterpay and then PayPal in the supported
native Cart layout. They group the existing native
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
| Earlier 150px native product/Cart visuals | [40082669837-1](https://github.com/nkrake6/petish/tree/automation-status/storefront-runtime-40082669837-1), diagnostic source `3c1ac7b` with unchanged `ceaa969` application bytes; 150×93.75px branding visible, native product appearance matches its original 17px source, guest Cart and temporary tools removed |

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

## Compact badge follow-up

The owner found the 150px badge too large and requested a size comparable to
the Google Pay mark. Application `86391bb` changes only its CSS width to 64px;
the SVG's natural 8:5 ratio yields 40px height and a roughly 56px-wide wordmark.
It retains the unchanged artwork, payment text, See plans placement, provider
eligibility and all JavaScript/PHP. The current-size browser assertions pass
at 1440, 800 and 390px. Staging install `40084030183-1` passed all eight steps;
full verification `40084162569-1` (`7319362`) passed all 226 required checks and
matched all 30 application hashes. Production
[40084541619-1](https://github.com/nkrake6/petish/tree/automation-status/homepage-40084541619-1)
(`b83783e`) passed all ten phases, 178 required checks and all 30 hashes, changing
exactly one CSS file. Cache and maintenance cleanup passed. Final visual
inspection [40084674282-1](https://github.com/nkrake6/petish/tree/automation-status/storefront-runtime-40084674282-1)
(`b83783e`) confirms the 64×40px badge, original native product/cache font of
17px and unchanged 13.39px Cart provider measurement. Native text/link layouts,
header ordering and Cart provider selection remain intact. The fresh guest
Cart and private tools were removed.

The current compact images were hash-verified and visually inspected:
[product, 400×111px](https://github.com/nkrake6/petish/blob/7539efa246bb918464d421354c3f772d2363ac5f/product-afterpay.png)
and [Cart, 310×113px](https://github.com/nkrake6/petish/blob/7539efa246bb918464d421354c3f772d2363ac5f/cart-payments.png).

## Native text beside the compact badge

The owner then requested the payment text to the right of the badge. CSS-only
application `2ae81fd` keeps the badge at 64×40px, reserves 88px of start padding
(12px outer padding, 64px badge, 12px gap), and centers the badge vertically.
The existing native host stays in normal flow with its original fonts and
controls. This does not override native display, fix iframe heights or change DOM order;
WooPayments can still hide/show its message with inline display and update its
own height after quantity changes. At a 400px panel the native content gets
298px and wraps naturally. The 66px minimum panel height accommodates the badge,
padding and borders without clipping.

Responsive and native hide/show checks passed at 320, 390, 800 and 1440px.
Staging install `40123310426-1` (`6aede0d`) passed all eight steps; full proof
`40123521325-1` (`15355c2`) matched all 30 hashes and all 226 required checks.
Fresh encrypted backup `40122768140-1` and matching restored-application proof
`40123260114-1` passed. Production
[40124005493-1](https://github.com/nkrake6/petish/tree/automation-status/homepage-40124005493-1)
(`2a867ec`) changed one stylesheet and passed all ten phases, 178 required checks
and all 30 hashes. Cache and maintenance cleanup passed. Final native inspection
[40124165126-1](https://github.com/nkrake6/petish/tree/automation-status/storefront-runtime-40124165126-1)
(`2a867ec`) confirms the compact badge sits left of the native message on both
product and Cart, with 40px vertical overlap. The native iframe-edge gap is
8px within the reserved 12px spacing; the SDK's own insets are untouched.
Product actual native-frame text/link measured 15.81px, separately from its
unchanged 17px quantity/cache source. Cart measured 13.39px. No font setting was
overridden. Both fixed crops were hash-verified and visually inspected; guest
Cart and private tools were removed. This verifies layout, not wallet
authorization or payment processing.

Current aligned previews:
[product, 400×68px](https://github.com/nkrake6/petish/blob/eea3c777cca28a95d80f6950c5b841dd759245e1/product-afterpay.png)
and [Cart, 310×67px](https://github.com/nkrake6/petish/blob/eea3c777cca28a95d80f6950c5b841dd759245e1/cart-payments.png).

## Cart wallet order and divider clearance

The owner then reported Google Pay below Afterpay and the green border touching
both native dividers. Live baseline `40125505336-1` confirmed the exact native
Summary → Slot → OrderMeta → sole BNPL shape and only 0.5px divider clearance.
Wallets did not render in that headless run, so its image was not button-order
proof.

Application `c369c24` changes only Cart CSS and presentation JavaScript. It
recognizes the fixed native summary/express structure, including empty native
notice wrappers. CSS exposes those existing boxes to the Sidebar flex layout,
placing Total → Google Pay → Afterpay → PayPal/Pay in 4/message → OR → checkout.
The entire native Slot/OrderMeta/BNPL group stays intact, retains its top divider
and receives the summary's bottom-divider style. The BNPL panel has 16px top
and bottom margins. Its native OrderMeta contains those margins even when no
wallet is available, preventing margin collapse and border contact.

No SDK nodes are moved or cloned; their parents, handlers, focus, badge and
font settings remain intact. Unknown/mixed slots or populated express notices
retain native layout with the divider-spacing correction. This is a visual
presentation change; native DOM keyboard order remains unchanged.

The native vendor-CSS fixture passes at 320, 390, 768 and 1280px, including
actual divider gaps in active and no-wallet fallback layouts, closed-shadow
message sizing, native notices, remounting and processing state. The previous
deployed CSS fails its Google Pay-before-Afterpay assertion. The existing
hosted layout predicate now also requires wallet → BNPL → PayPal when present.
Staging `40126132827-1` (`c369c24`) passed all eight steps, and full staging
`40126382686-1` (`523db17`) passed all 226 required checks and all 30 hashes.
Production
[40126747135-1](https://github.com/nkrake6/petish/tree/automation-status/homepage-40126747135-1)
(`ad8a010`) passed all ten phases, 178 required checks and all 30 hashes,
changing only Cart payment-layout CSS and JavaScript. Cache, health, assets and
commit checks passed. Final live inspection
[40127230278-1](https://github.com/nkrake6/petish/tree/automation-status/storefront-runtime-40127230278-1)
(`e4157f0`, unchanged `ad8a010` application bytes) confirms both actual divider
gaps are 16.5px, up from 0.5px. Native Slot inner gaps are 16px top and bottom;
both border-touch flags are false. The expanded actual Cart crop was
hash-verified and visually inspected:
[current divider spacing](https://github.com/nkrake6/petish/blob/7de36ebf20c3438cd0247cee37e031d41a10dac6/cart-payments.png).
The badge remains 64×40 with text on its right; product/Cart native frame font
metrics remain 15.81px/13.39px. Fresh guest Cart and private-tool cleanup passed.
Google Pay and PayPal did not render in this headless session, so that run does
not claim visible-wallet ordering. That ordering is covered by the actual
native vendor-layout fixtures and the now-strengthened conditional release
predicate; browser wallet availability remains native.

The baseline also installed the live [hero-image editor](https://petish.biz/wp-admin/admin.php?page=petish-hero-images)
and account dropdown. Photo choices remain preserved. The corrective header
places the account widget after currency while retaining native visibility and
breakpoint behavior; hosted provider-specific observations are recorded separately.
