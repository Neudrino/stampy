<?php
/**
 * Integration tests for Phase 15: Import/Export.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\ImportExportService;
use Stampy\Installer;
use Stampy\Repositories\FieldRepository;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\SubscriberRepository;
use WP_UnitTestCase;

/**
 * Tests Phase 15: CSV/JSON export and JSON import of subscribers.
 */
class PhaseFifteenTest extends WP_UnitTestCase {

	/**
	 * Subscriber repository.
	 *
	 * @var SubscriberRepository
	 */
	private $subscribers;

	/**
	 * Subscriber meta repository.
	 *
	 * @var SubscriberMetaRepository
	 */
	private $meta;

	/**
	 * List repository.
	 *
	 * @var ListRepository
	 */
	private $lists;

	/**
	 * Field repository.
	 *
	 * @var FieldRepository
	 */
	private $fields;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		Installer::install();

		$this->subscribers = new SubscriberRepository();
		$this->meta        = new SubscriberMetaRepository();
		$this->lists       = new ListRepository();
		$this->fields      = new FieldRepository();

		// Ensure default fields exist.
		$existing = $this->fields->find_by_key( 'first_name' );
		if ( null === $existing ) {
			$this->fields->create( 'first_name', 'First Name', 'text' );
		}
		$existing = $this->fields->find_by_key( 'last_name' );
		if ( null === $existing ) {
			$this->fields->create( 'last_name', 'Last Name', 'text' );
		}

		// The test framework's _create_temporary_tables filter only fires on
		// CREATE TABLE statements. Since dbDelta() skips CREATE TABLE when the
		// real table already exists, the temporary table is never created.
		// All inserts go to the REAL table and persist across test methods.
		// Clean up before each test.
		global $wpdb;
		$subs_table      = $wpdb->prefix . 'stampy_subscribers';
		$meta_table      = $wpdb->prefix . 'stampy_subscriber_meta';
		$junction_table  = $wpdb->prefix . 'stampy_subscriber_lists';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM $meta_table" );
		$wpdb->query( "DELETE FROM $junction_table" );
		$wpdb->query( "DELETE FROM $subs_table" );
		// phpcs:enable
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Create a test subscriber with confirmed status and meta.
	 *
	 * @param string $email      Email.
	 * @param string $first_name First name.
	 * @param string $last_name  Last name.
	 * @return int Subscriber ID.
	 */
	private function create_subscriber( string $email, string $first_name = '', string $last_name = '' ): int {
		$sub = $this->subscribers->create_or_get( $email, 'confirmed', 1 );
		$this->subscribers->update_status( (int) $sub->id, 'confirmed' );

		$attributes = array();
		if ( '' !== $first_name ) {
			$attributes['first_name'] = $first_name;
		}
		if ( '' !== $last_name ) {
			$attributes['last_name'] = $last_name;
		}
		if ( count( $attributes ) > 0 ) {
			$this->meta->apply_merge( (int) $sub->id, $attributes );
		}

		return (int) $sub->id;
	}

	/**
	 * Test CSV export includes all subscribers with properties and custom fields.
	 *
	 * @return void
	 */
	public function test_export_csv_all_subscribers(): void {
		$this->create_subscriber( 'alice@stampy.local', 'Alice', 'Smith' );
		$this->create_subscriber( 'bob@stampy.local', 'Bob', 'Jones' );

		$service = new ImportExportService();
		$csv     = $service->export_csv();

		$this->assertStringContainsString( 'email', $csv );
		$this->assertStringContainsString( 'status', $csv );
		$this->assertStringContainsString( 'first_name', $csv );
		$this->assertStringContainsString( 'last_name', $csv );
		$this->assertStringContainsString( 'alice@stampy.local', $csv );
		$this->assertStringContainsString( 'bob@stampy.local', $csv );
		$this->assertStringContainsString( 'Alice', $csv );
		$this->assertStringContainsString( 'Bob', $csv );
		$this->assertStringContainsString( 'confirmed', $csv );
	}

