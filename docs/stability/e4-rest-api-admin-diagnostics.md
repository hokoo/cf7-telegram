# Epic 4 REST API, Admin UI And Diagnostics

Status: completed

Release line: `1.0.13`

## Outcome

The settings screen behaves predictably under partial REST failures and does not
change plugin data as a side effect of frontend rendering. REST mutations are
explicit, paginated data loading covers all plugin-owned entity types, and
diagnostics are categorized and redacted.

## Delivered Scope

- Bot sync mutations are POST-first and validated on the server. Deprecated GET
  compatibility remains explicit instead of silently mutating state.
- Admin API pagination covers bots, chats, channels, and forms instead of
  relying on WordPress REST defaults.
- React bootstrap state handles per-resource loading, partial failures, and
  retry-only-failed behavior.
- Bot token state is no longer mutated during render.
- Browser compatibility no longer depends on `String.prototype.toWellFormed`.
- Plugin styles are scoped to the plugin container.
- WordPress admin notices are not globally hidden; only unrelated system notices
  are hidden on the plugin page when the plugin UI needs the full admin surface.
- Telegram update transport timeouts are treated as transient polling failures
  and do not put the bot card into a persistent error state.
- REST, migration, sync, and delivery diagnostics are categorized and redacted.

## Task Traceability

| Project Item | Status | Evidence |
| --- | --- | --- |
| E4.1 Backend REST mutation contract | completed | `plugin-dir/lib/Controllers/RestApi/BotController.php`, `plugin-dir/tests/RestBotControllerTest.php` |
| E4.2 React API pagination and error contract | completed | `plugin-dir/react/src/utils/api.js`, `plugin-dir/react/src/utils/api.test.js` |
| E4.3 Bootstrap loading, partial failure and retry | completed | `plugin-dir/react/src/App.js`, `plugin-dir/react/src/App.test.js` |
| E4.4 Bot view render and browser compatibility | completed | `plugin-dir/react/src/components/Bot*.js`, related React tests |
| E4.5 Mutation sequencing and client state safety | completed | React component/API tests and real WordPress REST smoke |
| E4.6 Scope WordPress admin styles and notices | completed | `plugin-dir/react/src/App.scss`, `tests/stability/wp-e4-rest-ui-smoke.php` |
| E4.7 Safe REST, migration, sync and delivery diagnostics | completed | logger/redaction tests and integration smoke evidence |
| E4.8 Integrate and verify Epic 4 | completed | `tests/stability/e4-rest-ui-smoke.sh` |
| QA E4 Independent REST and UI verification | completed | Project QA record |

## Verification

The E4 closure evidence is the combination of:

- PHP unit/regression tests through `php tests/run.php`;
- React unit/component tests through `CI=true npm test -- --watchAll=false --runInBand`;
- real WordPress REST/admin smoke through `tests/stability/e4-rest-ui-smoke.sh`;
- Project E4 independent QA record with no accepted blocking defects.

## Residual Ownership

- Full public Contact Form 7 submit-to-Telegram browser E2E is intentionally not
  part of E4; it belongs to E6.
- Live Telegram network coverage remains out of scope for the stabilization
  gates.
- Suite-wide test layout cleanup belongs to E7.
