<?php
/**
 * VerdantCart Engagement Notices
 *
 * Two dismissible admin notices that appear inside wp-admin after the free
 * plugin has been active for a while:
 *
 *   1. Feedback notice (after 7 days):  "How is VerdantCart working for you?"
 *      Encourages the merchant to email us with feedback / feature ideas.
 *
 *   2. Pro upgrade notice (after 14 days): "CSRD-ready in 10 minutes"
 *      Explains VerdantCart AI Pro exists (many free users never learn).
 *      Hidden automatically when the Pro plugin is active.
 *
 * Both notices are:
 *   - Only shown to users with `manage_options`
 *   - Only rendered inside wp-admin
 *   - Dismissed per user (not per site) via user_meta
 *   - "Remind me later" also snoozes for 30 days
 *
 * Why this exists (v1.3.3):
 *   The free plugin lives on WordPress.org and the Pro plugin lives on Freemius.
 *   Free users had no in-product path to discover Pro, and no channel to give
 *   feedback back to the solo maintainer. That's why 721 free installs had
 *   produced zero paid conversions and zero reviews — nobody knew there was
 *   more, and nobody had a place to ask. These notices close that gap without
 *   spamming — each shows at most once, is easy to dismiss, and is dead simple
 *   to disable for merchants who prefer a quiet admin.
 *
 * @package VerdantCart_Carbon_Reports
 * @since   1.3.3
 */

defined('ABSPATH') || exit;

/**
 * Class VCARB_Engagement_Notices
 */
final class VCARB_Engagement_Notices
{
    /**
     * Site-wide option storing the first-activation timestamp. Set once on
     * activation, back-filled lazily for existing installs upgrading from
     * an earlier version that didn't track this.
     */
    const OPTION_INSTALLED_AT = 'vcarb_installed_at';

    /**
     * User meta key that hides the feedback notice for the current user
     * once they've dismissed it.
     */
    const META_FEEDBACK_DISMISSED = 'vcarb_feedback_notice_dismissed';

    /**
     * User meta key that hides the Pro upgrade notice for the current user.
     */
    const META_UPGRADE_DISMISSED = 'vcarb_upgrade_notice_dismissed';

    /**
     * User meta storing a "remind me later" snooze timestamp. When set to a
     * future Unix time, notices stay hidden until that moment passes.
     */
    const META_FEEDBACK_SNOOZED_UNTIL = 'vcarb_feedback_notice_snoozed_until';
    const META_UPGRADE_SNOOZED_UNTIL  = 'vcarb_upgrade_notice_snoozed_until';

    /**
     * admin-post action names for the dismiss/snooze buttons.
     */
    const ACTION_FEEDBACK_DISMISS = 'vcarb_engagement_feedback_dismiss';
    const ACTION_FEEDBACK_SNOOZE  = 'vcarb_engagement_feedback_snooze';
    const ACTION_UPGRADE_DISMISS  = 'vcarb_engagement_upgrade_dismiss';
    const ACTION_UPGRADE_SNOOZE   = 'vcarb_engagement_upgrade_snooze';

    /**
     * Nonce action shared by all four button handlers.
     */
    const NONCE_ACTION = 'vcarb_engagement_notices';

    /**
     * How long a "remind me later" click hides the notice for.
     */
    const SNOOZE_SECONDS = 30 * DAY_IN_SECONDS;

    /**
     * How long the plugin must be installed before each notice is allowed
     * to appear. Both values chosen conservatively — merchants are still
     * evaluating the plugin during their first week, and prompting for
     * feedback (or upselling) before they've explored the product feels
     * pushy and damages trust.
     */
    const FEEDBACK_MIN_DAYS = 7;
    const UPGRADE_MIN_DAYS  = 14;

    /**
     * Where the feedback notice's email button routes to. Kept in one place
     * so it's easy to point at a different mailbox later.
     */
    const FEEDBACK_MAILTO = 'yonas.ghebremedhin@gmail.com';

