<?php
defined('ABSPATH') || exit;

/**
 * Central access helper for VerdantCart Carbon Reports.
 *
 * WordPress.org build:
 * - no paid local feature gating
 * - no subscription lookup
 * - no checkout routing
 * - all built-in local reporting views/features are available to logged-in users
 *
 * This class keeps older method names so older templates, AJAX handlers,
 * dashboard files, and export files do not fatal during migration.
 */
final class VCARB_Access
{
    private const PLAN_FREE = 'free';

    /** @var array<int,string> */
    private const VIEWS_ALL = ['month', 'week', 'year'];

    /**
     * Backward-compatible cache clearer.
     *
     * The WordPress.org build has no remote/subscription plan cache.
     *
     * @param int|null $user_id Optional user ID.
     */
    public static function clear_plan_cache(?int $user_id = null): void
    {
        unset($user_id);
    }

    /**
     * Resolve the local plugin plan.
     *
     * WordPress.org build always uses the free local build.
     */
    public static function plan(int $user_id): string
    {
        unset($user_id);

        return self::PLAN_FREE;
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
     * Built-in local reporting features are available to logged-in users.
     */
    public static function can_use(string $feature_key, int $user_id): bool
    {
        unset($feature_key);

        $user_id = absint($user_id);

        if ($user_id <= 0) {
            $user_id = get_current_user_id();
        }

        if ($user_id <= 0) {
            return false;
        }

        return user_can($user_id, 'read') || user_can($user_id, 'manage_options');
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
        unset($view);

        return self::can_use($feature, $user_id);
    }

    /**
     * Upgrade URLs are intentionally unavailable in the WordPress.org build.
     */
    public static function upgrade_url(array $utm = []): string
    {
        unset($utm);

        return '';
    }

    /**
     * Checkout URLs are intentionally unavailable in the WordPress.org build.
     */
    public static function checkout_url(string $plan_key = '', array $utm = []): string
    {
        unset($plan_key, $utm);

        return '';
    }

    /**
     * Return disabled anchor attributes for old templates that still expect them.
     */
    public static function upgrade_link_attrs(
        string $feature_label = '',
        array $opts = [],
        string $required_plan = 'pro'
    ): string {
        unset($feature_label, $required_plan);

        $class = isset($opts['class']) && is_scalar($opts['class'])
            ? trim((string) $opts['class'])
            : '';

        $aria = isset($opts['aria_label']) && is_scalar($opts['aria_label'])
            ? trim((string) $opts['aria_label'])
            : '';

        if ($aria === '') {
            $aria = __('Feature link is unavailable in this build.', 'verdantcart-ai-reports');
        }

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
    public static function upgrade_box(string $feature_label = '', string $required_plan = ''): string
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
