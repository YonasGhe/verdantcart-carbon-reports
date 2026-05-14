<?php
defined('ABSPATH') || exit;

final class VCARB_Insights
{
    public static function analyze($current, $previous = null, array $breakdown = [], array $opts = []): array
    {
        $thresholds = wp_parse_args($opts, self::default_thresholds());

        $co2_drop_positive_pct    = (float) ($thresholds['co2_drop_positive_pct'] ?? 10.0);
        $co2_rise_warning_pct     = (float) ($thresholds['co2_rise_warning_pct'] ?? 15.0);
        $co2_rise_risk_pct        = (float) ($thresholds['co2_rise_risk_pct'] ?? 20.0);
        $co2_spike_risk_pct       = (float) ($thresholds['co2_spike_risk_pct'] ?? 50.0);
        $orders_drop_risk_pct     = (float) ($thresholds['orders_drop_risk_pct'] ?? 15.0);
        $orders_rise_positive_pct = (float) ($thresholds['orders_rise_positive_pct'] ?? 10.0);
        $co2_flat_positive_pct    = (float) ($thresholds['co2_flat_positive_pct'] ?? 5.0);
        $hotspot_warning_share    = (float) ($thresholds['hotspot_warning_share'] ?? 0.50);
        $hotspot_risk_share       = (float) ($thresholds['hotspot_risk_share'] ?? 0.65);
        $co2_per_order_warning    = (float) ($thresholds['co2_per_order_warning'] ?? 2.50);

        $limit_per_group = isset($opts['limit_per_group']) ? absint($opts['limit_per_group']) : 5;
        $limit_per_group = max(1, min(10, $limit_per_group));

        $current  = self::normalize_period_row($current);
        $previous = self::normalize_period_row($previous, true);

        $orders_now = (int) ($current->orders ?? 0);
        $co2_now    = (float) ($current->total_co2 ?? 0.0);

        $orders_prev = $previous ? (int) ($previous->orders ?? 0) : null;
        $co2_prev    = $previous ? (float) ($previous->total_co2 ?? 0.0) : null;

        $orders_delta_pct = self::pct_change($orders_now, $orders_prev);
        $co2_delta_pct    = self::pct_change($co2_now, $co2_prev);

        $eff_now = ($orders_now > 0)
            ? ($co2_now / $orders_now)
            : null;

        $eff_prev = ($orders_prev !== null && $orders_prev > 0 && $co2_prev !== null)
            ? ($co2_prev / $orders_prev)
            : null;

        $eff_delta_pct = self::pct_change($eff_now, $eff_prev);

        $shipping_share = self::shipping_share($breakdown, $co2_now);
        $hotspot        = self::hotspot_dominance($breakdown, $co2_now);

        $meta = [
            'orders_now'         => $orders_now,
            'orders_prev'        => $orders_prev,
            'co2_now'            => $co2_now,
            'co2_prev'           => $co2_prev,
            'orders_delta_pct'   => $orders_delta_pct,
            'co2_delta_pct'      => $co2_delta_pct,
            'co2_per_order_now'  => $eff_now,
            'co2_per_order_prev' => $eff_prev,
            'eff_delta_pct'      => $eff_delta_pct,
            'shipping_share'     => $shipping_share,
            'has_previous'       => $previous !== null,
            'hotspot'            => $hotspot,
        ];

        $insights = [
            'score'           => self::build_score($meta, $thresholds),
            'positives'       => [],
            'warnings'        => [],
            'risks'           => [],
            'recommendations' => [],
            'meta'            => $meta,
        ];

        if ($orders_now === 0) {
            $insights['warnings'][] = self::item(
                'inactive_store',
                __('Inactive store', 'verdantcart-ai-reports'),
                __('No completed or processing orders were detected for this period.', 'verdantcart-ai-reports'),
                [
                    __('Check WooCommerce order statuses.', 'verdantcart-ai-reports'),
                    __('Verify checkout and payment flow.', 'verdantcart-ai-reports'),
                ],
                'warning',
                [
                    'score'    => 90,
                    'severity' => 'medium',
                    'metric'   => '0',
                    'reason'   => __('This appears because the selected snapshot period has zero orders.', 'verdantcart-ai-reports'),
                ]
            );
        }

        if ($previous === null) {
            $insights['recommendations'][] = self::item(
                'no_previous_snapshot',
                __('Build more reporting history', 'verdantcart-ai-reports'),
                __('No previous snapshot was available for comparison.', 'verdantcart-ai-reports'),
                [
                    __('Continue generating snapshots so trends become more meaningful.', 'verdantcart-ai-reports'),
                    __('Use historical browsing once more periods are available.', 'verdantcart-ai-reports'),
                ],
                'recommendation',
                [
                    'score'    => 60,
                    'severity' => 'low',
                    'reason'   => __('This appears because the plugin could not compare the selected period with a previous snapshot.', 'verdantcart-ai-reports'),
                ]
            );
        }

        if ($co2_delta_pct !== null && $co2_delta_pct > $co2_rise_risk_pct) {
            $insights['risks'][] = self::item(
                'co2_risk_up',
                __('CO₂ increased sharply', 'verdantcart-ai-reports'),
                sprintf(
                    /* translators: %s: percentage increase versus previous period. */
                    __('Total CO₂ is up %s versus the previous period.', 'verdantcart-ai-reports'),
                    self::fmt_pct($co2_delta_pct)
                ),
                [
                    __('Review shipping zones and carrier changes.', 'verdantcart-ai-reports'),
                    __('Check whether a few large orders caused the spike.', 'verdantcart-ai-reports'),
                ],
                'risk',
                [
                    'score'    => 95,
                    'severity' => 'high',
                    'metric'   => self::fmt_pct($co2_delta_pct),
                    'reason'   => __('This appears because total CO₂ increased above the high-risk threshold.', 'verdantcart-ai-reports'),
                ]
            );
        }

        if ($orders_delta_pct !== null && $co2_delta_pct !== null && $orders_delta_pct > 0.0 && $co2_delta_pct < 0.0) {
            $insights['positives'][] = self::item(
                'efficiency_improved',
                __('Efficiency improved', 'verdantcart-ai-reports'),
                sprintf(
                    /* translators: 1: CO2 percentage change, 2: orders percentage change. */
                    __('Orders increased while total CO₂ decreased (%1$s CO₂, %2$s orders).', 'verdantcart-ai-reports'),
                    self::fmt_signed_pct($co2_delta_pct),
                    self::fmt_signed_pct($orders_delta_pct)
                ),
                [
                    __('Keep the changes that improved efficiency.', 'verdantcart-ai-reports'),
                    __('Monitor the next snapshot to confirm the improvement holds.', 'verdantcart-ai-reports'),
                ],
                'positive',
                [
                    'score'    => 90,
                    'severity' => 'low',
                    'metric'   => self::fmt_signed_pct($co2_delta_pct),
                    'reason'   => __('This appears because order volume increased while estimated emissions decreased.', 'verdantcart-ai-reports'),
                ]
            );
        }

        if ($eff_delta_pct !== null && $eff_delta_pct > 10.0) {
            $insights['risks'][] = self::item(
                'efficiency_regression',
                __('Efficiency regression detected', 'verdantcart-ai-reports'),
                sprintf(
                    /* translators: %s: percentage increase in CO2 per order. */
                    __('CO₂ per order increased %s versus the previous period.', 'verdantcart-ai-reports'),
                    self::fmt_pct($eff_delta_pct)
                ),
                [
                    __('Check shipping methods, packaging weight, and product mix changes.', 'verdantcart-ai-reports'),
                    __('Look for high-CO₂ orders driving the average.', 'verdantcart-ai-reports'),
                ],
                'risk',
                [
                    'score'    => 92,
                    'severity' => 'high',
                    'metric'   => self::fmt_pct($eff_delta_pct),
                    'reason'   => __('This appears because average CO₂ per order increased above the efficiency threshold.', 'verdantcart-ai-reports'),
                ]
            );
        }

        if (
            $co2_delta_pct !== null &&
            $orders_delta_pct !== null &&
            $co2_delta_pct >= 10.0 &&
            abs($orders_delta_pct) <= 5.0
        ) {
            $insights['warnings'][] = self::item(
                'co2_up_orders_flat',
                __('Emissions increased without growth', 'verdantcart-ai-reports'),
                sprintf(
                    /* translators: %s: percentage increase in total CO2. */
                    __('Total CO₂ rose %s while order volume stayed roughly flat.', 'verdantcart-ai-reports'),
                    self::fmt_pct($co2_delta_pct)
                ),
                [
                    __('Review shipping zones and carrier changes in this period.', 'verdantcart-ai-reports'),
                    __('Check whether basket size or long-distance orders increased.', 'verdantcart-ai-reports'),
                ],
                'warning',
                [
                    'score'    => 85,
                    'severity' => 'medium',
                    'metric'   => self::fmt_pct($co2_delta_pct),
                    'reason'   => __('This appears because emissions increased while orders did not grow significantly.', 'verdantcart-ai-reports'),
                ]
            );
        }

        if ($shipping_share !== null && $shipping_share >= 0.55) {
            $insights['recommendations'][] = self::item(
                'shipping_dominates',
                __('Shipping is the main driver', 'verdantcart-ai-reports'),
                sprintf(
                    /* translators: %s: shipping share percentage of total CO2. */
                    __('Shipping accounts for about %s of total CO₂ in this period.', 'verdantcart-ai-reports'),
                    self::fmt_pct($shipping_share * 100.0)
                ),
                [
                    __('Test lighter packaging and fewer parcels per order.', 'verdantcart-ai-reports'),
                    __('Consider optimizing shipping zones or carrier selection.', 'verdantcart-ai-reports'),
                ],
                'recommendation',
                [
                    'score'    => 78,
                    'severity' => 'medium',
                    'metric'   => self::fmt_pct($shipping_share * 100.0),
                    'reason'   => __('This appears because shipping represents more than half of the selected period’s estimated emissions.', 'verdantcart-ai-reports'),
                ]
            );
        }

        if ($co2_delta_pct !== null && $co2_delta_pct <= (-1 * $co2_drop_positive_pct)) {
            $insights['positives'][] = self::item(
                'co2_down',
                __('Emissions decreased', 'verdantcart-ai-reports'),
                sprintf(
                    /* translators: %s: percentage decrease in total CO2. */
                    __('Total CO₂ is down %s versus the previous period.', 'verdantcart-ai-reports'),
                    self::fmt_pct(abs($co2_delta_pct))
                ),
                [
                    __('Keep the changes that drove this drop and monitor whether it holds next period.', 'verdantcart-ai-reports'),
                ],
                'positive',
                [
                    'score'    => 80,
                    'severity' => 'low',
                    'metric'   => self::fmt_signed_pct($co2_delta_pct),
                    'reason'   => __('This appears because total estimated CO₂ decreased beyond the positive threshold.', 'verdantcart-ai-reports'),
                ]
            );
        }

        if (
            $co2_delta_pct !== null &&
            $co2_delta_pct >= $co2_rise_warning_pct &&
            $co2_delta_pct <= $co2_rise_risk_pct
        ) {
            $insights['warnings'][] = self::item(
                'co2_up',
                __('Emissions increased', 'verdantcart-ai-reports'),
                sprintf(
                    /* translators: %s: percentage increase in total CO2. */
                    __('Total CO₂ is up %s versus the previous period.', 'verdantcart-ai-reports'),
                    self::fmt_pct($co2_delta_pct)
                ),
                [
                    __('Review product mix, shipping zones, and packaging changes in this period.', 'verdantcart-ai-reports'),
                ],
                'warning',
                [
                    'score'    => 72,
                    'severity' => 'medium',
                    'metric'   => self::fmt_pct($co2_delta_pct),
                    'reason'   => __('This appears because total estimated CO₂ increased above the warning threshold.', 'verdantcart-ai-reports'),
                ]
            );
        }

        if ($orders_delta_pct !== null && $co2_delta_pct !== null) {
            if (
                $orders_delta_pct <= (-1 * $orders_drop_risk_pct) &&
                $co2_delta_pct >= $co2_rise_risk_pct
            ) {
                $insights['risks'][] = self::item(
                    'orders_down_co2_up',
                    __('Efficiency dropped sharply', 'verdantcart-ai-reports'),
                    __('Orders fell while total CO₂ increased, so emissions per order likely worsened.', 'verdantcart-ai-reports'),
                    [
                        __('Check if a few heavy shipments dominated this period.', 'verdantcart-ai-reports'),
                        __('Review shipping method changes and packaging weight.', 'verdantcart-ai-reports'),
                    ],
                    'risk',
                    [
                        'score'    => 94,
                        'severity' => 'high',
                        'reason'   => __('This appears because orders decreased while estimated emissions increased.', 'verdantcart-ai-reports'),
                    ]
                );
            }

            if (
                $orders_delta_pct >= $orders_rise_positive_pct &&
                $co2_delta_pct <= $co2_flat_positive_pct
            ) {
                $insights['positives'][] = self::item(
                    'orders_up_co2_flat',
                    __('Scale without higher emissions', 'verdantcart-ai-reports'),
                    __('Orders increased without increasing total CO₂ significantly.', 'verdantcart-ai-reports'),
                    [
                        __('This indicates improving efficiency. Keep optimizations and monitor hotspots.', 'verdantcart-ai-reports'),
                    ],
                    'positive',
                    [
                        'score'    => 82,
                        'severity' => 'low',
                        'reason'   => __('This appears because order growth was stronger than emissions growth.', 'verdantcart-ai-reports'),
                    ]
                );
            }
        }

        if ($co2_delta_pct !== null && $co2_delta_pct >= $co2_spike_risk_pct) {
            $insights['risks'][] = self::item(
                'abnormal_spike',
                __('Abnormal CO₂ spike', 'verdantcart-ai-reports'),
                sprintf(
                    /* translators: %s: percentage increase in CO2 versus previous period. */
                    __('CO₂ increased %s versus the previous period. This may indicate an outlier event.', 'verdantcart-ai-reports'),
                    self::fmt_pct($co2_delta_pct)
                ),
                [
                    __('Inspect orders with long-distance shipping or unusually large baskets.', 'verdantcart-ai-reports'),
                    __('Check whether refunds, returns, or unusual order patterns affected the snapshot.', 'verdantcart-ai-reports'),
                ],
                'risk',
                [
                    'score'    => 96,
                    'severity' => 'high',
                    'metric'   => self::fmt_pct($co2_delta_pct),
                    'reason'   => __('This appears because the emissions increase crossed the abnormal spike threshold.', 'verdantcart-ai-reports'),
                ]
            );
        }

        if ($hotspot) {
            [$driver, $share] = $hotspot;

            if ($share >= $hotspot_risk_share) {
                $insights['risks'][] = self::item(
                    'hotspot_dominance_risk',
                    __('One hotspot dominates emissions', 'verdantcart-ai-reports'),
                    sprintf(
                        /* translators: 1: emissions driver label, 2: percentage share of emissions. */
                        __('%1$s accounts for about %2$s of emissions this period.', 'verdantcart-ai-reports'),
                        self::label_driver((string) $driver),
                        self::fmt_pct((float) $share * 100.0)
                    ),
                    [
                        sprintf(
                            /* translators: %s: emissions driver label. */
                            __('Focus improvements on %s first because it has the biggest leverage.', 'verdantcart-ai-reports'),
                            self::label_driver((string) $driver)
                        ),
                        __('Review carrier, packaging, product weight, or routing choices for this driver.', 'verdantcart-ai-reports'),
                    ],
                    'risk',
                    [
                        'score'    => 88,
                        'severity' => 'high',
                        'metric'   => self::fmt_pct((float) $share * 100.0),
                        'reason'   => __('This appears because one emissions driver is above the hotspot risk threshold.', 'verdantcart-ai-reports'),
                    ]
                );
            } elseif ($share >= $hotspot_warning_share) {
                $insights['warnings'][] = self::item(
                    'hotspot_dominance_warning',
                    __('Hotspot concentration detected', 'verdantcart-ai-reports'),
                    sprintf(
                        /* translators: 1: emissions driver label, 2: percentage share of emissions. */
                        __('%1$s accounts for about %2$s of emissions.', 'verdantcart-ai-reports'),
                        self::label_driver((string) $driver),
                        self::fmt_pct((float) $share * 100.0)
                    ),
                    [
                        sprintf(
                            /* translators: %s: emissions driver label. */
                            __('Target %s optimizations to reduce total footprint fastest.', 'verdantcart-ai-reports'),
                            self::label_driver((string) $driver)
                        ),
                    ],
                    'warning',
                    [
                        'score'    => 74,
                        'severity' => 'medium',
                        'metric'   => self::fmt_pct((float) $share * 100.0),
                        'reason'   => __('This appears because one emissions driver is above the hotspot warning threshold.', 'verdantcart-ai-reports'),
                    ]
                );
            }
        }

        $insights['recommendations'] = array_merge(
            $insights['recommendations'],
            self::generate_recommendations($insights, [
                'co2_per_order_warning' => $co2_per_order_warning,
            ])
        );

        $insights = self::dedupe_and_limit($insights, $limit_per_group);

        /*
         * New filter first.
         */
        $insights = apply_filters(
            'vcarb_insights_analyze',
            $insights,
            $current,
            $previous,
            $breakdown,
            $thresholds
        );

        /*
         * Legacy filter kept for existing custom snippets/extensions.
         */
        return apply_filters(
            'amatorcarbon_insights_analyze',
            $insights,
            $current,
            $previous,
            $breakdown,
            $thresholds
        );
    }

