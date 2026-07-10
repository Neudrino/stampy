# AGENTS.md

WordPress mailing-list plugin. PHP backend (`includes/`, PSR-4 `Stampy\`), TS/JS frontend (`src/`), built with `@wordpress/scripts`. Version frozen at `0.0.1` — never bump unless explicitly told.

## Essential commands

```bash
npm run validate:fast      # lint:js, lint:css, type-check, test:unit:js, lint:php, analyse:php, test:unit:php
npm run env:start          # start wp-env (both WP instances + Mailpit)
npm run env:stop           # stop containers
npm run test:integration:php  # integration tests (needs env:start first)
npm run test:e2e           # Playwright (needs env:start first)
npm run validate           # full: env:start → validate:fast → validate:docker
```

## Critical gotchas

- **Host has no PHP/Composer.** All PHP commands run inside the wp-env container via `wp-env run cli`. Never call `php`, `composer`, or `phpunit` directly on the host.
- **`@wordpress/env` must be v11+.** v10 hangs on Node 24 (got@11 download-stream `pipeline` never resolves). v11 works.
- **`WP_ENV_HOME=./.wp-env-home` is baked into every `env:*` npm script.** This keeps wp-env state project-local. The `.wp-env-home/` dir is gitignored — don't remove the env var from scripts.
- **Composer `phpunit` must be `vendor/bin/phpunit`.** A global phpunit v10 exists in the wp-env container and shadows the project's v9 if you use bare `phpunit`.
- **PHPUnit integration mode is keyed on `STAMPY_TEST_INTEGRATION=1`**, not on `WP_PHPUNIT__DIR` (which wp-phpunit's autoloader always sets). The composer `test:integration` script sets it automatically.
- **After adding/changing Composer deps**, run `composer install` inside the container:
  `npx wp-env run cli --env-cwd=wp-content/plugins/stampy composer install`
- **After adding PHP classes**, run `composer dump-autoload` in the container to regenerate the PSR-4 autoloader.
- **Husky pre-commit hook pipes through `cat`** so `process.stdout.isTTY` is false, which makes wp-env auto-add `-T` to docker-compose. Without this, git hooks run with a pseudo-TTY that confuses wp-env's TTY detection → "input device is not a TTY".

## Testing

- **Unit tests (PHP):** WP-free, Brain Monkey. `tests/phpunit/Unit/` — namespace `Stampy\Tests\Unit`.
- **Integration tests (PHP):** `WP_UnitTestCase` via wp-phpunit. `tests/phpunit/Integration/` — namespace `Stampy\Tests\Integration`. Run on the `tests-cli` container (not `cli`).
- **Unit tests (JS):** Jest via `wp-scripts`. `--testPathIgnorePatterns` excludes `tests/e2e/` (Playwright specs) and `.wp-env-home/` (WP core).
- **E2E:** Playwright, baseURL `:8889` (tests instance). No `webServer` block — must `npm run env:start` first.
- **Test dirs are PSR-4 cased:** `Unit/` and `Integration/` (capitalized) under `tests/phpunit/`.

## Code style

- PHPCS: `WordPress-Extra` + `WordPress-Docs`, text domain `stampy`, prefixes `stampy`/`Stampy`. `includes/*` excluded from file-name sniff (PSR-4).
- PHPStan: level 8, scans `includes/`, `stampy.php`, `uninstall.php`.
- TypeScript: strict mode, `types: ["jest"]`.
- **Do not add comments** unless explicitly asked.

## Architecture

- `stampy.php` — entry point, defines `Stampy\VERSION` and `Stampy\PLUGIN_FILE`, calls `bootstrap()`.
- `includes/` — PSR-4 classes (`Stampy\` namespace).
- `src/` — TypeScript/JS (block editor, frontend).
- `dev/` — dev-only Mailpit docker-compose, mu-plugin mailer, startup script.
- `.wp-env.json` — WP 7.0, PHP 8.3, dual instance (dev `:8888`, tests `:8889`), per-instance Mailpit (`:8025`/`:8026`).
- `PLAN.md` — full phased implementation plan. `PROGRESS.md` — phase tracking and environment state.

## Implementation phase workflow

When proceeding with an implementation phase, **always**:

1. **Run all available tests at the end and ensure they pass.** This means `npm run validate:fast` at minimum, plus `npm run test:integration:php` and `npm run test:e2e` if the phase touches container-dependent code. Do not declare a phase done with failing tests.
2. **Update `PROGRESS.md`** with findings, resolved issues, and the current state of the phase. Record any gotchas discovered along the way so future sessions don't re-derive them.
3. **Update `AGENTS.md`** with working solutions discovered during the phase — correct commands to call tools, start environments, run checks, or anything else that took trial and error to figure out. Keep the file a living document.
4. **Stop at the end of the phase and offer a manual testing step.** Describe:
   - How to start the manual test (which commands to run, which URL to open).
   - What needs to be tested and how to test it.
   - What result to expect (success criteria).

Do not skip the manual testing offer or automatically move to the next phase without it.

## CI

GitHub Actions (`.github/workflows/ci.yml`) runs: lint (JS/TS/CSS), unit-js, unit-php (8.3 + 8.4 matrix), integration (WP 7.0 + latest matrix), e2e, build, plugin-check, audit. All use the same npm/composer scripts. Node version pinned via `.nvmrc`.
