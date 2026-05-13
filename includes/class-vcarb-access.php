<?php
defined('ABSPATH') || exit;

/**
 * Central access helper for VerdantCart Carbon Reports.
 *
 * WordPress.org build:
 * - no paid local feature gating
 * - no subscription lookup
 * - no checkout routing
 * - all built-in local reporting views/features are available
 *
 * This class intentionally keeps old method names so older templates,
 * AJAX handlers, dashboard files, and export files do not fatal.
 */
final class VCARB_Access
{
    private const PLAN_FREE = 'free';

    /** @var array<int,string> */
    private static array $plan_cache = [];

    /** @var array<int,string> */
    private const VIEWS_ALL = ['month', 'week', 'year'];

    /**
     * Clear cached resolved plans.
     *
     * @param int|null $user_id Optional user ID. Null clears the full cache.
     */
    public static function clear_plan_cache(?int $user_id = null): void
    {
        if ($user_id === null) {
            self::$plan_cache = [];
            return;
        }

        unset(self::$plan_cache[absint($user_id)]);
    }

    /**
     * Resolve the local plugin plan.
     *
     * WordPress.org build always uses the free local build,
     * while keeping all built-in reporting views/features available.
     */
    public static function plan(int $user_id): string
    {
        $user_id = absint($user_id);

        if ($user_id <= 0) {
            return self::PLAN_FREE;
        }

        if (!isset(self::$plan_cache[$user_id])) {
            self::$plan_cache[$user_id] = self::PLAN_FREE;
        }

        return self::$plan_cache[$user_id];
    }

    /**
     * Backward-compatible Pro check.
     *
     * The WordPress.org build does not expose a local Pro entitlement.
     */
    public static function is_pro(int $user_id): bool
    {
        unset($user_id);

        return false;
    }

    /**
     * Backward-compatible Pro Plus check.
     *
     * Pro Plus is not used in the WordPress.org build.
     */
    public static function is_pro_plus(int $user_id): bool
    {
        unset($user_id);

        return false;
    }

    /**
     * Built-in local features remain available in the WordPress.org build.
     */
    public static function can_use(string $feature_key, int $user_id): bool
    {
        unset($feature_key, $user_id);

        return true;
    }

    /**
     * Return allowed reporting views for the local plugin UI.
     *
     * @return array<int,string>
     */
    public static function allowed_views(int $user_id): array
    {
        unset($user_id);

        return self::VIEWS_ALL;
    }

    /**
     * Normalize a requested reporting view.
     */
    public static function normalize_view(string $requested, int $user_id = 0): string
    {
        unset($user_id);

        $requested = sanitize_key($requested);

        return in_array($requested, self::VIEWS_ALL, true) ? $requested : 'month';
    }

    /**
     * Backward-compatible feature helper.
     */
    public static function feature_allowed(string $feature, int $user_id, string $view = 'month'): bool
    {
        unset($feature, $user_id, $view);

        return true;
    }

    /**
     * Backward-compatible safe fallback.
     *
     * The WordPress.org build should not route built-in local functionality
     * to upgrade URLs from inside the plugin.
     */
    public static function upgrade_url(array $utm = []): string
    {
        unset($utm);

        return '';
    }

    /**
     * Backward-compatible safe fallback.
     *
     * The WordPress.org build should not generate checkout links for
     * built-in local functionality from inside the plugin UI.
     */
    public static function checkout_url(string $plan_key = '', array $utm = []): string
    {
        unset($plan_key, $utm);

        return '';
    }

    /**
     * Return safe generic anchor attributes for old templates that still
     * expect link attributes from this method.
     */
    public static function upgrade_link_attrs(
        string $feature_label = '',
        array $opts = [],
        string $required_plan = 'pro'
    ): string {
        unset($feature_label, $required_plan);

        $class = !empty($opts['class']) ? (string) $opts['class'] : '';
        $aria  = !empty($opts['aria_label'])
            ? (string) $opts['aria_label']
            : __('Feature link is unavailable.', 'verdantcart-ai-reports');

        $attr_parts = [
            'href="#"',
            'aria-label="' . esc_attr($aria) . '"',
            'aria-disabled="true"',
            'tabindex="-1"',
            'role="link"',
        ];

        if ($class !== '') {
            $attr_parts[] = 'class="' . esc_attr($class) . '"';
        }

        return implode(' ', $attr_parts);
    }

    /**
     * Retained for backward compatibility with older templates.
     */
    public static function upgrade_notice(string $feature_label = '', string $required_plan = ''): string
    {
        unset($feature_label, $required_plan);

        return '';
    }

    /**
     * Retained for backward compatibility with older templates.
     */
    public static function upgrade_box(string $feature_label = 'Week & Year views', string $required_plan = 'pro'): string
    {
        unset($feature_label, $required_plan);

        return '';
    }

    /**
     * Retained for backward compatibility with older templates.
     */
    public static function upgrade_form(
        string $plan_key = '',
        string $label = '',
        array $attrs = []
    ): string {
        unset($plan_key, $label, $attrs);

        return '';
    }

    /**
     * Retained for backward compatibility with older templates.
     */
    public static function upgrade_form_html(
        string $plan_key,
        string $button_html,
        array $attrs = []
    ): string {
        unset($plan_key, $button_html, $attrs);

        return '';
    }
}
