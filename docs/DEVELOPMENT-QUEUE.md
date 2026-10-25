# Petish development queue

Updated 25 October 2026 (Australia/Melbourne).

## In progress: Pet Chat channel upgrade on private staging

Build a publicly readable community with flat channel conversations. Posting
uses the existing signed-in customer account and chosen public names. The owner
replaced the original threaded Q&A direction with channels, reactions, quoted
replies and uploaded media. No direct messages or live broadcasting are required.

Keep private Passport records, shopping information and customer contact details
out of public discussion. Live thread updates must preserve a member's draft.
Start with the existing WordPress hosting and accounts; no new paid service has
been requested.

The historical discussion pilot is implemented and verified on
[private staging](https://staging.petish.biz/?petish_chat=1), source `14386ae`.
See [Pet Chat](PET-CHAT.md) for the channel upgrade, native checks and historical
staging receipts. The live Chat feature remains off during private verification.

## Queued next: desktop product layout

The owner requested this be queued after Pet Chat:

1. Left third: product photographs/gallery.
2. Middle third: price, description, product choices/sizes, tags and categories.
3. Right third: payment options.

Preserve native payment-provider elements and their behavior. Define responsive
stacking for smaller screens when implementing the desktop layout. This queue
entry does not mean the layout is already implemented or deployed.
