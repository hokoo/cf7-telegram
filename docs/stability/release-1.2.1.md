# Release 1.2.1

Status: completed
Outcome: publish version 1.2.1 from `topics`, including an accurate changelog.
Scope: accepted topics branch changes, release metadata, baseline upgrade
coverage, existing verification and GitHub/WordPress.org publication workflow.
Out of scope: unrelated PR #64, dependency upgrades and additional features.
Authorization: user requested the complete release cycle, including publication.

## B1 — Prepare and verify release

Status: review
Goal: a consistent, verified 1.2.1 release candidate from `topics`.
DoR: clean branch at `b02aeda`; previous public version is `v-1.2.0`.
AC: version metadata and candidate defaults agree on 1.2.1; changelog covers
forum topics, names, active-recipient fix and UI changes; upgrade checks include
the exact published 1.2.0 baseline while retaining historical coverage.
DoD: reviewed scoped commit; local focused checks and complete PR CI pass;
independent source QA accepts the frozen candidate before merge/publication.
Dependencies: GitHub CI, package registries and published baseline ZIP.
Risks: local PHP lacks PHPUnit extensions; pinned CI provides release evidence.
Owner selection: `gpt-6.1-sol`, high; bounded release preparation with artifact
consistency and upgrade-compatibility risk. Specialists use pinned role defaults.

## Publication

After B1 acceptance and independent QA, root merges the topics PR, tags
`v-1.2.1`, creates the GitHub release and observes the existing publication
workflow. Completion requires its full support matrix and publish jobs to pass,
plus verification of GitHub and WordPress.org artifacts and stable version.

## Checkpoint

- Initial branch `topics`, HEAD `b02aeda`, clean; origin/topics matches.
- Latest GitHub release `v-1.2.0`; unrelated open PR #64 excluded.
- Existing process/reference: `docs/stability/release-1.2.0.md`.

- B1 owner `/root/release_121_prepare`, native thread
  `01a186ff-b03a-7072-9350-9d17dfedf695`, selected `gpt-6.1-sol/high`.
  Fresh worker `/root/release_121_prepare/source_prepare` uses pinned
  `gpt-6-sol/high`; owns metadata/changelog/baseline and focused PHP/React
  checks. One writer; no Docker gates locally. Root owns PR and publication.

## B1 local source handoff

- Owner reviewed the full scoped diff and focused evidence; local source stage
  accepted. Complete B1 remains pending PR CI and root's independent source QA.
- Frozen source: initial HEAD `b02aedad5954172fd9e174108042ad17788bcaba`,
  10 tracked files, +41/-22; `git diff -- plugin-dir tests` SHA-256
  `60665b3934f4e6503d5daaa72ede33f8cf84dcde92804e262976bb443e346262`.
- Changed paths: plugin entrypoint/readme/test bootstrap; E5/E6 Playwright
  candidate fallbacks; E1 runner/manifest; E5 browser/support and E6 runners.
  Versions agree on 1.2.1. Changelog covers topics, discovery/assignment,
  topic/local names, active-recipient filtering, failure continuation and UI.
- Official WordPress.org 1.2.0 ZIP downloaded and verified SHA-256
  `0661546af993df5f7facefcb18a65d2aec1f55dadc98b14ecd668c4c7bcaaf96`;
  `upgrade-1.2.0` added to defaults/all support rows; old baselines preserved.
- Checks exit 0: eight-source version assertions, manifest/official ZIP hash
  assertions, `php -l` (2 files), `bash -n` (4 runners), JSON parse,
  `node --check` (2 specs), and `git diff --check`. Evidence:
  `/tmp/cf7tg-121-{version-check,manifest-check,php-lint,bash-check,json-check,node-check,diff-check}.log`.
- `php tests/run.php` in `plugin-dir`: exit 0, 110 tests/0 failures;
  `/tmp/cf7tg-121-php-tests.log`. Missing dom/mbstring/xmlwriter means the
  compatibility harness ran; pinned CI PHPUnit remains required.
