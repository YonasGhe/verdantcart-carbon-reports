<?php
defined('ABSPATH') || exit;

trait VCARB_Ajax_Helpers_Trait
{
    protected function send_ajax_error(string $message, int $status = 400, array $extra = []): void
    {
        $status = max(400, min(599, (int) $status));

        wp_send_json_error(
            array_merge(
                [
                    'message' => $message,
                ],
                $extra
            ),
            $status
        );
    }

    protected function require_logged_in(): int
    {
        if (!is_user_logged_in()) {
            $this->send_ajax_error(
                __('Unauthorized', 'verdantcart-ai-reports'),
                401
            );
        }

        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            $this->send_ajax_error(
                __('Invalid user', 'verdantcart-ai-reports'),
                400
            );
        }

        return $user_id;
    }

    protected function require_cap(string $cap): void
    {
        $cap = sanitize_key($cap);

        if ($cap === '' || !current_user_can($cap)) {
            $this->send_ajax_error(
                __('Forbidden', 'verdantcart-ai-reports'),
                403
            );
        }
    }

    protected function verify_nonce(string $nonce_action): void
    {
        $nonce_action = sanitize_key($nonce_action);

        if ($nonce_action === '') {
            $this->send_ajax_error(
                __('Invalid nonce action', 'verdantcart-ai-reports'),
                403
            );
        }

        $nonce = $this->get_post_string('nonce', '');

        if ($nonce === '') {
            $nonce = $this->get_post_string('_ajax_nonce', '');
        }

        if ($nonce === '') {
            $nonce = $this->get_post_string('_wpnonce', '');
        }

        if ($nonce === '' || !wp_verify_nonce($nonce, $nonce_action)) {
            $this->send_ajax_error(
                __('Invalid nonce', 'verdantcart-ai-reports'),
                403
            );
        }
    }

    protected function rate_limit(string $bucket, int $limit = 20, int $seconds = 10): void
    {
        $user_id = get_current_user_id();

        if ($user_id <= 0) {
            $this->send_ajax_error(
                __('Invalid user', 'verdantcart-ai-reports'),
                400
            );
        }

        $bucket = sanitize_key($bucket);

        if ($bucket === '') {
            $bucket = 'default';
        }

        $limit   = max(1, (int) $limit);
        $seconds = max(1, (int) $seconds);

        $key  = 'vcarb_rl_' . $bucket . '_' . absint($user_id);
        $hits = (int) get_transient($key);

        if ($hits >= $limit) {
            $this->send_ajax_error(
                __('Too many requests', 'verdantcart-ai-reports'),
                429,
                [
                    'retry' => $seconds,
                ]
            );
        }

        set_transient($key, $hits + 1, $seconds);
    }

    protected function get_post_string(string $key, string $default = ''): string
    {
        $key = sanitize_key($key);

        if ($key === '') {
            return $default;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Raw request helper; calling action must verify nonce before use.
        if (!isset($_POST[$key])) {
            return $default;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is unslashed, type-checked, and sanitized immediately below.
        $value = wp_unslash($_POST[$key]);

        if (!is_scalar($value)) {
            return $default;
        }

        return sanitize_text_field((string) $value);
    }

    protected function get_post_key(string $key, string $default = ''): string
    {
        $key = sanitize_key($key);

        if ($key === '') {
            return $default;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Raw request helper; calling action must verify nonce before use.
        if (!isset($_POST[$key])) {
            return $default;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Value is unslashed, type-checked, and sanitized immediately below.
        $value = wp_unslash($_POST[$key]);

        if (!is_scalar($value)) {
            return $default;
        }

        return sanitize_key((string) $value);
    }

    protected function get_post_bool(string $key): bool
    {
        $value = strtolower(trim($this->get_post_string($key, '')));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
