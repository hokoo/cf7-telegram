# Epic 7 Test Suite Taxonomy Refactor

Status: deferred

## Outcome

After the stabilized version has enough rollout feedback, reorganize the test
suite by test type instead of delivery chronology while preserving coverage, CI
gates, and release evidence traceability.

## Rationale

The current `e1`, `e4`, `e5`, and planned `e6` test names are useful while the
work is delivery-driven: they make it easy to prove which stabilization epic
closed which risk. They are less useful as the permanent information
architecture of the test suite.

E7 is intentionally delayed so broad path churn does not compete with active
stability feedback from users of the `1.0.13` line.

## Candidate Taxonomy

- PHP unit/model tests.
- React unit/component tests.
- WordPress integration tests.
- Browser E2E tests.
- Release and artifact gates.
- Shared fixtures.
- Fake transports.
- Evidence helpers.

## Out Of Scope

- New product behavior.
- Live Telegram API coverage.
- Replacing E6 fake Telegram delivery coverage.
- Release policy changes beyond command names and evidence paths.
- Large behavioral rewrites inside tests that should be pure moves.

## Decision Gate

E7 cannot start until the owner approves:

- rollout timing after stabilized-release feedback;
- final suite taxonomy and destination paths;
- compatibility wrapper policy for old epic-named commands.

Recommended default: keep wrappers for old commands during the first refactor
release cycle to reduce CI and contributor friction.

## Initial Task Plan

### E7.1 Post-rollout Readiness And Taxonomy Contract

Status: deferred

Goal: approve the timing, taxonomy, destination paths, and wrapper policy before
any test files move.

Acceptance criteria:

- The owner confirms there are no higher-priority stability regressions.
- The suite taxonomy is explicit.
- Wrapper-vs-rename behavior is recorded.

### E7.2 Current Coverage Inventory And Old-to-new Map

Status: deferred

Goal: produce a complete mapping from current epic-named tests and evidence
artifacts to the target taxonomy.

Acceptance criteria:

- Every current E1-E6 test path has a destination.
- Every CI command has a replacement or wrapper.
- Historical evidence remains traceable.

### E7.3 Shared Fixtures And Fake Transport Consolidation

Status: deferred

Goal: extract shared WordPress, Playwright, and fake Telegram fixtures only where
they remove real duplication.

Acceptance criteria:

- Existing tests produce equivalent evidence after extraction.
- Fixture APIs are documented.
- No unrelated behavior changes are introduced.

### E7.4 Move Tests By Suite Type With Compatibility Wrappers

Status: deferred

Goal: move or rename tests into the approved taxonomy while preserving current CI
behavior.

Acceptance criteria:

- Old commands either continue to work or all callers are updated in the same
  branch.
- New paths are predictable from suite type.
- Local and GitHub CI pass after the move.

### E7.5 CI, Docs And Evidence Artifact Migration

Status: deferred

Goal: update workflows, runbooks, and evidence paths to match the new taxonomy.

Acceptance criteria:

- `.github/workflows/*` no longer points to stale paths.
- Repository docs reference the new layout.
- Historical E1-E6 links can still be resolved through the mapping.

### QA E7 Independent No-coverage-loss Verification

Status: deferred

Goal: independently verify that the taxonomy refactor preserved coverage,
CI behavior, and release evidence traceability.

Acceptance criteria:

- QA samples every mapped suite area.
- Required CI gates are present and green.
- No blocker/high/medium findings remain unresolved.

## Dependencies

- E6 completed or explicitly descoped.
- Stabilized release rollout feedback window complete.
- Owner approval of E7.1.
