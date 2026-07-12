<?php
/**
 * Field-type validator registry.
 *
 * Maps field types to their validator implementations. Extensible:
 * Phase 13 adds more validators; Phase 14 form builder adds the rest.
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
 * Registry of field-type validators.
 */
final class ValidatorRegistry {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Map of field type => validator.
	 *
	 * @var array<string, FieldValidatorInterface>
	 */
	private array $validators = array();

	/**
	 * Private constructor — use ::instance().
	 */
	private function __construct() {
		$this->register( new EmailValidator() );
		$this->register( new TextValidator() );
		$this->register( new AcceptanceValidator() );
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register or replace a validator.
	 *
	 * @param FieldValidatorInterface $validator Validator to register.
	 * @return void
	 */
	public function register( FieldValidatorInterface $validator ): void {
		$this->validators[ $validator->type() ] = $validator;
	}

	/**
	 * Get the validator for a field type.
	 *
	 * @param string $type Field type.
	 * @return FieldValidatorInterface|null
	 */
	public function get( string $type ): ?FieldValidatorInterface {
		return $this->validators[ $type ] ?? null;
	}

	/**
	 * Check if a validator is registered for the given type.
	 *
	 * @param string $type Field type.
	 * @return bool
	 */
	public function has( string $type ): bool {
		return isset( $this->validators[ $type ] );
	}

	/**
	 * Validate a value against a field type.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Raw value.
	 * @return ValidationResult
	 */
	public function validate( string $type, $value ): ValidationResult {
		$validator = $this->get( $type );

		if ( null === $validator ) {
			return ValidationResult::invalid(
				sprintf(
					/* translators: %s: field type */
					__( 'Unknown field type: %s', 'stampy' ),
					$type
				)
			);
		}

		return $validator->validate( $value );
	}
}
