const fs = require('fs');
const path = require('path');
const repoRoot = path.resolve(__dirname, '..', '..');
const {expect, test} = require(path.join(repoRoot, 'plugin-dir/react/node_modules/@playwright/test'));

const resultPath = process.env.CF7TG_E6_RESULT_JSON || path.join(
	process.env.CF7TG_E6_RESULTS_DIR || process.cwd(),
	'browser-result.json'
);
const expectedCandidateVersion = process.env.CF7TG_EXPECTED_CANDIDATE_VERSION || '1.0.13';
const expectedCandidateSha256 = process.env.CF7TG_CANDIDATE_SHA256 || '';
const controlAction = 'cf7tg_e6_fake_telegram_control';
const fullTokenCanary = '660001:E6_FAKE_TOKEN_CANARY';

const requiredCheckIds = [
	'fake-transport-active',
	'public-form-renders',
	'cf7-submit-success',
	'send-message-attempts',
	'no-unexpected-recipient',
	'no-token-leakage',
	'no-page-errors',
	'no-console-errors',
];

const checks = new Map();
const evidence = {
	console_errors: [],
	page_errors: [],
	request_failures: [],
	contact_form_7_responses: [],
	telegram: {},
	fixture: {},
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

const control = async (page, baseURL, action, fields = {}) => {
	const response = await page.request.post(`${baseURL}/wp-admin/admin-ajax.php`, {
		form: {
			action: controlAction,
			e6_action: action,
			...fields,
		},
	});

	const body = await response.json();
	expect(response.status()).toBe(200);
	expect(body.success).toBe(true);
	return body.data;
};

const telegramEvidence = async (page, baseURL) => {
	const data = await control(page, baseURL, 'evidence');
	evidence.telegram = data.telegram || {};
	evidence.fixture = data.fixture || {};
	return data;
};

const sendMessageCalls = (data) => (data.telegram?.calls || [])
	.filter((call) => call.method === 'sendMessage');

const isCf7FeedbackResponse = (response) => (
	response.request().method() === 'POST'
	&& /\/contact-form-7\/v1\/contact-forms\/\d+\/feedback(?:\?|$)/.test(response.url())
);

test.afterAll(() => {
	const failed = requiredCheckIds
		.map((id) => checks.get(id))
		.some((check) => !check || check.status !== 'pass');
	writeResult(failed ? 'failed' : 'passed');
});

test('public CF7 submit records fake Telegram sendMessage attempts', async ({baseURL, page}) => {
	const unexpectedConsoleErrors = [];
	const pageErrors = [];
	const marker = `cf7tg-e6-${Date.now()}`;

	page.on('console', (message) => {
		if (message.type() !== 'error') {
			return;
		}

		const entry = {
			text: message.text(),
			location: message.location(),
		};
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

	page.on('requestfailed', (request) => {
		evidence.request_failures.push({
			method: request.method(),
			url: sanitizeUrl(request.url()),
			failure: request.failure()?.errorText || '',
		});
	});

	page.on('response', async (response) => {
		const url = response.url();
		if (!isCf7FeedbackResponse(response)) {
			return;
		}

		try {
			evidence.contact_form_7_responses.push({
				status: response.status(),
				url: sanitizeUrl(url),
				body: await response.json(),
			});
		} catch (error) {
			evidence.contact_form_7_responses.push({
				status: response.status(),
				url: sanitizeUrl(url),
				error: error.message,
			});
		}
	});

	await expectCheck('fake-transport-active', 'Fake Telegram transport is active and resettable.', async () => {
		await control(page, baseURL, 'reset');
		const data = await telegramEvidence(page, baseURL);
		expect(data.active).toBe(true);
		expect(data.fixture.form_id).toBeTruthy();
		expect(data.fixture.page_url).toMatch(/^http/);
		expect(data.fixture.expected_chat_ids).toHaveLength(2);
		return {
			form_id: data.fixture.form_id,
			page_url: data.fixture.page_url,
			expected_chat_ids: data.fixture.expected_chat_ids,
			unexpected_chat_id: data.fixture.unexpected_chat_id,
		};
	});

	const fixture = evidence.fixture;

	await expectCheck('public-form-renders', 'The real public Contact Form 7 page renders.', async () => {
		await page.goto(fixture.page_url, {waitUntil: 'domcontentloaded'});
		await expect(page.locator('.wpcf7 form')).toBeVisible();
		await expect(page.locator('input[name="your-name"]')).toBeVisible();
		await expect(page.locator('input[name="your-email"]')).toBeVisible();
		await expect(page.locator('input[name="e6-marker"]')).toBeVisible();
		await expect(page.locator('textarea[name="your-message"]')).toBeVisible();
		return {
			url: page.url(),
			form_id: fixture.form_id,
		};
	});

	await expectCheck('cf7-submit-success', 'Submitting the public CF7 form succeeds in the browser.', async () => {
		await page.locator('input[name="your-name"]').fill('E6 Browser User');
		await page.locator('input[name="your-email"]').fill('e6-browser@example.test');
		await page.locator('input[name="your-subject"]').fill('E6 fake Telegram delivery');
		await page.locator('input[name="e6-marker"]').fill(marker);
		await page.locator('textarea[name="your-message"]').fill(`E6 public submit marker ${marker}`);

		const feedbackResponse = page.waitForResponse(isCf7FeedbackResponse, {timeout: 30000});

		await page.locator('.wpcf7 form input[type="submit"], .wpcf7 form button[type="submit"]').click();
		const response = await feedbackResponse;
		expect(response.status()).toBe(200);
		const body = await response.json();
		expect(body.status).toBe('mail_sent');
		await expect(page.locator('.wpcf7 form')).toHaveClass(/sent/);
		await expect(page.locator('.wpcf7-response-output')).toBeVisible();
		return {
			status: body.status,
			message: body.message,
			marker,
		};
	});

	await expectCheck('send-message-attempts', 'Fake Telegram captured expected sendMessage attempts.', async () => {
		let data = null;
		await expect(async () => {
			data = await telegramEvidence(page, baseURL);
			const calls = sendMessageCalls(data);
			expect(calls).toHaveLength(fixture.expected_chat_ids.length);
			for (const chatID of fixture.expected_chat_ids) {
				const call = calls.find((entry) => String(entry.params.chat_id) === String(chatID));
				expect(call, `Expected sendMessage for chat ${chatID}`).toBeTruthy();
				expect(call.params.text).toContain(marker);
				expect(call.response.ok).toBe(true);
			}
		}).toPass({message: 'Expected sendMessage evidence should be recorded.', timeout: 30000, intervals: [250, 500, 1000]});

		return {
			expected_chat_ids: fixture.expected_chat_ids,
			send_message_calls: sendMessageCalls(data || {}).map((call) => ({
				index: call.index,
				chat_id: call.params.chat_id,
				token_hash: call.token_hash,
				response: call.response,
			})),
		};
	});

	await expectCheck('no-unexpected-recipient', 'No unrelated chat receives a sendMessage attempt.', async () => {
		const data = await telegramEvidence(page, baseURL);
		const calls = sendMessageCalls(data);
		const recipients = calls.map((call) => String(call.params.chat_id));
		expect(recipients).not.toContain(String(fixture.unexpected_chat_id));
		expect(new Set(recipients)).toEqual(new Set(fixture.expected_chat_ids.map(String)));
		return {recipients};
	});

	await expectCheck('no-token-leakage', 'Fake Telegram evidence does not expose the full bot token.', async () => {
		const data = await telegramEvidence(page, baseURL);
		const serialized = JSON.stringify(data);
		expect(serialized).not.toContain('E6_FAKE_TOKEN');
		expect(serialized).not.toContain(fullTokenCanary);
		expect(serialized).toContain(fixture.bot_token_hash);
		return {
			token_hash: fixture.bot_token_hash,
			call_count: data.telegram?.calls?.length || 0,
		};
	});

	await expectCheck('no-page-errors', 'No page errors occurred during the E6 public submit flow.', async () => {
		expect(pageErrors).toEqual([]);
		return {page_errors: pageErrors};
	});

	await expectCheck('no-console-errors', 'No unexpected console errors occurred during the E6 public submit flow.', async () => {
		expect(unexpectedConsoleErrors).toEqual([]);
		return {console_errors: unexpectedConsoleErrors};
	});
});
