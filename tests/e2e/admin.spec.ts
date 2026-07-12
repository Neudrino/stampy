/**
 * E2E test: admin subscribers/lists management.
 *
 * Tests the admin UI for browsing subscribers and lists, changing
 * subscriber status, and managing list memberships.
 *
 * Uses the tests instance (:8889) which has seeded data from
 * the globalSetup (1 list + 10 subscribers).
 */

import { test, expect } from '@playwright/test';

const TESTS_URL = 'http://localhost:8889';

async function adminLogin(
	page: import('@playwright/test').Page
): Promise< void > {
	await page.goto( `${ TESTS_URL }/wp-login.php` );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await Promise.all( [
		page.waitForNavigation( { timeout: 20000 } ),
		page.click( '#wp-submit' ),
	] );
	// Wait until we're actually in the admin (wpadminbar appears on all admin pages).
	await page.waitForSelector( '#wpadminbar', { timeout: 20000 } );
}

test.describe( 'Admin subscribers/lists management', () => {
	test( 'subscribers page shows table with data rows', async ( { page } ) => {
		await adminLogin( page );

		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-subscribers`
		);

		await expect( page.locator( 'h1' ) ).toContainText( 'Subscribers' );
		await expect( page.locator( '.wp-list-table' ) ).toBeVisible();

		// The table must contain at least one data row with an email.
		const rows = page.locator( '.wp-list-table tbody tr' );
		const rowCount = await rows.count();
		expect( rowCount ).toBeGreaterThan( 0 );

		// Verify the first row has a non-empty email cell.
		const firstEmail = page
			.locator( '.wp-list-table tbody tr td.column-email' )
			.first();
		await expect( firstEmail ).not.toBeEmpty();
	} );

	test( 'lists page shows table with data rows', async ( { page } ) => {
		await adminLogin( page );

		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-lists`
		);

		await expect( page.locator( 'h1' ) ).toContainText( 'Lists' );
		await expect( page.locator( '.wp-list-table' ) ).toBeVisible();

		// The table must contain at least one data row with a list name.
		const rows = page.locator( '.wp-list-table tbody tr' );
		const rowCount = await rows.count();
		expect( rowCount ).toBeGreaterThan( 0 );

		// Verify the first row has a non-empty name cell.
		const firstName = page
			.locator( '.wp-list-table tbody tr td.column-name' )
			.first();
		await expect( firstName ).not.toBeEmpty();
	} );

	test( 'lists page allows creating a new list', async ( { page } ) => {
		await adminLogin( page );

		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-lists&action=new`
		);

		await expect( page.locator( 'h1' ) ).toContainText( 'Add New List' );

		await page.fill( '#list_name', 'E2E Created List' );
		await page.fill( '#list_slug', 'e2e-created' );
		await page.fill( '#list_description', 'Created via E2E test' );
		await page.click( 'input[type="submit"]' );

		await page.waitForLoadState( 'networkidle' );

		// Should redirect to the list overview.
		await expect( page.locator( 'h1' ) ).toContainText( 'Lists' );
		await expect( page.locator( '.wp-list-table' ) ).toBeVisible();
		await expect( page.locator( '.wp-list-table' ) ).toContainText(
			'E2E Created List'
		);
	} );
} );
