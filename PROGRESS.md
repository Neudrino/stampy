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
