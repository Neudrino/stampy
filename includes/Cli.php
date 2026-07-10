<?php
/**
 * WP-CLI commands for Stampy.
 *
 * Provides `wp stampy seed --subscribers=N` for manual testing and E2E
 * fixture generation.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy;

use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\SubscriberRepository;
use stdClass;

/**
 * WP-CLI command for seeding test data.
 */
class Cli {

	/**
	 * Seed subscribers and lists for manual testing.
	 *
	 * ## OPTIONS
	 *
	 * [--subscribers=<N>]
	 * : Number of subscribers to create. Default: 50.
	 *
	 * [--list=<slug>]
	 * : Slug of a list to create and add subscribers to. Default: newsletter.
	 *
	 * ## EXAMPLES
	 *
	 *     wp stampy seed --subscribers=100
	 *     wp stampy seed --subscribers=20 --list=announcements
	 *
	 * @when after_wp_load
	 *
	 * @param array<string, mixed> $args       Positional args.
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @return void
	 */
	public function seed( array $args, array $assoc_args ): void {
		$count = (int) ( $assoc_args['subscribers'] ?? 50 );
		$slug  = sanitize_key( $assoc_args['list'] ?? 'newsletter' );

		$list_repo       = new ListRepository();
		$subscriber_repo = new SubscriberRepository();
		$meta_repo       = new SubscriberMetaRepository();

		\WP_CLI::log( "Stampy: seeding $count subscribers…" );

		// Create or get the list.
		$list = $list_repo->find_by_slug( $slug );
		if ( null === $list ) {
			$name    = ucwords( str_replace( '-', ' ', $slug ) );
			$list_id = $list_repo->create( $name, $slug, 'Seeded by Stampy CLI' );
			\WP_CLI::log( "Created list: $name (ID: $list_id)" );
		} else {
			$list_id = (int) $list->id;
			\WP_CLI::log( "Using existing list: {$list->name} (ID: $list_id)" );
		}

		$first_names = array( 'Alice', 'Bob', 'Carol', 'David', 'Eve', 'Frank', 'Grace', 'Henry', 'Iris', 'Jack', 'Karen', 'Leo', 'Mia', 'Noah', 'Olivia', 'Paul', 'Quinn', 'Ruth', 'Sam', 'Tara' );
		$last_names  = array( 'Anderson', 'Brown', 'Clarke', 'Davis', 'Evans', 'Foster', 'Garcia', 'Hughes', 'Irwin', 'Jones', 'Klein', 'Lopez', 'Miller', 'Nash', 'Owens', 'Patel', 'Quinn', 'Reed', 'Smith', 'Turner' );

		$created = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$first = $first_names[ array_rand( $first_names ) ];
			$last  = $last_names[ array_rand( $last_names ) ];
			$email = strtolower( $first . '.' . $last . wp_rand( 1, 9999 ) . '@example.com' );

			$subscriber = $subscriber_repo->create_or_get( $email, 'confirmed' );
			$subscriber_repo->update_status( (int) $subscriber->id, 'confirmed' );

			$meta_repo->set( (int) $subscriber->id, 'first_name', $first );
			$meta_repo->set( (int) $subscriber->id, 'last_name', $last );

			$list_repo->add_subscriber( (int) $subscriber->id, $list_id );

			++$created;
		}

		\WP_CLI::success( "Seeded $created subscribers into list '$slug'." );
	}
}
