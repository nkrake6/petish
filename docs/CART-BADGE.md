# Cart quantity badge

The owner approved the yellow, font-height circle and authorized deployment on
21 October 2026. Hosted staging and production acceptance are in progress.

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

Local verification passed 79 release, request-boundary and cleanup tests, plus
eight browser mock cases across light/dark mode and 320/390/767/1440px widths.
Those mocks cover quantities through 1,500, pending Blocks updates, cloned
headers, stale responses, failures and recovery; they do not replace hosted
acceptance. Hosted staging requires 20 fixture-preparation, 19 real-cart browser
and 28 cleanup checks in addition to the existing homepage checks. Its owned
synthetic customer/product tests quantities 2 → 3 → 0, private responses and a
separate empty visitor session. Production verification uses anonymous GETs
and exact asset hashes, without test carts, accounts, uploads or orders.

Initial candidate: `c931434faaf7e36096e240c57c5636a830c246a1`.
