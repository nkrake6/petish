# Petish WooCommerce email branding

Status: shared branding deployed to private staging in
[`39379561684`](https://github.com/nkrake6/petish/actions/runs/39379561684)
from child-theme commit `238d7dc`. Native rendering and independent publication
screening passed in
[`39379634749`](https://github.com/nkrake6/petish/actions/runs/39379634749)
using preview tools `238d7dc`. All four types rendered with the animal logo;
order-record counts and stock were unchanged. Chromium checks passed for every
type at 900px, 390px and inside the 326px mobile gallery. Tabs, keyboard controls,
direct links and image loading passed with no overflow or JavaScript errors.
Live branding is unchanged.

Review gallery: <https://nkrake6.github.io/petish/email-preview/?v=2>.
Public availability is checked after each preview publication.

The shared HTML email header and footer live in the The7 child theme at
`woocommerce/emails/`. Styling is appended through
`inc/petish-email-branding.php`, loaded by the child theme's `functions.php`.
WooCommerce still renders its native order, address, refund and customer-note
content. Plain-text messages keep their native format.

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
completed, full-refund and customer-note messages with the installed native
WooCommerce renderer and inline styling.

These are unsaved in-memory orders with fictional Alex Taylor details, order
P-1042 and A$14.60 total. No checkout, provider payment, refund request, supplier
call or email submission is performed. Order-record counts and product stock
are checked before and after rendering. The readiness marker remains in the
private staging mail capture.

Shareable copies use fixed example footer/additional copy and public account
links rather than exposing private staging settings or customer links. The
fictional item uses WooCommerce's native no-photo option. The
deployed template retains WooCommerce's configurable footer and body hooks.

Only four screened synthetic HTML files, the approved logo and finite result
booleans can leave the private runner. A second independent HTML validator
rejects active content, private URLs, unexpected images and non-synthetic email
addresses. Raw output, captured mail, credentials and customer/order data are
never published. The result branch is `email-preview/<run>-<attempt>`.

Reviewed generated files are copied into `docs/email-preview/` for GitHub Pages.
The gallery offers four email types and desktop/mobile widths; each email also
has a direct link. Public availability must be verified after publishing.
Staging previews establish rendering in a browser, not universal email-client
compatibility or live DSers tracking. Inbox delivery was tested separately.

## Later live release

Review the actual staging previews before a separate production release.
Keep the shared header/footer together with their helper and `functions.php`
include. Preserve configurable WooCommerce footer text and native body hooks.
Check template compatibility after WooCommerce upgrades. Production deployment
and any new test-email recipient need their own authorized scope.
