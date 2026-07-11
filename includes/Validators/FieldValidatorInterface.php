<?php
/**
 * Field validator interface.
 *
 * Defines the contract for pluggable field-type validators. v1 ships
 * `email`, `text`, and `acceptance`; §10 R2 adds the rest.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Validators;

/**
 * Validates a single field value in the signup pipeline.
 */
interface FieldValidatorInterface {

	/**
	 * The field type this validator handles (e.g. 'email', 'text', 'acceptance').
	 *
	 * @return string
	 */
	public function type(): string;

	/**
	 * Validate a field value.
	 *
	 * @param mixed $value The raw value from the signup request.
	 * @return ValidationResult
	 */
	public function validate( $value ): ValidationResult;
}
