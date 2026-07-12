<?php
/**
 * Campaign custom post type registration.
 *
 * Registers the `stampy_campaign` CPT, its postmeta keys, and the restricted
 * block set for the block editor.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Campaigns;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\ListRepository;
use const Stampy\PLUGIN_FILE;
use const Stampy\VERSION;

/**
 * Registers the stampy_campaign CPT and related meta.
 */
final class CampaignPostType {

	/**
	 * Post type slug.
	 */
	public const POST_TYPE = 'stampy_campaign';

	/**
	 * Meta key for the email subject.
	 */
	public const META_SUBJECT = 'stampy_campaign_subject';

	/**
	 * Meta key for the targeted list IDs (JSON array).
	 */
	public const META_LIST_IDS = 'stampy_campaign_list_ids';

	/**
	 * Meta key for the campaign status enum.
	 */
	public const META_STATUS = 'stampy_campaign_status';

	/**
	 * Allowed block set in the campaign editor.
	 */
	public const ALLOWED_BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/image',
		'core/buttons',
		'core/button',
		'core/list',
		'core/list-item',
		'core/separator',
		'core/spacer',
		'core/columns',
		'core/column',
		'core/group',
	);

	/**
	 * Register the CPT on init.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_action( 'init', array( self::class, 'register_meta' ) );
		add_filter( 'allowed_block_types_all', array( self::class, 'restrict_block_types' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );
	}

	/**
	 * Enqueue the campaign editor sidebar script and localize data.
	 *
	 * Hooked to enqueue_block_editor_assets — runs only in the block editor.
	 * The campaign sidebar is a JS plugin (not an inseratable block), so it
	 * must be enqueued directly rather than relying on block registration.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$asset_file = plugin_dir_path( PLUGIN_FILE ) . 'build/campaign-editor/index.asset.php';
		$asset      = is_readable( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(),
				'version'      => VERSION,
			);

		wp_enqueue_script(
			'stampy-campaign-editor-editor-script',
			plugin_dir_url( PLUGIN_FILE ) . 'build/campaign-editor/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$lists_repo      = new ListRepository();
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

		$preview_url = admin_url( 'admin-post.php?action=stampy_campaign_preview' );

		$data = array(
			'lists'      => $lists_formatted,
			'previewUrl' => $preview_url,
		);

		wp_add_inline_script(
			'stampy-campaign-editor-editor-script',
			'window.stampy = Object.assign( window.stampy || {}, ' . wp_json_encode( $data ) . ' );',
			'before'
		);
	}

	/**
	 * Register the stampy_campaign CPT.
	 *
	 * @return void
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'                     => __( 'Campaigns', 'stampy' ),
					'singular_name'            => __( 'Campaign', 'stampy' ),
					'add_new'                  => __( 'New Campaign', 'stampy' ),
					'add_new_item'             => __( 'Compose New Campaign', 'stampy' ),
					'edit_item'                => __( 'Edit Campaign', 'stampy' ),
					'new_item'                 => __( 'New Campaign', 'stampy' ),
					'view_item'                => __( 'View Campaign', 'stampy' ),
					'search_items'             => __( 'Search Campaigns', 'stampy' ),
					'not_found'                => __( 'No campaigns found.', 'stampy' ),
					'not_found_in_trash'       => __( 'No campaigns found in trash.', 'stampy' ),
					'all_items'                => __( 'All Campaigns', 'stampy' ),
					'archives'                 => __( 'Campaign Archives', 'stampy' ),
					'attributes'               => __( 'Campaign Attributes', 'stampy' ),
					'insert_into_item'         => __( 'Insert into campaign', 'stampy' ),
					'uploaded_to_this_item'    => __( 'Uploaded to this campaign', 'stampy' ),
					'filter_items_list'        => __( 'Filter campaigns list', 'stampy' ),
					'items_list_navigation'    => __( 'Campaigns list navigation', 'stampy' ),
					'items_list'               => __( 'Campaigns list', 'stampy' ),
					'item_published'           => __( 'Campaign published.', 'stampy' ),
					'item_published_privately' => __( 'Campaign published privately.', 'stampy' ),
					'item_reverted_to_draft'   => __( 'Campaign reverted to draft.', 'stampy' ),
					'item_scheduled'           => __( 'Campaign scheduled.', 'stampy' ),
					'item_updated'             => __( 'Campaign updated.', 'stampy' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'stampy-subscribers',
				'show_in_rest'        => true,
				'rest_base'           => 'stampy-campaigns',
				'supports'            => array(
					'title',
					'editor',
					'revisions',
					'author',
					'thumbnail',
					'custom-fields',
				),
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'menu_icon'           => 'dashicons-email-alt',
				'menu_position'       => 27,
				'rewrite'             => false,
				'has_archive'         => false,
				'exclude_from_search' => true,
			)
		);
	}

	/**
	 * Register postmeta for campaigns.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		register_post_meta(
			self::POST_TYPE,
			self::META_SUBJECT,
			array(
				'type'          => 'string',
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => true,
				'auth_callback' => array( self::class, 'meta_auth_callback' ),
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_LIST_IDS,
			array(
				'type'          => 'string',
				'single'        => true,
				'default'       => '[]',
				'show_in_rest'  => true,
				'auth_callback' => array( self::class, 'meta_auth_callback' ),
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_STATUS,
			array(
				'type'          => 'string',
				'single'        => true,
				'default'       => 'draft',
				'show_in_rest'  => true,
				'auth_callback' => array( self::class, 'meta_auth_callback' ),
			)
		);
	}

	/**
	 * Meta auth callback — restricts meta writes to users with manage_options.
	 *
	 * @param bool   $allowed  Whether the user can edit the meta.
	 * @param string $meta_key  Meta key being checked.
	 * @param int    $post_id   Post ID.
	 * @return bool
	 */
	public static function meta_auth_callback( bool $allowed, string $meta_key, int $post_id ): bool {
		if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
			return $allowed;
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Restrict the block set in the campaign editor.
	 *
	 * @param bool|string[]            $allowed  Allowed block types.
	 * @param \WP_Block_Editor_Context $context Block editor context.
	 * @return bool|string[]
	 */
	public static function restrict_block_types( $allowed, $context ) {
		if ( ! $context instanceof \WP_Block_Editor_Context ) {
			return $allowed;
		}

		if ( ! isset( $context->post ) ) {
			return $allowed;
		}

		$post = $context->post;
		if ( $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) {
			return self::ALLOWED_BLOCKS;
		}

		return $allowed;
	}

	/**
	 * Get the list IDs for a campaign.
	 *
	 * @param int $post_id Campaign post ID.
	 * @return int[]
	 */
	public static function get_list_ids( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_LIST_IDS, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', $decoded ) ) );
	}

	/**
	 * Get the subject for a campaign.
	 *
	 * @param int $post_id Campaign post ID.
	 * @return string
	 */
	public static function get_subject( int $post_id ): string {
		$subject = get_post_meta( $post_id, self::META_SUBJECT, true );
		return is_string( $subject ) ? $subject : '';
	}

	/**
	 * Get the status for a campaign.
	 *
	 * @param int $post_id Campaign post ID.
	 * @return string
	 */
	public static function get_status( int $post_id ): string {
		$status = get_post_meta( $post_id, self::META_STATUS, true );
		if ( ! is_string( $status ) || '' === $status ) {
			return 'draft';
		}
		return $status;
	}

	/**
	 * Set the list IDs for a campaign.
	 *
	 * @param int   $post_id  Campaign post ID.
	 * @param int[] $list_ids List IDs.
	 * @return bool
	 */
	public static function set_list_ids( int $post_id, array $list_ids ): bool {
		$ids = array_values( array_filter( array_map( 'intval', $list_ids ) ) );
		return (bool) update_post_meta( $post_id, self::META_LIST_IDS, wp_json_encode( $ids ) );
	}

	/**
	 * Set the subject for a campaign.
	 *
	 * @param int    $post_id Campaign post ID.
	 * @param string $subject Email subject.
	 * @return bool
	 */
	public static function set_subject( int $post_id, string $subject ): bool {
		return (bool) update_post_meta( $post_id, self::META_SUBJECT, $subject );
	}

	/**
	 * Set the status for a campaign.
	 *
	 * @param int    $post_id Campaign post ID.
	 * @param string $status  Status enum value.
	 * @return bool
	 */
	public static function set_status( int $post_id, string $status ): bool {
		$valid = array( 'draft', 'sending', 'sent', 'cancelled' );
		if ( ! in_array( $status, $valid, true ) ) {
			return false;
		}
		return (bool) update_post_meta( $post_id, self::META_STATUS, $status );
	}
}
