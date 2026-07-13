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

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * If the chain was built via `default_chain()` and no custom guards
	 * were added, guards are evaluated lazily so that option changes
	 * (e.g. quiz questions configured after service construction) take
	 * effect immediately.
	 *
	 * @param array<mixed> $request Signup request data.
	 * @return SpamGuardResult The first failing result, or a pass.
	 */
	public function check( array $request ): SpamGuardResult {
		if ( $this->use_default ) {
			$this->guards = $this->build_default_guards();
		}

		foreach ( $this->guards as $guard ) {
			$result = $guard->check( $request );
			if ( ! $result->passed() ) {
				return $result;
			}
		}

		return SpamGuardResult::pass();
	}

	/**
	 * Whether the chain should lazily rebuild from defaults on next check.
	 *
	 * @var bool
	 */
	private bool $use_default = false;

	/**
	 * Build the default guard list (lazy, respects current option state).
	 *
	 * @return array<int, SpamGuardInterface>
	 */
	private function build_default_guards(): array {
		$guards   = array();
		$guards[] = new HoneypotGuard();

		if ( apply_filters( 'stampy_rate_limit_enabled', true ) ) {
			$guards[] = new RateLimitGuard();
		}

		if ( QuizGuard::is_enabled() ) {
			$guards[] = new QuizGuard();
		}

		if ( TurnstileGuard::is_enabled() ) {
			$guards[] = new TurnstileGuard();
		}

		if ( FriendlyCaptchaGuard::is_enabled() ) {
			$guards[] = new FriendlyCaptchaGuard();
		}

		return $guards;
	}

	/**
	 * Build the default guard chain with v1's built-in guards.
	 *
	 * The chain is built lazily on the first `check()` call so that
	 * option changes (e.g. quiz questions configured after service
	 * construction) take effect immediately.
	 *
	 * @return self
	 */
	public static function default_chain(): self {
		$chain              = new self();
		$chain->use_default = true;
		return $chain;
	}
}
