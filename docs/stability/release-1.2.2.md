# Release 1.2.2

## Authorized scope and delivery

User requested logical commits for all accumulated changes and publication of version 1.2.2. Source baseline: `e25d811aa7bff4163eef652b19c8b5f2b775464e` (`master`, also remote master on 2026-10-30). Follow the established `release-1.2.1.md` process: candidate PR, required checks, independent acceptance, merge, annotated `v-1.2.2` tag, GitHub release, WordPress.org publication and public-artifact verification.

Include the local Media Files compatibility harness/mounts and settings card with responsive 200px sidebar and installed-but-inactive activation state. Preserve old plugin compatibility. Exclude unrelated PRs #64 and #72. The sibling addon is already committed at `0437481af4da9a2131a65eff8e9d3b1fc9260dd2` (version 1.2); its sole dirty license-bypass line must remain uncommitted and outside every published artifact. Do not change the local site's addon activation state or send real Telegram/email messages.

## Work and acceptance

1. **R1 — prepare and verify candidate (accepted).** Review the accumulated diff; make only necessary release or portability repairs. Create logical commits for (a) local addon integration/compatibility evidence, (b) settings UI and state/activation tests, (c) release metadata/changelog and exact published 1.2.1 upgrade baseline. Update current candidate defaults to 1.2.2, retaining historical baselines. Required local checks: PHP/React suites, lint, build, ZIP validation and audits; PR CI supplies real PHPUnit, lifecycle, REST, browser and fake-Telegram gates. Existing visual evidence covers three addon states at widths 1920, 1440, 1439, 1120, 768 and 390, including focus and overflow. No duplicated broad tests unless source changes invalidate evidence.
2. **Q1 — independent candidate acceptance (accepted: pass_with_notes).** Fresh read-only epic QA reviews the frozen candidate and actual verification evidence; required criteria must pass before publication.
3. **R2 — release and verify (depends on Q1).** Root merges the accepted candidate, tags/releases it, and delegates observation of release checks and public artifact validation. Required completion: release workflow verification/publication successful, GitHub asset and official WordPress.org 1.2.2 ZIP validated and normalized contents equal, SVN trunk/tag and stable mirror match. Record final evidence in this checkpoint.

R1 owner selection: `batch_owner`, explicit `gpt-6.1-sol` / `high`: bounded release engineering with existing CI and compatibility evidence. Worker and test-monitor role defaults remain pinned; one writer at a time. Root owns commits/PR/publication unless explicitly delegated. No implementation agent may publish.

## Checkpoints

- Initial inventory: seven tracked modified files plus `mediafiles-compatibility.md` and three local media test helpers; main version 1.2.1. Remote master equals local baseline; v-1.2.2 absent. Existing addon commits and local bypass preserved.
- R1 technical verdict `pass_with_notes`. Owner `/root/release122_prepare`, native
  `01a18c65-e31d-7d82-b8b8-a8a9f563126a`, selected `gpt-6.1-sol/high`.
  Fresh worker `/root/release122_prepare/prepare_source`, native
  `01a18c66-6ddb-75b1-980b-d21c084c5cb3`, pinned `gpt-6-sol/high`, owns
  source/metadata/defaults and minimal local harness prerequisites. Root owns
  commits/PR/publication. Source, local gates and required PR CI accepted.

## R1 frozen source preparation

- Owner inspected the actual UI/Settings/test and release/default/manifest diff;
  source preparation technically accepted. Sidebar/state/permission/nonce
  behavior required no repair. Main changelog describes the settings card and
  activation; the separate addon 1.2 transport fix is not claimed by main 1.2.2.
- Frozen HEAD `e25d811aa7bff4163eef652b19c8b5f2b775464e`; tracked diff
  `git diff -- docker-compose.yml plugin-dir tests/stability tests/e2e`
  SHA-256 `7c2149ad263e2ab1ac8a45b5d69f4bb341d6416ba9770de379e07bdc8f234f3f`,
  16 tracked paths, +292/-26. Four accumulated untracked media files remain;
  only the compatibility document gained an eight-line local prerequisite note.
  Release checkpoint is excluded from the source hash.