    private static function generate_recommendations(array $insights, array $thresholds): array
    {
        $recs = [];

        $meta               = is_array($insights['meta'] ?? null) ? $insights['meta'] : [];
        $orders_now         = (int) ($meta['orders_now'] ?? 0);
        $co2_now            = (float) ($meta['co2_now'] ?? 0.0);
        $co2_per_order_warn = (float) ($thresholds['co2_per_order_warning'] ?? 0.0);
        $eff                = $meta['co2_per_order_now'] ?? null;

        if ($orders_now === 0) {
            $recs[] = self::item(
                'rec_check_orders',
                __('Verify order pipeline', 'verdantcart-ai-reports'),
                __('No orders were detected in this period.', 'verdantcart-ai-reports'),
                [
                    __('Confirm the WooCommerce order status filter.', 'verdantcart-ai-reports'),
                    __('Check checkout and payment gateway logs.', 'verdantcart-ai-reports'),
                ],
                'recommendation',
                [
                    'score'    => 62,
                    'severity' => 'low',
                    'reason'   => __('This recommendation appears because the selected snapshot has no orders.', 'verdantcart-ai-reports'),
                ]
            );

            return $recs;
        }

        if (is_numeric($eff) && (float) $eff >= $co2_per_order_warn) {
            $recs[] = self::item(
                'rec_reduce_efficiency',
                __('Reduce CO₂ per order', 'verdantcart-ai-reports'),
                sprintf(
                    /* translators: %s: current CO2 per order in kg. */
                    __('Current CO₂ per order is about %s kg.', 'verdantcart-ai-reports'),
                    number_format_i18n((float) $eff, 2)
                ),
                [
                    __('Review packaging weight and materials.', 'verdantcart-ai-reports'),
                    __('Offer local pickup or greener shipping options where possible.', 'verdantcart-ai-reports'),
                    __('Bundle shipments to reduce delivery trips.', 'verdantcart-ai-reports'),
                ],
                'recommendation',
                [
                    'score'    => 78,
                    'severity' => 'medium',
                    'metric'   => number_format_i18n((float) $eff, 2) . ' kg',
                    'reason'   => __('This recommendation appears because CO₂ per order is above the configured threshold.', 'verdantcart-ai-reports'),
                ]
            );
        }

        if (!empty($insights['risks'])) {
            $recs[] = self::item(
                'rec_monitor_next',
                __('Monitor the next snapshot', 'verdantcart-ai-reports'),
                __('Risks were detected this period. Validate whether they persist next period.', 'verdantcart-ai-reports'),
                [
                    __('Compare the same drivers and top orders next period.', 'verdantcart-ai-reports'),
                    __('Review spikes weekly or monthly depending on your reporting view.', 'verdantcart-ai-reports'),
                ],
                'recommendation',
                [
                    'score'    => 74,
                    'severity' => 'medium',
                    'reason'   => __('This recommendation appears because one or more risk insights were detected.', 'verdantcart-ai-reports'),
                ]
            );
        }

        if ($co2_now > 0.0) {
            $recs[] = self::item(
                'rec_fast_win',
                __('Focus on the top drivers first', 'verdantcart-ai-reports'),
                __('Reducing the biggest emissions drivers first gives the fastest improvement opportunity.', 'verdantcart-ai-reports'),
                [
                    __('Identify the top products, shipping zones, or order types contributing to emissions.', 'verdantcart-ai-reports'),
                    __('Optimize the largest driver before spending time on smaller ones.', 'verdantcart-ai-reports'),
                ],
                'recommendation',
                [
                    'score'    => 72,
                    'severity' => 'medium',
                    'reason'   => __('This recommendation appears because the period has measurable estimated emissions.', 'verdantcart-ai-reports'),
                ]
            );
        }

        return $recs;
    }

