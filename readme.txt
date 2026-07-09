=== DevBrothers SMTP ===
Contributors: lzolotarev
Tags: smtp, email, telegram, wp_mail, mail
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 1.2.0
Requires PHP: 7.4
Requires Plugins: devbrothers-admin-panel
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress emails through your SMTP server, with a delivery log and optional Telegram copies of messages.

== Description ==

**DevBrothers SMTP** routes the standard WordPress mailer (`wp_mail`) through your own SMTP server instead of PHP `mail()`. It configures the PHPMailer instance bundled with WordPress on the `phpmailer_init` hook, so every plugin and theme that calls `wp_mail()` benefits automatically.

Optionally, you can also send a plain-text copy of each email to a Telegram chat (success and/or failure). Telegram notifications are disabled by default and only run when you enable them and provide a bot token and chat ID.

= Key Features =

* **One-click presets** — Yandex Mail and Mail.ru (host, port, SSL) filled in automatically
* **Custom SMTP** — manual host, port, TLS or SSL, optional authentication
* **Custom sender** — optional From email and From name, with a force option
* **Test email** — one-click send to verify SMTP settings
* **Delivery log** — last 200 messages with sent/failed status and error text
* **Telegram copies** — optional plain-text copy of emails to a Telegram chat (opt-in)
* **Secure secrets** — optional `DBSMTP_PASSWORD`, `DBSMTP_TELEGRAM_BOT_TOKEN`, and `DBSMTP_TELEGRAM_CHAT_ID` constants in `wp-config.php`
* **DevBrothers Admin Panel** — settings inside the unified DevBrothers interface

= Requirements =

* WordPress 5.8+
* PHP 7.4+
* [DevBrothers Admin Panel](https://wordpress.org/plugins/devbrothers-admin-panel/) (required dependency)

== Installation ==

1. Install and activate **DevBrothers Admin Panel** from WordPress.org (or install it when prompted).
2. Install and activate **DevBrothers SMTP**.
3. Go to **DevBrothers → SMTP**.
4. Choose **Yandex Mail**, **Mail.ru**, or **Custom server**, enter your credentials, and save.
5. Send a test email to verify the configuration.
6. (Optional) Open the **Telegram** section, enable notifications, enter bot token and Chat ID, then send a Telegram test.

== External services ==

This plugin connects only to services that **you** configure. No data is sent to DevBrothers.

= Your SMTP server =

When SMTP delivery is enabled, WordPress emails (recipients, subject, body, attachments, and headers) are transmitted to the host, port, and account you specify. The plugin does not connect to a fixed third-party email API — you choose the server (for example Yandex Mail, Mail.ru, your hosting provider, or another SMTP service).

Authentication credentials (username and password) are stored in your WordPress database, or in the `DBSMTP_PASSWORD` constant in `wp-config.php`. They are used only to connect to your configured SMTP server.

* Service provider: defined by you (the site administrator)
* Terms and privacy: depend on your chosen SMTP provider

= Telegram Bot API (optional) =

When Telegram notifications are enabled, the plugin sends a plain-text summary of the email (site name, URL, From, To, subject, body text, and optional error message) to the Telegram Bot API endpoint `https://api.telegram.org/bot{token}/sendMessage`.

Data is sent only when:

* the Telegram toggle is enabled in settings, and
* a bot token and Chat ID are configured (in settings or via `wp-config.php` constants), and
* the corresponding notify option (success and/or failure) is enabled.

Bot token and Chat ID are stored in your WordPress database, or in the `DBSMTP_TELEGRAM_BOT_TOKEN` / `DBSMTP_TELEGRAM_CHAT_ID` constants in `wp-config.php`. They are used only to call the Telegram Bot API.

* Service provider: Telegram FZ-LLC
* Terms: https://telegram.org/tos/bot-developers
* Privacy: https://telegram.org/privacy

= Local delivery log =

The plugin stores a log of sent and failed messages (recipient, subject, status, error text) in a database table on your site. This data is **not** transmitted to DevBrothers or any external service.

== Frequently Asked Questions ==

= How do I set up Yandex Mail? =

Select **Yandex Mail** in the provider dropdown. Use your full Yandex email as the username. If two-factor authentication is enabled, create an app password in your Yandex ID settings. The From address should match your mailbox.

= How do I set up Mail.ru? =

Select **Mail.ru** in the provider dropdown. Use your full Mail.ru email as the username. If two-factor protection is enabled, use an app-specific password. The From address should match your mailbox.

= How do I enable Telegram copies of emails? =

1. Create a bot with [@BotFather](https://t.me/BotFather) and copy the bot token.
2. Start a chat with the bot (send `/start`), or add it to a group/channel.
3. Get your Chat ID (for example via `getUpdates` or a helper bot such as `@userinfobot`).
4. In **DevBrothers → SMTP → Telegram**, enable notifications, paste the token and Chat ID, choose success and/or failure, and save.
5. Click **Send Telegram test**.

= Where do I store secrets securely? =

You can define constants in `wp-config.php` instead of storing values in the database:

`define('DBSMTP_PASSWORD', 'your-smtp-password');`
`define('DBSMTP_TELEGRAM_BOT_TOKEN', '123456:ABC...');`
`define('DBSMTP_TELEGRAM_CHAT_ID', '123456789');`

When a constant is defined, the matching field in the settings UI is hidden and the constant value is used.

= Does Telegram receive the full email body? =

Yes, when enabled. The plugin sends a plain-text version (HTML tags stripped) truncated to Telegram’s message length limit. Attachments are not uploaded — only the attachment count is mentioned.

= My emails go to spam. What should I check? =

Make sure your sending domain has valid SPF, DKIM and DMARC records, and that the From address matches your SMTP account.

= Why is DevBrothers Admin Panel required? =

DevBrothers SMTP registers its settings page inside the DevBrothers admin UI. Install Admin Panel from WordPress.org first.

= Does this plugin work when SMTP is disabled? =

Yes. When the SMTP toggle is off, WordPress uses its default mail transport and this plugin does not alter PHPMailer settings. Telegram notifications can still run independently if enabled.

== Screenshots ==

1. SMTP connection settings with provider presets
2. Telegram notification settings
3. Test email sending
4. Delivery log

== Changelog ==

= 1.2.0 =
* Optional Telegram copies of sent/failed emails (opt-in)
* Secure storage helpers for Telegram bot token and Chat ID via wp-config.php
* Bot token is not echoed back in the settings form (same pattern as SMTP password)
* Documented Telegram Bot API under External services

= 1.1.0 =
* One-click presets for Yandex Mail and Mail.ru
* Removed unencrypted SMTP option (only TLS and SSL)
* All core features remain free

= 1.0.0 =
* Initial release
* SMTP configuration for wp_mail via PHPMailer
* Test email and delivery log (last 200 entries)
* Integration with DevBrothers Admin Panel

== Upgrade Notice ==

= 1.2.0 =
Adds optional Telegram email copies. Review the Telegram settings and External services notes before enabling.

= 1.1.0 =
Adds Yandex and Mail.ru presets. Unencrypted SMTP is no longer available.

= 1.0.0 =
Initial release.
