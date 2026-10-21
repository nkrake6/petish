# Petish WordPress development

The current store is WordPress/WooCommerce at `petish.biz`, using The7's child
theme and the custom Passport and Fulfilment plugins. The static storefront at
the repository root and in `docs/` is an earlier prototype; it is not the deployed
WordPress application.

Use the existing checkout. Do not create a Git worktree unless requested.
Preserve unrelated local changes. Never commit the uploaded historical chat,
configuration credentials, SQL, customer data or decrypted backups.

## Recover current custom source

`.github/workflows/sync-wordpress-source.yml` uses the existing GitHub repository
SSH secret and verified Hostinger fingerprint. It reads only these production
trees, without bootstrapping WordPress:

- `wp-content/themes/dt-the7-child`
- `wp-content/plugins/petish-passport`
- `wp-content/plugins/petish-fulfilment`
- Petish production MU plugin loaders and directories, excluding the staging guard

The raw export exists only in private runner temporary storage. The export
validator checks archive paths and file types, excludes configuration/data/archive
files and screens source for secret literals. Gitleaks provides a second scan.
Suspect files are quarantined wholesale, not replaced with deployable redactions.
Only screened files and a manifest reach a separate `site-source/<run>-<attempt>`
review branch. Check omissions before merging. Automated screening reduces the
risk of publishing credentials; review recovered code before changing it.

Changes to the recovery workflow/scripts on `main` start recovery automatically.
Once editable source is on `main`, subsequent changes belong under `wordpress/`.
An encrypted full backup remains separate from this code snapshot.

## Staging deployment

The existing private site is `staging.petish.biz`. Its guard, private
configuration and separate WooPayments test connection must remain intact.
Production supplier, marketing and Google-login integrations are not copied.

`.github/workflows/deploy-staging.yml` supports:

- Changes on `main`: validate the staging package and destination only.
- Changes on `staging`: apply the exact source commit to staging.
- Manual run: choose `check` (default) or `apply`, optionally selecting an exact
  source commit.

Only the existing child-theme, Passport and Fulfilment directories can be
replaced. The package contains a file-hash manifest. The remote script validates
the fixed destination, checks staging configuration and preserves the staging
guard/configuration hashes. It rejects links and unexpected package entries,
lints PHP, saves a private code snapshot and restores prior code if a swap fails.
Successful release snapshots remain outside the webroot for rollback.

This is code deployment, not database rollback or application acceptance testing.

The separate [production email release](PRODUCTION-EMAIL-RELEASE.md) is limited
to six verified child-theme email files. Release `39407138767` passed all seven
native renders and public health checks on production. It does not deploy the
other custom components or the unready shipment-tracking receiver.
Review update hooks/schema changes before deploying plugin changes. Validate
the affected feature on staging after deployment. Production deployment is not
implemented by this workflow. Real purchases and supplier orders require a
separately agreed sample-order task.

Pet Club has a separate, narrowly scoped release workflow in
`.github/workflows/pet-club.yml`. `verify-pet-club` exercises the exact application
on private staging; `deploy-pet-club` applies only its eighteen reviewed Passport
files after matching staging proof and a fresh, successfully recovered encrypted
backup. Main pushes inspect production without applying the upgrade. Native
production verification is read-only, with public page/asset checks and rollback
protection. A separate migration fills missing directory search fields from
already-published snapshots while the release holds maintenance. It records the
exact inserted metadata rows for conditional rollback and preserves private pet
edits. See [Pet Club release evidence and privacy behavior](docs/PET-CLUB.md).

The personalised homepage and header use the fifteen-file child-theme release
through `.github/workflows/homepage.yml`: the original seven homepage files plus
eight appearance-control/country-flag files. It requires the isolated order-history
proof, browser checks, cleanup evidence and a fresh recovered backup. Deploy it
after the Passport release because its protected-file checks expect that account
UI. See [For your pet](docs/FOR-YOUR-PET.md) for matching and staff curation, and
[header appearance and flags](docs/HEADER-THEME-AND-FLAGS.md) for the current
candidate/release status. The yellow Cart-count preview is separate.

