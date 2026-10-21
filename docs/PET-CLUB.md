# Petish Passport: Pet Club

The owner selected the Pet Club design on 21 October 2026 (Australia/Sydney).
This upgrades the existing Passport plugin and its My Pets endpoint, retaining
the existing pet records, private photos, active-pet selection, account menu,
and reviewed product collections.

## Owner experience

My Pets uses the approved photo-led Pet Club layout: pet switches, a profile
hero, biography and personality tags, Moments/About/Journal sections, and a
community sidebar. Existing pets need no re-entry. Empty sections invite the
owner to add their own content; no sample pets or fabricated hearts are used.

Each pet can have an optional biography of up to 400 characters, up to three
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

Release evidence will be recorded here after the hosted checks complete.
