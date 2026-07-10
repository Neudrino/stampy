/**
 * Unit tests for the Stampy JavaScript entry point.
 */

import { STAMPY_VERSION } from './index';

describe( 'STAMPY_VERSION', () => {
	it( 'exposes the plugin version', () => {
		expect( STAMPY_VERSION ).toBe( '0.0.1' );
	} );
} );