- Official 1.2.1 baseline downloaded to `/tmp/cf7-telegram.1.2.1.zip`; SHA-256
  `fcd80ed6aafd6d5f4580dafc817ba151e70a1c9e90dd0eeefb2eead016ffaaff`
  verified before pinning URL/version/hash. Initial sandbox curl exit 6 (DNS),
  narrow escalated retry exit 0. Old baselines retained; `upgrade-1.2.1` added
  to E1 default cases, lifecycle contract and all three support rows.
- Worker focused checks exit 0: `git diff --check`; seven `php -l` checks
  (entrypoint, Settings, bootstrap, SettingsTest, three media helpers);
  `node --check` on E5/E6 specs; `bash -n` on E1, E5 browser/support and E6
  runners; `jq -e` candidate/baseline/support assertions; Python official ZIP
  SHA/pin/version/every-row assertions; ZIP header/constant inspection.
  These short checks retained tool-transcript evidence, not persistent logs.
- Existing visual script and 1440 inactive/390 active screenshots inspected;
  prior 18 state/viewport checks cover responsive layout, overflow and focus.
  Previous fake-transport WP/legacy gates remain in the media checkpoint;
  no local WordPress loading or addon activation is part of this preflight.
- Worker completed/stopped with no descendants or commands. Root verified and
  archived native `01a18c66-6ddb-75b1-980b-d21c084c5cb3`, exit 0.
  Fresh `/root/release122_prepare/local_preflight` owns serial local gates,
  logs `/tmp/cf7tg-122-local-preflight/`; no implementation writer remains.
- Local monitor `/root/release122_prepare/local_preflight`, native
  `01a18c6a-0a65-7731-aff4-a45f6f6509a3`, pinned `gpt-6-luna/low`,
  completed with no descendants or live commands. Owner inspected its actual
  manifest, successful build logs and raw audit reports; local preflight
  technically accepted. Root accepted and archived it, exit 0.
- PHP lint passed. `php tests/run.php` (plugin-dir) passed 114 tests/0 failures
  through the compatibility harness (host PHP 8.0.30 lacks dom/mbstring/xmlwriter).
  `CI=true npm test -- --watchAll=false --runInBand` (plugin-dir/react) passed
  8 suites/64 tests. Real PHPUnit remains a PR CI gate.
- `EXPECTED_VERSION=1.2.2 scripts/build-release-zip.sh` succeeded with network
  escalation; successful independent rebuilds matched. Final explicit
  `SOURCE_DATE_EPOCH=1793266661` preserves the original comparison inputs
  across root's commits. `cmp` exit 0, both ZIPs SHA-256
  `a0da7ff288e2e66347f3394c9e2e535af433de79f1ae7b6fd8a9545d417901d7`.
  Logs `build-second-escalated.log`, `build-second-pinned-epoch.log` under
  `/tmp/cf7tg-122-local-preflight/`; reference ZIP retained there.
- `EXPECTED_VERSION=1.2.2 scripts/validate-release-zip.sh` passed.
  `EXPECTED_VERSION=1.2.2 tests/stability/e5-release-zip-hygiene-negative.sh`
  rejected 17 forbidden fixtures. Archive: 196 entries, zero addon paths,
  zero suspicious secret-named paths, zero local-bypass markers in every member.
  Sibling source SHA remains
  `9ff223a0810f8d39b1d7d51aba9419427e1a9991e5f7f69a177855d29a52077f`.
- `scripts/run-release-audits.sh` with report directory
  `/tmp/cf7tg-122-local-preflight/audit-reports-escalated` exit 0:
  `blocking_failed=0`; Composer root/plugin/platform passed. Runtime audit:
  2 moderate, 0 high/critical; nonblocking full-dev: 78 (12 low, 33 moderate,
  29 high, 4 critical). Default-network failures retained in `audit-reports/`.
- Early builds/audits failed on DNS/network access; no source/dependency repair.
  Exact attempt outcomes and log gaps remain in
  `/tmp/cf7tg-122-local-preflight/result-manifest.txt`. Some early PHP/React,
  build/hygiene outputs exist only in tool transcripts; successful rebuild
  logs and failed/successful raw audit reports persist. No required gate waived.
