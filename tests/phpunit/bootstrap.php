<?php
/**
 * PHPUnit bootstrap for the Stampy plugin test suites.
 *
 * This bootstrap serves both the WP-free "unit" suite (Brain Monkey) and the
 * "integration" suite (WP_UnitTestCase via wp-phpunit). It always loads the
 * Composer autoloader, and only loads the WordPress test framework when an
 * integration run is requested — so a unit-only run never fatals if the WP
 * test libraries are absent.
 *
 * @package Stampy
 */

declare( strict_types=1 );

/*
 * 1. Composer autoloader (guarded).
 *
 * Provides PSR-4 autoloading for both the plugin ("Stampy\") and the test
 * classes ("Stampy\Tests\"), plus dev dependencies such as Brain Monkey,
 * Mockery, and the Yoast PHPUnit polyfills.
 */
$stampy_autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( ! is_readable( $stampy_autoload ) ) {
	fwrite(
		STDERR,
		"Could not find vendor/autoload.php. Run \"composer install\" first.\n"
	);
	exit( 1 );
}
require_once $stampy_autoload;

/*
 * 2. Decide whether to boot the WordPress integration test framework.
 *
 * Integration mode is enabled when either:
 *   - the WP_PHPUNIT__DIR environment variable is set (wp-phpunit is present), or
 *   - the STAMPY_TEST_INTEGRATION environment variable is truthy.
 *
 * The unit suite needs nothing further from this file: individual unit tests
 * manage their own Brain\Monkey setUp()/tearDown() lifecycle.
 */
$stampy_wp_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' );
$stampy_force_integration = (bool) getenv( 'STAMPY_TEST_INTEGRATION' );

if ( false === $stampy_wp_phpunit_dir && ! $stampy_force_integration ) {
	// Unit-only run: nothing more to load.
	return;
}

/*
 * 3. Locate the wp-phpunit bootstrap.
 *
 * Prefer the path advertised via WP_PHPUNIT__DIR (set automatically by the
 * wp-phpunit/wp-phpunit Composer package), and fall back to the vendored copy.
 */
if ( false === $stampy_wp_phpunit_dir || '' === $stampy_wp_phpunit_dir ) {
	$stampy_wp_phpunit_dir = dirname( __DIR__, 2 ) . '/vendor/wp-phpunit/wp-phpunit';
}

$stampy_wp_tests_functions = rtrim( $stampy_wp_phpunit_dir, '/\\' ) . '/includes/functions.php';
$stampy_wp_tests_bootstrap = rtrim( $stampy_wp_phpunit_dir, '/\\' ) . '/includes/bootstrap.php';

if ( ! is_readable( $stampy_wp_tests_functions ) || ! is_readable( $stampy_wp_tests_bootstrap ) ) {
	fwrite(
		STDERR,
		sprintf(
			"Could not find the wp-phpunit test framework in \"%s\".\n" .
			"Install it via Composer and/or set WP_PHPUNIT__DIR.\n",
			$stampy_wp_phpunit_dir
		)
	);
	exit( 1 );
}

// Give access to tests_add_filter() before the WP test suite loads.
require_once $stampy_wp_tests_functions;

/**
 * Register the Stampy plugin so it loads inside the WordPress test install.
 *
 * @return void
 */
function stampy_manually_load_plugin(): void {
	require dirname( __DIR__, 2 ) . '/stampy.php';
}
tests_add_filter( 'muplugins_loaded', 'stampy_manually_load_plugin' );

// Start up the WordPress testing environment.
require $stampy_wp_tests_bootstrap;
