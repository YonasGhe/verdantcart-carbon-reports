<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

/**
 * VerdantCart Carbon Reports uninstall.
 *
 * Default behavior:
 * - Remove lightweight plugin runtime/options.
 * - Preserve reports, snapshots, audit logs, dashboard pages, user meta, and order meta.
 *
 * Full data deletion is intentionally disabled by default.
 */

/**
 * ------------------------------------------------------------
 * Current VerdantCart options
 * ------------------------------------------------------------
 */
delete_option('vcarb_db_version');
delete_option('vcarb_backfill_state');
delete_option('vcarb_backfill_stop');
delete_option('vcarb_dashboard_page_id');

delete_site_option('vcarb_db_version');
delete_site_option('vcarb_backfill_state');
delete_site_option('vcarb_backfill_stop');
delete_site_option('vcarb_dashboard_page_id');

/**
 * ------------------------------------------------------------
 * Transitional AmatorCarbon options
 *
 * Kept because previous builds used these option names.
 * ------------------------------------------------------------
 */
delete_option('amatorcarbon_db_version');
delete_option('amatorcarbon_backfill_state');
delete_option('amatorcarbon_backfill_stop');
delete_option('amatorcarbon_dashboard_page_id');
delete_option('amatorcarbon_pricing_page_id');
delete_option('amatorcarbon_landing_page_id');

delete_site_option('amatorcarbon_db_version');
delete_site_option('amatorcarbon_backfill_state');
delete_site_option('amatorcarbon_backfill_stop');
delete_site_option('amatorcarbon_dashboard_page_id');
delete_site_option('amatorcarbon_pricing_page_id');
delete_site_option('amatorcarbon_landing_page_id');

/**
 * ------------------------------------------------------------
 * Legacy cleanup from older builds / migrations
 * ------------------------------------------------------------
 */
delete_option('ai_carbon_db_version');
delete_option('gc_ai_plan');
delete_option('gc_ai_backfill_state');
delete_option('gc_ai_backfill_stop');
delete_option('ai_carbon_dashboard_page_id');
delete_option('acr_dashboard_page_id');

delete_site_option('ai_carbon_db_version');
delete_site_option('gc_ai_plan');
delete_site_option('gc_ai_backfill_state');
delete_site_option('gc_ai_backfill_stop');
delete_site_option('ai_carbon_dashboard_page_id');
delete_site_option('acr_dashboard_page_id');

/**
 * ------------------------------------------------------------
 * Cron cleanup
 *
 * These hooks should also be cleared on deactivation, but uninstall can safely
 * clear them again.
 * ------------------------------------------------------------
 */
if (function_exists('wp_clear_scheduled_hook')) {
    $vcarb_cron_hooks = [
        // Current VCARB hooks.
        'vcarb_weekly_event',
        'vcarb_monthly_event',
        'vcarb_yearly_event',
        'vcarb_run_aggregate',

        // Transitional/old AmatorCarbon hooks.
        'amatorcarbon_weekly_event',
        'amatorcarbon_monthly_event',
        'amatorcarbon_yearly_event',
        'amatorcarbon_run_aggregate',

        // Older ACR hooks.
        'acr_weekly_event',
        'acr_monthly_event',
        'acr_yearly_event',
        'acr_run_aggregate',

        // Old AI Carbon / GreenCart hooks.
        'ai_carbon_weekly_event',
        'ai_carbon_monthly_event',
        'ai_carbon_yearly_event',
        'ai_carbon_run_aggregate',
        'gc_ai_weekly_event',
        'gc_ai_monthly_event',
        'gc_ai_yearly_event',
        'gc_ai_run_aggregate',
    ];

    foreach ($vcarb_cron_hooks as $vcarb_cron_hook) {
        wp_clear_scheduled_hook($vcarb_cron_hook);
    }
}

/*
 * Data is preserved by default on uninstall.
 *
 * Do not enable the blocks below in the WordPress.org release unless you clearly
 * document that uninstall deletes stored reporting data.
 */

/*
// Optional: remove user meta plan values from old/current builds.
global $wpdb;

if (isset($wpdb) && $wpdb instanceof wpdb) {
    $vcarb_user_meta_keys = [
        'vcarb_plan',
        'amatorcarbon_plan',
        'ai_plan',
    ];

    foreach ($vcarb_user_meta_keys as $vcarb_user_meta_key) {
        $wpdb->delete(
            $wpdb->usermeta,
            ['meta_key' => $vcarb_user_meta_key],
            ['%s']
        );
    }
}
*/

/*
// Optional: remove order meta from old/current builds.
global $wpdb;

if (isset($wpdb) && $wpdb instanceof wpdb) {
    $vcarb_order_meta_keys = [
        '_vcarb_order_co2_kg',
        '_vcarb_order_co2_counted',
        '_vcarb_order_hotspots_counted',
        '_vcarb_order_co2_lock',

        '_amatorcarbon_order_co2_kg',
        '_amatorcarbon_order_co2_counted',
        '_amatorcarbon_order_hotspots_counted',
        '_amatorcarbon_order_co2_lock',

        '_gc_order_co2_counted',
        '_gc_order_hotspots_counted',
        '_gc_order_co2_kg',
    ];

    foreach ($vcarb_order_meta_keys as $vcarb_order_meta_key) {
        $wpdb->delete(
            $wpdb->postmeta,
            ['meta_key' => $vcarb_order_meta_key],
            ['%s']
        );
    }
}
*/

/*
// Optional: delete plugin-created dashboard pages.
//
// Disabled by default because merchants may customize the dashboard page.
global $wpdb;

if (isset($wpdb) && $wpdb instanceof wpdb) {
    $vcarb_page_meta_keys = [
        '_vcarb_managed_page',
        'vcarb_plugin_page',

        '_amatorcarbon_managed_page',
        'amatorcarbon_plugin_page',
    ];

    foreach ($vcarb_page_meta_keys as $vcarb_page_meta_key) {
        $vcarb_page_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
                $vcarb_page_meta_key
            )
        );

        foreach ((array) $vcarb_page_ids as $vcarb_page_id) {
            wp_delete_post((int) $vcarb_page_id, true);
        }
    }
}
*/

/*
// Optional: drop plugin tables on uninstall.
global $wpdb;

if (isset($wpdb) && $wpdb instanceof wpdb) {
    $vcarb_tables = [
        // Current 1.1.0 still intentionally uses these table names
        // to preserve existing user data.
        $wpdb->prefix . 'amatorcarbon_logs',
        $wpdb->prefix . 'amatorcarbon_product_logs',
        $wpdb->prefix . 'amatorcarbon_export_audit',

        // Future renamed tables, if ever introduced.
        $wpdb->prefix . 'vcarb_logs',
        $wpdb->prefix . 'vcarb_product_logs',
        $wpdb->prefix . 'vcarb_export_audit',

        // Legacy tables from older builds.
        $wpdb->prefix . 'ai_carbon_logs',
        $wpdb->prefix . 'gc_product_logs',
        $wpdb->prefix . 'ai_carbon_export_audit',
        $wpdb->prefix . 'acr_logs',
    ];

    foreach ($vcarb_tables as $vcarb_table) {
        $vcarb_table = esc_sql($vcarb_table);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Optional uninstall cleanup for plugin-owned tables.
        $wpdb->query("DROP TABLE IF EXISTS {$vcarb_table}");
    }
}
*/