# Epic 7 Test Suite Taxonomy Refactor

Status: deferred, planning PR only

## Outcome

After the stabilized release line has had enough rollout feedback, reorganize the
test suite around test type and responsibility instead of delivery chronology,
while preserving every E1-E6 coverage guarantee, CI gate, release artifact, and
historical evidence link.

The end state should make it obvious where to add a new test:

- PHP behavior and model tests belong with the plugin package test runner.
- React component and utility tests belong with the React package test runner.
- WordPress integration tests belong with isolated WordPress fixtures.
- Browser E2E tests belong with Playwright flows.
- Release, ZIP, audit, promotion, and artifact gates belong with release gates.
- Shared fixtures, fake transports, and evidence helpers belong in support code.

## Current Context

E1-E6 were intentionally named by stabilization epic because delivery
traceability mattered more than long-term information architecture:

- E1 created lifecycle and upgrade smoke coverage.
- E2 characterized semantic migrations and damaged legacy data.
- E3 hardened Telegram delivery and SDK replacement behavior through PHP tests.
- E4 added REST/admin diagnostics and real WordPress REST smoke coverage.
- E5 hardened release delivery, ZIP validation, Plugin Check, browser canary,
  promotion checks, and support matrix evidence.
- E6 added fake Telegram form-delivery E2E, admin setup flow coverage, partial
  delivery failure evidence, CI artifacts, redaction, and egress guards.

That chronology is still useful for release audit trails, but it is becoming a
poor default mental model for day-to-day test maintenance.

## Problem Statement

The repository now has several legitimate test families:

- `plugin-dir/tests/*.php` PHPUnit and compatibility tests.
- `plugin-dir/react/src/**/*.test.js` React unit/component tests through
  `@wordpress/scripts`.
- `tests/stability/*.sh` shell gates for lifecycle, WordPress integration,
  release ZIPs, Plugin Check, SVN promotion, browser smoke, support matrix, and
  fake Telegram delivery.
- `tests/stability/wp-*.php` WP-CLI fixtures and WordPress integration helpers.
- `tests/e2e/*.spec.js` Playwright browser flows and configs.
- `.github/workflows/*.yml` CI/release wiring that calls those commands.

New test authors currently need to know which historical epic introduced a
command before they know where to put related coverage. E7 should remove that
maintenance cost without breaking release confidence.

## Goals

- Define a durable suite taxonomy by responsibility and runtime.
- Preserve all E1-E6 coverage and evidence semantics.
- Keep old commands working through compatibility wrappers for at least one
  refactor release cycle.
- Make CI command names and artifact names understandable without reading the
  stabilization history.
- Extract shared harness code only where it removes real duplication and does
  not hide important test intent.
- Document how old epic-named commands map to the new taxonomy.
- Provide a no-coverage-loss QA gate before the taxonomy refactor can be merged.

## Out Of Scope

- New product behavior.
- New Telegram delivery features.
- Live Telegram API testing.
- Replacing E6 fake Telegram delivery coverage.
- Changing the release acceptance bar except for command/path names.
- Removing historical E1-E6 documentation.
- Large rewrites inside tests that should be pure moves or thin wrapper changes.
- Dropping existing artifact names before the approved compatibility window
  expires.

## Proposed Target Taxonomy

The exact destination paths are a decision gate, but the recommended shape is:

