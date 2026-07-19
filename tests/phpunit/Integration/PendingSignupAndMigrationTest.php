<?php
/**
 * Integration tests for PendingSignupRepository.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Repositories\PendingSignupRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Schema;
use WP_UnitTestCase;

/**
 * Tests pending signups, token handling, and expiry purge.
 */
class PendingSignupAndMigrationTest extends WP_UnitTestCase {

	/**
	 * Pending signup repository.
	 *
	 * @var PendingSignupRepository
	 */
	private PendingSignupRepository $pending_repo;

	/**
	 * Subscriber repository.
	 *
	 * @var SubscriberRepository
	 */
	private SubscriberRepository $sub_repo;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		Installer::install();
		$this->pending_repo = new PendingSignupRepository();
		$this->sub_repo    = new SubscriberRepository();
	}

	/**
	 * Create a pending signup and find it by token.
	 *
	 * @return void
	 */
	public function test_create_and_find_by_token(): void {
		$subscriber = $this->sub_repo->create_or_get( 'pending@example.com' );
		$token_hash = hash( 'sha256', 'test-token' );
		$payload    = array(
			'attributes'      => array( 'first_name' => 'Alice' ),
			'list_ids'        => array( 1 ),
			'consent_version' => 1,
		);

		$id = $this->pending_repo->create_or_refresh( (int) $subscriber->id, $token_hash, $payload );

		$found = $this->pending_repo->find_by_token( $token_hash );
		$this->assertNotNull( $found );
		$this->assertSame( $id, (int) $found->id );
		$this->assertSame( (int) $subscriber->id, (int) $found->subscriber_id );

		$decoded = json_decode( $found->payload, true );
		$this->assertSame( 'Alice', $decoded['attributes']['first_name'] );
		$this->assertSame( array( 1 ), $decoded['list_ids'] );
	}

	/**
	 * create_or_refresh should update an existing pending signup for the
	 * same (subscriber_id, form_id) pair, not create a new row.
	 *
	 * @return void
	 */
	public function test_create_or_refresh_updates_existing(): void {
		$subscriber = $this->sub_repo->create_or_get( 'refresh@example.com' );
		$token1     = hash( 'sha256', 'token1' );
		$token2     = hash( 'sha256', 'token2' );
		$payload1   = array( 'attributes' => array( 'first_name' => 'Alice' ) );
		$payload2   = array( 'attributes' => array( 'first_name' => 'Bob' ) );

		$id1 = $this->pending_repo->create_or_refresh( (int) $subscriber->id, $token1, $payload1, 1 );
		$id2 = $this->pending_repo->create_or_refresh( (int) $subscriber->id, $token2, $payload2, 1 );

		$this->assertSame( $id1, $id2, 'Should return the same ID (refresh, not duplicate).' );

		// The new token should be active, the old one should not be findable.
		$this->assertNull( $this->pending_repo->find_by_token( $token1 ) );
		$found = $this->pending_repo->find_by_token( $token2 );
		$this->assertNotNull( $found );

		$decoded = json_decode( $found->payload, true );
		$this->assertSame( 'Bob', $decoded['attributes']['first_name'] );
	}

	/**
	 * Different form_ids should allow independent pending signups.
	 *
	 * @return void
	 */
	public function test_different_forms_are_independent(): void {
		$subscriber = $this->sub_repo->create_or_get( 'multi@example.com' );
		$token1     = hash( 'sha256', 'token1' );
		$token2     = hash( 'sha256', 'token2' );

		$id1 = $this->pending_repo->create_or_refresh( (int) $subscriber->id, $token1, array(), 1 );
		$id2 = $this->pending_repo->create_or_refresh( (int) $subscriber->id, $token2, array(), 2 );

		$this->assertNotSame( $id1, $id2, 'Different form_ids should create different rows.' );

		$this->assertNotNull( $this->pending_repo->find_by_token( $token1 ) );
		$this->assertNotNull( $this->pending_repo->find_by_token( $token2 ) );
	}

	/**
	 * delete should remove the pending signup.
	 *
	 * @return void
	 */
	public function test_delete_pending_signup(): void {
		$subscriber = $this->sub_repo->create_or_get( 'del@example.com' );
		$token      = hash( 'sha256', 'del-token' );

		$id = $this->pending_repo->create_or_refresh( (int) $subscriber->id, $token, array() );

		$this->pending_repo->delete( $id );

		$this->assertNull( $this->pending_repo->find_by_token( $token ) );
	}

	/**
	 * purge_expired should delete pending signups past their expires_at.
	 *
	 * @return void
	 */
	public function test_purge_expired(): void {
		global $wpdb;
		$subscriber = $this->sub_repo->create_or_get( 'expired@example.com' );
		$token      = hash( 'sha256', 'expired-token' );

		$id = $this->pending_repo->create_or_refresh( (int) $subscriber->id, $token, array() );

		// Manually set expires_at to the past.
		$table = Schema::table( 'pending_signups' );
		$past  = gmdate( 'Y-m-d H:i:s', time() - 3600 );
		// phpcs:ignore WordPress.DB
		$wpdb->update( $table, array( 'expires_at' => $past ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );

		$deleted = $this->pending_repo->purge_expired();

		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->pending_repo->find_by_token( $token ) );
	}
}