    private static function build_score(array $meta, array $thresholds): array
    {
        $orders_now       = (int) ($meta['orders_now'] ?? 0);
        $co2_now          = (float) ($meta['co2_now'] ?? 0.0);
        $has_previous     = !empty($meta['has_previous']);
        $co2_delta_pct    = $meta['co2_delta_pct'] ?? null;
        $orders_delta_pct = $meta['orders_delta_pct'] ?? null;
        $eff_delta_pct    = $meta['eff_delta_pct'] ?? null;
        $hotspot          = $meta['hotspot'] ?? null;

        $hotspot_warning_share = (float) ($thresholds['hotspot_warning_share'] ?? 0.50);
        $hotspot_risk_share    = (float) ($thresholds['hotspot_risk_share'] ?? 0.65);

        $components = [
            'emissions_trend'       => 18,
            'emissions_per_order'   => 15,
            'hotspot_concentration' => 16,
            'data_completeness'     => 10,
            'reporting_consistency' => $has_previous ? 10 : 5,
        ];

        if ($has_previous && is_numeric($co2_delta_pct)) {
            $co2_delta_pct = (float) $co2_delta_pct;

            if ($co2_delta_pct <= -10.0) {
                $components['emissions_trend'] = 30;
            } elseif ($co2_delta_pct <= 0.0) {
                $components['emissions_trend'] = 26;
            } elseif (
                is_numeric($orders_delta_pct) &&
                (float) $orders_delta_pct > 0.0 &&
                $co2_delta_pct < (float) $orders_delta_pct
            ) {
                $components['emissions_trend'] = 22;
            } elseif ($co2_delta_pct < 15.0) {
                $components['emissions_trend'] = 16;
            } elseif ($co2_delta_pct < 25.0) {
                $components['emissions_trend'] = 10;
            } else {
                $components['emissions_trend'] = 5;
            }
        }

        if ($has_previous && is_numeric($eff_delta_pct)) {
            $eff_delta_pct = (float) $eff_delta_pct;

            if ($eff_delta_pct <= -10.0) {
                $components['emissions_per_order'] = 25;
            } elseif ($eff_delta_pct <= 0.0) {
                $components['emissions_per_order'] = 22;
            } elseif ($eff_delta_pct <= 10.0) {
                $components['emissions_per_order'] = 16;
            } elseif ($eff_delta_pct <= 25.0) {
                $components['emissions_per_order'] = 9;
            } else {
                $components['emissions_per_order'] = 4;
            }
        }

        if (is_array($hotspot) && isset($hotspot[1]) && is_numeric($hotspot[1])) {
            $share = (float) $hotspot[1];

            if ($share < 0.35) {
                $components['hotspot_concentration'] = 20;
            } elseif ($share < $hotspot_warning_share) {
                $components['hotspot_concentration'] = 15;
            } elseif ($share < $hotspot_risk_share) {
                $components['hotspot_concentration'] = 9;
            } else {
                $components['hotspot_concentration'] = 4;
            }
        } elseif ($co2_now > 0.0) {
            $components['hotspot_concentration'] = 18;
        }

        if ($orders_now <= 0) {
            $components['data_completeness'] = 5;
        } elseif ($co2_now <= 0.0) {
            $components['data_completeness'] = 7;
        } else {
            $components['data_completeness'] = 15;
        }

        $value = max(0, min(100, (int) round(array_sum($components))));

        return [
            'value'      => $value,
            'label'      => self::score_label($value),
            'summary'    => self::score_summary($value, $meta),
            'components' => $components,
        ];
    }

