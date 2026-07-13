<?php
/**
 * Integration tests for SignupBlock server-side rendering.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Repositories\FieldRepository;
use Stampy\Repositories\ListRepository;
use Stampy\SignupBlock;
use WP_UnitTestCase;

/**
 * Tests that SignupBlock::render() outputs the correct HTML for
 * enabled_fields, field types, and data-stampy-field attributes.
 */
class SignupBlockRenderTest extends WP_UnitTestCase {

	/**
	 * List ID for testing.
	 *
	 * @var int
	 */
	private int $list_id;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		Installer::install();

		$list_repo     = new ListRepository();
		$this->list_id = $list_repo->create( 'Newsletter', 'newsletter', 'Test list' );
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Render with no list_ids returns empty string.
	 *
	 * @return void
	 */
	public function test_render_without_list_ids_returns_empty(): void {
		$html = SignupBlock::render( array() );

		$this->assertSame( '', $html );
	}

	/**
	 * Render with list_ids produces a form with data-list-ids.
	 *
	 * @return void
	 */
	public function test_render_with_list_ids_produces_form(): void {
		$html = SignupBlock::render(
			array(
				'list_ids' => array( $this->list_id ),
			)
		);

		$this->assertStringContainsString( 'stampy-signup-form', $html );
		$this->assertStringContainsString( 'data-list-ids', $html );
		$this->assertStringContainsString( (string) $this->list_id, $html );
	}

	/**
	 * Render outputs email and consent fields always.
	 *
	 * @return void
	 */
	public function test_render_always_outputs_email_and_consent(): void {
		$html = SignupBlock::render(
			array(
				'list_ids' => array( $this->list_id ),
			)
		);

		$this->assertStringContainsString( 'type="email"', $html );
		$this->assertStringContainsString( 'name="email"', $html );
		$this->assertStringContainsString( 'name="consent"', $html );
	}

