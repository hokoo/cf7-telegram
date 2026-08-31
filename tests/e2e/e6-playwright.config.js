const path = require('path');
const repoRoot = path.resolve(__dirname, '..', '..');
const {defineConfig, devices} = require(path.join(repoRoot, 'plugin-dir/react/node_modules/@playwright/test'));

const resultsDir = process.env.CF7TG_E6_RESULTS_DIR || path.resolve(__dirname, '../stability-results/e6-form-delivery');
const reportPath = process.env.CF7TG_E6_PLAYWRIGHT_REPORT_JSON || path.join(resultsDir, 'playwright-report.json');

module.exports = defineConfig({
	testDir: __dirname,
	testMatch: /e6-form-delivery\.spec\.js$/,
	fullyParallel: false,
	workers: 1,
	timeout: 120000,
	expect: {
		timeout: 15000,
	},
	reporter: [
		['list'],
		['json', {outputFile: reportPath}],
	],
	outputDir: path.join(resultsDir, 'playwright-artifacts'),
	use: {
		baseURL: process.env.CF7TG_E6_BASE_URL || 'http://127.0.0.1:8080',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		...devices['Desktop Chrome'],
	},
});
