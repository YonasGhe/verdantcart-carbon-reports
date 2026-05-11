<?php
defined('ABSPATH') || exit;

final class VCARB_Insights_Renderer
{
    public const STYLE_HANDLE = 'verdantcart-insights';

    private const CSS_REL = 'public/css/verdantcart-insights.css';

    public static function init(): void
    {
        // Intentionally empty.
    }

    private static function css_url(): string
    {
        return VCARB_PLUGIN_URL . self::CSS_REL;
    }

    private static function css_path(): string
    {
        return VCARB_PLUGIN_DIR . self::CSS_REL;
    }

    private static function version(): string
    {
        $path = self::css_path();

        if (file_exists($path)) {
            return (string) filemtime($path);
        }

        if (defined('VCARB_VERSION')) {
            return (string) VCARB_VERSION;
        }

        if (defined('VCARB_DB_VERSION')) {
            return (string) VCARB_DB_VERSION;
        }

        return '1.0.0';
    }

    public static function enqueue_assets(): void
    {
        if (!file_exists(self::css_path())) {
            return;
        }

        if (!wp_style_is(self::STYLE_HANDLE, 'registered')) {
            wp_register_style(
                self::STYLE_HANDLE,
                self::css_url(),
                [],
                self::version()
            );
        }

        wp_enqueue_style(self::STYLE_HANDLE);
    }

    private static function empty_groups(): array
    {
        return [
            'positives'       => [],
            'warnings'        => [],
            'risks'           => [],
            'recommendations' => [],
        ];
    }

    private static function normalize_card(array $card): array
    {
        $reason = (string) ($card['reason'] ?? '');
        $why    = (string) ($card['why'] ?? '');

        if ($why === '' && $reason !== '') {
            $why = $reason;
        }

        return [
            'key'      => (string) ($card['key'] ?? ''),
            'title'    => (string) ($card['title'] ?? ''),
            'message'  => (string) ($card['message'] ?? ''),
            'score'    => (int) ($card['score'] ?? 0),
            'actions'  => is_array($card['actions'] ?? null) ? $card['actions'] : [],
            'severity' => (string) ($card['severity'] ?? ''),
            'metric'   => (string) ($card['metric'] ?? ''),
            'reason'   => $reason,
            'why'      => $why,
        ];
    }

    private static function normalize_insights_input($insights): array
    {
        $empty = self::empty_groups();

        if (!is_array($insights) || empty($insights)) {
            return $empty;
        }

        if (
            array_key_exists('positives', $insights) ||
            array_key_exists('warnings', $insights) ||
            array_key_exists('risks', $insights) ||
            array_key_exists('recommendations', $insights)
        ) {
            return [
                'positives'       => is_array($insights['positives'] ?? null) ? array_values($insights['positives']) : [],
                'warnings'        => is_array($insights['warnings'] ?? null) ? array_values($insights['warnings']) : [],
                'risks'           => is_array($insights['risks'] ?? null) ? array_values($insights['risks']) : [],
                'recommendations' => is_array($insights['recommendations'] ?? null) ? array_values($insights['recommendations']) : [],
            ];
        }

        if (!isset($insights[0]) || !is_array($insights[0])) {
            return $empty;
        }

        foreach ($insights as $card) {
            if (!is_array($card)) {
                continue;
            }

            $type = sanitize_key((string) ($card['type'] ?? 'warning'));
            $item = self::normalize_card($card);

            if ($type === 'positive' || $type === 'success') {
                $empty['positives'][] = $item;
            } elseif ($type === 'risk') {
                $empty['risks'][] = $item;
            } elseif ($type === 'recommendation') {
                $empty['recommendations'][] = $item;
            } else {
                $empty['warnings'][] = $item;
            }
        }

        return $empty;
    }

    private static function normalize_cards_group($cards): array
    {
        if (!is_array($cards)) {
            return [];
        }

        $out = [];

        foreach ($cards as $card) {
            if (is_array($card)) {
                $out[] = self::normalize_card($card);
            }
        }

        return $out;
    }

