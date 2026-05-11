<?php
defined('ABSPATH') || exit;

/**
 * Snapshot-only helpers (STORE snapshots: user_id = 0).
 *
 * Safe:
 * - Works without Period trait, but will use it if present.
 * - Does not use trait constants, so it remains safe for PHP 8.0/8.1.
 *
 * Important:
 * - For now, this keeps the existing 1.0.2 database table names:
 *   amatorcarbon_logs
 *   amatorcarbon_product_logs
 *
 * Do not rename the tables to vcarb_* until you add a safe data migration.
 */
trait VCARB_Snapshot_Trait
{
    /** @var array<string,bool> */
    protected static array $vcarb_snapshot_exists_cache = [];

    /** @var array<string,string> */
    protected static array $vcarb_snapshot_updated_cache = [];

    /** @var array<string,string> */
    protected static array $vcarb_snapshot_latest_cache = [];

    /** @var array<string,array<string,mixed>> */
    protected static array $vcarb_snapshot_anchor_cache = [];

    /**
     * Keep allowed views in a method instead of a trait constant.
     *
     * Trait constants require newer PHP versions and are not safe for
     * a WordPress.org plugin that supports PHP 8.0.
     *
     * @return array<int,string>
     */
    protected static function vcarb_snapshot_allowed_views(): array
    {
        return ['month', 'week', 'year'];
    }

    protected function sanitize_view(string $view): string
    {
        $view = sanitize_key($view);

        return in_array($view, self::vcarb_snapshot_allowed_views(), true)
            ? $view
            : 'month';
    }

    protected static function sanitize_view_static(string $view): string
    {
        $view = sanitize_key($view);

        return in_array($view, self::vcarb_snapshot_allowed_views(), true)
            ? $view
            : 'month';
    }

    protected function sanitize_period_for_view_safe(string $view, $raw): string
    {
        $view = $this->sanitize_view($view);

        if (method_exists($this, 'sanitize_period_for_view')) {
            return (string) $this->sanitize_period_for_view($view, $raw);
        }

        return self::sanitize_period_for_view_static($view, $raw);
    }

    protected static function sanitize_period_for_view_static(string $view, $raw): string
    {
        $view   = self::sanitize_view_static($view);
        $period = is_scalar($raw) ? trim(sanitize_text_field((string) $raw)) : '';

        if ($period === '') {
            return '';
        }

        if ($view === 'month' && preg_match('/^(\d{4})-(\d{2})$/', $period, $matches)) {
            $year  = (int) $matches[1];
            $month = (int) $matches[2];

            if ($year >= 1970 && $year <= 2100 && $month >= 1 && $month <= 12) {
                return sprintf('%04d-%02d', $year, $month);
            }

            return '';
        }

        if ($view === 'year' && preg_match('/^\d{4}$/', $period)) {
            $year = (int) $period;

            return ($year >= 1970 && $year <= 2100) ? (string) $year : '';
        }

        if ($view === 'week' && preg_match('/^(\d{4})-W(\d{2})$/', $period, $matches)) {
            $year = (int) $matches[1];
            $week = (int) $matches[2];

            if ($year < 1970 || $year > 2100 || $week < 1 || $week > 53) {
                return '';
            }

            try {
                $tz = function_exists('wp_timezone')
                    ? wp_timezone()
                    : new DateTimeZone('UTC');

                $dt = (new DateTimeImmutable('now', $tz))
                    ->setISODate($year, $week, 1)
                    ->setTime(0, 0, 0);

                $normalized = sprintf(
                    '%04d-W%02d',
                    (int) $dt->format('o'),
                    (int) $dt->format('W')
                );

                return ($normalized === sprintf('%04d-W%02d', $year, $week))
                    ? $normalized
                    : '';
            } catch (Throwable $e) {
                return '';
            }
        }

        return '';
    }

    protected function period_regex_safe(string $view): string
    {
        $view = $this->sanitize_view($view);

        if (method_exists($this, 'period_regex')) {
            return (string) $this->period_regex($view);
        }

        return self::period_regex_static($view);
    }

    protected static function period_regex_static(string $view): string
    {
        $view = self::sanitize_view_static($view);

        if ($view === 'week') {
            return '^[0-9]{4}-W(0[1-9]|[1-4][0-9]|5[0-3])$';
        }

        if ($view === 'year') {
            return '^[0-9]{4}$';
        }

        return '^[0-9]{4}-(0[1-9]|1[0-2])$';
    }

    protected function snapshot_cache_key(string $view, string $period = ''): string
    {
        return $this->sanitize_view($view) . '|' . trim($period);
    }

