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
            'vcarb_weekly_event',
            'vcarb_monthly_event',
            'vcarb_yearly_event',
            'vcarb_run_aggregate',
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
        if (!function_exists('wp_unschedule_hook')) {
            return;
        }

        foreach (self::cron_hooks() as $hook) {
            wp_unschedule_hook($hook);
        }
    }

    /**
     * Clear Action Scheduler events when available.
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
