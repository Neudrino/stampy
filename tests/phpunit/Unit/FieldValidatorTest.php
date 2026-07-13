<?php
/**
 * Unit tests for the new field validators (textarea, number, date, select, checkbox).
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Unit;

use Brain\Monkey;
use Stampy\Validators\CheckboxValidator;
use Stampy\Validators\DateValidator;
use Stampy\Validators\NumberValidator;
use Stampy\Validators\SelectValidator;
use Stampy\Validators\TextareaValidator;
use Stampy\Validators\ValidatorRegistry;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests the new field-type validators.
 */
class FieldValidatorTest extends TestCase {

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
				'__'                       => function ( $text ) {
					return $text;
				},
				'sanitize_text_field'      => function ( $text ) {
					return is_string( $text ) ? trim( $text ) : '';
				},
				'sanitize_textarea_field'  => function ( $text ) {
					return is_string( $text ) ? trim( $text ) : '';
				},
			)
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

	// --- TextareaValidator ---

	/**
	 * Textarea validator should sanitize textarea input.
	 *
	 * @return void
	 */
	public function test_textarea_validates_string(): void {
		$validator = new TextareaValidator();
		$result    = $validator->validate( '  hello  ' );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( 'hello', $result->sanitized() );
	}

	/**
	 * Textarea validator should reject non-strings.
	 *
	 * @return void
	 */
	public function test_textarea_rejects_non_string(): void {
		$validator = new TextareaValidator();
		$result    = $validator->validate( 42 );

		$this->assertFalse( $result->is_valid() );
	}

	// --- NumberValidator ---

	/**
	 * Number validator should accept integers.
	 *
	 * @return void
	 */
	public function test_number_accepts_int(): void {
		$validator = new NumberValidator();
		$result    = $validator->validate( 42 );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( '42', $result->sanitized() );
	}

	/**
	 * Number validator should accept floats.
	 *
	 * @return void
	 */
	public function test_number_accepts_float(): void {
		$validator = new NumberValidator();
		$result    = $validator->validate( 3.14 );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Number validator should accept numeric strings.
	 *
	 * @return void
	 */
	public function test_number_accepts_numeric_string(): void {
		$validator = new NumberValidator();
		$result    = $validator->validate( ' 123 ' );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Number validator should reject non-numeric strings.
	 *
	 * @return void
	 */
	public function test_number_rejects_non_numeric(): void {
		$validator = new NumberValidator();
		$result    = $validator->validate( 'abc' );

		$this->assertFalse( $result->is_valid() );
	}

	/**
	 * Number validator should accept empty strings.
	 *
	 * @return void
	 */
	public function test_number_accepts_empty(): void {
		$validator = new NumberValidator();
		$result    = $validator->validate( '' );

		$this->assertTrue( $result->is_valid() );
	}

	// --- DateValidator ---

	/**
	 * Date validator should accept valid dates.
	 *
	 * @return void
	 */
	public function test_date_accepts_valid(): void {
		$validator = new DateValidator();
		$result    = $validator->validate( '2024-01-15' );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( '2024-01-15', $result->sanitized() );
	}

	/**
	 * Date validator should reject invalid dates.
	 *
	 * @return void
	 */
	public function test_date_rejects_invalid(): void {
		$validator = new DateValidator();
		$result    = $validator->validate( '15-01-2024' );

		$this->assertFalse( $result->is_valid() );
	}

	/**
	 * Date validator should reject impossible dates.
	 *
	 * @return void
	 */
	public function test_date_rejects_impossible(): void {
		$validator = new DateValidator();
		$result    = $validator->validate( '2024-02-30' );

		$this->assertFalse( $result->is_valid() );
	}

	/**
	 * Date validator should accept empty strings.
	 *
	 * @return void
	 */
	public function test_date_accepts_empty(): void {
		$validator = new DateValidator();
		$result    = $validator->validate( '' );

		$this->assertTrue( $result->is_valid() );
	}

	// --- SelectValidator ---

	/**
	 * Select validator should sanitize text.
	 *
	 * @return void
	 */
	public function test_select_validates_string(): void {
		$validator = new SelectValidator();
		$result    = $validator->validate( 'option1' );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( 'option1', $result->sanitized() );
	}

	/**
	 * Select validator should reject non-strings.
	 *
	 * @return void
	 */
	public function test_select_rejects_non_string(): void {
		$validator = new SelectValidator();
		$result    = $validator->validate( array( 'a' ) );

		$this->assertFalse( $result->is_valid() );
	}

	// --- CheckboxValidator ---

	/**
	 * Checkbox validator should accept boolean true.
	 *
	 * @return void
	 */
	public function test_checkbox_accepts_true(): void {
		$validator = new CheckboxValidator();
		$result    = $validator->validate( true );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( '1', $result->sanitized() );
	}

	/**
	 * Checkbox validator should accept boolean false.
	 *
	 * @return void
	 */
	public function test_checkbox_accepts_false(): void {
		$validator = new CheckboxValidator();
		$result    = $validator->validate( false );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( '0', $result->sanitized() );
	}

	/**
	 * Checkbox validator should accept "1" string.
	 *
	 * @return void
	 */
	public function test_checkbox_accepts_1_string(): void {
		$validator = new CheckboxValidator();
		$result    = $validator->validate( '1' );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( '1', $result->sanitized() );
	}

	/**
	 * Checkbox validator should accept "on" string.
	 *
	 * @return void
	 */
	public function test_checkbox_accepts_on(): void {
		$validator = new CheckboxValidator();
		$result    = $validator->validate( 'on' );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( '1', $result->sanitized() );
	}

	/**
	 * Checkbox validator should accept empty string as unchecked.
	 *
	 * @return void
	 */
	public function test_checkbox_accepts_empty(): void {
		$validator = new CheckboxValidator();
		$result    = $validator->validate( '' );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( '0', $result->sanitized() );
	}

	/**
	 * Checkbox validator should reject invalid strings.
	 *
	 * @return void
	 */
	public function test_checkbox_rejects_invalid(): void {
		$validator = new CheckboxValidator();
		$result    = $validator->validate( 'maybe' );

		$this->assertFalse( $result->is_valid() );
	}

	// --- ValidatorRegistry ---

	/**
	 * Registry should have all new validators registered.
	 *
	 * @return void
	 */
	public function test_registry_has_all_validators(): void {
		$registry = ValidatorRegistry::instance();

		$this->assertTrue( $registry->has( 'textarea' ) );
		$this->assertTrue( $registry->has( 'number' ) );
		$this->assertTrue( $registry->has( 'date' ) );
		$this->assertTrue( $registry->has( 'select' ) );
		$this->assertTrue( $registry->has( 'checkbox' ) );
		$this->assertTrue( $registry->has( 'email' ) );
		$this->assertTrue( $registry->has( 'text' ) );
		$this->assertTrue( $registry->has( 'acceptance' ) );
	}
}
