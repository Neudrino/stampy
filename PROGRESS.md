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

### Blocker to clear on resume
- Restart the login session (log out/in or reboot) so the `docker` group
  membership activates. Then `docker ps` should work without `sg docker`, and
  `npm run env:start` can be retried. Until then, wp-env starts only the MySQL
  container and exits early; this needs re-verification once the session is
  restarted (it is not yet confirmed whether the early exit was caused solely
  by the Podman/`DOCKER_HOST` situation or by something in `.wp-env.json`).

---

## Phase 0 — Skeleton

Status: **IN PROGRESS**

### Requirements (from PLAN.md §9 Phase 0)
- [ ] Plugin header in `stampy.php` + matching `readme.txt`
      (Author `Neudrino`, `Plugin URI` `https://github.com/Neudrino/stampy`,
      `Requires at least: 7.0`, `Requires PHP: 8.3`, identical in both files)
- [ ] Version frozen at `0.0.1` in header and `Stable tag`
- [ ] Prefix `stampy_` / namespace `Stampy` established
- [ ] `LICENSE` (GPLv2 or later)
- [ ] `SECURITY.md` (GitHub private vulnerability reporting, no email)
- [ ] Composer scripts (§4 Local/CI Parity): lint, lint:fix, analyse,
      test:unit, test:integration
- [ ] npm scripts (§4 Local/CI Parity), incl. validate:fast / validate
- [ ] Tool configs: `phpcs.xml.dist`, `phpstan.neon.dist`,
      `phpunit.xml.dist`, `.nvmrc` (Node 24)
- [ ] `tsconfig.json` (strict)
- [ ] wp-env (`.wp-env.json`) dual instance + dual Mailpit
      (`dev/docker-compose.mailpit.yml`, dev mu-plugin), verify
      `host.docker.internal` reachability
- [ ] Husky pre-commit (`validate:fast`)
- [ ] GitHub Actions `ci.yml` + `codeql.yml` calling the same scripts
- [ ] Dependabot config (npm, composer, github-actions)
- [ ] README "Manual testing" section

### Verification targets
- [ ] `npm run validate:fast` green (PHP checks via container)
- [ ] CI job green on first PR (deferred until repo push)
- [ ] Both Mailpit UIs reachable (`:8025`, `:8026`)
- [ ] Manual demo: `npm run dev:start` → log in at `:8888`, activate Stampy,
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

### NOT yet verified (blocked on session restart / Docker access)
- `npm run env:start` bringing up BOTH sites (:8888 dev, :8889 tests)
- `host.docker.internal` reachability from WP to Mailpit
- PHP checks via container: `lint:php`, `analyse:php`, `test:unit:php`
- `npm run validate:fast` end-to-end (JS side green; PHP side needs container)
- Both Mailpit UIs reachable (:8025, :8026)
- Manual demo: activate Stampy at :8888
