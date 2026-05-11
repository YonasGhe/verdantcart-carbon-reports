<?php
defined('ABSPATH') || exit;

/**
 * Central access guard for VerdantCart Carbon Reports.
 *
 * Protects:
 * - Front dashboard page from guests
 * - Plugin wp-admin pages from non-admin users
 *
 * Legacy AmatorCarbon slugs/options are kept so existing installed sites do not break.
 */
final class VCARB_Admin_Guard
{
    private static bool $did_boot = false;

    /**
     * Plugin admin page slugs.
     *
     * @return array<int,string>
     */
    private static function protected_admin_pages(): array
    {
        return [
            'vcarb-carbon-reports',
            'vcarb-settings',
            'vcarb-all-customers',
            'vcarb-backfill',
            'vcarb-front-dashboard',
            'vcarb-open-home-page',
            'vcarb-open-plans-page',
            'vcarb-sustainability-summary',

            /*
             * Legacy admin page slugs kept for existing installs/bookmarks.
             */
            'amator-carbon-reports',
            'amatorcarbon-settings',
            'amatorcarbon-all-customers',
            'amatorcarbon-backfill',
            'amatorcarbon-front-dashboard',
            'amatorcarbon-open-home-page',
            'amatorcarbon-open-plans-page',
            'amatorcarbon-sustainability-summary',
        ];
    }

    public static function boot(): void
    {
        if (self::$did_boot) {
            return;
        }

        self::$did_boot = true;

        add_action('template_redirect', [__CLASS__, 'guard_frontend_dashboard'], 1);
        add_action('admin_init', [__CLASS__, 'guard_admin_pages'], 1);
    }

    /**
     * Frontend: lock down dashboard page.
     *
     * Logged out users are redirected to login.
     * Logged in users are allowed.
     */
    public static function guard_frontend_dashboard(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        if (!self::is_frontend_dashboard_request()) {
            return;
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(self::current_dashboard_url()));
            exit;
        }

        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow', true);
        }

        nocache_headers();
    }

    /**
     * wp-admin: guard plugin admin pages.
     */
    public static function guard_admin_pages(): void
    {
        if (!is_admin()) {
            return;
        }

        $page = '';

        if (
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing parameter.
            isset($_GET['page']) &&
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing parameter.
            is_scalar($_GET['page'])
        ) {
            $page = sanitize_key(
                wp_unslash(
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing parameter.
                    (string) $_GET['page']
                )
            );
        }

        if (!in_array($page, self::protected_admin_pages(), true)) {
            return;
        }

        if (current_user_can('manage_options')) {
            nocache_headers();
            return;
        }

        wp_die(
            esc_html__('You do not have permission to access this page.', 'verdantcart-ai-reports'),
            esc_html__('Forbidden', 'verdantcart-ai-reports'),
            ['response' => 403]
        );
    }

    private static function is_frontend_dashboard_request(): bool
    {
        $dashboard_id = self::get_dashboard_page_id();

        if ($dashboard_id > 0 && is_page($dashboard_id)) {
            return true;
        }

        if (!is_singular('page')) {
            return false;
        }

        $post = get_post();

        if (!($post instanceof WP_Post)) {
            return false;
        }

        $content = (string) $post->post_content;

        return (
            has_shortcode($content, 'verdantcart_dashboard') ||
            has_shortcode($content, 'verdantcart_carbon_dashboard') ||

            /*
             * Legacy shortcodes kept so old dashboard pages stay protected.
             */
            has_shortcode($content, 'amatorcarbon_dashboard') ||
            has_shortcode($content, 'amator_carbon_dashboard') ||
            has_shortcode($content, 'acr_dashboard')
        );
    }

    private static function current_dashboard_url(): string
    {
        $dashboard_id = self::get_dashboard_page_id();

        if ($dashboard_id > 0) {
            $permalink = get_permalink($dashboard_id);

            if (is_string($permalink) && $permalink !== '') {
                return $permalink;
            }
        }

        $queried_id = get_queried_object_id();

        if ($queried_id > 0) {
            $permalink = get_permalink($queried_id);

            if (is_string($permalink) && $permalink !== '') {
                return $permalink;
            }
        }

        return home_url('/');
    }

    private static function get_dashboard_page_id(): int
    {
        $dashboard_id = 0;

        if (class_exists('VCARB_Reports_Activator')) {
            $dashboard_id = (int) get_option(VCARB_Reports_Activator::OPT_DASHBOARD_ID);
        }

        /*
         * Legacy fallback for sites that already stored the dashboard page ID
         * before the internal prefix was renamed.
         */
        if ($dashboard_id <= 0) {
            $dashboard_id = (int) get_option('vcarb_dashboard_page_id');
        }

        if ($dashboard_id <= 0) {
            $dashboard_id = (int) get_option('amatorcarbon_dashboard_page_id');
        }

        if ($dashboard_id > 0 && self::is_valid_page($dashboard_id)) {
            return $dashboard_id;
        }

        $slugs = [];

        if (class_exists('VCARB_Reports_Activator')) {
            $slugs[] = VCARB_Reports_Activator::SLUG_DASHBOARD;
        }

        $slugs[] = 'verdantcart-carbon-dashboard';
        $slugs[] = 'verdantcart-dashboard';

        /*
         * Legacy slug kept so old pages remain protected after update.
         */
        $slugs[] = 'amator-carbon-dashboard';

        $slugs = array_values(array_unique(array_filter(array_map('sanitize_title', $slugs))));

        foreach ($slugs as $slug) {
            $page = get_page_by_path($slug, OBJECT, 'page');

            if ($page instanceof WP_Post && self::is_valid_page((int) $page->ID)) {
                return (int) $page->ID;
            }
        }

        return 0;
    }

    private static function is_valid_page(int $page_id): bool
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
}
