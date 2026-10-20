# Petish order email

The owner created `orders@petish.biz` on Hostinger Email. The read-only DNS
audit on 20 October 2026 found Hostinger MX records, an SPF record including
Hostinger, three Hostinger DKIM selectors and a DMARC monitoring policy
(`p=none`). These records do not prove delivery to an inbox.

## Secure setup

Add the mailbox password as the GitHub Actions repository secret
`PETISH_SMTP_PASSWORD` at
<https://github.com/nkrake6/petish/settings/secrets/actions>.
Use the mailbox password, not the Hostinger account or hosting SSH password.
Do not put it in chat, WordPress options, source files or a repository variable.

Run **Configure Petish order email** manually on `main`, or push the reviewed
`main` commit to the dedicated `configure-mail` branch. Ordinary `main` pushes
do not activate the production mail adapter. Existing repository SSH credentials
and the verified Hostinger host key are reused.

The workflow first checks existing mail metadata without loading WordPress.
It refuses competing active mail plugins, SMTP constants and unsafe paths.
The candidate connection uses `smtp.hostinger.com`, port 465, implicit TLS with
certificate/hostname verification, and the `orders@petish.biz` mailbox login.
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

## Tracking remains a separate integration

WooCommerce handles order notifications; SMTP delivers those messages.
Shipment updates still require tracking to return from DSers/suppliers and a
compatible WooCommerce tracking integration. Test split parcels and partial
fulfilment with the sample-order plan before promising tracking notifications.

Hostinger mailbox sending limits depend on the subscribed plan and have not
been verified. Keep marketing campaigns separate from transactional delivery;
SMTP configuration alone does not implement retries, delivery monitoring or
bounce processing.
