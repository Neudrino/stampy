<?php
/**
 * Integration tests for ListRepository and subscriber memberships.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberRepository;
use WP_UnitTestCase;

/**
 * Tests list CRUD and subscriber-list memberships.
 */
class ListRepositoryTest extends WP_UnitTestCase {

	/**
	 * List repository under test.
	 *
	 * @var ListRepository
	 */
	private ListRepository $list_repo;

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
		$this->list_repo = new ListRepository();
		$this->sub_repo  = new SubscriberRepository();
	}

	/**
	 * Create a list and find it by slug.
	 *
	 * @return void
	 */
	public function test_create_list(): void {
		$id = $this->list_repo->create( 'Newsletter', 'newsletter', 'A test list' );

		$found = $this->list_repo->find_by_slug( 'newsletter' );
		$this->assertNotNull( $found );
		$this->assertSame( 'Newsletter', $found->name );
		$this->assertSame( 'newsletter', $found->slug );
		$this->assertSame( 'A test list', $found->description );
	}

	/**
	 * find_by_slug should return null for non-existent slug.
	 *
	 * @return void
	 */
	public function test_find_by_slug_returns_null_for_missing(): void {
		$this->assertNull( $this->list_repo->find_by_slug( 'nonexistent' ) );
	}

	/**
	 * Add a subscriber to a list and verify membership.
	 *
	 * @return void
	 */
	public function test_add_subscriber_to_list(): void {
		$list_id    = $this->list_repo->create( 'Newsletter', 'newsletter' );
		$subscriber = $this->sub_repo->create_or_get( 'list@example.com' );

		$this->list_repo->add_subscriber( (int) $subscriber->id, $list_id );

		$lists = $this->list_repo->get_subscriber_lists( (int) $subscriber->id );
		$this->assertCount( 1, $lists );
		$this->assertSame( 'newsletter', $lists[0]->slug );
		$this->assertSame( 'subscribed', $lists[0]->status );
	}

	/**
	 * Adding a subscriber to a list they already belong to should not
	 * create a duplicate junction row.
	 *
	 * @return void
	 */
	public function test_add_subscriber_no_duplicate(): void {
		$list_id    = $this->list_repo->create( 'Newsletter', 'newsletter' );
		$subscriber = $this->sub_repo->create_or_get( 'dup@example.com' );

		$this->list_repo->add_subscriber( (int) $subscriber->id, $list_id );
		$this->list_repo->add_subscriber( (int) $subscriber->id, $list_id );

		$lists = $this->list_repo->get_subscriber_lists( (int) $subscriber->id );
		$this->assertCount( 1, $lists );
	}

	/**
	 * Unsubscribing a subscriber from a list should set status to unsubscribed.
	 *
	 * @return void
	 */
	public function test_remove_subscriber_from_list(): void {
		$list_id    = $this->list_repo->create( 'Newsletter', 'newsletter' );
		$subscriber = $this->sub_repo->create_or_get( 'remove@example.com' );

		$this->list_repo->add_subscriber( (int) $subscriber->id, $list_id );
		$this->list_repo->remove_subscriber( (int) $subscriber->id, $list_id );

		$lists = $this->list_repo->get_subscriber_lists( (int) $subscriber->id );
		$this->assertCount( 1, $lists );
		$this->assertSame( 'unsubscribed', $lists[0]->status );
	}

	/**
	 * Re-subscribing an unsubscribed subscriber should flip the junction
	 * back to subscribed (no duplicate row).
	 *
	 * @return void
	 */
	public function test_resubscribe_flips_junction(): void {
		$list_id    = $this->list_repo->create( 'Newsletter', 'newsletter' );
		$subscriber = $this->sub_repo->create_or_get( 'resub@example.com' );

		$this->list_repo->add_subscriber( (int) $subscriber->id, $list_id );
		$this->list_repo->remove_subscriber( (int) $subscriber->id, $list_id );
		$this->list_repo->add_subscriber( (int) $subscriber->id, $list_id );

		$lists = $this->list_repo->get_subscriber_lists( (int) $subscriber->id );
		$this->assertCount( 1, $lists );
		$this->assertSame( 'subscribed', $lists[0]->status );
		$this->assertNotNull( $lists[0]->subscribed_at );
	}

	/**
	 * get_list_subscribers should return subscribed subscribers for a list.
	 *
	 * @return void
	 */
	public function test_get_list_subscribers(): void {
		$list_id = $this->list_repo->create( 'Newsletter', 'newsletter' );
		$s1      = $this->sub_repo->create_or_get( 'a@example.com' );
		$s2      = $this->sub_repo->create_or_get( 'b@example.com' );
		$s3      = $this->sub_repo->create_or_get( 'c@example.com' );

		$this->list_repo->add_subscriber( (int) $s1->id, $list_id );
		$this->list_repo->add_subscriber( (int) $s2->id, $list_id );
		// s3 is not added to the list.

		$subscribers = $this->list_repo->get_list_subscribers( $list_id );
		$this->assertCount( 2, $subscribers );
	}

	/**
	 * get_list_subscribers should filter by status.
	 *
	 * @return void
	 */
	public function test_get_list_subscribers_filters_by_status(): void {
		$list_id = $this->list_repo->create( 'Newsletter', 'newsletter' );
		$s1      = $this->sub_repo->create_or_get( 'a@example.com' );
		$s2      = $this->sub_repo->create_or_get( 'b@example.com' );

		$this->list_repo->add_subscriber( (int) $s1->id, $list_id );
		$this->list_repo->add_subscriber( (int) $s2->id, $list_id );
		$this->list_repo->remove_subscriber( (int) $s2->id, $list_id );

		$active = $this->list_repo->get_list_subscribers( $list_id, 'subscribed' );
		$this->assertCount( 1, $active );

		$unsubbed = $this->list_repo->get_list_subscribers( $list_id, 'unsubscribed' );
		$this->assertCount( 1, $unsubbed );
	}
}