    /**
     * Pro plugin main-file relative paths. Two variants because the folder
     * name changed across Freemius packaging generations.
     */
    const PRO_PLUGIN_BASENAME        = 'verdantcart-ai-pro/verdantcart-ai-pro.php';
    const PRO_PLUGIN_BASENAME_LEGACY = 'VerdantCart-ai-pro/verdantcart-ai-pro.php';

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
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

    /**
     * Private constructor — use ::instance().
     */
    private function __construct()
    {
    }

    /**
     * Wire hooks. Called once from the plugin bootstrap.
     *
     * @return void
     */
    public function init(): void
    {
        // Back-fill the install timestamp on the first admin_init after
        // upgrade, so existing installs that predate this version still
        // get a stable "days installed" number.
        add_action('admin_init', [$this, 'lazy_set_install_timestamp'], 5);

        // Render notices.
        add_action('admin_notices', [$this, 'maybe_render_notices']);

        // Handle the 4 dismiss/snooze button clicks.
        add_action('admin_post_' . self::ACTION_FEEDBACK_DISMISS, [$this, 'handle_feedback_dismiss']);
        add_action('admin_post_' . self::ACTION_FEEDBACK_SNOOZE,  [$this, 'handle_feedback_snooze']);
        add_action('admin_post_' . self::ACTION_UPGRADE_DISMISS,  [$this, 'handle_upgrade_dismiss']);
        add_action('admin_post_' . self::ACTION_UPGRADE_SNOOZE,   [$this, 'handle_upgrade_snooze']);
    }

    /**
     * Store the current timestamp as "installed at" the first time we see
     * it missing. Called on activation via the activator and lazily on
     * admin_init to catch existing installs that upgraded into this
     * version without a fresh activation event.
     *
     * @return void
     */
    public function lazy_set_install_timestamp(): void
    {
        if (get_option(self::OPTION_INSTALLED_AT)) {
            return;
        }

        add_option(self::OPTION_INSTALLED_AT, time(), '', false);
    }

    /**
     * Decide which notice (if any) to render and print the appropriate one.
     * Called on the admin_notices hook — runs on every wp-admin page load,
     * so all the guards below need to be cheap.
     *
     * @return void
     */
    public function maybe_render_notices(): void
    {
        if (!$this->current_user_can_see_notices()) {
            return;
        }

        $days_installed = $this->days_since_install();

        // Show at most ONE notice per page load — pick the more urgent one.
        // Upgrade wins over feedback because it has a longer minimum age
        // (14 days vs 7), so if it fires the user has already had a chance
        // to see the feedback notice.
        if ($this->should_render_upgrade_notice($days_installed)) {
            $this->render_upgrade_notice();
            return;
        }

        if ($this->should_render_feedback_notice($days_installed)) {
            $this->render_feedback_notice();
            return;
        }
    }

    /**
     * Feedback notice gate. Shown when:
     *   - The plugin has been installed for at least FEEDBACK_MIN_DAYS days.
     *   - The current user hasn't permanently dismissed it.
     *   - The current user isn't currently within their snooze window.
     *
     * @param int $days_installed Number of days since first activation.
     * @return bool
     */
    private function should_render_feedback_notice(int $days_installed): bool
    {
        if ($days_installed < self::FEEDBACK_MIN_DAYS) {
            return false;
        }

        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            return false;
        }

        if ((bool) get_user_meta($user_id, self::META_FEEDBACK_DISMISSED, true)) {
            return false;
        }

        $snoozed_until = (int) get_user_meta($user_id, self::META_FEEDBACK_SNOOZED_UNTIL, true);
        if ($snoozed_until > time()) {
            return false;
        }

