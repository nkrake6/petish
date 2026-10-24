# Pet Chat

Development candidate, 25 October 2026 (Australia/Melbourne).

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
under a native SQL-write barrier. Private staging installation remains in
progress. These tests do not claim production activation.

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
