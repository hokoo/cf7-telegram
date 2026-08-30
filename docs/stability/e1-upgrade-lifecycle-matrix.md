# Epic 1 Upgrade And Lifecycle Smoke Matrix

Epic 1 treats `1.0.10` as already published. The stability harness validates the `1.0.11` corrective candidate against published WordPress.org artifacts, including the published `1.0.10` baseline. Tagging and publication remain separate release actions.

## Scope

Included:

- fresh install and activation of the local candidate;
- upgrade from published `0.10`, `0.11`, `1.0.9`, and `1.0.10` artifacts;
- checksum and plugin header version verification for every published source artifact;
- cleanup cron presence, absence after deactivation, and duplicate detection;
- deactivation, reactivation, uninstall, and rollback restoration smoke checks;
- anonymized fixture schema for production-like fresh, legacy, partially migrated, and damaged states.

Out of scope for this E1.3 harness:

- changing plugin migration behavior;
- Telegram API transport assertions;
- React or REST API behavior;
- editing the developer Docker environment;
- using real customer secrets, full production dumps, or the persistent local development database.

## Commands

Artifact-only verification:

```bash
tests/stability/e1-smoke-matrix.sh --artifact-only
```

Full default matrix:

```bash
tests/stability/e1-smoke-matrix.sh
```

Run one case:

```bash
tests/stability/e1-smoke-matrix.sh --case fresh
tests/stability/e1-smoke-matrix.sh --case upgrade-1.0.10
```

Use an externally built production zip as the corrective candidate:

```bash
CF7TG_CANDIDATE_ZIP=/absolute/path/cf7-telegram-wp-plugin.zip \
CF7TG_EXPECTED_CANDIDATE_VERSION=1.0.11 \
tests/stability/e1-smoke-matrix.sh
```

Candidate source priority:

1. `CF7TG_CANDIDATE_ZIP`.
2. `dist/cf7-telegram-wp-plugin.zip`, expected from the release-gate/build task.
3. Local `plugin-dir` fallback only when ignored runtime artifacts already exist (`vendor/autoload.php` and `react/build`).

On a clean checkout without a supplied production zip, the harness fails clearly instead of producing a misleading incomplete candidate artifact.

Useful environment overrides:

- `CF7TG_E1_WORKDIR`: fixed temporary working directory.
- `CF7TG_E1_RESULTS_DIR`: fixed evidence output directory.
- `CF7TG_E1_CACHE_DIR`: cache for downloaded WordPress.org zips.
- `CF7TG_E1_WP_VERSION`: WordPress core version, default `7.0.4`.
- `CF7TG_E1_WP_CLI_IMAGE`: WP-CLI Docker image, default `wordpress:cli-php8.2`.
- `CF7TG_E1_CF7_VERSION`: Contact Form 7 version, default `6.0.6`.
- `CF7TG_E1_FIXTURE`: `legacy-heavy`, `legacy-basic`, `damaged-legacy`, `partial-modern`, or `none`.

## Isolation

The harness does not call the repository `docker-compose.yml`. It writes a compose file into a temporary work directory and uses a unique Compose project per case. The repository development database is protected because the harness never mounts `~/mysql-data/itron/cf7-telegram` and never uses the checked-in persistent MySQL service.

Every smoke case uses:

- a temporary Compose file under the run workdir;
- a unique Docker Compose project name;
- a Docker named volume scoped to that project;
- WordPress installed from scratch inside the project volume;
- published plugin zips mounted read-only from the run artifact directory.

The default WordPress and Contact Form 7 versions are pinned for reproducibility. Use env overrides when validating against a newer runtime. The WP-CLI wrapper is invoked as `php -d memory_limit=512M /usr/local/bin/wp` because `WP_CLI_PHP_ARGS` alone did not prevent extractor memory exhaustion with the moving `latest` WordPress target.

The DB readiness check uses `wp db check --skip-ssl` because the `mariadb-check` client bundled in `wordpress:cli-php8.2` rejects the MySQL 5.7 container's self-signed certificate during readiness probing. This is a harness compatibility flag and is not used as plugin behavior evidence.

The result files remain in the workdir so failures are auditable. Docker containers and volumes are removed after each case unless `--keep-workdir` is passed.

## Source Manifest

The committed source manifest is [tests/stability/e1-version-sources.json](../../tests/stability/e1-version-sources.json). It records the exact WordPress.org URLs and checksums used for published plugin artifacts.

