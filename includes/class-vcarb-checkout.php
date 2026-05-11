<?php
defined('ABSPATH') || exit;

/**
 * Backward-compatible checkout stub.
 *
 * The WordPress.org build does not create checkout sessions,
 * route users to paid checkout, or unlock local plugin features through payment.
 *
 * This class is intentionally disabled and kept only to prevent fatal errors
 * from older internal callers.
 */
class VCARB_Checkout
{
    public const ACTION       = 'vcarb_create_checkout';
    public const NONCE_ACTION = 'vcarb_checkout';

    public function __construct()
    {
        // Intentionally disabled in the WordPress.org build.
    }

    public static function init(): void
    {
        // Intentionally disabled in the WordPress.org build.
    }
}

/**
 * Legacy alias for older AmatorCarbon references.
 *
 * Keep temporarily during migration. You can delete this later after confirming
 * no files still reference AmatorCarbon_Checkout.
 */
if (!class_exists('AmatorCarbon_Checkout')) {
    class AmatorCarbon_Checkout extends VCARB_Checkout {}
}
