<?php
/**
 * Integration smoke tests proving the plugin loads under WordPress.
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
	 * The plugin's VERSION constant is defined and non-empty.
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
		$this->assertNotEmpty( \Stampy\VERSION, 'The Stampy\\VERSION constant should be a non-empty string.' );
	}

	/**
	 * The PLUGIN_FILE constant points to the plugin's main file.
	 *
	 * @return void
	 */
	public function test_plugin_file_constant_is_defined(): void {
		$this->assertTrue(
			defined( 'Stampy\\PLUGIN_FILE' ),
			'The Stampy\\PLUGIN_FILE constant should be defined.'
		);
		$this->assertFileExists( \Stampy\PLUGIN_FILE );
	}

	/**
	 * The bootstrap function exists and is callable.
	 *
	 * @return void
	 */
	public function test_bootstrap_function_exists(): void {
		$this->assertTrue(
			function_exists( 'Stampy\\bootstrap' ),
			'The Stampy\\bootstrap() function should exist.'
		);
	}

	/**
	 * The plugin's main file is readable.
	 *
	 * @return void
	 */
	public function test_plugin_file_is_readable(): void {
		$this->assertFileIsReadable( \Stampy\PLUGIN_FILE );
	}
}
