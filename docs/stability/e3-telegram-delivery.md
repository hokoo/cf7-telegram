# Epic 3 Telegram Delivery Gate

Epic 3 replaces the Telegram SDK with a narrow WordPress HTTP gateway and closes the delivery, token lifecycle, update polling, formatting, diagnostics, and chat pagination risks identified by the stability plan. Version `1.0.12` is the current source and artifact version; tagging and publication are separate release decisions.

## Approved Contracts

- Telegram calls go through `TelegramGateway`; the production implementation uses the WordPress HTTP API and tests use a recording fake. Tests do not need a real bot token or Telegram network access.
- Gateway operations return `TelegramDeliveryResult` for transport failures, malformed or non-2xx responses, Telegram `ok=false`, retry metadata, and successful result payloads.
- A bot identity is `getMe.result.id`. A known matching identity preserves relations, a known different identity resets only relations owned by that bot, and a legacy bot without a stored identity is preserved conservatively.
- A replacement token is persisted only after successful `getMe` validation. Failed validation leaves the stored token, identity, and relations unchanged.
- An active webhook is diagnosed and polling is skipped. Epic 3 never deletes or reconfigures a webhook.
- Existing Markdown and plaintext behavior remains compatible. Parse-entity failures receive one plaintext retry; other Telegram failures are not retried by the formatter.
- Chat collection requests traverse every WordPress REST page and retain the existing array API exposed to React callers.

## Delivery Traceability

| Task | Delivery Evidence |
| --- | --- |
| E3.1 Gateway contract and fake transport | `d4e4177` adds the gateway interface, normalized result, redaction, WordPress HTTP implementation, bootstrap stubs, and gateway tests. |
| E3.2 SDK removal | `51ed6fe` removes `irazasyed/telegram-bot-sdk` and SDK-only transitives from Composer and removes runtime SDK/Collection coupling. |
| E3.3 Token lifecycle | `c74960f` adds validated transactional token replacement, identity-aware relation handling, REST endpoint, and React transaction handling. |
| E3.4 Update polling and webhook diagnostics | `51ed6fe` diagnoses webhook conflicts, processes updates sequentially, commits offsets only through successful or safely ignored updates, and repairs partial relations on retry. |
| E3.5 Chat pagination | `38bcfbb` loads all `/wp/v2/cf7tg_chat` pages, deduplicates stable IDs, and rejects the whole request when any later page fails. |
| E3.6 Formatting and compatibility hooks | `51ed6fe` adds Unicode-safe 4096-character chunking, plaintext conversion, parse-error retry, and restores published `wpcf7tg_*` hook signatures alongside current hooks. |
| E3.7 Delivery results and diagnostics | `51ed6fe` returns per-recipient/per-chunk outcomes, continues other recipients after failure, keeps CF7 submission independent of Telegram, and logs only safe internal identifiers and error categories. |
| E3.8 Integration gate | Production ZIP, Composer, PHP, React, and isolated WordPress lifecycle/upgrade matrix evidence below. |

## Verification

Verified on 2026-08-31:

- `php tests/run.php`: `72/72`, no failures;
- `CI=true npm test -- --watchAll=false --runInBand` in `plugin-dir/react`: `4/4` suites and `14/14` tests;
- `npm run build` in `plugin-dir/react`: pass, with existing Create React App and ESLint warnings;
- `composer validate --no-check-publish`: valid, with the existing unbound `ramsey/collection` constraint warning;
- `composer audit`: no known security advisories;
- `composer check-platform-reqs --no-dev`: pass;
- PHP lint for changed PHP files and `git diff --check`: pass;
- `scripts/build-release-zip.sh`: pass with byte-identical output across `Asia/Tbilisi` and `America/Los_Angeles`; its default epoch follows release inputs and ZIP timestamps are normalized to UTC;
- candidate ZIP SHA-256: `b82c4b9aac9b339bb96270c4372ada27e95d746e4d013d137f36e26a18495d29`;
- candidate ZIP size: `272759` bytes;
- candidate ZIP contains no Telegram SDK, Guzzle, Illuminate, or Carbon paths;
- Composer production graph contains only `hokoo/wpconnections`, `hokoo/wppostable`, `psr/log`, `ramsey/collection`, and `symfony/polyfill-php81`.

The production ZIP was exercised by the isolated WordPress matrix:

```bash
CF7TG_CANDIDATE_ZIP="$PWD/dist/cf7-telegram-wp-plugin.zip" \
tests/stability/e1-smoke-matrix.sh
```

Result:

- run id: `20260830T214947Z-85952`;
- WordPress `7.0.4`, Contact Form 7 `6.0.6`, PHP `8.2`;
- `total_steps=164`, `passed_steps=164`;
- `failed_steps=0`, `expected_failed_steps=0`;
- fresh install, activation, reactivation, deactivation, and uninstall passed;
- upgrade, migration, lifecycle, uninstall, and rollback passed from published `0.10.2`, `0.11`, `1.0.9`, and `1.0.10` artifacts to candidate `1.0.12`.

Independent QA initially found that a WordPress transport error containing the request URL could expose a URL-encoded bot token. Commit `825b4d5` routes every gateway failure through token-aware redaction, covers raw and repeatedly encoded token forms, and adds a regression that returns the actual request URL through `WP_Error`. The PHP suite and production artifact matrix above were rerun after that fix.

## Residual Ownership

- Pagination and bootstrap coverage for bots, channels, forms, and general REST/UI behavior remains in Epic 4. Chat pagination is closed by Epic 3.
- Create React App deprecation warnings and `npm audit` findings in the development/build dependency graph remain in Epic 5. They are not shipped as PHP runtime dependencies in the plugin ZIP.
- Background polling, webhook provisioning/deletion, attachments, and new Telegram formatting modes are outside Epic 3.
- Tagging, further version changes, and WordPress.org publication require a separate release decision.
