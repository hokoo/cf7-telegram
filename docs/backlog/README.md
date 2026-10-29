# Backlog

This directory records accepted follow-up work that is not part of an active
implementation batch. Tasks use the repository status model and are not
commitments to a release.

## B2. WordPress Abilities API adaptation

Status: needs_design

Specification: [Abilities API](abilities-api.md), drafted 2026-10-29.
User-selected target scope: configuration discovery, static form diagnostics, bridge
test delivery, message preview, bridge configuration, and recent errors.
First independent stage: `get-configuration` and `diagnose-form`; implementation
plan R1.1–R1.3 is in the specification. It does not depend on unresolved questions
for the other abilities. R1 implementation was authorized on 2026-10-29 and
completed by root in `release/abilities-readonly`; R1.1 is accepted at `c393034`,
R1.2 at `c534c66`, and R1.3 with its destination repair at `137f308` (product `542f7ab`).
Local Core/REST/MCP and compatibility gates passed. Fresh independent QA accepted
`e25d811..119b66a` with `pass_with_notes`; R1-AC1–R1-AC8 and DoD are met.
Candidate ZIP and evidence are recorded in the specification checkpoint.
Version bump, hosted CI, push, merge and publication remain a separate release task.
The remaining four abilities retain `needs_design` and are outside this delivery.

## B1. Preserve legacy Telegram chat display names during migration

Status: needs_design

Priority: P2

Owner: unassigned

Source: manual clean-baseline upgrade from `0.8.1` to `1.1.0` on 2026-09-07.
The migration correctly created the bot, bridge, and form-to-bridge relations,
but the migrated chat display names were observed as lost.

Goal: preserve a readable legacy Telegram chat name in the migrated
`cf7tg_chat` record and in the admin UI.

Scope:

- Reproduce the loss with a clean `0.8.1` baseline containing private and
  group legacy chat rows.
- Trace legacy `wpcf7_telegram_chats` data through `LegacyImporter` and the
  `cf7tg_chat` persistence/UI paths.
- Define and implement the display-name precedence and fallback.
- Add regression coverage at the importer boundary and in the upgrade
  lifecycle characterization.

Out of Scope:

- WPML or any other multilingual-plugin compatibility.
- Form-to-bridge migration, bot naming, bridge naming, or Telegram API
  transport behaviour.
- Changing the legacy chat subscription/status contract unless required by the
  agreed display-name mapping.

DoR:

- A reproducible before/after snapshot identifies the legacy chat fields and
  the resulting `cf7tg_chat` fields/UI value.
- The owner approves the display-name precedence:
  group title; otherwise private first name plus last name; otherwise username;
  otherwise Telegram chat ID.
- The task has a clean migration baseline with no `cf7tg_*` migration state,
  modern entities, or modern relations.

DoD:

- Migrated private and group chats retain their expected readable display
  names in storage and in the admin UI.
- The fallback is used only when no preferred legacy name is available.
- Re-running migration does not replace an already correct migrated name.
- Focused and lifecycle regression tests pass.

AC:

- Given a legacy private chat with `first_name` and `last_name`, when it is
  migrated from `0.8.1`, then its new chat record and UI display their combined
  name.
- Given a legacy group chat with `title`, when it is migrated, then its new
  chat record and UI display that title.
- Given a legacy chat without a title or personal name but with `username`,
  when it is migrated, then the username is displayed; when username is also
  absent, the Telegram chat ID is displayed.
- Given a completed migration, when the migration is invoked again, then the
  chat display name remains unchanged.

Dependencies:

- Owner decision on the display-name precedence in DoR.

Notes/Risks:

- The manual observation establishes the symptom but not yet whether name data
  is lost while importing, stored under a different field, or omitted only by
  the admin UI.
- Keep this work independent from the already verified legacy form-relation
  migration.
