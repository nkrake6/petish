# Account navigation

On screens up to 991px wide, the account links use a disclosure button showing
“Account menu” and the current section. Opening it reveals WooCommerce's native
links, including My Pets, For my pet and Log out. Desktop keeps the existing
sidebar. With JavaScript disabled, all links remain visible.

The Passport plugin enqueues `assets/account-navigation.js` on account pages.
The script enhances the native navigation rather than replacing its links or
logout nonce. Labels are localized by `Petish_Passport_UI::enqueue()`. Styles use
the existing light and dark theme variables. Escape closes the menu and returns
focus; clicking or focusing outside also closes it. Resizing keeps focus visible.

Validation:

```sh
node tests/account-navigation.browser.cjs
python3 -m unittest discover -s tests -p 'test_account_navigation_*.py'
```

The staging browser check uses the existing private synthetic customer and reads
My Pets, Orders and Account details at 320px, 390px and desktop widths. It permits
one login submission and blocks other writes, checkout, supplier requests and
logout. Session cookies and input stay private; published evidence contains only
fixed boolean checks and the three candidate hashes.

The `Verify and release Petish account navigation` workflow inspects production
on main. Staging checks follow the existing staging deployment and email preview
sequentially. The `deploy-account-navigation` branch applies only the three
Passport files after matching staging evidence and a recent full backup with
successful application recovery. It retains private rollback copies and verifies
native navigation, exact public script/stylesheet bytes, public pages and unchanged customer, order,
authentication, stock and account configuration before committing the release.
The existing email theme and all other Passport files are protected.

Staging public component directories explicitly use mode `0755` and files `0644`.
The private release workspace, configuration and rollback parents stay private.
This avoids the release process's private `umask 077` blocking static assets.

Staging proof: [39412424150](https://github.com/nkrake6/petish/actions/runs/39412424150),
source `2b3ef0790c1976825cbe3b8c6c15857d9f04f8c6`. All 21 authenticated browser
checks passed after the staging directory permission correction. The production
release request records matching source and the verified backup/recovery branches.
