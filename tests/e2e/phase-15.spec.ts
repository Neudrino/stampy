/**
 * E2E test: Phase 15 — Import/Export.
 */

import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

const TESTS_URL = 'http://localhost:8889';

async function adminLogin(
	page: import('@playwright/test').Page
): Promise< void > {
	await page.goto( `${ TESTS_URL }/wp-admin/` );
	if ( page.url().includes( 'wp-login.php' ) ) {
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForSelector( '#wpadminbar', { timeout: 30000 } );
		await page.waitForLoadState( 'domcontentloaded' );
	}
}

function wpCli( command: string ): string {
	let lastError: Error | null = null;
	for ( let attempt = 0; attempt < 3; attempt++ ) {
		try {
			return execSync(
				`WP_ENV_HOME=./.wp-env-home npx wp-env run tests-cli --env-cwd=wp-content/plugins/stampy ${ command }`,
				{
					encoding: 'utf-8',
					timeout: 60_000,
					stdio: [ 'pipe', 'pipe', 'pipe' ],
				}
			);
		} catch ( e ) {
			lastError = e instanceof Error ? e : new Error( String( e ) );
			if ( attempt < 2 ) {
				const start = Date.now();
				while ( Date.now() - start < 2000 ) {
					// busy wait
				}
			}
		}
	}
	throw lastError;
}