`0.10` is represented by `0.10.2` because WordPress.org does not publish an exact `cf7-telegram.0.10.zip`, while it does publish `0.10.0`, `0.10.1`, and `0.10.2`.

The runtime evidence records every artifact checksum and the version parsed from `cf7-telegram/cf7-telegram.php`.

## Matrix

| Case | Baseline | Candidate | Fixture | Checks |
| --- | --- | --- | --- | --- |
| `fresh` | Clean WordPress | Local candidate or `CF7TG_CANDIDATE_ZIP` | none | install, activate, cleanup cron, no duplicate cleanup event, deactivate, uninstall cleanup |
| `upgrade-0.10` | Published `0.10.2` | Local candidate or `CF7TG_CANDIDATE_ZIP` | `legacy-heavy` by default | legacy activation, fixture seed, rollback export, WordPress single-plugin upgrade, migration event assertion, cleanup cron, deactivate/reactivate, uninstall, rollback import |
| `upgrade-0.11` | Published `0.11` | Local candidate or `CF7TG_CANDIDATE_ZIP` | `legacy-heavy` by default | same as above |
| `upgrade-1.0.9` | Published `1.0.9` | Local candidate or `CF7TG_CANDIDATE_ZIP` | `legacy-heavy` by default | same as above |
| `upgrade-1.0.10` | Published `1.0.10` | Local candidate or `CF7TG_CANDIDATE_ZIP` | `legacy-heavy` by default | same as above, validates corrective path from the already released version |

## Evidence

Each run writes:

- `results/evidence.jsonl`: one machine-readable pass/fail record per step;
- `results/summary.json`: aggregate result, failures, environment, and the full evidence array;
- `results/state/*.json`: WordPress snapshots after key lifecycle stages;
- `results/logs/*.log`: command output for each step.

The summary contains:

- `failed_steps`;
- `failures[]`;
- `environment.uses_repo_docker_compose=false`;
- `environment.dev_database_guard`.

A release or corrective candidate should not be considered stable while `failed_steps > 0`.

The GitHub Actions `verify` job runs this full matrix against the production ZIP before it uploads an artifact. Known migration failures therefore block publication rather than remaining advisory evidence.

## Rollback Runbook

The harness validates rollback mechanics inside each upgrade case:

1. Install and activate the published baseline.
2. Seed the fixture.
3. Export the database to `/runtime/<case>-rollback.sql`.
4. Upgrade to the candidate.
5. Uninstall the candidate during lifecycle smoke.
6. Import the rollback SQL.
7. Reinstall and activate the original published baseline zip.
8. Assert that the baseline plugin is active and pre-upgrade plugin options exist.

Operational rollback for a real site should follow the same order:

1. Put the site into maintenance mode or otherwise stop writes.
2. Export the current database and archive `wp-content/plugins/cf7-telegram`.
3. Restore the last known-good database snapshot from before the candidate upgrade.
4. Install the previous published `cf7-telegram` zip.
5. Activate the plugin and verify `cf7tg_cleanup` cron state.
6. Remove maintenance mode only after smoke checks pass.

## Fixture Redaction

Production-derived fixtures must use [tests/stability/fixtures/production-fixture.schema.json](../../tests/stability/fixtures/production-fixture.schema.json).

Required redaction:

- remove bot tokens entirely;
- hash Telegram chat IDs with a project-specific salt;
- hash emails and user identifiers;
- replace free text with shape descriptors such as field count, shortcode presence, meta key presence, and relation type;
- preserve only counts, option names, post types, statuses, relation names, and sanitized structural values needed to reproduce lifecycle behavior.

Do not commit raw SQL dumps, real tokens, unhashed chat IDs, customer names, emails, full message bodies, or uploaded files.

## Known Gaps

Application-level migration assertions are intentionally shallow in E1.3. The harness uses `Plugin_Upgrader::upgrade()` so its hook metadata matches a real single-plugin WordPress update. It proves that the update path runs, records whether a migration event was scheduled, leaves machine-readable state snapshots, and catches fatal lifecycle regressions. Exact semantic assertions for migrated bots, chats, channels, relations, malformed legacy data, and repeated migration idempotency belong to E2 after the migration contract is locked.

Telegram delivery and fake transport assertions belong to E3. This harness does not call the Telegram API.

See [the E1 traceability report](e1-traceability-report.md) for the P0-to-epic owner matrix.
