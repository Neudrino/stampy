<?php
/**
 * Server-side registration and rendering for the Stampy Signup block.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\ConsentTextRepository;
use Stampy\Repositories\FieldRepository;
use Stampy\Repositories\ListRepository;
use Stampy\SpamGuards\FriendlyCaptchaGuard;
use Stampy\SpamGuards\QuizGuard;
use Stampy\SpamGuards\TurnstileGuard;

/**
 * Registers the Stampy Signup block and renders it server-side.
 */
final class SignupBlock {

	/**
	 * Register the block on init.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_block_type_from_metadata(
			plugin_dir_path( PLUGIN_FILE ) . 'build/blocks/signup',
			array(
				'render_callback' => array( self::class, 'render' ),
			)
		);

		wp_set_script_translations( 'stampy-signup-editor-script', 'stampy', plugin_dir_path( PLUGIN_FILE ) . 'languages' );
		wp_set_script_translations( 'stampy-signup-view-script', 'stampy', plugin_dir_path( PLUGIN_FILE ) . 'languages' );

		wp_register_script(
			'stampy-captcha-loader',
			plugins_url( 'assets/captcha-loader.js', PLUGIN_FILE ),
			array(),
			VERSION,
			true
		);

		add_action( 'enqueue_block_editor_assets', array( self::class, 'localize_editor_data' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'localize_view_data' ) );
	}

	/**
	 * Get the localized data array for JavaScript.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_localized_data(): array {
		$lists_repo      = new ListRepository();
		$consent_repo    = new ConsentTextRepository();
		$consent_row     = $consent_repo->latest();
		$consent_text    = null !== $consent_row ? $consent_row->text : '';
		$lists           = $lists_repo->all();
		$lists_formatted = array();

		foreach ( $lists as $list ) {
			$lists_formatted[] = array(
				'id'          => (int) $list->id,
				'name'        => $list->name,
				'slug'        => $list->slug,
				'description' => $list->description,
			);
		}

		$fields_repo      = new FieldRepository();
		$field_defs       = $fields_repo->all();
		$fields_formatted = array();
		foreach ( $field_defs as $field ) {
			$fields_formatted[] = array(
				'key'      => $field->field_key,
				'label'    => $field->label,
				'type'     => $field->type,
				'options'  => null !== $field->options ? json_decode( (string) $field->options, true ) : null,
				'required' => '1' === (string) $field->required,
			);
		}

		return array(
			'restUrl'                => esc_url_raw( rest_url( 'stampy/v1' ) ),
			'restNonce'              => wp_create_nonce( 'wp_rest' ),
			'lists'                  => $lists_formatted,
			'fields'                 => $fields_formatted,
			'consentText'            => $consent_text,
			'quizQuestions'          => QuizGuard::get_questions(),
			'turnstileEnabled'       => TurnstileGuard::is_enabled(),
			'turnstileSiteKey'       => TurnstileGuard::get_site_key(),
			'friendlyCaptchaEnabled' => FriendlyCaptchaGuard::is_enabled(),
			'friendlyCaptchaSiteKey' => FriendlyCaptchaGuard::get_site_key(),
		);
	}

	/**
	 * Localize data for the editor script.
	 *
	 * Hooked to enqueue_block_editor_assets — runs only in the block editor.
	 *
	 * @return void
	 */
	public static function localize_editor_data(): void {
		$data = self::get_localized_data();

		wp_add_inline_script(
			'stampy-signup-editor-script',
			'window.stampy = ' . wp_json_encode( $data ) . ';',
			'before'
		);
	}

	/**
	 * Localize data for the front-end view script.
	 *
	 * Hooked to wp_enqueue_scripts — runs only on the front end.
	 *
	 * @return void
	 */
	public static function localize_view_data(): void {
		$data = self::get_localized_data();

		wp_add_inline_script(
			'stampy-signup-view-script',
			'window.stampy = ' . wp_json_encode( $data ) . ';',
			'before'
		);
	}

