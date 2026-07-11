<?php
/**
 * Spam guard result value object.
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
 * Immutable result returned by a SpamGuardInterface::check().
 */
final class SpamGuardResult {

	/**
	 * Whether the request passed this guard.
	 *
	 * @var bool
	 */
	private bool $passed;

	/**
	 * Human-readable reason for rejection (empty when passed).
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Constructor.
	 *
	 * @param bool   $passed True if the request passed.
	 * @param string $reason Rejection reason (empty when passed).
	 */
	public function __construct( bool $passed, string $reason = '' ) {
		$this->passed = $passed;
		$this->reason = $reason;
	}

	/**
	 * Create a "passed" result.
	 *
	 * @return self
	 */
	public static function pass(): self {
		return new self( true );
	}

	/**
	 * Create a "failed" result with a reason.
	 *
	 * @param string $reason Rejection reason.
	 * @return self
	 */
	public static function fail( string $reason ): self {
		return new self( false, $reason );
	}

	/**
	 * Whether the request passed.
	 *
	 * @return bool
	 */
	public function passed(): bool {
		return $this->passed;
	}

	/**
	 * The rejection reason (empty when passed).
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
}
