# MediaFiles compatibility repair

The checks in `tests/stability/mediafiles-*.php` are local-only. They require
the sibling `../cf7-telegram-mediafiles` checkout; the legacy contract check
also requires the local 0.9.2 plugin at
`wordpress/wp-content/plugins/cf7-telegram/classes/wpcf7telegram.php`.
The WordPress smoke requires an active local add-on and form 4; run it with
`mediafiles-wp-prelude.php` so HTTP and mail stay intercepted. These local
prerequisites are not part of the main plugin release package or CI gates.

## Authority and boundary

2026-10-30: user authorized planning and execution of the media addon repair,
explicitly preserving compatibility with the old main plugin. Deliver verified
local diffs only: no commit, push, release, deployment, or real Telegram messages.
The previously authorized temporary `true ||` license bypass remains local and
uncommitted. Designing its eventual local overlay is deferred.

Initial boundary: main `e25d811aa7bff4163eef652b19c8b5f2b775464e`, addon
`6259c025ab2aba87f409aeef92d3216edf42477b`. Preserve the user's main
`docker-compose.yml` mounts and the addon's one-line temporary bypass.

## Outcome and scope

Main form (ID 4, file field `file-836`) can deliver uploaded images/files with
main plugin 1.2.1, and the existing pre-1.0 integration remains working. The local
0.9.2 source under `wordpress/wp-content/plugins/cf7-telegram` is the concrete
legacy baseline. Preserve its `wpcf7tg_messages_sent` / `api_request` contract.

Prefer changes confined to the addon, reusing current public hooks and WordPress
HTTP transport. Retain photo/audio/video/document routing, uploaded file handling,
and file-ID reuse. Modern delivery must preserve bot/chat/topic identity and avoid
duplicate attachments when the core sends multiple chunks or compatibility hooks.
No unrelated UI, database/migration, dependency, or license-system redesign.

Risks: the modern compatibility hook loses bot/topic identity; current gateways
only send text; Telegram file IDs belong to one bot; upload errors must not break
CF7 submissions or stop other recipients. Test transport must intercept all HTTP
and email; local real bot tokens and personal data must never appear in evidence.

## T1. Restore old and modern media delivery

Status: completed
Owner: `/root/mediafiles_batch1`; implementation delegated to its worker.
Goal: restore media delivery without dropping the legacy path.
Scope: addon `classes/mediafiles.php`, focused regression checks, and a minimal
repeatable local WordPress smoke check; main code only if existing hooks prove
insufficient, with the reason recorded before expanding changes.
Out of Scope: real Telegram sends, licensing redesign, commits/publication, UI.
DoR: execution authorized; root-cause and active runtime confirmed; legacy source
and modern source are available; local Docker/WP-CLI access already verified.
Dependencies: addon mounted at `/srv/cf7-telegram-mediafiles`; current WordPress
at `/srv/web/wordpress` in `docker compose -p cf7t exec -T php`; no new services.
AC:
- A current Main form submission with a test PNG yields `sendPhoto` with the
  correct file bytes, bot and chat; a submission without files adds no media calls.
- Modern group topics carry `message_thread_id`; two bots never share cached
  file IDs; repeat sends for the same bot reuse its file ID.
- Attachments are not duplicated by text chunking, legacy compatibility hooks,
  or repeated delivery callbacks for the same submission/destination.
- The real legacy 0.9.2 API and legacy hook still upload and reuse files; loading
  the addon does not require modern classes in a legacy-only process.
- Existing photo/audio/video/document dispatch remains supported; failures,
  missing files and malformed responses cannot cause an uncaught error or stop
  delivery to independent recipients. No tokens are emitted in diagnostics.
- The temporary license bypass remains unchanged and no commits are created.
DoD: worker's actual diff reviewed by owner; PHP lint and focused regression
checks pass; serial WordPress smoke with fake transport proves Main form flow;
legacy smoke uses the actual local 0.9.2 source; required broader main PHP tests
pass; root accepts frozen local diff and fresh independent QA passes.
Verification: worker supplies exact focused test and WordPress smoke commands
plus assertions; monitor runs the frozen ladder, including
`php tests/run.php` with working directory `plugin-dir`. No dependency installation or
provisioning is authorized without an actual need.
Notes/Risks: pre-1.0 compatibility is concretely verified against available 0.9.2,
not claimed as an exhaustive matrix of every historical release. The license
bypass is an explicit local exception, not a production security change.

