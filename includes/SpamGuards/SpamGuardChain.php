<?php
/**
 * Spam-guard chain.
 *
 * Runs an ordered list of SpamGuardInterface implementations.
 * The chain stops at the first failing guard.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\SpamGuards;

/**
 * Orchestrates the ordered spam-guard pipeline.
 */
final class SpamGuardChain {

	/**
	 * Ordered list of guards.
	 *
	 * @var array<int, SpamGuardInterface>
	 */
	private array $guards = array();

	/**
	 * Add a guard to the end of the chain.
	 *
	 * @param SpamGuardInterface $guard Guard to append.
	 * @return self
	 */
	public function add( SpamGuardInterface $guard ): self {
		$this->guards[] = $guard;
		return $this;
	}

	/**
	 * Run all guards in order. Stops at the first failure.
	 *
	 * @param array<mixed> $request Signup request data.
	 * @return SpamGuardResult The first failing result, or a pass.
	 */
	public function check( array $request ): SpamGuardResult {
		foreach ( $this->guards as $guard ) {
			$result = $guard->check( $request );
			if ( ! $result->passed() ) {
				return $result;
			}
		}

		return SpamGuardResult::pass();
	}

	/**
	 * Build the default guard chain with v1's built-in guards.
	 *
	 * The rate-limit guard can be disabled via the
	 * `stampy_rate_limit_enabled` filter (returns false to disable).
	 *
	 * @return self
	 */
	public static function default_chain(): self {
		$chain = new self();
		$chain->add( new HoneypotGuard() );

		if ( apply_filters( 'stampy_rate_limit_enabled', true ) ) {
			$chain->add( new RateLimitGuard() );
		}

		return $chain;
	}
}
