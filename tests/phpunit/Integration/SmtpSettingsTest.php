<?php
/**
 * Integration tests for SMTP settings and transport.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Smtp\SmtpSettings;
use Stampy\Smtp\SmtpTransport;
use WP_UnitTestCase;

/**
 * Tests SMTP settings persistence, encryption, phpmailer config, and
 * admin settings page handlers.
 */
class SmtpSettingsTest extends WP_UnitTestCase {

	/**
	 * Clean up options between tests.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		SmtpSettings::delete_all();
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		SmtpSettings::delete_all();
		unset( $_POST, $_REQUEST );
		parent::tearDown();
	}

	/**
	 * Defaults should have empty host (not configured).
	 *
	 * @return void
	 */
	public function test_defaults_not_configured(): void {
		$settings = SmtpSettings::get();

		$this->assertSame( '', $settings['host'] );
		$this->assertFalse( SmtpSettings::is_configured() );
	}

	/**
	 * Saving settings with a host marks as configured.
	 *
	 * @return void
	 */
	public function test_save_marks_configured(): void {
		SmtpSettings::save(
			array(
				'host'       => 'smtp.example.com',
				'port'       => 587,
				'encryption' => 'tls',
				'auth'       => true,
				'username'   => 'user',
				'password'   => 'secret',
			)
		);

		$this->assertTrue( SmtpSettings::is_configured() );
		$this->assertSame( '1', get_option( SmtpSettings::CONFIGURED_KEY ) );
	}

	/**
	 * Saving empty host removes configured flag.
	 *
	 * @return void
	 */
	public function test_save_empty_host_unmarks_configured(): void {
		SmtpSettings::save(
			array(
				'host' => 'smtp.example.com',
				'port' => 587,
			)
		);
		$this->assertTrue( SmtpSettings::is_configured() );

		SmtpSettings::save(
			array(
				'host' => '',
				'port' => 587,
			)
		);
		$this->assertFalse( SmtpSettings::is_configured() );
		$this->assertFalse( get_option( SmtpSettings::CONFIGURED_KEY ) );
	}

	/**
	 * Password should be encrypted in storage and decrypted on retrieval.
	 *
	 * @return void
	 */
	public function test_password_encryption_round_trip(): void {
		SmtpSettings::save(
			array(
				'host'     => 'smtp.example.com',
				'port'     => 587,
				'auth'     => true,
				'username' => 'user',
				'password' => 'my-secret-password',
			)
		);

		$stored = get_option( SmtpSettings::OPTION_KEY, array() );
		$this->assertArrayHasKey( 'password', $stored );
		$this->assertNotSame( 'my-secret-password', $stored['password'] );
		$this->assertTrue( str_starts_with( $stored['password'], 'enc:' ) );

		$retrieved = SmtpSettings::get();
		$this->assertSame( 'my-secret-password', $retrieved['password'] );
	}

	/**
	 * Saving without a new password should preserve the existing one.
	 *
	 * @return void
	 */
	public function test_save_without_password_keeps_existing(): void {
		SmtpSettings::save(
			array(
				'host'     => 'smtp.example.com',
				'port'     => 587,
				'auth'     => true,
				'username' => 'user',
				'password' => 'original-secret',
			)
		);

		SmtpSettings::save(
			array(
				'host'     => 'smtp.new.com',
				'port'     => 465,
				'auth'     => true,
				'username' => 'user',
				'password' => '',
			)
		);

		$retrieved = SmtpSettings::get();
		$this->assertSame( 'smtp.new.com', $retrieved['host'] );
		$this->assertSame( 'original-secret', $retrieved['password'] );
	}

	/**
	 * Disabling auth should clear username and password.
	 *
	 * @return void
	 */
	public function test_disabling_auth_clears_credentials(): void {
		SmtpSettings::save(
			array(
				'host'     => 'smtp.example.com',
				'port'     => 25,
				'auth'     => true,
				'username' => 'user',
				'password' => 'secret',
			)
		);

		SmtpSettings::save(
			array(
				'host'     => 'smtp.example.com',
				'port'     => 25,
				'auth'     => false,
				'username' => 'ignored',
				'password' => 'ignored',
			)
		);

		$retrieved = SmtpSettings::get();
		$this->assertFalse( $retrieved['auth'] );
		$this->assertSame( '', $retrieved['username'] );
		$this->assertSame( '', $retrieved['password'] );
	}

