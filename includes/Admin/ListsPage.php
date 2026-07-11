<?php
/**
 * Lists admin page — list table + add/edit/delete.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\ListRepository;

/**
 * Renders the lists admin page with CRUD operations.
 */
final class ListsPage {

	/**
	 * Render the lists page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'stampy' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only GET params for page routing.
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['list_id'] ) ? (int) $_GET['list_id'] : 0;
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
	 * Render the lists list table.
	 *
	 * @return void
	 */
	private static function render_list(): void {
		$table = new ListsListTable();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Lists', 'stampy' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=stampy-lists&action=new' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'stampy' ); ?>
			</a>
			<form method="get">
				<input type="hidden" name="page" value="stampy-lists" />
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the add/edit form.
	 *
	 * @param int $id List ID (0 = new).
	 * @return void
	 */
	private static function render_edit( int $id ): void {
		$repo = new ListRepository();

		$name        = '';
		$slug        = '';
		$description = '';

		if ( $id > 0 ) {
			$list = $repo->find( $id );
			if ( null === $list ) {
				echo '<div class="wrap"><h1>' . esc_html__( 'List not found', 'stampy' ) . '</h1></div>';
				return;
			}
			$name        = $list->name;
			$slug        = $list->slug;
			$description = $list->description;
		}
		?>
		<div class="wrap">
			<h1>
				<?php echo 0 === $id ? esc_html__( 'Add New List', 'stampy' ) : esc_html__( 'Edit List', 'stampy' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=stampy-lists' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Back', 'stampy' ); ?>
				</a>
			</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="stampy_save_list" />
				<input type="hidden" name="list_id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<?php wp_nonce_field( 'stampy_save_list_' . $id, 'stampy_list_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="list_name"><?php esc_html_e( 'Name', 'stampy' ); ?></label></th>
						<td><input type="text" name="list_name" id="list_name" class="regular-text" value="<?php echo esc_attr( $name ); ?>" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="list_slug"><?php esc_html_e( 'Slug', 'stampy' ); ?></label></th>
						<td><input type="text" name="list_slug" id="list_slug" class="regular-text" value="<?php echo esc_attr( $slug ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="list_description"><?php esc_html_e( 'Description', 'stampy' ); ?></label></th>
						<td><textarea name="list_description" id="list_description" class="large-text" rows="3"><?php echo esc_textarea( $description ); ?></textarea></td>
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
		$id = isset( $_POST['list_id'] ) ? (int) $_POST['list_id'] : 0;

		check_admin_referer( 'stampy_save_list_' . $id, 'stampy_list_nonce' );

		$name        = isset( $_POST['list_name'] ) ? sanitize_text_field( wp_unslash( $_POST['list_name'] ) ) : '';
		$slug        = isset( $_POST['list_slug'] ) ? sanitize_title( wp_unslash( $_POST['list_slug'] ) ) : '';
		$description = isset( $_POST['list_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['list_description'] ) ) : '';
		// phpcs:enable

		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}

		if ( '' === $name ) {
			wp_safe_redirect( admin_url( 'admin.php?page=stampy-lists' ) );
			exit;
		}

		$repo = new ListRepository();

		if ( $id > 0 ) {
			$repo->update( $id, $name, $slug, $description );
		} else {
			$id = $repo->create( $name, $slug, $description );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'stampy-lists',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
