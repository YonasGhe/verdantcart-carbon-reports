<?php
defined('ABSPATH') || exit;

/**
 * Backward-compatible subscription products handler stub.
 *
 * The WordPress.org build does not create WooCommerce subscription products,
 * checkout products, Pro products, or paid entitlement products.
 *
 * This class is intentionally disabled and kept only to prevent fatal errors
 * from older internal callers.
 */
class VCARB_Subscription_Products_Handler
{
    public static function init(): void
    {
        // Intentionally disabled in the WordPress.org build.
    }
}

/**
 * Legacy alias for older AmatorCarbon references.
 *
 * Keep temporarily during migration. You can delete this later after confirming
 * no files still reference AmatorCarbon_Subscription_Products_Handler.
 */
if (!class_exists('AmatorCarbon_Subscription_Products_Handler')) {
    class AmatorCarbon_Subscription_Products_Handler extends VCARB_Subscription_Products_Handler {}
}
