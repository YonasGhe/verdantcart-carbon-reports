<?php
defined('ABSPATH') || exit;

class VCARB_Export_Audit
{
    public static function table(): string
    {
        global $wpdb;

        /*
         * Keep legacy table name for existing 1.0.2 installations.
         * Do not rename this to vcarb_export_audit until you add a safe migration.
         */
        return $wpdb->prefix . 'amatorcarbon_export_audit';
    }

    public static function install_table(): void
    {
        global $wpdb;

        $table   = esc_sql(self::table());
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            actor_role VARCHAR(50) NOT NULL DEFAULT '',
            scope VARCHAR(20) NOT NULL DEFAULT 'user',
            format VARCHAR(10) NOT NULL DEFAULT 'csv',
            view VARCHAR(10) NOT NULL DEFAULT 'month',
            requested_date VARCHAR(20) NOT NULL DEFAULT '',
            resolved_anchor VARCHAR(20) NOT NULL DEFAULT '',
            action VARCHAR(60) NOT NULL DEFAULT '',
            result VARCHAR(20) NOT NULL DEFAULT 'ok',
            http_status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
            message TEXT NULL,
            ip VARCHAR(64) NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY actor_user_id (actor_user_id),
            KEY view (view),
            KEY result (result),
            KEY action (action),
            KEY scope (scope)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function log(array $row): void
    {
        global $wpdb;

        $table = esc_sql(self::table());

        $current_user_id = get_current_user_id();

        $defaults = [
            'created_at'      => current_time('mysql', true),
            'actor_user_id'   => $current_user_id,
            'actor_role'      => self::role_label($current_user_id),
            'scope'           => 'user',
            'format'          => 'csv',
            'view'            => 'month',
            'requested_date'  => '',
            'resolved_anchor' => '',
            'action'          => '',
            'result'          => 'ok',
            'http_status'     => 200,
            'message'         => '',
            'ip'              => self::ip(),
            'user_agent'      => self::ua(),
        ];

        $data = array_merge($defaults, $row);

        $insert_data = [
            'created_at'      => sanitize_text_field((string) $data['created_at']),
            'actor_user_id'   => absint($data['actor_user_id']),
            'actor_role'      => substr(sanitize_key((string) $data['actor_role']), 0, 50),
            'scope'           => substr(sanitize_key((string) $data['scope']), 0, 20),
            'format'          => substr(sanitize_key((string) $data['format']), 0, 10),
            'view'            => substr(sanitize_key((string) $data['view']), 0, 10),
            'requested_date'  => substr(sanitize_text_field((string) $data['requested_date']), 0, 20),
            'resolved_anchor' => substr(sanitize_text_field((string) $data['resolved_anchor']), 0, 20),
            'action'          => substr(sanitize_key((string) $data['action']), 0, 60),
            'result'          => substr(sanitize_key((string) $data['result']), 0, 20),
            'http_status'     => max(100, min(599, (int) $data['http_status'])),
            'message'         => sanitize_textarea_field((string) $data['message']),
            'ip'              => substr(sanitize_text_field((string) $data['ip']), 0, 64),
            'user_agent'      => substr(sanitize_text_field((string) $data['user_agent']), 0, 255),
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to plugin-owned custom audit table.
        $wpdb->insert(
            $table,
            $insert_data,
            [
                '%s', // created_at
                '%d', // actor_user_id
                '%s', // actor_role
                '%s', // scope
                '%s', // format
                '%s', // view
                '%s', // requested_date
                '%s', // resolved_anchor
                '%s', // action
                '%s', // result
                '%d', // http_status
                '%s', // message
                '%s', // ip
                '%s', // user_agent
            ]
        );
    }

    private static function role_label(int $user_id): string
    {
        if ($user_id <= 0) {
            return 'guest';
        }

        $user = get_userdata($user_id);

        if (!$user instanceof WP_User || empty($user->roles) || !is_array($user->roles)) {
            return 'user';
        }

        $role = reset($user->roles);

        return is_string($role) && $role !== ''
            ? sanitize_key($role)
            : 'user';
    }

    private static function ua(): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- User agent is unslashed and sanitized immediately below.
        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- User agent is unslashed and sanitized immediately.
        return sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
    }

    private static function ip(): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Remote address is unslashed and sanitized immediately below.
        if (empty($_SERVER['REMOTE_ADDR'])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Remote address is unslashed and sanitized immediately.
        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    }
}
