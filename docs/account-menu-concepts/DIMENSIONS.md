# Account menu design comparison

[Open all three designs](index.html)

All three previews contain the same nine account destinations and retain the existing collapsed mobile header. Icons use inline SVG. Fonts are local system fonts, and the colours come from Petish’s current light/dark palette.

| Menu | Desktop panel | Expanded mobile panel | Collapsed mobile header |
| --- | ---: | ---: | ---: |
| Current menu baseline | 423 px | 414 px | 65.8 px |
| [01 · Colour lines](01-colour-lines.html) | 423 px | 414 px | 65.8 px |
| [02 · Petish tiles](02-petish-tiles.html) | 350 px | 350 px | 65.8 px |
| [03 · Colour track](03-colour-track.html) | 423 px | 414 px | 65.8 px |

The baseline uses the current Passport account-navigation CSS and script. Measurements use the same 252 px desktop menu width, 16 px root font and system font in every preview. Colour lines and Colour track retain 45 px desktop rows and 44 px mobile rows. Petish tiles uses four pairs of 66 px tiles followed by a 44 px Log out row, making the whole panel shorter.

The existing mobile header has a 64 px minimum height. Its caption, selected label, spacing and border produce a measured 65.8 px height here; all three concepts preserve that exact geometry. On short screens the expanded menu retains the existing scrolling limit of 60% of viewport height, capped at 420 px.

Verification covered all three designs at 320, 390 and 1440 px widths in light and dark modes: 18 combinations, with no horizontal overflow or clipped menu labels. Checks also covered theme switching, menu selections, keyboard Enter/Escape for the mobile dropdown and preview-only Log out. All browser requests were local; no live account was accessed or changed.

## Screenshots

| Design | Desktop dark | Desktop light | Mobile open |
| --- | --- | --- | --- |
| Colour lines | [View](assets/01-desktop.png) | [View](assets/01-desktop-light.png) | [View](assets/01-mobile.png) |
| Petish tiles | [View](assets/02-desktop.png) | [View](assets/02-desktop-light.png) | [View](assets/02-mobile.png) |
| Colour track | [View](assets/03-desktop.png) | [View](assets/03-desktop-light.png) | [View](assets/03-mobile.png) |

Prepared for design review on 21 October 2026. The recommendation is **Colour lines**, which adds Petish character while preserving familiar navigation.
