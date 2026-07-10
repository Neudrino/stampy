<?php
/**
 * Integration test proving the plugin loads under WordPress.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use WP_UnitTestCase;

/**
 * Confirms the Stampy plugin file was loaded by the WordPress test bootstrap.
 *
 * This suite always runs inside a WordPress test environment (e.g. wp-env),
 * where WP_UnitTestCase is available.
 */
final class PluginActivationTest extends WP_UnitTestCase {

	/**
	 * The plugin's VERSION constant is defined and frozen at 0.0.1.
	 *
	 * A defined \Stampy\VERSION proves stampy.php was loaded via the
	 * muplugins_loaded hook registered in the test bootstrap.
	 *
	 * @return void
	 */
	public function test_plugin_version_constant_is_defined(): void {
		$this->assertTrue(
			defined( 'Stampy\\VERSION' ),
			'The Stampy\\VERSION constant should be defined once the plugin loads.'
		);
		$this->assertSame( '0.0.1', \Stampy\VERSION );
	}
}