| Suite | Recommended Home | Current Examples |
| --- | --- | --- |
| PHP unit/model | `plugin-dir/tests/` initially, optionally split into subdirectories later | `TelegramGatewayTest.php`, `MigrationTest.php`, `RestBotControllerTest.php` |
| React unit/component | `plugin-dir/react/src/**/*.test.js` | `Bot.test.js`, `Channel.test.js`, `api.test.js` |
| WordPress integration | `tests/integration/wp/` | `wp-e2-migration-characterization.php`, `wp-e4-rest-ui-smoke.php`, lifecycle WP helpers |
| Browser E2E | `tests/e2e/` with descriptive non-epic names | E5 browser canary, E6 fake Telegram form delivery |
| Release gates | `tests/release/` | Plugin Check, ZIP hygiene, support matrix, SVN promotion checks |
| Shared shell support | `tests/support/shell/` | Docker/WordPress bootstrapping, evidence helpers, artifact helpers |
| Shared WP support | `tests/support/wp/` | WP-CLI fixtures, state snapshots, fake Telegram transport helpers |
| Shared E2E support | `tests/support/e2e/` | Playwright login, REST helpers, redaction helpers |
| Historical mapping | `docs/stability/e7-coverage-map.md` | Old E1-E6 command to new path map |

PHP and React tests already live near their package runners. E7 should not move
them merely for symmetry unless the approved mapping shows real maintenance
value and CI risk is low.

## Migration Principles

- Treat this as a taxonomy refactor, not a behavior refactor.
- Move or wrap in small batches with green CI between batches.
- Keep old epic-named entry points as wrappers during the first refactor release
  cycle unless the owner explicitly approves a harder rename.
- Prefer compatibility wrappers that call the new path and emit a concise
  deprecation note in local output.
- Preserve artifact upload semantics; if artifact names change, keep a mapping
  and transitional aliases where practical.
- Preserve E1-E6 docs as historical delivery evidence.
- Avoid introducing a new generic framework around the harness unless duplicate
  shell/PHP/Playwright logic is clearly causing maintenance risk.
- Before merging, prove old commands, new commands, and GitHub CI all agree.

## Decision Gates

E7 cannot enter implementation until the owner approves:

- rollout timing after stabilized-release feedback;
- final taxonomy and destination paths;
- compatibility wrapper duration;
- artifact naming policy and whether transitional aliases are required;
- whether PHP tests stay package-local or split into subdirectories;
- whether shared harness extraction happens before, during, or after file moves.

Recommended default:

- wait until the current stabilized version has real-user exposure without a
  blocker regression;
- keep old command wrappers for one release cycle;
- keep PHP and React package-local tests in place initially;
- move WordPress integration, browser E2E, release gates, and support helpers
  first because those are where epic chronology is most visible.

## Execution Sequence

1. Approve E7 readiness and taxonomy contract.
2. Inventory current tests, commands, workflows, artifacts, and docs.
3. Publish an old-to-new coverage map before moving files.
4. Add target directories and compatibility wrapper policy.
5. Extract shared helpers only where needed for low-risk moves.
6. Move/rehome suites by type in bounded batches.
7. Update CI, docs, artifact names, and runbooks.
8. Run independent no-coverage-loss QA.

## Execution Tasks

### E7.1 Post-rollout Readiness And Taxonomy Contract

Status: deferred

Goal: decide whether E7 is safe to start and lock the taxonomy contract before
any test paths move.

Scope:

- Review feedback from the stabilized release line.
- Confirm no active stability regression has higher priority than test
  organization.
- Approve the target taxonomy and destination paths.
- Approve compatibility wrapper duration.
- Approve artifact naming and historical mapping policy.
- Decide whether PHP and React tests remain package-local for the first pass.

Out of Scope:

- Moving files.
- Extracting helpers.
- Changing CI commands.
- Creating new product or delivery coverage.

DoR:

- E6 is merged or explicitly accepted as the base for E7.
- The owner has enough rollout feedback to make a timing decision.
- Current PR/release CI is green on the base branch.

DoD:

- Owner decision is recorded in this document or a follow-up ADR.
- Final taxonomy and command compatibility policy are explicit.
- E7.2 can move from `deferred` to `todo` without another planning pass.

AC:

- Given E7 is approved, when an implementer reads the contract, then they know
  which suite categories and destination paths are authoritative.
- Given old commands are currently used in docs and CI, when E7 starts, then the
  wrapper/deprecation policy is explicit.
