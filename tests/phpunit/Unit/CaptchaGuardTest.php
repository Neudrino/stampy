<?php
/**
 * Unit tests for the TurnstileGuard and FriendlyCaptchaGuard.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Unit;

use Brain\Monkey;
use Stampy\SpamGuards\FriendlyCaptchaGuard;
use Stampy\SpamGuards\TurnstileGuard;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests the third-party captcha spam guards.
 */
class CaptchaGuardTest extends TestCase {

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}

		Monkey\Functions\stubs(
			array(
				'__'                  => function ( $text ) {
					return $text;
				},
				'sanitize_text_field' => function ( $text ) {
					return is_string( $text ) ? trim( $text ) : '';
				},
				'wp_unslash'          => function ( $value ) {
					return $value;
				},
				'wp_json_encode'      => function ( $data ) {
					return json_encode( $data );
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
	 * Helper: set up option mocks for captcha guards.
	 *
	 * @param string $turnstile_secret   Turnstile secret key (empty to disable).
	 * @param string $turnstile_site     Turnstile site key.
	 * @param string $fc_secret          Friendly Captcha secret key (empty to disable).
	 * @param string $fc_site            Friendly Captcha site key.
	 * @return void
	 */
	private function set_options(
		string $turnstile_secret = '',
		string $turnstile_site = '',
		string $fc_secret = '',
		string $fc_site = ''
	): void {
		Monkey\Functions\when( 'get_option' )->alias(
			function ( $key, $default = '' ) use ( $turnstile_secret, $turnstile_site, $fc_secret, $fc_site ) {
				$map = array(
					'stampy_turnstile_secret_key'     => $turnstile_secret,
					'stampy_turnstile_site_key'        => $turnstile_site,
					'stampy_friendly_captcha_secret_key' => $fc_secret,
					'stampy_friendly_captcha_site_key' => $fc_site,
				);
				return $map[ $key ] ?? $default;
			}
		);
	}

	// --- TurnstileGuard tests ---

	/**
	 * Turnstile guard should pass when disabled (no secret key).
	 *
	 * @return void
	 */
	public function test_turnstile_passes_when_disabled(): void {
		$this->set_options();
		$guard  = new TurnstileGuard();
		$result = $guard->check( array() );

		$this->assertTrue( $result->passed() );
	}

	/**
	 * Turnstile guard should fail when token is missing.
	 *
	 * @return void
	 */
	public function test_turnstile_fails_when_token_missing(): void {
		$this->set_options( 'test-secret', 'test-site' );

		$guard  = new TurnstileGuard();
		$result = $guard->check( array() );

		$this->assertFalse( $result->passed() );
	}

	/**
	 * Turnstile guard should fail when token is empty.
	 *
	 * @return void
	 */
	public function test_turnstile_fails_when_token_empty(): void {
		$this->set_options( 'test-secret', 'test-site' );

		$guard  = new TurnstileGuard();
		$result = $guard->check( array( TurnstileGuard::TOKEN_KEY => '' ) );

		$this->assertFalse( $result->passed() );
	}

	/**
	 * Turnstile guard should pass when API returns success.
	 *
	 * @return void
	 */
	public function test_turnstile_passes_when_api_succeeds(): void {
		$this->set_options( 'test-secret', 'test-site' );

		Monkey\Functions\when( 'wp_remote_post' )->alias(
			function () {
				return array( 'body' => '{"success": true}' );
			}
		);
		Monkey\Functions\when( 'is_wp_error' )->alias(
			function () {
				return false;
			}
		);
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return isset( $response['body'] ) ? $response['body'] : '';
			}
		);

		$guard  = new TurnstileGuard();
		$result = $guard->check( array( TurnstileGuard::TOKEN_KEY => 'valid-token' ) );

		$this->assertTrue( $result->passed() );
	}

	/**
	 * Turnstile guard should fail when API returns failure.
	 *
	 * @return void
	 */
	public function test_turnstile_fails_when_api_fails(): void {
		$this->set_options( 'test-secret', 'test-site' );

		Monkey\Functions\when( 'wp_remote_post' )->alias(
			function () {
				return array( 'body' => '{"success": false}' );
			}
		);
		Monkey\Functions\when( 'is_wp_error' )->alias(
			function () {
				return false;
			}
		);
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return isset( $response['body'] ) ? $response['body'] : '';
			}
		);

		$guard  = new TurnstileGuard();
		$result = $guard->check( array( TurnstileGuard::TOKEN_KEY => 'invalid-token' ) );

		$this->assertFalse( $result->passed() );
	}

	/**
	 * Turnstile guard should fail when API returns WP_Error.
	 *
	 * @return void
	 */
	public function test_turnstile_fails_on_wp_error(): void {
		$this->set_options( 'test-secret', 'test-site' );

		Monkey\Functions\when( 'wp_remote_post' )->alias(
			function () {
				return new \stdClass();
			}
		);
		Monkey\Functions\when( 'is_wp_error' )->alias(
			function () {
				return true;
			}
		);

		$guard  = new TurnstileGuard();
		$result = $guard->check( array( TurnstileGuard::TOKEN_KEY => 'token' ) );

		$this->assertFalse( $result->passed() );
	}

	/**
	 * Turnstile is_enabled should return true when secret is configured.
	 *
	 * @return void
	 */
	public function test_turnstile_is_enabled_true(): void {
		$this->set_options( 'test-secret', 'test-site' );

		$this->assertTrue( TurnstileGuard::is_enabled() );
	}

	/**
	 * Turnstile is_enabled should return false when no secret configured.
	 *
	 * @return void
	 */
	public function test_turnstile_is_enabled_false(): void {
		$this->set_options();

		$this->assertFalse( TurnstileGuard::is_enabled() );
	}

	// --- FriendlyCaptchaGuard tests ---

	/**
	 * Friendly Captcha guard should pass when disabled.
	 *
	 * @return void
	 */
	public function test_fc_passes_when_disabled(): void {
		$this->set_options();
		$guard  = new FriendlyCaptchaGuard();
		$result = $guard->check( array() );

		$this->assertTrue( $result->passed() );
	}

	/**
	 * Friendly Captcha guard should fail when solution is missing.
	 *
	 * @return void
	 */
	public function test_fc_fails_when_solution_missing(): void {
		$this->set_options( '', '', 'fc-secret', 'fc-site' );

		$guard  = new FriendlyCaptchaGuard();
		$result = $guard->check( array() );

		$this->assertFalse( $result->passed() );
	}

	/**
	 * Friendly Captcha guard should pass when API returns success.
	 *
	 * @return void
	 */
	public function test_fc_passes_when_api_succeeds(): void {
		$this->set_options( '', '', 'fc-secret', 'fc-site' );

		Monkey\Functions\when( 'wp_remote_post' )->alias(
			function () {
				return array( 'body' => '{"success": true}' );
			}
		);
		Monkey\Functions\when( 'is_wp_error' )->alias(
			function () {
				return false;
			}
		);
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return isset( $response['body'] ) ? $response['body'] : '';
			}
		);

		$guard  = new FriendlyCaptchaGuard();
		$result = $guard->check( array( FriendlyCaptchaGuard::SOLUTION_KEY => 'valid-solution' ) );

		$this->assertTrue( $result->passed() );
	}

	/**
	 * Friendly Captcha guard should fail when API returns failure.
	 *
	 * @return void
	 */
	public function test_fc_fails_when_api_fails(): void {
		$this->set_options( '', '', 'fc-secret', 'fc-site' );

		Monkey\Functions\when( 'wp_remote_post' )->alias(
			function () {
				return array( 'body' => '{"success": false}' );
			}
		);
		Monkey\Functions\when( 'is_wp_error' )->alias(
			function () {
				return false;
			}
		);
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return isset( $response['body'] ) ? $response['body'] : '';
			}
		);

		$guard  = new FriendlyCaptchaGuard();
		$result = $guard->check( array( FriendlyCaptchaGuard::SOLUTION_KEY => 'bad-solution' ) );

		$this->assertFalse( $result->passed() );
	}

	/**
	 * Friendly Captcha is_enabled should return true when secret is configured.
	 *
	 * @return void
	 */
	public function test_fc_is_enabled_true(): void {
		$this->set_options( '', '', 'fc-secret', 'fc-site' );

		$this->assertTrue( FriendlyCaptchaGuard::is_enabled() );
	}

	/**
	 * Friendly Captcha is_enabled should return false when no secret configured.
	 *
	 * @return void
	 */
	public function test_fc_is_enabled_false(): void {
		$this->set_options();

		$this->assertFalse( FriendlyCaptchaGuard::is_enabled() );
	}
}
