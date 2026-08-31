# Stability Roadmap

This roadmap records the stabilization work completed through `1.0.13`, the next
fake-Telegram E2E epic, and the deliberately deferred test-suite refactor.

GitHub Project tracking lives in https://github.com/users/hokoo/projects/2. The
Project remains the delivery board; this document is the repository-level source
of context and sequencing.

## Direction

- `1.0.10` is treated as a released baseline.
- E1-E5 are complete and merged into `master` through `1.0.13`.
- E6 should be delivered in the current epic/evidence style so the next user
  value is better end-to-end coverage, not a disruptive test tree rewrite.
- E7 is deferred until the stabilized version has had a rollout window and
  feedback from users does not expose higher-priority regressions.

## Completed Epics

### E1. Stability Gate

Status: completed

Outcome: the plugin has a repeatable lifecycle and upgrade gate for fresh
installs, activation, deactivation, uninstall, cleanup cron state, and upgrades
from published baselines including `1.0.10`.

Evidence:

- `docs/stability/e1-traceability-report.md`
- `docs/stability/e1-upgrade-lifecycle-matrix.md`
- `tests/stability/e1-smoke-matrix.sh`

### E2. Migrations And Data Integrity

Status: completed

Outcome: legacy and partially migrated installs are reconciled idempotently
without false-success migration state, data loss, or noisy relation bootstrap
side effects.

Evidence:

- `docs/stability/e2-semantic-migration-characterization.md`
- `tests/stability/wp-e2-migration-characterization.php`
- release gate evidence for `1.0.11`

### E3. Telegram Gateway And Reliable Delivery

Status: completed

Outcome: Telegram delivery no longer depends on the removed SDK runtime graph.
The plugin uses a narrow WordPress HTTP gateway with normalized results,
token-aware redaction, transactional token replacement, webhook diagnostics,
formatting compatibility, chat pagination, and per-recipient failure handling.

Evidence:

- `docs/stability/e3-telegram-delivery.md`
- PHP gateway, formatting, token, pagination, and diagnostics tests
- production ZIP matrix evidence for `1.0.12`

### E4. REST API, Admin UI And Diagnostics

Status: completed

Outcome: the settings screen behaves predictably under partial REST failures and
does not mutate plugin data from frontend render side effects.

Scope delivered:

- POST-first mutating bot sync routes with validation and deprecated GET
  compatibility headers.
- Pagination for bots, chats, channels, and forms.
- Per-resource loading, partial failure, and retry-failed behavior.
- Removal of render-time token mutation and brittle `toWellFormed` dependency.
- Container-scoped admin styles and WordPress notice policy fixes.
- Redacted REST, migration, sync, and delivery diagnostics.

Evidence:

- `docs/stability/e4-rest-api-admin-diagnostics.md`
- `tests/stability/e4-rest-ui-smoke.sh`
- `tests/stability/wp-e4-rest-ui-smoke.php`
- React API/admin component tests
- Project E4 delivery record

### E5. Release Delivery And Maintainability

Status: completed

Outcome: release ZIP creation and WordPress.org promotion are reproducible,
fail-closed, and backed by executable lifecycle, browser, rollback, Plugin Check,
dependency audit, and artifact hygiene evidence.

Scope delivered:

- React build migrated to `@wordpress/scripts`.
- PHPUnit made the primary PHP test runner.
- ZIP lifecycle compatibility matrix expanded for supported WordPress/PHP/CF7
  combinations.
- Browser lifecycle smoke added through Playwright.
- Plugin Check, dependency audit, forbidden-entry ZIP gates, and log retention
  controls added.
- Canary promotion and race-free SVN deployment gates added.

Evidence:

- `docs/stability/e5-release-delivery-maintainability.md`
- `.github/workflows/build-zip.yml`
- `.github/workflows/promote-wordpress-org.yml`
- `tests/e2e/e5-browser-smoke.spec.js`
- `tests/stability/e5-*.sh`
- release gate evidence for `1.0.13`

