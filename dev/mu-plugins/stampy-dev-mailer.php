<?php
/**
 * Stampy Dev Mailer (mu-plugin)
 *
 * Development-only helper that routes all outgoing WordPress mail to a local
 * Mailpit instance so nothing escapes to the real world during development and
 * testing. It is mounted into wp-env via .wp-env.json and lives under dev/,
 * which is excluded from the shipped plugin zip by .distignore. It is NEVER
 * shipped to production.
 *
 * The target SMTP port is chosen per wp-env instance via the
 * STAMPY_DEV_SMTP_PORT constant:
 *   - development -> 1025 (Mailpit UI http://localhost:8025)
 *   - tests       -> 1026 (Mailpit UI http://localhost:8026)
 *
 * @package Stampy\Dev
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'phpmailer_init',
	/**
	 * Point PHPMailer at the local Mailpit SMTP server.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer The mailer instance (by reference).
	 * @return void
	 */
	static function ( $phpmailer ): void {
		// YIELD to the real plugin: once Stampy's own SMTP settings are
		// configured (a later phase sets the `stampy_smtp_configured` option),
		// this dev mailer must do nothing so it does not override the plugin's
		// real SMTP transport under test. Deleting/leaving the option unset
		// re-enables Mailpit routing for plain dev work.
		if ( get_option( 'stampy_smtp_configured' ) ) {
			return;
		}

		$port = defined( 'STAMPY_DEV_SMTP_PORT' ) ? (int) STAMPY_DEV_SMTP_PORT : 1025;

		$phpmailer->isSMTP();
		// host.docker.internal lets the wp-env PHP container reach Mailpit,
		// which is published on the Docker host.
		$phpmailer->Host       = 'host.docker.internal';
		$phpmailer->Port       = $port;
		$phpmailer->SMTPAuth   = false;
		$phpmailer->SMTPAutoTLS = false;
	}
);
