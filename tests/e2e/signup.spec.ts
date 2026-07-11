/**
 * E2E test: full signup → confirm journey.
 *
 * Tests the complete double opt-in flow:
 * 1. POST signup via REST API
 * 2. Check Mailpit for the confirmation email
 * 3. Extract and hit the confirmation link
 * 4. Verify the subscriber is confirmed
 */

import { test, expect, request } from '@playwright/test';

const TESTS_URL = 'http://localhost:8889';
const MAILPIT_API = 'http://localhost:8026/api/v1';

function randomEmail(): string {
	const rand = Math.random().toString( 36 ).slice( 2, 10 );
	return `e2e-${ rand }@stampy-test.example`;
}

function getListId(): number {
	const id = parseInt( process.env.STAMPY_E2E_LIST_ID || '0', 10 );
	if ( ! id ) {
		throw new Error(
			'STAMPY_E2E_LIST_ID not set. Run global setup first.'
		);
	}
	return id;
}

async function clearMailpit(): Promise< void > {
	const ctx = await request.newContext();
	await ctx.delete( `${ MAILPIT_API }/messages` );
	await ctx.dispose();
}

async function waitForEmail(
	toAddress: string,
	timeout = 10_000
): Promise< { subject: string; body: string } > {
	const startTime = Date.now();

	while ( Date.now() - startTime < timeout ) {
		const ctx = await request.newContext();
		const response = await ctx.get(
			`${ MAILPIT_API }/search?query=${ encodeURIComponent(
				'to:' + toAddress
			) }`
		);
		const body = await response.json();

		if ( body.messages && body.messages.length > 0 ) {
			const messageId = body.messages[ 0 ].ID;

			const msgResponse = await ctx.get(
				`${ MAILPIT_API }/message/${ messageId }`
			);
			const msgBody = await msgResponse.json();

			await ctx.dispose();

			return {
				subject: msgBody.Subject || msgBody.subject || '',
				body:
					msgBody.Text ||
					msgBody.text ||
					msgBody.HTML ||
					msgBody.html ||
					'',
			};
		}

		await ctx.dispose();
		await new Promise( ( resolve ) => setTimeout( resolve, 500 ) );
	}

	throw new Error(
		`No email received for ${ toAddress } within ${ timeout }ms`
	);
}

function extractConfirmUrl( emailBody: string ): string {
	const match = emailBody.match(
		/https?:\/\/[^\s"'<>]*stampy_confirm=[^\s"'<>]*/i
	);
	if ( ! match ) {
		throw new Error( 'No confirmation link found in email body' );
	}
	return match[ 0 ];
}

test.describe( 'Signup → Confirm flow', () => {
	test( 'full double opt-in signup journey', async () => {
		await clearMailpit();

		const listId = getListId();
		const email = randomEmail();

		const apiContext = await request.newContext( {
			baseURL: TESTS_URL,
		} );

		const signupResponse = await apiContext.post(
			'/?rest_route=/stampy/v1/signup',
			{
				data: {
					email,
					fields: {
						first_name: 'E2E',
						last_name: 'Tester',
					},
					consent: true,
					list_ids: [ listId ],
				},
				headers: {
					'Content-Type': 'application/json',
				},
			}
		);

		expect( signupResponse.ok() ).toBeTruthy();

		const signupResult = await signupResponse.json();
		expect( signupResult.success ).toBe( true );
		expect( signupResult.message ).toContain( 'confirm' );

		const emailData = await waitForEmail( email, 15_000 );
		expect( emailData.subject ).toContain( 'confirm' );

		const confirmUrl = extractConfirmUrl( emailData.body );
		expect( confirmUrl ).toBeTruthy();

		const confirmResponse = await apiContext.get( confirmUrl );
		expect( confirmResponse.ok() ).toBeTruthy();

		const confirmBody = await confirmResponse.text();
		expect( confirmBody.toLowerCase() ).toContain( 'confirmed' );

		await apiContext.dispose();
	} );

	test( 'signup without consent fails', async () => {
		const listId = getListId();
		const email = randomEmail();

		const apiContext = await request.newContext( {
			baseURL: TESTS_URL,
		} );

		const signupResponse = await apiContext.post(
			'/?rest_route=/stampy/v1/signup',
			{
				data: {
					email,
					consent: false,
					list_ids: [ listId ],
				},
				headers: {
					'Content-Type': 'application/json',
				},
			}
		);

		const result = await signupResponse.json();
		expect( result.success ).toBe( false );

		await apiContext.dispose();
	} );

	test( 'signup without list_ids fails', async () => {
		const email = randomEmail();

		const apiContext = await request.newContext( {
			baseURL: TESTS_URL,
		} );

		const signupResponse = await apiContext.post(
			'/?rest_route=/stampy/v1/signup',
			{
				data: {
					email,
					consent: true,
					list_ids: [],
				},
				headers: {
					'Content-Type': 'application/json',
				},
			}
		);

		const result = await signupResponse.json();
		expect( result.success ).toBe( false );

		await apiContext.dispose();
	} );
} );
