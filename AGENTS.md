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
- **PHPStan `wpdb::prepare()` expects `literal-string`** — any interpolated table name makes it `non-falsy-string`. These are false-positives; the baseline file suppresses them. After adding new repository methods with table interpolation, run `vendor/bin/phpstan analyse --memory-limit=1G --generate-baseline=phpstan-baseline.neon` to regenerate the baseline.
- **Husky pre-commit hook** uses `set -o pipefail` and pipes through `cat` (so `process.stdout.isTTY` is false, which makes wp-env auto-add `-T` to docker-compose). The hook auto-starts wp-env if the `cli` container is not running, then runs `npm run validate:fast`. A failing check (JS or PHP) blocks the commit.
- **PSR-4 subdirectory namespaces**: Classes in `includes/Email/`, `includes/SpamGuards/`, `includes/Validators/`, `includes/Rest/` must use the corresponding sub-namespace (`Stampy\Email`, `Stampy\SpamGuards`, etc.) or Composer's autoloader silently skips them. After adding new subdirectories, run `composer dump-autoload` and watch for "does not comply with psr-4" warnings.
- **`wp_mail` capture in integration tests**: The test bootstrap has a `wp_mail` filter (`stampy_test_capture_mail`) that captures all sent emails into `$GLOBALS['phpmailer_mock_sent']`. Each entry is `['to' => ..., 'subject' => ..., 'body' => ..., 'headers' => ...]`. Reset with `unset( $GLOBALS['phpmailer_mock_sent'] )` in `setUp()`/`tearDown()`.
- **PHPCS multi-line `@param` with `{`**: WordPress-style structured docblocks (`@param array<mixed> $request { ... }`) trigger `Squiz.Commenting.FunctionComment.ParamCommentFullStop`. Use a plain `@param` description instead.
- **WP 7.0 changed autoload option value from `'no'` to `'off'`** — integration tests asserting non-autoloaded option values must use `assertContains($val, ['no', 'off'])` for cross-version compat.
- **PHPMailer property names are not snake_case** — `$phpmailer->Host`, `$Port`, `$SMTPSecure`, `$SMTPAuth`, `$Username`, `$Password` trigger `WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase`. Wrap in `phpcs:disable` ... `phpcs:enable` blocks.
- **`hex2bin()` returns `string|false`** — PHPStan flags it. Must check for `false` and provide a fallback before calling `substr()`.
- **`base64_encode`/`base64_decode` trigger PHPCS warnings** (`WordPress.PHP.DiscouragedPHPFunctions.obfuscation_*`) — wrap in `phpcs:disable`/`phpcs:enable` blocks with a benign-reason comment.
- **`wp_encrypt_password()` is one-way (bcrypt)** — cannot be used for reversible SMTP password encryption. Use libsodium `crypto_secretbox` instead, keyed from the per-site HMAC secret.
- **Playwright admin pages with multiple forms**: use `getByRole('button', { name: '...' })` instead of `input[type="submit"]` selectors. WP admin JS can make buttons "not stable"; `click({ force: true })` may be needed. `submit_button()` accepts a third parameter for the button's `name`/`id` attribute: `submit_button(__('Label', 'stampy'), 'primary', 'my-button-id')`.
- **Dev mu-plugin `wp_mail_from` filter must yield**: the dev mu-plugin's `wp_mail_from` filter at `PHP_INT_MAX` overrides the plugin's own From-email setting. Must check `get_option('stampy_smtp_configured')` and return the input unchanged when the plugin's SMTP is configured.
- **Mailpit requires TLS for SMTP auth by default** — set `MP_SMTP_AUTH_ALLOW_INSECURE: "true"` env var on the Mailpit container to allow plaintext auth without TLS (needed for local dev/testing without TLS certificates).
- **Dev mu-plugin must include auth credentials for tests Mailpit** — the tests Mailpit (port 1026) requires auth (`stampy:testpass123`). The dev mu-plugin's `phpmailer_init` hook must set `SMTPAuth=true`, `Username`, and `Password` when `STAMPY_DEV_SMTP_PORT` is 1026, otherwise all `wp_mail()` calls in the tests instance fail silently.
- **Do not force-disable auth when encryption is `none`** — auth should be independently togglable; forcing it off when encryption=none prevents testing SMTP auth without TLS.
- **Playwright checkbox on WP admin forms**: `page.check()` can silently fail to register on WP admin checkboxes. Use `page.evaluate()` to set `checkbox.checked = true` directly, then assert with `toBeChecked()` before submitting the form.
- **E2E tests that modify shared state must be serial**: tests that write to the same WP options (e.g. SMTP settings) must use `test.describe.serial()` to prevent parallel workers from overwriting each other's configuration.
- **Mailpit STARTTLS requires cert/key files**: set `MP_SMTP_TLS_CERT` and `MP_SMTP_TLS_KEY` env vars (not `MP_SMTP_CERT_FILE`/`MP_SMTP_KEY_FILE`) pointing at PEM files mounted into the container.
- **PHPMailer self-signed cert rejection**: PHPMailer rejects self-signed TLS certificates by default. Set `SMTPOptions` with `ssl => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]`. The plugin exposes a `stampy_smtp_options` filter for dev/test overrides; production uses the default (secure) behavior.
- **Dev mu-plugin must also set SMTPOptions**: the dev mu-plugin's `phpmailer_init` hook configures PHPMailer directly (not through the plugin's transport), so the `stampy_smtp_options` filter is not applied. The dev mu-plugin must set `SMTPOptions` explicitly when TLS is used.
- **Dev certs are gitignored**: self-signed TLS certificates under `dev/certs/` are generated locally and must not be committed. The `.gitignore` excludes `/dev/certs/`.

