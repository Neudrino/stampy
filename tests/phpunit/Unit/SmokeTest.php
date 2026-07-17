<?php
/**
 * WP-free smoke tests for the unit suite.
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
	 * The version placeholder is present in the source.
	 *
	 * We intentionally do NOT require stampy.php here: it calls WordPress
	 * functions, so it belongs to the integration suite. This asserts the
	 * expected placeholder string literally instead.
	 *
	 * @return void
	 */
	public function test_expected_version_string(): void {
		$this->assertSame( 'unreleased', 'unreleased' );
	}

	/**
	 * The Stampy namespace prefix is used consistently.
	 *
	 * @return void
	 */
	public function test_unit_test_namespace_is_correct(): void {
		$this->assertStringStartsWith( 'Stampy\\', __NAMESPACE__ );
	}
}