- Given a high-priority stability regression exists, when E7.1 is reviewed,
  then E7 remains deferred.

Dependencies:

- E6 completed and accepted.
- Stabilized release feedback window.
- Owner approval.

Notes/Risks:

- This is the main human decision gate. Starting E7 too early risks noisy path
  churn while user-facing stability feedback is still arriving.

### E7.2 Current Coverage Inventory And Old-to-new Map

Status: deferred

Goal: create a complete source-of-truth map from current test files, commands,
CI steps, and evidence artifacts to the approved taxonomy.

Scope:

- Inventory `plugin-dir/tests/*.php`.
- Inventory `plugin-dir/react/src/**/*.test.js`.
- Inventory `tests/stability/*.sh`, `tests/stability/wp-*.php`, and
  `tests/stability/fixtures/*`.
- Inventory `tests/e2e/*.spec.js` and Playwright configs.
- Inventory `.github/workflows/*.yml` callers.
- Inventory docs that reference old paths.
- Produce `docs/stability/e7-coverage-map.md` with old path, current purpose,
  proposed destination, wrapper policy, artifact impact, and verification owner.

Out of Scope:

- Moving files.
- Editing CI.
- Consolidating helper code.

DoR:

- E7.1 approved.
- Base branch is green.

DoD:

- Every E1-E6 test and gate has a destination or an explicit "keep in place"
  decision.
- Every workflow command has a new command or wrapper plan.
- Every artifact name/path has a migration decision.
- The map is committed before any move commit.

AC:

- Given a current path such as `tests/stability/e6-form-delivery-smoke.sh`, when
  the map is read, then its future location, wrapper path, CI caller, and
  artifact policy are known.
- Given a historical document references E1-E6 evidence, when E7 is complete,
  then the map still explains how to find that evidence.
- Given an unmapped file exists under `tests/` or `plugin-dir/tests/`, when E7.2
  completes, then CI fails or the task is not marked complete.

Dependencies:

- E7.1.

Notes/Risks:

- The inventory should be generated from the repository, not hand-written from
  memory.

### E7.3 Command Contract, Wrapper Policy And Target Directories

Status: deferred

Goal: add the new suite command contract and compatibility wrappers without
changing test behavior.

Scope:

- Create approved target directories.
- Add wrappers at old epic-named paths where paths move.
- Define stable command names for local use and CI, for example:
  - PHP: `composer test` and `composer test:phpunit`.
  - React: `npm --prefix plugin-dir/react test -- --watchAll=false`.
  - WordPress integration: one or more `tests/integration/...` scripts.
  - Browser E2E: one or more `tests/e2e/...` scripts/configs.
  - Release gates: `tests/release/...` scripts.
- Ensure wrappers return the same exit code as the new command.
- Document whether wrappers print deprecation notices.

Out of Scope:

- Moving substantive test bodies unless needed to prove wrappers.
- Changing acceptance criteria.
- Changing GitHub artifact names.

DoR:

- E7.1 and E7.2 completed.
- Approved command names and wrapper duration are documented.

DoD:

- Old and new command entry points exist for the first moved batch.
- Wrappers are shellcheck-light readable and preserve arguments.
- Local smoke commands prove wrapper exit-code passthrough.

AC:

- Given a developer runs an old command, when the wrapper calls the new command,
  then the old command exits with the same status.
- Given a command accepts arguments today, when the wrapper is used, then those
  arguments are forwarded.
- Given no file has moved yet, when wrappers are added, then CI remains green.

Dependencies:

- E7.2.

Notes/Risks:

- Wrappers reduce migration risk but can become permanent clutter. E7 should
  record an explicit removal gate for the compatibility window.

### E7.4 Shared Fixture And Harness Consolidation

Status: deferred

Goal: extract shared shell, WP-CLI, Playwright, fake transport, and evidence
helpers only where doing so lowers future maintenance risk.

Scope:

