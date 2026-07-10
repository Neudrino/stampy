/**
 * Unit tests for the Stampy JavaScript entry point.
 */

import * as stampy from './index';
import { STAMPY_VERSION } from './index';

describe( 'STAMPY_VERSION', () => {
	it( 'exposes the plugin version', () => {
		expect( STAMPY_VERSION ).toBe( '0.0.1' );
	} );

	it( 'is a string', () => {
		expect( typeof STAMPY_VERSION ).toBe( 'string' );
	} );
} );

describe( 'Stampy entry point exports', () => {
	it( 'exports exactly one symbol', () => {
		expect( Object.keys( stampy ) ).toEqual( [ 'STAMPY_VERSION' ] );
	} );
} );
