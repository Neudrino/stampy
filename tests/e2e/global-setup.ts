import { execSync } from 'child_process';
import { chromium } from '@playwright/test';

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

	// Activate the Plugin Check plugin (pre-installed via .wp-env.json
	// tests.plugins mapping) so the plugin-check E2E test can run
	// `wp plugin check` via WP-CLI.
	try {
		wpCli( 'wp plugin activate plugin-check' );
	} catch ( e ) {
		throw new Error(
			'Failed to activate Plugin Check plugin in tests instance. ' +
				'Ensure .wp-env.json has the plugin-check mapping.\n' +
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

	// Log in once and save the session so all tests can reuse it
	// without each triggering a separate WP login (which races under
	// fullyParallel mode).
	const browser = await chromium.launch();
	const context = await browser.newContext();
	const page = await context.newPage();

	await page.goto( 'http://localhost:8889/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForSelector( '#wpadminbar', { timeout: 30000 } );
	await page.waitForLoadState( 'domcontentloaded' );

	await context.storageState( { path: 'tests/e2e/.auth/admin.json' } );
	await browser.close();
}
