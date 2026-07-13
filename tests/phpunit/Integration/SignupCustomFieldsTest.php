<?php
/**
 * Integration tests for signup with custom fields.
 *
 * Tests that custom field values submitted during signup are validated
 * against their registered field type, persisted to subscriber_meta after
 * confirmation, and rejected when invalid.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Repositories\FieldRepository;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\SignupService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Tests custom field submission, validation, and persistence.
 */
class SignupCustomFieldsTest extends WP_UnitTestCase {

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

		$list_repo     = new ListRepository();
		$this->list_id = $list_repo->create( 'Newsletter', 'newsletter', 'Test list' );

		// Create custom field definitions.
		$field_repo = new FieldRepository();
		$field_repo->create( 'company', 'Company', 'text' );
		$field_repo->create( 'age', 'Age', 'number' );
		$field_repo->create( 'country', 'Country', 'select', array( 'US', 'DE', 'FR' ) );
		$field_repo->create( 'bio', 'Bio', 'textarea' );

		unset( $GLOBALS['phpmailer_mock_sent'] );
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $GLOBALS['phpmailer_mock_sent'] );

		global $wpdb;
		$table      = \Stampy\Schema::table( 'subscribers', $wpdb );
		$meta_table = \Stampy\Schema::table( 'subscriber_meta', $wpdb );
		$emails     = array(
			'custom@example.com',
			'invalid-number@example.com',
			'merge@example.com',
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

		parent::tearDown();
	}

	/**
	 * Extract the confirmation token from the last sent email.
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

	/**
	 * Custom field values submitted during signup should be persisted to
	 * subscriber_meta after confirmation.
	 *
	 * @return void
	 */
	public function test_custom_fields_persisted_after_confirm(): void {
		$service = new SignupService();

		$result = $service->signup(
			array(
				'email'    => 'custom@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(
					'first_name' => 'Jane',
					'last_name'  => 'Doe',
					'company'   => 'ACME Corp',
					'age'       => '30',
					'country'   => 'US',
					'bio'       => 'Software engineer',
				),
			)
		);

		$this->assertTrue( $result['success'] );

		$subscriber_repo = new SubscriberRepository();
		$subscriber      = $subscriber_repo->find_by_email( 'custom@example.com' );
		$this->assertNotNull( $subscriber );

		$token   = $this->extract_token_from_email();
		$confirm = $service->confirm( $token );
		$this->assertTrue( $confirm['success'] );

		$meta_repo = new SubscriberMetaRepository();
		$sub_id    = (int) $subscriber->id;

		$this->assertSame( 'Jane', $meta_repo->get( $sub_id, 'first_name' ) );
		$this->assertSame( 'Doe', $meta_repo->get( $sub_id, 'last_name' ) );
		$this->assertSame( 'ACME Corp', $meta_repo->get( $sub_id, 'company' ) );
		$this->assertSame( '30', $meta_repo->get( $sub_id, 'age' ) );
		$this->assertSame( 'US', $meta_repo->get( $sub_id, 'country' ) );
		$this->assertSame( 'Software engineer', $meta_repo->get( $sub_id, 'bio' ) );
	}

	/**
	 * Invalid number field value should be rejected at signup.
	 *
	 * @return void
	 */
	public function test_invalid_number_field_rejected(): void {
		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'    => 'invalid-number@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(
					'age' => 'not-a-number',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
		$this->assertArrayHasKey( 'errors', $data );
		$this->assertArrayHasKey( 'age', $data['errors'] );
	}

	/**
	 * Valid number field value should be accepted.
	 *
	 * @return void
	 */
	public function test_valid_number_field_accepted(): void {
		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'    => 'custom@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(
					'age' => '42',
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Already-confirmed subscriber: custom field values are merged immediately.
	 *
	 * @return void
	 */
	public function test_confirmed_subscriber_custom_fields_merged_immediately(): void {
		$subscriber_repo = new SubscriberRepository();
		$subscriber      = $subscriber_repo->create_or_get( 'merge@example.com', 'confirmed' );
		$subscriber_repo->update_status( (int) $subscriber->id, 'confirmed' );

		$service = new SignupService();
		$result  = $service->signup(
			array(
				'email'    => 'merge@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(
					'company' => 'New Corp',
					'country' => 'DE',
				),
			)
		);

		$this->assertTrue( $result['success'] );

		$meta_repo = new SubscriberMetaRepository();
		$sub_id    = (int) $subscriber->id;

		$this->assertSame( 'New Corp', $meta_repo->get( $sub_id, 'company' ) );
		$this->assertSame( 'DE', $meta_repo->get( $sub_id, 'country' ) );
	}

	/**
	 * Empty field values should not overwrite existing meta (merge policy).
	 *
	 * @return void
	 */
	public function test_empty_field_value_does_not_overwrite(): void {
		$subscriber_repo = new SubscriberRepository();
		$meta_repo       = new SubscriberMetaRepository();
		$subscriber      = $subscriber_repo->create_or_get( 'merge@example.com', 'confirmed' );
		$subscriber_repo->update_status( (int) $subscriber->id, 'confirmed' );
		$sub_id = (int) $subscriber->id;

		$meta_repo->set( $sub_id, 'company', 'Old Corp' );

		$service = new SignupService();
		$service->signup(
			array(
				'email'    => 'merge@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(
					'company' => '',
				),
			)
		);

		$this->assertSame( 'Old Corp', $meta_repo->get( $sub_id, 'company' ) );
	}
}
