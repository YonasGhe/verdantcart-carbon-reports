<?php
defined('ABSPATH') || exit;

/**
 * Backward-compatible subscription product stub.
 *
 * WordPress.org build:
 * - does not create WooCommerce subscription products
 * - does not create checkout products
 * - does not create Pro products
 * - does not create paid entitlement products
 *
 * This class is kept only so older internal callers do not fatal
 * if they reference subscription product methods.
 */
class VCARB_Subscription_Products
{
    public static function ensure(): array
    {
        return [
            'ok'      => false,
            'message' => __('Subscription products are not available in this build.', 'verdantcart-ai-reports'),
            'created' => [],
            'updated' => [],
            'errors'  => [],
        ];
    }

    public static function pro_product_ids(): array
    {
        return [];
    }

    public static function is_pro_product_id(int $product_id): bool
    {
        unset($product_id);

        return false;
    }

    public static function product_id_by_plan(string $plan): int
    {
        unset($plan);

        return 0;
    }
}
