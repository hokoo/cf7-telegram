# E1. Bridge UI Copy And Guide

Outcome: The plugin presents the routing entity as `Bridge` to users while the
existing `Channel` implementation remains intact.

Scope:
- Replace user-visible `Channel`/`Channels` wording in the React administration
  UI with `Bridge`/`Bridges` where it refers to the plugin routing entity.
- Add concise helper text below the `Bridges` heading explaining that at least
  one bridge is required and additional bridges can separate forms by
  recipients.
- Update `plugin-dir/readme.txt` setup guidance to use bridge terminology.
- Verify that no internal API, database, selector, or class rename is included.

Out of Scope:
- PHP class, CPT, REST, relation, selector, variable, file, and database renames.
- Full design refresh of the administration screen.
- Translation catalog regeneration unless the release process explicitly
  requires it later.
- Historical changelog copy updates.

Success Criteria:
- Users see `Bridges` as the entity heading in the admin UI.
- Users see a short explanation under the heading that integration requires at
  least one bridge and multiple bridges can split forms across recipients.
- Creating a new item uses `Create Bridge` and `Bridge Name`.
- User-visible empty states, confirmations, and errors refer to `bridge`.
- The public usage guide uses bridge terminology for setup and configuration.
- Existing technical contracts still use the current `channel` identifiers.

Dependencies:
- Product decision to use `Bridge` as the user-facing term.
- Current React administration UI source under `plugin-dir/react/src`.
- Current public guide in `plugin-dir/readme.txt`.

Risks/Open Questions:
- Some `channel` strings are intentionally technical and should not be changed.
- Existing tests may assert old visible copy and need narrow updates.
- Translation files may need a later release-process task if they are generated
  manually outside this repository workflow.

Tasking Guidance:
- Re-run `$decompose-work` on this epic if scope changes beyond UI copy and
  public guide updates.
- Produce execution tasks using Status, Goal, Scope, Out of Scope, DoR, DoD,
  AC, Dependencies, and Notes/Risks.
- Assign `needs_design`, `waiting_dependency`, or `todo` based on actual
  readiness; do not mark work `todo` until DoR is satisfied.

## Execution Tasks

### T1. Update React Bridge UI Copy
Status: completed

Goal: Make the administration screen use `Bridge` terminology for the
user-facing routing entity.

Scope:
- Update visible React strings in `plugin-dir/react/src/App.js`,
  `plugin-dir/react/src/components/NewChannel.js`,
  `plugin-dir/react/src/components/Channel.js`,
  `plugin-dir/react/src/components/ChannelView.js`, and any directly related
  tests that assert visible copy.
- Change the heading from `Channels` to `Bridges`.
- Add helper text below the `Bridges` heading:
  `A bridge is required to run the integration. Create at least one bridge, and add more when different forms should send messages to different sets of Telegram recipients.`
- Change creation copy to `Create Bridge` and default title to `Bridge Name`.
- Change user-visible empty states, confirmations, and errors to refer to
  `bridge`.

Out of Scope:
- File, component, function, variable, CSS class, data-testid, route, relation,
  CPT, or database renames.
- Visual redesign beyond minimal styling needed for the helper text.
- Generated build artifacts unless the existing project workflow requires them.

DoR:
- The `Bridge` term is approved for user-facing copy.
- Internal `Channel` compatibility boundary is accepted.

DoD:
- React UI source contains the approved bridge copy.
- Existing internal identifiers remain unchanged.
- Relevant tests are updated only where they assert changed visible text.
- Focused React verification runs or any inability to run it is documented.

AC:
- Given the admin UI renders, when the routing entity list is shown, then its
  heading is `Bridges`.
- Given the admin UI renders, when the `Bridges` section is shown, then helper
  text explains that at least one bridge is required and multiple bridges can
  route different forms to different sets of Telegram recipients.
- Given the user creates a new routing entity, when the create button is shown,
  then it says `Create Bridge`.
- Given a new routing entity is created, when its default title is set, then the
  visible default title is `Bridge Name`.