	/**
	 * Render the signup form server-side.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string HTML output.
	 */
	public static function render( array $attributes ): string {
		$raw_list_ids       = $attributes['list_ids'] ?? array();
		$raw_enabled_fields = $attributes['enabled_fields'] ?? array();
		$list_ids           = is_array( $raw_list_ids ) ? array_map( 'intval', $raw_list_ids ) : array();
		$enabled_fields     = is_array( $raw_enabled_fields ) ? array_map( 'strval', $raw_enabled_fields ) : array();

		if ( count( $list_ids ) === 0 ) {
			return '';
		}

		if ( TurnstileGuard::is_enabled() || FriendlyCaptchaGuard::is_enabled() ) {
			wp_enqueue_script( 'stampy-captcha-loader' );
		}

		$consent_repo = new ConsentTextRepository();
		$consent_row  = $consent_repo->latest();
		$consent_text = null !== $consent_row ? $consent_row->text : __( 'I agree to receive marketing emails from this website. I can unsubscribe at any time.', 'stampy' );

		$fields_repo    = new FieldRepository();
		$field_defs     = $fields_repo->all();
		$visible_fields = array();
		foreach ( $field_defs as $fd ) {
			if ( in_array( (string) $fd->field_key, $enabled_fields, true ) ) {
				$visible_fields[] = $fd;
			}
		}

		$wrapper_class = 'stampy-signup-block';
		if ( isset( $attributes['align'] ) ) {
			$wrapper_class .= ' align' . sanitize_html_class( $attributes['align'] );
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrapper_class ); ?>">
			<form class="stampy-signup-form" method="post" data-list-ids="<?php echo esc_attr( (string) wp_json_encode( $list_ids ) ); ?>">
			<?php foreach ( $visible_fields as $vf ) : ?>
				<?php
				$vf_key     = (string) $vf->field_key;
				$vf_label   = (string) $vf->label;
				$vf_type    = (string) $vf->type;
				$vf_options = null !== $vf->options ? json_decode( (string) $vf->options, true ) : null;
				$vf_req     = '1' === (string) $vf->required;
				$vf_id      = 'stampy-' . $vf_key . '-' . wp_rand();
				?>
				<p class="stampy-signup-field">
					<label for="<?php echo esc_attr( (string) $vf_id ); ?>">
						<?php echo esc_html( $vf_label ); ?>
						<?php if ( $vf_req ) : ?>
							<span class="required" aria-hidden="true">*</span>
						<?php endif; ?>
					</label>
					<?php
					switch ( $vf_type ) {
						case 'textarea':
							printf(
								'<textarea name="%s" data-stampy-field="%s" class="stampy-signup-input" rows="3"%s></textarea>',
								esc_attr( $vf_key ),
								esc_attr( $vf_key ),
								$vf_req ? ' required aria-required="true"' : ''
							);
							break;
						case 'select':
							if ( is_array( $vf_options ) && count( $vf_options ) > 0 ) {
								echo '<select name="' . esc_attr( $vf_key ) . '" data-stampy-field="' . esc_attr( $vf_key ) . '" class="stampy-signup-input"' . ( $vf_req ? ' required aria-required="true"' : '' ) . '>';
								echo '<option value="">—</option>';
								foreach ( $vf_options as $opt ) {
									printf(
										'<option value="%s">%s</option>',
										esc_attr( $opt ),
										esc_html( $opt )
									);
								}
								echo '</select>';
							}
							break;
						case 'checkbox':
							printf(
								'<input type="checkbox" name="%s" data-stampy-field="%s" value="1" class="stampy-signup-input"%s />',
								esc_attr( $vf_key ),
								esc_attr( $vf_key ),
								$vf_req ? ' required aria-required="true"' : ''
							);
							break;
						case 'number':
							printf(
								'<input type="number" name="%s" data-stampy-field="%s" class="stampy-signup-input"%s />',
								esc_attr( $vf_key ),
								esc_attr( $vf_key ),
								$vf_req ? ' required aria-required="true"' : ''
							);
							break;
						case 'date':
							printf(
								'<input type="date" name="%s" data-stampy-field="%s" class="stampy-signup-input"%s />',
								esc_attr( $vf_key ),
								esc_attr( $vf_key ),
								$vf_req ? ' required aria-required="true"' : ''
							);
							break;
						default:
							printf(
								'<input type="text" name="%s" data-stampy-field="%s" class="stampy-signup-input"%s />',
								esc_attr( $vf_key ),
								esc_attr( $vf_key ),
								$vf_req ? ' required aria-required="true"' : ''
							);
					}
					?>
				</p>
			<?php endforeach; ?>

				<p class="stampy-signup-field">
					<label for="stampy-email-<?php echo esc_attr( (string) wp_rand() ); ?>">
						<?php esc_html_e( 'Email', 'stampy' ); ?>
						<span class="required" aria-hidden="true">*</span>
					</label>
					<input
						type="email"
						name="email"
						class="stampy-signup-input"
						required
						aria-required="true"
					/>
				</p>

				<p class="stampy-signup-field">
					<label>
						<input
							type="checkbox"
							name="consent"
							required
							aria-required="true"
						/>
						<?php echo esc_html( $consent_text ); ?>
						<span class="required" aria-hidden="true">*</span>
					</label>
				</p>

				<?php
				$quiz_questions = QuizGuard::get_questions();
				if ( count( $quiz_questions ) > 0 ) :
					$quiz_index = wp_rand( 0, count( $quiz_questions ) - 1 );
					?>
				<p class="stampy-signup-field stampy-signup-quiz">
					<label for="stampy-quiz-<?php echo esc_attr( (string) wp_rand() ); ?>">
						<?php echo esc_html( $quiz_questions[ $quiz_index ]['question'] ); ?>
						<span class="required" aria-hidden="true">*</span>
					</label>
					<input
						type="text"
						name="stampy_quiz_answer"
						class="stampy-signup-input"
						required
						aria-required="true"
					/>
					<input type="hidden" name="stampy_quiz_index" value="<?php echo esc_attr( (string) $quiz_index ); ?>" />
				</p>
				<?php endif; ?>

		<?php if ( TurnstileGuard::is_enabled() ) : ?>
			<?php $turnstile_site_key = TurnstileGuard::get_site_key(); ?>
			<p class="stampy-signup-field stampy-signup-turnstile">
				<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_site_key ); ?>"></div>
			</p>
		<?php endif; ?>

		<?php if ( FriendlyCaptchaGuard::is_enabled() ) : ?>
			<?php $fc_site_key = FriendlyCaptchaGuard::get_site_key(); ?>
			<p class="stampy-signup-field stampy-signup-friendly-captcha">
				<div class="frc-captcha" data-sitekey="<?php echo esc_attr( $fc_site_key ); ?>" data-start="auto"></div>
			</p>
		<?php endif; ?>

			<p class="stampy-signup-field stampy-signup-honeypot" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">
					<label>
						<?php esc_html_e( 'Website', 'stampy' ); ?>
						<input
							type="text"
							name="website_check"
							tabindex="-1"
							autocomplete="off"
						/>
					</label>
				</p>

				<button type="submit" class="stampy-signup-button button">
					<?php esc_html_e( 'Subscribe', 'stampy' ); ?>
				</button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
