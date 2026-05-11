<?php
defined('ABSPATH') || exit;

class VCARB_Order_Tracker
{
    public const META_CO2_KG           = '_vcarb_order_co2_kg';
    public const META_COUNTED          = '_vcarb_order_co2_counted';
    public const META_HOTSPOTS_COUNTED = '_vcarb_order_hotspots_counted';

    private const META_LOCK = '_vcarb_order_co2_lock';

    /** @var self|null */
    private static $instance = null;

    /** @var bool */
    private $hooks_registered = false;

    public static function register(): void
    {
        self::instance(true);
    }

    public static function instance(bool $register_hooks = true): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        if ($register_hooks) {
            self::$instance->maybe_register_hooks();
        }

        return self::$instance;
    }

    public function __construct()
    {
        // Hooks are registered explicitly through register()/instance().
    }

    private function maybe_register_hooks(): void
    {
        if ($this->hooks_registered) {
            return;
        }

        add_action('woocommerce_order_status_changed', [$this, 'on_status_changed'], 20, 4);

        $this->hooks_registered = true;
    }

    public static function should_exclude_order_from_analytics($order): bool
    {
        if (!($order instanceof WC_Order)) {
            return true;
        }

        return !self::order_has_countable_items($order);
    }

    public function on_status_changed($order_id, $from, $to, $order): void
    {
        $order_id = absint($order_id);
        $from     = sanitize_key((string) $from);
        $to       = sanitize_key((string) $to);

        if ($order_id <= 0) {
            return;
        }

        if (!($order instanceof WC_Order)) {
            $order = wc_get_order($order_id);

            if (!($order instanceof WC_Order)) {
                return;
            }
        }

        $final_statuses = self::get_final_statuses($order);

        if (!in_array($to, $final_statuses, true)) {
            return;
        }

        if (in_array($from, $final_statuses, true)) {
            return;
        }

        if (self::should_exclude_order_from_analytics($order)) {
            return;
        }

        $this->on_order_finalized($order_id);
    }

    public function on_order_finalized($order_id): void
    {
        $order_id = absint($order_id);

        if ($order_id <= 0) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!($order instanceof WC_Order)) {
            return;
        }

        $lock_val = wp_generate_uuid4();

        if ((string) $order->get_meta(self::META_LOCK, true) !== '') {
            return;
        }

        $order->update_meta_data(self::META_LOCK, $lock_val);
        $order->save();

        try {
            if (self::should_exclude_order_from_analytics($order)) {
                return;
            }

            $co2_kg = $this->get_or_calculate_order_co2($order);

            if ($co2_kg <= 0.0) {
                $order->save();
                return;
            }

            $already_counted = ((string) $order->get_meta(self::META_COUNTED, true) === 'yes');
            $is_first_count  = false;

            if (!$already_counted) {
                $is_first_count = $this->acquire_flag($order, self::META_COUNTED);

                if ($is_first_count) {
                    $this->increment_order_period_rows($order, $co2_kg);
                }
            }

            if ($already_counted || $is_first_count) {
                $this->maybe_increment_hotspot_rows($order, $co2_kg);
            }

            $order->save();
            $this->schedule_period_aggregates($order);
        } finally {
            $current_lock = (string) $order->get_meta(self::META_LOCK, true);

            if ($current_lock === $lock_val) {
                $order->delete_meta_data(self::META_LOCK);
                $order->save();
            }
        }
    }

    private static function order_has_countable_items(WC_Order $order): bool
    {
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

            if ((float) $item->get_quantity() <= 0.0) {
                continue;
            }

            return true;
        }

        return false;
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
            $parent_id = absint($product->get_parent_id());

            if ($parent_id > 0) {
                $parent = wc_get_product($parent_id);

                if ($parent instanceof WC_Product && $check_product($parent)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function get_final_statuses(?WC_Order $order = null): array
    {
        $defaults = ['processing', 'completed'];

        $statuses = (array) apply_filters(
            'vcarb_final_statuses',
            $defaults,
            $order
        );

        /*
         * Legacy filter kept for existing custom snippets/extensions.
         */
        $statuses = (array) apply_filters(
            'amatorcarbon_final_statuses',
            $statuses,
            $order
        );

        $statuses = array_values(
            array_unique(
                array_filter(
                    array_map('sanitize_key', $statuses)
                )
            )
        );

        return !empty($statuses) ? $statuses : $defaults;
    }

    private function get_or_calculate_order_co2(WC_Order $order): float
    {
        $co2_kg = (float) $order->get_meta(self::META_CO2_KG, true);

        if ($co2_kg > 0.0) {
            return $co2_kg;
        }

        /*
         * Legacy fallback keeps old counted orders readable after the rename.
         */
        $legacy_co2_kg = (float) $order->get_meta('_amatorcarbon_order_co2_kg', true);

        if ($legacy_co2_kg > 0.0) {
            $order->update_meta_data(self::META_CO2_KG, $this->decimal_2($legacy_co2_kg));
            return $legacy_co2_kg;
        }

        $co2_kg = $this->calculate_order_co2_kg($order);

        if ($co2_kg > 0.0) {
            $order->update_meta_data(self::META_CO2_KG, $this->decimal_2($co2_kg));
        }

        return $co2_kg;
    }

    private function acquire_flag(WC_Order $order, string $meta_key): bool
    {
        $current = (string) $order->get_meta($meta_key, true);

        if ($current !== '') {
            return false;
        }

        $legacy_key = $this->legacy_meta_key_for($meta_key);

        if ($legacy_key !== '' && (string) $order->get_meta($legacy_key, true) !== '') {
            $order->update_meta_data($meta_key, 'yes');
            $order->save();

            return false;
        }

        $order->update_meta_data($meta_key, 'yes');
        $order->save();

        return true;
    }

    private function legacy_meta_key_for(string $meta_key): string
    {
        if ($meta_key === self::META_COUNTED) {
            return '_amatorcarbon_order_co2_counted';
        }

        if ($meta_key === self::META_HOTSPOTS_COUNTED) {
            return '_amatorcarbon_order_hotspots_counted';
        }

        if ($meta_key === self::META_CO2_KG) {
            return '_amatorcarbon_order_co2_kg';
        }

        if ($meta_key === self::META_LOCK) {
            return '_amatorcarbon_order_co2_lock';
        }

        return '';
    }

    private function maybe_increment_hotspot_rows(WC_Order $order, float $co2_kg): void
    {
        $legacy_hotspots_counted = (string) $order->get_meta('_amatorcarbon_order_hotspots_counted', true);

        if ((string) $order->get_meta(self::META_HOTSPOTS_COUNTED, true) !== '' || $legacy_hotspots_counted !== '') {
            if ($legacy_hotspots_counted !== '' && (string) $order->get_meta(self::META_HOTSPOTS_COUNTED, true) === '') {
                $order->update_meta_data(self::META_HOTSPOTS_COUNTED, 'yes');
                $order->save();
            }

            return;
        }

        $alloc = $this->allocate_co2_to_products($order, $co2_kg);

        if (empty($alloc)) {
            return;
        }

        if (!$this->acquire_flag($order, self::META_HOTSPOTS_COUNTED)) {
            return;
        }

        $hotspot_periods = [
            'week'  => $this->period_for_order('week', $order),
            'month' => $this->period_for_order('month', $order),
            'year'  => $this->period_for_order('year', $order),
        ];

        foreach ($hotspot_periods as $period) {
            if ($period === '') {
                continue;
            }

            foreach ($alloc as $product_id => $row) {
                $this->increment_product_log_row(
                    (int) $product_id,
                    $period,
                    (int) ($row['orders'] ?? 0),
                    (float) ($row['total_co2'] ?? 0.0)
                );
            }
        }
    }

    private function calculate_order_co2_kg(WC_Order $order): float
    {
        $total_weight_kg = $this->get_order_countable_weight_kg($order);

        if ($total_weight_kg <= 0.0) {
            return 0.0;
        }

        $factor = (float) apply_filters(
            'vcarb_emission_factor',
            0.5,
            $order
        );

        /*
         * Legacy filter kept for existing custom snippets/extensions.
         */
        $factor = (float) apply_filters(
            'amatorcarbon_emission_factor',
            $factor,
            $order
        );

        $co2 = $total_weight_kg * $factor;

        $co2 = (float) apply_filters(
            'vcarb_calculate_order_co2_kg',
            $co2,
            $order
        );

        /*
         * Legacy filter kept for existing custom snippets/extensions.
         */
        $co2 = (float) apply_filters(
            'amatorcarbon_calculate_order_co2_kg',
            $co2,
            $order
        );

        return max(0.0, $co2);
    }

    private function get_order_countable_weight_kg(WC_Order $order): float
    {
        $total_weight_kg = 0.0;

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

            $weight = $this->resolve_product_weight($product, $item, $order);

            if ($weight <= 0.0) {
                continue;
            }

            $total_weight_kg += ($this->to_kg($weight) * $qty);
        }

        return max(0.0, $total_weight_kg);
    }

    private function resolve_product_weight(WC_Product $product, WC_Order_Item_Product $item, WC_Order $order): float
    {
        $weight = (float) $product->get_weight();

        if ($weight > 0.0) {
            return $weight;
        }

        $skip_without_weight = (bool) apply_filters(
            'vcarb_skip_items_without_weight',
            true,
            $order
        );

        /*
         * Legacy filter kept for existing custom snippets/extensions.
         */
        $skip_without_weight = (bool) apply_filters(
            'amatorcarbon_skip_items_without_weight',
            $skip_without_weight,
            $order
        );

        if ($skip_without_weight) {
            return 0.0;
        }

        $fallback_weight = (float) apply_filters(
            'vcarb_default_product_weight',
            0,
            $product,
            $item,
            $order
        );

        /*
         * Legacy filter kept for existing custom snippets/extensions.
         */
        $fallback_weight = (float) apply_filters(
            'amatorcarbon_default_product_weight',
            $fallback_weight,
            $product,
            $item,
            $order
        );

        return max(0.0, $fallback_weight);
    }

    private function allocate_co2_to_products(WC_Order $order, float $order_co2_kg): array
    {
        if ($order_co2_kg <= 0.0) {
            return [];
        }

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

            $weight = $this->resolve_product_weight($product, $item, $order);

            if ($weight <= 0.0) {
                continue;
            }

            $line_weight = $this->to_kg($weight) * $qty;

            if ($line_weight <= 0.0) {
                continue;
            }

            $product_id = absint($product->get_id());

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

        if ($total_weight <= 0.0 || empty($lines)) {
            return [];
        }

        $alloc = [];

        foreach ($lines as $product_id => $line) {
            $share = ((float) $line['weight']) / $total_weight;

            $alloc[$product_id] = [
                'orders'    => (int) $line['orders'],
                'total_co2' => $order_co2_kg * $share,
            ];
        }

        return $alloc;
    }

    private function to_kg(float $weight): float
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

    private function period_for_order(string $view, WC_Order $order): string
    {
        $view = in_array($view, ['month', 'week', 'year'], true) ? $view : 'month';

        $dt = $this->get_order_datetime($order);

        if (!$dt) {
            return '';
        }

        if ($view === 'month') {
            return $dt->format('Y-m');
        }

        if ($view === 'year') {
            return $dt->format('Y');
        }

        return sprintf('%04d-W%02d', (int) $dt->format('o'), (int) $dt->format('W'));
    }

    private function get_order_datetime(WC_Order $order): ?DateTimeImmutable
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

            return (new DateTimeImmutable('@' . $date_obj->getTimestamp()))->setTimezone($tz);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function increment_product_log_row(int $product_id, string $period, int $orders_delta, float $co2_delta): void
    {
        global $wpdb;

        $product_id   = absint($product_id);
        $period       = sanitize_text_field($period);
        $orders_delta = max(0, $orders_delta);
        $co2_delta    = max(0.0, $co2_delta);

        if ($product_id <= 0 || $period === '' || $orders_delta <= 0 || $co2_delta <= 0.0) {
            return;
        }

        $table   = esc_sql($wpdb->prefix . 'vcarb_product_logs');
        $now     = current_time('mysql');
        $co2_str = $this->decimal_2($co2_delta);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Upserting plugin-owned analytics table; table name is derived from $wpdb->prefix and escaped.
        $wpdb->query(
            $wpdb->prepare(
                "
                INSERT INTO `{$table}` (product_id, period, orders, total_co2, created_at)
                VALUES (%d, %s, %d, %s, %s)
                ON DUPLICATE KEY UPDATE
                    orders = orders + VALUES(orders),
                    total_co2 = total_co2 + VALUES(total_co2),
                    created_at = VALUES(created_at)
                ",
                $product_id,
                $period,
                $orders_delta,
                $co2_str,
                $now
            )
        );
        // phpcs:enable
    }

    private function increment_log_row(int $user_id, string $view, string $period, int $orders_delta, float $co2_delta): void
    {
        global $wpdb;

        $user_id      = max(0, $user_id);
        $view         = in_array($view, ['month', 'week', 'year'], true) ? $view : 'month';
        $period       = sanitize_text_field($period);
        $orders_delta = max(0, $orders_delta);
        $co2_delta    = max(0.0, $co2_delta);

        if ($period === '' || $orders_delta <= 0 || $co2_delta <= 0.0) {
            return;
        }

        $table   = esc_sql($wpdb->prefix . 'vcarb_logs');
        $now     = current_time('mysql');
        $co2_str = $this->decimal_2($co2_delta);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Upserting plugin-owned analytics table; table name is derived from $wpdb->prefix and escaped.
        $wpdb->query(
            $wpdb->prepare(
                "
                INSERT INTO `{$table}`
                    (user_id, view_type, period, orders, total_co2, created_at)
                VALUES
                    (%d, %s, %s, %d, %s, %s)
                ON DUPLICATE KEY UPDATE
                    orders = orders + VALUES(orders),
                    total_co2 = total_co2 + VALUES(total_co2),
                    created_at = VALUES(created_at)
                ",
                $user_id,
                $view,
                $period,
                $orders_delta,
                $co2_str,
                $now
            )
        );
        // phpcs:enable
    }

    private function increment_order_period_rows(WC_Order $order, float $co2_kg): void
    {
        if ($co2_kg <= 0.0) {
            return;
        }

        $customer_id = absint($order->get_user_id());

        $periods = [
            'week'  => $this->period_for_order('week', $order),
            'month' => $this->period_for_order('month', $order),
            'year'  => $this->period_for_order('year', $order),
        ];

        foreach ($periods as $view => $period) {
            if ($period === '') {
                continue;
            }

            $this->increment_log_row(0, $view, $period, 1, $co2_kg);

            if ($customer_id > 0) {
                $this->increment_log_row($customer_id, $view, $period, 1, $co2_kg);
            }
        }
    }

    private function schedule_period_aggregates(WC_Order $order): void
    {
        if (!class_exists('VCARB_Scheduler')) {
            return;
        }

        $periods = [
            'week'  => $this->period_for_order('week', $order),
            'month' => $this->period_for_order('month', $order),
            'year'  => $this->period_for_order('year', $order),
        ];

        foreach ($periods as $view => $period) {
            if ($period !== '') {
                VCARB_Scheduler::schedule_aggregate_debounced($view, $period);
            }
        }
    }

    private function decimal_2(float $number): string
    {
        return number_format(max(0.0, $number), 2, '.', '');
    }
}
