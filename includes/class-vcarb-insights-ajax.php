<?php
defined('ABSPATH') || exit;

class VCARB_Insights_Ajax
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

        $did = true;

        $self = new self();

        add_action('wp_ajax_vcarb_admin_insights', [$self, 'ajax_admin_insights']);
        add_action('wp_ajax_vcarb_user_insights', [$self, 'ajax_user_insights']);

        /*
         * Temporary legacy AJAX support for existing 1.0.x screens/scripts.
         * Remove later only after all JS/localized nonce names are fully migrated.
         */
        add_action('wp_ajax_amatorcarbon_admin_insights', [$self, 'ajax_admin_insights']);
        add_action('wp_ajax_amatorcarbon_user_insights', [$self, 'ajax_user_insights']);
    }

    private function normalize_view(string $view): string
    {
        $view = sanitize_key($view);

        return in_array($view, self::ALLOWED_VIEWS, true) ? $view : 'month';
    }

    private function missing_classes_error(): void
    {
        wp_send_json_error(
            [
                'message' => __('Missing required classes.', 'verdantcart-ai-reports'),
            ],
            500
        );
    }

    private function empty_insights_html(): string
    {
        return '<div class="gc-empty">' .
            esc_html__('Insights will appear once a snapshot is available for this period.', 'verdantcart-ai-reports') .
            '</div>';
    }

    private function send_empty_insights_success(): void
    {
        wp_send_json_success([
            'html' => $this->empty_insights_html(),
        ]);
    }

    private function send_rendered_insights_success(array $insights, string $title, string $context): void
    {
        $html = VCARB_Insights_Renderer::render(
            $insights,
            [
                'title'   => $title,
                'context' => $context,
            ]
        );

        wp_send_json_success([
            'html' => $html,
        ]);
    }

    private function ensure_dependencies(): void
    {
        if (
            !class_exists('VCARB_Calculator') ||
            !class_exists('VCARB_Insights') ||
            !class_exists('VCARB_Insights_Renderer')
        ) {
            $this->missing_classes_error();
        }
    }

    private function verify_insights_nonce(string $new_action, string $legacy_action): void
    {
        $nonce = $this->get_post_string('nonce', '');

        if ($nonce === '') {
            $nonce = $this->get_post_string('_ajax_nonce', '');
        }

        if ($nonce === '') {
            $nonce = $this->get_post_string('_wpnonce', '');
        }

        $valid = $nonce !== '' && wp_verify_nonce($nonce, $new_action);

        if (!$valid) {
            $valid = $nonce !== '' && wp_verify_nonce($nonce, $legacy_action);
        }

        if (!$valid) {
            $this->send_ajax_error(
                __('Invalid nonce', 'verdantcart-ai-reports'),
                403
            );
        }
    }

    private function resolve_requested_period(string $view, string $raw_date): string
    {
        return $this->sanitize_period_for_view_safe($view, $raw_date);
    }

    private function has_store_snapshot_for_period(string $view, string $date): bool
    {
        return $date !== '' && $this->store_snapshot_exists($view, $date);
    }

    private function load_snapshot_rows(int $subject_user_id, string $view, string $date): array
    {
        $current_row = VCARB_Calculator::get_row($subject_user_id, $view, $date);

        if (!is_object($current_row)) {
            return [
                'current'  => null,
                'previous' => null,
                'has'      => false,
            ];
        }

        $prev_period = $this->get_previous_available_snapshot_period($view, $date);
        $previous_row = ($prev_period !== '')
            ? VCARB_Calculator::get_row($subject_user_id, $view, $prev_period)
            : null;

        return [
            'current'  => $current_row,
            'previous' => is_object($previous_row) ? $previous_row : null,
            'has'      => true,
        ];
    }

    private function get_user_request_view(int $user_id): string
    {
        $requested_view = $this->normalize_view($this->get_post_key('view', 'month'));

        if (class_exists('VCARB_Access')) {
            return (string) VCARB_Access::normalize_view($requested_view, $user_id);
        }

        return $requested_view;
    }

    private function get_admin_request_view(): string
    {
        return $this->normalize_view($this->get_post_key('view', 'month'));
    }

    public function ajax_user_insights(): void
    {
        $this->verify_insights_nonce('vcarb_user_insights', 'amatorcarbon_user_insights');

        $user_id = $this->require_logged_in();

        $this->rate_limit('user_insights', 20, 10);
        $this->ensure_dependencies();

        $view     = $this->get_user_request_view($user_id);
        $raw_date = $this->get_post_string('date', '');
        $date     = $this->resolve_requested_period($view, $raw_date);

        if (!$this->has_store_snapshot_for_period($view, $date)) {
            $this->send_empty_insights_success();
            return;
        }

        $rows = $this->load_snapshot_rows($user_id, $view, $date);

        if (empty($rows['has']) || !is_object($rows['current'])) {
            $this->send_empty_insights_success();
            return;
        }

        $insights = VCARB_Insights::analyze(
            $rows['current'],
            $rows['previous']
        );

        $this->send_rendered_insights_success(
            is_array($insights) ? $insights : [],
            __('Your Sustainability Insights', 'verdantcart-ai-reports'),
            'front'
        );
    }

    public function ajax_admin_insights(): void
    {
        $this->verify_insights_nonce('vcarb_admin_insights', 'amatorcarbon_admin_insights');

        $this->require_logged_in();
        $this->require_cap('manage_options');

        $this->rate_limit('admin_insights', 20, 10);
        $this->ensure_dependencies();

        $view     = $this->get_admin_request_view();
        $raw_date = $this->get_post_string('date', '');
        $date     = $this->resolve_requested_period($view, $raw_date);

        if (!$this->has_store_snapshot_for_period($view, $date)) {
            $this->send_empty_insights_success();
            return;
        }

        $rows = $this->load_snapshot_rows(0, $view, $date);

        if (empty($rows['has']) || !is_object($rows['current'])) {
            $this->send_empty_insights_success();
            return;
        }

        $insights = VCARB_Insights::analyze(
            $rows['current'],
            $rows['previous']
        );

        $this->send_rendered_insights_success(
            is_array($insights) ? $insights : [],
            __('Store Sustainability Insights', 'verdantcart-ai-reports'),
            'admin'
        );
    }
}
