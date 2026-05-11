<?php
defined('ABSPATH') || exit;

class VCARB_Reports_Admin
{
  use VCARB_Snapshot_Trait;
  use VCARB_Period_Trait;

  private static bool $did_hooks = false;

  private const PAGE_SETTINGS               = 'verdantcart-carbon-reports';
  private const PAGE_SETTINGS_ALT           = 'vcarb-settings';
  private const PAGE_FRONT_DASHBOARD        = 'vcarb-front-dashboard';
  private const PAGE_ALL_CUSTOMERS          = 'vcarb-all-customers';
  private const PAGE_BACKFILL               = 'vcarb-backfill';
  private const PAGE_PUBLIC_HOME            = 'vcarb-open-home-page';
  private const PAGE_PUBLIC_PLANS           = 'vcarb-open-plans-page';
  private const PAGE_SUSTAINABILITY_SUMMARY = 'vcarb-sustainability-summary';

  /** @var array<int,string> */
  private const LEGACY_PAGES = [
    'amator-carbon-reports',
    'amatorcarbon-settings',
    'amatorcarbon-front-dashboard',
    'amatorcarbon-all-customers',
    'amatorcarbon-backfill',
    'amatorcarbon-open-home-page',
    'amatorcarbon-open-plans-page',
    'amatorcarbon-sustainability-summary',
  ];

  /** @var array<int,string> */
  private const ALLOWED_VIEWS = ['month', 'week', 'year'];

  public function __construct()
  {
    if (self::$did_hooks) {
      return;
    }

    self::$did_hooks = true;

    if (class_exists('VCARB_Plugin_Pages_Admin')) {
      VCARB_Plugin_Pages_Admin::init();
    }

    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('admin_init', [$this, 'protect_admin_pages']);
  }

  /* -------------------------------------------------------------------------
     * Admin page helpers
     * ---------------------------------------------------------------------- */

