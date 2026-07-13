<?php
/**
 * Integration tests for the third-party captcha spam guards.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\SpamGuards\FriendlyCaptchaGuard;
use Stampy\SpamGuards\TurnstileGuard;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Tests the Turnstile and Friendly Captcha guards via the signup REST endpoint.
 */
class CaptchaGuardTest extends WP_UnitTestCase {

	/**
	 * List ID for testing.
	 *
	 * @var int
	 */
	private int $list_id;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		Installer::install();

		$list_repo     = new ListRepository();
		$existing_list = $list_repo->find_by_slug( 'newsletter' );
		if ( null === $existing_list ) {
			$this->list_id = $list_repo->create( 'Newsletter', 'newsletter', 'Test list' );
		} else {
			$this->list_id = (int) $existing_list->id;
		}

		unset( $GLOBALS['phpmailer_mock_sent'] );
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $GLOBALS['phpmailer_mock_sent'] );
		delete_option( 'stampy_turnstile_secret_key' );
		delete_option( 'stampy_turnstile_site_key' );
		delete_option( 'stampy_friendly_captcha_secret_key' );
		delete_option( 'stampy_friendly_captcha_site_key' );

		parent::tearDown();

		global $wpdb;
		$table      = \Stampy\Schema::table( 'subscribers', $wpdb );
		$meta_table = \Stampy\Schema::table( 'subscriber_meta', $wpdb );
		$emails     = array( 'captcha-test@example.com', 'captcha-wrong@example.com' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $emails as $email ) {
			$sub_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE email = %s", $email ) );
			if ( $sub_id ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM $meta_table WHERE subscriber_id = %d", (int) $sub_id ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id = %d", (int) $sub_id ) );
			}
		}
		// phpcs:enable
	}

	/**
	 * Signup should succeed when Turnstile is disabled.
	 *
	 * @return void
	 */
	public function test_signup_succeeds_when_turnstile_disabled(): void {
		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'    => 'captcha-test@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Signup should fail when Turnstile is enabled but token is missing.
	 *
	 * @return void
	 */
	public function test_signup_fails_when_turnstile_enabled_no_token(): void {
		update_option( 'stampy_turnstile_secret_key', 'test-secret' );
		update_option( 'stampy_turnstile_site_key', 'test-site' );

		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'    => 'captcha-wrong@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
	}

	/**
	 * Signup should fail when Turnstile is enabled and token is invalid.
	 *
	 * @return void
	 */
	public function test_signup_fails_when_turnstile_token_invalid(): void {
		update_option( 'stampy_turnstile_secret_key', 'test-secret' );
		update_option( 'stampy_turnstile_site_key', 'test-site' );

		// Mock wp_remote_post to return failure.
		add_filter( 'pre_http_request', array( $this, 'mock_turnstile_fail' ), 10, 3 );

		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'                   => 'captcha-wrong@example.com',
				'consent'                 => true,
				'list_ids'                => array( $this->list_id ),
				'stampy_turnstile_token'  => 'invalid-token',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'pre_http_request', array( $this, 'mock_turnstile_fail' ) );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
	}

	/**
	 * Signup should succeed when Turnstile is enabled and token is valid.
	 *
	 * @return void
	 */
	public function test_signup_succeeds_when_turnstile_token_valid(): void {
		update_option( 'stampy_turnstile_secret_key', 'test-secret' );
		update_option( 'stampy_turnstile_site_key', 'test-site' );

		add_filter( 'pre_http_request', array( $this, 'mock_turnstile_success' ), 10, 3 );

		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'                  => 'captcha-test@example.com',
				'consent'                => true,
				'list_ids'               => array( $this->list_id ),
				'stampy_turnstile_token' => 'valid-token',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'pre_http_request', array( $this, 'mock_turnstile_success' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Signup should fail when Friendly Captcha is enabled but solution is missing.
	 *
	 * @return void
	 */
	public function test_signup_fails_when_fc_enabled_no_solution(): void {
		update_option( 'stampy_friendly_captcha_secret_key', 'fc-secret' );
		update_option( 'stampy_friendly_captcha_site_key', 'fc-site' );

		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'    => 'captcha-wrong@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
	}

	/**
	 * Signup should succeed when Friendly Captcha is enabled and solution is valid.
	 *
	 * @return void
	 */
	public function test_signup_succeeds_when_fc_solution_valid(): void {
		update_option( 'stampy_friendly_captcha_secret_key', 'fc-secret' );
		update_option( 'stampy_friendly_captcha_site_key', 'fc-site' );

		add_filter( 'pre_http_request', array( $this, 'mock_fc_success' ), 10, 3 );

		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'                               => 'captcha-test@example.com',
				'consent'                             => true,
				'list_ids'                            => array( $this->list_id ),
				'stampy_friendly_captcha_solution'    => 'valid-solution',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		remove_filter( 'pre_http_request', array( $this, 'mock_fc_success' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Mock Turnstile API returning failure.
	 *
	 * @param bool|array $preempt Preempt value.
	 * @param array      $args    HTTP request args.
	 * @param string     $url     Request URL.
	 * @return array
	 */
	public function mock_turnstile_fail( $preempt, array $args, string $url ): array {
		if ( false !== strpos( $url, 'challenges.cloudflare.com' ) ) {
			return array(
				'body'     => '{"success": false}',
				'response' => array( 'code' => 200 ),
			);
		}
		return $preempt;
	}

	/**
	 * Mock Turnstile API returning success.
	 *
	 * @param bool|array $preempt Preempt value.
	 * @param array      $args    HTTP request args.
	 * @param string     $url     Request URL.
	 * @return array
	 */
	public function mock_turnstile_success( $preempt, array $args, string $url ): array {
		if ( false !== strpos( $url, 'challenges.cloudflare.com' ) ) {
			return array(
				'body'     => '{"success": true}',
				'response' => array( 'code' => 200 ),
			);
		}
		return $preempt;
	}

	/**
	 * Mock Friendly Captcha API returning success.
	 *
	 * @param bool|array $preempt Preempt value.
	 * @param array      $args    HTTP request args.
	 * @param string     $url     Request URL.
	 * @return array
	 */
	public function mock_fc_success( $preempt, array $args, string $url ): array {
		if ( false !== strpos( $url, 'global.frcapi.com' ) ) {
			return array(
				'body'     => '{"success": true}',
				'response' => array( 'code' => 200 ),
			);
		}
		return $preempt;
	}
}
