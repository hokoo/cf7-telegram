# Epic 6 Fake Telegram Form Delivery And Admin Setup E2E

Status: todo, pending execution approval

## Outcome

A candidate ZIP cannot pass PR/release verification unless a real Contact Form 7
public form submission produces the expected Telegram delivery attempts through
deterministic fake Telegram transport, and the bot/channel/form/chat setup flows
behind that delivery graph are covered by browser E2E.

## Current Decision

Recommended contract: fake Telegram transport only.

This avoids live bot tokens, human-in-the-middle Telegram confirmation, and
external network flakiness. The fake transport should still exercise the real
plugin code path by intercepting Telegram HTTP calls at the WordPress transport
boundary and recording structured evidence.

## Scope

- Dedicated isolated WordPress plus Playwright E2E harness.
- Fake Telegram transport that records `getMe`, `getWebhookInfo`, `getUpdates`,
  and `sendMessage`.
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

Status: todo

Goal: lock the fake transport contract, covered entities, and CI placement before
implementation starts.

Acceptance criteria:

- The fake transport records request type, sanitized payload, response, and
  failure category.
- Covered entity flows are explicit: bot, channel, form, chat, relation,
  submitted form message, and recipient attempts.
- CI placement is explicit; recommended default is PR and release gates, not
  every push.

### E6.2 Dedicated Fake Telegram E2E Harness

Status: todo after E6.1

Goal: add an isolated WordPress plus Playwright harness that can run without live
Telegram access.

Acceptance criteria:

- The harness installs the candidate plugin and Contact Form 7.
- The fake transport is active only inside the test environment.
- Evidence is written in a stable, redacted format.

### E6.3 Public CF7 Submit Delivery Happy Path

Status: todo after E6.2

Goal: prove that a real rendered CF7 form submission attempts Telegram delivery
to the expected chats.

Acceptance criteria:

- Playwright submits the public form and observes a successful CF7 result.
- Fake Telegram evidence contains expected `sendMessage` attempts.
- The message payload includes submitted fields and no unexpected recipient.

### E6.4 Admin Bot, Channel, Form And Chat Setup Browser Flow

Status: todo after E6.2

Goal: cover the setup graph needed for delivery through the real plugin admin UI.

Acceptance criteria:

- Bot creation and removal work through the UI.
- Channel creation and removal work through the UI.
- A CF7 form appears in the plugin selector and can be assigned to a channel.
- A chat is discovered from fake updates and assigned to a channel.
- DOM, REST, and fake transport evidence agree on the resulting graph.

### E6.5 Delivery Failure And Evidence Cases

Status: todo after E6.3 and E6.4

Goal: prove that Telegram failures are visible in evidence without breaking the
CF7 submission or skipping later recipients.

Acceptance criteria:

- A configured recipient failure is recorded with a safe error category.
- CF7 submission still completes.
- Later recipients are attempted.
- Logs and artifacts do not expose bot tokens or raw sensitive payloads.

### E6.6 CI Gate And Evidence Artifacts

Status: todo after E6.3-E6.5

Goal: wire the fake Telegram E2E gate into CI with useful failure artifacts.

Acceptance criteria:

- PR/release CI runs the new gate.
- Evidence summary is uploaded on success and failure.
- Playwright trace, screenshot, and video are uploaded on browser failure.
- The gate fails closed when expected delivery evidence is missing.

### QA E6 Independent Fake Telegram Verification

Status: todo after E6.6

Goal: independently verify E6 against its acceptance criteria before closure.

Acceptance criteria:

- QA checks public submit, bot/channel/form/chat setup, partial failure behavior,
  CI wiring, and evidence redaction.
- No blocker/high/medium findings remain unresolved.

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