    private static function score_label(int $score): string
    {
        if ($score >= 85) {
            return __('Excellent', 'verdantcart-ai-reports');
        }

        if ($score >= 70) {
            return __('Good', 'verdantcart-ai-reports');
        }

        if ($score >= 50) {
            return __('Fair', 'verdantcart-ai-reports');
        }

        return __('Needs work', 'verdantcart-ai-reports');
    }

    private static function score_summary(int $score, array $meta): string
    {
        $has_previous  = !empty($meta['has_previous']);
        $co2_delta_pct = $meta['co2_delta_pct'] ?? null;
        $eff_delta_pct = $meta['eff_delta_pct'] ?? null;

        if (!$has_previous) {
            return __('This score is based on the selected snapshot. Add more snapshot history for stronger trend analysis.', 'verdantcart-ai-reports');
        }

        if (is_numeric($co2_delta_pct) && (float) $co2_delta_pct < 0.0) {
            return __('Your estimated emissions decreased compared with the previous snapshot period.', 'verdantcart-ai-reports');
        }

        if (is_numeric($eff_delta_pct) && (float) $eff_delta_pct < 0.0) {
            return __('Your CO₂ per order improved compared with the previous snapshot period.', 'verdantcart-ai-reports');
        }

        if ($score >= 70) {
            return __('Your snapshot data shows a stable sustainability profile for this period.', 'verdantcart-ai-reports');
        }

        return __('This period has improvement opportunities based on emissions trend, efficiency, or hotspot concentration.', 'verdantcart-ai-reports');
    }

