# Release 1.2.2

## Authorized scope and delivery

User requested logical commits for all accumulated changes and publication of version 1.2.2. Source baseline: `e25d811aa7bff4163eef652b19c8b5f2b775464e` (`master`, also remote master on 2026-10-30). Follow the established `release-1.2.1.md` process: candidate PR, required checks, independent acceptance, merge, annotated `v-1.2.2` tag, GitHub release, WordPress.org publication and public-artifact verification.

Include the local Media Files compatibility harness/mounts and settings card with responsive 200px sidebar and installed-but-inactive activation state. Preserve old plugin compatibility. Exclude unrelated PRs #64 and #72. The sibling addon is already committed at `0437481af4da9a2131a65eff8e9d3b1fc9260dd2` (version 1.2); its sole dirty license-bypass line must remain uncommitted and outside every published artifact. Do not change the local site's addon activation state or send real Telegram/email messages.

## Work and acceptance

1. **R1 — prepare and verify candidate (ready).** Review the accumulated diff; make only necessary release or portability repairs. Create logical commits for (a) local addon integration/compatibility evidence, (b) settings UI and state/activation tests, (c) release metadata/changelog and exact published 1.2.1 upgrade baseline. Update current candidate defaults to 1.2.2, retaining historical baselines. Required local checks: PHP/React suites, lint, build, ZIP validation and audits; PR CI supplies real PHPUnit, lifecycle, REST, browser and fake-Telegram gates. Existing visual evidence covers three addon states at widths 1920, 1440, 1439, 1120, 768 and 390, including focus and overflow. No duplicated broad tests unless source changes invalidate evidence.
2. **Q1 — independent candidate acceptance (depends on R1).** Fresh read-only epic QA reviews the frozen candidate and actual verification evidence; required criteria must pass before publication.
3. **R2 — release and verify (depends on Q1).** Root merges the accepted candidate, tags/releases it, and delegates observation of release checks and public artifact validation. Required completion: release workflow verification/publication successful, GitHub asset and official WordPress.org 1.2.2 ZIP validated and normalized contents equal, SVN trunk/tag and stable mirror match. Record final evidence in this checkpoint.

R1 owner selection: `batch_owner`, explicit `gpt-6.1-sol` / `high`: bounded release engineering with existing CI and compatibility evidence. Worker and test-monitor role defaults remain pinned; one writer at a time. Root owns commits/PR/publication unless explicitly delegated. No implementation agent may publish.

## Checkpoints

- Initial inventory: seven tracked modified files plus `mediafiles-compatibility.md` and three local media test helpers; main version 1.2.1. Remote master equals local baseline; v-1.2.2 absent. Existing addon commits and local bypass preserved.
- R1 in progress. Owner `/root/release122_prepare`, native
  `01a18c65-e31d-7d82-b8b8-a8a9f563126a`, selected `gpt-6.1-sol/high`.
  Fresh worker `/root/release122_prepare/prepare_source`, native
  `01a18c66-6ddb-75b1-980b-d21c084c5cb3`, pinned `gpt-6-sol/high`, owns
  source/metadata/defaults and minimal local harness prerequisites. Root owns
  commits/PR/publication. Source freeze and serial local verification pending.

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
- Source/package preflight pending. Real PHPUnit and required lifecycle,
  Plugin Check, REST, browser and fake-Telegram PR gates plus independent QA
  remain mandatory. Root owns logical commits and subsequent PR/publication.
- Local monitor native `01a18c6a-0a65-7731-aff4-a45f6f6509a3`, pinned
  `gpt-6-luna/low`; initial frozen hashes match. PHP lint passed; host PHP
  compatibility suite passed 114 tests/0 failures (dom/mbstring/xmlwriter
  unavailable); React passed 8 suites/64 tests. Standard ZIP build in progress.
- First standard build exited 1 in `npm ci`: `EAI_AGAIN` resolving
  `registry.npmjs.org/@wordpress/eslint-plugin`; npm's default log directory
  was also unwritable in sandbox. Failed attempt retained. Narrow network
  escalation remained pending without starting its retry when checked;
  monitor continues that same request, no duplicate command or gate waiver.
- Root proceeds with three logical source commits and PR from the accepted
  frozen file contents while network approval is pending; HEAD/index changes
  from these commits are expected. Source content edits remain frozen.
  ZIP/build/audit and real PR runtime evidence are still required before full
  R1 acceptance. No new owner or independent QA starts before that acceptance.
- Network escalation subsequently executed: successful build exit 0 produced
  valid 1.2.2 ZIP SHA-256
  `a0da7ff288e2e66347f3394c9e2e535af433de79f1ae7b6fd8a9545d417901d7`,
  saved as `/tmp/cf7tg-122-local-preflight/reference.zip`. Second default
  reproducibility build exited 1 (DNS), leaving that same old ZIP; comparing
  it with its saved copy does not establish reproducibility. Owner requires
  a successful second build before accepting that gate.
- ZIP validator passed; negative hygiene rejected 17 forbidden fixture entries.
  Default audit attempt failed obtaining remote data (3 blocking report
  commands; platform requirements passed), rather than proving vulnerability
  thresholds. Failed reports retained under the monitor's `audit-reports/`;
  escalated audit retry and genuine second-build evidence pending. Early
  monitor command output remained in tool transcripts instead of full log
  files; owner required persistent logs for subsequent commands.
