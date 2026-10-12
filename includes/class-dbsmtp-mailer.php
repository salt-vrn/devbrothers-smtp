<?php
/**
 * Конфигурация PHPMailer для отправки через SMTP
 *
 * @package DevBrothers_SMTP
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBSMTP_Mailer {

    /**
     * Пауза SMTP после сбоя: пока флаг жив, письма сразу идут через PHP mail().
     */
    const TRANSIENT_SMTP_DOWN = 'dbsmtp_smtp_down';

    /**
     * @var DBSMTP_Settings
     */
    private $settings;

    /**
     * @var DBSMTP_Logger
     */
    private $logger;

    /**
     * Текст последней ошибки отправки.
     *
     * @var string
     */
    private $last_error = '';

    /**
     * Последняя отправка ушла запасным PHP mail(), а не SMTP.
     *
     * @var bool
     */
    private $fell_back = false;

    /**
     * Внутренний wp_mail() не должен снова попадать в запасной сценарий.
     *
     * @var bool
     */
    private static $bypass = false;

    /**
     * После текущей попытки будет ещё одна: ошибка пока не окончательная.
     *
     * @var bool
     */
    private static $retry_pending = false;

    /**
     * Текущую попытку отправлять через PHP mail(), не через SMTP.
     *
     * @var bool
     */
    private static $force_php_mail = false;

    /**
     * Не писать автоматическую строку журнала: итог запишет запасной сценарий.
     *
     * @var bool
     */
    private static $suppress_log = false;

    /**
     * @param DBSMTP_Settings $settings
     * @param DBSMTP_Logger   $logger
     */
    public function __construct($settings, $logger) {
        $this->settings = $settings;
        $this->logger   = $logger;

        add_filter('pre_wp_mail', [$this, 'maybe_send_with_fallback'], 10, 2);
        add_action('phpmailer_init', [$this, 'configure_phpmailer']);
        add_filter('wp_mail_from', [$this, 'filter_from_email']);
        add_filter('wp_mail_from_name', [$this, 'filter_from_name']);
        add_action('wp_mail_failed', [$this, 'capture_error']);
    }

    /**
     * Идёт попытка, после которой будет ещё одна (ошибка не окончательная).
     *
     * @return bool
     */
    public static function is_retry_pending() {
        return self::$retry_pending;
    }

    /**
     * @return bool
     */
    public static function is_forcing_php_mail() {
        return self::$force_php_mail;
    }

    /**
     * @return bool
     */
    public static function should_suppress_log() {
        return self::$suppress_log;
    }

    /**
     * Сбросить паузу SMTP (например, перед тестовой отправкой).
     */
    public static function reset_circuit() {
        delete_transient(self::TRANSIENT_SMTP_DOWN);
    }

    /**
     * Письмо ушло через PHP mail() вместо SMTP.
     *
     * @return bool
     */
    public function did_fallback() {
        return $this->fell_back;
    }

    /**
     * SMTP, а при ошибке — то же письмо через PHP mail().
     *
     * Внешний wp_mail() получает true, если какой-то канал сработал,
     * иначе форма заявки считает отправку проваленной.
     *
     * Фильтр `wp_mail` на время внутренних вызовов снимается: он уже был
     * применён к этим данным во внешнем wp_mail(), повторно применять нельзя.
     *
     * @param null|bool $short_circuit
     * @param array     $atts
     * @return null|bool
     */
    public function maybe_send_with_fallback($short_circuit, $atts) {
        if (null !== $short_circuit || self::$bypass) {
            return $short_circuit;
        }

        if (class_exists('DBSMTP_Fallback_Alert') && DBSMTP_Fallback_Alert::is_sending()) {
            return $short_circuit;
        }

        $settings = $this->settings->get_settings();
        if (empty($settings['enabled']) || empty($settings['host'])) {
            return $short_circuit;
        }

        global $wp_filter;

        $saved_filters = isset($wp_filter['wp_mail']) ? $wp_filter['wp_mail'] : null;
        unset($wp_filter['wp_mail']);

        self::$bypass       = true;
        self::$suppress_log = true;

        try {
            return $this->deliver($atts);
        } finally {
            self::$bypass         = false;
            self::$suppress_log   = false;
            self::$force_php_mail = false;
            self::$retry_pending  = false;

            if (null !== $saved_filters) {
                $wp_filter['wp_mail'] = $saved_filters;
            } else {
                unset($wp_filter['wp_mail']);
            }
        }
    }

    /**
     * Перебор каналов: обычно SMTP, затем PHP mail(); после недавнего сбоя
     * SMTP порядок обратный, чтобы не ждать таймаут на каждом письме.
     *
     * @param array $atts Аргументы wp_mail().
     * @return bool
     */
    private function deliver($atts) {
        $to          = isset($atts['to']) ? $atts['to'] : '';
        $subject     = isset($atts['subject']) ? $atts['subject'] : '';
        $message     = isset($atts['message']) ? $atts['message'] : '';
        $headers     = isset($atts['headers']) ? $atts['headers'] : '';
        $attachments = isset($atts['attachments']) ? $atts['attachments'] : [];
        $embeds      = isset($atts['embeds']) ? $atts['embeds'] : [];

        $this->fell_back = false;

        $php_available = DBSMTP_Fallback_Alert::is_php_mail_available();
        $smtp_paused   = $php_available && (bool) get_transient(self::TRANSIENT_SMTP_DOWN);

        if (!$php_available) {
            $order = ['smtp'];
        } elseif ($smtp_paused) {
            $order = ['php', 'smtp'];
        } else {
            $order = ['smtp', 'php'];
        }

        $errors  = [];
        $sent_by = '';
        $last    = count($order) - 1;

        foreach ($order as $index => $channel) {
            self::$force_php_mail = ($channel === 'php');
            self::$retry_pending  = ($index < $last);
            $this->reset_last_error();

            $ok = $this->call_wp_mail($to, $subject, $message, $headers, $attachments, $embeds);

            self::$retry_pending = false;

            if ($ok) {
                $sent_by = $channel;
                break;
            }

            $errors[$channel] = $this->last_error !== ''
                ? $this->last_error
                : __('неизвестная ошибка', 'devbrothers-smtp');
        }

        self::$force_php_mail = false;

        if ($sent_by === 'smtp') {
            delete_transient(self::TRANSIENT_SMTP_DOWN);
            $this->logger->log($to, $subject, DBSMTP_Logger::STATUS_SENT, '', DBSMTP_Logger::TRANSPORT_SMTP);
            return true;
        }

        if ($sent_by === 'php') {
            $this->fell_back = true;

            if (isset($errors['smtp'])) {
                $minutes = (int) apply_filters('dbsmtp_smtp_pause_minutes', 5);
                if ($minutes > 0) {
                    set_transient(self::TRANSIENT_SMTP_DOWN, time(), $minutes * MINUTE_IN_SECONDS);
                }
                $note = sprintf(
                    /* translators: %s: SMTP error text */
                    __('SMTP не ответил (%s). Письмо отправлено через PHP mail().', 'devbrothers-smtp'),
                    $errors['smtp']
                );
            } else {
                $note = __('SMTP временно пропущен после недавнего сбоя. Письмо отправлено через PHP mail().', 'devbrothers-smtp');
            }

            $this->logger->log($to, $subject, DBSMTP_Logger::STATUS_SENT, $note, DBSMTP_Logger::TRANSPORT_PHP);
            return true;
        }

        $parts = [];
        if (isset($errors['smtp'])) {
            $parts[] = 'SMTP: ' . $errors['smtp'];
        }
        if (isset($errors['php'])) {
            $parts[] = 'PHP mail(): ' . $errors['php'];
        }
        if (!$php_available) {
            $parts[] = __('PHP mail() недоступен на сервере', 'devbrothers-smtp');
        }

        $this->logger->log(
            $to,
            $subject,
            DBSMTP_Logger::STATUS_FAILED,
            implode('. ', $parts),
            isset($errors['php']) ? DBSMTP_Logger::TRANSPORT_PHP : DBSMTP_Logger::TRANSPORT_SMTP
        );

        return false;
    }

    /**
     * @param string|string[] $to
     * @param string          $subject
     * @param string          $message
     * @param string|string[] $headers
     * @param string|string[] $attachments
     * @param string|string[] $embeds
     * @return bool
     */
    private function call_wp_mail($to, $subject, $message, $headers, $attachments, $embeds) {
        static $accepts_embeds = null;

        if ($accepts_embeds === null) {
            $accepts_embeds = false;
            if (function_exists('wp_mail')) {
                try {
                    $reflection     = new ReflectionFunction('wp_mail');
                    $accepts_embeds = $reflection->getNumberOfParameters() >= 6;
                } catch (ReflectionException $e) {
                    $accepts_embeds = false;
                }
            }
        }

        if ($accepts_embeds) {
            return (bool) wp_mail($to, $subject, $message, $headers, $attachments, $embeds);
        }

        return (bool) wp_mail($to, $subject, $message, $headers, $attachments);
    }

    /**
     * Настраивает PHPMailer на отправку через SMTP.
     *
     * Применяется глобально к каждому письму, поэтому при выключенном
     * плагине или пустом хосте сразу выходим без изменений.
     *
     * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
     */
    public function configure_phpmailer($phpmailer) {
        if (
            self::$force_php_mail
            || (class_exists('DBSMTP_Fallback_Alert') && DBSMTP_Fallback_Alert::is_sending())
        ) {
            // wp_mail() reuses the global PHPMailer; force mail() so a nested
            // alert or the SMTP fallback cannot ride the same broken connection.
            $phpmailer->isMail();
            $phpmailer->SMTPAuth = false;
            return;
        }

        $s = $this->settings->get_settings();

        if (empty($s['enabled']) || empty($s['host'])) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host    = $s['host'];
        $phpmailer->Port    = (int) $s['port'];
        $phpmailer->Timeout = 15;

        if (!empty($s['auth'])) {
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = $s['username'];
            $phpmailer->Password = $this->settings->get_password();
        } else {
            $phpmailer->SMTPAuth = false;
        }

        $phpmailer->SMTPSecure = $s['encryption'];
    }

    /**
     * Подмена адреса отправителя.
     *
     * @param string $from_email
     * @return string
     */
    public function filter_from_email($from_email) {
        if (
            self::$force_php_mail
            || (class_exists('DBSMTP_Fallback_Alert') && DBSMTP_Fallback_Alert::is_sending())
        ) {
            return $from_email;
        }

        $s = $this->settings->get_settings();

        if (empty($s['enabled']) || empty($s['from_email']) || !is_email($s['from_email'])) {
            return $from_email;
        }

        // Меняем всегда, если задан force_from, иначе только дефолтный wordpress@.
        if (!empty($s['force_from']) || strpos($from_email, 'wordpress@') === 0) {
            return $s['from_email'];
        }

        return $from_email;
    }

    /**
     * Подмена имени отправителя.
     *
     * @param string $from_name
     * @return string
     */
    public function filter_from_name($from_name) {
        if (
            self::$force_php_mail
            || (class_exists('DBSMTP_Fallback_Alert') && DBSMTP_Fallback_Alert::is_sending())
        ) {
            return $from_name;
        }

        $s = $this->settings->get_settings();

        if (empty($s['enabled']) || empty($s['from_name'])) {
            return $from_name;
        }

        if (!empty($s['force_from']) || $from_name === 'WordPress') {
            return $s['from_name'];
        }

        return $from_name;
    }

    /**
     * Сохраняет текст ошибки последней отправки.
     *
     * @param WP_Error $error
     */
    public function capture_error($error) {
        if (is_wp_error($error)) {
            $this->last_error = $error->get_error_message();
        }
    }

    /**
     * Возвращает текст последней ошибки.
     *
     * @return string
     */
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * Сбрасывает текст последней ошибки.
     */
    public function reset_last_error() {
        $this->last_error = '';
    }
}