    private static function dedupe_and_limit(array $insights, int $limit): array
    {
        foreach (['positives', 'warnings', 'risks', 'recommendations'] as $group) {
            if (empty($insights[$group]) || !is_array($insights[$group])) {
                $insights[$group] = [];
                continue;
            }

            $seen  = [];
            $clean = [];

            foreach ($insights[$group] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $key = sanitize_key((string) ($item['key'] ?? ''));

                if ($key === '') {
                    $json = wp_json_encode($item);
                    $key  = is_string($json) ? md5($json) : md5(maybe_serialize($item));
                }

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $clean[]    = $item;
            }

            usort(
                $clean,
                static function (array $a, array $b): int {
                    return (int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0);
                }
            );

            $insights[$group] = array_slice($clean, 0, $limit);
        }

        return $insights;
    }

    private static function normalize_period_row($row, bool $nullable = false): ?object
    {
        if ($row === null) {
            return $nullable ? null : (object) [
                'orders'    => 0,
                'total_co2' => 0.0,
            ];
        }

        if (is_object($row)) {
            return (object) [
                'orders'    => (int) ($row->orders ?? 0),
                'total_co2' => (float) ($row->total_co2 ?? 0.0),
            ];
        }

        if (is_array($row)) {
            return (object) [
                'orders'    => (int) ($row['orders'] ?? 0),
                'total_co2' => (float) ($row['total_co2'] ?? 0.0),
            ];
        }

        return $nullable ? null : (object) [
            'orders'    => 0,
            'total_co2' => 0.0,
        ];
    }

