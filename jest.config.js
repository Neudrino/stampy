const wpPreset = require( '@wordpress/jest-preset-default/jest-preset' );

const moduleMocks = {
	'@wordpress/block-editor': '<rootDir>/tests/jest/mocks/block-editor.js',
	'@wordpress/components': '<rootDir>/tests/jest/mocks/components.js',
	'@wordpress/i18n': '<rootDir>/tests/jest/mocks/i18n.js',
	'@wordpress/api-fetch': '<rootDir>/tests/jest/mocks/api-fetch.js',
};

module.exports = {
	...wpPreset,
	moduleNameMapper: {
		...wpPreset.moduleNameMapper,
		...moduleMocks,
	},
	transform: {
		'\\.[jt]sx?$': require.resolve(
			'@wordpress/scripts/config/babel-transform'
		),
	},
	setupFilesAfterEnv: [
		...wpPreset.setupFilesAfterEnv,
		'<rootDir>/tests/jest/setup.js',
	],
	testPathIgnorePatterns: [
		'/node_modules/',
		'<rootDir>/vendor/',
		'<rootDir>/tests/e2e/',
		'<rootDir>/.wp-env-home/',
	],
};