## Planned Epic

### E6. Fake Telegram Form Delivery And Admin Setup E2E

Status: in progress, first implementation batch locally verified

Plan document: `docs/stability/e6-fake-telegram-e2e-plan.md`

Outcome: a candidate ZIP cannot pass PR/release verification unless a real
Contact Form 7 public form submission produces expected Telegram delivery
attempts through deterministic fake Telegram transport, and the admin setup graph
behind that delivery is covered by browser E2E.

Scope:

- Dedicated isolated WordPress plus Playwright E2E harness for fake Telegram
  delivery.
- Fake Telegram transport that records `getMe`, `getWebhookInfo`, `getUpdates`,
  and `sendMessage` without live Telegram network calls.
- Public CF7 submit flow using a real rendered page and real plugin hooks.
- Admin/browser setup coverage for bot creation/removal, channel
  creation/removal, CF7 form visibility/selection, chat discovery through fake
  `/cf7tg_start`, and relation assignment.
- Multi-recipient delivery, partial failures, redacted evidence, and CI
  artifacts.

Out of scope:

- Live Telegram API calls.
- Human-in-the-middle Telegram confirmation.
- Webhook-based delivery.
- Attachments or new media delivery behavior.
- Release tagging or deployment.

Success criteria:

- Playwright submits a real CF7 form and observes a successful CF7 response.
- Fake Telegram evidence contains `sendMessage` attempts for every expected chat
  and no unexpected chat.
- Bot, channel, form, and chat setup flows pass through the real plugin admin UI
  and are verified against DOM, REST, and fake transport evidence.
- Partial Telegram failures do not abort the CF7 submission and do not prevent
  later recipients from being attempted.
- The gate runs in CI on PR/release paths and uploads structured evidence plus
  Playwright traces/screenshots/videos on failure.

Tasking guidance:

- Keep the current epic/evidence naming for E6 to preserve delivery traceability.
- Re-run `$decompose-work` before implementation if the scope changes.
- Do not restructure the full test tree inside E6; use E7 for that work.
- E6.1 was approved on 2026-08-31.
- E6.2/E6.3 local fake Telegram form-delivery smoke is implemented and passing.
- Next runnable batch: E6.4 admin setup coverage.

## Deferred Epic

### E7. Test Suite Taxonomy Refactor

Status: deferred

Plan document: `docs/stability/e7-test-suite-taxonomy-refactor.md`

Outcome: after the stabilized version has enough rollout feedback, reorganize
the test suite by test type instead of delivery chronology while preserving
coverage, CI gates, and release evidence traceability.

Rationale:

- The current `e1`, `e4`, `e5`, and planned `e6` names make delivery ownership
  and release evidence easy to audit.
- They are not the best long-term information architecture once the immediate
  stabilization work stops being the main axis of change.
- Deferring the rewrite avoids large test-path churn while `1.0.13` is still
  settling with users.

Candidate taxonomy:

- PHP unit/model tests.
- React unit/component tests.
- WordPress integration tests.
- Browser E2E tests.
- Release and artifact gates.
- Shared fixtures, fake transports, and evidence helpers.

Initial task candidates:

- E7.1: approve the post-rollout readiness gate and final taxonomy contract.
- E7.2: inventory current epic-named coverage and create an old-to-new mapping.
- E7.3: extract shared WordPress, Playwright, and fake Telegram fixtures.
- E7.4: move or rename tests by suite type with temporary compatibility wrappers
  for established CI commands.
- E7.5: update CI, docs, runbooks, and artifact names without losing historical
  traceability.
- E7 QA: independently verify no coverage loss, no CI regression, and no
  evidence gap.

Dependencies:

- `1.0.13` rollout feedback window.
- E6 completed or explicitly descoped.
- No active stability regressions with higher priority than test organization.

Decision gate:

- The owner must approve the rollout timing and final taxonomy before E7 enters
  execution. The recommended default is to wait until the next stabilized
  release has been used by real users without blocker feedback.
