# Related product cards and review filters

25 October 2026 (Australia/Melbourne). Application source `3eceb22`;
production release source `481ea28`. Only the existing child-theme
`assets/css/petish.css` changes.

The7's related-product thumbnail already contains the supplier Hot badge. Its
anchor lacked a positioning context, so the absolute badge escaped onto the
text area. The thumbnail now anchors the existing badge; eligibility and
supplier metadata are unchanged.

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

The baseline related sample came from the fixed public Corner product; the
final sample came from Rainbow and included both native action types. The
final variable button RGB is 237/231/255 (`#EDE7FF`), simple button RGB is
15/76/92 (`#0F4C5C`). Hot positioning is verified against the actual thumbnail
geometry, not only DOM ownership. All ten guest-browser checks and private
tool cleanup passed. Existing payment-panel images remained byte-identical.

The [immutable final geometry report](https://github.com/nkrake6/petish/blob/d053b7815efaa4e3cb9e2feb43a8c8b73f81a472/status.json)
contains the finite measurements. The optional related-products screenshot
encountered a capture exception and was not published; no related image or
visual proof is claimed. Actual DOM geometry, computed colors and control
alignment provide the live verification above.
