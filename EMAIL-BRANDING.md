# Petish WooCommerce email branding

Status: shared order/account branding is live on `petish.biz` following
[production release 39407138767](https://github.com/nkrake6/petish/actions/runs/39407138767)
from release-tools commit `81d7598`, deploying the six unchanged files tested
on child-theme commit `86e315f`. All seven native email types and the core
welcome/reset filters passed on the production installation. Native account
links, full animal logo, verification button, public store pages and logo pixels
passed. Order/user counts, authentication state, stock and email/account settings
were unchanged; verification dispatched no message. The private rollback copy
and a verified pre-release full backup were retained. See
[PRODUCTION-EMAIL-RELEASE.md](PRODUCTION-EMAIL-RELEASE.md) for release evidence.

Shared branding was deployed to private staging in
[`39383134509`](https://github.com/nkrake6/petish/actions/runs/39383134509)
from child-theme commit `86e315f`. Native rendering and independent publication
screening passed in
[`39383213374`](https://github.com/nkrake6/petish/actions/runs/39383213374)
using preview tools `86e315f`. All seven types rendered with the animal logo;
order/user counts, authentication state and stock were unchanged. The deployed
core WordPress welcome/reset filters also passed synthetic message/link checks. Chromium checks passed for every
type at 900px, 390px and inside the 326px mobile gallery. Tabs, keyboard controls,
direct links and image loading passed with no overflow or JavaScript errors.

Review gallery: <https://nkrake6.github.io/petish/email-preview/?v=3>.
Public availability passed in
[`39383546221`](https://github.com/nkrake6/petish/actions/runs/39383546221):
the gallery, all seven emails, scripts, styles and animal logo matched the
reviewed Git files byte for byte.

The shared HTML email header and footer live in the The7 child theme at
`woocommerce/emails/`. Styling is appended through
`inc/petish-email-branding.php`, loaded by the child theme's `functions.php`.
WooCommerce still renders its native order, address, refund and customer-note
content. The shared `email-button.php` component preserves each native
action URL/label and uses readable white text on Petish purple. WooCommerce plain-text messages keep their native format.

The same design now covers `customer_new_account`, `customer_verify_email` and
`customer_reset_password`. Their native templates, message text and security-link
generation remain WooCommerce-owned. The child theme also loads
`inc/petish-account-email-branding.php` for WordPress core new-user and
forgot-password messages that bypass WooCommerce email classes. Only the two
complete mail-array filters are used; native recipients, subjects, body text and
authentication URLs are preserved. Explicit formats and existing HTML from other
plugins are respected. No global mail format, account policy or token behavior
is changed.

The owner requested the full logo with the animals. The header references the
updated `assets/brand/repaired/combined-light-v2.png` (1536 × 1024),
displayed at 240 × 160px with its natural aspect ratio. This is the existing
complete artwork used by the site brand preview; its file bytes are unchanged.
The older `combined_light_landscape.png` has damaged transparency through the
lettering and must not be used for email branding. Public previews use the new
filename `petish-logo-v2.png` and versioned gallery links to avoid stale caches.
Colours are Petish purple `#6532A1`, lavender `#EDE7FF` and navy `#14213D`.
Email styling is self-contained and does not depend on website CSS or web fonts.

## Staging review

The staging deployment swaps only the existing child theme and two custom
plugins, retaining a private rollback release. It preserves the staging guard,
configuration, mail capture and integration isolation. Live email branding is
not changed by this staging deployment.

The **Preview Petish staging order email branding** workflow follows a successful
`staging` code deployment, or can be run manually. It reuses the verified staging
bootstrap in `staging-rehearsal-remote.php`, selecting the render-only
`email-preview` operation. All HTTP remains blocked. It renders processing,
completed, full-refund, customer-note, new-account, verify-email and
password-reset messages with the installed native
WooCommerce renderer and inline styling.

These are unsaved in-memory orders with fictional Alex Taylor details, order
P-1042 and A$14.60 total. The account previews use an unsaved `WP_User(0)` for fictional Alex and a fixed
nonfunctional reset marker. No checkout, provider payment, refund request,
supplier call, account creation, token generation or email submission is
performed. Order/user counts, product stock and private SQL-computed digests of
authentication state are checked before and after rendering. Only equality
booleans are published; no keys, user records or digests leave the server. The readiness marker remains in the
private staging mail capture.

Shareable copies use fixed example footer/additional copy and public account
links rather than exposing private staging settings or customer links. The
fictional item uses WooCommerce's native no-photo option. The
deployed template retains WooCommerce's configurable footer and body hooks.

Only seven screened synthetic HTML files, the approved logo and finite result
booleans can leave the private runner. A second independent HTML validator
rejects active content, private URLs, unexpected images and non-synthetic email
addresses. Raw output, captured mail, credentials and customer/order data are
never published. The result branch is `email-preview/<run>-<attempt>`.

Reviewed generated files are copied into `docs/email-preview/` for GitHub Pages.
The gallery offers seven email types and desktop/mobile widths; each email also
has a direct link. Public availability must be verified after publishing.
Staging previews establish rendering in a browser, not universal email-client
compatibility or live DSers tracking. Inbox delivery was tested separately.

## Email verification behavior

WooCommerce’s existing `customer_verify_email` message confirms inbox ownership
before linking previous guest orders to a logged-in account. It is not mandatory
registration activation. Its Orders-page prompt depends on the email being
enabled, guest checkout being allowed and the customer still needing verification.
Native password setup/reset also confirms inbox ownership. The preview reports
the staging enabled/guest-checkout flags without changing either setting.
The latest staging flags were verification enabled and guest checkout disabled;
the Orders-page prompt was not exercised in this render-only check. Production
trigger settings were not changed or inferred from staging.

The shared WooCommerce shell covers this native message. WordPress core profile
email-change confirmations and third-party social-login notification paths are
separate; they are not claimed as changed or tested by these account previews.

## Future releases

Review the actual staging previews before another production release.
Keep the shared header/footer together with their helper and `functions.php`
include. Preserve configurable WooCommerce footer text and native body hooks.
Check template compatibility after WooCommerce upgrades. Production deployment
and any new test-email recipient need their own authorized scope.
