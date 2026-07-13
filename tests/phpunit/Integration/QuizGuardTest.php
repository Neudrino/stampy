<?php
/**
 * Integration tests for the quiz spam guard.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\SpamGuards\QuizGuard;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Tests the quiz guard via the signup REST endpoint.
 */
class QuizGuardTest extends WP_UnitTestCase {

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
		$existing_list = $list_repo->find_by_slug( 'newsletter' );
		if ( null === $existing_list ) {
			$this->list_id = $list_repo->create( 'Newsletter', 'newsletter', 'Test list' );
		} else {
			$this->list_id = (int) $existing_list->id;
		}

		update_option( 'stampy_quiz_questions', "What is 3 + 4?||7\nWhat is the opposite of hot?||cold" );

		unset( $GLOBALS['phpmailer_mock_sent'] );
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		unset( $GLOBALS['phpmailer_mock_sent'] );
		delete_option( 'stampy_quiz_questions' );

		parent::tearDown();

		global $wpdb;
		$table      = \Stampy\Schema::table( 'subscribers', $wpdb );
		$meta_table = \Stampy\Schema::table( 'subscriber_meta', $wpdb );
		$emails     = array( 'quiz-test@example.com', 'quiz-wrong@example.com' );
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
	 * Signup with correct quiz answer should succeed.
	 *
	 * @return void
	 */
	public function test_signup_with_correct_quiz_answer_succeeds(): void {
		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'              => 'quiz-test@example.com',
				'consent'            => true,
				'list_ids'           => array( $this->list_id ),
				'stampy_quiz_answer' => '7',
				'stampy_quiz_index'  => 0,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Signup with wrong quiz answer should fail.
	 *
	 * @return void
	 */
	public function test_signup_with_wrong_quiz_answer_fails(): void {
		$subscriber_repo = new SubscriberRepository();
		$initial_count   = $subscriber_repo->count();

		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'              => 'quiz-wrong@example.com',
				'consent'            => true,
				'list_ids'           => array( $this->list_id ),
				'stampy_quiz_answer' => '42',
				'stampy_quiz_index'  => 0,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );

		// Subscriber should not be created.
		$this->assertSame( $initial_count, $subscriber_repo->count() );
	}

	/**
	 * Signup with empty quiz answer should fail.
	 *
	 * @return void
	 */
	public function test_signup_with_empty_quiz_answer_fails(): void {
		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'              => 'quiz-wrong@example.com',
				'consent'            => true,
				'list_ids'           => array( $this->list_id ),
				'stampy_quiz_answer' => '',
				'stampy_quiz_index'  => 0,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
	}

	/**
	 * Signup with invalid quiz index should fail.
	 *
	 * @return void
	 */
	public function test_signup_with_invalid_quiz_index_fails(): void {
		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'              => 'quiz-wrong@example.com',
				'consent'            => true,
				'list_ids'           => array( $this->list_id ),
				'stampy_quiz_answer' => '7',
				'stampy_quiz_index'  => 99,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['success'] );
	}

	/**
	 * Quiz answer should be case-insensitive.
	 *
	 * @return void
	 */
	public function test_quiz_answer_case_insensitive(): void {
		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'              => 'quiz-test@example.com',
				'consent'            => true,
				'list_ids'           => array( $this->list_id ),
				'stampy_quiz_answer' => 'COLD',
				'stampy_quiz_index'  => 1,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Quiz guard is disabled when no questions configured.
	 *
	 * @return void
	 */
	public function test_signup_succeeds_when_quiz_disabled(): void {
		delete_option( 'stampy_quiz_questions' );

		$request = new WP_REST_Request( 'POST', '/stampy/v1/signup' );
		$request->set_body_params(
			array(
				'email'    => 'quiz-test@example.com',
				'consent'  => true,
				'list_ids' => array( $this->list_id ),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
	}

	/**
	 * Settings save handler stores quiz questions.
	 *
	 * @return void
	 */
	public function test_settings_save_quiz_questions(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$_POST = array(
			'action'            => 'stampy_save_smtp_settings',
			'stampy_smtp_nonce' => wp_create_nonce( 'stampy_save_smtp_settings' ),
			'quiz_questions'    => 'What is 2 + 2?||4',
		);
		$_REQUEST = $_POST;

		// Capture the redirect.
		$redirected = false;
		add_filter(
			'wp_redirect',
			function () use ( &$redirected ) {
				$redirected = true;
				throw new \RuntimeException( 'redirect' );
			},
			1
		);

		try {
			\Stampy\Admin\SettingsPage::handle_save_settings();
		} catch ( \RuntimeException $e ) { // phpcs:ignore
			// Expected.
		}

		$this->assertTrue( $redirected );
		$this->assertSame( 'What is 2 + 2?||4', get_option( 'stampy_quiz_questions' ) );

		unset( $_POST, $_REQUEST );
		wp_set_current_user( 0 );
	}
}
