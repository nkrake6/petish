# Delivery & shipping page copy

Published to [Delivery & shipping](https://petish.biz/delivery-shipping/) on
21 October 2026 at 04:50 UTC. The owner chose supplier-specific estimates and
no fixed delivery-time range. The content remains editable on the existing
WordPress page, ID `1002`. The reviewed HTML is
[`operations/delivery-page-content.html`](../operations/delivery-page-content.html).

The release changed only the page content and its modified timestamps. The
complete original row was saved privately on the host before committing. The
updater checks the previous content hash and preserves every other page field.
Mail, outbound HTTP, Jetpack sync and cron were blocked in the update process.

Release evidence:

- Source: `511e3ea229018cca6c5a1772feb7568b88166d2e`.
- [Rehearsal `39546008385-1`](https://github.com/nkrake6/petish/blob/automation-status/delivery-page-39546008385-1/status.json): the actual update succeeded inside a transaction, the original full row was restored, and the original canonical public copy was verified twice.
- [Production `39546227600-1`](https://github.com/nkrake6/petish/blob/automation-status/delivery-page-39546227600-1/status.json): private before-image and committed row verified; only this URL's LiteSpeed cache was purged, consumed and checked; the new canonical public copy was verified twice. Temporary helper cleanup succeeded. No rollback or manual recovery was needed.
- Published content SHA256: `e5c6e2575f72e4bd5c1c9e45fb05dd33f27f816c7db0ae3b63b2805e41b9b065`.

The private page before-image is retained at
`~/.petish-delivery-page-backups/39546227600-1.json` on the host. A rollback must
match the exact owned after-row before restoring that snapshot, so it cannot
overwrite a later WordPress edit.

---

## Delivery for small-pet adventures

Little essentials, delivered to your door. We work with a range of suppliers to
bring you products for your small pets. Items may be sent directly from our
supplier partners, including suppliers overseas.

### How long will my order take?

Delivery times vary by product, supplier, destination and shipping service.
Check the estimate for your chosen item when one is available. Check whether it
includes preparation time or starts after dispatch. If you need an estimate
before ordering, contact us with the product and your delivery postcode.

Orders need time to be prepared before they are handed to a carrier. Estimated
arrival dates are a guide, not a guaranteed delivery date. Busy periods, public
holidays, customs checks and local carrier delays can affect timing.

### Delivery options and costs

Enter your delivery address at checkout to see the available delivery options
and charges for your order. Availability varies by item and destination. If no
delivery option is shown, we do not currently have a delivery rate for every
item in your cart at that address. Contact us before ordering if you need help.

Review the delivery charge, applicable taxes and full order total before paying.
Free delivery applies only where it is offered for the item and destination.

### Separate parcels

Items from different suppliers may be sent in separate parcels and arrive on
different days. If one item arrives before another, the rest of your order may
still be on its way.

### Tracking your order

Tracking depends on the supplier and delivery service. When a tracking number
is available, the carrier's updates can help you follow your parcel. A new
tracking number may take a little time to show its first scan. Contact us if you
need help finding tracking information or checking an order's progress.

### International deliveries

Some products travel internationally before reaching you. Customs processing
and local delivery arrangements can add time. Delivery is available only where
the product and shipping service support your destination.

### Need a hand?

Check your delivery address carefully before placing an order. If you notice a
mistake, your parcel is delayed, or you need delivery information, email
[orders@petish.biz](mailto:orders@petish.biz) with your order number, if you have
one. We'll help you check the next steps. Address changes may not be possible
once an order has been dispatched.

[View cart](https://petish.biz/cart/) · [Browse products](https://petish.biz/shop/)
