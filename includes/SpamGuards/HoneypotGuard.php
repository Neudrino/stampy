<?php
/**
 * Honeypot spam guard.
 *
 * A hidden field in the form that should never be filled by a human.
 * Bots tend to fill all fields — a non-empty honeypot = bot.
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
 * Rejects submissions where the honeypot field is non-empty.
 */
final class HoneypotGuard implements SpamGuardInterface {

	/**
	 * The honeypot field key in the request.
	 *
	 * @var string
	 */
	public const FIELD_KEY = 'website_check';

	/**
	 * Evaluate the honeypot field.
	 *
	 * @param array<mixed> $request Signup request data.
	 * @return SpamGuardResult
	 */
	public function check( array $request ): SpamGuardResult {
		$honeypot = $request[ self::FIELD_KEY ] ?? '';

		if ( is_string( $honeypot ) && '' !== trim( $honeypot ) ) {
			return SpamGuardResult::fail( __( 'Spam detected.', 'stampy' ) );
		}

		return SpamGuardResult::pass();
	}
}
