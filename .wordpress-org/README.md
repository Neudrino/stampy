# WordPress.org Plugin Assets

This directory contains images used on the WordPress.org plugin directory page.
These files are deployed to the SVN `assets/` directory by the
`10up/action-wordpress-plugin-deploy` GitHub Action during the deploy workflow
(`deploy-wporg.yml`). They are excluded from the plugin zip via `.distignore`.

## Files

### Banner (header image at the top of the plugin page)

- `banner-772x250.png` — standard banner (772×250)
- `banner-1544x500.png` — retina/hi-DPI banner (1544×500)
- `banner-772x250.svg` — SVG source for the banner

### Plugin icon (square, shown in search results and admin)

- `icon-128x128.png` — standard icon (128×128)
- `icon-256x256.png` — retina/hi-DPI icon (256×256)
- `icon.svg` — SVG vector icon (with PNG fallback)

### Screenshots (displayed on the plugin page, captions from `readme.txt`)

- `screenshot-1.png` — Stampy admin: subscriber list with status filter and bulk actions
- `screenshot-2.png` — Campaign composer: block editor with sidebar plugin
- `screenshot-3.png` — Settings page: SMTP configuration, tracking toggle, compliance settings
- `screenshot-4.png` — Signup block: double opt-in form with email, name fields, and consent checkbox

## Naming conventions (WordPress.org requirements)

- Filenames must be lowercase
- Banner: `banner-772x250.(jpg|png)` and `banner-1544x500.(jpg|png)`
- Icon: `icon-128x128.(png|jpg|gif)`, `icon-256x256.(png|jpg|gif)`, and/or `icon.svg`
- Screenshots: `screenshot-1.(png|jpg)`, `screenshot-2.(png|jpg)`, etc.
- Localized: append `-de`, `-es`, `-rtl`, etc. (e.g. `screenshot-1-de.png`)

## Regenerating from SVG sources

```bash
cd .wordpress-org
convert -background none -density 300 icon.svg -resize 128x128 icon-128x128.png
convert -background none -density 300 icon.svg -resize 256x256 icon-256x256.png
convert -background none -density 150 banner-772x250.svg -resize 772x250 banner-772x250.png
convert -background none -density 300 banner-772x250.svg -resize 1544x500 banner-1544x500.png
```

## SVN MIME types

If images download instead of displaying when viewed on WordPress.org, set the
SVN MIME type properties:

```bash
svn propset svn:mime-type image/png *.png
svn propset svn:mime-type image/jpeg *.jpg
```
