=== Message Bridge for Contact Form 7 and Telegram ===
Contributors: hokku
Donate link: https://www.paypal.me/igortron
Tags: contact form telegram,contact form 7,telegram
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Deliver Contact Form 7 submissions to Telegram instantly via a bot.

== Description ==

This plugin lets you send Contact Form 7 messages to Telegram chats via a bot. Setup takes just a few steps:

1. Create a Telegram bot ([how to](https://core.telegram.org/bots#3-how-do-i-create-a-bot "Telegram docs")).
2. Create a bot in the plugin UI.
3. Paste the bot token into the bot form (PHP constants also available).
4. Create a channel in the plugin UI — it links your Contact Form 7 forms to Telegram chats.
5. Add users to the subscriber list by sending the <code>/cf7tg_start</code> command to your bot. To add a group, first add the bot to the group, then send <code>/cf7tg_start</code> in that group.
6. Approve or decline subscription requests on the Contact Form 7 → CF7 Telegram settings page.
7. Configure the channel: choose which forms to send messages from.

= Hooks and constants =

Filter <code>wpcf7tg_skip_tg</code>
Use it to skip sending a Telegram message.

Filter <code>wpcf7tg_markdown</code>
Use it to customize the allowed Markdown tags.

This plugin uses [API Telegram](https://core.telegram.org/api "Telegram docs") and sends remote HTTP requests to Telegram servers to deliver notifications.

== Changelog ==

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