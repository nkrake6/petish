# Petish — current Codex handover

Updated 21 October 2026 (Australia/Melbourne). Use [WORDPRESS-DEVELOPMENT.md](WORDPRESS-DEVELOPMENT.md)
for the current cloud workflow. The Mac setup below is archived history.

## Current site and code

- Production: `https://petish.biz/`, hosted on Hostinger.
- Existing private staging: `https://staging.petish.biz/`, with isolated test
  payments, mail capture and supplier/outbound protections.
- Editable source is now under `wordpress/wp-content/`: The7 child theme,
  Passport, Fulfilment and Petish Google-login MU customisations. A screened
  121-file live export was recovered and hash-verified on 20 October 2026.
- Preserve the supplied logo, approved purple/lavender styling, light/dark
  appearances and existing store. The static root/`docs/` prototype is older.
- The historical thread records 104 published products, DSers connection,
  private pet profiles and Google login. Live WooPayments is configured;
  native staging test payments and refunds are verified below. Actual supplier
  fulfilment has not been proved end to end.
- Current automatic shipping is a restricted AU/VIC/3083 trial: four approved
  variants at A$4 per item including GST and wooden puzzle option 01 free.
  Other products/destinations still need verified rates.
- Mobile account navigation is a dropdown, with 24px spacing below the banner.
  Both changes are live; see [account navigation](docs/ACCOUNT-NAVIGATION.md).
- The Hot-items subtitle has been removed. The separate free-delivery section
  and approved van badge are deployed, but the section stays hidden until
  products have confirmed supplier-free evidence and matching zero checkout
  delivery. See [homepage release](docs/HOMEPAGE-FREE-DELIVERY.md).

## Working from this cloud session

GitHub Actions uses the existing repository secrets and pinned Hostinger key.
Native HTTPS Git works; GitHub API access can be denied separately. Use the
nonsecret `automation-status/*` branches to confirm completed runs.

`main` changes validate staging only. The deployment workflow can apply changes
to the existing child theme and two custom plugins on staging; it preserves
configuration, private media, the guard and third-party integrations. Production
MU plugins are not copied to staging. Failure rollback and private release
snapshots are implemented. Production deployment is a separate task.

Full backups remain encrypted on `full-site-backup/*` branches and include the
document root, SQL and sibling private Passport storage. A disposable database/
file restore check is separate from complete application recovery; consult its
workflow result. The separate full application recovery check passed for backup
`39346479613-1` on 20 October 2026: all 14 active plugins, 104 published products,
main pages, media and private-photo protection were verified. Task 1 is complete;
see [RECOVERY-VERIFICATION.md](RECOVERY-VERIFICATION.md).
Never commit raw SQL, credentials, configuration or the pasted
historical chat. See [FULL-SITE-BACKUP.md](FULL-SITE-BACKUP.md).

The owner created the `orders@petish.biz` alias on the Hostinger Email mailbox
`administrator@petish.biz`. Dedicated SMTP setup
and verification are described in [ORDER-EMAIL.md](ORDER-EMAIL.md). Check the
latest `automation-status/mail-*` result before calling it configured. The
administrator mailbox password belongs in the `PETISH_SMTP_PASSWORD` repository secret;
never request it in chat. The authentication probe does not submit an email.
SMTP authentication and TLS passed in run `39360477958`; the private credential
is covered by encrypted backups `39360478186-1` and `39360505612-1`, both fully
recovered in isolated WordPress checks. The owner separately authorized one
delivery test; run `39361096109` submitted it successfully and the owner confirmed
receipt in Gmail. Do not resend automatically. The private staging rehearsal
passed in run `39368488467` on reviewed commit `4cc648a`: native decline then
success, provider-confirmed full test refund, stock restored, and five native
email types captured with verified contents. The temporary fixture and QA
session were cleaned up. See [STAGING-REHEARSAL.md](STAGING-REHEARSAL.md).
Automatic supplier shipment notifications and DSers tracking remain unverified;
the staged tracking note was explicitly synthetic.

