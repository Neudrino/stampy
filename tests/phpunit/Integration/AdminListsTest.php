<?php
/**
 * Integration tests for the admin lists page.
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
 * Tests admin lists page: CRUD, subscriber counts, capability checks.
 */
class AdminListsTest extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * List repository.
	 *
	 * @var ListRepository
	 */
	private $lists;

	/**
	 * Subscriber repository.
	 *
	 * @var SubscriberRepository
	 */
	private $subscribers;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		Installer::install();

		$this->admin_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Prevent wp_safe_redirect from calling exit during tests.
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ), 1 );

		$this->lists       = new ListRepository();
		$this->subscribers = new SubscriberRepository();
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
	 * Creating a list should work via the repository.
	 *
	 * @return void
	 */
	public function test_create_list(): void {
		$id = $this->lists->create( 'Newsletter', 'newsletter', 'Weekly newsletter' );

		$list = $this->lists->find( $id );
		$this->assertNotNull( $list );
		$this->assertSame( 'Newsletter', $list->name );
		$this->assertSame( 'newsletter', $list->slug );
		$this->assertSame( 'Weekly newsletter', $list->description );
	}

	/**
	 * Updating a list should change its fields.
	 *
	 * @return void
	 */
	public function test_update_list(): void {
		$id = $this->lists->create( 'Old Name', 'old-slug', 'Old desc' );

		$this->lists->update( $id, 'New Name', 'new-slug', 'New desc' );

		$list = $this->lists->find( $id );
		$this->assertSame( 'New Name', $list->name );
		$this->assertSame( 'new-slug', $list->slug );
		$this->assertSame( 'New desc', $list->description );
	}

	/**
	 * Deleting a list should remove it and its memberships.
	 *
	 * @return void
	 */
	public function test_delete_list(): void {
		$list_id     = $this->lists->create( 'Delete Me', 'delete-me' );
		$subscriber  = $this->subscribers->create_or_get( 'test@example.com', 'confirmed' );

		$this->lists->add_subscriber( (int) $subscriber->id, $list_id );

		$memberships = $this->lists->get_subscriber_lists( (int) $subscriber->id );
		$this->assertCount( 1, $memberships );

		$this->lists->delete( $list_id );

		$this->assertNull( $this->lists->find( $list_id ) );
		$memberships = $this->lists->get_subscriber_lists( (int) $subscriber->id );
		$this->assertCount( 0, $memberships );
	}

	/**
	 * count_subscribers should return the correct count.
	 *
	 * @return void
	 */
	public function test_count_subscribers(): void {
		$list_id    = $this->lists->create( 'Count Test', 'count-test' );
		$subscriber = $this->subscribers->create_or_get( 'a@example.com', 'confirmed' );
		$subscriber2 = $this->subscribers->create_or_get( 'b@example.com', 'confirmed' );

		$this->lists->add_subscriber( (int) $subscriber->id, $list_id );
		$this->lists->add_subscriber( (int) $subscriber2->id, $list_id );

		$this->assertSame( 2, $this->lists->count_subscribers( $list_id ) );
	}

	/**
	 * all_with_counts should include subscriber counts.
	 *
	 * @return void
	 */
	public function test_all_with_counts(): void {
		$list_id    = $this->lists->create( 'With Counts', 'with-counts' );
		$subscriber = $this->subscribers->create_or_get( 'a@example.com', 'confirmed' );
		$this->lists->add_subscriber( (int) $subscriber->id, $list_id );

		$lists = $this->lists->all_with_counts();

		$this->assertNotEmpty( $lists );
		$found = false;
		foreach ( $lists as $list ) {
			if ( (int) $list->id === $list_id ) {
				$this->assertSame( '1', (string) $list->subscriber_count );
				$found = true;
			}
		}
		$this->assertTrue( $found, 'List not found in all_with_counts result' );
	}

	/**
	 * Saving a list via admin_post should create or update.
	 *
	 * @return void
	 */
	public function test_handle_save_creates_list(): void {
		$_POST = array(
			'action'           => 'stampy_save_list',
			'list_id'          => '0',
			'list_name'        => 'Via Admin',
			'list_slug'        => 'via-admin',
			'list_description' => 'Created via admin post',
			'stampy_list_nonce' => wp_create_nonce( 'stampy_save_list_0' ),
		);
		$_REQUEST = $_POST;

		try {
			\Stampy\Admin\ListsPage::handle_save();
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted.
		}

		$list = $this->lists->find_by_slug( 'via-admin' );
		$this->assertNotNull( $list );
		$this->assertSame( 'Via Admin', $list->name );
	}

	/**
	 * Saving an existing list via admin_post should update it.
	 *
	 * @return void
	 */
	public function test_handle_save_updates_list(): void {
		$list_id = $this->lists->create( 'Original', 'original' );

		$_POST = array(
			'action'            => 'stampy_save_list',
			'list_id'           => (string) $list_id,
			'list_name'         => 'Updated Name',
			'list_slug'         => 'updated-slug',
			'list_description'  => 'Updated description',
			'stampy_list_nonce' => wp_create_nonce( 'stampy_save_list_' . $list_id ),
		);
		$_REQUEST = $_POST;

		try {
			\Stampy\Admin\ListsPage::handle_save();
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted.
		}

		$list = $this->lists->find( $list_id );
		$this->assertSame( 'Updated Name', $list->name );
		$this->assertSame( 'updated-slug', $list->slug );
	}

	/**
	 * Non-admin user should be denied list save.
	 *
	 * @return void
	 */
	public function test_non_admin_denied_list_save(): void {
		$non_admin = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $non_admin );

		$_POST = array(
			'action'            => 'stampy_save_list',
			'list_id'           => '0',
			'list_name'         => 'Should Fail',
			'list_slug'         => 'should-fail',
			'list_description'  => '',
			'stampy_list_nonce' => wp_create_nonce( 'stampy_save_list_0' ),
		);
		$_REQUEST = $_POST;

		$this->expectException( \WPDieException::class );
		\Stampy\Admin\ListsPage::handle_save();
	}

	/**
	 * Invalid nonce should fail list save.
	 *
	 * @return void
	 */
	public function test_invalid_nonce_fails_list_save(): void {
		$_POST = array(
			'action'            => 'stampy_save_list',
			'list_id'           => '0',
			'list_name'         => 'Should Fail',
			'list_slug'         => 'should-fail',
			'list_description'  => '',
			'stampy_list_nonce' => 'invalid',
		);
		$_REQUEST = $_POST;

		$this->expectException( \WPDieException::class );
		\Stampy\Admin\ListsPage::handle_save();
	}
}
