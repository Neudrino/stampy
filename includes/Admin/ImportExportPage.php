<?php
/**
 * Import/Export admin page.
 *
 * Renders a React-based import/export UI and enqueues its assets.
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
use const Stampy\PLUGIN_FILE;
use const Stampy\VERSION;

/**
 * Import/Export admin page renderer.
 */
final class ImportExportPage {

	/**
	 * Enqueue assets for the import/export page.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || 'stampy_page_stampy-import-export' !== $screen->id ) {
			return;
		}

		$asset_file = plugin_dir_path( PLUGIN_FILE ) . 'build/import-export/index.asset.php';
		$asset      = is_readable( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => VERSION,
			);

		wp_enqueue_script(
			'stampy-import-export-script',
			plugin_dir_url( PLUGIN_FILE ) . 'build/import-export/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'stampy-import-export-script', 'stampy', plugin_dir_path( PLUGIN_FILE ) . 'languages' );

		$lists_repo = new ListRepository();
		$lists      = $lists_repo->all();

		$lists_formatted = array();
		foreach ( $lists as $list ) {
			$lists_formatted[] = array(
				'id'   => (int) $list->id,
				'name' => $list->name,
				'slug' => $list->slug,
			);
		}

		wp_add_inline_script(
			'stampy-import-export-script',
			'window.stampy = window.stampy || {}; window.stampy.lists = ' . wp_json_encode( $lists_formatted ) . '; window.stampy.restNonce = ' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ';',
			'before'
		);
	}

	/**
	 * Render the import/export page.
	 *
	 * @return void
	 */
	public static function render(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import / Export Subscribers', 'stampy' ); ?></h1>
			<div id="stampy-import-export-app"></div>
		</div>
		<?php
	}
}
