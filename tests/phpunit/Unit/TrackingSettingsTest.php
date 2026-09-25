<?php
/**
 * Unit tests for TrackingSettings (anonymous toggle).
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Unit;

use Brain\Monkey;
use Stampy\Tracking\TrackingSettings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests tracking settings storage (global toggle + anonymous toggle).
 */
final class TrackingSettingsTest extends TestCase {

	/**
	 * Mock options store.
	 *
	 * @var array<string,mixed>
	 */
	private array $options;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();

		Monkey\Functions\stubs(
			array(
				'get_option'    => function ( $key, $default = false ) {
					return isset( $this->options[ $key ] ) ? $this->options[ $key ] : $default;
				},
				'update_option' => function ( $key, $value ) {
					$this->options[ $key ] = $value;
					return true;
				},
				'delete_option' => function ( $key ) {
					unset( $this->options[ $key ] );
					return true;
				},
				'get_post_meta' => function ( $post_id, $key, $single ) {
					return $this->options[ 'meta:' . $post_id . ':' . $key ] ?? '';
				},
				'update_post_meta' => function ( $post_id, $key, $value ) {
					$this->options[ 'meta:' . $post_id . ':' . $key ] = $value;
					return true;
				},
			)
		);
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
	 * The global tracking toggle defaults to disabled.
	 *
	 * @return void
	 */
	public function test_tracking_defaults_to_disabled(): void {
		$this->assertFalse( TrackingSettings::is_globally_enabled() );
	}

	/**
	 * Anonymous tracking defaults to enabled.
	 *
	 * @return void
	 */
	public function test_anonymous_defaults_to_enabled(): void {
		$this->assertTrue( TrackingSettings::is_anonymous() );
	}

	/**
	 * The anonymous setting round-trips.
	 *
	 * @return void
	 */
	public function test_anonymous_setting_round_trip(): void {
		TrackingSettings::set_anonymous( false );
		$this->assertFalse( TrackingSettings::is_anonymous() );

		TrackingSettings::set_anonymous( true );
		$this->assertTrue( TrackingSettings::is_anonymous() );
	}

	/**
	 * The global tracking setting round-trips.
	 *
	 * @return void
	 */
	public function test_globally_enabled_setting_round_trip(): void {
		TrackingSettings::set_globally_enabled( true );
		$this->assertTrue( TrackingSettings::is_globally_enabled() );

		TrackingSettings::set_globally_enabled( false );
		$this->assertFalse( TrackingSettings::is_globally_enabled() );
	}

	/**
	 * is_tracking_enabled() resolution is not affected by anonymous setting.
	 *
	 * @return void
	 */
	public function test_anonymous_does_not_affect_tracking_enabled(): void {
		TrackingSettings::set_globally_enabled( true );

		foreach ( array( true, false ) as $anonymous ) {
			TrackingSettings::set_anonymous( $anonymous );

			// No override → global default wins.
			$this->assertTrue( TrackingSettings::is_tracking_enabled( 4711 ) );

			// Per-campaign overrides take precedence regardless.
			TrackingSettings::set_campaign_override( 4711, 'off' );
			$this->assertFalse( TrackingSettings::is_tracking_enabled( 4711 ) );

			TrackingSettings::set_campaign_override( 4711, '' );
		}
	}

	/**
	 * Invalid per-campaign override values are rejected.
	 *
	 * @return void
	 */
	public function test_invalid_campaign_override_rejected(): void {
		$this->assertFalse( TrackingSettings::set_campaign_override( 4711, 'sometimes' ) );
	}

	/**
	 * Deleting the anonymous option restores the default.
	 *
	 * @return void
	 */
	public function test_delete_anonymous_restores_default(): void {
		TrackingSettings::set_anonymous( false );
		TrackingSettings::delete_anonymous();

		$this->assertTrue( TrackingSettings::is_anonymous() );
	}
}
