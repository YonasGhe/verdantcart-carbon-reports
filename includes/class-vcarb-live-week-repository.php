<?php
defined('ABSPATH') || exit;

/**
 * Backward-compatible live weekly repository.
 *
 * Current dashboard/report flow is snapshot-only, so this class should not be
 * used during normal page rendering. It is kept to avoid fatal errors from
 * older construction call sites or future internal tools.
 */
class VCARB_Live_Week_Repository
{
    use VCARB_Period_Trait;

    private const BATCH_SIZE = 200;

    /**
     * Per-request cache.
     *
     * @var array<string,array{
     *   rows: array<int,object>,
     *   updated: string,
     *   user_totals: array<int,array{orders:int,co2:float}>,
     *   user_updated: array<int,string>
     * }>
     */
    private static array $week_cache = [];

    public function get_user_totals(int $user_id, string $week_period): array
    {
        $user_id = absint($user_id);

        if ($user_id <= 0) {
            return $this->empty_totals();
        }

        $cache = $this->get_week_cache($week_period);
        $item  = $cache['user_totals'][$user_id] ?? null;

        if (!is_array($item)) {
            return $this->empty_totals();
        }

        return [
            'orders' => (int) ($item['orders'] ?? 0),
            'co2'    => (float) ($item['co2'] ?? 0.0),
        ];
    }

    public function get_user_updated(int $user_id, string $week_period): string
    {
        $user_id = absint($user_id);

        if ($user_id <= 0) {
            return '';
        }

        $cache = $this->get_week_cache($week_period);

        return isset($cache['user_updated'][$user_id])
            ? (string) $cache['user_updated'][$user_id]
            : '';
    }

    public function get_store_rows(string $week_period): array
    {
        $cache = $this->get_week_cache($week_period);

        return is_array($cache['rows'] ?? null)
            ? $cache['rows']
            : [];
    }

    public function get_store_updated(string $week_period): string
    {
        $cache = $this->get_week_cache($week_period);

        return isset($cache['updated'])
            ? (string) $cache['updated']
            : '';
    }

    /**
     * Build and cache all live week aggregates once per request.
     *
     * @return array{
     *   rows: array<int,object>,
     *   updated: string,
     *   user_totals: array<int,array{orders:int,co2:float}>,
     *   user_updated: array<int,string>
     * }
     */
    private function get_week_cache(string $week_period): array
    {
        $week_period = $this->sanitize_period_for_view('week', $week_period);

        if ($week_period === '') {
            return $this->empty_cache();
        }

        if (isset(self::$week_cache[$week_period])) {
            return self::$week_cache[$week_period];
        }

        if (!function_exists('wc_get_orders') || !function_exists('wc_get_order')) {
            self::$week_cache[$week_period] = $this->empty_cache();
            return self::$week_cache[$week_period];
        }

        $bounds = $this->get_week_bounds($week_period);

        if (!$bounds['start'] instanceof DateTimeImmutable || !$bounds['end'] instanceof DateTimeImmutable) {
            self::$week_cache[$week_period] = $this->empty_cache();
            return self::$week_cache[$week_period];
        }

        $co2_meta_keys  = $this->get_co2_meta_keys();
        $statuses       = $this->get_final_statuses();
        $start_range    = $bounds['start']->format('Y-m-d H:i:s');
        $end_range      = $bounds['end']->format('Y-m-d H:i:s');
        $timezone       = $this->vcarb_wp_timezone();
        $rows           = [];
        $user_totals    = [];
        $user_updated   = [];
        $store_updated  = '';
        $page           = 1;
        $max_pages      = 1;

        do {
            $result = wc_get_orders([
                'status'       => $statuses,
                'limit'        => self::BATCH_SIZE,
                'paged'        => $page,
                'paginate'     => true,
                'return'       => 'ids',
                'orderby'      => 'date',
                'order'        => 'ASC',
                'date_created' => $start_range . '...' . $end_range,
            ]);

            $order_ids = (is_object($result) && isset($result->orders) && is_array($result->orders))
                ? $result->orders
                : [];

            $max_pages = (is_object($result) && isset($result->max_num_pages))
                ? max(1, (int) $result->max_num_pages)
                : 1;

            if (empty($order_ids)) {
                break;
            }

            foreach ($order_ids as $order_id) {
                $order = wc_get_order(absint($order_id));

                if (!($order instanceof WC_Order)) {
                    continue;
                }

                if ($this->should_exclude_order($order)) {
                    continue;
                }

                $dt = $this->resolve_order_datetime($order, $timezone);

                if (!$dt || $dt < $bounds['start'] || $dt >= $bounds['end']) {
                    continue;
                }

                $user_id = absint($order->get_customer_id());
                $co2     = $this->get_order_co2($order, $co2_meta_keys);
                $stamp   = $dt->format('Y-m-d H:i:s');

                if (!isset($rows[$user_id])) {
                    $rows[$user_id] = (object) [
                        'user_id'   => $user_id,
                        'orders'    => 0,
                        'total_co2' => 0.0,
                    ];
                }

                $rows[$user_id]->orders++;
                $rows[$user_id]->total_co2 += $co2;

                if (!isset($user_totals[$user_id])) {
                    $user_totals[$user_id] = $this->empty_totals();
                }

                $user_totals[$user_id]['orders']++;
                $user_totals[$user_id]['co2'] += $co2;

                if (!isset($user_updated[$user_id]) || $stamp > $user_updated[$user_id]) {
                    $user_updated[$user_id] = $stamp;
                }

                if ($stamp > $store_updated) {
                    $store_updated = $stamp;
                }
            }

            unset($order_ids, $result);

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $page++;
        } while ($page <= $max_pages);

        ksort($rows);

        self::$week_cache[$week_period] = [
            'rows'         => array_values($rows),
            'updated'      => $store_updated,
            'user_totals'  => $user_totals,
            'user_updated' => $user_updated,
        ];

        return self::$week_cache[$week_period];
    }

