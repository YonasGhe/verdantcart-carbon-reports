<?php
defined('ABSPATH') || exit;

class VCARB_Backfill_Ajax
{
    use VCARB_Ajax_Helpers_Trait;

    public const BACKFILL_NONCE_ACTION = 'vcarb_backfill';
    public const BACKFILL_STATE_OPTION = 'vcarb_backfill_state';
    public const BACKFILL_STOP_OPTION  = 'vcarb_backfill_stop';

    private const LOCK_KEY        = 'vcarb_backfill_batch_lock';
    private const LEGACY_LOCK_KEY = 'amatorcarbon_backfill_batch_lock';

    public static function register(): void
    {
        static $did = false;

        if ($did) {
            return;
        }

        $did  = true;
        $self = new self();

        add_action('wp_ajax_vcarb_backfill_start', [$self, 'backfill_start']);
        add_action('wp_ajax_vcarb_backfill_batch', [$self, 'backfill_batch']);
        add_action('wp_ajax_vcarb_backfill_stop', [$self, 'backfill_stop']);

    }

    private function require_backfill_dependencies(): void
    {
        if (!function_exists('wc_get_orders') || !function_exists('wc_get_order')) {
            wp_send_json_error(
                ['message' => __('WooCommerce is not active.', 'verdantcart-ai-reports')],
                500
            );
        }

        if (!class_exists('VCARB_Order_Tracker')) {
            wp_send_json_error(
                ['message' => __('Order tracker missing.', 'verdantcart-ai-reports')],
                500
            );
        }
    }

    private function get_final_statuses(): array
    {
        $statuses = (array) apply_filters(
            'vcarb_final_statuses',
            ['processing', 'completed'],
            null
        );

        // Temporary legacy filter support.
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

        return !empty($statuses) ? $statuses : ['processing', 'completed'];
    }

    private function get_wp_timezone_safe(): DateTimeZone
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

