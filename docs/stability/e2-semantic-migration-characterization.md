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
- CF7 `post_content` and `_form` preservation checks, including literal `[telegram]` retention;
- actual Contact Form 7 render checks proving stored `[telegram]` remains while rendered HTML contains no literal `[telegram]`;
- conservative cleanup/dry-run repair probes that create a temporary orphan-like `cf7tg_chat`, prove scheduled cleanup and dry-run preserve it, and delete the probe record before final fingerprint output.

Out of scope:

- production migration implementation changes;
- release ZIP/build artifact updates;
- React/UI behavior;
- Telegram API delivery behavior;
- destructive cleanup apply-mode assertions.

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

The E2 JSON files include machine-readable checks for:

- `form_<id>_telegram_tag_no_literal_render_output`, owned by E2.4;
- `cleanup_scheduled_preserves_orphan_like_chat`, owned by E2.5;
- `cleanup_dry_run_preserves_orphan_like_chat`, owned by E2.5.

The cleanup probes intentionally store only the temporary post id, booleans, dry-run counters, and sanitized error text. They delete the probe post in `finally` so migrated entity counts and the final characterization fingerprint stay deterministic.

## Current Characterization

Verified the completed E2.2-E2.5 implementation on 2026-08-30.

All four published baselines:

```bash
CF7TG_E2_CHARACTERIZATION=1 \
tests/stability/e1-smoke-matrix.sh \
  --case upgrade-0.10 \
  --case upgrade-0.11 \
  --case upgrade-1.0.9 \
  --case upgrade-1.0.10
```

Result:

- run id: `20260830T183937Z-20490`;
- `total_steps=360`, `passed_steps=360`;
- `failed_steps=0`;
- `expected_failed_steps=0`;
- candidate source: current local `plugin-dir`, not `dist/cf7-telegram-wp-plugin.zip`;
- `migration_event_scheduled_or_self_healed`: pass on all four baselines;
- `migration_state_scheduled`, `migration_state_completed`, and `migration_state_second_run_stable`: pass on all four baselines;
- entity/relation counts and second-run fingerprints: pass;
- stored form preservation and actual CF7 no-literal render checks: pass;
- scheduled cleanup and repair dry-run orphan-preservation checks: pass.

Additional fixtures:

- `damaged-legacy`, run id `20260830T184429Z-29360`: `96/96`, no failed or expected-failed steps;
- `partial-modern`, run id `20260830T184656Z-33796`: `59/59`, no failed or expected-failed steps.

The temporary run directories are not repository artifacts. Reproduce the evidence with the documented commands; each run emits its own `results/summary.json` and machine-readable E2 files.

## Gate Status

The E2.6 semantic gate is green.

E2.7 verified the combined implementation and production artifact on 2026-08-30:

- PHP suite: `51/51`, including constant-only legacy source scheduling and secret non-persistence;
- React suite: `3/3` suites and `7/7` tests;
- production build and `scripts/validate-release-zip.sh`: pass for version `1.0.11`;
- artifact SHA-256: `4c5c0f5336a25f1f8cc1d73f859559549aa1e0def401ec87e7bb5019f7fdd2ab`;
- final `1.0.11` artifact matrix run id `20260830T195004Z-2367`: `360/360`, no failed or expected-failed steps;
- tracked worktree after build and matrix: clean.

Independent Epic 2 QA passed on `93b969a`:

- independent four-baseline run `20260830T190506Z-60294`: `360/360`;
- independent `damaged-legacy` run `20260830T191035Z-76095`: `96/96`;
- independent `partial-modern` run `20260830T191148Z-78349`: `59/59`;
- independent final `legacy-heavy` 1.0.10 run `20260830T192149Z-87942`: `96/96`;
- real WordPress constant-only lifecycle completed without persisting the secret or emitting missing-table database warnings.

Epic 2 is closed. The combined E1/E2 pull request prepares version `1.0.11`; tagging and publication remain separate release actions.
