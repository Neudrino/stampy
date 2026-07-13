/**
 * E2E test: campaign admin UI — row actions + sidebar send button.
 *
 * Tests that:
 * 1. The campaign list table shows a "Send" row action for draft campaigns.
 * 2. Clicking "Send Campaign" in the PluginSidebar starts the send (AJAX).
 * 3. The campaign status changes to "sending" after clicking.
 * 4. A "Cancel Send" row action appears for sending campaigns.
 */

import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

const TESTS_URL = 'http://localhost:8889';

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

test.describe.serial( 'Campaign admin UI', () => {
	let campaignId: number;

	test.beforeAll( () => {
		const listId = process.env.STAMPY_E2E_LIST_ID || '1';

		wpCli(
			`wp eval 'delete_option( "stampy_smtp_configured" ); delete_option( "stampy_smtp_settings" );'`
		);

		const output = wpCli(
			`wp eval '
			$post_id = wp_insert_post( array(
				"post_type" => "stampy_campaign",
				"post_title" => "E2E UI Test Campaign",
				"post_content" => "<!-- wp:paragraph --><p>Hello {field:first_name}!</p><!-- /wp:paragraph -->",
				"post_status" => "publish",
			) );
			update_post_meta( $post_id, "stampy_campaign_subject", "E2E UI Test" );
			update_post_meta( $post_id, "stampy_campaign_list_ids", "[${ listId }]" );
			update_post_meta( $post_id, "stampy_campaign_status", "draft" );
			echo $post_id;
			'`
		);

		campaignId = parseInt( output.trim(), 10 );
		expect( campaignId ).toBeGreaterThan( 0 );
	} );

	test.afterAll( () => {
		if ( campaignId ) {
			wpCli( `wp post delete ${ campaignId } --force` );
		}
	} );

	test( 'campaign list table shows Send row action for draft campaigns', async ( {
		page,
	} ) => {
		await adminLogin( page );

		await page.goto(
			`${ TESTS_URL }/wp-admin/edit.php?post_type=stampy_campaign`
		);

		await expect( page.locator( '.wp-list-table' ) ).toBeVisible();

		const row = page.locator( '.wp-list-table tbody tr' ).filter( {
			hasText: 'E2E UI Test Campaign',
		} );

		await expect( row ).toBeVisible();
		await row.hover();

		const rowActions = row.locator( '.row-actions' );

		await expect( rowActions ).toContainText( 'Send' );

		const sendLink = rowActions.locator( 'a', { hasText: 'Send' } );
		await expect( sendLink ).toBeVisible();

		const href = await sendLink.getAttribute( 'href' );
		expect( href ).toContain( 'admin-post.php' );
		expect( href ).toContain( 'action=stampy_start_send' );
		expect( href ).toContain( `post_id=${ campaignId }` );
	} );

	test( 'sidebar Send button starts the campaign send via AJAX', async ( {
		page,
	} ) => {
		await adminLogin( page );

		await page.goto(
			`${ TESTS_URL }/wp-admin/post.php?post=${ campaignId }&action=edit`
		);

		// Dismiss the block editor welcome modal if present.
		const modal = page.locator( '.components-modal__screen-overlay' );
		if ( await modal.isVisible() ) {
			await page.keyboard.press( 'Escape' );
			await modal.waitFor( { state: 'hidden', timeout: 5000 } );
		}

		// Click the "Campaign Settings" button in the editor top bar
		// to open the PluginSidebar.
		const settingsButton = page.getByRole( 'button', {
			name: 'Campaign Settings',
		} );
		await expect( settingsButton ).toBeVisible( { timeout: 20000 } );
		await settingsButton.click();

		// The Send Campaign button should be in the PluginSidebar.
		const sendButton = page.getByRole( 'button', {
			name: 'Send Campaign',
		} );
		await expect( sendButton ).toBeVisible( { timeout: 10000 } );

		// Click the Send button.
		await sendButton.click();

		// Wait for the status to change to "sending" in the sidebar.
		await expect(
			page.locator( 'text=Status:' ).locator( '..' )
		).toContainText( 'sending', { timeout: 10000 } );

		// Progress info should appear.
		await expect( page.locator( 'text=Total recipients:' ) ).toBeVisible( {
			timeout: 10000,
		} );
	} );

	test( 'campaign list table shows Cancel Send row action for sending campaigns', async ( {
		page,
	} ) => {
		await adminLogin( page );

		await page.goto(
			`${ TESTS_URL }/wp-admin/edit.php?post_type=stampy_campaign`
		);

		const row = page.locator( '.wp-list-table tbody tr' ).filter( {
			hasText: 'E2E UI Test Campaign',
		} );

		await expect( row ).toBeVisible();
		await row.hover();

		const rowActions = row.locator( '.row-actions' );

		await expect( rowActions ).toContainText( 'Cancel Send' );

		const cancelLink = rowActions.locator( 'a', {
			hasText: 'Cancel Send',
		} );
		await expect( cancelLink ).toBeVisible();

		const href = await cancelLink.getAttribute( 'href' );
		expect( href ).toContain( 'admin-post.php' );
		expect( href ).toContain( 'action=stampy_cancel_send' );
		expect( href ).toContain( `post_id=${ campaignId }` );
	} );
} );
