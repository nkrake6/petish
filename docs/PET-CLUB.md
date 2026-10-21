# Petish Passport: Pet Club

**Passport 0.3.2 is live**, released on 21 October 2026. The owner selected the
Pet Club design for the existing Passport and My Pets section. The upgrade
retains existing pet records, private media, active-pet selection and reviewed
product collections.

Sign in at [My Pets](https://petish.biz/my-account/my-pets/) to use Passport.
[Pet Club](https://petish.biz/?petish_club=1) lists only profiles their owners
explicitly publish. Existing private pets were not made public by this release.

## Owner experience

My Pets uses the approved photo-led Pet Club layout: pet switches, a profile
hero, biography and personality tags, Moments/About/Journal sections, and a
community sidebar. Existing pets need no re-entry. Empty sections invite the
owner to add their own content; no sample pets or fabricated hearts are used.

Each pet can have an optional species-specific breed or variety, including a
custom Other choice, a biography of up to 400 characters, up to three
personality tags, and up to twelve combined photo moments, video moments and
journal entries. Photos use validated, re-encoded private storage. Creating or
editing a pet does not publish it.

The selected **Colour track** account menu includes endpoint-specific icons,
grouped colours and an active circular marker. Labels, link order, destinations
and the mobile dropdown script are unchanged. The narrow desktop layout gives
labels enough room while preserving row heights. See
[Colour track implementation notes](ACCOUNT-MENU-COLOUR.md).

## Sharing, hearts and discovery

The owner previews the shared fields, chooses whether to include the broad age
range and particular moments, and explicitly confirms publication. Publishing
creates a selected copy with its own media files. Account identity, addresses,
orders, shopping preferences and unselected moments are excluded.

Private edits do not silently update that shared copy. The owner can update the
shared profile explicitly or turn sharing off. Changing or removing a profile
photo, or deleting or replacing a shared moment, withdraws sharing before
removing associated files. Public requests check current visibility and use
no-store responses. Existing private media URLs remain owner-only.

Only shared pets appear in the community directory. Species, breed/variety and
pet-name filters match the explicitly published snapshot, with paginated
results. Private profile edits do not change search results until the owner
shares them again.

Signed-in members can add one heart to another member's pet and remove it.
Repeating the same operation does not increase the count again. The public view
shows aggregate counts without member identifiers. **My favourites** shows only
the signed-in member's hearted pets. Guests can view shared profiles and are
asked to sign in to use favourites, react or report.

## Video moments

Video moments accept progressive MP4 files with H.264 video and optional AAC
audio, up to 20 seconds and 52,428,800 bytes (shown as 50 MB). Passport 0.4.1
raised the shared video limit alongside the
[customer review release](CUSTOMER-REVIEWS.md). Clips use private
storage outside the webroot, authenticated playback and bounded byte-range
responses. This storage is inside the existing encrypted Passport backup root.

Sharing a clip requires explicit selection and creates a separate public
snapshot; withdrawal revokes access before cleanup. Original video bytes are
retained, so the publication screen explains that embedded device or location
metadata may remain. No host transcoder is needed.

The hosted browser proof covers a normal valid upload and playback, private
access denial, public snapshot playback and revocation. Server-limit checks use
the browser's native `requestSubmit` algorithm with site scripts disabled,
preserving multipart uploads and native form validation. This verifies server
limits; it does not establish full upload usability without JavaScript.

## Moderation and data lifecycle

Members can report a shared profile using a defined reason. Reports are private
and deduplicated per member and pet. **WooCommerce → Pet Club moderation** lets
store managers review reports and hide a pet. A moderated pet cannot be shared
again until a manager clears that restriction.

Deleting a pet removes its moments, public copies and community records as well
as its private profile and media. Account deletion and WordPress privacy export
and erasure include the added data. Failed file cleanup retains private
references for retry; withdrawing publication takes effect before cleanup.
Encrypted backup retention remains separate from deletion on the active site.

If account deletion stops because private files could not be removed, the
private `_petish_club_deleting` marker keeps new sharing and interactions
disabled. Resolve the cleanup failure and retry deletion. An administrator may
clear the marker only after confirming that deletion was cancelled and reviewing
the retained cleanup references; do not clear it automatically on failure.

## Release scope and directory migration

The production release covered eighteen exact application files; fourteen
required changes. Independent media copies, owner checks, per-pet locks,
authenticated nonces and unique heart keys protect the interactions.

A separate, explicit migration added only missing directory search metadata
for already-published snapshots: `_petish_club_find_name`,
`_petish_club_find_species` and `_petish_club_find_variety`. Values were derived
from the frozen public copy, not current private profile fields. The migration
did not publish pets, change consent or rewrite the source snapshots.

An owned WordPress maintenance guard covered the dependent file swaps and
migration. A private journal recorded the exact inserted rows, and verification
proved that only those rows changed. Conditional rollback removes only those unchanged inserted rows
before restoring application files; concurrent changes are not overwritten.
The committed release retains the exact private migration journal alongside
its code backups. The maintenance guard was removed after verification.

The release required a fresh encrypted full-site backup with successful
application recovery, native staging and browser checks, complete fixture
cleanup and exact application-file hashes. Production verification was
read-only and checked existing pet records/media, users/authentication, orders,
product stock/prices and settings. No production test accounts, purchases,
supplier orders or email deliveries were created.

If an interrupted release cannot restore a complete application, its verified
maintenance guard, exact deployment helper, payload and private journals remain
available for recovery. A retry for the same run verifies the persisted guard
receipt before adopting it. Unrelated guards and customer media are preserved.

## Staging isolation

The rehearsal pauses Jetpack Sync only for its isolated test requests, without
changing stored Jetpack settings or clearing existing queues. A temporary owned
MU guard covers browser requests. Hostinger disables PHP's `link()` function,
so pinned SSH publishes the guard; PHP verifies its receipt, digest and inode
before loading WordPress. Cleanup removes the guard and owned fixtures and
requires the original protected-state hashes to match.

Browser rehearsal also pauses Action Scheduler's asynchronous dispatcher and
refreshes of two WooPayments runtime caches and The7's plugin-check timestamp.
These are temporary request filters. They do not rewrite stored values or
exclude changes from the final settings comparison. Failed or retired runs do
not count as passing release evidence.

## Hosted release evidence

The complete 0.3.2 staging rehearsal passed on source
`3012a277a7fe6537f4a3a643937cdbe03df8d77c`: **158 checks** comprising 16 environment,
66 native, 13 fixture, 46 browser and 17 cleanup checks. The proof exercised the
same index migration and conditional rollback on an owned synthetic legacy
profile. It also verified video access/revocation, member favourites isolation,
directory filters and Colour track icons and heights. All owned test users,
media and the temporary browser guard were removed.

| Evidence | Result |
| --- | --- |
| [Encrypted full backup 39511176722-1](https://github.com/nkrake6/petish/tree/automation-status/backup-39511176722-1) | Successful archive and restore check, including private Passport storage; fresh at deployment |
| [Application recovery 39511541357-1](https://github.com/nkrake6/petish/tree/automation-status/recovery-39511541357-1) | Restored WordPress and private-photo protection verified in an isolated sandbox |
| [Complete staging rehearsal 39537031480-1](https://github.com/nkrake6/petish/tree/automation-status/pet-club-39537031480-1) | All 158 checks passed; exact eighteen application files verified |
| [Production release 39537501595-1](https://github.com/nkrake6/petish/tree/automation-status/pet-club-39537501595-1) | Fourteen files changed within the eighteen-file scope; migration plan/apply/verify, 23 read-only native checks, public health and four exact asset checks passed; release committed |

Production source is `c136035fe5d76538fbc0199a3f3a87ec8e5f5995`; its eighteen
application files match the passing staging source. The migration phases passed
10 plan, 13 apply and 13 verification checks. The committed receipt confirms
protected state preservation, removal of the maintenance guard and archival of
the private migration journal. No rollback was needed.

The preceding [0.3.1 release 39526422172-1](https://github.com/nkrake6/petish/tree/automation-status/pet-club-39526422172-1)
introduced optional breeds/varieties and the account-page spacing fixes. Those
features remain in 0.3.2.

## Separate homepage release

The personalised homepage feature matches staff-curated products against the
active pet's existing biography and personality tags, shows newest matches
first and excludes prior purchases, including variants. It adds no Passport
fields. It is live under the separate
[homepage production release 39537785516-1](https://github.com/nkrake6/petish/tree/automation-status/homepage-39537785516-1).
See [For your pet](FOR-YOUR-PET.md) for its verification and release scope.
