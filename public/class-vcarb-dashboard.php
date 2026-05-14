<?php
defined('ABSPATH') || exit;

class VCARB_Dashboard
{
    use VCARB_Snapshot_Trait;
    use VCARB_Period_Trait;

    public const SHORTCODE_SHORT = 'vcarb_dashboard';
    public const SHORTCODE       = 'verdantcart_dashboard';
    public const ALT_SHORTCODE   = 'verdantcart_carbon_dashboard';

    public const AJAX_ACTION  = 'vcarb_dashboard_report';
    public const NONCE_ACTION = 'vcarb_dashboard';

    private const ALLOWED_VIEWS = ['month', 'week', 'year'];

    public function __construct()
    {
        /*
     * Main recommended shortcode:
     * [vcarb_dashboard]
     */
        add_shortcode(self::SHORTCODE_SHORT, [$this, 'render']);

        /*
     * VerdantCart aliases.
     */
        add_shortcode(self::SHORTCODE, [$this, 'render']);
        add_shortcode(self::ALT_SHORTCODE, [$this, 'render']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_dashboard_report']);
    }

    /* -------------------------------------------------------------------------
 * Assets
 * ---------------------------------------------------------------------- */

    public function enqueue_assets(): void
    {
        if (!$this->should_enqueue_assets()) {
            return;
        }

        $this->enqueue_dashboard_styles();
        $this->enqueue_dashboard_scripts();
        $this->localize_dashboard_script();
    }


    private function should_enqueue_assets(): bool
    {
        if (is_admin() || !is_singular()) {
            return false;
        }

        global $post;

        if ($post instanceof WP_Post) {
            $content = (string) $post->post_content;

            if (
                has_shortcode($content, self::SHORTCODE_SHORT) ||
                has_shortcode($content, self::SHORTCODE) ||
                has_shortcode($content, self::ALT_SHORTCODE)
            ) {
                return true;
            }
        }

        $dashboard_ids = [];

        if (class_exists('VCARB_Reports_Activator')) {
            $dashboard_ids[] = (int) get_option(VCARB_Reports_Activator::OPT_DASHBOARD_ID, 0);
        }

        $dashboard_ids[] = (int) get_option('vcarb_dashboard_page_id', 0);

        foreach (array_unique(array_filter($dashboard_ids)) as $dashboard_id) {
            if ($dashboard_id > 0 && is_page($dashboard_id)) {
                return true;
            }
        }

        return is_page('verdantcart-dashboard')
            || is_page('verdantcart-carbon-dashboard')
            || is_page('vcarb-dashboard')
            || is_page('vcarb-carbon-dashboard');
    }

    private function enqueue_dashboard_styles(): void
    {
        $dashboard_handle = 'vcarb-dashboard';
        $dashboard_rel    = 'public/css/verdantcart-dashboard.css';
        $dashboard_path   = VCARB_PLUGIN_DIR . $dashboard_rel;

        if (file_exists($dashboard_path)) {
            wp_enqueue_style(
                $dashboard_handle,
                VCARB_PLUGIN_URL . $dashboard_rel,
                [],
                (string) filemtime($dashboard_path)
            );

            wp_add_inline_style(
                $dashboard_handle,
                '.gc-tip{display:none!important;opacity:0!important;visibility:hidden!important;pointer-events:none!important;}'
            );
        }

        if (
            class_exists('VCARB_Insights_Renderer') &&
            method_exists('VCARB_Insights_Renderer', 'enqueue_assets')
        ) {
            VCARB_Insights_Renderer::enqueue_assets();
            return;
        }

        $insights_rel  = 'public/css/verdantcart-insights.css';
        $insights_path = VCARB_PLUGIN_DIR . $insights_rel;

        if (file_exists($insights_path)) {
            wp_enqueue_style(
                'vcarb-insights',
                VCARB_PLUGIN_URL . $insights_rel,
                [],
                (string) filemtime($insights_path)
            );
        }
    }

    private function enqueue_dashboard_scripts(): void
    {
        $chart_rel  = 'public/vendor/chartjs/chart.umd.min.js';
        $chart_path = VCARB_PLUGIN_DIR . $chart_rel;

        if (file_exists($chart_path)) {
            wp_enqueue_script(
                'vcarb-chartjs',
                VCARB_PLUGIN_URL . $chart_rel,
                [],
                (string) filemtime($chart_path),
                true
            );
        }

        $deps = ['jquery'];

        if (wp_script_is('vcarb-chartjs', 'enqueued') || wp_script_is('vcarb-chartjs', 'registered')) {
            $deps[] = 'vcarb-chartjs';
        }

        $filters_rel  = 'public/js/verdantcart-insights-filters.js';
        $filters_path = VCARB_PLUGIN_DIR . $filters_rel;

        if (file_exists($filters_path)) {
            wp_enqueue_script(
                'vcarb-insights-filters',
                VCARB_PLUGIN_URL . $filters_rel,
                ['jquery'],
                (string) filemtime($filters_path),
                true
            );

            $deps[] = 'vcarb-insights-filters';
        }

        $dashboard_rel  = 'public/js/verdantcart-dashboard.js';
        $dashboard_path = VCARB_PLUGIN_DIR . $dashboard_rel;

        if (!file_exists($dashboard_path)) {
            return;
        }

        wp_enqueue_script(
            'vcarb-dashboard',
            VCARB_PLUGIN_URL . $dashboard_rel,
            array_values(array_unique($deps)),
            (string) filemtime($dashboard_path),
            true
        );
    }

    private function localize_dashboard_script(): void
    {
        if (
            !wp_script_is('vcarb-dashboard', 'enqueued') &&
            !wp_script_is('vcarb-dashboard', 'registered')
        ) {
            return;
        }

        wp_localize_script('vcarb-dashboard', 'vcarbDashAjax', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'action'      => self::AJAX_ACTION,
            'nonce'       => wp_create_nonce(self::NONCE_ACTION),
            'upgradeUrl'  => '',
            'backfillUrl' => admin_url('admin.php?page=vcarb-backfill'),
            'strings'     => [
                'emptyExportMsg'    => __('Export is unavailable until data exists for this period.', 'verdantcart-ai-reports'),
                'emptyChartMsg'     => __('No emissions data for this period yet.', 'verdantcart-ai-reports'),
                'loadErrorMsg'      => __('Could not load dashboard data. Please try again.', 'verdantcart-ai-reports'),
                'networkErrorMsg'   => __('Network error. Please try again.', 'verdantcart-ai-reports'),
                'loadingChart'      => __('Loading emissions data…', 'verdantcart-ai-reports'),
                'loadingInsights'   => __('Loading insights…', 'verdantcart-ai-reports'),
                'exportCsv'         => __('Export CSV', 'verdantcart-ai-reports'),
                'exportPdf'         => __('Export PDF', 'verdantcart-ai-reports'),
                'all'               => __('All', 'verdantcart-ai-reports'),
                'positive'          => __('Positive', 'verdantcart-ai-reports'),
                'warnings'          => __('Warnings', 'verdantcart-ai-reports'),
                'risks'             => __('Risks', 'verdantcart-ai-reports'),
                'recommendations'   => __('Recommendations', 'verdantcart-ai-reports'),
                'noInsights'        => __('No insights available yet.', 'verdantcart-ai-reports'),
                'noRecommendations' => __('No recommendations yet for this period.', 'verdantcart-ai-reports'),
                'nothingDetected'   => __('Nothing detected for this period.', 'verdantcart-ai-reports'),
                'carbonHotspot'     => __('Carbon Hotspot', 'verdantcart-ai-reports'),
                'topProducts'       => __('Top emitting products for this period.', 'verdantcart-ai-reports'),
                'noData'            => __('No data', 'verdantcart-ai-reports'),
                'biggestDriver'     => __('Biggest driver:', 'verdantcart-ai-reports'),
                'productEmissions'  => __('of product emissions.', 'verdantcart-ai-reports'),
            ],
        ]);
    }

