<?php
defined('ABSPATH') || exit;

/**
 * VerdantCart Carbon Reports activation / page provisioner.
 *
 * Responsibilities:
 * - Register activation hooks
 * - Run install and migration tasks
 * - Provision only required plugin pages safely
 * - Support multisite/network activation
 * - Prevent duplicate provisioning with a lock
 *
 * Safety rules:
 * - Only plugin-managed pages are auto-reused or updated
 * - Never hijack unrelated existing pages
 * - Keep page creation minimal to avoid cluttering merchant sites
 *
 * Migration note:
 * - New internal prefix is VCARB_/vcarb_.
 * - Legacy AmatorCarbon/amatorcarbon option/meta keys are read only as fallback
 *   so existing published users do not lose their dashboard page connection.
 */
final class VCARB_Reports_Activator
{
    public const OPT_DASHBOARD_ID = 'vcarb_dashboard_page_id';

    public const SLUG_DASHBOARD = 'verdantcart-dashboard';

    public const META_MANAGED_PAGE = '_vcarb_managed_page';
    public const META_PAGE_ROLE    = '_vcarb_page_role';

    private const LEGACY_OPT_DASHBOARD_ID = 'amatorcarbon_dashboard_page_id';

    private const LEGACY_META_MANAGED_PAGE = '_amatorcarbon_managed_page';
    private const LEGACY_META_PAGE_ROLE    = '_amatorcarbon_page_role';

    private const LOCK_KEY_BASE = 'vcarb_page_provision_lock';
    private const LOCK_TTL      = 30;

    public static function register_hooks(string $plugin_file): void
    {
        register_activation_hook($plugin_file, [__CLASS__, 'on_activation']);

        /*
         * Optional safe repair:
         * Keep disabled if you only want page creation on activation.
         * Enable if you want the plugin to recreate the dashboard page
         * when the stored page was deleted or trashed.
         */
        // add_action('admin_init', [__CLASS__, 'repair_if_missing']);
    }

    /**
     * Plugin activation callback.
     *
     * @param mixed $network_wide Whether plugin was network activated.
     */
    public static function on_activation($network_wide): void
    {
        if (is_multisite() && !empty($network_wide)) {
            $site_ids = get_sites([
                'fields' => 'ids',
                'number' => 0,
            ]);

            foreach ($site_ids as $site_id) {
                switch_to_blog((int) $site_id);

                try {
                    self::install_site();
                } finally {
                    restore_current_blog();
                }
            }

            return;
        }

        self::install_site();
    }

    public static function repair_if_missing(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (!self::needs_repair()) {
            return;
        }

        self::provision_pages();
    }

    private static function install_site(): void
    {
        if (function_exists('vcarb_migrate_db')) {
            vcarb_migrate_db();
        }

        if (
            class_exists('VCARB_Export_Audit') &&
            method_exists('VCARB_Export_Audit', 'install_table')
        ) {
            VCARB_Export_Audit::install_table();
        }

        if (function_exists('vcarb_schedule_cron_events')) {
            vcarb_schedule_cron_events();
        }

        self::provision_pages();

        flush_rewrite_rules(false);
    }

    private static function needs_repair(): bool
    {
        $dashboard_id = self::get_dashboard_page_id_option();

        return !self::is_valid_managed_page_id($dashboard_id, 'dashboard');
    }

    private static function provision_pages(): void
    {
        if (!self::acquire_lock()) {
            return;
        }

        try {
            self::ensure_page(
                self::OPT_DASHBOARD_ID,
                self::SLUG_DASHBOARD,
                __('VerdantCart Dashboard', 'verdantcart-ai-reports'),
                '[vcarb_dashboard]',
                'dashboard'
            );
        } finally {
            self::release_lock();
        }
    }

    private static function ensure_page(
        string $option_key,
        string $slug,
        string $title,
        string $content,
        string $role
    ): int {
        $stored_id = self::get_dashboard_page_id_option();

        if (self::is_valid_managed_page_id($stored_id, $role)) {
            self::mark_page_as_managed($stored_id, $role);
            self::ensure_shortcode_present($stored_id, $content);
            update_option($option_key, $stored_id, false);

            return $stored_id;
        }

        /*
         * If the stored option points to a normal existing page,
         * do not claim it. Clear the new option and continue safely.
         */
        if ($stored_id > 0 && self::is_valid_page_id($stored_id) && !self::is_managed_page($stored_id)) {
            delete_option($option_key);
        }

        $existing = get_page_by_path($slug, OBJECT, 'page');

        if ($existing instanceof WP_Post && $existing->post_status !== 'trash') {
            if (self::is_managed_page((int) $existing->ID)) {
                $page_id = (int) $existing->ID;

                self::mark_page_as_managed($page_id, $role);
                self::ensure_shortcode_present($page_id, $content);
                update_option($option_key, $page_id, false);

                return $page_id;
            }

            /*
             * A merchant/page-builder page already owns this slug.
             * Never hijack it; create a unique plugin page instead.
             */
            $slug = self::unique_plugin_slug($slug);
        }

        $page_id = wp_insert_post(
            [
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'post_title'     => $title,
                'post_name'      => $slug,
                'post_content'   => $content . "\n",
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ],
            true
        );

        if (is_wp_error($page_id) || (int) $page_id <= 0) {
            return 0;
        }

        $page_id = (int) $page_id;

        self::mark_page_as_managed($page_id, $role);
        update_option($option_key, $page_id, false);

        return $page_id;
    }

