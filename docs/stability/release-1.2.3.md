# Release 1.2.3

## Authorization and scope

The user authorized merging PR #72 into master, raising the version on master
after the merge, and publishing 1.2.3. Starting source: `a71de44` on
`release/abilities-readonly`; master: `54c9c02`. Follow the established
[1.2.2 release procedure](release-1.2.2.md). Deliver the three accepted read-only
abilities and prepared changelog; the other three abilities remain future work.
Do not send real Telegram/email messages or change unrelated local addon/site
state. Preserve historical evidence and baselines. Root owns external writes.

## Execution and gates

### R1. Verify the existing PR and merge

Status: accepted (merged after R1a). Goal/scope: obtain current hosted CI evidence for PR #72
at `a71de44`, then merge it. Exclusions: product changes, version bump, release.
DoR: clean published branch and completed abilities QA; satisfied.
AC/DoD: required PR workflow checks pass at the frozen head; inspect test,
audit, integration and ZIP evidence; no required skips; root reviews the
technical handoff and merges with a head-SHA guard. Release-only gates remain
R3 obligations. Dependencies: GitHub CI. Risk: previous draft run was skipped.
Owner selection: fresh `batch_owner`, `gpt-6.1-sol/high`, for bounded CI evidence
and integration acceptance. Delegate observation to pinned `test_monitor`.

### R2. Prepare and verify version 1.2.3 on master

Status: in_progress (prepared; root commit/push pending). Goal/scope: metadata, current candidate
defaults, official 1.2.2 upgrade baseline and release documentation. Exclude
new features and unrelated cleanup. DoR: accepted R1 merged on master.
AC: header/runtime/readme/defaults agree on 1.2.3; official 1.2.2 baseline is
hash-pinned and covered without deleting historical cases; changelog is retained.
DoD: worker diff review and focused checks; committed/pushed on master by root;
required hosted verification passes, including real PHPUnit, React, audits,
package/reproducibility, Plugin Check, lifecycle, Core/REST/MCP and delivery.
Dependency: R1 and official baseline download. Root selects a fresh owner.

### Q1. Independent release acceptance

Status: waiting_dependency (R2). Fresh root-launched `epic_qa` reviews the frozen
source and actual R1/R2 evidence, scoped to release readiness and integration
of accepted abilities. Required criteria cannot be waived. Verdict and exact
boundary are recorded here before tagging. Full release support matrix and
public artifact identity remain R3 obligations under the established workflow.

### R3. Publish and verify

Status: waiting_dependency (Q1). Scope: root creates annotated `v-1.2.3` and
GitHub release; a fresh owner delegates observation/public reads. Exclude
implementation and real message sending. DoR: Q1 pass/pass_with_notes.
AC/DoD: exact-tag verify and publish succeed; full required support matrix and
audit thresholds pass; GitHub/WordPress.org ZIPs validate as 1.2.3 and have
identical normalized contents; SVN trunk/tag and stable mirror match. Record
actual hashes, revisions, commands and stop evidence. Final checkpoint commit
may follow publication with `[skip ci]` without moving the release tag.
Dependencies: GitHub Actions and WordPress.org publication. Report failed
publication or unavailable public artifacts as incomplete, never a pass.

## Checkpoints

- Root accepted completed R1/R1a after inspecting the actual repair diffs,
  final raw summary thresholds and exact head/base. PR #72 merged as
  `1c5986d4b50745eddc45d9adde8c47e0520e19b9`; merge tree equals `a682592`.
  Local checkout is now master. All R1a descendants/commands stopped; final
  monitor and owner archived through native daemon, both exit 0.
- R2 is now ready. Fresh owner selection: `batch_owner`, `gpt-6.1-sol/high`,
  for bounded version/baseline preparation and hosted verification. Delegate
  metadata to pinned worker and serial CI observation to pinned test_monitor;
  root alone commits/pushes/tags/publishes. The version bump must occur here
  after the actual master merge, preserving the user's requested order.

### R1a. Retain Plugin Check diagnostics and restore required CI

