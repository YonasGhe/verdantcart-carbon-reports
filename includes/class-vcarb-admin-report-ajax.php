<?php
defined('ABSPATH') || exit;

class VCARB_Admin_Report_Ajax
{
    use VCARB_Ajax_Helpers_Trait;
    use VCARB_Snapshot_Trait;
    use VCARB_Period_Trait;

    private const ALLOWED_VIEWS = ['month', 'week', 'year'];

    public static function register(): void
    {
        static $did = false;

        if ($did) {
            return;
        }

        $did  = true;
        $self = new self();

        add_action('wp_ajax_vcarb_get_report', [$self, 'get_report']);
        add_action('wp_ajax_vcarb_rebuild_aggregate', [$self, 'rebuild_aggregate']);
        add_action('wp_ajax_vcarb_get_hotspots', [$self, 'get_hotspots']);

        /*
         * Temporary legacy AJAX action support.
         * This protects old cached admin JS during the 1.0.x → 1.1.0 transition.
         */
        add_action('wp_ajax_amatorcarbon_get_report', [$self, 'get_report']);
        add_action('wp_ajax_amatorcarbon_rebuild_aggregate', [$self, 'rebuild_aggregate']);
        add_action('wp_ajax_amatorcarbon_get_hotspots', [$self, 'get_hotspots']);
    }

    private function normalize_view(string $view): string
    {
        $view = sanitize_key($view);

        return in_array($view, self::ALLOWED_VIEWS, true) ? $view : 'month';
    }

    private function require_calculator(): void
    {
        if (!class_exists('VCARB_Calculator')) {
            wp_send_json_error(
                ['message' => __('Calculator missing.', 'verdantcart-ai-reports')],
                500
            );
        }
    }

    private function has_dataset_dependencies(): bool
    {
        return class_exists('VCARB_Dataset_Service');
    }

    private function require_dataset_dependencies(): void
    {
        if (!$this->has_dataset_dependencies()) {
            wp_send_json_error(
                ['message' => __('Missing required classes.', 'verdantcart-ai-reports')],
                500
            );
        }
    }

    private function make_dataset_service(): VCARB_Dataset_Service
    {
        return new VCARB_Dataset_Service();
    }

    private function normalize_report_dataset(array $dataset, string $fallback_view): array
    {
        $snapshot = isset($dataset['snapshot']) && is_array($dataset['snapshot']) ? $dataset['snapshot'] : [];
        $metrics  = isset($dataset['metrics']) && is_array($dataset['metrics']) ? $dataset['metrics'] : [];
        $chart    = isset($dataset['chart']) && is_array($dataset['chart']) ? $dataset['chart'] : [];
        $rows     = isset($dataset['rows']) && is_array($dataset['rows']) ? $dataset['rows'] : [];
        $hotspots = isset($dataset['hotspots']) && is_array($dataset['hotspots']) ? $dataset['hotspots'] : [];
        $insights = isset($dataset['insights']) && is_array($dataset['insights']) ? $dataset['insights'] : [];

        $resolved_view = isset($dataset['view'])
            ? $this->normalize_view((string) $dataset['view'])
            : $this->normalize_view($fallback_view);

        $resolved_date = isset($dataset['date'])
            ? $this->sanitize_period_for_view_safe($resolved_view, (string) $dataset['date'])
            : '';

        return [
            'view'     => $resolved_view,
            'date'     => $resolved_date,
            'notice'   => isset($dataset['notice']) ? (string) $dataset['notice'] : '',
            'snapshot' => $snapshot,
            'metrics'  => $metrics,
            'chart'    => [
                'labels'      => isset($chart['labels']) && is_array($chart['labels']) ? array_values($chart['labels']) : [],
                'orders'      => isset($chart['orders']) && is_array($chart['orders']) ? array_values($chart['orders']) : [],
                'co2'         => isset($chart['co2']) && is_array($chart['co2']) ? array_values($chart['co2']) : [],
                'periods'     => isset($chart['periods']) && is_array($chart['periods']) ? array_values($chart['periods']) : [],
                'snapshotSet' => isset($chart['snapshotSet']) && is_array($chart['snapshotSet']) ? $chart['snapshotSet'] : [],
            ],
            'rows'     => array_values($rows),
            'hotspots' => array_values($hotspots),
            'insights' => $insights,
        ];
    }

