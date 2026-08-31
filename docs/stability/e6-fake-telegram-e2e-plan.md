# Epic 6 Fake Telegram Form Delivery And Admin Setup E2E

Status: completed

## Outcome

A candidate ZIP cannot pass PR/release verification unless a real Contact Form 7
public form submission produces the expected Telegram delivery attempts through
deterministic fake Telegram transport, and the bot/channel/form/chat setup flows
behind that delivery graph are covered by browser E2E.

## Readiness Summary

E6.1 was approved by the owner on 2026-08-31. The approved contract uses fake
Telegram transport only, keeps E6 in the current epic/evidence style, and starts
with E6.2 plus E6.3 as the first execution batch. E6.2, E6.3, E6.4, and E6.5 are
implemented and locally verified by `tests/stability/e6-form-delivery-smoke.sh`.
E6.6 wires that smoke into pull request and release CI with uploaded evidence.
Independent QA passed on 2026-08-31 after redaction, CI metadata, and Telegram
egress hardening were verified.

Approved decision:

- Use a dedicated E6 harness instead of extending the E5 browser canary.
- Use fake Telegram transport only: no live Telegram API and no
  human-in-the-middle confirmation.
- Make bot, channel, form, chat, relation assignment, public CF7 submit, and
  recipient delivery attempts mandatory coverage.
- Run the E6 gate on pull requests and release paths, not every push.
- Keep the current epic/evidence test naming for E6; defer suite-wide taxonomy
  cleanup to E7.

Completed first execution batch: E6.2 plus E6.3.

## Current Decision Candidate

Recommended contract: fake Telegram transport only.

This avoids live bot tokens, human-in-the-middle Telegram confirmation, and
external network flakiness. The fake transport should still exercise the real
plugin code path by intercepting Telegram HTTP calls at the WordPress transport
boundary and recording structured evidence.

Alternative considered: extend the existing E5 browser canary. It is faster
initially, but it makes the E5 smoke broader and harder to debug. E6 is a
delivery-specific gate, so a dedicated harness is cleaner.

Alternative considered: live Telegram or human-in-the-middle confirmation. This
can validate a real bot manually, but it is not deterministic enough for a
release gate and would require secrets or human timing.

## Scope

- Dedicated isolated WordPress plus Playwright E2E harness.
- Fake Telegram transport that records `getMe`, `getWebhookInfo`, `getUpdates`,
  and `sendMessage`.
- Docker-level `api.telegram.org` egress guard so a broken intercept cannot
  reach live Telegram.
- Real public Contact Form 7 page submit.
- Admin browser coverage for bot creation/removal.
- Admin browser coverage for channel creation/removal.
- Contact Form 7 form creation or fixture seeding, visibility in the plugin form
  selector, and channel assignment.
- Chat discovery through fake `/cf7tg_start` updates.
- Chat assignment to a channel and safety from implicit deletion.
- Multi-recipient delivery evidence.
- Partial Telegram failure evidence where CF7 submission still succeeds and
  later recipients are still attempted.
- CI integration on PR/release paths with structured logs and Playwright failure
  artifacts.

## Out Of Scope

- Live Telegram API calls.
- Human-in-the-middle confirmation.
- Webhook provisioning or deletion.
- Attachments/media delivery.
- New Telegram formatting features.
- Release tagging, deployment, or WordPress.org publication.
- Suite-wide test tree reorganization; that belongs to E7.

## Execution Tasks

### E6.1 Fake Telegram E2E Contract, Entity Coverage And CI Placement

Status: completed

Goal: approve the fake Telegram E2E contract, required bot/channel/form/chat
coverage, CI placement, and first batch before implementation starts.

Scope:

- Confirm dedicated E6 harness rather than extending E5 browser canary.
- Confirm fake transport only: no live Telegram and no human-in-the-middle in
  this epic.
- Confirm required entity coverage: bot create/remove, channel create/remove,
  CF7 form create/visibility/selection, chat discovery via fake `/cf7tg_start`,
  relation assignment, and public submit delivery.
- Confirm CI placement: pull request and release events.
- Confirm first execution batch: E6.2 plus E6.3 after this decision; E6.4
  remains mandatory in the same epic as the admin setup batch.

Out of scope:

- Implementation.
- Live credentials.
- Release publication.

DoR:

- Current test inventory and coverage gap documented.
- E1-E5 merged and CI green.

DoD:

- Owner approved the contract on 2026-08-31.
- E6.2 and E6.3 moved into the active execution batch after approval.

Acceptance criteria:

- Given the approved decision, when implementation starts, then the owner can
  tell whether the new gate is required on PR, release, or both.
- Given the approved decision, when fake Telegram evidence is produced, then it
  is accepted as the authoritative delivery proof for this epic.
- Given the approved decision, when E6 is complete, then bot/channel/form/chat
  admin setup coverage is not treated as optional or out of scope.

Dependencies:

- None.

Notes/Risks:

- Recommended option is dedicated E6 harness on PR/release.
- Extending E5 is faster initially but makes the existing canary too broad and
  harder to debug.

### E6.2 Dedicated Fake Telegram E2E Harness

Status: completed

Goal: add an isolated WordPress plus Playwright harness that can run without live
Telegram access.

Scope:

- Add a dedicated E6 stability script, fixture, and Playwright spec, likely
  `tests/stability/e6-form-delivery-smoke.sh`,
  `tests/stability/wp-e6-form-delivery-fixture.php`, and
  `tests/e2e/e6-form-delivery.spec.js`.
- Install the exact candidate ZIP in isolated WordPress with Contact Form 7.
- Add a mu-plugin fake transport using `pre_http_request` to intercept
  `api.telegram.org` calls.
- Record method, sanitized params, token identity bucket, response, and ordering
  for `getMe`, `getWebhookInfo`, `getUpdates`, and `sendMessage`.
- Add AJAX/control endpoints for resetting evidence, scripting one-shot
  failures, and reading evidence.

Out of scope:

- Actual form submit assertions.
- Live Telegram.
- Production code changes unless required for testability.

DoR:

- E6.1 approved.
- Existing E5 browser harness remains green.

DoD:

- Local E6 script bootstraps WordPress, seeds fixture, runs Playwright, and
  writes summary/evidence JSON.
- Fake Telegram calls are intercepted by the fixture `pre_http_request`
  transport.
- Evidence is sanitized and stable across runs.
- The isolated WordPress container maps `api.telegram.org` to localhost so
  live Telegram egress is blocked even if the intercept regresses.

Acceptance criteria:

- Given the fixture is loaded, when the plugin calls
  `getMe`/`getWebhookInfo`/`getUpdates`/`sendMessage`, then the mu-plugin returns
  scripted fake Bot API JSON.
- Given a test requests recorded evidence, then it receives ordered calls with
  sanitized payloads and no full token leakage.
- Given the fake transport is disabled or missing, then the smoke fails closed
  rather than silently using real Telegram.

Dependencies:

- E6.1.

Notes/Risks:

- Use the existing E5 shell/Docker pattern to avoid inventing another
  environment stack.

### E6.3 Public CF7 Submit Delivery Happy Path

Status: completed

Goal: prove that a real rendered CF7 form submission attempts Telegram delivery
to the expected chats.

Scope:

- Seed one real CF7 form, one public page with shortcode, one bot, one channel,
  and two connected chats.
- Configure form-to-channel, bot-to-channel, and chat-to-channel relations using
  plugin APIs/setup helpers in the isolated WP fixture.
- Submit the public form with Playwright using realistic field values and a
  unique run marker.
- Assert the Contact Form 7 submission succeeds in browser/network state.
- Assert fake Telegram captured exactly the expected `sendMessage` calls for
  both chats.

Out of scope:

- Admin UI CRUD.
- Partial failure.
- Live Telegram.
- Attachments.

DoR:

- E6.2 completed.
- Candidate ZIP build available.

DoD:

- The E6 Playwright spec fails if CF7 submit succeeds but Telegram dispatch does
  not occur.
- Evidence includes submitted marker, recipient count, sanitized message params,
  and success result per recipient.
- Local verification passed with `failed_steps: 0`, two expected `sendMessage`
  attempts, no unexpected recipient, no token leakage, and no page/console
  errors.

Acceptance criteria:

- Given a real CF7 form is connected to a channel, when the public form is
  submitted, then the plugin delivery hook runs.
- Given two active chats are connected to the form's channel, when the public CF7
  form is submitted, then fake Telegram records two `sendMessage` calls.
- Given a unique marker is submitted, then each recorded message text includes
  that marker.
- Given no unrelated chat is connected, then no `sendMessage` call targets that
  chat.
- Given the test completes, then no page errors or unexpected console errors are
  recorded.

