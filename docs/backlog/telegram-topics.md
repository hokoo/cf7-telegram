# Telegram forum topics delivery

Execution authorized by the user on 2026-10-19 after the chat analysis/plan.
Root coordinates with ponytail, delivery-owner and decompose-work. Baseline:
`8fd2372`. Existing unrelated change: `install/setup.sh` (preserve, never stage).
Delivery: verified scoped commits and independent epic QA; no push/publication.

## Contract

- Reuse `Chat` and existing relations, with optional `messageThreadID` stored
  through `setParam`; identity is `(chatID, normalized messageThreadID)`.
- Scope: forum supergroup topics. Ordinary chats keep their current behavior.
  General uses the default destination without an outgoing thread parameter.
  Verify General normalization against official Telegram sources before coding;
  do not mistake ordinary reply threads for forum topics or silently turn an
  invalid/unsupported topic destination into the default chat.
- Discover through `/cf7tg_start@BotName` inside the topic, retain pending
  approval and offset/retry safety. Repeated discovery is idempotent. Multiple
  topics in one updates batch must survive deduplication.
- Old records without the field mean default destination. Legacy importer
  must only reuse default records, never a topic with the same chat ID.
- Send the thread on every chunk and plaintext fallback. A failed topic never
  falls back to General; other recipients and the CF7 submission continue.
  Preserve published hook signatures and legacy aggregate shape; expose thread
  information in modern delivery results. Preserve safe logging/redaction.
- Reuse rename/restore and existing bridge selection. Distinguish topics even
  when custom labels match. REST exposes read-only normalized thread identity.
  `getChat`/Restore must not clear or change destination identity.
- New topic approval makes it selectable; explicitly enable it in chosen
  bridges, instead of auto-attaching it to every bridge. Preserve the existing
  activation flow for ordinary chats. Explain the extra selection in UI copy.
- Excluded: topic creation/deletion/closing, automatic title synchronization,
  channel direct-message topics, private-chat forum topics, webhook changes,
  dependencies, mass migrations, unrelated backlog B1, release version bumps.

## Tasks and batches

Shared DoD: focused regression evidence, actual diff review, scoped commit;
the feature closes only after the full gate and independent QA pass. Exclusions
are the Contract exclusions. No task authorizes real Telegram messages.

### B1 — recipient identity, discovery and delivery (plan tasks 1–2)

Status: review
Goal/Scope: implement the backend contract in Chat, Util, Bot, Channel,
LegacyImporter and relevant existing PHP tests; additive REST identity if useful.
DoR: implementation authorized; General evidence is a bounded technical check
inside this assignment, with escalation only for a material contradiction.
AC: distinct same-group topics; repeated/batched updates and legacy import are
safe; unchanged default chats; validated identity; all chunks/retries retain
thread; no reroute on failure; modern results distinguish topics; hooks stable.
DoD: shared DoD; PHP suite passes after writer stops, using test_monitor.
Dependencies: none.
Notes/Risks: General semantics, partial retries, legacy records sharing chat IDs.
Owner selection: `gpt-6-sol`, `high`; bounded PHP change with compatibility risk,
adequately covered by the existing tests. Specialists use pinned role defaults.

### B2 — admin setup and recipient display (plan task 3)

Status: waiting_dependency
Goal/Scope: REST response, Bot/BotView/ChannelView as required, focused React/REST
tests and concise plugin usage copy. Reuse existing components and relations.
DoR/Dependencies: B1 accepted commit and documented identity contract.
AC: separate visible topic destinations, readable command/setup guidance,
explicit bridge selection for new topics, ordinary activation unchanged,
rename/restore preserve identity and labels remain distinguishable.
DoD: shared DoD; relevant PHP/React checks and production build via test_monitor.
Notes/Risks: pending/active transitions and existing rename behavior.
Owner selection: `gpt-6-sol`, `high`; established React/REST patterns, narrow UX.

### B3 — end-to-end acceptance (plan task 4)

Status: waiting_dependency
Goal/Scope: extend existing E6 fixture/spec/required-check list; verify candidate
ZIP through real CF7 and admin UI using fake Telegram, no new harness.
DoR/Dependencies: B1/B2 accepted; isolated Docker/Playwright runtime available.
AC: two forms/bridges to distinct topics in one group; ordinary delivery;
idempotent discovery; explicit assignment; topic failure/recipient continuity;
new assertions mandatory in the E6 evidence gate; no token leakage.
DoD: shared DoD; PHP and React suites, production ZIP build, E6 and relevant
existing compatibility gates on the candidate; fresh independent epic QA.
Notes/Risks: fake examples must match verified Telegram semantics; shared-state
checks serial; absent required runtime evidence is not a pass.
Owner selection: `gpt-6-sol`, `high`; integration work with existing E6 harness.

## Checkpoint

- B1 backend implementation is frozen and technically accepted on baseline `8fd2372`; scoped PHP diff SHA-256 before commit: `97e61f984af307f5262715a1001be08aedab41a13f104ff64a07d3a8ba1b4c42`. Nine PHP files changed: `lib/{Chat,Util,Bot,Channel}.php`, `lib/Migrations/LegacyImporter.php`, `lib/Controllers/RestApi/ChatController.php`, and three corresponding existing PHP tests. `install/setup.sh` remains unrelated and unstaged.
- General normalization follows official Telegram forum/Bot API docs: https://core.telegram.org/api/forum and https://core.telegram.org/bots/api . General is the default destination with no outgoing thread; non-General topic identity is `(chatID, messageThreadID)`. Private forum and channel direct-message updates are consumed without recipient creation. Public send filters retain existing behavior.
- Focused worker check: 33 selected PHP tests, 0 failures; `php -l` changed files and `git diff --check` passed. Fresh monitor full gate: `cd plugin-dir && composer test` exit 0, 108 tests/0 failures using the existing compatibility fallback (PHP 8.0.30 lacks `dom`, `mbstring`, `xmlwriter`); `git diff --check` exit 0; `php -l` all nine changed PHP files exit 0. Frozen diff hash unchanged; no PHP/Composer process remains. Earlier focused harness setup/fixture failures were corrected before the final pass.
- Owner `/root/topics_backend` native ID `01a15502-8526-7381-8f5c-bda5b1a6b175`, selected `gpt-6-sol/high`. Worker `/root/topics_backend/b1_php` native ID `01a15502-f5a8-71d3-8800-f1192e361695` (pinned `gpt-6-sol/high`) stopped and archived by root, exit 0. Monitor `/root/topics_backend/b1_gate` native ID `01a1550a-f4a7-7a51-90b8-628870f25873` (pinned `gpt-6-luna/low`) stopped and archived by root, exit 0; both absent from subsequent agent state.
- B1 remains in review until root accepts the scoped commit. Then B2 is the next ready batch; no B1 publication or real Telegram traffic was authorized.
- At most one active owner and writer; root -> fresh owner -> pinned specialists;
  root launches fresh epic_qa. Specialists do not delegate.
- Root edits only this concise bookkeeping; product/tests/docs belong to workers.
- Daemon discovered with `codex app-server daemon version` (escalated read):
  `unix:///home/itron/.codex/app-server-control/app-server-control.sock`, v0.163.0.
  Archive only accepted/stopped branches using reported native thread IDs.
