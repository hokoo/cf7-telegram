const fs = require('fs');
const path = require('path');
const repoRoot = path.resolve(__dirname, '..', '..');
const {expect, test} = require(path.join(repoRoot, 'plugin-dir/react/node_modules/@playwright/test'));

const resultPath = process.env.CF7TG_E5_BROWSER_RESULT_JSON || path.join(
	process.env.CF7TG_E5_BROWSER_RESULTS_DIR || process.cwd(),
	'browser-result.json'
);
const expectedCandidateVersion = process.env.CF7TG_EXPECTED_CANDIDATE_VERSION || '1.0.13';
const expectedCandidateSha256 = process.env.CF7TG_CANDIDATE_SHA256 || '';
const expectedWpVersion = process.env.CF7TG_E5_BROWSER_EXPECTED_WP_VERSION || '';
const adminUser = process.env.CF7TG_E5_BROWSER_ADMIN_USER || 'admin';
const adminPassword = process.env.CF7TG_E5_BROWSER_ADMIN_PASSWORD || 'admin-password';

const requiredCheckIds = [
	'authenticated-admin-render',
	'no-page-errors',
	'no-console-errors',
	'full-page-background',
	'system-notices-hidden',
	'pagination-beyond-ten',
	'post-mutation-observed',
];

const checks = new Map();
const evidence = {
	console_errors: [],
	expected_console_errors: [],
	page_errors: [],
	request_failures: [],
	rest_requests: [],
	rest_responses: [],
	asset_responses: [],
	collections: {},
	control_actions: [],
};

const sanitizeUrl = (url) => {
	const parsed = new URL(url);
	for (const key of Array.from(parsed.searchParams.keys())) {
		if (/nonce|token|secret|password|key/i.test(key)) {
			parsed.searchParams.set(key, '[redacted]');
		}
	}
	return `${parsed.origin}${parsed.pathname}${parsed.search}${parsed.hash}`;
};

const writeResult = (status = 'unknown') => {
	const directory = path.dirname(resultPath);
	fs.mkdirSync(directory, {recursive: true});

	const normalizedChecks = requiredCheckIds.map((id) => checks.get(id) || {
		id,
		status: 'fail',
		message: 'Required check did not run.',
		extra: {},
	});

	fs.writeFileSync(
		resultPath,
		JSON.stringify({
			schema: 1,
			status,
			candidate: {
				version: expectedCandidateVersion,
				sha256: expectedCandidateSha256,
			},
			wordpress: {
				version: evidence.wordpress_version || null,
			},
			output_dir: path.join(path.dirname(resultPath), 'playwright-artifacts'),
			checks: normalizedChecks,
			evidence,
		}, null, 2)
	);
};

const recordCheck = (id, passed, message, extra = {}) => {
	checks.set(id, {
		id,
		status: passed ? 'pass' : 'fail',
		message,
		extra,
	});
};

const expectCheck = async (id, message, callback) => {
	try {
		const extra = await callback();
		recordCheck(id, true, message, extra || {});
	} catch (error) {
		recordCheck(id, false, message, {
			error: error.message,
		});
		throw error;
	} finally {
		writeResult('running');
	}
};

const routeName = (url) => {
	const parsed = new URL(url);
	const decoded = decodeURIComponent(parsed.searchParams.get('rest_route') || '');
	const pathName = decoded || parsed.pathname;

	if (/\/wp\/v2\/cf7tg_bot\/?$/.test(pathName)) return 'bots';
	if (/\/wp\/v2\/cf7tg_chat\/?$/.test(pathName)) return 'chats';
	if (/\/wp\/v2\/cf7tg_channel\/?$/.test(pathName)) return 'channels';
	return '';
};

const isExpectedRecoverableConsoleError = (entry, allowRecoverableApiConsoleError) => {
	if (!allowRecoverableApiConsoleError) {
		return false;
	}

	if (/API request error:/i.test(entry.text)) {
		return true;
	}

	return /Failed to load resource:.*503/i.test(entry.text)
		&& entry.location?.url
		&& routeName(entry.location.url) === 'channels';
};

const waitForRestState = async (page, predicate, message) => {
	await expect.poll(predicate, {message, timeout: 30000, intervals: [250, 500, 1000]}).toBeTruthy();
};

const login = async (page, baseURL) => {
	await page.goto(`${baseURL}/wp-login.php`, {waitUntil: 'domcontentloaded'});
	await page.locator('#user_login').fill(adminUser);
	await page.locator('#user_pass').fill(adminPassword);
	await Promise.all([
		page.waitForURL(/wp-admin/, {timeout: 30000}),
		page.locator('#wp-submit').click(),
	]);
};

