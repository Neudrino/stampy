<?php
/**
 * Integration tests for SubscriberRepository CRUD and constraints.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Repositories\SubscriberRepository;
use WP_UnitTestCase;

/**
 * Tests subscriber CRUD, email uniqueness, and status management.
 */
class SubscriberRepositoryTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var SubscriberRepository
	 */
	private SubscriberRepository $repo;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		Installer::install();
		$this->repo = new SubscriberRepository();
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clean up subscribers created by test methods AFTER parent::tearDown()
		// because dbDelta()'s implicit commit means data persists across tests.
		global $wpdb;
		$table      = \Stampy\Schema::table( 'subscribers', $wpdb );
		$meta_table = \Stampy\Schema::table( 'subscriber_meta', $wpdb );
		$emails     = array(
			'test@example.com',
			'dup@example.com',
			'find@example.com',
			'byid@example.com',
			'confirm@example.com',
			'unsub@example.com',
			'token@example.com',
			'consent@example.com',
			'a@example.com',
			'b@example.com',
			'delete@example.com',
			'sub-repo-test@example.com',
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
	 * Creating a subscriber should work and return the row.
	 *
	 * @return void
	 */
	public function test_create_subscriber(): void {
		$subscriber = $this->repo->create_or_get( 'test@example.com' );

		$this->assertSame( 'test@example.com', $subscriber->email );
		$this->assertSame( 'pending', $subscriber->status );
		$this->assertNotEmpty( $subscriber->created_at );
		$this->assertSame( 1, (int) $subscriber->consent_version );
	}

	/**
	 * Email should be normalized (lowercase + trim).
	 *
	 * @return void
	 */
	public function test_email_is_normalized(): void {
		$subscriber = $this->repo->create_or_get( '  Test@Example.COM  ' );
		$this->assertSame( 'test@example.com', $subscriber->email );
	}

	/**
	 * Creating a subscriber with an existing email should return the
	 * existing row (upsert), not a new row.
	 *
	 * @return void
	 */
	public function test_create_or_get_upserts_existing_email(): void {
		$initial_count = $this->repo->count();
		$first  = $this->repo->create_or_get( 'dup@example.com' );
		$second = $this->repo->create_or_get( 'dup@example.com' );

		$this->assertSame( (int) $first->id, (int) $second->id );
		$this->assertSame( $initial_count + 1, $this->repo->count() );
	}

	/**
	 * find_by_email should return the subscriber.
	 *
	 * @return void
	 */
	public function test_find_by_email(): void {
		$this->repo->create_or_get( 'find@example.com' );

		$found = $this->repo->find_by_email( 'find@example.com' );
		$this->assertNotNull( $found );
		$this->assertSame( 'find@example.com', $found->email );
	}

	/**
	 * find_by_email should return null for non-existent email.
	 *
	 * @return void
	 */
	public function test_find_by_email_returns_null_for_missing(): void {
		$this->assertNull( $this->repo->find_by_email( 'missing@example.com' ) );
	}

	/**
	 * find by ID should return the subscriber.
	 *
	 * @return void
	 */
	public function test_find_by_id(): void {
		$created = $this->repo->create_or_get( 'byid@example.com' );
		$found   = $this->repo->find( (int) $created->id );

		$this->assertNotNull( $found );
		$this->assertSame( 'byid@example.com', $found->email );
	}

	/**
	 * update_status should change the status and set timestamps.
	 *
	 * @return void
	 */
	public function test_update_status_to_confirmed(): void {
		$subscriber = $this->repo->create_or_get( 'confirm@example.com' );
		$this->assertSame( 'pending', $subscriber->status );

		$this->repo->update_status( (int) $subscriber->id, 'confirmed' );

		$updated = $this->repo->find( (int) $subscriber->id );
		$this->assertSame( 'confirmed', $updated->status );
		$this->assertNotNull( $updated->confirmed_at );
	}

	/**
	 * update_status to unsubscribed should set the unsubscribed_at timestamp.
	 *
	 * @return void
	 */
	public function test_update_status_to_unsubscribed(): void {
		$subscriber = $this->repo->create_or_get( 'unsub@example.com' );

		$this->repo->update_status( (int) $subscriber->id, 'unsubscribed' );

		$updated = $this->repo->find( (int) $subscriber->id );
		$this->assertSame( 'unsubscribed', $updated->status );
		$this->assertNotNull( $updated->unsubscribed_at );
	}

	/**
	 * set_unsub_token_hash should store the token hash.
	 *
	 * @return void
	 */
	public function test_set_unsub_token_hash(): void {
		$subscriber = $this->repo->create_or_get( 'token@example.com' );
		$hash       = hash( 'sha256', 'test-token' );

		$this->repo->set_unsub_token_hash( (int) $subscriber->id, $hash );

		$updated = $this->repo->find( (int) $subscriber->id );
		$this->assertSame( $hash, $updated->unsub_token_hash );
	}

	/**
	 * set_consent_version should update the version.
	 *
	 * @return void
	 */
	public function test_set_consent_version(): void {
		$subscriber = $this->repo->create_or_get( 'consent@example.com' );
		$this->assertSame( 1, (int) $subscriber->consent_version );

		$this->repo->set_consent_version( (int) $subscriber->id, 2 );

		$updated = $this->repo->find( (int) $subscriber->id );
		$this->assertSame( 2, (int) $updated->consent_version );
	}

	/**
	 * count() should return the total number of subscribers.
	 *
	 * @return void
	 */
	public function test_count(): void {
		$initial = $this->repo->count();
		$this->repo->create_or_get( 'a@example.com' );
		$this->repo->create_or_get( 'b@example.com' );
		$this->assertSame( $initial + 2, $this->repo->count() );
	}

	/**
	 * count() with a status filter should filter correctly.
	 *
	 * @return void
	 */
	public function test_count_by_status(): void {
		$this->repo->create_or_get( 'sub-repo-test@example.com', 'pending' );
		$this->repo->create_or_get( 'b@example.com', 'confirmed' );

		$pending_count = $this->repo->count( 'pending' );
		$confirmed_count = $this->repo->count( 'confirmed' );

		$this->assertGreaterThanOrEqual( 1, $pending_count );
		$this->assertGreaterThanOrEqual( 1, $confirmed_count );
	}

	/**
	 * delete should remove the subscriber.
	 *
	 * @return void
	 */
	public function test_delete(): void {
		$subscriber = $this->repo->create_or_get( 'delete@example.com' );
		$id          = (int) $subscriber->id;

		$initial = $this->repo->count();
		$this->repo->delete( $id );

		$this->assertNull( $this->repo->find( $id ) );
		$this->assertSame( $initial - 1, $this->repo->count() );
	}
}
