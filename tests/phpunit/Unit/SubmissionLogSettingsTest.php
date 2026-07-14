<?php
/**
 * Unit tests for SubmissionLogSettings.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Unit;

use Brain\Monkey;
use Stampy\SubmissionLogSettings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests submission log settings storage.
 */
final class SubmissionLogSettingsTest extends TestCase {

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
				'__'         => function ( $text ) {
					return $text;
				},
				'get_option' => array( $this, 'mock_get_option' ),
			)
		);
		$this->options = array();
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
	 * Mock options store.
	 *
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * Mock get_option.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function mock_get_option( string $key, $default = false ) {
		return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
	}

	/**
	 * Enabled by default.
	 *
	 * @return void
	 */
	public function test_is_enabled_default_true(): void {
		$this->assertTrue( SubmissionLogSettings::is_enabled() );
	}

	/**
	 * Disabled when option is '0'.
	 *
	 * @return void
	 */
	public function test_is_enabled_false(): void {
		$this->options[ SubmissionLogSettings::ENABLED_OPTION ] = '0';
		$this->assertFalse( SubmissionLogSettings::is_enabled() );
	}

	/**
	 * Enabled when option is '1'.
	 *
	 * @return void
	 */
	public function test_is_enabled_true(): void {
		$this->options[ SubmissionLogSettings::ENABLED_OPTION ] = '1';
		$this->assertTrue( SubmissionLogSettings::is_enabled() );
	}
}
