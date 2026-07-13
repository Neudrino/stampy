<?php
/**
 * Friendly Captcha spam guard.
 *
 * Verifies the Friendly Captcha solution via the Friendly Captcha API.
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
 * Verifies a Friendly Captcha solution server-side.
 */
final class FriendlyCaptchaGuard implements SpamGuardInterface {

	/**
	 * The Friendly Captcha solution field key in the request.
	 *
	 * @var string
	 */
	public const SOLUTION_KEY = 'stampy_friendly_captcha_solution';

	/**
	 * Friendly Captcha v2 siteverify endpoint.
	 *
	 * @var string
	 */
	private const VERIFY_URL = 'https://global.frcapi.com/api/v2/captcha/siteverify';

	/**
	 * Evaluate the Friendly Captcha solution.
	 *
	 * @param array<mixed> $request Signup request data.
	 * @return SpamGuardResult
	 */
	public function check( array $request ): SpamGuardResult {
		$secret = get_option( 'stampy_friendly_captcha_secret_key', '' );

		if ( ! is_string( $secret ) || '' === trim( $secret ) ) {
			return SpamGuardResult::pass();
		}

		$solution = isset( $request[ self::SOLUTION_KEY ] ) ? (string) $request[ self::SOLUTION_KEY ] : '';

		if ( '' === trim( $solution ) ) {
			return SpamGuardResult::fail( __( 'Please complete the captcha challenge.', 'stampy' ) );
		}

		$body = wp_json_encode(
			array(
				'response' => $solution,
			)
		);

		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'headers' => array(
					'X-API-Key'    => $secret,
					'Content-Type' => 'application/json',
				),
				'body'    => false !== $body ? $body : '',
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return SpamGuardResult::fail( __( 'Captcha verification failed. Please try again.', 'stampy' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['success'] ) || ! $data['success'] ) {
			return SpamGuardResult::fail( __( 'Captcha verification failed. Please try again.', 'stampy' ) );
		}

		return SpamGuardResult::pass();
	}

	/**
	 * Check whether the Friendly Captcha guard is enabled (secret key configured).
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$secret = get_option( 'stampy_friendly_captcha_secret_key', '' );

		return is_string( $secret ) && '' !== trim( $secret );
	}

	/**
	 * Get the configured site key.
	 *
	 * @return string
	 */
	public static function get_site_key(): string {
		$key = get_option( 'stampy_friendly_captcha_site_key', '' );

		return is_string( $key ) ? $key : '';
	}
}