	/**
	 * Test CSV export filtered by list.
	 *
	 * @return void
	 */
	public function test_export_csv_by_list(): void {
		$list_id = $this->lists->create( 'Test List', 'test-list' );

		$sid1 = $this->create_subscriber( 'alice@stampy.local', 'Alice' );
		$sid2 = $this->create_subscriber( 'bob@stampy.local', 'Bob' );

		$this->lists->add_subscriber( $sid1, $list_id );
		// sid2 not in the list.

		$service = new ImportExportService();
		$csv     = $service->export_csv( $list_id );

		$this->assertStringContainsString( 'alice@stampy.local', $csv );
		$this->assertStringNotContainsString( 'bob@stampy.local', $csv );
	}

	/**
	 * Test JSON export format.
	 *
	 * @return void
	 */
	public function test_export_json_format(): void {
		$this->create_subscriber( 'alice@stampy.local', 'Alice', 'Smith' );

		$service = new ImportExportService();
		$json    = $service->export_json();
		$data    = json_decode( $json, true );

		$this->assertIsArray( $data );
		$this->assertCount( 1, $data );
		$this->assertSame( 'alice@stampy.local', $data[0]['email'] );
		$this->assertSame( 'confirmed', $data[0]['status'] );
		$this->assertSame( 'Alice', $data[0]['first_name'] );
		$this->assertSame( 'Smith', $data[0]['last_name'] );
	}

	/**
	 * Test export columns order — email first.
	 *
	 * @return void
	 */
	public function test_export_columns_email_first(): void {
		$service = new ImportExportService();
		$columns = $service->export_columns();

		$this->assertSame( 'email', $columns[0] );
		$this->assertContains( 'first_name', $columns );
		$this->assertContains( 'status', $columns );
		$this->assertContains( 'consent_version', $columns );
	}

	/**
	 * Test CSV import round-trip preserves subscribers + attributes.
	 *
	 * @return void
	 */
	public function test_csv_import_round_trip(): void {
		// Create original subscribers.
		$this->create_subscriber( 'alice@stampy.local', 'Alice', 'Smith' );
		$this->create_subscriber( 'bob@stampy.local', 'Bob', 'Jones' );

		$service = new ImportExportService();
		$csv     = $service->export_csv();

		// Parse the CSV to simulate what the frontend would send.
		$rows    = array();
		$lines   = str_getcsv( $csv, "\n" );
		$headers = str_getcsv( array_shift( $lines ) );
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$values = str_getcsv( $line );
			$rows[] = array_combine( $headers, $values );
		}

		// Import is an upsert — existing subscribers are found and updated.
		$result = $service->import( $rows, 'Imported List' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['imported'] );
		$this->assertSame( 0, $result['skipped'] );

		// Verify subscribers exist with correct attributes.
		$sub = $this->subscribers->find_by_email( 'alice@stampy.local' );
		$this->assertNotNull( $sub );
		$this->assertSame( 'confirmed', $sub->status );

		$meta = $this->meta->get_all( (int) $sub->id );
		$this->assertSame( 'Alice', $meta['first_name'] ?? '' );
		$this->assertSame( 'Smith', $meta['last_name'] ?? '' );

