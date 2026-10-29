# Release 1.2.1

Status: in_progress
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