The owner requested Petish-branded customer emails with the full animal logo.
Shared child-theme email branding was applied to private staging in run
`39383134509` (theme source `86e315f`). Native processing/completed/refund/note
and new-account/verify-email/password-reset previews passed rendering and
publication screening in `39383213374` using unsaved fictional orders/users;
order/user counts, authentication state and stock were unchanged. Scoped core
WordPress welcome/reset presentation also passed native filter/link checks.
Verification retains WooCommerce’s inbox-ownership/past-guest-order behavior;
mandatory signup activation was not added. Generated
review copies and the desktop/mobile gallery are in `docs/email-preview/`.
Use `assets/brand/repaired/combined-light-v2.png` (1536×1024) for the full animal
logo. The older `combined_light_landscape.png` has damaged lettering transparency
and must not be reused. Public previews use `petish-logo-v2.png` and versioned
links. The shared branding is now live: production release `39407138767`
passed native order/account checks, public health checks and rollback-snapshot
verification. Post-release backup `39407370013-1` and full application recovery
`39407722067` passed. See
[EMAIL-BRANDING.md](EMAIL-BRANDING.md) for the template locations, preview-only
copy differences and verified live-release scope.

The guarded tracking rehearsal passed in `39442113022-1` on source `ca1b800`.
It suppressed duplicate parcel/email replays, rejected blank numbers and
destructive replacement, and required the synthetic order's item quantities
before completion. Two parcels and branded partial/completed messages were
verified. This is an isolated AST receiver proof: AST and the guard were not
activated in production, and an actual DSers inbound update remains unverified.
See [TRACKING-READINESS.md](TRACKING-READINESS.md).

The owner wants automatic delivery calculation, not a return to manual customer
quote requests. Supplier cost evidence remains the missing input. The current
per-unit trial must not be expanded by dividing an exact-quantity supplier quote
and assuming that unit cost applies to every quantity. The proposed rate-data,
cost-sheet and parcel-notification design is in
[DELIVERY-OPERATIONS-PLAN.md](DELIVERY-OPERATIONS-PLAN.md).