		// Verify list membership.
		$lists = $this->lists->get_subscriber_lists( (int) $sub->id );
		$this->assertCount( 1, $lists );
		$this->assertSame( 'Imported List', $lists[0]->name );
	}

	/**
	 * Test JSON import round-trip.
	 *
	 * @return void
	 */
	public function test_json_import_round_trip(): void {
		$this->create_subscriber( 'alice@stampy.local', 'Alice', 'Smith' );

		$service = new ImportExportService();
		$json    = $service->export_json();
		$rows    = json_decode( $json, true );

		// Import is an upsert — existing subscriber is found and updated.
		$result = $service->import( $rows, 'JSON Import List' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['imported'] );

		$sub = $this->subscribers->find_by_email( 'alice@stampy.local' );
		$this->assertNotNull( $sub );
		$this->assertSame( 'confirmed', $sub->status );

		$meta = $this->meta->get_all( (int) $sub->id );
		$this->assertSame( 'Alice', $meta['first_name'] ?? '' );
	}

	/**
	 * Test import skips invalid emails.
	 *
	 * @return void
	 */
	public function test_import_skips_invalid_emails(): void {
		$rows = array(
			array(
				'email'      => 'alice@stampy.local',
				'first_name' => 'Alice',
			),
			array(
				'email'      => 'not-an-email',
				'first_name' => 'Bad',
			),
			array(
				'email'      => '',
				'first_name' => 'Empty',
			),
		);

		$service = new ImportExportService();
		$result  = $service->import( $rows, 'Skip Test List' );

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 2, $result['skipped'] );
		$this->assertCount( 2, $result['errors'] );
	}

	/**
	 * Test import upserts existing emails (does not duplicate).
	 *
	 * @return void
	 */
	public function test_import_upserts_existing_emails(): void {
		$initial_count = $this->subscribers->count();
		$sid           = $this->create_subscriber( 'alice@stampy.local', 'OldName' );

		$rows = array(
			array(
				'email'      => 'alice@stampy.local',
				'first_name' => 'NewName',
			),
		);

		$service = new ImportExportService();
		$result  = $service->import( $rows, 'Upsert List' );

		$this->assertSame( 1, $result['imported'] );

		// Should still be only one more subscriber (upsert, not duplicate).
		$count = $this->subscribers->count();
		$this->assertSame( $initial_count + 1, $count );

		// Merge policy: non-empty overwrites — name should be updated.
		$meta = $this->meta->get_all( $sid );
		$this->assertSame( 'NewName', $meta['first_name'] ?? '' );
	}

	/**
	 * Test import merge policy: empty values don't overwrite existing data.
	 *
	 * @return void
	 */
	public function test_import_merge_policy_empty_does_not_overwrite(): void {
		$sid = $this->create_subscriber( 'alice@stampy.local', 'ExistingName' );

		$rows = array(
			array(
				'email'      => 'alice@stampy.local',
				'first_name' => '',
			),
		);

		$service = new ImportExportService();
		$result  = $service->import( $rows, 'Merge Policy List' );

		$this->assertSame( 1, $result['imported'] );

		$meta = $this->meta->get_all( $sid );
		$this->assertSame( 'ExistingName', $meta['first_name'] ?? '' );
	}

	/**
	 * Test import creates the user-chosen list.
	 *
	 * @return void
	 */
	public function test_import_creates_list(): void {
		$rows = array(
			array(
				'email' => 'alice@stampy.local',
			),
		);

		$service = new ImportExportService();
		$result  = $service->import( $rows, 'My Custom Import List' );

		$list = $this->lists->find( $result['list_id'] );
		$this->assertNotNull( $list );
		$this->assertSame( 'My Custom Import List', $list->name );
	}

	/**
	 * Test REST export endpoint returns CSV.
	 *
	 * @return void
	 */
	public function test_rest_export_endpoint_csv(): void {
		$this->create_subscriber( 'alice@stampy.local', 'Alice' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request  = new \WP_REST_Request( 'GET', '/stampy/v1/export' );
		$request->set_param( 'format', 'csv' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertStringContainsString( 'alice@stampy.local', $data['data'] );
	}

	/**
	 * Test REST export endpoint returns JSON.
	 *
	 * @return void
	 */
	public function test_rest_export_endpoint_json(): void {
		$this->create_subscriber( 'alice@stampy.local', 'Alice' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request  = new \WP_REST_Request( 'GET', '/stampy/v1/export' );
		$request->set_param( 'format', 'json' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data['data'] );
		$this->assertSame( 'alice@stampy.local', $data['data'][0]['email'] );
	}

	/**
	 * Test REST import endpoint.
	 *
	 * @return void
	 */
	public function test_rest_import_endpoint(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new \WP_REST_Request( 'POST', '/stampy/v1/import' );
		$request->set_param(
			'rows',
			array(
				array(
					'email'      => 'alice@stampy.local',
					'first_name' => 'Alice',
				),
			)
		);
		$request->set_param( 'list_name', 'REST Import List' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( 1, $data['imported'] );

		$sub = $this->subscribers->find_by_email( 'alice@stampy.local' );
		$this->assertNotNull( $sub );
	}

	/**
	 * Test REST export endpoint requires authentication.
	 *
	 * @return void
	 */
	public function test_rest_export_requires_auth(): void {
		wp_set_current_user( 0 );

		$request  = new \WP_REST_Request( 'GET', '/stampy/v1/export' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Test REST import endpoint requires authentication.
	 *
	 * @return void
	 */
	public function test_rest_import_requires_auth(): void {
		wp_set_current_user( 0 );

		$request = new \WP_REST_Request( 'POST', '/stampy/v1/import' );
		$request->set_param( 'rows', array( array( 'email' => 'alice@stampy.local' ) ) );
		$request->set_param( 'list_name', 'Test' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}
}
