<?php
defined('ABSPATH') || exit;

/**
 * Period parsing and formatting utilities shared by Dashboard / Reports / AJAX / Exports.
 *
 * Views:
 * - month: YYYY-MM
 * - week : YYYY-WNN (ISO week)
 * - year : YYYY
 *
 * Snapshot-safe philosophy:
 * - no live WooCommerce order scanning in this trait
 * - no request-time chart building from raw orders
 * - this trait only provides period/date helpers
 *
 * Important:
 * - Do NOT define sanitize_period_for_view_safe() here.
 * - Do NOT define get_previous_available_snapshot_period() here.
 * - Do NOT define get_next_available_snapshot_period() here.
 *
 * Those methods belong to VCARB_Snapshot_Trait to avoid trait collisions.
 */
trait VCARB_Period_Trait
{
    /**
     * @return array<int,string>
     */
    protected function vcarb_allowed_views(): array
    {
        return ['month', 'week', 'year'];
    }

    protected function vcarb_wp_timezone(): DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        $tz_string = function_exists('wp_timezone_string')
            ? wp_timezone_string()
            : '';

        try {
            return new DateTimeZone($tz_string ?: 'UTC');
        } catch (Throwable $e) {
            return new DateTimeZone('UTC');
        }
    }

    protected function normalize_period_view(string $view): string
    {
        $view = sanitize_key($view);

        return in_array($view, $this->vcarb_allowed_views(), true) ? $view : 'month';
    }

    /**
     * @return array{
     *     labels: array<int,string>,
     *     orders: array<int,int|float>,
     *     co2: array<int,int|float>,
     *     periods: array<int,string>
     * }
     */
    protected function empty_chart_series(): array
    {
        return [
            'labels'  => [],
            'orders'  => [],
            'co2'     => [],
            'periods' => [],
        ];
    }

    protected function sanitize_period_for_view(string $view, $raw): string
    {
        $view = $this->normalize_period_view($view);
        $raw  = is_scalar($raw) ? (string) $raw : '';
        $p    = trim(sanitize_text_field($raw));

        if ($p === '') {
            return '';
        }

        if ($view === 'month') {
            if (!preg_match('/^(\d{4})-(\d{2})$/', $p, $matches)) {
                return '';
            }

            $year  = (int) $matches[1];
            $month = (int) $matches[2];

            if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) {
                return '';
            }

            return sprintf('%04d-%02d', $year, $month);
        }

        if ($view === 'year') {
            if (!preg_match('/^\d{4}$/', $p)) {
                return '';
            }

            $year = (int) $p;

            if ($year < 1970 || $year > 2100) {
                return '';
            }

            return (string) $year;
        }

        if ($view === 'week') {
            if (!preg_match('/^(\d{4})-W(\d{2})$/', $p, $matches)) {
                return '';
            }

            $year = (int) $matches[1];
            $week = (int) $matches[2];

            if ($year < 1970 || $year > 2100 || $week < 1 || $week > 53) {
                return '';
            }

            try {
                $dt = (new DateTimeImmutable('now', $this->vcarb_wp_timezone()))
                    ->setISODate($year, $week, 1)
                    ->setTime(0, 0, 0);

                $normalized = sprintf(
                    '%04d-W%02d',
                    (int) $dt->format('o'),
                    (int) $dt->format('W')
                );

                return ($normalized === sprintf('%04d-W%02d', $year, $week))
                    ? $normalized
                    : '';
            } catch (Throwable $e) {
                return '';
            }
        }

        return '';
    }

    protected function period_regex(string $view): string
    {
        $view = $this->normalize_period_view($view);

        if ($view === 'week') {
            return '^[0-9]{4}-W(0[1-9]|[1-4][0-9]|5[0-3])$';
        }

        if ($view === 'year') {
            return '^[0-9]{4}$';
        }

        return '^[0-9]{4}-(0[1-9]|1[0-2])$';
    }

    protected function previous_period(string $view, string $current): string
    {
        $view    = $this->normalize_period_view($view);
        $current = $this->sanitize_period_for_view($view, $current);

        if ($current === '') {
            return '';
        }

        $tz = $this->vcarb_wp_timezone();

        if ($view === 'month') {
            try {
                $dt = new DateTimeImmutable($current . '-01 00:00:00', $tz);

                return $dt->modify('-1 month')->format('Y-m');
            } catch (Throwable $e) {
                return '';
            }
        }

        if ($view === 'year') {
            $previous = (int) $current - 1;

            return ($previous >= 1970) ? (string) $previous : '';
        }

        if ($view === 'week' && preg_match('/^(\d{4})-W(\d{2})$/', $current, $matches)) {
            try {
                $dt = (new DateTimeImmutable('now', $tz))
                    ->setISODate((int) $matches[1], (int) $matches[2], 1)
                    ->setTime(0, 0, 0)
                    ->modify('-7 days');

                $previous = sprintf(
                    '%04d-W%02d',
                    (int) $dt->format('o'),
                    (int) $dt->format('W')
                );

                return $this->sanitize_period_for_view('week', $previous);
            } catch (Throwable $e) {
                return '';
            }
        }

        return '';
    }

    protected function next_period(string $view, string $current): string
    {
        $view    = $this->normalize_period_view($view);
        $current = $this->sanitize_period_for_view($view, $current);

        if ($current === '') {
            return '';
        }

        $tz = $this->vcarb_wp_timezone();

        if ($view === 'month') {
            try {
                $dt = new DateTimeImmutable($current . '-01 00:00:00', $tz);

                return $dt->modify('+1 month')->format('Y-m');
            } catch (Throwable $e) {
                return '';
            }
        }

        if ($view === 'year') {
            $next = (int) $current + 1;

            return ($next <= 2100) ? (string) $next : '';
        }

        if ($view === 'week' && preg_match('/^(\d{4})-W(\d{2})$/', $current, $matches)) {
            try {
                $dt = (new DateTimeImmutable('now', $tz))
                    ->setISODate((int) $matches[1], (int) $matches[2], 1)
                    ->setTime(0, 0, 0)
                    ->modify('+7 days');

                $next = sprintf(
                    '%04d-W%02d',
                    (int) $dt->format('o'),
                    (int) $dt->format('W')
                );

                return $this->sanitize_period_for_view('week', $next);
            } catch (Throwable $e) {
                return '';
            }
        }

        return '';
    }

    protected function current_calendar_period(string $view): string
    {
        $view = $this->normalize_period_view($view);

        try {
            $now = new DateTimeImmutable('now', $this->vcarb_wp_timezone());
        } catch (Throwable $e) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        if ($view === 'week') {
            return sprintf(
                '%04d-W%02d',
                (int) $now->format('o'),
                (int) $now->format('W')
            );
        }

        if ($view === 'month') {
            return $now->format('Y-m');
        }

        if ($view === 'year') {
            return $now->format('Y');
        }

        return '';
    }
}