  private function get_admin_query_key(string $key, string $default = ''): string
  {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing/display parameter.
    if (!isset($_GET[$key]) || !is_scalar($_GET[$key])) {
      return $default;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing/display parameter.
    return sanitize_key(wp_unslash((string) $_GET[$key]));
  }

  private function get_admin_query_text(string $key, string $default = ''): string
  {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing/display parameter.
    if (!isset($_GET[$key]) || !is_scalar($_GET[$key])) {
      return $default;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page routing/display parameter.
    return sanitize_text_field(wp_unslash((string) $_GET[$key]));
  }

  private function normalize_view(string $view): string
  {
    $view = sanitize_key($view);

    return in_array($view, self::ALLOWED_VIEWS, true) ? $view : 'month';
  }

  private function render_plugin_page_header(string $title, string $subtitle = ''): void
  {
?>
    <div class="gc-page-brand">
      <div class="gc-page-brand__content">
        <h1 class="gc-page-brand__title"><?php echo esc_html($title); ?></h1>

        <?php if ($subtitle !== '') : ?>
          <p class="gc-page-brand__subtitle"><?php echo esc_html($subtitle); ?></p>
        <?php endif; ?>
      </div>
    </div>
  <?php
  }

  private function get_current_admin_page(): string
  {
    return $this->get_admin_query_key('page');
  }

  private function is_protected_admin_page(string $page): bool
  {
    return in_array(
      $page,
      array_merge(
        [
          self::PAGE_SETTINGS,
          self::PAGE_SETTINGS_ALT,
          self::PAGE_FRONT_DASHBOARD,
          self::PAGE_ALL_CUSTOMERS,
          self::PAGE_BACKFILL,
          self::PAGE_PUBLIC_HOME,
          self::PAGE_PUBLIC_PLANS,
          self::PAGE_SUSTAINABILITY_SUMMARY,
        ],
        self::LEGACY_PAGES
      ),
      true
    );
  }

  private function is_settings_page(string $page): bool
  {
    return in_array(
      $page,
      [
        self::PAGE_SETTINGS,
        self::PAGE_SETTINGS_ALT,
        'amator-carbon-reports',
        'amatorcarbon-settings',
      ],
      true
    );
  }

  private function is_all_customers_page(string $page): bool
  {
    return in_array(
      $page,
      [
        self::PAGE_ALL_CUSTOMERS,
        'amatorcarbon-all-customers',
      ],
      true
    );
  }

  private function is_backfill_page(string $page): bool
  {
    return in_array(
      $page,
      [
        self::PAGE_BACKFILL,
        'amatorcarbon-backfill',
      ],
      true
    );
  }

  private function is_sustainability_summary_page(string $page): bool
  {
    return in_array(
      $page,
      [
        self::PAGE_SUSTAINABILITY_SUMMARY,
        'amatorcarbon-sustainability-summary',
      ],
      true
    );
  }

  private function can_manage_reports(): bool
  {
    return current_user_can('manage_options');
  }

  /* -------------------------------------------------------------------------
     * Access protection
     * ---------------------------------------------------------------------- */

  public function protect_admin_pages(): void
  {
    if (!is_admin()) {
      return;
    }

    $page = $this->get_current_admin_page();

    if (!$this->is_protected_admin_page($page)) {
      return;
    }

    if (!is_user_logged_in()) {
      auth_redirect();
    }

    if (!$this->can_manage_reports()) {
      wp_die(
        esc_html__('You do not have permission to access this page.', 'verdantcart-ai-reports'),
        esc_html__('Forbidden', 'verdantcart-ai-reports'),
        ['response' => 403]
      );
    }

    nocache_headers();
  }

  /* -------------------------------------------------------------------------
     * Asset loading
     * ---------------------------------------------------------------------- */

  public function enqueue_assets(string $hook): void
  {
    unset($hook);

    if (!is_admin()) {
      return;
    }

    $page = $this->get_current_admin_page();

    if (!$this->is_protected_admin_page($page)) {
      return;
    }

    $this->enqueue_admin_base_style();

    if ($this->is_all_customers_page($page)) {
      $this->enqueue_admin_page_style(
        'vcarb-admin-all-customers',
        [
          'admin/css/vcarb-admin-all-customers.css',
          'admin/css/verdantcart-admin-all-customers.css',
          'admin/css/amatorcarbon-admin-all-customers.css',
        ]
      );

      $this->enqueue_all_customers_assets();
      return;
    }

    if ($this->is_backfill_page($page)) {
      $this->enqueue_admin_page_style(
        'vcarb-admin-backfill',
        [
          'admin/css/vcarb-admin-backfill.css',
          'admin/css/verdantcart-admin-backfill.css',
          'admin/css/amatorcarbon-admin-backfill.css',
        ]
      );

      $this->enqueue_backfill_assets();
      return;
    }

    if ($this->is_settings_page($page)) {
      $this->enqueue_admin_page_style(
        'vcarb-admin-overview',
        [
          'admin/css/vcarb-admin-overview.css',
          'admin/css/verdantcart-admin-overview.css',
          'admin/css/amatorcarbon-admin-overview.css',
        ]
      );

      $this->enqueue_settings_assets();
      return;
    }

    if ($this->is_sustainability_summary_page($page)) {
      $this->enqueue_admin_page_style(
        'vcarb-admin-report',
        [
          'admin/css/vcarb-admin-report.css',
          'admin/css/verdantcart-admin-report.css',
          'admin/css/amatorcarbon-admin-report.css',
        ]
      );

      $this->enqueue_report_assets();
    }
  }

  /**
   * @param array<int,string> $relative_paths
   */
  private function first_existing_asset(array $relative_paths): string
  {
    foreach ($relative_paths as $rel) {
      $rel = ltrim((string) $rel, '/');

      if ($rel !== '' && file_exists(VCARB_PLUGIN_DIR . $rel)) {
        return $rel;
      }
    }

    return '';
  }

  private function enqueue_admin_base_style(): void
  {
    $rel = $this->first_existing_asset(
      [
        'admin/css/vcarb-admin-base.css',
        'admin/css/verdantcart-admin-base.css',
        'admin/css/amatorcarbon-admin-base.css',
      ]
    );

    if ($rel === '') {
      return;
    }

    wp_enqueue_style(
      'vcarb-admin-base',
      VCARB_PLUGIN_URL . $rel,
      [],
      (string) filemtime(VCARB_PLUGIN_DIR . $rel)
    );
  }

  /**
   * @param array<int,string> $relative_paths
   */
  private function enqueue_admin_page_style(string $handle, array $relative_paths): void
  {
    $rel = $this->first_existing_asset($relative_paths);

    if ($rel === '') {
      return;
    }

    wp_enqueue_style(
      $handle,
      VCARB_PLUGIN_URL . $rel,
      ['vcarb-admin-base'],
      (string) filemtime(VCARB_PLUGIN_DIR . $rel)
    );
  }

  private function enqueue_report_assets(): void
  {
    wp_enqueue_script('jquery');

    $js_rel = $this->first_existing_asset(
      [
        'admin/js/vcarb-admin-report.js',
        'admin/js/verdantcart-admin-report.js',
        'public/js/verdantcart-admin-report.js',
        'public/js/amatorcarbon-admin-report.js',
      ]
    );

    if ($js_rel === '') {
      return;
    }

    wp_enqueue_script(
      'vcarb-admin-report',
      VCARB_PLUGIN_URL . $js_rel,
      ['jquery'],
      (string) filemtime(VCARB_PLUGIN_DIR . $js_rel),
      true
    );
  }

  private function enqueue_all_customers_assets(): void
  {
    if (
      class_exists('VCARB_Insights_Renderer') &&
      method_exists('VCARB_Insights_Renderer', 'enqueue_assets')
    ) {
      VCARB_Insights_Renderer::enqueue_assets();
    }

    wp_enqueue_script('jquery');

    $deps = ['jquery'];

    $chart_rel = $this->first_existing_asset(
      [
        'public/vendor/chartjs/chart.umd.min.js',
      ]
    );

    if ($chart_rel !== '') {
      wp_enqueue_script(
        'vcarb-chartjs',
        VCARB_PLUGIN_URL . $chart_rel,
        [],
        (string) filemtime(VCARB_PLUGIN_DIR . $chart_rel),
        true
      );

      $deps[] = 'vcarb-chartjs';
    }

    $filters_rel = $this->first_existing_asset(
      [
        'public/js/vcarb-insights-filters.js',
        'public/js/verdantcart-insights-filters.js',
      ]
    );

    if ($filters_rel !== '') {
      wp_enqueue_script(
        'vcarb-insights-filters',
        VCARB_PLUGIN_URL . $filters_rel,
        ['jquery'],
        (string) filemtime(VCARB_PLUGIN_DIR . $filters_rel),
        true
      );

      $deps[] = 'vcarb-insights-filters';
    }

    $admin_rel = $this->first_existing_asset(
      [
        'public/js/vcarb-admin.js',
        'public/js/verdantcart-admin.js',
        'public/js/amatorcarbon-admin.js',
      ]
    );

    if ($admin_rel === '') {
      return;
    }

    wp_enqueue_script(
      'vcarb-admin',
      VCARB_PLUGIN_URL . $admin_rel,
      array_values(array_unique($deps)),
      (string) filemtime(VCARB_PLUGIN_DIR . $admin_rel),
      true
    );

    $config = $this->build_all_customers_js_config();

    wp_localize_script(
      'vcarb-admin',
      'vcarbChartAjax',
      $config
    );

    wp_add_inline_script(
      'vcarb-admin',
      'window.amatorcarbonChartAjax = window.amatorcarbonChartAjax || window.vcarbChartAjax;',
      'after'
    );
  }

  private function enqueue_backfill_assets(): void
  {
    wp_enqueue_script('jquery');

    $js_rel = $this->first_existing_asset(
      [
        'public/js/vcarb-backfill.js',
        'public/js/verdantcart-backfill.js',
        'public/js/amatorcarbon-backfill.js',
      ]
    );

    if ($js_rel === '') {
      return;
    }

    wp_enqueue_script(
      'vcarb-backfill',
      VCARB_PLUGIN_URL . $js_rel,
      ['jquery'],
      (string) filemtime(VCARB_PLUGIN_DIR . $js_rel),
      true
    );

    $config = [
      'ajaxurl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce('vcarb_backfill'),
      'actions' => [
        'start' => 'vcarb_backfill_start',
        'batch' => 'vcarb_backfill_batch',
        'stop'  => 'vcarb_backfill_stop',
      ],
    ];

    wp_localize_script(
      'vcarb-backfill',
      'vcarbBackfillAjax',
      $config
    );

    wp_add_inline_script(
      'vcarb-backfill',
      'window.amatorcarbonBackfillAjax = window.amatorcarbonBackfillAjax || window.vcarbBackfillAjax;',
      'after'
    );
  }

  private function enqueue_settings_assets(): void
  {
    wp_enqueue_script('jquery');

    $settings_rel = $this->first_existing_asset(
      [
        'admin/js/vcarb-settings.js',
        'admin/js/verdantcart-settings.js',
        'public/js/amatorcarbon-settings.js',
      ]
    );

    if ($settings_rel === '') {
      return;
    }

    wp_enqueue_script(
      'vcarb-settings',
      VCARB_PLUGIN_URL . $settings_rel,
      ['jquery'],
      (string) filemtime(VCARB_PLUGIN_DIR . $settings_rel),
      true
    );
  }

  private function build_all_customers_js_config(): array
  {
    $requested_view = $this->normalize_view($this->get_admin_query_key('view', 'month'));

    $requested_date = $this->get_admin_query_text('date', '');
    $requested_date = $this->sanitize_period_for_view_safe($requested_view, $requested_date);

    $initial_date = '';

    if ($requested_date !== '' && $this->store_snapshot_exists($requested_view, $requested_date)) {
      $initial_date = $requested_date;
    }

    if ($initial_date === '') {
      $initial_date = $this->latest_store_snapshot_period($requested_view);
    }

    $initial_has_snapshot = (
      $initial_date !== '' &&
      $this->store_snapshot_exists($requested_view, $initial_date)
    );

    $export_base = [
      'csv' => '',
      'pdf' => '',
    ];

    if (current_user_can('manage_options')) {
      $nonce = wp_create_nonce('vcarb_export_admin');

      $export_base['csv'] = add_query_arg(
        [
          'action'   => 'vcarb_export_admin_csv',
          '_wpnonce' => $nonce,
          'view'     => $requested_view,
          'date'     => $initial_date,
        ],
        admin_url('admin-post.php')
      );

      $export_base['pdf'] = add_query_arg(
        [
          'action'   => 'vcarb_export_admin_pdf',
          '_wpnonce' => $nonce,
          'view'     => $requested_view,
          'date'     => $initial_date,
        ],
        admin_url('admin-post.php')
      );
    }

    return [
      'ajaxurl'            => admin_url('admin-ajax.php'),
      'nonceReport'        => wp_create_nonce('vcarb_get_report'),
      'nonceHotspots'      => wp_create_nonce('vcarb_get_hotspots'),
      'nonceAdminInsights' => wp_create_nonce('vcarb_admin_insights'),
      'screen'             => 'all_customers',
      'initialView'        => $requested_view,
      'initialDate'        => $initial_date,
      'initialHasSnapshot' => $initial_has_snapshot,
      'exportBase'         => $export_base,
      'strings'            => [
        'loading'           => __('Loading…', 'verdantcart-ai-reports'),
        'noSnapshot'        => __('Snapshot missing', 'verdantcart-ai-reports'),
        'exportUnavailable' => __('Export is unavailable until a snapshot exists for this period.', 'verdantcart-ai-reports'),
      ],
    ];
  }

  /* -------------------------------------------------------------------------
     * Public/admin shortcut URLs
     * ---------------------------------------------------------------------- */

  public static function render_front_dashboard_menu_page(): void
  {
    try {
      if (!current_user_can('manage_options')) {
        wp_die(
          esc_html__('You do not have permission to access this page.', 'verdantcart-ai-reports'),
          esc_html__('Forbidden', 'verdantcart-ai-reports'),
          ['response' => 403]
        );
      }

      $dashboard_url = self::get_front_dashboard_url();

      if ($dashboard_url === '') {
        wp_die(
          esc_html__('Front dashboard page not found.', 'verdantcart-ai-reports'),
          esc_html__('Not found', 'verdantcart-ai-reports'),
          ['response' => 404]
        );
      }

      wp_safe_redirect($dashboard_url);
      exit;
    } catch (Throwable $e) {
      echo '<div class="wrap gc-wrap"><div class="notice notice-error"><p><strong>VerdantCart Carbon Reports:</strong> ' .
        esc_html($e->getMessage()) .
        '</p></div></div>';
    }
  }

  public static function open_public_homepage(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(
        esc_html__('You do not have permission to access this page.', 'verdantcart-ai-reports'),
        esc_html__('Forbidden', 'verdantcart-ai-reports'),
        ['response' => 403]
      );
    }

    wp_safe_redirect(self::get_public_home_url());
    exit;
  }

  public static function open_public_plans_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(
        esc_html__('You do not have permission to access this page.', 'verdantcart-ai-reports'),
        esc_html__('Forbidden', 'verdantcart-ai-reports'),
        ['response' => 403]
      );
    }

