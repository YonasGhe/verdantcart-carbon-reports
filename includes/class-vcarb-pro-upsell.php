<?php
/**
 * Pro Upsell
 *
 * Promotes the VerdantCart AI Pro add-on inside the free plugin's admin
 * UI. Detects whether Pro is installed/active/licensed and renders an
 * upsell card or success notice accordingly. Designed to be helpful, not
 * annoying — dismissable per-user, never shows a second time after
 * dismissal, and silently hides itself entirely when Pro is active with
 * a valid license.
 *
 * Detection model (4 mutually exclusive states):
 *
 *   1. NOT_INSTALLED  Pro plugin file is not present on disk.
 *                     → Show full upsell card with feature list + Buy CTA.
 *
 *   2. INSTALLED_INACTIVE
 *                     Pro file exists but plugin isn't activated.
 *                     → Show "Activate Pro" notice.
 *
 *   3. ACTIVE_UNLICENSED
 *                     Pro is active but no valid license is stored.
 *                     → Show "Add your license key" notice.
 *
 *   4. ACTIVE_LICENSED
 *                     Pro is active and licensed.
 *                     → Show nothing (silent — Pro is doing its job).
 *
 * URL strategy: every CTA uses get_public_plans_url() from
 * VCARB_Reports_Admin, with UTM params appended so the merchant can see
 * which upsell surface converts best (?utm_source=verdantcart_free
 * &utm_medium=admin_upsell&utm_campaign=<placement>).
 *
 * @package VerdantCart_Carbon_Reports
 */

defined('ABSPATH') || exit;

/**
 * Class VCARB_Pro_Upsell
 */
class VCARB_Pro_Upsell
{
    const STATE_NOT_INSTALLED      = 'not_installed';
    const STATE_INSTALLED_INACTIVE = 'installed_inactive';
    const STATE_ACTIVE_UNLICENSED  = 'active_unlicensed';
    const STATE_ACTIVE_LICENSED    = 'active_licensed';

    /**
     * User meta key for per-user dismissal of the overview-page upsell.
     */
    const DISMISS_META_KEY = 'vcarb_pro_upsell_dismissed';

    /**
     * Action used by the dismiss admin-post handler.
     */
    const DISMISS_ACTION = 'vcarb_pro_upsell_dismiss';

    /**
     * Standard Pro plugin slug + main file path (relative to WP plugins dir).
     */
    const PRO_PLUGIN_BASENAME = 'VerdantCart-ai-pro/verdantcart-ai-pro.php';

    /** @var self|null */
    private static $instance = null;

    /**
     * Singleton getter.
     *
     * @return self
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        // Empty by design.
    }

    /**
     * Register hooks.
     *
     * @return void
     */
    public function init(): void
    {
        add_action('admin_post_' . self::DISMISS_ACTION, [$this, 'handle_dismiss']);
    }

    /* ---------------------------------------------------------------------
     * Public API — used by the overview template and feature surfaces
     * ------------------------------------------------------------------- */

    /**
     * Resolve the current Pro plugin state on this site.
     *
     * @return string One of the STATE_* constants.
     */
    public function get_state(): string
    {
        $basename = self::PRO_PLUGIN_BASENAME;
        $absolute = WP_PLUGIN_DIR . '/' . $basename;

        if (!file_exists($absolute)) {
            return self::STATE_NOT_INSTALLED;
        }

        // Pro file exists — is it active?
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!is_plugin_active($basename)) {
            return self::STATE_INSTALLED_INACTIVE;
        }

        // Active — does it report a valid license?
        if (class_exists('VCARTPRO_License')) {
            $license = VCARTPRO_License::instance();

            if (method_exists($license, 'is_active') && $license->is_active()) {
                return self::STATE_ACTIVE_LICENSED;
            }

            return self::STATE_ACTIVE_UNLICENSED;
        }

