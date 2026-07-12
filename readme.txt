=== Stampy ===
Contributors: neudrino
Tags: newsletter, mailing list, subscribers, email, double opt-in, smtp, campaign, tracking
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted mailing-list plugin with double opt-in signup, block-editor campaign composer, SMTP delivery, and open/click tracking.

== Description ==

Stampy is a self-hosted mailing-list plugin for WordPress. No external email service required — connect your own SMTP server and send directly from your site.

**Features**

* **Double opt-in signup block** — a Gutenberg block with email, optional first/last name fields, consent checkbox, and list selection. Spam protection via honeypot and rate limiting.
* **Subscriber management** — admin list table with search, status filtering, and bulk actions. Per-subscriber attribute storage (EAV) with generic field definitions.
* **List management** — create and manage multiple mailing lists with subscriber memberships.
* **Block-editor campaign composer** — compose newsletters in the WordPress block editor. The campaign sidebar plugin provides subject, target list selection, tracking override, send/cancel, progress, and preview — all in one place.
* **SMTP connector** — configure any SMTP server with TLS/SSL encryption and authentication. Test-send from the settings page. Passwords encrypted with libsodium.
* **Batched background sending** — Action Scheduler-powered sending with per-recipient personalization, RFC 8058 one-click unsubscribe headers, and stuck-send detection.
* **Open & click tracking** — optional tracking pixel and link rewriting with HMAC-signed URLs. Per-campaign override. Disabled by default for privacy.
* **GDPR compliance** — personal data exporters and erasers for subscriber profiles, attributes, list memberships, pending signups, campaign recipient history, and click tracking data. Physical address field for CAN-SPAM compliance.
* **Preference center** — subscribers can manage their list memberships and unsubscribe from a self-hosted preference page.
* **Uninstall cleanup** — all data (tables, options, cron events, campaigns) is removed on plugin deletion (toggle in settings).

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/stampy` directory, or install it through the WordPress plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Navigate to **Stampy → Settings** to configure your SMTP server and physical address.
4. Create a mailing list under **Stampy → Lists**.
5. Place the "Stampy Signup" block on any page or post.
6. Compose a campaign under **Campaigns** in the WordPress admin.

== Frequently Asked Questions ==

= Does Stampy require an external email service? =

No. Stampy connects directly to your own SMTP server. No Mailchimp, SendGrid, or third-party API is needed.

= Is tracking enabled by default? =

No. Open and click tracking is disabled by default for privacy. You can enable it globally in Settings, and override per campaign.

= What happens to subscriber data when the plugin is deleted? =

By default, all data is removed on uninstall (tables, options, campaigns, cron events). You can toggle this in **Stampy → Settings → Compliance**. Deactivating the plugin always preserves data.

= Is Stampy GDPR compliant? =

Stampy registers personal-data exporters and erasers with the WordPress privacy tools. Subscribers can export or erase all their data (profile, attributes, list memberships, campaign history, clicks) from **Tools → Export Personal Data** / **Erase Personal Data**.

= Can I use a different language? =

Stampy is translation-ready and ships with a German (de_DE) translation. The `.pot` file is in the `languages/` directory.

== Screenshots ==

1. Stampy admin — subscriber list with status filter and bulk actions
2. Campaign composer — block editor with sidebar plugin (subject, lists, tracking, send/progress)
3. Settings page — SMTP configuration, tracking toggle, compliance settings
4. Signup block — double opt-in form with email, name fields, and consent checkbox

== Changelog ==

= 0.0.1 =

* Initial pre-release.
* Double opt-in signup block with honeypot and rate-limit spam protection.
* Subscriber and list management with bulk actions.
* Block-editor campaign composer with sidebar plugin.
* SMTP connector with TLS/SSL, authentication, and test-send.
* Batched background sending via Action Scheduler.
* Open/click tracking with HMAC-signed endpoints.
* GDPR personal-data export and erase.
* Preference center and one-click unsubscribe (RFC 8058).
* German (de_DE) translation.