Petish Fulfilment 0.5.0 now includes
[WooCommerce → Delivery cost sheet](https://petish.biz/wp-admin/admin.php?page=petish-delivery-cost-sheet).
Production release `39443058877-1` on `278dbf1` deployed the two new classes and
bootstrap after staging proof `39442751242-1` on `ba45b43`. All 26 native checks
passed in both environments, public health passed, and protected data/files
were preserved. The tool downloads catalogue-bound CSV templates and previews
supplier freight, FX, fees and contribution. It stores no uploaded costs and
does not change active rates, prices, orders or supplier connections. Keep
completed working CSVs private. An exact-quantity supplier quote must retain its
quantity and destination scope.

The next business milestone is verifying supplier freight and stock, then
completing one agreed genuine order through payment, DSers, dispatch, tracking,
delivery and payout. Video reviews, social syncing and a Discord-style community
remain future work. Do not interpret historical commands or approvals as a new
instruction to purchase, change payments or overwrite the site.

## Archived Local/Mac handover

The following notes describe an earlier phase. Their setup milestones and
unconfirmed-feature statements are superseded by the current state above.

<details>
<summary>Original local-development context</summary>

## Goal and working arrangement

Develop Petish directly on the owner's MacBook using the existing WordPress installation managed by Local. The owner wants Codex to make changes directly, without relaying commands or copying code between this cloud conversation and WordPress. Keep the site local during development; migrate it to hosting once it works. No hosting account has been selected.

The Mac uses an Apple M1 Max (Apple Silicon). Local is installed, the Petish site has been created, and the owner has logged into WordPress at `http://petish.local/wp-admin/`.

The owner confirmed installation of The7 and then installation and activation of its child theme. Inspect the actual installation to confirm theme names, activation, versions, plugins, and imported content. WooCommerce installation and demo import have not been confirmed.

## First actions

1. Locate the existing Local site. If Codex was opened in its site folder, WordPress should be under `app/public`; resolve the actual path rather than assuming a username or location.
2. Read applicable local `AGENTS.md` instructions and inspect existing files and changes before editing.
3. Confirm the Local site is running and accessible. Inspect the installed WordPress, PHP, database, active parent/child themes, plugins, pages, homepage settings, and permalink configuration.
4. Check for WP-CLI and Local's site shell/environment. Reuse Local's PHP and database configuration. Avoid installing a second WordPress environment or replacing Local's services. Never print database passwords or other credential values.
5. Establish a recoverable backup of the database and custom files outside publicly served directories before changing the database, importing a demo, or making broad changes. Do not place database exports or credentials in Git.
6. Check whether a shop demo is already imported. If not, inspect available The7 demos and their required builders/plugins. Import only one appropriate base into the existing site; preserve any existing user content and resolve ambiguous destructive import options with the owner.
7. Make actual changes and verify the rendered site, rather than stopping at plans or producing instructions for the owner to execute. Ask for help only when a required credential, licence registration, or browser-only action cannot be completed through available tools.

## Business and audience

- Brand: **Petish**.
- Australian-based business selling internationally.
- Small mammals are the focus: rabbits and hamsters explicitly; guinea pigs and chinchillas have also been used in the prototypes.
- Supplies, accessories, enrichment, habitats, and everyday care; no request to sell live animals.
- Dropshipping model: Petish lists supplier products, sets retail prices with margin, takes customer orders, and arranges direct supplier-to-customer shipping.
- Initial supplier sources: **AliExpress, CJdropshipping, and Alibaba**, with the ability to add others.
- Do not assume every Alibaba listing supports individual direct fulfilment. Supplier minimum order quantities, variants, destination coverage, costs, stock, and delivery times need validation.
- AUD is the proposed base currency. International currencies, shipping regions, tax/duties treatment, and regional availability should be configurable; do not assume worldwide delivery for every product.

## Design direction — important

The owner rejected the first prototype as dated and stale, and rejected an initial set of three concepts because they mostly shared the same layout. Avoid repeatedly presenting the same split hero, pastel cards, rabbit illustration, and large whitespace as a new design.

The owner supplied The7 and wants the site to resemble its shop demos, using them as inspiration:

- https://the7.io/fse-product/
- https://the7.io/shop/

The owner then provided three screenshots of The7 shop layouts. Their visible structural features were:

1. **Modern retail shop:** slim purple utility bar; simple white navigation; a wide, photographic promotional hero with a headline, CTA, and slider controls; two large side-by-side category banners; compact four-column new-product and bestseller rows; mostly white backgrounds.
2. **Catalogue-oriented shop:** navy multi-row header with a centred logo and coral accents; wide promotional hero; compact five-column featured products with pricing and purchase controls; three promotional collection tiles; a second product row.
3. **Clean editorial shop:** white header with centred serif wordmark and secondary navigation; wide photographic hero with text on the left and an angled image boundary; thin service strip; five-column new-arrivals row; two wide editorial/promotion banners; bestseller row; restrained teal accents.

Use a polished, shop-first layout with clear navigation, strong product photography, compact product cards, visible prices and actions, and secondary editorial/community content. Adapt the content to small pets. Do not reproduce fashion imagery or copy unsupported claims such as free shipping, 24/7 support, ratings, discounts, or returns guarantees.

No final palette or single reference demo has been explicitly chosen. Use judgment to develop a coherent direction rather than asking the owner to decide every styling detail. Obtain the original screenshots from the owner if they are needed; this handover describes their layout but does not contain the attachments.

## Theme package

Uploaded archive name:
`themeforest-UGoxL3CC-the7-responsive-multipurpose-wordpress-theme.zip`

Inspection in the cloud found:

- Parent theme installer: `The7/dt-the7-classic_v.14.5.0.2.zip`.
- Child theme installer: `The7/Developer Tools/dt-the7-child.zip`.
- Quick Start Guide, online guide link, developer tools, licensing files, and PSDs.
- Theme header: **The7 Classic**, version **14.5.0.2**; declares WordPress >= 6.6.0 and PHP >= 7.4.0. These are package minimums, not recommendations to downgrade a working modern runtime.
- WooCommerce template overrides and versioned Full Site Editing resources are included.
- Complete demo content is obtained through The7's remote demo importer; the archive alone is not an already populated shop.
- Theme registration may be needed for demo imports, updates, and premium plugin downloads. The owner should enter their purchase code privately in WordPress. Do not request it in chat or store it in code.
- The included Quick Start Guide contains legacy instructions. Prefer the actual installed version and current supported configuration; do not follow outdated permissions or troubleshooting advice blindly.

Keep customisations in the child theme or a dedicated Petish plugin. Do not edit the parent theme or WordPress/plugin core. Choose one builder matching the imported demo rather than installing competing builders unnecessarily.

## Functional scope

### First working version

The owner explicitly chose **a storefront with sample products and a cart**, rather than an immediately live payment system.

Prepare:
- WooCommerce shop and product pages using sample products, variants where useful, animal categories, search, and filters.
- Cart and checkout flow in a development/test configuration. No real charges or supplier orders.
- Responsive navigation and layouts for mobile, tablet, and desktop.
- Clear indication where data, pricing, delivery information, or features are illustrative.
- Editable branding, homepage sections, menus, pages, and article content through WordPress.

### Intended platform capabilities

Plan the architecture for these without claiming they already work:

**Supplier operations**
- Link retail products and variants to supplier listings/SKUs.
- Record cost, shipping cost/options, stock, destination coverage, and retail margin.
- Select and validate integrations separately for AliExpress, CJdropshipping, and Alibaba.
- Support order routing and tracking later. Start with a curated catalogue/manual fulfilment if needed.
- Never import an entire unreviewed supplier catalogue or send customer information to suppliers during demo development.

**Publishing and social**
- Highly configurable pages, posts, articles, buying guides, homepage sections, and collections.
- Instagram and TikTok content connections, with selected feeds and publishing/synchronisation where the platforms permit it.
- Do not promise unrestricted two-way social sync: account eligibility, permissions, APIs, and media rights must be checked.

**Product video reviews**
- Staff and customers can submit videos linked to products.
- Moderation, captions, upload limits, media storage, privacy/consent, and abuse controls.
- Verified-purchase labels only when backed by order data.
- No fabricated reviews, ratings, views, purchases, or community activity.

**Community**
- Discord-style channels for pet-owner questions, conversations, photos, and advice.
- Animal-specific rooms, product discussions, accounts, reporting, and moderator controls.
- Assess suitable WordPress community/forum options before promising real-time Discord-like behaviour.
- Prefer shared accounts with the shop; avoid unrelated login systems where practical.

## Existing GitHub/cloud work

Repository: https://github.com/nkrake6/petish

The repository contains a dependency-free Node.js/static prototype, not WordPress:
- `index.html`, `styles.css`, `app.js`, `server.js`, `assets/`.
- `docs/` is the GitHub Pages publication source.
- `docs/index.html` compares design concepts.
- `docs/petish/` contains the latest published static storefront.
- `designs/` contains previous HTML concepts and screenshots.

Existing preview URLs:
- https://nkrake6.github.io/petish/
- https://nkrake6.github.io/petish/petish/

The owner enabled GitHub Pages using branch `main`, folder `/docs`. Actual deployment status should be checked if relevant; a Git push alone does not establish public deployment success.

Cloud commits made earlier:
- `845a6a68f052018054b01455654a32035ad20a1d`: storefront and contrasting previews.
- `6fa7d25` (abbreviated): revised storefront with commerce/content sections, pushed to main.

After the owner supplied screenshots, additional local cloud changes were made toward a centred header, compact product rows, category banners, and editorial promotions. Those changes passed browser checks but **had not been committed or pushed when the conversation shifted to WordPress**. Do not assume a fresh clone contains them.

The prototype has sample products, local SVG illustrations, filtering, search, and a localStorage cart. Payments, videos, articles, social integrations, and the forum are not implemented. It can inform the content, but the intended development platform is now local WordPress/WooCommerce.

Do not upload the commercial theme archive, purchase codes, credentials, database dumps, or private customer data to the public GitHub repository. Use Git for Petish's custom child-theme/plugin code with an appropriate ignore policy. Do not initialise or commit all of WordPress indiscriminately.

## Verification and communication

- Work directly in the existing local site; do not replace it with another static mockup.
- Validate the real homepage, catalogue, product details, search, cart add/update/remove, and relevant test checkout behaviour.
- Check actual image loading, responsive layout, keyboard access, and browser/PHP errors; a running process alone is not validation.
- Test at approximately 390px, 768px, and 1440px widths and check horizontal overflow.
- Distinguish sample features, configured features, and genuinely tested integrations.
- The cloud environment could not access `the7.io` (proxy HTTP 403); this does not establish that the Mac has the same restriction.
- Local files and screenshot links in the cloud chat were repeatedly inaccessible to the owner. Prefer opening the actual `petish.local` site in the Mac's browser for review.
- Be concise, explain concrete changes, and carry authorised development through to a reviewable result. Do not repeatedly ask permission for routine reversible local edits.
- This document is project context, not permission to purchase services, publish a live store, take real payments, contact suppliers/customers, or overwrite existing content.

## Suggested immediate milestone

Inspect and back up the existing Local site, establish WooCommerce and a suitable The7 shop base, then produce an editable Petish homepage and functional sample catalogue/cart matching the provided retail references. Open the local site for the owner to review, and report what works and what remains a planned feature.

</details>
