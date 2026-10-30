=== Message Bridge for Contact Form 7 and Telegram ===
Contributors: hokku, igortron
Donate link: https://www.paypal.me/igortron
Tags: contact form telegram,contact form 7,telegram
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Deliver Contact Form 7 submissions to Telegram instantly via a bot.

== Description ==

This plugin lets you send Contact Form 7 messages to Telegram chats via a bot. Setup takes just a few steps:

1. Create a Telegram bot ([how to](https://core.telegram.org/bots#3-how-do-i-create-a-bot "Telegram docs")).
2. Create a bot in the plugin UI.
3. Paste the bot token into the bot form (PHP constants also available).
4. Create a bridge in the plugin UI. A bridge links Contact Form 7 forms, a Telegram bot, and one or more Telegram recipients.
5. Keep the Contact Form 7 → CF7 Telegram settings page open while sending the bot's displayed <code>/cf7tg_start@BotName</code> command as a new standalone message. The page checks Telegram for new recipients. To add a group, first add the bot to the group, then send the command there. To add a forum topic, send it inside that topic. The <code>@BotName</code> suffix addresses the correct bot when Telegram group privacy mode is enabled. The short <code>/cf7tg_start</code> command is still supported when Telegram delivers it to the bot; editing an existing message does not register a recipient.
6. Approve or decline the subscription requests that appear on the settings page.
7. Configure the bridge: choose which forms it handles and which Telegram recipients should receive those messages. After approving a topic, select it in each desired bridge; approving it alone does not assign it to bridges. Create at least one bridge to run the integration, and add more when different forms should send messages to different sets of Telegram recipients.

**The integration is configured correctly when the bot panel is highlighted in blue and the bridge panel is highlighted in green.**

= Read-only Abilities API =

On WordPress 6.9 or newer, three read-only abilities expose saved configuration and sanitized recent log events without sending to Telegram: <code>cf7-telegram/get-configuration</code>, <code>cf7-telegram/diagnose-form</code>, and <code>cf7-telegram/get-recent-errors</code>. Older WordPress versions continue to deliver forms without the Abilities API. A logged-in user needs the existing CF7 Telegram settings capability to read configuration or diagnose a form, plus permission to read each involved form, bridge, bot, and recipient. Lists omit objects the user cannot read and count only visible items; diagnosis rejects a form when any existing object in its bounded route graph is hidden. Reading the shared log additionally requires <code>manage_options</code>; both capabilities are required. Anonymous users cannot run these abilities.

In PHP, after WordPress has initialized:

<code>wp_get_ability( 'cf7-telegram/get-configuration' )->execute( [ 'resource' => 'forms', 'page' => 1, 'per_page' => 50 ] );</code>
<code>wp_get_ability( 'cf7-telegram/diagnose-form' )->execute( [ 'form_id' => 123 ] );</code>
<code>wp_get_ability( 'cf7-telegram/get-recent-errors' )->execute( [ 'since' => 1761782400, 'until' => 1761868800, 'limit' => 20 ] );</code>

For authenticated REST clients, use GET with a JSON-encoded <code>input</code> query parameter. WordPress Application Passwords or normal cookie and REST nonce authentication work; the plugin adds no separate credential:

<code>GET /wp-json/wp-abilities/v1/abilities/cf7-telegram/get-configuration/run?input=%7B%22resource%22%3A%22bridges%22%2C%22page%22%3A1%7D</code>
<code>GET /wp-json/wp-abilities/v1/abilities/cf7-telegram/diagnose-form/run?input=%7B%22form_id%22%3A123%7D</code>
<code>GET /wp-json/wp-abilities/v1/abilities/cf7-telegram/get-recent-errors/run?input=%7B%22since%22%3A1761782400%2C%22until%22%3A1761868800%2C%22limit%22%3A20%7D</code>
<code>GET /wp-json/wp-abilities/v1/abilities/cf7-telegram/get-recent-errors/run?input=%7B%22since%22%3A1761782400%2C%22until%22%3A1761868800%2C%22limit%22%3A20%2C%22before_id%22%3A456%7D</code>

The <code>resource</code> is <code>forms</code>, <code>bridges</code>, <code>bots</code>, or <code>recipients</code>. Results use schema version 1 and pages of up to 100 visible items, ordered by WordPress ID. Recipient IDs are local WordPress IDs; a forum topic keeps its distinct <code>message_thread_id</code>. Bot token presence and cached online/offline status are saved-state observations only.

Recent errors accepts inclusive Unix UTC <code>since</code> and <code>until</code> bounds (default: the past 24 hours), <code>min_level</code> 0–3 (default: 1), and <code>limit</code> 1–100 (default: 20). Pages are ordered by descending log ID. Pass the returned <code>next_before_id</code> as <code>before_id</code> with the same time bounds to get the next page; <code>null</code> means there are no more matching rows. The example cursor 456 is illustrative. The response reports the current retention <code>days</code> and <code>max_rows</code>, including overrides from <code>WPCF7TG_LOG_RETENTION_DAYS</code>, <code>WPCF7TG_LOG_MAX_ROWS</code>, <code>cf7tg/logRetentionDays</code>, and <code>cf7tg/logMaxRows</code>. Reads do not prune the log. <code>history_complete</code> is always false: the shared log omits successes and may have lost older entries to retention. Events are <code>telegram_delivery_failed</code>, <code>bot_unreachable</code>, <code>bot_token_missing</code>, <code>bridge_bot_missing</code>, or <code>other</code>. Some bot-unavailable entries have level 0 and require <code>min_level: 0</code>; the default level 1 is not a complete error list. Unknown or malformed old entries have generic messages, and no raw log data is exposed.

The optional official WordPress MCP Adapter exposes these abilities through its default server. With that separate plugin installed, a local MCP client can run <code>wp mcp-adapter serve --server=mcp-adapter-default-server --user=admin</code> using STDIO. Discover the abilities with <code>mcp-adapter-discover-abilities</code>, inspect their schemas with <code>mcp-adapter-get-ability-info</code>, then call <code>mcp-adapter-execute-ability</code> with <code>{"ability_name":"cf7-telegram/diagnose-form","parameters":{"form_id":123}}</code>. The server applies the WordPress user's permissions. The adapter is not required for PHP or REST use; this plugin does not include an MCP server.

Diagnosis is static: <code>ready</code> means the saved route has eligible active recipients, <code>partial</code> means eligible and broken paths coexist, and <code>blocked</code> means no eligible path remains. <code>cached_bot_offline</code> is only a warning. <code>delivery_verified</code> is always false: diagnosis does not contact Telegram, submit a CF7 form, prove that a message arrived, change configuration, or repair a route. Existing form delivery remains the way to test end-to-end behavior.

= Hooks and constants =

Filter <code>wpcf7tg_skip_tg</code>
Use it to skip sending a Telegram message.

Filter <code>wpcf7tg_markdown</code>
Use it to customize the allowed Markdown tags.

Filter <code>wpcf7tg_sendMessage( $args, $chat_id, $mode )</code>
Use it to customize Telegram <code>sendMessage</code> arguments for each recipient and message chunk.

Action <code>wpcf7tg_message_sent( $args, $submission )</code>
Runs after each Telegram delivery attempt. A Telegram failure does not abort the Contact Form 7 submission.

Action <code>wpcf7tg_messages_sent( $list, $output, $mode, $submission )</code>
Runs after all configured recipients have been attempted.

Constant <code>WPCF7TG_LOG_RETENTION_DAYS</code> and filter <code>cf7tg/logRetentionDays</code>
Set the log retention period in days. The default is 30; set it to 0 to disable age-based pruning.

Constant <code>WPCF7TG_LOG_MAX_ROWS</code> and filter <code>cf7tg/logMaxRows</code>
Set the maximum number of newest log rows to retain. The default is 10000.

Constants <code>WPCF7TG_PING_INTERVAL</code> and <code>WPCF7TG_UPDATES_INTERVAL</code>
Set the offline bot status retry interval and the online Telegram update polling interval in milliseconds. The defaults are 5000 and 12000 respectively.

This plugin uses [API Telegram](https://core.telegram.org/api "Telegram docs") and sends remote HTTP requests to Telegram servers to deliver notifications.

== Screenshots ==

1. Telegram notifier settings after a successful setup: the bot is highlighted in blue and the bridge is highlighted in green.
2. Multiple Telegram recipients and multiple bridges connected to different Contact Form 7 forms.

== Changelog ==

= 1.2.1 =
- Send Contact Form 7 notifications to selected Telegram forum topics, with separate topic approval, bridge assignment, and delivery; preserve ordinary chat and General topic behavior.
- Show the bot-qualified discovery command and explain how to register a topic and assign it to bridges.
- Track Telegram topic names and let administrators set local recipient names or restore Telegram-provided names.
- Send notifications only to active recipients, and continue with other recipients when a topic delivery fails.
- Improve recipient actions with accessible icon buttons and keep bridge form choices in a top-aligned four-column layout.

= 1.2.0 =
- Restore the CF7 Telegram settings submenu in Contact Form 7 6.2 and later, while keeping it available with older Contact Form 7 versions.

= 1.1.0 =
- Add fake Telegram browser E2E coverage for form delivery, admin setup, partial delivery failures, and CI evidence collection.
- Rename user-facing Channel terminology to Bridge across the admin UI and guide, with bridge and bot setup guidance.
- Add chat display-name controls so administrators can set custom names and restore Telegram-provided names.

= 1.0.13 =
- Make Telegram bot actions POST-first and harden the administration interface against partial loading, pagination, and retry failures.
- Restore the full-page administration background, hide unrelated WordPress notices on the plugin screen, and improve diagnostics without exposing bot tokens or contact details.
- Ignore transient Telegram polling timeouts in the interface and use a 12-second default update polling interval.
- Add automatic 30-day and 10,000-row log retention with configurable limits.
- Verify the release ZIP across supported WordPress, PHP, and Contact Form 7 versions, including install, upgrade, uninstall, and rollback workflows.
- Add reproducible build, Plugin Check, dependency audit, artifact hygiene, browser canary, and manually approved WordPress.org promotion gates.

= 1.0.12 =
- Replace the Telegram SDK with a smaller WordPress HTTP integration and safer error handling.
- Validate bot tokens before saving them and preserve existing chat connections when the bot identity is unchanged.
- Make update polling webhook-aware and prevent failed updates from being skipped.
- Load every page of Telegram chats in the administration interface.
- Improve Unicode message chunking, plaintext fallback, multi-recipient delivery, and legacy hook compatibility.

= 1.0.11 =
- Make legacy migrations self-healing, retryable, and safe to run repeatedly.
- Preserve Contact Form 7 content while migrating bots, chats, channels, and form connections without duplicates.
- Keep bot tokens defined through PHP constants out of stored plugin data.
- Replace destructive load-time cleanup with conservative repair and recovery controls.

= 1.0.10 =
- Add maintenance cleanup for orphan chats and broken relations.
- Fix fatal error on CF7 submission when a channel has chats but no connected bot (#56).

= 1.0.9 =
- Remove external update controls.

= 1.0.8 =
- Harden Telegram chat sanitization and update polling.

= 1.0.7 =
- Prevent duplicate chat subscriptions when bot updates are fetched concurrently.

= 1.0.6 =
- Fix truncated bot name copy.

= 1.0.5 =
* Race condition in chats loading fixed.

= 1.0.4 =
* Minor fixes.

= 1.0.3 =
* Fix translation loading issue.

= 1.0.2 =
* Manual migration button added.
* Migration process improved.

= 1.0.1 =
* Fix react template.

= 1.0.0 =
* New UI for managing channels and bot token.
* New plugin name was chosen to comply with new WordPress guidelines.

= 0.10.0 =
* Beta testing is available for everyone.

= 0.9.3 =
* Preparing for beta testing.

= 0.9.2 =
* Loading textdomain fixed - moved to init hook.
* Array undefined key fixed.

= 0.9.1 =
* Version never released.

= 0.9 =
* Preparing to v1.0 seamless transition.

= 0.8.7 =
* API Telegram errors logging added.

= 0.8.6 =
* Security issue CVE-2024-9629 got fixed.

= 0.8.5 =
* PHP 7.2 compatibility fixed.

= 0.8.4 =
* Markdown symbols escaping got added in order to fix [an issue](https://github.com/hokoo/cf7-telegram/issues/17).

= 0.8.3 =
* Blueprint got added.

= 0.8.2 =
* Donation link got changed.
* tested up to WP 6.5

= 0.8.1 =
* Actualize add-on sale date.

= 0.8 =
* Addons available

= 0.7.10 =
* Preparing for attachment sending
* A few fixes

= 0.7.9 =
* Markdown for HTML-format issue

= 0.7.7 =
* Support WP 5.3 functions for WP before 5.3

= 0.7 =
* New interface recipient management
* Groups are supported
* WPCF7_ContactForm::prop( 'mail' ) instead WPCF7_ContactForm::$mail
* FIXED Dependence parse_mode by use_html property

= 0.6.2 =
* Trim for CHAT_ID field elements added

= 0.6.1 =
* Markdown bug fixed

= 0.6 =
* Message to telegram now sends on <code>wpcf7_before_send_mail</code> hook instead <code>wpcf7_mail_sent</code>. It is more reliable way. 
* <code>wpcf7tg_skip_tg</code> added.
* <code>wpcf7tg_markdown</code> added.
* <code>WPFC7TG_BOT_TOKEN</code> added.
* bugs fixed

= 0.5 =
* Markdown added

== Upgrade Notice ==

= 0.10.0 =
* ATTENTION! ⚡⚡⚡ Get v0.10 now to preserve your settings when you will be upgrading to v1.0.
* 🔥 Upgrading to v1.0 from v0.10 will be seamless.
* 😵 Upgrading to v1.0 from v0.8 and earlier will cause losing your settings.
* 😎 Early access to v1.0 is available for all users.
