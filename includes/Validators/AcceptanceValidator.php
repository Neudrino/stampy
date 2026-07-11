<?php
/**
 * Acceptance (consent checkbox) validator.
 *
 * The consent checkbox is always required at signup. A submission
 * without it is rejected. The `acceptance` validator enforces this —
 * it only passes for truthy boolean values.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Validators;

/**
 * Validates consent checkbox (acceptance) values.
 */
final class AcceptanceValidator implements FieldValidatorInterface {

	/**
	 * {@inheritdoc}
	 */
	public function type(): string {
		return 'acceptance';
	}

	/**
	 * Validate consent. Must be truthy.
	 *
	 * @param mixed $value Raw value.
	 * @return ValidationResult
	 */
	public function validate( $value ): ValidationResult {
		$accepted = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

		if ( true !== $accepted ) {
			return ValidationResult::invalid( __( 'You must accept the consent terms to sign up.', 'stampy' ) );
		}

		return ValidationResult::valid( true );
	}
}
