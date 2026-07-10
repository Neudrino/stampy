<?php
/**
 * Integration tests for SubscriberMetaRepository.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\SubscriberRepository;
use WP_UnitTestCase;

/**
 * Tests subscriber meta (EAV) storage and merge policy.
 */
class SubscriberMetaRepositoryTest extends WP_UnitTestCase {

	/**
	 * Meta repository under test.
	 *
	 * @var SubscriberMetaRepository
	 */
	private SubscriberMetaRepository $meta_repo;

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
		$this->meta_repo = new SubscriberMetaRepository();
		$this->sub_repo  = new SubscriberRepository();
	}

	/**
	 * set + get should round-trip a value.
	 *
	 * @return void
	 */
	public function test_set_and_get(): void {
		$subscriber = $this->sub_repo->create_or_get( 'meta@example.com' );
		$id         = (int) $subscriber->id;

		$this->meta_repo->set( $id, 'first_name', 'Alice' );

		$this->assertSame( 'Alice', $this->meta_repo->get( $id, 'first_name' ) );
	}

	/**
	 * get should return null for a non-existent key.
	 *
	 * @return void
	 */
	public function test_get_returns_null_for_missing(): void {
		$subscriber = $this->sub_repo->create_or_get( 'missing@example.com' );
		$this->assertNull( $this->meta_repo->get( (int) $subscriber->id, 'nonexistent' ) );
	}

	/**
	 * set should upsert — calling twice with the same key updates the value.
	 *
	 * @return void
	 */
	public function test_set_upserts(): void {
		$subscriber = $this->sub_repo->create_or_get( 'upsert@example.com' );
		$id         = (int) $subscriber->id;

		$this->meta_repo->set( $id, 'first_name', 'Alice' );
		$this->meta_repo->set( $id, 'first_name', 'Bob' );

		$this->assertSame( 'Bob', $this->meta_repo->get( $id, 'first_name' ) );
	}

	/**
	 * get_all should return all meta for a subscriber.
	 *
	 * @return void
	 */
	public function test_get_all(): void {
		$subscriber = $this->sub_repo->create_or_get( 'all@example.com' );
		$id         = (int) $subscriber->id;

		$this->meta_repo->set( $id, 'first_name', 'Alice' );
		$this->meta_repo->set( $id, 'last_name', 'Smith' );

		$all = $this->meta_repo->get_all( $id );
		$this->assertSame( 'Alice', $all['first_name'] );
		$this->assertSame( 'Smith', $all['last_name'] );
		$this->assertCount( 2, $all );
	}

	/**
	 * apply_merge should overwrite non-empty values and keep existing
	 * values when new is empty.
	 *
	 * @return void
	 */
	public function test_apply_merge_overwrites_non_empty(): void {
		$subscriber = $this->sub_repo->create_or_get( 'merge@example.com' );
		$id         = (int) $subscriber->id;

		$this->meta_repo->set( $id, 'first_name', 'Alice' );
		$this->meta_repo->set( $id, 'last_name', 'Smith' );

		// Non-empty value overwrites.
		$this->meta_repo->apply_merge(
			$id,
			array(
				'first_name' => 'Bob',
				'last_name'  => '',  // Empty — should NOT erase 'Smith'.
			)
		);

		$this->assertSame( 'Bob', $this->meta_repo->get( $id, 'first_name' ) );
		$this->assertSame( 'Smith', $this->meta_repo->get( $id, 'last_name' ) );
	}

	/**
	 * delete_all should remove all meta for a subscriber.
	 *
	 * @return void
	 */
	public function test_delete_all(): void {
		$subscriber = $this->sub_repo->create_or_get( 'del@example.com' );
		$id         = (int) $subscriber->id;

		$this->meta_repo->set( $id, 'first_name', 'Alice' );
		$this->meta_repo->set( $id, 'last_name', 'Smith' );

		$this->meta_repo->delete_all( $id );

		$this->assertEmpty( $this->meta_repo->get_all( $id ) );
	}

	/**
	 * Meta should be isolated per subscriber.
	 *
	 * @return void
	 */
	public function test_meta_is_isolated_per_subscriber(): void {
		$s1 = $this->sub_repo->create_or_get( 's1@example.com' );
		$s2 = $this->sub_repo->create_or_get( 's2@example.com' );

		$this->meta_repo->set( (int) $s1->id, 'first_name', 'Alice' );
		$this->meta_repo->set( (int) $s2->id, 'first_name', 'Bob' );

		$this->assertSame( 'Alice', $this->meta_repo->get( (int) $s1->id, 'first_name' ) );
		$this->assertSame( 'Bob', $this->meta_repo->get( (int) $s2->id, 'first_name' ) );
	}
}
