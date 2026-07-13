<?php
/**
 * Select field validator.
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
 * Validates select field values against an allowed-options list.
 */
final class SelectValidator implements FieldValidatorInterface {

	/**
	 * {@inheritdoc}
	 */
	public function type(): string {
		return 'select';
	}

	/**
	 * Validate a select value.
	 *
	 * The value must be a string. Empty strings are valid (field not
	 * required). Non-empty strings are accepted as-is (the field
	 * definition's `options` constraint is enforced at the field
	 * configuration level, not in the validator — the validator only
	 * sanitizes the text).
	 *
	 * @param mixed $value Raw value.
	 * @return ValidationResult
	 */
	public function validate( $value ): ValidationResult {
		if ( ! is_string( $value ) ) {
			return ValidationResult::invalid( __( 'Value must be a string.', 'stampy' ) );
		}

		$sanitized = sanitize_text_field( $value );

		return ValidationResult::valid( $sanitized );
	}
}
