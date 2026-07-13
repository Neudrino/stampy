<?php
/**
 * Checkbox field validator.
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
 * Validates checkbox field values.
 */
final class CheckboxValidator implements FieldValidatorInterface {

	/**
	 * {@inheritdoc}
	 */
	public function type(): string {
		return 'checkbox';
	}

	/**
	 * Validate a checkbox value.
	 *
	 * Accepts booleans, "1"/"0" strings, "true"/"false" strings,
	 * and "on"/"off" strings. Returns "1" or "0" as the sanitized value.
	 *
	 * @param mixed $value Raw value.
	 * @return ValidationResult
	 */
	public function validate( $value ): ValidationResult {
		if ( is_bool( $value ) ) {
			return ValidationResult::valid( $value ? '1' : '0' );
		}

		if ( ! is_string( $value ) ) {
			return ValidationResult::invalid( __( 'Value must be a string or boolean.', 'stampy' ) );
		}

		$lower = strtolower( trim( $value ) );

		if ( in_array( $lower, array( '1', 'true', 'on', 'yes' ), true ) ) {
			return ValidationResult::valid( '1' );
		}

		if ( in_array( $lower, array( '0', 'false', 'off', 'no', '' ), true ) ) {
			return ValidationResult::valid( '0' );
		}

		return ValidationResult::invalid( __( 'Invalid checkbox value.', 'stampy' ) );
	}
}