    private static function shipping_share(array $breakdown, float $total): ?float
    {
        if ($total <= 0.0 || empty($breakdown)) {
            return null;
        }

        if (isset($breakdown['shipping']) && is_numeric($breakdown['shipping'])) {
            return min(1.0, max(0.0, (float) $breakdown['shipping'] / $total));
        }

        if (isset($breakdown['shipping_co2']) && is_numeric($breakdown['shipping_co2'])) {
            return min(1.0, max(0.0, (float) $breakdown['shipping_co2'] / $total));
        }

        return null;
    }

    private static function hotspot_dominance(array $breakdown, float $total): ?array
    {
        if ($total <= 0.0 || empty($breakdown)) {
            return null;
        }

        $max_driver = null;
        $max_value  = 0.0;

        foreach ($breakdown as $driver => $value) {
            if (!is_numeric($value)) {
                continue;
            }

            $value = max(0.0, (float) $value);

            if ($value > $max_value) {
                $max_value  = $value;
                $max_driver = (string) $driver;
            }
        }

        if ($max_driver === null || $max_value <= 0.0) {
            return null;
        }

        return [$max_driver, min(1.0, $max_value / $total)];
    }

    private static function label_driver(string $driver): string
    {
        $driver = sanitize_key($driver);

        $map = [
            'shipping'     => __('Shipping', 'verdantcart-ai-reports'),
            'shipping_co2' => __('Shipping', 'verdantcart-ai-reports'),
            'packaging'    => __('Packaging', 'verdantcart-ai-reports'),
            'products'     => __('Products', 'verdantcart-ai-reports'),
            'product'      => __('Product', 'verdantcart-ai-reports'),
            'returns'      => __('Returns', 'verdantcart-ai-reports'),
            'energy'       => __('Energy', 'verdantcart-ai-reports'),
        ];

        if (isset($map[$driver])) {
            return $map[$driver];
        }

        $label = ucwords(str_replace('_', ' ', $driver));

        return $label !== '' ? $label : __('Unknown driver', 'verdantcart-ai-reports');
    }