    private function normalize_browser(array $browser, string $view, string $selected_period): array
    {
        $view            = $this->normalize_view($view);
        $selected_period = $this->sanitize_period_for_view_safe($view, $selected_period);

        $latest_period = $this->latest_store_snapshot_period($view);
        $latest_period = $this->sanitize_period_for_view_safe($view, $latest_period);

        $previous = isset($browser['previous']) ? (string) $browser['previous'] : '';
        $next     = isset($browser['next']) ? (string) $browser['next'] : '';

        $previous = $this->sanitize_period_for_view_safe($view, $previous);
        $next     = $this->sanitize_period_for_view_safe($view, $next);

        return [
            'selected'    => $selected_period,
            'previous'    => $previous,
            'next'        => $next,
            'has_prev'    => ($previous !== ''),
            'has_next'    => ($next !== ''),
            'latest'      => $latest_period,
            'latest_date' => $latest_period,
            'is_latest'   => ($latest_period !== '' && $selected_period !== '' && $latest_period === $selected_period),
        ];
    }

    public function get_report(): void
    {
        $this->require_logged_in();
        $this->require_cap('manage_options');
        $this->verify_nonce('vcarb_get_report');
        $this->rate_limit('admin_report', 25, 10);
        $this->require_calculator();
        $this->require_dataset_dependencies();

        $admin_id = get_current_user_id();
        $view     = $this->normalize_view($this->get_post_key('view', 'month'));
        $raw_date = $this->get_post_string('date', '');

        $dataset = $this->make_dataset_service()->build_admin_dataset(
            $view,
            $raw_date,
            $admin_id
        );

        if (!is_array($dataset)) {
            wp_send_json_error(
                ['message' => __('Invalid admin dataset.', 'verdantcart-ai-reports')],
                500
            );
        }

        $data     = $this->normalize_report_dataset($dataset, $view);
        $snapshot = $data['snapshot'];
        $metrics  = $data['metrics'];
        $chart    = $data['chart'];

        $total_co2     = isset($metrics['total_co2']) ? (float) $metrics['total_co2'] : 0.0;
        $orders        = isset($metrics['orders']) ? (int) $metrics['orders'] : 0;
        $co2_per_order = isset($metrics['co2_per_order']) && is_numeric($metrics['co2_per_order'])
            ? (float) $metrics['co2_per_order']
            : (($orders > 0) ? ($total_co2 / $orders) : null);

        $delta      = $metrics['delta'] ?? null;
        $delta_html = isset($metrics['delta_html']) ? (string) $metrics['delta_html'] : '';

        if ($delta_html === '' && class_exists('VCARB_Calculator') && method_exists('VCARB_Calculator', 'format_percent')) {
            $delta_html = wp_kses_post(VCARB_Calculator::format_percent($delta));
        }

        wp_send_json_success([
            'view'         => $data['view'],
            'date'         => $data['date'],
            'notice'       => $data['notice'],
            'has_snapshot' => !empty($snapshot['has']),
            'browser'      => $this->normalize_browser(
                isset($dataset['browser']) && is_array($dataset['browser']) ? $dataset['browser'] : [],
                $data['view'],
                $data['date']
            ),

            'snapshot' => [
                'has'         => !empty($snapshot['has']),
                'period'      => isset($snapshot['period']) ? (string) $snapshot['period'] : '',
                'source'      => isset($snapshot['source']) ? (string) $snapshot['source'] : 'none',
                'updated'     => isset($snapshot['updated']) ? (string) $snapshot['updated'] : '',
                'updated_raw' => isset($snapshot['updated_raw']) ? (string) $snapshot['updated_raw'] : '',
            ],

            'metrics' => [
                'total_co2'     => $total_co2,
                'orders'        => $orders,
                'co2_per_order' => $co2_per_order,
                'delta'         => $delta,
                'delta_html'    => $delta_html,
                'updated'       => isset($metrics['updated']) ? (string) $metrics['updated'] : '',
                'updated_raw'   => isset($metrics['updated_raw']) ? (string) $metrics['updated_raw'] : '',
            ],

            'chart' => [
                'labels'      => $chart['labels'],
                'orders'      => $chart['orders'],
                'co2'         => $chart['co2'],
                'periods'     => $chart['periods'],
                'snapshotSet' => $chart['snapshotSet'],
            ],

            'rows'     => $data['rows'],
            'hotspots' => $data['hotspots'],
            'insights' => $data['insights'],
        ]);
    }

