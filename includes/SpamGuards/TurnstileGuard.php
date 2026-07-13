<?php
/**
 * Cloudflare Turnstile spam guard.
 *
 * Verifies the Turnstile token via Cloudflare's siteverify API.
 * Requires a site key + secret key configured in admin settings.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\SpamGuards;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies a Cloudflare Turnstile token server-side.
 */
final class TurnstileGuard implements SpamGuardInterface {

	/**
	 * The Turnstile token field key in the request.
	 *
	 * @var string
	 */
	public const TOKEN_KEY = 'stampy_turnstile_token';

	/**
	 * Cloudflare siteverify endpoint.
	 *
	 * @var string
	 */
	private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * Evaluate the Turnstile token.
	 *
	 * @param array<mixed> $request Signup request data.
	 * @return SpamGuardResult
	 */
	public function check( array $request ): SpamGuardResult {
		$secret = get_option( 'stampy_turnstile_secret_key', '' );

		if ( ! is_string( $secret ) || '' === trim( $secret ) ) {
			return SpamGuardResult::pass();
		}

		$token = isset( $request[ self::TOKEN_KEY ] ) ? (string) $request[ self::TOKEN_KEY ] : '';

		if ( '' === trim( $token ) ) {
			return SpamGuardResult::fail( __( 'Please complete the Turnstile challenge.', 'stampy' ) );
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return SpamGuardResult::fail( __( 'Turnstile verification failed. Please try again.', 'stampy' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['success'] ) || ! $data['success'] ) {
			return SpamGuardResult::fail( __( 'Turnstile verification failed. Please try again.', 'stampy' ) );
		}

		return SpamGuardResult::pass();
	}

	/**
	 * Check whether the Turnstile guard is enabled (secret key configured).
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$secret = get_option( 'stampy_turnstile_secret_key', '' );

		return is_string( $secret ) && '' !== trim( $secret );
	}

	/**
	 * Get the configured site key.
	 *
	 * @return string
	 */
	public static function get_site_key(): string {
		$key = get_option( 'stampy_turnstile_site_key', '' );

		return is_string( $key ) ? $key : '';
	}
}