- `CI=true npm test -- --watchAll=false --runInBand` in `plugin-dir/react`:
  exit 0, 8 suites/60 tests; `/tmp/cf7tg-121-react-tests.log`. Worker had
  already started and finished this 2-second run before root clarified using
  a monitor for the full suite; no duplicate or interrupted run was made.
- Worker `/root/release_121_prepare/source_prepare`, native
  `01a18701-230f-78d3-81db-8419518c8388`, completed with no descendants or
  live commands/mutations. Owner sent accepted identity to root for cleanup.
- No dependencies/workflow gates changed. No local build/audit/Docker/live
  Telegram run; required PR gates remain unchanged, full support matrix is
  required at publication. Root owns PR/push/QA/merge/publication.
- Next: scoped source commit, root's PR, then fresh monitor of its CI run and
  artifacts. Source stays frozen; no successor batch taken.

## B1 frozen PR verification

- Owner technical verdict: `pass_with_notes` for source/required PR gates;
  root accepted technically, pending independent QA. Frozen commit
  `b498602294c00455c76fd187bafc80d936a42c3c`, PR #71. Run `40851205650`
  succeeded (verify 9m23s):
  https://github.com/hokoo/cf7-telegram/actions/runs/40851205650.
  CI tested synthetic merge `1f0497e` into master `8fd23726`; head matches.
- Owner inspected actual diff, terminal job/log and downloaded summaries.
  CI PHP lint/Composer: 110 tests/529 assertions; React: 8 suites/60 tests.
  Required audits, ZIP build/version/reproducibility, Plugin Check and E1
  current lifecycle passed. E1 source includes default `upgrade-1.2.0` and
  enforces official artifact SHA-256; detailed E1/Plugin Check summaries
  stayed in runner temporary paths, so per-case counts are unavailable in
  PR artifacts. Gate success is retained in the full log; no gate waived.
- REST: 11/11 steps and 24/24 checks. Browser: 28/28 steps, 8/8 checks.
  Fake Telegram: 57/57 steps, 37/37 checks including all 12 topic assertions
  and failure continuation/no General reroute. Runtime WP 7.1/PHP 8.3/CF7 6.2.
- Audit `blocking_failed=0`; runtime has 2 moderate, no high/critical.
  Nonblocking development audit: 78 findings (12 low, 33 moderate, 29 high,
  4 critical). Dependency upgrades remain excluded.
- Downloaded ZIP validates as 1.2.1, SHA-256
  `e9afb1c1eb3fd2b60b55eb09033a6e430bfde991a3cd86a25e8ebf6e22cb8d6d`.
- Exact commands: `gh run watch 40851205650 --repo hokoo/cf7-telegram
  --exit-status`, terminal `gh run view` JSON/log and `gh run download`,
  and `EXPECTED_VERSION=1.2.1 scripts/validate-release-zip.sh` on downloaded
  ZIP all exited 0. Initial sandbox watcher exited 1 for GitHub connectivity;
  narrow escalated retry was the sole live watcher. No duplicate local gates.
  Evidence `/tmp/cf7tg-121-pr-ci/{run-view.json,full-run.log,run-watch.log}`,
  downloaded `artifacts/` and validator log.
- Monitor `/root/release_121_prepare/pr_ci_verify`, native
  `01a18706-eb32-7a50-ad74-559233040ae4`, completed: no descendants/live
  watcher or retrieval commands, checkout clean at frozen commit before this
  bookkeeping append. Worker was accepted/archived by root, exit 0. Root owns
  monitor/owner cleanup; no active implementation processes remain.
- This checkpoint append is uncommitted; no commit/push before QA. Source stays
  frozen. Root owns independent QA/merge/publication; full support matrix and
  publication jobs were correctly skipped for PR and remain release gates.
  B1 owner stops here; no successor batch taken.

## Independent QA and root acceptance

- Root accepted B1 and independent source gate `pass_with_notes`, reviewed
  `8fd23726..b498602294c00455c76fd187bafc80d936a42c3c` and actual PR artifacts.
  Reviewer `/root/release_121_qa`, native `01a18711-3778-7523-b7f9-7811a8057f76`,
  had no implementation role and stopped all commands with no descendants.
