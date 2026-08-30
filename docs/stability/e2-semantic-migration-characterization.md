# Epic 2 Semantic Migration Characterization

Epic 2 extends the E1 isolated upgrade matrix with opt-in semantic evidence for migration scheduling, migrated data shape, repeatability, malformed legacy input, partial modern state, and the approved `[telegram]` preservation contract.

## Scope

Included:

- current local candidate ZIP creation inside the temporary E1 workdir when `CF7TG_E2_CHARACTERIZATION=1`;
- migration scheduling or self-healing evidence after published-baseline upgrades;
- fixture expectations for `legacy-heavy`, `damaged-legacy`, and `partial-modern`;
- migrated bot/chat/channel/form relation count checks;
- duplicate relation checks;
- second migration run fingerprint comparison;
- CF7 `post_content` and `_form` preservation checks, including literal `[telegram]` retention.

Out of scope:

- production migration implementation changes;
- release ZIP/build artifact updates;
- React/UI behavior;
- Telegram API delivery behavior.
- E2.5 server-owned deletion and dry-run behavior.

## Commands

Run one characterization case against the current local `plugin-dir`:

```bash
CF7TG_E2_CHARACTERIZATION=1 \
tests/stability/e1-smoke-matrix.sh --case upgrade-1.0.10
```

Run all four published upgrade baselines:

```bash
CF7TG_E2_CHARACTERIZATION=1 \
tests/stability/e1-smoke-matrix.sh \
  --case upgrade-0.10 \
  --case upgrade-0.11 \
  --case upgrade-1.0.9 \
  --case upgrade-1.0.10
```

Run malformed or partial-state characterization:

```bash
CF7TG_E2_CHARACTERIZATION=1 \
CF7TG_E1_FIXTURE=damaged-legacy \
tests/stability/e1-smoke-matrix.sh --case upgrade-1.0.10

CF7TG_E2_CHARACTERIZATION=1 \
CF7TG_E1_FIXTURE=partial-modern \
tests/stability/e1-smoke-matrix.sh --case upgrade-1.0.10
```

## Evidence

Each E2 run writes:

- `results/e2/<case>-after-upgrade.json`;
- `results/e2/<case>-after-migration-run.json`;
- `results/e2/<case>-rerun.json`;
- `results/e2/<case>-after-second-migration-run.json`;
- E2 check rows in `results/evidence.jsonl`;
- `expected_failed_steps` and `expected_failures[]` in `results/summary.json`.

`fail` means an unexpected harness or environment failure. `expected_fail` means a known downstream Epic 2 implementation dependency is still red.

## Current Characterization

Verified all published baselines on 2026-08-30 against the E2.2 durable runner:

```bash
CF7TG_E2_CHARACTERIZATION=1 \
tests/stability/e1-smoke-matrix.sh \
  --case upgrade-0.10 \
  --case upgrade-0.11 \
  --case upgrade-1.0.9 \
  --case upgrade-1.0.10
```

Result:

- `failed_steps=0`;
- `expected_failed_steps=72`, 18 per baseline;
- candidate source: current local `plugin-dir`, not `dist/cf7-telegram-wp-plugin.zip`;
- `migration_event_scheduled_or_self_healed`: pass on all four baselines;
- `migration_state_scheduled`, `migration_state_completed`, and `migration_state_second_run_stable`: pass on all four baselines;
- first migration semantic entity/relation counts: pass for `legacy-heavy`;
- second-run state/entity/relation fingerprint: pass for the completed `legacy-heavy` path;
- all remaining expected failures are `[telegram]` content/meta preservation checks owned by E2.4.

## Remaining Dependencies

- E2.3 must reconcile historical false-success and partial modern state without duplicates, preserve valid malformed fixture rows, and skip invalid rows without fatal errors.
- E2.4 must implement the approved `[telegram]` preservation/no automatic content mutation and admin recovery contract.
- E2.5 remains server-owned deletion and dry-run behavior, outside this characterization batch.
