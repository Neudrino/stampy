<?php
/**
 * Quiz spam guard.
 *
 * A configurable question/answer challenge (e.g. "What is 3 + 4?").
 * Deliberately NOT an image CAPTCHA — text-based quizzes are accessible
 * and OCR-proof. Admin settings define question/answer pairs; the guard
 * picks one (by index) for each form render.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\SpamGuards;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejects submissions where the quiz answer is incorrect.
 */
final class QuizGuard implements SpamGuardInterface {

	/**
	 * The quiz answer field key in the request.
	 *
	 * @var string
	 */
	public const ANSWER_KEY = 'stampy_quiz_answer';

	/**
	 * The quiz index field key in the request.
	 *
	 * @var string
	 */
	public const INDEX_KEY = 'stampy_quiz_index';

	/**
	 * Evaluate the quiz answer.
	 *
	 * @param array<mixed> $request Signup request data.
	 * @return SpamGuardResult
	 */
	public function check( array $request ): SpamGuardResult {
		$questions = self::get_questions();

		if ( count( $questions ) === 0 ) {
			return SpamGuardResult::pass();
		}

		$index = isset( $request[ self::INDEX_KEY ] ) ? (int) $request[ self::INDEX_KEY ] : -1;

		if ( ! isset( $questions[ $index ] ) ) {
			return SpamGuardResult::fail( __( 'Invalid challenge. Please try again.', 'stampy' ) );
		}

		$expected = self::normalize_answer( $questions[ $index ]['answer'] );
		$actual   = self::normalize_answer( (string) ( $request[ self::ANSWER_KEY ] ?? '' ) );

		if ( '' === $actual ) {
			return SpamGuardResult::fail( __( 'Please answer the challenge question.', 'stampy' ) );
		}

		if ( $expected !== $actual ) {
			return SpamGuardResult::fail( __( 'Incorrect challenge answer. Please try again.', 'stampy' ) );
		}

		return SpamGuardResult::pass();
	}

	/**
	 * Get the configured quiz questions.
	 *
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function get_questions(): array {
		$raw = get_option( 'stampy_quiz_questions', '' );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$lines   = preg_split( '/\r\n|\r|\n/', $raw );
		$results = array();

		if ( ! is_array( $lines ) ) {
			return array();
		}

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}

			$parts = explode( '||', $line, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}

			$question = trim( $parts[0] );
			$answer   = trim( $parts[1] );

			if ( '' !== $question && '' !== $answer ) {
				$results[] = array(
					'question' => $question,
					'answer'   => $answer,
				);
			}
		}

		return $results;
	}

	/**
	 * Check whether the quiz guard is enabled (has questions configured).
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return count( self::get_questions() ) > 0;
	}

	/**
	 * Normalize an answer for comparison (lowercase, trim, collapse spaces).
	 *
	 * @param string $answer Raw answer.
	 * @return string
	 */
	private static function normalize_answer( string $answer ): string {
		$answer = strtolower( trim( $answer ) );
		$answer = preg_replace( '/\s+/', ' ', $answer );

		return null !== $answer ? $answer : '';
	}
}
