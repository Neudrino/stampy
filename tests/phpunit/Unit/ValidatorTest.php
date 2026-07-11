<?php
/**
 * Unit tests for the field-type validator registry.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Unit;

use Stampy\Validators\AcceptanceValidator;
use Stampy\Validators\EmailValidator;
use Stampy\Validators\TextValidator;
use Stampy\Validators\ValidationResult;
use Stampy\Validators\ValidatorRegistry;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

use Brain\Monkey;

/**
 * Tests the validator registry and individual validators.
 */
class ValidatorTest extends TestCase {

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Monkey\Functions\stubs(
			array(
				'__'       => function ( $text ) {
					return $text;
				},
				'sanitize_email' => function ( $email ) {
					return filter_var( $email, FILTER_SANITIZE_EMAIL );
				},
				'is_email' => function ( $email ) {
					return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
				},
				'sanitize_text_field' => function ( $text ) {
					if ( ! is_string( $text ) ) {
						return '';
					}
					$text = strip_tags( $text );
					$text = preg_replace( '/\s+/', ' ', $text );
					return trim( $text );
				},
				'sanitize_key' => function ( $key ) {
					return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
				},
			)
		);

		Monkey\Functions\expect( 'wp_json_encode' )
			->andReturnUsing(
				function ( $data ) {
					return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_wp_json_encode
				}
			);
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * EmailValidator should accept valid emails.
	 *
	 * @return void
	 */
	public function test_email_validator_accepts_valid(): void {
		$validator = new EmailValidator();
		$result   = $validator->validate( 'Test@Example.COM' );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( 'test@example.com', $result->sanitized() );
	}

	/**
	 * EmailValidator should reject invalid emails.
	 *
	 * @return void
	 */
	public function test_email_validator_rejects_invalid(): void {
		$validator = new EmailValidator();
		$result   = $validator->validate( 'not-an-email' );

		$this->assertFalse( $result->is_valid() );
		$this->assertNotEmpty( $result->error() );
	}

	/**
	 * EmailValidator should reject empty strings.
	 *
	 * @return void
	 */
	public function test_email_validator_rejects_empty(): void {
		$validator = new EmailValidator();
		$result   = $validator->validate( '' );

		$this->assertFalse( $result->is_valid() );
	}

	/**
	 * EmailValidator type() should return 'email'.
	 *
	 * @return void
	 */
	public function test_email_validator_type(): void {
		$validator = new EmailValidator();
		$this->assertSame( 'email', $validator->type() );
	}

	/**
	 * TextValidator should sanitize text input.
	 *
	 * @return void
	 */
	public function test_text_validator_sanitizes(): void {
		$validator = new TextValidator();
		$result   = $validator->validate( '  Hello <script> World  ' );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( 'Hello World', $result->sanitized() );
	}

	/**
	 * TextValidator type() should return 'text'.
	 *
	 * @return void
	 */
	public function test_text_validator_type(): void {
		$validator = new TextValidator();
		$this->assertSame( 'text', $validator->type() );
	}

	/**
	 * AcceptanceValidator should accept true.
	 *
	 * @return void
	 */
	public function test_acceptance_validator_accepts_true(): void {
		$validator = new AcceptanceValidator();
		$result   = $validator->validate( true );

		$this->assertTrue( $result->is_valid() );
		$this->assertTrue( $result->sanitized() );
	}

	/**
	 * AcceptanceValidator should reject false.
	 *
	 * @return void
	 */
	public function test_acceptance_validator_rejects_false(): void {
		$validator = new AcceptanceValidator();
		$result   = $validator->validate( false );

		$this->assertFalse( $result->is_valid() );
	}

	/**
	 * AcceptanceValidator should accept string '1' / 'true' as truthy.
	 *
	 * @return void
	 */
	public function test_acceptance_validator_accepts_string_true(): void {
		$validator = new AcceptanceValidator();
		$result   = $validator->validate( '1' );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * AcceptanceValidator type() should return 'acceptance'.
	 *
	 * @return void
	 */
	public function test_acceptance_validator_type(): void {
		$validator = new AcceptanceValidator();
		$this->assertSame( 'acceptance', $validator->type() );
	}

	/**
	 * ValidatorRegistry should have email, text, and acceptance validators.
	 *
	 * @return void
	 */
	public function test_registry_has_default_validators(): void {
		$registry = ValidatorRegistry::instance();

		$this->assertTrue( $registry->has( 'email' ) );
		$this->assertTrue( $registry->has( 'text' ) );
		$this->assertTrue( $registry->has( 'acceptance' ) );
		$this->assertFalse( $registry->has( 'nonexistent' ) );
	}

	/**
	 * ValidatorRegistry::validate() should delegate to the right validator.
	 *
	 * @return void
	 */
	public function test_registry_validate_delegates(): void {
		$registry = ValidatorRegistry::instance();

		$result = $registry->validate( 'email', 'valid@example.com' );
		$this->assertTrue( $result->is_valid() );

		$result = $registry->validate( 'email', 'invalid' );
		$this->assertFalse( $result->is_valid() );
	}

	/**
	 * ValidatorRegistry::validate() should reject unknown types.
	 *
	 * @return void
	 */
	public function test_registry_validate_unknown_type(): void {
		$registry = ValidatorRegistry::instance();
		$result   = $registry->validate( 'unknown_type', 'value' );

		$this->assertFalse( $result->is_valid() );
	}

	/**
	 * ValidationResult::valid() factory.
	 *
	 * @return void
	 */
	public function test_validation_result_valid_factory(): void {
		$result = ValidationResult::valid( 'sanitized' );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( '', $result->error() );
		$this->assertSame( 'sanitized', $result->sanitized() );
	}

	/**
	 * ValidationResult::invalid() factory.
	 *
	 * @return void
	 */
	public function test_validation_result_invalid_factory(): void {
		$result = ValidationResult::invalid( 'bad' );

		$this->assertFalse( $result->is_valid() );
		$this->assertSame( 'bad', $result->error() );
	}
}
