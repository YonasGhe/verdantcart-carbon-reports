<?php
defined('ABSPATH') || exit;

class VCARB_Calculator
{
    private const ALLOWED_VIEWS = ['month', 'week', 'year'];

    /** @var array<string,mixed> */
    private static array $row_cache = [];

    /** @var array<string,array> */
    private static array $series_cache = [];

    public static function get_period_keys(string $view): array
    {
        $view = self::normalize_view($view);
        $now  = new DateTimeImmutable('now', self::wp_tz());

        if ($view === 'month') {
            return [
                'current'  => $now->format('Y-m'),
                'previous' => $now->modify('-1 month')->format('Y-m'),
            ];
        }

        if ($view === 'year') {
            $current = $now->format('Y');

            return [
                'current'  => $current,
                'previous' => (string) ((int) $current - 1),
            ];
        }

        $current  = sprintf('%04d-W%02d', (int) $now->format('o'), (int) $now->format('W'));
        $previous = $now->modify('-7 days');

        return [
            'current'  => $current,
            'previous' => sprintf('%04d-W%02d', (int) $previous->format('o'), (int) $previous->format('W')),
        ];
    }

    private static function normalize_view(string $view): string
    {
        $view = sanitize_key($view);

        return in_array($view, self::ALLOWED_VIEWS, true) ? $view : 'month';
    }

