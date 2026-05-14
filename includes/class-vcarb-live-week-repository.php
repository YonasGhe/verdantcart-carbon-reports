<?php
defined('ABSPATH') || exit;

/**
 * Backward-compatible live weekly repository.
 *
 * Current VerdantCart Carbon Reports flow is snapshot-only.
 *
 * This class is intentionally kept as a compatibility stub so older internal
 * construction call sites do not fatal, but it does not scan WooCommerce orders
 * during request-time rendering.
 */
class VCARB_Live_Week_Repository
{
    /**
     * Return empty user totals.
     *
     * @return array{orders:int,co2:float}
     */
    public function get_user_totals(int $user_id, string $week_period): array
    {
        unset($user_id, $week_period);

        return $this->empty_totals();
    }

    /**
     * Return no user updated timestamp.
     */
    public function get_user_updated(int $user_id, string $week_period): string
    {
        unset($user_id, $week_period);

        return '';
    }

    /**
     * Return no live store rows.
     *
     * @return array<int,object>
     */
    public function get_store_rows(string $week_period): array
    {
        unset($week_period);

        return [];
    }

    /**
     * Return no live store updated timestamp.
     */
    public function get_store_updated(string $week_period): string
    {
        unset($week_period);

        return '';
    }

    /**
     * @return array{orders:int,co2:float}
     */
    private function empty_totals(): array
    {
        return [
            'orders' => 0,
            'co2'    => 0.0,
        ];
    }
}
