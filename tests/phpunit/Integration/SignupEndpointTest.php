<?php
/**
 * Integration tests for the signup REST endpoint.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\PendingSignupRepository;
use Stampy\Repositories\SubscriberRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Tests the signup REST endpoint: validation, staging, confirmation emails.
 */
class SignupEndpointTest extends WP_UnitTestCase {

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

		$list_repo = new ListRepository();
		$this->list_id = $list_repo->create( 'Newsletter', 'newsletter', 'Test list' );

		// Reset mail capture.
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

		// Clean up subscribers created by test methods AFTER parent::tearDown()
		// because dbDelta()'s implicit commit means data persists across tests.
		global $wpdb;
		$table      = \Stampy\Schema::table( 'subscribers', $wpdb );
		$meta_table = \Stampy\Schema::table( 'subscriber_meta', $wpdb );
		$emails     = array(
			'test@example.com',
			'consent-fail@example.com',
			'invalid@example.com',
			'resign@example.com',
		);
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
	 * A valid signup should create a pending signup and send a confirmation email.
	 *
	 * @return void
	 */
	public function test_valid_signup_creates_pending_and_sends_email(): void {
		$subscriber_repo = new SubscriberRepository();
		$initial_count   = $subscriber_repo->count();

		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'     => 'test@example.com',
				'consent'   => true,
				'list_ids'  => array( $this->list_id ),
				'fields'    => array(
					'first_name' => 'John',
					'last_name'  => 'Doe',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		$subscriber_repo = new SubscriberRepository();
		$subscriber       = $subscriber_repo->find_by_email( 'test@example.com' );
		$this->assertNotNull( $subscriber );
		$this->assertSame( 'pending', $subscriber->status );

		$pending_repo = new PendingSignupRepository();
		$this->assertSame( $initial_count + 1, $subscriber_repo->count() );

		$this->assertNotEmpty( $GLOBALS['phpmailer_mock_sent'] );
	}

	/**
	 * Signup without consent should fail.
	 *
	 * @return void
	 */
	public function test_signup_without_consent_fails(): void {
		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'    => 'test@example.com',
				'consent'  => false,
				'list_ids' => array( $this->list_id ),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
		$this->assertArrayHasKey( 'errors', $data );
		$this->assertArrayHasKey( 'consent', $data['errors'] );
	}

	/**
	 * Signup without list_ids should fail.
	 *
	 * @return void
	 */
	public function test_signup_without_list_ids_fails(): void {
		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'    => 'test@example.com',
				'consent'  => true,
				'list_ids' => array(),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
		$this->assertArrayHasKey( 'errors', $data );
		$this->assertArrayHasKey( 'list_ids', $data['errors'] );
	}

	/**
	 * Signup with invalid email should fail.
	 *
	 * @return void
	 */
	public function test_signup_with_invalid_email_fails(): void {
		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'    => 'not-an-email',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
		$this->assertArrayHasKey( 'errors', $data );
		$this->assertArrayHasKey( 'email', $data['errors'] );
	}

	/**
	 * Honeypot field filled should cause rejection.
	 *
	 * @return void
	 */
	public function test_signup_with_honeypot_filled_fails(): void {
		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'         => 'bot@example.com',
				'consent'       => true,
				'list_ids'      => array( $this->list_id ),
				'website_check' => 'spam bot',
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
	}

	/**
	 * Already-confirmed subscribers should get immediate list membership,
	 * no re-confirmation email.
	 *
	 * @return void
	 */
	public function test_confirmed_subscriber_gets_immediate_membership(): void {
		$subscriber_repo = new SubscriberRepository();
		$subscriber      = $subscriber_repo->create_or_get( 'confirmed@example.com', 'confirmed' );
		$subscriber_repo->update_status( (int) $subscriber->id, 'confirmed' );

		$mail_count_before = count( $GLOBALS['phpmailer_mock_sent'] ?? array() );

		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'    => 'confirmed@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array( 'first_name' => 'Jane' ),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );

		$list_repo = new ListRepository();
		$members   = $list_repo->get_list_subscribers( $this->list_id );
		$this->assertCount( 1, $members );

		$mail_count_after = count( $GLOBALS['phpmailer_mock_sent'] ?? array() );
		$this->assertSame( $mail_count_before, $mail_count_after );
	}

	/**
	 * Re-signup of the same form should refresh the pending signup.
	 *
	 * @return void
	 */
	public function test_resignup_same_form_refreshes_pending(): void {
		$subscriber_repo = new SubscriberRepository();
		$initial_count   = $subscriber_repo->count();

		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'    => 'resign@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		rest_get_server()->dispatch( $request );

		unset( $GLOBALS['phpmailer_mock_sent'] );

		$request2 = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request2->set_body_params(
			array(
				'email'    => 'resign@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		$response2 = rest_get_server()->dispatch( $request2 );

		$this->assertSame( 200, $response2->get_status() );

		// Resignup should not create a duplicate subscriber.
		$this->assertSame( $initial_count + 1, $subscriber_repo->count() );
	}
}