Dependencies:

- E6.2.

Notes/Risks:

- CF7 frontend can submit via AJAX; the test should wait on stable CF7
  response/output events instead of arbitrary sleeps.

### E6.4 Admin Bot, Channel, Form And Chat Setup Browser Flow

Status: completed

Goal: cover the setup graph needed for delivery through the real plugin admin UI.

Scope:

- From the real plugin admin UI, create a bot and validate it through fake
  `getMe`/ping.
- From the real plugin admin UI, create a channel.
- Create a real Contact Form 7 form in the isolated WP install, preferably
  through CF7 admin UI; if CF7 admin selectors are too brittle, create via WP
  fixture and still verify it appears/selects through plugin UI.
- Use fake `getUpdates` with `/cf7tg_start` to create a chat through the real
  polling/fetch path.
- Assign bot, form, and chat to a channel through plugin UI controls.
- Remove channel and bot through UI controls and confirm DOM/REST state reflects
  removal.
- Verify destructive actions are explicit and do not delete unrelated chats
  automatically.

Out of scope:

- Deep styling assertions.
- Live Telegram.
- Every selector permutation.
- Contact Form 7 core UI regression testing beyond the form needed by this
  plugin.

DoR:

- E6.2 completed.
- E6.3 happy path proves baseline delivery fixture.
- Stable selectors/roles are available or small accessibility/test-id additions
  are approved.

DoD:

- Browser E2E covers bot create/remove, channel create/remove, form
  visibility/selection, chat discovery, relation assignment, and deletion safety
  against real WordPress REST state.
- The test fails on stale DOM-only success if REST state did not change.
- Local verification passed with admin UI creation/removal, form selection,
  fake `/cf7tg_start` discovery, relation assignment, one-recipient
  admin-built submit delivery, deletion safety, and no page/console errors.

Acceptance criteria:

- Given an empty delivery fixture state, when Create Bot and Create Channel are
  used, then new entities appear in DOM and REST collections.
- Given a CF7 form exists, when plugin admin loads, then the form is available
  for channel assignment and can be connected.
- Given fake `getUpdates` returns `/cf7tg_start` for the configured bot, when
  updates are fetched, then a chat appears and can be assigned.
- Given bot, form, and chat are assigned to the channel, when the public form is
  submitted, then delivery evidence targets the assigned chat only.
- Given a bot/channel is removed, then it disappears from DOM and REST while
  unrelated chats remain.

Dependencies:

- E6.2.
- E6.3.

Notes/Risks:

- Minimal `data-testid` attributes were added to existing controls/containers
  for reliable browser selectors without changing production behavior.
- E6.4 caught and fixed non-pretty REST delete URL construction for bot,
  channel, and chat deletion.

### E6.5 Delivery Failure And Evidence Cases

Status: completed

Goal: prove that Telegram failures are visible in evidence without breaking the
CF7 submission or skipping later recipients.

Scope:

- Use the E6 bot/channel/form/chat delivery graph.
- Script one `sendMessage` failure followed by a success for another recipient.
- Assert CF7 submit is not aborted by Telegram failure.
- Assert later recipients are still attempted.
- Add at least one payload case for Markdown/HTML fallback or long-message
  chunking if feasible in browser E2E.
- Assert evidence/logs do not expose full token or sensitive chat
  fields.

Out of scope:

- Full Telegram error taxonomy.
- Live Telegram.
- Attachments.

DoR:

- E6.2, E6.3, and E6.4 completed.
- Existing PHP delivery unit tests remain green.

DoD:

- E6 summary records pass/fail per recipient and the overall CF7 submit result.
- Failure artifacts are actionable and redacted.
- Local verification passed with CF7 `mail_sent`, a scripted first-recipient
  `sendMessage` failure, a successful later-recipient `sendMessage`, redacted
  token/chat-identity evidence, and no page/console errors.

Acceptance criteria:

- Given the first recipient returns fake Telegram error, when the form is
  submitted, then the browser still observes CF7 completion.
- Given a second recipient is configured, then `sendMessage` is attempted after
  the first failure.
- Given evidence is uploaded, then it does not include raw bot tokens or private
  chat labels/usernames.

Dependencies:

- E6.2.
- E6.3.
- E6.4.

Notes/Risks:

- Keep this focused; detailed formatter behavior already has PHP unit coverage.
- The fixture stores scripted Bot API failures by exact Telegram method name so
  method-case mismatches fail the test instead of silently becoming successes.

