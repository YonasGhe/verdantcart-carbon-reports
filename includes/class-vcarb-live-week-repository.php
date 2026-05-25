<?php
defined('ABSPATH') || exit;

/**
 * Snapshot-only compatibility repository.
 *
 * VerdantCart Carbon Reports no longer performs live WooCommerce
 * order aggregation during request-time rendering.
 *
 * This class remains only as a lightweight backward-compatible stub
 * for older internal call sites that may still instantiate it.
 *
 * Important:
 * - No live order scanning
 * - No WooCommerce queries
 * - No recalculation logic
 * - No payment or upgrade handling
 * - No fallback aggregation
 *
 * All reporting data should come from snapshot datasets.
 */
final class VCARB_Live_Week_Repository
{
    /**
     * Return empty user totals.
     *
     * @return array{orders:int,co2:float}
     */
    public function get_user_totals(int $user_id, string $week_period): array
    {
        unset($user_id, $week_period);

        return [
            'orders' => 0,
            'co2'    => 0.0,
        ];
    }

    /**
     * Return empty updated timestamp.
     */
    public function get_user_updated(int $user_id, string $week_period): string
    {
        unset($user_id, $week_period);

        return '';
    }

    /**
     * Return empty store rows.
     *
     * @return array<int,object>
     */
    public function get_store_rows(string $week_period): array
    {
        unset($week_period);

        return [];
    }

    /**
     * Return empty store updated timestamp.
     */
    public function get_store_updated(string $week_period): string
    {
        unset($week_period);

        return '';
    }

}
