# Homepage: For your pet

**Live on petish.biz:** production
[run 39537785516-1](https://github.com/nkrake6/petish/actions/runs/39537785516)
committed source `c136035fe5d76538fbc0199a3f3a87ec8e5f5995` on 21 October 2026.
Five files changed within the seven-file homepage release scope. Staff still
need to configure eligible product matching rules; deployment does not approve
products or invent recommendations.

The owner chose staff-curated matching against the existing Passport biography
and personality tags. No additional quirks or favourite-things fields are added.
The homepage introduces the section with “Products curated for your pet’s species,
personality, quirks and favourite things.”
It appears after the category section and before Hot items. The section title
stays visible while the customer's picks load privately; guests see sign-in and
Passport guidance. Without JavaScript, the generic links still open My Pets and
the existing broader pet collection.

## Matching and purchase history

For a signed-in customer, the section uses their saved active pet. Products must
have an enabled staff matching rule, match the pet’s species and at least one
positive keyword or phrase, and have a current eligible species review. Matching
rules do not approve a product or replace the existing review process.

Personality tags match exactly, ignoring case. Biography phrases match whole
words; a negative sentence containing that phrase vetoes it. Staff can list
aliases separately. This is conservative phrase matching, not an interpretation
of every possible meaning in a biography.

Up to four published, visible, purchasable products in stock are shown, ordered
by date added, newest first, with product ID resolving equal dates. Purchasing
any variation excludes its parent and the other variations. Paid and refunded
orders are included; pending, failed and cancelled orders are not purchases.
The history includes the current account’s orders and guest orders using that
account’s email, excluding orders belonging to another registered account.

Incomplete or unavailable purchase history produces an unavailable state, rather
than recommendations that might include previous purchases. Customers with no
active pet are invited to choose one. No matches produces an empty state; the
system does not invent products or automatically approve the catalogue.

## Staff setup

To curate matching products, in the WooCommerce product editor, open
**Product data → For your pet**:

1. Confirm the product has a current eligible Petish review for the intended
   species, including the existing source, dimensions and limitations checks.
2. Enable the product’s **For your pet picks** rule.
3. Choose the matching species.
4. Add up to 20 comma-separated words or phrases, each up to 60 characters, such
   as `curious, inquisitive, digging`.
5. Save the product. Only customers whose active pet matches will see it here.

These settings require store-management and product-edit capabilities. Product
duplication does not copy the matching approval. Product REST metadata does not
expose or accept these rules. No production products are curated automatically
as part of deploying this feature.

## Privacy and verification

The initial homepage contains a generic section. The customer’s selection loads
from a same-origin, read-only endpoint using the current session, with private
no-store headers. The endpoint accepts no customer or pet identity from the
browser. Pet biographies and order history are not sent to external services or
browser storage. Leaving the page clears the personalised fragment; restoring a
page from browser history refreshes the selection. A failed request keeps the
generic links available and offers a retry.

Verification covers matching, sorting, previous purchases and variants, separate
customer sessions, inaccessible purchase history, generic guest content, and
responsive layout. Hosted staging
[run 39534481202-1](https://github.com/nkrake6/petish/actions/runs/39534481202)
at source `44ef53e` passed all 126 required checks:

- 16 preflight checks.
- 33 native checks, including actual WooCommerce purchase-history fixtures.
- 22 cleanup checks, confirming fixture removal and preservation of protected
  store records and private media.
- 22 browser checks.
- 33 final verification checks.

The production candidate matched those seven verified homepage application files;
the separate account-menu CSS adjustment did not change this release scope.
Production run `39537785516-1` passed all 33 required native checks, all five
public asset checks, and site health checks. Protected store state and the prior
account and email assets were preserved. The release was committed, maintenance
mode is off, and no rollback was needed.

The informational `free_delivery_products_available` observation was false:
there were no qualifying products for the separate supplier free-delivery
section. Its visibility matched that evidence; this was not a failed release
check or an automatic approval of any product.

This child-theme feature has a separate release scope from Passport 0.3.2
favourites, directory filters, video moments and Colour track, which are also
live following [production run 39537501595-1](https://github.com/nkrake6/petish/actions/runs/39537501595).