## Execution and checkpoints

Sequence: one coherent implementation/test batch -> frozen serial verification
-> root acceptance -> fresh independent epic QA. If QA fails, one bounded repair
batch followed by fresh QA; repeated failure of the same condition returns to user.

Agent limits: one owner and one implementation writer at a time; specialists do
not delegate; at most two delegation edges. Root handles completed-branch archive
using known native IDs only. Long/shared-container checks belong to test_monitor.

Owner selection: `gpt-6.1-sol`, reasoning `high`; bounded cross-version PHP repair
with transport/failure-path risk benefits from careful review without a frontier
owner. Named worker/test_monitor/epic_qa roles retain their pinned model/effort.

Prior investigator `/root/inspect_media_contract`, native ID
`01a18c1d-fda6-7af2-bbe0-6a171906f914`: accepted read-only findings, stopped, no
descendants/processes. Archived through serving daemon on 2026-10-30, exit 0.

Implementation checkpoint (2026-10-30): T1 technically accepted at frozen local
diff; full-batch root acceptance and independent QA pending. Writer stopped. Owner
`/root/mediafiles_batch1`, native `01a18c2a-0718-7541-9964-c565de4b7e95`,
selected `gpt-6.1-sol/high`; worker `/root/mediafiles_batch1/mediafiles_impl`,
native `01a18c2a-d17a-7d01-9aaa-b34c112b1bc7`, named role `gpt-6-sol/high`,
completed with no descendants/running commands. Root archived worker through
native serving daemon, exit 0; completed state verified first.
Owner reviewed actual addon diff and all assertions; multipart quote defect and
smoke CF7 API/UA prerequisites repaired within T1 before external verification.
Changed: addon `classes/mediafiles.php` (112+/88-); main three new
`tests/stability/mediafiles-{contract,wp-prelude,wp-smoke}.php`. No main product
change, dependency, addon test directory or release packaging change required.
Baseline revisions unchanged; compose mounts and exact `true ||` line preserved.
Frozen SHA256: addon `9ff223a0810f8d39b1d7d51aba9419427e1a9991e5f7f69a177855d29a52077f`;
contract `9169e011a33480afedbcf4795311211b35c12ff38f437192ca252512f806208a`;
prelude `08f424a3414289b47aab8b35648f12e8d040860bf1cf73cd9ed7137def662741`;
smoke `582456e26ac6788904f6271f8b004d0070a3c5292d0c53ee977c65106b0bd94c`.
Worker focused evidence and monitor runtime logs inspected by owner. Media remains best effort after failed text results.
Concrete version proof targets current 1.2.1 and legacy 0.9.2. CLI smoke invokes
real CF7 submit/validation/mail but injects upload into private submission field
because CLI cannot execute `move_uploaded_file`; HTTP/email blocked pre-bootstrap,
PNG fixture/globals/media transients restored in finally. No commits/real sends.
Verification checkpoint (2026-10-30): `/root/mediafiles_batch1/mediafiles_verify`,
native `01a18c39-a699-7782-982e-e0c495b134ce`, named role `gpt-6-luna/low`,
completed/stopped with no ladder processes. Logs `/tmp/cf7tg-mediafiles-T1-monitor01/`.
- Four `php -l` commands for addon file and three scripts: exit 0, clean
  (`01-lint.log`). Owner checked both repository diffs with `git diff --check`.
- `php tests/stability/mediafiles-contract.php modern`: exit 0, pass (`02-modern.log`).
- `php tests/stability/mediafiles-contract.php legacy`: exit 0, pass using actual
  local 0.9.2 class (`03-legacy.log`).
- `php tests/run.php`, cwd `/home/itron/reps/cf7-telegram/plugin-dir`: exit 0,
  110 tests / 0 failures (`04-main-suite.log`). Host PHP 8.0.30 lacks PHPUnit
  dom/mbstring/xmlwriter extensions; repository compatibility harness executed
  assertions, no required test skipped.
