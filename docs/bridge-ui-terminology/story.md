# Bridge UI Terminology Story

## Source

User decision: rename the user-facing `Channel` concept to `Bridge` so users do
not confuse the plugin entity with Telegram channels.

## Goal

Make the administration UI and public usage guide describe the routing entity as
a `Bridge`, while preserving the current internal implementation contract.

## Audience

- WordPress administrators configuring Message Bridge for Contact Form 7 and
  Telegram.
- Maintainers who need to understand that this is a copy/UI terminology change,
  not a data-model refactor.

## Product Language

- User-facing singular: `Bridge`
- User-facing plural: `Bridges`
- Default created item title: `Bridge Name`
- Internal implementation term: `Channel`

## Success Criteria

- The plugin UI presents the entity list as `Bridges`.
- The UI explains that at least one bridge is required to run the integration.
- The UI explains that multiple bridges can be created to route different forms
  to different sets of Telegram recipients.
- The Bots section explains that at least one Telegram bot is required and links
  to Telegram's bot creation guide.
- Bots and Bridges helper text containers have matching visual height.
- Buttons, empty states, confirmations, and user-visible errors use `bridge`
  instead of `channel` when referring to this plugin entity.
- The usage guide explains bridge creation and bridge configuration.
- PHP classes, JavaScript component names, variables, REST routes, test IDs,
  CSS classes, CPT names, relation names, and stored database entities remain
  unchanged.

## Scope

- React administration screen copy.
- Small React administration screen layout additions under the `Bots` and
  `Bridges` headings.
- User-facing setup instructions in `plugin-dir/readme.txt`.
- Focused verification for visible copy and existing React behavior.

## Out Of Scope

- Renaming `Channel.php`, React `Channel*` components, class names, functions,
  variables, CSS selectors, data attributes, or test IDs.
- Renaming REST route keys or endpoints such as `channels` and `cf7tg_channel`.
- Renaming relation names such as `bot2channel`, `chat2channel`, or
  `form2channel`.
- Database migration or CPT rename.
- Historical changelog rewriting.
- WordPress.org release packaging.

## Constraints

- The change must be low risk and copy-focused.
- Existing unit and e2e selectors should continue to work.
- Documentation must make the UI term clear without hiding the internal
  compatibility decision from maintainers.

## Recommended Execution Order

1. Update React UI copy and heading helper text.
2. Update the user-facing guide.
3. Run focused verification and search for remaining user-visible references.
