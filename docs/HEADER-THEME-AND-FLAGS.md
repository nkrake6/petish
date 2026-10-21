# Header appearance control and country flags

The header adds a light/dark switch between Search and the country/currency
selector. It uses the existing appearance preference, synchronises with the
account's Appearance selector, and follows the system preference until the
visitor makes a choice. The control supports keyboard activation, a visible
focus state and an accessible switch state. Without JavaScript it stays hidden.

The country selector now uses locally hosted SVG flags rather than emoji. This
prevents environments that lack flag emoji from displaying country letters such
as “AU”. All 250 WooCommerce country codes have a symbol, with a neutral globe
fallback. The flag asset is derived from the MIT-licensed HatScripts/circle-flags
project; its licence is distributed alongside it. Currency, destination and
checkout behaviour are unchanged.

## Release boundary

The existing homepage workflow covers fifteen child-theme files. Seven are the
previously deployed personalised-homepage scope; eight cover the appearance
control and country flags. Production rollback baselines remain the previously
verified live bytes. The two new flag files must be absent before installation.

Private staging checks cover both display themes, 320/390/1280px headers,
keyboard and pointer interaction, persisted and system preferences, cloned
headers, and home/Cart flag rendering. The mobile checks require a readable logo
and preserve the 60px header and 44px appearance-switch target. Hosted WebKit
checks confirm the flag's pixels rather than accepting an SVG element alone.
The Cart check is limited to the header in the existing synthetic QA account.
It records the observed Cart state without requiring the account's saved cart
to be empty or altering its contents. Cart and payment actions remain blocked;
only the decorative flag is cropped in memory for its pixel check. This is not
an acceptance test of cart hydration, checkout or payment flows.

Deployment requires matching application hashes from successful hosted checks
and a fresh encrypted backup that has passed disconnected application recovery.
The scoped release snapshots prior files, verifies native rendering and public
asset bytes, and refreshes LiteSpeed's public HTML cache. It restores the previous
files and refreshes that cache if verification fails.

## Current status

The appearance control and SVG flags are installed on private staging from
`083eac25f15c1287152111205f2163a0e7990e34`, with
[installation 39545593776-1](https://github.com/nkrake6/petish/tree/automation-status/staging-39545593776-1)
successful. [Hosted acceptance 39549754179-1](https://github.com/nkrake6/petish/tree/automation-status/homepage-39549754179-1)
passed from source `d7a33cb54599d2a1b5c65f3f3a3ea46aed8f626b`, with all fifteen
application hashes matching the installed candidate. All 35 browser checks,
33 native checks, 22 cleanup checks and 36 required rendering checks passed.
Chromium and WebKit verified home/Cart geometry and actual AU flag paint in both
display themes. The logo measured about 103px at a 320px viewport and 125px at
390px; header heights stayed at 60px mobile and 90px desktop, without overflow.
The Cart checks wait for stable layout after responsive theme updates while
preserving those geometry requirements.

Released to [petish.biz](https://petish.biz/) on **21 October 2026 at 05:34 UTC**.
[Production 39550192872-1](https://github.com/nkrake6/petish/tree/automation-status/homepage-39550192872-1)
passed from source `2db179ec003d211a4583b856cd1bca753db1ebfa`. All fifteen deployed
file hashes match the staging proof. Native verification, public HTML-cache
queue/consumption checks, canonical page health, exact header/flag asset checks
and the final release commit passed. Public homepage and Cart markup include
the SVG flag, and the header order is Search → appearance → country/currency.
The maintenance guard was removed, protected state was preserved, and no
rollback was needed. Private prior-file snapshots remain available through the
scoped release's rollback mechanism.

The prerequisite
[encrypted backup 39545072151-1](https://github.com/nkrake6/petish/tree/automation-status/backup-39545072151-1)
and [disconnected recovery 39545366926-1](https://github.com/nkrake6/petish/tree/automation-status/recovery-39545366926-1)
passed on 21 October 2026. Recovery selected that exact backup, booted WordPress
and the active plugins, verified page/media responses and private-photo
protection, and removed the isolated sandbox.

The yellow Cart count remains a separate preview awaiting design approval. It
is not included in this release.
