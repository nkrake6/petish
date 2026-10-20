# Petish production email release

The release contains only six The7 child-theme PHP files tested together on
staging commit `86e315f3daf172e1c9faee9a62d768b48f634bca`: `functions.php`,
`inc/petish-email-branding.php`, `inc/petish-account-email-branding.php`, and the shared WooCommerce
email header, footer and button templates. The approved animal logo already
exists on production; its file contents are not replaced.

The pre-release full backup is
[`full-site-backup/39404802272-1`](https://github.com/nkrake6/petish/tree/full-site-backup/39404802272-1).
Its file/database restore passed, and application recovery
[`39405110164`](https://github.com/nkrake6/petish/actions/runs/39405110164)
started the restored WordPress site and verified its main pages and integrations
with external networking and mail blocked.

Production inspection
[`39405674799`](https://github.com/nkrake6/petish/actions/runs/39405674799)
confirmed that all six live destinations match the expected baseline, the
existing logo is correct, and homepage/shop/product/cart/account respond
normally. The publicly served PNG is losslessly recompressed: its dimensions
and every decoded RGBA pixel, including transparency, match the approved file.

## Release workflow

[Deploy verified Petish email branding](https://github.com/nkrake6/petish/actions/workflows/deploy-email-branding.yml)
inspects on relevant `main` pushes. The dedicated `deploy-email-branding` branch
selects apply; a manual run can also select inspect or apply. Tool/source
checkout must match the workflow commit. Apply requires the exact successful
native staging, recent full-backup and application-recovery evidence recorded
in `operations/email-branding-release.json`.

The runner compares the six candidate files byte for byte with the tested
staging commit and the remote installer checks their pinned hashes. It refuses
unexpected live edits. Helpers/templates are installed before their functions
includes; rollback restores functions first. Existing config, MU plugins and
other theme files are checked for changes. A private release copy and journal
remain under `$HOME/.petish-email-releases/<run>-<attempt>` for recovery.

Before committing a release, the native verifier renders seven email types on
the production installation using unsaved synthetic order/user objects. Core
welcome/reset filters and native action links are checked, along with unchanged
order/user counts, authentication state, stock and account/email settings.
Outbound HTTP, mail delivery, cron and async queues are blocked for that CLI
request. Public store pages and the logo are checked again. Verification
failure invokes the scoped rollback; raw output and message bodies remain
private. Only finite checks reach `production-email-release/<run>-<attempt>`.

A successful workflow triggers another encrypted full-site backup and isolated
application recovery. Deployment does not change SMTP credentials, native
email enabled flags, account-verification policy, payment/shipping settings or
DSers configuration. No actual test message is sent. The tracking receiver
remains excluded because its duplicate and blank-input gaps are unresolved.
