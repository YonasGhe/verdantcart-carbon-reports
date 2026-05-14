<?php
defined('ABSPATH') || exit;

/**
 * VerdantCart Carbon Reports plugin page helper.
 *
 * Responsibilities:
 * - Mark plugin-created pages.
 * - Add a Pages list column for plugin-managed pages.
 * - Add a Pages list filter for plugin-managed pages.
 * - Add a post state label for plugin-managed pages.
 * - Remove Quick Edit from plugin-managed pages to reduce accidental shortcode changes.
 *
 * Backward compatibility:
 * - Reads both new VCARB page meta and old AmatorCarbon page meta.
 * - New saves use the VCARB meta key.
 */
final class VCARB_Plugin_Pages_Admin
{
    public const META_PLUGIN_PAGE = 'vcarb_plugin_page';

    private const META_MANAGED_PAGE = '_vcarb_managed_page';
    private const LEGACY_META_PLUGIN_PAGE = 'amatorcarbon_plugin_page';
    private const LEGACY_META_MANAGED_PAGE = '_amatorcarbon_managed_page';

    private const FILTER_KEY = 'vcarb_pages';
    private const LEGACY_FILTER_KEY = 'amatorcarbon_pages';

    private const COLUMN_KEY = 'vcarb_plugin_page';

    public static function init(): void
    {
        static $did = false;

        if ($did) {
            return;
        }

        $did = true;

        add_filter('manage_pages_columns', [__CLASS__, 'add_pages_column']);
        add_action('manage_pages_custom_column', [__CLASS__, 'render_pages_column'], 10, 2);

        add_action('restrict_manage_posts', [__CLASS__, 'render_pages_filter']);
        add_action('pre_get_posts', [__CLASS__, 'apply_pages_filter']);

        add_filter('display_post_states', [__CLASS__, 'add_post_state'], 10, 2);
        add_filter('post_row_actions', [__CLASS__, 'filter_row_actions'], 10, 2);
    }

    public static function mark_page(int $page_id): void
    {
        if ($page_id <= 0 || get_post_type($page_id) !== 'page') {
            return;
        }

        update_post_meta($page_id, self::META_PLUGIN_PAGE, '1');
        update_post_meta($page_id, self::META_MANAGED_PAGE, '1');

        /*
     * Keep legacy marker too so older installs and existing admin filters
     * do not lose the page relationship during the rename.
     */
        update_post_meta($page_id, self::LEGACY_META_PLUGIN_PAGE, '1');
        update_post_meta($page_id, self::LEGACY_META_MANAGED_PAGE, '1');
    }

    public static function is_plugin_page(int $page_id): bool
    {
        if ($page_id <= 0) {
            return false;
        }

        $keys = [
            self::META_PLUGIN_PAGE,
            self::META_MANAGED_PAGE,
            self::LEGACY_META_PLUGIN_PAGE,
            self::LEGACY_META_MANAGED_PAGE,
        ];

        foreach ($keys as $key) {
            if ((string) get_post_meta($page_id, $key, true) === '1') {
                return true;
            }
        }

        return false;
    }

    public static function add_pages_column(array $columns): array
    {
        $out = [];

        foreach ($columns as $key => $label) {
            $out[$key] = $label;

            if ($key === 'title') {
                $out[self::COLUMN_KEY] = __('VerdantCart', 'verdantcart-ai-reports');
            }
        }

        if (!isset($out[self::COLUMN_KEY])) {
            $out[self::COLUMN_KEY] = __('VerdantCart', 'verdantcart-ai-reports');
        }

        return $out;
    }

    public static function render_pages_column(string $column, int $post_id): void
    {
        if ($column !== self::COLUMN_KEY) {
            return;
        }

        if (!self::is_plugin_page($post_id)) {
            echo '&mdash;';
            return;
        }

        echo '<span class="vcarb-admin-pill" style="display:inline-block;padding:4px 10px;border-radius:999px;background:#dcfce7;border:1px solid #86efac;color:#166534;font-size:12px;font-weight:600;">';
        echo esc_html__('VerdantCart page', 'verdantcart-ai-reports');
        echo '</span>';
    }