	/**
	 * Invalid encryption value falls back to default.
	 *
	 * @return void
	 */
	public function test_invalid_encryption_falls_back(): void {
		SmtpSettings::save(
			array(
				'host'       => 'smtp.example.com',
				'port'       => 587,
				'encryption' => 'invalid',
			)
		);

		$retrieved = SmtpSettings::get();
		$this->assertSame( 'tls', $retrieved['encryption'] );
	}

	/**
	 * Out-of-range port falls back to default.
	 *
	 * @return void
	 */
	public function test_invalid_port_falls_back(): void {
		SmtpSettings::save(
			array(
				'host' => 'smtp.example.com',
				'port' => 99999,
			)
		);

		$retrieved = SmtpSettings::get();
		$this->assertSame( 587, $retrieved['port'] );
	}

	/**
	 * From email defaults to admin email when not set.
	 *
	 * @return void
	 */
	public function test_from_email_defaults_to_admin(): void {
		$expected = (string) get_option( 'admin_email' );

		$this->assertSame( $expected, SmtpSettings::get_from_email() );
	}

	/**
	 * From name defaults to blogname when not set.
	 *
	 * @return void
	 */
	public function test_from_name_defaults_to_blogname(): void {
		$expected = (string) get_option( 'blogname' );

		$this->assertSame( $expected, SmtpSettings::get_from_name() );
	}

