<?php
/**
 * Integration tests for Phase 16: Submission Log.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Privacy;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Repositories\SubmissionLogRepository;
use Stampy\SignupService;
use Stampy\SubmissionLogSettings;
use WP_UnitTestCase;

/**
 * Tests Phase 16: submission logging, GDPR export/erase, and cascade delete.
 */
class PhaseSixteenTest extends WP_UnitTestCase {

	/**
	 * Submission log repository.
	 *
	 * @var SubmissionLogRepository
	 */
	private $log_repo;

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
	 * List ID created for tests.
	 *
	 * @var int
	 */
	private $list_id;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		Installer::install();

		$this->log_repo    = new SubmissionLogRepository();
		$this->subscribers = new SubscriberRepository();
		$this->lists       = new ListRepository();

		$wpdb      = $GLOBALS['wpdb'];
		$log_table = $wpdb->prefix . 'stampy_submission_log';
		$wpdb->query( "DELETE FROM $log_table" );

		$found = $this->lists->find_by_slug( 'phase16-list' );
		if ( $found ) {
			$this->list_id = (int) $found->id;
		} else {
			$this->list_id = $this->lists->create( 'Phase 16 List', 'phase16-list' );
		}

		update_option( SubmissionLogSettings::ENABLED_OPTION, '1', true );

