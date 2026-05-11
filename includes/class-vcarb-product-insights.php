<?php
defined('ABSPATH') || exit;

class VCARB_Product_Insights
{
    private const MAX_HOTSPOTS_LIMIT = 50;
    private const REBUILD_BATCH_SIZE = 200;

    public static function get_hotspots(string $period, int $limit = 10): array
    {
        global $wpdb;

        $period = trim($period);
        $view   = self::infer_view_from_period($period);

        if ($view === '' || !self::is_valid_period($period) || !self::table_exists()) {
            return [];
        }

        $limit  = max(1, min(self::MAX_HOTSPOTS_LIMIT, (int) $limit));
        $tables = self::readable_product_tables();

        if (empty($tables)) {
            return [];
        }

        $combined = [];

        foreach ($tables as $raw_table) {
            $table = esc_sql($raw_table);

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned analytics tables; table names are plugin-controlled.
            $table_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "
                    SELECT product_id, total_co2, orders
                    FROM `{$table}`
                    WHERE period = %s
                    ORDER BY total_co2 DESC, orders DESC, product_id ASC
                    LIMIT %d
                    ",
                    $period,
                    $limit
                )
            );
            // phpcs:enable

            $table_rows = is_array($table_rows) ? $table_rows : [];

            foreach ($table_rows as $row) {
                $product_id = (int) ($row->product_id ?? 0);

                if ($product_id <= 0) {
                    continue;
                }

                if (!isset($combined[$product_id])) {
                    $combined[$product_id] = (object) [
                        'product_id' => $product_id,
                        'total_co2'  => 0.0,
                        'orders'     => 0,
                    ];
                }

                $combined[$product_id]->total_co2 += (float) ($row->total_co2 ?? 0.0);
                $combined[$product_id]->orders    += (int) ($row->orders ?? 0);
            }
        }

        $rows = array_values($combined);

        usort(
            $rows,
            static function ($a, $b): int {
                $co2_compare = ((float) $b->total_co2) <=> ((float) $a->total_co2);

                if ($co2_compare !== 0) {
                    return $co2_compare;
                }

                $orders_compare = ((int) $b->orders) <=> ((int) $a->orders);

                if ($orders_compare !== 0) {
                    return $orders_compare;
                }

                return ((int) $a->product_id) <=> ((int) $b->product_id);
            }
        );

        $rows = array_slice($rows, 0, $limit);

        if (empty($rows)) {
            return [];
        }

        $snapshot = self::get_store_snapshot_row($view, $period);
        $total    = (float) ($snapshot['total_co2'] ?? 0.0);

        if ($total <= 0.0) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $product_id = (int) ($row->product_id ?? 0);

            if ($product_id <= 0) {
                continue;
            }

            $product = wc_get_product($product_id);

            if (!($product instanceof WC_Product) || self::is_plan_product($product)) {
                continue;
            }

            $co2    = (float) ($row->total_co2 ?? 0.0);
            $orders = (int) ($row->orders ?? 0);

            if ($co2 <= 0.0) {
                continue;
            }

            $out[] = [
                'product_id'   => $product_id,
                'product_name' => self::get_product_title_from_product($product, $product_id),
                'orders'       => max(0, $orders),
                'total_co2'    => $co2,
                'percent'      => ($co2 / $total) * 100.0,
            ];
        }

        return $out;
    }

    public static function rebuild_month(string $period): void
    {
        global $wpdb;

        if (!self::is_valid_month_period($period)) {
            return;
        }

        if (!function_exists('wc_get_orders') || !function_exists('wc_get_order')) {
            return;
        }

        if (!self::table_exists()) {
            return;
        }

        $table = self::writable_table_name();

        try {
            $tz       = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $start_dt = new DateTimeImmutable($period . '-01 00:00:00', $tz);
            $end_dt   = $start_dt->modify('+1 month');

            $start = $start_dt->format('Y-m-d H:i:s');
            $end   = $end_dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting plugin-owned monthly hotspot snapshot rows.
        $wpdb->delete(
            $table,
            ['period' => $period],
            ['%s']
        );

        $co2_meta_keys = [];

        if (class_exists('VCARB_Order_Tracker')) {
            $co2_meta_keys[] = VCARB_Order_Tracker::META_CO2_KG;
        }

        if (class_exists('AmatorCarbon_Order_Tracker')) {
            $co2_meta_keys[] = AmatorCarbon_Order_Tracker::META_CO2_KG;
        }

        $co2_meta_keys[] = '_vcarb_order_co2_kg';
        $co2_meta_keys[] = '_amatorcarbon_order_co2_kg';
        $co2_meta_keys[] = '_gc_order_co2_kg';

        $co2_meta_keys = array_values(array_unique(array_filter($co2_meta_keys)));

        $page      = 1;
        $max_pages = 1;

        do {
            $query = wc_get_orders([
                'status'       => self::get_final_statuses(),
                'limit'        => self::REBUILD_BATCH_SIZE,
                'paged'        => $page,
                'return'       => 'ids',
                'paginate'     => true,
                'orderby'      => 'date',
                'order'        => 'ASC',
                'date_created' => $start . '...' . $end,
            ]);

            $order_ids = (is_object($query) && isset($query->orders) && is_array($query->orders))
                ? $query->orders
                : [];

            $max_pages = (is_object($query) && isset($query->max_num_pages))
                ? (int) $query->max_num_pages
                : 1;

            if (empty($order_ids)) {
                break;
            }

            foreach ($order_ids as $order_id) {
                $order = wc_get_order((int) $order_id);

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

                $order_co2 = 0.0;

                foreach ($co2_meta_keys as $meta_key) {
                    $candidate = (float) $order->get_meta($meta_key, true);

                    if ($candidate > 0.0) {
                        $order_co2 = $candidate;
                        break;
                    }
                }

                if ($order_co2 <= 0.0) {
                    continue;
                }

                $dt = self::get_order_period_date($order);

                if (!$dt || $dt->format('Y-m') !== $period) {
                    continue;
                }

                $alloc = self::allocate_order_co2_by_weight($order, $order_co2);

                if (empty($alloc)) {
                    continue;
                }

                foreach ($alloc as $product_id => $row) {
                    $product_id = (int) $product_id;
                    $orders     = (int) ($row['orders'] ?? 0);
                    $co2        = (float) ($row['total_co2'] ?? 0.0);

                    if ($product_id <= 0 || $orders <= 0 || $co2 <= 0.0) {
                        continue;
                    }

                    $safe_table = esc_sql($table);

                    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Upserting plugin-owned analytics table; interpolated table name is plugin-controlled.
                    $wpdb->query(
                        $wpdb->prepare(
                            "
                            INSERT INTO `{$safe_table}` (product_id, period, orders, total_co2, created_at)
                            VALUES (%d, %s, %d, %s, %s)
                            ON DUPLICATE KEY UPDATE
                                orders = orders + VALUES(orders),
                                total_co2 = total_co2 + VALUES(total_co2),
                                created_at = VALUES(created_at)
                            ",
                            $product_id,
                            $period,
                            $orders,
                            number_format($co2, 2, '.', ''),
                            current_time('mysql', true)
                        )
                    );
                    // phpcs:enable
                }
            }

            $page++;
        } while ($page <= $max_pages);
    }

    public static function get_hotspot_analyzer(string $period, int $limit = 10): array
    {
        global $wpdb;

        if (!self::is_valid_month_period($period) || !self::table_exists()) {
            return self::empty_analyzer($period);
        }

        $limit       = max(1, min(self::MAX_HOTSPOTS_LIMIT, (int) $limit));
        $prev_period = self::prev_month($period);
        $tables      = self::readable_product_tables();

        $combined = [];

        foreach ($tables as $raw_table) {
            $table = esc_sql($raw_table);

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned analytics tables; table names are plugin-controlled.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "
                    SELECT product_id, total_co2, orders
                    FROM `{$table}`
                    WHERE period = %s
                    ORDER BY total_co2 DESC, orders DESC, product_id ASC
                    LIMIT %d
                    ",
                    $period,
                    $limit
                )
            );
            // phpcs:enable

            $rows = is_array($rows) ? $rows : [];

            foreach ($rows as $row) {
                $product_id = (int) ($row->product_id ?? 0);

                if ($product_id <= 0) {
                    continue;
                }

                if (!isset($combined[$product_id])) {
                    $combined[$product_id] = (object) [
                        'product_id' => $product_id,
                        'total_co2'  => 0.0,
                        'orders'     => 0,
                    ];
                }

                $combined[$product_id]->total_co2 += (float) ($row->total_co2 ?? 0.0);
                $combined[$product_id]->orders    += (int) ($row->orders ?? 0);
            }
        }

        $cur_rows = array_values($combined);

        usort(
            $cur_rows,
            static function ($a, $b): int {
                $co2_compare = ((float) $b->total_co2) <=> ((float) $a->total_co2);

                if ($co2_compare !== 0) {
                    return $co2_compare;
                }

                $orders_compare = ((int) $b->orders) <=> ((int) $a->orders);

                if ($orders_compare !== 0) {
                    return $orders_compare;
                }

                return ((int) $a->product_id) <=> ((int) $b->product_id);
            }
        );

        $cur_rows = array_slice($cur_rows, 0, $limit);

        $snapshot = self::get_store_snapshot_row('month', $period);

        $store_total_co2    = (float) ($snapshot['total_co2'] ?? 0.0);
        $store_total_orders = (int) ($snapshot['orders'] ?? 0);
        $store_avg          = ($store_total_orders > 0)
            ? ($store_total_co2 / $store_total_orders)
            : 0.0;

        if (empty($cur_rows)) {
            return [
                'period'                  => $period,
                'previous_period'         => $prev_period,
                'store_total_co2'         => $store_total_co2,
                'store_total_orders'      => $store_total_orders,
                'store_avg_co2_per_order' => $store_avg,
                'items'                   => [],
            ];
        }

        $product_ids = [];

        foreach ($cur_rows as $row) {
            $product_id = (int) ($row->product_id ?? 0);

            if ($product_id > 0) {
                $product_ids[] = $product_id;
            }
        }

        $product_ids = array_values(array_unique($product_ids));
        $prev_map    = [];

        if ($prev_period !== '' && !empty($product_ids)) {
            foreach ($tables as $raw_table) {
                $table        = esc_sql($raw_table);
                $placeholders = implode(', ', array_fill(0, count($product_ids), '%d'));
                $prepare_args = array_merge([$prev_period], $product_ids);

                // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned analytics tables; table names and placeholders are controlled.
                $prev_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "
                        SELECT product_id, total_co2, orders
                        FROM `{$table}`
                        WHERE period = %s
                          AND product_id IN ({$placeholders})
                        ",
                        ...$prepare_args
                    )
                );
                // phpcs:enable

                $prev_rows = is_array($prev_rows) ? $prev_rows : [];

                foreach ($prev_rows as $row) {
                    $product_id = (int) ($row->product_id ?? 0);

                    if ($product_id <= 0) {
                        continue;
                    }

                    if (!isset($prev_map[$product_id])) {
                        $prev_map[$product_id] = (object) [
                            'total_co2' => 0.0,
                            'orders'    => 0,
                        ];
                    }

                    $prev_map[$product_id]->total_co2 += (float) ($row->total_co2 ?? 0.0);
                    $prev_map[$product_id]->orders    += (int) ($row->orders ?? 0);
                }
            }
        }

        $items = [];

        foreach ($cur_rows as $row) {
            $product_id = (int) ($row->product_id ?? 0);

            if ($product_id <= 0) {
                continue;
            }

            $product = wc_get_product($product_id);

            if (!($product instanceof WC_Product) || self::is_plan_product($product)) {
                continue;
            }

            $co2_now          = (float) ($row->total_co2 ?? 0.0);
            $orders_now       = (int) ($row->orders ?? 0);
            $percent          = ($store_total_co2 > 0.0) ? (($co2_now / $store_total_co2) * 100.0) : 0.0;
            $co2_per_order    = ($orders_now > 0) ? ($co2_now / $orders_now) : 0.0;
            $prev_row         = $prev_map[$product_id] ?? null;
            $co2_prev         = $prev_row ? (float) ($prev_row->total_co2 ?? 0.0) : null;
            $orders_prev      = $prev_row ? (int) ($prev_row->orders ?? 0) : null;
            $delta_co2_pct    = self::percent_change($co2_now, $co2_prev);
            $delta_orders_pct = self::percent_change(
                (float) $orders_now,
                $orders_prev === null ? null : (float) $orders_prev
            );

            $suggestions = [];

            if ($percent >= 25.0) {
                $suggestions[] = __('High impact product (large share of emissions). Review packaging and shipping settings.', 'verdantcart-ai-reports');
            } elseif ($percent >= 15.0) {
                $suggestions[] = __('Meaningful emissions contributor. Consider optimizing shipping methods or packaging.', 'verdantcart-ai-reports');
            }

            if ($store_avg > 0.0 && $co2_per_order >= ($store_avg * 1.5)) {
                $suggestions[] = __('High CO₂ per order vs store average. Check weight/dimensions, shipping zones, or fulfillment methods.', 'verdantcart-ai-reports');
            }

            if ($delta_co2_pct !== null && $delta_co2_pct >= 30.0) {
                $suggestions[] = __('CO₂ increased sharply vs previous month. Check promotions, pricing changes, or shipping method changes.', 'verdantcart-ai-reports');
            }

            if ($delta_orders_pct !== null && $delta_orders_pct >= 30.0) {
                $suggestions[] = __('Orders increased sharply vs previous month. Ensure the product setup and shipping settings are optimized.', 'verdantcart-ai-reports');
            }

            if (empty($suggestions)) {
                $suggestions[] = __('No major anomalies detected. Monitor over time as more snapshot periods accumulate.', 'verdantcart-ai-reports');
            }

            $items[] = [
                'product_id'       => $product_id,
                'product_name'     => self::get_product_title_from_product($product, $product_id),
                'orders'           => $orders_now,
                'total_co2'        => $co2_now,
                'percent'          => $percent,
                'co2_per_order'    => $co2_per_order,
                'delta_co2_pct'    => $delta_co2_pct,
                'delta_orders_pct' => $delta_orders_pct,
                'suggestions'      => $suggestions,
            ];
        }

        return [
            'period'                  => $period,
            'previous_period'         => $prev_period,
            'store_total_co2'         => $store_total_co2,
            'store_total_orders'      => $store_total_orders,
            'store_avg_co2_per_order' => $store_avg,
            'items'                   => $items,
        ];
    }

    private static function allocate_order_co2_by_weight(WC_Order $order, float $order_co2): array
    {
        $skip_without_weight = (bool) self::apply_plugin_filter(
            'skip_items_without_weight',
            true,
            $order
        );

        $lines        = [];
        $total_weight = 0.0;

        foreach ($order->get_items('line_item') as $item) {
            if (!($item instanceof WC_Order_Item_Product)) {
                continue;
            }

            $product = $item->get_product();

            if (!($product instanceof WC_Product)) {
                continue;
            }

            if (self::is_plan_product($product)) {
                continue;
            }

            $qty = (float) $item->get_quantity();

            if ($qty <= 0.0) {
                continue;
            }

            $weight = (float) $product->get_weight();

            if ($weight <= 0.0) {
                if ($skip_without_weight) {
                    continue;
                }

                $weight = (float) self::apply_plugin_filter(
                    'default_product_weight',
                    0,
                    $product,
                    $item,
                    $order
                );

                if ($weight <= 0.0) {
                    continue;
                }
            }

            $kg         = self::to_kg($weight);
            $line_weight = $kg * $qty;

            if ($line_weight <= 0.0) {
                continue;
            }

            $product_id = (int) $product->get_id();

            if ($product_id <= 0) {
                continue;
            }

            $total_weight += $line_weight;

            if (!isset($lines[$product_id])) {
                $lines[$product_id] = [
                    'weight' => 0.0,
                    'orders' => 0,
                ];
            }

            $lines[$product_id]['weight'] += $line_weight;
            $lines[$product_id]['orders'] += (int) $qty;
        }

        if ($total_weight <= 0.0 || empty($lines) || $order_co2 <= 0.0) {
            return [];
        }

        $alloc = [];

        foreach ($lines as $product_id => $line) {
            $share = ((float) $line['weight']) / $total_weight;

            $alloc[$product_id] = [
                'orders'    => (int) $line['orders'],
                'total_co2' => $order_co2 * $share,
            ];
        }

        return $alloc;
    }

    private static function get_order_period_date(WC_Order $order): ?DateTimeImmutable
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
            $tz = function_exists('wp_timezone')
                ? wp_timezone()
                : new DateTimeZone(wp_timezone_string() ?: 'UTC');

            return (new DateTimeImmutable('@' . $date_obj->getTimestamp()))
                ->setTimezone($tz);
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function get_store_snapshot_row(string $view, string $period): array
    {
        global $wpdb;

        $view   = sanitize_key($view);
        $period = trim($period);

        if (!in_array($view, ['week', 'month', 'year'], true) || !self::is_valid_period($period)) {
            return [
                'orders'    => 0,
                'total_co2' => 0.0,
            ];
        }

        $tables = [];

        if (self::db_table_exists(self::logs_table_name())) {
            $tables[] = self::logs_table_name();
        }

        if (self::db_table_exists(self::legacy_logs_table_name())) {
            $tables[] = self::legacy_logs_table_name();
        }

        foreach ($tables as $raw_table) {
            $table = esc_sql($raw_table);

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading plugin-owned snapshot tables; table names are plugin-controlled.
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "
                    SELECT orders, total_co2
                    FROM `{$table}`
                    WHERE user_id = 0
                      AND view_type = %s
                      AND period = %s
                    LIMIT 1
                    ",
                    $view,
                    $period
                ),
                ARRAY_A
            );
            // phpcs:enable

            if (is_array($row) && !empty($row)) {
                return [
                    'orders'    => isset($row['orders']) ? (int) $row['orders'] : 0,
                    'total_co2' => isset($row['total_co2']) ? (float) $row['total_co2'] : 0.0,
                ];
            }
        }

        return [
            'orders'    => 0,
            'total_co2' => 0.0,
        ];
    }

    private static function is_plan_product($product): bool
    {
        if (!($product instanceof WC_Product)) {
            return false;
        }

        $check_product = static function (WC_Product $candidate): bool {
            $plan_flag = (string) $candidate->get_meta('_vcarb_plan', true);

            if ($plan_flag === 'pro') {
                return true;
            }

            $legacy_plan_flag = (string) $candidate->get_meta('_amatorcarbon_plan', true);

            if ($legacy_plan_flag === 'pro') {
                return true;
            }

            $managed_flag = (string) $candidate->get_meta('_vcarb_managed_product', true);

            if ($managed_flag === '1') {
                return true;
            }

            $legacy_managed_flag = (string) $candidate->get_meta('_amatorcarbon_managed_product', true);

            if ($legacy_managed_flag === '1') {
                return true;
            }

            $sku = method_exists($candidate, 'get_sku')
                ? (string) $candidate->get_sku()
                : '';

            return in_array(
                $sku,
                [
                    'vcarb-pro-monthly',
                    'vcarb-pro-yearly',
                    'verdantcart-pro-monthly',
                    'verdantcart-pro-yearly',
                    'amatorcarbon-pro-monthly',
                    'amatorcarbon-pro-yearly',
                ],
                true
            );
        };

        if ($check_product($product)) {
            return true;
        }

        if ($product instanceof WC_Product_Variation) {
            $parent_id = (int) $product->get_parent_id();

            if ($parent_id > 0) {
                $parent = wc_get_product($parent_id);

                if ($parent instanceof WC_Product && $check_product($parent)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function get_product_title_from_product(WC_Product $product, int $product_id): string
    {
        $name = trim((string) $product->get_name());

        if ($name !== '') {
            return $name;
        }

        return self::get_product_title($product_id);
    }

    private static function to_kg(float $weight): float
    {
        $unit = get_option('woocommerce_weight_unit', 'kg');

        switch ($unit) {
            case 'g':
                return $weight / 1000.0;

            case 'lbs':
                return $weight * 0.45359237;

            case 'oz':
                return $weight * 0.028349523125;

            case 'kg':
            default:
                return $weight;
        }
    }

    private static function prev_month(string $period): string
    {
        if (!self::is_valid_month_period($period)) {
            return '';
        }

        try {
            $tz = function_exists('wp_timezone')
                ? wp_timezone()
                : new DateTimeZone('UTC');

            $dt = new DateTimeImmutable($period . '-01 00:00:00', $tz);

            return $dt->modify('-1 month')->format('Y-m');
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function percent_change(float $now, $prev): ?float
    {
        if ($prev === null) {
            return null;
        }

        $prev = (float) $prev;

        if ($prev == 0.0) {
            return ($now == 0.0) ? 0.0 : null;
        }

        return (($now - $prev) / abs($prev)) * 100.0;
    }

    private static function empty_analyzer(string $period): array
    {
        return [
            'period'                  => $period,
            'previous_period'         => '',
            'store_total_co2'         => 0.0,
            'store_total_orders'      => 0,
            'store_avg_co2_per_order' => 0.0,
            'items'                   => [],
        ];
    }

    private static function get_product_title(int $product_id): string
    {
        if ($product_id <= 0) {
            return '#0';
        }

        $title = get_the_title($product_id);

        if (is_string($title) && $title !== '') {
            return $title;
        }

        return '#' . $product_id;
    }

    private static function is_valid_month_period(string $period): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $period, $matches)) {
            return false;
        }

        $year  = (int) $matches[1];
        $month = (int) $matches[2];

        return $year >= 1970 && $year <= 2100 && $month >= 1 && $month <= 12;
    }

    private static function table_exists(): bool
    {
        return self::db_table_exists(self::table_name()) || self::db_table_exists(self::legacy_table_name());
    }

    private static function db_table_exists(string $table): bool
    {
        global $wpdb;

        $table = trim($table);

        if ($table === '') {
            return false;
        }

        $cache_key = 'vcarb_table_exists_' . md5($table);
        $cached    = wp_cache_get($cache_key, 'vcarb');

        if ($cached !== false) {
            return (bool) $cached;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking plugin-owned table existence.
        $exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        $result = !empty($exists);

        wp_cache_set($cache_key, $result, 'vcarb', MINUTE_IN_SECONDS);

        return $result;
    }

    private static function readable_product_tables(): array
    {
        $tables = [];

        if (self::db_table_exists(self::table_name())) {
            $tables[] = self::table_name();
        }

        if (self::db_table_exists(self::legacy_table_name())) {
            $tables[] = self::legacy_table_name();
        }

        return array_values(array_unique($tables));
    }

    private static function writable_table_name(): string
    {
        if (self::db_table_exists(self::table_name())) {
            return self::table_name();
        }

        return self::legacy_table_name();
    }

    private static function infer_view_from_period(string $period): string
    {
        $period = trim($period);

        if (preg_match('/^\d{4}-W\d{2}$/', $period)) {
            return 'week';
        }

        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            return 'month';
        }

        if (preg_match('/^\d{4}$/', $period)) {
            return 'year';
        }

        return '';
    }

    private static function is_valid_period(string $period): bool
    {
        $view = self::infer_view_from_period($period);

        if ($view === 'month') {
            return self::is_valid_month_period($period);
        }

        if ($view === 'year') {
            if (!preg_match('/^\d{4}$/', $period)) {
                return false;
            }

            $year = (int) $period;

            return $year >= 1970 && $year <= 2100;
        }

        if ($view === 'week' && preg_match('/^(\d{4})-W(\d{2})$/', $period, $matches)) {
            $year = (int) $matches[1];
            $week = (int) $matches[2];

            if ($year < 1970 || $year > 2100 || $week < 1 || $week > 53) {
                return false;
            }

            try {
                $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');

                $dt = (new DateTimeImmutable('now', $tz))
                    ->setISODate($year, $week, 1)
                    ->setTime(0, 0, 0);

                return sprintf(
                    '%04d-W%02d',
                    (int) $dt->format('o'),
                    (int) $dt->format('W')
                ) === sprintf('%04d-W%02d', $year, $week);
            } catch (Throwable $e) {
                return false;
            }
        }

        return false;
    }

    private static function table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'vcarb_product_logs';
    }

    private static function legacy_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'amatorcarbon_product_logs';
    }

    private static function logs_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'vcarb_logs';
    }

    private static function legacy_logs_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'amatorcarbon_logs';
    }

    private static function get_final_statuses(): array
    {
        $statuses = (array) self::apply_plugin_filter(
            'final_statuses',
            ['processing', 'completed'],
            null
        );

        $statuses = array_values(
            array_unique(
                array_filter(
                    array_map('sanitize_key', $statuses)
                )
            )
        );

        return !empty($statuses) ? $statuses : ['processing', 'completed'];
    }

    /**
     * Applies the new vcarb_* filter first, while still allowing legacy
     * amatorcarbon_* filters to affect older customizations during migration.
     *
     * @param mixed $value Default/current value.
     * @param mixed ...$args Extra filter args.
     *
     * @return mixed
     */
    private static function apply_plugin_filter(string $suffix, $value, ...$args)
    {
        $suffix = sanitize_key($suffix);

        if ($suffix === '') {
            return $value;
        }

        $value = apply_filters('vcarb_' . $suffix, $value, ...$args);

        return apply_filters('amatorcarbon_' . $suffix, $value, ...$args);
    }
}
