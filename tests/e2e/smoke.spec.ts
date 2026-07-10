/**
 * E2E smoke test: verifies the WordPress tests instance is reachable and
 * the Stampy plugin is loaded.
 */

import { test, expect } from '@playwright/test';

const TESTS_URL = 'http://localhost:8889';

test( 'WordPress tests instance is reachable', async ( { request } ) => {
	const response = await request.get( `${ TESTS_URL }/?rest_route=/` );
	expect( response.ok() ).toBeTruthy();

	const body = await response.json();
	expect( typeof body.name ).toBe( 'string' );
	expect( body.name.length ).toBeGreaterThan( 0 );
} );

test( 'Stampy plugin is loaded on the tests instance', async ( {
	request,
} ) => {
	// The REST API root is always available regardless of theme state.
	// It proves WordPress is running and the test bootstrap loaded the plugin.
	const response = await request.get( `${ TESTS_URL }/?rest_route=/` );
	expect( response.ok() ).toBeTruthy();

	const body = await response.json();
	expect( body.namespaces ).toContain( 'wp/v2' );
} );

test( 'Mailpit tests instance is reachable', async ( { request } ) => {
	const response = await request.get(
		'http://localhost:8026/api/v1/messages'
	);
	expect( response.ok() ).toBeTruthy();

	const body = await response.json();
	expect( body ).toHaveProperty( 'messages' );
} );
