<?php
/**
 * Email field validator.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Validators;

/**
 * Validates and sanitizes email field values.
 */
final class EmailValidator implements FieldValidatorInterface {

	/**
	 * {@inheritdoc}
	 */
	public function type(): string {
		return 'email';
	}

	/**
	 * Validate an email address.
	 *
	 * @param mixed $value Raw value.
	 * @return ValidationResult
	 */
	public function validate( $value ): ValidationResult {
		if ( ! is_string( $value ) ) {
			return ValidationResult::invalid( __( 'Email must be a string.', 'stampy' ) );
		}

		$email = sanitize_email( $value );

		if ( '' === $email ) {
			return ValidationResult::invalid( __( 'Email is required.', 'stampy' ) );
		}

		if ( ! is_email( $email ) ) {
			return ValidationResult::invalid( __( 'Please enter a valid email address.', 'stampy' ) );
		}

		return ValidationResult::valid( strtolower( trim( $email ) ) );
	}
}
