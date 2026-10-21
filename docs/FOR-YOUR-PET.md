# Homepage: For your pet

Implementation is in progress; this document is not a production release receipt.

The owner chose staff-curated matching against the existing Passport biography
and personality tags. No additional quirks or favourite-things fields are added.
The homepage introduces the section with “Products curated for your pet’s species,
personality, quirks and favourite things.”

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

In the WooCommerce product editor, open **Product data → For your pet**:

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
browser storage. Restoring a page from browser history refreshes the selection.

Verification covers matching, sorting, previous purchases and variants, separate
customer sessions, inaccessible purchase history, generic guest content, and
responsive layout. Hosted checks must also demonstrate that synthetic staging
fixtures are removed and protected store records and private media are preserved
before a production release can be recorded here.
