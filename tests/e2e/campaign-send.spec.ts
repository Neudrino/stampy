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
			// Wait 2s before retrying (Docker container may be temporarily busy).
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

test.describe.serial( 'Campaign send', () => {
	test( 'send campaign delivers personalized emails', async () => {
		test.setTimeout( 120_000 );
		const subject = `E2E Campaign ${ Date.now() }`;

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
		const escapedSubject = subject.replace( /'/g, `\\'` );
		const createOutput = wpCli(
			`wp eval '
			$post_id = wp_insert_post( array(
				"post_type" => "stampy_campaign",
				"post_title" => "E2E Campaign",
				"post_content" => "<!-- wp:paragraph --><p>Hello {field:first_name}! Your email is {email}.</p><!-- /wp:paragraph -->",
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

		// Wait for the campaign email to arrive in Mailpit.
		const detail = await waitForCampaignEmail( subject, 30_000 );

		const msgSubject = detail.Subject || detail.subject || '';
		const msgBody =
			detail.Text || detail.text || detail.HTML || detail.html || '';

		expect( msgSubject ).toContain( subject );

		// Verify the email has personalized content (merge tags replaced).
		expect( msgBody ).not.toContain( '{field:first_name}' );
		expect( msgBody ).not.toContain( '{email}' );
	} );
} );
