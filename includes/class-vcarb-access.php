<?php
defined('ABSPATH') || exit;

/**
 * Access helper for VerdantCart Carbon Reports.
 *
 * WordPress.org build:
 * - No payments
 * - No subscriptions
 * - No upgrade flows
 * - No checkout logic
 * - No feature restrictions
 */
final class VCARB_Access
{
    /** @var array<int,string> */
    private const ALLOWED_VIEWS = [
        'month',
        'week',
        'year',
    ];

    /**
     * Determine whether current user may access reporting.
     */
    public static function can_use(
        string $feature_key = '',
        int $user_id = 0
    ): bool {
        unset($feature_key);

        $user_id = $user_id > 0
            ? absint($user_id)
            : get_current_user_id();

        return $user_id > 0
            && user_can($user_id, 'read');
    }

    /**
     * Return reporting views available.
     *
     * @return array<int,string>
     */
    public static function allowed_views(
        int $user_id = 0
    ): array {
        unset($user_id);

        return self::ALLOWED_VIEWS;
    }

    /**
     * Normalize reporting view.
     */
    public static function normalize_view(
        string $requested,
        int $user_id = 0
    ): string {
        unset($user_id);

        $requested = sanitize_key($requested);

        return in_array(
            $requested,
            self::ALLOWED_VIEWS,
            true
        )
            ? $requested
            : 'month';
    }

    /**
     * Backward compatibility.
     */
    public static function feature_allowed(
        string $feature,
        int $user_id,
        string $view = 'month'
    ): bool {
        unset($view);

        return self::can_use(
            $feature,
            $user_id
        );
    }
}
