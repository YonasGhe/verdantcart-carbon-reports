<?php
defined('ABSPATH') || exit;

final class VCARB_Exports
{
    use VCARB_Snapshot_Trait;
    use VCARB_Period_Trait;

    private static bool $did_hooks = false;

    private const ALLOWED_VIEWS = ['month', 'week', 'year'];

    public function __construct()
    {
        if (self::$did_hooks) {
            return;
        }

        self::$did_hooks = true;

        add_action('admin_post_vcarb_export_user_csv', [$this, 'export_user_csv']);
        add_action('admin_post_vcarb_export_user_pdf', [$this, 'export_user_pdf']);
        add_action('admin_post_vcarb_export_admin_csv', [$this, 'export_admin_csv']);
        add_action('admin_post_vcarb_export_admin_pdf', [$this, 'export_admin_pdf']);

        /*
         * Temporary legacy export action support for 1.0.x URLs.
         */
        add_action('admin_post_amatorcarbon_export_user_csv', [$this, 'export_user_csv']);
        add_action('admin_post_amatorcarbon_export_user_pdf', [$this, 'export_user_pdf']);
        add_action('admin_post_amatorcarbon_export_admin_csv', [$this, 'export_admin_csv']);
        add_action('admin_post_amatorcarbon_export_admin_pdf', [$this, 'export_admin_pdf']);
    }

    // ------------------------------------------------------------
    // Audit
    // ------------------------------------------------------------

    private function audit(array $row): void
    {
        if (class_exists('VCARB_Export_Audit')) {
            VCARB_Export_Audit::log($row);
        }
    }

    private function audit_success(
        string $scope,
        string $format,
        string $view,
        string $requested_date,
        string $resolved_anchor,
        string $action,
        int $actor_user_id
    ): void {
        $this->audit([
            'actor_user_id'   => $actor_user_id,
            'scope'           => $scope,
            'format'          => $format,
            'view'            => $view,
            'requested_date'  => $requested_date,
            'resolved_anchor' => $resolved_anchor,
            'action'          => $action,
            'result'          => 'ok',
            'http_status'     => 200,
            'message'         => '',
            'created_at'      => current_time('mysql', true),
        ]);
    }

    // ------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------

    private function require_login(): void
    {
        if (!is_user_logged_in()) {
            wp_die(
                esc_html__('You must be logged in to export.', 'verdantcart-ai-reports'),
                esc_html__('Unauthorized', 'verdantcart-ai-reports'),
                ['response' => 403]
            );
        }
    }

