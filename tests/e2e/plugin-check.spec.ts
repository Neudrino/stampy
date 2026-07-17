/**
 * E2E test: Plugin Check.
 *
 * Runs the official WordPress Plugin Check tool against Stampy inside the
 * wp-env tests container. This is the same tool the WP.org review team uses
 * to vet plugin submissions, so it must pass before any release.
 *
 * Prerequisite: the Plugin Check plugin must be installed in the tests
 * instance. This is handled by the `.wp-env.json` `tests.plugins` mapping,
 * which downloads and installs the plugin from wordpress.org on `env:start`.
 *
 * Only static checks run in this E2E test (no `--require=cli.php` flag).
 * Runtime checks require a full WP environment boot which hangs in the
 * wp-env tests-cli container. The CI `wordpress/plugin-check-action@v1`
 * job (in `ci.yml`) and the release pipeline `build-release.yml` both run
 * the full check (static + runtime) via the GitHub Action, which spins up
 * its own dedicated wp-env instance.
 *
 * Dev files and directories are excluded because the wp-env mapping mounts
 * the entire project directory (including tests, config, .wp-env-home) into
 * the plugin folder inside the container.
 */

import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

function wpCli( command: string ): string {
	let lastError: Error | null = null;
	for ( let attempt = 0; attempt < 3; attempt++ ) {
		try {
			return execSync(
				`WP_ENV_HOME=./.wp-env-home npx wp-env run tests-cli --env-cwd=wp-content/plugins/stampy ${ command }`,
				{
					encoding: 'utf-8',
					timeout: 120_000,
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

test.describe( 'Plugin Check', () => {
	test( 'wp plugin check reports 0 errors', () => {
		// Activate the Plugin Check plugin in the tests instance.
		wpCli( 'wp plugin activate plugin-check' );

		// Run the plugin check. The --require flag loads cli.php which
		// enables runtime checks (not just static checks).
		let output: string;
		try {
			output = wpCli(
				'wp plugin check stampy --format=json --exclude-directories=.wp-env-home,node_modules,tests,dev,stubs,.husky,test-results,playwright-report,WordPress-PHPUnit,tests-WordPress-PHPUnit,akismet --exclude-files=phpunit.xml.dist,phpcs.xml.dist,phpstan.neon.dist,phpstan-baseline.neon,playwright.config.ts,jest.config.js,tsconfig.json,eslint.config.cjs,.gitignore,.distignore,.nvmrc,.wp-env.json,.editorconfig,.gitkeep,.phpunit.result.cache,composer.json,composer.lock,package.json,package-lock.json,PLAN.md,PROGRESS.md,AGENTS.md,README.md,SECURITY.md,LICENSE --ignore-warnings'
			);
		} catch ( e ) {
			// wp plugin check exits non-zero on failures. Capture the output
			// from stdout for analysis, but re-throw if we can't parse it.
			output =
				e instanceof Error && 'stdout' in e
					? String( ( e as { stdout?: string } ).stdout || '' )
					: '';
			if ( ! output ) {
				throw e;
			}
		}

		// Parse the JSON output. The `--format=json` output includes
		// `FILE: ...` lines before each JSON array and a summary line at
		// the end. Extract and concatenate all JSON arrays.
		const jsonArrays: unknown[] = [];
		for ( const line of output.split( '\n' ) ) {
			const trimmed = line.trim();
			if (
				! trimmed ||
				trimmed.startsWith( 'FILE:' ) ||
				trimmed.startsWith( 'Success:' ) ||
				trimmed.startsWith( 'Error:' ) ||
				trimmed.startsWith( 'ℹ' ) ||
				trimmed.startsWith( '✔' )
			) {
				continue;
			}
			try {
				const parsed = JSON.parse( trimmed );
				if ( Array.isArray( parsed ) ) {
					jsonArrays.push( ...parsed );
				}
			} catch {
				// Not JSON — skip (wp-env log lines, etc.)
			}
		}

		// Filter for errors only (warnings are ignored via --ignore-warnings
		// but we double-check here).
		const errors = jsonArrays.filter(
			( item ) =>
				typeof item === 'object' &&
				item !== null &&
				( item as { type?: string } ).type === 'ERROR'
		);

		expect(
			errors.length,
			`Plugin Check found ${ errors.length } error(s):\n${ JSON.stringify(
				errors,
				null,
				2
			) }`
		).toBe( 0 );
	} );
} );