	/**
	 * Render outputs honeypot field.
	 *
	 * @return void
	 */
	public function test_render_outputs_honeypot(): void {
		$html = SignupBlock::render(
			array(
				'list_ids' => array( $this->list_id ),
			)
		);

		$this->assertStringContainsString( 'name="website_check"', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
	}

	/**
	 * Render outputs subscribe button.
	 *
	 * @return void
	 */
	public function test_render_outputs_subscribe_button(): void {
		$html = SignupBlock::render(
			array(
				'list_ids' => array( $this->list_id ),
			)
		);

		$this->assertStringContainsString( 'type="submit"', $html );
		$this->assertStringContainsString( 'Subscribe', $html );
	}

	/**
	 * Render with enabled_fields outputs data-stampy-field attributes.
	 *
	 * @return void
	 */
	public function test_render_enabled_fields_have_data_stampy_field(): void {
		$html = SignupBlock::render(
			array(
				'list_ids'       => array( $this->list_id ),
				'enabled_fields' => array( 'first_name', 'last_name' ),
			)
		);

		$this->assertStringContainsString( 'data-stampy-field="first_name"', $html );
		$this->assertStringContainsString( 'data-stampy-field="last_name"', $html );
	}

	/**
	 * Fields not in enabled_fields are omitted from rendered HTML.
	 *
	 * @return void
	 */
	public function test_render_excludes_disabled_fields(): void {
		$field_repo = new FieldRepository();
		$field_repo->create( 'company', 'Company', 'text' );

		$html = SignupBlock::render(
			array(
				'list_ids'       => array( $this->list_id ),
				'enabled_fields' => array( 'first_name' ),
			)
		);

		$this->assertStringContainsString( 'data-stampy-field="first_name"', $html );
		$this->assertStringNotContainsString( 'data-stampy-field="company"', $html );
		$this->assertStringNotContainsString( 'name="company"', $html );
	}

	/**
	 * Render with empty enabled_fields shows no custom fields.
	 *
	 * @return void
	 */
	public function test_render_with_empty_enabled_fields_shows_no_custom_fields(): void {
		$html = SignupBlock::render(
			array(
				'list_ids'       => array( $this->list_id ),
				'enabled_fields' => array(),
			)
		);

		$this->assertStringNotContainsString( 'data-stampy-field="first_name"', $html );
		$this->assertStringNotContainsString( 'data-stampy-field="last_name"', $html );
	}

	/**
	 * Text field renders as text input.
	 *
	 * @return void
	 */
	public function test_render_text_field_as_text_input(): void {
		$html = SignupBlock::render(
			array(
				'list_ids'       => array( $this->list_id ),
				'enabled_fields' => array( 'first_name' ),
			)
		);

		$this->assertStringContainsString( 'type="text"', $html );
		$this->assertStringContainsString( 'name="first_name"', $html );
	}

	/**
	 * Textarea field renders as textarea element.
	 *
	 * @return void
	 */
	public function test_render_textarea_field(): void {
		$field_repo = new FieldRepository();
		$field_repo->create( 'bio', 'Bio', 'textarea' );

		$html = SignupBlock::render(
			array(
				'list_ids'       => array( $this->list_id ),
				'enabled_fields' => array( 'bio' ),
			)
		);

		$this->assertStringContainsString( '<textarea', $html );
		$this->assertStringContainsString( 'name="bio"', $html );
		$this->assertStringContainsString( 'data-stampy-field="bio"', $html );
	}

	/**
	 * Select field renders as select element with options.
	 *
	 * @return void
	 */
	public function test_render_select_field(): void {
		$field_repo = new FieldRepository();
		$field_repo->create(
			'country',
			'Country',
			'select',
			array( 'US', 'DE', 'FR' )
		);

		$html = SignupBlock::render(
			array(
				'list_ids'       => array( $this->list_id ),
				'enabled_fields' => array( 'country' ),
			)
		);

		$this->assertStringContainsString( '<select', $html );
		$this->assertStringContainsString( 'name="country"', $html );
		$this->assertStringContainsString( 'data-stampy-field="country"', $html );
		$this->assertStringContainsString( '<option value="US">US</option>', $html );
		$this->assertStringContainsString( '<option value="DE">DE</option>', $html );
		$this->assertStringContainsString( '<option value="FR">FR</option>', $html );
	}

	/**
	 * Checkbox field renders as checkbox input.
	 *
	 * @return void
	 */
	public function test_render_checkbox_field(): void {
		$field_repo = new FieldRepository();
		$field_repo->create( 'agree_terms', 'Agree to Terms', 'checkbox' );

		$html = SignupBlock::render(
			array(
				'list_ids'       => array( $this->list_id ),
				'enabled_fields' => array( 'agree_terms' ),
			)
		);

		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( 'name="agree_terms"', $html );
		$this->assertStringContainsString( 'data-stampy-field="agree_terms"', $html );
	}

	/**
	 * Number field renders as number input.
	 *
	 * @return void
	 */
	public function test_render_number_field(): void {
		$field_repo = new FieldRepository();
		$field_repo->create( 'age', 'Age', 'number' );

		$html = SignupBlock::render(
			array(
				'list_ids'       => array( $this->list_id ),
				'enabled_fields' => array( 'age' ),
			)
		);

		$this->assertStringContainsString( 'type="number"', $html );
		$this->assertStringContainsString( 'name="age"', $html );
		$this->assertStringContainsString( 'data-stampy-field="age"', $html );
	}

	/**
	 * Date field renders as date input.
	 *
	 * @return void
	 */
	public function test_render_date_field(): void {
		$field_repo = new FieldRepository();
		$field_repo->create( 'birthday', 'Birthday', 'date' );

		$html = SignupBlock::render(
			array(
				'list_ids'       => array( $this->list_id ),
				'enabled_fields' => array( 'birthday' ),
			)
		);

		$this->assertStringContainsString( 'type="date"', $html );
		$this->assertStringContainsString( 'name="birthday"', $html );
		$this->assertStringContainsString( 'data-stampy-field="birthday"', $html );
	}

	/**
	 * Required fields have required attribute in rendered HTML.
	 *
	 * @return void
	 */
	public function test_render_required_field_has_required_attr(): void {
		$html = SignupBlock::render(
			array(
				'list_ids'       => array( $this->list_id ),
				'enabled_fields' => array( 'first_name' ),
			)
		);

		$this->assertStringContainsString( 'required', $html );
		$this->assertStringContainsString( 'aria-required="true"', $html );
	}

	/**
	 * Field labels are rendered.
	 *
	 * @return void
	 */
	public function test_render_field_labels(): void {
		$html = SignupBlock::render(
			array(
				'list_ids'       => array( $this->list_id ),
				'enabled_fields' => array( 'first_name', 'last_name' ),
			)
		);

		$this->assertStringContainsString( 'First Name', $html );
		$this->assertStringContainsString( 'Last Name', $html );
	}
}
