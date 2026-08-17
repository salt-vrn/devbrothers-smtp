<?php
/**
 * Plugin Name: DevBrothers SMTP
 * Plugin URI: https://devbrothers.ru/smtp/
 * Description: Send WordPress emails through your own SMTP server, with a delivery log and optional Telegram copies.
 * Version: 1.2.2
 * Author: DevBrothers
 * Author URI: https://devbrothers.ru
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: devbrothers-smtp
 * Requires at least: 5.8
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Requires Plugins: devbrothers-admin-panel
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DBSMTP_VERSION', '1.2.2');
define('DBSMTP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DBSMTP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DBSMTP_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('DBSMTP_PREFIX', 'dbsmtp');

require_once DBSMTP_PLUGIN_DIR . 'includes/class-dbsmtp-logger.php';
require_once DBSMTP_PLUGIN_DIR . 'includes/class-dbsmtp-providers.php';

register_activation_hook(__FILE__, ['DBSMTP_Logger', 'install']);

class DBSMTP_Plugin {

    /**
     * @var DBSMTP_Plugin
     */
    private static $instance = null;

    /**
     * @var DBSMTP_Settings
     */
    public $settings;

    /**
     * @var DBSMTP_Mailer
     */
    public $mailer;

    /**
     * @var DBSMTP_Logger
     */
    public $logger;

    /**
     * @var DBSMTP_Telegram
     */
    public $telegram;

    /**
     * @var DBSMTP_Fallback_Alert
     */
    public $fallback_alert;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once DBSMTP_PLUGIN_DIR . 'includes/class-dbsmtp-settings.php';
        require_once DBSMTP_PLUGIN_DIR . 'includes/class-dbsmtp-fallback-alert.php';
        require_once DBSMTP_PLUGIN_DIR . 'includes/class-dbsmtp-notifications.php';
        require_once DBSMTP_PLUGIN_DIR . 'includes/class-dbsmtp-mailer.php';
        require_once DBSMTP_PLUGIN_DIR . 'includes/class-dbsmtp-telegram.php';
    }

    private function init_components() {
        $this->settings        = new DBSMTP_Settings();
        $this->logger        = new DBSMTP_Logger();
        $this->fallback_alert = new DBSMTP_Fallback_Alert($this->settings);
        $this->mailer        = new DBSMTP_Mailer($this->settings, $this->logger);
        $this->telegram      = new DBSMTP_Telegram($this->settings);
        new DBSMTP_Notifications($this->settings);
    }

    private function init_hooks() {
        add_action('devbrothers_ready', [$this, 'register_in_devbrothers']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_init', [$this, 'maybe_upgrade_db']);

        add_action('wp_ajax_dbsmtp_send_test', [$this, 'ajax_send_test']);
        add_action('wp_ajax_dbsmtp_test_telegram', [$this, 'ajax_test_telegram']);
        add_action('wp_ajax_dbsmtp_clear_logs', [$this, 'ajax_clear_logs']);
    }

    /**
     * Создаёт/обновляет таблицу логов, если схема устарела.
     */
    public function maybe_upgrade_db() {
        if (get_option('dbsmtp_db_version') !== DBSMTP_VERSION) {
            DBSMTP_Logger::install();
        }
    }

    /**
     * Регистрация плагина в системе DevBrothers.
     */
    public function register_in_devbrothers() {
        if (!function_exists('devbrothers_register_plugin')) {
            return;
        }

        devbrothers_register_plugin([
            'id'                => 'smtp',
            'name'              => __('SMTP', 'devbrothers-smtp'),
            'name_ru'           => __('SMTP-почта', 'devbrothers-smtp'),
            'description'       => __('Отправка писем WordPress через ваш SMTP-сервер', 'devbrothers-smtp'),
            'version'           => DBSMTP_VERSION,
            'icon'              => 'dashicons-email-alt',
            'settings_callback' => [$this->settings, 'render_settings_page'],
            'categories'        => [
                [
                    'id'   => 'connection',
                    'name' => __('Настройки SMTP', 'devbrothers-smtp'),
                    'icon' => 'dashicons-admin-settings',
                ],
                [
                    'id'   => 'telegram',
                    'name' => __('Telegram', 'devbrothers-smtp'),
                    'icon' => 'dashicons-format-chat',
                ],
                [
                    'id'   => 'test',
                    'name' => __('Тест отправки', 'devbrothers-smtp'),
                    'icon' => 'dashicons-email',
                ],
                [
                    'id'   => 'logs',
                    'name' => __('Журнал отправок', 'devbrothers-smtp'),
                    'icon' => 'dashicons-list-view',
                ],
            ],
        ]);
    }

    /**
     * Подключение стилей и скриптов в админке DevBrothers.
     *
     * @param string $hook Текущий хук страницы админки.
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'devbrothers') === false) {
            return;
        }

        wp_enqueue_style(
            'dbsmtp-admin',
            DBSMTP_PLUGIN_URL . 'assets/css/admin.css',
            ['devbrothers-admin'],
            DBSMTP_VERSION
        );

        wp_enqueue_script(
            'dbsmtp-admin',
            DBSMTP_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            DBSMTP_VERSION,
            true
        );

        wp_localize_script('dbsmtp-admin', 'dbsmtpConfig', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'testNonce'      => wp_create_nonce('dbsmtp_test_nonce'),
            'telegramNonce'  => wp_create_nonce('dbsmtp_telegram_nonce'),
            'logsNonce'      => wp_create_nonce('dbsmtp_logs_nonce'),
            'presets'        => DBSMTP_Providers::get_presets_for_js(),
            'strings'        => [
                'enabled'           => __('Включён', 'devbrothers-smtp'),
                'disabled'          => __('Выключен', 'devbrothers-smtp'),
                'sending'           => __('Отправка...', 'devbrothers-smtp'),
                'sendTest'          => __('Отправить тестовое письмо', 'devbrothers-smtp'),
                'sendTelegramTest'  => __('Отправить тест в Telegram', 'devbrothers-smtp'),
                'emptyEmail'        => __('Укажите email получателя.', 'devbrothers-smtp'),
                'telegramNeedSave'  => __('Сначала сохраните токен бота и Chat ID.', 'devbrothers-smtp'),
                'requestError'      => __('Ошибка запроса. Попробуйте ещё раз.', 'devbrothers-smtp'),
                'confirmClear'      => __('Очистить весь журнал отправок?', 'devbrothers-smtp'),
                'cleared'           => __('Журнал очищен.', 'devbrothers-smtp'),
            ],
        ]);
    }

    /**
     * AJAX: тестовая отправка письма.
     */
    public function ajax_send_test() {
        if (
            !isset($_POST['nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'dbsmtp_test_nonce')
        ) {
            wp_send_json_error(['message' => __('Ошибка безопасности.', 'devbrothers-smtp')]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостаточно прав.', 'devbrothers-smtp')]);
        }

        $recipient = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

        if (empty($recipient) || !is_email($recipient)) {
            wp_send_json_error(['message' => __('Укажите корректный email получателя.', 'devbrothers-smtp')]);
        }

        $this->mailer->reset_last_error();

        $subject = __('Тестовое письмо DevBrothers SMTP', 'devbrothers-smtp');
        $body    = sprintf(
            /* translators: %s: site name */
            __('Это тестовое письмо, отправленное плагином DevBrothers SMTP с сайта «%s». Если вы его получили — настройки SMTP работают корректно.', 'devbrothers-smtp'),
            get_bloginfo('name')
        );

        $sent = wp_mail($recipient, $subject, $body);

        if ($sent) {
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %s: recipient email */
                    __('Письмо успешно отправлено на %s.', 'devbrothers-smtp'),
                    $recipient
                ),
            ]);
        }

        $error = $this->mailer->get_last_error();
        wp_send_json_error([
            'message' => $error !== ''
                ? $error
                : __('Не удалось отправить письмо. Проверьте настройки SMTP.', 'devbrothers-smtp'),
        ]);
    }

    /**
     * AJAX: тестовое уведомление в Telegram.
     */
    public function ajax_test_telegram() {
        if (
            !isset($_POST['nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'dbsmtp_telegram_nonce')
        ) {
            wp_send_json_error(['message' => __('Ошибка безопасности.', 'devbrothers-smtp')]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостаточно прав.', 'devbrothers-smtp')]);
        }

        // Токен не прогоняем через sanitize_text_field — только убираем переносы,
        // формат проверяется в DBSMTP_Telegram::send_message().
        $token = '';
        if (isset($_POST['bot_token'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Token format validated in send_message().
            $token = trim(str_replace(["\r", "\n"], '', (string) wp_unslash($_POST['bot_token'])));
        }
        if ($token === '') {
            $token = trim($this->settings->get_telegram_bot_token());
        }

        $chat_id = '';
        if (isset($_POST['chat_id'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Chat ID format validated in send_message().
            $chat_id = trim(str_replace([' ', "\r", "\n"], '', (string) wp_unslash($_POST['chat_id'])));
        }
        if ($chat_id === '') {
            $chat_id = trim($this->settings->get_telegram_chat_id());
        }

        if ($token === '' || $chat_id === '') {
            wp_send_json_error(['message' => __('Укажите токен бота и Chat ID.', 'devbrothers-smtp')]);
        }

        $result = $this->telegram->send_test_message_with_credentials($token, $chat_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Тестовое сообщение отправлено в Telegram.', 'devbrothers-smtp'),
        ]);
    }

    /**
     * AJAX: очистка журнала отправок.
     */
    public function ajax_clear_logs() {
        if (
            !isset($_POST['nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'dbsmtp_logs_nonce')
        ) {
            wp_send_json_error(['message' => __('Ошибка безопасности.', 'devbrothers-smtp')]);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостаточно прав.', 'devbrothers-smtp')]);
        }

        $this->logger->clear();

        wp_send_json_success(['message' => __('Журнал очищен.', 'devbrothers-smtp')]);
    }
}

function dbsmtp_plugin() {
    return DBSMTP_Plugin::get_instance();
}

add_action('plugins_loaded', 'dbsmtp_plugin', 10);
