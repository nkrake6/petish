# Pet Passport design review

Prepared 21 October 2026 (Australia/Sydney).

[Open all three interactive concepts](https://nkrake6.github.io/petish/passport-concepts/).

| Direction | Layout | Best suited to |
|---|---|---|
| [Pet Club](https://nkrake6.github.io/petish/passport-concepts/01-pet-club.html) | Bright social profile, pet switcher, photo moments and community column | Building engagement and discovering other pets |
| [Passport Studio](https://nkrake6.github.io/petish/passport-concepts/02-passport-studio.html) | Ink-blue frame, cream identity card and keepsake milestones | A distinctive passport identity and owner utility |
| [Little World](https://nkrake6.github.io/petish/passport-concepts/03-little-world.html) | Immersive photo hero and editorial journal | Photos, memories and a strong individual pet personality |

Pet Club is the recommended starting point for the requested community and hearts. Passport Studio's milestones could be incorporated after choosing the main direction.

## Current visibility

Existing passports and photos are private to their owner. The pet post type is not public or exposed in REST; every profile and photo read checks ownership. Photo originals are stored outside the public web root. There is currently no public-profile setting, heart storage, biography, gallery or milestone timeline.

The concepts are self-contained static demonstrations using fictional pets and existing licensed theme photographs. They contain no customer records, account requests, backend connection or persistent reactions. Showing a shared profile in the demo does not publish an existing passport. The production WordPress implementation is unchanged.

## Proposed first community release

- Keep all existing pets private. Add an explicit, per-pet sharing choice and a public-view preview.
- Share only the chosen pet name, species, optional age range, selected photo, short biography and personality tags. Keep owner account details, addresses, orders, shopping preferences and private notes out of the shared view.
- Preserve current private records and photo routes. A separately approved public photo derivative must check visibility and become unavailable when sharing is withdrawn; public discovery must also remove the pet.
- Allow one heart per signed-in member per shared pet, with undo. Enforce this with a unique pet/member pair and authenticated, nonce-protected writes; reject self-hearts. Display aggregate counts, not private member identifiers.
- Provide a simple report/hide moderation route before enabling public submissions. Comments, direct messages and a full social feed are later features.

The owner has requested design options, not publication of customer passports. Choose a direction before implementing the new public data model or changing live visibility.