    public static function render_pages_filter(string $post_type): void
    {
        global $pagenow;

        if ($pagenow !== 'edit.php' || $post_type !== 'page') {
            return;
        }

        $current = self::get_current_filter_value();

        echo '<select name="' . esc_attr(self::FILTER_KEY) . '">';
        echo '<option value="">' . esc_html__('All pages', 'verdantcart-ai-reports') . '</option>';
        echo '<option value="plugin"' . selected($current, 'plugin', false) . '>' . esc_html__('VerdantCart pages', 'verdantcart-ai-reports') . '</option>';
        echo '<option value="regular"' . selected($current, 'regular', false) . '>' . esc_html__('Regular pages only', 'verdantcart-ai-reports') . '</option>';
        echo '</select>';
    }

    public static function apply_pages_filter(WP_Query $query): void
    {
        global $pagenow;

        if (!is_admin() || !$query->is_main_query() || $pagenow !== 'edit.php') {
            return;
        }

        $post_type = self::get_admin_post_type($query);

        if ($post_type !== 'page') {
            return;
        }

        $filter = self::get_current_filter_value();

        if ($filter === '') {
            return;
        }

        $meta_query = (array) $query->get('meta_query');

        if ($filter === 'plugin') {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key'     => self::META_PLUGIN_PAGE,
                    'value'   => '1',
                    'compare' => '=',
                ],
                [
                    'key'     => self::LEGACY_META_PLUGIN_PAGE,
                    'value'   => '1',
                    'compare' => '=',
                ],
            ];
        } elseif ($filter === 'regular') {
            $meta_query[] = [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    [
                        'key'     => self::META_PLUGIN_PAGE,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => self::META_PLUGIN_PAGE,
                        'value'   => '1',
                        'compare' => '!=',
                    ],
                ],
                [
                    'relation' => 'OR',
                    [
                        'key'     => self::LEGACY_META_PLUGIN_PAGE,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => self::LEGACY_META_PLUGIN_PAGE,
                        'value'   => '1',
                        'compare' => '!=',
                    ],
                ],
            ];
        }

        $query->set('meta_query', $meta_query);
    }

    public static function add_post_state(array $states, WP_Post $post): array
    {
        if ($post->post_type !== 'page') {
            return $states;
        }

        if (!self::is_plugin_page((int) $post->ID)) {
            return $states;
        }

        $states[self::COLUMN_KEY] = __('VerdantCart page', 'verdantcart-ai-reports');

        return $states;
    }

    public static function filter_row_actions(array $actions, WP_Post $post): array
    {
        if ($post->post_type !== 'page') {
            return $actions;
        }

        if (!self::is_plugin_page((int) $post->ID)) {
            return $actions;
        }

        if (isset($actions['inline hide-if-no-js'])) {
            unset($actions['inline hide-if-no-js']);
        }

        return $actions;
    }

    private static function get_current_filter_value(): string
    {
        $raw = null;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter; no state is changed.
        if (isset($_GET[self::FILTER_KEY])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only admin list filter; value is unslashed and sanitized immediately.
            $raw = wp_unslash($_GET[self::FILTER_KEY]);
        }

        /*
         * Legacy filter key accepted so bookmarked old admin URLs still work.
         */
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list filter; no state is changed.
        if ($raw === null && isset($_GET[self::LEGACY_FILTER_KEY])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only admin list filter; value is unslashed and sanitized immediately.
            $raw = wp_unslash($_GET[self::LEGACY_FILTER_KEY]);
        }

        if (!is_scalar($raw)) {
            return '';
        }

        $value = sanitize_key((string) $raw);

        return in_array($value, ['plugin', 'regular'], true) ? $value : '';
    }

    private static function get_admin_post_type(WP_Query $query): string
    {
        $post_type = $query->get('post_type');

        if (is_string($post_type) && $post_type !== '') {
            return sanitize_key($post_type);
        }

        if (is_array($post_type) && !empty($post_type)) {
            $first = reset($post_type);

            return is_string($first) ? sanitize_key($first) : '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin list routing parameter; no state is changed.
        if (isset($_GET['post_type'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only admin list routing parameter; value is unslashed and sanitized immediately.
            $raw_post_type = wp_unslash($_GET['post_type']);

            if (is_scalar($raw_post_type)) {
                return sanitize_key((string) $raw_post_type);
            }
        }

        return 'post';
    }
}