    protected function clear_snapshot_trait_caches(?string $view = null, ?string $period = null): void
    {
        if ($view === null) {
            self::$vcarb_snapshot_exists_cache  = [];
            self::$vcarb_snapshot_updated_cache = [];
            self::$vcarb_snapshot_latest_cache  = [];
            self::$vcarb_snapshot_anchor_cache  = [];
            return;
        }

        $view = $this->sanitize_view($view);

        if ($period !== null) {
            $period = $this->sanitize_period_for_view_safe($view, $period);
            $key    = $this->snapshot_cache_key($view, $period);

            unset(self::$vcarb_snapshot_exists_cache[$key]);
            unset(self::$vcarb_snapshot_updated_cache[$key]);
            unset(self::$vcarb_snapshot_anchor_cache[$key]);
        }

        unset(self::$vcarb_snapshot_latest_cache[$view]);
    }

    /**
     * Rebuild store snapshot from per-user rows.
     *
     * Important:
     * - This should only be used when all counted orders have customer rows.
     * - Guest orders may not have user rows, so rebuilding from user_id != 0
     *   can undercount stores that allow guest checkout.
     */
    public static function rebuild_store_snapshot(string $view, string $period): void
    {
        global $wpdb;

        $view   = self::sanitize_view_static($view);
        $period = self::sanitize_period_for_view_static($view, $period);

        if ($period === '') {
            return;
        }

        /*
         * Keep legacy table name for now to preserve existing 1.0.2 user data.
         */
        $table = esc_sql($wpdb->prefix . 'amatorcarbon_logs');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading/upserting plugin-owned snapshot table; query is prepared and table name is derived from $wpdb->prefix.
        $sum = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT
                    COALESCE(SUM(orders), 0) AS orders,
                    COALESCE(SUM(total_co2), 0) AS co2
                FROM `{$table}`
                WHERE user_id != 0
                  AND view_type = %s
                  AND period = %s
                ",
                $view,
                $period
            )
        );

        $orders = (int) ($sum->orders ?? 0);
        $co2    = (float) ($sum->co2 ?? 0.0);

        $wpdb->query(
            $wpdb->prepare(
                "
                INSERT INTO `{$table}`
                    (user_id, view_type, period, orders, total_co2, created_at)
                VALUES
                    (0, %s, %s, %d, %s, %s)
                ON DUPLICATE KEY UPDATE
                    orders = VALUES(orders),
                    total_co2 = VALUES(total_co2),
                    created_at = VALUES(created_at)
                ",
                $view,
                $period,
                $orders,
                number_format($co2, 2, '.', ''),
                current_time('mysql')
            )
        );
        // phpcs:enable

        $cache_key = $view . '|' . $period;

        unset(self::$vcarb_snapshot_exists_cache[$cache_key]);
        unset(self::$vcarb_snapshot_updated_cache[$cache_key]);
        unset(self::$vcarb_snapshot_anchor_cache[$cache_key]);
        unset(self::$vcarb_snapshot_latest_cache[$view]);
    }

    protected function get_snapshot_updated_for_anchor(string $view, string $anchor): string
    {
        $view   = $this->sanitize_view($view);
        $anchor = $this->sanitize_period_for_view_safe($view, $anchor);

        if ($anchor === '') {
            return '';
        }

        return $this->get_store_snapshot_updated($view, $anchor);
    }

    protected function store_snapshot_exists(string $view_or_period, ?string $period = null): bool
    {
        if ($period === null) {
            $view   = 'month';
            $period = $view_or_period;
        } else {
            $view = $this->sanitize_view($view_or_period);
        }

        $period = $this->sanitize_period_for_view_safe($view, $period);

        if ($period === '') {
            return false;
        }

        $cache_key = $this->snapshot_cache_key($view, $period);

        if (array_key_exists($cache_key, self::$vcarb_snapshot_exists_cache)) {
            return self::$vcarb_snapshot_exists_cache[$cache_key];
        }

        global $wpdb;

        $table = esc_sql($wpdb->prefix . 'amatorcarbon_logs');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned snapshot table; query is prepared and table name is derived from $wpdb->prefix.
        $exists = (bool) $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT 1
                FROM `{$table}`
                WHERE user_id = 0
                  AND view_type = %s
                  AND period = %s
                LIMIT 1
                ",
                $view,
                $period
            )
        );
        // phpcs:enable

        self::$vcarb_snapshot_exists_cache[$cache_key] = $exists;

        return $exists;
    }

    protected function require_store_snapshot_exists(string $view_or_period, ?string $period = null): void
    {
        if (!$this->store_snapshot_exists($view_or_period, $period)) {
            wp_die(
                esc_html__('Snapshot period not found (store total row missing).', 'verdantcart-ai-reports'),
                esc_html__('Not Found', 'verdantcart-ai-reports'),
                ['response' => 404]
            );
        }
    }

    protected function latest_store_snapshot_period(string $view): string
    {
        global $wpdb;

        $view = $this->sanitize_view($view);

        if (array_key_exists($view, self::$vcarb_snapshot_latest_cache)) {
            return self::$vcarb_snapshot_latest_cache[$view];
        }

        $table = esc_sql($wpdb->prefix . 'amatorcarbon_logs');
        $regex = $this->period_regex_safe($view);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned snapshot table; query is prepared and table name is derived from $wpdb->prefix.
        $latest = (string) $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT period
                FROM `{$table}`
                WHERE user_id = 0
                  AND view_type = %s
                  AND period REGEXP %s
                ORDER BY period DESC
                LIMIT 1
                ",
                $view,
                $regex
            )
        );
        // phpcs:enable

        $latest = $this->sanitize_period_for_view_safe($view, $latest);
        self::$vcarb_snapshot_latest_cache[$view] = $latest;

        return $latest;
    }

    protected function latest_snapshot_period(string $view): string
    {
        return $this->latest_store_snapshot_period($view);
    }

    protected function get_store_snapshot_updated(string $view_or_period, ?string $period = null): string
    {
        global $wpdb;

        if ($period === null) {
            $view   = 'month';
            $period = $view_or_period;
        } else {
            $view = $this->sanitize_view($view_or_period);
        }

        $period = $this->sanitize_period_for_view_safe($view, $period);

        if ($period === '') {
            return '';
        }

        $cache_key = $this->snapshot_cache_key($view, $period);

        if (array_key_exists($cache_key, self::$vcarb_snapshot_updated_cache)) {
            return self::$vcarb_snapshot_updated_cache[$cache_key];
        }

        $table = esc_sql($wpdb->prefix . 'amatorcarbon_logs');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned snapshot table; query is prepared and table name is derived from $wpdb->prefix.
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT created_at
                FROM `{$table}`
                WHERE user_id = 0
                  AND view_type = %s
                  AND period = %s
                LIMIT 1
                ",
                $view,
                $period
            )
        );
        // phpcs:enable

        self::$vcarb_snapshot_updated_cache[$cache_key] = is_string($value) ? $value : '';

        return self::$vcarb_snapshot_updated_cache[$cache_key];
    }

    protected function snapshot_updated_display(string $view, string $period): string
    {
        $raw = $this->get_store_snapshot_updated($view, $period);

        if ($raw !== '') {
            $formatted = $this->format_snapshot_updated($raw);

            if ($formatted !== '') {
                return $formatted;
            }
        }

        return '';
    }

    protected function format_snapshot_updated(string $mysql_dt): string
    {
        $mysql_dt = trim($mysql_dt);

        if ($mysql_dt === '') {
            return '';
        }

        $format = get_option('date_format') . ' ' . get_option('time_format');

        return mysql2date($format, $mysql_dt, false);
    }

    protected function current_period_for_view_safe(string $view): string
    {
        $view = $this->sanitize_view($view);

        if (method_exists($this, 'current_calendar_period')) {
            $current = (string) $this->current_calendar_period($view);

            return $this->sanitize_period_for_view_safe($view, $current);
        }

        return '';
    }

    protected function resolve_snapshot_anchor(string $view, string $raw_date): array
    {
        $view      = $this->sanitize_view($view);
        $requested = $this->sanitize_period_for_view_safe($view, $raw_date);
        $cache_key = $this->snapshot_cache_key($view, $requested);

        if (array_key_exists($cache_key, self::$vcarb_snapshot_anchor_cache)) {
            return self::$vcarb_snapshot_anchor_cache[$cache_key];
        }

        $result = [
            'date'         => '',
            'period'       => '',
            'has_snapshot' => false,
            'source'       => 'none',
        ];

        if ($requested !== '' && $this->store_snapshot_exists($view, $requested)) {
            $result = [
                'date'         => $requested,
                'period'       => $requested,
                'has_snapshot' => true,
                'source'       => 'requested',
            ];
        } else {
            $current = $this->current_period_for_view_safe($view);

            if ($current !== '' && $this->store_snapshot_exists($view, $current)) {
                $result = [
                    'date'         => $current,
                    'period'       => $current,
                    'has_snapshot' => true,
                    'source'       => 'current',
                ];
            } else {
                $latest = $this->latest_store_snapshot_period($view);

                if ($latest !== '' && $this->store_snapshot_exists($view, $latest)) {
                    $result = [
                        'date'         => $latest,
                        'period'       => $latest,
                        'has_snapshot' => true,
                        'source'       => 'latest',
                    ];
                }
            }
        }

        self::$vcarb_snapshot_anchor_cache[$cache_key] = $result;

        return $result;
    }

    protected function infer_view_from_period(string $period): string
    {
        $period = trim($period);

        if (preg_match('/^\d{4}-W\d{2}$/', $period)) {
            return 'week';
        }

        if (preg_match('/^\d{4}$/', $period)) {
            return 'year';
        }

        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            return 'month';
        }

        return 'month';
    }

    /**
     * Return available snapshot periods for a view, newest first by default.
     *
     * @return array<int,string>
     */
    protected function get_available_snapshot_periods(string $view, int $limit = 24, string $order = 'DESC'): array
    {
        global $wpdb;

        $view  = $this->sanitize_view($view);
        $limit = max(1, min(120, (int) $limit));
        $order = ('ASC' === strtoupper((string) $order)) ? 'ASC' : 'DESC';

        $table = esc_sql($wpdb->prefix . 'amatorcarbon_logs');
        $regex = $this->period_regex_safe($view);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned snapshot table; query is prepared and table name is derived from $wpdb->prefix.
        if ($order === 'ASC') {
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    "
                    SELECT period
                    FROM `{$table}`
                    WHERE user_id = 0
                      AND view_type = %s
                      AND period REGEXP %s
                    ORDER BY period ASC
                    LIMIT %d
                    ",
                    $view,
                    $regex,
                    $limit
                )
            );
        } else {
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    "
                    SELECT period
                    FROM `{$table}`
                    WHERE user_id = 0
                      AND view_type = %s
                      AND period REGEXP %s
                    ORDER BY period DESC
                    LIMIT %d
                    ",
                    $view,
                    $regex,
                    $limit
                )
            );
        }
        // phpcs:enable

        $periods = [];

        foreach ((array) $rows as $period) {
            $period = $this->sanitize_period_for_view_safe($view, (string) $period);

            if ($period !== '') {
                $periods[] = $period;
            }
        }

        return array_values(array_unique($periods));
    }

    /**
     * Return the previous available stored snapshot period before an anchor.
     */
    protected function get_previous_available_snapshot_period(string $view, string $anchor): string
    {
        global $wpdb;

        $view   = $this->sanitize_view($view);
        $anchor = $this->sanitize_period_for_view_safe($view, $anchor);

        if ($anchor === '') {
            return '';
        }

        $table = esc_sql($wpdb->prefix . 'amatorcarbon_logs');
        $regex = $this->period_regex_safe($view);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned snapshot table; query is prepared and table name is derived from $wpdb->prefix.
        $period = (string) $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT period
                FROM `{$table}`
                WHERE user_id = 0
                  AND view_type = %s
                  AND period REGEXP %s
                  AND period < %s
                ORDER BY period DESC
                LIMIT 1
                ",
                $view,
                $regex,
                $anchor
            )
        );
        // phpcs:enable

        return $this->sanitize_period_for_view_safe($view, $period);
    }

    /**
     * Return the next available stored snapshot period after an anchor.
     */
    protected function get_next_available_snapshot_period(string $view, string $anchor): string
    {
        global $wpdb;

        $view   = $this->sanitize_view($view);
        $anchor = $this->sanitize_period_for_view_safe($view, $anchor);

        if ($anchor === '') {
            return '';
        }

        $table = esc_sql($wpdb->prefix . 'amatorcarbon_logs');
        $regex = $this->period_regex_safe($view);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned snapshot table; query is prepared and table name is derived from $wpdb->prefix.
        $period = (string) $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT period
                FROM `{$table}`
                WHERE user_id = 0
                  AND view_type = %s
                  AND period REGEXP %s
                  AND period > %s
                ORDER BY period ASC
                LIMIT 1
                ",
                $view,
                $regex,
                $anchor
            )
        );
        // phpcs:enable

        return $this->sanitize_period_for_view_safe($view, $period);
    }

    /**
     * Build simple browsing metadata for previous / next snapshot navigation.
     *
     * @return array<string,mixed>
     */
    protected function get_snapshot_browser_data(string $view, string $anchor): array
    {
        $view   = $this->sanitize_view($view);
        $anchor = $this->sanitize_period_for_view_safe($view, $anchor);

        if ($anchor === '') {
            return [
                'current'  => '',
                'previous' => '',
                'next'     => '',
                'has_prev' => false,
                'has_next' => false,
            ];
        }

        $previous = $this->get_previous_available_snapshot_period($view, $anchor);
        $next     = $this->get_next_available_snapshot_period($view, $anchor);

        return [
            'current'  => $anchor,
            'previous' => $previous,
            'next'     => $next,
            'has_prev' => ($previous !== ''),
            'has_next' => ($next !== ''),
        ];
    }
}
