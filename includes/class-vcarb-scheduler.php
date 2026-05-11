<?php
defined('ABSPATH') || exit;

/**
 * VerdantCart Carbon Reports scheduler.
 *
 * Responsibilities:
 * - Schedule debounced aggregate rebuilds
 * - Prefer Action Scheduler when available
 * - Fall back to WP-Cron
 * - Run aggregate jobs safely
 *
 * Migration note:
 * - New internal prefix is VCARB_/vcarb_.
 * - Legacy amatorcarbon hook names are not used for new jobs.
 * - Deactivation cleanup should still clear old amatorcarbon/acr hooks.
 */
final class VCARB_Scheduler
{
    private const HOOK_RUN_AGGREGATE = 'vcarb_run_aggregate';
    private const ACTION_GROUP       = 'vcarb';
    private const ALLOWED_VIEWS      = ['week', 'month', 'year'];

    /**
     * Register scheduler runtime hooks.
     */
    public static function register_hooks(): void
    {
        add_action(self::HOOK_RUN_AGGREGATE, [__CLASS__, 'run_aggregate'], 10, 2);
    }

    /**
     * Schedule an aggregate job with debounce.
     */
    public static function schedule_aggregate_debounced(string $view, string $period): void
    {
        $view   = self::normalize_view($view);
        $period = self::sanitize_period($view, $period);

        if ($period === '') {
            return;
        }

        /**
         * In local development, run immediately so testing feels instant.
         * Production/staging use async scheduling.
         */
        if (
            function_exists('wp_get_environment_type') &&
            wp_get_environment_type() === 'local'
        ) {
            self::run_aggregate($view, $period);
            return;
        }

        $delay = max(
            1,
            (int) apply_filters('vcarb_aggregate_delay_seconds', 30, $view, $period)
        );

        $args = [$view, $period];

        if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_single_action')) {
            if (!as_has_scheduled_action(self::HOOK_RUN_AGGREGATE, $args, self::ACTION_GROUP)) {
                as_schedule_single_action(
                    time() + $delay,
                    self::HOOK_RUN_AGGREGATE,
                    $args,
                    self::ACTION_GROUP
                );
            }

            return;
        }

        if (!wp_next_scheduled(self::HOOK_RUN_AGGREGATE, $args)) {
            wp_schedule_single_event(time() + $delay, self::HOOK_RUN_AGGREGATE, $args);
        }
    }

    /**
     * Execute an aggregate job.
     */
    public static function run_aggregate(string $view, string $period): void
    {
        $view   = self::normalize_view($view);
        $period = self::sanitize_period($view, $period);

        if ($period === '') {
            return;
        }

        if (!class_exists('VCARB_Calculator') || !method_exists('VCARB_Calculator', 'aggregate')) {
            return;
        }

        $lock_key = 'vcarb_aggregate_lock_' . md5($view . '|' . $period);

        if (get_transient($lock_key)) {
            return;
        }

        set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);

        try {
            VCARB_Calculator::aggregate($view, $period);
        } finally {
            delete_transient($lock_key);
        }
    }

    private static function normalize_view(string $view): string
    {
        $view = sanitize_key($view);

        return in_array($view, self::ALLOWED_VIEWS, true) ? $view : 'month';
    }

    private static function sanitize_period(string $view, string $period): string
    {
        $view   = self::normalize_view($view);
        $period = trim(sanitize_text_field($period));

        if ($period === '') {
            return '';
        }

        if ($view === 'month') {
            return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) ? $period : '';
        }

        if ($view === 'year') {
            return preg_match('/^(19[7-9]\d|20\d{2}|2100)$/', $period) ? $period : '';
        }

        if ($view === 'week') {
            if (!preg_match('/^(\d{4})-W(\d{2})$/', $period, $matches)) {
                return '';
            }

            $year = (int) $matches[1];
            $week = (int) $matches[2];

            if ($year < 1970 || $year > 2100 || $week < 1 || $week > 53) {
                return '';
            }

            try {
                $tz = function_exists('wp_timezone')
                    ? wp_timezone()
                    : new DateTimeZone(wp_timezone_string() ?: 'UTC');

                $dt = (new DateTimeImmutable('now', $tz))
                    ->setISODate($year, $week, 1)
                    ->setTime(0, 0, 0);

                $normalized = sprintf(
                    '%04d-W%02d',
                    (int) $dt->format('o'),
                    (int) $dt->format('W')
                );

                return $normalized === $period ? $period : '';
            } catch (Throwable $e) {
                return '';
            }
        }

        return '';
    }
}
