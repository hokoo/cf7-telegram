const path = require('path');
const baseConfig = require('@wordpress/scripts/config/jest-unit.config');

const scriptsPath = path.dirname(require.resolve('@wordpress/scripts/package.json'));

module.exports = {
	...baseConfig,
	preset: require.resolve('@wordpress/jest-preset-default', {
		paths: [scriptsPath],
	}),
	setupFilesAfterEnv: [
		...(baseConfig.setupFilesAfterEnv || []),
		'<rootDir>/src/setupTests.js',
	],
	testEnvironment: 'jsdom',
};
