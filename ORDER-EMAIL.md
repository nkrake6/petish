# Petish order email

Shared HTML branding and the separate staging design-preview workflow are
described in [EMAIL-BRANDING.md](EMAIL-BRANDING.md). The design uses the approved
animal logo; SMTP delivery and email appearance are configured separately.

The owner created the `orders@petish.biz` alias on the Hostinger Email mailbox
`administrator@petish.biz`. SMTP authenticates as the administrator mailbox;
customers see the orders alias as the sender. The read-only DNS
audit on 20 October 2026 found Hostinger MX records, an SPF record including
Hostinger, three Hostinger DKIM selectors and a DMARC monitoring policy
(`p=none`). These records do not prove delivery to an inbox.

On 20 October 2026, configuration run
[`39360477958`](https://github.com/nkrake6/petish/actions/runs/39360477958)
verified authentication and TLS and installed the production adapter without
submitting a message. Encrypted backup `full-site-backup/39360478186-1`
includes the private SMTP configuration. Application recovery run
[`39360763834`](https://github.com/nkrake6/petish/actions/runs/39360763834)
passed with that backup, all 14 active plugins, 104 published products, the main
store pages and mail/network blocking in the disposable sandbox.

The newer backup `full-site-backup/39360505612-1` also passed full application
recovery in run `39360965357`. At 02:50 UTC, delivery-test run
[`39361096109`](https://github.com/nkrake6/petish/actions/runs/39361096109)
submitted the owner's single requested message successfully. The one-use key
was consumed and its private directory removed; the encrypted request was
removed from `main`. This confirms SMTP acceptance of the orders alias.
The owner confirmed successful receipt in Gmail. Authentication headers were
not separately inspected. SMTP connection, sender-alias acceptance and delivery
are verified. The native order-event rehearsal subsequently passed on private
staging; supplier shipment notifications still need their end-to-end test.

The owner confirmed that WooCommerce's **New order** recipient is
`orders@petish.biz` and that customer processing, completed and refund emails
are enabled. The native order-email and refund rehearsal passed in hosted run
[`39368488467`](https://github.com/nkrake6/petish/actions/runs/39368488467).
Staging captured the new-order, processing, completed, customer-note and refund
emails; recipient, order/product and AUD-total checks passed. The provider
confirmed a successful test payment and full test refund, and stock returned
to baseline. The customer note used explicitly synthetic tracking only.
See [STAGING-REHEARSAL.md](STAGING-REHEARSAL.md) for the scope and evidence.

## Secure setup

Add the `administrator@petish.biz` mailbox password as the GitHub Actions repository secret
`PETISH_SMTP_PASSWORD` at
<https://github.com/nkrake6/petish/settings/secrets/actions>.
Use the administrator mailbox password, not the Hostinger account or hosting SSH password.
Do not put it in chat, WordPress options, source files or a repository variable.

Run **Configure Petish order email** manually on `main`, or push the reviewed
`main` commit to the dedicated `configure-mail` branch. Ordinary `main` pushes
do not activate the production mail adapter. Existing repository SSH credentials
and the verified Hostinger host key are reused.
Mail configuration and full backups share a concurrency group so production
credential changes cannot overlap a backup export.

The workflow first checks existing mail metadata without loading WordPress.
It refuses competing active mail plugins, SMTP constants and unsafe paths.
The candidate connection uses `smtp.hostinger.com`, port 465, implicit TLS with
certificate/hostname verification, and the `administrator@petish.biz` mailbox login.
It authenticates and disconnects without submitting a message. Only after that
check succeeds does it install the credential and Petish MU adapter.

Private configuration is stored at
`$HOME/domains/petish.biz/private/petish-mailer/smtp.json`, outside `public_html`.
The directory is mode `0700` and the file is mode `0600`. The new full backup
includes this private tree inside its encrypted archive. Never publish or copy
the plaintext configuration into Git or workflow artifacts.

The adapter fixes the sender and envelope sender to `orders@petish.biz`, named
Petish. It retains existing recipients, Reply-To, WooCommerce templates and
message contents. It does not change which order events generate notifications.
It requires production and valid private configuration; it does not fall back
to unauthenticated PHP mail. Staging and recovery retain their mail captures.

## Verification and recovery

The nonsecret result appears at
`automation-status/mail-<run>-<attempt>/status.json`. Success requires
`configured`, `smtp_authentication_verified` and `tls_peer_verification` true,
with `message_submitted` false. Failed authentication leaves prior files intact.
No password, SMTP transcript, customer address or message is published.

A successful setup triggers **Back up the full Petish site to Git**. A successful
backup then triggers **Verify restored Petish WordPress application**, using the
exact new backup branch. Inspect their separate results before claiming the new
configuration has been backed up and recovered. Recovery blocks outbound mail.

Authentication proves the credential and TLS connection, not inbox placement.
Send a single test only after the owner explicitly requests it and gives the
recipient. Check receipt, sender, Reply-To and authentication headers. Then use
an agreed sample order to verify paid-order, shipment, cancellation and refund
notifications. Do not create a purchase or contact customers for a mail check.

The dedicated **Test Petish order email** workflow keeps the recipient out of
Git. An explicitly invoked `prepare-mail-test` run publishes an ephemeral public
key and keeps its private half outside the webroot. The operator encrypts the
authorized recipient and token using RSA OAEP and commits only the encrypted
envelope at `operations/petish-mail-test-envelope.json`. A `send-mail-test` run
consumes the private key before one submission attempt. It never bootstraps
WordPress, creates an order or automatically retries. Keys expire after 15
minutes; recipient addresses and SMTP transcripts are not published. Check its
separate `automation-status/mail-test-*` result and confirm inbox receipt.

## Tracking remains a separate integration

The current receiver evaluation and remaining release checks are recorded in
[TRACKING-READINESS.md](TRACKING-READINESS.md).

WooCommerce handles order notifications; SMTP delivers those messages.
Shipment updates still require tracking to return from DSers/suppliers and a
compatible WooCommerce tracking integration. Test split parcels and partial
fulfilment with the sample-order plan before promising tracking notifications.

The read-only inventory found one DSers API key and five DSers webhooks, but no
recognized WooCommerce shipment-tracking plugin. This establishes setup
inventory only. It does not verify supplier shipment updates, tracking returned
to WooCommerce, customer tracking emails or a delivery round trip.

Hostinger mailbox sending limits depend on the subscribed plan and have not
been verified. Keep marketing campaigns separate from transactional delivery;
SMTP configuration alone does not implement retries, delivery monitoring or
bounce processing.
