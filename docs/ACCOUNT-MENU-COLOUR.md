# Colour track account menu

The selected [Colour track concept](account-menu-concepts/03-colour-track.html) is implemented in `wordpress/wp-content/plugins/petish-passport/assets/passport.css`.

The account links retain WooCommerce’s endpoint classes, labels, order, URLs and authentication behavior. The existing `account-navigation.js` remains unchanged. Decorative CSS masks add the nine meaningful icons; no extra icon text enters the links’ accessible names.

The colour groups use endpoint classes, so removing or adding an account item cannot shift the existing icons to the wrong label:

| Destinations | Light | Dark |
| --- | --- | --- |
| Dashboard, Orders, Downloads | Teal `#0F4C5C` | Cyan `#20C6F7` |
| Addresses, Payment methods, Account details | Purple `#6532A1` | Lavender `#E9D5FF` |
| My Pets, For my pet | Pink `#B22D69` | Pink `#FF5BA7` |
| Log out | Gold `#8B5B05` | Gold `#F9A825` |

Rows use a slim left rail and right-aligned icons. The current item has a stronger colour wash, bold text and a 26 px circular marker around its 17 px icon. Normal icons are 20 px, with the approved 1.65 SVG stroke. Masks include both standard and WebKit properties. Labels remain usable if masks are unsupported.

## Geometry and checks

Local browser verification used the actual child-theme colour tokens, self-hosted DM Sans and unchanged account-navigation script, comparing the new stylesheet against its original contents at the same widths. The focused desktop fixture uses a 50 px outer inset and calibrates the longest label to the hosted measurement of 125 px; this calibration is confined to the fixture and does not change the site's font or spacing.

| Width | Original and new menu height | Original and new mobile trigger |
| --- | ---: | ---: |
| 320 px | 414 px expanded | 65.8 px |
| 390 px | 414 px expanded | 65.8 px |
| 992 px | 423 px | Not displayed |
| 1100 px | 423 px | Not displayed |
| 1101 px | 423 px | Not displayed |
| 1199 px | 423 px | Not displayed |
| 1200 px | 423 px | Not displayed |
| 1440 px | 423 px | Not displayed |

Both themes passed all eight widths. Every row retained its original height, all nine native links were unchanged, and there was no horizontal overflow. Additional checks covered each destination as the active item at 992 px, keyboard Enter/Escape on mobile, and all nine visible links without JavaScript. No account requests or form submissions were made.

Hosted rehearsal measured 160.39 px rows at 992 px. Adding the icon to the original 20% navigation width left 118.39 px for the 125 px Payment methods label, increasing that row from 45 px to 66 px. The correction uses 22% navigation and 74% content widths from 992–1199 px, retaining the existing 4% gap and compact horizontal padding. At 992 px the corrected fixture provides 178.23 px rows, enough for the full label and icon, while retaining 45 px row heights and a 423 px panel. The upper breakpoint also avoids reintroducing wrapping when full horizontal padding resumes at 1200 px.

Font size, labels and vertical spacing are unchanged. Mobile caption, header, chevron, open/close behavior and scrolling limit are unchanged. The existing no-JavaScript link layout remains available.

These are source and local verification notes; deployment and hosted verification are tracked by the Passport release.
