(function($) {
    'use strict';

    function initProviderPresets() {
        var $provider = $('#dbsmtp_provider');
        var $hint = $('#dbsmtp_provider_hint');
        var $presetFields = $('#dbsmtp_host, #dbsmtp_port, #dbsmtp_encryption, #dbsmtp_auth');

        if (!$provider.length) {
            return;
        }

        function updateHint() {
            var key = $provider.val();
            var preset = dbsmtpConfig.presets[key];

            if (key !== 'custom' && preset && preset.hint) {
                $hint.text(preset.hint).show();
            } else {
                $hint.hide().empty();
            }
        }

        function applyPreset() {
            var key = $provider.val();
            var preset = dbsmtpConfig.presets[key];
            var isPreset = key !== 'custom';

            $presetFields.prop('disabled', isPreset);

            if (isPreset && preset) {
                $('#dbsmtp_host').val(preset.host);
                $('#dbsmtp_port').val(preset.port);
                $('#dbsmtp_encryption').val(preset.encryption);
                $('#dbsmtp_auth').prop('checked', !!preset.auth).trigger('change');
            }

            updateHint();
        }

        $provider.on('change', applyPreset);
        applyPreset();
    }

    function initToggleCard() {
        var $card = $('#connection .dbsmtp-card');
        var $toggle = $('#dbsmtp_enabled');
        var $status = $card.find('.dbsmtp-card-status');

        if (!$toggle.length) {
            return;
        }

        function update() {
            var active = $toggle.is(':checked');
            $card.toggleClass('dbsmtp-active', active);
            $status
                .text(active ? dbsmtpConfig.strings.enabled : dbsmtpConfig.strings.disabled)
                .toggleClass('dbsmtp-status-on', active);
        }

        $toggle.on('change', update);
        update();
    }

    function initAuthFields() {
        var $auth = $('#dbsmtp_auth');
        var $fields = $('.dbsmtp-auth-field');

        if (!$auth.length) {
            return;
        }

        function update() {
            $fields.toggleClass('dbsmtp-hidden', !$auth.is(':checked'));
        }

        $auth.on('change', update);
        update();
    }

    function initTestEmail() {
        var $button = $('#dbsmtp-send-test');
        var $result = $('#dbsmtp-test-result');
        var $email = $('#dbsmtp_test_email');

        if (!$button.length) {
            return;
        }

        $button.on('click', function() {
            var email = $.trim($email.val());

            if (!email) {
                showResult(false, dbsmtpConfig.strings.emptyEmail);
                return;
            }

            var originalText = $button.html();
            $button.prop('disabled', true).text(dbsmtpConfig.strings.sending);
            $result.hide();

            $.post(dbsmtpConfig.ajaxUrl, {
                action: 'dbsmtp_send_test',
                nonce: dbsmtpConfig.testNonce,
                email: email
            }).done(function(response) {
                if (response && response.success) {
                    showResult(true, response.data.message);
                } else {
                    showResult(false, response && response.data ? response.data.message : dbsmtpConfig.strings.requestError);
                }
            }).fail(function() {
                showResult(false, dbsmtpConfig.strings.requestError);
            }).always(function() {
                $button.prop('disabled', false).html(originalText);
            });
        });

        function showResult(success, message) {
            $result
                .removeClass('dbsmtp-success dbsmtp-error')
                .addClass(success ? 'dbsmtp-success' : 'dbsmtp-error')
                .text(message)
                .show();
        }
    }

    function initClearLogs() {
        var $button = $('#dbsmtp-clear-logs');

        if (!$button.length) {
            return;
        }

        $button.on('click', function() {
            if (!window.confirm(dbsmtpConfig.strings.confirmClear)) {
                return;
            }

            $button.prop('disabled', true);

            $.post(dbsmtpConfig.ajaxUrl, {
                action: 'dbsmtp_clear_logs',
                nonce: dbsmtpConfig.logsNonce
            }).done(function(response) {
                if (response && response.success) {
                    var $wrapper = $('#dbsmtp-logs-table-wrapper');
                    $wrapper.html('<p class="dbsmtp-logs-empty">' + dbsmtpConfig.strings.cleared + '</p>');
                }
            }).always(function() {
                $button.prop('disabled', false);
            });
        });
    }

    $(function() {
        initProviderPresets();
        initToggleCard();
        initAuthFields();
        initTestEmail();
        initClearLogs();
    });

})(jQuery);
