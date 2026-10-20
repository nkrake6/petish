# Petish WooCommerce email branding

The shared HTML email header and footer live in the The7 child theme at
`woocommerce/emails/`. Styling is appended through
`inc/petish-email-branding.php`, loaded by the child theme's `functions.php`.
WooCommerce still renders its native order, address, refund and customer-note
content. Plain-text messages keep their native format.

The owner requested the full logo with the animals. The header references the
original `assets/brand/combined_light_landscape.png` (1372 × 938), displayed at
240px wide with its natural aspect ratio. The original artwork is unchanged.
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