- Root created logical commits `6470888` (local media integration), `517c21d`
  (settings UI/state tests), and `b6a16f6b6dc5170e97d6c8eed7b48ea7a41e1bf4`
  (1.2.2 metadata/baselines). Frozen PR #73:
  https://github.com/hokoo/cf7-telegram/pull/73. Owner independently confirmed
  original 16-path diff hash unchanged by commits when excluding three formerly
  untracked media helpers; all four media file hashes also match. Working tree
  differs only in this checkpoint; final `git diff --check` passes.
- Fresh owner-delegated `/root/release122_prepare/pr_ci_observer` owns remote
  CI observation/artifacts only; no duplicate local checks. Root owns complete-batch acceptance,
  independent QA and all merge/publication steps.
- PR observer native `01a18c77-97eb-7c61-828d-791900f8a48d`, pinned
  `gpt-6-luna/low`; run `41044734573`, exact head `b6a16f6`, base `e25d811`.
  https://github.com/hokoo/cf7-telegram/actions/runs/41044734573
  Single watcher exited 0; verify succeeded in 9m43s with all required PR
  steps green. Release-only support matrix and publication correctly skipped.
  Observer completed, no descendants, watcher or retrieval processes remain.

## R1 terminal verification and handoff

- Owner inspected the actual source diff, full PR run log and downloaded raw
  summaries/audits. Technical verdict `pass_with_notes`; all R1 AC/local and
  PR DoD gates pass. No required gate waived. Frozen source
  `b6a16f6b6dc5170e97d6c8eed7b48ea7a41e1bf4`; CI tested synthetic merge
  `b254a3eb6a9b6d884aefa1a0f5fec4067ea43b52` into `e25d811`.
- Actual CI PHPUnit 9.6.36: 114 tests/539 assertions; React: 8 suites/64 tests.
  PHP lint, Composer gates, runtime audits, production build/version/ZIP
  reproducibility, Plugin Check and current lifecycle all passed.
  Audit `blocking_failed=0`; runtime 2 moderate, no high/critical; development
  78 nonblocking findings (12 low/33 moderate/29 high/4 critical).
- Downloaded runtime evidence: REST 11/11 steps and 24/24 checks; browser
  28/28 steps and 8/8 checks; fake Telegram 57/57 steps and 37/37 checks,
  including topic isolation and failed-recipient continuation. WP 7.1,
  PHP 8.3.36, CF7 6.2. No failed required checks.
- E1 and Plugin Check enforced passing gates are in the full run log. Their
  detailed runner-temporary summaries were not uploaded, so exact E1 per-case
  counts and Plugin Check report details are unavailable. Source/defaults
  include `upgrade-1.2.1` with the verified official hash; historical support
  rows remain unchanged apart from adding that baseline. This evidence limit
  is retained; full support matrix remains a publication gate.
- Downloaded CI ZIP validates as 1.2.2, SHA-256
  `2ac7d81e5c43264fe1171eef1bbe7f5f94a3e16013d50b0763c8088d2677fa53`.
  No sibling addon/local-bypass/secret-named paths found; local all-member
  bypass scan also passed. No addon source or activation changes.
- Exact terminal commands all exit 0: `gh run watch 41044734573 --repo
  hokoo/cf7-telegram --exit-status`; `gh run view 41044734573 --repo
  hokoo/cf7-telegram --json ...`; `gh run view 41044734573 --repo
  hokoo/cf7-telegram --log`; `gh run download 41044734573 --repo
  hokoo/cf7-telegram --dir /tmp/cf7tg-122-pr-ci/artifacts`;
  `EXPECTED_VERSION=1.2.2 scripts/validate-release-zip.sh` on downloaded ZIP.
  Full log `/tmp/cf7tg-122-pr-ci/run.log`, summaries/ZIP/raw audits under
  `/tmp/cf7tg-122-pr-ci/artifacts/`. Watch/metadata/validator outputs retained
  in observer tool transcript; full CI test output persists in the run log.
- Worker and local monitor accepted/stopped/archived by root; PR monitor
  accepted/stopped/archived by root, native
  `01a18c77-97eb-7c61-828d-791900f8a48d`, exit 0. Root inspected actual run
  log/artifact summaries/audits and frozen commit statistics and accepted R1.
  Only this checkpoint differs
  from frozen source. Owner stops at R1; no successor batch taken. Root next
  closes independent Q1 and owns merge/release/publication gates. Owner has
  no running commands or active descendants; Q1 is root-owned independent work.

