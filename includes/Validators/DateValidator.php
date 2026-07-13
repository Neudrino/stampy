<?php
/**
 * Date field validator.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Validators;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and sanitizes date field values (YYYY-MM-DD format).
 */
final class DateValidator implements FieldValidatorInterface {

	/**
	 * {@inheritdoc}
	 */
	public function type(): string {
		return 'date';
	}

	/**
	 * Validate a date value.
	 *
	 * Accepts dates in YYYY-MM-DD format. Returns the sanitized date
	 * string, or empty string for empty input.
	 *
	 * @param mixed $value Raw value.
	 * @return ValidationResult
	 */
	public function validate( $value ): ValidationResult {
		if ( ! is_string( $value ) ) {
			return ValidationResult::invalid( __( 'Value must be a string.', 'stampy' ) );
		}

		$trimmed = trim( $value );

		if ( '' === $trimmed ) {
			return ValidationResult::valid( '' );
		}

		$d = \DateTime::createFromFormat( 'Y-m-d', $trimmed );

		if ( false === $d || $d->format( 'Y-m-d' ) !== $trimmed ) {
			return ValidationResult::invalid( __( 'Please enter a valid date (YYYY-MM-DD).', 'stampy' ) );
		}

		return ValidationResult::valid( $trimmed );
	}
}
