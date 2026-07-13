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
 * 1. Decide whether to boot the WordPress integration test framework.
 *
 * Integration mode is enabled when the STAMPY_TEST_INTEGRATION environment
 * variable is truthy.
 *
 * We intentionally do NOT use WP_PHPUNIT__DIR as a trigger: wp-phpunit's
 * Composer autoloader (__loaded.php) calls putenv('WP_PHPUNIT__DIR=...')
 * unconditionally, and PHPUnit's bin proxy loads the autoloader before this
 * bootstrap file runs — so WP_PHPUNIT__DIR is always set by the time we get
 * here, even for unit-only runs.
 *
 * The unit suite needs nothing further from this file: individual unit tests
 * manage their own Brain\Monkey setUp()/tearDown() lifecycle.
 */
$stampy_force_integration = (bool) getenv( 'STAMPY_TEST_INTEGRATION' );

/*
 * 2. Composer autoloader (guarded).
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

if ( ! $stampy_force_integration ) {
	// Unit-only run: define ABSPATH so that class files with
	// `if ( ! defined( 'ABSPATH' ) ) { exit; }` guards don't kill
	// the process when the autoloader loads them.
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__, 2 ) );
	}
	return;
}

/*
 * 3. Locate the wp-phpunit bootstrap.
 *
 * WP_PHPUNIT__DIR is set automatically by the wp-phpunit/wp-phpunit Composer
 * package's autoloaded __loaded.php file. Fall back to a vendored path.
 */
$stampy_wp_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' );
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

/*
 * The wp-phpunit bootstrap looks for wp-tests-config.php relative to its own
 * location. When using the vendored copy, that file doesn't exist. The
 * wp-env tests container provides one at /wordpress-phpunit/wp-tests-config.php
 * (exposed via WP_TESTS_DIR). Point the bootstrap at it.
 */
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	$stampy_wp_tests_dir = getenv( 'WP_TESTS_DIR' );
	if ( false !== $stampy_wp_tests_dir && '' !== $stampy_wp_tests_dir ) {
		$stampy_config = rtrim( $stampy_wp_tests_dir, '/\\' ) . '/wp-tests-config.php';
		if ( is_readable( $stampy_config ) ) {
			define( 'WP_TESTS_CONFIG_FILE_PATH', $stampy_config );
		}
	}
}

/**
 * Register the Stampy plugin so it loads inside the WordPress test install.
 *
 * @return void
 */
function stampy_manually_load_plugin(): void {
	require dirname( __DIR__, 2 ) . '/stampy.php';
}
tests_add_filter( 'muplugins_loaded', 'stampy_manually_load_plugin' );

/**
 * Capture wp_mail() calls for integration test assertions.
 *
 * The WordPress test framework uses a mock PHPMailer that doesn't send
 * real mail. This filter captures the arguments for assertion in tests.
 *
 * @param array<string, mixed> $args wp_mail arguments.
 * @return array<string, mixed> Unchanged arguments.
 */
function stampy_test_capture_mail( $args ) {
	if ( ! isset( $GLOBALS['phpmailer_mock_sent'] ) ) {
		$GLOBALS['phpmailer_mock_sent'] = array();
	}
	$GLOBALS['phpmailer_mock_sent'][] = array(
		'to'      => $args['to'] ?? '',
		'subject' => $args['subject'] ?? '',
		'body'    => $args['message'] ?? '',
		'headers' => $args['headers'] ?? array(),
	);
	return $args;
}
tests_add_filter( 'wp_mail', 'stampy_test_capture_mail' );

// Disable rate-limit guard during integration tests (all requests come
// from 127.0.0.1, which would hit the 5/hour limit after a few tests).
tests_add_filter( 'stampy_rate_limit_enabled', '__return_false' );

// Start up the WordPress testing environment.
require $stampy_wp_tests_bootstrap;
