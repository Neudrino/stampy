<?php
/**
 * Integration tests for field management and subscriber profile editing.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Repositories\FieldRepository;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\SubscriberRepository;
use WP_UnitTestCase;

/**
 * Tests field CRUD operations and subscriber profile editing.
 */
class FieldManagementTest extends WP_UnitTestCase {

	/**
	 * Field repository.
	 *
	 * @var FieldRepository
	 */
	private FieldRepository $field_repo;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		Installer::install();

		$this->field_repo = new FieldRepository();
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Create a field definition.
	 *
	 * @return void
	 */
	public function test_create_field(): void {
		$id = $this->field_repo->create( 'company', 'Company', 'text', null, false, null, true );

		$this->assertGreaterThan( 0, $id );

		$field = $this->field_repo->find( $id );
		$this->assertNotNull( $field );
		$this->assertSame( 'company', $field->field_key );
		$this->assertSame( 'Company', $field->label );
		$this->assertSame( 'text', $field->type );
	}

	/**
	 * Find a field by key.
	 *
	 * @return void
	 */
	public function test_find_by_key(): void {
		$id = $this->field_repo->create( 'phone', 'Phone', 'text' );

		$field = $this->field_repo->find_by_key( 'phone' );
		$this->assertNotNull( $field );
		$this->assertSame( $id, (int) $field->id );
	}

	/**
	 * Update a field definition.
	 *
	 * @return void
	 */
	public function test_update_field(): void {
		$id = $this->field_repo->create( 'job_title', 'Job Title', 'text' );

		$result = $this->field_repo->update(
			$id,
			'job_title',
			'Job Title (Updated)',
			'select',
			array( 'Developer', 'Designer', 'Manager' ),
			true,
			null,
			true
		);

		$this->assertTrue( $result );

		$field = $this->field_repo->find( $id );
		$this->assertSame( 'Job Title (Updated)', $field->label );
		$this->assertSame( 'select', $field->type );
		$this->assertSame( '1', (string) $field->required );
	}

	/**
	 * Delete a field definition.
	 *
	 * @return void
	 */
	public function test_delete_field(): void {
		$id = $this->field_repo->create( 'temp_field', 'Temp', 'text' );

		$result = $this->field_repo->delete( $id );
		$this->assertTrue( $result );

		$field = $this->field_repo->find( $id );
		$this->assertNull( $field );
	}

	/**
	 * Get all fields (admin only filter).
	 *
	 * @return void
	 */
	public function test_all_admin_only(): void {
		$this->field_repo->create( 'visible_field', 'Visible', 'text', null, false, null, true );
		$this->field_repo->create( 'hidden_field', 'Hidden', 'text', null, false, null, false );

		$all = $this->field_repo->all();
		$this->assertGreaterThanOrEqual( 2, count( $all ) );

		$admin_only = $this->field_repo->all( true );
		$keys       = array_map( fn( $f ) => $f->field_key, $admin_only );
		$this->assertContains( 'visible_field', $keys );
		$this->assertNotContains( 'hidden_field', $keys );
	}

	/**
	 * Subscriber profile: set and get meta values.
	 *
	 * @return void
	 */
	public function test_subscriber_profile_set_get_meta(): void {
		$subscriber_repo = new SubscriberRepository();
		$meta_repo       = new SubscriberMetaRepository();

		$subscriber = $subscriber_repo->create_or_get( 'profile-test@example.com', 'confirmed' );
		$sub_id     = (int) $subscriber->id;

		$meta_repo->set( $sub_id, 'company', 'ACME Corp' );
		$meta_repo->set( $sub_id, 'phone', '+1234567890' );

		$this->assertSame( 'ACME Corp', $meta_repo->get( $sub_id, 'company' ) );
		$this->assertSame( '+1234567890', $meta_repo->get( $sub_id, 'phone' ) );

		$all = $meta_repo->get_all( $sub_id );
		$this->assertArrayHasKey( 'company', $all );
		$this->assertArrayHasKey( 'phone', $all );
	}

	/**
	 * Subscriber profile: merge policy overwrites non-empty values.
	 *
	 * @return void
	 */
	public function test_subscriber_profile_merge_overwrites(): void {
		$subscriber_repo = new SubscriberRepository();
		$meta_repo       = new SubscriberMetaRepository();

		$subscriber = $subscriber_repo->create_or_get( 'merge-test@example.com', 'confirmed' );
		$sub_id     = (int) $subscriber->id;

		$meta_repo->set( $sub_id, 'company', 'Old Corp' );
		$meta_repo->apply_merge( $sub_id, array( 'company' => 'New Corp', 'phone' => '555-0100' ) );

		$this->assertSame( 'New Corp', $meta_repo->get( $sub_id, 'company' ) );
		$this->assertSame( '555-0100', $meta_repo->get( $sub_id, 'phone' ) );
	}

	/**
	 * Field definition with options stores JSON.
	 *
	 * @return void
	 */
	public function test_field_with_options_stores_json(): void {
		$options = array( 'Option A', 'Option B', 'Option C' );
		$id      = $this->field_repo->create( 'choice_field', 'Choice', 'select', $options );

		$field  = $this->field_repo->find( $id );
		$stored = json_decode( (string) $field->options, true );

		$this->assertSame( $options, $stored );
	}
}
