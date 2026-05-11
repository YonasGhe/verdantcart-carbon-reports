<?php
defined('ABSPATH') || exit;

/**
 * Protect the front VerdantCart dashboard page.
 *
 * Guests are redirected to login and then returned to the dashboard.
 */
class VCARB_Dashboard_Guard
{
    public static function init(): void
    {
        static $did = false;

        if ($did) {
            return;
        }

        $did = true;

        add_action('template_redirect', [__CLASS__, 'protect_dashboard_page']);
    }

    public static function protect_dashboard_page(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        if (!self::is_dashboard_request()) {
            return;
        }

        if (is_user_logged_in()) {
            return;
        }

        $redirect_back = self::current_dashboard_url();

        wp_safe_redirect(wp_login_url($redirect_back));
        exit;
    }

    private static function is_dashboard_request(): bool
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
        if (class_exists('VCARB_Reports_Activator')) {
            $dashboard_id = (int) get_option(VCARB_Reports_Activator::OPT_DASHBOARD_ID);
        } else {
            $dashboard_id = (int) get_option('vcarb_dashboard_page_id');
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
         * Legacy slug kept so existing sites do not lose dashboard protection
         * after upgrading from the old internal prefix.
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