- All source/PR criteria pass. Notes retained: topic creation before recipient
  registration does not save its name (documented Topic #ID fallback applies);
  detailed E1/Plugin Check temporary summaries were not uploaded, though enforced
  gates passed; runtime audit 2 moderate, development 78 nonblocking findings.
  No user exception or waived criterion. Full release matrix remains mandatory.
- Worker, monitor and B1 owner accepted, stopped and archived successfully
  using the serving daemon; archive commands exited 0.

## Publication checkpoint

- PR #71 merged as `f5a8f757422a54711fd11e283aec82e3aee7eecb`.
  Product/test/workflow/install tree matches reviewed `b498602` exactly.
- Annotated tag `v-1.2.1` pushed; GitHub release created from readme changelog:
  https://github.com/hokoo/cf7-telegram/releases/tag/v-1.2.1.
- Release workflow `40853242359` is running, with full matrix and publication
  still required. Master push run `40853205086` also started.
- Independent source QA agent stopped and archived successfully (exit 0).
  Fresh pinned test_monitor `/root/release_121_publish_verify` owns publication
  observation and public artifact verification. Root owns remaining mutations.
- Local master synchronized to the merge; only this checkpoint differs.


## Delivered release

- Root accepted final publication verification. Release run `40853242359`
  and master push run `40853205086` succeeded on tagged merge
  `f5a8f757422a54711fd11e283aec82e3aee7eecb`.
  https://github.com/hokoo/cf7-telegram/actions/runs/40853242359
- All required release gates passed: PHP/React, audits, version/ZIP validation,
  reproducibility, Plugin Check, full lifecycle matrix, REST, browser and fake
  Telegram delivery. Required publication jobs passed; no gates waived.
- Root inspected full matrix row evidence: legacy WP5.6.19/PHP8.0/CF7 5.4.2
  348/348 steps; current WP7.1/PHP8.3/CF7 6.2 313/313; optional available
  PHP8.5 row 313/313. No failed/expected-failed steps. Upgrade 1.2.0 passed
  35/35 in every row; historical baselines remain covered (0.8.1 on legacy).
- SVN deployment committed revision `3772619`; stable mirror advanced
  `a12f39e` to `8e157dc`. Live SVN trunk/tag readme stable tag, PHP header and
  runtime constant all report 1.2.1. Public plugin API reports 1.2.1 and its
  official download; API tested metadata 7.1.4 is external directory metadata.
- GitHub published ZIP matches both accepted PR and release CI ZIP SHA-256:
  `e9afb1c1eb3fd2b60b55eb09033a6e430bfde991a3cd86a25e8ebf6e22cb8d6d`.
- Official WordPress.org ZIP validates as 1.2.1, SHA-256
  `fcd80ed6aafd6d5f4580dafc817ba151e70a1c9e90dd0eeefb2eead016ffaaff`.
  Normalized ZIP comparison: 153/153 paths and uncompressed file bytes match
  the GitHub asset exactly; only archive packaging differs.
  https://downloads.wordpress.org/plugin/cf7-telegram.1.2.1.zip
- Evidence: `/tmp/cf7tg-121-release-ci/{run-view.json,full-run.log,run-watch.log}`,
  downloaded `artifacts/` (including detailed support-row summaries), public
  ZIPs, `github-asset-validation.log`, `wporg-zip-validation.log`,
  `zip-content-comparison.log`, `svn-*`, `wordpress-plugin-api.json`.
- Exact terminal checks: `gh run watch 40853242359 --repo hokoo/cf7-telegram
  --exit-status` exit 0; terminal run metadata/logs/artifacts retrieved;
  `EXPECTED_VERSION=1.2.1 scripts/validate-release-zip.sh` passed for both
  public ZIPs; normalized Python ZIP-content comparison passed.
- No remaining release blocker. Nonblocking source-QA/audit notes above remain
  recorded. This final documentation does not alter the immutable release tag.
- Publication monitor `/root/release_121_publish_verify`, native
  `01a18715-346b-7b61-a78b-6d70855f997c`, completed with all commands stopped
  and no descendants/source changes. Root accepted and archived it (exit 0).
