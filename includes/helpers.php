<?php
defined('ABSPATH') || exit;

/**
 * VerdantCart Carbon Reports helpers.
 *
 * New internal prefix:
 * - vcarb_
 * - VCARB_
 *
 * Legacy amatorcarbon_* helper aliases are kept for backward compatibility.
 */

/* -------------------------------------------------------------------------
 * Page URL helpers
 * ---------------------------------------------------------------------- */

if (!function_exists('vcarb_get_page_url_by_option_or_slug')) {
    function vcarb_get_page_url_by_option_or_slug(string $option_key, string $fallback_slug = ''): string
    {
        $id = (int) get_option($option_key);

        if ($id > 0) {
            $post = get_post($id);

            if (
                $post instanceof WP_Post &&
                $post->post_type === 'page' &&
                $post->post_status !== 'trash'
            ) {
                $url = get_permalink($id);

                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        $slug = sanitize_title(trim($fallback_slug, "/ \t\n\r\0\x0B"));

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
        $id = (int) get_option($option_key);

        if ($id > 0) {
            $post = get_post($id);

            if (
                $post instanceof WP_Post &&
                $post->post_type === 'page' &&
                $post->post_status !== 'trash'
            ) {
                $url = get_permalink($id);

                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        foreach ($fallback_slugs as $fallback_slug) {
            $slug = sanitize_title(trim((string) $fallback_slug, "/ \t\n\r\0\x0B"));

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

/* -------------------------------------------------------------------------
 * VerdantCart URLs
 * ---------------------------------------------------------------------- */

if (!function_exists('vcarb_get_pricing_url')) {
    function vcarb_get_pricing_url(): string
    {
        $url = vcarb_get_page_url_by_option_or_slug(
            'vcarb_pricing_page_id',
            'pricing'
        );

        /*
         * Legacy fallback for sites that stored this option before the rename.
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

        $slugs = [
            'verdantcart-dashboard',
            'verdantcart-carbon-dashboard',
            'amator-carbon-dashboard',
        ];

        if (class_exists('VCARB_Reports_Activator')) {
            array_unshift($slugs, VCARB_Reports_Activator::SLUG_DASHBOARD);
        }

        $slugs = array_values(
            array_unique(
                array_filter(
                    array_map('sanitize_title', $slugs)
                )
            )
        );

        $url = vcarb_get_page_url_by_option_or_slugs($option_key, $slugs);

        /*
         * Legacy fallback for already-installed sites.
         */
        if ($url === home_url('/')) {
            $url = vcarb_get_page_url_by_option_or_slugs(
                'amatorcarbon_dashboard_page_id',
                $slugs
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
         * Legacy fallback for already-installed sites.
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

/* -------------------------------------------------------------------------
 * Legacy aliases
 * ----------------------------------------------------------------------
 * Keep these for now because the plugin is already published.
 * Old options, old pages, old shortcodes, and older internal callers may
 * still use amatorcarbon_* names.
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
