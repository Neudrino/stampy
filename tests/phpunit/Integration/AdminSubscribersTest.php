<?php
/**
 * Integration tests for the admin subscribers page.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Admin\AdminMenu;
use Stampy\Admin\SubscribersListTable;
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

		// Clean up subscribers created by tests AFTER parent::tearDown()
		// because dbDelta()'s implicit commit means data persists across
		// test classes.
		global $wpdb;
		$subscribers_table = $wpdb->prefix . 'stampy_subscribers';
		$meta_table        = $wpdb->prefix . 'stampy_subscriber_meta';
		$test_emails       = array(
			'bulk1@example.com',
			'bulk2@example.com',
			'bulk3@example.com',
			'bulk4@example.com',
			'bulk5@example.com',
			'bulk-del1@example.com',
			'bulk-del2@example.com',
			'nonce-test@example.com',
			'redirect-test@example.com',
			'confirmed-at@example.com',
			'unsub-at@example.com',
			'names@example.com',
			'delete-me@example.com',
			'test@example.com',
			'as-pending@example.com',
			'as-confirmed@example.com',
			'as-alice@example.com',
			'as-bob@example.com',
			'cf-a@example.com',
			'cf-b@example.com',
			'cf-c@example.com',
			'a@example.com',
			'b@example.com',
			'c@example.com',
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $test_emails as $email ) {
			$sub_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $subscribers_table WHERE email = %s", $email ) );
			if ( $sub_id ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM $meta_table WHERE subscriber_id = %d", (int) $sub_id ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM $subscribers_table WHERE id = %d", (int) $sub_id ) );
			}
		}
		// phpcs:enable
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
		$this->subscribers->create_or_get( 'as-pending@example.com', 'pending' );
		$this->subscribers->create_or_get( 'as-confirmed@example.com', 'confirmed' );

		$results = $this->subscribers->get_all( array( 'status' => 'confirmed', 'search' => 'as-' ) );

		$this->assertCount( 1, $results );
		$this->assertSame( 'as-confirmed@example.com', $results[0]->email );
	}

	/**
	 * get_all should search by email.
	 *
	 * @return void
	 */
	public function test_get_all_search_by_email(): void {
		$this->subscribers->create_or_get( 'as-alice@example.com' );
		$this->subscribers->create_or_get( 'as-bob@example.com' );

		$results = $this->subscribers->get_all( array( 'search' => 'as-alice' ) );

		$this->assertCount( 1, $results );
		$this->assertSame( 'as-alice@example.com', $results[0]->email );
	}

	/**
	 * count_filtered should return correct count with filters.
	 *
	 * @return void
	 */
	public function test_count_filtered(): void {
		$this->subscribers->create_or_get( 'cf-a@example.com', 'pending' );
		$this->subscribers->create_or_get( 'cf-b@example.com', 'confirmed' );
		$this->subscribers->create_or_get( 'cf-c@example.com', 'confirmed' );

		$this->assertSame( 3, $this->subscribers->count_filtered( array( 'search' => 'cf-' ) ) );
		$this->assertSame( 2, $this->subscribers->count_filtered( array( 'status' => 'confirmed', 'search' => 'cf-' ) ) );
		$this->assertSame( 1, $this->subscribers->count_filtered( array( 'search' => 'cf-a@' ) ) );
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

	/**
	 * Helper: simulate a bulk action POST. Throws if the action triggers
	 * a redirect (which is the expected behavior).
	 *
	 * @param array<int> $subscriber_ids Subscriber IDs to act on.
	 * @param string     $action         Bulk action key.
	 * @return void
	 * @throws \RuntimeException When wp_safe_redirect is intercepted.
	 */
	private function do_bulk_action( array $subscriber_ids, string $action ): void {
		$post = array(
			'action'           => $action,
			'action2'          => '-1',
			'subscriber'       => array_map( 'strval', $subscriber_ids ),
			'_wpnonce'         => wp_create_nonce( 'bulk-subscribers' ),
			'_wp_http_referer' => '',
		);
		$_POST    = $post;
		$_REQUEST = $post;

		// handle_bulk_action() runs on load-{hook} and calls exit after redirect.
		SubscribersListTable::handle_bulk_action();
	}

	/**
	 * Bulk action set_confirmed should update subscriber status.
	 *
	 * @return void
	 */
	public function test_bulk_set_confirmed(): void {
		$s1 = $this->subscribers->create_or_get( 'bulk1@example.com', 'pending' );
		$s2 = $this->subscribers->create_or_get( 'bulk2@example.com', 'pending' );

		try {
			$this->do_bulk_action( array( (int) $s1->id, (int) $s2->id ), 'set_confirmed' );
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted — expected.
		}

		$this->assertSame( 'confirmed', $this->subscribers->find( (int) $s1->id )->status );
		$this->assertSame( 'confirmed', $this->subscribers->find( (int) $s2->id )->status );
	}

	/**
	 * Bulk action set_unsubscribed should update subscriber status.
	 *
	 * @return void
	 */
	public function test_bulk_set_unsubscribed(): void {
		$s1 = $this->subscribers->create_or_get( 'bulk3@example.com', 'confirmed' );
		$s2 = $this->subscribers->create_or_get( 'bulk4@example.com', 'confirmed' );

		try {
			$this->do_bulk_action( array( (int) $s1->id, (int) $s2->id ), 'set_unsubscribed' );
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted — expected.
		}

		$this->assertSame( 'unsubscribed', $this->subscribers->find( (int) $s1->id )->status );
		$this->assertSame( 'unsubscribed', $this->subscribers->find( (int) $s2->id )->status );
	}

	/**
	 * Bulk action set_pending should update subscriber status.
	 *
	 * @return void
	 */
	public function test_bulk_set_pending(): void {
		$s1 = $this->subscribers->create_or_get( 'bulk5@example.com', 'confirmed' );

		try {
			$this->do_bulk_action( array( (int) $s1->id ), 'set_pending' );
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted — expected.
		}

		$this->assertSame( 'pending', $this->subscribers->find( (int) $s1->id )->status );
	}

	/**
	 * Bulk action delete should remove subscribers.
	 *
	 * @return void
	 */
	public function test_bulk_delete(): void {
		$s1 = $this->subscribers->create_or_get( 'bulk-del1@example.com' );
		$s2 = $this->subscribers->create_or_get( 'bulk-del2@example.com' );

		try {
			$this->do_bulk_action( array( (int) $s1->id, (int) $s2->id ), 'delete' );
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted — expected.
		}

		$this->assertNull( $this->subscribers->find( (int) $s1->id ) );
		$this->assertNull( $this->subscribers->find( (int) $s2->id ) );
	}

	/**
	 * Bulk action with invalid nonce should not process.
	 *
	 * @return void
	 */
	public function test_bulk_action_invalid_nonce(): void {
		$s1 = $this->subscribers->create_or_get( 'nonce-test@example.com', 'pending' );

		$post = array(
			'action'           => 'set_confirmed',
			'action2'          => '-1',
			'subscriber'       => array( (string) $s1->id ),
			'_wpnonce'         => 'invalid',
			'_wp_http_referer' => '',
		);
		$_POST    = $post;
		$_REQUEST = $post;

		// check_admin_referer with invalid nonce calls wp_die().
		try {
			SubscribersListTable::handle_bulk_action();
		} catch ( \RuntimeException $e ) {
			// Redirect may fire — but with 0 IDs processed.
		} catch ( \WPDieException $e ) {
			// Expected: invalid nonce triggers wp_die.
		}

		// Status should NOT have changed.
		$this->assertSame( 'pending', $this->subscribers->find( (int) $s1->id )->status );
	}

	/**
	 * Bulk action should redirect after processing.
	 *
	 * @return void
	 */
	public function test_bulk_action_redirects(): void {
		$s1 = $this->subscribers->create_or_get( 'redirect-test@example.com', 'pending' );

		$redirected = false;
		try {
			$this->do_bulk_action( array( (int) $s1->id ), 'set_confirmed' );
		} catch ( \RuntimeException $e ) {
			if ( 'redirect intercepted' === $e->getMessage() ) {
				$redirected = true;
			}
		}

		$this->assertTrue( $redirected, 'Bulk action should redirect after processing' );
	}

	/**
	 * Bulk action should set confirmed_at when confirming.
	 *
	 * @return void
	 */
	public function test_bulk_confirmed_sets_confirmed_at(): void {
		$s1 = $this->subscribers->create_or_get( 'confirmed-at@example.com', 'pending' );

		try {
			$this->do_bulk_action( array( (int) $s1->id ), 'set_confirmed' );
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted — expected.
		}

		$updated = $this->subscribers->find( (int) $s1->id );
		$this->assertSame( 'confirmed', $updated->status );
		$this->assertNotEmpty( $updated->confirmed_at );
	}

	/**
	 * Bulk action should set unsubscribed_at when unsubscribing.
	 *
	 * @return void
	 */
	public function test_bulk_unsubscribed_sets_unsubscribed_at(): void {
		$s1 = $this->subscribers->create_or_get( 'unsub-at@example.com', 'confirmed' );

		try {
			$this->do_bulk_action( array( (int) $s1->id ), 'set_unsubscribed' );
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted — expected.
		}

		$updated = $this->subscribers->find( (int) $s1->id );
		$this->assertSame( 'unsubscribed', $updated->status );
		$this->assertNotEmpty( $updated->unsubscribed_at );
	}

	/**
	 * First name and last name columns should show meta values.
	 *
	 * @return void
	 */
	public function test_first_last_name_columns(): void {
		$meta_repo   = new \Stampy\Repositories\SubscriberMetaRepository();
		$subscriber  = $this->subscribers->create_or_get( 'names@example.com', 'confirmed' );
		$meta_repo->set( (int) $subscriber->id, 'first_name', 'Jane' );
		$meta_repo->set( (int) $subscriber->id, 'last_name', 'Doe' );

		$table = new SubscribersListTable();

		// Access the page_meta cache directly to verify column rendering.
		$reflection = new \ReflectionClass( $table );
		$prop       = $reflection->getProperty( 'page_meta' );
		$prop->setAccessible( true );

		$item = new \stdClass();
		$item->id = (int) $subscriber->id;

		// Manually populate the cache.
		$prop->setValue( $table, array( (int) $subscriber->id => array( 'first_name' => 'Jane', 'last_name' => 'Doe' ) ) );

		$first = $table->column_first_name( $item );
		$last  = $table->column_last_name( $item );

		$this->assertStringContainsString( 'Jane', $first );
		$this->assertStringContainsString( 'Doe', $last );
	}
}
