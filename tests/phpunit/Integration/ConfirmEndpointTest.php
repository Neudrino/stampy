<?php
/**
 * Integration tests for the confirm endpoint and merge policy.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\PendingSignupRepository;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Security;
use Stampy\SignupService;
use WP_UnitTestCase;

/**
 * Tests confirmation flow, merge policy, and list membership.
 */
class ConfirmEndpointTest extends WP_UnitTestCase {

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
	 * Full signup→confirm flow should promote to confirmed and add lists.
	 *
	 * @return void
	 */
	public function test_confirm_promotes_and_adds_lists(): void {
		$service = new SignupService();

		$signup_result = $service->signup(
			array(
				'email'    => 'confirm@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(
					'first_name' => 'Alice',
				),
			)
		);
		$this->assertTrue( $signup_result['success'] );

		$subscriber_repo = new SubscriberRepository();
		$subscriber      = $subscriber_repo->find_by_email( 'confirm@example.com' );
		$this->assertNotNull( $subscriber );
		$this->assertSame( 'pending', $subscriber->status );

		$sent = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertNotEmpty( $sent );

		$pending_repo = new PendingSignupRepository();
		$pending     = $pending_repo->find_by_token( Security::hash_token( $this->extract_token_from_email() ) );

		// Use the service to confirm.
		$token = $this->extract_token_from_email();
		$confirm_result = $service->confirm( $token );

		$this->assertTrue( $confirm_result['success'] );
		$this->assertArrayHasKey( 'preferences_url', $confirm_result );
		$this->assertNotEmpty( $confirm_result['preferences_url'] );

		$subscriber_repo2 = new SubscriberRepository();
		$confirmed        = $subscriber_repo2->find( (int) $subscriber->id );
		$this->assertSame( 'confirmed', $confirmed->status );
		$this->assertNotNull( $confirmed->confirmed_at );
		$this->assertNotEmpty( $confirmed->unsub_token_hash );

		$list_repo  = new ListRepository();
		$members    = $list_repo->get_list_subscribers( $this->list_id );
		$this->assertCount( 1, $members );

		$meta_repo = new SubscriberMetaRepository();
		$this->assertSame( 'Alice', $meta_repo->get( (int) $subscriber->id, 'first_name' ) );

		// Pending signup should be deleted.
		$pending_repo2 = new PendingSignupRepository();
		$this->assertNull( $pending_repo2->find_by_token( Security::hash_token( $token ) ) );
	}

	/**
	 * Merge policy: non-empty overwrites, empty never erases.
	 *
	 * @return void
	 */
	public function test_merge_policy_non_empty_overwrites_empty_never_erases(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'merge@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(
					'first_name' => 'Bob',
				),
			)
		);

		$token1 = $this->extract_token_from_email();
		$service->confirm( $token1 );

		$subscriber_repo = new SubscriberRepository();
		$subscriber      = $subscriber_repo->find_by_email( 'merge@example.com' );

		$meta_repo = new SubscriberMetaRepository();
		$this->assertSame( 'Bob', $meta_repo->get( (int) $subscriber->id, 'first_name' ) );

		// Now sign up again with empty first_name.
		unset( $GLOBALS['phpmailer_mock_sent'] );
		$subscriber_repo->update_status( (int) $subscriber->id, 'pending' );

		$service->signup(
			array(
				'email'    => 'merge@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(
					'first_name' => '',
				),
			)
		);

		$token2 = $this->extract_token_from_email();
		$service->confirm( $token2 );

		$meta_repo2 = new SubscriberMetaRepository();
		$this->assertSame( 'Bob', $meta_repo2->get( (int) $subscriber->id, 'first_name' ) );
	}

	/**
	 * Confirm with invalid token should fail.
	 *
	 * @return void
	 */
	public function test_confirm_with_invalid_token_fails(): void {
		$service = new SignupService();
		$result  = $service->confirm( 'invalid-token' );

		$this->assertFalse( $result['success'] );
	}

	/**
	 * Confirm with expired token should fail.
	 *
	 * @return void
	 */
	public function test_confirm_with_expired_token_fails(): void {
		$service        = new SignupService();
		$subscriber_repo = new SubscriberRepository();
		$pending_repo   = new PendingSignupRepository();

		$subscriber = $subscriber_repo->create_or_get( 'expired@example.com', 'pending' );

		$token      = Security::generate_token();
		$token_hash = Security::hash_token( $token );

		$pending_id = $pending_repo->create_or_refresh(
			(int) $subscriber->id,
			$token_hash,
			array(
				'attributes'     => array(),
				'list_ids'       => array( $this->list_id ),
				'consent_version' => 1,
				'form_id'        => null,
			),
			null
		);

		// Manually set expiry in the past.
		global $wpdb;
		$table = \Stampy\Schema::table( 'pending_signups' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update(
			$table,
			array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ),
			array( 'id' => $pending_id ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable

		$result = $service->confirm( $token );

		$this->assertFalse( $result['success'] );
	}

	/**
	 * List-level resubscribe: confirming for a list where the junction
	 * exists as unsubscribed flips it back to subscribed.
	 *
	 * @return void
	 */
	public function test_resubscribe_flips_unsubscribed_to_subscribed(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'resub@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		$token = $this->extract_token_from_email();
		$service->confirm( $token );

		$subscriber_repo = new SubscriberRepository();
		$subscriber      = $subscriber_repo->find_by_email( 'resub@example.com' );

		$list_repo = new ListRepository();
		$list_repo->remove_subscriber( (int) $subscriber->id, $this->list_id );

		$members = $list_repo->get_list_subscribers( $this->list_id );
		$this->assertCount( 0, $members );

		$members_all = $list_repo->get_subscriber_lists( (int) $subscriber->id );
		$this->assertCount( 1, $members_all );
		$this->assertSame( 'unsubscribed', $members_all[0]->status );

		unset( $GLOBALS['phpmailer_mock_sent'] );
		$subscriber_repo->update_status( (int) $subscriber->id, 'pending' );

		$service->signup(
			array(
				'email'    => 'resub@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		$token2 = $this->extract_token_from_email();
		$service->confirm( $token2 );

		$members_after = $list_repo->get_list_subscribers( $this->list_id );
		$this->assertCount( 1, $members_after );
	}

	/**
	 * Extract the confirmation token from the last sent email.
	 *
	 * The mock mailer captures the email body. We parse the token from
	 * the confirmation URL which contains `stampy_confirm=<token>`.
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
}
