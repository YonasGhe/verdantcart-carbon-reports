<?php
defined('ABSPATH') || exit;

/**
 * Shared dataset builder for:
 * - Front dashboard
 * - Admin report
 * - Exports
 *
 * Snapshot-only source of truth for:
 * - selected-period metrics
 * - snapshot info
 * - chart payload
 * - admin rows
 * - hotspots
 * - insights
 * - score
 *
 * Important:
 * - No live WooCommerce order scans in normal request-time rendering
 * - No live week fallback on dashboard/admin page loads
 * - Heavy recalculation belongs only in cron/backfill/rebuild flows
 *
 * Database note:
 * - Uses the current VerdantCart table helper.
 * - Existing 1.0.2 data should be copied into the new vcarb_* tables by the DB migration.
 */
class VCARB_Dataset_Service
{
    use VCARB_Snapshot_Trait;
    use VCARB_Period_Trait;

    /** @var array<int,string> */
    private const DATASET_ALLOWED_VIEWS = ['month', 'week', 'year'];

    private const ADMIN_ROWS_LIMIT = 200;

    public function build_user_dataset(int $user_id, string $view, string $raw_date = ''): array
    {
        if ($user_id <= 0) {
            return $this->empty_dataset(
                'user',
                'month',
                'none',
                __('Invalid user.', 'verdantcart-ai-reports')
            );
        }

        $view = $this->normalize_view($view);

        if (!class_exists('VCARB_Calculator')) {
            return $this->empty_dataset(
                'user',
                $view,
                'none',
                __('Calculator missing.', 'verdantcart-ai-reports')
            );
        }

        $requested_date = $this->safe_sanitize_period_for_view($view, $raw_date);
        $anchor_info    = $this->resolve_dataset_anchor($view, $requested_date);

        $date   = (string) ($anchor_info['date'] ?? '');
        $source = (string) ($anchor_info['source'] ?? 'none');
        $has    = !empty($anchor_info['has_snapshot']);

        if (!$has || $date === '') {
            return $this->empty_dataset(
                'user',
                $view,
                $source,
                __('No snapshot available for this period yet.', 'verdantcart-ai-reports')
            );
        }

        $snapshot = $this->build_snapshot_block($view, $date, $source, $has);
        $metrics  = $this->build_user_metrics_block_snapshot_only($user_id, $view, $date);

        $metrics['updated']     = (string) ($snapshot['updated'] ?? '');
        $metrics['updated_raw'] = (string) ($snapshot['updated_raw'] ?? '');

        $chart = $this->build_chart_block_snapshot_only($user_id, $view, $date);
        $chart = $this->align_chart_current_point_with_metrics($chart, $view, $date, $metrics);

        /*
     * Product hotspots are stored as store-level period snapshots.
     * Show them on the front dashboard so the Carbon Hotspot panel can display data.
     */
        $hotspots = $this->build_hotspots_block($view, $date, $has);

        $metrics['hotspots'] = $hotspots;

        $insights = $this->build_user_insights_block($metrics, $has);
        $score    = $this->extract_score_from_insights($insights, $metrics);
        $browser  = $this->build_browser_block($view, $date);

        return [
            'scope'    => 'user',
            'user_id'  => $user_id,
            'view'     => $view,
            'date'     => $date,
            'browser'  => $browser,
            'snapshot' => $snapshot,
            'metrics'  => $metrics,
            'chart'    => $chart,
            'rows'     => [],
            'hotspots' => $hotspots,
            'insights' => $insights,
            'score'    => $score,
            'notice'   => (string) ($metrics['notice'] ?? ''),
        ];
    }

