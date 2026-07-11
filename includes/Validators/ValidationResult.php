<?php
/**
 * Validation result value object.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Validators;

/**
 * Immutable result returned by a FieldValidatorInterface::validate().
 */
final class ValidationResult {

	/**
	 * Whether the value is valid.
	 *
	 * @var bool
	 */
	private bool $valid;

	/**
	 * Human-readable error message (empty when valid).
	 *
	 * @var string
	 */
	private string $error;

	/**
	 * The sanitized value (may differ from input).
	 *
	 * @var mixed
	 */
	private $sanitized;

	/**
	 * Constructor.
	 *
	 * @param bool   $valid     True if valid.
	 * @param string $error     Error message (empty when valid).
	 * @param mixed  $sanitized Sanitized value.
	 */
	public function __construct( bool $valid, string $error = '', $sanitized = null ) {
		$this->valid     = $valid;
		$this->error     = $error;
		$this->sanitized = $sanitized;
	}

	/**
	 * Create a "valid" result with a sanitized value.
	 *
	 * @param mixed $sanitized Sanitized value.
	 * @return self
	 */
	public static function valid( $sanitized ): self {
		return new self( true, '', $sanitized );
	}

	/**
	 * Create an "invalid" result with an error message.
	 *
	 * @param string $error Error message.
	 * @return self
	 */
	public static function invalid( string $error ): self {
		return new self( false, $error, null );
	}

	/**
	 * Whether the value is valid.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return $this->valid;
	}

	/**
	 * The error message (empty when valid).
	 *
	 * @return string
	 */
	public function error(): string {
		return $this->error;
	}

	/**
	 * The sanitized value.
	 *
	 * @return mixed
	 */
	public function sanitized() {
		return $this->sanitized;
	}
}