## Independent acceptance and publication

- Root accepted R1 and archived its stopped owner `01a18c65-e31d-7d82-b8b8-a8a9f563126a`, exit 0; all descendants had already stopped and been archived.
- Q1 reviewer `/root/release122_qa`, native `01a18c79-5384-7581-99e0-dc14e3c4bf98`, pinned `gpt-6-sol/high`, returned `pass_with_notes` for `e25d811..b6a16f6` (21 paths). Root accepts the verdict unchanged. It confirms all scoped UI, permission/nonce, compatibility, version/baseline, package and required local/PR verification criteria. Exact run metadata is also retained in `/tmp/cf7tg-122-pr-ci/run-api.json` (PR event, completed/success, exact head/base); checkout merge is `b254a3eb6a9b6d884aefa1a0f5fec4067ea43b52`.
- Nonblocking notes: media evidence uses CLI upload injection/fake transport; existing dev audit policy reports 78 findings; temporary PR E1/Plugin Check detail was not uploaded though enforced gates passed. R2 must retain uploaded support-matrix evidence. No required criterion was waived, no human decision needed. Reviewer commands stopped, no edits or descendants.
- R2 owner selection: fresh `batch_owner`, `gpt-6.1-sol/high`, for release evidence and public-artifact integrity. Root alone owns merge/tag/GitHub publication; owner delegates observation and public read-only validation to a fresh test monitor, with no implementation changes or live messages.

## R2 publication verification

- Root merged PR #73 as `62436ab8f11034fb74c0bd9904ceda1745409a9f`;
  `git diff b6a16f6 62436ab` exits 0. Annotated `v-1.2.2` and GitHub release
  were published by root. Q1 reviewer stopped and archived, exit 0.
- Owner `/root/release122_publish`, native
  `01a18c86-01ed-7203-92c1-b1ab1c85cc0f`, delegates only remote observation
  and public artifact checks to fresh pinned `gpt-6-luna/low` monitor
  `/root/release122_publish/release_observer`, native
  `01a18c86-d275-76c3-86f5-b53706582945`. No implementation writer exists.
- Frozen boundary is the tagged merge above; only this checkpoint differs.
  Required R2 gates: successful exact-tag verify/publish, inspected full support
  matrix including `upgrade-1.2.1` and history, runtime/audit/test thresholds,
  validated matching public GitHub/WordPress.org contents, SVN trunk/tag and
  stable mirror identity. No source repair, rerun, local broad suite or external
  mutation belongs to this batch. Root owns final acceptance and commit.
- Release run `41046707760` and master push `41046581240` were in progress
  at the exact frozen HEAD when observation began. One release watcher owns
  the process; no duplicate root observer. Evidence destination:
  `/tmp/cf7tg-122-release-ci/`. Initial sandbox GitHub discovery failed on
  network access; escalated read-only discovery succeeded, both attempts kept.

## R2 terminal verification and handoff

- Owner technical verdict `pass_with_notes`: all R2 required criteria pass;
  root inspected the actual run metadata, row counts and public comparisons.
  Release run https://github.com/hokoo/cf7-telegram/actions/runs/41046707760
  succeeded on `62436ab8f11034fb74c0bd9904ceda1745409a9f` (verify 21m16s,
  publish 40s). Master push
  https://github.com/hokoo/cf7-telegram/actions/runs/41046581240 also succeeded
  on that HEAD. Release-only push skips and prerelease-only release skips
  follow the existing workflow; no required release gate was skipped or waived.
- Owner inspected full logs, raw audit reports and actual summary assertions.
  PHPUnit 9.6.36: 114 tests/539 assertions; React: 8 suites/64 tests.
  Build/version/ZIP validation, reproducibility and Plugin Check passed.
  Audits: `blocking_failed=0`, runtime 2 moderate/0 high/0 critical;
  development 78 nonblocking findings (12 low/33 moderate/29 high/4 critical).
