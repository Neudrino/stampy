/**
 * E2E test: Phase 16 — Submission Log.
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

/**
 * Perform a signup via the SignupService (server-side), which logs a
 * 'pending' submission when the submission log is enabled. Returns the
 * created subscriber ID.
 *
 * @param email Subscriber email.
 * @param first First name attribute.
 */
function signup( email: string, first: string ): string {
	return wpCli(
		`wp eval '
		$list = $GLOBALS["wpdb"]->get_row( "SELECT * FROM {$GLOBALS["wpdb"]->prefix}stampy_lists WHERE slug = \\"e2e-test\\"" );
		$svc = new \\Stampy\\SignupService();
		$svc->signup( array(
			"email"    => "${ email }",
			"consent"  => true,
			"list_ids" => array( (int) $list->id ),
			"fields"   => array( "first_name" => "${ first }" ),
		) );
		$repo = new \\Stampy\\Repositories\\SubscriberRepository();
		$sub = $repo->find_by_email( "${ email }" );
		echo $sub ? $sub->id : 0;
		'`
	).trim();
}

function deleteSubscriber( email: string ): void {
	wpCli(
		`wp eval '
		$repo = new \\Stampy\\Repositories\\SubscriberRepository();
		$sub = $repo->find_by_email( "${ email }" );
		if ( $sub ) { $repo->delete( (int) $sub->id ); }
		'`
	);
}

function logCountForEmail( email: string ): number {
	const out = wpCli(
		`wp eval '
		$repo = new \\Stampy\\Repositories\\SubmissionLogRepository();
		echo count( $repo->find_by_email( "${ email }" ) );
		'`
	);
	return parseInt( out.trim(), 10 );
}

test.describe( 'Phase 16 — Submission Log', () => {
	test( 'submission log page is accessible', async ( { page } ) => {
		await adminLogin( page );
		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-submission-log`
		);
		await expect( page.locator( '.wrap h1' ) ).toContainText(
			'Submission Log'
		);
	} );

	test( 'signup creates a log entry visible in the viewer', async ( {
		page,
	} ) => {
		await adminLogin( page );

		const email = `log-e2e-${ Date.now() }@stampy.local`;
		signup( email, 'LogE2E' );

		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-submission-log`
		);

		// The entry should appear in the table.
		await expect( page.locator( '.wp-list-table' ) ).toContainText( email );
		// The form data (first_name) should be rendered.
		await expect( page.locator( '.wp-list-table' ) ).toContainText(
			'LogE2E'
		);

		// Cleanup.
		deleteSubscriber( email );
	} );

	test( 'search by email filters the log', async ( { page } ) => {
		await adminLogin( page );

		const unique = Date.now();
		const emailA = `search-a-${ unique }@stampy.local`;
		const emailB = `search-b-${ unique }@stampy.local`;
		signup( emailA, 'SearchA' );
		signup( emailB, 'SearchB' );

		// Search for emailA only.
		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-submission-log&s=${ encodeURIComponent(
				emailA
			) }`
		);

		await expect( page.locator( '.wp-list-table' ) ).toContainText(
			emailA
		);
		await expect( page.locator( '.wp-list-table' ) ).not.toContainText(
			emailB
		);

		// Cleanup.
		deleteSubscriber( emailA );
		deleteSubscriber( emailB );
	} );

	test( 'deleting a subscriber removes their log entries', async ( {
		page,
	} ) => {
		await adminLogin( page );

		const email = `cascade-e2e-${ Date.now() }@stampy.local`;
		signup( email, 'CascadeE2E' );

		// Log entry exists.
		expect( logCountForEmail( email ) ).toBeGreaterThan( 0 );

		// Delete the subscriber.
		deleteSubscriber( email );

		// Log entries should be cascade-deleted.
		expect( logCountForEmail( email ) ).toBe( 0 );

		// Viewer should no longer show the email.
		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-submission-log&s=${ encodeURIComponent(
				email
			) }`
		);
		await expect( page.locator( '.wrap' ) ).not.toContainText( email );
	} );
} );
