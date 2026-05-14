<?php

/**
 * Plugin Name: VerdantCart Carbon Reports
 * Description: Carbon analytics and reporting for WooCommerce stores.
 * Version: 1.2.0
 * Author: Yonas
 * Text Domain: verdantcart-ai-reports
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.7
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

/**
 * ------------------------------------------------------------
 * WooCommerce compatibility
 * ------------------------------------------------------------
 */
function vcarb_declare_wc_compatibility(): void
{
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
}
add_action('before_woocommerce_init', 'vcarb_declare_wc_compatibility');

/**
 * ------------------------------------------------------------
 * Constants
 * ------------------------------------------------------------
 */
defined('VCARB_VERSION') || define('VCARB_VERSION', '1.2.0');
defined('VCARB_DB_VERSION') || define('VCARB_DB_VERSION', '1.2.0');
defined('VCARB_PLUGIN_FILE') || define('VCARB_PLUGIN_FILE', __FILE__);
defined('VCARB_PLUGIN_DIR') || define('VCARB_PLUGIN_DIR', plugin_dir_path(__FILE__));
defined('VCARB_PLUGIN_URL') || define('VCARB_PLUGIN_URL', plugin_dir_url(__FILE__));
defined('VCARB_TEXT_DOMAIN') || define('VCARB_TEXT_DOMAIN', 'verdantcart-ai-reports');

/**
 * ------------------------------------------------------------
 * Central table helpers
 * ------------------------------------------------------------
 */
if (!function_exists('vcarb_logs_table')) {
    function vcarb_logs_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'vcarb_logs';
    }
}

if (!function_exists('vcarb_product_logs_table')) {
    function vcarb_product_logs_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'vcarb_product_logs';
    }
}

if (!function_exists('vcarb_export_audit_table')) {
    function vcarb_export_audit_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'vcarb_export_audit';
    }
}

if (!function_exists('vcarb_legacy_logs_table')) {
    function vcarb_legacy_logs_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'amatorcarbon_logs';
    }
}

if (!function_exists('vcarb_legacy_product_logs_table')) {
    function vcarb_legacy_product_logs_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'amatorcarbon_product_logs';
    }
}

if (!function_exists('vcarb_legacy_export_audit_table')) {
    function vcarb_legacy_export_audit_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'amatorcarbon_export_audit';
    }
}

/**
 * ------------------------------------------------------------
 * Includes
 * ------------------------------------------------------------
 */
function vcarb_require_files(): void
{
    $files = [
        'includes/helpers.php',

        'includes/trait-vcarb-ajax-helpers.php',
        'includes/trait-vcarb-snapshot.php',
        'includes/trait-vcarb-period-trait.php',

        'includes/class-vcarb-reports-activator.php',
        'includes/class-vcarb-plugin-pages-admin.php',
        'includes/class-vcarb-reports-deactivator.php',

        'includes/class-vcarb-access.php',
        'includes/class-vcarb-capabilities.php',

        'includes/class-vcarb-insights.php',
        'includes/class-vcarb-insights-renderer.php',
        'includes/class-vcarb-export-audit.php',
        'includes/class-vcarb-calculator.php',
        'includes/class-vcarb-scheduler.php',
        'includes/class-vcarb-product-insights.php',
        'includes/class-vcarb-order-tracker.php',

        'includes/class-vcarb-live-week-repository.php',
        'includes/class-vcarb-dataset-service.php',
        'includes/class-vcarb-sustainability-summary.php',
        'includes/class-vcarb-admin-report-ajax.php',
        'includes/class-vcarb-insights-ajax.php',
        'includes/class-vcarb-backfill-ajax.php',

        'includes/vcarb-admin-guard.php',
        'includes/vcarb-access-guards.php',
        'includes/vcarb-urls.php',

        'includes/class-vcarb-exports.php',
        'includes/class-vcarb-reports-admin.php',
        'includes/class-vcarb-front-context.php',

        'public/class-vcarb-dashboard.php',
    ];

    foreach ($files as $file) {
        $path = VCARB_PLUGIN_DIR . $file;

        if (!is_readable($path)) {
            continue;
        }

        require_once $path;
    }
}
vcarb_require_files();

