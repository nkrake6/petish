# Customer review design concepts

Three separate static mockups for design review, not a live review system:

1. **Product stories:** light, editorial product-review page.
2. **Community wall:** dark, media-led discovery feed.
3. **Reviewer studio:** personal contribution dashboard and review composer.

All names, reviews, star averages, counts, progress and transactions are fictional.
The shared JavaScript changes only the open preview: reactions, filters, tabs,
example star choices, attachment placeholders and moderation confirmation.
It does not upload files, submit reviews, call the store, persist account data,
activate rewards or play a real customer video. Video posters open an explanatory
preview dialog. Shipping thresholds and caps remain undecided.

The member tiers follow the [programme proposal](../CUSTOMER-REVIEWS-PROPOSAL.md):
50 Pet Scout (cyan), 100 Regular Reviewer (green, 5%), 250 Community Champion
(pink, 10%), 500 Petish Legend (gold, 15% and capped standard delivery).
The 100-review Regular Reviewer badge and profile ring use green in place of
lavender. The general lavender styling and all other tier colours are retained.

The pet photographs and approved logo reuse the existing concept assets
unchanged. They are licensed illustrative photographs, not private customer pet
photos or evidence for any review. See [photo credits](../passport-concepts/PHOTO-CREDITS.md).
The local DM Sans font and its licence are in `../cart-badge-preview/assets/`.

Recommended combination: Product stories on product pages and Reviewer studio
under My Account. Community wall is an alternative discovery experience.

The Reviewer studio shows published and pending review counts separately, plus
Helpful (likes received) and Not helpful (dislikes received) totals. All four
are visible on mobile. The fictional example has 97 published reviews, 3
pending, 248 Helpful and 12 Not helpful reactions. Votes do not affect status;
the milestone progress bar remains based on qualifying approved reviews.

Local Chromium checks covered 320, 390, 768 and 1440px widths without horizontal
overflow, all image loads, preview voting/filtering/sorting, dialogs, form
confirmation, sample attachments and the gallery. All requests stayed on the
local static preview server. Desktop and mobile JPGs are provided for each
concept, plus a comparison image. The public availability workflow separately
checks that published preview files match the committed source.
