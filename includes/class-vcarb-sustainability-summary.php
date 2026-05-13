<?php
defined('ABSPATH') || exit;

/**
 * Snapshot-based Sustainability Summary.
 *
 * This class does not calculate new emissions.
 * It only turns an existing dataset into a clean HTML summary.
 *
 * Safe wording:
 * - Sustainability Summary
 * - Operational sustainability summary
 * - Not a certified ESG / GHG / compliance report
 */
final class VCARB_Sustainability_Summary
{
    /**
     * Render summary from a dataset created by VCARB_Dataset_Service.
     *
     * @param array<string,mixed> $dataset
     * @param array<string,mixed> $args
     */
    public static function render(array $dataset, array $args = []): string
    {
        $args = wp_parse_args(
            $args,
            [
                'title'        => __('Store Sustainability Summary', 'verdantcart-ai-reports'),
                'context'      => is_admin() ? 'admin' : 'front',
                'show_actions' => true,
                'show_notice'  => true,
            ]
        );

        $context = sanitize_key((string) ($args['context'] ?? 'front'));

        if (!in_array($context, ['admin', 'front', 'export', 'email'], true)) {
            $context = 'front';
        }

        $scope    = sanitize_key((string) ($dataset['scope'] ?? 'user'));
        $view     = self::normalize_view((string) ($dataset['view'] ?? 'month'));
        $date     = self::sanitize_period_for_view($view, (string) ($dataset['date'] ?? ''));
        $snapshot = self::array_value($dataset, 'snapshot');
        $metrics  = self::array_value($dataset, 'metrics');
        $hotspots = self::array_list($dataset, 'hotspots');
        $insights = self::array_value($dataset, 'insights');
        $score    = self::array_value($dataset, 'score');
        $notice   = trim((string) ($dataset['notice'] ?? ''));

        $has_snapshot = !empty($snapshot['has']);
        $period_label = self::period_label($view, $date);

        $total_co2 = (float) ($metrics['total_co2'] ?? 0.0);
        $orders    = (int) ($metrics['orders'] ?? 0);
        $cpo       = ($orders > 0) ? ($total_co2 / max(1, $orders)) : null;

        $updated = trim((string) ($snapshot['updated'] ?? ($metrics['updated'] ?? '')));

        $score_value   = self::score_value($score);
        $score_label   = self::score_label($score, $score_value);
        $score_summary = self::score_summary($score);

        $groups = self::normalize_insights($insights);

        ob_start();
?>
        <section class="vcarb-summary vcarb-summary--<?php echo esc_attr($context); ?>" data-scope="<?php echo esc_attr($scope); ?>">
            <header class="vcarb-summary__header">
                <div>
                    <div class="vcarb-summary__kicker">
                        <?php echo esc_html__('Snapshot-based report', 'verdantcart-ai-reports'); ?>
                    </div>

                    <h2 class="vcarb-summary__title">
                        <?php echo esc_html((string) $args['title']); ?>
                    </h2>

                    <p class="vcarb-summary__subtitle">
                        <?php echo esc_html__('An operational sustainability summary generated from saved VerdantCart Carbon Reports snapshot data.', 'verdantcart-ai-reports'); ?>
                    </p>
                </div>

                <div class="vcarb-summary__meta">
                    <div>
                        <span><?php echo esc_html__('Period', 'verdantcart-ai-reports'); ?></span>
                        <strong><?php echo esc_html($period_label !== '' ? $period_label : __('No period selected', 'verdantcart-ai-reports')); ?></strong>
                    </div>

                    <div>
                        <span><?php echo esc_html__('View', 'verdantcart-ai-reports'); ?></span>
                        <strong><?php echo esc_html(ucfirst($view)); ?></strong>
                    </div>

                    <div>
                        <span><?php echo esc_html__('Snapshot', 'verdantcart-ai-reports'); ?></span>
                        <strong><?php echo esc_html($has_snapshot ? __('Available', 'verdantcart-ai-reports') : __('Missing', 'verdantcart-ai-reports')); ?></strong>
                    </div>
                </div>
            </header>

            <?php if (!$has_snapshot) : ?>
                <div class="vcarb-summary__notice">
                    <?php echo esc_html__('No snapshot is available for this period yet. Run Backfill or wait until eligible WooCommerce orders are processed.', 'verdantcart-ai-reports'); ?>
                </div>
            <?php elseif ($notice !== '' && !empty($args['show_notice'])) : ?>
                <div class="vcarb-summary__notice">
                    <?php echo esc_html($notice); ?>
                </div>
            <?php endif; ?>

            <div class="vcarb-summary__grid">
                <?php echo wp_kses_post(self::metric_card(__('Total CO₂', 'verdantcart-ai-reports'), self::format_kg($total_co2), __('Selected period emissions', 'verdantcart-ai-reports'))); ?>
                <?php echo wp_kses_post(self::metric_card(__('Orders', 'verdantcart-ai-reports'), number_format_i18n($orders), __('Eligible orders included', 'verdantcart-ai-reports'))); ?>
                <?php echo wp_kses_post(self::metric_card(__('CO₂ per order', 'verdantcart-ai-reports'), $cpo === null ? '—' : self::format_kg($cpo), __('Average intensity', 'verdantcart-ai-reports'))); ?>
                <?php echo wp_kses_post(self::metric_card(__('Score', 'verdantcart-ai-reports'), $score_value === null ? '—' : (string) $score_value, $score_label)); ?>
            </div>

            <section class="vcarb-summary__section">
                <h3><?php echo esc_html__('Executive summary', 'verdantcart-ai-reports'); ?></h3>

                <p>
                    <?php echo esc_html(self::executive_summary_text($has_snapshot, $total_co2, $orders, $cpo, $score_value, $score_label)); ?>
                </p>

                <?php if ($score_summary !== '') : ?>
                    <p><?php echo esc_html($score_summary); ?></p>
                <?php endif; ?>
            </section>

            <section class="vcarb-summary__section">
                <h3><?php echo esc_html__('Top hotspots', 'verdantcart-ai-reports'); ?></h3>

                <?php echo wp_kses_post(self::render_hotspots($hotspots)); ?>
            </section>

            <section class="vcarb-summary__section vcarb-summary__section--insights">
                <h3><?php echo esc_html__('Insights overview', 'verdantcart-ai-reports'); ?></h3>

                <div class="vcarb-summary__insight-grid">
                    <?php echo wp_kses_post(self::render_insight_group(__('Top positives', 'verdantcart-ai-reports'), $groups['positives'], __('No positive signals detected for this period.', 'verdantcart-ai-reports'))); ?>
                    <?php echo wp_kses_post(self::render_insight_group(__('Top risks', 'verdantcart-ai-reports'), $groups['risks'], __('No major risks detected for this period.', 'verdantcart-ai-reports'))); ?>
                    <?php echo wp_kses_post(self::render_insight_group(__('Recommendations', 'verdantcart-ai-reports'), $groups['recommendations'], __('No recommendations available yet.', 'verdantcart-ai-reports'))); ?>
                </div>
            </section>

            <section class="vcarb-summary__section">
                <h3><?php echo esc_html__('Methodology note', 'verdantcart-ai-reports'); ?></h3>

                <p>
                    <?php echo esc_html__('This summary is generated from saved VerdantCart Carbon Reports snapshot data. It uses eligible WooCommerce order records, stored emissions totals, hotspot data, and deterministic local insight rules. Historical snapshots are intended to keep reporting consistent over time.', 'verdantcart-ai-reports'); ?>
                </p>

                <?php if ($updated !== '') : ?>
                    <p>
                        <strong><?php echo esc_html__('Snapshot updated:', 'verdantcart-ai-reports'); ?></strong>
                        <?php echo esc_html($updated); ?>
                    </p>
                <?php endif; ?>
            </section>

            <footer class="vcarb-summary__footer">
                <p>
                    <?php echo esc_html__('Disclaimer: This is an operational sustainability summary. It is not a certified ESG report, GHG Protocol report, legal compliance document, or verified carbon audit.', 'verdantcart-ai-reports'); ?>
                </p>
            </footer>
        </section>
    <?php

        return (string) ob_get_clean();
    }

