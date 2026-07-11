<?php
/**
 * Unit tests for the spam-guard chain.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Unit;

use Stampy\SpamGuards\HoneypotGuard;
use Stampy\SpamGuards\RateLimitGuard;
use Stampy\SpamGuards\SpamGuardChain;
use Stampy\SpamGuards\SpamGuardResult;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

use Brain\Monkey;

/**
 * Tests spam guard chain, honeypot, and rate-limit guards.
 */
class SpamGuardTest extends TestCase {

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Monkey\Functions\stubs(
			array(
				'__'       => function ( $text ) {
					return $text;
				},
				'sanitize_text_field' => function ( $text ) {
					return is_string( $text ) ? trim( $text ) : '';
				},
				'wp_unslash' => function ( $value ) {
					return $value;
				},
			)
		);

		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Honeypot should pass when the field is empty.
	 *
	 * @return void
	 */
	public function test_honeypot_passes_when_empty(): void {
		$guard  = new HoneypotGuard();
		$result = $guard->check( array( 'website_check' => '' ) );

		$this->assertTrue( $result->passed() );
	}

	/**
	 * Honeypot should pass when the field is not present.
	 *
	 * @return void
	 */
	public function test_honeypot_passes_when_absent(): void {
		$guard  = new HoneypotGuard();
		$result = $guard->check( array() );

		$this->assertTrue( $result->passed() );
	}

	/**
	 * Honeypot should fail when the field is non-empty.
	 *
	 * @return void
	 */
	public function test_honeypot_fails_when_filled(): void {
		$guard  = new HoneypotGuard();
		$result = $guard->check( array( 'website_check' => 'spam' ) );

		$this->assertFalse( $result->passed() );
		$this->assertNotEmpty( $result->reason() );
	}

	/**
	 * Honeypot should pass when the field is only whitespace (treated as empty).
	 *
	 * @return void
	 */
	public function test_honeypot_passes_when_whitespace(): void {
		$guard  = new HoneypotGuard();
		$result = $guard->check( array( 'website_check' => '   ' ) );

		$this->assertTrue( $result->passed() );
	}

	/**
	 * SpamGuardChain should stop at the first failing guard.
	 *
	 * @return void
	 */
	public function test_chain_stops_at_first_failure(): void {
		$chain = new SpamGuardChain();
		$chain->add( new HoneypotGuard() );

		$result = $chain->check( array( 'website_check' => 'bot' ) );

		$this->assertFalse( $result->passed() );
	}

	/**
	 * SpamGuardChain should pass when all guards pass.
	 *
	 * @return void
	 */
	public function test_chain_passes_when_all_pass(): void {
		$chain = new SpamGuardChain();
		$chain->add( new HoneypotGuard() );

		$result = $chain->check( array( 'website_check' => '' ) );

		$this->assertTrue( $result->passed() );
	}

	/**
	 * SpamGuardResult::pass() should create a passing result.
	 *
	 * @return void
	 */
	public function test_result_pass_factory(): void {
		$result = SpamGuardResult::pass();

		$this->assertTrue( $result->passed() );
		$this->assertSame( '', $result->reason() );
	}

	/**
	 * SpamGuardResult::fail() should create a failing result.
	 *
	 * @return void
	 */
	public function test_result_fail_factory(): void {
		$result = SpamGuardResult::fail( 'blocked' );

		$this->assertFalse( $result->passed() );
		$this->assertSame( 'blocked', $result->reason() );
	}
}
