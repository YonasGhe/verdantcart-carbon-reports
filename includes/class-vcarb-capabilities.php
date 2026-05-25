<?php
defined('ABSPATH') || exit;

final class VCARB_Capabilities
{
    public const VIEW_MONTH = 'vcarb_view_month';
    public const VIEW_WEEK  = 'vcarb_view_week';
    public const VIEW_YEAR  = 'vcarb_view_year';

    public const ADMIN_REBUILD =
    'vcarb_rebuild_data';

    /** @var array<int,string> */
    private const VIEW_CAPABILITIES = [
        self::VIEW_MONTH,
        self::VIEW_WEEK,
        self::VIEW_YEAR,
    ];

    public static function user_can(
        int $user_id,
        string $cap
    ): bool {
        $user_id = absint($user_id);
        $cap     = sanitize_key($cap);

        if (
            $user_id <= 0 ||
            $cap === ''
        ) {
            return false;
        }

        if (
            user_can(
                $user_id,
                'manage_options'
            )
        ) {
            return true;
        }

        if (
            $cap === self::ADMIN_REBUILD
        ) {
            return false;
        }

        return user_can(
            $user_id,
            'read'
        ) && in_array(
            $cap,
            self::VIEW_CAPABILITIES,
            true
        );
    }

    /**
     * @return array<int,string>
     */
    public static function view_capabilities(): array
    {
        return self::VIEW_CAPABILITIES;
    }

    public static function can_view_reports(
        int $user_id
    ): bool {
        return self::user_can(
            $user_id,
            self::VIEW_MONTH
        );
    }
}
