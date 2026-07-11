<?php
/**
 * Integration tests for the admin subscribers page.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Admin\AdminMenu;
use Stampy\Installer;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberRepository;
use WP_UnitTestCase;

/**
 * Tests admin subscribers page: capability checks, nonce protection,
 * status/list editing, bulk actions.
 */
class AdminSubscribersTest extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber repository.
	 *
	 * @var SubscriberRepository
	 */
	private $subscribers;

	/**
	 * List repository.
	 *
	 * @var ListRepository
	 */
	private $lists;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		Installer::install();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Prevent wp_safe_redirect from calling exit during tests.
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ), 1 );

		$this->subscribers = new SubscriberRepository();
		$this->lists       = new ListRepository();
	}

	/**
	 * Intercept wp_redirect to prevent exit by throwing.
	 *
	 * @return never
	 * @throws \RuntimeException Always.
	 */
	public function intercept_redirect(): never {
		throw new \RuntimeException( 'redirect intercepted' );
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $_POST, $_REQUEST );
		parent::tearDown();
	}

	/**
	 * Helper: find or create a list (avoids duplicate slug errors from
	 * dbDelta committing the first test's transaction).
	 *
	 * @param string $slug List slug.
	 * @return int List ID.
	 */
	private function ensure_list( string $slug = 'test-list' ): int {
		$existing = $this->lists->find_by_slug( $slug );
		if ( null !== $existing ) {
			return (int) $existing->id;
		}
		return $this->lists->create( 'Test List', $slug, 'Test description' );
	}

	/**
	 * Admin menu should be registered.
	 *
	 * @return void
	 */
	public function test_admin_menu_registered(): void {
		global $submenu;

		AdminMenu::add_menu();

		$this->assertNotEmpty( $submenu );
		$found_subscribers = false;
		$found_lists       = false;

		foreach ( $submenu as $parent => $items ) {
			if ( 'stampy-subscribers' === $parent ) {
				foreach ( $items as $item ) {
					if ( 'stampy-subscribers' === $item[2] ) {
						$found_subscribers = true;
					}
					if ( 'stampy-lists' === $item[2] ) {
						$found_lists = true;
					}
				}
			}
		}

		$this->assertTrue( $found_subscribers, 'Subscribers sub-menu not registered' );
		$this->assertTrue( $found_lists, 'Lists sub-menu not registered' );
	}

	/**
	 * get_all should return subscribers with pagination.
	 *
	 * @return void
	 */
	public function test_get_all_returns_subscribers(): void {
		$this->subscribers->create_or_get( 'a@example.com' );
		$this->subscribers->create_or_get( 'b@example.com' );
		$this->subscribers->create_or_get( 'c@example.com' );

		$results = $this->subscribers->get_all( array( 'per_page' => 2, 'page' => 1 ) );

		$this->assertCount( 2, $results );
	}

	/**
	 * get_all should filter by status.
	 *
	 * @return void
	 */
	public function test_get_all_filter_by_status(): void {
		$this->subscribers->create_or_get( 'pending@example.com', 'pending' );
		$this->subscribers->create_or_get( 'confirmed@example.com', 'confirmed' );

		$results = $this->subscribers->get_all( array( 'status' => 'confirmed' ) );

		$this->assertCount( 1, $results );
		$this->assertSame( 'confirmed@example.com', $results[0]->email );
	}

	/**
	 * get_all should search by email.
	 *
	 * @return void
	 */
	public function test_get_all_search_by_email(): void {
		$this->subscribers->create_or_get( 'alice@example.com' );
		$this->subscribers->create_or_get( 'bob@example.com' );

		$results = $this->subscribers->get_all( array( 'search' => 'alice' ) );

		$this->assertCount( 1, $results );
		$this->assertSame( 'alice@example.com', $results[0]->email );
	}

	/**
	 * count_filtered should return correct count with filters.
	 *
	 * @return void
	 */
	public function test_count_filtered(): void {
		$this->subscribers->create_or_get( 'a@example.com', 'pending' );
		$this->subscribers->create_or_get( 'b@example.com', 'confirmed' );
		$this->subscribers->create_or_get( 'c@example.com', 'confirmed' );

		$this->assertSame( 3, $this->subscribers->count_filtered() );
		$this->assertSame( 2, $this->subscribers->count_filtered( array( 'status' => 'confirmed' ) ) );
		$this->assertSame( 1, $this->subscribers->count_filtered( array( 'search' => 'a@' ) ) );
	}

	/**
	 * Saving a subscriber's status should update it.
	 *
	 * @return void
	 */
	public function test_save_subscriber_status(): void {
		$subscriber = $this->subscribers->create_or_get( 'test@example.com', 'pending' );

		$_POST = array(
			'action'         => 'stampy_save_subscriber',
			'subscriber_id'  => (string) $subscriber->id,
			'status'         => 'confirmed',
			'list_ids'       => array(),
			'stampy_nonce'   => wp_create_nonce( 'stampy_save_subscriber_' . $subscriber->id ),
		);
		$_REQUEST = $_POST;

		try {
			\Stampy\Admin\SubscribersPage::handle_save();
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted.
		}

		$updated = $this->subscribers->find( (int) $subscriber->id );
		$this->assertSame( 'confirmed', $updated->status );
	}

	/**
	 * Saving list memberships should add/remove subscriptions.
	 *
	 * @return void
	 */
	public function test_save_subscriber_list_memberships(): void {
		$subscriber = $this->subscribers->create_or_get( 'test@example.com', 'confirmed' );
		$list_id    = $this->ensure_list();

		$_POST = array(
			'action'         => 'stampy_save_subscriber',
			'subscriber_id'  => (string) $subscriber->id,
			'status'         => 'confirmed',
			'list_ids'       => array( (string) $list_id ),
			'stampy_nonce'   => wp_create_nonce( 'stampy_save_subscriber_' . $subscriber->id ),
		);
		$_REQUEST = $_POST;

		try {
			\Stampy\Admin\SubscribersPage::handle_save();
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted.
		}

		$my_lists = $this->lists->get_subscriber_lists( (int) $subscriber->id );
		$this->assertCount( 1, $my_lists );
		$this->assertSame( 'Test List', $my_lists[0]->name );
		$this->assertSame( 'subscribed', $my_lists[0]->status );

		// Now remove the list.
		$_POST = array(
			'action'         => 'stampy_save_subscriber',
			'subscriber_id'  => (string) $subscriber->id,
			'status'         => 'confirmed',
			'list_ids'       => array(),
			'stampy_nonce'   => wp_create_nonce( 'stampy_save_subscriber_' . $subscriber->id ),
		);
		$_REQUEST = $_POST;

		try {
			\Stampy\Admin\SubscribersPage::handle_save();
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted.
		}

		$my_lists = $this->lists->get_subscriber_lists( (int) $subscriber->id );
		$this->assertCount( 1, $my_lists );
		$this->assertSame( 'unsubscribed', $my_lists[0]->status );
	}

	/**
	 * Non-admin user should be denied.
	 *
	 * @return void
	 */
	public function test_non_admin_denied(): void {
		$subscriber = $this->subscribers->create_or_get( 'test@example.com', 'pending' );

		$non_admin = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $non_admin );

		$_POST = array(
			'action'         => 'stampy_save_subscriber',
			'subscriber_id'  => (string) $subscriber->id,
			'status'         => 'confirmed',
			'list_ids'       => array(),
			'stampy_nonce'   => wp_create_nonce( 'stampy_save_subscriber_' . $subscriber->id ),
		);
		$_REQUEST = $_POST;

		$this->expectException( \WPDieException::class );
		\Stampy\Admin\SubscribersPage::handle_save();
	}

	/**
	 * Invalid nonce should fail.
	 *
	 * @return void
	 */
	public function test_invalid_nonce_fails(): void {
		$subscriber = $this->subscribers->create_or_get( 'test@example.com', 'pending' );

		$_POST = array(
			'action'         => 'stampy_save_subscriber',
			'subscriber_id'  => (string) $subscriber->id,
			'status'         => 'confirmed',
			'list_ids'       => array(),
			'stampy_nonce'   => 'invalid',
		);
		$_REQUEST = $_POST;

		$this->expectException( \WPDieException::class );
		\Stampy\Admin\SubscribersPage::handle_save();
	}

	/**
	 * Deleting a subscriber should remove them.
	 *
	 * @return void
	 */
	public function test_delete_subscriber(): void {
		$subscriber = $this->subscribers->create_or_get( 'delete-me@example.com' );
		$id         = (int) $subscriber->id;

		$this->assertNotNull( $this->subscribers->find( $id ) );

		$this->subscribers->delete( $id );

		$this->assertNull( $this->subscribers->find( $id ) );
	}
}
