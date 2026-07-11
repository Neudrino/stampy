/**
 * Playwright configuration for Stampy end-to-end tests.
 *
 * The WordPress TESTS instance is provided by `@wordpress/env` and must be
 * started separately (e.g. `npm run env:start`) — this config intentionally
 * does NOT define a `webServer` block.
 */

import { defineConfig } from '@playwright/test';

export default defineConfig( {
	testDir: './tests/e2e',
	outputDir: 'test-results',
	fullyParallel: true,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	timeout: 30_000,
	expect: {
		timeout: 5_000,
	},
	reporter: [ [ 'html', { open: 'never' } ] ],
	globalSetup: './tests/e2e/global-setup.ts',
	use: {
		baseURL: 'http://localhost:8889',
		actionTimeout: 10_000,
		navigationTimeout: 15_000,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'off',
	},
} );
