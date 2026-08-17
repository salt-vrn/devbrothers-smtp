<?php
/**
 * Управление стандартными почтовыми уведомлениями WordPress.
 *
 * @package DevBrothers_SMTP
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBSMTP_Notifications {

    /**
     * @var DBSMTP_Settings
     */
    private $settings;

    /**
     * @param DBSMTP_Settings $settings
     */
    public function __construct(DBSMTP_Settings $settings) {
        $this->settings = $settings;

        add_action('init', [$this, 'maybe_disable_password_change_admin_email']);
    }

    /**
     * Отключает письмо администратору при сбросе пароля пользователя.
     *
     * Не затрагивает письмо самому пользователю (send_password_change_email).
     */
    public function maybe_disable_password_change_admin_email() {
        $settings = $this->settings->get_settings();

        if (empty($settings['disable_password_change_admin_email'])) {
            return;
        }

        remove_action('after_password_reset', 'wp_password_change_notification');
        add_filter('woocommerce_disable_password_change_notification', '__return_true');
    }
}
