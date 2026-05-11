<?php
defined('ABSPATH') || exit;

/**
 * Checkout success hooks are disabled in the WordPress.org build.
 */
class VCARB_Checkout_Success
{
    public static function init(): void
    {
        // No-op.
    }
}
