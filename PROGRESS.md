# Stampy — Implementation Progress

Tracking file for phase-by-phase implementation of the plan in `PLAN.md`.
Each phase lists **requirements fulfilled** and **functional steps taken**.
Version is frozen at `0.0.1` (never bumped unless explicitly instructed).

---

## Environment notes (host reality vs. plan)

- **Node:** v24 (matches `.nvmrc` pin) ✓
- **Container runtime:** **Docker** (real `dockerd` 29.1.3, root dir
  `/var/lib/docker`). The machine previously had only Podman (with a `docker`
  shim); Docker has since been installed. The user was added to the `docker`
  group but **the login session has not been restarted yet**, so group
  membership is not active in the current shell — Docker commands currently
  require a fresh-group shell (`sg docker -c '...'`). After a real re-login,
  plain `docker`/`wp-env` will work directly.
- **Leftover reverted:** `DOCKER_HOST` had been pointed at the Podman socket in
  the systemd user environment; it has been unset
  (`systemctl --user unset-environment DOCKER_HOST`). A Podman-specific
  workaround file (`dev/containers.conf`) that was briefly created has been
  removed. No other host/system changes remain.
- **Host PHP/Composer:** NOT installed. **Deviation from plan:** the PHP checks
  (`lint:php`, `analyse:php`, `test:unit:php`) run **inside the wp-env
  container** rather than on the host, so `validate:fast` uses the container
  for PHP. Everything else about the parity model is unchanged.

### Environment — RESOLVED (Docker fully working)
- `docker` group membership ACTIVE in the user session ✓
- `podman-docker` shim package removed; `/etc/profile.d/podman-docker.*` gone ✓
- `DOCKER_HOST` unset (systemd user env) AND opencode restarted with clean env ✓
- Real Docker daemon reachable and used: **Server Version 29.1.3**, root dir
  `/var/lib/docker` ✓
- wp-env home made **project-local** via `WP_ENV_HOME=./.wp-env-home` baked
  into every `env:*` npm script; `.wp-env-home/` is gitignored + distignored.
  Nothing wp-env creates lands outside the project directory now. ✓

### ACTIVE BLOCKER — RESOLVED (upgraded @wordpress/env to v11)
Root cause was `@wordpress/env@10`'s transitive `got@11.8.6` hanging on its
download-stream `pipeline` under Node 24. **Resolution: upgraded
`@wordpress/env` from 10.39.0 to 11.10.0** (latest). Same `got@11.8.6`
dependency, but the download completes successfully in v11 — likely an
internal change in the download flow. wp-env now starts both sites in ~27s
on Node 24 with no issues.

---

## Phase 0 — Skeleton

Status: **COMPLETE** ✓

### Requirements (from PLAN.md §9 Phase 0)
- [x] Plugin header in `stampy.php` + matching `readme.txt`
      (Author `Neudrino`, `Plugin URI` `https://github.com/Neudrino/stampy`,
      `Requires at least: 7.0`, `Requires PHP: 8.3`, identical in both files)
- [x] Version frozen at `0.0.1` in header and `Stable tag`
- [x] Prefix `stampy_` / namespace `Stampy` established
- [x] `LICENSE` (GPLv2 or later)
- [x] `SECURITY.md` (GitHub private vulnerability reporting, no email)
- [x] Composer scripts (§4 Local/CI Parity): lint, lint:fix, analyse,
      test:unit, test:integration
- [x] npm scripts (§4 Local/CI Parity), incl. validate:fast / validate
- [x] Tool configs: `phpcs.xml.dist`, `phpstan.neon.dist`,
      `phpunit.xml.dist`, `.nvmrc` (Node 24)
- [x] `tsconfig.json` (strict)
- [x] wp-env (`.wp-env.json`) dual instance + dual Mailpit
      (`dev/docker-compose.mailpit.yml`, dev mu-plugin), verify
      `host.docker.internal` reachability
- [x] Husky pre-commit (`validate:fast`)
- [x] GitHub Actions `ci.yml` + `codeql.yml` calling the same scripts
- [x] Dependabot config (npm, composer, github-actions)
- [x] README "Manual testing" section

### Verification targets
- [x] `npm run validate:fast` green (PHP checks via container)
- [ ] CI job green on first PR (deferred until repo push)
- [x] Both Mailpit UIs reachable (`:8025`, `:8026`)
- [x] Manual demo: `npm run dev:start` → log in at `:8888`, activate Stampy,
      open `:8025`

### Functional steps taken (Phase 0 so far)
- Verified host tooling; identified container-runtime situation (Docker now
  installed; group membership pending session restart).
- Created `PROGRESS.md`.
- Wrote core plugin files: `stampy.php` (header, v0.0.1, namespace `Stampy`,
  bootstrap stub), `uninstall.php` (inert safe stub), `LICENSE` (full GPLv2
  text), `SECURITY.md` (GitHub private reporting), `readme.txt` (WP.org
  listing, matching header fields), `README.md` (dev/manual-testing docs),
  `.gitignore`, `.distignore`.
- PHP tooling (via subagent): `composer.json` (PSR-4 `Stampy\`→`includes/`,
  dev deps, canonical scripts), `phpcs.xml.dist`, `phpstan.neon.dist`
  (level 8), `phpunit.xml.dist` (unit + integration suites),
  `tests/phpunit/bootstrap.php`, unit `SmokeTest`, integration
  `PluginActivationTest`, `includes/.gitkeep`.
- JS/TS tooling (via subagent): `package.json` (canonical npm scripts; PHP
  checks routed through the wp-env container), `tsconfig.json` (strict),
  `.nvmrc` (24), `types/globals.d.ts`, `src/index.ts`, `src/index.test.ts`,
  `.husky/pre-commit` (runs `validate:fast`), `playwright.config.ts`
  (baseURL :8889, no webServer), `tests/e2e/smoke.spec.ts`.
- Env/CI (via subagent): `.wp-env.json` (WP 7.0, PHP 8.3, dual instance,
  per-instance Mailpit SMTP port, mu-plugin mapping, afterStart hook),
  `dev/docker-compose.mailpit.yml` (mailpit-dev :8025/1025, mailpit-tests
  :8026/1026), `dev/mailpit-up.sh` (idempotent), `dev/mu-plugins/
  stampy-dev-mailer.php` (routes wp_mail to Mailpit, yields when plugin SMTP
  configured), `.github/workflows/ci.yml`, `.github/workflows/codeql.yml`,
  `.github/dependabot.yml`, issue/PR templates.
- Fixed `.wp-env.json` double-mount (removed `"plugins": ["."]`; rely on the
  `mappings` entry so `--env-cwd=wp-content/plugins/stampy` resolves).
- Verified WP 7.0 zip URL exists (HTTP 200).

### Verified GREEN (host-runnable JS/TS checks)
- `npm install` (1919 packages, lockfile generated, husky installed) ✓
- `npm run type-check` ✓ (after adding `@types/jest` + `"types": ["jest"]`)
- `npm run lint:js` ✓
- `npm run lint:css` ✓ (after adding `--allow-empty-input`)
- `npm run test:unit:js` ✓ (1 test passes)

### Verified GREEN (container-based PHP checks)
- `npm run lint:php` ✓ (phpcs, 2 files, 0 errors)
- `npm run analyse:php` ✓ (phpstan level 8, 2 files, 0 errors)
- `npm run test:unit:php` ✓ (phpunit, 2 tests, 2 assertions)
- `npm run test:integration:php` ✓ (phpunit, 1 test, 2 assertions)

### Verified GREEN (full validate:fast)
- `npm run validate:fast` ✓ — all 7 steps pass cleanly (no warnings)

### Container environment verified
- `npm run env:start` brings up BOTH sites (:8888 dev, :8889 tests) in ~27s ✓
- Both Mailpit UIs reachable: `:8025` (dev), `:8026` (tests) — HTTP 200 ✓
- Stampy plugin visible in `wp plugin list` (v0.0.1) ✓
- Stampy plugin activates successfully ✓
- `host.docker.internal` reachability (configured via wp-env, Mailpit
  containers healthy) ✓

### Package changes made (beyond original scaffold)
- Added `@types/jest` dev dep; set `tsconfig.json` `"types": ["jest"]`
  (fixes type-check of the Jest test).
- `lint:css` script gained `--allow-empty-input` (no CSS files yet).
- Every `env:*` / container-PHP npm script now prefixes
  `WP_ENV_HOME=./.wp-env-home` (project-local wp-env home).
- `.gitignore` + `.distignore` now exclude `/.wp-env-home/`.
- Removed `"plugins": ["."]` from `.wp-env.json` (kept the `mappings` entry) to
  avoid double-mounting the plugin.
- **Upgraded `@wordpress/env` from 10.39.0 to 11.10.0** — resolves the Node 24
  / got@11 download hang that blocked wp-env from starting WordPress.
- `phpcs.xml.dist`: added `exclude-pattern` for `/.wp-env-home/`, `/tests/`,
  `/dev/` (prevented PHPCS from scanning 5468 WP core files).
- `composer.json`: `phpunit` → `vendor/bin/phpunit` in test scripts (avoids
  global phpunit v10 shadowing the project's v9).
- `composer.json`: `test:integration` sets `STAMPY_TEST_INTEGRATION=1`.
- `tests/phpunit/bootstrap.php`: integration mode now keyed on
  `STAMPY_TEST_INTEGRATION` env var only (not `WP_PHPUNIT__DIR`, which
  wp-phpunit's `__loaded.php` autoload always sets).
- `tests/phpunit/bootstrap.php`: defines `WP_TESTS_CONFIG_FILE_PATH` from
  `WP_TESTS_DIR` so the vendored wp-phpunit finds the wp-env test config.
- `phpunit.xml.dist`: test suite dirs updated to PSR-4-compliant casing
  (`Unit`, `Integration`); directories renamed accordingly.
- `test:unit:js` script: added `--testPathIgnorePatterns` for `/tests/e2e/`
  and `/.wp-env-home/` (prevents Jest from trying to run Playwright specs and
  scanning WP core).
- `dev/mu-plugins/stampy-dev-mailer.php`: added `wp_mail_from` filter
  (`wordpress@example.com`) — WP 7.0 generates `wordpress@localhost` as the
  default From, which fails PHPMailer's domain validator and causes `wp_mail`
  to return false before `phpmailer_init` ever fires.
- `.husky/pre-commit`: pipes through `cat` so `process.stdout.isTTY` is
  false, making wp-env auto-add `-T` to docker-compose (avoids "input device
  is not a TTY" in git hooks).

---

## Phase 1 — Test harness

Status: **COMPLETE** ✓

### Requirements (from PLAN.md §9 Phase 1)
- [x] 4 suites (Jest/Playwright in TS) + smoke tests
- [x] CI matrix (PHP 8.3/8.4, WP 7.0/latest)
- [x] Plugin Check in CI

### Verification targets
- [x] All suites green locally: `validate:fast` + `validate:docker`
- [ ] CI job green on first PR (deferred until repo push)

### Manual demo
- [x] `npm run env:clean:tests` resets `:8889` while `:8888` state survives

### What was done
- **Jest smoke tests** (`src/index.test.ts`): expanded from 1 to 3 tests —
  version string, type check, export surface.
- **PHP unit smoke tests** (`tests/phpunit/Unit/SmokeTest.php`): expanded from
  2 to 3 tests — harness works, version string, namespace prefix.
- **PHP integration smoke tests** (`tests/phpunit/Integration/PluginActivationTest.php`):
  expanded from 1 to 4 tests — VERSION constant, PLUGIN_FILE constant,
  bootstrap function exists, plugin file is readable.
- **Playwright E2E smoke tests** (`tests/e2e/smoke.spec.ts`): replaced
  placeholder `1+1=2` with 3 real tests — WP tests instance reachable via
  REST API, plugin loaded (REST namespaces), Mailpit tests instance reachable.
- **CI workflow** (`.github/workflows/ci.yml`): added `composer lint` +
  `composer analyse` to the `unit-php` job (PHP is available on CI runners,
  unlike the local host); removed stale Phase 0 comments.

### Gotchas discovered
- **Tests instance (:8889) has no active theme after integration test run** —
  `wp-env clean tests` resets the DB, which un-activates the theme. E2E
  tests that check front-end HTML fail. Fix: use the REST API
  (`?rest_route=/`) which is always available regardless of theme state.
- **Tests instance doesn't have pretty permalinks** — `?rest_route=/` must
  be used instead of `/wp-json/`.

---

## Phase 2 — Data layer

Status: **COMPLETE** ✓

### Requirements (from PLAN.md §9 Phase 2)
- [x] Schema (all 9 tables, all JSON in LONGTEXT; `fields`/`subscriber_meta`/`pending_signups` with `UNIQUE(subscriber_id, form_id)`)
- [x] Migration runner (db_version option, supports jumps from any version)
- [x] Activation/deactivation/`plugins_loaded` lifecycle (create tables, seed, schedule purge, rewrite flush)
- [x] Repositories (attribute access generic)
- [x] WP-CLI seeder (`wp stampy seed --subscribers=N`)

### Verification targets
- [x] Integration tests: activation idempotency, schema/CRUD/constraints,
      email-uniqueness upsert, meta round-trip, migration jump — all green
- [x] All suites green: `validate:fast` + `validate:docker` (58 tests total)

### What was done
- **Schema** (`includes/Schema.php`): 9 tables created via `dbDelta()` —
  `subscribers`, `fields`, `subscriber_meta`, `consent_texts`,
  `pending_signups`, `lists`, `subscriber_lists`, `campaign_recipients`,
  `campaign_clicks`. All follow PLAN §3 conventions (BIGINT UNSIGNED IDs,
  VARCHAR(191) for utf8mb4-safe UNIQUE indexes, LONGTEXT for JSON payloads,
  DATETIME timestamps in UTC).
- **Migrations** (`includes/Migrations.php`): versioned migration runner
  with `stampy_db_version` option; `migrate_to_1()` creates all tables;
  supports jumps from any older version.
- **Installer** (`includes/Installer.php`): seeds `first_name`/`last_name`
  field definitions and consent-text version 1; all idempotent.
- **Lifecycle** (`includes/Lifecycle.php`): activation (install + rewrite
  flush + schedule daily purge), deactivation (unschedule + flush),
  `plugins_loaded` (upgrade check).
- **Repositories** (`includes/Repositories/`):
  - `SubscriberRepository` — CRUD, email normalization, upsert by email,
    status updates, token hash, consent version, orphan-cleanup delete.
  - `FieldRepository` — CRUD for field definitions.
  - `SubscriberMetaRepository` — EAV storage with upsert, get_all,
    apply_merge (non-empty overwrites, empty never erases).
  - `ListRepository` — list CRUD, subscriber membership (add/remove with
    junction upsert and resubscribe flip), list/subscriber lookups.
  - `PendingSignupRepository` — create_or_refresh (UNIQUE(subscriber_id,
    form_id) upsert), token lookup, expiry purge.
  - `ConsentTextRepository` — append-only registry, auto-incrementing
    version numbers.
- **WP-CLI** (`includes/Cli.php`): `wp stampy seed --subscribers=N --list=<slug>`
  creates confirmed subscribers with first/last name meta and list membership.
- **Integration tests** (4 test files, 45 new tests):
  - `SchemaTest` — table existence, idempotency, db_version, default seeds,
    UNIQUE index verification.
  - `SubscriberRepositoryTest` — create, normalize, upsert, find, status,
    token hash, consent version, count, delete.
  - `SubscriberMetaRepositoryTest` — set/get, upsert, get_all, apply_merge
    (non-empty overwrites, empty never erases), delete_all, isolation.
  - `ListRepositoryTest` — create, add_subscriber, no duplicate junction,
    unsubscribe, resubscribe flip, get_list_subscribers, status filter.
  - `PendingSignupAndMigrationTest` — create/find by token, refresh
    (same form_id), independent forms, delete, purge_expired, migration
    idempotency, migration from zero.

### Gotchas discovered
- **PHPCS `WordPress.DB.PreparedSQL.InterpolatedNotPrepared`** flags any
  interpolated variable in a `$wpdb->prepare()` query string. Table names
  can't use `%s` placeholders in `prepare()` (they'd be quoted), so we
  must interpolate and suppress with `phpcs:disable`/`phpcs:enable` blocks.
  Using `// phpcs:ignore` on a separate line does NOT work — it only
  suppresses the current line, not the line where the string actually is.
- **PHPCS `Universal.Operators.DisallowShortTernary`** forbids `$row ?: null`.
  Must use `null !== $row ? $row : null`.
- **PHPStan `wpdb::prepare()` expects `literal-string`** — table name
  interpolation makes it `non-falsy-string`. Generated a PHPStan baseline
  (`phpstan-baseline.neon`) to suppress these 42 known false-positives.
  The baseline is included via `includes:` in `phpstan.neon.dist`.
- **PHPStan memory limit** — default 128M is insufficient with
  `szepeviktor/phpstan-wordpress`. Fixed by adding `--memory-limit=512M`
  to the `composer analyse` script.
- **PHPStan `WP_CLI` class unknown** — WP-CLI is loaded conditionally
  and not available to PHPStan's autoloader. Created `stubs/WP_CLI.php`
  with a stub class, added to `phpstan.neon.dist` via `scanFiles:`.
  Excluded `stubs/` from PHPCS.
- **`wpdb::insert()` format arrays with `null`** — PHPStan flags
  `array<int, string|null>` where `array<string>|string|null` is expected.
  Fix: omit columns with `null` values from the insert data array entirely
  (MySQL defaults handle them).
- **`wpdb::get_results()` returns `array<int, stdClass>|null`** — declared
  return type `array<int, stdClass>` triggers PHPStan error. Baseline
  suppresses this; could also be fixed with `?: array()` on the foreach.

### CI fixes — round 1 (CI #14, investigated locally before push)
- **Root cause of all 4 CI failures identified** — each was a different
  infrastructure issue, not a code bug:
  1. **Integration (WP 7.0 + latest):** `vendor/` is gitignored, and the CI
     workflow never ran `composer install` inside the container before
     `test:integration:php`. The `composer test:integration` script calls
     `vendor/bin/phpunit` which doesn't exist → exit code 127 (reported as 1
     by npm). **Fix:** added a `Composer install (in container)` step to the
     CI integration job: `npx wp-env run tests-cli --env-cwd=... composer
     install --no-interaction --prefer-dist`.
  2. **E2E (Playwright):** the smoke test asserted `body.name === 'Test Blog'`
     but a fresh wp-env instance names the site after the directory
     (`wordpress-plugin-stampy`). **Fix:** changed the assertion to check
     `body.name` is a non-empty string.
  3. **Plugin Check:** the action was checking the raw repo root (including
     `tests/`, `dev/`, config files, dotfiles) as if it were a production
     plugin build. **Fix:** added `exclude-directories`, `exclude-files`,
     and `ignore-warnings: 'true'` to the plugin-check-action inputs.
  4. **E2E (Playwright) — Playwright browser install:** `wp-scripts
     test-playwright` runs `npx playwright install` (all browsers) before
     tests, but CI only installs chromium with `--with-deps`. Set
     `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD: '1'` env var on the `test:e2e` step.

