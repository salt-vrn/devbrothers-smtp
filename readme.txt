=== DevBrothers SMTP ===
Contributors: lzolotarev
Tags: smtp, wp_mail, email, yandex, mail.ru
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 1.1.0
Requires PHP: 7.4
Requires Plugins: devbrothers-admin-panel
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WordPress emails through your own SMTP server, with one-click presets for Yandex and Mail.ru.

== Description ==

**DevBrothers SMTP** reroutes the standard WordPress mailer (`wp_mail`) through your own SMTP server instead of the unreliable PHP `mail()` function. It configures the PHPMailer instance bundled with WordPress on the `phpmailer_init` hook, so every plugin and theme that calls `wp_mail()` benefits automatically.

This plugin focuses on a simple, privacy-friendly setup for Russian mail providers and small sites. All core features are free — no premium tier, no upsells, no external email APIs.

= Key Features =

* **One-click presets** — Yandex Mail and Mail.ru (host, port, SSL) filled in automatically
* **Custom SMTP** — manual host, port, TLS or SSL, optional authentication
* **Custom sender** — optional From email and From name, with a force option
* **Test email** — one-click send to verify settings
* **Delivery log** — last 200 messages with sent/failed status and error text (free; many competitors charge for this)
* **Secure password** — optional `DBSMTP_PASSWORD` constant in `wp-config.php` instead of the database
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

== External services ==

This plugin sends email through an SMTP server that **you** configure in the settings. No email is sent to DevBrothers or any service we operate.

= Your SMTP server =

When SMTP delivery is enabled, WordPress emails (recipients, subject, body, attachments, and headers) are transmitted to the host, port, and account you specify. The plugin does not connect to a fixed third-party provider — you choose the server (for example Yandex Mail, Mail.ru, your hosting provider, or another SMTP service).

Authentication credentials (username and password) are stored in your WordPress database or in the `DBSMTP_PASSWORD` constant in `wp-config.php`. They are used only to connect to your configured SMTP server.

* Service provider: defined by you (the site administrator)
* Terms and privacy: depend on your chosen SMTP provider

= Local delivery log =

The plugin stores a log of sent and failed messages (recipient, subject, status, error text) in a database table on your site. This data is **not** transmitted to DevBrothers or any external service.

== Frequently Asked Questions ==

= How do I set up Yandex Mail? =

Select **Yandex Mail** in the provider dropdown. Use your full Yandex email as the username. If two-factor authentication is enabled, create an app password in your Yandex ID settings. The From address should match your mailbox.

= How do I set up Mail.ru? =

Select **Mail.ru** in the provider dropdown. Use your full Mail.ru email as the username. If two-factor protection is enabled, use an app-specific password. The From address should match your mailbox.

= Where do I store the SMTP password securely? =

You can define a constant in `wp-config.php` instead of storing the password in the database:

`define('DBSMTP_PASSWORD', 'your-smtp-password');`

When this constant is defined, the password field in the settings is hidden and the constant value is used.

= My emails go to spam. What should I check? =

Make sure your sending domain has valid SPF, DKIM and DMARC records, and that the From address matches your SMTP account.

= Why is DevBrothers Admin Panel required? =

DevBrothers SMTP registers its settings page inside the DevBrothers admin UI. Install Admin Panel from WordPress.org first.

= Does this plugin work when SMTP is disabled? =

Yes. When the SMTP toggle is off, WordPress uses its default mail transport and this plugin does not alter PHPMailer settings.

= Is everything really free? =

Yes. SMTP configuration, presets, test email, delivery log, and wp-config password support are included at no cost. There is no premium version.

== Screenshots ==

1. SMTP connection settings with provider presets
2. Test email sending
3. Delivery log

== Changelog ==

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

= 1.1.0 =
Adds Yandex and Mail.ru presets. Unencrypted SMTP is no longer available.

= 1.0.0 =
Initial release.