    /**
     * Convenience helper for admin/store summary.
     */
    public static function render_admin_summary(string $view = 'month', string $date = ''): string
    {
        if (!class_exists('VCARB_Dataset_Service')) {
            return self::missing_dependency_message();
        }

        $service = new VCARB_Dataset_Service();
        $dataset = $service->build_admin_dataset($view, $date, get_current_user_id());

        return self::render(
            is_array($dataset) ? $dataset : [],
            [
                'title'   => __('Store Sustainability Summary', 'verdantcart-ai-reports'),
                'context' => is_admin() ? 'admin' : 'front',
            ]
        );
    }

    /**
     * Convenience helper for current logged-in user summary.
     */
    public static function render_user_summary(int $user_id, string $view = 'month', string $date = ''): string
    {
        if (!class_exists('VCARB_Dataset_Service')) {
            return self::missing_dependency_message();
        }

        if ($user_id <= 0) {
            return '<div class="vcarb-summary__notice">' . esc_html__('Invalid user.', 'verdantcart-ai-reports') . '</div>';
        }

        $service = new VCARB_Dataset_Service();
        $dataset = $service->build_user_dataset($user_id, $view, $date);

        return self::render(
            is_array($dataset) ? $dataset : [],
            [
                'title'   => __('Customer Sustainability Summary', 'verdantcart-ai-reports'),
                'context' => is_admin() ? 'admin' : 'front',
            ]
        );
    }

