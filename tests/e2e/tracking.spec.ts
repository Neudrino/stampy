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

async function clearMailpit(): Promise< void > {
	const ctx = await request.newContext();
	await ctx.delete( `${ MAILPIT_TESTS_API }/messages` );
	await ctx.dispose();
}

async function getMailpitMessages(): Promise< any[] > {
	const ctx = await request.newContext();
	const response = await ctx.get( `${ MAILPIT_TESTS_API }/messages` );
	const body = await response.json();
	await ctx.dispose();
	return body.messages || [];
}

test.describe.serial( 'Tracking', () => {
	test( 'tracking pixel and click redirect are injected and record events', async () => {
		await clearMailpit();

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
		const createOutput = wpCli(
			`wp eval '
			$post_id = wp_insert_post( array(
				"post_type" => "stampy_campaign",
				"post_title" => "Tracking E2E",
				"post_content" => "<!-- wp:paragraph --><p>Hello {first_name}! <a href=\\"https://example.com/target\\">Click here</a></p><!-- /wp:paragraph -->",
				"post_status" => "publish",
			) );
			update_post_meta( $post_id, "stampy_campaign_subject", "Tracking E2E" );
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

		// Wait for emails to arrive.
		await new Promise( ( resolve ) => setTimeout( resolve, 2000 ) );

		const messages = await getMailpitMessages();
		expect( messages.length ).toBeGreaterThan( 0 );

		// Find the campaign email and extract pixel + click URLs.
		const ctx = await request.newContext();
		let pixelUrl = '';
		let clickUrl = '';

		for ( const msg of messages ) {
			const detailResponse = await ctx.get(
				`${ MAILPIT_TESTS_API }/message/${ msg.ID }`
			);
			const detail = await detailResponse.json();

			const msgSubject = detail.Subject || detail.subject || '';
			const msgBody =
				detail.HTML || detail.html || detail.Text || detail.text || '';

			if ( msgSubject.includes( 'Tracking E2E' ) ) {
				// Extract the pixel URL.
				const pixelMatch = msgBody.match(
					/<img[^>]+src="(http[^"]*stampy_trk_r=[^"]+)"/i
				);
				if ( pixelMatch ) {
					pixelUrl = pixelMatch[ 1 ]
						.replace( /&amp;/g, '&' )
						.replace( /&#038;/g, '&' );
				}

				// Extract the click URL.
				const clickMatch = msgBody.match(
					/href="(http[^"]*stampy_clk_r=[^"]+)"/i
				);
				if ( clickMatch ) {
					clickUrl = clickMatch[ 1 ]
						.replace( /&amp;/g, '&' )
						.replace( /&#038;/g, '&' );
				}

				// Verify the email body has tracking elements.
				expect( msgBody ).toContain( 'stampy_trk_r' );
				expect( msgBody ).toContain( 'stampy_clk_r' );
				break;
			}
		}

		await ctx.dispose();

		expect( pixelUrl ).not.toBe( '' );
		expect( clickUrl ).not.toBe( '' );

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