- Full support matrix: legacy WP5.6.19/PHP8.0/CF7 5.4.2 passed 384/384 steps
  across 11 cases (fresh plus 10 upgrades); current WP7.1/PHP8.3/CF7 6.2
  passed 349/349 across 10 cases; available optional PHP8.5 passed 349/349
  across the same 10 cases. Zero failed/expected-failed/skipped steps.
  Fresh is 20/20 and each upgrade 35/35. Every row includes `upgrade-1.2.1`
  with official baseline SHA-256
  `fcd80ed6aafd6d5f4580dafc817ba151e70a1c9e90dd0eeefb2eead016ffaaff`;
  historical cases, including 0.8.1 on legacy, remain covered.
- REST passed 11/11 steps and 24/24 checks; authenticated browser 28/28 and
  8/8; fake Telegram E6 57/57 and 37/37. These are isolated fake-transport
  gates; no real Telegram/email or local addon activation was performed.
- Public GitHub release is final, tagged `v-1.2.2`. Asset and release CI ZIP
  are 212366 bytes, SHA-256
  `2ac7d81e5c43264fe1171eef1bbe7f5f94a3e16013d50b0763c8088d2677fa53`.
  WordPress.org API reports version 1.2.2 and official download
  https://downloads.wordpress.org/plugin/cf7-telegram.1.2.2.zip; official ZIP
  SHA-256 `ac57316219a6ff714aa384b4f767c34cd337fba57d9cabf7a05748143f49b2cc`.
  `EXPECTED_VERSION=1.2.2 scripts/validate-release-zip.sh` passed on all three.
- Normalized comparisons prove identical 153 file paths and every uncompressed
  file byte across CI/GitHub/WordPress.org ZIPs, SVN trunk/tag exports and
  stable mirror. Directory/ZIP metadata differs harmlessly. Every archive
  member was scanned: zero addon/hidden/secret-named paths or bypass markers.
  SVN last-change revision is `3775097`; public repository HEAD advanced during
  reads, with unchanged plugin exports at r3775105. Trunk/tag readme stable
  tag, entrypoint header and runtime constant are 1.2.2. Stable mirror commit
  is `c41da0a0725a1ebf424fb120aeb4cc0ff07e7b06` (repository `stable` branch).
- Evidence root `/tmp/cf7tg-122-release-ci/`: `run-api.json`, `run.log`,
  `master-run-api.json`, `master-run.log`, downloaded `artifacts/`,
  `release-view.json`, `wporg-api.json`, public ZIPs, SVN info/exports,
  `stable-branch-api.json` and archive. Threshold/comparison evidence:
  `zip-comparison.json`, `svn-stable-comparison.json`,
  `package-content-marker-scan.json`, `validator-outputs.log` and
  `command-exit-manifest.json`. Exact commands/exits and terminal session IDs
  are in the manifest; successful watch/view/log/download/validate/compare
  commands all exited 0.
- Failed read-only attempts retained: initial sandbox GitHub discovery exit 1;
  official ZIP curl exit 23 before its destination directory existed; guessed
  separate stable repository lookup exit 128. Each was resolved once without
  source changes; failed outputs are in tool transcripts, summarized in the
  manifest. No CDN retry or release rerun was needed.
- Monitor `/root/release122_publish/release_observer`, native
  `01a18c86-d275-76c3-86f5-b53706582945`, reports all commands/sessions terminal
  and no descendants. Owner has no live commands or implementation changes;
  only this checkpoint differs from the tagged source, `git diff --check`
  passes. Existing Q1 audit/transport evidence limits remain nonblocking;
  no R2 blocker remains. Root owns final acceptance, documentation commit and
  supported lifecycle cleanup. This owner stops at R2; no next batch taken.

## Final acceptance

Root accepted R2 and the completed release without waiving any required gate.
All three source commits are merged; annotated `v-1.2.2` remains on verified
merge `62436ab8f11034fb74c0bd9904ceda1745409a9f`. GitHub, WordPress.org and
stable publication are verified as above. Root confirmed completed/stopped
states and archived the R2 observer and owner through the serving daemon,
both exit 0; all delivery agents and their commands have stopped.

This documentation-only evidence update is committed after publication with
`[skip ci]`, following the established release process. It does not move the
release tag or change the published package. The sibling addon's sole local
license bypass remains uncommitted and unchanged.