		unset( $GLOBALS['phpmailer_mock_sent'] );
	}

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$wpdb      = $GLOBALS['wpdb'];
		$log_table = $wpdb->prefix . 'stampy_submission_log';
		$wpdb->query( "DELETE FROM $log_table" );

		delete_option( SubmissionLogSettings::ENABLED_OPTION );

		unset( $GLOBALS['phpmailer_mock_sent'] );

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
	 * A pending signup should create a submission log entry with status 'pending'.
	 *
	 * @return void
	 */
	public function test_pending_signup_logs_entry(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'alice@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(
					'first_name' => 'Alice',
				),
			)
		);

		$logs = $this->log_repo->find_by_email( 'alice@stampy.local' );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'pending', $logs[0]->status );
	}

	/**
	 * A re-signup by an already-confirmed subscriber should log 'confirmed'.
	 *
	 * @return void
	 */
	public function test_confirmed_resignup_logs_entry(): void {
		$service = new SignupService();

		// First signup → pending.
		$service->signup(
			array(
				'email'    => 'bob@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$token = $this->extract_token_from_email();
		$service->confirm( $token );

		// Second signup (subscriber already confirmed) → 'confirmed' log.
		unset( $GLOBALS['phpmailer_mock_sent'] );
		$service->signup(
			array(
				'email'    => 'bob@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$logs = $this->log_repo->find_by_email( 'bob@stampy.local' );
		$this->assertCount( 2, $logs );

		$statuses = array_column( $logs, 'status' );
		$this->assertContains( 'pending', $statuses );
		$this->assertContains( 'confirmed', $statuses );
	}

	/**
	 * Spam-rejected submissions should NOT be logged.
	 *
	 * @return void
	 */
	public function test_spam_rejected_not_logged(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'         => 'spam@stampy.local',
				'consent'       => true,
				'list_ids'      => array( $this->list_id ),
				'fields'        => array(),
				'website_check' => 'filled',
			)
		);

		$logs = $this->log_repo->find_by_email( 'spam@stampy.local' );
		$this->assertCount( 0, $logs );
	}

	/**
	 * Validation-failed submissions should NOT be logged.
	 *
	 * @return void
	 */
	public function test_validation_failed_not_logged(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'invalid',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$logs = $this->log_repo->find_by_email( 'invalid' );
		$this->assertCount( 0, $logs );
	}

	/**
	 * Log entries should capture form data as JSON.
	 *
	 * @return void
	 */
	public function test_log_captures_form_data(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'carol@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(
					'first_name' => 'Carol',
					'last_name'  => 'Test',
				),
			)
		);

		$logs = $this->log_repo->find_by_email( 'carol@stampy.local' );
		$this->assertCount( 1, $logs );

		$form_data = json_decode( $logs[0]->form_data, true );
		$this->assertIsArray( $form_data );
		$this->assertSame( 'Carol', $form_data['first_name'] );
		$this->assertSame( 'Test', $form_data['last_name'] );
	}

	/**
	 * Log entries should capture list IDs as CSV.
	 *
	 * @return void
	 */
	public function test_log_captures_list_ids(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'dave@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$logs = $this->log_repo->find_by_email( 'dave@stampy.local' );
		$this->assertCount( 1, $logs );
		$this->assertSame( (string) $this->list_id, $logs[0]->list_ids );
	}

	/**
	 * Log entries should capture consent text.
	 *
	 * @return void
	 */
	public function test_log_captures_consent_text(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'eve@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$logs = $this->log_repo->find_by_email( 'eve@stampy.local' );
		$this->assertCount( 1, $logs );
		$this->assertNotEmpty( $logs[0]->consent_text );
	}

	/**
	 * Log entries should have subscriber_id set.
	 *
	 * @return void
	 */
	public function test_log_has_subscriber_id(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'sid@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$subscriber = $this->subscribers->find_by_email( 'sid@stampy.local' );
		$this->assertNotNull( $subscriber );

		$logs = $this->log_repo->find_by_email( 'sid@stampy.local' );
		$this->assertCount( 1, $logs );
		$this->assertSame( (int) $subscriber->id, (int) $logs[0]->subscriber_id );
	}

	/**
	 * When logging is disabled, no entries should be created.
	 *
	 * @return void
	 */
	public function test_no_logging_when_disabled(): void {
		update_option( SubmissionLogSettings::ENABLED_OPTION, '0', true );

		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'frank@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$logs = $this->log_repo->find_by_email( 'frank@stampy.local' );
		$this->assertCount( 0, $logs );
	}

	/**
	 * GDPR privacy exporter should include submission log entries.
	 *
	 * @return void
	 */
	public function test_privacy_export_includes_submission_log(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'export@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$export = Privacy::export_data( 'export@stampy.local' );

		$this->assertTrue( $export['done'] );
		$group_ids = array_column( $export['data'], 'group_id' );
		$this->assertContains( 'stampy-submission-log', $group_ids );
	}

	/**
	 * GDPR privacy eraser should delete submission log entries.
	 *
	 * @return void
	 */
	public function test_privacy_erase_deletes_submission_log(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'erase@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$logs = $this->log_repo->find_by_email( 'erase@stampy.local' );
		$this->assertGreaterThan( 0, count( $logs ) );

		Privacy::erase_data( 'erase@stampy.local' );

		$logs_after = $this->log_repo->find_by_email( 'erase@stampy.local' );
		$this->assertCount( 0, $logs_after );
	}

	/**
	 * Deleting a subscriber should cascade-delete their log entries.
	 *
	 * @return void
	 */
	public function test_subscriber_delete_cascades_log(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'cascade@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$subscriber = $this->subscribers->find_by_email( 'cascade@stampy.local' );
		$this->assertNotNull( $subscriber );

		$logs = $this->log_repo->find_by_subscriber( (int) $subscriber->id );
		$this->assertGreaterThan( 0, count( $logs ) );

		$this->subscribers->delete( (int) $subscriber->id );

		$logs_after = $this->log_repo->find_by_subscriber( (int) $subscriber->id );
		$this->assertCount( 0, $logs_after );
	}

	/**
	 * Find by subscriber ID should return entries.
	 *
	 * @return void
	 */
	public function test_find_by_subscriber(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'findsub@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$subscriber = $this->subscribers->find_by_email( 'findsub@stampy.local' );
		$logs       = $this->log_repo->find_by_subscriber( (int) $subscriber->id );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'findsub@stampy.local', $logs[0]->email );
	}

	/**
	 * Find by ID should return the entry.
	 *
	 * @return void
	 */
	public function test_find_by_id(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'findid@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$subscriber = $this->subscribers->find_by_email( 'findid@stampy.local' );
		$logs       = $this->log_repo->find_by_subscriber( (int) $subscriber->id );
		$this->assertGreaterThan( 0, count( $logs ) );

		$entry = $this->log_repo->find( (int) $logs[0]->id );
		$this->assertNotNull( $entry );
		$this->assertSame( 'findid@stampy.local', $entry->email );
		$this->assertSame( 'pending', $entry->status );
	}

	/**
	 * Paginated get_all should return entries in descending order.
	 *
	 * @return void
	 */
	public function test_get_all_returns_entries(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'first@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);
		$service->signup(
			array(
				'email'    => 'second@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$entries = $this->log_repo->get_all( 10, 1 );
		$this->assertGreaterThanOrEqual( 2, count( $entries ) );

		$emails = array_column( $entries, 'email' );
		$this->assertContains( 'first@stampy.local', $emails );
		$this->assertContains( 'second@stampy.local', $emails );
	}

	/**
	 * Search by email should filter results.
	 *
	 * @return void
	 */
	public function test_get_all_with_search(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'searchable@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);
		$service->signup(
			array(
				'email'    => 'other@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$results = $this->log_repo->get_all( 10, 1, 'searchable' );
		$this->assertGreaterThanOrEqual( 1, count( $results ) );
		foreach ( $results as $entry ) {
			$this->assertStringContainsString( 'searchable', $entry->email );
		}
	}

	/**
	 * Count with search should return the filtered count.
	 *
	 * @return void
	 */
	public function test_count_with_search(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'countme@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$this->assertGreaterThanOrEqual( 1, $this->log_repo->count( 'countme' ) );
		$this->assertSame( 0, $this->log_repo->count( 'nonexistent' ) );
	}

	/**
	 * Delete by subscriber should remove entries.
	 *
	 * @return void
	 */
	public function test_delete_by_subscriber(): void {
		$service = new SignupService();

		$service->signup(
			array(
				'email'    => 'delsub@stampy.local',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
				'fields'   => array(),
			)
		);

		$subscriber = $this->subscribers->find_by_email( 'delsub@stampy.local' );
		$this->assertGreaterThan( 0, $this->log_repo->count( 'delsub' ) );

		$deleted = $this->log_repo->delete_by_subscriber( (int) $subscriber->id );
		$this->assertGreaterThan( 0, $deleted );
		$this->assertSame( 0, $this->log_repo->count( 'delsub' ) );
	}
}
