=== Stampy ===
Contributors: neudrino
Tags: newsletter, mailing list, email campaign, subscription, smtp
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: unreleased
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Self-hosted mailing-list plugin with double opt-in, custom fields, block-editor campaigns, SMTP, tracking, import/export, and GDPR tools.

== Description ==

Stampy is a self-hosted mailing-list plugin for WordPress. No external email service required — connect your own SMTP server and send directly from your site.

**Features**

* **Double opt-in signup block** — a Gutenberg block with email, configurable custom fields, consent checkbox, and list selection. Spam protection via honeypot, rate limiting, optional quiz challenge, Cloudflare Turnstile, and Friendly Captcha.
* **Custom field management** — define custom subscriber attributes (text, textarea, number, date, select, checkbox) with a self-service admin UI. Fields are validated on signup and usable as merge tags in campaigns.
* **Subscriber management** — admin list table with search, status filtering, list membership filtering, bulk actions, and prominent count display. Full per-subscriber profile editor for all attributes.
* **List management** — create and manage multiple mailing lists with subscriber memberships and subscriber counts.
* **Block-editor campaign composer** — compose newsletters in the WordPress block editor. The campaign sidebar plugin provides subject, target list selection, tracking override, send/cancel, progress, and preview — all in one place. Campaign duplication for reusing existing content as a template.
* **SMTP connector** — configure any SMTP server with TLS/SSL encryption and authentication. Test-send from the settings page. Passwords encrypted with libsodium.
* **Batched background sending** — Action Scheduler-powered sending with per-recipient personalization via merge tags, RFC 8058 one-click unsubscribe headers, and stuck-send detection.
* **Open & click tracking** — optional tracking pixel and link rewriting with HMAC-signed URLs. Per-campaign override. Disabled by default for privacy.
* **Import & Export** — export all subscribers or a specific list to CSV or JSON. Import subscribers from CSV or JSON with live preview, delimiter auto-detection, and merge-policy upsert.
* **Submission log** — optional consent audit trail logging every signup submission (email, fields, list IDs, consent text, timestamp). Searchable admin viewer. GDPR export/erase covered.
* **GDPR compliance** — personal data exporters and erasers for subscriber profiles, attributes, list memberships, pending signups, campaign recipient history, click tracking data, and submission log entries. Physical address field for CAN-SPAM compliance.
* **Preference center** — subscribers can manage their list memberships and unsubscribe from a self-hosted preference page.
* **Uninstall cleanup** — all data (tables, options, cron events, campaigns) is removed on plugin deletion (toggle in settings).

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/stampy` directory, or install it through the WordPress plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Navigate to **Stampy → Settings** to configure your SMTP server, physical address, tracking, anti-spam, and compliance settings.
4. Create a mailing list under **Stampy → Lists**.
5. Optionally define custom subscriber fields under **Stampy → Fields**.
6. Place the "Stampy Signup" block on any page or post.
7. Compose a campaign under **Campaigns** in the WordPress admin.

== Frequently Asked Questions ==

= Does Stampy require an external email service? =

No. Stampy connects directly to your own SMTP server. No Mailchimp, SendGrid, or third-party API is needed.

= Is tracking enabled by default? =

No. Open and click tracking is disabled by default for privacy. You can enable it globally in Settings, and override per campaign.

= What spam protection is available? =

Stampy includes honeypot and rate-limiting by default. You can optionally enable a question/answer quiz challenge, Cloudflare Turnstile, or Friendly Captcha in **Stampy → Settings**.

= Can I collect custom subscriber fields? =

Yes. Go to **Stampy → Fields** to define custom attributes (text, textarea, number, date, select, checkbox). Each field can be marked as required and toggled for admin visibility. Custom fields appear in the signup block and are usable as `{field:field_key}` merge tags in campaigns.

= Can I import existing subscribers? =

Yes. Go to **Stampy → Import / Export** to import subscribers from a CSV or JSON file. The import page shows a live preview, auto-detects the CSV delimiter, and creates a new list for the imported subscribers. You can also export all subscribers or a specific list to CSV or JSON.

= What is the submission log? =

The submission log is an optional audit trail that records every signup submission — the email, all field values, target list IDs, the exact consent text, and timestamp. It is enabled by default and can be toggled in **Stampy → Settings**. Entries are searchable by email and auto-deleted when the subscriber is deleted.

= What happens to subscriber data when the plugin is deleted? =

By default, all data is removed on uninstall (tables, options, campaigns, cron events). You can toggle this in **Stampy → Settings → Compliance**. Deactivating the plugin always preserves data.

= Is Stampy GDPR compliant? =

Stampy provides tools that help you meet GDPR requirements, but compliance depends on how you configure and use the plugin. Features include double opt-in consent, a submission audit log, and WordPress privacy-data export/erase integration (admin-initiated via **Tools → Export Personal Data** / **Erase Personal Data**). You are responsible for your privacy policy and lawful basis for processing.

= Can I use a different language? =

Stampy is translation-ready and ships with a German (de_DE) translation. The `.pot` file is in the `languages/` directory.

== Screenshots ==

1. Campaigns — list of all campaigns with status, progress, and tracking columns
2. Subscribers — admin list table with status filter, list filter, and bulk actions
3. Lists — manage mailing lists with subscriber counts
4. Fields — custom field definitions with type, required, and admin-visibility toggles
5. Settings — SMTP configuration, tracking toggle, compliance, anti-spam, and captcha settings
6. Submission Log — consent audit trail with searchable log entries
7. Import/Export — CSV/JSON import and export of subscriber data

== Changelog ==

= unreleased =

* Double opt-in signup block with configurable custom fields, consent checkbox, and list selection.
* Spam protection via honeypot, rate limiting, optional quiz challenge, Cloudflare Turnstile, and Friendly Captcha.
* Custom field management (text, textarea, number, date, select, checkbox) with admin CRUD UI.
* Subscriber management with search, status and list filtering, bulk actions, full profile editor.
* List management with subscriber counts and CRUD.
* Block-editor campaign composer with sidebar plugin (subject, lists, tracking, send/cancel, progress, preview).
* Campaign duplication (copy as new draft).
* SMTP connector with TLS/SSL, authentication, test-send, and libsodium-encrypted passwords.
* Batched background sending via Action Scheduler with merge-tag personalization and RFC 8058 one-click unsubscribe.
* Open/click tracking with HMAC-signed endpoints, per-campaign override, disabled by default.
* Import/Export with CSV/JSON, live preview, delimiter auto-detection, and merge-policy upsert.
* Submission log (consent audit trail) with searchable admin viewer.
* GDPR personal-data export and erase (including submission log entries).
* Preference center and one-click unsubscribe (RFC 8058).
* German (de_DE) translation.
