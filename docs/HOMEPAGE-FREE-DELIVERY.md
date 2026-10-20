# Homepage free delivery

The homepage keeps Hot items and Free delivery favourites separate. The Hot
subtitle “Selected by supplier-reported orders.” is removed.

Free delivery favourites appears beneath Hot items when there are eligible
published products. It shows up to four products, sorted by supplier order count
descending, then current displayed price descending, with product ID as the
final tie-breaker. An unknown order count follows a genuine reported zero.
Variable products use their native displayed starting price.

The navy pill with the lavender delivery van appears at the bottom-right of
eligible homepage product images. The existing Hot rule remains independent:
at least 500 supplier-reported orders observed within the last 30 days.
Being in the free-delivery section never grants a Hot badge.

Eligibility requires both current, explicitly reviewed supplier-free-freight
evidence and an enabled checkout shipping method that returns zero for the same
product, option, quantity and destination. Missing or expired evidence, an
unavailable option, an unknown required postcode, or a paid checkout rate
removes eligibility. Variable product cards require every available purchasable
option to qualify. The section identifies the delivery country and quantity.

This feature reads evidence; it does not change shipping charges, contact
suppliers, place orders or mark products as confirmed.

## Current inventory

The read-only [supplier delivery audit](https://github.com/nkrake6/petish/blob/supplier-delivery-audit/39416351102-1/status.json)
found 104 published products and no supplier-free-delivery evidence in their
metadata. Supplier order counts and fresh observation dates were available for
103 products. One merchant-set free-shipping rule exists, but it is not evidence
of supplier-free freight.

The new section therefore stays hidden until supplier offers are confirmed and
recorded. Do not copy a merchant zero-price policy into supplier evidence.
