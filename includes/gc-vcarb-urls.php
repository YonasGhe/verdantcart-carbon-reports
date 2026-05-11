<?php
defined('ABSPATH') || exit;

/**
 * VerdantCart URL helpers.
 *
 * New internal prefix:
 * - vcarb_
 * - VCARB_
 *
 * Legacy amatorcarbon_* helper aliases are kept for backward compatibility
 * with older installed sites and older internal callers.
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

        /*
         * Legacy option fallback for sites that stored this before rename.
         */
        if ($url === home_url('/')) {
            $url = vcarb_get_page_url_by_option_or_slug(
                'amatorcarbon_pricing_page_id',
                'pricing'
            );
        }

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

        /*
         * Legacy slug kept so old dashboard pages still resolve.
         */
        $slugs[] = 'amator-carbon-dashboard';

        $url = vcarb_get_page_url_by_option_or_slugs(
            $option_key,
            array_values(array_unique(array_filter(array_map('sanitize_title', $slugs))))
        );

        /*
         * Legacy option fallback for sites that already stored dashboard page ID
         * before the internal prefix rename.
         */
        if ($url === home_url('/')) {
            $url = vcarb_get_page_url_by_option_or_slugs(
                'amatorcarbon_dashboard_page_id',
                array_values(array_unique(array_filter(array_map('sanitize_title', $slugs))))
            );
        }

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

        /*
         * Legacy option fallback.
         */
        if ($url === home_url('/')) {
            $url = vcarb_get_page_url_by_option_or_slug(
                'amatorcarbon_landing_page_id',
                ''
            );
        }

        return $url;
    }
}

if (!function_exists('vcarb_get_home_url')) {
    function vcarb_get_home_url(): string
    {
        return vcarb_get_landing_url();
    }
}

/**
 * -------------------------------------------------------------------------
 * Legacy aliases
 * -------------------------------------------------------------------------
 * Keep these while the plugin is already published and users may have old
 * options/pages/classes. You can remove them later only after a careful
 * migration release.
 */

if (!function_exists('amatorcarbon_get_page_url_by_option_or_slug')) {
    function amatorcarbon_get_page_url_by_option_or_slug(string $option_key, string $fallback_slug = ''): string
    {
        return vcarb_get_page_url_by_option_or_slug($option_key, $fallback_slug);
    }
}

if (!function_exists('amatorcarbon_get_page_url_by_option_or_slugs')) {
    function amatorcarbon_get_page_url_by_option_or_slugs(string $option_key, array $fallback_slugs = []): string
    {
        return vcarb_get_page_url_by_option_or_slugs($option_key, $fallback_slugs);
    }
}

if (!function_exists('amatorcarbon_get_pricing_url')) {
    function amatorcarbon_get_pricing_url(): string
    {
        return vcarb_get_pricing_url();
    }
}

if (!function_exists('amatorcarbon_get_dashboard_url')) {
    function amatorcarbon_get_dashboard_url(): string
    {
        return vcarb_get_dashboard_url();
    }
}

if (!function_exists('amatorcarbon_front_dashboard_url')) {
    function amatorcarbon_front_dashboard_url(): string
    {
        return vcarb_front_dashboard_url();
    }
}

if (!function_exists('amatorcarbon_get_landing_url')) {
    function amatorcarbon_get_landing_url(): string
    {
        return vcarb_get_landing_url();
    }
}

if (!function_exists('amatorcarbon_get_home_url')) {
    function amatorcarbon_get_home_url(): string
    {
        return vcarb_get_home_url();
    }
}
