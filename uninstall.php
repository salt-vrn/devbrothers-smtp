<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package DevBrothers_SMTP
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/class-dbsmtp-logger.php';

delete_option('dbsmtp_settings');
delete_option('dbsmtp_db_version');

DBSMTP_Logger::drop_table();
