<?php
/**
 * Integration tests for the unsubscribe endpoint and preference page.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Security;
use Stampy\SignupService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Tests one-click unsubscribe, global unsubscribe, and preferences.
 */
class UnsubscribePreferencesTest extends WP_UnitTestCase {

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

		$list_repo      = new ListRepository();
		$this->list_id  = $list_repo->create( 'Newsletter', 'newsletter', 'Test list' );

		unset( $GLOBALS['phpmailer_mock_sent'] );
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $GLOBALS['phpmailer_mock_sent'] );
		parent::tearDown();
	}

	/**
	 * One-click unsubscribe should remove subscriber from the targeted list.
	 *
	 * @return void
	 */
	public function test_one_click_unsubscribe_removes_from_list(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'unsub@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		$token = $this->extract_token_from_email();
		$service->confirm( $token );

		$subscriber_repo = new SubscriberRepository();
		$subscriber      = $subscriber_repo->find_by_email( 'unsub@example.com' );

		$unsub_token = $this->get_unsub_token( $subscriber );
		$sig         = Security::sign(
			array(
				's'    => (int) $subscriber->id,
				'list' => $this->list_id,
				't'    => $unsub_token,
			)
		);

		$request = new WP_REST_Request( 'POST', '/stampy/v1/unsubscribe' );
		$request->set_body_params(
			array(
				's'    => (int) $subscriber->id,
				'list' => $this->list_id,
				't'    => $unsub_token,
				'sig'  => $sig,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		$list_repo = new ListRepository();
		$members   = $list_repo->get_list_subscribers( $this->list_id );
		$this->assertCount( 0, $members );

		$subscriber_after = $subscriber_repo->find( (int) $subscriber->id );
		$this->assertSame( 'confirmed', $subscriber_after->status );
	}

	/**
	 * Global unsubscribe should mark the subscriber as unsubscribed.
	 *
	 * @return void
	 */
	public function test_global_unsubscribe_sets_status_unsubscribed(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'global@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		$token = $this->extract_token_from_email();
		$service->confirm( $token );

		$subscriber_repo = new SubscriberRepository();
		$subscriber      = $subscriber_repo->find_by_email( 'global@example.com' );

		$unsub_token = $this->get_unsub_token( $subscriber );
		$sig         = Security::sign(
			array(
				's' => (int) $subscriber->id,
				't' => $unsub_token,
			)
		);

		$request = new WP_REST_Request( 'POST', '/stampy/v1/unsubscribe-all' );
		$request->set_body_params(
			array(
				's'   => (int) $subscriber->id,
				't'   => $unsub_token,
				'sig' => $sig,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		$subscriber_after = $subscriber_repo->find( (int) $subscriber->id );
		$this->assertSame( 'unsubscribed', $subscriber_after->status );
	}

	/**
	 * Unsubscribe with invalid signature should fail with 403.
	 *
	 * @return void
	 */
	public function test_unsubscribe_with_invalid_signature_fails(): void {
		$subscriber_repo = new SubscriberRepository();
		$subscriber       = $subscriber_repo->create_or_get( 'bad-sig@example.com', 'confirmed' );
		$subscriber_repo->set_unsub_token_hash( (int) $subscriber->id, Security::hash_token( 'token' ) );

		$request = new WP_REST_Request( 'POST', '/stampy/v1/unsubscribe' );
		$request->set_body_params(
			array(
				's'    => (int) $subscriber->id,
				'list' => $this->list_id,
				't'    => 'token',
				'sig'  => 'invalid-signature',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Preferences GET should return the subscriber's list memberships.
	 *
	 * @return void
	 */
	public function test_preferences_get_returns_memberships(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'prefs@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		$token = $this->extract_token_from_email();
		$service->confirm( $token );

		$subscriber_repo = new SubscriberRepository();
		$subscriber      = $subscriber_repo->find_by_email( 'prefs@example.com' );

		$unsub_token = $this->get_unsub_token( $subscriber );
		$sig         = Security::sign(
			array(
				's' => (int) $subscriber->id,
				't' => $unsub_token,
			)
		);

		$request = new WP_REST_Request( 'GET', '/stampy/v1/preferences' );
		$request->set_query_params(
			array(
				's'   => (int) $subscriber->id,
				't'   => $unsub_token,
				'sig' => $sig,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'prefs@example.com', $data['email'] );
		$this->assertIsArray( $data['lists'] );
		$this->assertCount( 1, $data['lists'] );
		$this->assertTrue( $data['lists'][0]['subscribed'] );
	}

	/**
	 * Preferences POST should toggle list memberships.
	 *
	 * @return void
	 */
	public function test_preferences_post_toggles_lists(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'toggle@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		$token = $this->extract_token_from_email();
		$service->confirm( $token );

		$subscriber_repo = new SubscriberRepository();
		$subscriber      = $subscriber_repo->find_by_email( 'toggle@example.com' );

		$unsub_token = $this->get_unsub_token( $subscriber );
		$sig         = Security::sign(
			array(
				's' => (int) $subscriber->id,
				't' => $unsub_token,
			)
		);

		$request = new WP_REST_Request( 'POST', '/stampy/v1/preferences' );
		$request->set_body_params(
			array(
				's'     => (int) $subscriber->id,
				't'     => $unsub_token,
				'sig'   => $sig,
				'lists' => array(),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$list_repo = new ListRepository();
		$members   = $list_repo->get_list_subscribers( $this->list_id );
		$this->assertCount( 0, $members );
	}

	/**
	 * Preferences POST with opt_out should globally unsubscribe.
	 *
	 * @return void
	 */
	public function test_preferences_opt_out_global_unsubscribe(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'optout@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		$token = $this->extract_token_from_email();
		$service->confirm( $token );

		$subscriber_repo = new SubscriberRepository();
		$subscriber      = $subscriber_repo->find_by_email( 'optout@example.com' );

		$unsub_token = $this->get_unsub_token( $subscriber );
		$sig         = Security::sign(
			array(
				's' => (int) $subscriber->id,
				't' => $unsub_token,
			)
		);

		$request = new WP_REST_Request( 'POST', '/stampy/v1/preferences' );
		$request->set_body_params(
			array(
				's'       => (int) $subscriber->id,
				't'       => $unsub_token,
				'sig'     => $sig,
				'opt_out' => true,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$subscriber_after = $subscriber_repo->find( (int) $subscriber->id );
		$this->assertSame( 'unsubscribed', $subscriber_after->status );
	}

	/**
	 * Preferences with invalid signature should fail.
	 *
	 * @return void
	 */
	public function test_preferences_invalid_signature_fails(): void {
		$request = new WP_REST_Request( 'GET', '/stampy/v1/preferences' );
		$request->set_query_params(
			array(
				's'   => 1,
				't'   => 'token',
				'sig' => 'bad',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Extract the confirmation token from the last sent email.
	 *
	 * @return string
	 */
	private function extract_token_from_email(): string {
		$sent = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertNotEmpty( $sent );

		$last = $sent[ count( $sent ) - 1 ];
		$body = $last['body'] ?? '';

		if ( preg_match( '/stampy_confirm=([a-f0-9]+)/', (string) $body, $matches ) ) {
			return $matches[1];
		}

		$this->fail( 'Could not extract confirmation token from email body.' );
	}

	/**
	 * Get the unsubscribe token for a subscriber.
	 *
	 * Since we only store the hash, we need to generate a fresh token
	 * and set it for the subscriber. In the test we can capture it
	 * during confirmation.
	 *
	 * @param object $subscriber Subscriber row.
	 * @return string
	 */
	private function get_unsub_token( object $subscriber ): string {
		$token = Security::generate_token();
		$subscriber_repo = new SubscriberRepository();
		$subscriber_repo->set_unsub_token_hash( (int) $subscriber->id, Security::hash_token( $token ) );
		return $token;
	}
}
