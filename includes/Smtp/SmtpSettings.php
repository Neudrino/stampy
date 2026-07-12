<?php
/**
 * SMTP settings storage for Stampy.
 *
 * Stores SMTP configuration in non-autoloaded options so credentials are
 * not loaded on every page request. Provides defaults for From-name and
 * From-email (site name / admin email). Passwords are encrypted with
 * libsodium (crypto_secretbox) keyed from the per-site HMAC secret.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Smtp;

use Stampy\Security;

/**
 * Manages SMTP settings persistence and retrieval.
 */
final class SmtpSettings {

	/**
	 * Option key for the full SMTP configuration.
	 */
	public const OPTION_KEY = 'stampy_smtp_settings';

	/**
	 * Option key for the "configured" flag (checked by the dev mu-plugin
	 * to decide whether to yield its own Mailpit routing).
	 */
	public const CONFIGURED_KEY = 'stampy_smtp_configured';

	/**
	 * Default settings (empty host = not configured).
	 *
	 * @return array{
	 *   host: string,
	 *   port: int,
	 *   encryption: string,
	 *   auth: bool,
	 *   username: string,
	 *   password: string,
	 *   from_email: string,
	 *   from_name: string,
	 * }
	 */
	public static function defaults(): array {
		return array(
			'host'       => '',
			'port'       => 587,
			'encryption' => 'tls',
			'auth'       => false,
			'username'   => '',
			'password'   => '',
			'from_email' => '',
			'from_name'  => '',
		);
	}

	/**
	 * Get the current SMTP settings, merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$merged = array_merge( self::defaults(), $stored );

		$merged['password'] = self::decrypt_password( (string) $merged['password'] );

		return $merged;
	}

	/**
	 * Save SMTP settings.
	 *
	 * @param array<string, mixed> $settings Raw input (will be sanitized).
	 * @return void
	 */
	public static function save( array $settings ): void {
		$clean = self::sanitize( $settings );

		update_option( self::OPTION_KEY, $clean, false );

		$is_configured = '' !== $clean['host'];
		if ( $is_configured ) {
			update_option( self::CONFIGURED_KEY, '1', false );
		} else {
			delete_option( self::CONFIGURED_KEY );
		}
	}

	/**
	 * Check whether SMTP is configured (host non-empty).
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$settings = self::get();

		return '' !== $settings['host'];
	}

	/**
	 * Get the effective From email (setting or admin email fallback).
	 *
	 * @return string
	 */
	public static function get_from_email(): string {
		$settings = self::get();

		if ( '' !== $settings['from_email'] ) {
			return $settings['from_email'];
		}

		return (string) get_option( 'admin_email' );
	}

	/**
	 * Get the effective From name (setting or site name fallback).
	 *
	 * @return string
	 */
	public static function get_from_name(): string {
		$settings = self::get();

		if ( '' !== $settings['from_name'] ) {
			return $settings['from_name'];
		}

		return (string) get_option( 'blogname' );
	}

	/**
	 * Delete all SMTP settings (used by uninstall).
	 *
	 * @return void
	 */
	public static function delete_all(): void {
		delete_option( self::OPTION_KEY );
		delete_option( self::CONFIGURED_KEY );
	}

	/**
	 * Sanitize raw settings input.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array{
	 *   host: string,
	 *   port: int,
	 *   encryption: string,
	 *   auth: bool,
	 *   username: string,
	 *   password: string,
	 *   from_email: string,
	 *   from_name: string,
	 * }
	 */
	private static function sanitize( array $input ): array {
		$defaults = self::defaults();

		$host       = isset( $input['host'] ) ? sanitize_text_field( (string) $input['host'] ) : '';
		$port       = isset( $input['port'] ) ? (int) $input['port'] : $defaults['port'];
		$encryption = isset( $input['encryption'] ) ? sanitize_key( (string) $input['encryption'] ) : $defaults['encryption'];
		$auth       = ! empty( $input['auth'] );
		$username   = isset( $input['username'] ) ? sanitize_text_field( (string) $input['username'] ) : '';
		$password   = isset( $input['password'] ) ? (string) $input['password'] : '';
		$from_email = isset( $input['from_email'] ) ? sanitize_email( (string) $input['from_email'] ) : '';
		$from_name  = isset( $input['from_name'] ) ? sanitize_text_field( (string) $input['from_name'] ) : '';

		if ( ! in_array( $encryption, array( '', 'none', 'ssl', 'tls' ), true ) ) {
			$encryption = $defaults['encryption'];
		}

		if ( '' === $encryption ) {
			$encryption = 'none';
		}

		if ( $port < 1 || $port > 65535 ) {
			$port = $defaults['port'];
		}

		if ( ! $auth ) {
			$username = '';
			$password = '';
		}

		// Keep existing password only when auth is enabled but the
		// password field was left blank (user didn't type a new one).
		if ( $auth && '' === $password ) {
			$existing = self::get_stored();
			$password = isset( $existing['password'] ) ? (string) $existing['password'] : '';
		} elseif ( $auth && '' !== $password ) {
			$password = self::encrypt_password( $password );
		}

		return array(
			'host'       => $host,
			'port'       => $port,
			'encryption' => $encryption,
			'auth'       => $auth,
			'username'   => $username,
			'password'   => $password,
			'from_email' => $from_email,
			'from_name'  => $from_name,
		);
	}

	/**
	 * Get the raw stored settings (without decryption).
	 *
	 * @return array<string, mixed>
	 */
	private static function get_stored(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return $stored;
	}

	/**
	 * Derive a 32-byte key from the per-site HMAC secret.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function encryption_key(): string {
		$secret = Security::get_secret();

		$hex = substr( $secret, 0, 64 );

		$bin = hex2bin( $hex );
		if ( false === $bin ) {
			return str_repeat( "\0", 32 );
		}

		return substr( $bin, 0, 32 );
	}

	/**
	 * Encrypt a password for storage.
	 *
	 * @param string $password Plaintext password.
	 * @return string Encrypted password (base64 of nonce+ciphertext, prefixed with 'enc:').
	 */
	private static function encrypt_password( string $password ): string {
		if ( '' === $password ) {
			return '';
		}

		$key   = self::encryption_key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$ciphertext = sodium_crypto_secretbox( $password, $nonce, $key );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Benign: encoding encrypted password for storage.
		return 'enc:' . base64_encode( $nonce . $ciphertext );
		// phpcs:enable
	}

	/**
	 * Decrypt a stored password.
	 *
	 * @param string $password Stored password (may be encrypted or plaintext).
	 * @return string Plaintext password.
	 */
	public static function decrypt_password( string $password ): string {
		if ( '' === $password ) {
			return '';
		}

		if ( ! str_starts_with( $password, 'enc:' ) ) {
			return $password;
		}

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Benign: decoding stored encrypted password.
		$raw = base64_decode( substr( $password, 4 ), true );
		// phpcs:enable
		if ( false === $raw ) {
			return '';
		}

		$nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( strlen( $raw ) < $nonce_len ) {
			return '';
		}

		$nonce      = substr( $raw, 0, $nonce_len );
		$ciphertext = substr( $raw, $nonce_len );

		$key   = self::encryption_key();
		$plain = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );

		return false === $plain ? '' : (string) $plain;
	}
}
