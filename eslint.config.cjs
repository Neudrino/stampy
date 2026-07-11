const wpScriptsConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	{
		ignores: [ '**/.wp-env-home/**' ],
	},
	...wpScriptsConfig,
	{
		rules: {
			'import/no-extraneous-dependencies': 'off',
			camelcase: [
				'error',
				{
					properties: 'never',
					ignoreGlobals: true,
					ignoreDestructuring: true,
				},
			],
		},
		languageOptions: {
			globals: {
				window: 'readonly',
				document: 'readonly',
				FormData: 'readonly',
				HTMLElement: 'readonly',
				HTMLInputElement: 'readonly',
				HTMLFormElement: 'readonly',
				Node: 'readonly',
				customElements: 'readonly',
				jest: 'readonly',
			},
		},
	},
];
