/**
 * E2E test: campaign send → personalized emails in Mailpit.
 *
 * Tests the full sending pipeline:
 * 1. Configure SMTP settings via WP-CLI (tests Mailpit, port 1026, auth)
 * 2. Create a campaign via WP-CLI with merge tags
 * 3. Start the send and run synchronously via WP-CLI
 * 4. Verify N personalized emails arrive in Mailpit
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

test.describe.serial( 'Campaign send', () => {
	test( 'send campaign delivers personalized emails', async () => {
		await clearMailpit();

		// Ensure SMTP is NOT configured so the dev mu-plugin routes
		// mail to the tests Mailpit (port 1026). This avoids conflicts
		// with the parallel smtp.spec.ts tests.
		wpCli(
			`wp eval '
			delete_option( "stampy_smtp_configured" );
			delete_option( "stampy_smtp_settings" );
			'`
		);

		// Create a campaign via WP-CLI.
		const listId = process.env.STAMPY_E2E_LIST_ID || '1';
		const createOutput = wpCli(
			`wp eval '
			$post_id = wp_insert_post( array(
				"post_type" => "stampy_campaign",
				"post_title" => "E2E Campaign",
				"post_content" => "<!-- wp:paragraph --><p>Hello {first_name}! Your email is {email}.</p><!-- /wp:paragraph -->",
				"post_status" => "publish",
			) );
			update_post_meta( $post_id, "stampy_campaign_subject", "E2E Campaign Test" );
			update_post_meta( $post_id, "stampy_campaign_list_ids", "[${ listId }]" );
			update_post_meta( $post_id, "stampy_campaign_status", "draft" );
			echo $post_id;
			'`
		);

		const campaignId = parseInt( createOutput.trim(), 10 );
		expect( campaignId ).toBeGreaterThan( 0 );

		// Start the send and run synchronously via WP-CLI.
		wpCli(
			`wp eval '
			$engine = new Stampy\\Campaigns\\SendingEngine();
			$result = $engine->start_send( ${ campaignId } );
			if ( ! $result["success"] ) {
				WP_CLI::error( $result["message"] );
			}
			$engine->run_synchronous( ${ campaignId } );
			$progress = $engine->get_progress( ${ campaignId } );
			WP_CLI::log( "Sent: " . $progress["sent"] . " / " . $progress["total"] );
			'`
		);

		// Wait for emails to arrive in Mailpit.
		await new Promise( ( resolve ) => setTimeout( resolve, 2000 ) );

		const messages = await getMailpitMessages();
		expect( messages.length ).toBeGreaterThan( 0 );

		// Verify at least one email has personalized content.
		const ctx = await request.newContext();
		let foundPersonalized = false;

		for ( const msg of messages ) {
			const detailResponse = await ctx.get(
				`${ MAILPIT_TESTS_API }/message/${ msg.ID }`
			);
			const detail = await detailResponse.json();

			const msgSubject = detail.Subject || detail.subject || '';
			const msgBody =
				detail.Text || detail.text || detail.HTML || detail.html || '';

			if ( msgSubject.includes( 'E2E Campaign' ) ) {
				expect( msgBody ).not.toContain( '{first_name}' );
				expect( msgBody ).not.toContain( '{email}' );
				foundPersonalized = true;
				break;
			}
		}

		await ctx.dispose();
		expect( foundPersonalized ).toBe( true );
	} );
} );
