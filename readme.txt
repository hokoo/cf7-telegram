=== Contact Form 7 + Telegram ===
Contributors: hokku
Donate link: https://www.paypal.me/igortron
Tags: contact form telegram,contact form 7,telegram
Requires at least: 4.7
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 0.8.6
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

This plugin allows to post CF7-messages to you through Telegram-bot. Just use shortcode [telegram] in your CF7-form.

== Description ==

This plugin allows to send Contact Form 7 messages to your Telegram-chat. For this you need to make several simple steps.

1. Create the Telegram-Bot and save the Bot-Token parameter on the settings page Contact Form 7 - CF7 Telegram or to <code>WPFC7TG_BOT_TOKEN</code> constant.
2. Paste the shortcode <code>[telegram]</code> in your contact form template for activate sending to Telegram.

Now you can to add users or group to subscriber list. 
To add a user send the <code>/cf7tg_start</code> command to your bot.
To add a group add your bot to the group and send the <code>/cf7tg_start</code> command to your group.

After this, you will see the requests on the Contact Form 7 - CF7 Telegram settings page. Approve or decline them.

= Hooks and constants =

Filter <code>wpcf7tg_skip_tg</code>.
Use for skipping sending message.

Filter <code>wpcf7tg_markdown</code>.
Use for customizing markdown tag set.

Constant <code>WPFC7TG_BOT_TOKEN</code>.
Use for define the bot token value in the program files.

This plugin uses [API Telegram](https://core.telegram.org/api "Telegram docs") and makes remote HTTP-requests to Telegram servers for sending your notifications.

== Frequently Asked Questions ==

= How to create the Telegram-Bot? =

It is very simple. Please, follow to  [official documentation](https://core.telegram.org/bots#3-how-do-i-create-a-bot "Telegram docs").

= What is Chat ID & how to get it? =

The Chat ID parameter is your Telegram-identifier. But this is not your phone number or Telegram-login (@xxxxxxxx). 
You can see your Chat ID by typing anything to Telegram-Bot <code>@wpcf7Bot</code>.

== Changelog ==
= 0.8.6 =
* CVE-2024-9629 got fixed.
* New filter (`wpcf7tg_manage_chats_cap`) added for setting the user capability to manage chats.

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