- Given user confirmations, empty states, and errors refer to this routing
  entity, then they use `bridge`, not `channel`.
- Given internal identifiers are inspected, when routes/selectors/classes are
  searched, then existing `channel` contracts remain in place.

Dependencies:
- Product terminology decision from this story.

Notes/Risks:
- Search results for `channel` will remain high because internal names are
  intentionally preserved.
- Tests should not be broadened into selector or API contract changes.

Verification:
- `rg -n "Channel|Channels|channel" plugin-dir/react/src --glob "!*.test.js"`
  with manual classification of remaining internal-only matches.
- `cd plugin-dir/react && npm test -- --watchAll=false` if the local npm
  environment is available.

### T2. Update Bridge Usage Guide
Status: completed

Goal: Align the public setup guide with the new `Bridge` UI terminology.

Scope:
- Update current setup steps in `plugin-dir/readme.txt` so users are instructed
  to create and configure a bridge.
- Explain that a bridge links Contact Form 7 forms, a Telegram bot, and Telegram
  recipients.
- Preserve historical changelog entries unless a release owner explicitly asks
  to rewrite them.

Out of Scope:
- Readme release metadata changes.
- Changelog rewrite.
- WordPress.org asset updates.
- Translation catalog regeneration.

DoR:
- T1 UI copy is implemented or the final UI wording is otherwise locked.

DoD:
- Setup instructions use `bridge` terminology consistently.
- The guide remains accurate for the current UI flow.
- Historical changelog entries are preserved.

AC:
- Given a new user reads setup steps, when they reach the routing entity step,
  then they are told to create a bridge in the plugin UI.
- Given a user reads the configuration step, when they see form routing
  guidance, then it says to configure the bridge.
- Given maintainers review the diff, when they inspect changelog history, then
  past version entries are not rewritten.

Dependencies:
- T1 final UI wording.

Notes/Risks:
- `plugin-dir/readme.txt` is public-facing; wording should stay concise and
  avoid internal implementation terms.

Verification:
- `rg -n "\\b[Cc]hannels?\\b|\\bchannel\\b" plugin-dir/readme.txt`
  with expected remaining matches limited to historical changelog entries if any.

### T3. Verify Bridge Terminology Scope
Status: completed

Goal: Confirm the copy update is complete and has not expanded into an internal
rename.

Scope:
- Inspect `git diff` for unintended internal renames.
- Run focused search for remaining user-visible `Channel` wording.
- Run React tests when the local environment supports them.
- Record skipped checks and residual risk if an environment dependency is
  unavailable.

Out of Scope:
- Full browser e2e campaign.
- WordPress release ZIP validation.
- Database migration testing.

DoR:
- T1 is implemented.
- T2 is implemented.

DoD:
- Diff contains only scoped UI copy, minimal styling, docs, and test assertion
  updates if needed.
- Verification command results are recorded in the delivery report.
- Any remaining `channel` wording is classified as internal or historical.

AC:
- Given the diff is inspected, when file paths are reviewed, then no PHP class,
  CPT, REST route, relation, database, selector, or variable rename is present.
- Given search is run across React user-facing source and guide docs, when
  remaining `channel` matches are reviewed, then no unintended user-visible
  routing-entity copy remains.
- Given tests can run locally, when React tests complete, then they pass.
- Given tests cannot run locally, when delivery is reported, then the exact
  skipped command and reason are stated.

Dependencies:
- T1 React copy update.
- T2 guide update.

Notes/Risks:
- Full UI rendering still depends on the WordPress admin environment and is out
  of this focused terminology task unless requested separately.

Verification:
- `git diff -- plugin-dir/react/src plugin-dir/readme.txt docs/bridge-ui-terminology`
- `rg -n "\\b[Cc]hannels?\\b|\\bchannel\\b" plugin-dir/react/src plugin-dir/readme.txt --glob "!*.test.js"`
- `cd plugin-dir/react && npm test -- --watchAll=false`
