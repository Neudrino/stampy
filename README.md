# Stampy 🦒

A WordPress mailing-list plugin: double opt-in signup, subscriber/list
management, a block-editor newsletter composer, generic SMTP delivery, and
open/click tracking.

> Status: pre-release, under active development (version `0.0.1`).

- **Requires:** WordPress ≥ 7.0, PHP ≥ 8.3
- **License:** GPLv3 (SPDX identifier: `GPL-3.0-only`)
- **Repository:** <https://github.com/Neudrino/stampy>

This file is the developer-facing README. The WordPress.org listing lives in
`readme.txt` and is maintained separately.

## Plugin icon attribution

The giraffe icon (🦒) used in the plugin menu, banner, and directory listing is
derived from the [Noto Emoji](https://github.com/googlefonts/noto-emoji) project
by Google. The original SVG was downloaded from
<https://raw.githubusercontent.com/googlefonts/noto-emoji/main/svg/emoji_u1f992.svg>.
The Noto Emoji project is licensed under the SIL Open Font License, Version 1.1
(SPDX identifier: `OFL-1.1`) — see
<https://github.com/googlefonts/noto-emoji/blob/main/LICENSE> for the full
license text. A copy is also included in `.wordpress-org/LICENSE`.

## Development setup

Prerequisites:

- [Node.js 24](https://nodejs.org/) (see `.nvmrc`)
- A container runtime for [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
  (Docker, or Podman with a Docker-compatible socket)

Install dependencies:

```bash
npm install
composer install   # or run PHP tooling via the wp-env container (see below)
```

## Testing

All checks are defined as npm/Composer scripts and run identically locally and
in CI.

| Command | What it does |
|---|---|
| `npm run validate:fast` | Lint, type-check, and unit tests (JS + PHP). No browser/E2E. |
| `npm run validate` | Full suite: fast checks + PHP integration + E2E (needs containers). |
| `npm run lint:js` / `lint:css` / `type-check` | Individual JS/TS checks. |
| `npm run lint:php` / `analyse:php` / `test:unit:php` | Individual PHP checks. |
| `npm run test:integration:php` | PHP integration tests inside wp-env. |
| `npm run test:e2e` | Playwright E2E against the tests instance. |

> Note: on hosts without PHP/Composer installed, the PHP checks are configured
> to run inside the wp-env container.

## Manual testing

Stampy is manually verifiable at every phase on a persistent development
WordPress instance, independent of the automated tests.

```bash
npm run dev:start                       # start wp-env + Mailpit + JS watch build
npm run wp -- stampy seed --subscribers=50   # seed demo data (later phases)
```

- **Development site:** <http://localhost:8888> (login `admin` / `password`) —
  persistent; automated tests never touch it.
- **Manual inbox (Mailpit):** <http://localhost:8025>
- **Tests site:** <http://localhost:8889> — reset any time with
  `npm run env:clean:tests` (does not affect the development site).
- **Tests inbox (Mailpit):** <http://localhost:8026> — used by E2E only.

To reset only the automated-test instance:

```bash
npm run env:clean:tests
```

To tear everything down:

```bash
npm run env:destroy
```

## Release & Deploy

Stampy uses a two-workflow release pipeline. The build workflow produces a
validated, release-ready zip on tag push. The deploy workflow pushes to the
WordPress.org SVN repository on manual dispatch.

### Build & Release workflow (`build-release.yml`)

Triggered by pushing a semantic version tag (e.g. `git tag 1.0.0 && git push origin 1.0.0`).

1. Version consistency check (git tag == `stampy.php` Version == `readme.txt` Stable tag)
2. `npm ci && npm run build` (compile JS/CSS)
3. `composer install --no-dev --optimize-autoloader` (production deps)
4. Stage to clean dir via `.distignore` (excludes dev files, tests, config)
5. Verify staged artifact structure (`stampy.php` at root, `vendor/` and `build/` present)
6. **Plugin Check** on the staged artifact (`wordpress/plugin-check-action@v1` with `categories=plugin_repo`)
7. Create zip: `stampy-<version>.zip`
8. Create GitHub Release with the zip attached

### Deploy workflow (`deploy-wporg.yml`)

Triggered manually via `workflow_dispatch` (GitHub Actions → "Run workflow" →
enter the tag, e.g. `1.0.0`). Runs in a protected `wporg-deploy` environment
with required reviewers.

1. Checkout at the specified tag
2. Build + composer install (same as build workflow)
3. Deploy to WordPress.org SVN via `10up/action-wordpress-plugin-deploy@stable`
   - `SLUG=stampy`, `ASSETS_DIR=.wordpress-org`
   - SVN credentials from environment secrets: `SVN_USERNAME`, `SVN_PASSWORD`

### First-time submission (one-time, before the deploy workflow can run)

Before the deploy workflow can push to SVN, the plugin must be manually
submitted to WordPress.org for review:

1. **Bump version** from `0.0.1` to `1.0.0` (or the first release version):
   - `stampy.php` header `Version:` field
   - `stampy.php` `VERSION` constant
   - `readme.txt` `Stable tag:` field
2. **Run full validation:** `npm run validate` (includes E2E + integration tests)
3. **Push a `1.0.0` tag** → triggers `build-release.yml` → produces the zip
4. **Download the zip** artifact from the GitHub Release
5. **Submit at** <https://wordpress.org/plugins/developers/add/> with a brief
   description and the zip upload
6. **Wait for review** (1–10 business days). The review team checks for:
   - Escaping/sanitization of all output and input
   - Nonces on all form processing
   - GPL compatibility
   - No code obfuscation, no external executable code loading
   - No tracking without consent
   - No bundling of WP-bundled libraries (jQuery, PHPMailer, etc.)
7. **After approval:** you'll receive an email with SVN access details
8. **Set up GitHub secrets** in the `wporg-deploy` environment:
   - `SVN_USERNAME` — your WordPress.org username (case-sensitive)
   - `SVN_PASSWORD` — your SVN-specific password (set at
     <https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password>)
9. **Run the deploy workflow** to push to SVN for the first time

### Plugin assets (`.wordpress-org/` directory)

Screenshots, banner, and plugin icon live in `.wordpress-org/` and are deployed
to the SVN `assets/` directory. See `.wordpress-org/README.md` for naming
conventions and file requirements.

### Plugin Check

The official `wordpress/plugin-check` tool (the same tool the WP.org review team
uses) is integrated at three levels:

1. **CI** (`ci.yml`) — runs on every PR/push against the repo root with dev files excluded
2. **Release pipeline** (`build-release.yml`) — runs on the staged production artifact
3. **E2E test** (`tests/e2e/plugin-check.spec.ts`) — runs `wp plugin check` inside wp-env

All three must report 0 errors before a release.
