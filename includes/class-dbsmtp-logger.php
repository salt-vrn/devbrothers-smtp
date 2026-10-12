<?php
/**
 * Журнал отправок писем
 *
 * @package DevBrothers_SMTP
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBSMTP_Logger {

    const STATUS_SENT   = 'sent';
    const STATUS_FAILED = 'failed';
    const TRANSPORT_SMTP = 'smtp';
    const TRANSPORT_PHP  = 'php_mail';

    /**
     * Максимальное число хранимых записей.
     *
     * @var int
     */
    private $max_rows = 200;

    public function __construct() {
        add_action('wp_mail_succeeded', [$this, 'log_success'], 10, 1);
        add_action('wp_mail_failed', [$this, 'log_failure'], 10, 1);
    }

    /**
     * Имя таблицы логов.
     *
     * @return string
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'dbsmtp_logs';
    }

    /**
     * Создание таблицы логов (вызывается при активации и обновлении).
     */
    public static function install() {
        global $wpdb;

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email_to TEXT NOT NULL,
            subject TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'sent',
            transport VARCHAR(20) NOT NULL DEFAULT '',
            message TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('dbsmtp_db_version', DBSMTP_VERSION);
    }

    /**
     * Запись об успешной отправке.
     *
     * @param array $mail_data Данные письма из хука wp_mail_succeeded.
     */
    public function log_success($mail_data) {
        if (class_exists('DBSMTP_Mailer') && DBSMTP_Mailer::should_suppress_log()) {
            return;
        }

        if (class_exists('DBSMTP_Fallback_Alert') && DBSMTP_Fallback_Alert::is_sending()) {
            return;
        }

        $to      = isset($mail_data['to']) ? $mail_data['to'] : '';
        $subject = isset($mail_data['subject']) ? $mail_data['subject'] : '';

        $this->insert($to, $subject, self::STATUS_SENT, '', $this->current_transport());
    }

    /**
     * Запись о неудачной отправке.
     *
     * @param WP_Error $error Ошибка из хука wp_mail_failed.
     */
    public function log_failure($error) {
        if (class_exists('DBSMTP_Mailer') && DBSMTP_Mailer::should_suppress_log()) {
            return;
        }

        if (class_exists('DBSMTP_Fallback_Alert') && DBSMTP_Fallback_Alert::is_sending()) {
            return;
        }

        if (!is_wp_error($error)) {
            return;
        }

        $data    = $error->get_error_data();
        $to      = isset($data['to']) ? $data['to'] : '';
        $subject = isset($data['subject']) ? $data['subject'] : '';

        $this->insert($to, $subject, self::STATUS_FAILED, $error->get_error_message(), $this->current_transport());
    }

    /**
     * Ручная запись (например, результат fallback-уведомления).
     *
     * @param string|array $to
     * @param string       $subject
     * @param string       $status
     * @param string       $message
     * @param string       $transport smtp|php_mail|''.
     */
    public function log($to, $subject, $status, $message, $transport = '') {
        $this->insert($to, $subject, $status, $message, $transport);
    }

    /**
     * Вставка записи в журнал.
     *
     * @param string|array $to      Получатель(и).
     * @param string       $subject Тема письма.
     * @param string       $status  Статус (sent|failed).
     * @param string       $message   Сообщение об ошибке.
     * @param string       $transport Канал: smtp, php_mail или пусто.
     */
    private function insert($to, $subject, $status, $message, $transport = '') {
        global $wpdb;

        if (is_array($to)) {
            $to = implode(', ', $to);
        }

        if (!in_array($transport, [self::TRANSPORT_SMTP, self::TRANSPORT_PHP], true)) {
            $transport = '';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Кастомная таблица логов плагина.
        $wpdb->insert(
            self::table_name(),
            [
                'email_to'   => sanitize_text_field((string) $to),
                'subject'    => sanitize_text_field((string) $subject),
                'status'     => $status === self::STATUS_FAILED ? self::STATUS_FAILED : self::STATUS_SENT,
                'transport'  => $transport,
                'message'    => sanitize_text_field((string) $message),
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        $this->prune();
    }

    /**
     * Удаление старых записей сверх лимита max_rows.
     */
    private function prune() {
        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Имя таблицы из $wpdb->prefix.
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        if ($total <= $this->max_rows) {
            return;
        }

        $remove = $total - $this->max_rows;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Кастомная таблица логов плагина.
        $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} ORDER BY id ASC LIMIT %d", $remove) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
    }

    /**
     * Получение последних записей журнала.
     *
     * @param int $limit Сколько записей вернуть.
     * @return array
     */
    public function get_logs($limit = 50) {
        global $wpdb;

        $table = self::table_name();
        $limit = absint($limit);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Кастомная таблица логов плагина.
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
    }

    /**
     * Полная очистка журнала.
     */
    public function clear() {
        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Имя таблицы из $wpdb->prefix.
        $wpdb->query("TRUNCATE TABLE {$table}");
    }

    /**
     * Удаление таблицы логов при деинсталляции плагина.
     */
    public static function drop_table() {
        global $wpdb;

        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Удаление таблицы плагина; имя из $wpdb->prefix.
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

    /**
     * Отрисовка таблицы журнала отправок.
     */
    public function render_logs_table() {
        $logs = $this->get_logs(50);
        ?>
        <div class="dbsmtp-logs-toolbar">
            <button type="button" class="button" id="dbsmtp-clear-logs">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e('Очистить журнал', 'devbrothers-smtp'); ?>
            </button>
            <span class="dbsmtp-logs-hint">
                <?php esc_html_e('Хранятся последние 200 писем.', 'devbrothers-smtp'); ?>
            </span>
        </div>

        <div id="dbsmtp-logs-table-wrapper">
            <?php if (empty($logs)) : ?>
                <p class="dbsmtp-logs-empty">
                    <?php esc_html_e('Журнал пуст. Отправьте письмо, чтобы здесь появились записи.', 'devbrothers-smtp'); ?>
                </p>
            <?php else : ?>
                <table class="widefat striped dbsmtp-logs-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Дата', 'devbrothers-smtp'); ?></th>
                            <th><?php esc_html_e('Получатель', 'devbrothers-smtp'); ?></th>
                            <th><?php esc_html_e('Тема', 'devbrothers-smtp'); ?></th>
                            <th><?php esc_html_e('Канал', 'devbrothers-smtp'); ?></th>
                            <th><?php esc_html_e('Статус', 'devbrothers-smtp'); ?></th>
                            <th><?php esc_html_e('Сообщение', 'devbrothers-smtp'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <?php $is_sent = ($log->status === self::STATUS_SENT); ?>
                            <tr>
                                <td><?php echo esc_html(get_date_from_gmt($log->created_at, 'd.m.Y H:i')); ?></td>
                                <td><?php echo esc_html($log->email_to); ?></td>
                                <td><?php echo esc_html($log->subject); ?></td>
                                <td><?php echo esc_html($this->transport_label(isset($log->transport) ? $log->transport : '')); ?></td>
                                <td>
                                    <span class="dbsmtp-status dbsmtp-status-<?php echo esc_attr($log->status); ?>">
                                        <?php echo $is_sent
                                            ? esc_html__('Отправлено', 'devbrothers-smtp')
                                            : esc_html__('Ошибка', 'devbrothers-smtp'); ?>
                                    </span>
                                </td>
                                <td class="dbsmtp-log-message"><?php echo esc_html($log->message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Канал текущей попытки для автоматической записи журнала.
     *
     * @return string
     */
    private function current_transport() {
        if (class_exists('DBSMTP_Mailer') && DBSMTP_Mailer::is_forcing_php_mail()) {
            return self::TRANSPORT_PHP;
        }

        $settings = get_option('dbsmtp_settings', []);
        if (is_array($settings) && !empty($settings['enabled']) && !empty($settings['host'])) {
            return self::TRANSPORT_SMTP;
        }

        return self::TRANSPORT_PHP;
    }

    /**
     * @param string $transport
     * @return string
     */
    private function transport_label($transport) {
        if ($transport === self::TRANSPORT_SMTP) {
            return 'SMTP';
        }

        if ($transport === self::TRANSPORT_PHP) {
            return 'PHP mail()';
        }

        return '—';
    }
}
