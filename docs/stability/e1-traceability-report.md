# Epic 1 Traceability Report

`1.0.10` is treated as already released. Epic 1 therefore validates the corrective path from published artifacts to the local corrective candidate and records downstream blockers without pretending they are fixed.

## P0 Mapping

| Known P0 / Risk | Epic Owner | E1.3 Evidence | Current Status |
| --- | --- | --- | --- |
| Maintenance lifecycle fatal during install, deactivate, cleanup cron, or uninstall | E1 | `fresh` and every `upgrade-*` case capture `after-activate`, `after-deactivate`, `after-uninstall`, and cleanup cron assertions in `results/evidence.jsonl` and `results/state/*.json`. | Covered by E1 harness. A failing lifecycle step blocks E1 closure. |
| Cleanup cron duplicate or unknown schedule regression | E1 | `assert_cleanup_scheduled` requires 1-2 total `cf7tg_cleanup` events, max one recurring event, and zero duplicate signatures. `assert_cleanup_absent` verifies deactivation/uninstall cleanup. | Covered by E1 harness. |
| Upgrade from published `1.0.10` to corrective candidate | E1 | `upgrade-1.0.10` downloads the published WordPress.org zip, verifies checksum/header, seeds data, upgrades to candidate, and runs lifecycle/rollback checks. | Covered by E1 harness because `1.0.10` is already published. |
| Migration scheduling, false-success, idempotency, malformed legacy state, and `[telegram]` shortcode cleanup | E2 | `upgrade-0.10`, `upgrade-0.11`, and `upgrade-1.0.9` seed legacy options and CF7 forms, upgrade through `Plugin_Upgrader::upgrade()`, then preserve snapshots before and after upgrade. A missing migration event is recorded as an E2 release blocker. E1.3 does not assert semantic migration correctness. | Downstream owner E2. E1.3 supplies reproduction evidence and keeps the release gate red while the real single-plugin update path does not schedule migration. |
| Markdown escaping, Telegram API failures, invalid token, unavailable Telegram, and "Telegram error must not break CF7 submission" | E3 | Not exercised by E1.3. The fixture includes special-character form/chats so later E3 tests can reuse the shape without real Telegram secrets. | Downstream owner E3. Requires fake Telegram transport and CF7 submission assertions. |
| More than 10 REST entities, frontend chat deletion, React/API diagnostics | E4 | `legacy-heavy` seeds more than 10 chats for upgrade-state capture. E1.3 does not call REST endpoints or the React UI. The local `Client::getChannels()` pagination typo is corrected in E1 and covered by `ClientTest`. | Downstream owner E4 for REST/UI coverage; the query typo is closed by E1 regression evidence. |
| Production-derived damaged fixtures | E1 input, E2/E4 consumers | `tests/stability/fixtures/production-fixture.schema.json` defines redacted structural fixture format; runbook bans real tokens, unhashed chat IDs, emails, and raw dumps. | Fixture contract ready. Actual redacted production fixtures still need collection/import tasks. |
| Release artifact/source integrity | E1 / release gate | `tests/stability/e1-version-sources.json` pins WordPress.org artifact URLs and SHA-256 checksums. Candidate source priority prefers `CF7TG_CANDIDATE_ZIP` or `dist/cf7-telegram-wp-plugin.zip`. | Covered for harness inputs. Production zip build/gate remains owned by the release-gate task. |
| Rollback restoration | E1 | Each `upgrade-*` case exports a rollback SQL before candidate upgrade, imports it after uninstall, reinstalls the published baseline, and asserts active baseline plus pre-upgrade data/options. | Covered by E1 harness. |

## Epic 1 Closure Rule

E1 can close only when:

- artifact verification passes for all published baselines;
- fresh and E1-owned upgrade lifecycle smoke cases pass;
- lifecycle P0s mapped to E1 have fixes and passing regression evidence;
- failures mapped to E2/E3/E4 are documented as downstream blockers with reproducible state snapshots;
- the final candidate is tested from a production-style zip, not an incomplete clean-checkout `plugin-dir`.

E1.3 does not close E2, E3, or E4 defects. It makes them reproducible and prevents release decisions from relying on unverified lifecycle behavior.

## Baseline Verification

Verified on 2026-08-30 with WordPress 7.0.4, Contact Form 7 6.0.6, PHP 8.2, and a production-style corrective candidate ZIP. The final release gate uses candidate version `1.0.11` while retaining published `1.0.10` as an upgrade baseline:

- `fresh`: all install, activation, cleanup cron, deactivation, and uninstall assertions passed;
- `upgrade-0.10`: all lifecycle and rollback assertions passed; migration scheduling failed as an E2 blocker;
- `upgrade-0.11`: all lifecycle and rollback assertions passed; migration scheduling failed as an E2 blocker;
- `upgrade-1.0.9`: all lifecycle and rollback assertions passed; migration scheduling failed as an E2 blocker;
- `upgrade-1.0.10`: all lifecycle and rollback assertions passed; migration scheduling failed as an E2 blocker.

The matrix intentionally exits non-zero while these E2 blockers remain. E1 is successful when it reports them accurately and prevents the corrective release from bypassing them.