        // Pro is active but the License class isn't loadable — treat as
        // unlicensed so the user gets a "something's off" prompt.
        return self::STATE_ACTIVE_UNLICENSED;
    }

    /**
     * Whether the upsell card has been dismissed by the current user.
     *
     * @return bool
     */
    public function is_dismissed_for_current_user(): bool
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return false;
        }
        return (bool) get_user_meta($user_id, self::DISMISS_META_KEY, true);
    }

    /**
     * Whether the upsell card should be rendered right now.
     *
     * Two conditions:
     *  - Pro is NOT already active+licensed (no need to upsell what they have)
     *  - The current user hasn't dismissed it
     *
     * @return bool
     */
    public function should_render_card(): bool
    {
        if (self::STATE_ACTIVE_LICENSED === $this->get_state()) {
            return false;
        }
        return !$this->is_dismissed_for_current_user();
    }

    /**
     * Build an upsell URL with UTM tracking parameters so the merchant
     * can see which placements convert best.
     *
     * @param string $placement Short label for this upsell surface,
     *                          e.g. 'overview_card', 'reports_badge'.
     * @return string
     */
    public function build_upgrade_url(string $placement = 'overview_card'): string
    {
        $base = $this->resolve_pricing_url();

        $args = [
            'utm_source'   => 'verdantcart_free',
            'utm_medium'   => 'admin_upsell',
            'utm_campaign' => sanitize_key($placement),
        ];

        return add_query_arg($args, $base);
    }

    /**
     * Resolve the pricing URL — prefer the public-site /pricing/ page if
     * one exists on the merchant's WP install, otherwise fall back to
     * the marketing site at verdantcart.com.
     *
     * @return string
     */
    private function resolve_pricing_url(): string
    {
        return (string) apply_filters(
            'vcarb_pro_upgrade_url',
            'https://verdantcart.ai/pricing/'
        );
    }

    /* ---------------------------------------------------------------------
     * Rendering helpers — called by the overview-page template
     * ------------------------------------------------------------------- */

    /**
     * Render the full upsell card. Caller is responsible for calling
     * should_render_card() first.
     *
     * Adapts to the current Pro state — different content for
     * not-installed vs installed-but-inactive vs active-unlicensed.
     *
     * @return void
     */
    public function render_upsell_card(): void
    {
        $state = $this->get_state();

        if (self::STATE_ACTIVE_LICENSED === $state) {
            return;
        }

        switch ($state) {
            case self::STATE_INSTALLED_INACTIVE:
                $this->render_card_installed_inactive();
                return;

            case self::STATE_ACTIVE_UNLICENSED:
                $this->render_card_active_unlicensed();
                return;

            case self::STATE_NOT_INSTALLED:
            default:
                $this->render_card_not_installed();
                return;
        }
    }

    /**
     * Render a small inline "Pro" badge next to a feature label, with
     * a tooltip-style title attribute explaining what the Pro extension
     * provides. Renders nothing when Pro is already active+licensed
     * (no point teasing a feature they already have).
     *
     * Example use in another template:
     *   echo $upsell->render_feature_badge(
     *       'PDF exports',
     *       __('Branded executive PDF in Pro.', 'verdantcart-ai-reports')
     *   );
     *
     * @param string $feature_label    Surrounding feature label (used as tooltip context).
     * @param string $pro_description  Short description of what Pro adds.
     * @param string $placement        UTM campaign label for the click-through.
     * @return string                  HTML snippet (safe to echo).
     */
    public function render_feature_badge(string $feature_label, string $pro_description, string $placement = 'feature_badge'): string
    {
        if (self::STATE_ACTIVE_LICENSED === $this->get_state()) {
            return '';
        }

        unset($feature_label); // Reserved for future per-feature analytics.

        $url = $this->build_upgrade_url($placement);

        return sprintf(
            ' <a class="vcarb-pro-badge" href="%1$s" target="_blank" rel="noopener" title="%2$s">%3$s</a>',
            esc_url($url),
            esc_attr($pro_description),
            esc_html__('Pro', 'verdantcart-ai-reports')
        );
    }

    /* ---------------------------------------------------------------------
     * Private card renderers
     * ------------------------------------------------------------------- */

    private function render_card_not_installed(): void
    {
        $upgrade_url = $this->build_upgrade_url('overview_card_not_installed');
        $dismiss_url = $this->build_dismiss_url();

        // Marketing-friendly Pro feature list. Higher-level than the
        // engineering bullet list — these are the value props a customer
        // wants to see, not the implementation details.
        $features = [
            __('Branded PDF Reports', 'verdantcart-ai-reports'),
            __('Advanced Carbon Analytics', 'verdantcart-ai-reports'),
            __('Scheduled Reports', 'verdantcart-ai-reports'),
            __('Customer Footprint Tracking', 'verdantcart-ai-reports'),
            __('AI Sustainability Insights', 'verdantcart-ai-reports'),
            __('Future Pro Features', 'verdantcart-ai-reports'),
        ];

        ?>
        <section class="gc-overview-card vcarb-pro-upsell vcarb-pro-upsell--full" style="background:linear-gradient(135deg,#f0fdf4 0%,#ffffff 60%);border:1px solid #bbf7d0;">
            <div class="gc-overview-card__head" style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
                <div>
                    <span class="gc-overview-badge is-ok" style="margin-bottom:0.5rem;display:inline-block;">
                        <?php echo esc_html__('🚀 Upgrade to VerdantCart AI Pro', 'verdantcart-ai-reports'); ?>
                    </span>
                    <h2 class="gc-overview-card__title">
                        <?php echo esc_html__('Take VerdantCart further with the Pro add-on', 'verdantcart-ai-reports'); ?>
                    </h2>
                    <p class="gc-overview-card__text">
                        <?php echo esc_html__('The free plugin gives you carbon visibility. Pro adds branded PDF reports, scheduled emails, customer-facing CO₂, and AI-written insights — built to work seamlessly alongside this plugin.', 'verdantcart-ai-reports'); ?>
                    </p>
                </div>
                <a href="<?php echo esc_url($dismiss_url); ?>" class="vcarb-pro-upsell__dismiss" aria-label="<?php esc_attr_e('Dismiss', 'verdantcart-ai-reports'); ?>" style="color:#6b7280;text-decoration:none;font-size:18px;line-height:1;">&times;</a>
            </div>

            <ul style="margin:0.5rem 0 1rem 1.25rem;padding:0;color:#374151;line-height:1.65;list-style:none;">
                <?php foreach ($features as $feature) : ?>
                    <li style="margin:0.2rem 0;">
                        <span style="color:#16a34a;font-weight:bold;margin-right:0.4rem;">✓</span>
                        <?php echo esc_html($feature); ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div style="margin:0.75rem 0 1rem;padding:0.6rem 0.9rem;background:#ffffff;border:1px solid #d1fae5;border-radius:6px;display:inline-block;">
                <span style="font-size:18px;font-weight:700;color:#1b5e20;">$9</span>
                <span style="color:#6b7280;font-size:13px;">/ month</span>
                <span style="color:#9ca3af;margin:0 0.4rem;">·</span>
                <span style="font-size:18px;font-weight:700;color:#1b5e20;">$99</span>
                <span style="color:#6b7280;font-size:13px;">/ year</span>
                <span style="color:#1b5e20;font-size:12px;margin-left:0.4rem;">(<?php echo esc_html__('save 8%', 'verdantcart-ai-reports'); ?>)</span>
            </div>

            <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                <a class="button button-primary button-hero" href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener" style="background:#2e7d32;border-color:#1b5e20;">
                    <?php echo esc_html__('Upgrade to Pro →', 'verdantcart-ai-reports'); ?>
                </a>
                <span style="color:#6b7280;font-size:13px;">
                    <?php echo esc_html__('Already have a license? Install Pro from the same page.', 'verdantcart-ai-reports'); ?>
                </span>
            </div>
        </section>
        <?php
    }

    private function render_card_installed_inactive(): void
    {
        $plugins_url = admin_url('plugins.php?s=VerdantCart+AI&plugin_status=inactive');
        $dismiss_url = $this->build_dismiss_url();

        ?>
        <section class="gc-overview-card vcarb-pro-upsell vcarb-pro-upsell--inactive" style="background:#fffbeb;border:1px solid #fcd34d;">
            <div class="gc-overview-card__head" style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
                <div>
                    <h2 class="gc-overview-card__title">
                        <?php echo esc_html__('VerdantCart AI Pro is installed but not active', 'verdantcart-ai-reports'); ?>
                    </h2>
                    <p class="gc-overview-card__text">
                        <?php echo esc_html__('Activate the Pro plugin to unlock AI summaries, branded PDFs, scheduled emails, customer footprint, and more.', 'verdantcart-ai-reports'); ?>
                    </p>
                </div>
                <a href="<?php echo esc_url($dismiss_url); ?>" class="vcarb-pro-upsell__dismiss" aria-label="<?php esc_attr_e('Dismiss', 'verdantcart-ai-reports'); ?>" style="color:#6b7280;text-decoration:none;font-size:18px;line-height:1;">&times;</a>
            </div>

            <a class="button button-primary" href="<?php echo esc_url($plugins_url); ?>">
                <?php echo esc_html__('Go to Plugins → Activate Pro', 'verdantcart-ai-reports'); ?>
            </a>
        </section>
        <?php
    }

    private function render_card_active_unlicensed(): void
    {
        $settings_url = admin_url('admin.php?page=verdantcart-ai-pro');
        $upgrade_url  = $this->build_upgrade_url('overview_card_unlicensed');
        $dismiss_url  = $this->build_dismiss_url();

        ?>
        <section class="gc-overview-card vcarb-pro-upsell vcarb-pro-upsell--unlicensed" style="background:#fef2f2;border:1px solid #fecaca;">
            <div class="gc-overview-card__head" style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
                <div>
                    <h2 class="gc-overview-card__title">
                        <?php echo esc_html__('VerdantCart AI Pro needs a license key', 'verdantcart-ai-reports'); ?>
                    </h2>
                    <p class="gc-overview-card__text">
                        <?php echo esc_html__('Pro is active but its features are paused until you enter a valid license key. Settings → License.', 'verdantcart-ai-reports'); ?>
                    </p>
                </div>
                <a href="<?php echo esc_url($dismiss_url); ?>" class="vcarb-pro-upsell__dismiss" aria-label="<?php esc_attr_e('Dismiss', 'verdantcart-ai-reports'); ?>" style="color:#6b7280;text-decoration:none;font-size:18px;line-height:1;">&times;</a>
            </div>

            <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                <a class="button button-primary" href="<?php echo esc_url($settings_url); ?>">
                    <?php echo esc_html__('Open Pro Settings', 'verdantcart-ai-reports'); ?>
                </a>
                <a class="button button-secondary" href="<?php echo esc_url($upgrade_url); ?>" target="_blank" rel="noopener">
                    <?php echo esc_html__('Get a license key', 'verdantcart-ai-reports'); ?>
                </a>
            </div>
        </section>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Dismissal handler
     * ------------------------------------------------------------------- */

    /**
     * Build a dismiss URL — a GET-style admin-post link with a nonce so
     * the action is CSRF-safe.
     *
     * @return string
     */
    private function build_dismiss_url(): string
    {
        $url = add_query_arg(
            [
                'action'    => self::DISMISS_ACTION,
                '_wpnonce'  => wp_create_nonce(self::DISMISS_ACTION),
            ],
            admin_url('admin-post.php')
        );

        return $url;
    }

    /**
     * admin-post handler: persist the dismissal, then redirect back to
     * wherever the user came from.
     *
     * @return void
     */
    public function handle_dismiss(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('Permission denied.', 'verdantcart-ai-reports'),
                esc_html__('Forbidden', 'verdantcart-ai-reports'),
                ['response' => 403]
            );
        }

        check_admin_referer(self::DISMISS_ACTION);

        $user_id = get_current_user_id();
        if ($user_id > 0) {
            update_user_meta($user_id, self::DISMISS_META_KEY, time());
        }

        $referer = wp_get_referer();
        if (false !== $referer && '' !== $referer) {
            wp_safe_redirect($referer);
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=verdantcart-carbon-reports'));
        exit;
    }
}
