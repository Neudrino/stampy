const wpScriptsConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	{
		ignores: [ '**/.wp-env-home/**' ],
	},
	...wpScriptsConfig,
];
