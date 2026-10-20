# Petish backup recovery verification

**Passed on 20 October 2026. Task 1, Secure the current work, is complete.**

- [Recovery workflow run](https://github.com/nkrake6/petish/actions/runs/39357055421)
- [Machine-readable result](https://github.com/nkrake6/petish/blob/automation-status/recovery-39357055421-1/status.json)
- Tested encrypted backup: `full-site-backup/39346479613-1`, under
  `site-backups/39346479613-1/`.

## Recovered and checked

The workflow verified every encrypted part checksum, decrypted and verified the
archive, restored **40,037 regular files** including the private Passport photo,
and imported **all 103 database tables**. It then started the recovered WordPress
application with the existing The7 parent/child theme and **all 14 active plugins**.
The recovered catalogue contained **104 published products**.

Actual HTTP requests successfully loaded the homepage, shop, a published product,
cart, account and a recovered media file. The stored private photo reference
resolved to its restored image, and an unauthenticated request for it returned
404. No hosting cache/database drop-ins needed quarantine in this run.

## Isolation and cleanup

The test used new throwaway database credentials and salts; the original hosting
configuration was never executed. Both application and database containers had
external networking disabled, no published ports and only a shared private
database socket. Mail, WordPress HTTP and background queue execution were blocked.
No checkout, payment, supplier purchase or delivery action was performed.

The temporary application, database, volumes and private recovered files were
removed after the test. Production and the existing hosted staging installation
were not modified. The archived external symlink was inspected and skipped,
never followed; see the backup's encrypted metadata for that dependency.

## Scope and repeating the check

This proves that this stored backup can boot the site and serve its main pages,
media and private-photo protection in the tested runtime. Payment-provider,
supplier, DNS/email-service, complete browser interaction and delivery tests are
separate. A live SQL/filesystem backup remains non-atomic.

Run **Start and verify a restored Petish backup** in GitHub Actions and choose
the exact encrypted backup branch to verify. Its default is the backup recorded
above; select a newer branch explicitly when testing a newer backup. Script
changes on main also run the check against that default.

The saved workflow requires the existing `PETISH_BACKUP_PASSPHRASE` repository
secret. It does not need a Mac, new hosting account, SSH login or changes to
production. Only safe counts, booleans and fixed failure stages are published
on `automation-status/recovery-<run>-<attempt>`.
