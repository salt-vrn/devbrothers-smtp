<?php
/**
 * Настройки SMTP
 *
 * @package DevBrothers_SMTP
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBSMTP_Settings {

    /**
     * @var string
     */
    private $option_name = 'dbsmtp_settings';

    /**
     * Получение текущих настроек.
     *
     * @return array
     */
    public function get_settings() {
        $defaults = [
            'provider'               => 'custom',
            'enabled'                => false,
            'host'                   => '',
            'port'                   => 587,
            'encryption'             => 'tls',
            'auth'                   => true,
            'username'               => '',
            'password'               => '',
            'from_email'             => '',
            'from_name'              => '',
            'force_from'             => false,
            'telegram_enabled'       => false,
            'telegram_bot_token'     => '',
            'telegram_chat_id'       => '',
            'telegram_notify_success'=> true,
            'telegram_notify_failed' => false,
            'fallback_alert_enabled' => false,
            'fallback_alert_email'   => '',
            'fallback_alert_cooldown'=> 30,
            'disable_password_change_admin_email' => false,
        ];

        $settings = wp_parse_args(get_option($this->option_name, $defaults), $defaults);

        if (!in_array($settings['encryption'], ['tls', 'ssl'], true)) {
            $settings['encryption'] = 'tls';
        }

        if (!array_key_exists($settings['provider'], DBSMTP_Providers::get_presets())) {
            $settings['provider'] = 'custom';
        }

        return $settings;
    }

    /**
     * Возвращает пароль SMTP.
     *
     * Приоритет у константы DBSMTP_PASSWORD из wp-config.php — так пароль
     * не хранится в базе данных в открытом виде.
     *
     * @return string
     */
    public function get_password() {
        if (defined('DBSMTP_PASSWORD') && DBSMTP_PASSWORD !== '') {
            return DBSMTP_PASSWORD;
        }

        $settings = $this->get_settings();
        return isset($settings['password']) ? $settings['password'] : '';
    }

    /**
     * Хранится ли пароль в wp-config.php.
     *
     * @return bool
     */
    public function password_in_config() {
        return defined('DBSMTP_PASSWORD') && DBSMTP_PASSWORD !== '';
    }

    /**
     * Токен Telegram-бота (константа DBSMTP_TELEGRAM_BOT_TOKEN в wp-config.php имеет приоритет).
     *
     * @return string
     */
    public function get_telegram_bot_token() {
        if (defined('DBSMTP_TELEGRAM_BOT_TOKEN') && DBSMTP_TELEGRAM_BOT_TOKEN !== '') {
            return (string) DBSMTP_TELEGRAM_BOT_TOKEN;
        }

        $settings = $this->get_settings();
        return isset($settings['telegram_bot_token']) ? (string) $settings['telegram_bot_token'] : '';
    }

    /**
     * Chat ID Telegram (константа DBSMTP_TELEGRAM_CHAT_ID в wp-config.php имеет приоритет).
     *
     * @return string
     */
    public function get_telegram_chat_id() {
        if (defined('DBSMTP_TELEGRAM_CHAT_ID') && DBSMTP_TELEGRAM_CHAT_ID !== '') {
            return (string) DBSMTP_TELEGRAM_CHAT_ID;
        }

        $settings = $this->get_settings();
        return isset($settings['telegram_chat_id']) ? (string) $settings['telegram_chat_id'] : '';
    }

    /**
     * @return bool
     */
    public function telegram_token_in_config() {
        return defined('DBSMTP_TELEGRAM_BOT_TOKEN') && DBSMTP_TELEGRAM_BOT_TOKEN !== '';
    }

    /**
     * @return bool
     */
    public function telegram_chat_id_in_config() {
        return defined('DBSMTP_TELEGRAM_CHAT_ID') && DBSMTP_TELEGRAM_CHAT_ID !== '';
    }

    /**
     * Отрисовка страницы настроек.
     */
    public function render_settings_page() {
        $settings_saved = false;

        if (isset($_POST['dbsmtp_save_settings'])) {
            if (
                !isset($_POST['_wpnonce'])
                || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'dbsmtp_settings_nonce')
            ) {
                wp_die(esc_html__('Ошибка безопасности', 'devbrothers-smtp'));
            }

            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('Недостаточно прав', 'devbrothers-smtp'));
            }

            $this->save_settings_internal();
            $settings_saved = true;
        }

        $settings = $this->get_settings();

        if ($settings_saved) {
            echo '<div class="notice notice-success"><p>' .
                 esc_html__('Настройки сохранены!', 'devbrothers-smtp') .
                 '</p></div>';
        }

        $is_active = !empty($settings['enabled']);
        $telegram_active = !empty($settings['telegram_enabled']);
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('dbsmtp_settings_nonce'); ?>

            <!-- Категория: Настройки SMTP -->
            <div id="connection" class="devbrothers-settings-category">
                <h2>
                    <span class="dashicons dashicons-admin-settings"></span>
                    <?php esc_html_e('Настройки SMTP', 'devbrothers-smtp'); ?>
                </h2>

                <div class="dbsmtp-card<?php echo esc_attr($is_active ? ' dbsmtp-active' : ''); ?>">
                    <div class="dbsmtp-card-header">
                        <div class="dbsmtp-card-title">
                            <span class="dbsmtp-card-icon"><span class="dashicons dashicons-email-alt"></span></span>
                            <div>
                                <h3><?php esc_html_e('Отправка через SMTP', 'devbrothers-smtp'); ?></h3>
                                <span class="dbsmtp-card-status<?php echo esc_attr($is_active ? ' dbsmtp-status-on' : ''); ?>">
                                    <?php echo $is_active
                                        ? esc_html__('Включён', 'devbrothers-smtp')
                                        : esc_html__('Выключен', 'devbrothers-smtp'); ?>
                                </span>
                            </div>
                        </div>
                        <label class="dbsmtp-toggle">
                            <input type="checkbox"
                                   name="dbsmtp_settings[enabled]"
                                   id="dbsmtp_enabled"
                                   value="1"
                                   <?php checked($is_active); ?> />
                            <span class="dbsmtp-toggle-track">
                                <span class="dbsmtp-toggle-thumb"></span>
                            </span>
                        </label>
                    </div>

                    <div class="dbsmtp-card-body">
                        <p class="dbsmtp-hint">
                            <?php esc_html_e('Когда включено, все письма WordPress (wp_mail) отправляются через указанный SMTP-сервер вместо стандартной функции PHP mail().', 'devbrothers-smtp'); ?>
                        </p>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="dbsmtp_provider"><?php esc_html_e('Почтовый сервис', 'devbrothers-smtp'); ?></label>
                                </th>
                                <td>
                                    <select name="dbsmtp_settings[provider]" id="dbsmtp_provider">
                                        <?php foreach (DBSMTP_Providers::get_presets() as $provider_key => $provider_data) : ?>
                                            <option value="<?php echo esc_attr($provider_key); ?>" <?php selected($settings['provider'], $provider_key); ?>>
                                                <?php echo esc_html($provider_data['label']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p id="dbsmtp_provider_hint" class="dbsmtp-hint dbsmtp-provider-hint" style="display:none;"></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dbsmtp_host"><?php esc_html_e('SMTP-хост', 'devbrothers-smtp'); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="dbsmtp_settings[host]"
                                           id="dbsmtp_host"
                                           class="regular-text"
                                           placeholder="smtp.example.com"
                                           value="<?php echo esc_attr($settings['host']); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dbsmtp_port"><?php esc_html_e('Порт', 'devbrothers-smtp'); ?></label>
                                </th>
                                <td>
                                    <input type="number"
                                           name="dbsmtp_settings[port]"
                                           id="dbsmtp_port"
                                           class="small-text"
                                           min="1"
                                           max="65535"
                                           value="<?php echo esc_attr($settings['port']); ?>" />
                                    <p class="dbsmtp-hint"><?php esc_html_e('Обычно: 465 для SSL, 587 для TLS (STARTTLS).', 'devbrothers-smtp'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dbsmtp_encryption"><?php esc_html_e('Шифрование', 'devbrothers-smtp'); ?></label>
                                </th>
                                <td>
                                    <select name="dbsmtp_settings[encryption]" id="dbsmtp_encryption">
                                        <option value="tls" <?php selected($settings['encryption'], 'tls'); ?>>
                                            <?php esc_html_e('TLS (STARTTLS)', 'devbrothers-smtp'); ?>
                                        </option>
                                        <option value="ssl" <?php selected($settings['encryption'], 'ssl'); ?>>
                                            <?php esc_html_e('SSL', 'devbrothers-smtp'); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Аутентификация', 'devbrothers-smtp'); ?></th>
                                <td>
                                    <label class="dbsmtp-inline-toggle">
                                        <input type="checkbox"
                                               name="dbsmtp_settings[auth]"
                                               id="dbsmtp_auth"
                                               value="1"
                                               <?php checked(!empty($settings['auth'])); ?> />
                                        <?php esc_html_e('Использовать логин и пароль', 'devbrothers-smtp'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr class="dbsmtp-auth-field">
                                <th scope="row">
                                    <label for="dbsmtp_username"><?php esc_html_e('Логин (имя пользователя)', 'devbrothers-smtp'); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="dbsmtp_settings[username]"
                                           id="dbsmtp_username"
                                           class="regular-text"
                                           autocomplete="off"
                                           value="<?php echo esc_attr($settings['username']); ?>" />
                                </td>
                            </tr>
                            <tr class="dbsmtp-auth-field">
                                <th scope="row">
                                    <label for="dbsmtp_password"><?php esc_html_e('Пароль', 'devbrothers-smtp'); ?></label>
                                </th>
                                <td>
                                    <?php if ($this->password_in_config()) : ?>
                                        <p class="description">
                                            <span class="dashicons dashicons-lock"></span>
                                            <?php esc_html_e('Пароль задан константой DBSMTP_PASSWORD в wp-config.php.', 'devbrothers-smtp'); ?>
                                        </p>
                                    <?php else : ?>
                                        <?php $has_password = !empty($settings['password']); ?>
                                        <input type="password"
                                               name="dbsmtp_settings[password]"
                                               id="dbsmtp_password"
                                               class="regular-text"
                                               autocomplete="new-password"
                                               value=""
                                               placeholder="<?php echo esc_attr($has_password
                                                   ? __('Пароль сохранён — оставьте пустым, чтобы не менять', 'devbrothers-smtp')
                                                   : __('Введите пароль', 'devbrothers-smtp')); ?>" />
                                        <p class="dbsmtp-hint">
                                            <?php $has_password
                                                ? esc_html_e('Пароль уже сохранён. Заполните поле, только если хотите его изменить. Для надёжности можно задать константу DBSMTP_PASSWORD в wp-config.php.', 'devbrothers-smtp')
                                                : esc_html_e('Для надёжности можно вместо этого поля задать константу DBSMTP_PASSWORD в wp-config.php.', 'devbrothers-smtp'); ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>

                        <hr />

                        <h4 class="dbsmtp-subhead"><?php esc_html_e('Отправитель', 'devbrothers-smtp'); ?></h4>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="dbsmtp_from_email"><?php esc_html_e('Email отправителя (From)', 'devbrothers-smtp'); ?></label>
                                </th>
                                <td>
                                    <input type="email"
                                           name="dbsmtp_settings[from_email]"
                                           id="dbsmtp_from_email"
                                           class="regular-text"
                                           placeholder="info@example.com"
                                           value="<?php echo esc_attr($settings['from_email']); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dbsmtp_from_name"><?php esc_html_e('Имя отправителя', 'devbrothers-smtp'); ?></label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="dbsmtp_settings[from_name]"
                                           id="dbsmtp_from_name"
                                           class="regular-text"
                                           value="<?php echo esc_attr($settings['from_name']); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Принудительно', 'devbrothers-smtp'); ?></th>
                                <td>
                                    <label class="dbsmtp-inline-toggle">
                                        <input type="checkbox"
                                               name="dbsmtp_settings[force_from]"
                                               id="dbsmtp_force_from"
                                               value="1"
                                               <?php checked(!empty($settings['force_from'])); ?> />
                                        <?php esc_html_e('Перезаписывать отправителя во всех письмах', 'devbrothers-smtp'); ?>
                                    </label>
                                    <p class="dbsmtp-hint">
                                        <?php esc_html_e('Если выключено — подставляется только когда другой плагин не задал отправителя. Многие SMTP-серверы требуют, чтобы From совпадал с логином.', 'devbrothers-smtp'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <hr />

                        <h4 class="dbsmtp-subhead"><?php esc_html_e('Уведомление при сбое SMTP', 'devbrothers-smtp'); ?></h4>
                        <p class="dbsmtp-hint">
                            <?php esc_html_e('Если отправка через SMTP не удалась, администратору будет отправлено служебное письмо стандартной функцией PHP mail() (без SMTP). Повторные уведомления ограничены выбранным интервалом.', 'devbrothers-smtp'); ?>
                        </p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php esc_html_e('Fallback-уведомление', 'devbrothers-smtp'); ?></th>
                                <td>
                                    <label class="dbsmtp-inline-toggle">
                                        <input type="checkbox"
                                               name="dbsmtp_settings[fallback_alert_enabled]"
                                               id="dbsmtp_fallback_alert_enabled"
                                               value="1"
                                               <?php checked(!empty($settings['fallback_alert_enabled'])); ?> />
                                        <?php esc_html_e('Уведомлять администратора при ошибке SMTP', 'devbrothers-smtp'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dbsmtp_fallback_alert_email"><?php esc_html_e('Email администратора', 'devbrothers-smtp'); ?></label>
                                </th>
                                <td>
                                    <input type="email"
                                           name="dbsmtp_settings[fallback_alert_email]"
                                           id="dbsmtp_fallback_alert_email"
                                           class="regular-text"
                                           placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"
                                           value="<?php echo esc_attr($settings['fallback_alert_email']); ?>" />
                                    <p class="dbsmtp-hint">
                                        <?php esc_html_e('Если пусто — используется email администратора сайта.', 'devbrothers-smtp'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dbsmtp_fallback_alert_cooldown"><?php esc_html_e('Интервал повторов', 'devbrothers-smtp'); ?></label>
                                </th>
                                <td>
                                    <select name="dbsmtp_settings[fallback_alert_cooldown]" id="dbsmtp_fallback_alert_cooldown">
                                        <option value="15" <?php selected((int) $settings['fallback_alert_cooldown'], 15); ?>>
                                            <?php esc_html_e('15 минут', 'devbrothers-smtp'); ?>
                                        </option>
                                        <option value="30" <?php selected((int) $settings['fallback_alert_cooldown'], 30); ?>>
                                            <?php esc_html_e('30 минут', 'devbrothers-smtp'); ?>
                                        </option>
                                        <option value="60" <?php selected((int) $settings['fallback_alert_cooldown'], 60); ?>>
                                            <?php esc_html_e('1 час', 'devbrothers-smtp'); ?>
                                        </option>
                                        <option value="120" <?php selected((int) $settings['fallback_alert_cooldown'], 120); ?>>
                                            <?php esc_html_e('2 часа', 'devbrothers-smtp'); ?>
                                        </option>
                                    </select>
                                    <p class="dbsmtp-hint">
                                        <?php esc_html_e('Не чаще одного уведомления за выбранный период, чтобы не заспамить почту при массовых сбоях.', 'devbrothers-smtp'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <hr />

                        <h4 class="dbsmtp-subhead"><?php esc_html_e('Уведомления WordPress', 'devbrothers-smtp'); ?></h4>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php esc_html_e('Смена пароля', 'devbrothers-smtp'); ?></th>
                                <td>
                                    <label class="dbsmtp-inline-toggle">
                                        <input type="checkbox"
                                               name="dbsmtp_settings[disable_password_change_admin_email]"
                                               id="dbsmtp_disable_password_change_admin_email"
                                               value="1"
                                               <?php checked(!empty($settings['disable_password_change_admin_email'])); ?> />
                                        <?php esc_html_e('Не слать администратору письмо о смене пароля пользователя', 'devbrothers-smtp'); ?>
                                    </label>
                                    <p class="dbsmtp-hint">
                                        <?php esc_html_e('Отключает стандартное письмо WordPress на admin_email при сбросе пароля («Забыли пароль?»). Письмо самому пользователю не затрагивается. Работает и с WooCommerce.', 'devbrothers-smtp'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Категория: Telegram -->
            <div id="telegram" class="devbrothers-settings-category">
                <h2>
                    <span class="dashicons dashicons-format-chat"></span>
                    <?php esc_html_e('Уведомления в Telegram', 'devbrothers-smtp'); ?>
                </h2>

                <div class="dbsmtp-card dbsmtp-card-telegram<?php echo esc_attr($telegram_active ? ' dbsmtp-active' : ''); ?>">
                    <div class="dbsmtp-card-header">
                        <div class="dbsmtp-card-title">
                            <span class="dbsmtp-card-icon dbsmtp-card-icon-telegram"><span class="dashicons dashicons-format-chat"></span></span>
                            <div>
                                <h3><?php esc_html_e('Дублировать письма в Telegram', 'devbrothers-smtp'); ?></h3>
                                <span class="dbsmtp-card-status dbsmtp-telegram-status<?php echo esc_attr($telegram_active ? ' dbsmtp-status-on' : ''); ?>">
                                    <?php echo $telegram_active
                                        ? esc_html__('Включён', 'devbrothers-smtp')
                                        : esc_html__('Выключен', 'devbrothers-smtp'); ?>
                                </span>
                            </div>
                        </div>
                        <label class="dbsmtp-toggle">
                            <input type="checkbox"
                                   name="dbsmtp_settings[telegram_enabled]"
                                   id="dbsmtp_telegram_enabled"
                                   value="1"
                                   <?php checked($telegram_active); ?> />
                            <span class="dbsmtp-toggle-track">
                                <span class="dbsmtp-toggle-thumb"></span>
                            </span>
                        </label>
                    </div>

                    <div class="dbsmtp-card-body">
                        <p class="dbsmtp-hint">
                            <?php esc_html_e('При отправке писем через wp_mail содержимое будет отправляться в указанный Telegram-чат. Создайте бота через @BotFather, напишите ему /start и узнайте Chat ID через getUpdates или @userinfobot.', 'devbrothers-smtp'); ?>
                        </p>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="dbsmtp_telegram_bot_token"><?php esc_html_e('Токен бота', 'devbrothers-smtp'); ?></label>
                                </th>
                                <td>
                                    <?php if ($this->telegram_token_in_config()) : ?>
                                        <p class="description">
                                            <span class="dashicons dashicons-lock"></span>
                                            <?php esc_html_e('Токен задан константой DBSMTP_TELEGRAM_BOT_TOKEN в wp-config.php.', 'devbrothers-smtp'); ?>
                                        </p>
                                    <?php else : ?>
                                        <?php $has_token = !empty($settings['telegram_bot_token']); ?>
                                        <input type="password"
                                               name="dbsmtp_settings[telegram_bot_token]"
                                               id="dbsmtp_telegram_bot_token"
                                               class="large-text"
                                               autocomplete="off"
                                               value=""
                                               placeholder="<?php echo esc_attr($has_token
                                                   ? __('Токен сохранён — оставьте пустым, чтобы не менять', 'devbrothers-smtp')
                                                   : '123456789:ABCdefGHIjklMNOpqrsTUVwxyz'); ?>" />
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="dbsmtp_telegram_chat_id"><?php esc_html_e('Chat ID', 'devbrothers-smtp'); ?></label>
                                </th>
                                <td>
                                    <?php if ($this->telegram_chat_id_in_config()) : ?>
                                        <p class="description">
                                            <span class="dashicons dashicons-lock"></span>
                                            <?php esc_html_e('Chat ID задан константой DBSMTP_TELEGRAM_CHAT_ID в wp-config.php.', 'devbrothers-smtp'); ?>
                                        </p>
                                    <?php else : ?>
                                        <input type="text"
                                               name="dbsmtp_settings[telegram_chat_id]"
                                               id="dbsmtp_telegram_chat_id"
                                               class="regular-text"
                                               placeholder="123456789"
                                               value="<?php echo esc_attr($settings['telegram_chat_id']); ?>" />
                                        <p class="dbsmtp-hint">
                                            <?php esc_html_e('ID личного чата, группы или канала (для групп обычно отрицательное число).', 'devbrothers-smtp'); ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Когда уведомлять', 'devbrothers-smtp'); ?></th>
                                <td>
                                    <label class="dbsmtp-inline-toggle">
                                        <input type="checkbox"
                                               name="dbsmtp_settings[telegram_notify_success]"
                                               id="dbsmtp_telegram_notify_success"
                                               value="1"
                                               <?php checked(!empty($settings['telegram_notify_success'])); ?> />
                                        <?php esc_html_e('При успешной отправке', 'devbrothers-smtp'); ?>
                                    </label>
                                    <br />
                                    <label class="dbsmtp-inline-toggle">
                                        <input type="checkbox"
                                               name="dbsmtp_settings[telegram_notify_failed]"
                                               id="dbsmtp_telegram_notify_failed"
                                               value="1"
                                               <?php checked(!empty($settings['telegram_notify_failed'])); ?> />
                                        <?php esc_html_e('При ошибке отправки', 'devbrothers-smtp'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <p>
                            <button type="button" id="dbsmtp-test-telegram" class="button">
                                <span class="dashicons dashicons-format-chat"></span>
                                <?php esc_html_e('Отправить тест в Telegram', 'devbrothers-smtp'); ?>
                            </button>
                        </p>
                        <div id="dbsmtp-telegram-test-result" class="dbsmtp-test-result" style="display:none;"></div>
                    </div>
                </div>

                <p class="submit">
                    <button type="submit" name="dbsmtp_save_settings" class="button button-primary">
                        <span class="dashicons dashicons-yes"></span>
                        <?php esc_html_e('Сохранить настройки', 'devbrothers-smtp'); ?>
                    </button>
                </p>
            </div>
        </form>

        <!-- Категория: Тест отправки -->
        <div id="test" class="devbrothers-settings-category">
            <h2>
                <span class="dashicons dashicons-email"></span>
                <?php esc_html_e('Тест отправки', 'devbrothers-smtp'); ?>
            </h2>

            <div class="dbsmtp-card">
                <div class="dbsmtp-card-body">
                    <p class="dbsmtp-hint">
                        <?php esc_html_e('Отправьте тестовое письмо, чтобы убедиться, что настройки SMTP работают. Результат появится ниже и в журнале отправок.', 'devbrothers-smtp'); ?>
                    </p>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="dbsmtp_test_email"><?php esc_html_e('Email получателя', 'devbrothers-smtp'); ?></label>
                            </th>
                            <td>
                                <input type="email"
                                       id="dbsmtp_test_email"
                                       class="regular-text"
                                       placeholder="<?php echo esc_attr(wp_get_current_user()->user_email); ?>"
                                       value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" />
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" id="dbsmtp-send-test" class="button button-primary">
                            <span class="dashicons dashicons-email"></span>
                            <?php esc_html_e('Отправить тестовое письмо', 'devbrothers-smtp'); ?>
                        </button>
                    </p>
                    <div id="dbsmtp-test-result" class="dbsmtp-test-result" style="display:none;"></div>
                </div>
            </div>
        </div>

        <!-- Категория: Журнал отправок -->
        <div id="logs" class="devbrothers-settings-category">
            <h2>
                <span class="dashicons dashicons-list-view"></span>
                <?php esc_html_e('Журнал отправок', 'devbrothers-smtp'); ?>
            </h2>

            <div class="dbsmtp-card">
                <div class="dbsmtp-card-body">
                    <?php dbsmtp_plugin()->logger->render_logs_table(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Сохранение настроек.
     *
     * Nonce и права доступа проверяются в render_settings_page() перед вызовом.
     */
    private function save_settings_internal() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce проверяется в render_settings_page
        if (!isset($_POST['dbsmtp_settings']) || !is_array($_POST['dbsmtp_settings'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via sanitize_settings()
        $input     = wp_unslash($_POST['dbsmtp_settings']);
        $sanitized = $this->sanitize_settings($input);

        update_option($this->option_name, $sanitized);
    }

    /**
     * Санитизация всех полей настроек.
     *
     * @param array $input Сырые данные из формы.
     * @return array
     */
    public function sanitize_settings($input) {
        $current   = $this->get_settings();
        $sanitized = [];

        $sanitized['enabled'] = !empty($input['enabled']);

        $provider = isset($input['provider']) ? sanitize_key($input['provider']) : 'custom';
        $sanitized['provider'] = array_key_exists($provider, DBSMTP_Providers::get_presets())
            ? $provider
            : 'custom';

        $preset = DBSMTP_Providers::get_preset($sanitized['provider']);

        if ($preset !== null) {
            $sanitized['host']       = $preset['host'];
            $sanitized['port']       = (int) $preset['port'];
            $sanitized['encryption'] = $preset['encryption'];
            $sanitized['auth']       = !empty($preset['auth']);
        } else {
            $sanitized['host'] = isset($input['host'])
                ? sanitize_text_field($input['host'])
                : '';

            $port              = isset($input['port']) ? absint($input['port']) : 587;
            $sanitized['port'] = ($port >= 1 && $port <= 65535) ? $port : 587;

            $encryption = isset($input['encryption']) ? sanitize_key($input['encryption']) : 'tls';
            $sanitized['encryption'] = in_array($encryption, ['tls', 'ssl'], true) ? $encryption : 'tls';

            $sanitized['auth'] = !empty($input['auth']);
        }

        $sanitized['username'] = isset($input['username'])
            ? sanitize_text_field($input['username'])
            : '';

        // Пароль не санитизируем агрессивно (может содержать спецсимволы),
        // только убираем переносы строк (защита от инъекции заголовков).
        $current_password = isset($current['password']) ? $current['password'] : '';

        if ($this->password_in_config()) {
            // Поле скрыто — сохраняем прежнее значение из БД.
            $sanitized['password'] = $current_password;
        } else {
            $new_password = isset($input['password'])
                ? trim(str_replace(["\r", "\n"], '', (string) $input['password']))
                : '';

            // Пустое поле означает «не менять» — оставляем прежний пароль.
            $sanitized['password'] = $new_password !== '' ? $new_password : $current_password;
        }

        $sanitized['from_email'] = isset($input['from_email'])
            ? sanitize_email($input['from_email'])
            : '';

        $sanitized['from_name'] = isset($input['from_name'])
            ? sanitize_text_field($input['from_name'])
            : '';

        $sanitized['force_from'] = !empty($input['force_from']);

        $sanitized['telegram_enabled'] = !empty($input['telegram_enabled']);
        $sanitized['telegram_notify_success'] = !empty($input['telegram_notify_success']);
        $sanitized['telegram_notify_failed'] = !empty($input['telegram_notify_failed']);

        $current_token = isset($current['telegram_bot_token']) ? $current['telegram_bot_token'] : '';
        $current_chat  = isset($current['telegram_chat_id']) ? $current['telegram_chat_id'] : '';

        if ($this->telegram_token_in_config()) {
            // Поле скрыто — сохраняем прежнее значение из БД.
            $sanitized['telegram_bot_token'] = $current_token;
        } else {
            $new_token = isset($input['telegram_bot_token'])
                ? trim(str_replace(["\r", "\n"], '', (string) $input['telegram_bot_token']))
                : '';

            if ($new_token === '') {
                // Пустое поле означает «не менять».
                $sanitized['telegram_bot_token'] = $current_token;
            } elseif (preg_match('/^\d+:[A-Za-z0-9._-]+$/', $new_token)) {
                $sanitized['telegram_bot_token'] = $new_token;
            } else {
                // Некорректный формат — не затираем сохранённый токен.
                $sanitized['telegram_bot_token'] = $current_token;
            }
        }

        if ($this->telegram_chat_id_in_config()) {
            $sanitized['telegram_chat_id'] = $current_chat;
        } else {
            $chat_raw = isset($input['telegram_chat_id']) ? (string) $input['telegram_chat_id'] : '';
            $chat_raw = trim(str_replace(["\r", "\n", ' '], '', $chat_raw));

            if ($chat_raw === '') {
                $sanitized['telegram_chat_id'] = '';
            } elseif (preg_match('/^-?\d+$/', $chat_raw)) {
                $sanitized['telegram_chat_id'] = $chat_raw;
            } else {
                // Некорректный Chat ID — оставляем прежний.
                $sanitized['telegram_chat_id'] = $current_chat;
            }
        }

        $sanitized['disable_password_change_admin_email'] = !empty($input['disable_password_change_admin_email']);

        $sanitized['fallback_alert_enabled'] = !empty($input['fallback_alert_enabled']);

        $fallback_email = isset($input['fallback_alert_email'])
            ? sanitize_email($input['fallback_alert_email'])
            : '';
        $sanitized['fallback_alert_email'] = is_email($fallback_email) ? $fallback_email : '';

        $cooldown = isset($input['fallback_alert_cooldown']) ? (int) $input['fallback_alert_cooldown'] : 30;
        $allowed_cooldowns = [15, 30, 60, 120];
        $sanitized['fallback_alert_cooldown'] = in_array($cooldown, $allowed_cooldowns, true) ? $cooldown : 30;

        return $sanitized;
    }
}
