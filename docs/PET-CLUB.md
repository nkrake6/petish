# Petish Passport: Pet Club

The owner selected the Pet Club design on 21 October 2026 (Australia/Sydney).
This upgrades the existing Passport plugin and its My Pets endpoint, retaining
the existing pet records, private photos, active-pet selection, account menu,
and reviewed product collections.

**Passport 0.3.1 is live**, released on 21 October 2026.
Sign in at [My Pets](https://petish.biz/my-account/my-pets/) to use the upgraded
Passport. [Pet Club](https://petish.biz/?petish_club=1) lists only profiles their
owners explicitly publish; existing pets were not made public by this release.

## Owner experience

My Pets uses the approved photo-led Pet Club layout: pet switches, a profile
hero, biography and personality tags, Moments/About/Journal sections, and a
community sidebar. Existing pets need no re-entry. Empty sections invite the
owner to add their own content; no sample pets or fabricated hearts are used.

Each pet can have an optional species-specific breed or variety (including a custom Other choice), an optional biography of up to 400 characters, up to three
personality tags, and up to twelve photo moments or journal entries. Photos use
the existing validated, re-encoded private storage. Creating or editing a pet
does not publish it.

## Sharing and hearts

The owner previews the shared fields, chooses whether to include the broad age
range and particular moments, and explicitly confirms publication. Publishing
creates a selected copy with its own photo files. Account identity, addresses,
orders, shopping preferences and unselected moments are excluded.

Private edits do not silently update that shared copy. The owner can update the
shared profile explicitly or turn sharing off. Changing/removing a profile photo
or deleting a shared moment withdraws sharing before removing the associated
files. Public requests check current visibility and use no-store responses.
Existing private photo URLs remain owner-only.

Only shared pets appear in the community directory. Signed-in members can add
one heart to another member's pet and remove it. Repeating the same operation
does not increase the count again. The public view shows aggregate counts,
without member identifiers. Anonymous visitors can view shared pets but must
sign in to react or report.

## Moderation and data lifecycle

Members can report a shared profile using a defined reason. Reports are private
and deduplicated per member and pet. **WooCommerce → Pet Club moderation** lets
store managers review reports and hide a pet. A moderated pet cannot be shared
again until a manager clears that restriction.

Deleting a pet removes its moments, public copies and community records as well
as its private profile/photo. Account deletion and WordPress privacy export and
erasure include the added data. Failed file cleanup retains private references
for retry; withdrawing publication takes effect before cleanup. Encrypted
backup retention remains separate from deletion on the active site.

If account deletion stops because private files could not be removed, the
private `_petish_club_deleting` marker keeps new sharing and interactions
disabled. Resolve the cleanup failure and retry deletion. An administrator may
clear the marker only after confirming that deletion was cancelled and reviewing
the retained cleanup references; do not clear it automatically on failure.

## Release scope and verification

Pet Club uses private post types and private metadata/options;
there is no automatic database migration or publication of existing pets.
Independent file copies, owner checks, per-pet locks, authenticated nonces and
unique heart keys protect the new interactions.

The release requires a fresh encrypted full-site backup with successful
application recovery, native staging checks, browser checks, fixture cleanup
and an exact application-file manifest. The production verification is
read-only and checks that existing pet records/photos, users, orders, product
stock/prices and settings are preserved. A short, owned WordPress maintenance
guard covers the dependent file swaps, followed by public page and asset checks.

If an interrupted release cannot restore a complete application, its verified
maintenance guard, exact deployment helper, payload and private journal remain
available for recovery. A retry for the same run checks the persisted guard
receipt before adopting it. Unrelated guards and concurrent customer photo
changes are preserved. Normal apply and rollback remove the owned guard.

The staging rehearsal pauses Jetpack Sync only for its isolated test requests,
without changing stored Jetpack settings or clearing existing queues. A temporary
owned MU guard covers browser requests. Hostinger disables PHP's `link()` function,
so the pinned SSH connection publishes that guard; PHP verifies its receipt,
digest and inode before loading WordPress. Cleanup removes the guard and owned
fixtures and requires the original protected-state hashes to match. Recovery of
older test queues accepts only events tied to the exact fixture identities and
only when their removal restores the original settings snapshot.

Browser rehearsal also pauses Action Scheduler's asynchronous dispatcher and
refreshes of the two WooPayments runtime caches and The7's plugin-check
timestamp. These controls are temporary request filters; they do not rewrite
stored values or exclude anything from the final settings comparison. The first
successful browser exercise, run `39519271900-1`, remains recorded as a failed
cleanup because those four runtime entries changed. Retirement
`39520554535-1` removed its owned browser guard/input without database changes
and retained its original private journal and diagnostic evidence. Retirement
does not count as a passing staging release.

## Hosted release evidence

The complete staging rehearsal passed on application source
`c67bbd655e1643b4caf61767947880d7b8f071f2`: 16 environment checks, 46 native
checks, 13 fixture checks, 31 browser checks and 17 cleanup checks. The final
comparison included the full protected settings snapshot, existing pets and
private photos, users/authentication, orders, product prices and stock. The
owned test users and temporary browser guard were removed.

| Evidence | Result |
| --- | --- |
| [Encrypted full backup 39511176722-1](https://github.com/nkrake6/petish/tree/automation-status/backup-39511176722-1) | Successful archive and restore check, including private Passport storage |
| [Application recovery 39511541357-1](https://github.com/nkrake6/petish/tree/automation-status/recovery-39511541357-1) | Restored WordPress and private-photo protection verified in an isolated sandbox |
| [Complete staging rehearsal 39521211067-1](https://github.com/nkrake6/petish/tree/automation-status/pet-club-39521211067-1) | All 123 checks passed; exact fourteen application files verified |
| [Production release 39521901384-1](https://github.com/nkrake6/petish/tree/automation-status/pet-club-39521901384-1) | Fourteen files deployed; 22 native checks, public site/logo checks and both Passport assets passed; release committed |

Production source was `076f9425fc1af7131ed8a22e89f9436dc5fad6d9`; its fourteen
application files exactly match the passing staging source. The production
comparison preserved existing pets/photos, users/authentication, orders,
product prices/stock and settings. The maintenance guard was removed and the
private pre-release code snapshot retained. No rollback was needed.

The production check made no test accounts, purchases, supplier orders or email
deliveries. Upload, publication, hearts and unpublication were exercised through
real browser requests on isolated staging; production verification was read-only.

## Follow-up release 0.3.1

Release `a58472a724b3f26b2eb53ef65cbd0092f5659d02` adds optional breeds and
varieties, corrects the photo-removal checkbox spacing, hides the tab strip’s
scrollbar while retaining scrolling, and restores the 28px gap above the For My
Pet collection button. Existing breed values remain blank until the owner sets
one; publication includes only the explicitly saved snapshot value.

[Staging 39526008899-1](https://github.com/nkrake6/petish/tree/automation-status/pet-club-39526008899-1)
passed all 123 checks on source `4c18a28498273fcdeedd653dc37e6d1b369fb5ac`.
[Production 39526422172-1](https://github.com/nkrake6/petish/tree/automation-status/pet-club-39526422172-1)
passed the 15-file transaction, 22 read-only native checks, public health and
three exact asset checks, then committed and removed its maintenance guard.
The existing encrypted backup/recovery evidence above was still fresh.

Favourites, directory filters, short videos and a personalised homepage section
are subsequent work in progress; this release does not enable them.

## Candidate 0.3.2: favourites, filters and video moments

Application source `233a641f87d2aed34f43121db8024715b39b3455` is installed on
private staging. It is not yet a production release. The candidate adds species,
breed/variety and pet-name filters, paginated results, and a **My favourites**
view using the existing hearts. Only the signed-in member's favourites are
returned. Directory matching uses explicitly published snapshot fields; private
profile edits do not change search results until the owner shares them again.

Video moments accept progressive MP4 files with H.264 video and optional AAC
audio, up to 20 seconds and 20,971,520 bytes (shown as 20 MB). The existing quota
remains twelve combined photo moments, video moments and journal entries per
pet. Clips use private storage outside the webroot, authenticated playback and
bounded byte-range responses. Sharing a clip requires explicit selection and
creates a separate public snapshot; withdrawal revokes access before cleanup.
Original video bytes are retained, so the publication screen explains that
embedded device or location metadata may remain. No host transcoder is needed.

Hosted rehearsal `39530335101-1` found that clicking the upload form's submit
button with site JavaScript disabled did not send a request, despite native
form validity passing. The cause of that pointer interaction failure is not
confirmed. Server-limit checks now use the browser's native `requestSubmit`
algorithm with site scripts disabled, preserving real multipart uploads,
validation, ownership checks and PHP error responses. This proves server
validation, not full usability without JavaScript. The valid upload still uses
a normal button click with JavaScript enabled.

Hosted native proof `39527803526-1` passed 62 checks and removed its fixtures,
but the run stopped before browser verification. It is a failed release gate,
not evidence that the candidate is ready for production. A complete passing
rehearsal and exact production receipt are still required.

The separate homepage matching work is described in
[For your pet](FOR-YOUR-PET.md).
