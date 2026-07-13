<?php
/**
 * Number field validator.
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
 * Validates and sanitizes number field values.
 */
final class NumberValidator implements FieldValidatorInterface {

	/**
	 * {@inheritdoc}
	 */
	public function type(): string {
		return 'number';
	}

	/**
	 * Validate a number value.
	 *
	 * Accepts integers and floats, as well as numeric strings.
	 *
	 * @param mixed $value Raw value.
	 * @return ValidationResult
	 */
	public function validate( $value ): ValidationResult {
		if ( is_int( $value ) || is_float( $value ) ) {
			return ValidationResult::valid( (string) $value );
		}

		if ( ! is_string( $value ) ) {
			return ValidationResult::invalid( __( 'Value must be a number.', 'stampy' ) );
		}

		$trimmed = trim( $value );

		if ( '' === $trimmed ) {
			return ValidationResult::valid( '' );
		}

		if ( ! is_numeric( $trimmed ) ) {
			return ValidationResult::invalid( __( 'Please enter a valid number.', 'stampy' ) );
		}

		return ValidationResult::valid( $trimmed );
	}
}
