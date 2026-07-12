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

add_filter(
	'wp_mail_from',
	/**
	 * Ensure a valid From address so PHPMailer::setFrom() doesn't reject it.
	 *
	 * The default `wordpress@localhost` fails PHPMailer's domain validator
	 * because `localhost` is not a valid RFC domain. This filter runs before
	 * setFrom() in wp_mail(), so it prevents the early return false.
	 *
	 * Yields to the plugin's own From-email setting once SMTP is configured.
	 *
	 * @param string $from_email The default From email address.
	 * @return string
	 */
	static function ( $from_email ) {
		if ( get_option( 'stampy_smtp_configured' ) ) {
			return (string) $from_email;
		}
		return 'wordpress@example.com';
	},
	PHP_INT_MAX
);

add_filter( 'stampy_rate_limit_enabled', '__return_false' );

// Allow self-signed certificates in dev/test (Mailpit uses self-signed certs).
// This filter is applied by SmtpTransport::configure_phpmailer() when the
// plugin's own SMTP settings are configured (i.e., when the dev mu-plugin
// yields its own Mailpit routing).
add_filter(
	'stampy_smtp_options',
	static function (): array {
		return array(
			'ssl' => array(
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'allow_self_signed' => true,
			),
		);
	}
);

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

		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer properties are external library API.
		$phpmailer->isSMTP();
		// host.docker.internal lets the wp-env PHP container reach Mailpit,
		// which is published on the Docker host.
		$phpmailer->Host = 'host.docker.internal';
		$phpmailer->Port = $port;

		// The tests Mailpit (port 1026) requires SMTP auth.
		if ( 1026 === $port ) {
			$phpmailer->SMTPAuth    = true;
			$phpmailer->Username    = 'stampy';
			$phpmailer->Password    = 'testpass123';
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		} else {
			$phpmailer->SMTPAuth    = false;
			$phpmailer->SMTPAutoTLS = false;
		}

		// Accept self-signed certificates (Mailpit uses self-signed).
		$phpmailer->SMTPOptions = array(
			'ssl' => array(
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'allow_self_signed' => true,
			),
		);
		// phpcs:enable
	}
);
