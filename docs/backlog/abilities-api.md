# Adapting the plugin to the WordPress Abilities API

Overall scope: six abilities; open questions for the remaining abilities retain
`needs_design` status. The user separated the first independent stage:
`get-configuration` and `diagnose-form`. Implementation of this stage was authorized
on 2026-10-29 and takes place on `release/abilities-readonly`.
Date: 2026-10-29. Baseline: Message Bridge for Contact Form 7 and Telegram 1.2.1,
commit `e25d811`. Coordination: root; no release number has been assigned yet.

## Goal and scope

Provide administrators, other plugins, and connected AI clients with structured
operations to inspect CF7 → Telegram routes, diagnose configuration, and test
sending through the existing bridge. Preserve current form delivery, UI, CF7
permissions, and support for older WordPress versions.

The target set contains six abilities. Only the first two belong to the first
independent release; their plan does not depend on decisions about the other four.

| Name | Purpose | External effects |
| --- | --- | --- |
| `cf7-telegram/get-configuration` | List forms, bridges, bots, or recipients and their relationships | None |
| `cf7-telegram/diagnose-form` | Inspect the saved route of a particular form | None |
| `cf7-telegram/send-test-message` | Send a test message to active recipients of a selected bridge | Actual Telegram send |
| `cf7-telegram/preview-message` | Preview a template with synthetic values | None |
| `cf7-telegram/configure-bridge` | Create or update a bridge using existing objects | Configuration write |
| `cf7-telegram/get-recent-errors` | Fetch a bounded set of sanitized log errors | None |

These names are a new public contract. The API uses `bridge`, although the
existing PHP model is named `Channel`; the model and CPT need no renaming.

Outside the overall target set: creating/approving recipients, changing tokens,
polling, webhook settings, repairing relationships, migrations, reading submission
content, redelivering submissions, a queue, read receipts, new UI, a custom MCP
server, and an AI provider. Configuration changes are limited to a bridge's
membership and name; forms and bots are not edited. A full form-processing test
is a separate future scenario described below.

## Existing foundation

| Behavior | Existing code | Implementation consequence |
| --- | --- | --- |
| Forms, bridges, bots, and recipients are connected by relationships | [Client](../../plugin-dir/lib/Client.php), [Channel](../../plugin-dir/lib/Channel.php) | Use these relationships; do not create parallel configuration |
| CPT permissions derive from CF7 | [CPT](../../plugin-dir/lib/Controllers/CPT.php), [Settings](../../plugin-dir/lib/Settings.php) | Check capabilities, not role names |
| Messages go to active recipients | `Channel::doSendOut()`, [Bot](../../plugin-dir/lib/Bot.php) | Reuse transport, filters, and error handling |
| Topic is part of the destination; General is stored as default | [Chat](../../plugin-dir/lib/Chat.php), [topic contract](telegram-topics.md) | Distinguish recipients in one chat; do not redirect a failed topic to General |
| CF7 sends through `wpcf7_before_send_mail` | [CF7](../../plugin-dir/lib/Controllers/CF7.php) | A bridge test does not prove form templates, validation, or handlers work |
| Telegram result contains the API response | [Gateway](../../plugin-dir/lib/Telegram/WordPressTelegramGateway.php), [DeliveryResult](../../plugin-dir/lib/Telegram/TelegramDeliveryResult.php) | Extract confirmation from `result`; do not return the whole Telegram payload |
| Secret and error redaction already exist | [LogRedactor](../../plugin-dir/lib/LogRedactor.php), [TelegramRedactor](../../plugin-dir/lib/Telegram/TelegramRedactor.php) | Reuse redaction in addition to an explicit field allowlist |

`Bot::ping()` writes bot status/data, while `fetchUpdates()` changes the offset and
recipients. They are unsuitable for abilities marked `readonly: true`.
Also, `new Bot()` changes status and logs through `initAPI()` when the token is
missing, and `Entity` creates a Logger whose initialization may create a table.
Read callbacks must read data without those side effects: use existing WP and
relationship read APIs, and extract a small shared pure helper for identifying
the token source if necessary. Do not duplicate constant/meta precedence rules
in a separate implementation or change ordinary sending behavior.
CF7 can report `mail_sent` despite a Telegram error; this is established by
[E6](../../tests/e2e/e6-form-delivery.spec.js).

## Registration and compatibility

1. Load one small registration module, for example `lib/Abilities.php`.
   Register category `cf7-telegram` on `wp_abilities_api_categories_init` and
   abilities on `wp_abilities_api_init`. Registration describes contracts and
   callbacks; it does not load configuration or contact Telegram.
2. Check for `wp_register_ability()` and `wp_register_ability_category()`.
   Without the API, the adaptation does not load. Do not add a polyfill or a
   mandatory Abilities API/MCP plugin. The baseline remains WP 5.6 / PHP 8.0;
   the built-in Abilities API is available from WP 6.9.
3. Register callbacks early enough for lazy registry initialization.
   Execution requires CF7/CPT/relationship initialization to have completed.
   An early call returns `cf7tg_not_ready`, without an undefined capability,
   fatal error, or attempt to rerun migrations. Missing CF7 must not introduce
   new loading errors from this adaptation.
4. Give every ability translatable label/description in the `cf7-telegram` domain,
   input/output JSON Schema, `permission_callback`, and `execute_callback`.
   Use the WordPress-supported JSON Schema subset; verify contracts through real
   `WP_Ability::execute()`, including on WP 6.9.
5. Set `meta.show_in_rest = true`, `meta.mcp.public = true`, and
   `meta.public = true`. Explicit channel flags allow operation independently of
   newer `meta.public` behavior. Exposure is for authenticated clients, not
   anonymous permission. MCP Adapter remains a separate optional integration.
   Administrators can limit exposure using standard WordPress/adapter facilities;
   no separate settings page is added for it.

| Ability | `readonly` | `destructive` | `idempotent` | REST method |
| --- | --- | --- | --- | --- |
| `get-configuration` | true | false | true | GET |
| `diagnose-form` | true | false | true | GET |
| `send-test-message` | false | false | false | POST |
| `preview-message` | true | false | true | GET |
| `configure-bridge` | false | true | false | POST |
| `get-recent-errors` | true | false | true | GET |

Annotations describe behavior but do not replace permission checks. A repeated
test creates a new message. The ability description must tell the client so.
`configure-bridge` may remove existing relationships, and creating a bridge is
not idempotent. In Core, destructive=true with idempotent=false uses POST;
DELETE is required for destructive+idempotent. Its description warns that the
complete destination list replaces the current one.

