# Pet Chat

Channel upgrade on private staging, 25 October 2026 (Australia/Melbourne).

## Current scope: channel conversations

The current owner-approved direction replaces the discussion/Q&A pilot below
with flat channel conversations. There are no direct messages or live audio or
video broadcasts. Members can upload photos and supported video files.

The upgrade adds a resizable desktop channel sidebar, mobile category/channel
rows, a fixed composer, limited safe formatting, quoted replies, emoji reactions,
per-member unread markers and personal mutes. Staff can review contributions,
pause posting, and create, move, reorder, archive and restore channels/categories.
The existing Petish fonts, light/dark tokens and accounts are reused.

Posting requires a chosen public name and explicit public-sharing consent. The
first contribution waits for staff approval; subsequent contributions can appear
immediately after approval. Edits return to review. Reports do not automatically
ban members. Bodies allow 1,000 characters and one attachment per message.

Chat alone permits attachments up to **250 MiB** without a video-duration policy.
JPEG, PNG and WebP photographs are resized/re-encoded to strip embedded tags.
Videos must be regular progressive H.264 MP4s with optional AAC audio; original
video metadata can remain. This shared host does not provide FFmpeg, so MOV,
WebM, HEVC and arbitrary formats are not silently accepted or converted.
Existing Passport/review limits stay unchanged. A 5 GiB per-member capacity
guard and request limits bound storage and processing.

Files remain outside the public web root. The media endpoint rechecks message
publication, ownership and personal mutes on every GET/HEAD/Range request.
Unapproved messages/files are available only to their owner and staff. There
are no public WordPress attachment URLs or external media embeds.

`petish_chat_stream_enabled_v1` is a second opt-in flag, defaulting off.
Loading source does not create tables, enable the flag or migrate discussions.
The explicit staging installer provisions two dedicated tables and imports
legacy topics/replies into flat messages without changing their source rows,
authors, timestamps or pending/public state. The importer is snapshot-bound and
idempotent. Private staging remains guarded; production activation is separate.

The service native proof passed 132 checks, media proof 73 checks and UI transport
proof 43 checks in a fresh local WordPress/MySQL sandbox with HTTP, mail and cron
blocked. A separate loopback HTTP proof passed 27 checks, including an actual
250 MiB multipart upload, rejection at 250 MiB plus one byte, 65-second H.264/AAC
video, photo metadata removal and authorization/Range behavior. Browser/staging
checks are tracked separately; local proof is not proof of a hosted upload.

`setup-pet-chat-stream-staging.php` permits provisioning only on the fixed
staging origin, verifies the exact installed Passport source and existing
isolation guard, imports within a transaction, and proves accounts, products,
orders, Passports/media and other settings unchanged. The dedicated verification
workflow shares the existing staging deployment lock.
Schema creation precedes the activation transaction. A failed activation can
leave empty private tables prepared for an idempotent retry; the flag/history
changes roll back together before commit.

