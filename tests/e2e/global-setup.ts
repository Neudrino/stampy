import { execSync } from 'child_process';

function wpCli( command: string ): string {
	return execSync(
		`WP_ENV_HOME=./.wp-env-home npx wp-env run tests-cli --env-cwd=wp-content/plugins/stampy ${ command }`,
		{
			encoding: 'utf-8',
			timeout: 30_000,
			stdio: [ 'pipe', 'pipe', 'pipe' ],
		}
	);
}

export default async function globalSetup() {
	execSync( 'npm run env:clean:tests', {
		encoding: 'utf-8',
		timeout: 30_000,
		stdio: [ 'pipe', 'pipe', 'pipe' ],
	} );

	try {
		wpCli( 'wp plugin activate stampy' );
	} catch ( e ) {
		throw new Error(
			'Failed to activate Stampy plugin in tests instance. ' +
				'Ensure composer install has run in the container.\n' +
				( e instanceof Error ? e.message : String( e ) )
		);
	}

	wpCli(
		`wp eval 'global $wpdb; $wpdb->insert( $wpdb->prefix . "stampy_lists", array( "name" => "E2E Test List", "slug" => "e2e-test", "description" => "E2E test list" ) );'`
	);

	const output = wpCli(
		`wp eval 'global $wpdb; $r = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}stampy_lists WHERE slug = \\"e2e-test\\"" ); echo $r ? $r->id : 0;'`
	);

	const listId = parseInt( output.trim(), 10 );

	if ( listId > 0 ) {
		process.env.STAMPY_E2E_LIST_ID = String( listId );
	} else {
		throw new Error(
			'Failed to create E2E test list. Plugin may not be active (missing vendor/?).'
		);
	}

	// Seed subscribers so the admin subscribers table has data rows.
	wpCli( `wp stampy seed --subscribers=10 --list=e2e-test` );
}