References: [Abilities API](https://developer.wordpress.org/apis/abilities-api/),
[registration](https://developer.wordpress.org/reference/functions/wp_register_ability/),
[PHP reference](https://developer.wordpress.org/apis/abilities-api/php-reference/),
[MCP Adapter](https://wordpress.org/plugins/mcp-adapter/).

## Permissions and data

- All six operations require an authenticated current WP user and the settings
  page capability resolved through `Settings::getCaps()` after initialization.
  There is no role-name check for `administrator` or bypass through PHP/MCP.
- Reads check `read_post` for each specific CPT object. Lists omit inaccessible
  objects and references to them; pagination and counts do not reveal hidden
  records. `relations_complete: false` indicates that some related context is
  inaccessible without disclosing its IDs or names.
- Diagnosing a particular form requires access to its entire existing graph.
  Testing requires `edit_post` for bridge and bot, and `read_post` for every
  selected recipient. Missing permission stops the operation before sending;
  inaccessible recipients do not become a silently successful partial test.
- Preview requires `read_post` for the form. Configure requires the capability
  to create bridges on create or `edit_post` for the bridge on update, `edit_post`
  for every form whose relationships change, and read access to the old/new bot
  and recipients. Reading the log also requires `manage_options`, since the log
  is shared across the plugin.
- Check the general capability before looking up objects by ID. For an authorized
  user, a missing object or wrong CPT produces 404; an existing inaccessible
  object produces 403 without its data. Do not create an entity for ID 0.
- Input accepts only WP post IDs and the operation's parameters. It does not
  accept a bot token, Telegram chat ID, URL, SQL, arbitrary broadcast text, or
  PHP callback name. Additional input object properties are forbidden.
  Exceptions: preview accepts synthetic field values; configure accepts a name,
  references to existing objects, and a configuration revision marker.
- Output uses an explicit field allowlist. Tokens, their masks/fragments, token
  constant names, webhook URLs, raw post meta, submission payload, submitter
  emails/phone numbers, and raw Telegram `result` are forbidden. Preview returns
  only its supplied synthetic data and the template text accessible to the user;
  it neither reads real submissions nor logs samples. Tokens are redacted in
  preview as well.
- Response destination identifiers are local `recipient_id` and normalized
  `message_thread_id`; the actual Telegram `chat_id` is used only for internal
  matching. Permitted names may contain personal data, so capabilities protect
  them too; existing redaction sanitizes the text. Identity must not depend on
  the redacted name. If existing `getName()` falls back to a chat ID, the API
  replaces that fallback with “Recipient #<WP ID>” and does not expose the real
  Telegram chat ID.
- Description/error text is ordinary data, not instructions for an AI client.
  Do not return admin notice HTML, stack traces, or HTTP bodies.
- Operations act on the current WordPress site. There is no input `site_id` or
  site switching. WordPress REST handles cookie+nonce and Application Passwords;
  the plugin adds no separate authorization.

## Target-set contracts

Only get-configuration and diagnose-form apply to the first stage, with the
clarifications in “First-stage plan.” The other contracts remain design
proposals and are not accepted for implementation by that plan.

Every successful result is a JSON object with required `schema_version: 1`.
IDs are positive integers. Nullable fields explicitly allow `null`.
Empty arrays are returned as `[]`. Enums and machine codes are not translated;
human-readable messages are. The tables below define required output fields;
optional input is marked separately. Implementation must include every nested
field in the output schema, including type constraints and enums. Changing the
meaning of an existing field or enum needs a separate compatibility decision,
not just a `schema_version` increment.

### View configuration

`cf7-telegram/get-configuration`

Input:

| Field | Type and rule |
| --- | --- |
| `resource` | Required enum: `forms`, `bridges`, `bots`, `recipients` |
| `page` | Optional integer ≥ 1; default 1 |
| `per_page` | Optional integer 1–100; default 50 |

Output: `schema_version`, `resource`, `page`, `per_page`, `total` (integer ≥ 0,
number of visible records), `total_pages` (integer ≥ 0), `items` (array).
Sort by WP ID ASC. A page beyond the last returns empty `items`.
Normalize default parameters identically for PHP and REST; do not rely on
all Core versions automatically filling nested defaults.

Each item contains `id`, `label` (sanitized string), `relations_complete`
(boolean), and fields for its type:

| Resource | Item fields |
| --- | --- |
| `forms` | `bridge_ids: integer[]` |
| `bridges` | `bot_id: integer\|null`, `form_ids: integer[]`, `recipient_ids: integer[]`, `revision: string\|null` |
| `bots` | `token_configured: boolean`, `cached_status: online\|offline\|unknown` |
| `recipients` | `destination_kind: default\|forum_topic\|invalid`, `message_thread_id: integer\|null`, `bridge_ids: integer[]`, `bot_connections: [{bot_id, status}]` |

Bot relationship status: `active|pending|muted|unknown`. A missing relationship
is omitted from `bot_connections`; empty/unrecognized metadata on an existing
relationship becomes `unknown`. `token_configured` means only that a token
source exists, not that the token is valid. `cached_status` is not a fresh
availability check.

Lists show published objects of the respective CPTs, including forms without
bridges, unassigned bots/recipients, and bridges without forms. Relationships to
deleted objects are not presented as working: `relations_complete: false`;
diagnosis examines the details. Visible relation ID arrays are returned in full,
without silent truncation. The `items` schema describes the permitted record
shapes for all four resources; do not use a free-form object with arbitrary meta.
`revision` is an opaque fingerprint of the name, bot/form/recipient IDs, and
bridge relationship metadata with deterministic sorting. It is not
`post_modified`: relationship changes may leave the post unchanged. If access
to the graph is incomplete, revision is null. This field belongs to future
configure-bridge: it is absent from the first-stage result and output schema.
Adding it will be a separate contract extension.

An invalid topic has `destination_kind: invalid`; its `message_thread_id` may be
`null`, but that does not mean General. A valid default recipient has a `null`
topic ID; a forum topic has a normalized positive value.

### Diagnose a form

`cf7-telegram/diagnose-form`

Input: required `form_id`. Output:

| Field | Type and meaning |
| --- | --- |
| `schema_version` | 1 |
| `form_id` | ID of the inspected form |
| `configuration_status` | `ready\|partial\|blocked` |
| `delivery_verified` | Always false |
| `bridges` | `[{bridge_id, eligible_recipient_ids: integer[]}]` |
| `issues` | `[{code, severity, bridge_id, recipient_id, message, suggested_action}]` |

For an issue, `severity` is `warning|error`; bridge/recipient IDs are nullable
when not applicable; `message` and `suggested_action` are strings. Suggestions
do not repair anything automatically. Minimum code set:

| Code | Condition |
| --- | --- |
| `no_bridge` | The form is not connected to any bridge |
| `broken_relation` | Deleted object, wrong CPT, or violated relationship cardinality |
| `no_bot` | The bridge has no bot |
| `token_missing` | No usable bot token |
| `no_recipients` | The bridge has no selected recipients |
| `recipient_not_active` | Its bot relationship is pending/muted/unknown or absent |
| `invalid_destination` | Damaged or unsupported chat/topic |
| `cached_bot_offline` | Saved offline status; warning only, without blocking |

`ready`: every route is structurally valid and every selected recipient is
eligible for sending. `partial`: at least one eligible send path exists, but
some destinations are structurally broken/inactive. `blocked`: no eligible paths.
A cached offline warning alone does not change `ready`. Missing log entries
do not prove that the route works.

Diagnosis checks only the selected form's graph. Do not run global repair through
`Maintenance::runRepair()`, Telegram `getMe`/`getUpdates`, CF7 submit, mail,
status writes, or name updates. A broken relationship must become an issue,
not a fatal error while building the whole collection. A webhook alone is not
a reason for outgoing delivery to fail.

### Send a bridge test message

`cf7-telegram/send-test-message`

Input: required `bridge_id`. No arbitrary text or list of new addressees is
accepted. Send a short localized plain-text message such as “CF7 Telegram test.
Bridge #42. Test 7f…”. The server generates `test_id` to distinguish runs; it
is neither a deduplication key nor a persistent history record. Do not include
form field values, the token, or a private site URL.

Before the first HTTP request, check permissions for the entire selected graph
and the presence of a bot/token. Use existing `Channel::doSendOut()` and the
standard gateway. Preserve send filters, splitting, plain-text fallback, order,
and continuation after a recipient fails. Do not approve subscriptions or
change bridge membership or bot/chat state. Errors may enter the existing log.

A recipient is eligible only if it is connected to the selected bridge and its
bot relationship has `active` status. Pending/muted/disconnected recipients are
skipped with an explicit reason. An invalid destination is not sent to
default/General. A structural failure that prevents safe enumeration of
recipients stops the test before the first send with
`cf7tg_invalid_configuration`.

Output:

| Field | Type and meaning |
| --- | --- |
| `schema_version` | 1 |
| `test_id` | Nonempty string |
| `bridge_id` | ID of the tested bridge |
| `status` | `sent\|partial\|failed\|unknown\|not_attempted` |
| `reason` | `no_recipients\|no_eligible_recipients\|execution_interrupted\|null` |
| `recipients` | Result array for every selected recipient, including skipped ones |

Recipient result: `recipient_id`, `message_thread_id: integer|null`,
`status: sent|partial|failed|unknown|skipped`, `reason: string|null`, `chunks: array`.
Skip reasons: `recipient_not_active`, `bot_relation_missing`,
`invalid_destination`, `execution_interrupted` (destinations demonstrably not
started after interruption). An attempt already started with an unknown outcome
is `unknown`, not `skipped`. Top-level `reason` is null after normal completion
with attempts.

Chunk result: `index` (integer ≥ 1), `status: sent|failed|unknown`,
`message_id: integer|null`, `error_code: string|null`, `retry_after: integer|null`
(seconds ≥ 0). The initial text is short; the chunk array remains correct if an
existing filter expands the message. Recipient-level `sent` requires every
chunk to succeed. Sent plus failed/unknown chunks means `partial`; with no sent
chunks and any unknown, `unknown`; otherwise `failed`. A skipped recipient has
`chunks: []`. Chunk error code: `telegram_error`, `rate_limited`,
`transport_unknown`, `invalid_response`, `destination_mismatch`, or null on
success; raw exception messages are not exposed.

Verification semantics:

1. `sent` requires `ok: true`, a positive `result.message_id`, and matching
   `result.chat.id` and normalized `result.message_thread_id` for every
   destination, including default. An unexpected non-General topic does not
   match default. Match against the saved destination; compare chat IDs as
   strings. General is normalized under the current topic contract: absent
   thread and a supported General notation are not different destinations.
2. Match the response to the destination even after public filters. If a
   filter redirects the message, the test does not claim the original route
   works: `failed`, code `destination_mismatch`; the message may already have
   gone to another chat.
3. An explicit negative Telegram error is `failed`. A `429` returns
   `retry_after` without sleeping/retrying. Timeout, interrupted response,
   malformed success without a message ID, or any other response that cannot
   establish the outcome becomes `unknown`. HTTP 5xx without a reliable result
   does not prove that delivery failed.
4. Do not retry automatically after an unknown outcome. Preserve the existing
   fallback after an explicit formatting refusal. Do not read history through
   `getUpdates`, automatically delete the test, or check whether it was read.
5. Whole-test `status`: no attempts → `not_attempted`; every selected recipient
   has `sent` → `sent`; at least one confirmed chunk exists without complete
   success → `partial`; otherwise, if any outcome is unknown → `unknown`;
   otherwise → `failed`. Skipping is not success.

Telegram confirms sending with a `Message` response; this does not confirm
delivery to a device or that a person read it. Reference:
[sendMessage and Message](https://core.telegram.org/bots/api#sendmessage).

The synchronous test uses the existing HTTP timeout and sends sequentially.
A large bridge may exceed the client's timeout. The client must then treat the
outcome as unknown and avoid automatic retries: the first version has no
persistent results registry or exactly-once guarantee. Do not change ordinary
submission transport for this new diagnostic operation.

Example result with partial success:

```json
{
  "schema_version": 1,
  "test_id": "example-test-1",
  "bridge_id": 42,
  "status": "partial",
  "reason": null,
  "recipients": [
    {
      "recipient_id": 81,
      "message_thread_id": 130,
      "status": "sent",
      "reason": null,
      "chunks": [{"index": 1, "status": "sent", "message_id": 501, "error_code": null, "retry_after": null}]
    },
    {
      "recipient_id": 82,
      "message_thread_id": null,
      "status": "skipped",
      "reason": "recipient_not_active",
      "chunks": []
    }
  ]
}
```

### Preview a message

`cf7-telegram/preview-message`

Input: `form_id` and `sample_values`, an object keyed by form field names.
A value is a string or an array of strings for multiple-value fields. The
object is required but may be empty. Unknown field names are rejected; missing
values are not replaced with current user data or a previous submission.
Limits: 100 fields, 100 array items, 4096 characters per string, and 64 KiB
total JSON input. The callback checks total size after permission checks.

Output: `schema_version`, `form_id`, `fidelity: base_template`, `parse_mode:
HTML|Markdown`, `text: string`, `chunks: string[]`, `unresolved_tags: string[]`,
`warnings: [{code, message}]`. `unresolved_tags` may be empty; `warnings`
always includes the runtime filter limitation described below.
`fidelity: base_template` means a preview of the base Telegram template,
not a promise to match the result of third-party submission filters.

Use `mail.body` and `mail.use_html` of the current form as in real Telegram
sending; mail_2, attachments, and email testing are excluded. Reuse the CF7
parser for mail tags and extract shared pure HTML/Markdown processing and
Unicode splitting with the production path. Do not copy
`CF7::handleSubscribe()` or construct a submission that calls `proceed()`.

At minimum, support ordinary mail tags for text, email, select, checkbox,
radio, and textarea fields; samples represent already normalized posted values.
Join lists under the current CF7 rules. For a missing value, special tag,
file tag, unsupported option/format, retain a visible placeholder and add it to
`unresolved_tags` with warning `unresolved_tag`. Escaped `[[tag]]` remains
literal and is not unresolved. An unsupported tag must not turn into an
invented IP/date/user value.

Preview does not execute `wpcf7_before_send_mail`, `wpcf7_mail_sent`, the
Telegram gateway, `wp_mail`, Flamingo/CRM writes, or arbitrary submission/send
filters. Output always contains warning `runtime_filters_not_applied`: external
filters may alter the real message. Both paths use pure formatting; the order
and arguments of existing production hooks stay the same. If splitting depends
on a runtime filter, preview shows base splitting. Pass synthetic values in an
explicit context, without replacing global `$_POST` or a CF7 singleton.
Translations and formatting use the form locale.

GET puts samples in the query string, so documentation requires fictional data
only. A server's URL limit may be smaller than 64 KiB; PHP `execute()` supports
larger previews, and clients should reduce samples after a transport error.
No new POST route is created for preview.

### Configure a bridge

`cf7-telegram/configure-bridge`

Input specifies the complete desired state of one bridge:

| Field | Type and rule |
| --- | --- |
| `bridge_id` | Optional ID; absence means create, presence means update |
| `expected_revision` | Required nonempty string on update; forbidden on create |
| `name` | Required plain-text string, 1–200 characters after trim |
| `bot_id` | Required ID of an existing bot |
| `form_ids` | Required array of unique IDs of existing forms |
| `recipient_ids` | Required array of unique IDs of existing recipients |

Empty arrays are allowed when preparing a bridge, but it is not considered
ready for delivery. An omitted array does not mean “keep the old values”; it
is an input error. The name is not a unique key and is not used for upsert.
Repeating create makes another bridge; clients must not blindly retry after
a timeout.

Each selected recipient must already have `active` status with `bot_id` and
a valid destination. Approving a topic and assigning it to a bridge remain
separate operations. A new bot does not inherit the old bot's subscriptions.
Saving is allowed when `token_configured: false`, but the result explicitly
includes `token_missing`; saving does not call `ping` or Telegram. Determine
the token without side effects, as in configuration reads.

Output: `schema_version`, `action: created|updated|unchanged`, `bridge` — a full
bridge record from get-configuration with non-null `revision`,
`configuration_status: ready|partial|blocked`, and `issues` in the same format
as diagnose-form, applied to this bridge. Add `no_forms` when no forms are
attached; that blocks CF7 use of the bridge although a Telegram test is
technically possible.

Before writing, validate all IDs, object types, capabilities, active
relationships, and `expected_revision`. Persist only changed relationships;
do not delete/recreate unchanged ones or their metadata. Other bridges, CF7
templates, and subscription statuses do not change. If the entire desired
state and revision match, return `unchanged` without writes.

Consistent persistence and conflict protection are required:

- Check the revision and write under one protection against concurrent changes.
  A stale revision returns `cf7tg_conflict` without writes. The check covers
  changes through the existing UI/REST, not just abilities. Under the same
  protection, recheck object types and active subscriptions used in preliminary
  validation.
- A failure at any write stage rolls back post, relationship, and metadata
  changes; it leaves no partially created bridge. Use a transaction in the
  existing database and consistent locking of affected rows, without a new
  storage model. Merely calling `connectBot()` and `connectForm()` in sequence
  does not establish atomicity. Before writing, verify transaction support for
  all affected tables; otherwise return `cf7tg_atomic_write_unavailable` while
  other abilities and the ordinary UI continue working. Do not automatically
  change the database schema/engine.
- After commit/rollback, invalidate affected WP/object/relationship caches.
  An integration check reads the result in a new request. Do not promise to
  roll back external effects of third-party hooks; the ability itself does not
  trigger sending or submission.
- Defining the exact locking mechanism for existing UI/REST writers belongs to
  the configure task's DoR. If it requires vendor changes or a broad REST
  rewrite, that is a separate design decision, not a hidden scope expansion.
  Uncoordinated concurrent writers do not pass the AC.

### Recent errors

`cf7-telegram/get-recent-errors`

Input: optional `since` (integer Unix UTC ≥ 0, default now − 24 hours),
`until` (integer Unix UTC ≥ since, default now), `min_level` (enum 0, 1, 2, 3;
default 1 — attention and above), `before_id` (row ID for the next page), and
`limit` (integer 1–100, default 20). Time bounds are inclusive; parameterize
SQL. Fetch subsequent pages using the same since/until.

Output: `schema_version`, `since`, `until`, `min_level`, `items`,
`next_before_id: integer|null`, `history_complete: false`,
`retention: {days: integer, max_rows: integer}`. Read retention settings from
current settings/filters; do not prune on read. Sort by ID DESC; the next page
has IDs strictly below the cursor.

Each item: `id`, `occurred_at` (Unix UTC), `level` (0–3), `event: string`,
`message: string`, `context: object`. Context contains only nullable fields
`bot_id`, `bridge_id`, `recipient_id`, `chunk`, `http_status`,
`telegram_error_code`, `error_type`, and `retry_after`; numeric values are
integers. Error type codes correspond to existing TelegramDeliveryResult.
Do not expose raw `data`, a whole exception, or the entire decoded JSON.

Map known Logger messages to a small documented event-code set
(`telegram_delivery_failed`, `bot_unreachable`, `bot_token_missing`,
`bridge_bot_missing`, `other`). Known events get safe, translatable message
templates. For `other`, use a generic “Plugin event” message without raw
msg/data. Extract context only for events with known semantics and after type
checks; do not guess that an ambiguous `wpPostID` is a bot_id. All strings
also pass through redaction.

The current Logger table is the source; it has no guaranteed link from every
entry to a form, so input does not promise form_id filtering or a complete
delivery history. Retention and the absence of success entries prevent a
delivery-rate calculation. `history_complete` is always false. If the table
does not yet exist, return an empty result; do not create it from a read
callback. Levels retain their original Logger meaning: some bot-unavailable
messages are currently logged at level 0. They appear with `min_level: 0`
alongside other informational events; the default filter is not represented
as a complete error list.

### Invocation errors and API transport

Core schema/authorization errors retain their standard codes. Custom errors
return `WP_Error` with a stable code and `data.status`:

| Code | HTTP | Condition |
| --- | --- | --- |
| `cf7tg_forbidden` | 403 | Insufficient permission for the operation/related object |
| `cf7tg_not_found` | 404 | No object of the required CPT |
| `cf7tg_not_ready` | 503 | CF7/models/relationships are not yet available |
| `cf7tg_invalid_configuration` | 409 | Cannot begin test: bot/token missing or graph damaged |
| `cf7tg_invalid_input` | 400 | Cross-field constraints, unknown sample fields, inactive configure recipient |
| `cf7tg_conflict` | 409 | Revision stale or configuration changing concurrently |
| `cf7tg_atomic_write_unavailable` | 503 | Cannot guarantee a consistent bridge write |
| `cf7tg_write_failed` | 500 | Save did not complete; changes rolled back |

An operational result for a send already begun, including `failed` and
`unknown`, follows the output schema instead of being lost in a generic HTTP
error. After the first attempt, an exception preserves results gathered so far
and explicitly marks the remaining untested destinations. Do not infer overall
success merely from HTTP 200.

Use standard Core routes:

```text
GET  /wp-json/wp-abilities/v1/abilities
GET  /wp-json/wp-abilities/v1/abilities/cf7-telegram/get-configuration/run
GET  /wp-json/wp-abilities/v1/abilities/cf7-telegram/diagnose-form/run
POST /wp-json/wp-abilities/v1/abilities/cf7-telegram/send-test-message/run
GET  /wp-json/wp-abilities/v1/abilities/cf7-telegram/preview-message/run
POST /wp-json/wp-abilities/v1/abilities/cf7-telegram/configure-bridge/run
GET  /wp-json/wp-abilities/v1/abilities/cf7-telegram/get-recent-errors/run
```

For GET, pass input as URL-encoded JSON in the `input` query parameter;
for POST, use JSON body `{"input":{"bridge_id":42}}`. Do not add a custom
REST namespace or internal HTTP loopback. PHP clients use
`wp_get_ability($name)->execute($input)`. Output and permission checks match.
The path containing `/abilities/` is confirmed by the
[WordPress 6.9 controller](https://github.com/WordPress/wordpress-develop/blob/6.9/src/wp-includes/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php);
authentication/input format are in the [REST reference](https://developer.wordpress.org/apis/abilities-api/rest-api-endpoints/).

## End-to-end CF7 testing and API reuse

No registration of `wp_register_ability()` was found in inspected CF7 6.2.3.
The current standard submission route is
[`/contact-forms/{id}/feedback`](https://github.com/rocklobster-in/contact-form-7/blob/v6.2.3/includes/rest-api.php),
which invokes CF7 submit. The first version does not depend on any future CF7
ability or a third-party adapter for it.

Reuse a future CF7 test-send ability if its documented contract accepts form
fields and runs normal validation, mail-tag replacement, and
`wpcf7_before_send_mail`. A direct `wp_mail()` test does not provide that.
Inspect the particular registered ability and its schema; searching for a
“similar” name and automatically invoking an arbitrary ability is forbidden.

An end-to-end test must separately return CF7 status and Telegram results
collected through `cf7tg_telegram_delivery_result` /
`cf7tg_telegram_deliveries_completed`, tied to the tested submission.
`mail_sent`, absence of exceptions, or absence of Telegram events does not
confirm delivery. Skip/abort/spam/validation failures must show that the
Telegram stage was never reached.

Before enabling such a test, define a separate side-effect contract: mail,
Flamingo, CRM, and other submission subscribers may run. Disabling mail does
not disable other integrations. Server-side submit also does not prove browser
JS/CAPTCHA works. A bridge test cannot stand in for this scenario.

## Future development

All items below have `deferred` status and do not block the first version:

| Feature | Requirement for a separate task |
| --- | --- |
| End-to-end form test | CF7 invocation contract, result collection, and control of side effects from the preceding section |
| Human acknowledgment | Separate decision on callback button, wait period, and updates processing; do not call this automatic read verification |

## Acceptance criteria

| ID | Verifiable result |
| --- | --- |
| AC1 | Without the Abilities API, the plugin loads and delivers ordinary forms; no new dependencies or errors |
| AC2 | On WP 6.9 and current WP, exactly six abilities and a category are registered; real Core executes the schemas |
| AC3 | Anonymous or insufficiently privileged users cannot read data or trigger Telegram; PHP, REST, and MCP enforce the same limits |
| AC4 | Configuration spanning multiple pages can be traversed completely; forms without bridges and different topics in one chat are distinguishable; hidden records are not disclosed |
| AC5 | Static diagnosis detects the listed problems without Telegram requests or configuration/status changes |
| AC6 | Test sends through the standard gateway only to selected active recipients; skips are visible, topics preserved, and General is not an error fallback |
| AC7 | Sent requires a valid Message and matching destination; timeout, malformed response, 429, errors, and partial success are distinct; unknown is not retried automatically |
| AC8 | One recipient's failure does not block later recipients; all chunks appear in results; an empty send does not become success |
| AC9 | Tokens, raw chat IDs, real submission content, and raw responses are absent from output/errors/new log entries; sentinel checks also cover name and error text; synthetic preview is not logged |
| AC10 | GET does not send messages; two intentional POST requests create two tests; inspecting schemas/the abilities list does not execute an operation |
| AC11 | Existing CF7 delivery hooks, REST permissions, formatting, topics, and `mail_sent` behavior on Telegram error are preserved |
| AC12 | Documentation explains permissions, the WP API dependency, the separate MCP Adapter, test side effects, and limits of delivery confirmation |
| AC13 | Preview supports the declared tags/arrays, literals, and Unicode; exposes unresolved values and runtime-filter limits; sends/writes nothing and does not alter global context |
| AC14 | Configure creates/updates exactly one bridge; complete relationship sets persist, active subscriptions are not approved automatically, and other bridges/forms/tokens do not change |
| AC15 | Stale revision and UI/REST concurrency do not lose changes; fault injection rolls back every write stage; unsupported atomicity prevents writes; unchanged writes nothing |
| AC16 | Errors are read with filters, cursor, and limit; raw data is not disclosed, a missing table is not created, retention is unchanged, and complete history is not promised |
| AC17 | Preview/configure/log permissions are checked through PHP/REST/MCP; reads do not persist data, and viewing the configure schema neither changes a bridge nor sends to Telegram |

## First-stage plan

### Boundary and result

The user authorized implementation of R1.1–R1.3 with delivery-owner/ponytail
and a separate release branch. Local scoped commits are made on
`release/abilities-readonly`; push, merge to master, and publication are outside
this boundary. `waiting_dependency` means the preceding task is pending.

The stage result is a candidate ready for a separate release, with two working
abilities, verified on supported versions and independently accepted.
All six abilities remain the overall direction; T2–T5 below describe later
work and are not dependencies of this first stage.

**Included:** one category, get-configuration for four resource types, static
diagnose-form, shared permissions/safe output schemas, access through PHP and
standard REST plus optional official MCP Adapter, documentation, and regression
checks.

**Excluded:** registration of the other four abilities, even as stubs; bridge
revision/fingerprint, new persistence paths, transactions/locking, UI changes,
live ping/polling/sending, preview, logs API, new dependencies, migrations,
a new table/results cache, and resolution of open product questions.

Local delivery result: verified scoped commits, a candidate ZIP, and evidence.
Selecting a release number, changing release metadata, and publishing are a
separate task. A ready ZIP is not a published release.

### First-stage contract clarifications

- Public names: `cf7-telegram/get-configuration` and
  `cf7-telegram/diagnose-form`. Exactly these two plugin abilities are
  registered on WP with the API. Category, exposure, and GET annotations follow
  the general sections.
- get-configuration retains resource/page/per_page, ID ordering, every listed
  field for the four types, and `relations_complete` rules, excluding future
  `revision`. Do not accidentally return other post meta/JSON.
- diagnose-form retains the listed codes and `ready|partial|blocked`;
  `delivery_verified` is always false. `cached_bot_offline` is a warning;
  `token_configured` means a usable token exists, not that Telegram was checked.
- Incomplete access: lists hide inaccessible objects/relationships and count
  visible pagination correctly; diagnosis returns 403 before exposing a private
  graph. An inaccessible object is not treated as absent or damaged.
- Two bots for one bridge or a wrong CPT must not arbitrarily select the first
  bot. List returns `bot_id: null` and `relations_complete: false`; diagnosis
  returns `broken_relation`; that bridge provides no eligible path. Damage to
  one bridge does not prevent inspecting other bridges of the same form.
- PHP, REST, and MCP use one callback contract. Extra fields and invalid
  resource/ID/page are rejected; defaults match. A wrong HTTP method does not
  execute the callback. The current user is checked even for direct PHP
  `execute()`.
- Registration is independent of the current caller and does not instantiate
  models. Execution checks CF7/CPT/relationship readiness; an early call gets
  `cf7tg_not_ready`. Installing or omitting MCP Adapter does not change the
  PHP/REST contract.

### Technical approach

Start with a small `plugin-dir/lib/Abilities.php` loaded from the entrypoint.
Keep shared selection/transformation for the two commands close by; extract an
additional helper only if genuinely reused. Do not create a repository layer,
factory, new transport abstraction, or registration framework.

Read existing records through WordPress read APIs and relationships through
initialized wpConnections read APIs. Account for actual storage:
`wpPostAbleTrait::getParam()` reads JSON from `post_content_filtered`, not from
separate postmeta for each parameter. Handle invalid JSON/type/destination
without creating or repairing a record. Do not edit vendor files.

Do not use `new Bot/Chat/Channel` or `Client::getBots()/getChannels()` for simple
reads: their constructor chain creates a Logger, and Bot may change state and
run a gateway filter. Do not reuse the current REST serializer for reads; it
returns token masks and constant names.

Token selection rules must remain unified: the specific bot constant, permitted
legacy constant, saved value, and recognition of an empty mask. If there is no
pure helper, extract the smallest shared one and route existing Bot getters
through it; preserve precedence, returns, and production behavior. The token
is used internally only to compute a boolean. For chat/topic, use existing
pure validators/normalization in Util.

Measure read-only behavior relative to the state after ordinary WordPress
bootstrap: ability execution adds no inserts/updates/deletes to plugin posts,
params/meta, relationships, statuses, offsets, logs, options/locks, or schedules.
Do not mislabel Core activity during authentication as a callback write; also
do not conceal Logger/gateway initialization caused by the ability. In runtime
checks, mark execute boundaries and intercept outgoing HTTP.

Return an issue/incomplete configuration for an existing damaged record; if
storage is unavailable, return a safe error rather than an empty “healthy”
result. Pagination after permission filtering must stay complete: do not take
one SQL page, remove forbidden records, and present it as a complete visible
page with a wrong total. Do not add a persistent cache for this first version.

### R1.1 Registration and get-configuration

Status: `completed` — root accepted code, local checks, and commit `c393034`.
Goal: an authorized client gets a complete, safe configuration list.
Scope: bootstrap/category, get-configuration schema and callback, shared pure
read/permission helpers, focused tests. Only ready get-configuration is
registered in this task; R1.2 adds diagnose before stage acceptance.
Out of Scope: all stage exclusions above; diagnoses and UI changes.
DoR: plan execution authorized; open questions for other abilities do not block.
DoD: R1-AC1–R1-AC4 and read aspects of R1-AC7 confirmed by focused checks;
`composer test` and diff review passed; scoped commit after execution approval.
AC: resource schemas, 100+ objects/multiple pages, hidden relationships, safe
fields, constant/legacy/missing token, default/topic/invalid, unchanged state,
and zero outgoing HTTP.
Dependencies: current WordPress/CF7 and project libraries.
Notes/Risks: constructor side effects, incorrect assumptions about param
storage, pagination with object capabilities; shared token helper needs regression.
Owned paths: `plugin-dir/cf7-telegram.php`, new `plugin-dir/lib/Abilities.php`,
minimal `lib/Bot.php`/pure helper changes if needed,
`plugin-dir/tests/AbilitiesTest.php` (new), focused test bootstrap/fixtures,
and existing token tests. Owner resolves exact new names without scope growth.
Checks: focused checks through existing harness; after writer stops,
`cd plugin-dir && composer test`, `git diff --check`, `php -l` on changed PHP.
Handoff: revision/diff boundary, actual paths, AC evidence, commands/exits,
stopped processes; pass stable pure read helpers to R1.2.

### R1.2 Static diagnose-form

Status: `completed` — root accepted code, checks, and commit `c534c66`.
Goal: explain form-route problems without external requests or data changes.
Scope: diagnose-form schema/callback, traversal of one form graph, issue/status
aggregation, focused regression. Reuse R1.1 reads and permissions.
Out of Scope: live checks, graph repair, global diagnosis of all forms,
log exposure, and any action after obtaining the diagnosis.
DoR: R1.1 technically accepted; stage implementation authorized.
DoD: R1-AC5–R1-AC7 and get-configuration regressions pass; scoped diff review
and commit. Two ready abilities are formally registered.
AC: every issue in the contract, mixed healthy/broken route, multiple topics in
one chat, denied access to a related object, unknown status, saved offline
without false blocking, and no sends/writes.
Dependencies: R1.1, fixed first-stage inputs/outputs.
Notes/Risks: one broken object must not crash the entire collection;
temporary data unavailability is not no_bridge/ready.
Owned paths: abilities module/its shared read helper,
`plugin-dir/tests/AbilitiesTest.php`, and related fixtures. Production send
path, REST writers, and React are unchanged.
Checks: focused diagnosis, then `cd plugin-dir && composer test`, diff check,
and lint. Do not repeat broader gates before the common candidate is stable.
Handoff: both schemas/callbacks, actual issue-code map, check results,
revision boundary, and writer-stop confirmation for R1.3.

### R1.3 Real Core API and separate-release acceptance

Status: `completed` — root accepted the stage; fresh independent QA
`pass_with_notes` on `119b66a`, product `542f7ab`; final handoff and evidence
are in the checkpoint below.
Goal: demonstrate both commands work through real PHP/REST/MCP and preserve
ordinary plugin operation, including WordPress without the Abilities API.
Scope: extend existing runtime fixtures/evidence, README usage, build candidate,
run the matrix, and obtain independent acceptance.
Out of Scope: the other four abilities, release number/publication, real
Telegram, new UI, or a custom MCP server.
DoR: R1.1/R1.2 accepted; Core/DB/MCP environment available; writer stopped
before checks and candidate tied to a stable revision.
DoD: positive evidence for all R1-AC1–R1-AC8; required gates/CI pass;
independent epic QA returns `pass` or `pass_with_notes`; verified scoped commits,
ZIP hash, evidence paths, and handoff for a separate release task.
AC: table below; registrar stubs cannot substitute for WP_Ability validation,
HTTP authorization, or MCP execution.
Dependencies: R1.1/R1.2, Docker/DB, WordPress/CF7 matrix versions, and official
MCP Adapter in the test environment. Record its exact version before execution;
do not add a production composer dependency.
Notes/Risks: WP 6.9 vs current Core JSON Schema mismatch; false read-only
measurement from HTTP bootstrap; missing runtime is unmet evidence, not pass.
Owned paths: `tests/stability/wp-e4-rest-ui-smoke.php`,
`tests/stability/e4-rest-ui-smoke.sh`, narrow new fixtures if needed, and
`plugin-dir/readme.txt`; existing required CI job changes only to include
new required checks. Do not create a parallel E2E framework.
Checks: sequential ladder below; test_monitor observes each started process
once. After freezing the candidate, root launches fresh epic_qa.
Handoff: R1-AC pass/fail, exact versions/commands/exits, ZIP hash and source
revision, limitations, agent/process status, and remaining separate publication
work.

### First-stage criteria

| ID | Result and minimum evidence |
| --- | --- |
| R1-AC1 | Core with API discovers exactly the two named abilities and category; the other four are absent. Without API the plugin loads and ordinary delivery continues |
| R1-AC2 | Real Core validates complete input/output schemas; PHP/GET REST return identical data, wrong methods/inputs do not execute callbacks, and early calls are safe |
| R1-AC3 | Anonymous/insufficiently privileged users receive no configuration; object capabilities apply to every object/relationship; totals/pages reveal no hidden objects |
| R1-AC4 | All four resources are readable, including unrelated objects; pagination has no omissions with >100 records, two topics in one chat are distinct, invalid is not disguised as General, and revision is not exposed |
| R1-AC5 | Every reason and ready/partial/blocked is reproduced in fixtures; healthy+broken routes give partial, cached offline does not prove failure, and delivery_verified is always false |
| R1-AC6 | After bootstrap, neither command writes plugin state/log/options/locks or calls HTTP/gateway, even with missing token, broken JSON, and repeated calls |
| R1-AC7 | Sentinel secrets/chat IDs/contact data are absent from output/errors/new logs; token-source precedence remains; ordinary CF7 hooks, active recipients, and topic delivery pass regressions |
| R1-AC8 | Official MCP Adapter discovers and executes both abilities with the same permissions; README explains static diagnosis limits and usage examples; CI and independent QA accepted the frozen candidate |

Minimum runtime fixtures: healthy route; form without bridge; bridge without bot;
missing/constant/legacy token; empty bridge; active/pending/muted/unknown/missing
bot relationship; deleted/wrong CPT; two bots on one bridge; invalid topic and
two topics in one supergroup; inaccessible related object; paged selection with
restricted permissions; sentinel in token/title/error. Fixtures alone create
broken records, rather than mutating production state. Fixture cleanup is
outside the measured execute boundary.

### First-stage matrix and commands

| Environment | Required result |
| --- | --- |
| WP 5.6.19 / PHP 8.0 / CF7 5.4.2 | Existing legacy support gate, no API/fatal errors, ordinary delivery through fake transport |
| WP 6.9 / PHP 8.3 / CF7 6.1.4 | Real registration/schema/PHP/REST/permissions/no-side-effects checks for both abilities |
| Current required-row support manifest | The same ability checks, current E4/E6 and required lifecycle cases; separate MCP smoke |

Current-row versions come from `tests/stability/e1-version-sources.json`.
WP 6.9 is added to feature evidence; it does not replace existing support rows.
A bootstrap or shared token-helper change requires ordinary delivery regressions.
CF7 6.1.4 was chosen for this row: its [readme](https://github.com/rocklobster-in/contact-form-7/blob/v6.1.4/readme.txt)
states minimum WP 6.7 and testing with WP 6.9. Inspected
[CF7 6.2.3](https://github.com/rocklobster-in/contact-form-7/blob/v6.2.3/readme.txt)
requires WP 7.1 and does not fit the minimum API row. Record actual versions
and ZIP source before runtime; do not force an incompatible installation.

Existing commands, after R1.3 expands fixtures/evidence:

```bash
# From plugin-dir, on a stable R1.1/R1.2 diff.
composer test

# From the repository root, after final R1.3 changes.
git diff --check
scripts/build-release-zip.sh

# Minimum version of the built-in API; variables are already supported by the E4 runner.
CF7TG_E4_SMOKE_WP_VERSION=6.9 CF7TG_E4_SMOKE_CF7_VERSION=6.1.4 CF7TG_E4_SMOKE_WP_CLI_IMAGE=wordpress:cli-php8.3 tests/stability/e4-rest-ui-smoke.sh

# Current row, existing delivery, and support matrix — same candidate ZIP.
tests/stability/e4-rest-ui-smoke.sh
tests/stability/e6-form-delivery-smoke.sh --skip-browser-install
tests/stability/e5-support-matrix.sh
```

Use separate work/results directories for different runs; record the hash of
`dist/cf7-telegram-wp-plugin.zip`. `--skip-browser-install` applies if Chromium
is already installed. Check `composer test` output to determine whether PHPUnit
or the compatibility fallback ran; required PHPUnit/CI are not waived.

New Core/MCP checks did not yet exist at plan time. R1.3 must include them in
required E4 evidence, check the actual set of two abilities, and add a
serialized MCP smoke. Record the exact MCP command when connecting a transport
supported by the adapter; do not invent a CLI. Existing applicable fixture or
a narrow extension, not successful activation alone, must prove legacy fake
delivery. A missing/skipped required check means the stage is unfinished.

### Order and process

`R1.1 → R1.2 → R1.3 → independent QA → handoff for separate release`.
After execution authorization, each batch gets a fresh owner; root manages
scope/checkpoint, worker owns code/tests/usage. The recommended owner for each
of the three tasks is `gpt-6.1-sol`, high: bounded PHP/integration work with
compatibility risk; root checks availability and records the actual choice
before spawning. Specialists use pinned roles; one writer in the checkout.

R1 acceptance does not wait for T2–T5 or claim the AC for all six abilities
are complete. Independent QA of this first stage checks only R1-AC and its
frozen boundary. If a finding requires send, UI/REST writer changes, or open
product decisions, owner returns the specific scope conflict instead of
enlarging the task. Normal read-error handling and fixture preparation are
ordinary implementation work, not new questions for the user.

## Follow-up work for the remaining abilities

The plan below covers the target set after separate R1 acceptance. Run checks
for the changed scope, rather than repeating the entire first stage without
cause.

First registration/reading, then test sending, preview/logs, consistent bridge
changes, and an overall gate. Use existing PHPUnit/compat harness, fake Telegram,
and E6; no new test framework is needed. A registrar stub for
`wp_register_ability()` cannot replace real Abilities API checks.

Required matrix: legacy WP 5.6.19 / PHP 8.0 / CF7 5.4.2 without API; WP 6.9 /
PHP 8.3 / CF7 6.1.4 with the minimum built-in API; current required row from
[manifest](../../tests/stability/e1-version-sources.json) with API. WP 6.9 is
an additional feature-check row, not a replacement for existing rows. On one
current row, check MCP discovery/execution through an installed official
adapter and record its version. Telegram is fake in these checks.

Existing gate commands to use after implementation:

```bash
# From plugin-dir; determine from output whether PHPUnit or the compatibility fallback ran.
composer test

# From the repository root; build before checking the same candidate ZIP.
git diff --check
scripts/build-release-zip.sh
tests/stability/e4-rest-ui-smoke.sh
tests/stability/e6-form-delivery-smoke.sh --skip-browser-install
tests/stability/e5-support-matrix.sh
```

Extend the existing harness and required evidence IDs for new real PHP/REST/MCP
checks; record exact new commands/flags during implementation. Do not treat
current scripts as already checking abilities. `--skip-browser-install` applies
only when Chromium is installed. Run gates sequentially against a fixed
candidate ZIP with a recorded hash; overall required CI remains mandatory.
Missing runtime or a skipped row is not pass.

Live Telegram is optional for the first version: a separate approved manual
check of a test chat/topic may supplement fake evidence. It sends real messages
and is not authorized merely by creating this specification.

### T1 Registration and configuration reads

The former broad task was replaced by the independent R1.1–R1.3 plan above.
References to T1 in T2–T5 dependencies mean accepted R1, not permission to
start the other abilities or closure of the entire target set.

### T2 Test sending with a reliable result

Status: `waiting_dependency`.
Goal: an authorized client tests sending through a selected bridge.
Scope: send callback, normalization of confirmations, minimal shared helpers
if needed, focused fake Telegram tests.
Out of Scope: CF7 submit, queue, arbitrary text, changing recipients.
DoR: T1 accepted; execution authorized; test contract accepted.
DoD: AC6–AC10 and preservation of AC11; PHP gate, diff review, scoped commit.
AC: topics, partial/unknown/skipped, all chunks, permissions before first HTTP,
destination mismatch, and no raw payload in result.
Dependencies: T1.
Notes/Risks: filters may alter the message/destination; whole-request timeout;
no exactly-once guarantee. Checks do not call real Telegram.

### T3 Preview and error reads

Status: `waiting_dependency`.
Goal: obtain a base preview and diagnostic events without external effects.
Scope: two read callbacks, shared pure formatter, bounded log mapper,
permissions, and focused/runtime checks.
Out of Scope: end-to-end submit, complete submission history, bridge changes.
DoR: T1 accepted; base_template and log limitations accepted; execution authorized.
DoD: AC9, AC13, AC16–AC17; PHP gate and production delivery regressions after
extracting formatter; diff review and scoped commit.
AC: relevant overall criteria; no write/send or recovery of real submission
data; synthetic samples do not enter the log.
Dependencies: T1; technical acceptance of the preceding batch before writing.
Notes/Risks: mail tags with special context, third-party filters, legacy logs.

### T4 Consistent bridge creation and updates

Status: `needs_design`.
Goal: change one bridge without partially saved routes or losing others' edits.
Scope: configure callback, revision, shared protection for existing writers,
minimal relationship-layer changes if needed, fault/concurrency tests.
Out of Scope: vendor patches without a separate decision, new UI, approving
subscriptions.
DoR: T1 accepted; a verifiable transaction/locking mechanism with current
UI/REST writers and cache invalidation defined; full replacement contract
accepted; execution authorized.
DoD: AC14–AC15, AC17; real DB integration check, PHP/E4 regression,
diff review, scoped commit. Preliminary ID validation alone is insufficient.
AC: relevant overall criteria; rollback at every failure point and conflict
with writes through current UI/REST checked without real Telegram requests.
Dependencies: T1 and an atomicity-mechanism decision; acceptance of previous batches.
Notes/Risks: relationship library has no ready contract for changing an entire
bridge atomically; multiple tables and object caches are involved.

### T5 Integration acceptance and documentation

Status: `waiting_dependency`.
Goal: a verified candidate and clear client contract.
Scope: extend existing runtime fixtures/evidence for Core REST/MCP, check the
matrix and E4/E6, add usage to README.
Out of Scope: release bump, push, merge, deployment, and publication.
DoR: T1–T4 accepted; writer stopped; candidate and revision frozen.
DoD: AC1–AC17 confirmed; required gates pass; independent epic QA returns
`pass` or `pass_with_notes`; verified scoped commits and evidence recorded here.
AC: overall criteria; no skipped required test recorded as pass.
Dependencies: T1–T4, Core/MCP runtime, and existing verification environment.
Notes/Risks: API support on minimum version, third-party plugins/filters;
specification and execution have separate boundaries.

Refine later tasks with `decompose-work`, preserving Goal, Scope,
Out of Scope, DoR, DoD, AC, Dependencies, and Notes/Risks. After authorization,
multi-batch delivery follows the accepted project process: root → fresh batch
owner → specialists, plus separate independent epic QA. Do not create a new
roadmap or duplicate registry; checkpoints remain in this file.

## Specification checkpoint

- 2026-10-29: specification prepared against `e25d811`; the worktree was clean
  before preparation. The user chose a target set of six abilities, then
  separated two read abilities into the first independent stage. Preview,
  configure, and errors remain later stages; submit and human ACK are deferred.
- Relationship model, sending, permissions, test gates, and official
  WordPress/Telegram contracts were checked. The actual REST path with the
  `/abilities/` segment was clarified.
- Boundary of that task: a local document and backlog link. Product code,
  ability registration, runtime tests, commit, and publication were not done.
- Next stage when the plan was prepared: implement R1.1 after authorization.
  The user requested a plan for two read abilities without settling the open
  questions; R1.1–R1.3 were added as an independent release boundary. The other
  four abilities and T4 do not block R1; their original contracts remain for
  discussion. R1 has no revision/fingerprint or registration of future operations.
- Document checks after separating R1: JSON example parsed; 14 local links
  resolved; tables, R1-AC1–R1-AC8, three R1.1–R1.3 contracts, and backlog link
  checked. `git diff --check` — exit 0; for the new file,
  `git diff --no-index --check` — exit 1 without whitespace findings (files
  differed). An independent trailing-whitespace/newline check also passed.
  The minimum API row was corrected to compatible CF7 6.1.4. These were plan
  checks, not implementation runtime evidence; code was unchanged.
- Read-only review of six abilities accepted from `/root/abilities_six_spec_review`,
  thread `01a1874d-622a-7b63-8563-aeb4e0bf7be2`: no material findings.
  Reviewed version before this checkpoint entry:
  SHA-256 `9ce8620575f4e3988c4b937c614dd2c86fe5ede195cd07d8aebf9240f4d7df44`.
  Agent completed without changing files; no descendants or running checks.
  This historical review preceded the R1 plan and was not its independent QA.
  Earlier reviewer `/root/abilities_spec_review` completed reading the prior
  three-ability scope; its feedback is not used as review of the expanded scope.
  The first reviewer's native ID was unavailable; neither branch was archived.

## R1 execution checkpoint

- 2026-10-29: the user authorized R1.1–R1.3, root delivery ownership, and
  git-flow with a separate release branch. `release/abilities-readonly` was
  created from `e25d811`; initial changes were only this specification and
  backlog README.
- R1.1 ready to start. Selected fresh batch owner `gpt-6.1-sol`, effort `high`:
  bounded PHP task with authorization and side-effect risks. Specialists are
  pinned worker/test_monitor; one writer, sequential checks.
- Before spawning, agent list contained only root. Historical reviewers were
  absent from the current tree; their runtime capacity was not assumed occupied.
- Delivery boundary: local verified commits and candidate ZIP; no version
  change, push, master merge, or publication. Next work: R1.1.
- R1.1 `in_progress`: owner `/root/r1_1_owner`, native thread
  `01a1876c-f633-7332-9193-12800ebc8cbe`; worker
  `/root/r1_1_owner/implementation`, native thread
  `01a1876d-b188-75e2-aab1-61b8af07ae92` (pinned `gpt-6-sol/high`).
  Initial boundary: planning commit `87bf2b1`, clean worktree.
  Root confirmed actual token precedence: with a legacy marker, a defined
  global constant takes priority even if a scoped constant is defined.
  Brief wording of precedence does not change the production contract.
- R1.1 technically accepted by owner: bootstrap registers only ready
  `get-configuration`; four resources are read without models, using object
  capabilities before pagination, strict schemas, safe labels, and relationships.
  Shared `BotToken` added; root-approved pure
  `Client::hasInitializedConnections()` prevents lazy storage creation after
  skipped/failed initialization. Paths: `plugin-dir/cf7-telegram.php`,
  `lib/{Abilities,BotToken,Bot,Client}.php`, `tests/{AbilitiesTest,bootstrap}.php`;
  all other changes were this checkpoint.
- R1.1 focused evidence: 9 Abilities tests and 4 selected Bot/WpConfig
  regressions through a reflection harness — exit 0; separate entrypoint
  subprocess without Abilities API functions — exit 0. Covered 111 visible
  records, hidden relationships, sibling topics, malformed JSON/relationships,
  safe refusal when storage is unavailable, and repeated no-write/no-HTTP/
  no-gateway snapshots. Constants regression was isolated in a subprocess,
  without affecting later tests. Owner reviewed the entire scoped diff;
  no blocking findings.
- After writer stopped, fresh `/root/r1_1_owner/verification`, native thread
  `01a1877d-78ea-7232-9dcd-d6fd0aafbc5a` (pinned `gpt-6-luna/low`), ran
  `cd plugin-dir && composer test` sequentially — exit 0,
  **119 tests / 0 failures**; `php -l` on all 7 changed PHP files — exit 0;
  `git diff --check` — exit 0. This was the compatibility harness (PHP 8.0.30,
  no dom/mbstring/xmlwriter), not PHPUnit or real Core/REST/MCP. Logs:
  `/tmp/cf7tg-r1.1-verification/`: `composer-test.log`, `php-lint.log`,
  `diff-check.log`. The monitor's initial read-only command with a wrong path
  prefix exited 1; it was corrected before the ladder without source changes.
  All seven frozen SHA-256 hashes matched before/after; manifest was
  `frozen-php.sha256` in the same directory. Git status was unchanged.
- R1.1 boundary: parent `87bf2b1` + the seven verified PHP files from the
  manifest and this checkpoint; scoped commit includes this entry. Focused
  R1.1 reads confirmed R1-AC1–4/7; AC6 had no-side-effects harness evidence.
  Full Core/REST/MCP/CI/QA gate remained for R1.3. Diagnosis was not registered
  until R1.2; all of R1 was unfinished. Worker and monitor completed, without
  descendants/running commands; root received IDs for supported lifecycle
  cleanup. Next work after root acceptance: R1.2.
- Root accepted R1.1 on `c3930344720050aaabd0f95a5b77e46a72a2c4bb`: decisive
  hunks, status, full composer log, and handoff were checked; tree clean.
  Worker, monitor, and owner were archived via standard `codex archive --remote`
  using the discovered daemon socket; all commands exited 0. After cleanup,
  agent list contained only root. Descendants and verification processes stopped.
- R1.2 ready. Selected fresh owner `gpt-6.1-sol/high`: diagnosis uses the
  accepted read path but needs careful graph traversal, permissions, and issue
  aggregation.
- R1.2 `in_progress`: owner `/root/r1_2_owner`, native thread
  `01a18781-3d23-72f2-ab4f-a3adae671e51`; initial clean boundary `43d63a6`.
  Only writer: fresh `/root/r1_2_owner/implementation` with pinned
  `gpt-6-sol/high`; changes limited to abilities and focused tests.
  After writer stops: separate test_monitor and serial PHP gate.
- Owner reviewed initial R1.2 scoped diff: second and final ability
  `diagnose-form` registered, with strict schemas and static aggregation of all
  eight issue codes. Permissions are checked before diagnosis for the form,
  its bridges, all forms/bots/recipients related to each bridge, and bots of
  selected recipients' relationships, without recursively traversing other
  routes. A hidden existing object, including a wrong CPT, produces 403.
  Two bots or a damaged bridge do not hide another valid path.
  Paths: `plugin-dir/lib/Abilities.php`, `plugin-dir/tests/AbilitiesTest.php`.
- Owner reviewed the actual diff, found and fixed omitted duplicate links
  from a neighboring form to the selected bridge. Repeated focused reflection
  harness for all 14 Abilities tests — exit 0; log
  `/tmp/cf7tg-r1.2-worker/abilities-focused-repair.log`.
  R1-AC5: each code, ready/partial/blocked, healthy+broken, two topics, offline;
  R1-AC6: repeated snapshots without plugin/log/options/cron/HTTP/gateway
  mutations; R1-AC7: fixed translated issue text, sentinel output/storage
  errors, and preserved get-configuration/token regressions. Real runtime
  regressions remained for R1.3.
- Writer `/root/r1_2_owner/implementation`, native thread
  `01a18782-007e-71d2-bddb-53a7492a73e7`, stopped before external gate.
  Fresh `/root/r1_2_owner/verification`, native thread
  `01a1878c-a51d-71f1-99be-dca052cfa3d8` (pinned `gpt-6-luna/low`), ran
  `cd plugin-dir && composer test` once — exit 0,
  **124 tests / 0 failures**; `php -l plugin-dir/lib/Abilities.php` and
  `php -l plugin-dir/tests/AbilitiesTest.php` — exit 0; `git diff --check`
  — exit 0. Logs and manifest:
  `/tmp/cf7tg-r1.2-verification/{01-composer-test,02-php-lint,03-diff-check}.log`,
  `manifest.txt`. PHP 8.0.30/Composer 2.5.5; this was the compatibility
  harness, with no dom/mbstring/xmlwriter. The monitor initially stopped on
  this limitation, then completed the remaining ladder without repeating
  composer; real PHPUnit, Core/REST/MCP, and overall candidate gate were not
  claimed as passed.
- Frozen PHP SHA-256 before/after gate matched: Abilities
  `e9e4907636cea8b96b011d89062f87903cf23e5f48a46aac4cf02a92a599008a`, tests
  `ffc4b1b25e6b1eb32d91fe986bdc56689e0445b1c52a5ac4bddb8d5679629d5e`.
  That was the original verified boundary before root's finding, not final
  acceptance. Root found a false token_missing with malformed JSON and a usable
  scoped constant; the same worker fixed token detection independently of JSON
  validity, while preserving broken-route blocking through broken_relation.
- Final R1.2 technically accepted by owner after actual repair diff review.
  An isolated subprocess was added: malformed bot JSON + usable scoped constant
  retains token_configured, reports broken_relation without false token_missing,
  exposes no secrets, and does not mutate state on repeat. Focused reflection
  harness for all **15 Abilities tests** — exit 0;
  `/tmp/cf7tg-r1.2-worker/abilities-focused-token-repair.log`.
  On the new boundary, the same monitor repeated the required ladder after the
  code change: `cd plugin-dir && composer test` — exit 0,
  **125 tests / 0 failures** (0.24 s, compatibility harness); `php -l` on both
  changed PHP files and `git diff --check` — exit 0. Earlier attempts were
  retained; real PHPUnit was not claimed. Final logs `01-composer-test.log`,
  `02-php-lint.log`, `03-diff-check.log` and manifest:
  `/tmp/cf7tg-r1.2-verification/final-token-repair/`.
- Final SHA-256 before/after gate: Abilities
  `e2ca4340d97075eb1f1787afa87a3833dd1ab47414c6b6e2d8994e857a2e294f`, tests
  `d4c1b83140ace3edbe1b48500fc9e998134d9a064d33ea6ad13dedb5d4e8e9aa`.
  Scoped commit: parent `43d63a6` + these two files and this checkpoint.
  No unexpected changes; writer and monitor completed without descendants or
  running commands. Native IDs above were passed to root for lifecycle cleanup.
  Next ready batch after root acceptance: R1.3; this owner does not take it.
- Root accepted R1.2 on `c534c665abc6e27b3a8d9884b48cd785ab9d93cf` after
  checking decisive hunks, the token finding fix, actual composer log, and
  clean status. Worker/monitor/owner archived normally, all exits 0; after
  cleanup, agent list contained only root and commands were stopped.

- R1.3 ready; selected fresh owner `gpt-6.1-sol/high`: integration with real
  Core/REST/MCP and evidence for one candidate are required. Root preflight:
  Docker 26 available; exact local images `wordpress:cli-php8.0`,
  `wordpress:cli-php8.3`, `mysql:5.7` present. Separate
  `wodby/wordpress-php:8.3-4.62.1` returned PHP 8.3.14 with
  dom/mbstring/xmlwriter=yes (exit 0); this is an available PHPUnit environment,
  not a test result yet. Live dev stack `cf7t_*` is unused.
- MCP smoke is pinned to official Adapter 0.7.1, WP>=6.9/PHP>=7.4:
  [version's CLI guide](https://github.com/WordPress/mcp-adapter/blob/v0.7.1/docs/guides/cli-usage.md).
  Standard STDIO `wp mcp-adapter serve --server=mcp-adapter-default-server --user=...`
  provides discovery/execute through default meta-tools without a custom server.
  E4 currently uses internal REST dispatch; R1.3 also checks HTTP auth.
- Required CI commands are checked locally with the changed workflow.
  Hosted CI requires push/PR and is not claimed to have run within this local
  boundary.
- R1.3 technically accepted by owner from actual diff/evidence; `review`,
  root QA pending. Product revision
  `75ad9e677ca2d0e02eb781491a3b9248387733e9`; latest harness
  `01220cfae6748999a97c358f2bcec08e717b008c`. Final delivery boundary:
  this source/harness plus bookkeeping commit; product ZIP unchanged.
  Branch `release/abilities-readonly`, version 1.2.1; no push/merge/
  publication/version bump was done.
- Changed Abilities.php, new ReadOnlyAbility.php, README, required CI, E1/E4
  runners, and three narrow runtime fixtures. Owner reviewed actual scoped
  diffs; worker owned code/fixtures/usage/CI, owner only checkpoint.
- Root approved native compatibility: GET JSON-object normalization only for
  the two exact Core run routes; boolean permission wrapper passes a logged-in
  not-ready caller to the execute guard (503); an `ability_class` subclass
  changes only validate_input, calls parent, and preserves strict integer/no
  callback. [Core 6.9 controller](https://github.com/WordPress/wordpress-develop/blob/6.9/src/wp-includes/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php),
  [WP_Ability](https://github.com/WordPress/wordpress-develop/blob/6.9/src/wp-includes/abilities-api/class-wp-ability.php).
  Query filters explicitly enabled (`suppress_filters=false`); per-object
  capabilities, visible pagination, and public schemas preserved. Translator
  comments added.
- Candidate `dist/cf7-telegram-wp-plugin.zip`: SHA-256
  `15239dd6a3aeff75f79ba11062e7c35bbc803bb45e007e297517532b0f47c249`,
  `SOURCE_DATE_EPOCH=1793281353`; build 2x, cmp, and validation exited 0.
  Evidence root: `/tmp/cf7tg-r1_3-gates-20261029T125240Z/`; final reference:
  `FINAL-candidate-reference.zip`. No unexpected tracked build changes.
- Source gates: PHP lint 61 + 3 fixtures, bash E1/E4, YAML, diff — exit 0;
  real PHPUnit with PHP 8.3.14/9.6.36 — 125 tests / 720 assertions, exit 0
  (`plugin-check-repair-source/phpunit-actual.log`); local PHP 8.0.30 Composer
  compatibility harness — 125/0. React 8 suites / 60 tests and blocking
  audits=0 were carried forward by unchanged JS/lock hashes. Full-dev audit
  was non-blocking: 78 findings (12 low / 33 moderate / 29 high / 4 critical).
  Restricted DNS/cache attempts were retained separately; successful audit:
  `audit-reports-attempt2/summary.json`.
- Final E4 minimum: WP 6.9 / PHP 8.3.33 / CF7 6.1.4; current: WP 7.1 /
  PHP 8.3.33 / CF7 6.2. Each: existing 24/24 + Core 26/26, HTTP,
  failed_steps=0. Current official MCP Adapter 0.7.1 STDIO/protocol 2025-11-25:
  all 11 required checks passed, including discovery/execute/denials/numeric
  parity. Evidence: `e4-minimum-final/`, `e4-current-final/` on the same
  candidate SHA.
- Plugin Check 1.9.0: errors=0/warnings=5 (`plugin-check-final/`);
  browser 8/8 (`browser-final/`), E6 fake Telegram 37/37 (`e6-final/`),
  all exit 0. Warning for set_error_handler is an intentional temporary
  storage-error guard; four other warnings were pre-existing (vendor manifest,
  importer SQL, legacy hook). Playwright 1.62.1 / Chromium rev1234
  Chrome151.0.7922.34; real Telegram/mail and live `cf7t_*` stack unused.
  Hosted CI was not run.
- Full `support-matrix-final/` exit 1 retained: legacy 10 cases, only fresh
  fake delivery failed; the other 9 legacy upgrades passed, current 9/9 passed,
  optional WP7.1/PHP8.5.10/CF7 6.2 9/9 passed. All rows used the final
  candidate SHA. Exact CF7 5.4.2 source confirmed an empty User-Agent yields
  spam before before_send_mail;
  [source](https://raw.githubusercontent.com/rocklobster-in/contact-form-7/v5.4.2/includes/submission.php).
  Harness `01220cf` added only HTTP metadata and safe counters, retained both
  skip_mail guards, and strengthened the hook assertion. Targeted
  `legacy-fresh-repair/` exit 0, failed_steps=0: no-API and fake delivery pass;
  mail_sent, hook=1, completed/send/ok=2, ordinary/topic=true;
  activate/reactivate/deactivate/uninstall (cron/options/files/tables) pass.
  Clean E5 failed legacy row retake `support-matrix-legacy-repair/`: exit 0,
  10/10 manifest cases, 349/349 steps, failed=0/skipped=0; both legacy IDs/
  counters pass. Owner inspected actual summary. Current 9/9 and optional 9/9
  passing rows were retained; original failed aggregate remains a historical
  failure, without rewriting it. All required cases now have positive evidence
  on the same ZIP.
- All previous failed ZIP/evidence retained: `candidate-reference.zip`,
  `repair-candidate-reference.zip`, `e4-minimum/`, `e4-current/`,
  `e4-current-repair/`, `plugin-check/`, `support-matrix-final/`.
- Agents: owner `/root/r1_3_owner` native `01a18794-740d-7552-a092-510eb3a36dfa`
  (`gpt-6.1-sol/high`); worker `/root/r1_3_owner/r1_3_worker` native
  `01a18795-392b-7232-b387-3771bde83b3d` (`gpt-6-sol/high`); monitor
  `/root/r1_3_owner/r1_3_monitor` native `01a187b8-0c03-7011-8e39-808dfcac3e32`
  (`gpt-6-luna/low`). Worker/monitor completed; all commands and isolated
  containers stopped after the row retake; completed confirmed by live agent
  state. Root owns lifecycle cleanup; next is independent epic QA; owner does
  not take the next batch. Other four abilities and publication excluded.
- Parent `/root` remained `errored`: "Selected model is at capacity. Please try a different model."
  R1.3 technical work was finished; root model/QA were not substituted.
  Recovery after handoff: resume root, obtain independent epic QA, and use
  supported cleanup for the native IDs.
- AC mapping: R1-AC1 — legacy 2/2 + current/min exact registration; AC2 —
  native schemas/parity/strict input/GET/early503; AC3 — user/object permissions,
  hidden graph/counts; AC4 — four resources/unlinked/100+/topics/no revision;
  AC5 — all 8 codes and ready/partial/blocked; AC6 — snapshots/SQL mutation,
  HTTP/gateway counters zero; AC7 — redaction/token precedence/E6 37/37;
  AC8 — official MCP/README/local required gates verified,
  **independent QA pending**. DoD root QA/full-batch acceptance remained unmet;
  the epic was not declared complete.
- Full command/result ledger: `/tmp/cf7tg-r1_3-gates-20261029T125240Z/verification-summary.txt`,
  SHA-256 `3256b075f306d588ecb2b597e29f1cee2c6143f178b044f6dff339e73678dadc`.
  After root recovery: read this checkpoint/ledger, launch fresh independent
  epic_qa directly from root on frozen source/harness/candidate, then perform
  supported cleanup of completed descendants using the native IDs above.
- Root recovered and accepted R1.3 technical handoff on
  `237003b36a6b19df5ecbfcaa6f5115bee67763a6`: actual compatibility/fixture/
  CI diffs, legacy counters, raw summaries (legacy retake 349/349, current
  313/313, optional PHP8.5 313/313), ledger, and matching candidate/reference
  SHA were checked. Original aggregate failure retained. Writer/monitor/owner
  completed and commands stopped; all three native sessions archived through
  serving daemon, exits 0; live list contained only root. Next: fresh
  independent `epic_qa` directly from root, role `gpt-6-sol/high`, read-only;
  reviewed boundary `e25d811..237003b` plus this bookkeeping. Product
  `75ad9e6`, harness `01220cf`, ZIP hash unchanged.
- Independent QA `/root/r1_epic_qa`, native `01a18838-2772-7213-becc-fe8eaa83f910`,
  pinned `gpt-6-sol/high/read-only`, reviewed `e25d811..b77e239`:
  **verdict: fail** (retained unchanged). R1-AC4/AC5, high:
  `Abilities::destination()` accepts a nonempty damaged chatID (`abc`, private),
  returns default, and could report ready instead of invalid/blocked. Sanitizer
  removes whitespace but does not validate the address; the fixture checked an
  invalid topic but not this chat ID. Minimal repair: validate saved address by
  type and permitted @username only in abilities, add PHPUnit/Core regressions,
  rerun affected gates, and obtain fresh independent QA. AC1–3/6–7, README,
  MCP, and local gates confirmed; AC8/DoD not accepted due to this finding.
  Original verified ZIP boundary and warnings retained above. QA did not
  implement the epic, worked read-only, and had no commands/descendants.
- Root accepted the finding and started one bounded repair batch; selected
  fresh owner `gpt-6.1-sol/high`: narrow destination validation with compatibility
  risk. Scope: Abilities::destination, focused/native fixtures, checks, and new
  ZIP; send path/other abilities excluded. If the same QA failure recurred,
  automatic repair would stop under the process.
- Destination repair: `review`, source/harness
  `542f7ab9e41bb53228331d71264bfa8ea6a751cb` on top of root bookkeeping
  `eaafb90`; writer stopped and clean boundary confirmed. Owner inspected the
  actual diff in four agreed paths: `Abilities.php`, `AbilitiesTest.php`,
  `wp-r1-abilities-smoke.php`, required E4 inventory. Raw integer/canonical
  numeric string and `@username` for supergroup/channel remain eligible;
  malformed address gives invalid/blocked, mixed graph gives partial.
  Topic/schema/auth/send path unchanged; no extra ID length/prefix restrictions.
  Decision source: [Bot API](https://core.telegram.org/bots/api#getchat).
  Pre-fix abc/private repro exit 1; five focused methods, PHP/bash lint, and
  diff check exit 0. Host PHPUnit attempt exit 1 (missing
  dom/mbstring/xmlwriter), so real Docker PHPUnit remained a required gate.
  Evidence: `/tmp/cf7tg-r1-destination-repair-20261029/worker/` and `monitor/`.
  Owner `/root/r1_destination_repair_owner`, native
  `01a1883d-6744-7a30-919c-de5343b4e2fa`, `gpt-6.1-sol/high`;
  worker `/root/r1_destination_repair_owner/destination_worker`, native
  `01a1883e-0cad-74a0-a6ff-949cd72857a9`, pinned `gpt-6-sol/high`, completed.
  Prior QA native `01a18838-2772-7213-becc-fe8eaa83f910` archived by root,
  exit 0. Monitor `/root/r1_destination_repair_owner/destination_monitor`, native
  `01a18843-9a81-7430-afa5-5978e08181f3`, pinned `gpt-6-luna/low`, completed.
  All its ladder processes/isolated containers stopped; pre-existing live
  `cf7t_*` was unused and remains running. Owner/worker did not run parallel
  broad checks; root owns native lifecycle cleanup.
  Verification ledger: `/tmp/cf7tg-r1-destination-repair-20261029/monitor/verification-summary.txt`,
  SHA-256 `a4ae4f27defd188edf51f981da21b7c2a9da8dc7b70050f129e08e59a4dc910a`.
  Owner read actual diff, PHPUnit transcript, and raw runtime summaries:
  PHP/bash lint/diff exit 0; real PHPUnit 9.6.36 — 126 tests/820 assertions,
  exit 0 (original output retained without rerun); compatibility fallback was
  unnecessary. Build 2x/cmp/ZIP validation exit 0,
  `SOURCE_DATE_EPOCH=1793287434`, version 1.2.1. New ZIP SHA-256
  `786807309ac1e62f024d371bafe00b545f0c3b746c097d212d253342cecd9d9c`.
  E4 WP6.9/PHP8.3.33/CF7 6.1.4 and WP7.1/PHP8.3.33/CF7 6.2 each produced
  existing 24/24 + Core 27/27; wrappers 26/26 and 38/38, failed_steps=0.
  Current MCP Adapter 0.7.1 / STDIO / protocol 2025-11-25: 11 required
  assertions passed. Address validity, mixed graph, repeated read-only, and
  redaction passed on both Core versions. Plugin Check 1.9.0:
  errors=0/warnings=5 (same set), wrapper 13/13. E6 fake Telegram: 37/37,
  wrapper 57/57, exit 0; requested browser-install skip did not skip
  Playwright execution. Legacy fresh WP5.6.19/PHP8.0.30/CF7 5.4.2: 34/34,
  exit 0; no-API and fake delivery passed, mail_sent/hook=1/completed=2/send=2/
  ok=2, ordinary/topic=true, activate/reactivate/deactivate/uninstall cleanup
  passed. All gates ran on the new SHA.
  Both ZIPs had 199 members, with none added/deleted; content delta was only
  `cf7-telegram/lib/Abilities.php` (also verified by root). Root therefore
  approved carrying forward full legacy10/current9/optional9 lifecycle,
  browser8, React8/60, and blocking audits=0 from the prior SHA
  `15239dd6a3aeff75f79ba11062e7c35bbc803bb45e007e297517532b0f47c249`;
  this is prior evidence, not claimed as rerun on the new ZIP.
  Bootstrap/send hooks/UI/dependencies/locks unchanged; prior aggregate failure
  and every evidence attempt retained. Hosted CI/push/merge/publication not run.
  Repair AC mapping: AC4 — malformed remains invalid; numeric/username/topic
  retain their kinds; AC5 — sole invalid blocked/empty, mixed partial; AC6/7 —
  repeated all-resource counters/snapshots/redaction and delivery regressions
  pass; AC1–3/8 — native Core/permissions/MCP regressions pass, unchanged broader
  evidence retained. Owner technically accepted repair; no blockers.
  Residual risk: only local address format is checked, not existence on Telegram;
  this is the agreed static diagnosis. Five previous warnings are non-blocking.
  Next, root accepts handoff and starts fresh independent QA; AC8/overall DoD
  remain unmet until its verdict. If the original failure recurs, stop automatic
  repair. This owner finishes only the current repair and does not take a
  successor.
- Root accepted repair on `137f308b2f6ebd4265197966c0d6cddb5ca398ba` after
  actual source/test diff, raw PHPUnit/E4/Plugin Check/E6 summaries, ledger,
  and independent ZIP-content comparison. All three repair sessions were
  completed/stopped and archived with the standard CLI, exits 0. Product
  `542f7ab`, new ZIP
  `786807309ac1e62f024d371bafe00b545f0c3b746c097d212d253342cecd9d9c`.
  Next: fresh independent `epic_qa`, pinned `gpt-6-sol/high/read-only`, directly
  from root on the complete R1 boundary; the original failure remains a
  historical verdict.
- Final independent QA `/root/r1_epic_qa_after_repair`, native
  `01a18854-15f6-7483-9cb0-b93e258a4dcd`, assigned pinned `epic_qa` role
  (`gpt-6-sol/high/read-only`), returned **pass_with_notes**. Verdict retained
  unchanged; review boundary
  `e25d811aa7bff4163eef652b19c8b5f2b775464e..119b66a4239909a71b947619a287224e053c9310`.
  Reviewer did not implement, own, or previously review the epic. They
  independently checked code, raw runtime artifacts, and ZIP contents,
  confirmed all R1-AC1–AC8 and DoD, the invalid/blocked/partial fix, and the
  justified carry-forward. Non-blocking notes (low): five Plugin Check
  warnings with errors=0; hosted CI was not run and is needed for a separate
  future PR. No hosted check was claimed successful. Diagnosis checks address
  structure, not chat existence or actual delivery. No acceptance decision
  was needed. QA completed without descendants/commands; native session was
  archived normally, exit 0.
- Root completed R1.1–R1.3 on `release/abilities-readonly`. Implementation,
  checks, and independent acceptance were all accepted; remaining work was
  separate release actions and design of the other four abilities, which were
  outside R1. Final product/harness
  `542f7ab9e41bb53228331d71264bfa8ea6a751cb`,
  `dist/cf7-telegram-wp-plugin.zip` version 1.2.1, SHA-256
  `786807309ac1e62f024d371bafe00b545f0c3b746c097d212d253342cecd9d9c`.
  Complete commands, results, and exact carried-forward evidence boundaries
  are in the two ledgers above. No push/master merge/version bump/publication;
  final commit changed only this checkpoint and the existing backlog summary.
- Documentation follow-up: the user requested English for the description of
  [draft PR #72](https://github.com/hokoo/cf7-telegram/pull/72) and all epic docs.
  Root updated the PR description; worker `/root/abilities_docs_english`, native
  `01a1886d-c535-7f00-a124-dca5ab9bdc08`, translated this document from baseline
  `a64b1fa`. Root accepted the translation after checking section/table/fence
  counts, unchanged hashes/UUIDs/links and executable examples, resolved relative
  links, no remaining Cyrillic prose, and `git diff --check`. Existing English
  user documentation needed no translation. The worker stopped and was archived
  normally, exit 0. This follow-up changes documentation only; product code,
  contracts, historical evidence, and the independent QA verdict are unchanged.