    public function build_admin_dataset(string $view, string $raw_date = '', int $admin_id = 0): array
    {
        $view = $this->normalize_view($view);

        if (!class_exists('VCARB_Calculator')) {
            return $this->empty_dataset(
                'admin',
                $view,
                'none',
                __('Calculator missing.', 'verdantcart-ai-reports')
            );
        }

        $requested_date = $this->safe_sanitize_period_for_view($view, $raw_date);
        $anchor_info    = $this->resolve_dataset_anchor($view, $requested_date);

        $date   = (string) ($anchor_info['date'] ?? '');
        $source = (string) ($anchor_info['source'] ?? 'none');
        $has    = !empty($anchor_info['has_snapshot']);

        if (!$has || $date === '') {
            return $this->empty_dataset(
                'admin',
                $view,
                $source,
                __('No snapshot available for this period yet.', 'verdantcart-ai-reports')
            );
        }

        $snapshot = $this->build_snapshot_block($view, $date, $source, $has);
        $metrics  = $this->build_admin_metrics_block_snapshot_only($view, $date);

        $metrics['updated']     = (string) ($snapshot['updated'] ?? '');
        $metrics['updated_raw'] = (string) ($snapshot['updated_raw'] ?? '');

        $chart    = $this->build_chart_block_snapshot_only(0, $view, $date);
        $chart    = $this->align_chart_current_point_with_metrics($chart, $view, $date, $metrics);
        $rows     = $this->build_admin_rows_block_snapshot_only($view, $date);

        $hotspots = $this->build_hotspots_block($view, $date, $has);
        $insights = $this->build_admin_insights_block($view, $date, $has, $metrics, $hotspots);
        $score    = $this->extract_score_from_insights($insights, $metrics);
        $browser  = $this->build_browser_block($view, $date);

        return [
            'scope'    => 'admin',
            'user_id'  => 0,
            'admin_id' => $admin_id,
            'view'     => $view,
            'date'     => $date,
            'browser'  => $browser,
            'snapshot' => $snapshot,
            'metrics'  => $metrics,
            'chart'    => $chart,
            'rows'     => $rows,
            'hotspots' => $hotspots,
            'insights' => $insights,
            'score'    => $score,
            'notice'   => (string) ($metrics['notice'] ?? ''),
        ];
    }

    protected function normalize_view(string $view): string
    {
        $view = sanitize_key($view);

        return in_array($view, self::DATASET_ALLOWED_VIEWS, true) ? $view : 'month';
    }

    protected function logs_table(): string
    {
        if (function_exists('vcarb_logs_table')) {
            return vcarb_logs_table();
        }

        global $wpdb;

        return $wpdb->prefix . 'vcarb_logs';
    }

    protected function safe_sanitize_period_for_view(string $view, string $raw_date): string
    {
        $view = $this->normalize_view($view);
        $date = $this->sanitize_period_for_view($view, $raw_date);

        return is_string($date) ? $date : '';
    }

    protected function resolve_dataset_anchor(string $view, string $requested_date): array
    {
        $view   = $this->normalize_view($view);
        $anchor = $this->resolve_snapshot_anchor($view, $requested_date);

        return [
            'date'         => (string) ($anchor['date'] ?? ''),
            'source'       => (string) ($anchor['source'] ?? 'none'),
            'has_snapshot' => !empty($anchor['has_snapshot']),
        ];
    }

    protected function build_snapshot_block(
        string $view,
        string $date,
        string $source,
        bool $has_snapshot
    ): array {
        $updated_raw = '';
        $updated     = '';

        if ($has_snapshot) {
            $updated_raw = (string) $this->get_store_snapshot_updated($view, $date);
            $updated     = $this->snapshot_updated_display($view, $date);
        }

        return [
            'has'         => $has_snapshot,
            'period'      => $date,
            'source'      => $source,
            'updated'     => $updated,
            'updated_raw' => $updated_raw,
            'message'     => '',
        ];
    }

    protected function build_user_metrics_block_snapshot_only(int $user_id, string $view, string $date): array
    {
        $current   = VCARB_Calculator::get_row($user_id, $view, $date);
        $prev_date = $this->get_previous_available_snapshot_period($view, $date);
        $previous  = ($prev_date !== '') ? VCARB_Calculator::get_row($user_id, $view, $prev_date) : null;

        $total_co2 = is_object($current) ? (float) ($current->total_co2 ?? 0.0) : 0.0;
        $orders    = is_object($current) ? (int) ($current->orders ?? 0) : 0;

        $previous_total_co2 = is_object($previous) ? (float) ($previous->total_co2 ?? 0.0) : null;

        $delta = VCARB_Calculator::percent_change($total_co2, $previous_total_co2);

        $notice = '';
        if (!is_object($current) || ($orders === 0 && $total_co2 === 0.0)) {
            $notice = __('No personal activity in this period yet.', 'verdantcart-ai-reports');
        }

        return [
            'current'       => is_object($current) ? $current : null,
            'previous'      => is_object($previous) ? $previous : null,
            'prev_date'     => $prev_date,
            'total_co2'     => $total_co2,
            'orders'        => $orders,
            'delta'         => $delta,
            'delta_html'    => $this->format_percent_html($delta),
            'co2_per_order' => ($orders > 0) ? ($total_co2 / $orders) : null,
            'notice'        => $notice,
        ];
    }

