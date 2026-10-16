# Petish

A responsive demo storefront for small mammal supplies. Includes searchable animal categories, a persistent shopping bag, quantity controls, and AUD sample pricing. Products and prices are illustrative; payments and orders are not enabled.

## Development

Requires Node.js 18 or later. No dependencies to install.

```sh
npm run dev
```

The server listens on port 3000 (override with `PORT`). Run `npm run check` for JavaScript syntax checks. All assets are local, including the original rabbit illustration. No external asset requests are required.

## Latest design preview

The updated Petish storefront is published from `docs/petish/`. The root application and this static copy use relative asset paths for GitHub Pages compatibility. Keep `index.html`, `styles.css`, `app.js`, and `assets/` in sync when updating the published preview. Video reviews, articles, and community sections are labelled concept features.
