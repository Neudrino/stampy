<?php
/**
 * Spam guard interface.
 *
 * Defines the contract for pluggable spam-guard stages in the signup
 * pipeline. v1 ships honeypot + IP rate-limit; §10 R3/R4 add quiz and
 * third-party guards to the same interface.
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
 * A single stage in the spam-guard chain.
 *
 * Each guard receives the raw signup request and may reject it by
 * returning a SpamGuardResult with `passed = false`.
 */
interface SpamGuardInterface {

	/**
	 * Evaluate the signup request.
	 *
	 * @param array<mixed> $request The raw signup request data:
	 *                              `email`, `fields`, `consent`,
	 *                              `form_id`, `list_ids`, plus any
	 *                              guard-specific extras (e.g. honeypot field).
	 * @return SpamGuardResult
	 */
	public function check( array $request ): SpamGuardResult;
}
