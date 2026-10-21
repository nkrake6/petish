# Petish customer reviews and member rewards — proposal

Status: design discussion, 21 October 2026. No review features, rewards,
shipping benefits or payment settings have been installed or enabled by this
proposal. Names, colours and defaults below are recommendations unless marked
as confirmed.

[Compare three interactive layout mockups](https://nkrake6.github.io/petish/review-concepts/):
Product stories, Community wall and Reviewer studio. These use fictional review
content and preview-only interactions; no programme features are enabled.

## Confirmed owner choices

- Any signed-in customer may submit reviews and earn status. Use a separate
  verified-purchase label when Petish order evidence supports it.
- Product quality uses a required 1–5 star rating; customers may attach photos
  and video. Reviews can be moderated.
- Status has a coloured profile-photo ring and a title, starting at 50 reviews
  and reaching 500 reviews.
- Benefits are ongoing: 5% at 100 qualifying reviews, 10% at 250, and 15% plus
  a standard-delivery benefit at 500.
- The delivery benefit has a minimum spend and a cost cap. Amounts and the
  treatment of charges above the cap are not decided.
- Other customers can react positively or negatively to a review.

## Suggested customer experience

1. Sign in and choose a public reviewer alias and optional avatar. Do not expose
   billing names, email addresses, orders or private Passport media. A pet photo
   or public pet-profile link is an explicit choice, not an automatic reuse.
2. Submit a quality rating, useful first-hand feedback, and optional media.
   Suggested initial limits: four photos at 5 MB each, and one video at
   20 seconds / 20 MB. Allow text-only reviews to earn the same credit.
3. Show the customer a private pending-review screen. Staff check authenticity,
   relevance, privacy and published content rules; a low rating is not a reason
   to reject a review. Keep rejection reasons and allow corrections.
4. Publish accepted text and approved media. Show rating, date, reviewer alias,
   status, verified-purchase label where applicable, and disclosure that the
   contribution participates in the rewards programme.
5. Award qualifying credit once, update the member's progress bar, and unlock
   the highest applicable benefit. Edits and repeated approval events must not
   award credit again. Votes and star values do not affect credit.

Substantive edits and replacement media return to moderation as a pending
revision. Keep the last approved version public until the revision is accepted,
unless the author withdraws it or a moderator removes it under the published
rules. Never let an approved review become a route for unmoderated uploads.

Public ratings should average approved ratings without giving higher-status
members extra weight. Offer newest, rating, verified purchase and media filters.
Keep each review tied to the product/variant actually discussed; record the
variant even if the counting rule groups variants under their parent product.

## Suggested milestone presentation

| Qualifying reviews | Suggested title | Profile ring | Ongoing benefit |
| --- | --- | --- | --- |
| 50 | Pet Scout | Cyan | Status recognition |
| 100 | Regular Reviewer | Green | 5% product discount |
| 250 | Community Champion | Pink | 10% product discount |
| 500 | Petish Legend | Gold | 15% product discount and capped standard delivery |

Below 50, show progress and acknowledgement of the first contribution without
moving the owner's requested first status threshold. Titles describe
participation, not veterinary expertise or a guarantee that a review is true.
Always display the title/count alongside colour for accessibility.

## Commercial and counting rules to settle

- **Counting:** the owner is choosing between one qualifying review per parent
  product and additional reviews following verified repeat purchases. The
  former is simpler and resists duplicate farming. The latter needs a meaningful
  update requirement and cooldown. Re-imported supplier listings must not
  create easy duplicate credit for the same underlying product.
- **Achievability:** the handover records a historical catalogue of 104 products;
  current live inventory has not been counted for this proposal. One credit per
  product requires at least 500 distinct products to reach 500. At one qualifying
  contribution a week, 100/250/500 take roughly 2/5/10 years.
- **Discounts:** recommend only the highest tier, not 5% + 10% + 15%. Bind the
  entitlement to the signed-in account and apply it automatically using native
  WooCommerce discount/tax calculations. A code, if displayed, must not grant
  benefits to other accounts. Sale-item treatment and other coupon stacking
  need explicit terms before rollout; do not silently narrow the owner's
  requested all-purchases benefit.
- **Margin:** use actual product, supplier freight, payment, tax, return,
  moderation, media processing and support costs. On a tax-exclusive A$100 sale
  with A$25 gross product margin, a 15% discount leaves A$10 before those other
  costs. The delivery cap alone cannot guarantee that an order remains profitable.
- **Delivery:** spend minimum, cap, calculation currency, tax treatment and
  above-cap behaviour remain open. Recommend measuring qualifying merchandise
  spend after discounts, with the tax basis stated in the terms, and applying
  one cap to the entire order across supplier parcels. Do not grant a fresh cap
  per parcel. Premium delivery, duties and taxes are outside a standard-delivery
  reward unless explicitly included.
- **Coverage:** rewards must never enable an unsupported destination, invent a
  shipping rate or display delivery as available when a supplier quote is absent.
  If customers pay an excess, label the benefit as delivery credit up to the cap;
  say free delivery only when the customer's remaining standard-delivery charge
  is zero.
  Keep this member benefit distinct from the existing supplier-free-delivery
  badge and homepage collection; membership does not establish supplier-free
  evidence for a product.
- **Refunds:** genuine reviews of received defective/returned products can remain
  valid. Do not automatically remove criticism or credit because of a refund.
  Fraud or duplicate removals reverse invalid credit. Define voluntary deletion,
  product retirement and account closure rules before launch. Honour already
  accepted orders; never retrospectively charge a discount back after a status
  change. Legitimate product retirement should not erase earned recognition.
- **Duration:** the owner selected ongoing rewards, not a one-use coupon. Avoid
  describing them as an irrevocable lifetime promise without an agreed programme
  change policy.

## Reactions, moderation and abuse

Recommend the labels **Helpful / Not helpful** rather than agreement with a
customer's personal experience. Preserve positive and negative reactions as
requested: one reversible choice per signed-in customer per review, no self-vote.
Keep a separate report button. Reaction totals never change stars, status or
discount eligibility, and downvotes alone never hide a review.

Open submission plus financial rewards creates an incentive for copied reviews,
recycled media, duplicate accounts and reaction groups. Require verified email,
server-side uniqueness, reasonable submission/upload limits, suspicious-pattern
review and a moderation audit trail. These measures reduce abuse; they do not
prove that an unverified reviewer has used the product. Moderation should apply
the same published standards to positive and negative experiences.

Reward one-star and five-star contributions equally. Disclose that reviews can
earn member benefits, and retain that disclosure independently of the customer's
current badge. Final incentives/moderation terms should be checked against the
consumer-review rules applicable to the countries Petish serves before launch.
No current regulatory text was retrieved or legally validated for this proposal.

## Media and privacy

Pending, rejected or removed content must not be accessible by guessing an
attachment URL. Scan/validate uploads, reject active content, re-encode photos,
and handle video conversion/metadata removal before promising broad phone-format
support. Obtain permission to display the media; advertising or social-media
reuse should require a separate choice. Support reporting and removal of
accidentally shared personal information.

Video storage and delivery need a budget: 500 videos at the proposed 20 MB ceiling
are about 10 GB before thumbnails, derivatives and backups. Use playback controls,
lazy loading and no autoplay. Consider dedicated media storage/processing once
volume justifies it; no paid service is selected or authorised by this proposal.

## Fit with the existing Petish application

- Use native WooCommerce review comments and rating metadata, extending them with
  review media, reactions, moderation revision history and an auditable member
  reward ledger. Protect against concurrent/double awards and validate eligibility
  on the server at checkout. Keep order records of the benefit actually applied.
- Existing `Petish_Product_Review` is staff species-suitability curation. Customer
  ratings must not change those approvals or the staff-curated For your pet rules.
- Passport supplies useful photo/video validation and private-serving patterns,
  but its attachments and deletion journals belong to pets/moments. Review media
  needs its own ownership/lifecycle, permission checks and backup coverage.
  Current Passport videos can retain device/location metadata; that behaviour
  must not be carried over as a promise of sanitised public review videos.
- Fulfilment validates destinations, product variants, quantities and rate
  evidence, including cached rates. Integrate delivery credits into that validated
  calculation and cache identity; a generic filter that sets shipping to zero
  would conflict with those protections.
- The existing cost sheet is a manual, nonpersistent worksheet. The available
  stored rate is the customer delivery charge, not a verified supplier freight
  cost. A first version can cap the charge Petish covers; a true supplier-cost
  ceiling needs additional cost data.

## Suggested rollout

Agree the open rules and review the customer-facing design first. Build and test
reviews, private moderation/media, reactions and progress on isolated staging.
Verify the reward ledger and checkout discounts, including retries, duplicate
approvals, returns, mixed carts and every supported payment flow. Validate the
delivery benefit against real supplier test costs before publishing its final
terms or enabling it. No real orders, supplier requests or customer messages
are part of this design discussion.
