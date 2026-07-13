# Stampy — Development Plan

A WordPress mailing-list plugin named **Stampy** (slug `stampy`, prefix
`stampy_`, namespace `Stampy`; mascot: 🦒 giraffe) with double opt-in signup,
subscriber/list management, a block-editor-based newsletter composer, SMTP
delivery, and open/click tracking. Target: public distribution via the
WordPress.org plugin directory.

**Mascot:** 🦒 — a giraffe (tall, sends things far, keeps an eye on the whole
herd). v1 uses the emoji directly wherever a lightweight visual accent is
plausible (admin notices, empty states, README/readme.txt headers, WP-CLI
output). Actual mascot artwork (menu icon, WP.org banner/icon assets) is a
Phase 10 deliverable — the emoji is a placeholder, not the final asset.

## 1. Scope

**v1 includes:** signup block with double opt-in (email required; optional
first/last-name inputs), **generic subscriber-attribute storage** (arbitrary
fields per subscriber — names, address, IBAN, … — with no schema migration to
add one; see §3), subscriber & list admin, campaign composer, generic SMTP
connector, batched background sending, per-list unsubscribe with preference
page, open/click tracking (toggleable), GDPR integration, i18n, WP.org
compliance. The architecture is explicitly designed so every §10 roadmap item
(field-management UI, central form builder, spam-guard extensions, submission
log) bolts on without contract or schema breaks.

**v1 excludes:** bounce handling (undetectable via generic SMTP), multisite
network-wide data (per-site only, basic network compatibility), segmentation
by attribute values, provider-specific APIs (SendGrid/SES — future transport
adapters), additional runtime Composer dependencies (namespace-collision risk;
would require Strauss/PHP-Scoper prefixing), file-upload form fields
(privacy/liability, near-zero value for mailing lists), image CAPTCHAs
(accessibility failure), any Contact Form 7 dependency — all form features are
native (post-1.0 items live in §10).

**Supported versions:** PHP ≥ 8.3; WordPress ≥ 7.0 (current major as of
release; revisited at each release). CI tests the min/max of both
(PHP 8.3 / latest; WP 7.0 / latest).

