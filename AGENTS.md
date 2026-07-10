# AGENTS.md

WordPress mailing-list plugin. PHP backend (`includes/`, PSR-4 `Stampy\`), TS/JS frontend (`src/`), built with `@wordpress/scripts`. Version frozen at `0.0.1` — never bump unless explicitly told.

## CRITICAL — Never commit to git

**The agent must NEVER run `git add`, `git commit`, `git push`, or any other git mutation command.** Only the user may commit or push changes. The agent may run read-only git commands (`git status`, `git diff`, `git log`, etc.) but must never stage, commit, or push. Violating this rule is a critical failure.

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
- **`WP_ENV_HOME=./.wp-env-home` is baked into every `env:*` npm script.** This keeps wp-env state project-local. The `.wp-env-home/` dir is gitignored — don't remove the env var from scripts. **In CI workflows**, any bare `npx wp-env run` command (outside an npm script) must also set `WP_ENV_HOME` — set it as a job-level `env:` or the command can't find the running containers.
- **Composer `phpunit` must be `vendor/bin/phpunit`.** A global phpunit v10 exists in the wp-env container and shadows the project's v9 if you use bare `phpunit`.
- **PHPUnit integration mode is keyed on `STAMPY_TEST_INTEGRATION=1`**, not on `WP_PHPUNIT__DIR` (which wp-phpunit's autoloader always sets). The composer `test:integration` script sets it automatically.
- **After adding PHP classes**, run `composer dump-autoload` in the container to regenerate the PSR-4 autoloader.
- **PHPCS `InterpolatedNotPrepared`** — table names in `$wpdb->prepare()` queries trigger this sniff. Use `phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared` ... `phpcs:enable` blocks around the query (NOT `// phpcs:ignore` on a separate line — that only covers the current line).
- **PHPCS `DisallowShortTernary`** — `$row ?: null` is forbidden. Use `null !== $row ? $row : null`.
- **PHPStan `wpdb::prepare()` expects `literal-string`** — any interpolated table name makes it `non-falsy-string`. These are false-positives; the baseline file suppresses them. After adding new repository methods with table interpolation, run `vendor/bin/phpstan analyse --memory-limit=512M --generate-baseline=phpstan-baseline.neon` to regenerate the baseline.
- **Husky pre-commit hook pipes through `cat`** so `process.stdout.isTTY` is false, which makes wp-env auto-add `-T` to docker-compose. Without this, git hooks run with a pseudo-TTY that confuses wp-env's TTY detection → "input device is not a TTY".

## Testing

- **Unit tests (PHP):** WP-free, Brain Monkey. `tests/phpunit/Unit/` — namespace `Stampy\Tests\Unit`.
- **Integration tests (PHP):** `WP_UnitTestCase` via wp-phpunit. `tests/phpunit/Integration/` — namespace `Stampy\Tests\Integration`. Run on the `tests-cli` container (not `cli`).
- **Unit tests (JS):** Jest via `wp-scripts`. `--testPathIgnorePatterns` excludes `tests/e2e/` (Playwright specs) and `.wp-env-home/` (WP core).
- **E2E:** Playwright, baseURL `:8889` (tests instance). No `webServer` block — must `npm run env:start` first.
- **Tests instance (:8889) has no theme after `env:clean:tests` or integration test runs** — E2E tests must use the REST API (`?rest_route=/`), not front-end HTML assertions. Pretty permalinks are also off; always use `?rest_route=/` instead of `/wp-json/`.
- **Test dirs are PSR-4 cased:** `Unit/` and `Integration/` (capitalized) under `tests/phpunit/`.

## Code style

- PHPCS: `WordPress-Extra` + `WordPress-Docs`, text domain `stampy`, prefixes `stampy`/`Stampy`. `includes/*` excluded from file-name sniff (PSR-4).
- PHPStan: level 8, scans `includes/`, `stampy.php`, `uninstall.php`. Uses `phpstan-baseline.neon` for `wpdb::prepare()` literal-string false-positives. `stubs/` dir has WP_CLI stub for PHPStan, excluded from PHPCS. `--memory-limit=512M` in the `composer analyse` script (default 128M is insufficient).
- TypeScript: strict mode, `types: ["jest"]`.
- **Do not add comments** unless explicitly asked.

## Architecture

- `stampy.php` — entry point, defines `Stampy\VERSION` and `Stampy\PLUGIN_FILE`, calls `bootstrap()`.
- `uninstall.php` — runs on plugin deletion (not deactivation). The plugin main file is NOT loaded, so `vendor/autoload.php` must be required manually. All variables must use `stampy_` prefix (global namespace → PHPCS `PrefixAllGlobals`). By default, all data (tables, options, cron) is removed on uninstall; `stampy_delete_data_on_uninstall` option defaults to `'1'` (on).
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

### CI gotchas

- **Bare `npx wp-env run` in CI needs `WP_ENV_HOME`.** The npm scripts set `WP_ENV_HOME=./.wp-env-home` inline, but any `npx wp-env run` command outside an npm script must set it too (job-level `env:`). Without it, wp-env can't find the containers and fails in ~1 second with "Environment not initialized".
- **Plugin Check action needs `vendor/` on the host.** The `wordpress/plugin-check-action@v1` mounts the plugin directory from the host into its own wp-env. Since `vendor/` is gitignored, the autoloader is missing and the plugin fatals on activation (`Class "Stampy\Lifecycle" not found`). Fix: run `shivammathur/setup-php@v2` + `composer install --no-dev --optimize-autoloader` before the plugin-check action.
- **Plugin Check excludes dev files.** The repo root contains dev-only files (tests, config, CI) that are not shipped in the production build. Use `exclude-directories`, `exclude-files`, and `ignore-warnings: 'true'` inputs on the plugin-check-action to exclude them. A more robust long-term fix would be to build the plugin first and run Plugin Check on the built artifact.

## Dependency management

- **`--legacy-peer-deps` required for npm install.** `@wordpress/scripts` wants `@wordpress/env@^10` as a peerOptional, but we use `@wordpress/env@11` (v11 is needed for Node 24 compatibility). This is a known false conflict. Always use `npm install --legacy-peer-deps`.
- **zod override in `package.json`.** `@wordpress/scripts@32` ships `eslint-plugin-react-hooks@7` which needs `zod@4`, but transitive deps dedupe to `zod@3.23.8` → ESLint 10 crashes on `zod/v4/core` (subpath not exported). The `"overrides": { "zod": "^4.4.3" }` entry forces zod 4 everywhere. Do not remove it.
- **TypeScript 7 is incompatible with `@typescript-eslint`.** `@typescript-eslint@8.63.0` (bundled in `@wordpress/scripts@32`) has peer dep `typescript: >=4.8.4 <6.1.0`. TS 7 crashes with `Cannot read properties of undefined (reading 'Cjs')`. TS 6.0.3 is the maximum working version. Do not bump TypeScript beyond `^6.0.3` until `@typescript-eslint` publishes a version supporting TS 7.
- **Never run `npm audit fix --force`.** It corrupts `package.json` by downgrading packages to resolve transitive vulnerabilities (e.g. downgraded `@wordpress/scripts` from `^32` to `^19.2.4`). If `package.json` gets corrupted, recover with `rm -rf node_modules package-lock.json && npm cache clean --force && npm install --legacy-peer-deps`.
- **After changing Composer deps**, run `composer install` or `composer update <package>` inside the container: `WP_ENV_HOME=./.wp-env-home npx wp-env run cli --env-cwd=wp-content/plugins/stampy composer update <package>`.
