# Release 1.2.0

Status: completed

Outcome: publish the authorized 1.2 update with the CF7 6.2 admin-menu fix,
accurate release notes, synchronized version metadata and a verified ZIP.

Scope: the accepted local menu fix and regression tests; release metadata and
changelog; verification against the changed CF7 menu; existing release pipeline.
Out of scope: deferred chat-name migration, E7 refactoring, dependency upgrades,
new features and unrelated open PR #64.

Version decision: use `1.2.0` consistently (the existing SVN deployment requires
three semantic-version components), with GitHub tag `v-1.2.0`.

## B1. Prepare and verify release source

Status: completed
DoR: user explicitly requested the release; the prior menu fix is accepted.
AC: header, runtime constant, WordPress stable tag and current candidate/test
versions agree on 1.2.0; changelog describes the actual shipped changes;
old/new menu regression remains passing; current integration checks exercise
CF7 6.2 (GitHub tag v6.2.0) and verify menu visibility; legacy CF7 coverage is retained; upgrade
coverage includes the exact published 1.1.0 production baseline and ZIP hash.
DoD: scoped diff reviewed; version consistency, PHP/React tests, build/ZIP,
audit and PR integration gates pass on a frozen commit; root accepts the batch.
Dependencies: existing GitHub CI and project build/test tools.
Risks: local PHP lacks PHPUnit extensions and local tool versions differ from
CI, so authoritative release evidence may come from the pinned CI toolchain.

Owner: fresh batch_owner using `gpt-6.1-sol`, high reasoning. Rationale:
bounded release coordination with compatibility and artifact consistency risk.
Worker/test_monitor use their pinned role models; one implementation writer.

## QA and publication

After B1 acceptance, independent epic_qa reviews the frozen source and actual
verification evidence. Root then completes the authorized merge/tag/release
flow. The existing release workflow must pass its full support matrix and
publication job before this release is called delivered; verify the GitHub ZIP
and WordPress.org stable tag. No gate is waived because of missing tools.

## Checkpoint

- Initial HEAD: `10b285ce120983767181cc09ad506638a4dda25e`, branch `master`.
- Initial local work: `Settings.php`, `SettingsTest.php`, test bootstrap and
  `docs/stability/cf7-menu-compatibility.md`, all from the accepted menu fix.
- GitHub latest release: `v-1.1.0`; last master CI run `34378346495` succeeded.
- Prior accepted agents `/root/cf7_menu_history` and `/root/cf7_menu_fix` had
  stopped all work. Both were archived successfully via the serving daemon
  at `/home/itron/.codex/app-server-control/app-server-control.sock` (exit 0).
- B1 owner `/root/release_120_prepare`, thread
  `01a136a5-eec1-7e80-a44e-5c0461eacd84`; fresh worker
  `/root/release_120_prepare/source_prepare` (`gpt-6-sol`, high) assigned
  scoped release metadata, current CF7 defaults and browser menu canary.
- Branch: `release/1.2`, same initial HEAD and accepted local menu patch.
- Local-stage delivery is a reviewed uncommitted diff; root owns commit,
  pinned-toolchain PR CI, independent QA and publication gates. Full DoD
  remains pending those gates.
- Root verified release workflow `33564927891` published WordPress.org 1.1.0;
  the worker must correct the stale production-baseline manifest metadata and
  include the exact 1.1.0 artifact in upgrade cases, retaining older history.
- Worker thread `01a136a6-f687-73c3-b77a-ad3977cf5b5a` stopped all commands
  and mutations; PHP compatibility harness passed 97/97, syntax/version and
  promotion-menu negative checks passed (`/tmp/cf7tg-120-*.log`).
- Owner reviewed all scoped source hunks. Initial source diff SHA-256:
  `0e945694b879e57e741d94a8c50e9ffca1e6114e530ef5a10a9bcd076834ce1f`.
- Fresh `/root/release_120_prepare/local_verify` (`gpt-6-luna`, low) owns
  serial React tests/build and release audits against that boundary.
- Root then verified WP.org names the introducing CF7 release `6.2`
  (GitHub tag `v6.2.0`); exact ZIP `6.2.0` is unavailable. After the monitor
  stops, the same unfinished writer assignment must correct only these
  distribution-version strings and release wording, then repeat affected
  checks. No source correction may overlap active monitor commands.
- Next: settle local ladder, correct CF7 distribution strings, review handoff.

## B1 local handoff

- Source stage technically accepted by owner after actual scoped diff review;
  full B1 acceptance remains pending root's frozen-commit CI and QA gates.
- Final source boundary: HEAD `10b285ce120983767181cc09ad506638a4dda25e`,
  branch `release/1.2`, uncommitted diff for `plugin-dir scripts tests` SHA-256
  `3bbfadafeecd88b45bd3792db0035a61fef64a520b6d2c27ff10733a40fdac35`.
- Changed source: plugin entrypoint/readme; accepted Settings fix/test/bootstrap;
  E1 manifest/smoke; E5 browser spec/runner, support runner and promotion
  verifier/regression; E6 spec/runner candidate/default strings. No locks,
  dependency versions or unrelated source changed; 14 tracked paths total.
