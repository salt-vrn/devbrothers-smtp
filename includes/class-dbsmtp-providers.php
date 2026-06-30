<?php
/**
 * Пресеты SMTP-провайдеров
 *
 * @package DevBrothers_SMTP
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBSMTP_Providers {

    /**
     * Список поддерживаемых провайдеров и их SMTP-параметров.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_presets() {
        return [
            'yandex' => [
                'label'      => __('Яндекс Почта', 'devbrothers-smtp'),
                'host'       => 'smtp.yandex.ru',
                'port'       => 465,
                'encryption' => 'ssl',
                'auth'       => true,
                'hint'       => __(
                    'Логин — полный адрес почты (например, user@yandex.ru). Если включена двухфакторная аутентификация, создайте пароль приложения в настройках Яндекс ID. Адрес отправителя (From) должен совпадать с почтовым ящиком.',
                    'devbrothers-smtp'
                ),
            ],
            'mailru' => [
                'label'      => __('Mail.ru', 'devbrothers-smtp'),
                'host'       => 'smtp.mail.ru',
                'port'       => 465,
                'encryption' => 'ssl',
                'auth'       => true,
                'hint'       => __(
                    'Логин — полный адрес почты (например, user@mail.ru). При включённой двухфакторной защите используйте пароль для внешнего приложения. Адрес отправителя (From) должен совпадать с почтовым ящиком.',
                    'devbrothers-smtp'
                ),
            ],
            'custom' => [
                'label' => __('Свой сервер', 'devbrothers-smtp'),
            ],
        ];
    }

    /**
     * Параметры подключения для выбранного провайдера.
     *
     * @param string $provider Ключ провайдера.
     * @return array<string, mixed>|null
     */
    public static function get_preset($provider) {
        $presets = self::get_presets();

        if (!isset($presets[ $provider ]) || $provider === 'custom') {
            return null;
        }

        return $presets[ $provider ];
    }

    /**
     * Данные пресетов для передачи в JavaScript (без label).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_presets_for_js() {
        $presets = self::get_presets();
        $js      = [];

        foreach ($presets as $key => $preset) {
            if ($key === 'custom') {
                continue;
            }

            $js[ $key ] = [
                'host'       => $preset['host'],
                'port'       => $preset['port'],
                'encryption' => $preset['encryption'],
                'auth'       => $preset['auth'],
                'hint'       => $preset['hint'],
            ];
        }

        return $js;
    }
}
