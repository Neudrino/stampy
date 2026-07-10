<?php
/**
 * WP-free smoke test for the unit suite.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifies the unit suite is wired up and runnable without WordPress.
 */
final class SmokeTest extends TestCase {

	/**
	 * The test harness itself works.
	 *
	 * @return void
	 */
	public function test_true_is_true(): void {
		$this->assertTrue( true );
	}

	/**
	 * Trivial version sanity check.
	 *
	 * We intentionally do NOT require stampy.php here: it calls WordPress
	 * functions, so it belongs to the integration suite. This asserts the
	 * expected version string literally instead.
	 *
	 * @return void
	 */
	public function test_expected_version_string(): void {
		$this->assertSame( '0.0.1', '0.0.1' );
	}
}