    private static function default_thresholds(): array
    {
        $defaults = [
            'co2_drop_positive_pct'    => 10.0,
            'co2_rise_warning_pct'     => 15.0,
            'co2_rise_risk_pct'        => 20.0,
            'co2_spike_risk_pct'       => 50.0,
            'orders_drop_risk_pct'     => 15.0,
            'orders_rise_positive_pct' => 10.0,
            'co2_flat_positive_pct'    => 5.0,
            'hotspot_warning_share'    => 0.50,
            'hotspot_risk_share'       => 0.65,
            'co2_per_order_warning'    => 2.50,
        ];

        $thresholds = apply_filters('vcarb_insights_thresholds', $defaults);

        /*
     * Legacy filter kept for existing custom snippets/extensions.
     */
        $thresholds = apply_filters('amatorcarbon_insights_thresholds', $thresholds);

        if (!is_array($thresholds)) {
            return $defaults;
        }

        return wp_parse_args($thresholds, $defaults);
    }

    private static function pct_change($now, $prev): ?float
    {
        if (!is_numeric($now) || $prev === null || $prev === '' || !is_numeric($prev)) {
            return null;
        }

        $now  = (float) $now;
        $prev = (float) $prev;

        /*
     * No meaningful comparison when the previous value is zero.
     * This prevents first-period insights from treating 0 → 0 as a real trend.
     */
        if ($prev <= 0.0) {
            return null;
        }

        return (($now - $prev) / abs($prev)) * 100.0;
    }