        return true;
    }

    /**
     * Pro upgrade notice gate. Shown when:
     *   - The plugin has been installed for at least UPGRADE_MIN_DAYS days.
     *   - The Pro plugin isn't already active (nothing to upgrade to).
     *   - The current user hasn't permanently dismissed it.
     *   - The current user isn't within their snooze window.
     *
     * @param int $days_installed Number of days since first activation.
     * @return bool
     */
    private function should_render_upgrade_notice(int $days_installed): bool
    {
        if ($days_installed < self::UPGRADE_MIN_DAYS) {
            return false;
        }

        if ($this->is_pro_plugin_active()) {
            return false;
        }

        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            return false;
        }

        if ((bool) get_user_meta($user_id, self::META_UPGRADE_DISMISSED, true)) {
            return false;
        }

        $snoozed_until = (int) get_user_meta($user_id, self::META_UPGRADE_SNOOZED_UNTIL, true);
        if ($snoozed_until > time()) {
            return false;
        }

        return true;
    }

    /**
     * Render the feedback capture notice.
     *
     * @return void
     */
    private function render_feedback_notice(): void
    {
        $mailto_url = 'mailto:' . self::FEEDBACK_MAILTO
            . '?subject=' . rawurlencode('VerdantCart feedback from ' . wp_parse_url(home_url(), PHP_URL_HOST));

        $dismiss_url = wp_nonce_url(
            add_query_arg(['action' => self::ACTION_FEEDBACK_DISMISS], admin_url('admin-post.php')),
            self::NONCE_ACTION
        );

        $snooze_url = wp_nonce_url(
            add_query_arg(['action' => self::ACTION_FEEDBACK_SNOOZE], admin_url('admin-post.php')),
            self::NONCE_ACTION
        );

        ?>
        <div class="notice notice-info" style="border-left-color:#2e7d32;padding:14px 16px;">
            <p style="margin:0 0 10px;font-size:14px;">
                🌱 <strong><?php esc_html_e('How is VerdantCart working for you?', 'verdantcart-ai-reports'); ?></strong>
            </p>
            <p style="margin:0 0 12px;">
                <?php
                echo wp_kses(
                    __(
                        'I\'m Yonas, the solo developer behind this plugin. If you have <strong>one or two sentences</strong> on what you like, dislike, or would like improved, I read every reply personally. Merchants who share thoughtful feedback get a <strong>free VerdantCart Pro+ license</strong> as thanks.',
                        'verdantcart-ai-reports'
                    ),
                    ['strong' => []]
                );
                ?>
            </p>
            <p style="margin:0;">
                <a href="<?php echo esc_url($mailto_url); ?>" class="button button-primary" style="margin-right:8px;">
                    <?php esc_html_e('Email Yonas', 'verdantcart-ai-reports'); ?>
                </a>
                <a href="<?php echo esc_url($snooze_url); ?>" class="button" style="margin-right:8px;">
                    <?php esc_html_e('Remind me later', 'verdantcart-ai-reports'); ?>
                </a>
                <a href="<?php echo esc_url($dismiss_url); ?>" class="button-link" style="color:#646970;">
                    <?php esc_html_e('No thanks', 'verdantcart-ai-reports'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Render the Pro upgrade notice.
     *
     * @return void
     */
    private function render_upgrade_notice(): void
    {
        $pricing_url = add_query_arg(
            [
                'utm_source'   => 'verdantcart_ai_reports',
                'utm_medium'   => 'admin_notice',
                'utm_campaign' => 'upgrade_prompt_v133',
                'utm_content'  => 'free_plugin_notice',
            ],
            'https://verdantcart.ai/pricing/'
        );

        $dismiss_url = wp_nonce_url(
            add_query_arg(['action' => self::ACTION_UPGRADE_DISMISS], admin_url('admin-post.php')),
            self::NONCE_ACTION
        );

        $snooze_url = wp_nonce_url(
            add_query_arg(['action' => self::ACTION_UPGRADE_SNOOZE], admin_url('admin-post.php')),
            self::NONCE_ACTION
        );

        ?>
        <div class="notice notice-info" style="border-left-color:#2e7d32;padding:14px 16px;">
            <p style="margin:0 0 10px;font-size:14px;">
                💰 <strong><?php esc_html_e('CSRD-ready in 10 minutes — meet VerdantCart AI Pro', 'verdantcart-ai-reports'); ?></strong>
            </p>
            <p style="margin:0 0 12px;">
                <?php
                echo wp_kses(
                    __(
                        'Free VerdantCart tracks emissions. <strong>Pro adds what auditors and B2B buyers ask for</strong>: branded PDF reports, AI executive summaries, and one-click <code>/carbon.txt</code> publishing in the Green Web Foundation format — from €19/month. Prep for EU CSRD without €500/month enterprise SaaS.',
                        'verdantcart-ai-reports'
                    ),
                    ['strong' => [], 'code' => []]
                );
                ?>
            </p>
            <p style="margin:0;">
                <a href="<?php echo esc_url($pricing_url); ?>" class="button button-primary" style="margin-right:8px;" target="_blank" rel="noopener">
                    <?php esc_html_e('See plans & start free trial', 'verdantcart-ai-reports'); ?>
                </a>
                <a href="<?php echo esc_url($snooze_url); ?>" class="button" style="margin-right:8px;">
                    <?php esc_html_e('Remind me later', 'verdantcart-ai-reports'); ?>
                </a>
                <a href="<?php echo esc_url($dismiss_url); ?>" class="button-link" style="color:#646970;">
                    <?php esc_html_e('Not interested', 'verdantcart-ai-reports'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * admin-post handler: permanently hide the feedback notice for this user.
     *
     * @return void
     */
    public function handle_feedback_dismiss(): void
    {
        $this->check_permissions_or_die();
        update_user_meta(get_current_user_id(), self::META_FEEDBACK_DISMISSED, '1');
        $this->redirect_back();
    }

    /**
     * admin-post handler: snooze the feedback notice for 30 days.
     *
     * @return void
     */
    public function handle_feedback_snooze(): void
    {
        $this->check_permissions_or_die();
        update_user_meta(get_current_user_id(), self::META_FEEDBACK_SNOOZED_UNTIL, time() + self::SNOOZE_SECONDS);
        $this->redirect_back();
    }

    /**
     * admin-post handler: permanently hide the upgrade notice for this user.
     *
     * @return void
     */
    public function handle_upgrade_dismiss(): void
    {
        $this->check_permissions_or_die();
        update_user_meta(get_current_user_id(), self::META_UPGRADE_DISMISSED, '1');
        $this->redirect_back();
    }

    /**
     * admin-post handler: snooze the upgrade notice for 30 days.
     *
     * @return void
     */
    public function handle_upgrade_snooze(): void
    {
        $this->check_permissions_or_die();
        update_user_meta(get_current_user_id(), self::META_UPGRADE_SNOOZED_UNTIL, time() + self::SNOOZE_SECONDS);
        $this->redirect_back();
    }

    /**
     * Only shop admins and network admins should see these notices. Editors
     * managing content don't need product-management messaging.
     *
     * @return bool
     */
    private function current_user_can_see_notices(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Days elapsed since the plugin was first activated on this site.
     * Falls back to 0 when the timestamp is unknown for any reason.
     *
     * @return int
     */
    private function days_since_install(): int
    {
        $installed_at = (int) get_option(self::OPTION_INSTALLED_AT);

        if ($installed_at <= 0) {
            return 0;
        }

        $seconds = time() - $installed_at;

        return $seconds > 0 ? (int) floor($seconds / DAY_IN_SECONDS) : 0;
    }

    /**
     * Whether the Pro plugin is active on this site.
     *
     * @return bool
     */
    private function is_pro_plugin_active(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active(self::PRO_PLUGIN_BASENAME)
            || is_plugin_active(self::PRO_PLUGIN_BASENAME_LEGACY);
    }

    /**
     * Validate capability + nonce, wp_die on failure.
     *
     * @return void
     */
    private function check_permissions_or_die(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('Permission denied.', 'verdantcart-ai-reports'),
                esc_html__('Forbidden', 'verdantcart-ai-reports'),
                ['response' => 403]
            );
        }

        check_admin_referer(self::NONCE_ACTION);
    }

    /**
     * Redirect back to wherever the user was before clicking a button.
     *
     * @return void
     */
    private function redirect_back(): void
    {
        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
