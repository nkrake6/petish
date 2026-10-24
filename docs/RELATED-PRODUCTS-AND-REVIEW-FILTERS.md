# Related product cards and review filters

25 October 2026 (Australia/Melbourne). Card/filter application source `3eceb22`;
flame-circle application source `b6def08`, production release source `9b1f87c`.
Only the existing child-theme
`assets/css/petish.css` changes.

The7's related-product thumbnail already contains the supplier Hot badge. Its
anchor lacked a positioning context, so the absolute badge escaped onto the
text area. The thumbnail now anchors the existing badge; eligibility and
supplier metadata are unchanged.

Related-product Hot badges now use a 32×32px white circle with a red border
and a 20px flame icon. The Hot label is visually hidden but retained for
assistive technology. This rule applies only to Related products; homepage
and catalogue Hot pills keep their existing text and shape.

Related content fills the available card width, and titles reserve two lines.
Price pills therefore share a width and top position for one- and two-line
titles. Native responsive columns, full title links, price/sale markup and
product actions remain intact. Select options uses light lavender; native
simple-product Add to cart uses teal. Hover and focus states are included.

The7's `select:not(.elementor-field):not(.elementor-field-textual)` rule adds a
10px bottom margin with greater specificity than Passport's filter styling.
The scoped `.petish-site .pcr .pcr-filters select` reset removes only that
bottom margin, keeping the native 5px label gap, 44px controls, checkbox, focus
and GET submission behavior. No Passport files or data change.

The native related template, WooCommerce button rendering and compiled The7
LESS fixtures pass at 1280, 900, 390 and 320px in both themes, including long
prices and sale markup. The filter fixture reproduces the old 10px offset and
passes at five widths in both themes. Shop controls are unchanged.

| Evidence | Result |
|---|---|
| Staging install `40128519343-1` (`3eceb22`) | All eight steps passed |
| Full staging `40128905678-1` (`cbda466`) | All 226 required checks and 30 hashes passed |
| Production `40129374822-1` (`481ea28`) | All ten phases, 178 required checks and 30 hashes passed; one CSS file changed |
| Backup/recovery | Encrypted backup `40122768140-1` and matching restored-application proof `40123260114-1` |
| Live baseline `40128907173-1` | Five variable-product cards; both Hot badges outside images; prices 115–235.89px wide and 20px vertical spread; filter controls offset 10px |
| Live final `40129859735-1` (`dbd0cd2`, unchanged `481ea28` app bytes) | All five price pills 235.89px wide, width/top spread 0px; sampled Hot badge inside image; four variable buttons lavender and one simple button teal; all review controls 44px, margin/bottom offset 0px; native GET behavior preserved |
| Circle staging install `40134768770-1` (`9a48281`) | All eight steps passed; automatic seven-type email preview `40134805590-1` also passed |
| Circle full staging `40134953986-1` (`9a48281`) | All eight phases, 226 required checks and 30 exact-source hashes passed |
| Circle production `40135426336-1` (`9b1f87c`) | All ten phases, 178 required checks and 30 hashes passed; only `petish.css` changed |
| Circle live inspection `40135602930-1` (`d4cab04`, same frozen circle app bytes) | Two Related circles 32×32px with loaded 20px flames, white backgrounds/red borders, accessible hidden labels and correct image anchoring; five homepage Hot pills remained 67×34px; card prices/buttons and review-filter geometry remained correct |

The baseline related sample came from the fixed public Corner product; the
final sample came from Rainbow and included both native action types. The
final variable button RGB is 237/231/255 (`#EDE7FF`), simple button RGB is
15/76/92 (`#0F4C5C`). Hot positioning is verified against the actual thumbnail
geometry, not only DOM ownership. All ten guest-browser checks and private
tool cleanup passed. Existing payment-panel images remained byte-identical.

The [original geometry report](https://github.com/nkrake6/petish/blob/d053b7815efaa4e3cb9e2feb43a8c8b73f81a472/status.json)
and [immutable circle inspection](https://github.com/nkrake6/petish/blob/27dc2dea27b98e1c0a3ca584c6078085af53740b/status.json)
contain the finite measurements. The circle sample used the fixed Corner
product fallback because the original Rainbow sample contained no Hot badge.
All ten guest-browser checks and private-tool cleanup passed. The optional
related-products screenshot failed during the screenshot call and was not
published; no related image or visual proof is claimed. Actual DOM geometry,
computed colors and control alignment provide the live verification above.
