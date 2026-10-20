# Petish visual system

Implemented in the The7 child theme only. Parent theme, WordPress core and generated The7 CSS are not edited.

## Files
- functions.php: frontend enqueues, pre-paint theme bootstrap, logo integration, accessible viewport, appearance controls and preview indexing protection.
- assets/css/petish.css: shared light/dark tokens, The7 variable mapping and existing component styles.
- assets/js/theme.js: light/dark/system preference, storage/system changes, inserted-logo synchronisation and explicit preview interactions.
- assets/fonts/: self-hosted Latin WOFF2 subsets for Poppins 600/700, Nunito Sans 400/600 and DM Sans 400/500; OFL licences included.
- assets/brand/: original supplied ZIP files remain unchanged. assets/brand/repaired/ contains separate repaired v2 transparent derivatives now used by the site; see REPAIRED-ASSETS.json.
- page-brand-preview.php: real WordPress preview at /brand-preview/, clearly labelled as a design demonstration and marked noindex.

## Intentional additions
The supplied decorative borders remain unchanged. Separate control borders (#8290A5 light / #6F829D dark) identify inputs and secondary buttons at >=3:1 against adjacent surfaces. Semantic error/success, disabled and hover tokens complete the component states. Navigation and controls use the specified DM Sans role. Body bold is 600 to match supplied weights.

Appearance defaults to the system preference and uses localStorage key "petish-theme". Options: system, light, dark. The selector is in the signed-in user's My Account → Account details profile, with changes applied immediately on the current browser. It has no form submission name and does not require saving account details. If storage is blocked, theme changes still work for the current page. Native form controls follow color-scheme.

## Logo repair
Four repaired v2 RGBA derivatives from the complete supplied JPEG now replace the live lettering and preview combined-logo references. The original PNGs remain untouched. Full lettering/tagline and alpha edges were visually checked on light/navy surfaces. Slight shading differences and negligible low-alpha specks remain; these are raster repairs, not pixel-identical/vector production masters. Repair notes and prompts: output/brand-repair-20261018/.

## Preview and scope
No fake product, Passport, account or checkout functionality is introduced. Existing content is retained. WooCommerce is not installed, so its product/checkout components require a later integration pass. This implementation does not assert WCAG conformance of the entire parent theme.

## Fonts
Font CSS obtained from the official Google Fonts CSS API, then files served locally. Only Latin subsets are installed for the English-language current site; other writing systems use the fallback stack until explicitly added.
API documentation: https://developers.google.com/fonts/docs/css2

## Rollback
A timestamped backup under the site's backups directory contains the pre-change database and child theme. Reactivate dt-the7 to remove the Petish visual system. The preview is a normal page and can be trashed separately. Database restore is unnecessary for this theme-only rollback.

## Verified checks (18 October 2026)
- PHP syntax checks passed for functions.php and page-brand-preview.php; theme.js syntax checked with Node.
- Browser preview tested at 390, 768 and 1440px in light/dark; no document horizontal overflow. Appearance control also checked at the 992px header boundary.
- Theme selection persists across reload and navigation; correct unmodified light/dark images load. System mode initially resolved to dark on this machine.
- Native dialog opens, autofocuses Close, closes with Escape and returns focus to the opener.
- Form demonstration displays accessible feedback without navigation; fields are disabled until the submit handler attaches. Inputs have no submission names.
- Existing homepage/Sample Page, sidebar search, blockquotes, footer, native mobile menu and active navigation were inspected. Legacy hard-coded colours were overridden where needed.
- Measured rendered text contrast: light body 15.17:1, muted 6.37:1, primary button 9.51:1, secondary button 6.92:1, error 6.57:1. Dark body 17.32:1, muted 8.92:1, primary button 9.18:1, secondary button 10.30:1, error 9.16:1.
- All 18 installed logo/artwork PNGs match their supplied ZIP SHA-256 hashes.
- Backup: backups/before-brand-system-20261018-135657 (database.sql + child-theme.tgz). Dump/archive integrity inspected; a full database restore was not performed. Earlier failed backup attempts are not usable recovery points.

Remaining QA: full assistive-technology, cross-browser, OS preference-change and transactional checkout testing remain later work. The repaired raster versions are installed; an original layered/vector master is still preferred for exact artwork fidelity.

## Repair update
Live logo references now use assets/brand/repaired/lettering-{light,dark}-v2.png, and the preview uses the combined pair. Existing CSS preserves natural proportions. Original SOURCE-SHA256.json remains the original archive manifest; repaired files have a separate manifest. No database changes.

## Browser icon update (18 October 2026)
The owner-supplied `webapp-icon.png` is retained unchanged at `assets/brand/webapp-icon.png` (589×589 RGBA; SHA-256 `83fad23e7e1fec8a00d4c15a86ef2ac6e49404ca79792bb9a8555f5a0e03b0c4`). It is installed through WordPress's native Site Icon setting as Media Library attachment 112, replacing the previous unset value (0). WordPress generated 32, 64, 180, 192, 270 and 512px variants without recolouring the artwork. The same supplied icon applies to both colour themes. The child theme's earlier light/dark icon fallback is automatically suppressed while the Site Icon is set; The7's competing device-icon settings remain empty. Include the Site Icon option, attachment and uploads when migrating the database and media to hosting.

Hostinger migration retained attachment 112 and all icon variants. A conventional `favicon.ico` fallback is copied to the public document root; its source is `assets/brand/favicon.ico`. The ICO wraps the exact generated 32px PNG without changing its pixels. Update this fallback alongside future Site Icon changes. Hosted shop layouts were checked at 390px and 1440px in both themes, with loaded fonts/images and no horizontal overflow. Saved captures: `output/hosting-ssh/shop-{mobile,desktop}-{light,dark}.jpg`.

## Contact bar hidden (18 October 2026)
The child theme empties The7's `top_bar_left` and `top_bar_right` widget lists, so the phone/hours/social widgets are not copied into mobile menus. The top-bar row is also explicitly hidden in CSS to prevent a reserved empty strip. Saved contact details remain in the theme options; the main header is retained.

## Appearance in account profile (18 October 2026)
The owner requested that appearance live under the user profile. The selector is now rendered through `woocommerce_before_edit_account_form`, above and outside the personal details form so it cannot submit account fields. The desktop header widget and separate mobile top strip are removed. Light/dark/system behaviour, saved browser preference and logo synchronisation use the existing theme script and bootstrap.

## Purple buttons (18 October 2026)
The owner approved the Soft purple proposal for the live site. Primary buttons use #6532A1 with white text in light mode, with #512581 on hover. Dark mode uses #B580F5 with #0B1426 text and #C69CF8 on hover. Matching purple borders replace the earlier contrasting outline. Normal text contrast is 8.32:1 in light mode and 6.45:1 in dark mode.

Secondary buttons use #EDE7FF / #6532A1 in light mode and #32244E / #E9D5FF in dark mode, with #E1D6FA / #433063 hover fills respectively. A contrasting neutral border preserves the control boundary. Native WooCommerce's Confirm email address action is secondary; Browse products remains primary. Controls retain 12px corners, DM Sans labels, at least 44px height and visible keyboard focus.

The7 button variables (including composite backgrounds), explicit theme buttons, WooCommerce account buttons, Passport and catalogue buttons consume shared button tokens. Ordinary links and focus rings retain their theme-specific teal/cyan colours. Destructive buttons and Google sign-in branding retain their semantic colours; logo artwork is unchanged.

Live QA confirmed the primary and secondary colours in both themes on Account details and Orders, purple Passport and catalogue actions, muted disabled controls, 12px button corners, 45px native account actions and a visible 3px keyboard focus ring. Orders was checked at 1440px and 390px without horizontal overflow. Direct account notice panels have a 1px theme border, 20px vertical / 24px horizontal desktop padding and 16px separation. The7 inner notice wrappers align text and actions centrally in a row; at 600px and below, panels use 20px padding and stack actions below the message with a 16px gap. Buttons retain at least 20px inset from panel edges. The saved System preference and viewport were restored after checks. Captures: `output/appearance-profile/orders-{light,dark}-purple.jpg` and `orders-mobile-light-purple.jpg`.

## Link presentation (18 October 2026)
The owner requested no link underlines anywhere on the frontend, including hover states. A shared anchor/descendant rule suppresses text decorations across parent-theme and plugin styles. Passport/catalogue underline rules are removed, paragraph links use semibold text as an additional visual cue, and keyboard focus outlines remain visible.

Profile placement was verified live at 1440px and 390px: one selector, no header/mobile strip, no horizontal overflow, and immediate light/dark changes. The selected mode survived reload, and keyboard focus retained its 3px outline. The original System preference and temporary viewport were restored after QA. Computed link decorations were none throughout the account page. Captures: `output/appearance-profile/profile-desktop-light.jpg` and `profile-mobile-{light,dark}.jpg`.

## Sticky-header surface fix (19 October 2026)
The owner reported white gutters on either side of the floating header while scrolling in dark mode. The7's compiled !important floating-header selector outweighed the previous child override, leaving the full-width masthead white around its navy inner header. The existing sticky override now uses the site's body ID and covers both sticky-on and sticky-mobile-on, retaining the shared surface colour without changing header dimensions or positioning.

Live QA: reproduced and fixed at 1920px, checked top/sticky transitions at 1440px, and verified light/dark mobile headers at 390px with no horizontal overflow. Mobile menu opened and closed normally. System preference and viewport were restored. Captures: output/sticky-header/dark-before.jpg, dark-after.jpg and mobile-dark-after.jpg.

## Landing page and navigation (19 October 2026)
The child theme now supplies `front-page.php` and front-page-only `assets/css/home.css`, retaining The7's header/footer and the existing tokens. The approved transparent combined light/dark artwork switches through the existing theme script. The desktop hero heading is capped at 48px, with responsive type, four category cards, species collection links, a Passport section and a published-product section. Draft products and private pet data are excluded from that section; an empty state is shown until products are published.

Native menus replace the automatic sample-page list. Shop contains all-products and category links, My Pets uses the existing account endpoint, and About Petish is a native page. Footer navigation replaces the sample Archives/Categories widgets, which remain recoverable as inactive widgets. Submenu text and mobile expansion SVGs explicitly consume the theme tokens so compiled The7 colours cannot make them unreadable in dark mode.

QA passed at 1440px and 390px in both themes without horizontal overflow. Desktop Shop keyboard expansion, mobile open/close and all five submenu entries were checked. Home, About, Shop and all four category destinations returned anonymous HTTP 200; the five draft product URLs returned 404, and public homepage content excluded draft product names/private pet context. Four PHP files passed syntax checks. System preference and the viewport were restored. Captures and the verification record are in `output/storefront/`.