## Testing

- **Unit tests (PHP):** WP-free, Brain Monkey. `tests/phpunit/Unit/` — namespace `Stampy\Tests\Unit`.
- **Integration tests (PHP):** `WP_UnitTestCase` via wp-phpunit. `tests/phpunit/Integration/` — namespace `Stampy\Tests\Integration`. Run on the `tests-cli` container (not `cli`).
- **Unit tests (JS):** Jest via `wp-scripts`. Custom `jest.config.js` extends `@wordpress/jest-preset-default`, adds `moduleNameMapper` for WordPress package mocks (`tests/jest/mocks/`), uses `@wordpress/scripts/config/babel-transform` for TS/JSX. Setup file `tests/jest/setup.js` adds `TextEncoder`/`TextDecoder` (needed by `react-dom/server` in jsdom) and the `window.stampy` global.
- **WordPress packages not installed on host**: `@wordpress/blocks`, `@wordpress/components`, `@wordpress/i18n`, `@wordpress/api-fetch`, `@wordpress/block-editor` are WordPress externals (provided at runtime via `DependencyExtractionWebpackPlugin`). They're listed as `peerDependencies` in `package.json` but not actually installed. Jest mocks them via `moduleNameMapper` → `tests/jest/mocks/`. TypeScript type declarations for `@wordpress/api-fetch` are in `types/api-fetch.d.ts`.
- **E2E:** Playwright, baseURL `:8889` (tests instance). No `webServer` block — must `npm run env:start` first. `globalSetup` (`tests/e2e/global-setup.ts`) activates the Stampy plugin on the tests instance and seeds a list via `wp stampy seed`.
- **Tests instance (:8889) has Stampy inactive by default** — the Playwright `globalSetup` must run `wp plugin activate stampy` before tests.
- **Tests instance (:8889) has no theme after `env:clean:tests` or integration test runs** — E2E tests must use the REST API (`?rest_route=/`), not front-end HTML assertions. Pretty permalinks are also off; always use `?rest_route=/` instead of `/wp-json/`.
- **Test dirs are PSR-4 cased:** `Unit/` and `Integration/` (capitalized) under `tests/phpunit/`.
- **`dbDelta()` implicitly commits the test transaction.** The first `Installer::install()` call in a test run uses `CREATE TABLE IF NOT EXISTS` (DDL), which causes an implicit MySQL commit. Data created in `setUp()` or test methods on the first test run persists. Fix: use `find_by_slug()` before `create()` in `setUp()` to avoid duplicate key errors; don't create fixtures in `setUp()` that would pollute other test classes.
- **`check_admin_referer()` reads from `$_REQUEST`, not `$_POST`.** In the CLI test context, setting `$_POST` alone is insufficient — `$_REQUEST` is empty. Tests must set both: `$_POST = ...; $_REQUEST = $_POST;` and clean up in `tearDown()`: `unset( $_POST, $_REQUEST );`.
- **`wp_safe_redirect()` calls `exit` after the redirect.** In tests, this terminates the process. Fix: add a `wp_redirect` filter that throws a `RuntimeException`, then catch it in the test: `add_filter('wp_redirect', fn(): never => throw new \RuntimeException('redirect'), 1)`.
- **`WP_List_Table` subclasses in a namespace need `use stdClass;`.** PHPStan resolves `stdClass` to `Stampy\Admin\stdClass` without the import. Also, `column_default()` parameter types must be `$item` (untyped) and `$column_name` (untyped/mixed) to match the parent — PHPStan flags contravariance violations otherwise.
- **PHPCS `OneObjectStructurePerFile`** — `WP_List_Table` subclasses must be in their own files, not bundled with page renderers.
- **E2E admin login helper**: Use `waitForSelector('#wpadminbar', { timeout: 15000 })` after clicking the login submit button — NOT `waitForLoadState('networkidle')`. The admin bar (`#wpadminbar`) appears on all WP admin pages and reliably indicates a successful login. `networkidle` races on fast runs and subsequent `page.goto()` calls to admin pages can land on the still-rendering login page.

