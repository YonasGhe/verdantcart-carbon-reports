<?php
defined('ABSPATH') || exit;

/**
 * Front-end context detector for VerdantCart Carbon Reports.
 *
 * Responsibilities:
 * - Detect the plugin dashboard page.
 * - Add dashboard-specific body classes.
 * - Load the isolated app template for the dashboard page.
 *
 * Migration note:
 * - New internal prefix is VCARB_/vcarb_.
 * - Legacy shortcodes, slugs, options, and classes are kept so older pages
 *   continue to work after the rename.
 */
class VCARB_Front_Context
{
    /** @var array<int,bool> */
    private static array $dashboard_page_cache = [];

    /** @var array<int,string> */
    private const DASHBOARD_SHORTCODES = [
        'vcarb_dashboard',
        'verdantcart_dashboard',
        'verdantcart_carbon_dashboard',

        // Legacy shortcodes kept so existing pages do not break.
        'amatorcarbon_dashboard',
        'amator_carbon_dashboard',
        'acr_dashboard',
    ];

    /** @var array<int,string> */
    private const DASHBOARD_SLUGS = [
        'verdantcart-dashboard',
        'vcarb-dashboard',
        'vcarb-carbon-dashboard',

        // Legacy page slug kept so older dashboard pages still use app layout.
        'amator-carbon-dashboard',
    ];

    public static function register_hooks(): void
    {
        static $did = false;

        if ($did) {
            return;
        }

        $did = true;

        add_filter('body_class', [__CLASS__, 'body_classes']);
        add_filter('template_include', [__CLASS__, 'filter_template'], 99);
    }

    public static function is_dashboard_page(): bool
    {
        if (is_admin() || !is_singular()) {
            return false;
        }

        global $post;

        if (!($post instanceof WP_Post)) {
            return false;
        }

        $post_id = (int) $post->ID;

        if ($post_id <= 0) {
            return false;
        }

        if (array_key_exists($post_id, self::$dashboard_page_cache)) {
            return self::$dashboard_page_cache[$post_id];
        }

        $is_dashboard = false;
        $content      = (string) $post->post_content;

        foreach (self::DASHBOARD_SHORTCODES as $shortcode) {
            if ($content !== '' && has_shortcode($content, $shortcode)) {
                $is_dashboard = true;
                break;
            }
        }

        if (!$is_dashboard) {
            $stored_id = self::get_dashboard_page_id();

            if ($stored_id > 0 && $post_id === $stored_id) {
                $is_dashboard = true;
            }
        }

        if (!$is_dashboard) {
            $post_slug = sanitize_title((string) $post->post_name);

            if ($post_slug !== '' && in_array($post_slug, self::get_dashboard_slugs(), true)) {
                $is_dashboard = true;
            }
        }

        self::$dashboard_page_cache[$post_id] = $is_dashboard;

        return $is_dashboard;
    }

    public static function should_use_app_layout(): bool
    {
        return self::is_dashboard_page();
    }

    public static function body_classes(array $classes): array
    {
        if (!self::is_dashboard_page()) {
            return $classes;
        }

        $classes[] = 'verdantcart-page';
        $classes[] = 'verdantcart-page--dashboard';
        $classes[] = 'verdantcart-layout--app';

        // New internal prefix classes.
        $classes[] = 'vcarb-page';
        $classes[] = 'vcarb-page--dashboard';
        $classes[] = 'vcarb-layout--app';

        /*
         * Legacy classes are kept so existing CSS/JS selectors do not break.
         * Remove later only after all assets/templates stop using them.
         */
        $classes[] = 'amatorcarbon-page';
        $classes[] = 'amatorcarbon-page--dashboard';
        $classes[] = 'amatorcarbon-layout--app';

        return array_values(array_unique($classes));
    }

    public static function filter_template(string $template): string
    {
        if (!self::should_use_app_layout()) {
            return $template;
        }

        if (!defined('VCARB_PLUGIN_DIR')) {
            return $template;
        }

        $custom = trailingslashit(VCARB_PLUGIN_DIR) . 'frontend/templates/vcarb-app-template.php';

        if (is_readable($custom)) {
            return $custom;
        }

        return $template;
    }

    private static function get_dashboard_page_id(): int
    {
        $page_id = 0;

        if (class_exists('VCARB_Reports_Activator')) {
            $page_id = (int) get_option(VCARB_Reports_Activator::OPT_DASHBOARD_ID, 0);
        } else {
            $page_id = (int) get_option('vcarb_dashboard_page_id', 0);
        }

        if ($page_id > 0) {
            return $page_id;
        }

        /*
         * Legacy option fallback for existing 1.0.x installs.
         */
        return (int) get_option('amatorcarbon_dashboard_page_id', 0);
    }

    /**
     * @return array<int,string>
     */
    private static function get_dashboard_slugs(): array
    {
        $slugs = self::DASHBOARD_SLUGS;

        if (class_exists('VCARB_Reports_Activator')) {
            $slugs[] = sanitize_title(VCARB_Reports_Activator::SLUG_DASHBOARD);
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map('sanitize_title', $slugs)
                )
            )
        );
    }
}