**Languages:** PHP (WordPress requirement) for all server-side code; **TypeScript**
for all client-side code (signup block, campaign-editor extensions, admin
screens' JS, and both Jest and Playwright test suites) — see §4 for feasibility
and tooling impact.

## 2. Architecture Decisions

| Area | Decision |
|---|---|
| Subscriber identity | **Email is the sole required datum and the identity**: `UNIQUE`, normalized (lowercased/trimmed); a signup for an existing email upserts into the existing subscriber — never a second row. Surrogate `id` PK kept (stable FK target, cheap joins) |
| Subscriber attributes | Everything beyond email (incl. first/last name) = **generic attribute system**: `fields` definitions table + `subscriber_meta` EAV storage (§3). Adding a field = inserting a `fields` row, never a schema migration. v1 pre-seeds `first_name`/`last_name` |
| Attribute updates | Staged in `pending_signups.payload`, **applied only after the confirmation click** (prevents anonymous overwriting of a confirmed subscriber's data). Merge policy: new non-empty values overwrite; empty values never erase existing data |
| Signup pipeline | Ordered, pluggable stages: **spam-guard chain** (v1: honeypot + IP rate limit + quiz + Cloudflare Turnstile + Friendly Captcha) → **field-type validator registry** (v1 registers `email`, `text`, `acceptance`; Phase 13 adds textarea/number/date/select/checkbox; Phase 17 form builder adds the rest) → staged upsert into `pending_signups` |
| Signup REST contract | `{ form_id: int\|null, email, fields: {key: value}, consent: bool }` from day one; `form_id = null` = built-in block schema. At least one target list is required (no implicit/default list) — the request resolves target lists from the block's saved attributes; a block with no list selected is a no-op front-end and shows an editor notice. The Phase 17 form builder reuses this contract unchanged |
| Subscribers/lists | Custom DB tables; membership status on junction table |
| Campaigns | Custom Post Type (block editor, revisions; subject as postmeta) |
| Campaign lifecycle | Status enum `draft\|sending\|sent\|cancelled` (postmeta); targeted list IDs as postmeta; rendered HTML + plain text **snapshotted at send start** — mid-send edits never affect an in-flight campaign |
| Sending | Bundled Action Scheduler; batched self-rescheduling actions, default **50 recipients/batch** (`stampy_batch_size` filter, no admin UI in v1), never one action per subscriber |
| Send tracking | Custom recipients table with idempotent claiming: a conditional `UPDATE … SET status='sending' WHERE id=? AND status='queued'` claims a recipient (0 affected rows = already taken → skip), guaranteeing no double-send on retries. Rows stuck in `sending` longer than **15 minutes** (`stampy_stuck_send_timeout` filter) are re-queued by the next batch |
| Send failures | A `wp_mail()` failure marks the recipient `failed` and the batch continues; campaign completes with sent/failed counts; claiming guarantees no double-send under Action Scheduler retries; automatic per-recipient retry and a manual "retry failed" action are deferred post-v1 |
| SMTP | Generic host/port/encryption/auth via `phpmailer_init`; all mail through `wp_mail()`; credentials in non-autoloaded options; **From-name / From-email settings** (defaults: site name / admin email; applied to campaigns and transactional mail to avoid the rejected `wordpress@` default); "send test email" feature |
| Capabilities | v1: all Stampy admin (subscribers, lists, campaigns, settings) gated behind the core `manage_options` capability — administrators only. Custom roles/capabilities (e.g. a dedicated "newsletter manager") are a possible post-1.0 addition, not in v1 |
| Email rendering | Restricted block set (paragraph, heading, image, button, list, separator) → table-based email template, CSS inlined, plain-text alternative part. Images referenced by **absolute URL** (hotlinked from the site; no CID/base64 embedding). The renderer **always auto-appends a footer** with the unsubscribe link + CAN-SPAM physical address if the author didn't include `{unsubscribe_url}` — a send is **never blocked** for a missing unsubscribe link |
| Personalization | At send time per recipient via a **merge-tag registry** (`{email}`, `{unsubscribe_url}`, `{field:*}`; `{first_name}` = sugar for `{field:first_name}`), plus tracking pixel and link rewriting — renderer output stays canonical and snapshot-testable |
| Transactional email | Confirmation email = translatable defaults + `apply_filters` customization points; no settings UI in v1 |
| Unsubscribe | Single subscriber-level token; preference page (token-authenticated, no login, all lists + global opt-out) and RFC 8058 `List-Unsubscribe`/`List-Unsubscribe-Post` one-click headers (targeting one list via a signed `list_id` in the URL — see §3 token model). Gmail/Yahoo bulk-sender requirement |
| Tracking | 1×1 pixel (opens) + signed redirect endpoint (clicks); global toggle with per-campaign override, **default OFF** (privacy-conservative; admin opts in); click rewriting covers content links only and **excludes `{unsubscribe_url}`, `mailto:`, and in-page anchors**; disclosed in readme; data covered by GDPR exporters/erasers; Gmail image-proxy accuracy limits documented |
| Consent proof | Timestamps + consent-text version, backed by an **append-only consent-text registry** (version → wording, created_at) so the exact accepted text is always retrievable; no IP storage (rate-limit IPs live only in ephemeral transients, never persisted) |
| Public endpoints | REST API (`register_rest_route`) for signup/confirm/preferences/tracking; confirm-landing and preference **pages are virtual endpoints via rewrite rules** (plugin-controlled URLs, token-authenticated, no dependence on user-created pages) |
| Admin UI | Classic `WP_List_Table` + Settings API (composer = block editor) |
| Throttling | No sends/hour cap in v1; batch size + Action Scheduler's own inter-batch pacing is the only throttle. A configurable rate-limit setting is deferred until real-world SMTP rate-limit complaints justify the added UI/testing surface |
| JS language | TypeScript (`strict: true`), type-stripped by Babel at build time; type-correctness enforced by a separate `tsc --noEmit` CI gate (see §4, §7) |

## 3. Data Model

```
{prefix}_subscribers          id, email (UNIQUE, lowercased/trimmed), status
                              (pending|confirmed|unsubscribed), created_at,
                              confirmed_at, unsubscribed_at, consent_version,
                              unsub_token_hash — INDEX(status)
{prefix}_fields               id, field_key (UNIQUE), label, type
                              (text|textarea|number|date|select|checkbox|…),
                              options (LONGTEXT, JSON-encoded), required,
                              validation (LONGTEXT, JSON-encoded),
                              show_in_admin, created_at
                              — pre-seeded: first_name, last_name
                              — v1 note: `required` is NOT enforced by the
                                built-in signup block (which hardcodes
                                name inputs optional); the Phase 17 form
                                builder is what honors per-field `required`
{prefix}_subscriber_meta      id, subscriber_id, field_key, value (LONGTEXT;
                              multi-value answers JSON-encoded)
                              — UNIQUE(subscriber_id, field_key),
                                INDEX(field_key)
{prefix}_consent_texts        version (PK), text, created_at
                              — append-only registry; a subscriber's
                                consent_version points here
{prefix}_pending_signups      id, subscriber_id, token_hash (UNIQUE),
                              payload (LONGTEXT, JSON-encoded: attributes,
                              target list IDs, consent_version, form_id),
                              created_at, expires_at
                              — UNIQUE(subscriber_id, form_id): a same-form
                                re-signup before confirmation refreshes this
                                row (new payload, fresh token, expires_at
                                reset to +7d, confirmation resent) rather
                                than creating a second row; different forms
                                remain independently pending/confirmable
{prefix}_lists                id, name, slug, description
{prefix}_subscriber_lists     subscriber_id, list_id, status
                              (subscribed|unsubscribed), subscribed_at,
                              unsubscribed_at
                              — UNIQUE(subscriber_id, list_id)
{prefix}_campaign_recipients  id, campaign_id, subscriber_id, status
                              (queued|sending|sent|failed), sent_at,
                              opened_at, clicked_at
                              — UNIQUE(campaign_id, subscriber_id),
                                INDEX(campaign_id, status)
{prefix}_campaigns            = CPT (posts/postmeta: subject, status enum,
                              targeted list IDs, send-time HTML/text snapshot)
{prefix}_campaign_clicks      id, recipient_id, url, clicked_at
{prefix}_submissions          id, subscriber_id (nullable — survives
                              subscriber deletion), email, payload (LONGTEXT,
                              JSON: submitted fields, list_ids, form_id),
                              consent_version, consent_text (LONGTEXT —
                              snapshot of the exact wording at submission time),
                              ip (nullable), created_at — INDEX(subscriber_id),
                              INDEX(created_at) — opt-in (off by default);
                              created in Phase 13 via the migration runner
```

Column conventions (applied uniformly, WordPress-idiomatic):
- Charset/collation from **`$wpdb->get_charset_collate()`** (utf8mb4) on
  every `CREATE TABLE`.
- `id`/FK columns `BIGINT UNSIGNED`; `subscribers.email` `VARCHAR(255)`
  (utf8mb4 UNIQUE index fits within InnoDB limits on MySQL 5.7+/MariaDB 10.2+,
  the effective floor for WP 7.0). `field_key`/`list.slug` `VARCHAR(191)`
  (classic utf8mb4 index-safe length) with UNIQUE.
- `status` columns are short `VARCHAR(20)` validated in PHP (not SQL `ENUM`,
  which `dbDelta` handles poorly and which is painful to extend).
- Timestamps are `DATETIME` (nullable where an event may not have occurred,
  e.g. `confirmed_at`), written in UTC via `current_time('mysql', true)`.
- All boolean-ish flags `TINYINT(1)`; JSON payloads `LONGTEXT` (see below).

Design principles:
- **Email is the identity.** The only datum a signup must provide; duplicate
  signups merge into the existing subscriber (upsert), a second row with the
  same email must never exist (enforced by the UNIQUE constraint).
- **Everything else is an attribute.** Names, address, phone, IBAN, … are
  rows in `subscriber_meta`, typed/validated by their `fields` definition.
  An arbitrary number of fields can be created at runtime — no schema
  migration, no code change in storage, GDPR, or admin layers (all generic).
- **Staged signups.** Each submission creates (or, for a repeat submission of
  the *same* form, refreshes) a `pending_signups` row holding the single-use
  confirmation token *and* the staged payload. On confirm: verify token →
  apply attributes (non-empty overwrites, empty never erases) → add list
  memberships → delete the row. Concurrent pending signups for *different*
  forms are each independently confirmable; a repeat of the *same* form
  updates the existing row (`UNIQUE(subscriber_id, form_id)`), issues a fresh
  token, resets `expires_at`, and resends confirmation.
- **Already-confirmed subscribers skip re-confirmation.** If the email
  already belongs to a `confirmed` subscriber, the submission does **not**
  create a `pending_signups` row or send a confirmation email: new list
  memberships are added immediately and attribute updates applied per the
  merge policy (non-empty overwrites, empty never erases). Double opt-in
  exists to prove ownership of a *new* address; a confirmed subscriber has
  already proven it. (Unsubscribed subscribers re-entering the funnel are
  treated as new → staged + re-confirmed, i.e. the resubscribe flow.)
- **All JSON is stored in `LONGTEXT` columns**, encoded/decoded in PHP —
  never native `JSON` columns. `dbDelta()` (the schema installer) parses
  column definitions with regex and mis-handles `JSON`, producing spurious
  `ALTER TABLE` churn on every run; `LONGTEXT` sidesteps this entirely.

Tokens: separate purposes — expiring single-use confirmation token (lives in
`pending_signups` with its staged payload; validity = `expires_at`, set to
**7 days** from creation); long-lived unsubscribe/preference token (one per
subscriber, on the subscriber row).

Token scheme: each token is **256 bits of CSPRNG** (`random_bytes(32)`),
delivered raw in the URL and stored only as its **SHA-256 hex digest** (fast
constant-time lookup; password-grade hashing is unnecessary because the token
is already high-entropy and unguessable). Tracking and unsubscribe URLs carry
their parameters signed with **`hash_hmac('sha256', …)`** keyed by a
per-site secret generated on activation and stored in a non-autoloaded
option; signatures are verified with `hash_equals`, and any tampering
(e.g. a swapped `list_id`) is rejected.

Unsubscribe token model: a **single** subscriber-level token authenticates
the person. The specific list to act on is carried in the (signed) URL, not
in a separate per-list token:
- **One-click / RFC 8058** header URL targets exactly one list:
  `…/unsubscribe?s={subscriber}&list={list_id}&t={token}`, signed so the
  `list_id` cannot be tampered with. Unsubscribes just that list.
- **Preference page** uses the same token but no `list_id` → shows all the
  subscriber's memberships with per-list toggles + a global opt-out.
This keeps storage to one token per subscriber while still allowing precise
per-list one-click unsubscribes.

Consent proof: `consent_version` is a single pointer per subscriber into the
append-only `consent_texts` registry (latest accepted wording wins; the exact
text of any version stays retrievable). The **consent checkbox is always
required** at signup (backed by the `acceptance` validator — a submission
without it is rejected) and records the current `consent_version`. An
attribute-only or new-list update on an already-confirmed subscriber (which
captures no new checkbox) **leaves `consent_version` unchanged**. Per-submission
proof (which specific submission accepted which text, with a payload snapshot)
is available when the Phase 13 submission log is enabled (off by default).

Membership & status precedence:
- **Subscriber-level `unsubscribed` (global opt-out) wins over everything.**
  Audience resolution selects only subscribers whose row status is
  `confirmed`; a globally opted-out subscriber is suppressed from **all**
  sends regardless of any `subscribed` list junctions. Global opt-out sets
  `subscribers.status = unsubscribed`.
- A returning globally opted-out person who signs up again is treated as
  **new** → staged in `pending_signups` and must **re-confirm** before status
  returns to `confirmed` (the resubscribe flow); no send resumes until then.
- **List-level resubscribe on confirm:** confirming (or an already-confirmed
  add) for a list where the junction row exists as `unsubscribed` **flips it
  back to `subscribed`** and refreshes `subscribed_at` — no duplicate junction
  row (`UNIQUE(subscriber_id, list_id)`).

Lifecycle rules: anti-enumeration on signup (always "check your email");
**daily** cron purge of `pending_signups` rows past `expires_at` (the 7-day
window) — unconfirmed signups and their staged attribute changes vanish
without ever touching live data; audience resolution = subscribers with row
status `confirmed` **and** `subscribed` junction status on targeted lists,
deduplicated.

Orphan cleanup: no DB-level foreign keys (WP convention). When a subscriber
is deleted or GDPR-erased, the erasure routine also removes that subscriber's
`subscriber_meta`, `subscriber_lists`, `pending_signups`, `campaign_recipients`,
and (via those recipients) `campaign_clicks` rows — application-enforced, in a
single transaction where the storage engine allows.

DB migrations: stored `db_version` option + upgrade runner supporting jumps
from any older version (not just the previous one).

Plugin lifecycle (register_activation_hook / register_deactivation_hook /
`plugins_loaded` upgrade check):
- **On activation:** run the migration runner to create/upgrade all tables
  (`dbDelta`); seed the `first_name`/`last_name` field definitions and
  consent-text **version 1** if absent (idempotent); register rewrite rules
  then **flush them once** (required so the virtual confirm/preference
  endpoints resolve — otherwise 404); schedule the **daily** recurring
  `pending_signups` purge (Action Scheduler recurring action, or WP-Cron
  fallback). All steps idempotent (safe on re-activation).
- **On every load (`plugins_loaded`):** compare stored `db_version` to code
  version and run the upgrade runner if behind (covers plugin updates that
  never fire the activation hook); register rewrite rules (no flush).
- **On deactivation:** unschedule the purge action; flush rewrite rules.
  **No data is dropped** — destructive deletion happens only via
  `uninstall.php`, gated behind the "delete data on uninstall" setting (§8).
- **Send batches** run under Action Scheduler; on low-traffic sites WP-Cron
  may fire late, so the readme recommends a real server cron
  (`DISABLE_WP_CRON` + system cron) for timely delivery.

## 4. Repository Layout & Tooling

```
stampy/
├── .github/            # workflows/ (ci.yml, release.yml, codeql.yml),
│                       # dependabot.yml, ISSUE_TEMPLATE/,
│                       # PULL_REQUEST_TEMPLATE.md
├── stampy.php          # plugin header
├── uninstall.php       # opt-in data deletion
├── includes/           # PHP, PSR-4 via Composer
├── src/                # TS/TSX block & admin source (@wordpress/scripts)
├── build/              # compiled assets (gitignored, shipped)
├── languages/          # .pot
├── tests/              # phpunit/ (unit + integration), e2e/ (Playwright, .ts)
├── dev/                # Mailpit compose file, dev mu-plugins
├── .wp-env.json  composer.json  package.json  tsconfig.json
├── phpcs.xml.dist  phpstan.neon.dist  phpunit.xml.dist  .nvmrc
├── SECURITY.md  .distignore  LICENSE (GPLv2 or later)  README.md  readme.txt
```

- **PHP:** Composer autoload (min PHP 8.3); deps: Action Scheduler
  (multi-embed safe); dev: PHPCS+WPCS, PHPStan + `szepeviktor/phpstan-wordpress`,
  `wp-phpunit/wp-phpunit`, `yoast/phpunit-polyfills`, Brain Monkey.
- **JS/TS:** `@wordpress/scripts` (build, Jest, ESLint, Stylelint), Playwright +
  `@wordpress/e2e-test-utils-playwright`. Node pinned to a concrete Active-LTS
  major in `.nvmrc` (**24**) and mirrored in `package.json` `engines` + the CI
  `setup-node` matrix — `@wordpress/scripts` requires a current LTS Node, so the
  exact major is pinned rather than "latest LTS" (which drifts over time).
- **WP-CLI:** `wp stampy seed --subscribers=N` for manual testing/E2E fixtures.
- **Extensibility:** public `do_action`/`apply_filters` at key points
  (subscriber added/confirmed, before send, filter email content).
- Conditional asset loading (block assets only where used); indexes on
  subscriber email/status.
- **Dual-readme rule:** `README.md` is GitHub-facing (dev setup, testing,
  contributing, badges); `readme.txt` is exclusively the WP.org listing
  (user-facing description, FAQ, changelog, `Stable tag`). Never merge the two.

### TypeScript — feasibility & specifics

Confirmed feasible with no build-tool replacement: `wp-scripts` (webpack config)
already resolves and transpiles `.ts`/`.tsx` via Babel (`@babel/preset-typescript`
inside `@wordpress/babel-preset-default`); Jest (`@wordpress/jest-preset-default`)
and Playwright handle `.ts`/`.tsx` test files natively; ESLint auto-enables
`typescript-eslint` as soon as the `typescript` package is present — no manual
parser/plugin wiring needed. Consequences to plan around:

- **Babel only strips types, it does not type-check.** Neither `wp-scripts build`
  nor `wp-scripts test-unit-js` will fail on a type error. A dedicated
  `tsc --noEmit` step (`tsconfig.json`, `strict: true`) is therefore a
  **required, separate CI gate** (folded into §7's lint stage), not optional.
- **Type coverage is uneven across `@wordpress/*` packages.** `@wordpress/blocks`,
  `@wordpress/data`, `@wordpress/components`, `@wordpress/element`,
  `@wordpress/api-fetch`, `@wordpress/compose`, `@wordpress/hooks`,
  `@wordpress/i18n`, `@wordpress/url` ship native, reliable `.d.ts`.
  `@wordpress/block-editor` (used for the campaign composer, Phase 7) does
  **not** yet ship native types — install the community
  `@types/wordpress__block-editor` package and expect occasional gaps/lag for
  brand-new APIs.
- **No official `@wordpress/create-block` TypeScript template.** Since the
  plugin is hand-scaffolded phase by phase anyway (§9), this has no practical
  impact — block folders are simply authored as `.tsx`/`index.ts` from the start.
- **No official typing story for PHP→JS data.** Data passed via
  `wp_localize_script`/`wp_add_inline_script` (e.g. REST nonce, REST URL,
  tracking-toggle flag) must get a hand-written ambient declaration
  (`types/globals.d.ts`, `declare global { interface Window { stampy: {...} } }`)
  kept in sync manually with the PHP side — call this out in code review
  checklists since tooling won't catch drift automatically.
- Block attribute types (from `block.json`) are not auto-inferred either;
  attribute interfaces are hand-typed alongside each block's `edit`/`save`.

### Local/CI Parity — Canonical Commands

**Principle:** `composer.json` and `package.json` `scripts` are the single
source of truth for every check. CI workflow steps only ever invoke these
named scripts — no tool flags or logic are hand-rolled a second time in YAML.
Anything a developer runs on their laptop is *exactly* what CI runs, so a
green local run reliably predicts a green CI run (and vice versa).

For the two checks that depend on a live WordPress instance (PHP integration,
E2E), parity goes one level deeper than "same command": both local dev and CI
execute them **inside the same `wp-env` Docker container** (`wp-env run
tests-cli ...`), so PHP/WP/MySQL versions are identical too, not just the
command invoked. `wp-env`'s Docker runtime works unchanged on GitHub-hosted
`ubuntu-latest` runners (Docker preinstalled), which is what makes this
possible without a separate CI-only test harness.

`composer.json`:
```json
"scripts": {
  "lint": "phpcs",
  "lint:fix": "phpcbf",
  "analyse": "phpstan analyse",
  "test:unit": "phpunit --testsuite=unit",
  "test:integration": "phpunit --testsuite=integration"
}
```
`test:unit` is WP-free (Brain Monkey) and needs no container.
`test:integration` needs a running `wp-env` (`WP_UnitTestCase`, real DB).

`package.json` (orchestration layer; shells into the container only where needed):
```json
"scripts": {
  "lint:js": "wp-scripts lint-js",
  "lint:css": "wp-scripts lint-style",
  "format": "wp-scripts format",
  "type-check": "tsc --noEmit",
  "test:unit:js": "wp-scripts test-unit-js",
  "lint:php": "composer lint",
  "analyse:php": "composer analyse",
  "test:unit:php": "composer test:unit",
  "env:start": "wp-env start",
  "env:destroy": "wp-env destroy",
  "env:clean:tests": "wp-env clean tests",
  "dev:start": "npm-run-all env:start start",
  "start": "wp-scripts start",
  "wp": "wp-env run cli wp",
  "test:integration:php": "wp-env run tests-cli --env-cwd=wp-content/plugins/stampy composer test:integration",
  "test:e2e": "wp-scripts test-playwright",
  "validate:fast": "npm-run-all --continue-on-error lint:js lint:css type-check test:unit:js lint:php analyse:php test:unit:php",
  "validate:docker": "npm-run-all --continue-on-error test:integration:php test:e2e",
  "validate": "npm-run-all env:start && npm-run-all --continue-on-error validate:fast && npm run validate:docker"
}
```

**`validate` failure semantics:** `env:start` must succeed before anything
depending on the container runs (fail hard — a dead container would make
every downstream check falsely "fail"); `--continue-on-error` applies only
*within* the check groups (`validate:fast`, `validate:docker`) so one failing
suite still lets the others report. (The exact wiring is finalised in Phase 0;
the intent is: infra errors fail fast, check failures are collected.)

- **Fast loop (no Docker, seconds):** `npm run validate:fast` — lint, type-check,
  JS+PHP unit tests. Safe to wire into a Husky + `lint-staged` pre-commit hook
  (scoped to staged files) without slowing commits down.
- **Full loop (Docker, minutes):** `npm run validate` — adds integration and
  E2E. This is what a developer runs before opening a PR, and it is the exact
  sequence CI's job list in §7 maps onto, one job per script.
- **Manual-testing loop:** `npm run dev:start` — starts wp-env + Mailpit and
  the JS watch build; then click through the development instance at
  `localhost:8888` (see §5). `npm run wp -- stampy seed --subscribers=50`
  seeds it; `npm run env:clean:tests` resets only the automated-test
  instance, never the manual one.
- Every CI job in §7 is now just `<setup> && npm run <script>` /
  `composer run <script>` — the job list there names which script it calls
  instead of re-describing tool flags.

## 5. Dev Environment (wp-env + Mailpit)

**Dual-instance model — manual testing is first-class from Phase 0.**
`wp-env` starts two independent WordPress instances; everything runs in
Docker containers/volumes, fully isolated from the host system (no host
PHP/MySQL, no other local sites touched; `wp-env destroy` removes it all):

| Instance | URL | Purpose |
|---|---|---|
| **development** | `localhost:8888` (`admin`/`password`) | **Manual testing.** Persistent across restarts; automated tests never touch it |
| **tests** | `localhost:8889` | Integration + E2E runs; freely resettable via `npm run env:clean:tests` without harming manual state |

Both instances mount the plugin source live: PHP changes are immediately
testable in the browser; `npm run dev:start` adds the JS watch build.
`npm run wp -- stampy seed` provides manual-test data; `wp-env start
--xdebug` enables step debugging.

**Mailpit — two isolated instances (no cross-talk between manual and
automated mail):** wp-env cannot add extra containers natively
(compose-override PR unmerged). Working pattern:
- `dev/docker-compose.mailpit.yml` defines **two services**:
  `mailpit-dev` (SMTP 1025 / UI+API `:8025`) — the developer's manual inbox —
  and `mailpit-tests` (SMTP 1026 / UI+API `:8026`) — Playwright's inbox.
  Started idempotently via `lifecycleScripts.afterStart`.
- WordPress reaches them at `host.docker.internal:<port>` (wp-env adds
  host-gateway; verified in Phase 0).
- Per-instance wiring via `.wp-env.json`'s `env.development` / `env.tests`
  config: each WP instance gets a `STAMPY_DEV_SMTP_PORT` constant (1025 vs
  1026), read by the dev-only mu-plugin.
- Dev-only mu-plugin (via `mappings`) hooks `phpmailer_init` → its instance's
  Mailpit; must yield once the plugin's own SMTP settings are configured
  (they point at Mailpit in E2E — doubling as the connector's test).
- Playwright asserts on delivered mail exclusively via the tests Mailpit
  HTTP API (`:8026/api/v1/`); manual mail in `:8025` can never pollute
  E2E assertions, and E2E runs never clutter the manual inbox.

## 6. Testing Strategy (pyramid)

Commands referenced below are the canonical scripts defined in §4's
Local/CI Parity subsection — identical whether run on a laptop or in CI.

1. **Unit (most, `test:unit:php` / `test:unit:js`, no Docker):** WP-free
   domain classes; Brain Monkey to stub WP functions where unavoidable.
   Jest + Testing Library, written in TypeScript, for block components.
   Renderer verified with golden-file HTML snapshots.
2. **Integration (moderate, `test:integration:php`, inside `wp-env`):**
   `WP_UnitTestCase` via wp-phpunit against wp-env's tests DB —
   schema/migrations, REST endpoints (nonces, capabilities, token
   tampering), `wp_mail` interception for email content, PHPMailer config,
   Action Scheduler run synchronously (incl. double-send prevention under
   retry).
3. **E2E (few, critical journeys, `test:e2e`, inside `wp-env` + Mailpit):**
   runs against the **tests** instance (`:8889`) and asserts mail via
   `mailpit-tests` (`:8026`) only — never the manual-testing instance/inbox
   (§5). signup→Mailpit→confirm; admin manages subscribers;
   compose→send→assert N mails with working unsubscribe + tracking links;
   open/click recorded; failure screenshots/traces as artifacts (local: in
   `test-results/`; CI: uploaded via `actions/upload-artifact`). Written in
   TypeScript (`playwright.config.ts` sets `use.baseURL` to
   `http://localhost:8889` and **omits the `webServer` block** so Playwright
   drives the already-running wp-env tests instance rather than spawning its
   own server; Mailpit must be up first, ensured by `env:start`).

Alongside the automated pyramid, every phase is **manually verifiable** on
the persistent development instance (`:8888` + `:8025`) — see the "manual
demo" column hints in §9; the workflow itself is documented in README.md's
"Manual testing" section (Phase 0 deliverable).

## 7. CI/CD (GitHub Actions)

**Model:** GitHub is the source of truth for development (PRs into `main`,
protected by a ruleset requiring all checks). WP.org SVN is a write-only
distribution target, touched exclusively by the release workflow — never
manually.

**Repository:** the public GitHub repo **`Neudrino/stampy`**
(`https://github.com/Neudrino/stampy`) — its URL is the plugin header's
`Plugin URI`. All CI/ruleset/Dependabot/CodeQL setup below targets that repo.

**`.github/workflows/ci.yml` — every PR + push to `main`**
(`concurrency` group cancels superseded PR runs; jobs run in parallel where
independent). Each job is a thin `<setup> && npm run <script>` /
`composer run <script>` wrapper around §4's canonical scripts — no
duplicated tool logic lives in the YAML:
1. **lint** — `lint:js`, `lint:css`, `type-check`, `lint:php`, `analyse:php`
   (catches the type errors Babel silently strips, plus PHPCS/PHPStan)
2. **unit** — `test:unit:php` (PHP matrix 8.3/latest via
   `shivammathur/setup-php`, Composer cache), `test:unit:js` (`actions/setup-node`,
   npm cache) — no Docker needed, mirrors the local fast loop exactly
3. **integration** — `env:start` + `test:integration:php`, WP matrix
   (7.0 / latest); same `wp-env run tests-cli` invocation a developer uses locally
4. **e2e** — `env:start` (incl. Mailpit sidecar) + `test:e2e`; Playwright
   browsers cached; screenshots/traces uploaded via `actions/upload-artifact`
   on failure
5. **build** — `npm run build` (`wp-scripts build`) + `composer install
   --no-dev` verification
6. **plugin-check** — official `wordpress/plugin-check-action`
7. **audit** — `composer audit` / `npm audit`

**Repo visibility:** public from day one (free CodeQL + full Actions minutes;
matches the open, GPL nature of a WP.org plugin; nothing pre-submission needs
hiding).

**Repo automation & hygiene:**
- `.github/dependabot.yml` covering `npm`, `composer`, **and
  `github-actions`** ecosystems; actions pinned to SHAs
- CodeQL scanning for TS/JS from Phase 0 via its own `codeql.yml` workflow
  (CodeQL doesn't support PHP — PHPStan is the PHP static-analysis gate)
- Least-privilege `permissions:` per workflow (default `contents: read`)

**`.github/workflows/release.yml` — on tag push `v*`:**
build (production assets + `composer install --no-dev
--optimize-autoloader`) → verify git tag == plugin-header `Version` ==
readme.txt `Stable tag` (fail fast on mismatch) → zip via `.distignore` →
GitHub Release with zip attached → `10up/action-wordpress-plugin-deploy`
to WP.org SVN (trunk + tag; `assets/` for banner/icon/screenshots).
SVN credentials live as secrets in a **protected GitHub Environment**
(deploy job only, optional required-reviewer gate).

**WP.org publishing:** one-time human-reviewed submission (days–weeks,
slug permanent, GPLv2+ throughout); afterwards releases are "merge, tag, done".
Maintain SemVer, readme changelog, and periodic "Tested up to" bumps.

**Versioning & tags:** **no git tags are created during development** — the
maintainer decides manually, later, when to cut the first release and which
version it carries. The `release.yml` workflow (triggered by a `v*` tag)
therefore lies dormant until that first manual tag. WordPress requires a
`Version:` header for the plugin to activate, so `stampy.php` carries a
**frozen placeholder version `0.0.1`** (mirrored in `readme.txt` `Stable tag`).
This value is *not* a published release, is not tagged, and is **never bumped
during development unless the maintainer explicitly says so**. The release
workflow's tag == header `Version` == `Stable tag` equality check still guards
every future tag once tagging begins.

**Header/readme consistency:** `Requires PHP` (8.3) and `Requires at least`
(WP 7.0) **must be identical** in `stampy.php` and `readme.txt` — Plugin Check
warns on any mismatch. Both are the single source enforced in Phase 0.

## 8. Compliance & Hardening Checklist

- Security: nonces + `current_user_can()` everywhere, `$wpdb->prepare()`,
  sanitize/escape all I/O, hashed tokens, signup rate-limit + honeypot (both
  as guards in the §2 pipeline; rate-limit IPs only in ephemeral transients,
  never persisted), `SECURITY.md` disclosure process using GitHub **private
  vulnerability reporting** (Security Advisories) as the **sole** intake
  channel — no security email is published.
- GDPR: `wp_privacy_personal_data_exporters`/`_erasers` covering subscriber
  rows, **all `subscriber_meta` attributes generically**, pending signups,
  and tracking data; erasure also removes the subscriber's junction, pending,
  recipient, and click rows (application-enforced orphan cleanup, §3);
  unticked consent checkbox by default; consent-version audit backed by the
  consent-text registry; no IP storage.
- CAN-SPAM: required physical-address settings field; unsubscribe presence
  guaranteed by the renderer — if the author omits `{unsubscribe_url}`, a
  standard footer (unsubscribe link + physical address) is auto-appended, so
  every campaign always carries a working unsubscribe (send never blocked).
- i18n: text domain = slug; **all default strings authored in English**
  (source language) — confirmation email, consent-text v1, admin UI, block
  UI. Ship `stampy.pot` **plus a bundled German `de_DE` `.po`/`.mo`** from v1;
  JS translations via `wp_set_script_translations`. (WP.org also serves
  community translations via translate.wordpress.org once listed; the bundled
  `de_DE` guarantees German availability immediately.)
- a11y: WCAG-basics for signup block and admin screens.
- Uninstall: `uninstall.php` gated behind a "delete data on uninstall" setting.
- WP.org: unique prefix everywhere, readme.txt format, no phone-home without
  disclosure, Plugin Check clean.

## 9. Implementation Phases (each ends green & demonstrable)

"Manual demo" = clickable verification on the persistent development
instance (`:8888`, inbox `:8025`, workflow in §5) — available from Phase 0,
independent of all automated runs.

**Project metadata (confirmed):** repo `Neudrino/stampy` (public) →
`Plugin URI` `https://github.com/Neudrino/stampy`; **Author** = `Neudrino`;
Author URI = none; security disclosures via GitHub private vulnerability
reporting only (no email in `SECURITY.md`); **Requires at least:** WP 7.0;
**Requires PHP:** 8.3. Still outstanding: WordPress.org username (needed only
at Phase 18).

| # | Phase | Verification | Manual demo |
|---|---|---|---|
| 0 | Skeleton: plugin header + matching `readme.txt` (Author `Neudrino`, `Plugin URI` `https://github.com/Neudrino/stampy`, `Requires at least: 7.0`, `Requires PHP: 8.3` — **identical in both files**), prefix, LICENSE, SECURITY.md (GitHub private reporting), Composer/npm scripts (§4 Local/CI Parity), tool configs (`phpcs.xml.dist`/`phpstan.neon.dist`/`phpunit.xml.dist`/`.nvmrc` pinned to Node 24), `tsconfig.json`, wp-env + dual Mailpit (verify `host.docker.internal` reachability), Husky pre-commit (`validate:fast`), GitHub Actions `ci.yml` + `codeql.yml` calling the same scripts, Dependabot config, README "Manual testing" section | `npm run validate:fast` green locally; identical CI job green on first PR; both Mailpit UIs reachable | `npm run dev:start` → log in at `:8888`, activate Stampy, open `:8025` |
| 1 | Test harness: 4 suites (Jest/Playwright in TS) + smoke tests, CI matrix, Plugin Check | all suites green in CI | `npm run env:clean:tests` resets `:8889` while `:8888` state survives |
| 2 | Data layer: schema (all JSON in `LONGTEXT`; incl. `fields`/`subscriber_meta`/`pending_signups` with `UNIQUE(subscriber_id, form_id)`, pre-seeded name fields + consent-text v1), migration runner, **activation/deactivation/`plugins_loaded` lifecycle** (create tables, seed, schedule purge, rewrite flush — see §3), repositories (attribute access generic), seeder | integration tests: activation idempotency, schema/CRUD/constraints, email-uniqueness upsert, meta round-trip, migration jump | `npm run wp -- stampy seed --subscribers=50` → inspect tables |
| 3 | Opt-in core (headless): staged signups (`pending_signups` + payload), spam-guard chain + field-type validator registry, consent-text registry, REST signup/confirm (`form_id` + `fields` contract), virtual preference page, one-click unsub, expiry cron | unit + REST tests; merge-policy tests (non-empty overwrites, empty never erases; applied only on confirm); `wp_mail` asserts; curl+Mailpit | curl signup → confirmation mail in `:8025` → click confirm link → open preference page |
| 4 | Signup block in TSX (email + optional first/last-name inputs, consent, list selection **requiring ≥1 list** — editor notice + front-end no-op if none, a11y; attributes deprecation-ready for the Phase 17 form builder) | Jest; first E2E signup→confirm | place block on a page at `:8888`, select a list, sign up in the browser, confirm via `:8025` |
| 5 | Admin subscribers/lists (`WP_List_Table`, bulk actions; subscriber view shows attributes **read-only** — editing status/list membership only; attribute editing + field CRUD land in Phase 13) | capability (`manage_options`)/nonce tests; admin E2E | browse seeded subscribers (attributes visible), change status/lists in wp-admin |
| 6 | SMTP connector: settings, transport, test-send | PHPMailer config test; E2E via own settings→Mailpit | enter Mailpit as SMTP in settings → "send test email" arrives in `:8025` |
| 7 | Campaign CPT + renderer (blocks→email HTML, plain-text, preview); composer extensions in TSX using `@types/wordpress__block-editor` | golden-file snapshots; CPT tests | compose a campaign in the block editor, use the HTML/plain-text preview |
| 8 | Sending engine: audience, send-start HTML/text snapshot, AS batches, claiming, failure marking, per-recipient personalization via merge-tag registry + RFC 8058 headers, progress UI | synchronous-AS tests incl. no-double-send + mid-send-edit isolation; full-send E2E | send to seeded subscribers → N personalized mails in `:8025`, progress UI advances |
| 9 | Tracking & stats: pixel, redirect (excludes unsubscribe/mailto/anchors), campaign stats, global toggle **default off** + per-campaign override | endpoint tests (tamper-rejection); toggle-off = no pixel/rewrite; open/click E2E | enable tracking, open a mail in `:8025`, click a link → stats increment in wp-admin |
| 10 | Compliance & release: privacy hooks, uninstall, i18n (`stampy.pot` + bundled `de_DE` `.po`/`.mo`), a11y, readme, mascot artwork (menu icon, WP.org banner/icon replacing the 🦒 emoji placeholder), `release.yml` + protected GitHub Environment for SVN secrets (deploy dry-run until WP.org approval) | Plugin Check clean; built zip installs on clean wp-env | run privacy export/erase for a subscriber; install the built zip on a fresh `:8888` |
| 11 | Native anti-spam challenge: configurable, accessible question/answer quiz (CF7 `[quiz]` equivalent, e.g. "What is 3 + 4?") as an additional guard in the existing spam-guard pipeline. Deliberately *not* an image CAPTCHA (WCAG failure, OCR-breakable). Admin settings for question/answer pairs; guard plugged into `SpamGuardChain` | unit tests (guard pass/fail, chain integration); integration tests (signup rejected on wrong answer, accepted on correct); E2E | configure a quiz question in settings → signup without correct answer fails → signup with correct answer succeeds |
| 12 | Third-party spam guards: **Cloudflare Turnstile** and **Friendly Captcha** integrations via the `SpamGuardInterface` (site-key settings + server-side verification HTTP call). Both purely additive — plug into the existing `SpamGuardChain`. Admin settings for provider selection, site key, and secret key. Frontend renders the provider's widget in the signup form; backend verifies the token on submit | unit tests (guard pass/fail with mocked HTTP verification); integration tests (signup rejected on invalid/missing token, accepted on valid token); E2E | configure Turnstile (or Friendly Captcha) in settings → signup without solving the challenge fails → signup with solved challenge succeeds |
| 13 | Field management & profiles: admin CRUD UI for `fields` definitions (create/edit/delete custom subscriber attributes — text, textarea, number, date, select, checkbox — with label, type, options, required flag, `show_in_admin` toggle). Subscriber detail view becomes a full profile editor: all attributes (including custom ones) are displayed and editable, not read-only as in Phase 5. `{field:*}` merge tags (e.g. `{field:company}`, `{field:phone}`) become usable in campaign email bodies via the `MergeTagRegistry`. **Purpose:** v1 ships a generic attribute system (`fields` table + `subscriber_meta` EAV storage, validation, and GDPR coverage) but the admin UI is intentionally minimal — the subscriber detail shows attributes read-only (Phase 5 deliberately deferred editing here), and there is no UI to create new field definitions (only `first_name`/`last_name` are pre-seeded). Without this phase, site operators who want to collect more than names (e.g. company, phone, address, birthday) must write SQL or use WP-CLI — they cannot create field definitions or edit subscriber attributes from the admin. This phase closes that gap: operators get a self-service UI to define new subscriber attributes, manage them, and edit any subscriber's full profile. It also unlocks richer campaign personalization — any custom field becomes a merge tag usable in email bodies (`Dear {field:first_name}`, "Your plan: {field:plan_type}"). The `ValidatorRegistry` already supports `text` and `acceptance` types; this phase adds validators for the remaining field types (textarea, number, date, select, checkbox) so new fields are validated on signup. No schema migration needed — all storage is already generic | integration tests (field CRUD, subscriber profile edit, merge-tag replacement in campaign send, validator pass/fail for each new type); E2E | create a custom field "Company" in admin → edit a subscriber profile → set company value → use `{field:company}` in a campaign → verify the merge tag is replaced in the sent email |
| 14 | List management & campaign improvements: (1) **Subscriber list filter** — add a dropdown filter to the subscribers overview that filters by list membership, supporting both "in list X" and "not in list X" modes. (2) **Prominent count display** — make the item count ("N items") shown on the right-hand side next to the filters more visually prominent (larger font, bold, distinct styling) so operators can immediately see how many subscribers match the current filter. (3) **Campaign duplication** — add a "Copy" row action to the campaign list table (next to Edit, Quick Edit, Trash). Clicking it opens the block editor prefilled with the source campaign's content, subject, target lists, and tracking setting — but as a new unsaved draft (not yet persisted). This lets operators use an existing campaign as a template without affecting the original. **Purpose:** v1's subscribers overview can only filter by status, not by list membership — an operator with multiple lists cannot answer "who is in my Newsletter list but not in my Announcements list?" without exporting to CSV. The item count is styled as plain inline text, easy to overlook. Campaigns are create-from-scratch only — there's no way to duplicate an existing campaign as a starting point, forcing operators to rebuild content, subject, and target-list settings from scratch each time | integration tests (list-membership filter returns correct subscribers, "not in list" mode excludes correctly, count reflects filtered results); E2E (filter subscribers by list, verify count, copy a campaign → verify editor opens with prefilled content as unsaved draft) | open subscribers overview → filter by a list → verify count is prominent → go to campaigns → copy a campaign → verify editor opens prefilled but unsaved |
| 15 | Import / Export: subscriber data portability via CSV and JSON. **Export:** (1) export all subscribers; (2) export a specific list's subscribers. Every subscriber export always includes all properties (status, timestamps, consent version) and all custom field values from `subscriber_meta` — no field selection, the export is a complete snapshot. CSV export uses the field definitions (from `FieldRepository`) as column headers, with `email` always first. JSON export uses an array of objects keyed by field key. **Import:** creates a new list (user chooses the list name in the import UI) and imports subscribers into it. The import UI is a React-based admin page (`src/import-export/`) that: (a) accepts a `.csv` or `.json` file upload; (b) parses CSV client-side via **PapaParse** (`papaparse` + `@types/papaparse`) which auto-detects the delimiter, with a **fallback dropdown** letting the user explicitly choose comma / semicolon / tab / pipe / custom if auto-detection fails; (c) parses JSON client-side via native `JSON.parse` (sufficient for any realistic subscriber-import file size — arrays of subscriber objects parse in milliseconds); (d) shows a **live preview of the first 10 rows** rendered as a table before the user commits to the import, so column-mapping issues are visible upfront. The import flow sends the parsed rows (as JSON) to a REST endpoint that validates each email, normalizes it, creates the subscriber (upsert by email), applies all field values via `SubscriberMetaRepository` (merge policy: non-empty overwrites), and adds the subscriber to the chosen list. Subscribers are imported as `confirmed` (the import represents a pre-existing audience, not a fresh opt-in). Non-CSV/JSON files are rejected. Invalid emails are skipped with a per-row error in the import result summary. **Purpose:** without import/export, an operator moving from another platform (Mailchimp, Sendgrid, a CSV export) must hand-write SQL or use WP-CLI to populate Stampy — there's no admin UI path. Export is equally essential: an operator must be able to extract their full subscriber list (with all attributes) at any time for backup, migration, or audit. This phase provides both, with a live preview so the operator can verify the column mapping before committing to a potentially large import | integration tests (export all, export by list, CSV round-trip, JSON round-trip, import creates subscribers + list membership, import upserts existing emails, import skips invalid emails, import respects merge policy, import creates the user-chosen list); E2E | export all subscribers to CSV → re-import the CSV into a new list → verify all subscribers + attributes + list membership are preserved |
| 16 | Submission log: opt-in (off by default) `{prefix}_submissions` table storing a raw snapshot of every signup submission — the submitted email, all field values, the target list IDs, the `form_id`, the exact consent-text version and its wording at the time of submission, the timestamp, and the originating IP (transient, not persisted unless the log is enabled). Retention setting (N days, default 90) with daily cron purge. Covered by GDPR exporters/erasers. **Purpose:** v1's consent proof is a single `consent_version` pointer per subscriber — it records *which* version of the consent text was accepted but not *what exactly was submitted, when, and with which fields*. The submission log closes that gap: it provides per-submission, tamper-evident proof of what the user saw and agreed to at the moment of signup (Flamingo equivalent in the CF7 ecosystem). This strengthens GDPR/DSGVO compliance audit trails — if a regulator or dispute asks "what did this person consent to and what data did they submit on 2024-03-15?", the answer is a single lookup rather than a reconstruction from version registries and merged meta. The log is disabled by default (privacy-conservative); when enabled, site operators get a full audit trail with automatic retention expiry | integration tests (log entry created on signup, consent-text snapshot matches version, GDPR export includes submission, GDPR erase removes submission, retention purge removes expired entries); unit tests (repository CRUD) | enable submission log in settings → perform a signup → inspect the log entry in admin → run GDPR export for the subscriber → verify the submission appears |
| 17 | Central form builder: **Stampy → Forms** admin screen with a new `stampy_form` CPT edited in the block editor. Dedicated field blocks: text, textarea, number, date, select, radio, checkbox-group, hidden, consent — each with its own `block.json` + `edit.tsx` + `save.ts`. A **"Stampy Form" picker block** embeds any form on any page — one form reusable everywhere, edited once, updated everywhere. Per-form settings: target lists (fixed or visitor-selectable), success message, optional redirect URL, per-field custom error messages. Server-side validation resolves `form_id` → parses the form post's blocks → validates the submission against that exact schema. The v1 signup block migrates via a block `deprecated[]` entry. **Purpose:** v1 ships a single, fixed signup block — email + optional first/last name + consent checkbox + list selector. Every signup form across the entire site looks the same and collects the same fields. If an operator wants a "Newsletter" form that only asks for email, a "Webinar registration" form that asks for email + company + job title, or a "Free trial" form that asks for email + name + phone + accepts different terms — they cannot do it. There is one block, one hardcoded field schema, one set of fields. The form builder solves this: operators compose forms visually in the block editor using field blocks, each form has its own schema (which fields, which types, which are required, which lists it targets), and the picker block embeds any form on any page. The server-side validator parses the form's blocks to validate each submission against that exact schema. This is the native replacement for what Contact Form 7 would otherwise provide — multiple reusable, customizable forms with per-form field composition, target lists, and validation rules — with no CF7 dependency. The REST signup contract (`form_id` + `fields`) already accepts `form_id` from day one, the `ValidatorRegistry` already supports extension (Phase 13 added textarea/number/date/select/checkbox validators), and the v1 signup block's attributes are deprecation-ready — so no contract or schema break is needed | integration tests (form CPT registration, field block rendering, server-side block-parsing validation per field type, picker block embedding, signup via custom form creates subscriber with correct fields + list memberships, per-form settings honored); Jest (field block edit components); E2E | create a "Webinar Registration" form with email + company + job title fields → embed it on a page via the picker block → fill it out in the browser → verify the subscriber is created with all custom attributes and added to the correct list |
| 18 | WP.org submission: `stampy` slug submission (search shows no existing `stampy` plugin — slug likely free, confirmed only at submission; have a fallback slug ready), review, enable auto-deploy | plugin live; tag-deploy works | install from WP.org on a clean site |

## 10. Post-1.0 Roadmap (architecture-ready in v1)

Iterative feature releases after the v1.0 MVP, replicating natively what
Contact Form 7 would otherwise provide. **No CF7 dependency — ever.** Each
item plugs into extension points that v1 ships with (extensible REST signup
contract, spam-guard chain, field-type validator registry, merge-tag
registry, generic attribute storage, migration runner, block deprecation
path), so none of them requires a contract or schema break.

The central form builder, field management, spam guards (quiz + Turnstile +
Friendly Captcha), and submission log were originally post-1.0 roadmap items
but have been promoted into the v1 MVP phases (Phases 11–15). The remaining
post-1.0 items are:

Still excluded (deliberate, revisit only with strong justification):
file-upload fields (privacy/liability), image CAPTCHAs (accessibility),
segmentation by attribute values (`subscriber_meta`'s `INDEX(field_key)`
keeps it *possible*, but query-builder UI is out of scope), bounce handling,
provider-specific sending APIs, **automatic tracking-data retention/pruning**
(v1 keeps opens/clicks indefinitely; they are covered by GDPR export/erase but
have no auto-expiry — a retention setting is a post-v1 addition).