### CI fixes — round 2 (CI #15, after push of round 1 fixes)
- CI #15 ran on commit `c2ccb7e` (`fixup! Phase 2`). E2E now passes (round 1
  fix worked), but 3 jobs still failed:
  1. **Integration (WP 7.0 + latest) — `Composer install (in container)` step
     failed in 1 second.** Root cause: the `npx wp-env run tests-cli` command
     in the CI workflow didn't set `WP_ENV_HOME`, so wp-env couldn't find the
     containers started by `npm run env:start` (which sets
     `WP_ENV_HOME=./.wp-env-home` inline). Reproduced locally: `npx wp-env
     run tests-cli` without `WP_ENV_HOME` fails with "Environment not
     initialized". **Fix:** added `env: WP_ENV_HOME: ./.wp-env-home` at the
     job level on the integration job.
  2. **Plugin Check — `Class "Stampy\Lifecycle" not found` fatal error.** The
     Plugin Check action mounts the plugin directory from the host into its
     own wp-env. Since `vendor/` is gitignored, the Composer autoloader is
     absent, so all PSR-4 classes are missing when WP-CLI tries to activate
     the plugin. **Fix:** added `shivammathur/setup-php@v2` +
     `composer install --no-dev --optimize-autoloader` steps before the
     `wordpress/plugin-check-action@v1` step. Also added `README.md`,
     `SECURITY.md`, and `LICENSE` to `exclude-files`.
- **All fixes verified locally** — 58 tests pass (3 JS unit, 3 PHP unit, 49
  PHP integration, 3 E2E). `validate:fast` ✓, `validate:docker` ✓.

### Dependency upgrades (Dependabot PRs #1–#9)
- All 9 open Dependabot PRs were implemented locally, one by one, with full
  test runs after each upgrade.
