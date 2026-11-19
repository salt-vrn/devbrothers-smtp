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
    const SKIP_HEADER = 'X-DevBrothers-Skip-Telegram: 1';

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
        add_filter('retrieve_password_notification_email', [$this, 'mark_password_reset_email']);
        add_filter('woocommerce_email_headers', [$this, 'mark_woocommerce_email'], 10, 2);
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

        $this->notify($mail_data, 'success');
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

        $mail_data = $error->get_error_data();
        if (is_array($mail_data)) {
            $this->notify($mail_data, 'failed', $error->get_error_message());
        }
    }

    /**
     * @param array  $mail_data
     * @param string $status success|failed
     * @param string $error_message
     */
    private function notify($mail_data, $status, $error_message = '') {
        if ($this->should_skip_mail($mail_data)) {
            return;
        }

        $token   = trim($this->get_bot_token());
        $chat_id = trim($this->get_chat_id());

        if ($token === '' || $chat_id === '') {
            return;
        }

        $text = $this->build_message($mail_data, $status, $error_message);
        $this->send_message($token, $chat_id, $text);
    }

    public function mark_password_reset_email($email) {
        $email['headers'] = $this->add_skip_header($email['headers'] ?? '');
        return $email;
    }

    public function mark_woocommerce_email($headers, $email_id) {
        return $email_id === 'customer_reset_password' ? $this->add_skip_header($headers) : $headers;
    }

    private function add_skip_header($headers) {
        if (is_array($headers)) {
            $headers[] = self::SKIP_HEADER;
            return $headers;
        }

        $headers = rtrim((string) $headers, "\r\n");
        return $headers === '' ? self::SKIP_HEADER : $headers . "\r\n" . self::SKIP_HEADER;
    }

    public function should_skip_mail($mail_data) {
        $headers = $mail_data['headers'] ?? '';
        $lines = is_array($headers) ? $headers : preg_split('/\r\n|\r|\n/', (string) $headers);
        foreach ($lines as $line) {
            if (is_string($line) && preg_match('/^X-DevBrothers-Skip-Telegram:\s*1\s*$/i', trim($line))) {
                return true;
            }
        }

        $message = html_entity_decode((string) ($mail_data['message'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $has_reset_link = preg_match(
            '~\bwp-login\.php\?(?:[^\s<>"\'#]*&)?action=(?:rp|resetpass)(?=[&#\s<>"\']|$)~i',
            $message
        );
        return $has_reset_link || (bool) apply_filters('dbsmtp_skip_telegram_email', false, $mail_data);
    }

    /**
     * @param array  $mail_data
     * @param string $status
     * @param string $error_message Legacy argument, not forwarded.
     * @return string
     */
    public function build_message($mail_data, $status = 'success', $error_message = '') {
        $lines = $this->status_lines($status);
        $to = $mail_data['to'] ?? '';
        $to = is_array($to) ? implode(', ', $to) : (string) $to;
        $subject = (string) ($mail_data['subject'] ?? '');
        $from = $this->extract_from_header($mail_data['headers'] ?? '');
        $body = (string) ($mail_data['message'] ?? '');
        // Блочные теги -> переносы строк, иначе абзацы слипаются после strip_tags().
        $body = preg_replace('~<\s*(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>~i', "\n", $body);
        $body = trim(html_entity_decode(wp_strip_all_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $body = preg_replace("/[ \t]+/u", ' ', $body);
        $body = preg_replace("/\n{3,}/u", "\n\n", $body);
        $body = trim($body);

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
        if ($body !== '') {
            $lines[] = '';
            $lines[] = __('Содержимое:', 'devbrothers-smtp');
            $lines[] = $body;
        }

        return $this->truncate_message(implode("\n", $lines));
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

    public function build_status_message($status) {
        return $this->truncate_message(implode("\n", $this->status_lines($status)));
    }

    private function status_lines($status) {
        if ($status === 'failed') {
            $title = __('❌ Ошибка отправки письма', 'devbrothers-smtp');
        } else {
            $title = __('📧 Письмо отправлено', 'devbrothers-smtp');
        }

        return [
            $title,
            '',
            sprintf('%s: %s', __('Сайт', 'devbrothers-smtp'), get_bloginfo('name')),
            sprintf('%s: %s', __('URL', 'devbrothers-smtp'), home_url('/')),
        ];
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
                'timeout'             => 15,
                'redirection'         => 0,
                'limit_response_size' => 65536,
                'body'                => [
                    'chat_id' => $chat_id,
                    'text'    => $text,
                    'link_preview_options' => wp_json_encode(['is_disabled' => true]),
                ],
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('dbsmtp_telegram_connection', __('Не удалось подключиться к Telegram API.', 'devbrothers-smtp'));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300 && !empty($body['ok'])) {
            return true;
        }

        return new WP_Error(
            'dbsmtp_telegram_api',
            sprintf(
                /* translators: %d: HTTP status code */
                __('Telegram API вернул код %d.', 'devbrothers-smtp'),
                $code
            )
        );
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
