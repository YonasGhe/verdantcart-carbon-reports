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
  private const PAGE_SUSTAINABILITY_SUMMARY = 'vcarb-sustainability-summary';
  private const PAGE_ADVANCED = 'vcarb-advanced';

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
    add_action('admin_post_vcarb_dismiss_pro_hint', [$this, 'handle_pro_hint_dismiss']);

    // Dismiss handlers remain for existing installs, but promotional notices and
    // locked preview cards are no longer registered on normal admin screens.
    add_action('admin_post_vcarb_dismiss_launch_v141', [$this, 'handle_v141_launch_dismiss']);
    add_action('admin_post_vcarb_dismiss_launch_v150', [$this, 'handle_v150_launch_dismiss']);
  }

  /**
   * Dismissal meta key for the per-page extension discovery hint bar at the top
   * of admin screens. Stores the unix timestamp when the user dismissed,
   * so we can auto-reappear after the cooldown.
   */
  const PRO_HINT_DISMISS_META_KEY = 'vcarb_pro_hint_dismissed_at';

  /**
   * One-time launch announcement for VerdantCart AI extension v1.4.1.
   * Different from the persistent hint bar — this is a celebration notice
   * that auto-expires on LAUNCH_NOTICE_EXPIRY_DATE regardless of dismissal.
   * After expiry the notice never shows again, even to users who never
   * dismissed it. Designed to feel like a launch event, not an upsell.
   */
  const LAUNCH_NOTICE_DISMISS_META_KEY = 'vcarb_launch_v141_dismissed_at';

  /**
   * Hard expiry date for the v1.4.1 launch notice. After this date the
   * notice stops rendering for everyone — it's a launch celebration,
   * not a permanent ad. Format: YYYY-MM-DD (UTC).
   */
  const LAUNCH_NOTICE_EXPIRY_DATE = '2026-07-04';

  /**
   * Dismissal meta key for the v1.5.0 extension launch announcement. Separate
   * from the v1.4.1 key so each launch is its own one-time event — a user
   * who dismissed the v1.4.1 notice should still see the bigger extension news.
   */
  const PRO_PLUS_LAUNCH_NOTICE_DISMISS_META_KEY = 'vcarb_launch_v150_dismissed_at';

  /**
   * Hard expiry date for the v1.5.0 extension launch notice. Gives a 6-week
   * window for the announcement to land with all 500 free users — longer
   * than v1.4.1 because extension + SPS is a bigger story to communicate.
   * Format: YYYY-MM-DD (UTC).
   */
  const PRO_PLUS_LAUNCH_NOTICE_EXPIRY_DATE = '2026-08-15';

  /**
   * Number of seconds to keep the hint hidden after dismissal. After this
   * window, the hint re-appears once — gentle re-engagement for users who
   * weren't ready the first time but may have warmed up since.
   */
  const PRO_HINT_DISMISS_COOLDOWN = 30 * DAY_IN_SECONDS;

  /**
   * admin-post handler that persists the user's dismissal of the discovery
   * hint and redirects them back to the page they came from.
   *
   * Per-user (not site-wide) so each admin chooses for themselves. CSRF-safe
   * via nonce. Permission-checked against manage_options.
   *
   * @return void
   */
  public function handle_pro_hint_dismiss(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(
        esc_html__('Permission denied.', 'verdantcart-ai-reports'),
        esc_html__('Forbidden', 'verdantcart-ai-reports'),
        ['response' => 403]
      );
    }

    check_admin_referer('vcarb_dismiss_pro_hint');

    $user_id = get_current_user_id();
    if ($user_id > 0) {
      update_user_meta($user_id, self::PRO_HINT_DISMISS_META_KEY, time());
    }

    $referer = wp_get_referer();
    if (false !== $referer && '' !== $referer) {
      wp_safe_redirect($referer);
      exit;
    }

    wp_safe_redirect(admin_url('admin.php?page=verdantcart-carbon-reports'));
    exit;
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

  private function icon_svg(string $name): string
  {
    $attrs = 'class="gc-kpi__icon-svg" aria-hidden="true" focusable="false" width="24" height="24" viewBox="0 0 24 24"';

    return match ($name) {
      'leaf'    => "<svg {$attrs}><path d='M20 4c-7 0-12 4-14 10-1 3 0 6 3 6 6 0 10-5 10-14Z' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/><path d='M6 20c2-4 6-7 10-9' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round'/></svg>",
      'cart'    => "<svg {$attrs}><path d='M6 6h15l-2 8H7L6 6Z' fill='none' stroke='currentColor' stroke-width='2' stroke-linejoin='round'/><path d='M6 6 5 3H2' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round'/><circle cx='8' cy='19' r='1.6' fill='currentColor'/><circle cx='18' cy='19' r='1.6' fill='currentColor'/></svg>",
      'trend'   => "<svg {$attrs}><path d='M4 16l6-6 4 4 6-8' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/><path d='M18 6h2v2' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/></svg>",
      'compare' => "<svg {$attrs}><path d='M7 7h10M7 12h10M7 17h10' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round'/><path d='M5 7h.01M5 12h.01M5 17h.01' fill='none' stroke='currentColor' stroke-width='3' stroke-linecap='round'/></svg>",
      default   => '',
    };
  }

  private static function admin_svg_allowed_html(): array
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

  private function render_plugin_page_header(string $title, string $subtitle = ''): void
  {
    $icon_rel  = 'frontend/assets/images/verdantcart-plugin-icon-site.png';
    $icon_path = VCARB_PLUGIN_DIR . $icon_rel;
    $icon_url  = VCARB_PLUGIN_URL . $icon_rel;

?>
    <div class="gc-page-brand">
      <?php if (file_exists($icon_path)) : ?>
        <img
          src="<?php echo esc_url($icon_url); ?>"
          alt="<?php echo esc_attr__('VerdantCart Carbon Reports', 'verdantcart-ai-reports'); ?>"
          class="gc-page-brand__logo"
          width="64"
          height="64">
      <?php endif; ?>

      <div class="gc-page-brand__content">
        <h1 class="gc-page-brand__title"><?php echo esc_html($title); ?></h1>

        <?php if ($subtitle !== '') : ?>
          <p class="gc-page-brand__subtitle"><?php echo esc_html($subtitle); ?></p>
        <?php endif; ?>
      </div>
    </div>
  <?php
  }

  /**
   * Render a subtle Pro-discovery hint at the top of every plugin admin page.
   *
   * Only shown when Pro isn't active. Gentle nudge — not a blocker, not a
   * popup, not a "you can't do anything until you buy" guilt-trip. Just a
   * one-line hint with the trial CTA so users always know Pro exists and
   * how to try it.
   *
   * The shape is intentionally narrow (max-width: full) and unobtrusive —
   * a thin green bar above the page brand. Skipping it entirely on the
   * Advanced page (which already has a big Pro upsell card) avoids duplicate
   * messaging on that single screen.
   *
   * @return void
   */
  private function render_pro_discovery_hint(): void
  {
    // Don't nag licensed users.
    if ($this->is_pro_active()) {
      return;
    }

    // Skip on the Advanced page — it has a big Pro card already.
    if (self::PAGE_ADVANCED === $this->get_current_admin_page()) {
      return;
    }

    // Respect the per-user dismissal until the cooldown window expires.
    if ($this->is_pro_hint_dismissed_recently()) {
      return;
    }

    $upgrade_url = add_query_arg(
      [
        'utm_source'   => 'verdantcart_free',
        'utm_medium'   => 'admin_top_hint',
        'utm_campaign' => 'pro_discovery_bar',
      ],
      'https://verdantcart.ai/pricing/'
    );

    $dismiss_url = wp_nonce_url(
      admin_url('admin-post.php?action=vcarb_dismiss_pro_hint'),
      'vcarb_dismiss_pro_hint'
    );

    ?>
    <div class="vcarb-pro-discovery-hint" style="margin:0 0 18px;padding:10px 16px;background:linear-gradient(135deg,#f0fdf4 0%,#dcfce7 100%);border:1px solid #bbf7d0;border-radius:8px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
      <div style="display:flex;align-items:center;gap:10px;color:#15803d;font-size:13px;line-height:1.4;flex:1;min-width:0;">
        <span style="font-size:18px;">🌱</span>
        <span>
          <strong><?php esc_html_e('Unlock executive summaries, branded PDFs, and scheduled reports', 'verdantcart-ai-reports'); ?></strong>
          —
          <?php esc_html_e('VerdantCart AI extension adds the premium reporting layer to this plugin.', 'verdantcart-ai-reports'); ?>
        </span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">
        <a href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener" style="background:#15803d;color:#fff;padding:6px 14px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;white-space:nowrap;">
          <?php esc_html_e('Try Pro free for 14 days →', 'verdantcart-ai-reports'); ?>
        </a>
        <a href="<?php echo esc_url($dismiss_url); ?>" class="vcarb-pro-discovery-hint__dismiss" title="<?php esc_attr_e('Dismiss for 30 days', 'verdantcart-ai-reports'); ?>" aria-label="<?php esc_attr_e('Dismiss this notice', 'verdantcart-ai-reports'); ?>" style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;color:#6b7280;text-decoration:none;font-size:20px;line-height:1;border-radius:4px;transition:background 0.15s;" onmouseover="this.style.background='#bbf7d0';this.style.color='#15803d';" onmouseout="this.style.background='transparent';this.style.color='#6b7280';">
          &times;
        </a>
      </div>
    </div>
  <?php
  }

  /**
   * One-time launch announcement for VerdantCart AI extension v1.4.1.
   *
   * Renders on EVERY wp-admin page (via the admin_notices hook) — not just
   * plugin pages — to maximize discovery for the ~342 existing free users.
   *
   * Auto-hides when ALL of these are true:
   *   - Pro is already active (don't announce a product they already have)
   *   - User dismissed the notice (per-user, never reappears)
   *   - The launch window has expired (LAUNCH_NOTICE_EXPIRY_DATE)
   *   - The current user is not an admin (manage_options)
   *
   * Visually distinct from the persistent extension discovery hint bar — bolder,
   * celebratory framing ("We just shipped") instead of upsell framing.
   *
   * @return void
   */
  public function render_v141_launch_notice(): void
  {
    return;
  }

  /**
   * admin-post handler that persists the user's dismissal of the v1.4.1
   * launch notice and redirects them back to the page they came from.
   *
   * Per-user (not site-wide) so each admin chooses for themselves. Unlike
   * the persistent extension hint bar, this dismissal is permanent — the launch
   * notice never reappears once dismissed (no cooldown).
   *
   * @return void
   */
  public function handle_v141_launch_dismiss(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(
        esc_html__('Permission denied.', 'verdantcart-ai-reports'),
        esc_html__('Forbidden', 'verdantcart-ai-reports'),
        ['response' => 403]
      );
    }

    check_admin_referer('vcarb_dismiss_launch_v141');

    $user_id = get_current_user_id();
    if ($user_id > 0) {
      update_user_meta($user_id, self::LAUNCH_NOTICE_DISMISS_META_KEY, time());
    }

    $referer = wp_get_referer();
    if (false !== $referer && '' !== $referer) {
      wp_safe_redirect($referer);
      exit;
    }

    wp_safe_redirect(admin_url('admin.php?page=verdantcart-carbon-reports'));
    exit;
  }

  /**
   * Whether the current user has dismissed the v1.4.1 launch notice.
   *
   * Permanent once dismissed (no cooldown) — the launch notice is a
   * one-time event, not a recurring upsell. Repeat impressions belong
   * to the persistent extension discovery hint bar.
   *
   * @return bool
   */
  private function is_launch_notice_dismissed(): bool
  {
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
      return false;
    }

    return (int) get_user_meta($user_id, self::LAUNCH_NOTICE_DISMISS_META_KEY, true) > 0;
  }

  /**
   * Whether the launch window has expired. After expiry the notice never
   * renders, regardless of dismissal state — old plugin versions stop
   * announcing a launch that already happened.
   *
   * @return bool
   */
  private function is_launch_notice_expired(): bool
  {
    $expiry_ts = strtotime(self::LAUNCH_NOTICE_EXPIRY_DATE . ' 23:59:59 UTC');
    if (false === $expiry_ts) {
      return false;
    }
    return time() > $expiry_ts;
  }

  /* -------------------------------------------------------------------
   * v1.5.0 extension launch notice — the big SPS reveal.
   *
   * Mirrors the v1.4.1 pattern but with sharper framing: this is
   * announcing a NEW TIER, not just a new release. The CTA goes to
   * verdantcart.ai/pricing where they can compare Pro vs extension.
   * ----------------------------------------------------------------- */

  /**
   * Render the one-time admin notice announcing extension + SPS.
   *
   * Visible on every wp-admin page until dismissed OR until the launch
   * window expires (PRO_PLUS_LAUNCH_NOTICE_EXPIRY_DATE).
   *
   * @return void
   */
  public function render_v150_pro_plus_launch_notice(): void
  {
    return;
  }

  /**
   * admin-post handler — persist extension launch notice dismissal per user.
   *
   * @return void
   */
  public function handle_v150_pro_plus_launch_dismiss(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(
        esc_html__('Permission denied.', 'verdantcart-ai-reports'),
        esc_html__('Forbidden', 'verdantcart-ai-reports'),
        ['response' => 403]
      );
    }

    check_admin_referer('vcarb_dismiss_launch_v150');

    $user_id = get_current_user_id();
    if ($user_id > 0) {
      update_user_meta($user_id, self::PRO_PLUS_LAUNCH_NOTICE_DISMISS_META_KEY, time());
    }

    $referer = wp_get_referer();
    if (false !== $referer && '' !== $referer) {
      wp_safe_redirect($referer);
      exit;
    }

    wp_safe_redirect(admin_url('admin.php?page=verdantcart-carbon-reports'));
    exit;
  }

  /**
   * Whether the current user has dismissed the v1.5.0 extension launch notice.
   *
   * @return bool
   */
  private function is_pro_plus_launch_notice_dismissed(): bool
  {
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
      return false;
    }

    return (int) get_user_meta($user_id, self::PRO_PLUS_LAUNCH_NOTICE_DISMISS_META_KEY, true) > 0;
  }

  /**
   * Whether the extension launch window has expired.
   *
   * @return bool
   */
  private function is_pro_plus_launch_notice_expired(): bool
  {
    $expiry_ts = strtotime(self::PRO_PLUS_LAUNCH_NOTICE_EXPIRY_DATE . ' 23:59:59 UTC');
    if (false === $expiry_ts) {
      return false;
    }
    return time() > $expiry_ts;
  }

  /**
   * Render the SPS teaser card — but only on the main overview page.
   *
   * Hooked into admin_notices because that hook fires inside the page
   * content area before page-specific output. Page-scoped so the teaser
   * appears where merchants actually browse their carbon data, not on
   * unrelated screens like Plugins or Posts.
   *
   * @return void
   */
  public function render_sps_teaser_on_overview(): void
  {
    if (!current_user_can('manage_options')) {
      return;
    }
    if ($this->is_pro_active()) {
      return;
    }

    // Only on the main reports overview page.
    $current_page = $this->get_current_admin_page();
    if (self::PAGE_SETTINGS !== $current_page && self::PAGE_SETTINGS_ALT !== $current_page) {
      return;
    }

    if (!class_exists('VCARB_Pro_Upsell')) {
      return;
    }

    VCARB_Pro_Upsell::instance()->render_sps_teaser_card();
  }

  /**
   * Whether the current user has dismissed the extension discovery hint within
   * the cooldown window.
   *
   * Returning true hides the hint. Once the cooldown elapses, returns false
   * again so the hint reappears (gentle re-engagement — users who weren't
   * ready may have warmed up after a month of using the free plugin).
   *
   * @return bool
   */
  private function is_pro_hint_dismissed_recently(): bool
  {
    $user_id = get_current_user_id();
    if ($user_id <= 0) {
      return false;
    }

    $dismissed_at = (int) get_user_meta($user_id, self::PRO_HINT_DISMISS_META_KEY, true);
    if ($dismissed_at <= 0) {
      return false;
    }

    return (time() - $dismissed_at) < self::PRO_HINT_DISMISS_COOLDOWN;
  }

  private function get_current_admin_page(): string
  {
    return $this->get_admin_query_key('page');
  }

  private function is_protected_admin_page(string $page): bool
  {
    return in_array(
      $page,
      [
        self::PAGE_SETTINGS,
        self::PAGE_SETTINGS_ALT,
        self::PAGE_FRONT_DASHBOARD,
        self::PAGE_ALL_CUSTOMERS,
        self::PAGE_BACKFILL,
        self::PAGE_SUSTAINABILITY_SUMMARY,
        self::PAGE_ADVANCED,
      ],
      true
    );
  }

  private function is_settings_page(string $page): bool
  {
    return self::PAGE_SETTINGS === $page
      || self::PAGE_SETTINGS_ALT === $page;
  }

  /**
   * Whether the VerdantCart AI extension plugin is installed AND the user is
   * actually entitled to Pro features (paying customer or active trial).
   *
   * Detection priority:
   *   1. Freemius integration via vap_fs() — most accurate, covers trials.
   *   2. New connected extension constants/classes (VCARTPRO_*).
   *   3. Legacy connected extension markers (VCARB_PRO_*) for backwards compatibility.
   *
   * @return bool
   */
  private function is_pro_active(): bool
  {
    // 1. Freemius — source of truth for paying/trialing users.
    if (function_exists('vap_fs')) {
      try {
        $fs = vap_fs();
        if (is_object($fs) && method_exists($fs, 'is_registered') && $fs->is_registered()) {
          if (method_exists($fs, 'can_use_premium_code') && $fs->can_use_premium_code()) {
            return true;
          }
          if (method_exists($fs, 'is_paying_or_trial') && $fs->is_paying_or_trial()) {
            return true;
          }
          if (method_exists($fs, 'is_trial') && $fs->is_trial()) {
            return true;
          }
          if (method_exists($fs, 'is_paying') && $fs->is_paying()) {
            return true;
          }
        }
      } catch (\Throwable $e) {
        // Fall through to other detection methods.
      }
    }

    // 2. New connected extension markers — these are defined by the connected extension's
    // main file as soon as it loads, so they're a fast positive signal.
    //
    // NOTE: The class name was misspelled as 'VCartPro_Settings' (camelCase)
    // through v1.4.x — it's actually 'VCARTPRO_Settings' (all caps). The
    // typo silently made this fallback always return false, which is why
    // Pro-active sites were sometimes seeing the upsell banners anyway.
    if (defined('VCARTPRO_VERSION') || class_exists('VCARTPRO_Settings')) {
      return true;
    }

    // 3. Active-plugin probe (casing-tolerant). Catches the case where Pro
    // is installed + activated but its main file hasn't loaded yet at this
    // hook point. Reuses the basename constants from VCARB_Pro_Upsell so we
    // don't drift between detection paths.
    if (class_exists('VCARB_Pro_Upsell')) {
      $candidates = [
        VCARB_Pro_Upsell::PRO_PLUGIN_BASENAME,        // verdantcart-ai-pro/...
        VCARB_Pro_Upsell::PRO_PLUGIN_BASENAME_LEGACY, // VerdantCart-ai-pro/...
      ];

      if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
      }

      foreach ($candidates as $candidate) {
        if (is_plugin_active($candidate)) {
          return true;
        }
      }
    }

    // 4. Legacy connected extension markers (pre-rebrand).
    return defined('VCARB_PRO_VERSION')
      || class_exists('VCARB_Pro');
  }

  private function is_all_customers_page(string $page): bool
  {
    return self::PAGE_ALL_CUSTOMERS === $page;
  }

  private function is_backfill_page(string $page): bool
  {
    return self::PAGE_BACKFILL === $page;
  }

  private function is_sustainability_summary_page(string $page): bool
  {
    return in_array(
      $page,
      [
        self::PAGE_SUSTAINABILITY_SUMMARY,
      ],
      true
    );
  }

  private function can_manage_reports(): bool
  {
    return current_user_can('manage_options');
  }

  private function has_any_store_snapshot(): bool
  {
    foreach (self::ALLOWED_VIEWS as $view) {
      if ($this->latest_store_snapshot_period($view) !== '') {
        return true;
      }
    }

    return false;
  }

  /**
   * @return array<string,mixed>
   */
  private function get_overview_snapshot_status(): array
  {
    $latest_period = $this->latest_store_snapshot_period('month');
    $row           = null;

    if ($latest_period !== '' && class_exists('VCARB_Calculator')) {
      $row = VCARB_Calculator::get_row(0, 'month', $latest_period);
    }

    $orders    = is_object($row) ? max(0, (int) ($row->orders ?? 0)) : 0;
    $total_co2 = is_object($row) ? max(0.0, (float) ($row->total_co2 ?? 0.0)) : 0.0;
    $updated   = ($latest_period !== '') ? $this->snapshot_updated_display('month', $latest_period) : '';

    return [
      'has_snapshot'  => ($latest_period !== ''),
      'has_orders'    => ($orders > 0),
      'latest_period' => $latest_period,
      'latest_label'  => $this->overview_period_label('month', $latest_period),
      'updated'       => $updated,
      'orders'        => $orders,
      'total_co2'     => $total_co2,
      'month_count'   => count($this->get_available_snapshot_periods('month', 120)),
      'week_count'    => count($this->get_available_snapshot_periods('week', 120)),
      'year_count'    => count($this->get_available_snapshot_periods('year', 120)),
    ];
  }

  private function overview_period_label(string $view, string $period): string
  {
    $view   = $this->sanitize_view($view);
    $period = $this->sanitize_period_for_view_safe($view, $period);

    if ($period === '') {
      return '';
    }

    if ($view === 'month') {
      $timestamp = strtotime($period . '-01 00:00:00');

      if ($timestamp) {
        return date_i18n('F Y', $timestamp);
      }
    }

    if ($view === 'week') {
      return sprintf(
        /* translators: %s: ISO week period, for example 2026-W31. */
        __('Week %s', 'verdantcart-ai-reports'),
        $period
      );
    }

    return $period;
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
          'admin/css/verdantcart-admin-all-customers.css',
        ]
      );

      $this->enqueue_all_customers_assets();
      return;
    }

    if ($this->is_backfill_page($page)) {
      $this->enqueue_admin_page_style(
        'vcarb-admin-backfill',
        [
          'admin/css/verdantcart-admin-backfill.css',
        ]
      );

      $this->enqueue_backfill_assets();
      return;
    }

    if ($this->is_settings_page($page)) {
      $this->enqueue_admin_page_style(
        'vcarb-admin-overview',
        [
          'admin/css/verdantcart-admin-overview.css',
        ]
      );

      $this->enqueue_settings_assets();
      return;
    }

    if ($this->is_sustainability_summary_page($page)) {
      $this->enqueue_admin_page_style(
        'vcarb-admin-report',
        [
          'admin/css/verdantcart-admin-report.css',
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
        'admin/css/verdantcart-admin-base.css',
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
        'admin/js/verdantcart-admin-report.js',
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
        'public/js/verdantcart-admin.js',
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
  }

  private function enqueue_backfill_assets(): void
  {
    wp_enqueue_script('jquery');

    $js_rel = $this->first_existing_asset(
      [
        'public/js/verdantcart-backfill.js',
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

    wp_localize_script(
      'vcarb-backfill',
      'vcarbBackfillAjax',
      [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('vcarb_backfill'),
        'actions' => [
          'start' => 'vcarb_backfill_start',
          'batch' => 'vcarb_backfill_batch',
          'stop'  => 'vcarb_backfill_stop',
        ],
      ]
    );
  }

  private function enqueue_settings_assets(): void
  {
    wp_enqueue_script('jquery');

    $settings_rel = $this->first_existing_asset(
      [
        'admin/js/vcarb-settings.js',
        'admin/js/verdantcart-settings.js',
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

  private static function get_front_dashboard_url(): string
  {
    if (function_exists('vcarb_front_dashboard_url')) {
      $url = (string) vcarb_front_dashboard_url();

      if ($url !== '') {
        return $url;
      }
    }

    $page_ids = [
      (int) get_option('vcarb_dashboard_page_id', 0),
    ];

    if (class_exists('VCARB_Reports_Activator')) {
      $page_ids[] = (int) get_option(VCARB_Reports_Activator::OPT_DASHBOARD_ID, 0);
    }

    foreach (array_unique(array_filter($page_ids)) as $page_id) {
      $page_id = absint($page_id);

      if ($page_id <= 0) {
        continue;
      }

      $post = get_post($page_id);

      if (!($post instanceof WP_Post) || $post->post_type !== 'page' || $post->post_status === 'trash') {
        continue;
      }

      $permalink = get_permalink($page_id);

      if (is_string($permalink) && $permalink !== '') {
        return $permalink;
      }
    }

    $dashboard_slugs = [
      'verdantcart-dashboard',
      'verdantcart-carbon-dashboard',
      'vcarb-dashboard',
      'vcarb-carbon-dashboard',
    ];

    if (class_exists('VCARB_Reports_Activator')) {
      $dashboard_slugs[] = VCARB_Reports_Activator::SLUG_DASHBOARD;
    }

    foreach (array_unique(array_filter(array_map('sanitize_title', $dashboard_slugs))) as $dashboard_slug) {
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
    return 'https://verdantcart.ai/';
  }

  private static function get_public_plans_url(): string
  {
    return 'https://verdantcart.ai/pricing/';
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
        __('VerdantCart — Sustainability Report', 'verdantcart-ai-reports'),
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

    $front_dashboard_url = self::get_front_dashboard_url();
    $reports_url         = admin_url('admin.php?page=' . self::PAGE_ALL_CUSTOMERS);
    $summary_url         = admin_url('admin.php?page=' . self::PAGE_SUSTAINABILITY_SUMMARY);
    $backfill_url        = admin_url('admin.php?page=' . self::PAGE_BACKFILL);
    $advanced_url        = 'https://verdantcart.ai/pricing/?utm_source=verdantcart_ai&utm_medium=landing&utm_campaign=pricing_click&utm_content=overview_explore_features';
    $official_site_url  = 'https://verdantcart.ai/';

    $wc_ready        = class_exists('WooCommerce') || function_exists('WC');
    $dashboard_ready = ($front_dashboard_url !== '');
  ?>
    <div class="wrap gc-wrap gc-overview-page">
      <?php
      (new self())->render_plugin_page_header(
        __('VerdantCart — Overview', 'verdantcart-ai-reports'),
        __('Carbon reporting and emissions insights for WooCommerce stores.', 'verdantcart-ai-reports')
      );
      ?>

      <div class="gc-overview-hero gc-overview-hero--welcome">
        <div class="gc-overview-hero__content">
          <h2 class="gc-overview-hero__title"><?php echo esc_html__('Turn store orders into clear sustainability reports', 'verdantcart-ai-reports'); ?></h2>
          <p class="gc-overview-hero__text">
            <?php echo esc_html__('VerdantCart helps you calculate estimated order emissions, review store and customer activity, export reports, and give buyers a simple carbon dashboard.', 'verdantcart-ai-reports'); ?>
          </p>
        </div>

        <div class="gc-overview-badges">
          <span class="gc-overview-badge <?php echo esc_attr($wc_ready ? 'is-ok' : 'is-muted'); ?>">
            <?php echo esc_html($wc_ready ? __('WooCommerce connected', 'verdantcart-ai-reports') : __('WooCommerce needed', 'verdantcart-ai-reports')); ?>
          </span>

          <span class="gc-overview-badge <?php echo esc_attr($dashboard_ready ? 'is-ok' : 'is-muted'); ?>">
            <?php echo esc_html($dashboard_ready ? __('Dashboard ready', 'verdantcart-ai-reports') : __('Dashboard page missing', 'verdantcart-ai-reports')); ?>
          </span>
        </div>
      </div>

      <section class="gc-overview-card gc-overview-card--wide gc-overview-workflows">
        <div class="gc-overview-card__head">
          <div>
            <h2 class="gc-overview-card__title"><?php echo esc_html__('Quick actions', 'verdantcart-ai-reports'); ?></h2>
            <p class="gc-overview-card__text">
              <?php echo esc_html__('Open the core tools most stores use first.', 'verdantcart-ai-reports'); ?>
            </p>
          </div>
        </div>

        <div class="gc-action-grid">
          <a class="gc-action-tile gc-action-tile--primary" href="<?php echo esc_url($reports_url); ?>">
            <span class="gc-action-tile__marker"><?php echo esc_html__('Start here', 'verdantcart-ai-reports'); ?></span>
            <span class="gc-action-tile__eyebrow"><?php echo esc_html__('Reports', 'verdantcart-ai-reports'); ?></span>
            <strong class="gc-action-tile__title"><?php echo esc_html__('All Customers', 'verdantcart-ai-reports'); ?></strong>
            <span class="gc-action-tile__text"><?php echo esc_html__('See emissions totals, trends, hotspots, exports, and customer breakdowns.', 'verdantcart-ai-reports'); ?></span>
          </a>

          <a class="gc-action-tile" href="<?php echo esc_url($backfill_url); ?>">
            <span class="gc-action-tile__eyebrow"><?php echo esc_html__('Maintenance', 'verdantcart-ai-reports'); ?></span>
            <strong class="gc-action-tile__title"><?php echo esc_html__('Run Backfill', 'verdantcart-ai-reports'); ?></strong>
            <span class="gc-action-tile__text"><?php echo esc_html__('Prepare reports for orders placed before VerdantCart was installed.', 'verdantcart-ai-reports'); ?></span>
          </a>

          <?php if ($front_dashboard_url !== '') : ?>
            <a class="gc-action-tile" href="<?php echo esc_url($front_dashboard_url); ?>" target="_blank" rel="noopener noreferrer">
              <span class="gc-action-tile__eyebrow"><?php echo esc_html__('Customer View', 'verdantcart-ai-reports'); ?></span>
              <strong class="gc-action-tile__title"><?php echo esc_html__('Open Front Dashboard', 'verdantcart-ai-reports'); ?></strong>
              <span class="gc-action-tile__text"><?php echo esc_html__('Preview what customers see about their own carbon activity.', 'verdantcart-ai-reports'); ?></span>
            </a>
          <?php else : ?>
            <div class="gc-action-tile gc-action-tile--disabled">
              <span class="gc-action-tile__eyebrow"><?php echo esc_html__('Customer View', 'verdantcart-ai-reports'); ?></span>
              <strong class="gc-action-tile__title"><?php echo esc_html__('Front Dashboard', 'verdantcart-ai-reports'); ?></strong>
              <span class="gc-action-tile__text"><?php echo esc_html__('Create or reconnect the public dashboard page to enable customer reporting.', 'verdantcart-ai-reports'); ?></span>
            </div>
          <?php endif; ?>
        </div>

        <p class="gc-backfill-hint">
          <strong><?php echo esc_html__('Reports look empty?', 'verdantcart-ai-reports'); ?></strong>
          <?php echo esc_html__('If this store already had WooCommerce orders before VerdantCart was installed, run Backfill once to prepare historical reporting data.', 'verdantcart-ai-reports'); ?>
        </p>
      </section>

      <div class="gc-overview-grid">
        <section class="gc-overview-card">
          <div class="gc-overview-card__head">
            <div>
              <h2 class="gc-overview-card__title"><?php echo esc_html__('VerdantCart site', 'verdantcart-ai-reports'); ?></h2>
              <p class="gc-overview-card__text">
                <?php echo esc_html__('Open the official VerdantCart AI site for product information, updates, and resources.', 'verdantcart-ai-reports'); ?>
              </p>
            </div>
          </div>

          <div class="gc-action-stack">
            <a class="gc-btn gc-btn--ghost" href="<?php echo esc_url($official_site_url); ?>" target="_blank" rel="noopener noreferrer">
              <?php echo esc_html__('Visit VerdantCart AI', 'verdantcart-ai-reports'); ?>
            </a>

            <a class="gc-btn gc-btn--ghost" href="<?php echo esc_url($advanced_url); ?>" target="_blank" rel="noopener noreferrer">
              <?php echo esc_html__('Explore Features', 'verdantcart-ai-reports'); ?>
            </a>
          </div>

          <p class="gc-overview-note">
            <?php echo esc_html__('Use this plugin for reporting work, and visit the official site when you want product information, updates, or extension details.', 'verdantcart-ai-reports'); ?>
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
                <?php echo esc_html($wc_ready ? __('Connected', 'verdantcart-ai-reports') : __('Needed', 'verdantcart-ai-reports')); ?>
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
              <h2 class="gc-overview-card__title"><?php echo esc_html__('How reporting works', 'verdantcart-ai-reports'); ?></h2>
              <p class="gc-overview-card__text">
                <?php echo esc_html__('A quick summary of how data flows through VerdantCart.', 'verdantcart-ai-reports'); ?>
              </p>
            </div>
          </div>

          <ul class="gc-note-list">
            <li><?php echo esc_html__('Estimated emissions are calculated from eligible WooCommerce order data.', 'verdantcart-ai-reports'); ?></li>
            <li><?php echo esc_html__('Reporting data is grouped into monthly, weekly, and yearly periods.', 'verdantcart-ai-reports'); ?></li>
            <li><?php echo esc_html__('Snapshots and aggregates are used for dashboards, trends, hotspots, and exports.', 'verdantcart-ai-reports'); ?></li>
          </ul>
        </section>

        <section class="gc-overview-card gc-overview-card--wide gc-overview-discovery">
          <div>
            <h2 class="gc-overview-card__title"><?php echo esc_html__('Explore more reporting workflows', 'verdantcart-ai-reports'); ?></h2>
            <p class="gc-overview-card__text">
              <?php echo esc_html__('For teams that need executive summaries, recurring delivery, report branding, strategic insights, or enhanced exports, VerdantCart AI is available as a separate extension.', 'verdantcart-ai-reports'); ?>
            </p>
          </div>

          <a class="gc-btn gc-btn--ghost" href="<?php echo esc_url($advanced_url); ?>" target="_blank" rel="noopener noreferrer">
            <?php echo esc_html__('Explore Features', 'verdantcart-ai-reports'); ?>
          </a>
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
        __('VerdantCart — Backfill', 'verdantcart-ai-reports'),
        __('Build missing reporting data for WooCommerce orders that existed before reporting was complete.', 'verdantcart-ai-reports')
      );

      $all_customers_url = admin_url('admin.php?page=vcarb-all-customers');
      $summary_url       = admin_url('admin.php?page=vcarb-sustainability-summary');
      ?>

      <div class="gc-card gc-backfill-setup">
        <div class="gc-backfill-hero">
          <div>
            <div class="gc-kicker"><?php echo esc_html__('First-time setup helper', 'verdantcart-ai-reports'); ?></div>
            <h2 class="gc-card__title"><?php echo esc_html__('Make older orders appear in reports', 'verdantcart-ai-reports'); ?></h2>
            <p class="gc-backfill-lede">
              <?php echo esc_html__('Run backfill when reports look empty after installing, or when WooCommerce orders existed before VerdantCart finished capturing reporting data.', 'verdantcart-ai-reports'); ?>
            </p>
          </div>

          <div class="gc-pill-row">
            <span class="gc-pill gc-pill--warn"><?php echo esc_html__('Manual only', 'verdantcart-ai-reports'); ?></span>
            <span class="gc-pill gc-pill--good"><?php echo esc_html__('Does not change order details', 'verdantcart-ai-reports'); ?></span>
            <span class="gc-pill gc-pill--good"><?php echo esc_html__('Safe to stop', 'verdantcart-ai-reports'); ?></span>
          </div>
        </div>

        <div class="gc-backfill-grid">
          <div class="gc-backfill-panel">
            <h3><?php echo esc_html__('What backfill does', 'verdantcart-ai-reports'); ?></h3>
            <ul class="gc-bullets">
              <li><?php echo esc_html__('Finds eligible completed orders that need reporting data.', 'verdantcart-ai-reports'); ?></li>
              <li><?php echo esc_html__('Fills missing store totals and product drivers for reports.', 'verdantcart-ai-reports'); ?></li>
              <li><?php echo esc_html__('Runs in small batches so larger stores can finish safely.', 'verdantcart-ai-reports'); ?></li>
            </ul>
          </div>

          <div class="gc-backfill-panel gc-backfill-panel--notice">
            <h3><?php echo esc_html__('When to run it', 'verdantcart-ai-reports'); ?></h3>
            <p>
              <?php echo esc_html__('If All Customers or the dashboard look empty after setup, run backfill once and then review the reports again.', 'verdantcart-ai-reports'); ?>
            </p>
          </div>
        </div>

        <div class="gc-options">
          <label class="gc-check">
            <input type="checkbox" id="gcBackfillIncludeCounted" checked />
            <span>
              <?php echo esc_html__('Also repair already-counted orders that are missing store totals or product drivers', 'verdantcart-ai-reports'); ?>
              <span class="gc-muted">(<?php echo esc_html__('recommended', 'verdantcart-ai-reports'); ?>)</span>
            </span>
          </label>
        </div>

        <div class="gc-actions">
          <button class="gc-btn gc-btn--primary" id="gcBackfillStart" type="button">
            <?php echo esc_html__('Start backfill', 'verdantcart-ai-reports'); ?>
          </button>

          <button class="gc-btn gc-btn--ghost" id="gcBackfillStop" type="button" disabled>
            <?php echo esc_html__('Stop', 'verdantcart-ai-reports'); ?>
          </button>
        </div>

        <p class="gc-helper-text">
          <?php echo esc_html__('You can leave this page open while batches run. Stopping pauses future batches and does not remove saved results.', 'verdantcart-ai-reports'); ?>
        </p>
      </div>

      <div class="gc-card gc-status-card">
        <div class="gc-card__head">
          <h2 class="gc-card__title"><?php echo esc_html__('Backfill progress', 'verdantcart-ai-reports'); ?></h2>
          <div class="gc-muted"><?php echo esc_html__('Live progress will appear here.', 'verdantcart-ai-reports'); ?></div>
        </div>

        <div id="gcBackfillStatus" class="gc-status-box">
          <div class="gc-status-empty">
            <?php echo esc_html__('No backfill has run yet. Start backfill when existing orders are missing from reports.', 'verdantcart-ai-reports'); ?>
          </div>
        </div>

        <div class="gc-status-next">
          <a class="gc-btn gc-btn--ghost" href="<?php echo esc_url($all_customers_url); ?>">
            <?php echo esc_html__('View All Customers', 'verdantcart-ai-reports'); ?>
          </a>
          <a class="gc-btn gc-btn--ghost" href="<?php echo esc_url($summary_url); ?>">
            <?php echo esc_html__('Open Sustainability Report', 'verdantcart-ai-reports'); ?>
          </a>
        </div>
      </div>
    </div>
  <?php
  }

  public function render_advanced_page(): void
  {
    $upsell = VCARB_Pro_Upsell::instance();

    $pricing_url    = $upsell->build_upgrade_url('advanced_page');
    $learn_more_url = 'https://verdantcart.ai/';

    /*
     * Save license.
     */
    if (
      isset($_POST['vcarb_activate_license']) &&
      check_admin_referer('vcarb_activate_license')
    ) {

      $license = isset($_POST['vcarb_license_key'])
        ? sanitize_text_field(wp_unslash($_POST['vcarb_license_key']))
        : '';

      update_option('vcarb_license_key', $license);

      echo '<div class="notice notice-success is-dismissible"><p>';
      esc_html_e('License key saved.', 'verdantcart-ai-reports');
      echo '</p></div>';
    }

    /*
     * Deactivate license.
     */
    if (
      isset($_POST['vcarb_deactivate_license']) &&
      check_admin_referer('vcarb_activate_license')
    ) {

      delete_option('vcarb_license_key');

      echo '<div class="notice notice-warning is-dismissible"><p>';
      esc_html_e('License deactivated.', 'verdantcart-ai-reports');
      echo '</p></div>';
    }

    /*
     * License source resolution (priority order):
     * 1. connected extension's Freemius integration via vap_fs() — source of truth
     *    when the connected extension is installed and a paying/trialing customer is
     *    authenticated. This stays in sync automatically with Freemius's
     *    own license storage so the Free plugin's card always reflects the
     *    same state the customer sees on the connected extension's own settings page.
     * 2. connected extension's local settings option (legacy fallback for sites that
     *    haven't yet activated through Freemius).
     * 3. Free plugin's stored license key (manual entry path, kept for
     *    backwards compatibility).
     */
    $license_key      = '';
    $masked_key       = '';
    $license_source   = '';
    $license_status   = '';
    $license_plan     = '';

    // Source 1: Freemius (connected extension).
    // Trial users may not have a secret_key, but is_paying_or_trial() is true,
    // so we detect on that signal rather than requiring a secret_key.
    if (function_exists('vap_fs')) {

      try {

        $fs_instance = vap_fs();

        if (
          is_object($fs_instance) &&
          method_exists($fs_instance, 'is_registered') &&
          $fs_instance->is_registered()
        ) {

          $is_trial    = method_exists($fs_instance, 'is_trial') && $fs_instance->is_trial();
          $is_paying   = method_exists($fs_instance, 'is_paying') && $fs_instance->is_paying();
          $is_active   = $is_trial || $is_paying ||
                         (method_exists($fs_instance, 'is_paying_or_trial') && $fs_instance->is_paying_or_trial()) ||
                         (method_exists($fs_instance, 'can_use_premium_code') && $fs_instance->can_use_premium_code());

          if ($is_active) {

            // Try to get the actual license key (paying customers).
            $fs_license_key = '';
            if (method_exists($fs_instance, '_get_license')) {
              $fs_license = $fs_instance->_get_license();
              if (is_object($fs_license) && ! empty($fs_license->secret_key)) {
                $fs_license_key = (string) $fs_license->secret_key;
              }
            }

            // Trial users without a secret_key: show generic indicator.
            $license_key    = '' !== $fs_license_key
              ? $fs_license_key
              : 'managed-by-pro'; // Sentinel so the "active" branch renders.
            $license_source = 'freemius';
            $license_status = 'active';

            if ($is_trial) {
              $license_plan = __('VerdantCart AI extension', 'verdantcart-ai-reports');
            } elseif ($is_paying) {
              $license_plan = __('VerdantCart AI extension', 'verdantcart-ai-reports');
            } else {
              $license_plan = __('VerdantCart AI extension', 'verdantcart-ai-reports');
            }
          }
        }
      } catch (\Throwable $e) {
        // Freemius not ready or threw. Silently fall through to next source.
      }
    }

    // Source 2: connected extension's local settings option (legacy fallback).
    if ('' === $license_key && defined('VCARTPRO_SETTINGS_KEY')) {

      $pro_settings = get_option(VCARTPRO_SETTINGS_KEY, array());

      if (is_array($pro_settings) && ! empty($pro_settings['license_key'])) {

        $license_key    = (string) $pro_settings['license_key'];
        $license_source = 'pro_option';
        $license_status = 'active';
        $license_plan   = __('VerdantCart AI extension', 'verdantcart-ai-reports');
      }
    }

    // Source 3: Free plugin's own stored key (manual entry).
    if ('' === $license_key) {

      $license_key = get_option('vcarb_license_key', '');

      if ('' !== $license_key) {
        $license_source = 'manual';
        $license_status = 'active';
        $license_plan   = __('VerdantCart AI extension', 'verdantcart-ai-reports');
      }
    }

    // Mask key for display (always show last 4 chars only).
    // Trial-only signal uses sentinel "managed-by-pro" — show a friendly label
    // instead of masking the literal sentinel string.
    if (! empty($license_key)) {
      if ('managed-by-pro' === $license_key) {
        $masked_key = __('Managed by connected extension', 'verdantcart-ai-reports');
      } else {
        $masked_key =
          str_repeat('•', max(0, strlen($license_key) - 4))
          . substr($license_key, -4);
      }
    }

  ?>

    <div class="wrap vcarb-advanced-tools-page">

      <h1><?php esc_html_e('Advanced Tools', 'verdantcart-ai-reports'); ?></h1>

      <p>
        <?php
        esc_html_e(
          'Operational reporting tools for maintaining VerdantCart carbon data and reviewing extension status.',
          'verdantcart-ai-reports'
        );
        ?>
      </p>

      <style>
        .vcarb-advanced-tools-page .vcarb-advanced-grid {
          display: grid;
          grid-template-columns: minmax(0, 1.4fr) minmax(280px, 0.8fr);
          gap: 20px;
          align-items: start;
          max-width: 1200px;
        }
        .vcarb-advanced-tools-page .postbox {
          margin: 0;
          padding: 20px;
          border-color: #dcdcde;
          box-shadow: none;
        }
        .vcarb-advanced-tools-page .vcarb-tool-list {
          display: grid;
          gap: 12px;
          margin-top: 16px;
        }
        .vcarb-advanced-tools-page .vcarb-tool-item {
          border: 1px solid #dcdcde;
          border-radius: 8px;
          padding: 14px 16px;
          background: #fff;
        }
        .vcarb-advanced-tools-page .vcarb-tool-item strong {
          display: block;
          margin-bottom: 4px;
        }
        .vcarb-advanced-tools-page .vcarb-tool-item p {
          margin: 0;
          color: #50575e;
        }
        .vcarb-advanced-tools-page .vcarb-status-pill {
          display: inline-flex;
          align-items: center;
          gap: 6px;
          border-radius: 999px;
          padding: 8px 12px;
          font-weight: 600;
        }
        .vcarb-advanced-tools-page .vcarb-status-pill--active {
          background: #ecfdf5;
          border: 1px solid #bbf7d0;
          color: #166534;
        }
        .vcarb-advanced-tools-page .vcarb-status-pill--neutral {
          background: #f6f7f7;
          border: 1px solid #dcdcde;
          color: #3c434a;
        }
        .vcarb-advanced-tools-page .vcarb-extension-note {
          max-width: 1200px;
          margin-top: 20px;
          padding: 18px 20px;
          border: 1px solid #dcdcde;
          border-left: 4px solid #15803d;
          background: #fff;
        }
        .vcarb-advanced-tools-page .vcarb-extension-note h2 {
          margin: 0 0 8px;
        }
        .vcarb-advanced-tools-page .vcarb-extension-note p {
          max-width: 780px;
          margin: 0 0 12px;
          color: #50575e;
        }
        .vcarb-advanced-tools-page .vcarb-feature-list {
          display: grid;
          grid-template-columns: repeat(2, minmax(0, 1fr));
          gap: 10px 14px;
          max-width: 980px;
          margin: 16px 0;
        }
        .vcarb-advanced-tools-page .vcarb-feature-list__item {
          border: 1px solid #dcdcde;
          border-radius: 8px;
          padding: 12px 14px;
          background: #fff;
        }
        .vcarb-advanced-tools-page .vcarb-feature-list__item strong {
          display: block;
          margin-bottom: 3px;
        }
        .vcarb-advanced-tools-page .vcarb-feature-list__item span {
          color: #50575e;
        }
        .vcarb-advanced-tools-page .vcarb-extension-note .button {
          margin-right: 8px;
        }
        .vcarb-advanced-tools-page .vcarb-support-note {
          max-width: 1200px;
          margin-top: 20px;
          color: #646970;
        }
        @media (max-width: 1100px) {
          .vcarb-advanced-tools-page .vcarb-advanced-grid {
            grid-template-columns: 1fr;
          }
        }
      </style>

      <div class="vcarb-advanced-grid">

        <div class="postbox">

          <h2 style="margin-top:0;">
            <?php esc_html_e('Available Tools', 'verdantcart-ai-reports'); ?>
          </h2>

          <p>
            <?php
            esc_html_e(
              'Use these built-in tools to keep reporting data accurate and ready for store dashboards, summaries, and exports.',
              'verdantcart-ai-reports'
            );
            ?>
          </p>

          <div class="vcarb-tool-list">
            <div class="vcarb-tool-item">
              <strong><?php esc_html_e('Snapshot reporting', 'verdantcart-ai-reports'); ?></strong>
              <p><?php esc_html_e('Review monthly, weekly, and yearly carbon snapshots generated from eligible WooCommerce orders.', 'verdantcart-ai-reports'); ?></p>
            </div>

            <div class="vcarb-tool-item">
              <strong><?php esc_html_e('Customer dashboard', 'verdantcart-ai-reports'); ?></strong>
              <p><?php esc_html_e('Show customers their own carbon activity using the public VerdantCart dashboard page.', 'verdantcart-ai-reports'); ?></p>
            </div>

            <div class="vcarb-tool-item">
              <strong><?php esc_html_e('Backfill utility', 'verdantcart-ai-reports'); ?></strong>
              <p><?php esc_html_e('Fill missing store totals and product hotspots for orders that have already been counted.', 'verdantcart-ai-reports'); ?></p>
            </div>

            <div class="vcarb-tool-item">
              <strong><?php esc_html_e('CSV and PDF exports', 'verdantcart-ai-reports'); ?></strong>
              <p><?php esc_html_e('Export store and customer sustainability data for operational review and record keeping.', 'verdantcart-ai-reports'); ?></p>
            </div>
          </div>

        </div>

        <div class="postbox">

          <h2 style="margin-top:0;">
            <?php esc_html_e('Extension Status', 'verdantcart-ai-reports'); ?>
          </h2>

          <?php if (! empty($license_key)) : ?>

            <p>
              <span class="vcarb-status-pill vcarb-status-pill--active">
                <?php esc_html_e('Connected extension detected', 'verdantcart-ai-reports'); ?>
              </span>
            </p>

            <table class="form-table">
              <tr>
                <th><?php esc_html_e('Extension', 'verdantcart-ai-reports'); ?></th>
                <td><?php echo esc_html($license_plan); ?></td>
              </tr>

              <?php if (! empty($masked_key)) : ?>
                <tr>
                  <th><?php esc_html_e('Key', 'verdantcart-ai-reports'); ?></th>
                  <td><code><?php echo esc_html($masked_key); ?></code></td>
                </tr>
              <?php endif; ?>

              <tr>
                <th><?php esc_html_e('Status', 'verdantcart-ai-reports'); ?></th>
                <td><?php esc_html_e('Active', 'verdantcart-ai-reports'); ?></td>
              </tr>
            </table>

            <p class="description">
              <?php esc_html_e('Extension licensing and billing are managed from the connected extension, when available.', 'verdantcart-ai-reports'); ?>
            </p>

          <?php else : ?>

            <p>
              <span class="vcarb-status-pill vcarb-status-pill--neutral">
                <?php esc_html_e('Free tools active', 'verdantcart-ai-reports'); ?>
              </span>
            </p>

            <p>
              <?php
              esc_html_e(
                'No connected extension is required to use the reporting, dashboard, backfill, and export tools included with this plugin.',
                'verdantcart-ai-reports'
              );
              ?>
            </p>

          <?php endif; ?>

        </div>

      </div>

      <div class="vcarb-extension-note">
        <h2><?php esc_html_e('Explore Features', 'verdantcart-ai-reports'); ?></h2>
        <p>
          <?php
          esc_html_e(
            'VerdantCart AI is available as a separate extension for stores that need a fuller reporting workflow beyond the tools included in this WordPress.org plugin.',
            'verdantcart-ai-reports'
          );
          ?>
        </p>

        <div class="vcarb-feature-list">
          <div class="vcarb-feature-list__item">
            <strong><?php esc_html_e('Executive summaries', 'verdantcart-ai-reports'); ?></strong>
            <span><?php esc_html_e('Manager-ready sustainability narratives based on saved reporting snapshots.', 'verdantcart-ai-reports'); ?></span>
          </div>

          <div class="vcarb-feature-list__item">
            <strong><?php esc_html_e('Scheduled reports', 'verdantcart-ai-reports'); ?></strong>
            <span><?php esc_html_e('Recurring delivery for teams that review carbon performance on a set cadence.', 'verdantcart-ai-reports'); ?></span>
          </div>

          <div class="vcarb-feature-list__item">
            <strong><?php esc_html_e('Report branding', 'verdantcart-ai-reports'); ?></strong>
            <span><?php esc_html_e('Business name, logo, brand color, footer note, and disclaimer support for reports.', 'verdantcart-ai-reports'); ?></span>
          </div>

          <div class="vcarb-feature-list__item">
            <strong><?php esc_html_e('Strategic insights', 'verdantcart-ai-reports'); ?></strong>
            <span><?php esc_html_e('Trend, intensity, hotspot, and recommendation signals for operational decisions.', 'verdantcart-ai-reports'); ?></span>
          </div>

          <div class="vcarb-feature-list__item">
            <strong><?php esc_html_e('Executive exports', 'verdantcart-ai-reports'); ?></strong>
            <span><?php esc_html_e('Enhanced CSV and PDF export presentation for internal review and sharing.', 'verdantcart-ai-reports'); ?></span>
          </div>
        </div>

        <a
          class="button button-secondary"
          href="<?php echo esc_url('https://verdantcart.ai/'); ?>"
          target="_blank"
          rel="noopener noreferrer">
          <?php esc_html_e('Read more', 'verdantcart-ai-reports'); ?>
        </a>

        <a
          class="button"
          href="<?php echo esc_url('https://verdantcart.ai/pricing/?utm_source=verdantcart_ai&utm_medium=landing&utm_campaign=pricing_click&utm_content=compare_pro'); ?>"
          target="_blank"
          rel="noopener noreferrer">
          <?php esc_html_e('Compare features', 'verdantcart-ai-reports'); ?>
        </a>
      </div>

      <p class="vcarb-support-note">
        <?php
        printf(
          /* translators: %s = support email address. */
          esc_html__('Need help with reporting setup? Email %s.', 'verdantcart-ai-reports'),
          '<a href="mailto:support@verdantcart.ai">support@verdantcart.ai</a>'
        );
        ?>
      </p>

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
    $backfill_url = admin_url('admin.php?page=' . self::PAGE_BACKFILL);
    $overview_url = admin_url('admin.php?page=' . self::PAGE_SETTINGS);

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

            <div class="gc-period-toolbar">
              <h3 class="nav-tab-wrapper gc-tabs gc-tabs--saas">
                <a
                  class="nav-tab gc-tab <?php echo esc_attr($view === 'month' ? 'nav-tab-active is-active' : ''); ?>"
                  href="<?php echo esc_url($month_url); ?>"
                  data-view="month">
                  <?php echo esc_html__('Month', 'verdantcart-ai-reports'); ?>
                </a>

                <a
                  class="nav-tab gc-tab <?php echo esc_attr($view === 'week' ? 'nav-tab-active is-active' : ''); ?>"
                  href="<?php echo esc_url($week_url); ?>"
                  data-view="week">
                  <?php echo esc_html__('Week', 'verdantcart-ai-reports'); ?>
                </a>

                <a
                  class="nav-tab gc-tab <?php echo esc_attr($view === 'year' ? 'nav-tab-active is-active' : ''); ?>"
                  href="<?php echo esc_url($year_url); ?>"
                  data-view="year">
                  <?php echo esc_html__('Year', 'verdantcart-ai-reports'); ?>
                </a>
              </h3>

              <div class="gc-period-browser gc-period-browser--compact">
                <?php if ($browser['has_prev']) : ?>
                  <a
                    class="button button-secondary gc-period-browser__btn"
                    href="<?php echo esc_url($prev_url); ?>"
                    data-gc-period-nav="prev">
                    ← <?php echo esc_html__('Previous', 'verdantcart-ai-reports'); ?>
                  </a>
                <?php else : ?>
                  <span class="button button-secondary gc-period-browser__btn disabled" aria-disabled="true">
                    ← <?php echo esc_html__('Previous', 'verdantcart-ai-reports'); ?>
                  </span>
                <?php endif; ?>

                <a
                  class="button button-secondary gc-period-browser__btn gc-period-browser__btn--current"
                  href="<?php echo esc_url($latest_url); ?>"
                  data-gc-period-nav="current">
                  <?php echo esc_html__('Current', 'verdantcart-ai-reports'); ?>
                </a>

                <?php if ($browser['has_next']) : ?>
                  <a
                    class="button button-secondary gc-period-browser__btn"
                    href="<?php echo esc_url($next_url); ?>"
                    data-gc-period-nav="next">
                    <?php echo esc_html__('Next', 'verdantcart-ai-reports'); ?> →
                  </a>
                <?php else : ?>
                  <span class="button button-secondary gc-period-browser__btn disabled" aria-disabled="true">
                    <?php echo esc_html__('Next', 'verdantcart-ai-reports'); ?> →
                  </span>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="gc-header__right">
            <div data-gc-admin-export></div>
          </div>
        </div>

        <div class="gc-kpis">
          <div class="gc-kpi gc-kpi--co2">
            <div class="gc-kpi__top">
              <span class="gc-kpi__icon">
                <?php echo wp_kses($this->icon_svg('leaf'), $this->admin_svg_allowed_html()); ?>
              </span>
              <div class="gc-kpi__label"><?php echo esc_html__('Total CO₂', 'verdantcart-ai-reports'); ?></div>
            </div>
            <div class="gc-kpi__value" data-gc-kpi="co2">—</div>
            <div class="gc-kpi__sub"><?php echo esc_html__('Selected period total', 'verdantcart-ai-reports'); ?></div>
          </div>

          <div class="gc-kpi gc-kpi--orders">
            <div class="gc-kpi__top">
              <span class="gc-kpi__icon">
                <?php echo wp_kses($this->icon_svg('cart'), $this->admin_svg_allowed_html()); ?>
              </span>
              <div class="gc-kpi__label"><?php echo esc_html__('Orders Included', 'verdantcart-ai-reports'); ?></div>
            </div>
            <div class="gc-kpi__value" data-gc-kpi="orders">—</div>
            <div class="gc-kpi__sub"><?php echo esc_html__('Completed orders', 'verdantcart-ai-reports'); ?></div>
          </div>

          <div class="gc-kpi gc-kpi--co2po">
            <div class="gc-kpi__top">
              <span class="gc-kpi__icon">
                <?php echo wp_kses($this->icon_svg('trend'), self::admin_svg_allowed_html()); ?>
              </span>
              <div class="gc-kpi__label"><?php echo esc_html__('CO₂ per Order', 'verdantcart-ai-reports'); ?></div>
            </div>
            <div class="gc-kpi__value" data-gc-kpi="co2po">—</div>
            <div class="gc-kpi__sub"><?php echo esc_html__('Average intensity', 'verdantcart-ai-reports'); ?></div>
          </div>

          <div class="gc-kpi gc-kpi--delta">
            <div class="gc-kpi__top">
              <span class="gc-kpi__icon">
                <?php echo wp_kses($this->icon_svg('compare'), self::admin_svg_allowed_html()); ?>
              </span>
              <div class="gc-kpi__label"><?php echo esc_html__('Change vs Previous', 'verdantcart-ai-reports'); ?></div>
            </div>
            <div class="gc-kpi__value" data-gc-kpi="delta">—</div>
            <div class="gc-kpi__sub"><?php echo esc_html__('Period trend', 'verdantcart-ai-reports'); ?></div>
          </div>
        </div>

        <div class="gc-empty-report-state" data-gc-empty-report-state hidden>
          <div class="gc-empty-report-state__content">
            <span class="gc-empty-report-state__eyebrow"><?php echo esc_html__('First report setup', 'verdantcart-ai-reports'); ?></span>
            <h2><?php echo esc_html__('No customer reporting data yet', 'verdantcart-ai-reports'); ?></h2>
            <p>
              <?php echo esc_html__('New eligible WooCommerce orders will appear here automatically. If this store already had orders before VerdantCart was installed, run Backfill once to prepare historical reporting data.', 'verdantcart-ai-reports'); ?>
            </p>
            <div class="gc-empty-report-state__actions">
              <a class="button button-primary gc-empty-report-state__button" href="<?php echo esc_url($backfill_url); ?>">
                <?php echo esc_html__('Run Backfill', 'verdantcart-ai-reports'); ?>
              </a>
              <a class="button button-secondary gc-empty-report-state__button" href="<?php echo esc_url($overview_url); ?>">
                <?php echo esc_html__('View setup guide', 'verdantcart-ai-reports'); ?>
              </a>
            </div>
          </div>
          <div class="gc-empty-report-state__preview" aria-label="<?php echo esc_attr__('What appears after orders are reported', 'verdantcart-ai-reports'); ?>">
            <div>
              <strong><?php echo esc_html__('Store totals', 'verdantcart-ai-reports'); ?></strong>
              <span><?php echo esc_html__('CO₂, order count, and average intensity.', 'verdantcart-ai-reports'); ?></span>
            </div>
            <div>
              <strong><?php echo esc_html__('Emission trends', 'verdantcart-ai-reports'); ?></strong>
              <span><?php echo esc_html__('Monthly, weekly, and yearly chart views.', 'verdantcart-ai-reports'); ?></span>
            </div>
            <div>
              <strong><?php echo esc_html__('Product hotspots', 'verdantcart-ai-reports'); ?></strong>
              <span><?php echo esc_html__('The products contributing most to emissions.', 'verdantcart-ai-reports'); ?></span>
            </div>
            <div>
              <strong><?php echo esc_html__('Customer breakdown', 'verdantcart-ai-reports'); ?></strong>
              <span><?php echo esc_html__('Per-customer totals for review and exports.', 'verdantcart-ai-reports'); ?></span>
            </div>
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