- Version assertions: six release values equal `1.2.0`; current/newest CF7
  distribution defaults are `6.2`, legacy remains `5.4.2`; all upgrade lists
  include `1.1.0`. Official WP.org 1.1.0 ZIP SHA-256:
  `a45c410bc1605b763be7615a3c156f8c5bc1367410873cc26230fc8813e19d82`.
- `php tests/run.php` (plugin-dir): exit 0, 97 tests/0 failures using the
  compatibility harness (local PHP lacks dom/mbstring/xmlwriter).
- Four `php -l` checks, two `node --check` checks, six `bash -n` checks,
  manifest assertions and `git diff --check`: passed. Logs:
  `/tmp/cf7tg-120-{version-check,manifest-check,php-lint,node-check,bash-check,php-tests}.log`.
- `tests/stability/e5-promotion-evidence-test.sh`: exit 0, including rejection
  of failed `cf7-menu-navigation`; `/tmp/cf7tg-120-promotion-test.log`.
- `CI=true npm test -- --watchAll=false --runInBand` (plugin-dir/react):
  exit 0, 8 suites/49 tests; `/tmp/cf7tg-120-react-tests.log`.
- `CI=false npm --prefix plugin-dir/react run build`: exit 0; required HTML,
  JS, CSS and asset metadata exist; `/tmp/cf7tg-120-react-build.log`.
- `CF7TG_AUDIT_REPORT_DIR=/tmp/cf7tg-120-audit-reports scripts/run-release-audits.sh`:
  exit 1, 3 blocking audit failures and 1 nonblocking failure due registry DNS;
  Composer production platform check passed. These are unresolved checks,
  not audit passes. Evidence: `/tmp/cf7tg-120-audit-reports/summary.json` and
  `/tmp/cf7tg-120-release-audits.log`.
- Final correction changed only CF7 distribution strings/wording; affected
  manifest/version/Bash/diff checks passed in
  `/tmp/cf7tg-120-correction-*.log`. An initial version-check invocation had
  a shell heredoc syntax error; corrected invocation passed. PHP/React/build
  were not repeated because their source was unchanged.
- Monitor thread `01a136aa-460f-7551-be88-5e16169db5b5` and worker thread
  `01a136a6-f687-73c3-b77a-ad3977cf5b5a` both completed, reported no descendants
  and all commands/mutations stopped. Monitor observed only ignored build
  artifacts and no status/source hash changes. Root owns supported cleanup.
- Pending root gates: pinned-toolchain audit, ZIP/build/reproducibility,
  plugin-check, exact support/upgrade matrix, browser navigation and form
  delivery runtime checks, then independent QA. No commit/push/publication
  by owner or specialists. No successor batch taken.

## B1 frozen PR verification

- Owner technical verdict: `pass_with_notes` for B1 source/PR readiness;
  root acceptance and independent QA follow. No required PR gate is waived.
- Reviewed commit: `f800a0165ceff11601d5084fca688ed1802c5bd9`, PR #70;
  CI run `38453048815` succeeded (verify job 8m50s):
  https://github.com/hokoo/cf7-telegram/actions/runs/38453048815.
- CI PHP lint and `composer test` passed: 97 tests/443 assertions. React:
  8 suites/49 tests. ZIP build, reproducibility, Plugin Check and current
  lifecycle passed; the E1 default cases include `upgrade-1.1.0` with the
  pinned official baseline hash. Detailed E1/Plugin Check summaries remained
  temporary runner files; the full log preserves successful gate outcomes.
- Downloaded ZIP validates as 1.2.0; SHA-256
  `64a6742c963a9bcc81969646f1029c29e7b642719b7d0b57cd0f45f139b2de01`.
- CI audits: `blocking_failed=0`; runtime npm has 2 moderate, 0 high/critical.
  Existing nonblocking development audit reports 85 findings (3 low,
  35 moderate, 43 high, 4 critical); dependency upgrades remain excluded.
- Runtime on WP 7.1/PHP 8.3/CF7 6.2: REST 11/11 steps and 24/24 checks;
  browser 28/28 steps including `cf7-menu-navigation`; fake delivery 45/45.
- Evidence for QA: `/tmp/cf7tg-120-ci/run-view.json`, `full-run.log`,
  `run-watch.log`, and downloaded `artifacts/` (audits, ZIP, REST, browser,
  fake Telegram). The one in-progress log lookup failed because GitHub
  withholds logs until completion; retrieval after completion succeeded.
- Fresh monitor `/root/release_120_prepare/pr_ci_verify`, thread
  `01a136af-6a5b-7920-8161-6ccc7cb05f5a`, completed: sole watcher exit 0,
  all commands stopped, no descendants, source checkout remained clean.
  Prior local specialists were accepted and archived by root (exit 0).
- Source stays frozen; only this uncommitted checkpoint was appended after
  monitor stop. Root owns cleanup, independent QA and publication. Full
  support matrix and publication were intentionally skipped for the PR
  event and remain mandatory release-workflow gates. No successor batch.

## Independent source QA and root acceptance

