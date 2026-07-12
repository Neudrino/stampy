/**
 * E2E test: open/click tracking.
 *
 * Tests that when tracking is enabled:
 * 1. Campaign emails contain the tracking pixel
 * 2. Campaign email links are rewritten with click-tracking
 * 3. Opening the pixel URL records an open
 * 4. Clicking a rewritten link records a click
 */

import { test, expect, request } from '@playwright/test';
import { execSync } from 'child_process';

const MAILPIT_TESTS_API = 'http://localhost:8026/api/v1';
const MAILPIT_DEV_API = 'http://localhost:8025/api/v1';

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

async function waitForCampaignEmail(
	subjectContains: string,
	timeout = 30_000
): Promise< any > {
	const startTime = Date.now();
	const apis = [ MAILPIT_TESTS_API, MAILPIT_DEV_API ];

	while ( Date.now() - startTime < timeout ) {
		for ( const api of apis ) {
			const ctx = await request.newContext();
			const response = await ctx.get(
				`${ api }/search?query=${ encodeURIComponent(
					'subject:' + subjectContains
				) }`
			);
			const body = await response.json();

			if ( body.messages && body.messages.length > 0 ) {
				const msg = body.messages[ 0 ];
				const detailResponse = await ctx.get(
					`${ api }/message/${ msg.ID }`
				);
				const detail = await detailResponse.json();
				await ctx.dispose();
				return detail;
			}

			await ctx.dispose();
		}

		await new Promise( ( resolve ) => setTimeout( resolve, 500 ) );
	}

	throw new Error(
		`No email with subject containing "${ subjectContains }" received within ${ timeout }ms`
	);
}

test.describe.serial( 'Tracking', () => {
	test( 'tracking pixel and click redirect are injected and record events', async () => {
		test.setTimeout( 120_000 );
		const subject = `Tracking E2E ${ Date.now() }`;

		// Ensure SMTP is NOT configured so the dev mu-plugin routes mail
		// to the tests Mailpit.
		wpCli(
			`wp eval '
			delete_option( "stampy_smtp_configured" );
			delete_option( "stampy_smtp_settings" );
			'`
		);

		// Enable tracking globally.
		wpCli( `wp eval 'update_option( "stampy_tracking_enabled", "1" );'` );

		// Create a campaign with a link.
		const listId = process.env.STAMPY_E2E_LIST_ID || '1';
		const escapedSubject = subject.replace( /'/g, `\\'` );
		const createOutput = wpCli(
			`wp eval '
			$post_id = wp_insert_post( array(
				"post_type" => "stampy_campaign",
				"post_title" => "Tracking E2E",
				"post_content" => "<!-- wp:paragraph --><p>Hello {first_name}! <a href=\\"https://example.com/target\\">Click here</a></p><!-- /wp:paragraph -->",
				"post_status" => "publish",
			) );
			update_post_meta( $post_id, "stampy_campaign_subject", "${ escapedSubject }" );
			update_post_meta( $post_id, "stampy_campaign_list_ids", "[${ listId }]" );
			update_post_meta( $post_id, "stampy_campaign_status", "draft" );
			echo $post_id;
			'`
		);

		const campaignId = parseInt( createOutput.trim(), 10 );
		expect( campaignId ).toBeGreaterThan( 0 );

		// Start the send and run synchronously.
		wpCli(
			`wp eval '
			$engine = new Stampy\\Campaigns\\SendingEngine();
			$result = $engine->start_send( ${ campaignId } );
			if ( ! $result["success"] ) {
				WP_CLI::error( $result["message"] );
			}
			$engine->run_synchronous( ${ campaignId } );
			'`
		);

		// Wait for the campaign email to arrive.
		const detail = await waitForCampaignEmail( subject, 30_000 );

		const msgSubject = detail.Subject || detail.subject || '';
		const msgBody =
			detail.HTML || detail.html || detail.Text || detail.text || '';

		expect( msgSubject ).toContain( subject );

		// Verify the email body has tracking elements.
		expect( msgBody ).toContain( 'stampy_trk_r' );
		expect( msgBody ).toContain( 'stampy_clk_r' );

		// Extract the pixel URL.
		const pixelMatch = msgBody.match(
			/<img[^>]+src="(http[^"]*stampy_trk_r=[^"]+)"/i
		);
		expect( pixelMatch ).not.toBeNull();
		const pixelUrl = pixelMatch![ 1 ]
			.replace( /&amp;/g, '&' )
			.replace( /&#038;/g, '&' );

		// Extract the click URL.
		const clickMatch = msgBody.match(
			/href="(http[^"]*stampy_clk_r=[^"]+)"/i
		);
		expect( clickMatch ).not.toBeNull();
		const clickUrl = clickMatch![ 1 ]
			.replace( /&amp;/g, '&' )
			.replace( /&#038;/g, '&' );

		// Hit the pixel URL to record an open.
		const pixelCtx = await request.newContext();
		const pixelResponse = await pixelCtx.get( pixelUrl );
		expect( pixelResponse.status() ).toBe( 200 );
		expect( pixelResponse.headers()[ 'content-type' ] ).toContain(
			'image/gif'
		);
		await pixelCtx.dispose();

		// Hit the click URL to record a click (should redirect to the original URL).
		const clickCtx = await request.newContext();
		const clickResponse = await clickCtx.get( clickUrl, {
			maxRedirects: 0,
		} );
		// Expect a 302 redirect (or the destination).
		expect( [ 200, 302 ] ).toContain( clickResponse.status() );
		await clickCtx.dispose();

		// Verify stats were recorded in the DB.
		const statsOutput = wpCli(
			`wp eval '
			$repo = new Stampy\\Repositories\\CampaignRecipientRepository();
			$stats = $repo->get_stats( ${ campaignId } );
			echo json_encode( $stats );
			'`
		);

		const stats = JSON.parse( statsOutput.trim() );
		expect( stats.opens ).toBeGreaterThanOrEqual( 1 );
		expect( stats.clicks ).toBeGreaterThanOrEqual( 1 );
		expect( stats.total_clicks ).toBeGreaterThanOrEqual( 1 );

		// Clean up: disable tracking.
		wpCli( `wp eval 'delete_option( "stampy_tracking_enabled" );'` );
	} );
} );
