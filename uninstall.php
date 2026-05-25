<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

/**
 * VerdantCart Carbon Reports uninstall.
 *
 * Default behavior:
 * - Remove lightweight plugin runtime/options.
 * - Preserve reports, snapshots, audit logs, dashboard pages,
 *   user meta, and order meta.
 *
 * Important:
 * - Plugin data is intentionally preserved by default.
 * - No reporting tables are deleted automatically.
 * - No user/order/page data is removed automatically.
 */

/**
 * ------------------------------------------------------------
 * Remove plugin options
 * ------------------------------------------------------------
 */
$vcarb_options = [
    'vcarb_db_version',
    'vcarb_backfill_state',
    'vcarb_backfill_stop',
    'vcarb_dashboard_page_id',
];

foreach ($vcarb_options as $vcarb_option_name) {
    delete_option($vcarb_option_name);
    delete_site_option($vcarb_option_name);
}

/**
 * ------------------------------------------------------------
 * Clear plugin cron hooks
 * ------------------------------------------------------------
 */
if (function_exists('wp_clear_scheduled_hook')) {
    $vcarb_cron_hooks = [
        'vcarb_weekly_event',
        'vcarb_monthly_event',
        'vcarb_yearly_event',
        'vcarb_run_aggregate',
    ];

    foreach ($vcarb_cron_hooks as $vcarb_hook) {
        wp_clear_scheduled_hook($vcarb_hook);
    }
}

/**
 * ------------------------------------------------------------
 * Optional destructive cleanup blocks
 *
 * Disabled intentionally.
 *
 * Enable ONLY in private/commercial builds if merchants explicitly
 * request full data deletion on uninstall.
 * ------------------------------------------------------------
 */

/*
// Optional: remove plugin user meta.
global $wpdb;

if (isset($wpdb) && $wpdb instanceof wpdb) {
    $user_meta_keys = [
        'vcarb_plan',
    ];

    foreach ($user_meta_keys as $meta_key) {
        $wpdb->delete(
            $wpdb->usermeta,
            ['meta_key' => $meta_key],
            ['%s']
        );
    }
}
*/

/*
// Optional: remove plugin order meta.
global $wpdb;

if (isset($wpdb) && $wpdb instanceof wpdb) {
    $order_meta_keys = [
        '_vcarb_order_co2_kg',
        '_vcarb_order_co2_counted',
        '_vcarb_order_hotspots_counted',
        '_vcarb_order_co2_lock',
    ];

    foreach ($order_meta_keys as $meta_key) {
        $wpdb->delete(
            $wpdb->postmeta,
            ['meta_key' => $meta_key],
            ['%s']
        );
    }
}
*/

/*
// Optional: delete plugin-created pages.
global $wpdb;

if (isset($wpdb) && $wpdb instanceof wpdb) {
    $page_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT post_id
             FROM {$wpdb->postmeta}
             WHERE meta_key = %s
             AND meta_value = %s",
            '_vcarb_managed_page',
            '1'
        )
    );

    foreach ((array) $page_ids as $page_id) {
        wp_delete_post((int) $page_id, true);
    }
}
*/

/*
// Optional: drop plugin tables.
global $wpdb;

if (isset($wpdb) && $wpdb instanceof wpdb) {
    $tables = [
        $wpdb->prefix . 'vcarb_logs',
        $wpdb->prefix . 'vcarb_product_logs',
        $wpdb->prefix . 'vcarb_export_audit',
    ];

    foreach ($tables as $table) {
        $table = esc_sql($table);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
}
*/