- Gate: `pass_with_notes`, accepted by root. Reviewer
  `/root/release_120_qa`, thread `01a136b9-0b02-76b0-b489-8be279664073`,
  independently reviewed `10b285ce120983767181cc09ad506638a4dda25e` →
  `f800a0165ceff11601d5084fca688ed1802c5bd9` and the actual CI artifacts.
- All source/PR criteria passed. Nonblocking notes are retained unchanged:
  development audit has 85 findings; runtime audit has 2 moderate and no
  high/critical findings; detailed E1 and Plugin Check summaries were not
  retained as artifacts, though the enforced gate outcomes are available.
- No required check was waived. Full support matrix and SVN publication
  remain mandatory release-workflow gates before delivery.
- Reviewer stopped all commands and has no descendants. B1 owner and its
  PR monitor were accepted, stopped and archived through the serving daemon
  (both archive commands exited 0). Root owns the remaining publication flow.

## Publication checkpoint

- PR #70 merged as `1b0a378080e8fa109d3e043a18c04f9f7d9edcaa`.
  Its product/test/workflow tree matches the reviewed source commit.
- Annotated tag `v-1.2.0` pushed and stable GitHub release created:
  https://github.com/hokoo/cf7-telegram/releases/tag/v-1.2.0.
- Publication is in progress, not yet delivered: the existing release workflow
  must finish the full matrix and WordPress.org deployment.
- Fresh read-only `/root/release_120_publish_verify` (`gpt-6-luna`, low)
  observes that workflow and verifies its published artifacts/WordPress.org
  metadata. Root retains mutation authority; no implementation writer exists.

## Delivered release

- Root accepted the final publication evidence. Release workflow
  `38455212476` completed successfully on tagged merge commit
  `1b0a378080e8fa109d3e043a18c04f9f7d9edcaa`:
  https://github.com/hokoo/cf7-telegram/actions/runs/38455212476.
  Both verification and publication jobs passed. Master push workflow
  `38455148823` also succeeded on the same commit.
- Release gates passed: PHP lint/tests, React tests, required audits, version
  consistency, ZIP validation/reproducibility, Plugin Check, full lifecycle
  matrix, REST, authenticated browser and fake Telegram delivery.
- Full matrix: WP 5.6.19/PHP 8.0/CF7 5.4.2 passed 312/312 steps;
  WP 7.1/PHP 8.3/CF7 6.2 passed 277/277; the available optional PHP 8.5
  row passed 277/277. No skipped rows or expected/actual failures.
  `upgrade-1.1.0` passed 35/35 steps in each row, as did the legacy
  `upgrade-0.8.1` case. Fixtures used `legacy-heavy` and the pinned official
  1.1.0 baseline ZIP. Root inspected the saved row evidence directly.
- REST passed 11/11 steps and 24/24 checks; browser passed 28/28 steps,
  including visible `cf7-menu-navigation` under `wpcf7-dashboard`; fake
  Telegram passed 45/45. Required audit failures: 0. The previously recorded
  nonblocking development/runtime audit findings remain unchanged.
- Published GitHub asset validates as 1.2.0 and exactly matches the release
  CI ZIP and accepted PR artifact, SHA-256:
  `64a6742c963a9bcc81969646f1029c29e7b642719b7d0b57cd0f45f139b2de01`.
- WordPress.org deployment committed SVN revision `3744804`; stable mirror
  advanced `3cb8aa6..a12f39e`. Live SVN trunk/tag readmes, tag PHP header and
  runtime constant report 1.2.0. The public plugin-information API reports
  version 1.2.0 and the official download is available:
  https://downloads.wordpress.org/plugin/cf7-telegram.1.2.0.zip.
- The official WordPress.org ZIP validates as 1.2.0, SHA-256:
  `0661546af993df5f7facefcb18a65d2aec1f55dadc98b14ecd668c4c7bcaaf96`.
  Read-only ZIP comparison confirms all 153 normalized file paths and
  uncompressed file bytes match the GitHub asset; only archive packaging
  differs. API `tested` metadata was 7.1.3 while the SVN readme retains 7.1;
  no release version or content discrepancy was found.
- Evidence: `/tmp/cf7tg-120-release-ci/{run-view.json,full-run.log,run-watch.log}`,
  downloaded `artifacts/`, `release-asset/cf7-telegram-wp-plugin.zip` and
  `cf7-telegram.1.2.0.wordpress.org.zip`. Public API/SVN responses were
  inspected from command output, not saved as separate snapshots.
- Exact terminal checks: `gh run watch 38455212476 --repo hokoo/cf7-telegram
  --exit-status` exited 0; `EXPECTED_VERSION=1.2.0
  scripts/validate-release-zip.sh` passed for both downloaded public ZIPs;
  Python `zipfile` path/content comparison exited 0 with zero differences.
- Monitor `/root/release_120_publish_verify`, thread
  `01a136be-1e9b-7230-a48d-00f336391106`, completed its assignment with all
  commands stopped and no descendants or source changes. Root accepted the
  handoff and archived the thread through the serving daemon (exit 0).
- No remaining release work or publication blocker. This final checkpoint
  is documentation only and does not alter the immutable release tag/artifact.