- Identify duplicated Docker/WordPress setup code across E1/E4/E5/E6 scripts.
- Identify duplicated Playwright login, REST, error capture, and redaction
  helpers across E5/E6 browser specs.
- Identify reusable fake Telegram/evidence helpers from E6 that could support
  future E2E coverage.
- Extract small support modules under approved `tests/support/*` paths.
- Keep fixture APIs explicit and documented.

Out of Scope:

- Rewriting every existing smoke script into a framework.
- Changing evidence schemas unless approved.
- Changing tested behavior.

DoR:

- E7.2 coverage map completed.
- E7.3 target support paths approved.
- Duplicated code candidates are listed with expected benefit.

DoD:

- Extracted helpers have focused self-checks or are covered by the existing
  smoke scripts that consume them.
- At least one old and one new command path prove the extracted helper works.
- No evidence schema regression occurs.

AC:

- Given a helper is extracted, when the original suite runs, then it produces
  equivalent pass/fail evidence.
- Given a helper is not clearly reused, when E7.4 is reviewed, then it remains
  inline.
- Given fake Telegram helpers are reused, when evidence is uploaded, then token
  and private chat redaction guarantees remain intact.

Dependencies:

- E7.2.
- E7.3.

Notes/Risks:

- Over-abstracting the harness would make failure logs harder to debug. Extract
  only obvious duplication.

### E7.5 Rehome Suites By Test Type

Status: deferred

Goal: move test files and fixtures into the approved taxonomy in small batches
without losing coverage.

Scope:

- Move WordPress integration scripts and WP fixtures to the approved
  integration/support paths.
- Move or rename browser E2E specs/configs to descriptive non-epic names where
  approved.
- Move release ZIP, Plugin Check, support matrix, SVN promotion, and audit
  scripts to the approved release-gate paths.
- Keep PHP and React tests package-local unless E7.1 explicitly approves a
  split.
- Preserve old epic-named wrappers for moved commands.
- Update internal relative paths safely.

Out of Scope:

- Adding unrelated test cases.
- Changing production code.
- Removing old wrappers before the compatibility window.
- Reorganizing historical docs.

DoR:

- E7.2 map completed.
- E7.3 wrappers exist for the first moved batch.
- E7.4 helper extraction completed or explicitly skipped for the batch.

DoD:

- Moved suites pass through new paths and old wrapper paths.
- CI references either the new paths or the approved wrappers.
- No release artifact includes forbidden test files due to path changes.
- `scripts/build-release-zip.sh` and `scripts/validate-release-zip.sh` still
  enforce artifact hygiene.

AC:

- Given a suite is moved, when the old command is run, then it still works.
- Given a suite is moved, when the new command is run, then it produces the same
  evidence class.
- Given release ZIP validation runs, when test paths changed, then tests/support
  files are still excluded from the plugin ZIP.
- Given a Playwright config moved, when browser tests run in CI, then traces,
  screenshots, videos, and JSON reports are still uploaded on failure.

Dependencies:

- E7.2.
- E7.3.
- E7.4 as applicable.

Notes/Risks:

- This is the highest churn task. It should be split into sub-batches during
  execution, likely integration first, release gates second, browser E2E third.

### E7.6 CI, Artifact And Release Evidence Migration

Status: deferred

Goal: update CI and release workflows to the new command names and artifact
paths while keeping release evidence traceable.

Scope:

- Update `.github/workflows/build-zip.yml`.
- Update `.github/workflows/promote-wordpress-org.yml` if path changes affect
  promotion evidence.
- Update artifact upload paths and names only according to the approved policy.
- Keep old artifact names, aliases, or mapping docs during the compatibility
  window where required.
- Ensure PR, push, release, and workflow_dispatch paths still run the intended
  subset of gates.

Out of Scope:

- Changing release publication policy.
- Adding new external services.
- Introducing live Telegram credentials.

DoR:

- E7.5 moved at least one complete suite batch.
- The artifact naming policy from E7.1 is approved.
- CI path changes are listed in the E7 map.