    /* -------------------------------------------------------------------------
     * Shared helpers
     * ---------------------------------------------------------------------- */

    private function normalize_view(string $view): string
    {
        $view = sanitize_key($view);

        return in_array($view, self::ALLOWED_VIEWS, true) ? $view : 'month';
    }

    private function has_dashboard_dependencies(): bool
    {
        return class_exists('VCARB_Access')
            && class_exists('VCARB_Calculator')
            && class_exists('VCARB_Dataset_Service');
    }

    private function require_dashboard_dependencies(): void
    {
        if (!$this->has_dashboard_dependencies()) {
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

    /* -------------------------------------------------------------------------
     * AJAX helpers
     * ---------------------------------------------------------------------- */

    private function get_post_string(string $key, string $default = ''): string
    {
        $key = sanitize_key($key);

        if ($key === '') {
            return $default;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified before this helper is used.
        if (!isset($_POST[$key])) {
            return $default;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is unslashed, type-checked, and sanitized immediately below.
        $value = wp_unslash($_POST[$key]);

        if (!is_scalar($value)) {
            return $default;
        }

        return sanitize_text_field((string) $value);
    }

    private function get_query_string(string $key, string $default = ''): string
    {
        $key = sanitize_key($key);

        if ($key === '') {
            return $default;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only dashboard routing/display parameter.
        if (!isset($_GET[$key])) {
            return $default;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is unslashed, type-checked, and sanitized immediately below.
        $value = wp_unslash($_GET[$key]);

        if (!is_scalar($value)) {
            return $default;
        }

        return sanitize_text_field((string) $value);
    }

    private function get_query_key(string $key, string $default = ''): string
    {
        $key = sanitize_key($key);

        if ($key === '') {
            return $default;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only dashboard routing/display parameter.
        if (!isset($_GET[$key])) {
            return $default;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is unslashed, type-checked, and sanitized immediately below.
        $value = wp_unslash($_GET[$key]);

        if (!is_scalar($value)) {
            return $default;
        }

        return sanitize_key((string) $value);
    }

    private function require_ajax_user(): int
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Unauthorized', 'verdantcart-ai-reports')], 401);
        }

        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            wp_send_json_error(['message' => __('Invalid user.', 'verdantcart-ai-reports')], 400);
        }

        return $user_id;
    }

    private function verify_ajax_nonce(): void
    {
        $nonce = $this->get_post_string('nonce', '');

        $valid = $nonce !== '' && wp_verify_nonce($nonce, self::NONCE_ACTION);

        if (!$valid) {
            wp_send_json_error(
                ['message' => __('Invalid nonce.', 'verdantcart-ai-reports')],
                403
            );
        }
    }

    private function rate_limit_ajax(int $user_id, string $bucket, int $limit, int $seconds): void
    {
        $key  = 'vcarb_dash_rl_' . sanitize_key($bucket) . '_' . absint($user_id);
        $hits = (int) get_transient($key);

        if ($hits >= $limit) {
            wp_send_json_error(['message' => __('Too many requests.', 'verdantcart-ai-reports')], 429);
        }

        set_transient($key, $hits + 1, $seconds);
    }

    public function ajax_dashboard_report(): void
    {
        $this->verify_ajax_nonce();

        $user_id = $this->require_ajax_user();

        $this->rate_limit_ajax($user_id, 'report', 10, 10);
        $this->require_dashboard_dependencies();

        $request = $this->get_dashboard_ajax_request_state($user_id);

        $dataset = $this->make_dataset_service()->build_user_dataset(
            $user_id,
            $request['view'],
            $request['raw_date']
        );

        if (!is_array($dataset)) {
            wp_send_json_error(
                ['message' => __('Invalid dashboard dataset.', 'verdantcart-ai-reports')],
                500
            );
        }

        $view         = isset($dataset['view']) ? (string) $dataset['view'] : $request['view'];
        $date         = isset($dataset['date']) ? (string) $dataset['date'] : '';
        $snapshot     = isset($dataset['snapshot']) && is_array($dataset['snapshot']) ? $dataset['snapshot'] : [];
        $has_snapshot = !empty($snapshot['has']);

        $dataset['browser'] = $this->build_period_browser_data(
            $view,
            $date,
            $this->current_page_url(),
            $has_snapshot
        );

        wp_send_json_success($dataset);
    }

    private function get_dashboard_ajax_request_state(int $user_id): array
    {
        $requested_view = $this->normalize_view($this->get_post_string('view', 'month'));

        return [
            'requested_view' => $requested_view,
            'view'           => VCARB_Access::normalize_view($requested_view, $user_id),
            'raw_date'       => $this->get_post_string('date', ''),
        ];
    }

    /* -------------------------------------------------------------------------
     * Render helpers
     * ---------------------------------------------------------------------- */

    private function current_page_url(): string
    {
        $url = get_permalink();

        if (is_string($url) && $url !== '') {
            return $url;
        }

        $referer = wp_get_referer();

        if (is_string($referer) && $referer !== '') {
            return remove_query_arg(
                [
                    'view',
                    'date',
                    '_wpnonce',
                    'action',
                ],
                $referer
            );
        }

        if (function_exists('vcarb_get_dashboard_url')) {
            $dashboard_url = vcarb_get_dashboard_url();

            if (is_string($dashboard_url) && $dashboard_url !== '') {
                return $dashboard_url;
            }
        }

        return (string) home_url('/');
    }

    private function render_login_required(): string
    {
        $login_url = wp_login_url($this->current_page_url());

        return sprintf(
            '<p>%s</p>',
            wp_kses(
                sprintf(
                    /* translators: %s: login link HTML. */
                    __('Please %s to view your carbon dashboard.', 'verdantcart-ai-reports'),
                    '<a href="' . esc_url($login_url) . '">' . esc_html__('log in', 'verdantcart-ai-reports') . '</a>'
                ),
                [
                    'a' => [
                        'href' => true,
                    ],
                ]
            )
        );
    }

    private function render_missing_classes(): string
    {
        return '<div class="gc-card gc-card--notice"><strong>VerdantCart Carbon Reports:</strong> ' .
            esc_html__('Missing required classes.', 'verdantcart-ai-reports') .
            '</div>';
    }

    private function get_period_label(string $view, string $date): string
    {
        $view = $this->normalize_view($view);

        if ($date !== '') {
            if ($view === 'month') {
                $ts = strtotime($date . '-01');

                if ($ts) {
                    return date_i18n('F Y', $ts);
                }
            }

            if ($view === 'week' && preg_match('/^(\d{4})-W(\d{2})$/', $date, $m)) {
                return sprintf(
                    /* translators: 1: ISO week number, 2: year. */
                    __('Week %1$s, %2$s', 'verdantcart-ai-reports'),
                    $m[2],
                    $m[1]
                );
            }

            if ($view === 'year') {
                return $date;
            }
        }

        if ($view === 'year') {
            return date_i18n('Y');
        }

        return date_i18n('F Y');
    }

    private function icon_svg(string $name): string
    {
        $attrs = 'class="gc-ico" aria-hidden="true" focusable="false" width="24" height="24" viewBox="0 0 24 24"';

        return match ($name) {
            'leaf'    => "<svg {$attrs}><path d='M20 4c-7 0-12 4-14 10-1 3 0 6 3 6 6 0 10-5 10-14Z' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/><path d='M6 20c2-4 6-7 10-9' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round'/></svg>",
            'cart'    => "<svg {$attrs}><path d='M6 6h15l-2 8H7L6 6Z' fill='none' stroke='currentColor' stroke-width='2' stroke-linejoin='round'/><path d='M6 6 5 3H2' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round'/><circle cx='8' cy='19' r='1.6' fill='currentColor'/><circle cx='18' cy='19' r='1.6' fill='currentColor'/></svg>",
            'trend'   => "<svg {$attrs}><path d='M4 16l6-6 4 4 6-8' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/><path d='M18 6h2v2' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/></svg>",
            'compare' => "<svg {$attrs}><path d='M7 7h10M7 12h10M7 17h10' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round'/><path d='M5 7h.01M5 12h.01M5 17h.01' fill='none' stroke='currentColor' stroke-width='3' stroke-linecap='round'/></svg>",
            'clock'   => "<svg {$attrs}><circle cx='12' cy='12' r='9' fill='none' stroke='currentColor' stroke-width='2'/><path d='M12 7v6l4 2' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/></svg>",
            default   => '',
        };
    }

    private function svg_allowed_html(): array
    {
        return [
            'svg' => [
                'class'       => true,
                'aria-hidden' => true,
                'focusable'   => true,
                'width'       => true,
                'height'      => true,
                'viewBox'     => true,
                'viewbox'     => true,
                'fill'        => true,
            ],
            'path' => [
                'd'               => true,
                'fill'            => true,
                'stroke'          => true,
                'stroke-width'    => true,
                'stroke-linecap'  => true,
                'stroke-linejoin' => true,
            ],
            'circle' => [
                'cx'   => true,
                'cy'   => true,
                'r'    => true,
                'fill' => true,
            ],
        ];
    }

    private function dashboard_allowed_html(): array
    {
        return array_merge(
            wp_kses_allowed_html('post'),
            $this->svg_allowed_html()
        );
    }

    private function normalize_dashboard_dataset(array $dataset, string $fallback_view): array
    {
        return [
            'view'     => isset($dataset['view']) ? (string) $dataset['view'] : $fallback_view,
            'date'     => isset($dataset['date']) ? (string) $dataset['date'] : '',
            'browser'  => isset($dataset['browser']) && is_array($dataset['browser']) ? $dataset['browser'] : [],
            'snapshot' => isset($dataset['snapshot']) && is_array($dataset['snapshot']) ? $dataset['snapshot'] : [],
            'metrics'  => isset($dataset['metrics']) && is_array($dataset['metrics']) ? $dataset['metrics'] : [],
            'chart'    => isset($dataset['chart']) && is_array($dataset['chart']) ? $dataset['chart'] : [],
            'hotspots' => isset($dataset['hotspots']) && is_array($dataset['hotspots']) ? $dataset['hotspots'] : [],
            'insights' => isset($dataset['insights']) && is_array($dataset['insights']) ? $dataset['insights'] : [],
            'score'    => isset($dataset['score']) && is_array($dataset['score']) ? $dataset['score'] : [],
            'notice'   => isset($dataset['notice']) ? (string) $dataset['notice'] : '',
        ];
    }

    private function get_dashboard_request_state(int $user_id): array
    {
        $requested_view = $this->normalize_view($this->get_query_key('view', 'month'));

        return [
            'requested_view' => $requested_view,
            'view'           => VCARB_Access::normalize_view($requested_view, $user_id),
            'raw_date'       => $this->get_query_string('date', ''),
        ];
    }

    private function get_user_export_urls(string $view, string $date): array
    {
        $view = $this->normalize_view($view);
        $date = $this->sanitize_period_for_view_safe($view, $date);

        $args = [
            'view' => $view,
        ];

        if ($date !== '') {
            $args['date'] = $date;
        }

        $csv_url = wp_nonce_url(
            add_query_arg(
                $args,
                admin_url('admin-post.php?action=vcarb_export_user_csv')
            ),
            'vcarb_export_user',
            '_wpnonce'
        );

        $pdf_url = wp_nonce_url(
            add_query_arg(
                $args,
                admin_url('admin-post.php?action=vcarb_export_user_pdf')
            ),
            'vcarb_export_user',
            '_wpnonce'
        );

        return [
            'csv' => $csv_url,
            'pdf' => $pdf_url,
        ];
    }

    private function build_period_browser_data(string $view, string $current_date, string $base_url, bool $has_snapshot): array
    {
        $view         = $this->normalize_view($view);
        $current_date = $this->sanitize_period_for_view_safe($view, $current_date);

        if (!$has_snapshot || $current_date === '') {
            return [
                'view'          => $view,
                'current_date'  => '',
                'current_label' => '',
                'is_latest'     => false,
                'prev_date'     => '',
                'next_date'     => '',
                'latest_date'   => '',
                'prev_url'      => '',
                'current_url'   => '',
                'latest_url'    => '',
                'next_url'      => '',
            ];
        }

        $browser = $this->get_snapshot_browser_data($view, $current_date);
        $latest  = $this->sanitize_period_for_view_safe(
            $view,
            (string) $this->latest_store_snapshot_period($view)
        );

        $prev_date = $this->sanitize_period_for_view_safe(
            $view,
            (string) ($browser['previous'] ?? '')
        );

        $next_date = $this->sanitize_period_for_view_safe(
            $view,
            (string) ($browser['next'] ?? '')
        );

        $current_url = ($latest !== '')
            ? add_query_arg(
                [
                    'view' => $view,
                    'date' => $latest,
                ],
                $base_url
            )
            : add_query_arg(
                [
                    'view' => $view,
                ],
                $base_url
            );

        $prev_url = ($prev_date !== '')
            ? add_query_arg(
                [
                    'view' => $view,
                    'date' => $prev_date,
                ],
                $base_url
            )
            : '';

        $next_url = ($next_date !== '')
            ? add_query_arg(
                [
                    'view' => $view,
                    'date' => $next_date,
                ],
                $base_url
            )
            : '';

        return [
            'view'          => $view,
            'current_date'  => $current_date,
            'current_label' => $this->get_period_label($view, $current_date),
            'is_latest'     => ($latest !== '' && $latest === $current_date),
            'prev_date'     => $prev_date,
            'next_date'     => $next_date,
            'latest_date'   => $latest,
            'prev_url'      => $prev_url,
            'current_url'   => $current_url,
            'latest_url'    => $current_url,
            'next_url'      => $next_url,
        ];
    }

    private function render_dashboard_notice(string $notice): string
    {
        if ($notice === '') {
            return '<div class="gc-dashboard-notice" aria-live="polite"></div>';
        }

        ob_start();
?>
        <div class="gc-dashboard-notice" aria-live="polite">
            <div class="gc-card gc-card--notice"><?php echo esc_html($notice); ?></div>
        </div>
    <?php

        return (string) ob_get_clean();
    }

    private function render_admin_overview_button(): string
    {
        if (!current_user_can('manage_options')) {
            return '';
        }

        ob_start();
    ?>
        <a class="gc-btn gc-btn--ghost"
            href="<?php echo esc_url(admin_url('admin.php?page=vcarb-all-customers')); ?>">
            <?php echo esc_html__('Overview', 'verdantcart-ai-reports'); ?>
        </a>
    <?php

        return (string) ob_get_clean();
    }

    private function render_export_actions(
        string $csv_url,
        string $pdf_url,
        string $view,
        string $date,
        bool $has_snapshot
    ): string {
        $csv_href = ($has_snapshot && $date !== '') ? $csv_url : '#';
        $pdf_href = ($has_snapshot && $date !== '') ? $pdf_url : '#';

        ob_start();
    ?>
        <div class="gc-export"
            data-gc-export
            data-gc-view="<?php echo esc_attr($view); ?>"
            data-gc-date="<?php echo esc_attr($date); ?>"
            data-gc-has-snapshot="<?php echo esc_attr($has_snapshot ? '1' : '0'); ?>"
            data-gc-snapshot-msg="<?php echo esc_attr__('Export is unavailable until a store snapshot exists for this period.', 'verdantcart-ai-reports'); ?>"
            data-gc-export-csv-base="<?php echo esc_url($csv_url ?: ''); ?>"
            data-gc-export-pdf-base="<?php echo esc_url($pdf_url ?: ''); ?>">

            <?php echo wp_kses_post($this->render_export_button($csv_href, $pdf_href, $has_snapshot)); ?>
        </div>
    <?php

        return (string) ob_get_clean();
    }

    private function render_export_button(string $csv_url, string $pdf_url, bool $enabled): string
    {
        $disabled_class = $enabled ? '' : ' is-disabled';

        ob_start();
    ?>
        <div class="gc-export__split" role="group" aria-label="<?php echo esc_attr__('Export', 'verdantcart-ai-reports'); ?>">
            <a class="gc-export__btn gc-export__btn--main<?php echo esc_attr($disabled_class); ?>"
                href="<?php echo esc_url($enabled ? $csv_url : '#'); ?>"
                data-default-format="csv"
                target="_blank"
                rel="noopener noreferrer"
                <?php if (!$enabled) : ?>
                aria-disabled="true"
                tabindex="-1"
                <?php endif; ?>>
                <span class="gc-export__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M12 3v10m0 0 4-4m-4 4-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M4 17v3h16v-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    </svg>
                </span>
                <span class="gc-export__label"><?php echo esc_html__('Export CSV', 'verdantcart-ai-reports'); ?></span>
            </a>

            <button class="gc-export__btn gc-export__btn--chev<?php echo esc_attr($disabled_class); ?>"
                type="button"
                aria-haspopup="true"
                aria-expanded="false"
                aria-label="<?php echo esc_attr__('Open export menu', 'verdantcart-ai-reports'); ?>"
                <?php disabled(!$enabled); ?>>
                <span class="gc-export__chev" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
            </button>
        </div>

        <div class="gc-export__menu" role="menu" aria-label="<?php echo esc_attr__('Export options', 'verdantcart-ai-reports'); ?>">
            <a role="menuitem"
                class="gc-export__item<?php echo esc_attr($disabled_class); ?>"
                data-format="csv"
                href="<?php echo esc_url($enabled ? $csv_url : '#'); ?>"
                target="_blank"
                rel="noopener noreferrer"
                <?php if (!$enabled) : ?>
                aria-disabled="true"
                tabindex="-1"
                <?php endif; ?>>
                <?php echo esc_html__('Export CSV', 'verdantcart-ai-reports'); ?>
            </a>

            <a role="menuitem"
                class="gc-export__item<?php echo esc_attr($disabled_class); ?>"
                data-format="pdf"
                href="<?php echo esc_url($enabled ? $pdf_url : '#'); ?>"
                target="_blank"
                rel="noopener noreferrer"
                <?php if (!$enabled) : ?>
                aria-disabled="true"
                tabindex="-1"
                <?php endif; ?>>
                <?php echo esc_html__('Export PDF', 'verdantcart-ai-reports'); ?>
            </a>
        </div>
    <?php

        return (string) ob_get_clean();
    }

    private function render_chart_panel(string $uid): string
    {
        $canvas_id = $uid . 'EmissionsChart';

        ob_start();
    ?>
        <div class="gc-chart" aria-busy="false">
            <span class="gc-chart__placeholder">
                <?php esc_html_e('Loading emissions data...', 'verdantcart-ai-reports'); ?>
            </span>
            <span class="gc-sr-only gc-chart__live" aria-live="polite"></span>
            <canvas id="<?php echo esc_attr($canvas_id); ?>" height="120"></canvas>
        </div>

        <p class="gc-muted gc-footnote">
            <?php esc_html_e('Calculations are based on completed orders during the selected period.', 'verdantcart-ai-reports'); ?>
        </p>
    <?php

        return (string) ob_get_clean();
    }

    private function chart_panel_allowed_html(): array
    {
        return [
            'div' => [
                'class'     => true,
                'id'        => true,
                'aria-busy' => true,
                'aria-live' => true,
            ],
            'span' => [
                'class'       => true,
                'id'          => true,
                'aria-hidden' => true,
                'aria-live'   => true,
            ],
            'canvas' => [
                'id'     => true,
                'class'  => true,
                'height' => true,
                'width'  => true,
            ],
            'p' => [
                'class' => true,
                'id'    => true,
            ],
        ];
    }

    private function render_empty_snapshot_state(): string
    {
        $can_manage = current_user_can('manage_options');

        ob_start();
    ?>
        <section class="gc-emptySnap" aria-label="<?php echo esc_attr__('No snapshot available', 'verdantcart-ai-reports'); ?>">
            <div class="gc-emptySnap__inner">
                <div class="gc-emptySnap__left">
                    <div class="gc-emptySnap__kicker"><?php echo esc_html__('SETUP', 'verdantcart-ai-reports'); ?></div>

                    <h3 class="gc-emptySnap__title">
                        <?php echo esc_html__('No reporting snapshot available yet', 'verdantcart-ai-reports'); ?>
                    </h3>

                    <p class="gc-emptySnap__sub">
                        <?php echo esc_html__('New completed WooCommerce orders will be tracked automatically after the plugin is active. If this store already has older completed orders, run Backfill once to build historical carbon reports.', 'verdantcart-ai-reports'); ?>
                    </p>

                    <ul class="gc-emptySnap__list">
                        <li><?php echo esc_html__('New completed orders are tracked automatically.', 'verdantcart-ai-reports'); ?></li>
                        <li><?php echo esc_html__('Existing old orders need Backfill one time.', 'verdantcart-ai-reports'); ?></li>
                        <li><?php echo esc_html__('Products need weight data, or a fallback weight filter, before emissions can be estimated.', 'verdantcart-ai-reports'); ?></li>
                    </ul>

                    <div class="gc-emptySnap__actions">
                        <?php if ($can_manage) : ?>
                            <a class="gc-btn gc-btn--primary"
                                href="<?php echo esc_url(admin_url('admin.php?page=vcarb-backfill')); ?>">
                                <?php echo esc_html__('Run Backfill', 'verdantcart-ai-reports'); ?>
                            </a>

                            <a class="gc-btn gc-btn--ghost"
                                href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order')); ?>">
                                <?php echo esc_html__('View Orders', 'verdantcart-ai-reports'); ?>
                            </a>
                        <?php else : ?>
                            <span class="gc-btn gc-btn--ghost is-disabled" aria-disabled="true">
                                <?php echo esc_html__('Ask the store admin to run Backfill', 'verdantcart-ai-reports'); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="gc-emptySnap__note">
                        <?php echo esc_html__('A snapshot is created when completed order emissions are saved for a period. Store snapshots use user_id=0 and power charts, exports, hotspots, and insights.', 'verdantcart-ai-reports'); ?>
                    </div>
                </div>

                <div class="gc-emptySnap__right" aria-hidden="true">
                    <div class="gc-emptySnap__card">
                        <div class="gc-emptySnap__cardTitle">
                            <?php echo esc_html__('For new stores', 'verdantcart-ai-reports'); ?>
                        </div>
                        <div class="gc-emptySnap__cardText">
                            <?php echo esc_html__('Create or receive a WooCommerce order, then move it to Processing or Completed. VerdantCart will calculate and store the reporting snapshot automatically.', 'verdantcart-ai-reports'); ?>
                        </div>
                    </div>

                    <div class="gc-emptySnap__card">
                        <div class="gc-emptySnap__cardTitle">
                            <?php echo esc_html__('For stores with old orders', 'verdantcart-ai-reports'); ?>
                        </div>
                        <div class="gc-emptySnap__cardText">
                            <?php echo esc_html__('Run Backfill once to process existing completed orders and create historical month, week, year, and product hotspot data.', 'verdantcart-ai-reports'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    <?php

        return (string) ob_get_clean();
    }

    private function render_hotspot_panel(array $hotspots): string
    {
        $hotspot_top   = !empty($hotspots) && is_array($hotspots[0]) ? $hotspots[0] : null;
        $hotspot_bars  = array_slice($hotspots, 0, 3);
        $hotspot_title = $hotspot_top ? (string) ($hotspot_top['product_name'] ?? '') : '';

        ob_start();
    ?>
        <section class="gc-panel gc-panel--hotspot" aria-label="<?php echo esc_attr__('Carbon hotspot', 'verdantcart-ai-reports'); ?>">
            <div class="gc-panel__head">
                <div>
                    <div class="gc-panel__kicker"><?php echo esc_html__('INSIGHTS', 'verdantcart-ai-reports'); ?></div>
                    <h3 class="gc-panel__title"><?php echo esc_html__('Carbon Hotspot', 'verdantcart-ai-reports'); ?></h3>
                    <p class="gc-panel__sub"><?php echo esc_html__('Top emitting products for this period.', 'verdantcart-ai-reports'); ?></p>
                </div>

                <?php if ($hotspot_title !== '') : ?>
                    <span class="gc-chip gc-chip--good"><?php echo esc_html($hotspot_title); ?></span>
                <?php else : ?>
                    <span class="gc-chip"><?php echo esc_html__('No data', 'verdantcart-ai-reports'); ?></span>
                <?php endif; ?>
            </div>

            <?php if (empty($hotspot_bars)) : ?>
                <div class="gc-panel__note">
                    <?php echo esc_html__('No product hotspots yet for this period. Add completed orders or run Backfill.', 'verdantcart-ai-reports'); ?>
                </div>
            <?php else : ?>
                <div class="gc-bars">
                    <?php foreach ($hotspot_bars as $i => $h) : ?>
                        <?php
                        if (!is_array($h)) {
                            continue;
                        }

                        $name       = (string) ($h['product_name'] ?? '');
                        $percent    = max(0.0, min(100.0, (float) ($h['percent'] ?? 0.0)));
                        $topPercent = max(0.0, min(100.0, (float) ($hotspot_top['percent'] ?? 0.0)));
                        ?>
                        <div class="gc-bar">
                            <div class="gc-bar__top">
                                <span><?php echo esc_html($name); ?></span>
                                <strong><?php echo esc_html(number_format_i18n($percent, 0)); ?>%</strong>
                            </div>
                            <div class="gc-bar__track">
                                <div class="gc-bar__fill <?php echo esc_attr($i > 0 ? 'gc-bar__fill--muted' : ''); ?>"
                                    style="<?php echo esc_attr('width:' . number_format($percent, 2, '.', '') . '%'); ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="gc-panel__note">
                    <?php echo esc_html__('Biggest driver:', 'verdantcart-ai-reports'); ?>
                    <strong><?php echo esc_html((string) ($hotspot_top['product_name'] ?? '')); ?></strong>
                    (<?php echo esc_html(number_format_i18n($topPercent, 0)); ?>% <?php echo esc_html__('of product emissions.', 'verdantcart-ai-reports'); ?>)
                </div>
            <?php endif; ?>
        </section>
    <?php

        return (string) ob_get_clean();
    }

    private function render_metrics_row(
        string $uid,
        string $view,
        float $total_co2,
        int $orders,
        $delta,
        string $updated
    ): string {
        ob_start();
    ?>
        <div class="gc-metrics">
            <div class="gc-metric gc-metric--primary">
                <div class="gc-metric__top">
                    <div class="gc-metric__label">
                        <?php echo wp_kses($this->icon_svg('leaf'), $this->svg_allowed_html()); ?>
                        <span><?php echo esc_html__('Total emissions', 'verdantcart-ai-reports'); ?></span>
                    </div>

                    <div class="gc-metric__chip"><?php echo esc_html(ucfirst($view)); ?></div>
                </div>

                <div class="gc-metric__value" id="<?php echo esc_attr($uid . 'MetricTotal'); ?>">
                    <?php echo esc_html(number_format_i18n($total_co2, 2)); ?>
                    <span class="gc-metric__unit"><?php echo esc_html__('kg CO₂', 'verdantcart-ai-reports'); ?></span>
                </div>
            </div>

            <div class="gc-metric">
                <div class="gc-metric__top">
                    <div class="gc-metric__label">
                        <?php echo wp_kses($this->icon_svg('cart'), $this->svg_allowed_html()); ?>
                        <span><?php echo esc_html__('Orders included', 'verdantcart-ai-reports'); ?></span>
                    </div>
                </div>

                <div class="gc-metric__value" id="<?php echo esc_attr($uid . 'MetricOrders'); ?>">
                    <?php echo esc_html(number_format_i18n($orders)); ?>
                </div>
            </div>

            <div class="gc-metric">
                <div class="gc-metric__top">
                    <div class="gc-metric__label">
                        <?php echo wp_kses($this->icon_svg('trend'), $this->svg_allowed_html()); ?>
                        <?php echo esc_html__('CO₂ change', 'verdantcart-ai-reports'); ?>
                    </div>
                </div>

                <div class="gc-metric__value gc-metric__delta" id="<?php echo esc_attr($uid . 'MetricDelta'); ?>">
                    <?php
                    echo wp_kses(
                        $this->format_carbon_delta_html($delta),
                        [
                            'span' => [
                                'class' => true,
                            ],
                        ]
                    );
                    ?>
                </div>
            </div>

            <div class="gc-metric">
                <div class="gc-metric__top">
                    <div class="gc-metric__label">
                        <?php echo wp_kses($this->icon_svg('clock'), $this->svg_allowed_html()); ?>
                        <span><?php echo esc_html__('Last updated', 'verdantcart-ai-reports'); ?></span>
                    </div>
                </div>

                <div class="gc-metric__value gc-metric__value--small" id="<?php echo esc_attr($uid . 'MetricUpdated'); ?>">
                    <?php echo esc_html($updated !== '' ? $updated : __('—', 'verdantcart-ai-reports')); ?>
                </div>
            </div>
        </div>
    <?php

        return (string) ob_get_clean();
    }

    private function format_carbon_delta_html($delta): string
    {
        if ($delta === null || $delta === '' || !is_numeric($delta)) {
            return '<span class="gc-neutral">—</span>';
        }

        $delta = (float) $delta;

        if (abs($delta) < 0.01) {
            return '<span class="gc-neutral">—</span>';
        }

        $value = esc_html(number_format_i18n(abs($delta), 1));

        if ($delta < 0) {
            return sprintf(
                '<span class="gc-carbon-good"><span class="gc-change-main">%s%%</span><span class="gc-change-word">%s</span></span>',
                $value,
                esc_html__('Lower', 'verdantcart-ai-reports')
            );
        }

        return sprintf(
            '<span class="gc-carbon-bad"><span class="gc-change-main">%s%%</span><span class="gc-change-word">%s</span></span>',
            $value,
            esc_html__('Higher', 'verdantcart-ai-reports')
        );
    }

    private function render_insights_panel(
        string $uid,
        array $insights,
        string $view,
        bool $has_snapshot
    ): string {
        ob_start();
    ?>
        <div class="gc-section gc-section--insights">
            <div id="<?php echo esc_attr($uid . 'UserInsights'); ?>">
                <?php
                if (
                    class_exists('VCARB_Insights_Renderer') &&
                    method_exists('VCARB_Insights_Renderer', 'render') &&
                    !empty($insights)
                ) {
                    echo wp_kses_post(
                        VCARB_Insights_Renderer::render($insights, [
                            'title'      => __('Sustainability Insights', 'verdantcart-ai-reports'),
                            'context'    => 'front',
                            'show_score' => false,
                        ])
                    );
                } else {
                    $empty_message = ($view === 'week' && !$has_snapshot)
                        ? __('Insights will appear after snapshot or backfill is available for this week.', 'verdantcart-ai-reports')
                        : __('No insights available yet.', 'verdantcart-ai-reports');

                    echo '<div class="gc-empty">' . esc_html($empty_message) . '</div>';
                }
                ?>
            </div>
        </div>
    <?php

        return (string) ob_get_clean();
    }

    private function score_label_from_value(int $score): string
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

    private function render_score_panel(array $score_data): string
    {
        $raw_score = null;

        if (isset($score_data['value']) && is_numeric($score_data['value'])) {
            $raw_score = $score_data['value'];
        } elseif (isset($score_data['score']) && is_numeric($score_data['score'])) {
            $raw_score = $score_data['score'];
        }

        $score_val     = ($raw_score === null) ? null : max(0, min(100, (int) round((float) $raw_score)));
        $score_display = ($score_val === null) ? '—' : (string) $score_val;
        $ring_p        = ($score_val === null) ? 0 : $score_val;

        $score_status = isset($score_data['label']) && is_string($score_data['label']) && trim($score_data['label']) !== ''
            ? trim((string) $score_data['label'])
            : (($score_val === null) ? __('—', 'verdantcart-ai-reports') : $this->score_label_from_value($score_val));

        $summary = isset($score_data['summary']) && is_string($score_data['summary'])
            ? trim((string) $score_data['summary'])
            : '';

        if ($summary === '') {
            $summary = __('Estimated score based on emissions trend, emissions per order, reporting history, and hotspot concentration.', 'verdantcart-ai-reports');
        }

        $delta_class = isset($score_data['delta_class']) ? sanitize_html_class((string) $score_data['delta_class']) : 'gc-neutral';
        $delta_text  = isset($score_data['delta_text']) ? (string) $score_data['delta_text'] : '<span class="gc-neutral">—</span>';

        ob_start();
    ?>
        <section class="gc-panel gc-panel--score" aria-label="<?php echo esc_attr__('Sustainability score', 'verdantcart-ai-reports'); ?>">
            <div class="gc-score">
                <div class="gc-score__ring" style="<?php echo esc_attr('--p:' . (int) $ring_p . ';'); ?>" aria-hidden="true">
                    <div class="gc-score__num"><?php echo esc_html($score_display); ?></div>
                    <div class="gc-score__label"><?php echo esc_html__('Score', 'verdantcart-ai-reports'); ?></div>
                </div>

                <div class="gc-score__meta">
                    <h3 class="gc-panel__title" style="margin:0;"><?php echo esc_html__('Sustainability Score', 'verdantcart-ai-reports'); ?></h3>
                    <p class="gc-panel__sub" style="margin:6px 0 0;">
                        <?php echo esc_html($summary); ?>
                    </p>

                    <div class="gc-score__row">
                        <span class="gc-score__status"><?php echo esc_html($score_status); ?></span>
                        <span class="gc-score__delta gc-score__delta--value">
                            <?php
                            echo wp_kses(
                                $delta_text,
                                [
                                    'span' => [
                                        'class' => true,
                                    ],
                                    'strong' => [],
                                    'em' => [],
                                ]
                            );
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </section>
    <?php

        return (string) ob_get_clean();
    }

    /* -------------------------------------------------------------------------
     * Shortcode render
     * ---------------------------------------------------------------------- */

    public function render($atts = [], $content = null): string
    {
        unset($atts, $content);

        if (!is_user_logged_in()) {
            return $this->render_login_required();
        }

        if (!$this->has_dashboard_dependencies()) {
            return $this->render_missing_classes();
        }

        $user_id  = get_current_user_id();
        $uid      = wp_unique_id('vcarb_');
        $base_url = $this->current_page_url();
        $request  = $this->get_dashboard_request_state($user_id);

        $dataset = $this->make_dataset_service()->build_user_dataset(
            $user_id,
            $request['view'],
            $request['raw_date']
        );

        if (!is_array($dataset)) {
            return '<div class="gc-card gc-card--notice">' .
                esc_html__('Invalid dashboard dataset.', 'verdantcart-ai-reports') .
                '</div>';
        }

        $dataset = $this->normalize_dashboard_dataset($dataset, $request['view']);

        $view     = (string) ($dataset['view'] ?? $request['view']);
        $date     = (string) ($dataset['date'] ?? '');
        $snapshot = is_array($dataset['snapshot'] ?? null) ? $dataset['snapshot'] : [];
        $metrics  = is_array($dataset['metrics'] ?? null) ? $dataset['metrics'] : [];
        $hotspots = is_array($dataset['hotspots'] ?? null) ? $dataset['hotspots'] : [];
        $insights = is_array($dataset['insights'] ?? null) ? $dataset['insights'] : [];
        $score    = is_array($dataset['score'] ?? null) ? $dataset['score'] : [];
        $notice   = (string) ($dataset['notice'] ?? '');

        $has_snapshot = !empty($snapshot['has']);
        $updated      = isset($metrics['updated']) ? (string) $metrics['updated'] : '';
        $total_co2    = isset($metrics['total_co2']) ? (float) $metrics['total_co2'] : 0.0;
        $orders       = isset($metrics['orders']) ? (int) $metrics['orders'] : 0;
        $delta        = $metrics['delta'] ?? null;

        $export_urls  = $this->get_user_export_urls($view, $date);
        $period_label = $this->get_period_label($view, $date);

        ob_start();
    ?>
        <div class="gc-ui"
            data-view="<?php echo esc_attr($view); ?>"
            data-date="<?php echo esc_attr($date); ?>"
            data-has-snapshot="<?php echo esc_attr($has_snapshot ? '1' : '0'); ?>"
            data-gc-instance="<?php echo esc_attr($uid); ?>">

            <div class="gc-shell">
                <?php
                echo wp_kses(
                    $this->render_dashboard_header(
                        $uid,
                        $view,
                        $base_url,
                        $period_label,
                        (string) $export_urls['csv'],
                        (string) $export_urls['pdf'],
                        $date,
                        $has_snapshot
                    ),
                    $this->dashboard_allowed_html()
                );
                ?>

                <?php echo wp_kses_post($this->render_dashboard_notice($notice)); ?>

                <?php
                if (!$has_snapshot) {
                    echo wp_kses_post($this->render_empty_snapshot_state());
                }
                ?>

                <?php echo wp_kses_post($this->render_score_panel($score)); ?>

                <?php
                echo wp_kses(
                    $this->render_metrics_row(
                        $uid,
                        $view,
                        $total_co2,
                        $orders,
                        $delta,
                        $updated
                    ),
                    $this->dashboard_allowed_html()
                );
                ?>

                <?php
                echo wp_kses(
                    $this->render_chart_panel($uid),
                    $this->chart_panel_allowed_html()
                );
                ?>

                <?php echo wp_kses_post($this->render_hotspot_panel($hotspots)); ?>

                <?php
                echo wp_kses_post(
                    $this->render_insights_panel(
                        $uid,
                        $insights,
                        $view,
                        $has_snapshot
                    )
                );
                ?>
            </div>
        </div>
    <?php

        return (string) ob_get_clean();
    }

    private function render_dashboard_header(
        string $uid,
        string $view,
        string $base_url,
        string $period_label,
        string $csv_url,
        string $pdf_url,
        string $date,
        bool $has_snapshot
    ): string {
        ob_start();
    ?>
        <div class="gc-top">
            <div class="gc-top__left">
                <div class="gc-kicker"><?php echo esc_html__('VERDANTCART CARBON REPORTS', 'verdantcart-ai-reports'); ?></div>

                <h2 class="gc-title">
                    <?php echo esc_html__('Carbon Dashboard', 'verdantcart-ai-reports'); ?>
                </h2>

                <p class="gc-subtitle">
                    <?php echo esc_html__('Measure emissions per period, compare trends, and share progress.', 'verdantcart-ai-reports'); ?>
                </p>

                <div class="gc-period-row">
                    <div class="gc-period">
                        📊 <?php echo esc_html($period_label); ?>
                    </div>

                    <?php
                    echo wp_kses_post(
                        $this->render_period_browser(
                            $view,
                            $date,
                            $base_url,
                            $has_snapshot
                        )
                    );
                    ?>
                </div>
            </div>

            <div class="gc-top__right">
                <nav class="gc-tabs"
                    role="tablist"
                    aria-label="<?php echo esc_attr__('Period', 'verdantcart-ai-reports'); ?>"
                    data-gc-instance="<?php echo esc_attr($uid); ?>">

                    <?php
                    echo wp_kses_post($this->render_period_tab($uid, 'month', $view, $base_url));
                    echo wp_kses_post($this->render_period_tab($uid, 'week', $view, $base_url));
                    echo wp_kses_post($this->render_period_tab($uid, 'year', $view, $base_url));
                    ?>
                </nav>

                <div class="gc-actions">
                    <?php echo wp_kses_post($this->render_admin_overview_button()); ?>
                    <?php echo wp_kses_post($this->render_export_actions($csv_url, $pdf_url, $view, $date, $has_snapshot)); ?>
                </div>
            </div>
        </div>
    <?php

        return (string) ob_get_clean();
    }

    private function render_period_browser(
        string $view,
        string $current_date,
        string $base_url,
        bool $has_snapshot
    ): string {
        if (!$has_snapshot || $current_date === '') {
            return '';
        }

        $view         = $this->normalize_view($view);
        $current_date = $this->sanitize_period_for_view_safe($view, $current_date);

        $browser     = $this->get_snapshot_browser_data($view, $current_date);
        $prev_date   = (string) ($browser['previous'] ?? '');
        $next_date   = (string) ($browser['next'] ?? '');
        $latest_date = $this->latest_store_snapshot_period($view);

        $prev_url = ($prev_date !== '')
            ? add_query_arg(
                [
                    'view' => $view,
                    'date' => $prev_date,
                ],
                $base_url
            )
            : '';

        $latest_url = ($latest_date !== '')
            ? add_query_arg(
                [
                    'view' => $view,
                    'date' => $latest_date,
                ],
                $base_url
            )
            : add_query_arg(
                [
                    'view' => $view,
                ],
                $base_url
            );

        $next_url = ($next_date !== '')
            ? add_query_arg(
                [
                    'view' => $view,
                    'date' => $next_date,
                ],
                $base_url
            )
            : '';

        ob_start();
    ?>
        <div class="gc-period-browser gc-period-browser--inline"
            aria-label="<?php echo esc_attr__('Historical period browsing', 'verdantcart-ai-reports'); ?>">

            <div class="gc-period-browser__actions">
                <?php if ($prev_url !== '') : ?>
                    <a class="gc-btn gc-btn--ghost gc-btn--sm"
                        href="<?php echo esc_url($prev_url); ?>"
                        data-gc-period-nav="prev">
                        ← <?php echo esc_html__('Previous', 'verdantcart-ai-reports'); ?>
                    </a>
                <?php else : ?>
                    <span class="gc-btn gc-btn--ghost gc-btn--sm is-disabled" aria-disabled="true">
                        ← <?php echo esc_html__('Previous', 'verdantcart-ai-reports'); ?>
                    </span>
                <?php endif; ?>

                <a class="gc-btn gc-btn--ghost gc-btn--sm"
                    href="<?php echo esc_url($latest_url); ?>"
                    data-gc-period-nav="current">
                    <?php echo esc_html__('Current', 'verdantcart-ai-reports'); ?>
                </a>

                <?php if ($next_url !== '') : ?>
                    <a class="gc-btn gc-btn--ghost gc-btn--sm"
                        href="<?php echo esc_url($next_url); ?>"
                        data-gc-period-nav="next">
                        <?php echo esc_html__('Next', 'verdantcart-ai-reports'); ?> →
                    </a>
                <?php else : ?>
                    <span class="gc-btn gc-btn--ghost gc-btn--sm is-disabled" aria-disabled="true">
                        <?php echo esc_html__('Next', 'verdantcart-ai-reports'); ?> →
                    </span>
                <?php endif; ?>
            </div>
        </div>
    <?php

        return (string) ob_get_clean();
    }

    private function render_period_tab(
        string $uid,
        string $tab_view,
        string $active_view,
        string $base_url
    ): string {
        $tab_view = $this->normalize_view($tab_view);

        $label_map = [
            'month' => __('Month', 'verdantcart-ai-reports'),
            'week'  => __('Week', 'verdantcart-ai-reports'),
            'year'  => __('Year', 'verdantcart-ai-reports'),
        ];

        $label = $label_map[$tab_view] ?? ucfirst($tab_view);

        ob_start();
    ?>
        <a id="<?php echo esc_attr($uid . 'tab-' . $tab_view); ?>"
            class="gc-tab <?php echo esc_attr(($active_view === $tab_view) ? 'is-active' : ''); ?>"
            href="<?php echo esc_url(add_query_arg('view', $tab_view, $base_url)); ?>"
            role="tab"
            aria-selected="<?php echo esc_attr(($active_view === $tab_view) ? 'true' : 'false'); ?>"
            data-view="<?php echo esc_attr($tab_view); ?>">
            <?php echo esc_html($label); ?>
        </a>
<?php

        return (string) ob_get_clean();
    }
}
