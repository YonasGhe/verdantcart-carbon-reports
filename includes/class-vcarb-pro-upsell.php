<?php
/**
 * Extension discovery compatibility helper.
 *
 * The WordPress.org package keeps this class so older call sites and saved
 * dismiss actions remain stable, but it no longer renders locked, trial, or
 * payment-focused cards. Contextual feature discovery now lives on the
 * Advanced Tools page.
 *
 * @package VerdantCart_Carbon_Reports
 */

defined('ABSPATH') || exit;

class VCARB_Pro_Upsell
{
    const STATE_NOT_INSTALLED      = 'not_installed';
    const STATE_INSTALLED_INACTIVE = 'installed_inactive';
    const STATE_ACTIVE_UNLICENSED  = 'active_unlicensed';
    const STATE_ACTIVE_LICENSED    = 'active_licensed';

    const DISMISS_META_KEY = 'vcarb_pro_upsell_dismissed';
    const DISMISS_ACTION   = 'vcarb_pro_upsell_dismiss';

    const PRO_PLUGIN_BASENAME        = 'verdantcart-ai-pro/verdantcart-ai-pro.php';
    const PRO_PLUGIN_BASENAME_LEGACY = 'VerdantCart-ai-pro/verdantcart-ai-pro.php';

    /** @var self|null */
    private static $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    public function init(): void
    {
        add_action('admin_post_' . self::DISMISS_ACTION, [$this, 'handle_dismiss']);
    }

    public function get_state(): string
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active = is_plugin_active(self::PRO_PLUGIN_BASENAME) || is_plugin_active(self::PRO_PLUGIN_BASENAME_LEGACY);

        return $active ? self::STATE_ACTIVE_LICENSED : self::STATE_NOT_INSTALLED;
    }

    public function is_dismissed_for_current_user(): bool
    {
        $user_id = get_current_user_id();

        return $user_id > 0 && (bool) get_user_meta($user_id, self::DISMISS_META_KEY, true);
    }

    public function should_render_card(): bool
    {
        return false;
    }

    public function build_upgrade_url(string $placement = 'overview_card'): string
    {
        return add_query_arg(
            [
                'utm_source'   => 'verdantcart_ai',
                'utm_medium'   => 'plugin',
                'utm_campaign' => 'feature_exploration',
                'utm_content'  => sanitize_key($placement),
            ],
            'https://verdantcart.ai/pricing/'
        );
    }

    public function render_upsell_card(): void
    {
        return;
    }

    public function render_feature_badge(string $feature_label, string $pro_description, string $placement = 'feature_badge'): string
    {
        return '';
    }

    public function render_sps_teaser_card(): void
    {
        return;
    }

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
            update_user_meta($user_id, self::DISMISS_META_KEY, '1');
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=verdantcart-carbon-reports'));
        exit;
    }
}
