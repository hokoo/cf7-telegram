# E8: Legacy 0.8.1 Upgrade Regression Coverage

## Goal

Prove that an installation running the published `0.8.1` plugin can upgrade to
the current candidate without losing the legacy Telegram delivery configuration
of any Contact Form 7 form that was enabled with `[telegram]`.

The acceptance target is migration continuity, not multilingual-plugin
compatibility. The test data contains ordinary Contact Form 7 posts only.

## Decision Record

Recommended transition environment:

- source artifact: the exact published `cf7-telegram.0.8.1.zip` artifact;
- WordPress `5.6.19`, PHP `8.0`, and Contact Form 7 `5.4.2`;
- target artifact: the local candidate ZIP with the version declared in
  `tests/stability/e1-version-sources.json`.

This is the existing `legacy-wp56-php80-cf7-542` support row. It is the
smallest supported environment in which both the legacy artifact and current
candidate can boot, so a failure identifies the plugin transition rather than
an unrelated incompatibility in a seven-year-old source artifact.

Alternative considered and rejected: run the `0.8.1` artifact in every current
support row. That expands the gate and can fail before migration because the
source artifact predates current WordPress and Contact Form 7 releases. It
does not add meaningful evidence for the legacy-to-current data transition.

## Scope

- Add the exact `0.8.1` WordPress.org ZIP and its SHA-256 to the source
  manifest.
- Add a dedicated `upgrade-0.8.1` E1/E2 lifecycle case in the legacy support
  row only.
- Use the existing anonymous `legacy-heavy` fixture: two independent
  `wpcf7_contact_form` posts with `[telegram]`, legacy token, and legacy
  subscribers.
- Add a focused PHP regression test that invokes the registered migration from
  source version `0.8.1` and asserts that every legacy-enabled CF7 form, and
  only those forms, receives a `form2channel` relation.
- Verify the candidate by the focused PHP suite and isolated E1/E2
  `upgrade-0.8.1` matrix run.
- Add the resulting command and evidence expectations to the stability
  documentation.

## Out of Scope

- Installing, mocking, configuring, or asserting behavior of WPML or any
  multilingual plugin.
- Resolving translated form IDs at run time.
- Changing the legacy importer, delivery logic, database schema, migration
  semantics, support policy, or production release process.
- Live Telegram calls, production data, and non-anonymised database dumps.

## Success Criteria

- The exact `0.8.1` source ZIP is checksum-pinned and selected by a named,
  reproducible E1 case.
- Before the upgrade, the fixture has two enabled ordinary CF7 forms.
- After the candidate migration has run, there is exactly one shared bot,
  one shared channel, all valid legacy chats, and exactly two `form2channel`
  relations—one from each enabled form to the migrated channel.
- Forms and their literal `[telegram]` content are preserved; a form without
  the tag receives no relation.
- A second migration run does not create duplicates or modify the result.
- The normal PHP suite and the exact upgrade case pass without WPML installed,
  mocked, referenced, or downloaded.

## Risks and Controls

| Risk | Control |
| --- | --- |
| The old artifact cannot boot in a modern runtime, obscuring migration behaviour. | Restrict the case to the existing legacy support row; preserve its logs and report a source-boot failure separately. |
| The `0.8.1` ZIP changes or disappears upstream. | Pin SHA-256 in the manifest and fail closed on checksum mismatch. |
| The background migration has only been scheduled, not executed. | Run and assert the named migration event in the isolated matrix; keep the existing durable-state checks. |
| A generic two-form fixture fails to prove selector boundaries. | Add the focused PHP test with both enabled and unmarked ordinary CF7 posts. |

## Execution Tasks

Order: T8.1 → T8.2 → T8.3. T8.4 independently validates the completed epic.

### T8.1. Add the exact 0.8.1 transition to the isolated lifecycle matrix

Status: completed

Goal: make the published `0.8.1` artifact a checksum-verified, reproducible
legacy upgrade baseline.

Scope: `tests/stability/e1-version-sources.json`,
`tests/stability/e1-smoke-matrix.sh`, and the relevant E1/E2 documentation.

Out of Scope: changes to plugin runtime code, WPML, or non-legacy support rows.

DoR:

- This E8 plan and the first execution batch are explicitly approved by the
  owner.
- The published `0.8.1` ZIP is available from WordPress.org and its checksum
  can be recorded.

DoD:

- `upgrade-0.8.1` installs the exact source artifact, seeds the existing
  anonymous fixture, upgrades to the candidate, executes migration, and emits
  E1/E2 evidence on the legacy support row.
- The case is not scheduled in newer support rows.

AC:

- Given `--case upgrade-0.8.1`, when the legacy matrix runs, then it verifies
  the source ZIP checksum before installation.
- Given the migrated fixture, when E2 evaluates it, then the expected
  `form2channel` count equals the number of enabled fixture forms.

Dependencies:

- Owner approval of this plan and execution batch.

Notes/Risks:

- The source artifact must remain treated as a migration baseline, not as a
  newly supported standalone release.