    private static function fmt_pct(float $pct): string
    {
        return number_format_i18n($pct, 1) . '%';
    }

    private static function fmt_signed_pct(float $pct): string
    {
        $prefix = $pct > 0 ? '+' : '';

        return $prefix . number_format_i18n($pct, 1) . '%';
    }

    private static function item(
        string $key,
        string $title,
        string $message,
        array $actions,
        string $type,
        array $meta = []
    ): array {
        $clean_actions = [];

        foreach ($actions as $action) {
            $action = is_string($action) ? wp_strip_all_tags($action) : '';
            $action = trim($action);

            if ($action !== '') {
                $clean_actions[] = $action;
            }
        }

        $out = [
            'key'     => sanitize_key($key),
            'type'    => sanitize_key($type),
            'title'   => wp_strip_all_tags($title),
            'message' => wp_strip_all_tags($message),
            'actions' => array_values($clean_actions),
        ];

        foreach (['score', 'severity', 'reason', 'metric'] as $meta_key) {
            if (!array_key_exists($meta_key, $meta)) {
                continue;
            }

            if ($meta_key === 'score') {
                $out[$meta_key] = (int) $meta[$meta_key];
            } elseif ($meta_key === 'severity') {
                $out[$meta_key] = sanitize_key((string) $meta[$meta_key]);
            } else {
                $out[$meta_key] = wp_strip_all_tags((string) $meta[$meta_key]);
            }
        }

        return $out;
    }
}
