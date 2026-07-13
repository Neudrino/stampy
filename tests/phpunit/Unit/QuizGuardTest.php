<?php
/**
 * Unit tests for the QuizGuard spam guard.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Unit;

use Brain\Monkey;
use Stampy\SpamGuards\QuizGuard;
use Stampy\SpamGuards\SpamGuardChain;
use Stampy\SpamGuards\SpamGuardResult;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests the QuizGuard spam guard.
 */
class QuizGuardTest extends TestCase {

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}

		Monkey\Functions\stubs(
			array(
				'__'                  => function ( $text ) {
					return $text;
				},
				'sanitize_text_field' => function ( $text ) {
					return is_string( $text ) ? trim( $text ) : '';
				},
				'wp_unslash'          => function ( $value ) {
					return $value;
				},
			)
		);
	}

	/**
	 * Set quiz questions option for testing.
	 *
	 * @param string $value The option value.
	 * @return void
	 */
	private function set_quiz_option( string $value ): void {
		Monkey\Functions\when( 'get_option' )->alias(
			function ( $key, $default = '' ) use ( $value ) {
				if ( 'stampy_quiz_questions' === $key ) {
					return $value;
				}
				return $default;
			}
		);
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Quiz guard should pass when quiz is disabled (no questions).
	 *
	 * @return void
	 */
	public function test_passes_when_quiz_disabled(): void {
		$this->set_quiz_option( '' );

		$guard  = new QuizGuard();
		$result = $guard->check( array() );

		$this->assertTrue( $result->passed() );
	}

	/**
	 * Quiz guard should fail when answer is empty.
	 *
	 * @return void
	 */
	public function test_fails_when_answer_empty(): void {
		$this->set_quiz_option( "What is 3 + 4?||7\nWhat is the opposite of hot?||cold" );

		$guard  = new QuizGuard();
		$result = $guard->check(
			array(
				QuizGuard::INDEX_KEY  => 0,
				QuizGuard::ANSWER_KEY => '',
			)
		);

		$this->assertFalse( $result->passed() );
		$this->assertNotEmpty( $result->reason() );
	}

	/**
	 * Quiz guard should fail when answer is wrong.
	 *
	 * @return void
	 */
	public function test_fails_when_answer_wrong(): void {
		$this->set_quiz_option( "What is 3 + 4?||7\nWhat is the opposite of hot?||cold" );

		$guard  = new QuizGuard();
		$result = $guard->check(
			array(
				QuizGuard::INDEX_KEY  => 0,
				QuizGuard::ANSWER_KEY => '5',
			)
		);

		$this->assertFalse( $result->passed() );
	}

	/**
	 * Quiz guard should pass when answer is correct.
	 *
	 * @return void
	 */
	public function test_passes_when_answer_correct(): void {
		$this->set_quiz_option( "What is 3 + 4?||7\nWhat is the opposite of hot?||cold" );

		$guard  = new QuizGuard();
		$result = $guard->check(
			array(
				QuizGuard::INDEX_KEY  => 0,
				QuizGuard::ANSWER_KEY => '7',
			)
		);

		$this->assertTrue( $result->passed() );
	}

	/**
	 * Quiz guard should be case-insensitive.
	 *
	 * @return void
	 */
	public function test_case_insensitive_answer(): void {
		$this->set_quiz_option( "What is 3 + 4?||7\nWhat is the opposite of hot?||cold" );

		$guard  = new QuizGuard();
		$result = $guard->check(
			array(
				QuizGuard::INDEX_KEY  => 1,
				QuizGuard::ANSWER_KEY => 'COLD',
			)
		);

		$this->assertTrue( $result->passed() );
	}

	/**
	 * Quiz guard should handle whitespace in answer.
	 *
	 * @return void
	 */
	public function test_answer_whitespace_normalized(): void {
		$this->set_quiz_option( "What is 3 + 4?||7\nWhat is the opposite of hot?||cold" );

		$guard  = new QuizGuard();
		$result = $guard->check(
			array(
				QuizGuard::INDEX_KEY  => 0,
				QuizGuard::ANSWER_KEY => '  7  ',
			)
		);

		$this->assertTrue( $result->passed() );
	}

	/**
	 * Quiz guard should fail when index is out of bounds.
	 *
	 * @return void
	 */
	public function test_fails_when_index_out_of_bounds(): void {
		$this->set_quiz_option( "What is 3 + 4?||7\nWhat is the opposite of hot?||cold" );

		$guard  = new QuizGuard();
		$result = $guard->check(
			array(
				QuizGuard::INDEX_KEY  => 99,
				QuizGuard::ANSWER_KEY => '7',
			)
		);

		$this->assertFalse( $result->passed() );
	}

	/**
	 * Quiz guard should fail when index is missing.
	 *
	 * @return void
	 */
	public function test_fails_when_index_missing(): void {
		$this->set_quiz_option( "What is 3 + 4?||7\nWhat is the opposite of hot?||cold" );

		$guard  = new QuizGuard();
		$result = $guard->check(
			array(
				QuizGuard::ANSWER_KEY => '7',
			)
		);

		$this->assertFalse( $result->passed() );
	}

	/**
	 * is_enabled should return true when questions are configured.
	 *
	 * @return void
	 */
	public function test_is_enabled_returns_true_when_configured(): void {
		$this->set_quiz_option( "What is 3 + 4?||7" );

		$this->assertTrue( QuizGuard::is_enabled() );
	}

	/**
	 * is_enabled should return false when no questions configured.
	 *
	 * @return void
	 */
	public function test_is_enabled_returns_false_when_empty(): void {
		$this->set_quiz_option( '' );

		$this->assertFalse( QuizGuard::is_enabled() );
	}

	/**
	 * Chain should include QuizGuard when questions are configured.
	 *
	 * @return void
	 */
	public function test_chain_includes_quiz_when_enabled(): void {
		$this->set_quiz_option( "What is 3 + 4?||7" );

		// The default_chain should include the quiz guard since get_option
		// returns questions in this test class.
		$chain = SpamGuardChain::default_chain();

		// With quiz enabled, a wrong answer should fail.
		$result = $chain->check(
			array(
				'website_check'       => '',
				QuizGuard::INDEX_KEY  => 0,
				QuizGuard::ANSWER_KEY => 'wrong',
			)
		);

		$this->assertFalse( $result->passed() );
	}

	/**
	 * SpamGuardResult::pass() and ::fail() factories work.
	 *
	 * @return void
	 */
	public function test_result_factories(): void {
		$pass = SpamGuardResult::pass();
		$fail = SpamGuardResult::fail( 'blocked' );

		$this->assertTrue( $pass->passed() );
		$this->assertFalse( $fail->passed() );
		$this->assertSame( 'blocked', $fail->reason() );
	}
}
