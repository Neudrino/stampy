<?php
/**
 * SMTP transport for Stampy.
 *
 * Hooks into `phpmailer_init` to configure PHPMailer with the stored SMTP
 * settings. Also filters `wp_mail_from` and `wp_mail_from_name` to apply
 * the configured From address / name. Provides a `send_test()` method for
 * the admin settings page.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Smtp;

/**
 * Configures PHPMailer and wp_mail From filters based on Stampy settings.
 */
final class SmtpTransport {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'phpmailer_init', array( self::class, 'configure_phpmailer' ) );
		add_filter( 'wp_mail_from', array( self::class, 'filter_from_email' ) );
		add_filter( 'wp_mail_from_name', array( self::class, 'filter_from_name' ) );
	}

	/**
	 * Configure PHPMailer with stored SMTP settings.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer The PHPMailer instance (passed by reference).
	 * @return void
	 */
	public static function configure_phpmailer( $phpmailer ): void {
		if ( ! SmtpSettings::is_configured() ) {
			return;
		}

		$settings = SmtpSettings::get();

		$phpmailer->isSMTP();
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer properties are external library API.
		$phpmailer->Host = $settings['host'];
		$phpmailer->Port = (int) $settings['port'];

		$encryption = $settings['encryption'];
		if ( 'ssl' === $encryption ) {
			$phpmailer->SMTPSecure = 'ssl';
		} elseif ( 'tls' === $encryption ) {
			$phpmailer->SMTPSecure = 'tls';
		} else {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		}

		if ( $settings['auth'] ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $settings['username'];
			$phpmailer->Password = SmtpSettings::decrypt_password( (string) $settings['password'] );
		} else {
			$phpmailer->SMTPAuth = false;
		}

		// Allow dev/test environments to override SSL verification settings
		// (e.g., for self-signed certificates). Default is secure (verify).
		$smtp_options = apply_filters( 'stampy_smtp_options', array() );
		if ( ! empty( $smtp_options ) ) {
			$phpmailer->SMTPOptions = $smtp_options;
		}
		// phpcs:enable
	}

	/**
	 * Filter the From email address.
	 *
	 * @param string $from_email Default From email.
	 * @return string
	 */
	public static function filter_from_email( $from_email ): string {
		if ( ! SmtpSettings::is_configured() ) {
			return (string) $from_email;
		}

		return SmtpSettings::get_from_email();
	}

	/**
	 * Filter the From name.
	 *
	 * @param string $from_name Default From name.
	 * @return string
	 */
	public static function filter_from_name( $from_name ): string {
		if ( ! SmtpSettings::is_configured() ) {
			return (string) $from_name;
		}

		return SmtpSettings::get_from_name();
	}

	/**
	 * Send a test email.
	 *
	 * @param string $to Recipient email.
	 * @return bool True on success, false on failure.
	 */
	public static function send_test( string $to ): bool {
		if ( ! SmtpSettings::is_configured() ) {
			return false;
		}

		$subject = __( 'Stampy SMTP test', 'stampy' );
		$message = __( 'This is a test email from the Stampy plugin to verify SMTP settings.', 'stampy' );

		return wp_mail( $to, $subject, $message );
	}
}
