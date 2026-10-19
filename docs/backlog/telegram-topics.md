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

Status: completed
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

Status: completed
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

Status: review
Goal/Scope: extend existing E6 fixture/spec/required-check list; verify candidate
ZIP through real CF7 and admin UI using fake Telegram, no new harness.
DoR/Dependencies: B1/B2 accepted; isolated Docker/Playwright runtime available.
AC: two forms/bridges to distinct topics in one group; ordinary delivery;
idempotent discovery; explicit assignment; topic failure/recipient continuity;
new assertions mandatory in the E6 evidence gate; no token leakage; real
browser confirms a long qualified bot command fits the bot card beside its
token, copies in full, and group/topic labels remain readable.
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
- Root accepted B1 commit `99eeb8d7bac3a275b880b346b64521d567737f60` after checking actual hunks, paths, evidence and post-commit status. Owner and descendants stopped; owner archived with native CLI, exit 0. B2 then became ready. No publication or real Telegram traffic.
- B2 admin implementation is technically accepted against B1 commit `99eeb8d7bac3a275b880b346b64521d567737f60`; scoped nine-path diff SHA-256 before commit: `2073bf6c102cb8d60f85e2094a3bdd54b615b16b3e7dfab15be574418818ab21`. Changed `react/src/App.scss`, components `Bot`, `BotView`, `ChannelView` and four existing React tests, plus `plugin-dir/readme.txt`. Topic rows show group context and thread ID through rename/Restore; the copied command addresses the current bot; topic approval leaves bridge choice explicit, while default recipients retain auto-attachment. Existing REST identity and permissions are unchanged. `install/setup.sh` remains unrelated and unstaged.
- B2 focused worker gate: four React suites/27 tests, then `BotView.test.js` 7/7 after the CSS fit repair; Sass compiled and `git diff --check` passed. Fresh monitor serial full gate before CSS: `cd plugin-dir && composer test` exit 0, 108/0 using the existing PHP compatibility fallback; `cd plugin-dir/react && CI=true npm test -- --watchAll=false --runInBand` exit 0, 8 suites/56 tests; `npm run build` exit 0; diff check exit 0. After CSS, fresh monitor repeated affected React suite 8/56, build and diff check, all exit 0; scoped hash unchanged within that gate; no test/build processes or generated tracked artifacts remain. PHP was unchanged, so its result stands. Worker Chromium measurement exited `Operation not permitted` in sandbox; B3 real-browser AC covers visual fit and readable labels.
- B2 owner `/root/topics_admin` native ID `01a1550d-206e-7173-aac3-a4c3cc968d65`, selected `gpt-6-sol/high`. Worker `/root/topics_admin/b2_ui` native ID `01a1550d-7d3b-7b83-a74e-20d352e4153c` (pinned `gpt-6-sol/high`) and first monitor `/root/topics_admin/b2_gate` native ID `01a15511-7333-7ef0-99c8-29636ec05a84` (pinned `gpt-6-luna/low`) stopped and archived by root, exit 0. Affected-gate monitor `/root/topics_admin/b2_css_gate` native ID `01a15513-8398-7a12-82cb-0ca69907d912` (pinned `gpt-6-luna/low`) stopped; root handles lifecycle cleanup. B3 is ready after the B2 scoped commit; no publication or real Telegram traffic.
- Root accepted B2 commit `f39bfc3274196121190f57c1322bfaea75d784d7` after actual diff/status review. Final monitor and owner archived with native CLI, both exit 0; descendants and commands stopped. B3 proceeds with the real-browser checks above.
- B3 E6 harness is technically accepted on B2 commit `f39bfc3274196121190f57c1322bfaea75d784d7`; scoped precommit diff SHA-256 `a5b67cb4f0c293da3854f3e53900b60d824c24c5fc3f274f12465227d7d732e6`, code commit `8bd6d9f78b0fe4fa299cd89af727908985f0ff46`. Changed only `tests/e2e/e6-form-delivery.spec.js`, `tests/stability/wp-e6-form-delivery-fixture.php`, and `tests/stability/e6-form-delivery-smoke.sh`. The existing fake transport now exercises two distinct topics plus General and an ordinary chat, two forms/bridges, repeated discovery, explicit topic assignment, custom labels, isolated toggles, a 32-character qualified command with full clipboard copy, and thread-scoped failure without reroute. Twelve topic check IDs are required in both browser and shell evidence. `install/setup.sh` remains unrelated and unstaged.
- B3 focused `php -l`, `node --check`, `bash -n`, `git diff --check`, check-ID consistency, and Playwright test discovery passed. `scripts/build-release-zip.sh` first exited 1 on sandbox npm DNS `EAI_AGAIN`; one escalated retry exited 0. Candidate `dist/cf7-telegram-wp-plugin.zip` is version 1.2.0, SHA-256 `8f810a253ccc806351bb6600231497d3fc21ecca78a197890f6400faadbe8c0b`. Build attempts were captured in command sessions; no separate build log was configured. The first E6 sandbox run exited 1 before browser checks on denied Docker socket; its failed summary is `/tmp/cf7tg-topics-b3-e6-20261019/results/summary.json`. Escalated E6 exited 0: 57 passed steps, 0 failures, browser 37/37 required checks including all 12 topic checks and 4/4 Playwright tests. Evidence: `/tmp/cf7tg-topics-b3-e6-20261019-escalated/results/{summary.json,browser-result.json,logs/}`. E4 exited 0: 11/11 steps, 24/24 checks at `/tmp/cf7tg-topics-b3-e4-20261019/results/{summary.json,e4-rest-ui-smoke.json,logs/}`. E1 exited 0: 102/102 steps; fresh 20/20, upgrade-0.8.1 35/35, upgrade-1.1.0 35/35 at `/tmp/cf7tg-topics-b3-e1-20261019/results/{summary.json,logs/,state/,rollback/}`. All successful runtime checks used that exact candidate on WP 7.1, PHP 8.3.33, CF7 6.2. B1/B2 unchanged PHP 108/0 and React 8 suites/56 tests carry forward. Read-only escalated `docker ps --format '{{.Names}}'` exited 0 empty; no build/test processes remain.
- B3 runtime commands from repo root: `CF7TG_E6_WORKDIR=/tmp/cf7tg-topics-b3-e6-20261019-escalated/work CF7TG_E6_RESULTS_DIR=/tmp/cf7tg-topics-b3-e6-20261019-escalated/results tests/stability/e6-form-delivery-smoke.sh --candidate-zip "$PWD/dist/cf7-telegram-wp-plugin.zip" --skip-browser-install`; `CF7TG_E4_SMOKE_WORKDIR=/tmp/cf7tg-topics-b3-e4-20261019/work CF7TG_E4_SMOKE_RESULTS_DIR=/tmp/cf7tg-topics-b3-e4-20261019/results tests/stability/e4-rest-ui-smoke.sh --candidate-zip "$PWD/dist/cf7-telegram-wp-plugin.zip"`; `CF7TG_CANDIDATE_ZIP="$PWD/dist/cf7-telegram-wp-plugin.zip" CF7TG_E1_WORKDIR=/tmp/cf7tg-topics-b3-e1-20261019/work CF7TG_E1_RESULTS_DIR=/tmp/cf7tg-topics-b3-e1-20261019/results tests/stability/e1-smoke-matrix.sh --case fresh --case upgrade-0.8.1 --case upgrade-1.1.0`. First E6 sandbox attempt used the same arguments with `/tmp/cf7tg-topics-b3-e6-20261019/{work,results}`.
- B3 owner `/root/topics_e2e` native ID `01a15515-dd4e-7cf2-beeb-bafadd509f51`, selected `gpt-6-sol/high`; worker `/root/topics_e2e/b3_harness` native ID `01a15516-3461-7983-b3c0-d89638448ceb` (pinned `gpt-6-sol/high`) stopped and archived by root, exit 0. Monitor `/root/topics_e2e/b3_gate` native ID `01a1551b-9160-7bb2-ac4d-127721fd7663` (pinned `gpt-6-luna/low`) stopped; root handles lifecycle cleanup. B3 remains in review pending root acceptance and fresh independent epic QA against committed B1–B3. No push/publication or real Telegram traffic.
- At most one active owner and writer; root -> fresh owner -> pinned specialists;
  root launches fresh epic_qa. Specialists do not delegate.
- Root edits only this concise bookkeeping; product/tests/docs belong to workers.
- Daemon discovered with `codex app-server daemon version` (escalated read):
  `unix:///home/itron/.codex/app-server-control/app-server-control.sock`, v0.163.0.
  Archive only accepted/stopped branches using reported native thread IDs.
