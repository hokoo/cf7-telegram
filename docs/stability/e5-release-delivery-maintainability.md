# Epic 5 Release Delivery And Maintainability

Status: completed

Release line: `1.0.13`

## Outcome

Release ZIP creation and WordPress.org publishing are reproducible, fail-closed,
and backed by executable lifecycle, browser, rollback, Plugin Check, dependency
audit, and artifact hygiene evidence.

## Delivered Scope

- React build migrated from Create React App customization to
  `@wordpress/scripts`.
- PHPUnit is the primary PHP test runner.
- Production ZIP validation rejects forbidden runtime/build entries.
- Dependency audits distinguish shipped runtime risk from development-tool risk.
- Plugin Check is a CI release gate and fails closed.
- The support matrix covers fresh install, upgrade, uninstall, rollback, and
  cached official support images.
- Browser lifecycle smoke uses Playwright against an isolated WordPress install.
- Central logging redacts secrets and PII-like values and bounds retention.
- Final GitHub releases publish automatically to WordPress.org after release ZIP
  validation; prereleases remain GitHub-only canaries.
- SVN deployment was made race-free and idempotent, closing the `1.0.12`
  publication failure mode.

## Task Traceability

| Project Item | Status | Evidence |
| --- | --- | --- |
| E5.1 Replace CRA with `@wordpress/scripts` | completed | `plugin-dir/react/webpack.config.js`, package scripts |
| E5.2 Normalize PHPUnit unit test runner | completed | `plugin-dir/phpunit.xml.dist`, `plugin-dir/tests/run.php` |
| E5.3 Supported WordPress, PHP and CF7 matrix | completed | Project decision record, support matrix scripts |
| E5.4 Expand ZIP lifecycle compatibility matrix | completed | `tests/stability/e5-support-matrix.sh` |
| E5.5 WordPress integration and browser lifecycle E2E | completed | `tests/e2e/e5-browser-smoke.spec.js`, `tests/stability/e5-browser-smoke.sh` |
| E5.6 Plugin Check release gate | completed | `tests/stability/e5-plugin-check-gate.sh` |
| E5.7 Dependency audit and artifact hygiene gates | completed | `scripts/run-release-audits.sh`, `scripts/validate-release-zip.sh` |
| E5.8 Central log redaction and retention | completed | `plugin-dir/lib/LogRedactor.php`, logger tests |
| E5.9 Release publishing and rollback gate | completed | `.github/workflows/build-zip.yml`, `scripts/deploy-wordpress-svn.sh` |
| E5.10 Verify and prepare `1.0.13` release candidate | completed | GitHub CI, local release artifact evidence |
| QA E5 Independent delivery and release verification | completed | Project QA record |

## Verification

The E5 closure evidence is the combination of:

- `php tests/run.php`;
- `CI=true npm test -- --watchAll=false --runInBand` in `plugin-dir/react`;
- `npm run build` in `plugin-dir/react`;
- `scripts/build-release-zip.sh`;
- `scripts/validate-release-zip.sh`;
- `scripts/run-release-audits.sh`;
- `tests/stability/e5-support-matrix.sh`;
- `tests/stability/e5-browser-smoke.sh`;
- `tests/stability/e5-plugin-check-gate.sh`;
- `tests/stability/e5-svn-deploy-test.sh`;
- GitHub `build-zip` release workflow evidence.

Final `1.0.13` candidate ZIP SHA recorded in Project E5:
`afe8e32809a3ee44d450fa552f9620c246a2b4a72269bbfbb473a7cc9d00b98b`.

## CI Recheck Note

The investigated `1.0.12` build failure was a CI/runtime mismatch: Playwright
`1.62` required Node 20 while the workflow was still running Node 18 before the
browser checks. E5 pinned the browser runner to Node 20 and pinned Composer for
stable artifacts. The follow-up push and PR runs passed with the same ZIP hash
as the local pinned-toolchain artifact.

## Residual Ownership

- Four non-blocking Plugin Check warnings remain recorded as accepted residual
  release noise.
- Development-tool npm advisories remain outside the shipped PHP runtime ZIP.
- WordPress.org publication requires a non-prerelease GitHub release and valid
  `WPORG_USERNAME` / `WPORG_PASSWORD` secrets.
- Full fake Telegram submit delivery coverage belongs to E6.
- Test-suite taxonomy cleanup belongs to E7.
