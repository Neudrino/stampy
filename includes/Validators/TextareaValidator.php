<?php
/**
 * Textarea field validator.
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
 * Validates and sanitizes textarea field values.
 */
final class TextareaValidator implements FieldValidatorInterface {

	/**
	 * {@inheritdoc}
	 */
	public function type(): string {
		return 'textarea';
	}

	/**
	 * Validate a textarea value.
	 *
	 * @param mixed $value Raw value.
	 * @return ValidationResult
	 */
	public function validate( $value ): ValidationResult {
		if ( ! is_string( $value ) ) {
			return ValidationResult::invalid( __( 'Value must be a string.', 'stampy' ) );
		}

		$sanitized = sanitize_textarea_field( $value );

		return ValidationResult::valid( $sanitized );
	}
}