    public function get_hotspots(): void
    {
        $this->require_logged_in();
        $this->require_cap('manage_options');
        $this->verify_nonce('vcarb_get_hotspots');
        $this->rate_limit('admin_hotspots', 60, 10);

        $view = $this->normalize_view($this->get_post_key('view', 'month'));

        $raw_period = $this->get_post_string('period', '');
        if ($raw_period === '') {
            $raw_period = $this->get_post_string('date', '');
        }

        $period = $this->sanitize_period_for_view_safe($view, $raw_period);

        if ($period === '') {
            wp_send_json_error(
                ['message' => __('Invalid hotspot period.', 'verdantcart-ai-reports')],
                400
            );
        }

        /*
         * Important:
         * Do not block hotspot loading only because a snapshot check fails.
         * The main report already validates the selected snapshot.
         * Hotspot rows can exist independently, especially after migrations/backfills.
         */
        if (
            !class_exists('VCARB_Product_Insights') ||
            !method_exists('VCARB_Product_Insights', 'get_hotspots')
        ) {
            wp_send_json_error(
                ['message' => __('Product hotspot service is missing.', 'verdantcart-ai-reports')],
                500
            );
        }

        $items = VCARB_Product_Insights::get_hotspots($period, 10);

        wp_send_json_success([
            'view'   => $view,
            'period' => $period,
            'items'  => is_array($items) ? array_values($items) : [],
        ]);
    }

    public function rebuild_aggregate(): void
    {
        $this->require_logged_in();
        $this->require_cap('manage_options');
        $this->verify_nonce('vcarb_rebuild_aggregate');
        $this->rate_limit('admin_rebuild', 3, 60);
        $this->require_calculator();

        if (
            !class_exists('VCARB_Scheduler') ||
            !method_exists('VCARB_Scheduler', 'schedule_aggregate_debounced')
        ) {
            wp_send_json_error(
                ['message' => __('Scheduler missing.', 'verdantcart-ai-reports')],
                500
            );
        }

        if (!method_exists('VCARB_Calculator', 'get_period_keys')) {
            wp_send_json_error(
                ['message' => __('Calculator period helper missing.', 'verdantcart-ai-reports')],
                500
            );
        }

        $view = $this->get_post_key('type', '');
        if ($view === '') {
            $view = $this->get_post_key('view', 'month');
        }

        $view = $this->normalize_view($view);

        $requested_period = $this->get_post_string('period', '');

        if ($requested_period === '') {
            $requested_period = $this->get_post_string('date', '');
        }

        $period = $this->sanitize_period_for_view_safe($view, $requested_period);

        if ($period === '') {
            $periods = VCARB_Calculator::get_period_keys($view);
            $period  = $this->sanitize_period_for_view_safe(
                $view,
                isset($periods['current']) ? (string) $periods['current'] : ''
            );
        }

        if ($period === '') {
            wp_send_json_error(
                ['message' => __('Invalid resolved period.', 'verdantcart-ai-reports')],
                500
            );
        }

        VCARB_Scheduler::schedule_aggregate_debounced($view, $period);

        $hotspots_rebuilt = false;

        if (
            class_exists('VCARB_Product_Insights') &&
            method_exists('VCARB_Product_Insights', 'rebuild_period')
        ) {
            VCARB_Product_Insights::rebuild_period($view, $period);
            $hotspots_rebuilt = true;
        }

        wp_send_json_success([
            'scheduled'        => true,
            'rebuilt'          => $view,
            'period'           => $period,
            'hotspots_rebuilt' => $hotspots_rebuilt,
        ]);
    }
}