    private function require_admin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('Unauthorized.', 'verdantcart-ai-reports'),
                esc_html__('Forbidden', 'verdantcart-ai-reports'),
                ['response' => 403]
            );
        }
    }

    private function verify_nonce_or_die(string $action): void
    {
        $nonce = '';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce is unslashed, sanitized, and verified immediately below.
        $raw_nonce = isset($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '';

        if (is_scalar($raw_nonce)) {
            $nonce = sanitize_text_field((string) $raw_nonce);
        }

        $legacy_action = '';

        if ($action === 'vcarb_export_user') {
            $legacy_action = 'amatorcarbon_export_user';
        } elseif ($action === 'vcarb_export_admin') {
            $legacy_action = 'amatorcarbon_export_admin';
        }

        $valid = $nonce !== '' && wp_verify_nonce($nonce, $action);

        if (!$valid && $legacy_action !== '') {
            $valid = $nonce !== '' && wp_verify_nonce($nonce, $legacy_action);
        }

        if (!$valid) {
            wp_die(
                esc_html__('Invalid nonce.', 'verdantcart-ai-reports'),
                esc_html__('Forbidden', 'verdantcart-ai-reports'),
                ['response' => 403]
            );
        }
    }

    private function deny_store_user_id(int $user_id): void
    {
        if ($user_id === 0) {
            wp_die(
                esc_html__('Invalid user.', 'verdantcart-ai-reports'),
                esc_html__('Bad Request', 'verdantcart-ai-reports'),
                ['response' => 400]
            );
        }
    }

    private function require_dataset_dependencies(): void
    {
        if (!class_exists('VCARB_Dataset_Service')) {
            wp_die(
                esc_html__('Missing dataset classes.', 'verdantcart-ai-reports'),
                esc_html__('Server error', 'verdantcart-ai-reports'),
                ['response' => 500]
            );
        }
    }

    private function rate_limit_or_die(string $bucket, int $limit = 5, int $seconds = 60): void
    {
        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            wp_die(
                esc_html__('Invalid user.', 'verdantcart-ai-reports'),
                esc_html__('Bad Request', 'verdantcart-ai-reports'),
                ['response' => 400]
            );
        }

        $key  = 'vcarb_rl_' . sanitize_key($bucket) . '_' . absint($user_id);
        $hits = (int) get_transient($key);

        if ($hits >= $limit) {
            wp_die(
                esc_html__('Too many export requests. Please try again shortly.', 'verdantcart-ai-reports'),
                esc_html__('Too Many Requests', 'verdantcart-ai-reports'),
                ['response' => 429]
            );
        }

        set_transient($key, $hits + 1, $seconds);
    }

    // ------------------------------------------------------------
    // Request helpers
    // ------------------------------------------------------------

    private function sanitize_view($view): string
    {
        $view = sanitize_key((string) $view);

        return in_array($view, self::ALLOWED_VIEWS, true) ? $view : 'month';
    }

    private function current_request_view(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only export routing parameter. Nonce is verified before this value is used.
        if (!isset($_GET['view'])) {
            return 'month';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is unslashed, type-checked, and sanitized below.
        $raw = wp_unslash($_GET['view']);

        if (!is_scalar($raw)) {
            return 'month';
        }

        return $this->sanitize_view((string) $raw);
    }

    private function current_request_date(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only export routing parameter. Nonce is verified before this value is used.
        if (!isset($_GET['date'])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is unslashed, type-checked, and sanitized below.
        $raw = wp_unslash($_GET['date']);

        if (!is_scalar($raw)) {
            return '';
        }

        return sanitize_text_field((string) $raw);
    }

    private function require_export_snapshot_period_strict(string $view, $date_raw): string
    {
        $view      = $this->sanitize_view($view);
        $date_raw  = is_scalar($date_raw) ? (string) $date_raw : '';
        $requested = $this->sanitize_period_for_view_safe($view, $date_raw);

        if ($requested === '' || !$this->validate_period_strict($view, $requested)) {
            wp_die(
                esc_html__('Missing or invalid snapshot date.', 'verdantcart-ai-reports'),
                esc_html__('Bad Request', 'verdantcart-ai-reports'),
                ['response' => 400]
            );
        }

        if (!$this->store_snapshot_exists($view, $requested)) {
            wp_die(
                esc_html__('Snapshot does not exist for this period.', 'verdantcart-ai-reports'),
                esc_html__('Bad Request', 'verdantcart-ai-reports'),
                ['response' => 400]
            );
        }

        return $requested;
    }

    private function validate_period_strict(string $view, string $period): bool
    {
        $view   = $this->sanitize_view($view);
        $period = trim($period);

        if ($view === 'month') {
            if (!preg_match('/^(\d{4})-(\d{2})$/', $period, $matches)) {
                return false;
            }

            $year  = (int) $matches[1];
            $month = (int) $matches[2];

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
            if (!preg_match('/^(\d{4})-W(\d{2})$/', $period, $matches)) {
                return false;
            }

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

    private function dataset_service(): VCARB_Dataset_Service
    {
        $this->require_dataset_dependencies();

        return new VCARB_Dataset_Service();
    }

    // ------------------------------------------------------------
    // Formatting helpers
    // ------------------------------------------------------------

    private function filename(string $base, string $view, string $ext, string $period = ''): string
    {
        $stamp       = gmdate('Y-m-d_His');
        $safe_period = $period !== ''
            ? preg_replace('/[^0-9A-Za-z\-]/', '_', $period)
            : 'current';

        return "{$base}_{$view}_{$safe_period}_{$stamp}.{$ext}";
    }

    private function send_csv_headers(string $filename): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');

        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
    }

    private function start_csv_output($out): void
    {
        /*
     * UTF-8 BOM helps Excel/WPS read CO₂ and special characters correctly.
     * Do not write "sep=," because WPS may display it as normal content.
     *
     * phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Output stream php://output is not a filesystem write and cannot be handled by WP_Filesystem.
     */
        fwrite($out, "\xEF\xBB\xBF");
    }

    private function put_csv($out, array $row): void
    {
        /*
     * Semicolon delimiter opens better in WPS/Excel for many European locales.
     */
        fputcsv($out, array_map([$this, 'csv_cell'], $row), ';');
    }

    private function send_html_headers(string $filename): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow', true);

        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="' . sanitize_file_name($filename) . '"');
        header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    }

    private function csv_cell($value): string
    {
        $value = (string) $value;
        $value = str_replace(["\r", "\n"], ' ', $value);

        /*
     * Prevent CSV formula injection for user-facing values.
     */
        if ($value !== '' && preg_match('/^[=\+\-@]/', $value)) {
            $value = "'" . $value;
        }

        return $value;
    }

    private function percent_text($value): string
    {
        if (!class_exists('VCARB_Calculator')) {
            return '—';
        }

        return wp_strip_all_tags(VCARB_Calculator::format_percent($value));
    }

    private function format_decimal($value, int $decimals = 2): string
    {
        return number_format((float) $value, $decimals, '.', '');
    }

    private function format_period_label(string $view, string $period): string
    {
        $view   = $this->sanitize_view($view);
        $period = trim($period);

        if ($period === '') {
            return '—';
        }

        if ($view === 'month' && preg_match('/^(\d{4})-(\d{2})$/', $period, $matches)) {
            try {
                $dt = new DateTimeImmutable(sprintf('%04d-%02d-01', (int) $matches[1], (int) $matches[2]));

                return wp_date('F Y', $dt->getTimestamp());
            } catch (Throwable $e) {
                return $period;
            }
        }

        if ($view === 'year' && preg_match('/^\d{4}$/', $period)) {
            return $period;
        }

        if ($view === 'week' && preg_match('/^(\d{4})-W(\d{2})$/', $period, $matches)) {
            return sprintf(
                /* translators: 1: ISO week number, 2: 4-digit year. */
                __('Week %1$02d, %2$04d', 'verdantcart-ai-reports'),
                (int) $matches[2],
                (int) $matches[1]
            );
        }

        return $period;
    }

    private function render_html_meta(array $rows): string
    {
        $parts = [];

        foreach ($rows as $row) {
            $key = isset($row[0]) ? (string) $row[0] : '';
            $val = isset($row[1]) ? (string) $row[1] : '';
            $parts[] = esc_html($key) . ': ' . esc_html($val);
        }

        return implode(' · ', $parts);
    }

    private function write_csv_meta($out, array $meta_rows): void
    {
        foreach ($meta_rows as $row) {
            $this->put_csv($out, $row);
        }

        $this->put_csv($out, []);
    }

    // ------------------------------------------------------------
    // Meta row helpers
    // ------------------------------------------------------------

    private function user_export_meta_rows(array $data): array
    {
        $rows = [
            [__('View', 'verdantcart-ai-reports'), (string) ($data['view'] ?? '')],
            [__('Anchor (snapshot)', 'verdantcart-ai-reports'), (string) ($data['anchor'] ?? '')],
            [__('Snapshot updated', 'verdantcart-ai-reports'), !empty($data['snapshot_updated']) ? (string) $data['snapshot_updated'] : '—'],
            [__('Generated (UTC)', 'verdantcart-ai-reports'), gmdate('Y-m-d H:i:s')],
        ];

        if (!empty($data['notice'])) {
            $rows[] = [__('Notice', 'verdantcart-ai-reports'), (string) $data['notice']];
        }

        return $rows;
    }

    private function admin_export_meta_rows(string $view, string $anchor_date, string $snapshot_updated): array
    {
        return [
            [__('View', 'verdantcart-ai-reports'), $view],
            [__('Anchor (snapshot)', 'verdantcart-ai-reports'), $anchor_date],
            [__('Snapshot updated', 'verdantcart-ai-reports'), $snapshot_updated !== '' ? $snapshot_updated : '—'],
            [__('Generated (UTC)', 'verdantcart-ai-reports'), gmdate('Y-m-d H:i:s')],
        ];
    }

    // ------------------------------------------------------------
    // Narrative helpers
    // ------------------------------------------------------------

    private function build_report_notes(
        float $total_co2,
        int $orders,
        $co2_per_order,
        $delta,
        string $anchor_date,
        bool $exclude_store_row = false
    ): array {
        $notes      = [];
        $delta_text = class_exists('VCARB_Calculator')
            ? wp_strip_all_tags(VCARB_Calculator::format_percent($delta))
            : '—';

        if ($delta === null) {
            $notes[] = __('No previous snapshot exists yet, so trend comparison is not available.', 'verdantcart-ai-reports');
        } elseif ((float) $delta > 0) {
            $notes[] = sprintf(
                /* translators: %s: percentage change text. */
                __('Emissions increased %s versus the previous period.', 'verdantcart-ai-reports'),
                $delta_text
            );
        } elseif ((float) $delta < 0) {
            $notes[] = sprintf(
                /* translators: %s: percentage change text. */
                __('Emissions decreased %s versus the previous period.', 'verdantcart-ai-reports'),
                $delta_text
            );
        } else {
            $notes[] = __('Emissions were unchanged compared with the previous period.', 'verdantcart-ai-reports');
        }

        $notes[] = sprintf(
            /* translators: 1: number of completed orders, 2: total emissions in kg CO2. */
            __('This report covers %1$d eligible orders with total emissions of %2$s kg CO₂.', 'verdantcart-ai-reports'),
            $orders,
            number_format($total_co2, 2)
        );

        if (is_numeric($co2_per_order)) {
            $notes[] = sprintf(
                /* translators: %s: average emissions per order in kg CO2. */
                __('Average emissions intensity was %s kg CO₂ per order.', 'verdantcart-ai-reports'),
                number_format((float) $co2_per_order, 2)
            );
        }

        $notes[] = sprintf(
            /* translators: %s: snapshot anchor period. */
            __('This export reflects snapshot period %s.', 'verdantcart-ai-reports'),
            $anchor_date
        );

        if ($exclude_store_row) {
            $notes[] = __('Customer breakdown excludes the store-total row because it is already summarized above.', 'verdantcart-ai-reports');
        }

        return $notes;
    }

    // ------------------------------------------------------------
    // Dataset builders
    // ------------------------------------------------------------

    private function build_user_export_data(int $user_id, string $view, string $anchor_date): array
    {
        $dataset = $this->dataset_service()->build_user_dataset($user_id, $view, $anchor_date);
        $metrics = (array) ($dataset['metrics'] ?? []);

        return [
            'view'             => (string) ($dataset['view'] ?? $view),
            'anchor'           => (string) ($dataset['date'] ?? $anchor_date),
            'total_co2'        => (float) ($metrics['total_co2'] ?? 0),
            'orders'           => (int) ($metrics['orders'] ?? 0),
            'delta'            => $metrics['delta'] ?? null,
            'notice'           => (string) ($dataset['notice'] ?? ''),
            'snapshot_updated' => (string) ($metrics['updated'] ?? ''),
            'chart'            => (array) ($dataset['chart'] ?? []),
        ];
    }

    private function build_admin_export_data(string $view, string $anchor_date, int $admin_id): array
    {
        $dataset = $this->dataset_service()->build_admin_dataset($view, $anchor_date, $admin_id);
        $metrics = (array) ($dataset['metrics'] ?? []);
        $rows    = (array) ($dataset['rows'] ?? []);

        $rows_out = [];

        foreach ($rows as $row) {
            $rows_out[] = [
                'user'             => (string) ($row['user'] ?? ''),
                'orders'           => (int) ($row['orders'] ?? 0),
                'orders_pct'       => wp_strip_all_tags((string) ($row['orders_pct'] ?? '')),
                'orders_pct_value' => $row['orders_pct_value'] ?? null,
                'co2'              => (string) ($row['co2'] ?? '0.00'),
                'co2_pct'          => wp_strip_all_tags((string) ($row['co2_pct'] ?? '')),
                'co2_pct_value'    => $row['co2_pct_value'] ?? null,
                'updated'          => (string) ($row['updated'] ?? ''),
            ];
        }

        return [
            'view'             => (string) ($dataset['view'] ?? $view),
            'anchor'           => (string) ($dataset['date'] ?? $anchor_date),
            'snapshot_updated' => (string) ($metrics['updated'] ?? ''),
            'total_co2'        => (float) ($metrics['total_co2'] ?? 0),
            'orders'           => (int) ($metrics['orders'] ?? 0),
            'co2_per_order'    => $metrics['co2_per_order'] ?? null,
            'delta'            => $metrics['delta'] ?? null,
            'rows'             => $rows_out,
            'chart'            => (array) ($dataset['chart'] ?? []),
        ];
    }

    // ------------------------------------------------------------
    // CSV helpers
    // ------------------------------------------------------------

    private function write_chart_series_csv($out, array $chart): void
    {
        $labels        = isset($chart['labels']) && is_array($chart['labels']) ? $chart['labels'] : [];
        $orders_series = isset($chart['orders']) && is_array($chart['orders']) ? $chart['orders'] : [];
        $co2_series    = isset($chart['co2']) && is_array($chart['co2']) ? $chart['co2'] : [];

        if (empty($labels)) {
            return;
        }

        $this->put_csv($out, [
            __('Section', 'verdantcart-ai-reports'),
            __('Period Label', 'verdantcart-ai-reports'),
            __('Orders', 'verdantcart-ai-reports'),
            __('CO₂ (kg)', 'verdantcart-ai-reports'),
        ]);

        foreach ($labels as $index => $label) {
            $this->put_csv($out, [
                'Series',
                (string) $label,
                isset($orders_series[$index]) ? (int) $orders_series[$index] : 0,
                isset($co2_series[$index]) ? $this->format_decimal($co2_series[$index]) : '0.00',
            ]);
        }

        $this->put_csv($out, []);
    }

    private function write_user_kpis_csv($out, array $data): void
    {
        $total_co2     = (float) ($data['total_co2'] ?? 0);
        $orders        = (int) ($data['orders'] ?? 0);
        $co2_per_order = $orders > 0 ? ($total_co2 / $orders) : 0.0;
        $delta_numeric = isset($data['delta']) && $data['delta'] !== null
            ? round((float) $data['delta'], 1)
            : '';

        $this->put_csv($out, [
            __('Section', 'verdantcart-ai-reports'),
            __('Metric', 'verdantcart-ai-reports'),
            __('Value', 'verdantcart-ai-reports'),
        ]);

        $this->put_csv($out, ['KPI', __('Total CO₂ (kg)', 'verdantcart-ai-reports'), $this->format_decimal($total_co2)]);
        $this->put_csv($out, ['KPI', __('Orders Included', 'verdantcart-ai-reports'), $orders]);
        $this->put_csv($out, ['KPI', __('CO₂ per Order (kg)', 'verdantcart-ai-reports'), $this->format_decimal($co2_per_order)]);
        $this->put_csv($out, ['KPI', __('Total CO₂ vs Previous', 'verdantcart-ai-reports'), $delta_numeric]);

        $this->put_csv($out, []);
    }

    private function write_admin_kpis_csv($out, array $data, int $admin_id): void
    {
        $total_co2     = (float) ($data['total_co2'] ?? 0);
        $orders        = (int) ($data['orders'] ?? 0);
        $co2_per_order = isset($data['co2_per_order']) && is_numeric($data['co2_per_order'])
            ? (float) $data['co2_per_order']
            : 0.0;
        $delta_numeric = isset($data['delta']) && $data['delta'] !== null
            ? round((float) $data['delta'], 1)
            : '';

        $this->put_csv($out, [
            __('Section', 'verdantcart-ai-reports'),
            __('Field', 'verdantcart-ai-reports'),
            __('Value', 'verdantcart-ai-reports'),
        ]);

        $this->put_csv($out, ['Meta', __('Report Type', 'verdantcart-ai-reports'), __('All Customers Carbon Report', 'verdantcart-ai-reports')]);
        $this->put_csv($out, ['Meta', __('Exported By Admin ID', 'verdantcart-ai-reports'), $admin_id]);

        $this->put_csv($out, []);

        $this->put_csv($out, [
            __('Section', 'verdantcart-ai-reports'),
            __('Metric', 'verdantcart-ai-reports'),
            __('Value', 'verdantcart-ai-reports'),
        ]);

        $this->put_csv($out, ['KPI', __('Total CO₂ (kg)', 'verdantcart-ai-reports'), $this->format_decimal($total_co2)]);
        $this->put_csv($out, ['KPI', __('Orders Included', 'verdantcart-ai-reports'), $orders]);
        $this->put_csv($out, ['KPI', __('CO₂ per Order (kg)', 'verdantcart-ai-reports'), $this->format_decimal($co2_per_order)]);
        $this->put_csv($out, ['KPI', __('Total CO₂ vs Previous', 'verdantcart-ai-reports'), $delta_numeric]);

        $this->put_csv($out, []);
    }

    private function write_admin_rows_csv($out, array $rows_out): void
    {
        $this->put_csv($out, [
            __('Section', 'verdantcart-ai-reports'),
            __('User', 'verdantcart-ai-reports'),
            __('Orders', 'verdantcart-ai-reports'),
            __('Orders vs Previous %', 'verdantcart-ai-reports'),
            __('Total CO₂ (kg)', 'verdantcart-ai-reports'),
            __('CO₂ vs Previous %', 'verdantcart-ai-reports'),
            __('Updated', 'verdantcart-ai-reports'),
        ]);

        foreach ($rows_out as $row) {
            $orders_pct = isset($row['orders_pct_value']) && $row['orders_pct_value'] !== null
                ? round((float) $row['orders_pct_value'], 1)
                : '';

            $co2_pct = isset($row['co2_pct_value']) && $row['co2_pct_value'] !== null
                ? round((float) $row['co2_pct_value'], 1)
                : '';

            $this->put_csv($out, [
                'Customer',
                (string) ($row['user'] ?? ''),
                (int) ($row['orders'] ?? 0),
                $orders_pct,
                $this->format_decimal((float) ($row['co2'] ?? 0)),
                $co2_pct,
                (string) ($row['updated'] ?? ''),
            ]);
        }

        $this->put_csv($out, []);
    }

    // ------------------------------------------------------------
    // FRONTEND: User CSV
    // ------------------------------------------------------------

    public function export_user_csv(): void
    {
        $this->require_login();
        $this->rate_limit_or_die('export_user_csv', 6, 60);
        $this->verify_nonce_or_die('vcarb_export_user');

        $user_id = get_current_user_id();
        $this->deny_store_user_id($user_id);

        $view           = $this->current_request_view();
        $requested_date = $this->current_request_date();
        $anchor_date    = $this->require_export_snapshot_period_strict($view, $requested_date);
        $data           = $this->build_user_export_data($user_id, $view, $anchor_date);

        $this->audit_success('user', 'csv', $view, $requested_date, $anchor_date, 'user_export_csv', $user_id);

        $filename = $this->filename('verdantcart_user_report', $view, 'csv', $anchor_date);
        $this->send_csv_headers($filename);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening php://output stream for direct CSV download.
        $out = fopen('php://output', 'w');

        if ($out === false) {
            wp_die(
                esc_html__('Could not open export stream.', 'verdantcart-ai-reports'),
                esc_html__('Server error', 'verdantcart-ai-reports'),
                ['response' => 500]
            );
        }

        $this->start_csv_output($out);

        $this->put_csv($out, [__('VerdantCart — User Report', 'verdantcart-ai-reports')]);
        $this->put_csv($out, []);

        $this->write_csv_meta($out, $this->user_export_meta_rows($data));
        $this->write_user_kpis_csv($out, $data);
        $this->write_chart_series_csv($out, (array) ($data['chart'] ?? []));

        $this->put_csv($out, [
            __('Section', 'verdantcart-ai-reports'),
            __('Note', 'verdantcart-ai-reports'),
            __('Value', 'verdantcart-ai-reports'),
        ]);

        $this->put_csv($out, [
            'Note',
            __('Snapshot', 'verdantcart-ai-reports'),
            __('This export reflects the selected snapshot period only.', 'verdantcart-ai-reports'),
        ]);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output stream after direct CSV streaming.
        fclose($out);
        exit;
    }

    // ------------------------------------------------------------
    // FRONTEND: User print-HTML
    // ------------------------------------------------------------

    public function export_user_pdf(): void
    {
        $this->require_login();
        $this->rate_limit_or_die('export_user_pdf', 6, 60);
        $this->verify_nonce_or_die('vcarb_export_user');

        $user_id = get_current_user_id();
        $this->deny_store_user_id($user_id);

        $view           = $this->current_request_view();
        $requested_date = $this->current_request_date();
        $anchor_date    = $this->require_export_snapshot_period_strict($view, $requested_date);
        $data           = $this->build_user_export_data($user_id, $view, $anchor_date);

        $this->audit_success('user', 'pdf', $view, $requested_date, $anchor_date, 'user_export_pdf', $user_id);

        $filename = $this->filename('verdantcart_user_report', $view, 'html', $anchor_date);
        $this->send_html_headers($filename);

        $store_name      = get_bloginfo('name');
        $current_label   = $this->format_period_label($view, $anchor_date);
        $delta_value     = $data['delta'] ?? null;
        $delta_plain     = $this->percent_text($delta_value);
        $orders          = (int) ($data['orders'] ?? 0);
        $total_co2       = (float) ($data['total_co2'] ?? 0);
        $per_order_value = $orders > 0 ? ($total_co2 / $orders) : null;
        $meta_rows       = $this->user_export_meta_rows($data);
        $report_notes    = $this->build_report_notes(
            $total_co2,
            $orders,
            $per_order_value,
            $delta_value,
            $anchor_date,
            false
        );
?>
        <!doctype html>
        <html>

        <head>
            <meta charset="utf-8" />
            <title><?php echo esc_html__('VerdantCart — User Report', 'verdantcart-ai-reports'); ?></title>
        </head>

        <body>
            <p>
                <button type="button" onclick="window.print()"><?php echo esc_html__('Print / Save as PDF', 'verdantcart-ai-reports'); ?></button>
            </p>

            <h1><?php echo esc_html__('VerdantCart — User Report', 'verdantcart-ai-reports'); ?></h1>
            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s: store name. */
                        __('Store: %s', 'verdantcart-ai-reports'),
                        $store_name ?: __('Store', 'verdantcart-ai-reports')
                    )
                );
                ?>
            </p>
            <p><?php echo wp_kses_post($this->render_html_meta($meta_rows)); ?></p>

            <h2><?php echo esc_html__('Summary', 'verdantcart-ai-reports'); ?></h2>
            <table border="1" cellpadding="8" cellspacing="0">
                <tbody>
                    <tr>
                        <th align="left"><?php echo esc_html__('User ID', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html((string) $user_id); ?></td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('View', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html($view); ?></td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('Period', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html($current_label); ?></td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('Anchor Snapshot', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html($anchor_date); ?></td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('Total CO₂ Emissions', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html(number_format($total_co2, 2) . ' kg'); ?></td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('Orders Included', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html((string) $orders); ?></td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('CO₂ per Order', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html($per_order_value !== null ? number_format($per_order_value, 2) . ' kg' : '—'); ?></td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('Total CO₂ vs Previous', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html($delta_plain !== '' ? $delta_plain : '—'); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2><?php echo esc_html__('Report Notes', 'verdantcart-ai-reports'); ?></h2>
            <ul>
                <?php foreach ($report_notes as $note) : ?>
                    <li><?php echo esc_html($note); ?></li>
                <?php endforeach; ?>
            </ul>

            <h2><?php echo esc_html__('Methodology', 'verdantcart-ai-reports'); ?></h2>
            <p>
                <?php echo esc_html__(
                    'Carbon emissions are estimated based on completed WooCommerce orders during the selected reporting period. Results are intended for operational sustainability analysis rather than certified carbon accounting.',
                    'verdantcart-ai-reports'
                ); ?>
            </p>
        </body>

        </html>
    <?php
        exit;
    }

    // ------------------------------------------------------------
    // ADMIN: All Customers CSV
    // ------------------------------------------------------------

    public function export_admin_csv(): void
    {
        $this->require_login();
        $this->require_admin();
        $this->rate_limit_or_die('export_admin_csv', 4, 60);
        $this->verify_nonce_or_die('vcarb_export_admin');

        $admin_id       = get_current_user_id();
        $view           = $this->current_request_view();
        $requested_date = $this->current_request_date();
        $anchor_date    = $this->require_export_snapshot_period_strict($view, $requested_date);
        $data           = $this->build_admin_export_data($view, $anchor_date, $admin_id);
        $rows_out       = (array) ($data['rows'] ?? []);

        $this->audit_success(
            'admin',
            'csv',
            $view,
            $requested_date,
            $anchor_date,
            'admin_export_csv',
            $admin_id
        );

        $filename = $this->filename('verdantcart_all_customers', $view, 'csv', $anchor_date);
        $this->send_csv_headers($filename);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Opening php://output stream for direct CSV download.
        $out = fopen('php://output', 'w');

        if ($out === false) {
            wp_die(
                esc_html__('Could not open export stream.', 'verdantcart-ai-reports'),
                esc_html__('Server error', 'verdantcart-ai-reports'),
                ['response' => 500]
            );
        }

        $this->start_csv_output($out);

        $this->put_csv($out, [__('VerdantCart — All Customers Report', 'verdantcart-ai-reports')]);
        $this->put_csv($out, []);

        $this->write_csv_meta(
            $out,
            $this->admin_export_meta_rows(
                $view,
                $anchor_date,
                (string) ($data['snapshot_updated'] ?? '')
            )
        );

        $this->write_admin_kpis_csv($out, $data, $admin_id);
        $this->write_chart_series_csv($out, (array) ($data['chart'] ?? []));
        $this->write_admin_rows_csv($out, $rows_out);

        $this->put_csv($out, [
            __('Section', 'verdantcart-ai-reports'),
            __('Note', 'verdantcart-ai-reports'),
            __('Value', 'verdantcart-ai-reports'),
        ]);

        $this->put_csv($out, [
            'Note',
            __('Snapshot', 'verdantcart-ai-reports'),
            __('This export reflects the selected snapshot period only.', 'verdantcart-ai-reports'),
        ]);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://output stream after direct CSV streaming.
        fclose($out);
        exit;
    }

    // ------------------------------------------------------------
    // ADMIN: All Customers print-HTML
    // ------------------------------------------------------------

    public function export_admin_pdf(): void
    {
        $this->require_login();
        $this->require_admin();
        $this->rate_limit_or_die('export_admin_pdf', 4, 60);
        $this->verify_nonce_or_die('vcarb_export_admin');

        $admin_id         = get_current_user_id();
        $view             = $this->current_request_view();
        $requested_date   = $this->current_request_date();
        $anchor_date      = $this->require_export_snapshot_period_strict($view, $requested_date);
        $data             = $this->build_admin_export_data($view, $anchor_date, $admin_id);
        $rows_out         = (array) ($data['rows'] ?? []);
        $snapshot_updated = (string) ($data['snapshot_updated'] ?? '');
        $total_co2        = (float) ($data['total_co2'] ?? 0);
        $orders           = (int) ($data['orders'] ?? 0);
        $co2_per_order    = $data['co2_per_order'] ?? null;
        $delta            = $data['delta'] ?? null;

        $this->audit_success(
            'admin',
            'pdf',
            $view,
            $requested_date,
            $anchor_date,
            'admin_export_pdf',
            $admin_id
        );

        $filename = $this->filename('verdantcart_all_customers', $view, 'html', $anchor_date);
        $this->send_html_headers($filename);

        $store_name     = get_bloginfo('name');
        $period_label   = $this->format_period_label($view, $anchor_date);
        $delta_plain    = $this->percent_text($delta);
        $customer_count = count($rows_out);
        $meta_rows      = $this->admin_export_meta_rows($view, $anchor_date, $snapshot_updated);

        $report_notes = [
            sprintf(
                /* translators: %s: snapshot anchor period. */
                __('This export reflects snapshot period %s.', 'verdantcart-ai-reports'),
                $anchor_date
            ),
            sprintf(
                /* translators: 1: number of completed orders, 2: total emissions in kg CO2. */
                __('The report covers %1$d eligible orders with total emissions of %2$s kg CO₂.', 'verdantcart-ai-reports'),
                $orders,
                number_format($total_co2, 2)
            ),
            is_numeric($co2_per_order)
                ? sprintf(
                    /* translators: %s: average emissions per order in kg CO2. */
                    __('Average emissions intensity was %s kg CO₂ per order.', 'verdantcart-ai-reports'),
                    number_format((float) $co2_per_order, 2)
                )
                : __('Average emissions intensity could not be calculated for this snapshot.', 'verdantcart-ai-reports'),
            sprintf(
                /* translators: %d: number of customer rows in the report. */
                __('Customer breakdown includes %d row(s).', 'verdantcart-ai-reports'),
                $customer_count
            ),
        ];
    ?>
        <!doctype html>
        <html>

        <head>
            <meta charset="utf-8" />
            <title><?php echo esc_html__('VerdantCart — All Customers Report', 'verdantcart-ai-reports'); ?></title>
        </head>

        <body>
            <p>
                <button type="button" onclick="window.print()"><?php echo esc_html__('Print / Save as PDF', 'verdantcart-ai-reports'); ?></button>
            </p>

            <h1><?php echo esc_html__('VerdantCart — All Customers Report', 'verdantcart-ai-reports'); ?></h1>

            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s: store name. */
                        __('Store: %s', 'verdantcart-ai-reports'),
                        $store_name ?: __('Store', 'verdantcart-ai-reports')
                    )
                );
                ?>
            </p>

            <p><?php echo wp_kses_post($this->render_html_meta($meta_rows)); ?></p>

            <h2><?php echo esc_html__('Store Summary', 'verdantcart-ai-reports'); ?></h2>
            <table border="1" cellpadding="8" cellspacing="0">
                <tbody>
                    <tr>
                        <th align="left"><?php echo esc_html__('View', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html($view); ?></td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('Period', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html($period_label); ?></td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('Anchor Snapshot', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html($anchor_date); ?></td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('Total CO₂ (kg)', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html(number_format($total_co2, 2)); ?></td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('Orders Included', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html((string) $orders); ?></td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('CO₂ per Order (kg)', 'verdantcart-ai-reports'); ?></th>
                        <td>
                            <?php
                            echo esc_html(
                                is_numeric($co2_per_order)
                                    ? number_format((float) $co2_per_order, 2)
                                    : '—'
                            );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('Total CO₂ vs Previous', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html($delta_plain !== '' ? $delta_plain : '—'); ?></td>
                    </tr>
                    <tr>
                        <th align="left"><?php echo esc_html__('Snapshot Updated', 'verdantcart-ai-reports'); ?></th>
                        <td><?php echo esc_html($snapshot_updated !== '' ? $snapshot_updated : '—'); ?></td>
                    </tr>
                </tbody>
            </table>

            <h2><?php echo esc_html__('Customer Breakdown', 'verdantcart-ai-reports'); ?></h2>
            <table border="1" cellpadding="8" cellspacing="0">
                <thead>
                    <tr>
                        <th align="left"><?php echo esc_html__('User', 'verdantcart-ai-reports'); ?></th>
                        <th align="left"><?php echo esc_html__('Orders', 'verdantcart-ai-reports'); ?></th>
                        <th align="left"><?php echo esc_html__('Δ Orders', 'verdantcart-ai-reports'); ?></th>
                        <th align="left"><?php echo esc_html__('Total CO₂ (kg)', 'verdantcart-ai-reports'); ?></th>
                        <th align="left"><?php echo esc_html__('Δ CO₂', 'verdantcart-ai-reports'); ?></th>
                        <th align="left"><?php echo esc_html__('Updated', 'verdantcart-ai-reports'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows_out)) : ?>
                        <tr>
                            <td colspan="6"><?php echo esc_html__('No data found for this snapshot period.', 'verdantcart-ai-reports'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($rows_out as $row) : ?>
                            <tr>
                                <td><?php echo esc_html((string) ($row['user'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['orders'] ?? 0)); ?></td>
                                <td><?php echo esc_html((string) ($row['orders_pct'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['co2'] ?? '0.00')); ?></td>
                                <td><?php echo esc_html((string) ($row['co2_pct'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($row['updated'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2><?php echo esc_html__('Report Notes', 'verdantcart-ai-reports'); ?></h2>
            <ul>
                <?php foreach ($report_notes as $note) : ?>
                    <li><?php echo esc_html($note); ?></li>
                <?php endforeach; ?>
            </ul>

            <h2><?php echo esc_html__('Methodology', 'verdantcart-ai-reports'); ?></h2>
            <p>
                <?php echo esc_html__(
                    'Carbon emissions are estimated based on eligible WooCommerce orders during the selected reporting period. Store totals are calculated from the selected snapshot anchor, and customer-level rows reflect the values stored for that same reporting window. Results are intended for operational sustainability analysis rather than certified carbon accounting.',
                    'verdantcart-ai-reports'
                ); ?>
            </p>
        </body>

        </html>
<?php
        exit;
    }
}
