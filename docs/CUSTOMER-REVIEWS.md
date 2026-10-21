# Customer reviews: staging implementation

Status: enabled on private staging, 21 October 2026. Hosted acceptance passed
47 native checks, 43 browser checks and all 8 fixture-cleanup checks.
The live store has not been enabled for this feature.

[Open My reviews on private staging](https://staging.petish.biz/my-account/?my-reviews=1)
and sign in with a staging account. Product pages include the review section
below their description/details. Synthetic acceptance accounts, products,
reviews and media have been removed; the feature itself remains enabled.

Verified source: `cacc02b498b0d821d30bd66b792fa32e224c0d0e`.
[Hosted acceptance and committed staging release, run 39578596321-1](https://github.com/nkrake6/petish/blob/automation-status/customer-reviews-39578596321-1/status.json)
confirms the feature flag, exact installed source and unchanged protected
configuration. The temporary test guard was removed. Production was unchanged.

The agreed layout puts customer reviews below the product description/details,
with the star summary near the title linking to that section. My Account gains
**My reviews**, including published and pending counts, Helpful/Not helpful
reactions received, and progress towards the next status. All four counters are
visible on mobile. Existing approved product reviews remain visible separately.

## Current scope

- Signed-in customers submit an explicit public alias, title, written review and
  required 1–5 product-quality rating. Verified-purchase labels use WooCommerce
  purchase evidence. Customer identity is not copied from billing or Passport.
- One managed review per customer and parent product; colour/size variants share
  that limit. Five submissions per 24 hours is the initial abuse-control limit.
- Every new review starts pending. Staff inspect text and all media through
  native comment moderation. Approval publishes the review and its attachments
  together. Low ratings count equally towards status.
- The comment editor includes a private attachment preview. **Comments → Review
  reports** lists member reports for staff; resolving a report does not approve
  or remove the review automatically.
- Up to four photos of 5 MB each, one H.264 MP4 video of 20 seconds/20 MB, and an
  optional reviewer avatar. Photos are resized and re-encoded. Video originals
  retain embedded metadata; the form discloses this before publication consent.
- Signed-in customers can give one reversible Helpful/Not helpful reaction to
  another customer's published review. Self-reactions are rejected. Dashboard
  totals mean reactions received, and do not affect status or product stars.
- Recognition tiers are 50 Pet Scout (cyan), 100 Regular Reviewer (green),
  250 Community Champion (pink), and 500 Petish Legend (gold). The green change
  applies to the Regular Reviewer tier, not the general page palette.
- Customers can withdraw a review from public view. Withdrawn records retain
  the product uniqueness constraint. An approved-edit/revision interface is
  not included in this candidate. Store assistance is needed to correct an
  existing submission; native staff edits remain available.
- Discount and delivery benefits are inactive. No coupons, prices, payment
  methods, shipping rates, orders or supplier connections are changed.

## Storage, privacy and moderation

The module lives inside Petish Passport but uses native WooCommerce review
comments and its own comment/user metadata. It does not change the staff
species-suitability evidence or the personalised catalogue's approval rules.

Review files are stored in the separate subtree
`private/petish-passport/customer-reviews/<user ID>/`, outside the public webroot.
That subtree is included by the existing encrypted full backup and restore
process. Pet/profile/moment cleanup does not own these files. An authorized
serving endpoint checks the current review status each time; knowing a media URL
cannot reveal an unapproved attachment. Pending media is restricted to its owner
and authorized moderators. Public media responses are also marked no-store so
withdrawals take effect on the next request.

Failed-upload journals retain cleanup ownership across interrupted submissions.
Comment/account deletion and WordPress personal-data erasure remove review
media, comments and reaction records. Erasure failures must be reported as
retained work rather than silently claiming completion. Backup retention remains
separate from active-site erasure.

## Rollout boundary

The module defaults off. Application classes can load without enabling customer
routes; frontend features require `petish_customer_reviews_enabled = yes`.
The staging acceptance workflow has a fixed staging destination and no production
apply mode. It validates the existing private staging configuration, keeps a
code snapshot and prior feature flag, blocks outbound integration/email traffic
during its fixtures, and restores code/flag if acceptance fails. Only synthetic
fixture accounts/products/reviews are created, and they must be removed before a
successful result is published. Public result branches contain finite checks,
not credentials, customer data, private media or browser sessions.

The hosted browser proof passed real reaction clicks (add, remove and switch),
received-counter updates, invalid-nonce rejection and multipart photo/video/avatar
submission. Pending media returned 200 only to its owner and 404 to another
member and a guest, with no-store headers on authorized responses. Desktop and
mobile layouts, review filters and placement below product details passed.

The implementation and public design previews are separate. Production rollout
needs a reviewed release with fresh backup/recovery evidence, completed staging
acceptance, and finalized customer-facing review/benefit terms. The discount
programme's unresolved commercial rules are recorded in
[CUSTOMER-REVIEWS-PROPOSAL.md](CUSTOMER-REVIEWS-PROPOSAL.md).

## Local verification

The native proof passed all 47 checks and all 8 cleanup checks against a fresh
WordPress 7.1.3/WooCommerce 11.2.1 database
in disposable containers with external networking disabled. It exercises real
WordPress comments, moderation, reactions, private-media authorization and
personal-data cleanup using owned synthetic fixtures. It does not restore or
alter production customer data.

Separate real multipart upload checks passed for resized/re-encoded JPEG
(including EXIF orientation), PNG, WebP, avatars and MP4 validation. Native PHP
templates passed Chromium layout checks at 320, 390, 768 and 1440 pixels; these
local renders do not include The7 and do not replace hosted acceptance. The
package validator, secret scan, PHP/JS/Bash lint, workflow lint and ten finite
report-sanitizer tests also pass.

Fresh `WP_ADMIN=true` form-processing requests also passed invalid-nonce,
action-error and success-notice checks. Review handlers explicitly load
WooCommerce's notice helpers because normal storefront includes are absent in
`admin-post.php` requests. The hosted proof uses real browser form submissions
for reactions, invalid-nonce rejection and review/media submission.