/**
 * ------------------------------------------------------------
 * Register activator/deactivator hooks
 * ------------------------------------------------------------
 */
if (class_exists('VCARB_Reports_Activator')) {
    VCARB_Reports_Activator::register_hooks(VCARB_PLUGIN_FILE);
}

register_activation_hook(VCARB_PLUGIN_FILE, 'vcarb_migrate_db');
register_activation_hook(VCARB_PLUGIN_FILE, 'vcarb_schedule_cron_events');

/**
 * ------------------------------------------------------------
 * Dependency helpers
 * ------------------------------------------------------------
 */
function vcarb_wc_active(): bool
{
    return class_exists('WooCommerce') || function_exists('WC');
}

function vcarb_admin_notice_wc_missing(): void
{
    if (vcarb_wc_active()) {
        return;
    }

    echo '<div class="notice notice-error"><p><strong>' .
        esc_html__('VerdantCart Carbon Reports:', 'verdantcart-ai-reports') .
        '</strong> ' .
        esc_html__('WooCommerce is required for this plugin to run.', 'verdantcart-ai-reports') .
        '</p></div>';
}

/**
 * ------------------------------------------------------------
 * DB helpers
 * ------------------------------------------------------------
 */
function vcarb_table_exists(string $table): bool
{
    global $wpdb;

    $table = trim($table);

    if ($table === '') {
        return false;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking plugin-owned table existence.
    $found = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like($table)
        )
    );

    return is_string($found) && $found === $table;
}

function vcarb_install_logs_table(): void
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table           = esc_sql(vcarb_logs_table());
    $charset_collate = $wpdb->get_charset_collate();

    dbDelta("
        CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            view_type VARCHAR(10) NOT NULL DEFAULT 'month',
            period VARCHAR(20) NOT NULL,
            orders INT UNSIGNED NOT NULL DEFAULT 0,
            total_co2 DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_view_period (user_id, view_type, period),
            KEY view_period (view_type, period),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) {$charset_collate};
    ");
}

function vcarb_install_product_logs_table(): void
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table           = esc_sql(vcarb_product_logs_table());
    $charset_collate = $wpdb->get_charset_collate();

    dbDelta("
        CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            period VARCHAR(20) NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            orders INT UNSIGNED NOT NULL DEFAULT 0,
            total_co2 DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_period_product (period, product_id),
            KEY period (period),
            KEY product_id (product_id)
        ) {$charset_collate};
    ");
}

function vcarb_install_export_audit_table(): void
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table           = esc_sql(vcarb_export_audit_table());
    $charset_collate = $wpdb->get_charset_collate();

    dbDelta("
        CREATE TABLE {$table} (
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
        ) {$charset_collate};
    ");
}

/**
 * ------------------------------------------------------------
 * Legacy to VCARB data migration
 *
 * Safe behavior:
 * - Creates new vcarb_* tables.
 * - Copies from old amatorcarbon_* tables if they exist.
 * - Does not delete old tables.
 * - Uses INSERT IGNORE so re-running migration does not duplicate rows.
 * ------------------------------------------------------------
 */
