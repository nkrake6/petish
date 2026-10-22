# Cart quantity badge

Live on [petish.biz](https://petish.biz) as of 22 October 2026, following hosted
staging and production verification. The owner approved the yellow, font-height
circle and authorized deployment on 21 October.

The badge counts item quantities, including multiple units of one product. It
is hidden for an empty cart. The circle is 14px in both themes, with yellow
`#f9a825` and dark `#0b1426` text. Counts above 99 display `99+`; the full quantity
remains available to assistive technology. Existing Cart menu links and The7
cart widgets are enhanced without adding a new header widget or increasing
header height.

Cacheable HTML contains only shared configuration. A count-only WooCommerce
AJAX endpoint reads the current visitor's session through a same-origin POST
and returns private, no-store responses varying by Cookie. WooCommerce may
reconcile and save its native cart session while reading it. The badge calls no
order or payment operation and does not persist quantities in browser storage.
Classic and Blocks cart updates, header clones, reloads and tab changes refresh
the count. Failed requests hide the badge instead of retaining a stale count.

The release extends the existing homepage/header manifest to 18 files. Only
four application files change: `includes/header-navigation.php`, new
`includes/cart-badge.php`, `assets/css/cart-badge.css` and
`assets/js/cart-badge.js`. The new dependencies are installed before the header
require. The existing protected-state, rollback and targeted-cache-purge
transaction remains in place.

Local release, request-boundary and cleanup tests passed, plus
eight browser mock cases across light/dark mode and 320/390/767/1440px widths.
Those mocks cover quantities through 1,500, pending Blocks updates, cloned
headers, stale responses, failures and recovery; they do not replace hosted
acceptance. Hosted staging requires 20 fixture-preparation, 19 real-cart browser
and 28 cleanup checks in addition to the existing homepage checks. Its owned
synthetic customer/product tests quantities 2 → 3 → 0, private responses and a
separate empty visitor session. Production verification uses anonymous GETs
and exact asset hashes, without test carts, accounts, uploads or orders.

Private staging retains its sign-in fence. Its anonymous count proof uses an
expiring private token limited to the count endpoint and an auth-only callback
attested against the pinned guard source. SQL, outbound HTTP and mail isolation
remain in place. An offline Chromium regression also covers delayed native
Cart rendering without selecting a separate Mini Cart control.

Initial candidate: `c931434faaf7e36096e240c57c5636a830c246a1`.

Hosted staging passed all required phases in
[run 39869020616-1](https://github.com/nkrake6/petish/actions/runs/39869020616),
using source `aab802ad84921f0f9c00aef1ee274a2e9ba97194` and receipt
`automation-status/homepage-39869020616-1`. That includes 20 fixture preparation,
19 native Cart browser and 28 cleanup checks, in addition to the existing
homepage checks. The optional absence of supplier-free-delivery products was
expected.

The release uses full backup `full-site-backup/39854296524-1`, verified by
`automation-status/backup-39854296524-1` and application recovery
`automation-status/recovery-39854818553-1`. Restoration verified 40,088 files,
103 database tables, WordPress pages and private-media protections.

Production [run 39869593070-1](https://github.com/nkrake6/petish/actions/runs/39869593070)
completed successfully at 23:07 UTC on 22 October 2026, using release commit
`19b08bbe998839a98dc845c9afac4ecd651c4e26`. The final receipt is
`automation-status/homepage-39869593070-1`, commit
`be7b4759622c0816b3b69557054d24689364c507`. Exactly four files changed. Native
verification, page-cache purge and verification, public page/asset checks and
the final release commit passed. Protected state was unchanged, the maintenance
guard was removed and no rollback was needed.