    wp_safe_redirect(self::get_public_plans_url());
    exit;
  }

  public static function render_welcome_box(): void
  {
  ?>
    <div class="gc-settings-card">
      <div class="gc-settings-card__head">
        <div>
          <h2 class="gc-settings-title"><?php echo esc_html__('Overview', 'verdantcart-ai-reports'); ?></h2>
          <p class="gc-muted" style="margin:6px 0 0;">
            <?php echo esc_html__('Use the reporting dashboard to review emissions data, open Backfill to rebuild historical reporting data, and use the front dashboard for customer-facing reporting.', 'verdantcart-ai-reports'); ?>
          </p>
        </div>
      </div>
    </div>
  <?php
  }

  private static function get_front_dashboard_url(): string
  {
    if (function_exists('vcarb_front_dashboard_url')) {
      $url = (string) vcarb_front_dashboard_url();

      if ($url !== '') {
        return $url;
      }
    }

    if (function_exists('amatorcarbon_front_dashboard_url')) {
      $url = (string) amatorcarbon_front_dashboard_url();

      if ($url !== '') {
        return $url;
      }
    }

    $page_id = (int) get_option('vcarb_dashboard_page_id', 0);

    if ($page_id <= 0) {
      $page_id = (int) get_option('amatorcarbon_dashboard_page_id', 0);
    }

    if ($page_id > 0) {
      $permalink = get_permalink($page_id);

      if (is_string($permalink) && $permalink !== '') {
        return $permalink;
      }
    }

    foreach (['verdantcart-dashboard', 'verdantcart-carbon-dashboard', 'amator-carbon-dashboard'] as $dashboard_slug) {
      $page = get_page_by_path($dashboard_slug, OBJECT, 'page');

      if ($page instanceof WP_Post && $page->post_status !== 'trash') {
        $permalink = get_permalink($page->ID);

        if (is_string($permalink) && $permalink !== '') {
          return $permalink;
        }
      }
    }

    return '';
  }

  private static function get_public_home_url(): string
  {
    $front_page_id = (int) get_option('page_on_front', 0);

    if ($front_page_id > 0) {
      $permalink = get_permalink($front_page_id);

      if (is_string($permalink) && $permalink !== '') {
        return $permalink;
      }
    }

    return home_url('/');
  }

  private static function get_public_plans_url(): string
  {
    $page = get_page_by_path('pricing', OBJECT, 'page');

    if ($page instanceof WP_Post && $page->post_status !== 'trash') {
      $permalink = get_permalink($page->ID);

      if (is_string($permalink) && $permalink !== '') {
        return $permalink;
      }
    }

    return home_url('/pricing/');
  }

  /* -------------------------------------------------------------------------
     * Sustainability summary page
     * ---------------------------------------------------------------------- */

  public function render_sustainability_summary_page(): void
  {
    if (!$this->can_manage_reports()) {
      wp_die(
        esc_html__('You do not have permission to access this page.', 'verdantcart-ai-reports'),
        esc_html__('Forbidden', 'verdantcart-ai-reports'),
        ['response' => 403]
      );
    }

    $view = $this->normalize_view($this->get_admin_query_key('view', 'month'));

    $base_url  = admin_url('admin.php?page=' . self::PAGE_SUSTAINABILITY_SUMMARY);
    $month_url = add_query_arg('view', 'month', $base_url);
    $week_url  = add_query_arg('view', 'week', $base_url);
    $year_url  = add_query_arg('view', 'year', $base_url);

  ?>
    <div class="wrap gc-wrap gc-report-page">
      <?php
      $this->render_plugin_page_header(
        __('Sustainability Report', 'verdantcart-ai-reports'),
        __('Snapshot-based sustainability reporting summary for the current store.', 'verdantcart-ai-reports')
      );
      ?>

      <div class="gc-overview-card gc-overview-card--wide">
        <div class="gc-report-toolbar">
          <div class="gc-report-tabs" aria-label="<?php echo esc_attr__('Report period', 'verdantcart-ai-reports'); ?>">
            <a class="button <?php echo esc_attr($view === 'month' ? 'button-primary' : 'button-secondary'); ?>" href="<?php echo esc_url($month_url); ?>">
              <?php echo esc_html__('Month', 'verdantcart-ai-reports'); ?>
            </a>

            <a class="button <?php echo esc_attr($view === 'week' ? 'button-primary' : 'button-secondary'); ?>" href="<?php echo esc_url($week_url); ?>">
              <?php echo esc_html__('Week', 'verdantcart-ai-reports'); ?>
            </a>

            <a class="button <?php echo esc_attr($view === 'year' ? 'button-primary' : 'button-secondary'); ?>" href="<?php echo esc_url($year_url); ?>">
              <?php echo esc_html__('Year', 'verdantcart-ai-reports'); ?>
            </a>
          </div>

          <button type="button" class="button button-primary gc-report-print" data-vcarb-print>
            <?php echo esc_html__('Print / Save PDF', 'verdantcart-ai-reports'); ?>
          </button>
        </div>

        <?php if (class_exists('VCARB_Sustainability_Summary')) : ?>
          <?php
          echo wp_kses_post(
            VCARB_Sustainability_Summary::render_admin_summary($view, '')
          );
          ?>
        <?php else : ?>
          <p class="gc-empty">
            <?php echo esc_html__('Sustainability Summary class is not available.', 'verdantcart-ai-reports'); ?>
          </p>
        <?php endif; ?>
      </div>
    </div>
  <?php
  }

  /* -------------------------------------------------------------------------
     * Settings page
     * ---------------------------------------------------------------------- */

  public static function render_settings_page(): void
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    $self = new self();

    $front_dashboard_url = self::get_front_dashboard_url();
    $reports_url         = admin_url('admin.php?page=' . self::PAGE_ALL_CUSTOMERS);
    $backfill_url        = admin_url('admin.php?page=' . self::PAGE_BACKFILL);
    $public_home_url     = self::get_public_home_url();
    $public_plans_url    = self::get_public_plans_url();

    $wc_ready        = class_exists('WooCommerce') || function_exists('WC');
    $dashboard_ready = ($front_dashboard_url !== '');

  ?>
    <div class="wrap gc-wrap gc-overview-page">
      <?php
      $self->render_plugin_page_header(
        __('VerdantCart Overview', 'verdantcart-ai-reports'),
        __('Carbon reporting and emissions insights for WooCommerce stores.', 'verdantcart-ai-reports')
      );
      ?>

      <div class="gc-overview-hero">
        <div class="gc-overview-hero__content">
          <h2 class="gc-overview-hero__title"><?php echo esc_html__('Welcome to VerdantCart', 'verdantcart-ai-reports'); ?></h2>
          <p class="gc-overview-hero__text">
            <?php echo esc_html__('Use this page as your control center for reporting, backfill, customer insights, and public store pages.', 'verdantcart-ai-reports'); ?>
          </p>
        </div>

        <div class="gc-overview-badges">
          <span class="gc-overview-badge <?php echo esc_attr($wc_ready ? 'is-ok' : 'is-muted'); ?>">
            <?php echo esc_html($wc_ready ? __('WooCommerce connected', 'verdantcart-ai-reports') : __('WooCommerce missing', 'verdantcart-ai-reports')); ?>
          </span>

          <span class="gc-overview-badge <?php echo esc_attr($dashboard_ready ? 'is-ok' : 'is-muted'); ?>">
            <?php echo esc_html($dashboard_ready ? __('Dashboard ready', 'verdantcart-ai-reports') : __('Dashboard page missing', 'verdantcart-ai-reports')); ?>
          </span>
        </div>
      </div>

      <div class="gc-overview-grid">
        <section class="gc-overview-card gc-overview-card--wide">
          <div class="gc-overview-card__head">
            <div>
              <h2 class="gc-overview-card__title"><?php echo esc_html__('Quick actions', 'verdantcart-ai-reports'); ?></h2>
              <p class="gc-overview-card__text">
                <?php echo esc_html__('Open the core tools you use most often.', 'verdantcart-ai-reports'); ?>
              </p>
            </div>
          </div>

          <div class="gc-action-grid">
            <a class="gc-action-tile gc-action-tile--primary" href="<?php echo esc_url($reports_url); ?>">
              <span class="gc-action-tile__eyebrow"><?php echo esc_html__('Reports', 'verdantcart-ai-reports'); ?></span>
              <strong class="gc-action-tile__title"><?php echo esc_html__('All Customers', 'verdantcart-ai-reports'); ?></strong>
              <span class="gc-action-tile__text"><?php echo esc_html__('View store-wide KPIs, charts, exports, and customer breakdown.', 'verdantcart-ai-reports'); ?></span>
            </a>

            <a class="gc-action-tile" href="<?php echo esc_url($backfill_url); ?>">
              <span class="gc-action-tile__eyebrow"><?php echo esc_html__('Maintenance', 'verdantcart-ai-reports'); ?></span>
              <strong class="gc-action-tile__title"><?php echo esc_html__('Run Backfill', 'verdantcart-ai-reports'); ?></strong>
              <span class="gc-action-tile__text"><?php echo esc_html__('Rebuild historical reporting data for existing WooCommerce orders.', 'verdantcart-ai-reports'); ?></span>
            </a>

            <?php if ($front_dashboard_url !== '') : ?>
              <a class="gc-action-tile" href="<?php echo esc_url($front_dashboard_url); ?>" target="_blank" rel="noopener noreferrer">
                <span class="gc-action-tile__eyebrow"><?php echo esc_html__('Customer view', 'verdantcart-ai-reports'); ?></span>
                <strong class="gc-action-tile__title"><?php echo esc_html__('Open Front Dashboard', 'verdantcart-ai-reports'); ?></strong>
                <span class="gc-action-tile__text"><?php echo esc_html__('Open the customer-facing carbon dashboard in a new tab.', 'verdantcart-ai-reports'); ?></span>
              </a>
            <?php else : ?>
              <div class="gc-action-tile gc-action-tile--disabled">
                <span class="gc-action-tile__eyebrow"><?php echo esc_html__('Customer view', 'verdantcart-ai-reports'); ?></span>
                <strong class="gc-action-tile__title"><?php echo esc_html__('Front Dashboard Unavailable', 'verdantcart-ai-reports'); ?></strong>
                <span class="gc-action-tile__text"><?php echo esc_html__('Create or reconnect the public dashboard page to enable this shortcut.', 'verdantcart-ai-reports'); ?></span>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="gc-overview-card">
          <div class="gc-overview-card__head">
            <div>
              <h2 class="gc-overview-card__title"><?php echo esc_html__('Store site', 'verdantcart-ai-reports'); ?></h2>
              <p class="gc-overview-card__text">
                <?php echo esc_html__('Open the public homepage of the WooCommerce store where this plugin is installed.', 'verdantcart-ai-reports'); ?>
              </p>
            </div>
          </div>

          <div class="gc-action-stack">
            <a class="gc-btn gc-btn--ghost" href="<?php echo esc_url($public_home_url); ?>" target="_blank" rel="noopener noreferrer">
              <?php echo esc_html__('Visit Store Homepage', 'verdantcart-ai-reports'); ?>
            </a>

            <a class="gc-btn gc-btn--ghost" href="<?php echo esc_url($public_plans_url); ?>" target="_blank" rel="noopener noreferrer">
              <?php echo esc_html__('View Plans & Services', 'verdantcart-ai-reports'); ?>
            </a>
          </div>

          <p class="gc-overview-note">
            <?php echo esc_html__('This opens the current store homepage. Marketing landing pages should live in your theme or main product website, not inside the WordPress.org plugin package.', 'verdantcart-ai-reports'); ?>
          </p>
        </section>

        <section class="gc-overview-card">
          <div class="gc-overview-card__head">
            <div>
              <h2 class="gc-overview-card__title"><?php echo esc_html__('System status', 'verdantcart-ai-reports'); ?></h2>
              <p class="gc-overview-card__text">
                <?php echo esc_html__('A quick view of the plugin environment and setup state.', 'verdantcart-ai-reports'); ?>
              </p>
            </div>
          </div>

          <ul class="gc-status-list">
            <li>
              <span><?php echo esc_html__('WooCommerce', 'verdantcart-ai-reports'); ?></span>
              <strong class="<?php echo esc_attr($wc_ready ? 'is-ok' : 'is-muted'); ?>">
                <?php echo esc_html($wc_ready ? __('Connected', 'verdantcart-ai-reports') : __('Missing', 'verdantcart-ai-reports')); ?>
              </strong>
            </li>

            <li>
              <span><?php echo esc_html__('Front dashboard page', 'verdantcart-ai-reports'); ?></span>
              <strong class="<?php echo esc_attr($dashboard_ready ? 'is-ok' : 'is-muted'); ?>">
                <?php echo esc_html($dashboard_ready ? __('Ready', 'verdantcart-ai-reports') : __('Not found', 'verdantcart-ai-reports')); ?>
              </strong>
            </li>
          </ul>
        </section>

        <section class="gc-overview-card gc-overview-card--wide">
          <div class="gc-overview-card__head">
            <div>
              <h2 class="gc-overview-card__title">
                <?php echo esc_html__('How reporting works', 'verdantcart-ai-reports'); ?>
              </h2>

              <p class="gc-overview-card__text">
                <?php echo esc_html__('A quick summary of how data flows through VerdantCart.', 'verdantcart-ai-reports'); ?>
              </p>
            </div>
          </div>

          <ul class="gc-note-list">
            <li><?php echo esc_html__('Estimated emissions are calculated from eligible WooCommerce order data.', 'verdantcart-ai-reports'); ?></li>
            <li><?php echo esc_html__('Reporting data is grouped into monthly, weekly, and yearly periods.', 'verdantcart-ai-reports'); ?></li>
            <li><?php echo esc_html__('Snapshots and aggregates are used for dashboards, trends, hotspots, and exports.', 'verdantcart-ai-reports'); ?></li>
            <li><?php echo esc_html__('If historical data is missing, use Backfill to rebuild it safely.', 'verdantcart-ai-reports'); ?></li>
          </ul>
        </section>
      </div>
    </div>
  <?php
  }

  /* -------------------------------------------------------------------------
     * Backfill page
     * ---------------------------------------------------------------------- */

  public function render_backfill_page(): void
  {
    if (!$this->can_manage_reports()) {
      wp_die(
        esc_html__('You do not have permission to access this page.', 'verdantcart-ai-reports'),
        esc_html__('Forbidden', 'verdantcart-ai-reports'),
        ['response' => 403]
      );
    }

  ?>
    <div class="wrap gc-wrap gc-backfill-wrap">
      <?php
      $this->render_plugin_page_header(
        __('VerdantCart - Backfill', 'verdantcart-ai-reports'),
        __('Rebuild historical carbon data for existing WooCommerce orders.', 'verdantcart-ai-reports')
      );
      ?>

      <div class="gc-card">
        <div class="gc-card__head">
          <h2 class="gc-card__title"><?php echo esc_html__('What this does', 'verdantcart-ai-reports'); ?></h2>
          <span class="gc-pill gc-pill--warn">⚠️ <?php echo esc_html__('Manual only', 'verdantcart-ai-reports'); ?></span>
        </div>

        <ul class="gc-bullets">
          <li><?php echo esc_html__('Never changes stored historical CO₂ values.', 'verdantcart-ai-reports'); ?></li>
          <li><?php echo esc_html__('Uses idempotency flags to prevent double counting.', 'verdantcart-ai-reports'); ?></li>
          <li><?php echo esc_html__('Runs in batches to avoid timeouts.', 'verdantcart-ai-reports'); ?></li>
        </ul>

        <div class="gc-callout gc-callout--warning">
          <strong><?php echo esc_html__('Manual only:', 'verdantcart-ai-reports'); ?></strong>
          <?php echo esc_html__('Nothing runs automatically. Click Start when you are ready.', 'verdantcart-ai-reports'); ?>
        </div>

        <div class="gc-options">
          <label class="gc-check">
            <input type="checkbox" id="gcBackfillIncludeCounted" checked />
            <span>
              <?php echo esc_html__('Fill missing store total and hotspots for already-counted orders', 'verdantcart-ai-reports'); ?>
              <span class="gc-muted">(<?php echo esc_html__('safe', 'verdantcart-ai-reports'); ?>)</span>
            </span>
          </label>
        </div>

        <div class="gc-actions">
          <button class="gc-btn gc-btn--primary" id="gcBackfillStart" type="button">
            ▶ <?php echo esc_html__('Start backfill', 'verdantcart-ai-reports'); ?>
          </button>

          <button class="gc-btn gc-btn--ghost" id="gcBackfillStop" type="button" disabled>
            ■ <?php echo esc_html__('Stop', 'verdantcart-ai-reports'); ?>
          </button>
        </div>
      </div>

      <div class="gc-card gc-status-card">
        <div class="gc-card__head">
          <h2 class="gc-card__title"><?php echo esc_html__('Status', 'verdantcart-ai-reports'); ?></h2>
          <div class="gc-muted"><?php echo esc_html__('Live progress will appear here.', 'verdantcart-ai-reports'); ?></div>
        </div>

        <div id="gcBackfillStatus" class="gc-status-box">
          <div class="gc-status-empty">
            <?php echo esc_html__('No backfill started yet.', 'verdantcart-ai-reports'); ?>
          </div>
        </div>
      </div>
    </div>
  <?php
  }

  /* -------------------------------------------------------------------------
     * All customers page
     * ---------------------------------------------------------------------- */

  public function render_all_customers_page(): void
  {
    if (!$this->can_manage_reports()) {
      wp_die(
        esc_html__('You do not have permission to access this page.', 'verdantcart-ai-reports'),
        esc_html__('Forbidden', 'verdantcart-ai-reports'),
        ['response' => 403]
      );
    }

    $requested_view = $this->normalize_view($this->get_admin_query_key('view', 'month'));

    $requested_date = $this->get_admin_query_text('date', '');
    $requested_date = $this->sanitize_period_for_view_safe($requested_view, $requested_date);

    $view = $requested_view;
    $date = '';

    if ($requested_date !== '' && $this->store_snapshot_exists($view, $requested_date)) {
      $date = $requested_date;
    }

    if ($date === '') {
      $date = $this->latest_store_snapshot_period($view);
    }

    $has_snapshot = (
      $date !== '' &&
      $this->store_snapshot_exists($view, $date)
    );

    $browser = [
      'selected' => '',
      'previous' => '',
      'next'     => '',
      'has_prev' => false,
      'has_next' => false,
    ];

    if ($has_snapshot) {
      $browser['selected'] = $date;
      $browser['previous'] = $this->get_previous_available_snapshot_period($view, $date);
      $browser['next']     = $this->get_next_available_snapshot_period($view, $date);
      $browser['has_prev'] = ($browser['previous'] !== '');
      $browser['has_next'] = ($browser['next'] !== '');
    }

    $base_url = admin_url('admin.php?page=' . self::PAGE_ALL_CUSTOMERS);

    $month_url = add_query_arg('view', 'month', $base_url);
    $week_url  = add_query_arg('view', 'week', $base_url);
    $year_url  = add_query_arg('view', 'year', $base_url);

    $prev_url = $browser['has_prev']
      ? add_query_arg(
        [
          'view' => $view,
          'date' => $browser['previous'],
        ],
        $base_url
      )
      : '';

    $next_url = $browser['has_next']
      ? add_query_arg(
        [
          'view' => $view,
          'date' => $browser['next'],
        ],
        $base_url
      )
      : '';

    $latest_url = add_query_arg(
      [
        'view' => $view,
      ],
      $base_url
    );

  ?>
    <div
      class="wrap gc-wrap gc-ui"
      data-view="<?php echo esc_attr($view); ?>"
      data-date="<?php echo esc_attr($date); ?>"
      data-has-snapshot="<?php echo esc_attr($has_snapshot ? '1' : '0'); ?>">
      <?php
      $this->render_plugin_page_header(
        __('VerdantCart — All Customers', 'verdantcart-ai-reports'),
        __('Store-wide carbon reporting, exports, and customer breakdown.', 'verdantcart-ai-reports')
      );
      ?>

      <div class="gc-dashboard gc-dashboard--admin">
        <div class="gc-header gc-topbar-saas">
          <div class="gc-header__left">
            <div class="gc-topbar-title">
              <h2 class="gc-title"><?php echo esc_html__('Store Overview', 'verdantcart-ai-reports'); ?></h2>

              <div class="gc-topbar-meta">
                <span
                  class="gc-snap-badge <?php echo esc_attr($has_snapshot ? 'is-ok' : 'is-missing'); ?>"
                  data-gc-snap-badge
                  data-has="<?php echo esc_attr($has_snapshot ? '1' : '0'); ?>"
                  data-period="<?php echo esc_attr($date); ?>"
                  data-updated="">
                  <?php
                  echo esc_html(
                    $has_snapshot
                      ? sprintf(
                        /* translators: %s: selected snapshot period. */
                        __('Snapshot: %s', 'verdantcart-ai-reports'),
                        $date
                      )
                      : __('Snapshot missing', 'verdantcart-ai-reports')
                  );
                  ?>
                </span>
              </div>
            </div>

            <h3 class="nav-tab-wrapper gc-tabs gc-tabs--saas">
              <a class="nav-tab gc-tab <?php echo esc_attr($view === 'month' ? 'nav-tab-active is-active' : ''); ?>" href="<?php echo esc_url($month_url); ?>" data-view="month">
                <?php echo esc_html__('Month', 'verdantcart-ai-reports'); ?>
              </a>

              <a class="nav-tab gc-tab <?php echo esc_attr($view === 'week' ? 'nav-tab-active is-active' : ''); ?>" href="<?php echo esc_url($week_url); ?>" data-view="week">
                <?php echo esc_html__('Week', 'verdantcart-ai-reports'); ?>
              </a>

              <a class="nav-tab gc-tab <?php echo esc_attr($view === 'year' ? 'nav-tab-active is-active' : ''); ?>" href="<?php echo esc_url($year_url); ?>" data-view="year">
                <?php echo esc_html__('Year', 'verdantcart-ai-reports'); ?>
              </a>
            </h3>

            <div class="gc-period-browser">
              <?php if ($browser['has_prev']) : ?>
                <a class="button button-secondary gc-period-browser__btn" href="<?php echo esc_url($prev_url); ?>" data-gc-period-nav="prev">
                  ← <?php echo esc_html__('Previous', 'verdantcart-ai-reports'); ?>
                </a>
              <?php else : ?>
                <span class="button button-secondary gc-period-browser__btn disabled" aria-disabled="true">
                  ← <?php echo esc_html__('Previous', 'verdantcart-ai-reports'); ?>
                </span>
              <?php endif; ?>

              <div class="gc-period-browser__current">
                <strong>
                  <?php
                  echo esc_html(
                    $date !== ''
                      ? sprintf(
                        /* translators: %s: selected report period. */
                        __('Selected: %s', 'verdantcart-ai-reports'),
                        $date
                      )
                      : __('No snapshot selected', 'verdantcart-ai-reports')
                  );
                  ?>
                </strong>

                <a class="button button-link" href="<?php echo esc_url($latest_url); ?>" data-gc-period-nav="current">
                  <?php echo esc_html__('Current', 'verdantcart-ai-reports'); ?>
                </a>
              </div>

              <?php if ($browser['has_next']) : ?>
                <a class="button button-secondary gc-period-browser__btn" href="<?php echo esc_url($next_url); ?>" data-gc-period-nav="next">
                  <?php echo esc_html__('Next', 'verdantcart-ai-reports'); ?> →
                </a>
              <?php else : ?>
                <span class="button button-secondary gc-period-browser__btn disabled" aria-disabled="true">
                  <?php echo esc_html__('Next', 'verdantcart-ai-reports'); ?> →
                </span>
              <?php endif; ?>
            </div>
          </div>

          <div class="gc-header__right">
            <div data-gc-admin-export></div>
          </div>
        </div>

        <div class="gc-kpis">
          <div class="gc-kpi">
            <div class="gc-kpi__label"><?php echo esc_html__('Total CO₂', 'verdantcart-ai-reports'); ?></div>
            <div class="gc-kpi__value" data-gc-kpi="co2">—</div>
            <div class="gc-kpi__sub"><?php echo esc_html__('Selected period total', 'verdantcart-ai-reports'); ?></div>
          </div>

          <div class="gc-kpi">
            <div class="gc-kpi__label"><?php echo esc_html__('Orders Included', 'verdantcart-ai-reports'); ?></div>
            <div class="gc-kpi__value" data-gc-kpi="orders">—</div>
            <div class="gc-kpi__sub"><?php echo esc_html__('Completed orders', 'verdantcart-ai-reports'); ?></div>
          </div>

          <div class="gc-kpi">
            <div class="gc-kpi__label"><?php echo esc_html__('CO₂ per Order', 'verdantcart-ai-reports'); ?></div>
            <div class="gc-kpi__value" data-gc-kpi="co2po">—</div>
            <div class="gc-kpi__sub"><?php echo esc_html__('Average intensity', 'verdantcart-ai-reports'); ?></div>
          </div>

          <div class="gc-kpi">
            <div class="gc-kpi__label"><?php echo esc_html__('Change vs Previous', 'verdantcart-ai-reports'); ?></div>
            <div class="gc-kpi__value" data-gc-kpi="delta">—</div>
            <div class="gc-kpi__sub"><?php echo esc_html__('Period trend', 'verdantcart-ai-reports'); ?></div>
          </div>
        </div>

        <div class="gc-section">
          <div class="gc-section__head">
            <div>
              <h2 class="gc-section__title"><?php echo esc_html__('Carbon emissions over time', 'verdantcart-ai-reports'); ?></h2>
              <p class="gc-muted gc-section__sub">
                <?php echo esc_html__('CO₂ (kg) and completed orders for the selected period.', 'verdantcart-ai-reports'); ?>
              </p>
            </div>
          </div>

          <div class="gc-panel gc-card-saas">
            <div class="gc-chart-wrap">
              <canvas id="gcCarbonChart"></canvas>
            </div>
          </div>
        </div>

        <div class="gc-section">
          <div class="gc-section__head">
            <div>
              <h2 class="gc-section__title"><?php echo esc_html__('Sustainability Insights', 'verdantcart-ai-reports'); ?></h2>
              <p class="gc-muted gc-section__sub">
                <?php echo esc_html__('Score, risks, positives, and recommendations based on the selected carbon snapshot.', 'verdantcart-ai-reports'); ?>
              </p>
            </div>
          </div>

          <div class="gc-panel gc-card-saas">
            <div id="gcAdminInsights">
              <p class="gc-empty"><?php echo esc_html__('Loading…', 'verdantcart-ai-reports'); ?></p>
            </div>
          </div>
        </div>

        <div class="gc-section">
          <div class="gc-section__head">
            <div>
              <h2 class="gc-section__title"><?php echo esc_html__('Emission hotspots', 'verdantcart-ai-reports'); ?></h2>
              <p class="gc-muted gc-section__sub">
                <?php echo esc_html__('Top products contributing to emissions.', 'verdantcart-ai-reports'); ?>
              </p>
            </div>
          </div>

          <div class="gc-panel gc-card-saas" id="gcHotspotsPanel">
            <div id="gcHotspotsBody">
              <p class="gc-empty"><?php echo esc_html__('Loading…', 'verdantcart-ai-reports'); ?></p>
            </div>
          </div>
        </div>

        <div class="gc-section">
          <div class="gc-section__head">
            <div>
              <h2 class="gc-section__title"><?php echo esc_html__('Customer breakdown', 'verdantcart-ai-reports'); ?></h2>
              <p class="gc-muted gc-section__sub">
                <?php echo esc_html__('Store total and per-customer totals for the selected period.', 'verdantcart-ai-reports'); ?>
              </p>
            </div>
          </div>

          <div class="gc-panel gc-card-saas">
            <table class="widefat striped gc-table">
              <thead>
                <tr>
                  <th><?php echo esc_html__('User', 'verdantcart-ai-reports'); ?></th>
                  <th><?php echo esc_html__('Orders', 'verdantcart-ai-reports'); ?></th>
                  <th><?php echo esc_html__('Δ Orders', 'verdantcart-ai-reports'); ?></th>
                  <th><?php echo esc_html__('Total CO₂', 'verdantcart-ai-reports'); ?></th>
                  <th><?php echo esc_html__('Δ CO₂', 'verdantcart-ai-reports'); ?></th>
                  <th><?php echo esc_html__('Updated', 'verdantcart-ai-reports'); ?></th>
                </tr>
              </thead>

              <tbody id="gcTableBody">
                <tr>
                  <td colspan="6" class="gc-empty">
                    <?php echo esc_html__('Loading…', 'verdantcart-ai-reports'); ?>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
<?php
  }
}