The editable delivery page uses `.github/workflows/delivery-page.yml`. Its
explicit `inspect-delivery-page`, `rehearse-delivery-page` and
`deploy-delivery-page` branches intentionally have no path filter: deployment
may reference a commit already present on another branch. Apply requires a
successful save-and-rollback rehearsal at the exact same commit. It changes only
the fixed page's content and modification dates, preserves unrelated fields,
refreshes that URL's LiteSpeed cache and verifies the canonical public copy.
See [delivery copy and evidence](docs/DELIVERY-PAGE-COPY.md).

Afterpay availability and the separate product/Cart checkout-button proposal
are documented in [the read-only inspection](docs/AFTERPAY-AVAILABILITY.md).
WooPayments does not provide Afterpay as an express wallet like Google Pay.
Do not treat the proposed normal-checkout shortcut as an implemented payment
flow or change processors without the owner's choice.

## Customer reviews release

The opt-in customer review module is live in Passport 0.4.1. It uses
native WooCommerce comments, private moderated media and separate reaction
records; staff species-suitability reviews are unchanged. The dedicated
`customer-reviews.yml` workflow targets private staging only. Product reviews
appear below description/details, and My reviews provides the member dashboard.
Private staging passed 47 native, 44 browser and 8 fixture-cleanup checks on
source `f16690d`, run `39583147075-1`, including a real 50 MiB upload.
`customer-reviews-production.yml` applied the exact 15 reviewed files and enabled
the feature in run `39583820285-1`, source `54e2983`, after 34 native and 14 public
checks. Main pushes inspect; `deploy-customer-reviews` applies after matching
staging and fresh backup/recovery evidence. Both customer review and Passport
videos permit 50 MiB/20 seconds; photo limits remain 5 MiB. Production validation
creates no test reviews, uploads, accounts or orders.
The application flag defaults off on other installations; discount/delivery rewards are not implemented
or enabled. See [the implementation and rollout scope](docs/CUSTOMER-REVIEWS.md).
Do not infer production acceptance from the static design previews.

## Backups and recovery checks

See [FULL-SITE-BACKUP.md](FULL-SITE-BACKUP.md). The encrypted archive includes the
full document root, database and the private Passport tree outside `public_html`.
Do not follow external symlinks when restoring.

After storing a backup, the workflow runs a disposable Docker database/file
restore check with external networking disabled. This does not restore over
production or the existing staging installation. It does not prove complete
application behaviour, external dependencies, payments or supplier integration.

The separate `verify-wordpress-recovery.yml` workflow starts an existing stored
backup in a disconnected application/database sandbox and tests the active
theme/plugins, public page responses, media and private-photo protection. Its
first actual-backup run passed on 20 October 2026; see
[RECOVERY-VERIFICATION.md](RECOVERY-VERIFICATION.md). It does not submit checkout,
payments or supplier orders. Choose an exact backup branch for each new drill.

## Monitoring from this cloud session

Native HTTPS Git works through the supplied authentication proxy. GitHub API
access may be unavailable even when Git works; do not request another token
merely because `gh api` is denied.

Each new workflow publishes a small nonsecret result branch:
`automation-status/<source-sync|backup|staging|recovery|mail>-<run>-<attempt>`. Discover it with
`git ls-remote --heads origin 'refs/heads/automation-status/*'`, fetch the exact
branch and read `status.json`. These results distinguish completed checks from
workflow files that have only been prepared.

Required repository bindings already used by the working backup workflow:
`HOSTINGER_SSH_PASSWORD` (secret), `PETISH_BACKUP_PASSPHRASE` (secret) and
`HOSTINGER_SSH_FINGERPRINT` (variable). Never copy their values into this repo.

## Transactional email

[ORDER-EMAIL.md](ORDER-EMAIL.md) describes the dedicated production SMTP setup.
The new `PETISH_SMTP_PASSWORD` repository secret holds the `administrator@petish.biz`
mailbox password; `orders@petish.biz` is the sender alias. Main pushes prepare code; the separate mail configuration
workflow performs authentication before installing it. The connection check
does not submit a message. Inbox delivery needs an explicitly authorized test.