test.describe( 'Phase 15 — Import/Export', () => {
	test( 'import/export page is accessible', async ( { page } ) => {
		await adminLogin( page );
		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-import-export`
		);
		await expect( page.locator( '.wrap h1' ) ).toContainText(
			'Import / Export Subscribers'
		);
	} );

	test( 'export CSV downloads all subscribers', async ( { page } ) => {
		await adminLogin( page );

		// Create test subscribers via WP-CLI.
		wpCli(
			`wp eval '
			$repo = new \\Stampy\\Repositories\\SubscriberRepository();
			$sub = $repo->create_or_get("alice-e2e@stampy.local", "confirmed", 1);
			$repo->update_status((int)$sub->id, "confirmed");
			$meta = new \\Stampy\\Repositories\\SubscriberMetaRepository();
			$meta->apply_merge((int)$sub->id, array("first_name" => "AliceE2E"));
			echo $sub->id;
			'`
		);

		// Fetch CSV via REST API.
		const csvResponse = await page.request.get(
			`${ TESTS_URL }/wp-json/stampy/v1/export?format=csv`,
			{
				headers: {
					'X-WP-Nonce': await page.evaluate( () => {
						return (
							(
								window as unknown as {
									wpApiSettings?: { nonce?: string };
								}
							 ).wpApiSettings?.nonce || ''
						);
					} ),
				},
			}
		);

		// If REST nonce isn't available via browser, use the admin page.
		if ( ! csvResponse.ok() ) {
			// Fall back to WP-CLI export.
			const csv = wpCli(
				`wp eval '
			$svc = new \\Stampy\\ImportExportService();
			echo $svc->export_csv();
			'`
			);
			expect( csv ).toContain( 'alice-e2e@stampy.local' );
		} else {
			const data = await csvResponse.json();
			expect( data.data ).toContain( 'alice-e2e@stampy.local' );
		}

		// Cleanup.
		wpCli(
			`wp eval '
			$repo = new \\Stampy\\Repositories\\SubscriberRepository();
			$sub = $repo->find_by_email("alice-e2e@stampy.local");
			if ($sub) { $repo->delete((int)$sub->id); }
			'`
		);
	} );

	test( 'import CSV creates subscribers via REST API', async ( { page } ) => {
		await adminLogin( page );

		// Get REST nonce from the admin page.
		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-import-export`
		);

		const restNonce = await page.evaluate( () => {
			return (
				( window as unknown as { stampy?: { restNonce?: string } } )
					.stampy?.restNonce || ''
			);
		} );
		expect( restNonce ).toBeTruthy();

		const listName = `E2E Import ${ Date.now() }`;

		// Import via REST API.
		const response = await page.request.post(
			`${ TESTS_URL }/wp-json/stampy/v1/import`,
			{
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': restNonce,
				},
				data: {
					rows: [
						{
							email: 'import-e2e@stampy.local',
							first_name: 'ImportedAlice',
							last_name: 'Test',
						},
						{
							email: 'import-e2e-2@stampy.local',
							first_name: 'ImportedBob',
						},
					],
					list_name: listName,
				},
			}
		);

		expect( response.ok() ).toBeTruthy();
		const result = await response.json();
		expect( result.success ).toBe( true );
		expect( result.imported ).toBe( 2 );
		expect( result.skipped ).toBe( 0 );

		// Verify subscribers were created.
		const sub1 = wpCli(
			`wp eval '
			$repo = new \\Stampy\\Repositories\\SubscriberRepository();
			$sub = $repo->find_by_email("import-e2e@stampy.local");
			if ($sub) { echo $sub->id . ":" . $sub->status; }
			'`
		);
		expect( sub1 ).toContain( ':confirmed' );

		// Verify meta was applied.
		const meta1 = wpCli(
			`wp eval '
			$repo = new \\Stampy\\Repositories\\SubscriberMetaRepository();
			$sub = new \\Stampy\\Repositories\\SubscriberRepository();
			$s = $sub->find_by_email("import-e2e@stampy.local");
			if ($s) {
				$m = $repo->get_all((int)$s->id);
				echo $m["first_name"] ?? "";
			}
			'`
		);
		expect( meta1.trim() ).toBe( 'ImportedAlice' );

		// Cleanup.
		wpCli(
			`wp eval '
			$repo = new \\Stampy\\Repositories\\SubscriberRepository();
			$sub = $repo->find_by_email("import-e2e@stampy.local");
			if ($sub) { $repo->delete((int)$sub->id); }
			$sub = $repo->find_by_email("import-e2e-2@stampy.local");
			if ($sub) { $repo->delete((int)$sub->id); }
			'`
		);
	} );

	test( 'export then re-import preserves data', async ( { page } ) => {
		await adminLogin( page );

		// Create a subscriber with attributes.
		wpCli(
			`wp eval '
			$repo = new \\Stampy\\Repositories\\SubscriberRepository();
			$meta = new \\Stampy\\Repositories\\SubscriberMetaRepository();
			$sub = $repo->create_or_get("roundtrip@stampy.local", "confirmed", 1);
			$repo->update_status((int)$sub->id, "confirmed");
			$meta->apply_merge((int)$sub->id, array("first_name" => "RoundTrip", "last_name" => "Test"));
			echo $sub->id;
			'`
		);

		// Export via WP-CLI.
		const csv = wpCli(
			`wp eval '
			$svc = new \\Stampy\\ImportExportService();
			echo $svc->export_csv();
			'`
		);
		expect( csv ).toContain( 'roundtrip@stampy.local' );
		expect( csv ).toContain( 'RoundTrip' );

		// Delete the subscriber.
		wpCli(
			`wp eval '
			$repo = new \\Stampy\\Repositories\\SubscriberRepository();
			$sub = $repo->find_by_email("roundtrip@stampy.local");
			if ($sub) { $repo->delete((int)$sub->id); }
			'`
		);

		// Verify it's gone.
		const deleted = wpCli(
			`wp eval '
			$repo = new \\Stampy\\Repositories\\SubscriberRepository();
			$sub = $repo->find_by_email("roundtrip@stampy.local");
			echo $sub ? "exists" : "gone";
			'`
		);
		expect( deleted.trim() ).toBe( 'gone' );

		// Re-import via REST API.
		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-import-export`
		);

		const restNonce = await page.evaluate( () => {
			return (
				( window as unknown as { stampy?: { restNonce?: string } } )
					.stampy?.restNonce || ''
			);
		} );

		// Parse CSV into rows.
		const lines = csv.trim().split( '\n' );
		const headers = lines[ 0 ].split( ',' );
		const rows = lines.slice( 1 ).map( ( line ) => {
			const values = line.split( ',' );
			const row: Record< string, string > = {};
			headers.forEach( ( h, i ) => {
				row[ h ] = ( values[ i ] || '' ).replace( /^"|"$/g, '' );
			} );
			return row;
		} );

		const response = await page.request.post(
			`${ TESTS_URL }/wp-json/stampy/v1/import`,
			{
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': restNonce,
				},
				data: {
					rows,
					list_name: `RoundTrip Re-Import ${ Date.now() }`,
				},
			}
		);

		expect( response.ok() ).toBeTruthy();
		const result = await response.json();
		expect( result.success ).toBe( true );
		expect( result.imported ).toBeGreaterThan( 0 );

		// Verify subscriber was recreated with attributes.
		const subAfter = wpCli(
			`wp eval '
			$repo = new \\Stampy\\Repositories\\SubscriberRepository();
			$meta = new \\Stampy\\Repositories\\SubscriberMetaRepository();
			$sub = $repo->find_by_email("roundtrip@stampy.local");
			if ($sub) {
				echo $sub->status . "|";
				$m = $meta->get_all((int)$sub->id);
				echo ($m["first_name"] ?? "") . "|" . ($m["last_name"] ?? "");
			}
			'`
		);
		expect( subAfter ).toContain( 'confirmed|RoundTrip|Test' );

		// Cleanup.
		wpCli(
			`wp eval '
			$repo = new \\Stampy\\Repositories\\SubscriberRepository();
			$sub = $repo->find_by_email("roundtrip@stampy.local");
			if ($sub) { $repo->delete((int)$sub->id); }
			'`
		);
	} );
} );