The first channel deployment [40175968467](https://github.com/nkrake6/petish/actions/runs/40175968467)
and its automatic email preview passed. The explicit staging enablement
[40176009885](https://github.com/nkrake6/petish/actions/runs/40176009885) passed
36 environment/source/transaction/preservation checks. The historical pending
contribution remains pending, with its legacy source unchanged.

Actual hosted browser checks verified channel navigation, keyboard sidebar
resizing, emoji controls and both theme palettes at desktop and mobile widths.
A generated 250 MiB H.264/AAC upload succeeded through the staging web endpoint;
the browser read its 65-second duration without a media error. It stayed pending
for moderation. The synthetic message was then removed through Chat, leaving
the tested channel empty. No genuine contribution was approved or removed.
Mobile composer spacing and upload status preservation were refined following
these checks. The live storefront has not been enabled or deployed by this work.

## Historical discussion pilot

Open [Pet Chat on staging](https://staging.petish.biz/?petish_chat=1) using the
existing staging login. The staging site's access guard is preserved; the
application supports public reading when launched publicly. Production Chat is
not enabled by this pilot.

The owner confirmed public reading, signed-in posting, topic channels and
threaded Q&A. Each member's first contribution requires moderation; after an
approved contribution, trusted members can post immediately. Chat uses the
existing WordPress/WooCommerce login, not a separate identity provider.

## First version

- General chat, Care and wellbeing, Food and nutrition, and Homes and
  enrichment channels, with a list of discussions/questions.
- A topic page with replies and a best answer for questions.
- Foreground live updates, with an explicit control for new replies so a
  reader's position and unsent draft are preserved.
- A chosen public name, initials avatar and public-posting consent. Account
  names, email addresses, purchases and private Passport data are not copied
  into discussions.
- A staff moderation queue, member reports, thread closing and member posting
  restrictions.

Private messages, chat attachments, push/email notifications and presence/online
counts are outside this version. A discussion reply never sends a customer
email. Existing review and Passport uploads remain separate.

## Implementation boundary

The optional Chat module lives inside the existing Passport package, with its
own service, screen, template and assets. Public controls load only when every
required file is available; the core storage guards also protect existing
records while the feature is off or a UI transfer is incomplete.
`petish_chat_enabled_v1` defaults off and requires the exact
value `yes`; production is not enabled merely by copying the source files.

Topics use a dedicated non-public WordPress post type and replies a dedicated
comment type. Public screens use explicit projections of approved discussions;
native post/comment APIs do not provide an alternative publication route.
Mutations require the current member, POST and an action-specific nonce. Bounded
thread reads use the existing server and do not need a persistent WebSocket
process or a paid service.

## Verification and availability

The native acceptance suite passed 380 checks: 370 core/UI/security checks and
10 feature-off/incomplete-UI checks. It used real WordPress 7.1.3, WooCommerce
11.2.1, MariaDB 11.4 and PHP 8.3 in a disposable database with network, mail and
cron blocked. Real database lock contention, native REST and query isolation,
moderation, consent, actor/nonce scoping, rates, duplicate submissions,
publication projections and privacy erasure were exercised. The sandbox and
containers were removed after success.

The actual UI template, JavaScript and styles passed 266 Chromium checks with
native The7 and child-theme CSS, including 32 combinations of width, theme,
membership and screen type. Checks cover drafts and selection/focus, native
no-JavaScript forms, consent, nested replies, pause/visibility/backoff,
untrusted responses, sign-out/account changes and history-restoration cleanup.
Synthetic screenshots were visually reviewed at 1280px light and 390px dark.

The staging tools passed 13 focused tests, including wrong-host-key rejection
before authentication, immutable Git sources, bounded public receipts and the
single-flag transaction's write scope. The actual hosted contract helper passed
under a native SQL-write barrier. Candidate `14386aefef4689493740d37a6999f24bd2ac778f`
was installed on private staging in `40136658566-1`, with all eight deployment
steps passing. The automatic seven-type email preview `40136700097-1` also
passed. The separate [verification/activation receipt `40136815148-1`](https://github.com/nkrake6/petish/blob/717b8c05acb00b932488835a86aa5f8a0e15d85e/status.json)
passed all 39 safeguards, four explicit scope observations and all 43 installed
Passport hashes against that immutable source. The Chat flag was enabled and
committed on staging. Other options and feature flags, topics/replies, orders,
accounts, products, private Passports and media stayed unchanged. Temporary
remote tools were removed. No hosted test accounts or messages were created;
the hosted proof did not exercise browser interaction or message mutations.
Production was not bootstrapped or changed by that workflow.

## Operation

Administrators moderate from **WooCommerce → Pet Chat moderation**. The queue
includes pending and reported contributions and allows approval, hiding, spam
classification and pausing/reinstating a member's Chat posting. A Chat posting
restriction does not change the member's shopping account or orders.

Members choose a public name and explicitly consent for each post. Bodies are
plain text (4,000 characters); titles allow 120 characters, names 40. The initial
limits are five new topics and 40 replies per member per rolling 24 hours, at
least 15 seconds apart. Threads paginate at 40 replies; the topic list at 20.
Reports are deduplicated per member/message, limited to 20 per day and private
to staff. Reports and Chat posts do not earn customer-review rewards.

Open threads check for updates every 15 seconds, topic lists every 30 seconds.
Updates pause in hidden tabs and can be paused manually; failures back off.
New content appears after the reader chooses **Show new updates**. Terminal
failures, account changes and browser history restoration remove old private
pending content and disable stale forms, while retaining the draft text.

WordPress personal-data export includes a member's Chat contributions and
reports. Active-site erasure removes their topics (including the replies in
those topics), their replies elsewhere and private Chat metadata. When removing
a parent reply, its children are held for moderation rather than promoted into
answer-eligible top-level messages. Backup retention remains separate.
