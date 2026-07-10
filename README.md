# Stampy 🦒

A WordPress mailing-list plugin: double opt-in signup, subscriber/list
management, a block-editor newsletter composer, generic SMTP delivery, and
open/click tracking.

> Status: pre-release, under active development (version `0.0.1`).

- **Requires:** WordPress ≥ 7.0, PHP ≥ 8.3
- **License:** GPLv2 or later
- **Repository:** <https://github.com/Neudrino/stampy>

This file is the developer-facing README. The WordPress.org listing lives in
`readme.txt` and is maintained separately.

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