- `docker compose -p cf7t exec -T php wp --path=/srv/web/wordpress
  --require=/srv/web/tests/stability/mediafiles-wp-prelude.php eval-file
  /srv/web/tests/stability/mediafiles-wp-smoke.php`: initial sandbox attempt
  blocked at Docker socket, exit 1 (`05-wp-smoke.log`); supported escalation
  retry exit 0 (`05-wp-smoke-escalated.log`): form 4 passes with one first text
  chunk / one photo, no-files submission sends text only, intercepted CF7 mail,
  asserted transient restoration. No external HTTP/email sent.
AC mapping: current PNG/bot/chat/no-files -> WP smoke; topics/two bots/reuse,
chunk/callback/compat dedup, all four methods and isolated malformed/transport/API/
throw/missing-file failures -> modern runner; real old API upload/reuse/token switch
and no modern Bot class -> legacy runner. Exact temporary bypass remains unchanged;
all four frozen hashes and both HEADs unchanged after verification. No commits.
Decision: accept scoped implementation technically; no main product change needed.
Remaining limits: CLI upload injection, concrete versions 1.2.1/0.9.2, authorized
local bypass. Root next accepts batch and launches fresh independent epic QA;
no successor batch taken by this owner.
Root acceptance (2026-10-30): actual addon implementation, three verification
scripts, decisive logs, status, diff statistics and all four frozen hashes
inspected. T1 implementation and verification accepted at the recorded local
boundary; independent QA remains the only gate. No commit/publication authorized.
Lifecycle: root verified completed owner/monitor states and no live commands;
archived monitor `01a18c39-a699-7782-982e-e0c495b134ce` then owner
`01a18c2a-0718-7541-9964-c565de4b7e95` through serving daemon, both exit 0.
Independent QA verdict (2026-10-30): `pass_with_notes`.
Reviewer `/root/mediafiles_qa1`, native `01a18c3c-2c97-7fa3-bc85-6b18b9b553fe`,
named `epic_qa` role (`gpt-6-sol/high`), did not implement or technically own T1.
Reviewed boundary: main `e25d811aa7bff4163eef652b19c8b5f2b775464e`, addon
`6259c025ab2aba87f409aeef92d3216edf42477b`, and the four unchanged SHA256 values
recorded above. Source/assertion/log review confirmed every required AC/DoD;
no blocking findings, token leakage or decision required.

QA note (low severity, retained): the CLI smoke injects the upload into CF7,
so browser upload / `move_uploaded_file` and actual Telegram receipt are not
verified. These are outside the agreed local fake-transport gate. Concrete
version coverage is 1.2.1 and 0.9.2; main suite used the repository compatibility
harness due to unavailable host PHPUnit extensions. No exception to a failed
required criterion was requested or granted.

Root accepted the independent verdict unchanged. Reviewer completed/stopped with
no running commands or descendants; root verified state and archived its native
thread through the serving daemon, exit 0. T1 delivered as verified local diffs;
no commits, push, release or real messages. Future local license overlay remains
deferred as requested; the existing temporary bypass is still present.

## Follow-up: addon commits and version 1.2

2026-10-30: user authorized committing all addon changes except the local key
bypass, then requested version 1.2. Created local addon commits:
- `fe0ab90325b9e2382189ccd7933fdde695c373e5`: media compatibility repair.
- `0437481af4da9a2131a65eff8e9d3b1fc9260dd2`: header/constant version 1.2.

Committed media source SHA256:
`14b70a5ff1ba6465f4dcf5928e8870c73ec68733461f6a43b7eb6985c91b9e09`.
The committed `is_allowed()` matches the original addon baseline byte-for-byte;
the working source still has its reviewed SHA256 and only the one-line bypass
differs from HEAD. Index empty; no other addon edits/untracked files remain.
Clean staged media source and updated entrypoint passed PHP lint; commit contents,
both version markers and exact remaining diff were asserted after committing.
No functional repair changed since the accepted gate; main-repo test/plan files
remain local. No push or publication performed.