function vcarb_migrate_legacy_tables_to_vcarb(): void
{
    global $wpdb;

    $new_logs     = esc_sql(vcarb_logs_table());
    $old_logs     = esc_sql(vcarb_legacy_logs_table());
    $new_products = esc_sql(vcarb_product_logs_table());
    $old_products = esc_sql(vcarb_legacy_product_logs_table());
    $new_audit    = esc_sql(vcarb_export_audit_table());
    $old_audit    = esc_sql(vcarb_legacy_export_audit_table());

    if (vcarb_table_exists(vcarb_legacy_logs_table()) && vcarb_table_exists(vcarb_logs_table())) {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Copying between plugin-owned tables.
        $wpdb->query("
            INSERT IGNORE INTO `{$new_logs}`
                (id, user_id, view_type, period, orders, total_co2, created_at)
            SELECT
                id,
                user_id,
                COALESCE(NULLIF(view_type, ''), 'month') AS view_type,
                period,
                orders,
                total_co2,
                created_at
            FROM `{$old_logs}`
        ");
        // phpcs:enable
    }

    if (vcarb_table_exists(vcarb_legacy_product_logs_table()) && vcarb_table_exists(vcarb_product_logs_table())) {
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Copying between plugin-owned tables.
        $wpdb->query("
            INSERT IGNORE INTO `{$new_products}`
                (id, period, product_id, orders, total_co2, created_at)
            SELECT
                id,
                period,
                product_id,
                orders,
                total_co2,
                created_at
            FROM `{$old_products}`
        ");
        // phpcs:enable
    }

    if (vcarb_table_exists(vcarb_legacy_export_audit_table()) && vcarb_table_exists(vcarb_export_audit_table())) {
        /*
         * Old audit tables may not have ip/user_agent columns.
         * Use empty values for those new 1.2.0 columns during migration.
         */
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Copying between plugin-owned audit tables.
        $wpdb->query("
            INSERT IGNORE INTO `{$new_audit}`
                (
                    id,
                    created_at,
                    actor_user_id,
                    actor_role,
                    scope,
                    format,
                    view,
                    requested_date,
                    resolved_anchor,
                    action,
                    result,
                    http_status,
                    message,
                    ip,
                    user_agent
                )
            SELECT
                id,
                created_at,
                actor_user_id,
                actor_role,
                scope,
                format,
                view,
                requested_date,
                resolved_anchor,
                action,
                result,
                http_status,
                message,
                '' AS ip,
                '' AS user_agent
            FROM `{$old_audit}`
        ");
        // phpcs:enable
    }
}

/**
 * ------------------------------------------------------------
 * DB migration
 * ------------------------------------------------------------
 */
function vcarb_migrate_db(): void
{
    global $wpdb;

    vcarb_install_logs_table();
    vcarb_install_product_logs_table();
    vcarb_install_export_audit_table();

    vcarb_migrate_legacy_tables_to_vcarb();

    /*
     * Repair old imported rows that may not have view_type set.
     */
    $logs_table = esc_sql(vcarb_logs_table());

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query(
        "UPDATE `{$logs_table}`
         SET view_type = 'week'
         WHERE (view_type = '' OR view_type IS NULL)
           AND period REGEXP '^[0-9]{4}-W[0-9]{2}$'"
    );

    $wpdb->query(
        "UPDATE `{$logs_table}`
         SET view_type = 'year'
         WHERE (view_type = '' OR view_type IS NULL)
           AND period REGEXP '^[0-9]{4}$'"
    );

    $wpdb->query(
        "UPDATE `{$logs_table}`
         SET view_type = 'month'
         WHERE (view_type = '' OR view_type IS NULL)
           AND period REGEXP '^[0-9]{4}-[0-9]{2}$'"
    );
    // phpcs:enable

    update_option('vcarb_db_version', VCARB_DB_VERSION, false);

    /*
     * Legacy marker kept so transitional installs do not repeatedly
     * think the database is outdated.
     */
    update_option('amatorcarbon_db_version', VCARB_DB_VERSION, false);
}

/**
 * ------------------------------------------------------------
 * Cron
 * ------------------------------------------------------------
 */
function vcarb_register_cron_schedules(array $schedules): array
{
    if (!isset($schedules['weekly'])) {
        $schedules['weekly'] = [
            'interval' => 7 * DAY_IN_SECONDS,
            'display'  => __('Once Weekly', 'verdantcart-ai-reports'),
        ];
    }

    if (!isset($schedules['monthly'])) {
        $schedules['monthly'] = [
            'interval' => defined('MONTH_IN_SECONDS') ? MONTH_IN_SECONDS : (30 * DAY_IN_SECONDS),
            'display'  => __('Once Monthly', 'verdantcart-ai-reports'),
        ];
    }

    if (!isset($schedules['yearly'])) {
        $schedules['yearly'] = [
            'interval' => defined('YEAR_IN_SECONDS') ? YEAR_IN_SECONDS : (365 * DAY_IN_SECONDS),
            'display'  => __('Once Yearly', 'verdantcart-ai-reports'),
        ];
    }

    return $schedules;
}

function vcarb_schedule_cron_events(): void
{
    $now = time();

    /*
     * Clear old cron hooks to prevent duplicate scheduled events.
     */
    wp_clear_scheduled_hook('amatorcarbon_weekly_event');
    wp_clear_scheduled_hook('amatorcarbon_monthly_event');
    wp_clear_scheduled_hook('amatorcarbon_yearly_event');

    wp_clear_scheduled_hook('acr_weekly_event');
    wp_clear_scheduled_hook('acr_monthly_event');
    wp_clear_scheduled_hook('acr_yearly_event');

    wp_clear_scheduled_hook('ai_carbon_weekly_event');
    wp_clear_scheduled_hook('ai_carbon_monthly_event');
    wp_clear_scheduled_hook('ai_carbon_yearly_event');

    wp_clear_scheduled_hook('gc_ai_weekly_event');
    wp_clear_scheduled_hook('gc_ai_monthly_event');
    wp_clear_scheduled_hook('gc_ai_yearly_event');

    if (!wp_next_scheduled('vcarb_weekly_event')) {
        wp_schedule_event($now + HOUR_IN_SECONDS, 'weekly', 'vcarb_weekly_event');
    }

    if (!wp_next_scheduled('vcarb_monthly_event')) {
        wp_schedule_event($now + (2 * HOUR_IN_SECONDS), 'monthly', 'vcarb_monthly_event');
    }

    if (!wp_next_scheduled('vcarb_yearly_event')) {
        wp_schedule_event($now + (3 * HOUR_IN_SECONDS), 'yearly', 'vcarb_yearly_event');
    }
}

function vcarb_handle_periodic_event(string $view): void
{
    if (!class_exists('VCARB_Calculator') || !class_exists('VCARB_Scheduler')) {
        return;
    }

    $view = in_array($view, ['week', 'month', 'year'], true) ? $view : 'month';

    $periods = VCARB_Calculator::get_period_keys($view);
    $current = isset($periods['current']) ? (string) $periods['current'] : '';

    if ($current !== '') {
        VCARB_Scheduler::schedule_aggregate_debounced($view, $current);
    }
}

function vcarb_handle_weekly_event(): void
{
    vcarb_handle_periodic_event('week');
}

function vcarb_handle_monthly_event(): void
{
    vcarb_handle_periodic_event('month');
}

function vcarb_handle_yearly_event(): void
{
    vcarb_handle_periodic_event('year');
}

/**
 * ------------------------------------------------------------
 * DB upgrade check
 * ------------------------------------------------------------
 */
function vcarb_maybe_upgrade_db(): void
{
    $installed = (string) get_option('vcarb_db_version', '');

    if ($installed === '') {
        $installed = (string) get_option('amatorcarbon_db_version', '');
    }

    if ($installed !== VCARB_DB_VERSION) {
        vcarb_migrate_db();
    }
}
add_action('plugins_loaded', 'vcarb_maybe_upgrade_db', 5);

/**
 * ------------------------------------------------------------
 * Bootstrap
 * ------------------------------------------------------------
 */
function vcarb_register_default_filters(): void
{
    /*
     * No forced defaults here.
     * Calculation classes already pass safe default values to apply_filters().
     */
}

function vcarb_bootstrap(): void
{
    vcarb_register_default_filters();

    if (!vcarb_wc_active()) {
        if (is_admin()) {
            add_action('admin_notices', 'vcarb_admin_notice_wc_missing');
        }

        return;
    }

    if (class_exists('VCARB_Plugin_Pages_Admin')) {
        VCARB_Plugin_Pages_Admin::init();
    }

    if (class_exists('VCARB_Admin_Guard')) {
        VCARB_Admin_Guard::boot();
    }

    if (class_exists('VCARB_Order_Tracker')) {
        VCARB_Order_Tracker::register();
    }

    if (class_exists('VCARB_Scheduler') && method_exists('VCARB_Scheduler', 'register_hooks')) {
        VCARB_Scheduler::register_hooks();
    }

    if (class_exists('VCARB_Admin_Report_Ajax') && method_exists('VCARB_Admin_Report_Ajax', 'register')) {
        VCARB_Admin_Report_Ajax::register();
    }

    if (class_exists('VCARB_Insights_Ajax') && method_exists('VCARB_Insights_Ajax', 'register')) {
        VCARB_Insights_Ajax::register();
    }

    if (class_exists('VCARB_Backfill_Ajax') && method_exists('VCARB_Backfill_Ajax', 'register')) {
        VCARB_Backfill_Ajax::register();
    }

    if (class_exists('VCARB_Front_Context') && method_exists('VCARB_Front_Context', 'register_hooks')) {
        VCARB_Front_Context::register_hooks();
    }

    if (class_exists('VCARB_Dashboard')) {
        $GLOBALS['vcarb_reports_dashboard'] = $GLOBALS['vcarb_reports_dashboard'] ?? new VCARB_Dashboard();
    }

    if (class_exists('VCARB_Exports')) {
        $GLOBALS['vcarb_reports_exports'] = $GLOBALS['vcarb_reports_exports'] ?? new VCARB_Exports();
    }
}
add_action('plugins_loaded', 'vcarb_bootstrap', 20);

/**
 * ------------------------------------------------------------
 * Admin menu
 * ------------------------------------------------------------
 */
function vcarb_register_admin_menu(): void
{
    if (!class_exists('VCARB_Reports_Admin')) {
        return;
    }

    if (
        !isset($GLOBALS['vcarb_reports_admin']) ||
        !($GLOBALS['vcarb_reports_admin'] instanceof VCARB_Reports_Admin)
    ) {
        $GLOBALS['vcarb_reports_admin'] = new VCARB_Reports_Admin();
    }

    $admin = $GLOBALS['vcarb_reports_admin'];

    add_menu_page(
        __('VerdantCart Carbon Reports', 'verdantcart-ai-reports'),
        __('VerdantCart', 'verdantcart-ai-reports'),
        'manage_options',
        'verdantcart-carbon-reports',
        ['VCARB_Reports_Admin', 'render_settings_page'],
        'dashicons-leaf',
        56
    );

    add_submenu_page(
        'verdantcart-carbon-reports',
        __('Overview', 'verdantcart-ai-reports'),
        __('Overview', 'verdantcart-ai-reports'),
        'manage_options',
        'verdantcart-carbon-reports',
        ['VCARB_Reports_Admin', 'render_settings_page']
    );

    add_submenu_page(
        'verdantcart-carbon-reports',
        __('All Customers', 'verdantcart-ai-reports'),
        __('All Customers', 'verdantcart-ai-reports'),
        'manage_options',
        'vcarb-all-customers',
        [$admin, 'render_all_customers_page']
    );

    add_submenu_page(
        'verdantcart-carbon-reports',
        __('Dashboard', 'verdantcart-ai-reports'),
        __('Dashboard', 'verdantcart-ai-reports'),
        'manage_options',
        'vcarb-front-dashboard',
        ['VCARB_Reports_Admin', 'render_front_dashboard_menu_page']
    );

    add_submenu_page(
        'verdantcart-carbon-reports',
        __('Sustainability Report', 'verdantcart-ai-reports'),
        __('Report', 'verdantcart-ai-reports'),
        'manage_options',
        'vcarb-sustainability-summary',
        [$admin, 'render_sustainability_summary_page']
    );

    add_submenu_page(
        'verdantcart-carbon-reports',
        __('Backfill', 'verdantcart-ai-reports'),
        __('Backfill', 'verdantcart-ai-reports'),
        'manage_options',
        'vcarb-backfill',
        [$admin, 'render_backfill_page']
    );
}

/**
 * ------------------------------------------------------------
 * Hooks
 * ------------------------------------------------------------
 */
add_filter('cron_schedules', 'vcarb_register_cron_schedules');

add_action('vcarb_weekly_event', 'vcarb_handle_weekly_event');
add_action('vcarb_monthly_event', 'vcarb_handle_monthly_event');
add_action('vcarb_yearly_event', 'vcarb_handle_yearly_event');

add_action('admin_menu', 'vcarb_register_admin_menu');