### T8.2. Add source-version 0.8.1 form-relation regression coverage

Status: completed

Goal: prove at the PHP migration boundary that legacy form selection is
complete and excludes unmarked ordinary CF7 forms.

Scope: `plugin-dir/tests/LegacyMigrationImporterTest.php` and, only if needed,
test-bootstrap helpers.

Out of Scope: WPML data, WPML hooks, multilingual metadata, and production
importer changes.

DoR:

- T8.1 has established the named transition baseline.
- The test can invoke the current migration runner with source version
  `0.8.1` without adding a third-party dependency.

DoD:

- A test seeds two independent enabled `wpcf7_contact_form` records and one
  unmarked record, runs the `0.8.1 → current` migration, and verifies the two
  expected source IDs each have one relation to the migrated channel.
- The test verifies no relation originates from the unmarked form.

AC:

- Given an enabled form whose tag is stored in legacy form data, when the
  migration runs, then that exact form ID is connected.
- Given an unmarked CF7 form, when the migration runs, then it remains
  unconnected.
- Given the migration is run twice, when relations are inspected, then no
  duplicate `form2channel` pair exists.

Dependencies:

- T8.1 completed.

Notes/Risks:

- This test proves the importer contract independently of the external E1
  environment; it intentionally models no language association.

### T8.3. Verify and publish E8 evidence

Status: completed

Goal: demonstrate that unit and isolated upgrade evidence agree for the exact
legacy source version.

Scope: focused PHP test, complete PHP suite, and the legacy-row
`upgrade-0.8.1` E1/E2 command; update this document with reproducible results.

Out of Scope: release tagging, deployment, and acceptance of unrelated matrix
failures.

DoR:

- T8.1 and T8.2 are completed.
- Candidate build dependencies and Docker images for the legacy support row
  are available.

DoD:

- Commands, summary, generated evidence location, and source/candidate
  versions are recorded.
- All E8 success criteria pass, or a reproducible migration defect is
  reported without weakening assertions.

AC:

- Given the documented commands, when run in a clean isolated work directory,
  then they produce an unambiguous pass/fail result for `upgrade-0.8.1`.

Dependencies:

- T8.1 and T8.2 completed.

Notes/Risks:

- Network or container-image failures are environment failures, not evidence
  that migration behaviour passed.

### T8.4. Independent E8 QA

Status: waiting_dependency

Goal: independently verify the delivered matrix case and PHP regression test
against this plan.

Scope: review of the manifest, fixture boundaries, test assertions, commands,
and generated evidence.

Out of Scope: implementing fixes and expanding scope to third-party plugins.

DoR:

- T8.1–T8.3 completed and committed.
- Test evidence is available to the reviewer.

DoD:

- An independent reviewer records pass, pass-with-notes, or fail against every
  E8 success criterion.

AC:

- The reviewer confirms no WPML package, hook, metadata, fixture, or download
  was introduced.
- The reviewer confirms the result comes from the exact `0.8.1` artifact and
  not the repository tag or an approximate version.

Dependencies:

- T8.1, T8.2, and T8.3 completed.

Notes/Risks:

- Any product-visible regression or missing evidence requires owner direction
  before E8 can close.

## Planned Verification Commands

```bash
php plugin-dir/tests/run.php --compat

CF7TG_E2_CHARACTERIZATION=1 \
CF7TG_E1_WP_VERSION=5.6.19 \
CF7TG_E1_WP_CLI_IMAGE=wordpress:cli-php8.0 \
CF7TG_E1_CF7_VERSION=5.4.2 \
tests/stability/e1-smoke-matrix.sh --case upgrade-0.8.1
```

The implementation may run a narrower named PHP test first, but the full PHP
suite and exact isolated upgrade command remain required before T8.3 completes.

## Verification Results

Verified on 2026-09-07 against the local `1.1.0` candidate and the exact
published `0.8.1` source artifact:

- Source ZIP: `cf7-telegram.0.8.1.zip`, `13,907` bytes, SHA-256
  `ae2be3a8df58683884f1013ab1dd619bb3c4f867267a9fdd3b16b7658759532d`.
- `php plugin-dir/tests/run.php --compat`: `95` tests, `0` failures.
- The isolated E1/E2 command above: `100` passed steps, `0` failed steps, and
  `0` expected failures. It ran WordPress `5.6.19`, PHP `8.0`, Contact Form 7
  `5.4.2`, and the `legacy-heavy` fixture.
- E2 proved the scheduled migration completed, created the expected entity and
  relation counts, preserved both form bodies and `[telegram]` tags, and left
  the second migration run unchanged. In particular,
  `expected_relation_counts`, `no_duplicate_relations`, and both form-tag
  preservation checks passed.

The isolated run wrote temporary evidence to
`/tmp/cf7tg-e1-20260907T155242Z-12249.gFHfV0/results/summary.json`. This path
is intentionally not a repository artifact; rerun the documented command to
produce fresh evidence.