Status: technical_pass (root acceptance/merge pending). Goal: recover the missing blocking evidence and correct only a
demonstrated release blocker. Scope: use the existing Plugin Check results-dir
option and an always-uploaded CI artifact; keep gate thresholds unchanged.
DoR: failed R1 handoff accepted by root; observer/owner stopped and archived
(both native archive commands exit 0). AC: failed and successful runs retain
raw findings/logs; the actual blocker is identified, and all required PR gates
pass before merge. DoD: worker patch and focused verification, root commit/push,
serial monitor evidence, owner technical review. If diagnostics identify a
product fix, obtain a bounded worker repair with preserved abilities contracts;
no speculative product changes, version bump or waived checks. Dependencies:
GitHub Actions; R1 failure evidence above/below. Owner selection: fresh
`batch_owner`, `gpt-6.1-sol/high`, for a small CI repair and evidence-led diagnosis.

- Diagnostic patch boundary: `a71de44` plus nine added lines in
  `.github/workflows/build-zip.yml` (blob
  `6f6e52049cb3a47079d348500a3b84dd8d07b026`). Reuses
  `CF7TG_E5_PLUGIN_CHECK_RESULTS_DIR` and uploads the full directory through
  an adjacent `always()` artifact step; gate thresholds and conditions unchanged.
  Owner inspected the actual complete diff; worker YAML parse and owner/worker
  `git diff --check` passed (exit 0). Hosted upload and required gates pending;
  root owns the diagnostic commit/push. No product/version edits.
- R1a owner `/root/release123_ci_repair`, native
  `01a193d7-fa01-7182-8f3e-b61f761b9445`, selected `gpt-6.1-sol/high`;
  worker `/root/release123_ci_repair/diagnostics_workflow`, native
  `01a193d8-d231-7551-9369-c35dc9c8ee47`, role-pinned `gpt-6-sol/high`.
  Worker handoff confirms no running commands or descendants; implementation
  stopped before external verification. Root may clean up the accepted worker.
- Root accepted/committed/pushed diagnostic change as
  `9da2725c97d2ea4fc5e3c00b39f08e686e7220bd` (push exit 0).
  Owner independently ran `python3 -c 'import yaml; yaml.safe_load(open(".github/workflows/build-zip.yml")); print("YAML parse passed")'`
  successfully (exit 0); initial `python` invocation exited 127 because that
  binary is absent. Fresh role-pinned `gpt-6-luna/low` monitor
  `/root/release123_ci_repair/diagnostic_ci` observes only this frozen PR head,
  with diagnostic artifact retrieval prioritized before bulk downloads.
