# Contact Form 7 menu compatibility

## T1. Restore the Telegram submenu across the CF7 menu change

Status: completed

Goal: keep CF7 Telegram reachable from the Contact Form 7 admin menu after
upgrading CF7, while retaining compatibility with older supported versions.

Scope: identify the first released CF7 version with the new parent slug;
adjust submenu registration; add regression coverage and record verification.

Out of Scope: permissions changes, migrations, Telegram delivery, UI redesign,
dependency upgrades, release publication and unrelated backlog tasks.

DoR: implementation authorized by the user; current working tree was clean.

DoD: upstream version boundary is supported by official source references;
the focused regression and existing PHP suite pass; scoped PHP files lint;
the final local diff is reviewed. Delivery is local implementation and
verification; no push or publication.

AC:
- CF7 Telegram attaches to the correct parent with both old and new CF7 menus.
- Registration runs after the parent exists and creates one submenu entry.
- The `wpcf7_tg` page slug, callback and existing access capability are preserved.
- A regression check fails with the original hard-coded parent on the new menu.

Dependencies: official CF7 release source for the version boundary.

Notes/Risks: the local CF7 installation is 6.0.6; its menu is `wpcf7`.
Local PHP 8.0 lacks dom, mbstring and xmlwriter, so the repository test runner
uses its compatibility harness unless a suitable PHPUnit runtime is available.

## Confirmed upstream boundary and approach

- [CF7 v6.1.7](https://github.com/rocklobster-in/contact-form-7/blob/v6.1.7/admin/admin.php)
  registers the top-level menu as `wpcf7`.
- [CF7 v6.2.0](https://github.com/rocklobster-in/contact-form-7/blob/v6.2.0/admin/admin.php)
  changes the parent to `wpcf7-dashboard`; `wpcf7` remains the form-list child.
  This is the first stable release with the changed parent.
- WordPress.org publishes that GitHub-tagged `v6.2.0` release as plugin version
  `6.2` (`contact-form-7.6.2.zip`); the `6.2.0.zip` URL does not exist.
- Both versions register their menu on `admin_menu` at priority 9. Our
  priority-10 callback can inspect WordPress's `$admin_page_hooks` and use
  `wpcf7-dashboard` when registered, falling back to `wpcf7` otherwise.
- This avoids depending on CF7 version strings and preserves the settings URL,
  page callback, capability and normal WordPress permission checks.

## Execution checkpoint

- Baseline: `10b285c` (`master`), clean working tree before this plan.
- Sequence: upstream history → one bounded implementation → focused/full PHP
  verification and root diff review. This is one bug fix, not a multi-batch epic.
- Read-only history agent: `/root/cf7_menu_history`, explorer role
  (`gpt-6-luna`, medium), thread `01a1369c-00d5-7280-a68f-f83dc0ecd271`;
  accepted pinned-tag evidence, no changes or running commands, stopped.
- Implementation: `/root/cf7_menu_fix`, worker role (`gpt-6-sol`, high), owns
  `Settings.php`, `SettingsTest.php` and minimal test-bootstrap support;
  thread `01a1369d-f9f8-7c02-81ed-d0e91df31545`, accepted and stopped with no
  remaining commands, writes or descendants.
- Root reviewed all three changed PHP files and accepted the local diff:
  34 insertions, 1 deletion against `10b285c`. Binary diff SHA-256:
  `f7ce25712f2bbe90c161801f0b78993902fe75e07f0f623ab440aa0bf4a6c1da`.
- Focused regression: PHP stdin loaded `tests/bootstrap.php` and
  `tests/SettingsTest.php`, invoked protected `setUp` using `ReflectionMethod`,
  then ran `testLegacyCf7ParentReceivesTelegramSubmenuAfterRegistration` and
  `testNewCf7ParentReceivesTelegramSubmenuAfterRegistration`. Before the fix,
  legacy passed and new-parent failed (exit 1: expected `wpcf7-dashboard`,
  actual `wpcf7`); after the fix both passed (exit 0).
- `php tests/run.php` in `plugin-dir`: exit 0, 97 tests, 0 failures, using
  the repository compatibility harness because PHP extensions are missing.
- `php -l plugin-dir/lib/Settings.php`,
  `php -l plugin-dir/tests/SettingsTest.php`, and
  `php -l plugin-dir/tests/bootstrap.php`: all exit 0, no syntax errors.
- `git diff --check`: exit 0. No unexpected changed paths.
- All scoped AC/DoD are satisfied. No live WordPress/browser integration run;
  verification covers menu registration and hook timing in the PHP harness,
  with the parent contracts checked against pinned official CF7 sources.
- Delivery remains an uncommitted local patch plus this plan/evidence file;
  nothing pushed or published. No next batch or blocker.
