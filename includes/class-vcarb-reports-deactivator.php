<?php
defined('ABSPATH') || exit;

/**
 * VerdantCart Carbon Reports deactivation handler.
 *
 * Responsibilities:
 * - Clear plugin cron events.
 * - Clear plugin Action Scheduler events when available.
 * - Flush rewrite rules.
 *
 * Important:
 * - Does not delete plugin data.
 * - Does not delete reports, snapshots, audit logs, pages, or settings.
 *
 * Migration note:
 * - New internal prefix is VCARB_/vcarb_.
 * - Legacy cron hooks are still cleared so existing installs do not leave
 *   old scheduled events behind after the rename.
 */
final class VCARB_Reports_Deactivator
{
    /**
     * Plugin cron/action hooks that should be cleared on deactivation.
     *
     * Keep these hook names in sync with the scheduler and main plugin file.
     *
     * @return array<int,string>
     */
    private static function cron_hooks(): array
    {
        return [
            /*
             * Current VCARB cron/action hooks.
             */
            'vcarb_weekly_event',
            'vcarb_monthly_event',
            'vcarb_yearly_event',
            'vcarb_run_aggregate',

            /*
             * Legacy AmatorCarbon hooks kept for cleanup compatibility.
             */
            'amatorcarbon_weekly_event',
            'amatorcarbon_monthly_event',
            'amatorcarbon_yearly_event',
            'amatorcarbon_run_aggregate',

            /*
             * Older ACR hooks, safe to clear if they exist.
             */
            'acr_weekly_event',
            'acr_monthly_event',
            'acr_yearly_event',
            'acr_run_aggregate',

            /*
             * Old GreenCart / AI Carbon hooks, safe to clear if they exist.
             */
            'ai_carbon_weekly_event',
            'ai_carbon_monthly_event',
            'ai_carbon_yearly_event',
            'ai_carbon_run_aggregate',

            'gc_ai_weekly_event',
            'gc_ai_monthly_event',
            'gc_ai_yearly_event',
            'gc_ai_run_aggregate',
        ];
    }

    /**
     * Run deactivation cleanup.
     */
    public static function deactivate(): void
    {
        self::clear_wp_cron_hooks();
        self::clear_action_scheduler_hooks();

        flush_rewrite_rules(false);
    }

    /**
     * Clear WP-Cron events.
     */
    private static function clear_wp_cron_hooks(): void
    {
        foreach (self::cron_hooks() as $hook) {
            /*
             * wp_unschedule_hook() clears all scheduled events for the hook,
             * including events scheduled with arguments.
             */
            if (function_exists('wp_unschedule_hook')) {
                wp_unschedule_hook($hook);
                continue;
            }

            /*
             * Fallback for older WordPress versions.
             */
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook($hook);
            }
        }
    }

    /**
     * Clear Action Scheduler events when WooCommerce/Action Scheduler is available.
     */
    private static function clear_action_scheduler_hooks(): void
    {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        foreach (self::cron_hooks() as $hook) {
            as_unschedule_all_actions($hook);
        }
    }
}