    private static function ensure_shortcode_present(int $page_id, string $required_shortcode): void
    {
        if ($page_id <= 0 || !self::is_managed_page($page_id)) {
            return;
        }

        $post = get_post($page_id);

        if (
            !($post instanceof WP_Post) ||
            $post->post_type !== 'page' ||
            $post->post_status === 'trash'
        ) {
            return;
        }

        $content = (string) $post->post_content;

        /*
     * Do not append the new shortcode if any supported dashboard shortcode
     * already exists. This prevents duplicate dashboard output on migrated pages.
     */
        $dashboard_shortcodes = [
            'vcarb_dashboard',
            'verdantcart_dashboard',
            'verdantcart_carbon_dashboard',
            'amatorcarbon_dashboard',
            'amator_carbon_dashboard',
            'acr_dashboard',
        ];

        foreach ($dashboard_shortcodes as $shortcode) {
            if (has_shortcode($content, $shortcode)) {
                return;
            }
        }

        $new_content = trim($content) === ''
            ? $required_shortcode . "\n"
            : rtrim($content) . "\n\n" . $required_shortcode . "\n";

        wp_update_post(
            [
                'ID'           => $page_id,
                'post_content' => $new_content,
            ],
            true
        );
    }

    private static function is_valid_page_id(int $page_id): bool
    {
        if ($page_id <= 0) {
            return false;
        }

        $post = get_post($page_id);

        return (
            $post instanceof WP_Post &&
            $post->post_type === 'page' &&
            $post->post_status !== 'trash'
        );
    }

    private static function is_valid_managed_page_id(int $page_id, string $role = ''): bool
    {
        if (!self::is_valid_page_id($page_id)) {
            return false;
        }

        if (!self::is_managed_page($page_id)) {
            return false;
        }

        if ($role === '') {
            return true;
        }

        $stored_role = self::get_managed_page_role($page_id);

        return $stored_role === sanitize_key($role);
    }

    private static function is_managed_page(int $page_id): bool
    {
        if ($page_id <= 0) {
            return false;
        }

        $new_value = (string) get_post_meta($page_id, self::META_MANAGED_PAGE, true);

        if ($new_value === '1') {
            return true;
        }

        $legacy_value = (string) get_post_meta($page_id, self::LEGACY_META_MANAGED_PAGE, true);

        return $legacy_value === '1';
    }

    private static function get_managed_page_role(int $page_id): string
    {
        if ($page_id <= 0) {
            return '';
        }

        $role = sanitize_key((string) get_post_meta($page_id, self::META_PAGE_ROLE, true));

        if ($role !== '') {
            return $role;
        }

        return sanitize_key((string) get_post_meta($page_id, self::LEGACY_META_PAGE_ROLE, true));
    }

    private static function mark_page_as_managed(int $page_id, string $role): void
    {
        if ($page_id <= 0) {
            return;
        }

        update_post_meta($page_id, self::META_MANAGED_PAGE, '1');
        update_post_meta($page_id, self::META_PAGE_ROLE, sanitize_key($role));

        if (
            class_exists('VCARB_Plugin_Pages_Admin') &&
            method_exists('VCARB_Plugin_Pages_Admin', 'mark_page')
        ) {
            VCARB_Plugin_Pages_Admin::mark_page($page_id);
        }
    }

    private static function unique_plugin_slug(string $base_slug): string
    {
        $base_slug = sanitize_title($base_slug);

        if ($base_slug === '') {
            $base_slug = 'verdantcart-carbon-page';
        }

        $candidate = $base_slug;
        $i         = 2;

        while (true) {
            $existing = get_page_by_path($candidate, OBJECT, 'page');

            if (!($existing instanceof WP_Post) || $existing->post_status === 'trash') {
                return $candidate;
            }

            $candidate = $base_slug . '-' . $i;
            $i++;
        }
    }

    private static function acquire_lock(): bool
    {
        $lock_key = self::get_lock_key();
        $now      = time();

        if (add_option($lock_key, $now, '', 'no')) {
            return true;
        }

        $existing_timestamp = (int) get_option($lock_key);

        if ($existing_timestamp > 0 && ($now - $existing_timestamp) > self::LOCK_TTL) {
            delete_option($lock_key);

            return add_option($lock_key, $now, '', 'no');
        }

        return false;
    }

    private static function release_lock(): void
    {
        delete_option(self::get_lock_key());
    }

    private static function get_lock_key(): string
    {
        return self::LOCK_KEY_BASE . '_' . (int) get_current_blog_id();
    }

    private static function get_dashboard_page_id_option(): int
    {
        $page_id = (int) get_option(self::OPT_DASHBOARD_ID, 0);

        if ($page_id > 0) {
            return $page_id;
        }

        $legacy_page_id = (int) get_option(self::LEGACY_OPT_DASHBOARD_ID, 0);

        if ($legacy_page_id > 0) {
            return $legacy_page_id;
        }

        return 0;
    }
}