const adminUrl = (baseURL) => `${baseURL}/wp-admin/admin.php?page=wpcf7_tg`;

const openAdmin = async (page, baseURL) => {
	await page.goto(adminUrl(baseURL), {waitUntil: 'domcontentloaded'});
	await expect(page.locator('#wpadminbar')).toBeVisible();
	await expect(page.locator('#cf7-telegram-container')).toBeVisible();
	await expect(page.getByRole('heading', {name: /Telegram notificator settings/i})).toBeVisible();
};

const runControlAction = async (page, action) => {
	const result = await page.evaluate(async (controlAction) => {
		const body = new FormData();
		body.append('action', 'cf7tg_e5_browser_control');
		body.append('e5_action', controlAction);

		const response = await fetch(window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		});

		return {
			status: response.status,
			body: await response.json(),
		};
	}, action);

	evidence.control_actions.push({action, result});
	expect(result.status).toBe(200);
	expect(result.body.success).toBe(true);
	return result;
};

test.afterAll(() => {
	const failed = Array.from(checks.values()).some((check) => check.status !== 'pass');
	writeResult(failed ? 'failed' : 'passed');
});

test('candidate admin browser lifecycle smoke', async ({baseURL, page}) => {
	const unexpectedConsoleErrors = [];
	const pageErrors = [];
	let allowRecoverableApiConsoleError = false;

	page.on('console', (message) => {
		if (message.type() !== 'error') {
			return;
		}

		const entry = {
			text: message.text(),
			location: message.location(),
		};

		if (isExpectedRecoverableConsoleError(entry, allowRecoverableApiConsoleError)) {
			evidence.expected_console_errors.push(entry);
			return;
		}

		unexpectedConsoleErrors.push(entry);
		evidence.console_errors.push(entry);
	});

	page.on('pageerror', (error) => {
		const entry = {
			message: error.message,
			stack: error.stack || '',
		};
		pageErrors.push(entry);
		evidence.page_errors.push(entry);
	});

	page.on('request', (request) => {
		const url = request.url();
		if (!url.includes('/wp-json/') && !url.includes('rest_route=')) {
			return;
		}

		evidence.rest_requests.push({
			method: request.method(),
			url: sanitizeUrl(url),
			has_wp_nonce_header: Boolean(request.headers()['x-wp-nonce']),
		});
	});

	page.on('requestfailed', (request) => {
		evidence.request_failures.push({
			method: request.method(),
			url: sanitizeUrl(request.url()),
			failure: request.failure()?.errorText || '',
		});
	});

	page.on('response', async (response) => {
		const url = response.url();
		if (url.includes('/wp-content/plugins/cf7-telegram/react/build/static/js/main.js')) {
			evidence.asset_responses.push({type: 'js', status: response.status(), url: sanitizeUrl(url)});
		}
		if (url.includes('/wp-content/plugins/cf7-telegram/react/build/static/css/main.css')) {
			evidence.asset_responses.push({type: 'css', status: response.status(), url: sanitizeUrl(url)});
		}

		const collection = routeName(url);
		if (!collection || response.request().method() !== 'GET' || response.status() >= 400) {
			return;
		}

		try {
			const data = await response.json();
			if (!Array.isArray(data)) {
				return;
			}

			const count = data.length;
			evidence.collections[collection] = {
				count,
				total: Number(response.headers()['x-wp-total'] || count),
				total_pages: Number(response.headers()['x-wp-totalpages'] || 1),
				url: sanitizeUrl(url),
			};
			evidence.rest_responses.push({
				collection,
				status: response.status(),
				count,
				url: sanitizeUrl(url),
			});
		} catch (error) {
			evidence.rest_responses.push({
				collection,
				status: response.status(),
				error: error.message,
				url: sanitizeUrl(url),
			});
		}
	});

	await login(page, baseURL);

	await expectCheck('authenticated-admin-render', 'Authenticated admin page renders before and after candidate reactivation.', async () => {
		await openAdmin(page, baseURL);

		await waitForRestState(
			page,
			() => evidence.asset_responses.some((entry) => entry.type === 'js' && entry.status === 200)
				&& evidence.asset_responses.some((entry) => entry.type === 'css' && entry.status === 200),
			'Built admin assets should load with HTTP 200.'
		);

		await runControlAction(page, 'reactivate-candidate');
		await openAdmin(page, baseURL);

		const runtime = await page.evaluate(() => ({
			wp_version: window.wp?.version || '',
			candidate_version: window.cf7TelegramData?.plugin?.version || '',
			has_nonce: Boolean(window.cf7TelegramData?.nonce),
		}));
		evidence.wordpress_version = runtime.wp_version || expectedWpVersion;

		return {
			url: page.url(),
			has_nonce: runtime.has_nonce,
			expected_candidate_version: expectedCandidateVersion,
			asset_responses: evidence.asset_responses,
		};
	});

	await expectCheck('system-notices-hidden', 'System WordPress notices are hidden on the plugin screen.', async () => {
		const notice = page.locator('#cf7tg-e5-server-notice');
		await expect(notice).toHaveCount(1);
		await expect(notice).toBeHidden();
		return await notice.evaluate((element) => {
			const style = window.getComputedStyle(element);
			const rect = element.getBoundingClientRect();
			return {
				text: element.textContent.trim(),
				display: style.display,
				visibility: style.visibility,
				width: rect.width,
				height: rect.height,
			};
		});
	});

	await expectCheck('full-page-background', 'The plugin background fills the complete WordPress content area.', async () => {
		const backgrounds = await page.evaluate(() => {
			const elements = [
				document.body,
				document.querySelector('#wpwrap'),
				document.querySelector('#wpcontent'),
				document.querySelector('#wpbody'),
				document.querySelector('#wpbody-content'),
				document.querySelector('#cf7-telegram-container'),
			];

			return elements.map((element) => ({
				element: element.id || element.tagName.toLowerCase(),
				background_color: window.getComputedStyle(element).backgroundColor,
			}));
		});

		expect(backgrounds.every((entry) => 'rgb(14, 22, 33)' === entry.background_color)).toBe(true);
		return {backgrounds};
	});

	await expectCheck('pagination-beyond-ten', 'Bots, chats, and channels beyond the first ten records are loaded in browser REST data.', async () => {
		await expect(page.locator('.bot-list .entity-container.bot')).toHaveCount(12);
		await expect(page.locator('.channel-list .entity-container.channel')).toHaveCount(12);
		await waitForRestState(
			page,
			() => ['bots', 'chats', 'channels'].every((name) => evidence.collections[name]?.count > 10),
			'REST collection responses should contain more than ten records.'
		);
		return {
			collections: evidence.collections,
			bot_dom_count: await page.locator('.bot-list .entity-container.bot').count(),
			channel_dom_count: await page.locator('.channel-list .entity-container.channel').count(),
		};
	});

	await expectCheck('post-mutation-observed', 'A protected bot mutation is observed as POST from the real admin screen.', async () => {
		await waitForRestState(
			page,
			() => evidence.rest_requests.some((request) => (
				request.method === 'POST'
				&& /\/wp\/v2\/cf7tg_bot\/\d+\/ping/.test(decodeURIComponent(request.url))
				&& request.has_wp_nonce_header
			)),
			'Expected POST /ping mutation with X-WP-Nonce should be observed.'
		);
		return {
			post_mutations: evidence.rest_requests.filter((request) => request.method === 'POST' && request.url.includes('/cf7tg_bot/')),
			protected_rest_observed: evidence.rest_requests.some((request) => request.has_wp_nonce_header),
		};
	});

	await runControlAction(page, 'fail-channel-once');
	allowRecoverableApiConsoleError = true;
	await page.goto(`${adminUrl(baseURL)}&e5_retry_probe=1`, {waitUntil: 'domcontentloaded'});
	await expect(page.locator('.cf7-tg-load-status')).toBeVisible();
	await page.getByRole('button', {name: /Retry failed requests/i}).click();
	await expect(page.locator('.channel-list .entity-container.channel')).toHaveCount(12);
	await expect(page.locator('.cf7-tg-load-status')).toBeHidden();
	allowRecoverableApiConsoleError = false;

	evidence.retry_recovery = {
		expected_console_errors: evidence.expected_console_errors.length,
		channel_count_after_retry: await page.locator('.channel-list .entity-container.channel').count(),
	};

	recordCheck('no-page-errors', pageErrors.length === 0, 'No uncaught page errors were emitted.', {
		page_errors: pageErrors,
	});
	recordCheck('no-console-errors', unexpectedConsoleErrors.length === 0, 'No unexpected console errors were emitted.', {
		console_errors: unexpectedConsoleErrors,
		expected_recoverable_console_errors: evidence.expected_console_errors.length,
	});

	expect(pageErrors).toHaveLength(0);
	expect(unexpectedConsoleErrors).toHaveLength(0);
});
