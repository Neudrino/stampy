<?php
/**
 * Token and security utilities.
 *
 * Handles CSPRNG token generation, SHA-256 hashing, and HMAC URL signing.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy;

/**
 * Static utility class for token generation and verification.
 */
final class Security {

	/**
	 * Option key for the per-site HMAC secret.
	 */
	public const SECRET_OPTION = 'stampy_hmac_secret';

	/**
	 * Generate a 256-bit (32-byte) CSPRNG token as a hex string.
	 *
	 * @return string 64-character hex string.
	 */
	public static function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Hash a token for storage (SHA-256 hex digest).
	 *
	 * @param string $token Raw token.
	 * @return string 64-character hex digest.
	 */
	public static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Verify a raw token against a stored hash using constant-time comparison.
	 *
	 * @param string $token Raw token.
	 * @param string $hash  Stored SHA-256 hex digest.
	 * @return bool
	 */
	public static function verify_token( string $token, string $hash ): bool {
		return hash_equals( $hash, self::hash_token( $token ) );
	}

	/**
	 * Get the per-site HMAC secret, generating one if absent.
	 *
	 * @return string 64-character hex string.
	 */
	public static function get_secret(): string {
		$secret = get_option( self::SECRET_OPTION );

		if ( ! is_string( $secret ) || 64 !== strlen( $secret ) ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( self::SECRET_OPTION, $secret, false );
		}

		return $secret;
	}

	/**
	 * Sign a set of parameters with an HMAC.
	 *
	 * @param array<string, mixed> $params Parameters to sign.
	 * @return string 64-character hex signature.
	 */
	public static function sign( array $params ): string {
		$secret = self::get_secret();
		$data   = wp_json_encode( $params );

		return hash_hmac( 'sha256', (string) $data, $secret );
	}

	/**
	 * Verify an HMAC signature against a set of parameters.
	 *
	 * @param array<string, mixed> $params    Parameters to verify.
	 * @param string               $signature Hex signature.
	 * @return bool
	 */
	public static function verify( array $params, string $signature ): bool {
		$expected = self::sign( $params );

		return hash_equals( $expected, $signature );
	}
}
