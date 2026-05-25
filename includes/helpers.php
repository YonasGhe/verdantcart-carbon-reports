<?php
defined('ABSPATH') || exit;

/**
 * VerdantCart URL helpers.
 *
 * Internal prefix:
 * - vcarb_
 * - VCARB_
 */

if (!function_exists('vcarb_get_page_url_by_option_or_slug')) {
    function vcarb_get_page_url_by_option_or_slug(string $option_key, string $fallback_slug = ''): string
    {
        $page_id = (int) get_option($option_key);

        if ($page_id > 0) {
            $post = get_post($page_id);

            if (
                $post instanceof WP_Post &&
                $post->post_type === 'page' &&
                $post->post_status !== 'trash'
            ) {
                $url = get_permalink($page_id);

                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        $slug = trim($fallback_slug, "/ \t\n\r\0\x0B");

        if ($slug !== '') {
            $page = get_page_by_path($slug, OBJECT, 'page');

            if (
                $page instanceof WP_Post &&
                $page->post_type === 'page' &&
                $page->post_status !== 'trash'
            ) {
                $url = get_permalink($page->ID);

                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        return home_url('/');
    }
}

if (!function_exists('vcarb_get_page_url_by_option_or_slugs')) {
    function vcarb_get_page_url_by_option_or_slugs(string $option_key, array $fallback_slugs = []): string
    {
        $page_id = (int) get_option($option_key);

        if ($page_id > 0) {
            $post = get_post($page_id);

            if (
                $post instanceof WP_Post &&
                $post->post_type === 'page' &&
                $post->post_status !== 'trash'
            ) {
                $url = get_permalink($page_id);

                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        foreach ($fallback_slugs as $fallback_slug) {
            $slug = trim((string) $fallback_slug, "/ \t\n\r\0\x0B");

            if ($slug === '') {
                continue;
            }

            $page = get_page_by_path($slug, OBJECT, 'page');

            if (
                $page instanceof WP_Post &&
                $page->post_type === 'page' &&
                $page->post_status !== 'trash'
            ) {
                $url = get_permalink($page->ID);

                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        return home_url('/');
    }
}

if (!function_exists('vcarb_get_pricing_url')) {
    function vcarb_get_pricing_url(): string
    {
        $option_key = (string) apply_filters(
            'vcarb_pricing_page_option_key',
            'vcarb_pricing_page_id'
        );

        $url = vcarb_get_page_url_by_option_or_slug(
            $option_key,
            'pricing'
        );

        return $url;
    }
}

if (!function_exists('vcarb_get_dashboard_url')) {
    function vcarb_get_dashboard_url(): string
    {
        $option_key = class_exists('VCARB_Reports_Activator')
            ? VCARB_Reports_Activator::OPT_DASHBOARD_ID
            : 'vcarb_dashboard_page_id';

        $slugs = [];

        if (class_exists('VCARB_Reports_Activator')) {
            $slugs[] = VCARB_Reports_Activator::SLUG_DASHBOARD;
        }

        $slugs[] = 'verdantcart-carbon-dashboard';
        $slugs[] = 'verdantcart-dashboard';

        $url = vcarb_get_page_url_by_option_or_slugs(
            $option_key,
            array_values(array_unique(array_filter(array_map('sanitize_title', $slugs))))
        );

        return $url;
    }
}

if (!function_exists('vcarb_front_dashboard_url')) {
    function vcarb_front_dashboard_url(): string
    {
        return vcarb_get_dashboard_url();
    }
}

if (!function_exists('vcarb_get_landing_url')) {
    function vcarb_get_landing_url(): string
    {
        $url = vcarb_get_page_url_by_option_or_slug(
            'vcarb_landing_page_id',
            ''
        );

        return $url;
    }
}

if (!function_exists('vcarb_get_home_url')) {
    function vcarb_get_home_url(): string
    {
        return vcarb_get_landing_url();
    }
}
