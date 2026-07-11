<?php
/**
 * Server-side registration and rendering for the Stampy Signup block.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy;

use Stampy\Repositories\ConsentTextRepository;
use Stampy\Repositories\ListRepository;

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

		return array(
			'restUrl'     => esc_url_raw( rest_url( 'stampy/v1' ) ),
			'restNonce'   => wp_create_nonce( 'wp_rest' ),
			'lists'       => $lists_formatted,
			'consentText' => $consent_text,
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
		$list_ids        = is_array( $attributes['list_ids'] ?? array() ) ? array_map( 'intval', $attributes['list_ids'] ) : array();
		$show_first_name = isset( $attributes['show_first_name'] ) ? (bool) $attributes['show_first_name'] : true;
		$show_last_name  = isset( $attributes['show_last_name'] ) ? (bool) $attributes['show_last_name'] : true;

		if ( count( $list_ids ) === 0 ) {
			return '';
		}

		$consent_repo = new ConsentTextRepository();
		$consent_row  = $consent_repo->latest();
		$consent_text = null !== $consent_row ? $consent_row->text : __( 'I agree to receive marketing emails from this website. I can unsubscribe at any time.', 'stampy' );

		$wrapper_class = 'stampy-signup-block';
		if ( isset( $attributes['align'] ) ) {
			$wrapper_class .= ' align' . sanitize_html_class( $attributes['align'] );
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrapper_class ); ?>">
			<form class="stampy-signup-form" method="post" data-list-ids="<?php echo esc_attr( (string) wp_json_encode( $list_ids ) ); ?>">
				<?php if ( $show_first_name ) : ?>
					<p class="stampy-signup-field">
						<label for="stampy-first-name-<?php echo esc_attr( (string) wp_rand() ); ?>">
							<?php esc_html_e( 'First Name', 'stampy' ); ?>
						</label>
						<input
							type="text"
							name="first_name"
							class="stampy-signup-input"
						/>
					</p>
				<?php endif; ?>

				<?php if ( $show_last_name ) : ?>
					<p class="stampy-signup-field">
						<label for="stampy-last-name-<?php echo esc_attr( (string) wp_rand() ); ?>">
							<?php esc_html_e( 'Last Name', 'stampy' ); ?>
						</label>
						<input
							type="text"
							name="last_name"
							class="stampy-signup-input"
						/>
					</p>
				<?php endif; ?>

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

				<p class="stampy-signup-field stampy-signup-honeypot" aria-hidden="true">
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
