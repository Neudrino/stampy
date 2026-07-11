<?php
/**
 * IP rate-limit spam guard.
 *
 * Limits signups per IP address using ephemeral WordPress transients.
 * No IP is ever persisted — the transient expires automatically.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\SpamGuards;

/**
 * Rejects submissions exceeding the per-IP rate limit.
 */
final class RateLimitGuard implements SpamGuardInterface {

	/**
	 * Maximum signups per window from a single IP.
	 *
	 * @var int
	 */
	private int $limit;

	/**
	 * Window in seconds.
	 *
	 * @var int
	 */
	private int $window;

	/**
	 * Constructor.
	 *
	 * @param int $limit  Max signups per window (default: 5).
	 * @param int $window Window in seconds (default: 3600 = 1 hour).
	 */
	public function __construct( int $limit = 5, int $window = HOUR_IN_SECONDS ) {
		$this->limit  = $limit;
		$this->window = $window;
	}

	/**
	 * Get the client IP address (X-Forwarded-For aware).
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return $remote_addr;
	}

	/**
	 * Build the transient key for a given IP.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	private function transient_key( string $ip ): string {
		return 'stampy_rl_' . md5( $ip );
	}

	/**
	 * Evaluate the rate limit.
	 *
	 * @param array<mixed> $request Signup request data.
	 * @return SpamGuardResult
	 */
	public function check( array $request ): SpamGuardResult {
		$ip = $this->get_client_ip();

		if ( '' === $ip ) {
			return SpamGuardResult::pass();
		}

		$key   = $this->transient_key( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $this->limit ) {
			return SpamGuardResult::fail( __( 'Too many signups from your IP. Please try again later.', 'stampy' ) );
		}

		set_transient( $key, $count + 1, $this->window );

		return SpamGuardResult::pass();
	}
}
