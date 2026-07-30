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

	// Cloudflare Turnstile widget is a proprietary service script that must be
	// loaded from Cloudflare's servers (it collects behavioral signals and is
	// not self-hostable). This is a permitted service dependency.
	if (
		window.stampy &&
		window.stampy.turnstileEnabled &&
		window.stampy.turnstileSiteKey
	) {
		loadScript( 'https://challenges.cloudflare.com/turnstile/v0/api.js' );
	}

	// Friendly Captcha widget script is self-hosted (MPL-2.0 licensed SDK) to
	// avoid an external CDN dependency. The URL is provided by PHP.
	if (
		window.stampy &&
		window.stampy.friendlyCaptchaEnabled &&
		window.stampy.friendlyCaptchaSiteKey &&
		window.stampy.friendlyCaptchaScriptUrl
	) {
		loadScript( window.stampy.friendlyCaptchaScriptUrl, {
			type: 'module',
		} );
		if ( window.stampy.friendlyCaptchaScriptCompatUrl ) {
			loadScript( window.stampy.friendlyCaptchaScriptCompatUrl, {
				nomodule: '',
			} );
		}
	}
} )();