    private function get_order_period_date(WC_Order $order): ?DateTimeImmutable
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
                ->setTimezone($this->get_wp_timezone_safe());
        } catch (Throwable $e) {
            return null;
        }
    }

    private function get_backfill_state(): array
    {
        $state = get_option(self::BACKFILL_STATE_OPTION, []);

        // Legacy fallback from 1.0.x.
        if (!is_array($state) || empty($state)) {
            $legacy_state = get_option('amatorcarbon_backfill_state', []);

            if (is_array($legacy_state) && !empty($legacy_state)) {
                $state = $legacy_state;
            }
        }

        return is_array($state) ? $state : [];
    }

    private function save_backfill_state(array $state): void
    {
        update_option(self::BACKFILL_STATE_OPTION, $state, false);

        // Remove old state so we do not resume from two different option names.
        delete_option('amatorcarbon_backfill_state');
    }

    private function delete_stop_flag(): void
    {
        delete_option(self::BACKFILL_STOP_OPTION);
        delete_option('amatorcarbon_backfill_stop');
    }

    private function delete_backfill_state(): void
    {
        delete_option(self::BACKFILL_STATE_OPTION);
        delete_option('amatorcarbon_backfill_state');
    }

    private function delete_backfill_locks(): void
    {
        delete_transient(self::LOCK_KEY);
        delete_transient(self::LEGACY_LOCK_KEY);
    }

    private function is_stop_requested(): bool
    {
        return (bool) get_option(self::BACKFILL_STOP_OPTION) || (bool) get_option('amatorcarbon_backfill_stop');
    }

    private function send_success_and_unlock(array $data): void
    {
        $this->delete_backfill_locks();
        wp_send_json_success($data);
    }

    private function send_error_and_unlock(array $data, int $status_code = 400): void
    {
        $this->delete_backfill_locks();
        wp_send_json_error($data, $status_code);
    }

    private function aggregate_touched_periods(array $touched_periods): void
    {
        if (!class_exists('VCARB_Calculator') || !method_exists('VCARB_Calculator', 'aggregate')) {
            return;
        }

        foreach (['week', 'month', 'year'] as $view) {
            $periods = array_keys($touched_periods[$view] ?? []);

            foreach ($periods as $period) {
                $period = is_string($period) ? trim($period) : '';

                if ($period === '') {
                    continue;
                }

                VCARB_Calculator::aggregate($view, $period);
            }
        }
    }

    private function reset_order_count_flags(int $order_id): void
    {
        $order = wc_get_order($order_id);

        if (!($order instanceof WC_Order)) {
            return;
        }

        $meta_keys = [
            // Current VCARB meta.
            VCARB_Order_Tracker::META_COUNTED,
            VCARB_Order_Tracker::META_HOTSPOTS_COUNTED,
            VCARB_Order_Tracker::META_CO2_KG,

            // Legacy AmatorCarbon meta.
            '_amatorcarbon_order_co2_counted',
            '_amatorcarbon_order_hotspots_counted',
            '_amatorcarbon_order_co2_kg',
            '_amatorcarbon_order_co2_lock',

            // Older GreenCart / AI Carbon meta.
            '_gc_order_co2_counted',
            '_gc_order_hotspots_counted',
            '_gc_order_co2_kg',
            '_gc_order_co2_lock',
        ];

        foreach ($meta_keys as $meta_key) {
            $order->delete_meta_data($meta_key);
        }

        $order->save();
    }

    private function order_needs_repair(WC_Order $order): bool
    {
        $co2              = $order->get_meta(VCARB_Order_Tracker::META_CO2_KG, true);
        $hotspots_counted = $order->get_meta(VCARB_Order_Tracker::META_HOTSPOTS_COUNTED, true);

        if ($co2 === '' || $co2 === null || (float) $co2 <= 0) {
            return true;
        }

        if ($hotspots_counted !== 'yes') {
            return true;
        }

        return false;
    }

    private function order_has_any_counted_flag(WC_Order $order): bool
    {
        $keys = [
            VCARB_Order_Tracker::META_COUNTED,
            '_amatorcarbon_order_co2_counted',
            '_gc_order_co2_counted',
        ];

        foreach ($keys as $key) {
            if ((string) $order->get_meta($key, true) !== '') {
                return true;
            }
        }

        return false;
    }
    
    private function stop_response(array $state): void
    {
        $this->delete_stop_flag();
        $this->delete_backfill_locks();

        wp_send_json_success([
            'done'            => false,
            'stopped'         => true,
            'page'            => (int) ($state['page'] ?? 1),
            'total_pages'     => 0,
            'processed_total' => (int) ($state['processed_total'] ?? 0),
            'updated_total'   => (int) ($state['updated_total'] ?? 0),
            'skipped_total'   => (int) ($state['skipped_total'] ?? 0),
            'last_order_id'   => (int) ($state['last_order_id'] ?? 0),
            'message'         => __('Backfill stopped.', 'verdantcart-ai-reports'),
        ]);
    }

    public function backfill_start(): void
    {
        $this->verify_nonce(self::BACKFILL_NONCE_ACTION);
        $this->require_logged_in();
        $this->require_cap('manage_options');
        $this->rate_limit('backfill_start', 10, 30);

        $include_counted = $this->get_post_bool('include_counted');

        $this->delete_stop_flag();
        $this->delete_backfill_locks();

        $state = [
            'page'            => 1,
            'per_page'        => 20,
            'include_counted' => $include_counted ? 1 : 0,
            'processed_total' => 0,
            'updated_total'   => 0,
            'skipped_total'   => 0,
            'last_order_id'   => 0,
            'started_at'      => time(),
            'touched_week'    => [],
            'touched_month'   => [],
            'touched_year'    => [],
        ];

        $this->save_backfill_state($state);

        wp_send_json_success([
            'started' => true,
            'message' => __('Backfill started.', 'verdantcart-ai-reports'),
        ]);
    }

    public function backfill_stop(): void
    {
        $this->verify_nonce(self::BACKFILL_NONCE_ACTION);
        $this->require_logged_in();
        $this->require_cap('manage_options');
        $this->rate_limit('backfill_stop', 20, 10);

        update_option(self::BACKFILL_STOP_OPTION, 1, false);
        update_option('amatorcarbon_backfill_stop', 1, false);

        $this->delete_backfill_locks();

        wp_send_json_success([
            'stopped' => true,
            'message' => __('Stop request accepted.', 'verdantcart-ai-reports'),
        ]);
    }

    public function backfill_batch(): void
    {
        $this->verify_nonce(self::BACKFILL_NONCE_ACTION);
        $this->require_logged_in();
        $this->require_cap('manage_options');
        $this->rate_limit('backfill_batch', 240, 60);
        $this->require_backfill_dependencies();

        /*
         * A lock prevents two batch requests from running at the same time.
         * It is cleared on every success/error path below.
         */
        if (get_transient(self::LOCK_KEY) || get_transient(self::LEGACY_LOCK_KEY)) {
            wp_send_json_error(
                ['message' => __('Backfill is already running. Please wait a few seconds, then click Stop or refresh and start again.', 'verdantcart-ai-reports')],
                429
            );
        }

        set_transient(self::LOCK_KEY, time(), 60);

        try {
            $state = $this->get_backfill_state();

            if ($this->is_stop_requested()) {
                $this->stop_response($state);
            }

            if (empty($state)) {
                $this->send_error_and_unlock(
                    ['message' => __('Backfill not started.', 'verdantcart-ai-reports')],
                    400
                );
            }

            $page            = max(1, (int) ($state['page'] ?? 1));
            $per_page        = max(5, min(100, (int) ($state['per_page'] ?? 20)));
            $include_counted = !empty($state['include_counted']);
            $statuses        = $this->get_final_statuses();

            $touched_periods = [
                'week'  => is_array($state['touched_week'] ?? null) ? $state['touched_week'] : [],
                'month' => is_array($state['touched_month'] ?? null) ? $state['touched_month'] : [],
                'year'  => is_array($state['touched_year'] ?? null) ? $state['touched_year'] : [],
            ];

            $query = wc_get_orders([
                'status'   => $statuses,
                'limit'    => $per_page,
                'paged'    => $page,
                'orderby'  => 'date',
                'order'    => 'ASC',
                'return'   => 'ids',
                'paginate' => true,
            ]);

            $order_ids = (is_object($query) && isset($query->orders) && is_array($query->orders))
                ? $query->orders
                : [];

            $total_pages = (is_object($query) && isset($query->max_num_pages))
                ? (int) $query->max_num_pages
                : $page;

            $total_pages = max(1, $total_pages);

            if (empty($order_ids)) {
                $this->aggregate_touched_periods($touched_periods);
                $this->delete_stop_flag();
                $this->delete_backfill_state();

                $this->send_success_and_unlock([
                    'done'            => true,
                    'stopped'         => false,
                    'page'            => $page,
                    'total_pages'     => $total_pages,
                    'processed_total' => (int) ($state['processed_total'] ?? 0),
                    'updated_total'   => (int) ($state['updated_total'] ?? 0),
                    'skipped_total'   => (int) ($state['skipped_total'] ?? 0),
                    'last_order_id'   => (int) ($state['last_order_id'] ?? 0),
                    'message'         => __('Backfill completed.', 'verdantcart-ai-reports'),
                ]);
            }

            $tracker = VCARB_Order_Tracker::instance(false);

            $updated_this_batch = 0;
            $skipped_this_batch = 0;

            foreach ($order_ids as $order_id) {
                $order_id = (int) $order_id;

                if ($order_id <= 0) {
                    $skipped_this_batch++;
                    continue;
                }

                $order = wc_get_order($order_id);

                if (!($order instanceof WC_Order)) {
                    $skipped_this_batch++;
                    continue;
                }

                $state['last_order_id'] = $order_id;

                if (
                    method_exists('VCARB_Order_Tracker', 'should_exclude_order_from_analytics') &&
                    VCARB_Order_Tracker::should_exclude_order_from_analytics($order)
                ) {
                    $skipped_this_batch++;
                    continue;
                }

                $counted = $this->order_has_any_counted_flag($order);

                if ($counted && !$include_counted) {
                    $skipped_this_batch++;
                    continue;
                }

                $should_process = !$counted;

                if ($counted && $include_counted && $this->order_needs_repair($order)) {
                    $this->reset_order_count_flags($order_id);
                    $order          = wc_get_order($order_id);
                    $should_process = true;
                }

                if (!$should_process) {
                    $skipped_this_batch++;
                    continue;
                }

                $tracker->on_order_finalized($order_id);

                $order = wc_get_order($order_id);
                $updated_this_batch++;

                if ($order instanceof WC_Order) {
                    $dt = $this->get_order_period_date($order);

                    if ($dt instanceof DateTimeImmutable) {
                        $week_period  = sprintf('%04d-W%02d', (int) $dt->format('o'), (int) $dt->format('W'));
                        $month_period = $dt->format('Y-m');
                        $year_period  = $dt->format('Y');

                        $touched_periods['week'][$week_period]   = true;
                        $touched_periods['month'][$month_period] = true;
                        $touched_periods['year'][$year_period]   = true;
                    }
                }
            }

            $state['processed_total'] = (int) ($state['processed_total'] ?? 0) + count($order_ids);
            $state['updated_total']   = (int) ($state['updated_total'] ?? 0) + $updated_this_batch;
            $state['skipped_total']   = (int) ($state['skipped_total'] ?? 0) + $skipped_this_batch;
            $state['touched_week']    = $touched_periods['week'];
            $state['touched_month']   = $touched_periods['month'];
            $state['touched_year']    = $touched_periods['year'];
            $state['page']            = $page + 1;

            $this->save_backfill_state($state);

            $done = ($page >= $total_pages);

            if ($done) {
                $this->aggregate_touched_periods($touched_periods);
                $this->delete_stop_flag();
                $this->delete_backfill_state();
            }

            $this->send_success_and_unlock([
                'done'            => $done,
                'stopped'         => false,
                'page'            => $page,
                'total_pages'     => $total_pages,
                'processed_total' => (int) $state['processed_total'],
                'updated_total'   => (int) $state['updated_total'],
                'skipped_total'   => (int) $state['skipped_total'],
                'last_order_id'   => (int) $state['last_order_id'],
                'message'         => $done
                    ? __('Backfill completed.', 'verdantcart-ai-reports')
                    : __('Batch processed.', 'verdantcart-ai-reports'),
            ]);
        } catch (Throwable $e) {
            $this->send_error_and_unlock(
                [
                    'message' => sprintf(
                        /* translators: %s: error message. */
                        __('Backfill failed: %s', 'verdantcart-ai-reports'),
                        $e->getMessage()
                    ),
                ],
                500
            );
        } finally {
            $this->delete_backfill_locks();
        }
    }
}