    public static function render(array $insights, array $args = []): string
    {
        self::enqueue_assets();

        $args = wp_parse_args($args, [
            'title'   => __('AI Insights', 'verdantcart-ai-reports'),
            'context' => is_admin() ? 'admin' : 'front',
        ]);

        $title   = (string) $args['title'];
        $context = sanitize_key((string) $args['context']);

        if (!in_array($context, ['admin', 'front'], true)) {
            $context = 'front';
        }

        $norm = self::normalize_insights_input($insights);

        $positives = self::normalize_cards_group($norm['positives'] ?? []);
        $warnings  = self::normalize_cards_group($norm['warnings'] ?? []);
        $risks     = self::normalize_cards_group($norm['risks'] ?? []);
        $recs      = self::normalize_cards_group($norm['recommendations'] ?? []);

        $has_any_items = !empty($positives) || !empty($warnings) || !empty($risks) || !empty($recs);

        ob_start();
?>
        <div class="gc-insights" data-context="<?php echo esc_attr($context); ?>">
            <div class="gc-insights__header">
                <div class="gc-insights__headerLeft">
                    <h2 class="gc-insights__title"><?php echo esc_html($title); ?></h2>
                </div>
            </div>

            <?php if (!$has_any_items) : ?>
                <div class="gc-insights__empty">
                    <?php echo esc_html__('No insights available yet.', 'verdantcart-ai-reports'); ?>
                </div>
            <?php else : ?>
                <div class="gc-insights__filters" role="tablist" aria-label="<?php echo esc_attr__('Filter insights', 'verdantcart-ai-reports'); ?>">
                    <button type="button" class="gc-insights__filter is-active" data-filter="all" aria-pressed="true">
                        <?php echo esc_html__('All', 'verdantcart-ai-reports'); ?>
                    </button>
                    <button type="button" class="gc-insights__filter" data-filter="positive" aria-pressed="false">
                        <?php echo esc_html__('Positive', 'verdantcart-ai-reports'); ?>
                    </button>
                    <button type="button" class="gc-insights__filter" data-filter="warning" aria-pressed="false">
                        <?php echo esc_html__('Warnings', 'verdantcart-ai-reports'); ?>
                    </button>
                    <button type="button" class="gc-insights__filter" data-filter="risk" aria-pressed="false">
                        <?php echo esc_html__('Risks', 'verdantcart-ai-reports'); ?>
                    </button>
                </div>

                <div class="gc-insights__grid">
                    <?php echo wp_kses_post(self::render_group('positive', __('Positive', 'verdantcart-ai-reports'), $positives)); ?>
                    <?php echo wp_kses_post(self::render_group('warning', __('Warnings', 'verdantcart-ai-reports'), $warnings)); ?>
                    <?php echo wp_kses_post(self::render_group('risk', __('Risks', 'verdantcart-ai-reports'), $risks)); ?>
                </div>

                <div class="gc-insights__recs">
                    <h3 class="gc-insights__subtitle">
                        <?php echo esc_html__('Recommendations', 'verdantcart-ai-reports'); ?>
                    </h3>

                    <?php
                    echo wp_kses_post(
                        self::render_list(
                            $recs,
                            [
                                'empty' => __('No recommendations yet for this period.', 'verdantcart-ai-reports'),
                            ]
                        )
                    );
                    ?>
                </div>
            <?php endif; ?>
        </div>
    <?php

        return (string) ob_get_clean();
    }

    private static function render_group(string $type, string $label, array $items): string
    {
        $type        = sanitize_key($type);
        $count_total = count($items);

        ob_start();
    ?>
        <div class="gc-insights__group" data-group="<?php echo esc_attr($type); ?>">
            <div class="gc-insights__card gc-insights__card--<?php echo esc_attr($type); ?>">
                <div class="gc-insights__cardHead">
                    <div class="gc-insights__badge gc-insights__badge--<?php echo esc_attr($type); ?>">
                        <?php echo esc_html($label); ?>
                    </div>
                    <div class="gc-insights__count"><?php echo (int) $count_total; ?></div>
                </div>

                <?php
                echo wp_kses_post(
                    self::render_list(
                        $items,
                        [
                            'empty' => __('Nothing detected for this period.', 'verdantcart-ai-reports'),
                        ]
                    )
                );
                ?>
            </div>
        </div>
<?php
        return (string) ob_get_clean();
    }

    private static function render_list(array $items, array $opts = []): string
    {
        $empty = (string) ($opts['empty'] ?? '');
        $rows  = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $reason = trim((string) ($item['reason'] ?? ''));
            $why    = trim((string) ($item['why'] ?? ''));

            if ($why === '' && $reason !== '') {
                $why = $reason;
            }

            $rows[] = [
                'title'    => trim((string) ($item['title'] ?? '')),
                'message'  => trim((string) ($item['message'] ?? '')),
                'metric'   => trim((string) ($item['metric'] ?? '')),
                'severity' => trim((string) ($item['severity'] ?? '')),
                'why'      => $why,
                'actions'  => is_array($item['actions'] ?? null) ? $item['actions'] : [],
            ];
        }

        if (empty($rows)) {
            return '<div class="gc-insights__empty">' . esc_html($empty) . '</div>';
        }

        ob_start();

        echo '<ul class="gc-insights__list">';

        foreach ($rows as $row) {
            echo '<li class="gc-insights__li">';

            if ($row['title'] !== '') {
                echo '<div class="gc-insights__liTitle">' . esc_html($row['title']) . '</div>';
            }

            if ($row['message'] !== '') {
                echo '<div class="gc-insights__liMsg">' . esc_html($row['message']) . '</div>';
            }

            if ($row['metric'] !== '') {
                echo '<div class="gc-insights__meta">';
                echo '<span class="gc-insights__metric">' . esc_html($row['metric']) . '</span>';
                echo '</div>';
            }

            if ($row['why'] !== '') {
                echo '<details class="gc-insights__why">';
                echo '<summary>' . esc_html__('Why this appears', 'verdantcart-ai-reports') . '</summary>';
                echo '<p>' . esc_html($row['why']) . '</p>';
                echo '</details>';
            }

            if (!empty($row['actions'])) {
                echo '<ul class="gc-insights__actions">';

                foreach ($row['actions'] as $action) {
                    $action = is_string($action) ? trim($action) : '';

                    if ($action === '') {
                        continue;
                    }

                    echo '<li>' . esc_html($action) . '</li>';
                }

                echo '</ul>';
            }

            echo '</li>';
        }

        echo '</ul>';

        return (string) ob_get_clean();
    }
}
