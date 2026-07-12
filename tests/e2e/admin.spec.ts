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
	// Storage state from globalSetup should already have us logged in.
	// Navigate to an admin page to verify; if redirected to login, log in.
	await page.goto( `${ TESTS_URL }/wp-admin/` );
	if ( page.url().includes( 'wp-login.php' ) ) {
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForSelector( '#wpadminbar', { timeout: 30000 } );
		await page.waitForLoadState( 'domcontentloaded' );
	}
}

test.describe( 'Admin subscribers/lists management', () => {
	test( 'subscribers page shows table with data rows', async ( { page } ) => {
		await adminLogin( page );

		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-subscribers`
		);

		await expect( page.locator( '.wrap h1' ) ).toContainText(
			'Subscribers'
		);
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

		await expect( page.locator( '.wrap h1' ) ).toContainText( 'Lists' );
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

		await expect( page.locator( '.wrap h1' ) ).toContainText(
			'Add New List'
		);

		await page.fill( '#list_name', 'E2E Created List' );
		await page.fill( '#list_slug', 'e2e-created' );
		await page.fill( '#list_description', 'Created via E2E test' );
		await page.click( 'input[type="submit"]' );

		await page.waitForLoadState( 'networkidle' );

		// Should redirect to the list overview.
		await expect( page.locator( '.wrap h1' ) ).toContainText( 'Lists' );
		await expect( page.locator( '.wp-list-table' ) ).toBeVisible();
		await expect( page.locator( '.wp-list-table' ) ).toContainText(
			'E2E Created List'
		);
	} );

	test.describe.serial( 'bulk actions on subscribers', () => {
		test( 'bulk set confirmed changes subscriber status', async ( {
			page,
		} ) => {
			await adminLogin( page );

			// Create a subscriber via REST API.
			const signupRes = await page.request.post(
				`${ TESTS_URL }/?rest_route=/stampy/v1/signup`,
				{
					headers: { 'Content-Type': 'application/json' },
					data: {
						email: 'bulk-e2e@example.com',
						fields: {
							first_name: 'Bulk',
							last_name: 'Tester',
						},
						consent: true,
						list_ids: [ 1 ],
					},
				}
			);
			expect( signupRes.ok() ).toBeTruthy();

			// Go to subscribers page.
			await page.goto(
				`${ TESTS_URL }/wp-admin/admin.php?page=stampy-subscribers`
			);

			// Search for our subscriber.
			await page.fill(
				'#subscriber-search-input',
				'bulk-e2e@example.com'
			);
			await page.click( '#search-submit' );
			await page.waitForLoadState( 'networkidle' );

			// Check the checkbox for the first (and only) row.
			// page.check() can silently fail on WP admin checkboxes — use evaluate + click.
			await page.evaluate( () => {
				const cb = document.querySelector(
					'.wp-list-table tbody tr:first-child input[type="checkbox"]'
				) as HTMLInputElement;
				if ( cb ) {
					cb.checked = true;
					cb.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				}
			} );
			await expect(
				page.locator(
					'.wp-list-table tbody tr:first-child input[type="checkbox"]'
				)
			).toBeChecked();

			// Select "Set Confirmed" from the bulk action dropdown.
			await page.selectOption( 'select[name="action"]', 'set_confirmed' );

			// Apply the bulk action.
			await page.click( '#doaction', { force: true } );
			await page.waitForLoadState( 'domcontentloaded', {
				timeout: 20000,
			} );
			await page.waitForSelector( '#wpadminbar', { timeout: 20000 } );

			// Verify we're on the subscribers page with bulk-done flag.
			expect( page.url() ).toContain( 'stampy-subscribers' );
			expect( page.url() ).toContain( 'stampy_bulk_done' );

			// A success notice should appear.
			await expect( page.locator( '.notice-success' ) ).toBeVisible( {
				timeout: 10000,
			} );

			// Wait for the search input.
			await page.waitForSelector( '#subscriber-search-input', {
				timeout: 10000,
			} );

			// Verify the subscriber status is now "Confirmed".
			await page.fill(
				'#subscriber-search-input',
				'bulk-e2e@example.com'
			);
			await page.click( '#search-submit', { force: true } );
			await page.waitForLoadState( 'networkidle' );

			const statusCell = page.locator(
				'.wp-list-table tbody tr:first-child td.column-status'
			);
			await expect( statusCell ).toContainText( 'Confirmed' );
		} );

		test( 'bulk set unsubscribed changes subscriber status', async ( {
			page,
		} ) => {
			await adminLogin( page );

			await page.goto(
				`${ TESTS_URL }/wp-admin/admin.php?page=stampy-subscribers`
			);

			// Search for the subscriber created in the previous test.
			await page.fill(
				'#subscriber-search-input',
				'bulk-e2e@example.com'
			);
			await page.click( '#search-submit' );
			await page.waitForLoadState( 'networkidle' );

			// Check the checkbox.
			// page.check() can silently fail on WP admin checkboxes — use evaluate + click.
			await page.evaluate( () => {
				const cb = document.querySelector(
					'.wp-list-table tbody tr:first-child input[type="checkbox"]'
				) as HTMLInputElement;
				if ( cb ) {
					cb.checked = true;
					cb.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				}
			} );
			await expect(
				page.locator(
					'.wp-list-table tbody tr:first-child input[type="checkbox"]'
				)
			).toBeChecked();

			// Select "Set Unsubscribed" from the bulk action dropdown.
			await page.selectOption(
				'select[name="action"]',
				'set_unsubscribed'
			);

			// Apply.
			await Promise.all( [
				page.waitForNavigation( { timeout: 20000 } ),
				page.click( '#doaction', { force: true } ),
			] );

			// Verify the subscriber status is now "Unsubscribed".
			await page.fill(
				'#subscriber-search-input',
				'bulk-e2e@example.com'
			);
			await page.click( '#search-submit', { force: true } );
			await page.waitForLoadState( 'networkidle' );

			const statusCell = page.locator(
				'.wp-list-table tbody tr:first-child td.column-status'
			);
			await expect( statusCell ).toContainText( 'Unsubscribed' );
		} );

		test( 'first name and last name columns are visible', async ( {
			page,
		} ) => {
			await adminLogin( page );

			await page.goto(
				`${ TESTS_URL }/wp-admin/admin.php?page=stampy-subscribers`
			);

			// The column headers should include "First Name" and "Last Name".
			await expect(
				page.locator( '.wp-list-table thead tr th.column-first_name' )
			).toBeVisible();
			await expect(
				page.locator( '.wp-list-table thead tr th.column-last_name' )
			).toBeVisible();

			// The columns should appear after email and before status.
			const headers = await page.locator( '.wp-list-table thead tr th' );
			const headerTexts = await headers.allTextContents();
			const emailIdx = headerTexts.findIndex( ( h ) =>
				h.includes( 'Email' )
			);
			const firstNameIdx = headerTexts.findIndex( ( h ) =>
				h.includes( 'First Name' )
			);
			const lastNameIdx = headerTexts.findIndex( ( h ) =>
				h.includes( 'Last Name' )
			);
			const statusIdx = headerTexts.findIndex( ( h ) =>
				h.includes( 'Status' )
			);

			expect( firstNameIdx ).toBeGreaterThan( emailIdx );
			expect( lastNameIdx ).toBeGreaterThan( firstNameIdx );
			expect( statusIdx ).toBeGreaterThan( lastNameIdx );
		} );
	} );
} );