    private static function wp_tz(): DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        try {
            return new DateTimeZone(wp_timezone_string() ?: 'UTC');
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }
    }

    private static function is_valid_period(string $view, string $period): bool
    {
        $view   = self::normalize_view($view);
        $period = trim($period);
        $tz     = self::wp_tz();

        if ($view === 'month') {
            if (!preg_match('/^(\d{4})-(\d{2})$/', $period, $m)) {
                return false;
            }

            $year  = (int) $m[1];
            $month = (int) $m[2];

            return $year >= 1970 && $year <= 2100 && $month >= 1 && $month <= 12;
        }

        if ($view === 'year') {
            if (!preg_match('/^\d{4}$/', $period)) {
                return false;
            }

            $year = (int) $period;

            return $year >= 1970 && $year <= 2100;
        }

        if ($view === 'week') {
            if (!preg_match('/^(\d{4})-W(\d{2})$/', $period, $m)) {
                return false;
            }

            $year = (int) $m[1];
            $week = (int) $m[2];

            if ($year < 1970 || $year > 2100 || $week < 1 || $week > 53) {
                return false;
            }

            try {
                $dt = (new DateTimeImmutable('now', $tz))
                    ->setISODate($year, $week, 1)
                    ->setTime(0, 0, 0);

                return sprintf('%04d-W%02d', (int) $dt->format('o'), (int) $dt->format('W')) === $period;
            } catch (Throwable $e) {
                return false;
            }
        }

        return false;
    }

    private static function period_minus_one(string $view, string $period): string
    {
        $view = self::normalize_view($view);
        $tz   = self::wp_tz();

        if ($view === 'month' && preg_match('/^\d{4}-\d{2}$/', $period)) {
            try {
                $dt = new DateTimeImmutable($period . '-01', $tz);
                return $dt->modify('-1 month')->format('Y-m');
            } catch (Throwable $e) {
                return '';
            }
        }

        if ($view === 'year' && preg_match('/^\d{4}$/', $period)) {
            return (string) ((int) $period - 1);
        }

        if ($view === 'week' && preg_match('/^(\d{4})-W(\d{2})$/', $period, $m)) {
            try {
                $dt = (new DateTimeImmutable('now', $tz))
                    ->setISODate((int) $m[1], (int) $m[2], 1)
                    ->setTime(0, 0, 0)
                    ->modify('-7 days');

                return sprintf('%04d-W%02d', (int) $dt->format('o'), (int) $dt->format('W'));
            } catch (Throwable $e) {
                return '';
            }
        }

        return '';
    }

    private static function period_date_range(string $view, string $period): array
    {
        $view = self::normalize_view($view);
        $tz   = self::wp_tz();

        if ($view === 'year' && preg_match('/^\d{4}$/', $period)) {
            try {
                $year  = (int) $period;
                $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), $tz);
                $end   = $start->modify('+1 year');

                return [
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end'   => $end->format('Y-m-d H:i:s'),
                ];
            } catch (Throwable $e) {
                return ['start' => '', 'end' => ''];
            }
        }

        if ($view === 'month' && preg_match('/^\d{4}-\d{2}$/', $period)) {
            try {
                $start = new DateTimeImmutable($period . '-01 00:00:00', $tz);
                $end   = $start->modify('+1 month');

                return [
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end'   => $end->format('Y-m-d H:i:s'),
                ];
            } catch (Throwable $e) {
                return ['start' => '', 'end' => ''];
            }
        }

        if ($view === 'week' && preg_match('/^(\d{4})-W(\d{2})$/', $period, $m)) {
            try {
                $start = (new DateTimeImmutable('now', $tz))
                    ->setISODate((int) $m[1], (int) $m[2], 1)
                    ->setTime(0, 0, 0);

                $end = $start->modify('+7 days');

                return [
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end'   => $end->format('Y-m-d H:i:s'),
                ];
            } catch (Throwable $e) {
                return ['start' => '', 'end' => ''];
            }
        }

        return ['start' => '', 'end' => ''];
    }

    private static function period_from_datetime(string $view, DateTimeImmutable $dt): string
    {
        $view = self::normalize_view($view);

        if ($view === 'month') {
            return $dt->format('Y-m');
        }

        if ($view === 'year') {
            return $dt->format('Y');
        }

        return sprintf('%04d-W%02d', (int) $dt->format('o'), (int) $dt->format('W'));
    }

    private static function row_float($row, string $field, $default)
    {
        if (!is_object($row) || !isset($row->{$field})) {
            return $default;
        }

        $value = $row->{$field};

        if ($value === null || $value === '') {
            return $default;
        }

        return (float) $value;
    }

    public static function get_row_legacy(int $user_id, string $period, string $view = 'month')
    {
        return self::get_row($user_id, $view, $period);
    }

    public static function get_row(int $user_id, string $view_or_period, ?string $period = null)
    {
        global $wpdb;

        $table = esc_sql(self::logs_table());

        if ($period === null) {
            $view   = 'month';
            $period = $view_or_period;
        } else {
            $view = self::normalize_view($view_or_period);
        }

        $period = trim((string) $period);

        if ($period === '' || !self::is_valid_period($view, $period)) {
            return null;
        }

        $cache_key = $user_id . '|' . $view . '|' . $period;
        if (array_key_exists($cache_key, self::$row_cache)) {
            return self::$row_cache[$cache_key];
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT *
                FROM `{$table}`
                WHERE user_id = %d
                  AND view_type = %s
                  AND period = %s
                LIMIT 1
                ",
                $user_id,
                $view,
                $period
            )
        );
        // phpcs:enable

        self::$row_cache[$cache_key] = $row;

        return $row;
    }

    public static function get_series(int $user_id, $view_or_limit = 'month', int $limit = 6): array
    {
        global $wpdb;

        $table = esc_sql(self::logs_table());

        if (is_int($view_or_limit) || ctype_digit((string) $view_or_limit)) {
            $view  = 'month';
            $limit = (int) $view_or_limit;
        } else {
            $view = self::normalize_view((string) $view_or_limit);
        }

        $pattern = ($view === 'year')
            ? '____'
            : (($view === 'month') ? '____-__' : '____-W__');

        $limit = max(1, (int) $limit);

        $cache_key = $user_id . '|' . $view . '|' . $limit;
        if (isset(self::$series_cache[$cache_key])) {
            return self::$series_cache[$cache_key];
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT period, total_co2, orders
                FROM `{$table}`
                WHERE user_id = %d
                  AND view_type = %s
                  AND period LIKE %s
                ORDER BY period DESC
                LIMIT %d
                ",
                $user_id,
                $view,
                $pattern,
                $limit
            )
        );
        // phpcs:enable

        self::$series_cache[$cache_key] = (array) $rows;

        return self::$series_cache[$cache_key];
    }

    public static function percent_change($current, $previous)
    {
        if ($previous === null || $previous === '' || !is_numeric($previous)) {
            return null;
        }

        $current  = is_numeric($current) ? (float) $current : 0.0;
        $previous = (float) $previous;

        /*
     * No meaningful comparison when previous value is zero.
     * This prevents ugly first-period output like -100%, +100%, or fake 0%.
     */
        if ($previous <= 0.0) {
            return null;
        }

        return (($current - $previous) / abs($previous)) * 100.0;
    }

    public static function format_percent($percent): string
    {
        if ($percent === null || $percent === '' || !is_numeric($percent)) {
            return '<span class="gc-neutral">—</span>';
        }

        $percent = (float) $percent;

        if (abs($percent) < 0.01) {
            return '<span class="gc-neutral">0%</span>';
        }

        if ($percent > 999.9) {
            return '<span class="gc-up">' . esc_html__('Up sharply', 'verdantcart-ai-reports') . '</span>';
        }

        if ($percent < -999.9) {
            return '<span class="gc-down">' . esc_html__('Down sharply', 'verdantcart-ai-reports') . '</span>';
        }

        if ($percent > 0) {
            return sprintf(
                '<span class="gc-up">%s%%</span>',
                esc_html(number_format_i18n(abs($percent), 1))
            );
        }

        return sprintf(
            '<span class="gc-down">%s%%</span>',
            esc_html(number_format_i18n(abs($percent), 1))
        );
    }
    public static function compare(int $user_id, string $view = 'month', string $anchor = ''): array
    {
        $view   = self::normalize_view($view);
        $anchor = trim($anchor);

        if ($anchor !== '' && self::is_valid_period($view, $anchor)) {
            $previous_period = self::period_minus_one($view, $anchor);
            $current_row     = self::get_row($user_id, $view, $anchor);
            $previous_row    = ($previous_period !== '') ? self::get_row($user_id, $view, $previous_period) : null;

            $current_co2  = self::row_float($current_row, 'total_co2', 0.0);
            $previous_co2 = $previous_row ? self::row_float($previous_row, 'total_co2', null) : null;

            return [
                'current'  => $current_row,
                'previous' => $previous_row,
                'delta'    => self::percent_change($current_co2, $previous_co2),
            ];
        }

        $periods      = self::get_period_keys($view);
        $current_row  = self::get_row($user_id, $view, (string) ($periods['current'] ?? ''));
        $previous_row = self::get_row($user_id, $view, (string) ($periods['previous'] ?? ''));

        $current_co2  = self::row_float($current_row, 'total_co2', 0.0);
        $previous_co2 = $previous_row ? self::row_float($previous_row, 'total_co2', null) : null;

        return [
            'current'  => $current_row,
            'previous' => $previous_row,
            'delta'    => self::percent_change($current_co2, $previous_co2),
        ];
    }

    private static function logs_table(): string
    {
        global $wpdb;

        /*
         * Keep legacy table name for now to preserve existing 1.0.2 user data.
         * Do not rename this to vcarb_logs until you add a safe migration.
         */
        return $wpdb->prefix . 'amatorcarbon_logs';
    }

    private static function co2_meta_key(): string
    {
        return class_exists('VCARB_Order_Tracker')
            ? VCARB_Order_Tracker::META_CO2_KG
            : '_vcarb_order_co2_kg';
    }

    private static function resolve_target_period(string $view, string $period): string
    {
        $period = trim($period);

        if ($period !== '' && self::is_valid_period($view, $period)) {
            return $period;
        }

        $periods = self::get_period_keys($view);

        return (string) ($periods['current'] ?? '');
    }

    private static function get_final_statuses(): array
    {
        $statuses = (array) apply_filters(
            'vcarb_final_statuses',
            ['processing', 'completed'],
            null
        );

        /*
         * Temporary legacy filter support from 1.0.x.
         */
        $statuses = (array) apply_filters(
            'amatorcarbon_final_statuses',
            $statuses,
            null
        );

        $statuses = array_values(array_unique(array_filter(array_map('sanitize_key', $statuses))));

        return empty($statuses) ? ['processing', 'completed'] : $statuses;
    }

    private static function get_order_datetime(WC_Order $order): ?DateTimeImmutable
    {
        $date_obj = $order->get_date_paid();

        if (!$date_obj) {
            $date_obj = $order->get_date_completed();
        }

        if (!$date_obj) {
            $date_obj = $order->get_date_created();
        }

        if (!$date_obj) {
            return null;
        }

        try {
            return (new DateTimeImmutable('@' . $date_obj->getTimestamp()))
                ->setTimezone(self::wp_tz());
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function delete_period_rows(string $view, string $period): void
    {
        global $wpdb;

        $table = esc_sql(self::logs_table());

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "
                DELETE FROM `{$table}`
                WHERE view_type = %s
                  AND period = %s
                ",
                $view,
                $period
            )
        );
        // phpcs:enable
    }

    private static function upsert_user_snapshot_row(
        int $user_id,
        string $view,
        string $period,
        int $orders_total,
        float $co2_total,
        string $now
    ): void {
        global $wpdb;

        $table        = esc_sql(self::logs_table());
        $orders_total = max(0, $orders_total);
        $co2_str      = number_format(max(0.0, $co2_total), 2, '.', '');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "
                INSERT INTO `{$table}` (user_id, view_type, period, orders, total_co2, created_at)
                VALUES (%d, %s, %s, %d, %s, %s)
                ON DUPLICATE KEY UPDATE
                    orders     = VALUES(orders),
                    total_co2  = VALUES(total_co2),
                    created_at = VALUES(created_at)
                ",
                $user_id,
                $view,
                $period,
                $orders_total,
                $co2_str,
                $now
            )
        );
        // phpcs:enable

        unset(self::$row_cache[$user_id . '|' . $view . '|' . $period]);
    }

    private static function upsert_store_snapshot_row_raw(
        string $table,
        string $view,
        string $period,
        int $orders_total,
        float $co2_total,
        string $now
    ): void {
        global $wpdb;

        $table        = esc_sql($table);
        $view         = self::normalize_view($view);
        $orders_total = max(0, (int) $orders_total);
        $co2_str      = number_format(max(0.0, (float) $co2_total), 2, '.', '');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "
                INSERT INTO `{$table}` (user_id, view_type, period, orders, total_co2, created_at)
                VALUES (0, %s, %s, %d, %s, %s)
                ON DUPLICATE KEY UPDATE
                    orders     = VALUES(orders),
                    total_co2  = VALUES(total_co2),
                    created_at = VALUES(created_at)
                ",
                $view,
                $period,
                $orders_total,
                $co2_str,
                $now
            )
        );
        // phpcs:enable

        unset(self::$row_cache['0|' . $view . '|' . $period]);
    }

    private static function collect_period_totals(string $view, string $period): array
    {
        $range = self::period_date_range($view, $period);

        if ($range['start'] === '' || $range['end'] === '') {
            return [
                'users'        => [],
                'store_orders' => 0,
                'store_co2'    => 0.0,
            ];
        }

        if (!function_exists('wc_get_orders')) {
            return [
                'users'        => [],
                'store_orders' => 0,
                'store_co2'    => 0.0,
            ];
        }

        $totals         = [];
        $store_orders   = 0;
        $store_co2      = 0.0;
        $page           = 1;
        $limit          = 50;
        $max_pages      = 1;
        $co2_meta_key   = self::co2_meta_key();
        $final_statuses = self::get_final_statuses();

        if (empty($final_statuses)) {
            return [
                'users'        => [],
                'store_orders' => 0,
                'store_co2'    => 0.0,
            ];
        }

        do {
            $query = wc_get_orders([
                'status'       => $final_statuses,
                'limit'        => $limit,
                'paged'        => $page,
                'paginate'     => true,
                'return'       => 'objects',
                'orderby'      => 'date',
                'order'        => 'ASC',
                'date_created' => $range['start'] . '...' . $range['end'],
            ]);

            $orders = (is_object($query) && isset($query->orders) && is_array($query->orders))
                ? $query->orders
                : [];

            $max_pages = (is_object($query) && isset($query->max_num_pages))
                ? max(1, (int) $query->max_num_pages)
                : 1;

            if (empty($orders)) {
                unset($query);
                break;
            }

            foreach ($orders as $order) {
                if (!($order instanceof WC_Order)) {
                    continue;
                }

                if (
                    class_exists('VCARB_Order_Tracker') &&
                    method_exists('VCARB_Order_Tracker', 'should_exclude_order_from_analytics') &&
                    VCARB_Order_Tracker::should_exclude_order_from_analytics($order)
                ) {
                    continue;
                }

                $dt = self::get_order_datetime($order);

                if (!$dt || self::period_from_datetime($view, $dt) !== $period) {
                    continue;
                }

                $co2_raw = $order->get_meta($co2_meta_key, true);
                $co2     = ($co2_raw === '' || $co2_raw === null) ? 0.0 : (float) $co2_raw;

                $store_orders++;
                $store_co2 += $co2;

                $user_id = (int) $order->get_customer_id();

                if ($user_id > 0) {
                    if (!isset($totals[$user_id])) {
                        $totals[$user_id] = [
                            'orders' => 0,
                            'co2'    => 0.0,
                        ];
                    }

                    $totals[$user_id]['orders'] += 1;
                    $totals[$user_id]['co2']    += $co2;
                }
            }

            unset($orders, $query);

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $page++;
        } while ($page <= $max_pages);

        return [
            'users'        => $totals,
            'store_orders' => $store_orders,
            'store_co2'    => $store_co2,
        ];
    }

    public static function aggregate(string $view, string $period = ''): void
    {
        if (!function_exists('wc_get_orders') || !function_exists('wc_get_order')) {
            return;
        }

        $view   = self::normalize_view($view);
        $period = self::resolve_target_period($view, $period);

        if ($period === '') {
            return;
        }

        /*
         * Year snapshots are derived from monthly snapshots.
         * This keeps yearly reporting snapshot-based and avoids mixing
         * direct WooCommerce scans with month-rollup totals.
         */
        if ($view === 'year') {
            self::build_year_snapshot($period);
            return;
        }

        $lock_key = 'vcarb_agg_lock_' . md5($view . '|' . $period);

        if (get_transient($lock_key)) {
            return;
        }

        set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);

        try {
            global $wpdb;

            $table = esc_sql(self::logs_table());
            $now   = current_time('mysql');

            $totals = self::collect_period_totals($view, $period);

            $users_totals = isset($totals['users']) && is_array($totals['users'])
                ? $totals['users']
                : [];

            $store_orders = isset($totals['store_orders'])
                ? (int) $totals['store_orders']
                : 0;

            $store_co2 = isset($totals['store_co2'])
                ? (float) $totals['store_co2']
                : 0.0;

            $written_user_ids = [];

            foreach ($users_totals as $user_id => $row) {
                $user_id = (int) $user_id;

                if ($user_id <= 0) {
                    continue;
                }

                self::upsert_user_snapshot_row(
                    $user_id,
                    $view,
                    $period,
                    (int) ($row['orders'] ?? 0),
                    (float) ($row['co2'] ?? 0.0),
                    $now
                );

                $written_user_ids[] = $user_id;
            }

            $written_user_ids = array_values(array_unique(array_filter(array_map('intval', $written_user_ids))));

            if (!empty($written_user_ids)) {
                $placeholders = implode(', ', array_fill(0, count($written_user_ids), '%d'));
                $prepare_args = array_merge([$view, $period], array_map('absint', $written_user_ids));

                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dynamic IN placeholders are generated from validated integer IDs and values are prepared.
                $wpdb->query(
                    $wpdb->prepare(
                        "
            DELETE FROM `{$table}`
            WHERE view_type = %s
              AND period = %s
              AND user_id <> 0
              AND user_id NOT IN ({$placeholders})
            ",
                        ...$prepare_args
                    )
                );
                // phpcs:enable
            } else {
                // No customer rows for this period, so clear all non-store rows only.
                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; values are prepared.
                $wpdb->query(
                    $wpdb->prepare(
                        "
            DELETE FROM `{$table}`
            WHERE view_type = %s
              AND period = %s
              AND user_id <> 0
            ",
                        $view,
                        $period
                    )
                );
                // phpcs:enable
            }

            self::upsert_store_snapshot_row_raw(
                $table,
                $view,
                $period,
                $store_orders,
                $store_co2,
                $now
            );

            if ($view === 'month' && preg_match('/^\d{4}-\d{2}$/', $period)) {
                self::build_year_snapshot(substr($period, 0, 4));
            } elseif ($view === 'week' && preg_match('/^(\d{4})-W(\d{2})$/', $period, $m)) {
                self::build_year_snapshot($m[1]);
            }

            foreach (array_keys(self::$series_cache) as $cache_key) {
                if (strpos($cache_key, '|' . $view . '|') !== false) {
                    unset(self::$series_cache[$cache_key]);
                }
            }

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        } finally {
            delete_transient($lock_key);
        }
    }

    public static function calculate_month(int $user_id = 0): array
    {
        $periods = self::get_period_keys('month');
        $row     = self::get_row($user_id, 'month', (string) ($periods['current'] ?? ''));

        return [
            'orders'    => (int) ($row->orders ?? 0),
            'total_co2' => (float) ($row->total_co2 ?? 0),
        ];
    }

    public static function build_year_snapshot($year): void
    {
        global $wpdb;

        $year = trim((string) $year);

        if (!preg_match('/^\d{4}$/', $year)) {
            return;
        }

        $table = esc_sql(self::logs_table());
        $now   = current_time('mysql');

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "
            SELECT
                user_id,
                SUM(orders) AS orders,
                SUM(total_co2) AS total_co2
            FROM `{$table}`
            WHERE view_type = %s
              AND period LIKE %s
            GROUP BY user_id
            ",
                'month',
                $year . '-%'
            )
        );
        // phpcs:enable

        $written_user_ids = [];

        if (is_array($rows) && !empty($rows)) {
            foreach ($rows as $row) {
                $user_id      = isset($row->user_id) ? (int) $row->user_id : 0;
                $orders_total = isset($row->orders) ? (int) $row->orders : 0;
                $co2_total    = isset($row->total_co2) ? (float) $row->total_co2 : 0.0;

                if ($user_id < 0) {
                    continue;
                }

                if ($user_id === 0) {
                    self::upsert_store_snapshot_row_raw(
                        self::logs_table(),
                        'year',
                        $year,
                        max(0, $orders_total),
                        max(0.0, $co2_total),
                        $now
                    );
                } else {
                    self::upsert_user_snapshot_row(
                        $user_id,
                        'year',
                        $year,
                        max(0, $orders_total),
                        max(0.0, $co2_total),
                        $now
                    );

                    $written_user_ids[] = $user_id;
                }

                unset(self::$row_cache[$user_id . '|year|' . $year]);
            }
        }

        $written_user_ids = array_values(
            array_unique(
                array_filter(
                    array_map('intval', $written_user_ids)
                )
            )
        );

        if (!empty($written_user_ids)) {
            $placeholders = implode(', ', array_fill(0, count($written_user_ids), '%d'));
            $prepare_args = array_merge(['year', $year], array_map('absint', $written_user_ids));

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; dynamic IN placeholders are generated from validated integer IDs and values are prepared.
            $wpdb->query(
                $wpdb->prepare(
                    "
            DELETE FROM `{$table}`
            WHERE view_type = %s
              AND period = %s
              AND user_id <> 0
              AND user_id NOT IN ({$placeholders})
            ",
                    ...$prepare_args
                )
            );
            // phpcs:enable
        } else {
            // No user rows remain for this year, clear all non-store year rows.
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; values are prepared.
            $wpdb->query(
                $wpdb->prepare(
                    "
            DELETE FROM `{$table}`
            WHERE view_type = %s
              AND period = %s
              AND user_id <> 0
            ",
                    'year',
                    $year
                )
            );
            // phpcs:enable
        }

        // If month snapshots returned no rows at all, also clear any stale store row.
        if (!is_array($rows) || empty($rows)) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                $wpdb->prepare(
                    "
                DELETE FROM `{$table}`
                WHERE view_type = %s
                  AND period = %s
                  AND user_id = 0
                ",
                    'year',
                    $year
                )
            );
            // phpcs:enable

            unset(self::$row_cache['0|year|' . $year]);
        }

        foreach (array_keys(self::$series_cache) as $cache_key) {
            if (strpos($cache_key, '|year|') !== false) {
                unset(self::$series_cache[$cache_key]);
            }
        }
    }

    public static function calculate_store_emissions(): array
    {
        return self::calculate_month(0);
    }
}
