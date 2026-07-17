# WordPress.org Plugin Assets

This directory contains images used on the WordPress.org plugin directory page.
These files are deployed to the SVN `assets/` directory by the
`10up/action-wordpress-plugin-deploy` GitHub Action during the deploy workflow
(`deploy-wporg.yml`). They are excluded from the plugin zip via `.distignore`.

## Files

### Banner (header image at the top of the plugin page)

- `banner-772x250.png` — standard banner (772×250)
- `banner-1544x500.png` — retina/hi-DPI banner (1544×500)

### Plugin icon (square, shown in search results and admin)

- `icon-128x128.png` — standard icon (128×128)
- `icon-256x256.png` — retina/hi-DPI icon (256×256)
- `icon.svg` — SVG vector icon (with PNG fallback)

The giraffe icon is derived from the [Noto Emoji](https://github.com/googlefonts/noto-emoji)
project by Google. The original SVG was downloaded from
<https://raw.githubusercontent.com/googlefonts/noto-emoji/main/svg/emoji_u1f992.svg>.
The Noto Emoji project is licensed under the SIL Open Font License, Version 1.1 —
see `LICENSE` in this directory for the full license text, or
<https://github.com/googlefonts/noto-emoji/blob/main/LICENSE> online.

### Screenshots (displayed on the plugin page, captions from `readme.txt`)

- `screenshot-1.png` — Campaigns: list of all campaigns with status, progress, and tracking columns
- `screenshot-2.png` — Subscribers: admin list table with status filter, list filter, and bulk actions
- `screenshot-3.png` — Lists: manage mailing lists with subscriber counts
- `screenshot-4.png` — Fields: custom field definitions with type, required, and admin-visibility toggles
- `screenshot-5.png` — Settings: SMTP configuration, tracking toggle, compliance, anti-spam, and captcha settings
- `screenshot-6.png` — Submission Log: consent audit trail with searchable log entries
- `screenshot-7.png` — Import/Export: CSV/JSON import and export of subscriber data

## Naming conventions (WordPress.org requirements)

- **Filenames must be lowercase** — uppercase names won't work
- Banner: `banner-772x250.(jpg|png)` and `banner-1544x500.(jpg|png)`
- Icon: `icon-128x128.(png|jpg|gif)`, `icon-256x256.(png|jpg|gif)`, and/or `icon.svg`
- Screenshots: `screenshot-1.(png|jpg)`, `screenshot-2.(png|jpg)`, etc.
- Localized: append `-de`, `-es`, `-rtl`, etc. (e.g. `screenshot-1-de.png`)

## Size limits (WordPress.org)

- Banners: max 4 MB (smaller is better)
- Icons: max 1 MB (smaller is better)
- Screenshots: max 10 MB each (smaller is better)

## SVN MIME types

If images download instead of displaying when viewed on WordPress.org, set the
SVN MIME type properties:

```bash
svn propset svn:mime-type image/png *.png
svn propset svn:mime-type image/jpeg *.jpg
```
