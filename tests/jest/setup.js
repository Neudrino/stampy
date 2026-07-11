const { TextEncoder, TextDecoder } = require( 'util' );

global.TextEncoder = TextEncoder;
global.TextDecoder = TextDecoder;

global.window.stampy = {
	restUrl: 'http://localhost/wp-json/stampy/v1',
	restNonce: 'test-nonce',
	lists: [
		{ id: 1, name: 'Newsletter', slug: 'newsletter', description: '' },
		{ id: 2, name: 'Promotions', slug: 'promotions', description: '' },
	],
	consentText: 'Test consent text',
};