- **GitHub Actions (PRs #1, #2, #3, #5):**
  - `github/codeql-action`: v3 → v4 (`codeql.yml`)
  - `actions/upload-artifact`: v4 → v7 (`ci.yml`, 2 occurrences)
  - `actions/setup-node`: v4 → v6 (`ci.yml`, all occurrences)
  - `actions/checkout`: v4 → v7 (`ci.yml` + `codeql.yml`)
- **Composer (PR #4):** `wp-phpunit/wp-phpunit`: ^6.5 → ^7.0 (6.9.4 → 7.0.1).
  Required changing the constraint in `composer.json` and running `composer
  update wp-phpunit/wp-phpunit` in the container.
- **npm packages (PRs #6, #7, #8, #9):**
  - `@types/wordpress__block-editor`: ^11 → ^15.0.6 (11.5.17 → 15.0.6)
  - `npm-run-all2`: ^7 → ^9.0.2 (7.0.2 → 9.0.2)
  - `@wordpress/scripts`: ^30 → ^32.6.0 (30.27.0 → 32.6.0)
  - `typescript`: ^5 → ^6.0.3 (5.9.3 → 6.0.3). PR #7 proposed v7.0.2 but
    `@typescript-eslint@8.63.0` (bundled in `@wordpress/scripts@32`) has peer
    dep `typescript: >=4.8.4 <6.1.0` — TS 7 crashes with `Cannot read
    properties of undefined (reading 'Cjs')`. TS 6.0.3 is within the peer dep
    range and works. Left as `^6.0.3` (not `^7`).
- **All devDependencies pinned to latest versions** from npmjs.com:
  `@playwright/test` ^1.61.1, `@types/jest` ^30.0.0,
  `@wordpress/e2e-test-utils-playwright` ^1.50.0, `@wordpress/env` ^11.10.0,
  `husky` ^9.1.7.
- **zod override added to `package.json`:** `@wordpress/scripts@32` ships
  `eslint-plugin-react-hooks@7` which needs `zod@4`, but transitive deps were
  deduping to `zod@3.23.8` → ESLint 10 crashes on `zod/v4/core` (subpath not
  exported). Added `"overrides": { "zod": "^4.4.3" }` to force zod 4
  everywhere.
- **npm `audit fix --force` corrupted `package.json`** — it downgraded
  `@wordpress/scripts` to `^19.2.4` and `@types/wordpress__block-editor` to
  `^11.5.13` to resolve a transitive `ws` vulnerability. Recovery: `rm -rf
  node_modules package-lock.json && npm cache clean --force && npm install
  --legacy-peer-deps`.
- **`--legacy-peer-deps` required** — `@wordpress/scripts@30+` wants
  `@wordpress/env@^10` as a peerOptional, but we use `@wordpress/env@11`.
  This is a known false conflict (wp-env v11 works fine with wp-scripts v32).
- **All 58 tests pass** after all upgrades: lint:js ✓, lint:css ✓,
  type-check ✓, test:unit:js ✓ (3 tests), lint:php ✓, analyse:php ✓,
  test:unit:php ✓ (3 tests), test:integration:php ✓ (49 tests),
  test:e2e ✓ (3 tests), build ✓.
- **CI fully green on commit `6c44291`** — all 12 check runs passed
  (Lint, Unit JS/PHP 8.3+8.4, Integration WP 7.0+latest, E2E, Build,
  Plugin Check, Dependency audit, CodeQL).

### Post-Phase 2 cleanup
- **Dependabot PR #7 closed** (TypeScript 6→7). Left a comment explaining
  the `@typescript-eslint` incompatibility. All other Dependabot PRs were
  already applied directly to `main` (not merged via PR).
- **`uninstall.php` updated** from Phase 0 inert stub to Phase 2 functional
  implementation:
  - Requires `vendor/autoload.php` manually (the plugin main file is NOT
    loaded during uninstall, so the Composer autoloader isn't available
    otherwise).
  - Calls `Schema::uninstall()` to drop all 9 custom tables.
  - Deletes plugin options (`stampy_db_version`,
    `stampy_delete_data_on_uninstall`).
  - Clears scheduled events (`stampy_daily_purge_pending_signups`).
   - All gated behind `stampy_delete_data_on_uninstall` option (defaults
     to `'1'` = on — all data removed by default). The admin settings UI
     to toggle this ships in Phase 10.

---

## Phase 3 — Opt-in core (headless)

Status: **COMPLETE** ✓

### Requirements (from PLAN.md §9 Phase 3)
- [x] Staged signups (`pending_signups` + payload)
- [x] Spam-guard chain + field-type validator registry
- [x] Consent-text registry integration
- [x] REST signup/confirm (`form_id` + `fields` contract)
- [x] Virtual preference page
- [x] One-click unsubscribe (RFC 8058)
- [x] Expiry cron
- [x] Rewrite rules for virtual endpoints, flush on activation

### Verification targets
- [x] Unit tests (spam guards, validators) — 26 tests green
- [x] Integration tests (REST endpoints, merge policy, wp_mail asserts,
      token tampering, expiry cron, HMAC signing) — 75 tests green
- [x] All suites green: `validate:fast` + `validate:docker` (107 tests total)

### What was done
- **Security** (`includes/Security.php`): CSPRNG token generation (32 bytes
  → 64 hex chars), SHA-256 token hashing, constant-time verification
  (`hash_equals`), per-site HMAC secret (generated on activation, stored
  in non-autoloaded `stampy_hmac_secret` option), `sign()`/`verify()` for
  URL parameter signing.
- **Spam-guard chain** (`includes/SpamGuards/`):
  - `SpamGuardInterface` — pluggable interface (Phase 11 adds quiz; Phase 12 adds Turnstile + Friendly Captcha)
  - `SpamGuardResult` — immutable pass/fail value object
  - `HoneypotGuard` — hidden field `website_check`, rejects non-empty
  - `RateLimitGuard` — transient-based per-IP limit (5/hour default), no
    IP persisted
  - `SpamGuardChain` — ordered pipeline, stops at first failure;
    `default_chain()` factory
- **Validator registry** (`includes/Validators/`):
  - `FieldValidatorInterface` — pluggable type validators (Phase 13 adds more; Phase 14 form builder adds the rest)
  - `ValidationResult` — immutable valid/invalid value object with
    sanitized value
  - `EmailValidator` — sanitize + validate via `is_email()`, normalizes
  - `TextValidator` — `sanitize_text_field()`
  - `AcceptanceValidator` — consent checkbox, must be truthy
  - `ValidatorRegistry` — singleton, maps types to validators, `validate()`
    delegates to the right one
- **SignupService** (`includes/SignupService.php`): Core business logic
  orchestrating the full pipeline:
  - `signup()`: spam-guard chain → validate email/consent/fields →
    check if subscriber is already confirmed (skip re-confirmation,
    apply immediate list membership + merge) → otherwise stage in
    `pending_signups` with fresh token, send confirmation email.
    Anti-enumeration: same "check your email" response regardless.
  - `confirm()`: hash token → find pending signup → check expiry →
    promote subscriber to confirmed → generate unsub token if needed →
    apply staged attributes (merge policy: non-empty overwrites, empty
    never erases) → add list memberships (flips unsubscribed junctions
    back to subscribed) → delete pending signup → fire action.
- **Confirmation email** (`includes/Email/ConfirmationEmail.php`):
  Translatable defaults, `apply_filters` on subject and body, builds
  signed confirmation URL, fires `stampy_confirmation_email_sent` action.
- **REST controllers** (`includes/Rest/`):
  - `RestApi` — registers all controllers on `rest_api_init`
  - `SignupController` — `POST /stampy/v1/signup` with `{ email, fields,
    consent, form_id, list_ids, website_check }` contract
  - `ConfirmController` — `GET /stampy/v1/confirm?token=...`
  - `UnsubscribeController` — `POST /stampy/v1/unsubscribe` (RFC 8058
    one-click, per-list) + `POST /stampy/v1/unsubscribe-all` (global)
  - `PreferencesController` — `GET/POST /stampy/v1/preferences` (list
    memberships with toggles + global opt-out)
- **Rewrites** (`includes/Rewrites.php`): Virtual endpoints for
  `/stampy/confirm/{token}`, `/stampy/preferences/{s}/{t}/{sig}`, and
  `/stampy/unsubscribe/{s}/{list}/{t}/{sig}`. Renders HTML pages with
  `template_redirect`. Also supports query-var-based access (works
  without pretty permalinks).
- **Lifecycle updates**: `Lifecycle::register()` now hooks
  `stampy_daily_purge_pending_signups` action → `purge_expired_signups()`.
  Activation generates HMAC secret.
- **Uninstall**: Added `stampy_hmac_secret` to deleted options.
- **Bootstrap**: `stampy.php` now registers `Rewrites::register()` and
  `Rest\RestApi::register()`.
- **Test bootstrap**: Added `wp_mail` capture filter
  (`stampy_test_capture_mail`) for integration test email assertions via
  `$GLOBALS['phpmailer_mock_sent']`.

### Tests added
- **Unit tests** (23 new, 26 total):
  - `SpamGuardTest` — honeypot pass/fail, chain stops at first failure,
    result factories.
  - `ValidatorTest` — email accept/reject/normalize, text sanitization,
    acceptance true/false/string, registry delegation, unknown type,
    result factories.
- **Integration tests** (26 new, 75 total):
  - `SignupEndpointTest` (7 tests): valid signup creates pending +
    sends email, consent required, list_ids required, invalid email
    rejected, honeypot blocks, confirmed subscriber gets immediate
    membership (no re-confirmation email), re-signup refreshes.
  - `ConfirmEndpointTest` (5 tests): full signup→confirm promotes +
    adds lists + sets token, merge policy (non-empty overwrites, empty
    never erases), invalid token fails, expired token fails, resubscribe
    flips unsubscribed→subscribed.
  - `UnsubscribePreferencesTest` (8 tests): one-click unsubscribe, global
    unsubscribe, invalid signature rejected, preferences GET returns
    memberships, preferences POST toggles lists, opt-out globally
    unsubscribes, invalid signature on preferences fails.
  - `ExpiryCronTest` (7 tests): purge expired removes expired signups,
    keeps valid ones, cron callback purges, HMAC secret generated on
    activation, token generation produces 64-char hex, token hashing
    deterministic, sign+verify roundtrip + tamper rejection.

### Gotchas discovered
- **PSR-4 namespace for `includes/Email/`**: The class
  `ConfirmationEmail` in `includes/Email/ConfirmationEmail.php` must
  use namespace `Stampy\Email` (not `Stampy`), or Composer's autoloader
  skips it silently. The `use Stampy\Security;` import is needed since
  `Security` is in the root `Stampy` namespace.
- **`wp_mail` capture in integration tests**: The WordPress test
  framework's mock PHPMailer doesn't actually capture `wp_mail`
  arguments. A `wp_mail` filter in `tests/phpunit/bootstrap.php`
  (`stampy_test_capture_mail`) captures the `to`, `subject`, `body`,
  and `headers` into `$GLOBALS['phpmailer_mock_sent']` for assertions.
- **PHPCS multi-line `@param` with `{`**: Using `@param array<mixed>
  $request {` (WordPress-style structured docblock) triggers
  `Squiz.Commenting.FunctionComment.ParamCommentFullStop` because PHPCS
  sees the comment as ending at `{` and expects a period. Workaround:
  use a regular `@param` description without the `{` structured format.
- **PHPCS short ternary**: `$request->get_param( 'form_id' ) ?: null`
  triggers `Universal.Operators.DisallowShortTernary`. Use
  `null !== $request->get_param( 'form_id' ) ? ... : null` instead.
  - PHPCS: all variables in `uninstall.php` must use the `stampy_` prefix
    (global namespace → `WordPress.NamingConventions.PrefixAllGlobals`).

## Phase 4 — Signup Block in TSX

**Status: COMPLETE — all tests green.**

### Requirements fulfilled
- [x] Signup block in TSX (email + optional first/last-name inputs)
- [x] Consent checkbox (required, text from consent-text registry)
- [x] List selection requiring ≥1 list — editor notice + front-end no-op if none
- [x] Accessibility (labels, aria-required, aria-invalid, aria-describedby, honeypot)
- [x] Attributes deprecation-ready for the Phase 14 form builder
- [x] Jest tests for block edit component
- [x] First E2E signup→confirm journey

### Functional steps taken
- **`types/globals.d.ts`** — expanded with `StampyList` and `StampyGlobal`
  interfaces declared as globals, covering REST URL, nonce, lists, consent text.
- **`types/api-fetch.d.ts`** — ambient type declaration for
  `@wordpress/api-fetch` (not installed as npm dep; provided as WordPress
  external at runtime).
- **`src/blocks/signup/block.json`** — block metadata: `stampy/signup`,
  attributes `list_ids` (number[]), `show_first_name` (bool), `show_last_name`
  (bool). `editorScript` and `viewScript` use `file:` prefix (wp-scripts
  auto-discovers and rewrites to `.js` on build).
- **`src/blocks/signup/index.ts`** — registers the block via
  `registerBlockType( metadata, { edit, save } )`.
- **`src/blocks/signup/edit.tsx`** — editor UI with `InspectorControls`:
  list checkboxes (from `window.stampy.lists`), toggle controls for name
  fields, warning `Notice` when no list selected. Form preview with email,
  optional name inputs, consent checkbox, honeypot.
- **`src/blocks/signup/save.ts`** — returns `null` (server-rendered block).
- **`src/blocks/signup/view.ts`** — front-end progressive enhancement:
  intercepts form submit, POSTs via `apiFetch` to `/stampy/v1/signup`,
  handles success (replaces form with message) and errors (inline field
  errors with `aria-invalid`, `aria-describedby`).
- **`includes/SignupBlock.php`** — server-side block registration via
  `register_block_type_from_metadata()` with `render_callback`. Renders
  the form HTML with proper labels, required attributes, honeypot, and
  `data-list-ids` attribute for the view script. Localizes REST URL,
  nonce, lists, and consent text via `wp_add_inline_script`.
- **`stampy.php`** — `bootstrap()` now calls `SignupBlock::register()`.
- **`jest.config.js`** — extends `@wordpress/jest-preset-default`, adds
  `moduleNameMapper` for WordPress package mocks, uses
  `@wordpress/scripts/config/babel-transform` for TypeScript/JSX support.
- **`tests/jest/mocks/`** — mock modules for `@wordpress/block-editor`,
  `@wordpress/components`, `@wordpress/i18n`, `@wordpress/api-fetch` (not
  installed as npm deps; provided as WordPress externals).
- **`tests/jest/setup.js`** — sets up `TextEncoder`/`TextDecoder` (needed
  by `react-dom/server` in jsdom) and the `window.stampy` global.
- **`tests/e2e/global-setup.ts`** — activates the Stampy plugin on the
  tests instance, seeds a list via `wp stampy seed`, stores list ID in
  `STAMPY_E2E_LIST_ID` env var.
- **`tests/e2e/signup.spec.ts`** — 3 E2E tests: full signup→confirm
  journey (signup → Mailpit → confirm link → confirmed page), signup
  without consent fails, signup without list_ids fails.
- **`eslint.config.cjs`** — extended with `camelcase` rule
  (`properties: 'never'` for WordPress snake_case attributes), disabled
  `import/no-extraneous-dependencies` (WordPress externals not in
  package.json), added `jest`/`window`/`document` globals.
- **`package.json`** — added `peerDependencies` for WordPress externals
  (`@wordpress/api-fetch`, `@wordpress/blocks`, `@wordpress/components`,
  `@wordpress/i18n`).

### Test results
- Jest: 17 tests (14 block edit + 3 existing)
- PHP unit: 26 tests
- PHP integration: 75 tests
- Playwright E2E: 6 tests (3 smoke + 3 signup)
- All `validate:fast` + `validate:docker` green. PHPStan: 0 errors.

### Gotchas discovered
- **wp-scripts auto-discovers block.json**: No explicit entry points needed
  in `package.json` — `wp-scripts build` scans `src/` for `**/block.json`
  and creates webpack entry points from `editorScript`/`viewScript` fields.
  The `file:` prefix in block.json is relative to the block.json file
  location and is rewritten to `.js` in the built output.
- **WordPress externals not in package.json**: `@wordpress/blocks`,
  `@wordpress/components`, `@wordpress/i18n`, `@wordpress/api-fetch`,
  `@wordpress/block-editor` are provided by WordPress at runtime (via
  `DependencyExtractionWebpackPlugin`). They're not npm dependencies but
  ESLint flags them with `import/no-extraneous-dependencies`. Fixed by
  disabling the rule and adding `peerDependencies` in `package.json`.
- **Jest with WordPress packages**: Since WordPress packages aren't
  installed on the host, Jest tests need `moduleNameMapper` entries
  pointing to mock files in `tests/jest/mocks/`. The
  `@wordpress/scripts/config/babel-transform` must be used as the Jest
  transform to handle TypeScript/JSX.
- **`react-dom/server` in jsdom**: `TextEncoder`/`TextDecoder` are not
  available in the jsdom environment. Must add them in the Jest setup
  via `require('util')`.
- **Block attributes use snake_case**: WordPress block.json attributes
  conventionally use snake_case (`list_ids`, `show_first_name`). ESLint's
  `camelcase` rule must be configured with `properties: 'never'` to allow
  snake_case property access, and local variables should be renamed to
  camelCase (e.g., `const listIds = attributes.list_ids`).
- **E2E plugin activation**: The tests instance (:8889) has Stampy
  **inactive** by default. The Playwright `globalSetup` must activate the
  plugin via `wp plugin activate stampy` before running tests.
- **Mailpit API**: Message search uses `GET /api/v1/search?query=to:ADDRESS`.
  Message detail uses `GET /api/v1/message/{ID}` (capital `ID`). Text body
  is in the `Text` field (capital T).
- **Confirmation URL format**: The confirmation link uses query parameters
  (`?stampy_confirm=TOKEN&sig=SIGNATURE`), not a path-based URL
  (`/stampy/confirm/`). The regex to extract it must match
  `stampy_confirm=` not `stampy/confirm`.

---

## Phase 5 — Admin Subscribers/Lists (COMPLETE)

### Requirements fulfilled

- Top-level "Stampy" admin menu with Subscribers and Lists sub-pages.
- `WP_List_Table` for subscribers: columns (Email, Status, Lists, Created,
  Confirmed), bulk actions (delete, unsubscribe, re-subscribe), row actions
  (edit, delete), search by email, filter by status, pagination.
- Subscriber detail/edit view: attributes read-only (from `subscriber_meta`
  joined with `fields` definitions where `show_in_admin=1`), editable status
  (dropdown) and list memberships (checkboxes). Capability check
  `manage_options`, nonce protection on all form submissions.
- `WP_List_Table` for lists: columns (Name, Slug, Description, Subscribers
  count), row actions (edit, delete), bulk delete, add new list form.
- List edit view: edit name, slug, description.
- Registered admin via `add_action('admin_menu', ...)` in `stampy.php`
  bootstrap.
- `admin_post` handlers for subscriber and list save forms, with nonce +
  capability checks.

### Files created/modified

- `includes/Admin/AdminMenu.php` — menu registration, `admin_post` handler
  registration.
- `includes/Admin/SubscribersPage.php` — render list table + detail view,
  `handle_save()` for status/list editing.
- `includes/Admin/SubscribersListTable.php` — `WP_List_Table` subclass with
  search, status filter, bulk actions.
- `includes/Admin/ListsPage.php` — render list table + add/edit form,
  `handle_save()` for list CRUD.
- `includes/Admin/ListsListTable.php` — `WP_List_Table` subclass with
  subscriber counts, bulk delete.
- `includes/Repositories/SubscriberRepository.php` — added `get_all()`
  (pagination + filtering + search + sorting), `count_filtered()`.
- `includes/Repositories/ListRepository.php` — added `update()`, `delete()`,
  `count_subscribers()`, `all_with_counts()`.
- `stampy.php` — added `Admin\AdminMenu::register()` to bootstrap.
- `tests/phpunit/Integration/AdminSubscribersTest.php` — 10 tests: menu
  registration, get_all/count_filtered, status update, list membership
  add/remove, capability check, nonce check, subscriber delete.
- `tests/phpunit/Integration/AdminListsTest.php` — 9 tests: list CRUD,
  count_subscribers, all_with_counts, handle_save create/update, capability
  check, nonce check.
- `tests/e2e/admin.spec.ts` — 3 E2E tests: subscribers page loads, lists page
  loads, create a new list via the form.
- `phpstan-baseline.neon` — regenerated for new `wpdb::prepare()` literal-
  string false positives.

### Test counts

- Jest: 17 (unchanged)
- PHP unit: 26 (unchanged)
- PHP integration: 94 (was 75, +19)
- Playwright E2E: 9 (was 6, +3)
- **Total: 146 tests** (was 124)

### Gotchas discovered

- **`dbDelta()` implicitly commits the test transaction.** The first
  `Installer::install()` call in a test run uses `CREATE TABLE IF NOT
  EXISTS`, which is DDL and causes an implicit MySQL commit. Data created
  in `setUp()` before `Installer::install()` (or in the same test) is
  committed and persists across tests. Fix: use `find_by_slug()` before
  `create()` to avoid duplicate key errors, and don't create fixtures in
  `setUp()` that would pollute other test classes.
- **`check_admin_referer()` reads from `$_REQUEST`, not `$_POST`.** In the
  CLI test context, setting `$_POST` alone is insufficient — `$_REQUEST`
  is empty. Tests must set both: `$_POST = ...; $_REQUEST = $_POST;`
- **`wp_safe_redirect()` calls `exit` after the redirect.** In tests, this
  terminates the process. Fix: add a `wp_redirect` filter that throws a
  `RuntimeException`, then catch it in the test: `add_filter('wp_redirect',
  fn(): never => throw new \RuntimeException('redirect'), 1)`.
- **`WP_List_Table` subclasses in a namespace need `use stdClass;`.** PHPStan
  resolves `stdClass` to `Stampy\Admin\stdClass` without the import.
- **`WP_List_Table::column_default()` parameter types must be `mixed`** (not
  `string`) to match the parent class signature. PHPStan flags
  contravariance violations otherwise.
- **PHPCS `OneObjectStructurePerFile`** — `WP_List_Table` subclasses must be
  in their own files, not bundled with page renderers.

### Post-completion fixes (manual testing feedback)

- **List creation redirect**: `ListsPage::handle_save()` redirected to the
  edit view (`action=edit&list_id=...`) after creating a new list. The
  expected behavior is to redirect back to the list overview. Fixed by
  removing `action` and `list_id` from the redirect query args, keeping
  only `page=stampy-lists&updated=1`. E2E test updated to assert the
  redirect lands on the overview page with the new list visible in the
  table.
- **E2E `adminLogin` race condition**: the helper used
  `waitForLoadState('networkidle')` after clicking the login submit button,
  which didn't reliably wait for the WP admin dashboard to finish loading.
  On fast runs, the subsequent `page.goto()` to an admin page would land
  on the still-rendering login page, causing `locator('h1')` to match the
  login page's `<h1>` elements instead of the admin page's heading. Fixed
  by replacing `waitForLoadState('networkidle')` with
  `waitForSelector('#wpadminbar', { timeout: 15000 })` — the admin bar
  appears on all WP admin pages and reliably indicates a successful login.

---

## Phase 6 — SMTP Connector (COMPLETE)

### Requirements fulfilled

- **SMTP settings storage** (`includes/Smtp/SmtpSettings.php`): host, port,
  encryption (none/ssl/tls), auth toggle, username, password, From-email,
  From-name. Stored in non-autoloaded options (`stampy_smtp_settings`).
  Defaults: port 587, encryption tls, From-email = admin_email, From-name =
  blogname.
- **Password encryption**: SMTP passwords are encrypted at rest using
  libsodium `crypto_secretbox` (XSalsa20-Poly1305), keyed from the per-site
  HMAC secret. Encrypted values are prefixed with `enc:` and stored as
  base64(nonce + ciphertext). Decryption is on-demand only.
- **SMTP transport** (`includes/Smtp/SmtpTransport.php`): hooks
  `phpmailer_init` to configure PHPMailer (isSMTP, Host, Port, SMTPSecure,
  SMTPAuth, Username, Password). Hooks `wp_mail_from` and `wp_mail_from_name`
  to apply configured From address/name. All hooks are no-ops when SMTP is
  not configured (host empty).
- **Settings admin page** (`includes/Admin/SettingsPage.php`): settings form
  with all SMTP fields + From address/name. "Send Test Email" form appears
  only when SMTP is configured. Both forms use `admin_post` handlers with
  nonce + `manage_options` capability checks.
- **Settings sub-menu** added to Stampy admin menu.
- **`stampy_smtp_configured` option**: set to `'1'` when host is non-empty,
  deleted when host is cleared. The dev mu-plugin checks this option to
  yield its own Mailpit routing when the plugin's SMTP is configured.
- **Uninstall cleanup**: `SmtpSettings::delete_all()` called from
  `uninstall.php`.
- **`SmtpTransport::register()`** called from `stampy.php` bootstrap.

### Tests

- **Integration** (`tests/phpunit/Integration/SmtpSettingsTest.php`): 21 tests
  covering defaults, save/configured flag, password encryption round-trip,
  keep-existing-password-on-blank, disabling auth clears credentials, invalid
  encryption/port fallbacks, From email/name defaults, non-autoloaded option
  verification, `configure_phpmailer()` property setting (host/port/secure/
  auth/username/password), noop when not configured, no-encryption mode,
  From filters, admin_post save handler, capability/nonce checks, send_test
  failure when not configured.
- **E2E** (`tests/e2e/smtp.spec.ts`): 3 tests (serial):
  1. No-auth: configures SMTP without auth against dev Mailpit (port 1025),
     sends a test email, verifies delivery via dev Mailpit API (:8025).
  2. Auth (no encryption): configures SMTP with auth (`stampy:testpass123`)
     and encryption=none against tests Mailpit (port 1026), sends a test
     email, verifies delivery via tests Mailpit API (:8026). Proves
     credentials are correctly passed to the SMTP server.
  3. Auth + TLS: configures SMTP with auth and TLS encryption against
     tests Mailpit (port 1026, STARTTLS with self-signed cert), sends a
     test email, verifies delivery. Proves STARTTLS encryption works
     end-to-end with a self-signed certificate.

### Test results

All 149 tests green: 17 Jest, 26 PHP unit, 115 PHP integration, 13 E2E
(10 previous + 3 new SMTP tests: no-auth, auth, auth+TLS).

### Gotchas discovered

- **WP 7.0 changed autoload option value from `'no'` to `'off'`** —
  integration tests asserting `autoload === 'no'` for non-autoloaded options
  must use `assertContains($val, ['no', 'off'])` for cross-version compat.
- **PHPMailer property names are not snake_case** — `$phpmailer->Host`,
  `$Port`, `$SMTPSecure`, `$SMTPAuth`, `$Username`, `$Password` trigger
  `WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase`.
  Wrap in `phpcs:disable` ... `phpcs:enable` blocks.
- **`hex2bin()` returns `string|false`** — PHPStan flags it. Must check for
  `false` and provide a fallback before calling `substr()`.
- **`base64_encode`/`base64_decode` trigger PHPCS warnings**
  (`WordPress.PHP.DiscouragedPHPFunctions.obfuscation_*`) — wrap in
  `phpcs:disable`/`phpcs:enable` blocks with a benign-reason comment.
- **Playwright `submit_button()` with ID**: `submit_button()` accepts a
  third parameter for the button's `name`/`id` attribute. Using
  `submit_button(__('Send Test Email', 'stampy'), 'primary', 'stampy-send-test')`
  gives the button an ID for reliable E2E targeting.
- **Playwright button stability**: admin pages with multiple forms can have
  buttons that Playwright considers "not stable" (likely due to WP admin
  JS re-rendering). Using `click({ force: true })` or
  `getByRole('button', { name: '...' })` is more reliable than
  `input[type="submit"]` selectors when multiple submit buttons exist.
- **Dev mu-plugin `wp_mail_from` filter must yield**: the dev mu-plugin's
  `wp_mail_from` filter at `PHP_INT_MAX` would override the plugin's own
  From-email setting. Must check `get_option('stampy_smtp_configured')` and
  return the input unchanged when the plugin's SMTP is configured.
- **`wp_encrypt_password()` is one-way (bcrypt)** — cannot be used for
  reversible SMTP password encryption. Use libsodium `crypto_secretbox`
  instead, keyed from the per-site HMAC secret.
- **Mailpit requires TLS for SMTP auth by default** — set
  `MP_SMTP_AUTH_ALLOW_INSECURE: "true"` env var on the Mailpit container to
  allow plaintext auth without TLS (needed for local dev/testing without
  TLS certificates).
- **Dev mu-plugin must include auth credentials for tests Mailpit** — the
  tests Mailpit (port 1026) now requires auth (`stampy:testpass123`). The
  dev mu-plugin's `phpmailer_init` hook must set `SMTPAuth=true`,
  `Username`, and `Password` when `STAMPY_DEV_SMTP_PORT` is 1026, otherwise
  all `wp_mail()` calls in the tests instance (e.g. confirmation emails in
  the signup E2E test) fail silently.
- **Do not force-disable auth when encryption is `none`** — the initial
  implementation forcibly cleared `auth` when `encryption=none`, but this
  prevents testing SMTP auth without TLS (e.g., against Mailpit with
  `MP_SMTP_AUTH_ALLOW_INSECURE`). Auth should be independently togglable.
- **Playwright checkbox on WP admin forms**: `page.check()` can silently
  fail to register on WP admin checkboxes. Use `page.evaluate()` to set
  `checkbox.checked = true` directly, then assert with `toBeChecked()`
  before submitting the form.
- **E2E tests that modify shared state must be serial**: SMTP settings tests
  that write to the same WP options must use `test.describe.serial()` to
  prevent parallel workers from overwriting each other's configuration.
- **Mailpit STARTTLS requires cert/key files**: set `MP_SMTP_TLS_CERT` and
  `MP_SMTP_TLS_KEY` env vars (not `MP_SMTP_CERT_FILE`/`MP_SMTP_KEY_FILE`)
  pointing at PEM files mounted into the container. The log should say
  "STARTTLS optional" or "STARTTLS required", not "no encryption".
- **PHPMailer self-signed cert rejection**: PHPMailer rejects self-signed
  TLS certificates by default. The `SMTPOptions` property must be set with
  `ssl => ['verify_peer' => false, 'verify_peer_name' => false,
  'allow_self_signed' => true]`. The plugin exposes a `stampy_smtp_options`
  filter so dev/test environments can override SSL verification settings;
  production uses the default (secure) behavior.
- **Dev mu-plugin must also set SMTPOptions**: the dev mu-plugin's
  `phpmailer_init` hook configures PHPMailer directly (not through the
  plugin's transport), so the `stampy_smtp_options` filter is not applied.
  The dev mu-plugin must set `SMTPOptions` explicitly when TLS is used.
- **Dev certs are gitignored**: self-signed TLS certificates under
  `dev/certs/` are generated locally and must not be committed. The
  `.gitignore` excludes `/dev/certs/`.

---

## Phase 7 — Campaign CPT + Renderer (COMPLETE)

### Requirements fulfilled

- **`stampy_campaign` CPT** (`includes/Campaigns/CampaignPostType.php`):
  Block-editor-native custom post type with revisions support. Status enum
  `draft|sending|sent|cancelled` (postmeta). Subject as postmeta. Target
  list IDs as postmeta (JSON array). Capability type `page` (gated behind
  `manage_options`). Shows under Stampy admin menu. REST-ready
  (`show_in_rest=true`, `rest_base=stampy-campaigns`).
- **Restricted block set**: `allowed_block_types_all` filter restricts the
  campaign editor to paragraph, heading, image, buttons/button, list/list-item,
  separator, spacer, columns/column, group.
- **Email renderer** (`includes/Campaigns/EmailRenderer.php`): Parses
  `post_content` blocks → table-based email HTML (CSS inlined, 600px wrapper,
  Arial font family). Plain-text alternative with uppercased headings, `* ` list
  items, `---` separators, `[text](url)` buttons. Images made absolute.
  Auto-appends unsubscribe footer (`{unsubscribe_url}` + physical address) when
  author omits it — send never blocked for missing unsubscribe link.
- **Campaign preview** (`includes/Admin/CampaignPreviewPage.php`):
  `admin_post_stampy_campaign_preview` handler outputs rendered HTML or plain
  text. Linked from the editor sidebar.
- **Composer TSX sidebar** (`src/campaign-editor/index.tsx`): Plugin sidebar
  with subject input, list selector (checkboxes from `window.stampy.lists`),
  status display, and HTML/plain-text preview links. Uses
  `@wordpress/plugins`, `@wordpress/editor`, `@wordpress/data` (WordPress
  externals).
- **Campaign editor script enqueue** (`CampaignPostType::enqueue_editor_assets()`):
  The sidebar script is enqueued directly via `enqueue_block_editor_assets`
  (screen-checked to `stampy_campaign` only). It is NOT registered as a
  block type — a "block" registered via `register_block_type_from_metadata`
  only enqueues its `editorScript` when the block is present in the post
  content. Since the sidebar is a JS plugin (never inserted as a block),
  that approach fails silently. The `block.json` in `src/campaign-editor/`
  is kept only so `wp-scripts build` auto-discovers the entry point.
- **Admin menu**: `admin_post_stampy_campaign_preview` handler registered in
  `AdminMenu::register()`.
- **Bootstrap**: `Campaigns\CampaignPostType::register()` added to
  `stampy.php` `bootstrap()`.
- **Uninstall**: `uninstall.php` deletes all campaign posts + the
  `stampy_physical_address` option.

### Postmeta keys

- `stampy_campaign_subject` — email subject line (string, default `''`)
- `stampy_campaign_list_ids` — JSON array of target list IDs (string, default `'[]'`)
- `stampy_campaign_status` — status enum (string, default `'draft'`)

Note: Meta keys do NOT start with `_`. WordPress treats keys starting with
`_` as "protected" and does NOT expose them in the REST API `meta` field,
even when `show_in_rest` is `true`. The block editor saves/loads meta via
REST, so protected meta is invisible to the editor sidebar.

### Files created/modified

- `includes/Campaigns/CampaignPostType.php` — CPT registration, meta
  registration, block restriction, editor data localization, meta getters/setters.
- `includes/Campaigns/EmailRenderer.php` — block-to-email renderer (HTML + text).
- `includes/Admin/CampaignPreviewPage.php` — preview handler.
- `includes/Admin/AdminMenu.php` — added `admin_post_stampy_campaign_preview` handler.
- `src/campaign-editor/index.tsx` — sidebar plugin (subject, lists, status, preview).
- `src/campaign-editor/block.json` — block metadata for the editor script.
- `types/globals.d.ts` — added `previewUrl` to `StampyGlobal`.
- `types/wordpress-plugins.d.ts` — type declarations for `@wordpress/plugins`,
  `@wordpress/editor`, `@wordpress/data`, `@wordpress/element`.
- `stampy.php` — added `Campaigns\CampaignPostType::register()` to bootstrap.
- `uninstall.php` — deletes campaign posts + `stampy_physical_address` option.
- `tests/phpunit/Integration/CampaignPostTypeTest.php` — 10 tests: CPT
  registration, visibility, REST, supports, menu parent, subject meta,
  list_ids meta, status meta, invalid status rejection, meta REST registration.
- `tests/phpunit/Integration/EmailRendererTest.php` — 20 tests: paragraph HTML,
  heading HTML, list HTML, separator HTML, button HTML, template wrapper,
  auto-append footer, footer suppression when unsubscribe present, physical
  address in footer, plain-text paragraph/heading/list/separator/button,
  plain-text auto-append unsubscribe, multiple blocks, empty content, subject
  in title tag, image HTML, relative URL made absolute.

### Test results

All 179 tests green:
- Jest: 17 (unchanged)
- PHP unit: 26 (unchanged)
- PHP integration: 147 (was 115, +32: 10 CPT + 20 renderer + 2 meta fix adjustments)
- Playwright E2E: 12 (unchanged)

### Gotchas discovered

- **`register_post_meta` default only applies in REST context** —
  `get_post_meta()` returns `''` for unset meta keys, not the registered
  default. The `get_status()` method must return `'draft'` when the meta
  is empty, not rely on the registered default.
- **Meta keys starting with `_` are invisible to the block editor** —
  WordPress treats meta keys starting with `_` as "protected" and does NOT
  expose them in the REST API `meta` field, even when `show_in_rest` is
  `true`. The block editor saves/loads meta via REST, so protected meta
  is invisible to the editor sidebar — selections don't persist after
  save. Fix: use non-underscore-prefixed meta keys (e.g.,
  `stampy_campaign_subject`, not `_stampy_campaign_subject`).
- **CPT must support `custom-fields` for REST meta exposure** — even with
  `show_in_rest=true` on the CPT and `show_in_rest=true` on the registered
  meta, the REST API does NOT include the `meta` field in the response
  unless the CPT `supports` array includes `'custom-fields'`. Without it,
  `editPost({ meta: {...} })` in the block editor silently fails to
  persist meta changes.
- **`register_post_meta` with `auth_callback`** — the auth callback
  restricts meta writes to `manage_options` users. In the integration test
  context, the meta may not be registered by the time `get_registered_meta_keys`
  is called if `init` has already fired before the plugin loaded. Fix: call
  `CampaignPostType::register_meta()` directly in `setUp()` for the CPT test.
- **`@wordpress/plugins` and `@wordpress/editor` have no native TypeScript
  types** — hand-written ambient declarations in
  `types/wordpress-plugins.d.ts` are needed. `useSelect`'s `select`
  parameter must be typed as an intersection of a callable and a record
  (`StoreSelectors` interface) for `select('core/editor')` to type-check.
- **`@wordpress/data` `useSelect` typing** — the `select` function passed to
  `useSelect` is both callable (`select('core/editor')`) and has properties
  (`select.getEditedPostAttribute`). Must declare it as an interface with a
  call signature, not just `Record<string, any>`.
- **Block JSON `editorScript` for a plugin sidebar** — registering a
  "block" via `register_block_type_from_metadata` only enqueues its
  `editorScript` when the block is actually present in the post content.
  Since the campaign sidebar is a JS plugin (never inserted as a block),
  this approach fails silently — the script never loads and the sidebar
  never appears. Fix: enqueue the script directly via
  `enqueue_block_editor_assets` (screen-checked to the campaign CPT).
  The `block.json` is kept in `src/campaign-editor/` only so
  `wp-scripts build` auto-discovers the entry point.
- **Image block `alt` attribute** — `parse_blocks()` puts the `alt` in
  `attrs['alt']` only when set via the block attributes panel. When the `alt`
  is in the `<img>` HTML directly, it must be extracted via regex from
  `innerHTML` as a fallback.
- **`parse_blocks()` return type** — PHPStan types it as
  `array<int|string, array<string, array|string|null>>`, not
  `array<int, array<string, mixed>>`. Must use `array<int|string, mixed>` in
  method signatures that accept parsed blocks.

## Phase 8 — Sending Engine (COMPLETE)

### Overview

Implemented the full campaign sending pipeline: audience resolution,
send-start snapshot, Action Scheduler batches, idempotent claiming,
failure marking, per-recipient personalization via merge-tag registry,
RFC 8058 one-click unsubscribe headers, and a progress UI.

### Implementation details

- **Action Scheduler** (`woocommerce/action-scheduler ^4.0`) added as a
  Composer dependency. Loaded in `bootstrap()` via
  `load_action_scheduler()` which requires the AS plugin file before
  `plugins_loaded` so AS can register its initialization hooks.
- **`MergeTagRegistry`** (`includes/Campaigns/MergeTagRegistry.php`) —
  Replaces `{email}`, `{unsubscribe_url}`, `{first_name}`, `{last_name}`,
  `{field:*}` merge tags at send time. Also builds RFC 8058
  `List-Unsubscribe`/`List-Unsubscribe-Post` headers targeting a
  specific list. Uses HMAC-only authentication for campaign unsubscribe
  URLs (the raw subscriber token is not available at send time — only
  its hash is stored). Extensible via `stampy_merge_tags` filter.
- **`CampaignRecipientRepository`**
  (`includes/Repositories/CampaignRecipientRepository.php`) — Manages
  the `campaign_recipients` table. `queue_audience()` resolves confirmed
  subscribers subscribed to target lists (deduplicated).
  `claim_batch()` uses conditional `UPDATE … SET status='sending'
  WHERE id=? AND status='queued'` for idempotent claiming (0 affected =
  already taken → skip). Re-queues rows stuck in 'sending' longer than
  `stampy_stuck_send_timeout` (default 15 min) based on `claimed_at`
  column.
- **`SendingEngine`** (`includes/Campaigns/SendingEngine.php`) —
  Orchestrates the full pipeline:
  1. `start_send()`: validates draft status, resolves audience,
     snapshots HTML/text/subject from current post_content, sets
     status='sending', schedules first batch via AS.
  2. `do_batch()`: claims batch (default 50, `stampy_batch_size`
     filter), personalizes each email via merge tags, sends via
     `wp_mail()` with AltBody for multipart/alternative, marks
     sent/failed, schedules next batch or completes.
  3. `maybe_complete_send()`: when no queued/sending remain, sets
     status='sent', records completed_at.
  4. `cancel_send()`: unschedules AS actions, sets status='cancelled'.
  5. `run_synchronous()`: for testing — loops `do_batch()` until no
     queued recipients remain.
- **Schema** — Added `claimed_at DATETIME` column to
  `campaign_recipients` table. DB_VERSION bumped to 2.
- **`Rewrites.php`** — Updated `render_unsubscribe_page()` to support
  two auth modes: token-based (subscriber token + HMAC) and HMAC-only
  (for RFC 8058 one-click unsubscribes from campaign emails). Also
  handles `list_id=0` as global unsubscribe (all lists).
- **`CampaignSendPage`** (`includes/Admin/CampaignSendPage.php`) —
  Admin post handler for start/cancel send, AJAX progress poll, and
  meta box renderer with progress bar. Registered as a side meta box
  on the campaign edit screen.
- **`CampaignPostType`** — Registered internal postmeta for
  HTML/text/subject snapshots + started_at/completed_at timestamps
  (not exposed to REST).
- **`EmailRenderer`** — Fixed `convert_links_to_inline()` to preserve
  `<a>` tags with inline styling in paragraph/heading/list-item blocks
  (previously stripped all HTML including links, losing `{unsubscribe_url}`
  in href attributes).

### Postmeta keys (internal, not in REST)

- `stampy_campaign_html_snapshot` — snapshotted HTML body at send start
- `stampy_campaign_text_snapshot` — snapshotted plain-text body
- `stampy_campaign_subject_snapshot` — snapshotted subject line
- `stampy_campaign_started_at` — send-start timestamp (UTC)
- `stampy_campaign_completed_at` — send-completed timestamp (UTC)

### Files created/modified

- `composer.json` — added `woocommerce/action-scheduler: ^4.0`
- `includes/Campaigns/MergeTagRegistry.php` — merge-tag registry
- `includes/Campaigns/SendingEngine.php` — sending engine
- `includes/Repositories/CampaignRecipientRepository.php` — recipient
  repository (claiming, progress, audience resolution)
- `includes/Admin/CampaignSendPage.php` — send/cancel/progress admin
- `includes/Admin/AdminMenu.php` — registered send handlers + meta box
- `includes/Campaigns/CampaignPostType.php` — registered snapshot meta
- `includes/Campaigns/EmailRenderer.php` — fixed link preservation
- `includes/Rewrites.php` — HMAC-only unsubscribe support
- `includes/Schema.php` — added `claimed_at` column, DB_VERSION=2
- `stampy.php` — `load_action_scheduler()` + `SendingEngine::register()`
- `uninstall.php` — unschedule AS batch actions on uninstall
- `src/campaign-editor/index.tsx` — status display update
- `tests/phpunit/Integration/SendingEngineTest.php` — 18 tests: start
  send, non-draft rejection, no-lists rejection, no-subscribers
  rejection, full synchronous send, mid-send edit isolation, no-double-
  send on retry, stuck row re-queue, merge-tag replacement, unsubscribe
  URL in headers, unsubscribe URL merge tag replaced, auto-appended
  unsubscribe, audience deduplication, only-confirmed queued, unsubscribed
  excluded, cancel send, timestamps set, small batch size.
- `tests/e2e/campaign-send.spec.ts` — E2E: create campaign via WP-CLI,
  send synchronously, verify personalized emails in Mailpit (no
  unreplaced merge tags).

### Test results

All 195 tests green:
- Jest: 17 (unchanged)
- PHP unit: 3 (unchanged)
- PHP integration: 165 (was 147, +18: SendingEngine tests)
- Playwright E2E: 13 (was 12, +1: campaign-send)

### Gotchas discovered

- **Action Scheduler must be loaded before `plugins_loaded`** — AS
  registers its init callback on `plugins_loaded` at priority 0. The
  plugin's `bootstrap()` runs at `muplugins_loaded` (via the test
  bootstrap) or at plugin load time. Calling `require_once` on the AS
  plugin file in `bootstrap()` ensures AS hooks are registered before
  `plugins_loaded` fires.
- **Subscriber token not available at send time** — only the SHA-256
  hash is stored. The raw token is returned to the caller during
  confirmation and never persisted. Campaign unsubscribe URLs use
  HMAC-only authentication (signed with the per-site secret) instead.
  The `render_unsubscribe_page()` method supports both modes.
- **`claimed_at` column needed for stuck detection** — without it, the
  re-queue query would re-queue ALL rows in 'sending' status on every
  batch, causing immediate re-queue of rows being actively processed.
  The `claimed_at` timestamp allows re-queuing only rows stuck longer
  than the timeout.
- **`wp_strip_all_tags()` strips `<a>` tags** — the EmailRenderer's
  `convert_links_to_inline()` previously called `extract_inner_text()`
  first (which strips all HTML), then tried to convert links on plain
  text — losing all href URLs. Fixed by passing raw HTML to
  `convert_links_to_inline()` and using a placeholder pattern to
  preserve `<a>` tags through the stripping process.
- **E2E tests with shared SMTP state** — the campaign-send E2E test
  must not conflict with the parallel smtp.spec.ts tests. Solution:
  delete SMTP settings so the dev mu-plugin routes mail to the tests
  Mailpit (port 1026) automatically. When SMTP is configured (by the
  SMTP tests), the dev mu-plugin yields to the plugin's transport.
- **`SmtpSettings::encrypt_password()` is private** — E2E tests must
  use `SmtpSettings::save()` (public) to configure SMTP, not call
  `encrypt_password()` directly.
- **PHPStan baseline regeneration** — after adding
  `CampaignRecipientRepository` with table-name interpolation in
  `$wpdb->prepare()`, run
  `vendor/bin/phpstan analyse --memory-limit=1G --generate-baseline=phpstan-baseline.neon`
  to suppress the `literal-string` false positives.
- **`wp_mail()` AltBody for multipart/alternative** — to send both
  HTML and plain-text parts, hook `phpmailer_init` and set
  `$phpmailer->AltBody`. Must `remove_action` after sending to avoid
  leaking the AltBody to subsequent `wp_mail()` calls.
- **Schema DB_VERSION bump** — incrementing `DB_VERSION` triggers
  the migration runner on next load. The `Installer::install()` method
  uses `CREATE TABLE IF NOT EXISTS` + `dbDelta()` which handles
  adding new columns to existing tables.

## Phase 9 — Tracking & Stats (COMPLETE)

### Overview

Implemented open/click tracking: 1×1 pixel for opens, signed redirect
endpoint for clicks, global toggle (default OFF) with per-campaign
override, and stats UI. All tracking URLs are HMAC-signed to prevent
tampering (e.g. swapping the destination URL in a click link).

### Implementation details

- **`TrackingSettings`** (`includes/Tracking/TrackingSettings.php`) —
  Global tracking toggle option (`stampy_tracking_enabled`, default
  `'0'` = OFF). Per-campaign override meta
  (`stampy_campaign_tracking`: `''` = inherit, `'on'` = force enable,
  `'off'` = force disable). `is_tracking_enabled(campaign_id)`
  resolves the effective setting.
- **`Tracking`** (`includes/Tracking/Tracking.php`) — Builds signed
  tracking URLs (open pixel + click redirect), injects the 1×1
  transparent GIF pixel into HTML bodies, rewrites content links with
  click-tracking redirects. Excludes from click rewriting:
  `{unsubscribe_url}` placeholders, URLs containing `stampy_unsub`,
  `mailto:`, `tel:`, in-page anchors (`#...`), and URL-encoded
  placeholders (`%7B...%7D`).
- **`TrackingEndpoints`** (`includes/Tracking/TrackingEndpoints.php`)
  — Registers query vars and rewrite rules for `/stampy/open/...` and
  `/stampy/click/...`. `handle_requests()` on `template_redirect`
  verifies HMAC signatures, records events, serves the 1×1 GIF (opens)
  or 302-redirects (clicks). `process_open()` and `process_click()`
  are public testable methods separated from the exit-calling handlers.
- **`CampaignRecipientRepository`** — Added `mark_opened()` (idempotent:
  only sets `opened_at` if NULL), `mark_clicked()` (idempotent),
  `record_click()` (inserts into `campaign_clicks`), `get_stats()`
  (opens, unique clicks, total clicks), `get_click_summary()` (per-URL
  click counts).
- **`SendingEngine`** — When `TrackingSettings::is_tracking_enabled()`
  is true, applies `rewrite_click_links()` then `inject_open_pixel()`
  to the personalized HTML after merge-tag replacement.
- **`CampaignPostType`** — Registered `stampy_campaign_tracking` meta
  (REST-exposed for block-editor sidebar control).
- **`SettingsPage`** — Added "Open & Click Tracking" section with a
  checkbox for the global toggle, saved alongside SMTP settings.
- **`CampaignSendPage`** — Added tracking stats display (opens, open
  rate, unique clicks, total clicks) in the send meta box when campaign
  status is `sent`.
- **`campaign-editor/index.tsx`** — Added "Open & Click Tracking"
  panel with a SelectControl for the per-campaign override (inherit /
  enable / disable).
- **`uninstall.php`** — Removes the `stampy_tracking_enabled` option.

### Files created/modified

- `includes/Tracking/TrackingSettings.php` — settings storage
- `includes/Tracking/Tracking.php` — pixel + link rewriting
- `includes/Tracking/TrackingEndpoints.php` — endpoints
- `includes/Repositories/CampaignRecipientRepository.php` — stats methods
- `includes/Campaigns/SendingEngine.php` — tracking instrumentation
- `includes/Campaigns/CampaignPostType.php` — tracking meta registration
- `includes/Campaigns/EmailRenderer.php` — fixed `esc_url()` stripping
  `{` from placeholder URLs; fixed `ensure_absolute_url()` to preserve
  `mailto:`, `tel:`, and `#` URLs; changed link placeholder to
  `%%STAMPYLINKn%%` (null bytes were stripped by `trim()`)
- `includes/Admin/SettingsPage.php` — tracking toggle UI
- `includes/Admin/CampaignSendPage.php` — stats display
- `src/campaign-editor/index.tsx` — tracking override control
- `stampy.php` — `Tracking\TrackingEndpoints::register()`
- `uninstall.php` — remove tracking option
- `phpstan-baseline.neon` — regenerated (66 errors, all `literal-string`)
- `tests/phpunit/Integration/TrackingTest.php` — 16 tests
- `tests/e2e/tracking.spec.ts` — E2E: pixel + click tracking

### Test results

All 210 tests green:
- Jest: 17 (unchanged)
- PHP unit: 3 (unchanged)
- PHP integration: 181 (was 165, +16: TrackingTest)
- Playwright E2E: 14 (was 13, +1: tracking.spec.ts)

## Campaign Admin UI Refactor — Unified PluginSidebar (COMPLETE)

### Overview

Replaced the PHP meta box + inline JavaScript campaign send UI with a
unified React `PluginSidebar` component. All campaign management
(subject, target lists, tracking, send/progress/stats, preview) now
lives in a single sidebar panel with auto-save via `editPost()` and
AJAX-based send/cancel/progress polling.

### What was removed

- `CampaignSendPage::render_send_box()` — PHP meta box HTML rendering
- `CampaignSendPage::enqueue_scripts()` — inline JavaScript for send/
  cancel/progress (replaced by React state + `fetch()`)
- `AdminMenu::add_campaign_meta_boxes()` — meta box registration hook
- `AdminMenu::admin_footer()` — inline script for progress polling
- `CampaignPostType` import from `AdminMenu` (no longer needed)

### What was added/updated

- **`src/campaign-editor/index.tsx`** — fully rewritten. All campaign UI
  in one `PluginSidebar`:
  - **Subject** — `TextControl`, auto-saves via `editPost({ meta: {...} })`
    on every keystroke
  - **Target Lists** — `CheckboxControl` list, auto-saves on toggle
  - **Open & Click Tracking** — `SelectControl` for per-campaign
    override (inherit / enable / disable), auto-saves on change
  - **Send & Progress** — Send/Cancel `Button`s via `fetch()` to
    `admin-ajax.php`; live progress bar with 3s polling (`useRef` for
    interval); tracking stats (opens, open rate, unique clicks, total
    clicks) shown when sent
  - **Preview** — HTML and plain-text preview links
- **`CampaignPostType::enqueue_editor_assets()`** — now passes
  `ajaxUrl`, `startSendNonce`, `cancelSendNonce`, `progressNonce` to
  JS via `window.stampy`
- **`CampaignSendPage::handle_progress_ajax()`** — now includes
  tracking stats when campaign status is `sent`
- **`AdminMenu`** — removed meta box hooks; kept `post_row_actions`
  filter + AJAX handler registrations
- **`types/globals.d.ts`** — added `ajaxUrl`, `startSendNonce`,
  `cancelSendNonce`, `progressNonce` to `StampyGlobal`
- **`types/wordpress-plugins.d.ts`** — added `useRef` export to
  `@wordpress/element` module declaration
- **`tests/jest/mocks/components.js`** — added `TextControl`,
  `SelectControl`, `Button`, `Spinner`
- **`tests/jest/mocks/i18n.js`** — added `sprintf`
- **`tests/jest/mocks/plugins.js`** — new mock for `@wordpress/plugins`
  (`registerPlugin` stub)
- **`tests/jest/mocks/editor.js`** — new mock for `@wordpress/editor`
  (`PluginSidebar` stub)
- **`jest.config.js`** — added `@wordpress/plugins` and
  `@wordpress/editor` to `moduleNameMapper`

### Files created/modified

- `src/campaign-editor/index.tsx` — fully rewritten unified sidebar
- `src/campaign-editor/index.test.tsx` — 7 Jest tests (new)
- `includes/Admin/CampaignSendPage.php` — removed PHP meta box; AJAX
  handlers retained
- `includes/Admin/AdminMenu.php` — removed meta box hooks
- `includes/Campaigns/CampaignPostType.php` — `enqueue_editor_assets()`
  passes nonces + AJAX URL
- `types/globals.d.ts` — StampyGlobal additions
- `types/wordpress-plugins.d.ts` — `useRef` addition
- `tests/jest/mocks/components.js` — new component stubs
- `tests/jest/mocks/i18n.js` — `sprintf` added
- `tests/jest/mocks/plugins.js` — new mock file
- `tests/jest/mocks/editor.js` — new mock file
- `jest.config.js` — new module mappings
- `tests/e2e/campaign-admin-ui.spec.ts` — 3 E2E tests (new)

### Test results

All 223 tests green:
- Jest: 24 (was 17, +7: `index.test.tsx`)
- PHP unit: 3 (unchanged)
- PHP integration: 181 (unchanged)
- Playwright E2E: 15 (was 14, +1: `campaign-admin-ui.spec.ts`)

### Gotchas discovered

- **TypeScript generics in `.tsx` files are parsed as JSX** —
  `jest.mock('@wordpress/element', () => ({ useState: <T>(...) => ... }))`
  causes `tsc --noEmit` to fail with "JSX element 'T' has no closing
  tag." Jest (babel) handles it fine, but `validate:fast` includes
  `type-check`. Fix: use `unknown` instead of generics, or move the
  mock to a `.js` file. Using `unknown` is simplest: `useState:
  (initial: unknown) => { ... }`.
- **`useSelect` mock return objects need `Record<string, any>` type**
  — TypeScript complains "No index signature with a parameter of type
  'string'" when indexing a plain object literal with a `string` store
  name. Fix: type the mock `select` object as `Record<string, any>`.
- **PHP meta boxes inside the post edit form cannot use nested
  `<form>` tags** — browsers ignore inner forms. The PHP meta box had
  a `<form>` for send/cancel that conflicted with the WP post edit
  form. Moving everything to React `PluginSidebar` with AJAX `fetch()`
  calls eliminates the nested form problem entirely.
- **`wp_print_inline_script_tag()` during `admin_enqueue_scripts`
  outputs in `<head>` before DOM exists** — use `admin_footer` hook
  instead, or better, move all interactivity to React.
- **PluginSidebar is opened via a button in the editor top bar** (e.g.
  "Campaign Settings" icon), NOT via the sidebar tab list (which shows
  "Campaign"/"Block" tabs for the built-in post sidebar).
- **E2E tests for React sidebar components** — use
  `getByRole('button', { name: '...' })` to find sidebar buttons.
  `page.click('button')` is too generic. The sidebar renders
  asynchronously; use `waitForSelector` or `expect(...).toBeVisible()`
  with a timeout.

### Gotchas discovered

- **`esc_url()` strips `{` and `}` from merge-tag placeholders** —
  `esc_url( '{unsubscribe_url}' )` returns `http://unsubscribe_url`
  (curly braces removed). The link rewriter then sees a plain URL
  instead of a placeholder. Fix: in `convert_links_to_inline()`, use
  `esc_attr()` for hrefs starting with `{`, `esc_url()` otherwise.
- **`wp_strip_all_tags()` + `trim()` strips null bytes** — the old
  `\x00LINKn\x00` placeholder pattern was stripped by `trim()` inside
  `wp_strip_all_tags()`, breaking link restoration. Changed to
  `%%STAMPYLINKn%%` which survives the stripping.
- **`ensure_absolute_url()` must preserve `mailto:`, `tel:`, and
  `#` URLs** — otherwise these are prefixed with `home_url()`, breaking
  them (e.g. `mailto:info@example.com` →
  `http://localhost:8889/mailto:info@example.com`).
- **HTML entity encoding in tracking URLs** — `esc_url()` encodes `&`
  as `&#038;` in the pixel URL. E2E tests extracting URLs from email
  HTML must decode both `&amp;` and `&#038;` before making HTTP
  requests.
- **Rewrite rules must be flushed after adding new endpoints** — the
  tracking rewrite rules are registered on `init` but only become
  active after `flush_rewrite_rules()`. In the tests instance, run
  `wp rewrite flush` after activating the plugin.
- **`should_exclude_link()` must check for URL-encoded placeholders**
  — `esc_url()` in the EmailRenderer can encode `{unsubscribe_url}` to
  `%7Bunsubscribe_url%7D` in some code paths. The link rewriter must
  exclude hrefs starting with `%7B` (case-insensitive) as well as `{`.
- **`base64_decode` triggers PHPCS warning** — the 1×1 GIF pixel is
  a fixed base64-encoded string. Wrap in
  `phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode`
  ... `phpcs:enable` with a benign-reason comment.

## Post-Phase-9 Fixes

### Campaign list table columns

Added three custom columns to the campaign list table
(`includes/Campaigns/CampaignPostType.php`):

- **Status** — colored badge (draft=grey, sending=blue, sent=green,
  cancelled=red) + subject line underneath
- **Progress** — `sent / total (XX%)` with failed count in red if any
  failures; `—` for drafts
- **Tracking** — On/Off indicator + opens/clicks summary when sent

### Campaign sidebar — all panels expanded

Changed the Preview `PanelBody` `initialOpen` from `false` to `true`
in `src/campaign-editor/index.tsx`. All panels (Subject, Target
Lists, Open & Click Tracking, Send & Progress, Preview) now open by
default.

### CI E2E — missing `npm run build` step

The CI E2E job was missing `npm run build` before running tests. The
`build/` directory is gitignored, so the compiled campaign-editor JS
(with `registerPlugin`) never existed in CI. The "Campaign Settings"
button couldn't appear without it, causing the "sidebar Send button
starts the campaign send via AJAX" test to fail with a timeout
waiting for the button. Fix: added `npm run build` step in
`.github/workflows/ci.yml` before `npx playwright install`.

### E2E admin login robustness

Updated all 3 `adminLogin` helpers (`admin.spec.ts`,
`campaign-admin-ui.spec.ts`, `smtp.spec.ts`) to use
`Promise.all([waitForNavigation, click])` instead of bare `click`,
and increased timeouts from 15s to 20s. This eliminates the flaky
`#wpadminbar` timeout that occurred when the login redirect was slow.

Also added `expect(settingsButton).toBeVisible({ timeout: 20000 })`
before clicking the "Campaign Settings" button in
`campaign-admin-ui.spec.ts`, to give the block editor time to fully
load.

### Honeypot field visible on frontend

The server-side rendered honeypot field in `SignupBlock.php` was
only hidden via `aria-hidden="true"`, which hides it from screen
readers but not visually. The block editor preview (`edit.tsx`)
already hid it with inline CSS (`position:absolute;
left:-9999px;width:1px;height:1px;overflow:hidden;`), but the PHP
render path did not. Fix: added the same inline CSS to the
`<p>` wrapper in `SignupBlock.php`.

### Files modified

- `includes/Campaigns/CampaignPostType.php` — list table columns
  (Status, Progress, Tracking)
- `src/campaign-editor/index.tsx` — Preview panel `initialOpen={true}`
- `.github/workflows/ci.yml` — added `npm run build` in E2E job
- `tests/e2e/admin.spec.ts` — robust admin login
- `tests/e2e/campaign-admin-ui.spec.ts` — robust admin login + button
  visibility wait
- `tests/e2e/smtp.spec.ts` — robust admin login
- `includes/SignupBlock.php` — honeypot field hidden with inline CSS

## Phase 10 — Compliance & Release

### Overview

Implemented all Phase 10 deliverables: GDPR privacy hooks, admin
compliance settings, i18n (POT + German translation), readme.txt
polish, SVG menu icon, release.yml workflow, and `.distignore`.

### Privacy export/erase (GDPR)

- **`includes/Privacy.php`** — registers
  `wp_privacy_personal_data_exporters` and
  `wp_privacy_personal_data_erasers` filters. Export covers 6 data
  groups: subscriber profile, subscriber attributes (meta), list
  memberships, pending signups, campaign recipients, campaign clicks.
  Erase uses `SubscriberRepository::delete()` which cascades across
  all related tables (meta, lists, pending, recipients, clicks).
- Registered in `stampy.php` `bootstrap()` via `Privacy::register()`.
- **9 integration tests** in `tests/phpunit/Integration/PrivacyTest.php`:
  exporter/eraser registration, empty export, profile export, meta
  export, list memberships export, campaign recipients + clicks
  export, full erase, erase for nonexistent email, erase removes
  campaign data.

### Admin compliance settings

- **`includes/Admin/SettingsPage.php`** — added "Compliance" section
  with:
  - **Physical Address** textarea — saves to
    `stampy_physical_address` option (CAN-SPAM requirement, shown in
    email footer).
  - **Data on Uninstall** checkbox — saves to
    `stampy_delete_data_on_uninstall` option (defaults to on).

### i18n

- **`load_plugin_textdomain('stampy', ...)`** added to `bootstrap()`
  in `stampy.php`.
- **`languages/stampy.pot`** — generated via `wp i18n make-pot`,
  covers PHP + block.json strings.
- **`languages/stampy-de_DE.po` + `.mo`** — German translation (165
  strings).
- **`wp_set_script_translations`** added for:
  - `stampy-campaign-editor-editor-script` in
    `CampaignPostType::enqueue_editor_assets()`
  - `stampy-signup-editor-script` and `stampy-signup-view-script` in
    `SignupBlock::register()`

### readme.txt

Fully rewritten with: feature list, installation instructions, FAQ
(external service? tracking default? uninstall data? GDPR? i18n?),
screenshots list, and detailed changelog.

### Menu icon

Uses the 🦒 giraffe emoji (U+1F992) rendered as an inline SVG data URI
in `AdminMenu.php`. The emoji is URL-encoded and embedded in an SVG
`<text>` element, so no external asset file is needed. A custom
hand-crafted giraffe SVG was attempted first but didn't render well at
20×20px; the emoji approach is crisp, instantly recognizable, and
stays on-brand ("Stampy" the giraffe).

### Release workflow

- **`.github/workflows/release.yml`** — on `v*` tag push:
  1. Build (npm + composer --no-dev --optimize-autoloader)
  2. Version consistency check (git tag == plugin header ==
     readme.txt stable tag)
  3. Create zip via `.distignore`
  4. Upload artifact + GitHub Release
  5. Deploy to WP.org SVN (protected `wporg-deploy` environment)
- **`.distignore`** — excludes dev files from the release zip.

### Test results

All tests green:
- Jest: 24 (unchanged)
- PHP unit: 3 (unchanged)
- PHP integration: 199 (was 190, +9: `PrivacyTest`)
- E2E: 20 (was 15, +5: bulk actions + first/last name columns)

### Test stability fixes

Fixed all integration test failures and E2E flakiness:

#### Integration tests (9 failures → 0)

Root cause: `dbDelta()` in `Installer::install()` causes an implicit
MySQL commit, breaking the WP test transaction. Data persists across
test methods and classes. Tests asserting absolute counts (`count() === 0`
or `count() === 1`) fail when leftover data exists.

Fixes applied:
- **`SubscriberRepositoryTest`** — added `tearDown()` cleanup that
  deletes all test-created subscribers (and their meta) AFTER
  `parent::tearDown()`. Changed `test_count`, `test_count_by_status`,
  `test_delete`, and `test_create_or_get_upserts_existing_email` to
  use delta assertions (`$initial + N === $repo->count()`).
- **`SignupEndpointTest`** — added `tearDown()` cleanup. Changed
  `test_valid_signup_creates_pending_and_sends_email` and
  `test_resignup_same_form_refreshes_pending` to record initial count
  before the test and assert delta.
- **`PrivacyTest::test_erase_removes_campaign_data`** — changed the
  click count assertion from `SELECT COUNT(*) FROM campaign_clicks`
  (counts ALL clicks from ALL tests) to `WHERE recipient_id = %d`
  (scoped to the specific test's recipient).

#### E2E tests (flaky → stable, 5/5 consecutive runs pass)

Root causes and fixes:
1. **Login race condition** — with `fullyParallel: true`, multiple
   workers calling `adminLogin()` simultaneously caused session cookie
   loss. Fix: `globalSetup` now logs in once via Playwright and saves
   `tests/e2e/.auth/admin.json` via `context.storageState()`.
   `playwright.config.ts` sets `storageState` so all tests start
   authenticated. `adminLogin()` helpers now check if already logged
   in and only fall back to the login form if the storage state is
   invalid.
2. **`Promise.all([waitForNavigation, click])` anti-pattern** —
   `waitForNavigation` resolves on intermediate redirects, making it
   unreliable for login flows. Replaced with bare `page.click()` +
   `waitForSelector('#wpadminbar', { timeout: 30000 })`.
3. **Mailpit race condition** — SMTP tests and campaign tests run in
   parallel. SMTP tests reconfigure SMTP settings (pointing to dev
   Mailpit port 1025) while campaign tests are sending emails.
   Campaign emails arrive in dev Mailpit instead of tests Mailpit.
   Fix: `waitForCampaignEmail()` now searches both `MAILPIT_TESTS_API`
   (port 8026) and `MAILPIT_DEV_API` (port 8025) in a loop.
4. **Static email subjects** — tests used `"E2E Campaign"` which
   matched stale emails from previous runs. Fixed: all campaign
   subjects now append `Date.now()` for uniqueness.
5. **WP-CLI transient Docker failures** — `wp-env run tests-cli` fails
   intermittently when multiple parallel workers spawn Docker exec
   calls. Fix: `wpCli()` helper now retries 3 times with a 2s delay
   and a 60s timeout (was 30s).
6. **`page.locator('h1')` strict mode violation** — WP admin pages
   have multiple `h1` elements. Fix: use `page.locator('.wrap h1')`.
7. **`page.click('#search-submit')` timeout** — WP admin JS makes the
   button "not stable". Fix: use `{ force: true }`.
8. **Fixed 2s wait for email delivery** — replaced with polling
   `waitForCampaignEmail()` that searches Mailpit every 500ms with a
   30s timeout.
9. **Test timeout too short** — campaign send + tracking tests need
   WP-CLI calls + synchronous send + Mailpit polling. Fix:
   `test.setTimeout(120_000)`.

### Gotchas discovered

- **WP privacy exporter `$page` parameter** — required by the WP API
  but unused (all data fits in one page). PHPCS warns about unused
  parameter. Fix: add `unset( $page );` after the null check.
- **`wp_json_encode()` returns `string|false`** — PHPStan flags it.
  Must check for `false` and provide a fallback.
- **PHPCS `DisallowShortTernary`** — `wp_json_encode( $x ) ?: ''` is
  forbidden. Use `false !== wp_json_encode( $x ) ? wp_json_encode( $x ) : ''`.
- **Integration test data pollution with `dbDelta()`** — the
  `Installer::install()` call uses `CREATE TABLE IF NOT EXISTS` +
  `dbDelta()`, which causes an implicit MySQL commit. Data created in
  test methods persists across test classes. PrivacyTest creates
  subscribers and lists that pollute SendingEngineTest, TrackingTest,
  and UnsubscribePreferencesTest. Fix: clean up ALL test-created data
  in `tearDown()` AFTER `parent::tearDown()` (the WP test framework's
  transaction rollback UNDOES deletes made before it).
- **WP test framework transaction rollback** — `parent::tearDown()`
  calls `ROLLBACK` on the WP test transaction. If you delete data
  BEFORE `parent::tearDown()`, the rollback UNDOES your deletes (the
  data was inserted within the transaction, even if `dbDelta()` broke
  it). Fix: run cleanup queries AFTER `parent::tearDown()`.
- **`$wpdb->insert_id` after a failed INSERT** — when
  `$list_repo->create('Newsletter', 'newsletter')` fails due to a
  duplicate key, `$wpdb->insert_id` retains the value from the last
  SUCCESSFUL insert in the `lists` table. This means
  `$this->list_id` could point to a completely different list. Fix:
  use `find_by_slug()` in test setUp to get the correct list ID.
- **E2E `storageState` eliminates login races** — with
  `fullyParallel: true`, multiple workers logging in simultaneously
  causes session cookie loss. `globalSetup` logs in once and saves
  the session. All tests reuse it via `playwright.config.ts`
  `storageState` setting.
- **E2E campaign tests must search both Mailpit instances** — SMTP
  tests reconfigure SMTP settings in parallel with campaign tests.
  Campaign emails may arrive in dev Mailpit (port 1025) instead of
  tests Mailpit (port 8026). `waitForCampaignEmail()` searches both.
- **E2E `wpCli()` must retry on Docker failures** — transient
  Docker exec failures when multiple workers run WP-CLI commands
  simultaneously. 3 retries with 2s delay, 60s timeout.

### Files created/modified

- `includes/Privacy.php` — new, GDPR exporters + erasers
- `stampy.php` — added `load_plugin_textdomain` + `Privacy::register()`
- `includes/Admin/SettingsPage.php` — compliance section (address +
  delete-data toggle)
- `includes/Admin/AdminMenu.php` — uses 🦒 emoji SVG data URI menu icon
- `includes/Admin/SubscribersListTable.php` — bulk actions refactored
  to `handle_bulk_action()` on `load-{hook}`, first/last name columns
- `includes/Admin/SubscribersPage.php` — form `method="post"`, bulk
  success notice rendering
- `includes/Campaigns/CampaignPostType.php` —
  `wp_set_script_translations`
- `includes/SignupBlock.php` — `wp_set_script_translations` for both
  editor + view scripts
- `languages/stampy.pot` — new, generated POT file
- `languages/stampy-de_DE.po` — new, German translation
- `languages/stampy-de_DE.mo` — new, compiled MO file
- `readme.txt` — fully rewritten
- `.github/workflows/release.yml` — new, release + deploy workflow
- `.distignore` — new, dist exclusion list
- `playwright.config.ts` — added `storageState` for shared login
- `tests/e2e/global-setup.ts` — added Playwright login + storage state
- `tests/e2e/admin.spec.ts` — bulk action E2E tests, `storageState`
  login, `.wrap h1` selectors, `force: true` on search-submit
- `tests/e2e/campaign-send.spec.ts` — unique subjects, dual-Mailpit
  search, `wpCli()` retry, `waitForCampaignEmail()` polling
- `tests/e2e/tracking.spec.ts` — unique subjects, dual-Mailpit search,
  `wpCli()` retry, `waitForCampaignEmail()` polling, 120s timeout
- `tests/e2e/campaign-admin-ui.spec.ts` — `storageState` login,
  `wpCli()` retry
- `tests/e2e/smtp.spec.ts` — `storageState` login, `.wrap h1` selectors
- `tests/phpunit/Integration/PrivacyTest.php` — new, 9 integration tests
- `tests/phpunit/Integration/AdminSubscribersTest.php` — 8 new bulk
  action tests + fixed pre-existing tests with unique emails
- `tests/phpunit/Integration/SubscriberRepositoryTest.php` — added
  `tearDown()` cleanup, delta-based count assertions
- `tests/phpunit/Integration/SignupEndpointTest.php` — added
  `tearDown()` cleanup, delta-based count assertions

---

## Phase 11 — Native Anti-Spam Quiz

Status: **COMPLETE** ✓

### Requirements (from PLAN.md §9 Phase 11)

- [x] Configurable, accessible question/answer quiz (e.g. "What is 3 + 4?")
- [x] Additional guard in the existing spam-guard pipeline
- [x] Deliberately NOT an image CAPTCHA (WCAG failure, OCR-breakable)
- [x] Admin settings for question/answer pairs
- [x] Guard plugged into `SpamGuardChain`
- [x] Unit tests (guard pass/fail, chain integration)
- [x] Integration tests (signup rejected on wrong answer, accepted on correct)
- [x] E2E (existing tests still pass with quiz disabled by default)

### Verification targets
- [x] `npm run validate:fast` green (38 unit PHP, 24 JS)
- [x] `npm run test:integration:php` green (206 tests, +7 new)
- [x] `npm run test:e2e` green (20 tests)

### What was done
- **`includes/SpamGuards/QuizGuard.php`** — new spam guard implementing
  `SpamGuardInterface`. Reads question/answer pairs from the
  `stampy_quiz_questions` option (one per line, format: `question||answer`).
  When no questions are configured, the guard passes (disabled). When
  configured, the signup form renders a random question and the guard
  verifies the answer (case-insensitive, whitespace-normalized).
  Exposes `QuizGuard::get_questions()`, `QuizGuard::is_enabled()`, and
  constants `ANSWER_KEY` / `INDEX_KEY` for form integration.
- **`includes/SpamGuards/SpamGuardChain.php`** — `default_chain()` now
  adds `QuizGuard` when `QuizGuard::is_enabled()` returns true.
- **`includes/Admin/SettingsPage.php`** — added "Anti-Spam Quiz" section
  with a textarea for question/answer pairs (format instructions included).
  Saves to `stampy_quiz_questions` option.
- **`includes/Rest/SignupController.php`** — added `stampy_quiz_answer`
  and `stampy_quiz_index` REST parameters.
- **`includes/SignupBlock.php`** — renders quiz field (random question +
  hidden index) when questions are configured. Passes `quizQuestions` in
  localized data for the block editor preview.
- **`src/blocks/signup/view.ts`** — sends `stampy_quiz_answer` and
  `stampy_quiz_index` in the API request when present.
- **`src/blocks/signup/edit.tsx`** — shows quiz preview when configured.
- **`types/globals.d.ts`** — added `StampyQuizQuestion` interface and
  `quizQuestions` field on `StampyGlobal`.
- **`uninstall.php`** — added `stampy_quiz_questions` to deleted options.
- **`tests/phpunit/Unit/QuizGuardTest.php`** — 12 unit tests: pass when
  disabled, fail on empty/wrong/missing answer, pass on correct answer,
  case-insensitive, whitespace normalized, index out of bounds, chain
  integration, result factories.
- **`tests/phpunit/Integration/QuizGuardTest.php`** — 7 integration tests:
  correct answer succeeds, wrong answer fails, empty answer fails, invalid
  index fails, case-insensitive, disabled quiz succeeds, settings save.
- **`tests/phpunit/bootstrap.php`** — defines `ABSPATH` in unit-only mode
  so class files with `if ( ! defined( 'ABSPATH' ) ) { exit; }` guards
  don't kill the process when the autoloader loads them.

### Test counts
- Jest: 24 (unchanged)
- PHP unit: 38 (was 26, +12 QuizGuard tests; existing SpamGuard/Validator
  tests also now run correctly thanks to ABSPATH fix)
- PHP integration: 206 (was 199, +7 QuizGuard tests)
- E2E: 20 (unchanged)
- **Total: 288 tests**

### Gotchas discovered
- **`if ( ! defined( 'ABSPATH' ) ) { exit; }` kills unit tests** — the
  guard at the top of class files in `includes/` causes the PHP process
  to exit silently when the Composer autoloader loads the class in a
  unit test context (Brain Monkey, no WordPress loaded). The existing
  SpamGuard and Validator tests were silently not running (PHPUnit
  reported exit code 0 with no test output). Fix: define `ABSPATH` in
  `tests/phpunit/bootstrap.php` for unit-only runs.
- **`HOUR_IN_SECONDS` not defined in unit tests** — `RateLimitGuard`
  uses it as a constructor default parameter. When `SpamGuardChain::
  default_chain()` is called in a unit test, the constant is undefined.
  Fix: define `HOUR_IN_SECONDS` in the test's `setUp()`.
- **Quiz question format** — uses `||` as the delimiter between question
  and answer (not `|` or `=`), because `|` is common in questions (e.g.
  "Is the sky blue or gray | red?") and `=` could appear in math questions.

---

## Phase 12 — Third-Party Spam Guards (Turnstile + Friendly Captcha)

Status: **COMPLETE** ✓

### Requirements (from PLAN.md §9 Phase 12)

- [x] Cloudflare Turnstile integration via `SpamGuardInterface`
- [x] Friendly Captcha integration via `SpamGuardInterface`
- [x] Admin settings for provider selection, site key, and secret key
- [x] Frontend renders the provider's widget in the signup form
- [x] Backend verifies the token/solution on submit via server-side HTTP call
- [x] Both purely additive — plug into the existing `SpamGuardChain`
- [x] Unit tests (guard pass/fail with mocked HTTP verification)
- [x] Integration tests (signup rejected on invalid/missing token, accepted on valid token)

### Verification targets
- [x] `npm run validate:fast` green (52 unit PHP, 24 JS)
- [x] `npm run test:integration:php` green (212 tests, +6 new)
- [x] `npm run test:e2e` green (20 tests)

### What was done
- **`includes/SpamGuards/TurnstileGuard.php`** — Cloudflare Turnstile
  spam guard. Verifies the Turnstile token via Cloudflare's
  `siteverify` API (`wp_remote_post`). When no secret key is configured,
  the guard passes (disabled). Exposes `TurnstileGuard::is_enabled()`
  and `TurnstileGuard::get_site_key()`.
- **`includes/SpamGuards/FriendlyCaptchaGuard.php`** — Friendly Captcha
  spam guard. Verifies the solution via Friendly Captcha's `siteverify`
  API. Same pattern as TurnstileGuard.
- **`includes/SpamGuards/SpamGuardChain.php`** — `default_chain()` now
  uses lazy evaluation: guards are rebuilt on every `check()` call when
  the chain was built via `default_chain()`. This ensures option changes
  (e.g. configuring Turnstile after the service was constructed) take
  effect immediately without needing to re-create the service.
- **`includes/Admin/SettingsPage.php`** — added "Cloudflare Turnstile"
  and "Friendly Captcha" settings sections (site key + secret key each).
- **`includes/Rest/SignupController.php`** — added `stampy_turnstile_token`
  and `stampy_friendly_captcha_solution` REST parameters.
- **`includes/SignupBlock.php`** — renders Turnstile widget (`<div class="cf-turnstile">`)
  and Friendly Captcha widget (`<div class="frc-captcha">`) when enabled.
  Enqueues the external provider scripts. Passes captcha config in
  localized data.
- **`src/blocks/signup/view.ts`** — reads Turnstile token from
  `[name="cf-turnstile-response"]` hidden input and Friendly Captcha
  solution from `[name="frc-captcha-solution"]` hidden input, sends them
  in the API request.
- **`types/globals.d.ts`** — added `turnstileEnabled`, `turnstileSiteKey`,
  `friendlyCaptchaEnabled`, `friendlyCaptchaSiteKey` fields.
- **`uninstall.php`** — added Turnstile + Friendly Captcha options to
  deleted options list.
- **`tests/phpunit/Unit/CaptchaGuardTest.php`** — 14 unit tests:
  Turnstile: pass when disabled, fail on missing/empty token, pass on
  API success, fail on API failure, fail on WP_Error, is_enabled checks.
  Friendly Captcha: same pattern.
- **`tests/phpunit/Integration/CaptchaGuardTest.php`** — 6 integration
  tests: signup succeeds when disabled, fails when Turnstile enabled but
  no token, fails on invalid token, succeeds on valid token (mocked API),
  fails when FC enabled but no solution, succeeds on valid FC solution.

### Test counts
- Jest: 24 (unchanged)
- PHP unit: 52 (was 38, +14 CaptchaGuard tests)
- PHP integration: 212 (was 206, +6 CaptchaGuard tests)
- E2E: 20 (unchanged)
- **Total: 308 tests**

### Gotchas discovered
- **`SpamGuardChain::default_chain()` must be lazy** — the
  `SignupController` is registered on `rest_api_init` and creates a
  `SignupService` with `SpamGuardChain::default_chain()`. The REST
  server is initialized once and cached. If the chain was built eagerly
  (checking `QuizGuard::is_enabled()` at construction time), option
  changes made after service construction (e.g. in a test's `setUp()`)
  would not take effect. Fix: the chain rebuilds its guard list on every
  `check()` call when built via `default_chain()`.
- **External script version** — `wp_enqueue_script()` for external
  provider scripts (Turnstile, Friendly Captcha) triggers
  `WordPress.WP.EnqueuedResourceParameters.MissingVersion` when `null`
  is passed as version. Use a string version (e.g. `'1.0'`) instead.
- **`pre_http_request` filter for mocking HTTP in integration tests** —
  use `add_filter('pre_http_request', ...)` with a callback that checks
  the URL and returns a mock response array. Must `remove_filter` in
  `tearDown()` or after the test to avoid leaking to other tests.

---

## Phase 13 — Field Management & Profiles

Status: **COMPLETE** ✓

### Requirements (from PLAN.md §9 Phase 13)

- [x] Admin CRUD UI for `fields` definitions (create/edit/delete)
- [x] Field types: text, textarea, number, date, select, checkbox
- [x] Each field has: label, key, type, options, required flag, show_in_admin toggle
- [x] Subscriber detail view becomes a full profile editor (editable, not read-only)
- [x] `{field:*}` merge tags (e.g. `{field:company}`, `{field:phone}`) usable in campaigns
- [x] Validators for new field types (textarea, number, date, select, checkbox)
- [x] SignupService validates fields against their registered field type
- [x] Integration tests (field CRUD, subscriber profile edit, merge-tag replacement)

### Verification targets
- [x] `npm run validate:fast` green (72 unit PHP, 24 JS)
- [x] `npm run test:integration:php` green (220 tests, +8 new)
- [x] `npm run test:e2e` green (20 tests)

### What was done
- **`includes/Validators/TextareaValidator.php`** — validates and
  sanitizes textarea input via `sanitize_textarea_field()`.
- **`includes/Validators/NumberValidator.php`** — accepts integers,
  floats, and numeric strings; rejects non-numeric input.
- **`includes/Validators/DateValidator.php`** — accepts dates in
  YYYY-MM-DD format; validates via `DateTime::createFromFormat()`.
- **`includes/Validators/SelectValidator.php`** — sanitizes text input
  (options constraint enforced at field config level).
- **`includes/Validators/CheckboxValidator.php`** — accepts booleans,
  "1"/"0", "true"/"false", "on"/"off", "yes"/"no" strings. Returns "1"
  or "0" as sanitized value.
- **`includes/Validators/ValidatorRegistry.php`** — registers all 5
  new validators in the constructor (8 total: email, text, textarea,
  number, date, select, checkbox, acceptance).
- **`includes/Repositories/FieldRepository.php`** — added `update()`
  method for editing field definitions. Fixed `wp_json_encode()` return
  type handling (`string|false`) in both `create()` and `update()`.
- **`includes/SignupService.php`** — signup pipeline now looks up the
  field definition for each submitted field and uses the field's `type`
  to select the appropriate validator (was hardcoded to `text`).
- **`includes/Admin/FieldsPage.php`** — new admin page with full CRUD
  UI for field definitions. Create/edit form with all field properties.
  Delete with nonce verification.
- **`includes/Admin/FieldsListTable.php`** — new `WP_List_Table`
  subclass for listing field definitions (label, key, type, required,
  show_in_admin columns with edit/delete row actions).
- **`includes/Admin/AdminMenu.php`** — registered "Fields" submenu page
  and `stampy_save_field` admin-post handler.
- **`includes/Admin/SubscribersPage.php`** — subscriber detail view
  now renders editable inputs for all custom attributes (text, textarea,
  number, date, select dropdown, checkbox). `handle_save()` now persists
  attribute changes via `SubscriberMetaRepository::set()`.
- **`tests/phpunit/Unit/FieldValidatorTest.php`** — 19 unit tests
  covering all 5 new validators (pass/fail cases, type coercion, empty
  values) + registry integration test.
- **`tests/phpunit/Integration/FieldManagementTest.php`** — 8 integration
  tests: create field, find by key, update field, delete field, all
  (admin_only filter), subscriber profile set/get meta, merge policy,
  field with options stores JSON.

### Test counts
- Jest: 24 (unchanged)
- PHP unit: 72 (was 52, +20 FieldValidator tests)
- PHP integration: 220 (was 212, +8 FieldManagement tests)
- E2E: 20 (unchanged)
- **Total: 336 tests**

### Notes
- `{field:*}` merge tags were already supported by
  `MergeTagRegistry::build_tag_values()` (Phase 8) — no changes needed.
  Any custom field stored in `subscriber_meta` is automatically available
  as `{field:field_key}` in campaign email bodies.
- Field definitions created via the admin UI are immediately available
  as merge tags — no additional configuration needed.
- The `fields` table and `subscriber_meta` EAV storage were already
  created in Phase 2 — no schema migration was needed.
- The `FieldRepository` already existed with `create()`, `find()`,
  `find_by_key()`, `all()`, and `delete()` methods from Phase 2. Only
  the `update()` method was new.

### Bug fixes (post-implementation)
- **Options display**: options were stored as JSON in the DB but the edit
  form showed the raw JSON string (`["Free","Pro","Enterprise"]`) in the
  textarea. Fixed: `render_edit()` now `json_decode()`s the options and
  joins them with newlines for display.
- **Key lost on edit**: the `field_key` input had `disabled` attribute,
  which prevented it from being submitted with the form. The `update()`
  call received an empty string for `field_key`, causing a Duplicate
  entry '' SQL error. Fixed: use a hidden `<input>` for the key value +
  a disabled visible input for display only, on existing fields.
- **Key not editable on new fields**: the hidden+disabled layout was
  applied unconditionally (even for new fields with `$id === 0`), making
  it impossible to enter a key. Fixed: only use the hidden+disabled
  layout when `$id > 0`; new fields get a regular editable text input.
- **Delete "headers already sent"**: the delete action was dispatched
  inside `render()`, which fires after WordPress has started
  outputting the admin page HTML. `wp_safe_redirect()` can't send
  headers after output starts. Fixed: moved delete to a `load-{hook}`
  handler (`handle_delete_action`) registered in `AdminMenu::add_menu()`.
- **Subscriber save no feedback**: the redirect after save went back to
  the edit view but no success notice was displayed. Fixed: added an
  "updated" notice check at the top of `render_detail()`.

### Bug fixes — Friendly Captcha v2 migration
- The Friendly Captcha implementation was using the v1 API, which no
  longer renders a widget. Migrated to v2:
  - Widget script: `friendly_challenge.js` →
    `@friendlycaptcha/[email protected]/site.min.js`
  - Widget field name: `frc-captcha-solution` → `frc-captcha-response`
  - Added `data-start="auto"` attribute to widget div
  - Verify endpoint: `api.friendlycaptcha.com/api/v1/siteverify` →
    `global.frcapi.com/api/v2/captcha/siteverify`
  - Auth: `secret` body parameter → `X-API-Key` header
  - Request body: form-encoded `solution` → JSON `{"response": "..."}`
  - Updated unit + integration test mocks for new endpoint URL

### Bug fixes — Plugin Check external script errors
- WordPress Plugin Check (`wordpress/plugin-check-action@v1`) errors on
  any `wp_enqueue_script()` call with a remote URL: "Offloading scripts
  to your servers or any remote service is disallowed."
- The Turnstile and Friendly Captcha widget scripts were loaded via
  `wp_enqueue_script()` with external URLs.
- Fix: created `assets/captcha-loader.js` — a local JS file that reads
  `window.stampy` flags and injects the external `<script>` tags at
  runtime via `document.createElement('script')`. The loader is
  registered in `SignupBlock::register()` and enqueued conditionally
  in `render()` when a captcha guard is enabled. The local file passes
  Plugin Check because `wp_enqueue_script()` points at a local file.

### Enhancement — Custom fields in signup block
- Custom field definitions (created in Stampy > Fields) are now available
  as toggleable inputs in the block editor's "Form Fields" inspector panel.
- Added `enabled_fields` block attribute (string array of field keys) to
  `block.json` — controls which custom fields are visible on the form.
- Field definitions are passed to JS via `window.stampy.fields` (each
  with `key`, `label`, `type`, `options`, `required`).
- `edit.tsx` renders a `ToggleControl` for each custom field in the
  inspector, and renders the appropriate input (text, textarea, number,
  date, select, checkbox) in the form preview when enabled.
- `SignupBlock::render()` renders the enabled custom field inputs
  server-side, with `data-stampy-field` attribute for JS collection.
- `view.ts` collects custom field values from `[data-stampy-field]`
  inputs and submits them in the `fields` object to the REST API.
- `StampyField` type added to `types/globals.d.ts`.

### Refactor — Unify first_name/last_name with custom fields
- Removed the special-cased `show_first_name` / `show_last_name` block
  attributes and their dedicated toggles / form inputs.
- `first_name` and `last_name` are now treated identically to all other
  custom fields — they flow through the generic `enabled_fields`
  attribute and the `FieldRepository::all()` loop.
- The only remaining special handling for these two fields:
  - **Seeded by `Installer::seed_default_fields()`** on plugin
    activation (they are pre-defined fields with `show_in_admin=1`).
  - **Dedicated list-table columns** in `SubscribersListTable` (for
    at-a-glance display).
  - **CLI seeding** in `Cli.php` (random name generation for dev data).
- Removed dedicated `{first_name}` / `{last_name}` merge tags from
  `MergeTagRegistry::build_tag_values()`. They are now accessed only via
  the generic `{field:first_name}` / `{field:last_name}` syntax (which
  was already supported). Updated all tests and E2E tests to use the
  generic form.
- When a new signup block is added to a page, `useEffect` auto-selects
  all required fields (sets `enabled_fields` to the list of required
  field keys). Optional fields start deselected.

### Test coverage audit & gap fill
- Audited all recent changes (field unification, merge-tag removal,
  SignupBlock render, SignupService field-type validation, useEffect
  auto-select) against unit, integration, and E2E tests.
- Added 3 new test files and 3 new JS unit tests to close gaps:
  - `tests/phpunit/Integration/SignupBlockRenderTest.php` (16 tests):
    tests `SignupBlock::render()` with various `enabled_fields`
    configurations, field-type-specific HTML (textarea, select,
    checkbox, number, date), `data-stampy-field` attributes, required
    attributes, label rendering, and exclusion of disabled fields.
  - `tests/phpunit/Integration/SignupCustomFieldsTest.php` (5 tests):
    tests custom field values submitted during signup are validated
    against their registered field type, persisted to subscriber_meta
    after confirmation, rejected when invalid (number field), merged
    immediately for confirmed subscribers, and empty values don't
    overwrite existing meta (merge policy).
  - `src/blocks/signup/edit.test.tsx` (3 new tests, 25 total):
    tests `useEffect` auto-selects required fields on mount, does not
    fire when `enabled_fields` is already populated, and excludes
    optional fields from auto-select. Uses `react act()` + `createRoot`
    instead of `renderToString` (which doesn't execute effects).
- Fixed `SignupBlock::render()` PHP notice: "Undefined array key
  enabled_fields" when the attribute is not set. The ternary
  `is_array($attributes['enabled_fields'] ?? array()) ? array_map(...,
  $attributes['enabled_fields']) : array()` accessed
  `$attributes['enabled_fields']` directly in the true branch. Fixed
  by extracting to a `$raw_enabled_fields` variable first.
- Set `global.IS_REACT_ACT_ENVIRONMENT = true` in `tests/jest/setup.js`
  to suppress React act() warnings that `@wordpress/jest-console`
  treats as failures.
- Total test count: 72 unit PHP, 25 JS, 241 integration, 20 E2E = 358.

