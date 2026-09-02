<?php
/**
 * Уведомление администратора при сбое SMTP.
 *
 * Письмо уходит напрямую через PHP mail(), минуя wp_mail() и SMTP.
 * Если mail() недоступен или не сработал, а Telegram включён — дубль в Telegram.
 *
 * @package DevBrothers_SMTP
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBSMTP_Fallback_Alert {

    const TRANSIENT_COOLDOWN = 'dbsmtp_fallback_alert_cooldown';
    const SUBJECT_PREFIX     = '[DevBrothers SMTP]';

    /**
     * @var bool
     */
    private static $is_sending = false;

    /**
     * @var DBSMTP_Settings
     */
    private $settings;

    /**
     * @param DBSMTP_Settings $settings
     */
    public function __construct(DBSMTP_Settings $settings) {
        $this->settings = $settings;
        add_action('wp_mail_failed', [$this, 'on_mail_failed'], 20, 1);
    }

    /**
     * @return bool
     */
    public static function is_sending() {
        return self::$is_sending;
    }

    /**
     * PHP mail() существует и не в disable_functions.
     *
     * @return bool
     */
    public static function is_php_mail_available() {
        if (!function_exists('mail')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('mail', $disabled, true);
    }

    /**
     * @param WP_Error $error
     */
    public function on_mail_failed($error) {
        if (self::$is_sending || !is_wp_error($error)) {
            return;
        }

        $settings = $this->settings->get_settings();

        if (empty($settings['enabled']) || empty($settings['host']) || empty($settings['fallback_alert_enabled'])) {
            return;
        }

        $data    = $error->get_error_data();
        $subject = is_array($data) && isset($data['subject']) ? (string) $data['subject'] : '';

        if ($subject !== '' && strpos($subject, self::SUBJECT_PREFIX) === 0) {
            return;
        }

        if ($this->is_on_cooldown()) {
            return;
        }

        $this->send_alert($error);
    }

    /**
     * @return bool
     */
    private function is_on_cooldown() {
        return (bool) get_transient(self::TRANSIENT_COOLDOWN);
    }

    /**
     * @param WP_Error $error
     */
    private function send_alert($error) {
        $settings  = $this->settings->get_settings();
        $recipient = $this->get_recipient_email($settings);

        if ($recipient === '') {
            return;
        }

        $data        = $error->get_error_data();
        $failed_to   = is_array($data) && isset($data['to']) ? $data['to'] : '';
        $failed_subj = is_array($data) && isset($data['subject']) ? (string) $data['subject'] : '';
        $error_text  = $error->get_error_message();

        if (is_array($failed_to)) {
            $failed_to = implode(', ', array_map('strval', $failed_to));
        }

        $subject = self::SUBJECT_PREFIX . ' ' . __('Ошибка отправки через SMTP', 'devbrothers-smtp');

        $body = sprintf(
            "%s\n\n%s: %s\n%s: %s\n%s: %s\n%s: %s\n\n%s: %s\n\n%s: %s\n\n---\n%s",
            __('На сайте не удалось отправить письмо через настроенный SMTP-сервер.', 'devbrothers-smtp'),
            __('Сайт', 'devbrothers-smtp'),
            get_bloginfo('name'),
            __('URL', 'devbrothers-smtp'),
            home_url('/'),
            __('Время', 'devbrothers-smtp'),
            wp_date('d.m.Y H:i:s'),
            __('SMTP-хост', 'devbrothers-smtp'),
            isset($settings['host']) ? $settings['host'] : '',
            __('Ошибка SMTP', 'devbrothers-smtp'),
            $error_text,
            __('Исходное письмо', 'devbrothers-smtp'),
            __('Кому', 'devbrothers-smtp') . ': ' . ($failed_to !== '' ? $failed_to : '—') . "\n" .
            __('Тема', 'devbrothers-smtp') . ': ' . ($failed_subj !== '' ? $failed_subj : '—'),
            __('Это служебное уведомление отправлено стандартной функцией PHP mail(), минуя SMTP.', 'devbrothers-smtp')
        );

        self::$is_sending = true;

        try {
            $mail_sent     = $this->send_via_php_mail($recipient, $subject, $body, $settings);
            $telegram_sent = $this->send_via_telegram($body);
            $status_parts  = [];

            $status_parts[] = $mail_sent
                ? __('PHP mail(): отправлено', 'devbrothers-smtp')
                : (self::is_php_mail_available()
                    ? __('PHP mail(): ошибка отправки', 'devbrothers-smtp')
                    : __('PHP mail(): функция недоступна на сервере', 'devbrothers-smtp'));

            if ($this->telegram_is_configured()) {
                $status_parts[] = $telegram_sent
                    ? __('Telegram: отправлено', 'devbrothers-smtp')
                    : __('Telegram: ошибка отправки', 'devbrothers-smtp');
            }

            $this->log_attempt(
                $recipient,
                $subject,
                ($mail_sent || $telegram_sent) ? DBSMTP_Logger::STATUS_SENT : DBSMTP_Logger::STATUS_FAILED,
                implode('; ', $status_parts)
            );

            // Cooldown after any attempt so a flood of SMTP errors does not hammer mail()/Telegram.
            $cooldown = $this->get_cooldown_minutes($settings);
            set_transient(self::TRANSIENT_COOLDOWN, time(), $cooldown * MINUTE_IN_SECONDS);
        } finally {
            self::$is_sending = false;
        }
    }

    /**
     * Direct PHP mail(), not wp_mail() — otherwise the global PHPMailer stays on SMTP
     * and the "fallback" letter is sent through the same broken connection.
     *
     * @param string $to
     * @param string $subject
     * @param string $body
     * @param array  $settings
     * @return bool
     */
    private function send_via_php_mail($to, $subject, $body, $settings) {
        if (!self::is_php_mail_available()) {
            return false;
        }

        $from_email = $this->get_from_email($settings, $to);
        if ($from_email === '') {
            return false;
        }

        $from_name = $this->get_from_name($settings);
        $headers   = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: ' . $this->format_from_header($from_name, $from_email),
        ];

        $header_str = implode("\r\n", $headers);
        $encoded_subject = $this->encode_header($subject);

        $sent = $this->call_mail($to, $encoded_subject, $body, $header_str, $from_email);

        if (!$sent) {
            $sent = $this->call_mail($to, $encoded_subject, $body, $header_str, '');
        }

        return $sent;
    }

    /**
     * @param string $to
     * @param string $subject
     * @param string $body
     * @param string $headers
     * @param string $envelope_from Empty to omit the 5th mail() argument.
     * @return bool
     */
    private function call_mail($to, $subject, $body, $headers, $envelope_from) {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.mail -- Intentional native mail() fallback when SMTP is down.
        if ($envelope_from !== '' && is_email($envelope_from) && stripos(PHP_OS, 'WIN') === false) {
            return (bool) @mail($to, $subject, $body, $headers, '-f' . $envelope_from);
        }

        return (bool) @mail($to, $subject, $body, $headers);
    }

    /**
     * @return bool
     */
    private function telegram_is_configured() {
        if (!function_exists('dbsmtp_plugin')) {
            return false;
        }

        $plugin = dbsmtp_plugin();
        if (!$plugin->telegram || !$plugin->telegram->is_enabled()) {
            return false;
        }

        return trim($plugin->telegram->get_bot_token()) !== ''
            && trim($plugin->telegram->get_chat_id()) !== '';
    }

    /**
     * @param string $body
     * @return bool
     */
    private function send_via_telegram($body) {
        if (!$this->telegram_is_configured()) {
            return false;
        }

        $plugin  = dbsmtp_plugin();
        $token   = trim($plugin->telegram->get_bot_token());
        $chat_id = trim($plugin->telegram->get_chat_id());

        $text = "⚠️ " . $body;
        $result = $plugin->telegram->send_message($token, $chat_id, $text);

        return $result === true;
    }

    /**
     * @param string $to
     * @param string $subject
     * @param string $status
     * @param string $message
     */
    private function log_attempt($to, $subject, $status, $message) {
        if (!function_exists('dbsmtp_plugin')) {
            return;
        }

        $logger = dbsmtp_plugin()->logger;
        if (!$logger instanceof DBSMTP_Logger) {
            return;
        }

        $logger->log($to, $subject, $status, $message);
    }

    /**
     * @param array  $settings
     * @param string $fallback
     * @return string
     */
    private function get_from_email($settings, $fallback = '') {
        $candidates = [
            isset($settings['from_email']) ? $settings['from_email'] : '',
            get_option('admin_email'),
            $fallback,
        ];

        foreach ($candidates as $candidate) {
            $email = sanitize_email((string) $candidate);
            if (is_email($email)) {
                return $email;
            }
        }

        return '';
    }

    /**
     * @param array $settings
     * @return string
     */
    private function get_from_name($settings) {
        $name = isset($settings['from_name']) ? trim((string) $settings['from_name']) : '';
        if ($name === '') {
            $name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        }
        return $name !== '' ? $name : 'WordPress';
    }

    /**
     * @param string $name
     * @param string $email
     * @return string
     */
    private function format_from_header($name, $email) {
        $name = str_replace(["\r", "\n"], '', $name);
        if ($name === '') {
            return $email;
        }

        return $this->encode_header($name) . ' <' . $email . '>';
    }

    /**
     * @param string $text
     * @return string
     */
    private function encode_header($text) {
        $text = str_replace(["\r", "\n"], '', $text);

        if (function_exists('mb_encode_mimeheader')) {
            return mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n");
        }

        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    /**
     * @param array $settings
     * @return string
     */
    private function get_recipient_email($settings) {
        $email = isset($settings['fallback_alert_email']) ? sanitize_email($settings['fallback_alert_email']) : '';

        if ($email !== '' && is_email($email)) {
            return $email;
        }

        $admin = sanitize_email(get_option('admin_email'));
        return is_email($admin) ? $admin : '';
    }

    /**
     * @param array $settings
     * @return int
     */
    private function get_cooldown_minutes($settings) {
        $allowed = [15, 30, 60, 120];
        $minutes = isset($settings['fallback_alert_cooldown']) ? (int) $settings['fallback_alert_cooldown'] : 30;

        if (!in_array($minutes, $allowed, true)) {
            $minutes = 30;
        }

        return $minutes;
    }
}
