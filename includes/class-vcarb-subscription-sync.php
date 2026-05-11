<?php
defined('ABSPATH') || exit;

/**
 * Backward-compatible subscription sync stub.
 *
 * WordPress.org build:
 * - does not perform subscription checks
 * - does not perform paid entitlement syncing
 * - does not route checkout
 * - does not use local Pro gating
 *
 * This class is kept only so older internal callers do not fatal
 * if they reference subscription sync methods.
 */
class VCARB_Subscription_Sync
{
    public const META_PLAN = 'vcarb_plan';

    /**
     * Register subscription sync hooks.
     *
     * Intentionally disabled in the WordPress.org build.
     */
    public static function init(): void
    {
        // Intentionally disabled in the WordPress.org build.
    }

    /**
     * Check whether a user has an active Pro subscription.
     *
     * WordPress.org build always returns false because paid entitlement
     * syncing is not included in this package.
     */
    public static function user_has_active_pro_subscription(int $user_id): bool
    {
        unset($user_id);

        return false;
    }

    /**
     * Resync a single user entitlement.
     *
     * WordPress.org build does not sync paid plans.
     */
    public static function resync_user(int $user_id): bool
    {
        unset($user_id);

        return false;
    }

    /**
     * Resync entitlement state in batches.
     *
     * WordPress.org build performs no subscription sync work, but returns a
     * successful no-op response so older admin tools do not fatal.
     */
    public static function resync_batch(int $limit = 200, int $offset = 0): array
    {
        unset($limit, $offset);

        return [
            'ok'         => true,
            'done'       => true,
            'nextOffset' => null,
            'processed'  => 0,
            'total'      => null,
            'changed'    => 0,
            'revoked'    => 0,
        ];
    }
}