	/**
	 * Options should be stored as non-autoloaded.
	 *
	 * @return void
	 */
	public function test_options_are_non_autoloaded(): void {
		SmtpSettings::save(
			array(
				'host' => 'smtp.example.com',
				'port' => 587,
			)
		);

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				SmtpSettings::OPTION_KEY
			)
		);
		// phpcs:enable

		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	/**
	 * configure_phpmailer should set SMTP properties.
	 *
	 * @return void
	 */
	public function test_configure_phpmailer(): void {
		SmtpSettings::save(
			array(
				'host'       => 'smtp.example.com',
				'port'       => 587,
				'encryption' => 'tls',
				'auth'       => true,
				'username'   => 'testuser',
				'password'   => 'testpass',
			)
		);

		$phpmailer = new \PHPMailer\PHPMailer\PHPMailer( true );

		SmtpTransport::configure_phpmailer( $phpmailer );

		$this->assertSame( 'smtp.example.com', $phpmailer->Host );
		$this->assertSame( 587, $phpmailer->Port );
		$this->assertSame( 'tls', $phpmailer->SMTPSecure );
		$this->assertTrue( $phpmailer->SMTPAuth );
		$this->assertSame( 'testuser', $phpmailer->Username );
		$this->assertSame( 'testpass', $phpmailer->Password );
	}

	/**
	 * configure_phpmailer should not configure when not configured.
	 *
	 * @return void
	 */
	public function test_configure_phpmailer_noop_when_not_configured(): void {
		$phpmailer = new \PHPMailer\PHPMailer\PHPMailer( true );

		$before = $phpmailer->Mailer;

		SmtpTransport::configure_phpmailer( $phpmailer );

		$this->assertSame( $before, $phpmailer->Mailer );
	}

	/**
	 * configure_phpmailer with no encryption sets SMTPAutoTLS false.
	 *
	 * @return void
	 */
	public function test_configure_phpmailer_no_encryption(): void {
		SmtpSettings::save(
			array(
				'host'       => 'mail.example.com',
				'port'       => 25,
				'encryption' => 'none',
				'auth'       => false,
			)
		);

		$phpmailer = new \PHPMailer\PHPMailer\PHPMailer( true );

		SmtpTransport::configure_phpmailer( $phpmailer );

		$this->assertSame( '', $phpmailer->SMTPSecure );
		$this->assertFalse( $phpmailer->SMTPAutoTLS );
		$this->assertFalse( $phpmailer->SMTPAuth );
	}

	/**
	 * From email filter returns configured value when configured.
	 *
	 * @return void
	 */
	public function test_filter_from_email_when_configured(): void {
		SmtpSettings::save(
			array(
				'host'       => 'smtp.example.com',
				'port'       => 587,
				'from_email' => 'newsletter@example.com',
			)
		);

		$this->assertSame( 'newsletter@example.com', SmtpTransport::filter_from_email( 'default@example.com' ) );
	}

	/**
	 * From name filter returns configured value when configured.
	 *
	 * @return void
	 */
	public function test_filter_from_name_when_configured(): void {
		SmtpSettings::save(
			array(
				'host'      => 'smtp.example.com',
				'port'      => 587,
				'from_name' => 'Newsletter Team',
			)
		);

		$this->assertSame( 'Newsletter Team', SmtpTransport::filter_from_name( 'WordPress' ) );
	}

	/**
	 * From email filter passes through when not configured.
	 *
	 * @return void
	 */
	public function test_filter_from_email_passthrough_when_not_configured(): void {
		$this->assertSame( 'default@example.com', SmtpTransport::filter_from_email( 'default@example.com' ) );
	}

	/**
	 * Non-admin user should be denied settings save.
	 *
	 * @return void
	 */
	public function test_non_admin_denied_settings_save(): void {
		$non_admin = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $non_admin );

		$_POST = array(
			'action'            => 'stampy_save_smtp_settings',
			'smtp_host'         => 'smtp.example.com',
			'smtp_port'         => '587',
			'smtp_encryption'   => 'tls',
			'stampy_smtp_nonce' => wp_create_nonce( 'stampy_save_smtp_settings' ),
		);
		$_REQUEST = $_POST;

		$this->expectException( \WPDieException::class );
		\Stampy\Admin\SettingsPage::handle_save_settings();
	}

	/**
	 * Invalid nonce should fail settings save.
	 *
	 * @return void
	 */
	public function test_invalid_nonce_fails_settings_save(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST = array(
			'action'            => 'stampy_save_smtp_settings',
			'smtp_host'         => 'smtp.example.com',
			'smtp_port'         => '587',
			'smtp_encryption'   => 'tls',
			'stampy_smtp_nonce' => 'invalid',
		);
		$_REQUEST = $_POST;

		$this->expectException( \WPDieException::class );
		\Stampy\Admin\SettingsPage::handle_save_settings();
	}

	/**
	 * Settings save via admin_post should persist.
	 *
	 * @return void
	 */
	public function test_handle_save_settings_persists(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ), 1 );

		$_POST = array(
			'action'            => 'stampy_save_smtp_settings',
			'smtp_host'         => 'smtp.admin-save.com',
			'smtp_port'         => '465',
			'smtp_encryption'   => 'ssl',
			'smtp_auth'         => '1',
			'smtp_username'     => 'adminuser',
			'smtp_password'     => 'adminpass',
			'smtp_from_email'   => 'from@example.com',
			'smtp_from_name'    => 'Admin Sender',
			'stampy_smtp_nonce' => wp_create_nonce( 'stampy_save_smtp_settings' ),
		);
		$_REQUEST = $_POST;

		try {
			\Stampy\Admin\SettingsPage::handle_save_settings();
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted.
		}

		$settings = SmtpSettings::get();
		$this->assertSame( 'smtp.admin-save.com', $settings['host'] );
		$this->assertSame( 465, $settings['port'] );
		$this->assertSame( 'ssl', $settings['encryption'] );
		$this->assertTrue( $settings['auth'] );
		$this->assertSame( 'adminuser', $settings['username'] );
		$this->assertSame( 'adminpass', $settings['password'] );
		$this->assertSame( 'from@example.com', $settings['from_email'] );
		$this->assertSame( 'Admin Sender', $settings['from_name'] );
	}

	/**
	 * Test send should fail when not configured.
	 *
	 * @return void
	 */
	public function test_send_test_fails_when_not_configured(): void {
		$this->assertFalse( SmtpTransport::send_test( 'test@example.com' ) );
	}

	/**
	 * Intercept wp_redirect to prevent exit by throwing.
	 *
	 * @return never
	 * @throws \RuntimeException Always.
	 */
	public function intercept_redirect(): never {
		throw new \RuntimeException( 'redirect intercepted' );
	}
}