    private static function missing_dependency_message(): string
    {
        return '<div class="vcarb-summary__notice">' .
            esc_html__('Sustainability summary is unavailable because the dataset service is missing.', 'verdantcart-ai-reports') .
            '</div>';
    }

    private static function normalize_view(string $view): string
    {
        $view = sanitize_key($view);

        return in_array($view, ['month', 'week', 'year'], true) ? $view : 'month';
    }

    private static function sanitize_period_for_view(string $view, string $period): string
    {
        $view   = self::normalize_view($view);
        $period = trim(sanitize_text_field($period));

        if ($period === '') {
            return '';
        }

        if ($view === 'month' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            return $period;
        }

        if ($view === 'week' && preg_match('/^(\d{4})-W(\d{2})$/', $period, $matches)) {
            $year = (int) $matches[1];
            $week = (int) $matches[2];

            if ($year < 1970 || $year > 2100 || $week < 1 || $week > 53) {
                return '';
            }

            try {
                $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');

                $dt = (new DateTimeImmutable('now', $tz))
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

        if ($view === 'year' && preg_match('/^\d{4}$/', $period)) {
            return $period;
        }

        return '';
    }

    /**
     * @param array<string,mixed> $array
     * @return array<string,mixed>
     */
    private static function array_value(array $array, string $key): array
    {
        return isset($array[$key]) && is_array($array[$key]) ? $array[$key] : [];
    }

    /**
     * @param array<string,mixed> $array
     * @return array<int,mixed>
     */
    private static function array_list(array $array, string $key): array
    {
        return isset($array[$key]) && is_array($array[$key]) ? array_values($array[$key]) : [];
    }

    private static function period_label(string $view, string $date): string
    {
        $view = self::normalize_view($view);
        $date = self::sanitize_period_for_view($view, $date);

        if ($date === '') {
            return '';
        }

        if ($view === 'month') {
            $timestamp = strtotime($date . '-01 00:00:00');

            return $timestamp ? date_i18n('F Y', $timestamp) : $date;
        }

        if ($view === 'week' && preg_match('/^(\d{4})-W(\d{2})$/', $date, $matches)) {
            return sprintf(
                /* translators: 1: ISO week number, 2: year. */
                __('Week %1$s, %2$s', 'verdantcart-ai-reports'),
                $matches[2],
                $matches[1]
            );
        }

        return $date;
    }

    private static function format_kg(float $value): string
    {
        return sprintf(
            /* translators: %s: kilogram amount. */
            __('%s kg', 'verdantcart-ai-reports'),
            number_format_i18n($value, 2)
        );
    }

    /**
     * @param array<string,mixed> $score
     */
    private static function score_value(array $score): ?int
    {
        $raw = null;

        if (isset($score['value']) && is_numeric($score['value'])) {
            $raw = $score['value'];
        } elseif (isset($score['score']) && is_numeric($score['score'])) {
            $raw = $score['score'];
        }

        if ($raw === null) {
            return null;
        }

        return max(0, min(100, (int) round((float) $raw)));
    }

    /**
     * @param array<string,mixed> $score
     */
    private static function score_label(array $score, ?int $score_value): string
    {
        $label = isset($score['label']) ? trim((string) $score['label']) : '';

        if ($label !== '') {
            return $label;
        }

        if ($score_value === null) {
            return __('No score available', 'verdantcart-ai-reports');
        }

        if ($score_value >= 85) {
            return __('Excellent', 'verdantcart-ai-reports');
        }

        if ($score_value >= 70) {
            return __('Good', 'verdantcart-ai-reports');
        }

        if ($score_value >= 50) {
            return __('Fair', 'verdantcart-ai-reports');
        }

        return __('Needs work', 'verdantcart-ai-reports');
    }

    /**
     * @param array<string,mixed> $score
     */
    private static function score_summary(array $score): string
    {
        return isset($score['summary']) ? trim((string) $score['summary']) : '';
    }

    private static function executive_summary_text(
        bool $has_snapshot,
        float $total_co2,
        int $orders,
        ?float $co2_per_order,
        ?int $score_value,
        string $score_label
    ): string {
        if (!$has_snapshot) {
            return __('No snapshot is available yet, so a complete sustainability summary cannot be generated for this period.', 'verdantcart-ai-reports');
        }

        $co2_per_order_text = $co2_per_order === null
            ? __('not available', 'verdantcart-ai-reports')
            : self::format_kg($co2_per_order);

        if ($score_value === null) {
            return sprintf(
                /* translators: 1: total CO2, 2: orders, 3: CO2 per order. */
                __('This period includes %1$s of estimated CO₂ across %2$s eligible orders, with an average intensity of %3$s per order.', 'verdantcart-ai-reports'),
                self::format_kg($total_co2),
                number_format_i18n($orders),
                $co2_per_order_text
            );
        }

        return sprintf(
            /* translators: 1: total CO2, 2: orders, 3: CO2 per order, 4: score, 5: score label. */
            __('This period includes %1$s of estimated CO₂ across %2$s eligible orders, with an average intensity of %3$s per order. The sustainability score is %4$s/100, rated %5$s.', 'verdantcart-ai-reports'),
            self::format_kg($total_co2),
            number_format_i18n($orders),
            $co2_per_order_text,
            number_format_i18n($score_value),
            $score_label
        );
    }

    private static function metric_card(string $label, string $value, string $sub): string
    {
        ob_start();
    ?>
        <div class="vcarb-summary__metric">
            <div class="vcarb-summary__metric-label"><?php echo esc_html($label); ?></div>
            <div class="vcarb-summary__metric-value"><?php echo esc_html($value); ?></div>
            <div class="vcarb-summary__metric-sub"><?php echo esc_html($sub); ?></div>
        </div>
    <?php

        return (string) ob_get_clean();
    }

    /**
     * @param array<int,mixed> $hotspots
     */
    private static function render_hotspots(array $hotspots): string
    {
        if (empty($hotspots)) {
            return '<p class="vcarb-summary__empty">' .
                esc_html__('No product hotspots are available for this period.', 'verdantcart-ai-reports') .
                '</p>';
        }

        $items = array_slice($hotspots, 0, 5);

        ob_start();
    ?>
        <ol class="vcarb-summary__list">
            <?php foreach ($items as $item) : ?>
                <?php
                if (!is_array($item)) {
                    continue;
                }

                $name = trim(
                    (string) (
                        $item['product_name']
                        ?? $item['name']
                        ?? $item['product_title']
                        ?? __('Unknown product', 'verdantcart-ai-reports')
                    )
                );

                $co2 = 0.0;

                if (isset($item['total_co2']) && is_numeric($item['total_co2'])) {
                    $co2 = (float) $item['total_co2'];
                } elseif (isset($item['co2']) && is_numeric($item['co2'])) {
                    $co2 = (float) $item['co2'];
                }

                $orders = null;

                if (isset($item['orders']) && is_numeric($item['orders'])) {
                    $orders = (int) $item['orders'];
                } elseif (isset($item['qty']) && is_numeric($item['qty'])) {
                    $orders = (int) $item['qty'];
                }

                $percent = null;

                if (isset($item['percent']) && is_numeric($item['percent'])) {
                    $percent = max(0, min(100, (float) $item['percent']));
                } elseif (isset($item['share']) && is_numeric($item['share'])) {
                    $percent = max(0, min(100, (float) $item['share']));
                }
                ?>
                <li>
                    <strong><?php echo esc_html($name); ?></strong>

                    <span>
                        <?php echo esc_html(self::format_kg($co2)); ?>

                        <?php if ($percent !== null) : ?>
                            <?php echo esc_html(' · ' . number_format_i18n($percent, 0) . '%'); ?>
                        <?php endif; ?>

                        <?php if ($orders !== null) : ?>
                            <?php echo esc_html(' · ' . sprintf(
                                /* translators: %s: order count. */
                                _n('%s order', '%s orders', $orders, 'verdantcart-ai-reports'),
                                number_format_i18n($orders)
                            )); ?>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php

        return (string) ob_get_clean();
    }

    /**
     * @param mixed $insights
     * @return array{positives:array<int,array<string,mixed>>,warnings:array<int,array<string,mixed>>,risks:array<int,array<string,mixed>>,recommendations:array<int,array<string,mixed>>}
     */
    private static function normalize_insights($insights): array
    {
        $groups = [
            'positives'       => [],
            'warnings'        => [],
            'risks'           => [],
            'recommendations' => [],
        ];

        if (!is_array($insights)) {
            return $groups;
        }

        foreach (array_keys($groups) as $group) {
            if (isset($insights[$group]) && is_array($insights[$group])) {
                $groups[$group] = self::normalize_insight_items($insights[$group]);
            }
        }

        if (
            empty($groups['positives']) &&
            empty($groups['warnings']) &&
            empty($groups['risks']) &&
            empty($groups['recommendations']) &&
            isset($insights[0]) &&
            is_array($insights[0])
        ) {
            foreach ($insights as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $type = sanitize_key((string) ($item['type'] ?? 'warning'));

                if ($type === 'positive' || $type === 'success') {
                    $groups['positives'][] = self::normalize_insight_item($item);
                } elseif ($type === 'risk') {
                    $groups['risks'][] = self::normalize_insight_item($item);
                } elseif ($type === 'recommendation') {
                    $groups['recommendations'][] = self::normalize_insight_item($item);
                } else {
                    $groups['warnings'][] = self::normalize_insight_item($item);
                }
            }
        }

        foreach (array_keys($groups) as $group) {
            $groups[$group] = array_slice($groups[$group], 0, 3);
        }

        return $groups;
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,array<string,mixed>>
     */
    private static function normalize_insight_items(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $out[] = self::normalize_insight_item($item);
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private static function normalize_insight_item(array $item): array
    {
        return [
            'title'   => trim(wp_strip_all_tags((string) ($item['title'] ?? ''))),
            'message' => trim(wp_strip_all_tags((string) ($item['message'] ?? ''))),
            'reason'  => trim(wp_strip_all_tags((string) ($item['reason'] ?? ''))),
            'actions' => isset($item['actions']) && is_array($item['actions']) ? array_values($item['actions']) : [],
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private static function render_insight_group(string $title, array $items, string $empty_message): string
    {
        ob_start();
    ?>
        <div class="vcarb-summary__insight-card">
            <h4><?php echo esc_html($title); ?></h4>

            <?php if (empty($items)) : ?>
                <p class="vcarb-summary__empty"><?php echo esc_html($empty_message); ?></p>
            <?php else : ?>
                <ul class="vcarb-summary__insight-list">
                    <?php foreach ($items as $item) : ?>
                        <?php
                        $item_title = trim((string) ($item['title'] ?? ''));
                        $message    = trim((string) ($item['message'] ?? ''));
                        $reason     = trim((string) ($item['reason'] ?? ''));
                        $actions    = isset($item['actions']) && is_array($item['actions']) ? $item['actions'] : [];
                        ?>
                        <li>
                            <?php if ($item_title !== '') : ?>
                                <strong><?php echo esc_html($item_title); ?></strong>
                            <?php endif; ?>

                            <?php if ($message !== '') : ?>
                                <span><?php echo esc_html($message); ?></span>
                            <?php endif; ?>

                            <?php if ($reason !== '') : ?>
                                <small><?php echo esc_html($reason); ?></small>
                            <?php endif; ?>

                            <?php if (!empty($actions)) : ?>
                                <ul>
                                    <?php foreach (array_slice($actions, 0, 3) as $action) : ?>
                                        <?php $action = is_string($action) ? trim(wp_strip_all_tags($action)) : ''; ?>
                                        <?php if ($action !== '') : ?>
                                            <li><?php echo esc_html($action); ?></li>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
<?php

        return (string) ob_get_clean();
    }
}