    protected function build_admin_metrics_block_snapshot_only(string $view, string $date): array
    {
        $current   = VCARB_Calculator::get_row(0, $view, $date);
        $prev_date = $this->get_previous_available_snapshot_period($view, $date);
        $previous  = ($prev_date !== '') ? VCARB_Calculator::get_row(0, $view, $prev_date) : null;

        $total_co2 = is_object($current) ? (float) ($current->total_co2 ?? 0.0) : 0.0;
        $orders    = is_object($current) ? (int) ($current->orders ?? 0) : 0;

        $previous_total_co2 = is_object($previous) ? (float) ($previous->total_co2 ?? 0.0) : null;

        $delta = VCARB_Calculator::percent_change($total_co2, $previous_total_co2);

        $notice = '';
        if (!is_object($current) || ($orders === 0 && $total_co2 === 0.0)) {
            $notice = __('No activity in this period yet.', 'verdantcart-ai-reports');
        }

        return [
            'current'       => is_object($current) ? $current : null,
            'previous'      => is_object($previous) ? $previous : null,
            'prev_date'     => $prev_date,
            'total_co2'     => $total_co2,
            'orders'        => $orders,
            'delta'         => $delta,
            'delta_html'    => $this->format_percent_html($delta),
            'co2_per_order' => ($orders > 0) ? ($total_co2 / $orders) : null,
            'notice'        => $notice,
        ];
    }

