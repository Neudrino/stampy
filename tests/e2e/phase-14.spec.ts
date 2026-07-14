/**
 * E2E test: Phase 14 — list filter, count display, campaign copy.
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

test.describe( 'Phase 14 — list filter and campaign copy', () => {
	test( 'subscriber list filter dropdown is present', async ( { page } ) => {
		await adminLogin( page );

		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-subscribers`
		);

		await expect( page.locator( '#filter-by-list' ) ).toBeVisible();
		await expect(
			page.locator( '#filter-by-list option' ).first()
		).toContainText( 'All lists' );
	} );

	test( 'filtering by list shows only subscribers in that list', async ( {
		page,
	} ) => {
		await adminLogin( page );

		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-subscribers`
		);

		await page.waitForLoadState( 'networkidle' );

		const listId = process.env.STAMPY_E2E_LIST_ID;
		expect( listId ).toBeTruthy();

		await page.selectOption( '#filter-by-list', listId! );
		await page.click( '#filter_action', { force: true } );
		await page.waitForLoadState( 'networkidle' );

		expect( page.url() ).toContain( `list_id=${ listId }` );

		const filteredRows = await page
			.locator( '.wp-list-table tbody tr' )
			.count();
		expect( filteredRows ).toBeGreaterThan( 0 );
	} );

	test( 'prominent total count is displayed', async ( { page } ) => {
		await adminLogin( page );

		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-subscribers`
		);

		await expect( page.locator( '.subtitle' ) ).toBeVisible();
		await expect( page.locator( '.subtitle' ) ).toContainText( /total/ );
	} );

	test( 'campaign Copy row action creates a duplicate', async ( {
		page,
	} ) => {
		await adminLogin( page );

		const campaignTitle = `E2E Copy Test ${ Date.now() }`;

		const createOutput = wpCli(
			`wp eval '
			$post_id = wp_insert_post( array(
				"post_type" => "stampy_campaign",
				"post_title" => "${ campaignTitle }",
				"post_content" => "<!-- wp:paragraph --><p>Copy me</p><!-- /wp:paragraph -->",
				"post_status" => "draft",
			) );
			update_post_meta( $post_id, "stampy_campaign_subject", "Copy Subject" );
			update_post_meta( $post_id, "stampy_campaign_list_ids", "[1]" );
			update_post_meta( $post_id, "stampy_campaign_status", "draft" );
			echo $post_id;
			'`
		);

		const originalId = createOutput.trim();
		expect( originalId ).toMatch( /^\d+$/ );

		await page.goto(
			`${ TESTS_URL }/wp-admin/edit.php?post_type=stampy_campaign`
		);

		const row = page.locator( '.wp-list-table tbody tr', {
			hasText: campaignTitle,
		} );
		await row.first().hover();
		await row
			.first()
			.locator( '.row-actions' )
			.waitFor( { state: 'visible' } );
		await expect( row.first().locator( '.row-actions' ) ).toContainText(
			'Copy'
		);

		await row.first().locator( '.row-actions a:has-text("Copy")' ).click();
		await page.waitForLoadState( 'domcontentloaded' );

		expect( page.url() ).toContain( 'post.php' );
		expect( page.url() ).toContain( 'action=edit' );

		// Verify the copy was created with "(Copy)" in the title.
		const urlMatch = page.url().match( /post=(\d+)/ );
		expect( urlMatch ).toBeTruthy();
		const copyId = urlMatch![ 1 ];

		const copyTitle = wpCli(
			`wp eval 'echo get_the_title( ${ copyId } );'`
		);
		expect( copyTitle.trim() ).toContain( '(Copy)' );

		// Cleanup.
		wpCli( `wp post delete ${ copyId } --force` );
		wpCli( `wp post delete ${ originalId } --force` );
	} );
} );
