<?php
defined('ABSPATH') || exit;

final class VCARB_Capabilities
{
    public const VIEW_MONTH    = 'vcarb_view_month';
    public const VIEW_WEEK     = 'vcarb_view_week';
    public const VIEW_YEAR     = 'vcarb_view_year';
    public const ADMIN_REBUILD = 'vcarb_rebuild_data';

    /**
     * Check whether a user can use a plugin capability.
     *
     * WordPress.org build:
     * - local reporting views are available to logged-in users
     * - admin-only rebuild remains restricted to administrators
     */
    public static function user_can(int $user_id, string $cap): bool
    {
        $user_id = absint($user_id);
        $cap     = sanitize_key($cap);

        if ($user_id <= 0 || $cap === '') {
            return false;
        }

        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        if ($cap === self::ADMIN_REBUILD) {
            return false;
        }

        if (!user_can($user_id, 'read')) {
            return false;
        }

        return in_array($cap, self::view_capabilities(), true);
    }

    /**
     * Local dashboard view capabilities available to logged-in users.
     *
     * @return array<int,string>
     */
    public static function view_capabilities(): array
    {
        return [
            self::VIEW_MONTH,
            self::VIEW_WEEK,
            self::VIEW_YEAR,
        ];
    }

    public static function can_view_reports(int $user_id): bool
    {
        return self::can_use($user_id, self::VIEW_MONTH);
    }

    /**
     * Alias for clearer plugin-internal usage.
     */
    public static function can_use(int $user_id, string $cap): bool
    {
        return self::user_can($user_id, $cap);
    }
}
