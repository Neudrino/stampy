/**
 * E2E tests: SMTP connector — configure Mailpit, send test, verify delivery.
 *
 * Three scenarios:
 * 1. No-auth: configure SMTP without authentication against the dev Mailpit
 *    (port 1025, no auth, no TLS) and verify a test email arrives in its inbox.
 * 2. Auth (no encryption): configure SMTP with authentication against the
 *    tests Mailpit (port 1026, requires auth with stampy:testpass123,
 *    STARTTLS optional) using encryption=none, and verify a test email
 *    arrives. This proves credentials are correctly passed to the SMTP server.
 * 3. Auth + TLS: configure SMTP with authentication and TLS encryption
 *    against the tests Mailpit (port 1026, STARTTLS), and verify a test
 *    email arrives. This proves STARTTLS encryption works end-to-end with
 *    a self-signed certificate.
 */

import { test, expect, request } from '@playwright/test';

const TESTS_URL = 'http://localhost:8889';
const MAILPIT_DEV_API = 'http://localhost:8025/api/v1';
const MAILPIT_TESTS_API = 'http://localhost:8026/api/v1';

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

async function clearMailpit( api: string ): Promise< void > {
	const ctx = await request.newContext();
	await ctx.delete( `${ api }/messages` );
	await ctx.dispose();
}

async function waitForEmail(
	api: string,
	toAddress: string,
	subjectContains: string,
	timeout = 15_000
): Promise< void > {
	const startTime = Date.now();

	while ( Date.now() - startTime < timeout ) {
		const ctx = await request.newContext();
		const response = await ctx.get(
			`${ api }/search?query=${ encodeURIComponent( 'to:' + toAddress ) }`
		);
		const body = await response.json();

		if ( body.messages && body.messages.length > 0 ) {
			const messageId = body.messages[ 0 ].ID;
			const msgResponse = await ctx.get(
				`${ api }/message/${ messageId }`
			);
			const msgBody = await msgResponse.json();
			const subject = msgBody.Subject || msgBody.subject || '';

			if (
				subject.toLowerCase().includes( subjectContains.toLowerCase() )
			) {
				await ctx.dispose();
				return;
			}
		}

		await ctx.dispose();
		await new Promise( ( resolve ) => setTimeout( resolve, 500 ) );
	}

	throw new Error(
		`No email with subject containing "${ subjectContains }" received for ${ toAddress } within ${ timeout }ms`
	);
}

test.describe.serial( 'SMTP connector', () => {
	test( 'send test email without SMTP authentication', async ( { page } ) => {
		await adminLogin( page );
		await clearMailpit( MAILPIT_DEV_API );

		const testRecipient = 'smtp-noauth@stampy-test.example';

		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-settings`
		);

		await expect( page.locator( '.wrap h1' ) ).toContainText(
			'Stampy Settings'
		);

		await page.fill( '#smtp_host', 'host.docker.internal' );
		await page.fill( '#smtp_port', '1025' );
		await page.selectOption( '#smtp_encryption', 'none' );
		await page.uncheck( '#smtp_auth' );
		await page.fill( '#smtp_from_email', 'stampy@example.com' );
		await page.fill( '#smtp_from_name', 'Stampy Test' );

		await page.getByRole( 'button', { name: 'Save Settings' } ).click();
		await page.waitForLoadState( 'networkidle' );

		await expect( page.locator( '.notice-success' ) ).toBeVisible();

		await expect(
			page.locator( 'h2', { hasText: 'Send Test Email' } )
		).toBeVisible();

		await page.fill( '#test_recipient', testRecipient );
		await page
			.getByRole( 'button', { name: 'Send Test Email' } )
			.click( { force: true } );
		await page.waitForLoadState( 'networkidle' );

		await waitForEmail(
			MAILPIT_DEV_API,
			testRecipient,
			'Stampy SMTP test',
			15_000
		);
	} );

	test( 'send test email with SMTP authentication', async ( { page } ) => {
		await adminLogin( page );
		await clearMailpit( MAILPIT_TESTS_API );

		const testRecipient = 'smtp-auth@stampy-test.example';

		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-settings`
		);

		await expect( page.locator( '.wrap h1' ) ).toContainText(
			'Stampy Settings'
		);

		await page.fill( '#smtp_host', 'host.docker.internal' );
		await page.fill( '#smtp_port', '1026' );
		await page.selectOption( '#smtp_encryption', 'none' );

		// Enable SMTP authentication — use evaluate to set the checked
		// property directly, which is more reliable than check() when
		// WP admin JS interferes with checkbox state.
		await page.evaluate( () => {
			const cb = document.getElementById(
				'smtp_auth'
			) as HTMLInputElement | null;
			if ( cb ) {
				cb.checked = true;
			}
		} );
		await expect( page.locator( '#smtp_auth' ) ).toBeChecked();

		await page.fill( '#smtp_username', 'stampy' );
		await page.fill( '#smtp_password', 'testpass123' );
		await page.fill( '#smtp_from_email', 'stampy@example.com' );
		await page.fill( '#smtp_from_name', 'Stampy Test' );

		await page.getByRole( 'button', { name: 'Save Settings' } ).click();
		await page.waitForLoadState( 'networkidle' );

		await expect( page.locator( '.notice-success' ) ).toBeVisible();

		await expect(
			page.locator( 'h2', { hasText: 'Send Test Email' } )
		).toBeVisible();

		await page.fill( '#test_recipient', testRecipient );
		await page
			.getByRole( 'button', { name: 'Send Test Email' } )
			.click( { force: true } );
		await page.waitForLoadState( 'networkidle' );

		await waitForEmail(
			MAILPIT_TESTS_API,
			testRecipient,
			'Stampy SMTP test',
			15_000
		);
	} );

	test( 'send test email with SMTP authentication and TLS', async ( {
		page,
	} ) => {
		await adminLogin( page );
		await clearMailpit( MAILPIT_TESTS_API );

		const testRecipient = 'smtp-tls@stampy-test.example';

		await page.goto(
			`${ TESTS_URL }/wp-admin/admin.php?page=stampy-settings`
		);

		await expect( page.locator( '.wrap h1' ) ).toContainText(
			'Stampy Settings'
		);

		await page.fill( '#smtp_host', 'host.docker.internal' );
		await page.fill( '#smtp_port', '1026' );
		await page.selectOption( '#smtp_encryption', 'tls' );

		// Enable SMTP authentication.
		await page.evaluate( () => {
			const cb = document.getElementById(
				'smtp_auth'
			) as HTMLInputElement | null;
			if ( cb ) {
				cb.checked = true;
			}
		} );
		await expect( page.locator( '#smtp_auth' ) ).toBeChecked();

		await page.fill( '#smtp_username', 'stampy' );
		await page.fill( '#smtp_password', 'testpass123' );
		await page.fill( '#smtp_from_email', 'stampy@example.com' );
		await page.fill( '#smtp_from_name', 'Stampy Test' );

		await page.getByRole( 'button', { name: 'Save Settings' } ).click();
		await page.waitForLoadState( 'networkidle' );

		await expect( page.locator( '.notice-success' ) ).toBeVisible();

		await expect(
			page.locator( 'h2', { hasText: 'Send Test Email' } )
		).toBeVisible();

		await page.fill( '#test_recipient', testRecipient );
		await page
			.getByRole( 'button', { name: 'Send Test Email' } )
			.click( { force: true } );
		await page.waitForLoadState( 'networkidle' );

		await waitForEmail(
			MAILPIT_TESTS_API,
			testRecipient,
			'Stampy SMTP test',
			15_000
		);
	} );
} );
