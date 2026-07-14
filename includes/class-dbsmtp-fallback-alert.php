<?php
/**
 * Уведомление администратора через PHP mail() при сбое SMTP.
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
        $settings = $this->settings->get_settings();
        $recipient = $this->get_recipient_email($settings);

        if ($recipient === '') {
            return;
        }

        $data         = $error->get_error_data();
        $failed_to    = is_array($data) && isset($data['to']) ? $data['to'] : '';
        $failed_subj  = is_array($data) && isset($data['subject']) ? (string) $data['subject'] : '';
        $error_text   = $error->get_error_message();

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

        $sent = wp_mail($recipient, $subject, $body);

        self::$is_sending = false;

        if ($sent) {
            $cooldown = $this->get_cooldown_minutes($settings);
            set_transient(self::TRANSIENT_COOLDOWN, time(), $cooldown * MINUTE_IN_SECONDS);
        }
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