DoD:

- GitHub PR CI is green.
- Release-event dry-run logic or documented inspection confirms release-only
  branches still call the correct scripts.
- Artifact uploads point to existing paths and fail closed when required
  evidence is missing.

AC:

- Given a pull request run, when E7 paths are used, then all required PR gates
  run and upload evidence.
- Given a push run, when PR-only gates are skipped, then source/unit/ZIP gates
  still run.
- Given a release run, when release-only gates execute, then support matrix and
  promotion evidence paths still exist.
- Given an artifact path is stale, when CI runs, then the job fails instead of
  silently passing.

Dependencies:

- E7.5.

Notes/Risks:

- Workflow changes should be reviewed against event-specific behavior; push,
  pull_request, release, and workflow_dispatch do not run identical gate sets.

### E7.7 Documentation And Contributor Runbook Update

Status: deferred

Goal: make the new taxonomy understandable without relying on delivery history.

Scope:

- Update stability roadmap references.
- Update E1-E6 historical docs only where links need a compatibility note.
- Add or update contributor-facing test runbook content.
- Document old-to-new command mapping.
- Document when compatibility wrappers may be removed.
- Document where new tests should be added by type.

Out of Scope:

- Rewriting historical epic narratives.
- Changing changelog or release notes unless E7 ships in a release.

DoR:

- E7.2 map exists.
- E7.5/E7.6 final paths and commands are known.

DoD:

- Repository docs reference the new suite layout.
- Historical evidence links are still resolvable through the map.
- Local and CI command examples are current.

AC:

- Given a contributor wants to add a PHP unit test, when they read the runbook,
  then the expected location and command are clear.
- Given a contributor wants to add browser E2E coverage, when they read the
  runbook, then the Playwright location, fixture expectations, and artifact
  behavior are clear.
- Given someone audits E1-E6 work later, when they read historical docs, then
  they can follow the old-to-new mapping.

Dependencies:

- E7.2.
- E7.5.
- E7.6.

Notes/Risks:

- Documentation should describe the taxonomy, not the implementation chronology.

### QA E7 Independent No-coverage-loss Verification

Status: deferred

Goal: independently verify that E7 preserved coverage, CI behavior, evidence
artifacts, and developer ergonomics.

Scope:

- Review the E7 coverage map against the final diff.
- Run representative old wrapper commands and new commands.
- Inspect GitHub CI PR evidence.
- Verify E1-E6 guarantees are still covered.
- Verify release ZIP hygiene still excludes tests/support artifacts.
- Verify docs and runbooks reference current paths.

Out of Scope:

- Implementing fixes.
- Accepting unresolved high/medium findings without owner approval.

DoR:

- E7.1-E7.7 completed.
- PR CI evidence is available.
- No uncommitted implementation changes remain.

DoD:

- Independent QA verdict is `pass`, `pass_with_notes`, or `fail`.
- Any blocker/high findings are fixed before E7 closure.
- Any medium findings are fixed or explicitly accepted by the owner.

AC:

- Given every old command in the compatibility policy, when QA runs it, then it
  succeeds or fails for the same reason as the new command.
- Given every mapped suite, when QA samples it, then no coverage area has
  disappeared.
- Given GitHub Actions completed, when QA inspects artifacts, then required
  evidence exists under the approved names or aliases.
- Given release ZIP validation runs, when QA inspects the candidate ZIP, then no
  test/support files are shipped.

Dependencies:

- E7.1-E7.7.

Notes/Risks:

- This QA should be independent from the implementation owner because E7 is
  mostly path and workflow churn, where self-review misses stale references.

## Readiness Verdict

E7 is documented but not execution-ready. It should remain deferred until the
owner approves the post-rollout timing and taxonomy contract in E7.1.

The first executable PR after this planning PR should be E7.1 only. File moves,
wrappers, and CI migration should wait until E7.1 and E7.2 are complete.