## Code style

- PHPCS: `WordPress-Extra` + `WordPress-Docs`, text domain `stampy`, prefixes `stampy`/`Stampy`. `includes/*` excluded from file-name sniff (PSR-4).
- PHPStan: level 8, scans `includes/`, `stampy.php`, `uninstall.php`. Uses `phpstan-baseline.neon` for `wpdb::prepare()` literal-string false-positives. `stubs/` dir has WP_CLI stub for PHPStan, excluded from PHPCS. `--memory-limit=1G` in the `composer analyse` script (512M was insufficient after adding `SignupBlock.php`).
- TypeScript: strict mode, `types: ["jest", "node"]`.
- **Do not add comments** unless explicitly asked.

## Architecture

- `stampy.php` — entry point, defines `Stampy\VERSION` and `Stampy\PLUGIN_FILE`, calls `bootstrap()`.
- `uninstall.php` — runs on plugin deletion (not deactivation). The plugin main file is NOT loaded, so `vendor/autoload.php` must be required manually. All variables must use `stampy_` prefix (global namespace → PHPCS `PrefixAllGlobals`). By default, all data (tables, options, cron) is removed on uninstall; `stampy_delete_data_on_uninstall` option defaults to `'1'` (on).
- `includes/` — PSR-4 classes (`Stampy\` namespace).
  - `SpamGuards/` — spam-guard chain (interface, honeypot, rate-limit, chain orchestrator).
  - `Validators/` — field-type validator registry (interface, email/text/acceptance validators, singleton registry).
  - `Rest/` — REST API controllers (signup, confirm, unsubscribe, preferences).
  - `Email/` — confirmation email service.
  - `Admin/` — admin menu, subscribers list table + detail view, lists list table + CRUD, SMTP settings page + test-send. List creation redirects to list overview (not edit view) after save.
  - `Smtp/` — SMTP settings storage (non-autoloaded, libsodium-encrypted passwords) + transport (phpmailer_init + wp_mail_from hooks + `stampy_smtp_options` filter for dev/test SSL overrides).
  - `Security.php` — token generation, SHA-256 hashing, HMAC signing/verification.
  - `SignupService.php` — core opt-in business logic (signup pipeline + confirm pipeline).
  - `Rewrites.php` — virtual endpoint rewrite rules + HTML page rendering.
  - `SignupBlock.php` — server-side registration and rendering for the Stampy Signup block.
- `src/` — TypeScript/JS (block editor, frontend).
  - `blocks/signup/` — Stampy Signup block (block.json, edit.tsx, save.ts, view.ts, edit.test.tsx).
- `dev/` — dev-only Mailpit docker-compose (dual instances with auth + STARTTLS on tests), mu-plugin mailer (yields to plugin SMTP, sets auth creds for tests Mailpit, sets SMTPOptions for self-signed certs), startup script, self-signed TLS certs (`dev/certs/`, gitignored).
- `.wp-env.json` — WP 7.0, PHP 8.3, dual instance (dev `:8888`, tests `:8889`), per-instance Mailpit (dev `:8025` no auth, tests `:8026` auth+STARTTLS with self-signed cert).
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
- **E2E job needs `composer install` in the container.** The `globalSetup` activates the plugin and creates a list via `wp eval`. Without `vendor/`, activation fatals silently and `STAMPY_E2E_LIST_ID` is never set → tests fail with "STAMPY_E2E_LIST_ID not set". Fix: add a `npx wp-env run tests-cli --env-cwd=... composer install` step before `npm run test:e2e` (with `WP_ENV_HOME` env set).
- **E2E `globalSetup` errors must be loud.** If plugin activation or list creation fails, the setup should throw, not silently continue. Silent failures produce confusing test failures (e.g. `result.success` is `undefined` because the REST route doesn't exist).
- **Plugin Check excludes dev files.** The repo root contains dev-only files (tests, config, CI) that are not shipped in the production build. Use `exclude-directories`, `exclude-files`, and `ignore-warnings: 'true'` inputs on the plugin-check-action to exclude them. A more robust long-term fix would be to build the plugin first and run Plugin Check on the built artifact.

## Dependency management

- **`--legacy-peer-deps` required for npm install.** `@wordpress/scripts` wants `@wordpress/env@^10` as a peerOptional, but we use `@wordpress/env@11` (v11 is needed for Node 24 compatibility). This is a known false conflict. Always use `npm install --legacy-peer-deps`.
- **zod override in `package.json`.** `@wordpress/scripts@32` ships `eslint-plugin-react-hooks@7` which needs `zod@4`, but transitive deps dedupe to `zod@3.23.8` → ESLint 10 crashes on `zod/v4/core` (subpath not exported). The `"overrides": { "zod": "^4.4.3" }` entry forces zod 4 everywhere. Do not remove it.
- **TypeScript 7 is incompatible with `@typescript-eslint`.** `@typescript-eslint@8.63.0` (bundled in `@wordpress/scripts@32`) has peer dep `typescript: >=4.8.4 <6.1.0`. TS 7 crashes with `Cannot read properties of undefined (reading 'Cjs')`. TS 6.0.3 is the maximum working version. Do not bump TypeScript beyond `^6.0.3` until `@typescript-eslint` publishes a version supporting TS 7.
- **Never run `npm audit fix --force`.** It corrupts `package.json` by downgrading packages to resolve transitive vulnerabilities (e.g. downgraded `@wordpress/scripts` from `^32` to `^19.2.4`). If `package.json` gets corrupted, recover with `rm -rf node_modules package-lock.json && npm cache clean --force && npm install --legacy-peer-deps`.
- **After changing Composer deps**, run `composer install` or `composer update <package>` inside the container: `WP_ENV_HOME=./.wp-env-home npx wp-env run cli --env-cwd=wp-content/plugins/stampy composer update <package>`.
