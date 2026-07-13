( function () {
	'use strict';

	function loadScript( src, attrs ) {
		const script = document.createElement( 'script' );
		script.src = src;
		script.async = true;
		script.defer = true;
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( key ) {
				script.setAttribute( key, attrs[ key ] );
			} );
		}
		document.head.appendChild( script );
	}

	if (
		window.stampy &&
		window.stampy.turnstileEnabled &&
		window.stampy.turnstileSiteKey
	) {
		loadScript( 'https://challenges.cloudflare.com/turnstile/v0/api.js' );
	}

	if (
		window.stampy &&
		window.stampy.friendlyCaptchaEnabled &&
		window.stampy.friendlyCaptchaSiteKey
	) {
		loadScript(
			'https://cdn.jsdelivr.net/npm/@friendlycaptcha/[email protected]/site.min.js',
			{ type: 'module' }
		);
	}
} )();
