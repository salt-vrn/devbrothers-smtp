<?php
/**
 * Уведомления в Telegram при отправке писем.
 *
 * @package DevBrothers_SMTP
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBSMTP_Telegram {

    const API_URL = 'https://api.telegram.org/bot';

    /**
     * @var DBSMTP_Settings
     */
    private $settings;

    /**
     * @param DBSMTP_Settings $settings
     */
    public function __construct(DBSMTP_Settings $settings) {
        $this->settings = $settings;

        add_action('wp_mail_succeeded', [$this, 'on_mail_succeeded'], 15, 1);
        add_action('wp_mail_failed', [$this, 'on_mail_failed'], 15, 1);
    }

    /**
     * @return bool
     */
    public function is_enabled() {
        $settings = $this->settings->get_settings();
        return !empty($settings['telegram_enabled']);
    }

    /**
     * @return string
     */
    public function get_bot_token() {
        if (defined('DBSMTP_TELEGRAM_BOT_TOKEN') && DBSMTP_TELEGRAM_BOT_TOKEN !== '') {
            return (string) DBSMTP_TELEGRAM_BOT_TOKEN;
        }

        $settings = $this->settings->get_settings();
        return isset($settings['telegram_bot_token']) ? (string) $settings['telegram_bot_token'] : '';
    }

    /**
     * @return string
     */
    public function get_chat_id() {
        if (defined('DBSMTP_TELEGRAM_CHAT_ID') && DBSMTP_TELEGRAM_CHAT_ID !== '') {
            return (string) DBSMTP_TELEGRAM_CHAT_ID;
        }

        $settings = $this->settings->get_settings();
        return isset($settings['telegram_chat_id']) ? (string) $settings['telegram_chat_id'] : '';
    }

    /**
     * @return bool
     */
    public function token_in_config() {
        return defined('DBSMTP_TELEGRAM_BOT_TOKEN') && DBSMTP_TELEGRAM_BOT_TOKEN !== '';
    }

    /**
     * @return bool
     */
    public function chat_id_in_config() {
        return defined('DBSMTP_TELEGRAM_CHAT_ID') && DBSMTP_TELEGRAM_CHAT_ID !== '';
    }

    /**
     * @param array $mail_data
     */
    public function on_mail_succeeded($mail_data) {
        if (!$this->is_enabled()) {
            return;
        }

        if (class_exists('DBSMTP_Fallback_Alert') && DBSMTP_Fallback_Alert::is_sending()) {
            return;
        }

        $settings = $this->settings->get_settings();
        if (empty($settings['telegram_notify_success'])) {
            return;
        }

        $this->notify($mail_data, 'success', '');
    }

    /**
     * @param WP_Error $error
     */
    public function on_mail_failed($error) {
        if (!$this->is_enabled()) {
            return;
        }

        if (class_exists('DBSMTP_Fallback_Alert') && DBSMTP_Fallback_Alert::is_sending()) {
            return;
        }

        // Пока впереди есть ещё одна попытка (запасной канал), ошибка не
        // окончательная. Не шлём в Telegram ложный провал.
        if (class_exists('DBSMTP_Mailer') && DBSMTP_Mailer::is_retry_pending()) {
            return;
        }

        $settings = $this->settings->get_settings();
        if (empty($settings['telegram_notify_failed'])) {
            return;
        }

        if (!is_wp_error($error)) {
            return;
        }

        $data = $error->get_error_data();
        $mail_data = is_array($data) ? $data : [];

        $this->notify($mail_data, 'failed', $error->get_error_message());
    }

    /**
     * @param array  $mail_data
     * @param string $status success|failed
     * @param string $error_message
     */
    private function notify($mail_data, $status, $error_message) {
        $token   = trim($this->get_bot_token());
        $chat_id = trim($this->get_chat_id());

        if ($token === '' || $chat_id === '') {
            return;
        }

        $text = $this->build_message($mail_data, $status, $error_message);
        $this->send_message($token, $chat_id, $text);
    }

    /**
     * @param array  $mail_data
     * @param string $status
     * @param string $error_message
     * @return string
     */
    public function build_message($mail_data, $status = 'success', $error_message = '') {
        $to      = isset($mail_data['to']) ? $mail_data['to'] : '';
        $subject = isset($mail_data['subject']) ? $mail_data['subject'] : '';
        $body    = isset($mail_data['message']) ? $mail_data['message'] : '';
        $headers = isset($mail_data['headers']) ? $mail_data['headers'] : '';

        if (is_array($to)) {
            $to = implode(', ', array_map('strval', $to));
        }

        $from = $this->extract_from_header($headers);

        if ($status === 'failed') {
            $title = __('❌ Ошибка отправки письма', 'devbrothers-smtp');
        } else {
            $title = __('📧 Письмо отправлено', 'devbrothers-smtp');
        }

        $lines = [
            $title,
            '',
            sprintf('%s: %s', __('Сайт', 'devbrothers-smtp'), get_bloginfo('name')),
            sprintf('%s: %s', __('URL', 'devbrothers-smtp'), home_url('/')),
        ];

        if ($from !== '') {
            $lines[] = sprintf('%s: %s', __('От', 'devbrothers-smtp'), $from);
        }

        if ($to !== '') {
            $lines[] = sprintf('%s: %s', __('Кому', 'devbrothers-smtp'), $to);
        }

        if ($subject !== '') {
            $lines[] = sprintf('%s: %s', __('Тема', 'devbrothers-smtp'), $subject);
        }

        if ($error_message !== '') {
            $lines[] = sprintf('%s: %s', __('Ошибка', 'devbrothers-smtp'), $error_message);
        }

        $attachments = isset($mail_data['attachments']) ? $mail_data['attachments'] : [];
        if (is_array($attachments) && !empty($attachments)) {
            $lines[] = sprintf(
                '%s: %d',
                __('Вложения', 'devbrothers-smtp'),
                count($attachments)
            );
        }

        $plain_body = $this->message_to_plain_text($body);
        if ($plain_body !== '') {
            $lines[] = '';
            $lines[] = __('Содержимое:', 'devbrothers-smtp');
            $lines[] = $plain_body;
        }

        $text = implode("\n", $lines);

        return $this->truncate_message($text);
    }

    /**
     * @param mixed $headers
     * @return string
     */
    private function extract_from_header($headers) {
        if (is_string($headers)) {
            $headers = explode("\n", str_replace("\r\n", "\n", $headers));
        }

        if (!is_array($headers)) {
            return '';
        }

        foreach ($headers as $header) {
            $header = trim((string) $header);
            if (stripos($header, 'From:') === 0) {
                return trim(substr($header, 5));
            }
        }

        return '';
    }

    /**
     * @param mixed $body
     * @return string
     */
    private function message_to_plain_text($body) {
        $body = (string) $body;
        if ($body === '') {
            return '';
        }

        $body = wp_strip_all_tags($body);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = preg_replace("/[ \t]+/u", ' ', $body);
        $body = preg_replace("/\n{3,}/u", "\n\n", $body);

        return trim($body);
    }

    /**
     * @param string $text
     * @return string
     */
    private function truncate_message($text) {
        $max = 4096;
        $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);

        if ($len <= $max) {
            return $text;
        }

        $suffix = "\n\n… " . __('(сообщение обрезано)', 'devbrothers-smtp');
        $suffix_len = function_exists('mb_strlen') ? mb_strlen($suffix, 'UTF-8') : strlen($suffix);
        $keep = max(0, $max - $suffix_len);

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $keep, 'UTF-8') . $suffix;
        }

        return substr($text, 0, $keep) . $suffix;
    }

    /**
     * @param string $token
     * @param string $chat_id
     * @param string $text
     * @return true|WP_Error
     */
    public function send_message($token, $chat_id, $text) {
        $token   = trim($token);
        $chat_id = trim($chat_id);

        if ($token === '' || $chat_id === '') {
            return new WP_Error('dbsmtp_telegram_config', __('Укажите токен бота и Chat ID.', 'devbrothers-smtp'));
        }

        if (!preg_match('/^\d+:[A-Za-z0-9._-]+$/', $token)) {
            return new WP_Error('dbsmtp_telegram_config', __('Некорректный формат токена бота.', 'devbrothers-smtp'));
        }

        if (!preg_match('/^-?\d+$/', $chat_id)) {
            return new WP_Error('dbsmtp_telegram_config', __('Некорректный Chat ID.', 'devbrothers-smtp'));
        }

        $url = self::API_URL . $token . '/sendMessage';

        $response = wp_remote_post(
            $url,
            [
                'timeout' => 15,
                'body'    => [
                    'chat_id' => $chat_id,
                    'text'    => $text,
                ],
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300 && !empty($body['ok'])) {
            return true;
        }

        $description = '';
        if (is_array($body) && !empty($body['description'])) {
            $description = (string) $body['description'];
        }

        if ($description === '') {
            $description = sprintf(
                /* translators: %d: HTTP status code */
                __('Telegram API вернул код %d.', 'devbrothers-smtp'),
                $code
            );
        }

        return new WP_Error('dbsmtp_telegram_api', $description);
    }

    /**
     * @return true|WP_Error
     */
    public function send_test_message() {
        return $this->send_test_message_with_credentials(
            trim($this->get_bot_token()),
            trim($this->get_chat_id())
        );
    }

    /**
     * Тестовое сообщение с явно переданными credentials (для AJAX до сохранения формы).
     *
     * @param string $token
     * @param string $chat_id
     * @return true|WP_Error
     */
    public function send_test_message_with_credentials($token, $chat_id) {
        $text = sprintf(
            "%s\n\n%s: %s\n%s: %s",
            __('✅ Тестовое уведомление DevBrothers SMTP', 'devbrothers-smtp'),
            __('Сайт', 'devbrothers-smtp'),
            get_bloginfo('name'),
            __('Время', 'devbrothers-smtp'),
            wp_date('d.m.Y H:i:s')
        );

        return $this->send_message($token, $chat_id, $text);
    }
}
