<?php
/**
 * Fields admin page — list table + add/edit/delete.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\FieldRepository;

/**
 * Renders the fields admin page with CRUD operations.
 */
final class FieldsPage {

	/**
	 * Allowed field types.
	 *
	 * @var array<int, string>
	 */
	private const FIELD_TYPES = array(
		'text',
		'textarea',
		'number',
		'date',
		'select',
		'checkbox',
	);

	/**
	 * Render the fields page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'stampy' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['field_id'] ) ? (int) $_GET['field_id'] : 0;
		// phpcs:enable

		if ( 'edit' === $action && $id > 0 ) {
			self::render_edit( $id );
		} elseif ( 'new' === $action ) {
			self::render_edit( 0 );
		} else {
			self::render_list();
		}
	}

	/**
	 * Render the fields list table.
	 *
	 * @return void
	 */
	private static function render_list(): void {
		$table = new FieldsListTable();
		$table->prepare_items();
		?>
		<div class="wrap">
			<?php
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			$updated = isset( $_GET['updated'] ) ? sanitize_text_field( wp_unslash( $_GET['updated'] ) ) : '';
			$deleted = isset( $_GET['deleted'] ) ? sanitize_text_field( wp_unslash( $_GET['deleted'] ) ) : '';
			// phpcs:enable
			if ( '1' === $updated ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Field saved.', 'stampy' ) . '</p></div>';
			}
			if ( '1' === $deleted ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Field deleted.', 'stampy' ) . '</p></div>';
			}
			?>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Fields', 'stampy' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=stampy-fields&action=new' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'stampy' ); ?>
			</a>
			<form method="get">
				<input type="hidden" name="page" value="stampy-fields" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the add/edit form.
	 *
	 * @param int $id Field ID (0 = new).
	 * @return void
	 */
	private static function render_edit( int $id ): void {
		$repo = new FieldRepository();

		$key           = '';
		$label         = '';
		$type          = 'text';
		$options       = '';
		$required      = false;
		$show_in_admin = true;

		if ( $id > 0 ) {
			$field = $repo->find( $id );
			if ( null === $field ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'Field not found', 'stampy' ) . '</h1></div>';
				return;
			}
			$key           = $field->field_key;
			$label         = $field->label;
			$type          = $field->type;
			$decoded       = json_decode( (string) ( $field->options ?? '' ), true );
			$options       = is_array( $decoded ) ? implode( "\n", $decoded ) : '';
			$required      = '1' === (string) $field->required;
			$show_in_admin = '1' === (string) $field->show_in_admin;
		}
		?>
		<div class="wrap">
			<h1>
				<?php echo 0 === $id ? esc_html__( 'Add New Field', 'stampy' ) : esc_html__( 'Edit Field', 'stampy' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=stampy-fields' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Back', 'stampy' ); ?>
				</a>
			</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="stampy_save_field" />
				<input type="hidden" name="field_id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<?php wp_nonce_field( 'stampy_save_field_' . $id, 'stampy_field_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="field_label"><?php esc_html_e( 'Label', 'stampy' ); ?></label></th>
						<td><input type="text" name="field_label" id="field_label" class="regular-text" value="<?php echo esc_attr( $label ); ?>" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="field_key"><?php esc_html_e( 'Key', 'stampy' ); ?></label></th>
						<td>
							<?php if ( $id > 0 ) : ?>
								<input type="hidden" name="field_key" value="<?php echo esc_attr( $key ); ?>" />
								<input type="text" class="regular-text" value="<?php echo esc_attr( $key ); ?>" disabled />
							<?php else : ?>
								<input type="text" name="field_key" id="field_key" class="regular-text" value="<?php echo esc_attr( $key ); ?>" required />
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Unique identifier (lowercase, underscores). Cannot be changed after creation.', 'stampy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="field_type"><?php esc_html_e( 'Type', 'stampy' ); ?></label></th>
						<td>
							<select name="field_type" id="field_type">
								<?php foreach ( self::FIELD_TYPES as $ft ) : ?>
									<option value="<?php echo esc_attr( $ft ); ?>" <?php selected( $type, $ft ); ?>><?php echo esc_html( ucfirst( $ft ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="field_options"><?php esc_html_e( 'Options', 'stampy' ); ?></label></th>
						<td>
							<textarea name="field_options" id="field_options" rows="3" class="large-text"><?php echo esc_textarea( $options ); ?></textarea>
							<p class="description"><?php esc_html_e( 'For select fields: one option per line. For other types: leave empty.', 'stampy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Required', 'stampy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="field_required" id="field_required" value="1" <?php checked( $required ); ?> />
								<?php esc_html_e( 'This field is required', 'stampy' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show in Admin', 'stampy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="field_show_in_admin" id="field_show_in_admin" value="1" <?php checked( $show_in_admin ); ?> />
								<?php esc_html_e( 'Display this field in the subscriber profile', 'stampy' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save', 'stampy' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the save form submission.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stampy' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$id = isset( $_POST['field_id'] ) ? (int) $_POST['field_id'] : 0;

		check_admin_referer( 'stampy_save_field_' . $id, 'stampy_field_nonce' );

		$label         = isset( $_POST['field_label'] ) ? sanitize_text_field( wp_unslash( $_POST['field_label'] ) ) : '';
		$key           = isset( $_POST['field_key'] ) ? sanitize_key( wp_unslash( $_POST['field_key'] ) ) : '';
		$type          = isset( $_POST['field_type'] ) ? sanitize_key( wp_unslash( $_POST['field_type'] ) ) : 'text';
		$options_raw   = isset( $_POST['field_options'] ) ? sanitize_textarea_field( wp_unslash( $_POST['field_options'] ) ) : '';
		$required      = isset( $_POST['field_required'] );
		$show_in_admin = isset( $_POST['field_show_in_admin'] );
		// phpcs:enable

		if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
			$type = 'text';
		}

		$options_arr = null;
		if ( '' !== trim( $options_raw ) ) {
			$lines       = preg_split( '/\r\n|\r|\n/', $options_raw );
			$options_arr = array();
			if ( is_array( $lines ) ) {
				foreach ( $lines as $line ) {
					$line = trim( (string) $line );
					if ( '' !== $line ) {
						$options_arr[] = $line;
					}
				}
			}
		}

		$repo = new FieldRepository();

		if ( $id > 0 ) {
			$repo->update(
				$id,
				$key,
				$label,
				$type,
				$options_arr,
				$required,
				null,
				$show_in_admin
			);
		} else {
			$repo->create(
				$key,
				$label,
				$type,
				$options_arr,
				$required,
				null,
				$show_in_admin
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'stampy-fields',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle field deletion (fired on load-{hook} before page rendering).
	 *
	 * @return void
	 */
	public static function handle_delete_action(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['field_id'] ) ? (int) $_GET['field_id'] : 0;
		$nonce  = isset( $_GET['stampy_field_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['stampy_field_nonce'] ) ) : '';
		// phpcs:enable

		if ( 'delete' !== $action || $id <= 0 ) {
			return;
		}

		if ( ! wp_verify_nonce( $nonce, 'stampy_delete_field_' . $id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'stampy' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stampy' ) );
		}

		$repo = new FieldRepository();
		$repo->delete( $id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'stampy-fields',
					'deleted' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