    protected function logs_table_exists(): bool
    {
        if (function_exists('vcarb_table_exists')) {
            return vcarb_table_exists($this->logs_table());
        }

        global $wpdb;

        $table = $this->logs_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking plugin-owned table existence.
        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($table)
            )
        );

        return is_string($found) && $found === $table;
    }

    protected function build_chart_block_snapshot_only(int $user_id, string $view, string $date): array
    {
        $chart = [
            'labels'      => [],
            'co2'         => [],
            'orders'      => [],
            'periods'     => [],
            'snapshotSet' => [],
        ];

        if ($date === '') {
            return $chart;
        }

        $series = $this->build_snapshot_chart_series($user_id, $view, $date);

        $chart['labels']  = array_values((array) ($series['labels'] ?? []));
        $chart['co2']     = array_values((array) ($series['co2'] ?? []));
        $chart['orders']  = array_values((array) ($series['orders'] ?? []));
        $chart['periods'] = array_values((array) ($series['periods'] ?? []));

        foreach ($chart['periods'] as $period) {
            $chart['snapshotSet'][(string) $period] = true;
        }

        return $chart;
    }

    protected function align_chart_current_point_with_metrics(array $chart, string $view, string $date, array $metrics): array
    {
        $view = $this->normalize_view($view);
        $date = $this->safe_sanitize_period_for_view($view, $date);

        if ($date === '' || $view === 'year') {
            return $chart;
        }

        $chart['labels']      = array_values((array) ($chart['labels'] ?? []));
        $chart['orders']      = array_values((array) ($chart['orders'] ?? []));
        $chart['co2']         = array_values((array) ($chart['co2'] ?? []));
        $chart['periods']     = array_values((array) ($chart['periods'] ?? []));
        $chart['snapshotSet'] = is_array($chart['snapshotSet'] ?? null) ? $chart['snapshotSet'] : [];

        $current_orders = (int) ($metrics['orders'] ?? 0);
        $current_co2    = round((float) ($metrics['total_co2'] ?? 0.0), 2);

        $index = array_search($date, $chart['periods'], true);

        if ($index === false) {
            $chart['labels'][]  = $this->chart_label_for_period($view, $date);
            $chart['orders'][]  = $current_orders;
            $chart['co2'][]     = $current_co2;
            $chart['periods'][] = $date;
        } else {
            $chart['orders'][$index] = $current_orders;
            $chart['co2'][$index]    = $current_co2;
        }

        $chart['snapshotSet'][(string) $date] = true;

        return $chart;
    }

    protected function build_snapshot_chart_series(int $user_id, string $view, string $anchor): array
    {
        $view   = $this->normalize_view($view);
        $anchor = $this->safe_sanitize_period_for_view($view, $anchor);

        if ($anchor === '') {
            return $this->empty_snapshot_chart_series();
        }

        if ($view === 'year') {
            return $this->build_year_snapshot_chart_series($user_id, $anchor);
        }

        return $this->build_single_snapshot_chart_series($user_id, $view, $anchor);
    }

    protected function build_year_snapshot_chart_series(int $user_id, string $anchor): array
    {
        global $wpdb;

        if (!$this->logs_table_exists()) {
            return $this->empty_snapshot_chart_series();
        }

        if (!preg_match('/^\d{4}$/', $anchor)) {
            return $this->empty_snapshot_chart_series();
        }

        $table = esc_sql($this->logs_table());
        $year  = (int) $anchor;
        $like  = $anchor . '-%';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned snapshot table; table name is derived from $wpdb->prefix and escaped.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT period, orders, total_co2
                FROM `{$table}`
                WHERE user_id = %d
                  AND view_type = %s
                  AND period LIKE %s
                ORDER BY period ASC
                ",
                $user_id,
                'month',
                $like
            )
        );
        // phpcs:enable

        $row_map = [];

        foreach ((array) $rows as $row) {
            $period = (string) ($row->period ?? '');

            if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
                continue;
            }

            $row_map[$period] = [
                'orders' => (int) ($row->orders ?? 0),
                'co2'    => round((float) ($row->total_co2 ?? 0.0), 2),
            ];
        }

        try {
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $now      = new DateTimeImmutable('now', $timezone);
        } catch (Throwable $e) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        $current_year = (int) $now->format('Y');
        $max_month    = ($year === $current_year) ? (int) $now->format('n') : 12;

        $labels  = [];
        $orders  = [];
        $co2     = [];
        $periods = [];

        $running_orders = 0;
        $running_co2    = 0.0;

        for ($month = 1; $month <= $max_month; $month++) {
            $period = sprintf('%04d-%02d', $year, $month);

            if (isset($row_map[$period])) {
                $running_orders += (int) $row_map[$period]['orders'];
                $running_co2    += (float) $row_map[$period]['co2'];
            }

            $labels[]  = $this->chart_label_for_period('month', $period);
            $orders[]  = $running_orders;
            $co2[]     = round($running_co2, 2);
            $periods[] = $period;
        }

        return [
            'labels'  => $labels,
            'orders'  => $orders,
            'co2'     => $co2,
            'periods' => $periods,
        ];
    }

    protected function build_single_snapshot_chart_series(
        int $user_id,
        string $view,
        string $anchor
    ): array {
        global $wpdb;

        if (!$this->logs_table_exists()) {
            return $this->empty_snapshot_chart_series();
        }

        $table  = esc_sql($this->logs_table());
        $view   = $this->normalize_view($view);
        $anchor = $this->safe_sanitize_period_for_view($view, $anchor);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned snapshot table; table name is derived from $wpdb->prefix and escaped.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "
                SELECT period, orders, total_co2
                FROM `{$table}`
                WHERE user_id = %d
                  AND view_type = %s
                  AND period = %s
                LIMIT 1
                ",
                $user_id,
                $view,
                $anchor
            )
        );
        // phpcs:enable

        if (!$row) {
            return $this->empty_snapshot_chart_series();
        }

        return [
            'labels'  => [$this->chart_label_for_period($view, $anchor)],
            'orders'  => [(int) ($row->orders ?? 0)],
            'co2'     => [round((float) ($row->total_co2 ?? 0.0), 2)],
            'periods' => [$anchor],
        ];
    }

    protected function empty_snapshot_chart_series(): array
    {
        return [
            'labels'  => [],
            'orders'  => [],
            'co2'     => [],
            'periods' => [],
        ];
    }

    protected function build_admin_rows_block_snapshot_only(string $view, string $date): array
    {
        global $wpdb;

        if (!$this->logs_table_exists()) {
            return [];
        }

        $view = $this->normalize_view($view);
        $date = $this->safe_sanitize_period_for_view($view, $date);

        if ($date === '') {
            return [];
        }

        $table = esc_sql($this->logs_table());

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned snapshot table; table name is derived from $wpdb->prefix and escaped.
        $current_rows = $wpdb->get_results(
            $wpdb->prepare(
                "
                SELECT user_id, orders, total_co2
                FROM `{$table}`
                WHERE view_type = %s
                  AND period = %s
                  AND user_id != 0
                ORDER BY total_co2 DESC, orders DESC
                LIMIT %d
                ",
                $view,
                $date,
                self::ADMIN_ROWS_LIMIT
            )
        );
        // phpcs:enable

        $current_rows = is_array($current_rows) ? $current_rows : [];
        $prev_date    = $this->get_previous_available_snapshot_period($view, $date);
        $prev_map     = [];

        if ($prev_date !== '' && !empty($current_rows)) {
            $user_ids = [];

            foreach ($current_rows as $row) {
                $uid = (int) ($row->user_id ?? 0);

                if ($uid > 0) {
                    $user_ids[] = $uid;
                }
            }

            $user_ids = array_values(array_unique($user_ids));

            if (!empty($user_ids)) {
                $placeholders = implode(', ', array_fill(0, count($user_ids), '%d'));
                $params       = array_merge([$view, $prev_date], array_map('absint', $user_ids));

                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned snapshot table; placeholder list is generated from integers.
                $prev_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "
                        SELECT user_id, orders, total_co2
                        FROM `{$table}`
                        WHERE view_type = %s
                          AND period = %s
                          AND user_id IN ({$placeholders})
                        ",
                        ...$params
                    )
                );
                // phpcs:enable

                foreach ((array) $prev_rows as $row) {
                    $prev_map[(int) ($row->user_id ?? 0)] = $row;
                }
            }
        }

        return $this->format_admin_rows(
            $current_rows,
            $prev_map,
            $this->snapshot_updated_display($view, $date)
        );
    }

    protected function format_admin_rows(array $current_rows, array $prev_map, string $updated): array
    {
        $rows_out   = [];
        $user_cache = [];

        foreach ($current_rows as $row) {
            $uid = (int) ($row->user_id ?? 0);

            if ($uid <= 0) {
                continue;
            }

            $orders_now = (int) ($row->orders ?? 0);
            $co2_now    = (float) ($row->total_co2 ?? 0.0);

            $prev_row    = $prev_map[$uid] ?? null;
            $orders_prev = $prev_row ? (int) ($prev_row->orders ?? 0) : null;
            $co2_prev    = $prev_row ? (float) ($prev_row->total_co2 ?? 0.0) : null;

            $orders_delta = VCARB_Calculator::percent_change($orders_now, $orders_prev);
            $co2_delta    = VCARB_Calculator::percent_change($co2_now, $co2_prev);

            $rows_out[] = [
                'user'             => $this->resolve_user_label($uid, $user_cache),
                'orders'           => $orders_now,
                'orders_pct'       => $this->format_percent_html($orders_delta),
                'orders_pct_value' => $orders_delta,
                'co2'              => number_format($co2_now, 2, '.', ''),
                'co2_pct'          => $this->format_percent_html($co2_delta),
                'co2_pct_value'    => $co2_delta,
                'updated'          => $updated,
            ];
        }

        return $rows_out;
    }

    protected function resolve_user_label(int $user_id, array &$cache): string
    {
        if ($user_id === 0) {
            return __('Store Total', 'verdantcart-ai-reports');
        }

        if (!isset($cache[$user_id])) {
            $user = get_userdata($user_id);

            if ($user instanceof WP_User) {
                $label = trim((string) $user->display_name);

                if ($label === '') {
                    $label = trim((string) $user->user_email);
                }

                if ($label === '') {
                    $label = sprintf(
                        /* translators: %d: WordPress user ID. */
                        __('User #%d', 'verdantcart-ai-reports'),
                        $user_id
                    );
                }

                $cache[$user_id] = $label;
            } else {
                $cache[$user_id] = sprintf(
                    /* translators: %d: WordPress user ID. */
                    __('User #%d', 'verdantcart-ai-reports'),
                    $user_id
                );
            }
        }

        return $cache[$user_id];
    }

    protected function build_hotspots_block(string $view, string $date, bool $has_snapshot): array
    {
        $view = $this->normalize_view($view);
        $date = $this->safe_sanitize_period_for_view($view, $date);

        if (!$has_snapshot || $date === '') {
            return [];
        }

        if (
            !class_exists('VCARB_Product_Insights') ||
            !method_exists('VCARB_Product_Insights', 'get_hotspots')
        ) {
            return [];
        }

        /*
     * Product hotspots are read from the product hotspot snapshot table.
     * The period must match the selected dashboard period:
     *
     * month: 2026-05
     * week:  2026-W20
     * year:  2026
     *
     * If no rows exist for that exact period, the UI should show the empty
     * hotspot state instead of inventing data from another period.
     */
        $items = VCARB_Product_Insights::get_hotspots($date, 10);

        if (!is_array($items) || empty($items)) {
            return [];
        }

        return array_values($items);
    }

    protected function build_user_insights_block(array $metrics, bool $has_snapshot): array
    {
        if (!$has_snapshot || !class_exists('VCARB_Insights')) {
            return [];
        }

        $current  = $metrics['current'] ?? null;
        $previous = $metrics['previous'] ?? null;

        if (!is_object($current)) {
            return [];
        }

        $breakdown = [];

        if (
            !empty($metrics['hotspots']) &&
            is_array($metrics['hotspots'])
        ) {
            foreach ($metrics['hotspots'] as $item) {
                $name = (string) ($item['product_name'] ?? '');
                $co2  = (float) ($item['total_co2'] ?? 0.0);

                if ($name !== '' && $co2 > 0.0) {
                    $breakdown[$name] = $co2;
                }
            }
        }

        $items = VCARB_Insights::analyze($current, $previous, $breakdown);

        return is_array($items) ? $items : [];
    }

    protected function build_admin_insights_block(
        string $view,
        string $date,
        bool $has_snapshot,
        array $metrics,
        array $hotspots
    ): array {
        unset($view, $date);

        if (!$has_snapshot) {
            return [
                'positives'       => [],
                'warnings'        => [
                    [
                        'type'    => 'warning',
                        'title'   => __('No insights available yet', 'verdantcart-ai-reports'),
                        'message' => __('Insights will appear once a snapshot is available for this period.', 'verdantcart-ai-reports'),
                        'score'   => 5,
                        'actions' => [],
                    ],
                ],
                'risks'           => [],
                'recommendations' => [],
            ];
        }

        if (!class_exists('VCARB_Insights')) {
            return [];
        }

        $current = (object) [
            'orders'    => (int) ($metrics['orders'] ?? 0),
            'total_co2' => (float) ($metrics['total_co2'] ?? 0.0),
        ];

        $previous  = $metrics['previous'] ?? null;
        $breakdown = [];

        if (!empty($hotspots) && is_array($hotspots)) {
            foreach ($hotspots as $item) {
                $name = (string) ($item['product_name'] ?? '');
                $co2  = (float) ($item['total_co2'] ?? 0.0);

                if ($name !== '' && $co2 > 0) {
                    $breakdown[$name] = $co2;
                }
            }
        }

        $items = VCARB_Insights::analyze($current, $previous, $breakdown);

        return is_array($items) ? $items : [];
    }

    protected function extract_score_from_insights(array $insights, array $fallback_metrics): array
    {
        if (isset($insights['score']) && is_array($insights['score'])) {
            $score = $insights['score'];

            $value = null;

            if (isset($score['value']) && is_numeric($score['value'])) {
                $value = (int) round((float) $score['value']);
            } elseif (isset($score['score']) && is_numeric($score['score'])) {
                $value = (int) round((float) $score['score']);
            }

            if ($value !== null) {
                $value = max(0, min(100, $value));

                return [
                    'score'       => $value,
                    'value'       => $value,
                    'label'       => isset($score['label']) && (string) $score['label'] !== ''
                        ? (string) $score['label']
                        : $this->score_label_from_value($value),

                    /*
                 * Important:
                 * Do not trust a static insight summary here.
                 * The dashboard changes period by AJAX, so this summary must always
                 * be rebuilt from the selected snapshot metrics.
                 */
                    'summary'     => $this->score_summary_from_metrics($fallback_metrics),

                    'components'  => isset($score['components']) && is_array($score['components'])
                        ? $score['components']
                        : [],

                    'delta_text'  => $this->score_delta_text_from_metrics($fallback_metrics),
                    'delta_class' => $this->score_delta_class_from_metrics($fallback_metrics),
                ];
            }
        }

        return $this->build_score_block($fallback_metrics);
    }

    protected function score_label_from_value(int $score): string
    {
        if ($score >= 85) {
            return __('Excellent', 'verdantcart-ai-reports');
        }

        if ($score >= 70) {
            return __('Good', 'verdantcart-ai-reports');
        }

        if ($score >= 50) {
            return __('Fair', 'verdantcart-ai-reports');
        }

        return __('Needs work', 'verdantcart-ai-reports');
    }

    protected function score_delta_text_from_metrics(array $metrics): string
    {
        $co2_per_order = $metrics['co2_per_order'] ?? null;

        if ($co2_per_order !== null && is_numeric($co2_per_order)) {
            return sprintf(
                /* translators: %s: CO2 per order value in kg. */
                __('%s kg CO₂ / order', 'verdantcart-ai-reports'),
                number_format_i18n((float) $co2_per_order, 2)
            );
        }

        return __('Based on snapshot metrics', 'verdantcart-ai-reports');
    }

    protected function score_delta_class_from_metrics(array $metrics): string
    {
        return $this->score_metric_delta_class_from_metrics($metrics);
    }

    protected function score_summary_from_metrics(array $metrics): string
    {
        $delta = $metrics['delta'] ?? null;

        if ($delta === null || $delta === '' || !is_numeric($delta)) {
            return __('Your sustainability score is based on CO₂ per order, emissions trend, reporting history, and hotspot concentration.', 'verdantcart-ai-reports');
        }

        $delta = (float) $delta;

        if ($delta < -0.01) {
            return __('Your estimated emissions decreased compared with the previous snapshot period.', 'verdantcart-ai-reports');
        }

        if ($delta > 0.01) {
            return __('Your estimated emissions increased compared with the previous snapshot period.', 'verdantcart-ai-reports');
        }

        return __('Your estimated emissions stayed nearly unchanged compared with the previous snapshot period.', 'verdantcart-ai-reports');
    }

    protected function score_metric_delta_class_from_metrics(array $metrics): string
    {
        $delta = $metrics['delta'] ?? null;

        if ($delta === null || $delta === '' || !is_numeric($delta)) {
            return 'gc-neutral';
        }

        $delta = (float) $delta;

        if ($delta < -0.01) {
            return 'gc-carbon-good';
        }

        if ($delta > 0.01) {
            return 'gc-carbon-bad';
        }

        return 'gc-neutral';
    }

    protected function build_score_block(array $metrics): array
    {
        $total_co2   = (float) ($metrics['total_co2'] ?? 0.0);
        $orders      = (int) ($metrics['orders'] ?? 0);
        $previous    = $metrics['previous'] ?? null;
        $prev_co2    = is_object($previous) ? (float) ($previous->total_co2 ?? 0.0) : null;
        $prev_orders = is_object($previous) ? (int) ($previous->orders ?? 0) : null;

        if ($orders <= 0) {
            return [
                'score'       => null,
                'value'       => null,
                'label'       => __('—', 'verdantcart-ai-reports'),
                'summary'     => __('No orders are available for this selected snapshot period yet.', 'verdantcart-ai-reports'),
                'delta_text'  => __('No orders yet for this period.', 'verdantcart-ai-reports'),
                'delta_class' => 'gc-neutral',
            ];
        }

        $co2_per_order = $total_co2 / max(1, $orders);
        $score         = (int) round(max(0, min(100, 100 - ($co2_per_order * 40))));

        $delta_text  = '—';
        $delta_class = 'gc-neutral';

        if ($prev_co2 !== null && $prev_orders !== null && $prev_orders > 0) {
            $prev_cpo = $prev_co2 / max(1, $prev_orders);

            if ($prev_cpo > 0) {
                $change = (($co2_per_order - $prev_cpo) / abs($prev_cpo)) * 100.0;

                if ($change < -0.01) {
                    $delta_text = sprintf(
                        /* translators: %s: percentage change compared with previous period. */
                        __('↓ %s%% vs previous', 'verdantcart-ai-reports'),
                        number_format_i18n(abs($change), 0)
                    );
                    $delta_class = 'gc-up';
                } elseif ($change > 0.01) {
                    $delta_text = sprintf(
                        /* translators: %s: percentage change compared with previous period. */
                        __('↑ %s%% vs previous', 'verdantcart-ai-reports'),
                        number_format_i18n(abs($change), 0)
                    );
                    $delta_class = 'gc-down';
                } else {
                    $delta_text  = __('No change vs previous.', 'verdantcart-ai-reports');
                    $delta_class = 'gc-neutral';
                }
            }
        }

        return [
            'score'       => $score,
            'value'       => $score,
            'label'       => $this->score_label_from_value($score),
            'summary'     => $this->score_summary_from_metrics($metrics),
            'delta_text'  => $this->score_delta_text_from_metrics($metrics),
            'delta_class' => $this->score_delta_class_from_metrics($metrics),
        ];
    }

    protected function empty_dataset(string $scope, string $view, string $source, string $message): array
    {
        return [
            'scope'    => $scope,
            'user_id'  => 0,
            'view'     => $this->normalize_view($view),
            'date'     => '',
            'browser'  => [
                'selected' => '',
                'previous' => '',
                'next'     => '',
                'has_prev' => false,
                'has_next' => false,
            ],
            'snapshot' => [
                'has'         => false,
                'period'      => '',
                'source'      => $source,
                'updated'     => '',
                'updated_raw' => '',
                'message'     => $message,
            ],
            'metrics'  => [
                'current'       => null,
                'previous'      => null,
                'prev_date'     => '',
                'total_co2'     => 0,
                'orders'        => 0,
                'delta'         => null,
                'delta_html'    => '',
                'co2_per_order' => null,
                'notice'        => $message,
            ],
            'chart'    => [
                'labels'      => [],
                'co2'         => [],
                'orders'      => [],
                'periods'     => [],
                'snapshotSet' => [],
            ],
            'rows'     => [],
            'hotspots' => [],
            'insights' => [],
            'score'    => [
                'score'       => null,
                'delta_text'  => __('No data available.', 'verdantcart-ai-reports'),
                'delta_class' => 'gc-neutral',
            ],
            'notice'   => $message,
        ];
    }

    protected function build_browser_block(string $view, string $selected_date): array
    {
        $view          = $this->normalize_view($view);
        $selected_date = $this->safe_sanitize_period_for_view($view, $selected_date);

        if ($selected_date === '') {
            return [
                'selected' => '',
                'previous' => '',
                'next'     => '',
                'has_prev' => false,
                'has_next' => false,
            ];
        }

        if (method_exists($this, 'get_snapshot_browser_data')) {
            $browser = $this->get_snapshot_browser_data($view, $selected_date);

            return [
                'selected' => (string) ($browser['current'] ?? $selected_date),
                'previous' => (string) ($browser['previous'] ?? ''),
                'next'     => (string) ($browser['next'] ?? ''),
                'has_prev' => !empty($browser['has_prev']),
                'has_next' => !empty($browser['has_next']),
            ];
        }

        $previous = method_exists($this, 'get_previous_available_snapshot_period')
            ? $this->get_previous_available_snapshot_period($view, $selected_date)
            : '';

        $next = method_exists($this, 'get_next_available_snapshot_period')
            ? $this->get_next_available_snapshot_period($view, $selected_date)
            : '';

        return [
            'selected' => $selected_date,
            'previous' => $previous,
            'next'     => $next,
            'has_prev' => ($previous !== ''),
            'has_next' => ($next !== ''),
        ];
    }

    protected function chart_label_for_period(string $view, string $period): string
    {
        $view   = $this->normalize_view($view);
        $period = $this->safe_sanitize_period_for_view($view, $period);

        if ($period === '') {
            return '';
        }

        if ($view === 'month') {
            $timestamp = strtotime($period . '-01');

            return $timestamp ? date_i18n('M', $timestamp) : $period;
        }

        return $period;
    }

    protected function format_percent_html($value): string
    {
        if (
            class_exists('VCARB_Calculator') &&
            method_exists('VCARB_Calculator', 'format_percent')
        ) {
            return wp_kses_post(VCARB_Calculator::format_percent($value));
        }

        if ($value === null) {
            return '<span class="gc-neutral">' . esc_html__('New', 'verdantcart-ai-reports') . '</span>';
        }

        $value = (float) $value;

        if (abs($value) < 0.01) {
            return '<span class="gc-neutral">0%</span>';
        }

        $class = $value > 0 ? 'gc-up' : 'gc-down';

        return sprintf(
            '<span class="%1$s">%2$s%%</span>',
            esc_attr($class),
            esc_html(number_format_i18n(abs($value), 1))
        );
    }
}
