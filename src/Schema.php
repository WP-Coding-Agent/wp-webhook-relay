<?php
declare(strict_types=1);

namespace WebhookRelay;

/**
 * Database schema management via dbDelta.
 */
final class Schema
{
    public static function install(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $subs    = $wpdb->prefix . WEBHOOK_RELAY_TABLE_SUBS;
        $queue   = $wpdb->prefix . WEBHOOK_RELAY_TABLE_QUEUE;
        $log     = $wpdb->prefix . WEBHOOK_RELAY_TABLE_LOG;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$subs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            url VARCHAR(2048) NOT NULL,
            events TEXT NOT NULL,
            secret VARCHAR(255) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_active (active)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$queue} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscription_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(255) NOT NULL,
            payload LONGTEXT NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
            next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending','processing','delivered','failed') NOT NULL DEFAULT 'pending',
            locked_until DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status_next (status, next_attempt_at),
            KEY idx_subscription (subscription_id)
        ) {$charset};" );

        dbDelta( "CREATE TABLE {$log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            queue_id BIGINT UNSIGNED NOT NULL,
            subscription_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(255) NOT NULL,
            status_code SMALLINT DEFAULT NULL,
            response_body TEXT DEFAULT NULL,
            response_time_ms INT UNSIGNED DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_queue (queue_id),
            KEY idx_subscription_event (subscription_id, event)
        ) {$charset};" );
    }
}
