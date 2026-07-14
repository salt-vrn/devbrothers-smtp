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
     * @param DBSMTP_Settings $settings
     * @param DBSMTP_Logger   $logger
     */
    public function __construct($settings, $logger) {
        $this->settings = $settings;
        $this->logger   = $logger;

        add_action('phpmailer_init', [$this, 'configure_phpmailer']);
        add_filter('wp_mail_from', [$this, 'filter_from_email']);
        add_filter('wp_mail_from_name', [$this, 'filter_from_name']);
        add_action('wp_mail_failed', [$this, 'capture_error']);
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
        if (class_exists('DBSMTP_Fallback_Alert') && DBSMTP_Fallback_Alert::is_sending()) {
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
        if (class_exists('DBSMTP_Fallback_Alert') && DBSMTP_Fallback_Alert::is_sending()) {
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
        if (class_exists('DBSMTP_Fallback_Alert') && DBSMTP_Fallback_Alert::is_sending()) {
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