### E6.6 CI Gate And Evidence Artifacts

Status: completed

Goal: wire the fake Telegram E2E gate into CI with useful failure artifacts.

Scope:

- Add the E6 smoke script to `.github/workflows/build-zip.yml` for pull request
  and release events.
- Upload E6 evidence artifacts on `always()` for those events.
- Ensure release promotion can reference E6 evidence when appropriate.
- Document local command and expected outputs in a concise test runbook or
  existing test docs.

Out of scope:

- Live Telegram promotion workflow.
- WordPress.org deployment.

DoR:

- E6.2 and E6.3 completed.
- E6 smoke runtime is acceptable for PR/release CI.

DoD:

- CI fails on E6 failures and uploads evidence.
- Local and GitHub commands are documented.
- Existing E1-E5 gates remain green.

Acceptance criteria:

- Given a pull request run, when E6 fails, then the verify job fails.
- Given E6 fails, then summary/logs/Playwright artifacts are uploaded.
- Given E6 passes, then the existing verified ZIP artifact remains reproducible
  and unchanged except for source/test changes.

Dependencies:

- E6.2.
- E6.3.

Notes/Risks:

- Recommended placement: PR and release only, not every push.

## Runbook

Local full smoke:

```bash
scripts/build-release-zip.sh
tests/stability/e6-form-delivery-smoke.sh --skip-browser-install
```

Expected local outputs:

- `summary.json` with `failed_steps: 0`.
- `browser-result.json`, `playwright-report.json`, WordPress logs, Docker
  compose file, and fake Telegram evidence under the E6 results directory.
- Fake Telegram evidence includes sanitized Bot API calls and token hashes, not
  raw bot tokens, private chat labels, or private chat usernames.
- The E6 Playwright report disables and strips CI git diff metadata so source
  diffs cannot reintroduce redaction canaries into uploaded evidence.

GitHub CI:

- `.github/workflows/build-zip.yml` runs
  `tests/stability/e6-form-delivery-smoke.sh --skip-browser-install` on
  `pull_request` and `release` events after the verified candidate ZIP and E5
  browser canary are available.
- CI uploads `cf7-telegram-fake-telegram-e2e-evidence` from
  `test-evidence/fake-telegram-e2e` on `always()` for those events.
- Push-only runs still execute source, unit, release ZIP, audit, and lifecycle
  gates; E6 is intentionally scoped to PR/release runtime.

### QA E6 Independent Fake Telegram Verification

Status: completed

Goal: independently verify E6 against its acceptance criteria before closure.

Scope:

- Review E6 implementation, fixture isolation, fake Telegram fail-closed
  behavior, bot/channel/form/chat browser flows, delivery evidence, redaction,
  and CI wiring.
- Re-run relevant local tests or inspect GitHub artifacts from the PR run.
- Confirm no live Telegram network is used.

Out of scope:

- Implementation.
- Risk acceptance.

DoR:

- E6 implementation tasks completed and committed.
- CI run or local evidence is available.

DoD:

- QA verdict is `pass` with evidence and file/line findings.
- No blocker/high findings remain unresolved before epic closure.

Acceptance criteria:

- Given E6 claims fake transport only, then QA can prove `api.telegram.org`
  calls are intercepted.
- Given E6 claims admin setup coverage, then QA can point to browser evidence
  for bot/channel/form/chat setup and relation assignment.
- Given E6 claims delivery coverage, then QA can point to evidence showing form
  submit and expected `sendMessage` calls.
- Given E6 is in CI, then QA can point to the gating workflow step and uploaded
  artifact.

Dependencies:

- E6.2-E6.6.

Notes/Risks:

- Independent QA initially found evidence redaction and live-egress guard gaps.
  Both were fixed and re-verified before closure.

## Dependencies

- E1-E5 completed on `master`.
- Candidate ZIP build and validation gates from E5.
- Existing Docker/WordPress/Playwright harness patterns.

## Risks

- Contact Form 7 frontend response details can change by version; assertions
  should prefer stable DOM/network outcomes.
- Admin UI selectors may need small accessibility or test-id additions if role
  selectors are brittle.
- The browser gate will add CI runtime, so PR/release placement is preferred.

## Readiness Verdict

E6.1-E6.6 and independent QA are closed. E6 is ready for PR review and merge.