- Diagnostic run [41243467713](https://github.com/hokoo/cf7-telegram/actions/runs/41243467713)
  at `9da2725` retained failure evidence successfully. Owner inspected actual
  `summary.json`, `plugin-check.raw.json` and `Abilities::getRecentErrors()`.
  Plugin Check 1.9.0 on WP 7.1/CLI PHP 8.3/CF7 6.2 completed with
  `infrastructure_failed=false`, two errors and nine warnings. Both errors at
  `lib/Abilities.php:290` concern dynamic `$query` passed into `prepare()`:
  `PluginCheck.Security.DirectDB.UnescapedDBParameter` and
  `WordPress.DB.PreparedSQL.NotPrepared`. The existing numeric parameters are
  prepared, but the static gate does not recognize the assembled query shape.
  This is a required-gate failure; no threshold/suppression exception accepted.
- Raw diagnostic artifact `13058780185` SHA-256
  `b3ac2b36d2715fe1571a5b9265c551091471a98eb223d634625d2e293e7219b0`,
  extracted `/tmp/cf7tg-123-r1a-ci/plugin-check/`; summary SHA-256
  `e095b97f2317c1de85573d8239fc23ce59275eacd29e2e2de289ef1d3e68afd0`,
  raw JSON SHA-256 `0f3f3e2258095f951188e799858053042f98d38f59f79c1a3991ae7d5ed36dcb`.
  Monitor native `01a193da-653d-7b51-ae0d-5fd699e2d8ff`.
  Single `gh run watch` attempt exited 1 on restricted-network connection;
  connector snapshots/retrieval continued. No credential defect inferred.
  Root authorized a narrow same-R1a SQL-shape repair after monitor stops:
  preserve cursor/no-cursor semantics, safe identifier escaping, WP 5.6,
  public contracts and storage-error behavior; require focused PHP checks
  plus exact static finding verification before the next hosted push.
- Diagnostic run terminal verdict: **fail**, job `124661960923`, duration
  2m13s, tested merge `00614f3142cccaa49c9bf5066f64a7a72fdfc7c2`.
  Required downstream gates skipped; merge remains blocked. Monitor handoff
  `/tmp/cf7tg-123-r1a-ci/summary.txt` confirms no live commands/descendants.
  Root archived that monitor by native ID (exit 0). Fresh pinned
  `gpt-6-sol/high` worker `/root/release123_ci_repair/sql_gate_repair` owns only
  the demonstrated SQL-shape defect and strictly necessary focused regression
  adjustment. No successor release batch was taken.
- SQL repair inspected: `Abilities.php` only, seven insertions/seven deletions,
  blob `68cc5b0bf9e6ce65d48fb6e41d93a78e07068384` to
  `7bd0aedb7dbcd22d9915daac942c69578933f602` over `9da2725`.
  Explicit prepared SQL covers cursor/no-cursor branches; `%d` numeric args,
  escaped backtick table identifier, ordering, limit+1 and storageRead remain.
  Worker native `01a193dd-f5c5-7141-8c27-f83b47d2fd44`. Owner inspected actual
  complete diff and pinned Plugin Check 1.9.0/PHPCS 3.13.5 JSON reports:
  plugin-review and DirectDB each reproduce one ERROR before and zero after.
  Reports remain `/tmp/cf7tg-123-r1a-sql/`; warnings about the safely escaped
  table identifier remain nonblocking under existing policy, without suppression.
  PHP 8.0 lint and `git diff --check` pass; four existing recent-error cases
  pass through compatibility harness. Real local PHPUnit lacks dom/mbstring/
  xmlwriter; hosted PHPUnit remains required. First ad hoc harness attempt
  exited 255 for duplicate class inclusion; corrected single include passed.
  No tests/docs/dependencies/version changed by worker, no commits made.
- SQL repair technically accepted for root commit/push. Exact local commands,
  exit codes and retained failed probes are in
  `/tmp/cf7tg-123-r1a-sql/summary.txt`; PHPCS uses bundled 3.13.5 under PHP 7.4
  because local PHP 8.0 lacks XML extensions. Both after-reports have zero
  errors (their exit 1 reflects warnings); explicit zero-error assertion exits 0.
  Worker final handoff confirms no running commands or descendants. Full hosted
  gate evidence on the next committed head remains mandatory.
- Root accepted/committed/pushed SQL repair as
  `a6825926c33533a647ca0cce29bfe8f5adb5db28` (push exit 0) and archived the
  stopped SQL worker (native archive exit 0). Fresh pinned `gpt-6-luna/low`
  monitor `/root/release123_ci_repair/final_pr_ci` owns the complete required
  PR ladder on that exact head. No writer active; version bump remains R2.
- Final PR run [41244205082](https://github.com/hokoo/cf7-telegram/actions/runs/41244205082)
  (#160), exact head `a682592`. Owner inspected retained Plugin Check
  summary/raw JSON: 13/13 passed, zero failed, `infrastructure_failed=false`,
  zero ERRORs and 15 WARNINGs. No suppression or threshold change. Artifact
  `13059275623` SHA-256
  `084b517b4522e9887578c43454418272e09a43d715799d6a892e623cfe0ab0ab`,
  retained `/tmp/cf7tg-123-r1a-final-ci/plugin-check/`. Default curl DNS failure
  exited 6; authorized escalated retry exited 0. Monitor native
  `01a193e2-ef67-73d1-84f7-a8a937e6a076`; full required ladder still pending.
- Final R1a technical verdict **pass** on frozen `a682592`, tested synthetic
  merge `f2e90e5d8a3d10421b750815d03b93c76b4bc05b`, run #160
  `41244205082` success (13m24s). Owner reviewed actual two-file scoped diff,
  full-log decisive lines and raw summaries: real PHPUnit 9.6.36 135/921;
  React 8 suites/64 tests; lint, ZIP validation and reproducibility pass;
  audit `blocking_failed=0`, full-dev npm remains policy-nonblocking.
  Current lifecycle passed in 6m08s (enforced step/full log; detailed JSON
  remains runner-temporary). Legacy WP 5.6.19/CF7 5.4.2 passed 35/35 steps;
  minimum WP 6.9 passed 33/33, current WP 7.1 + official MCP 0.7.1 passed
  49/49; both report 39 abilities assertions/zero failed. Browser passed
  28 steps and one Playwright test; fake Telegram passed 57 steps and four
  tests. Both Playwright reports show zero skipped/unexpected/flaky tests.
  All required PR gates/uploads passed; only expected release-only gates
  skipped. Failed and successful diagnostic uploads are both proven.
- Final retained evidence `/tmp/cf7tg-123-r1a-final-ci/summary.txt`, raw
  `run.log` SHA-256 `f83b288813f1e0ef0f0abe7b5459a9300c1d04c03bb65dc238bc1db4ee5b5942`,
  complete reports under `audit/`, `plugin-check/`, `matrix/`, `rest/`,
  `browser/`, `fake-e2e/`. Candidate ZIP SHA-256
  `fb0dd77d1db08da095f7f671eec370a842cae73f6c0ff63f4ea9c8002a6ead71`,
  version 1.2.2 intentionally unchanged pending R2. Final PR read remains
  open/unmerged at exact head; local status only this checkpoint untracked.
  Final monitor handoff confirms no running watcher/download/command or
  descendants; root owns archival and complete-batch acceptance. Owner stops
  at R1a, with no successor batch taken. Next root action: accept R1a and
  head-guarded PR merge; R2 still requires that merge.

- Initial state: clean `a71de44`, PR #72 open/draft and mergeable. User has
  explicitly authorized merge/version/publication. Required checks will run
  by marking the PR ready; prior skipped run is not acceptance evidence.
- R1 technical verdict **fail** at frozen head
  `a71de44a8999c4a962fe020715a4d24db85c46be`, base
  `54c9c024fa19a5a02c697717da4c96c4d5bc53cd`. Current PR run
  [41242677236](https://github.com/hokoo/cf7-telegram/actions/runs/41242677236),
  attempt 1, tested synthetic merge `8b6db1e3be1c404e428efaaed5623275ae71c5fe`;
  job `124659622451` failed. No merge, rerun, repair or waiver performed.
- Owner inspected actual retained job log, raw audit JSON and workflow gate
  logic. PHP lint passed; real PHPUnit 9.6.36 passed 135 tests/921 assertions;
  React passed 8 suites/64 tests. Audit `blocking_failed=0`: Composer root,
  plugin and platform passed; npm runtime has 2 moderate, 0 high/critical.
  Full-dev audit exit 1 is explicitly nonblocking: 113 findings
  (6 low/74 moderate/29 high/4 critical). Build validated ZIP version 1.2.2
  twice and reproducibility passed; verified ZIP upload was subsequently skipped.
- Required Plugin Check command `tests/stability/e5-plugin-check-gate.sh`
  exited 1. Raw log lines 1037–1039 report only `1 failing step(s)` and
  runner-temporary summary
  `/tmp/cf7tg-e5-plugin-check-20261031T211708Z-3648.0tRbXt/results/summary.json`.
  Summary/raw findings/stdout/stderr were not uploaded; the actual failure
  cannot be classified as product or environment from retained evidence.
  Current lifecycle, legacy no-API/fake delivery, minimum/current Core,
  official MCP, browser and fake-Telegram E2E were skipped, so their AC/DoD
  remains unmet. Their evidence uploads failed for missing paths. Release-only
  support matrix/publication skips are expected and remain R3 obligations.
- Evidence: `/tmp/cf7tg-123-pr-ci/summary.txt` records exact connector calls,
  command outcomes, steps and stop status; raw log `run.log` SHA-256
  `798d45946fe2f5e433a68c27fd681add3bc7389d474d9b90d5267009998e15d4`.
  Audit ZIP `/tmp/cf7tg-123-pr-ci-audit.zip` SHA-256
  `bf2122fafc2b080a66b04778e2fbbc4e0cb0c259d3ee67137d943c892bc5d688`
  matches GitHub artifact digest; extracted reports are in the evidence `audit/`.
  Initial restricted-network artifact download failed DNS; authorized retry
  succeeded. No broad local tests ran. Checkout source stayed unchanged;
  only this pre-existing untracked checkpoint was updated by owner.
- Owner `/root/release123_pr_verify`, native
  `01a193d1-229f-7f52-a928-f2dd0783cbea`; monitor
  `/root/release123_pr_verify/hosted_ci`, native
  `01a193d1-7a49-7870-84cc-a5324d3321ae`, pinned `gpt-6-luna/low`.
  Monitor completed without descendants or live watcher/retrieval processes.
  Owner stops after rejected R1 handoff; root owns lifecycle cleanup.
  Next recommended work: a fresh bounded diagnostic repair batch to retain
  Plugin Check findings, fix only a proven cause, then obtain the full required
  PR gates before merge. R2/Q1/R3 remain waiting; no successor was taken.

### R2. Version and official upgrade baseline on master

- Owner `/root/release123_prepare`, native
  `01a193f3-3fc1-7702-9f80-4a2c8b387284`, selected `gpt-6.1-sol/high`.
  Starting boundary `1c5986d4b50745eddc45d9adde8c47e0520e19b9` on master;
  only this checkpoint untracked. Fresh worker
  `/root/release123_prepare/metadata_baseline` uses actual role-pinned
  `gpt-6-sol/high`, owns metadata/defaults, official 1.2.2 baseline and scoped
  backlog/release notes. Root alone commits/pushes. Ponytail applied: follow
  existing release precedent without product or dependency changes.
- Root clarified R2 verification follows the existing master-push event:
  required PHPUnit/React/audits/build/reproducibility/Plugin Check, current
  lifecycle (including new 1.2.2 upgrade), legacy fake delivery and minimum/current
  Core/REST/MCP must pass. Browser/full E6 are explicit PR-or-release gates,
  expected skipped on push; retain exact R1 PR evidence and require exact
  1.2.3 browser/E6 during R3 before publication. Q1 reviews this division.
  No workflow changes, ad hoc reruns or required-gate waivers.
- Metadata worker native `01a193f3-9ed1-76e3-ba77-e012743daaba` completed
  and stopped all commands/descendants. Owner inspected all 11 tracked-file
  changes (39 insertions/25 deletions), complete release notes and focused logs;
  preparation technically accepted for root commit/push. Stable local boundary:
  HEAD `1c5986d4b50745eddc45d9adde8c47e0520e19b9` plus binary diff SHA-256
  `d9ed544e990cb89d328d3d5fe8d356fbbb7837aa9336567f347928ddbe732ca6`.
  Header/runtime/readme/bootstrap and runner defaults agree on 1.2.3; the
  prepared changelog and all older baseline entries remain unchanged. New
  `upgrade-1.2.2` covers default lifecycle and all required/optional rows.
- Actual downloaded official 1.2.2 ZIP (212366 bytes) verifies SHA-256
  `ac57316219a6ff714aa384b4f767c34cd337fba57d9cabf7a05748143f49b2cc`,
  matching the new manifest pin. Worker checks all exit 0: `php -l` on two
  changed PHP files, `bash -n` on four changed shell scripts, `node --check`
  on two changed specs, `jq -e .` on the manifest, `unzip -tqq` on official
  baseline, `python3 /tmp/cf7tg-123-r2-prep/check-metadata.py` semantics
  (every previous case/entry retained; every row adds 1.2.2), and
  `git diff --check`. Owner independently confirmed binary diff/baseline hashes
  and `git diff --check` exit 0. Logs/hashes:
  `/tmp/cf7tg-123-r2-prep/focused-checks.log`, `semantic-check.log`, `boundary.txt`.
- English release notes `/tmp/cf7tg-123-release-notes.md` SHA-256
  `b598a394243a7211d5b7e7a6add5582b26e692f5531cc3169457cd2bf4c57f10`
  accurately cover three shipped read-only abilities, permissions, incomplete
  logs/static diagnosis and three future abilities outside 1.2.3. No product,
  dependency or workflow change. Hosted verification on root's new exact head
  remains mandatory; no broad local reruns performed. Root may archive the
  stopped accepted worker before a fresh serial monitor is launched.
- Root review found two stale current backlog summaries. Same unfinished worker
  assignment corrected only `docs/backlog/README.md` (PR #72 merged, 1.2.3
  metadata/baseline prepared; publication pending) and top specification wording;
  historical R1/R2/version 1.2.1 evidence preserved. Owner inspected both hunks;
  `git diff --check` exit 0. No tests rerun. Final precommit boundary supersedes
  earlier hash: 12 tracked files, 45 insertions/31 deletions, binary diff SHA-256
  `13139405d82bfec863886703641bd95cc2a7d9b7fe1a747b6ca3e49032e85dbe`
  over `1c5986d4`. Worker stopped again, no commands/descendants; same native ID.