    /**
     * @return array{start:?DateTimeImmutable,end:?DateTimeImmutable}
     */
    private function get_week_bounds(string $week_period): array
    {
        $week_period = $this->sanitize_period_for_view('week', $week_period);

        if ($week_period === '' || !preg_match('/^(\d{4})-W(\d{2})$/', $week_period, $matches)) {
            return [
                'start' => null,
                'end'   => null,
            ];
        }

        try {
            $timezone = $this->vcarb_wp_timezone();

            $start = (new DateTimeImmutable('now', $timezone))
                ->setISODate((int) $matches[1], (int) $matches[2], 1)
                ->setTime(0, 0, 0);

            return [
                'start' => $start,
                'end'   => $start->modify('+7 days'),
            ];
        } catch (Throwable $e) {
            return [
                'start' => null,
                'end'   => null,
            ];
        }
    }

    private function get_final_statuses(): array
    {
        $defaults = ['processing', 'completed'];

        $statuses = (array) apply_filters(
            'vcarb_final_statuses',
            $defaults,
            null
        );

        /*
         * Legacy filter kept for existing custom snippets/extensions.
         */
        $statuses = (array) apply_filters(
            'amatorcarbon_final_statuses',
            $statuses,
            null
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

    /**
     * @return array<int,string>
     */
    private function get_co2_meta_keys(): array
    {
        $keys = [];

        if (class_exists('VCARB_Order_Tracker')) {
            $keys[] = VCARB_Order_Tracker::META_CO2_KG;
        }

        $keys[] = '_vcarb_order_co2_kg';
        $keys[] = '_amatorcarbon_order_co2_kg';
        $keys[] = '_gc_order_co2_kg';

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * @param array<int,string> $meta_keys
     */
    private function get_order_co2(WC_Order $order, array $meta_keys): float
    {
        foreach ($meta_keys as $meta_key) {
            $value = (float) $order->get_meta($meta_key, true);

            if ($value > 0.0) {
                return $value;
            }
        }

        return 0.0;
    }
    
    private function should_exclude_order(WC_Order $order): bool
    {
        return class_exists('VCARB_Order_Tracker')
            && method_exists('VCARB_Order_Tracker', 'should_exclude_order_from_analytics')
            && VCARB_Order_Tracker::should_exclude_order_from_analytics($order);
    }

    private function resolve_order_datetime(WC_Order $order, DateTimeZone $timezone): ?DateTimeImmutable
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
            return (new DateTimeImmutable('@' . $date_obj->getTimestamp()))->setTimezone($timezone);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{orders:int,co2:float}
     */
    private function empty_totals(): array
    {
        return [
            'orders' => 0,
            'co2'    => 0.0,
        ];
    }

    /**
     * @return array{
     *   rows: array<int,object>,
     *   updated: string,
     *   user_totals: array<int,array{orders:int,co2:float}>,
     *   user_updated: array<int,string>
     * }
     */
    private function empty_cache(): array
    {
        return [
            'rows'         => [],
            'updated'      => '',
            'user_totals'  => [],
            'user_updated' => [],
        ];
    }
}
