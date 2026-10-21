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

Local browser verification used the actual child-theme colour tokens, self-hosted DM Sans and unchanged account-navigation script, comparing the new stylesheet against its original contents at the same widths.

| Width | Original and new menu height | Original and new mobile trigger |
| --- | ---: | ---: |
| 320 px | 414 px expanded | 65.8 px |
| 390 px | 414 px expanded | 65.8 px |
| 992 px | 423 px | Not displayed |
| 1100 px | 423 px | Not displayed |
| 1440 px | 423 px | Not displayed |

Both themes passed all five widths. Every row retained its original height, all nine native links were unchanged, and there was no horizontal overflow. Additional checks covered each destination as the active item at 992 px, keyboard Enter/Escape on mobile, and all nine visible links without JavaScript. No account requests or form submissions were made.

The 992–1100 px desktop range uses tighter horizontal padding only, preserving the original font size and vertical spacing so Payment methods fits beside its icon. Mobile caption, header, chevron, open/close behavior and scrolling limit are unchanged. The existing no-JavaScript link layout remains available.

These are source and local verification notes; deployment and hosted verification are tracked by the Passport release.
