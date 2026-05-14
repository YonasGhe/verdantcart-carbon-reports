/**
 * VerdantCart Carbon Reports — admin dashboard script.
 *
 * Matches current admin HTML:
 * - .wrap.gc-wrap
 * - [data-gc-snap-badge]
 * - [data-gc-admin-export]
 * - [data-gc-kpi]
 * - #gcCarbonChart
 * - #gcAdminInsights
 * - #gcHotspotsPanel / #gcHotspotsBody
 * - #gcTableBody
 * - .gc-tabs a.nav-tab
 * - optional .gc-period-browser links
 */

jQuery(function ($) {
  "use strict";

  if (window.__vcarbAdminInitBound) {
    return;
  }

  window.__vcarbAdminInitBound = true;

  let chartInstance = null;
  let reportRequest = null;
  let insightsRequest = null;
  let hotspotsRequest = null;

  function cfg() {
    if (typeof window.vcarbChartAjax !== "undefined") {
      return window.vcarbChartAjax;
    }

    return {};
  }

  function escHtml(str) {
    return String(str == null ? "" : str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function hasAjax() {
    const config = cfg();

    return !!config.ajaxurl && !!config.nonceReport;
  }

  function getRoot() {
    return $(".wrap.gc-wrap").first();
  }

  function getTabs() {
    return $(".gc-tabs").first();
  }

  function getUrlParams() {
    try {
      return new URLSearchParams(window.location.search);
    } catch (e) {
      return new URLSearchParams();
    }
  }

  function getViewAndDateFromHref(href) {
    try {
      const url = new URL(href, window.location.origin);
      const view = String(url.searchParams.get("view") || getCurrentView() || "month");
      const date = String(url.searchParams.get("date") || "");

      return { view: view, date: date };
    } catch (e) {
      return { view: getCurrentView(), date: "" };
    }
  }

  function isValidSnap(view, snap) {
    const value = String(snap || "");

    if (!value) {
      return false;
    }

    if (view === "month") {
      return /^\d{4}-\d{2}$/.test(value);
    }

    if (view === "week") {
      return /^\d{4}-W\d{2}$/.test(value);
    }

    if (view === "year") {
      return /^\d{4}$/.test(value);
    }

    return false;
  }

  function getCurrentView() {
    const config = cfg();
    const $root = getRoot();

    const domView = String($root.attr("data-view") || "");
    const urlView = String(getUrlParams().get("view") || "");
    const initialView = String(config.initialView || "");

    const candidate = urlView || domView || initialView || "month";

    return ["month", "week", "year"].indexOf(candidate) !== -1 ? candidate : "month";
  }

  function getCurrentSnap(view) {
    const config = cfg();
    const $root = getRoot();
    const domDate = String($root.attr("data-date") || "");

    if (isValidSnap(view, domDate)) {
      return domDate;
    }

    if (isValidSnap(view, config.initialDate || "")) {
      return String(config.initialDate || "");
    }

    const urlDate = String(getUrlParams().get("date") || "");

    if (isValidSnap(view, urlDate)) {
      return urlDate;
    }

    return "";
  }

  function persistCanonicalState(view, date, hasSnapshot) {
    const $root = getRoot();

    if (!$root.length) {
      return;
    }

    $root.attr("data-view", view);
    $root.attr("data-has-snapshot", hasSnapshot ? "1" : "0");

    if (hasSnapshot && date) {
      $root.attr("data-date", date);
    } else {
      $root.attr("data-date", "");
    }
  }

  function replaceUrlState(view, date, hasSnapshot) {
    try {
      const url = new URL(window.location.href);

      url.searchParams.set("view", view);

      if (hasSnapshot && isValidSnap(view, date)) {
        url.searchParams.set("date", date);
      } else {
        url.searchParams.delete("date");
      }

      window.history.replaceState({}, "", url.toString());
    } catch (e) {
      // no-op
    }
  }

  function safeBuildUrl(base, params) {
    const rawBase = String(base || "");

    if (!rawBase) {
      return "";
    }

    try {
      const url = new URL(rawBase, window.location.origin);

      Object.keys(params).forEach(function (key) {
        if (params[key] === "" || params[key] == null) {
          url.searchParams.delete(key);
        } else {
          url.searchParams.set(key, params[key]);
        }
      });

      return url.toString();
    } catch (e) {
      const hasQuery = rawBase.indexOf("?") !== -1;
      const joiner = hasQuery ? "&" : "?";
      const query = Object.keys(params)
        .filter(function (key) {
          return params[key] !== "" && params[key] != null;
        })
        .map(function (key) {
          return encodeURIComponent(key) + "=" + encodeURIComponent(params[key]);
        })
        .join("&");

      return rawBase + (query ? joiner + query : "");
    }
  }

  function abortRequest(req) {
    if (req && typeof req.abort === "function") {
      try {
        req.abort();
      } catch (e) {
        // no-op
      }
    }
  }

  function setSnapshotBadge(has, period, updated) {
    const $badge = $("[data-gc-snap-badge]").first();

    if (!$badge.length) {
      return;
    }

    const ok = !!has && !!period;

    $badge.toggleClass("is-ok", ok);
    $badge.toggleClass("is-missing", !ok);

    if (!ok) {
      $badge.text("⚠️ Snapshot missing");
      return;
    }

    const updatedHtml = updated
      ? ' <span class="gc-snap-badge__muted">• Updated ' + escHtml(updated) + "</span>"
      : "";

    $badge.html("✅ Snapshot: " + escHtml(period) + updatedHtml);
  }

  function setAdminExportState(ok, view, snap) {
    const config = cfg();
    const $box = $("[data-gc-admin-export]").first();

    if (!$box.length) {
      return;
    }

    const baseCsv =
      config.exportBase && config.exportBase.csv
        ? String(config.exportBase.csv)
        : "";

    const basePdf =
      config.exportBase && config.exportBase.pdf
        ? String(config.exportBase.pdf)
        : "";

    if (!ok || !isValidSnap(view, snap) || !baseCsv || !basePdf) {
      $box.html(
        '<span class="button disabled gc-export-disabled" style="opacity:.6;cursor:not-allowed;">' +
        "Export (snapshot missing)" +
        "</span>"
      );
      return;
    }

    const csvUrl = safeBuildUrl(baseCsv, { view: view, date: snap });
    const pdfUrl = safeBuildUrl(basePdf, { view: view, date: snap });

    $box.html(
      '<a class="button gc-export-admin-link" data-format="csv" href="' +
      escHtml(csvUrl) +
      '" target="_blank" rel="noopener noreferrer">Export CSV</a>' +
      '<a class="button button-primary gc-export-admin-link" data-format="pdf" href="' +
      escHtml(pdfUrl) +
      '" target="_blank" rel="noopener noreferrer">Export PDF</a>'
    );
  }

  function initExports() {
    const config = cfg();

    const view = getCurrentView();
    const snap = getCurrentSnap(view);
    const $root = getRoot();
    const hasDomSnapshot = String($root.attr("data-has-snapshot") || "") === "1";

    const ok =
      hasDomSnapshot &&
      !!config.initialHasSnapshot &&
      isValidSnap(view, snap);

    setAdminExportState(ok, view, snap);
  }

  function getStoreMetrics(data) {
    const metrics = data && typeof data.metrics === "object" ? data.metrics : {};

    return {
      totalCo2:
        metrics.total_co2 != null && !isNaN(Number(metrics.total_co2))
          ? Number(metrics.total_co2)
          : 0,
      orders:
        metrics.orders != null && !isNaN(Number(metrics.orders))
          ? Number(metrics.orders)
          : 0,
      co2PerOrder:
        metrics.co2_per_order != null && !isNaN(Number(metrics.co2_per_order))
          ? Number(metrics.co2_per_order)
          : null,
      deltaHtml:
        metrics.delta_html != null && String(metrics.delta_html) !== ""
          ? String(metrics.delta_html)
          : "—"
    };
  }

  function setKpisFromReport(data) {
    const metrics = getStoreMetrics(data);

    $('[data-gc-kpi="co2"]').text(Number(metrics.totalCo2).toFixed(2) + " kg");
    $('[data-gc-kpi="orders"]').text(String(metrics.orders));

    $('[data-gc-kpi="co2po"]').text(
      metrics.co2PerOrder === null
        ? "—"
        : Number(metrics.co2PerOrder).toFixed(2) + " kg"
    );

    $('[data-gc-kpi="delta"]').html(metrics.deltaHtml);
  }

  function setTableLoading() {
    const $tbody = $("#gcTableBody");

    if (!$tbody.length) {
      return;
    }

    $tbody.html(
      '<tr><td colspan="6" class="gc-empty"><div class="gc-loading">Loading customer data…</div></td></tr>'
    );
  }

  function setTableError(msg) {
    const $tbody = $("#gcTableBody");

    if (!$tbody.length) {
      return;
    }

    $tbody.html(
      '<tr><td colspan="6" class="gc-empty gc-error">' + escHtml(msg) + "</td></tr>"
    );
  }

  function renderTable(rows) {
    const $tbody = $("#gcTableBody");

    if (!$tbody.length) {
      return;
    }

    if (!Array.isArray(rows) || !rows.length) {
      $tbody.html(
        '<tr><td colspan="6" class="gc-empty">No customer data found for this period.</td></tr>'
      );
      return;
    }

    const html = rows
      .map(function (row) {
        return (
          "<tr>" +
          "<td>" + escHtml(row && row.user != null ? row.user : "") + "</td>" +
          "<td>" + escHtml(row && row.orders != null ? row.orders : 0) + "</td>" +
          "<td>" + (row && row.orders_pct ? row.orders_pct : "") + "</td>" +
          "<td>" + escHtml(row && row.co2 != null ? row.co2 : "0.00") + "</td>" +
          "<td>" + (row && row.co2_pct ? row.co2_pct : "") + "</td>" +
          "<td>" + escHtml(row && row.updated != null ? row.updated : "") + "</td>" +
          "</tr>"
        );
      })
      .join("");

    $tbody.html(html);
  }

  function ensureChartEmptyState(message) {
    const $wrap = $(".gc-chart-wrap").first();

    if (!$wrap.length) {
      return;
    }

    let $empty = $wrap.find(".gc-chart-empty").first();

    if (!$empty.length) {
      $empty = $('<div class="gc-chart-empty"></div>');
      $wrap.append($empty);
    }

    $empty.html(
      '<div class="gc-empty">' +
      escHtml(message || "No chart data available for this period.") +
      "</div>"
    );

    $empty.show();
  }

  function hideChartEmptyState() {
    $(".gc-chart-wrap").find(".gc-chart-empty").hide();
  }

  function setChartEmpty(message) {
    if (chartInstance) {
      chartInstance.destroy();
      chartInstance = null;
    }

    const canvas = document.getElementById("gcCarbonChart");

    if (canvas) {
      const ctx = canvas.getContext("2d");

      if (ctx) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
      }
    }

    ensureChartEmptyState(message || "No chart data available for this period.");
  }

  function formatPeriodTitle(view, period) {
    const value = String(period || "");

    if (!value) {
      return "";
    }

    if (view === "month" && /^\d{4}-\d{2}$/.test(value)) {
      const parts = value.split("-");
      const date = new Date(Number(parts[0]), Number(parts[1]) - 1, 1);

      return date.toLocaleString(undefined, {
        month: "long",
        year: "numeric"
      });
    }

    if (view === "year" && /^\d{4}$/.test(value)) {
      return value;
    }

    if (view === "week" && /^\d{4}-W\d{2}$/.test(value)) {
      const match = value.match(/^(\d{4})-W(\d{2})$/);

      if (match) {
        return "Week " + match[2] + ", " + match[1];
      }
    }

    return value;
  }

  function centerLabelForSinglePeriod(view, selectedPeriod, fallback) {
    const period = String(selectedPeriod || "");

    if (view === "month" && /^\d{4}-\d{2}$/.test(period)) {
      const parts = period.split("-");
      const date = new Date(Number(parts[0]), Number(parts[1]) - 1, 1);

      return date.toLocaleString(undefined, { month: "short" });
    }

    if (view === "year" && /^\d{4}$/.test(period)) {
      return period;
    }

    return fallback || "";
  }

  function niceMax(value) {
    const num = Number(value || 0);

    if (num <= 0) {
      return 5;
    }

    if (num <= 2) {
      return 2.5;
    }

    if (num <= 5) {
      return 5;
    }

    if (num <= 10) {
      return 10;
    }

    if (num <= 20) {
      return 20;
    }

    if (num <= 25) {
      return 25;
    }

    if (num <= 40) {
      return 40;
    }

    if (num <= 50) {
      return 50;
    }

    if (num <= 100) {
      return 100;
    }

    return Math.ceil(num / 10) * 10;
  }

  function niceStep(max) {
    const num = Number(max || 0);

    if (num <= 2.5) {
      return 0.5;
    }

    if (num <= 5) {
      return 1;
    }

    if (num <= 10) {
      return 2;
    }

    if (num <= 20) {
      return 4;
    }

    if (num <= 25) {
      return 5;
    }

    if (num <= 40) {
      return 8;
    }

    if (num <= 50) {
      return 10;
    }

    if (num <= 100) {
      return 20;
    }

    return Math.ceil(num / 5);
  }

  function ceilTo(value, step) {
    const num = Number(value || 0);
    const size = Number(step || 1);

    if (size <= 0) {
      return num;
    }

    return Math.ceil(num / size) * size;
  }

  function renderChart(chartData, selectedPeriod, view) {
    const canvas = document.getElementById("gcCarbonChart");

    if (!canvas) {
      return;
    }

    if (typeof window.Chart === "undefined") {
      console.warn("Chart.js not loaded");
      return;
    }

    if (chartInstance) {
      chartInstance.destroy();
      chartInstance = null;
    }

    const baseLabels = Array.isArray(chartData && chartData.labels)
      ? chartData.labels.map(String)
      : [];

    const rawCo2 = Array.isArray(chartData && chartData.co2)
      ? chartData.co2.map(function (value) {
        const num = Number(value || 0);
        return Number.isFinite(num) ? num : 0;
      })
      : [];

    const rawOrders = Array.isArray(chartData && chartData.orders)
      ? chartData.orders.map(function (value) {
        const num = Number(value || 0);
        return Number.isFinite(num) ? num : 0;
      })
      : [];

    const periods = Array.isArray(chartData && chartData.periods)
      ? chartData.periods.map(String)
      : [];

    const snapshotSet =
      chartData && typeof chartData.snapshotSet === "object" && chartData.snapshotSet
        ? chartData.snapshotSet
        : {};

    const currentView = String(view || "month");
    const currentPeriod = String(selectedPeriod || "");

    if (!baseLabels.length || !rawCo2.length || !rawOrders.length) {
      setChartEmpty("No chart data available for this period.");
      return;
    }

    hideChartEmptyState();

    const ctx = canvas.getContext("2d");

    if (!ctx) {
      console.warn("Canvas context not available");
      return;
    }

    let labels = baseLabels.slice();
    let displayCo2 = rawCo2.slice();
    let displayOrders = rawOrders.slice();

    const xTitle = formatPeriodTitle(currentView, currentPeriod);
    const hasSinglePoint =
      baseLabels.length === 1 &&
      rawCo2.length === 1 &&
      rawOrders.length === 1;

    if (hasSinglePoint) {
      const realCo2 = Number(rawCo2[0] || 0);
      const realOrders = Number(rawOrders[0] || 0);

      if (currentView === "week") {
        labels = ["", baseLabels[0] || currentPeriod || "Week"];
        displayCo2 = [realCo2, realCo2];
        displayOrders = [realOrders, realOrders];
      } else {
        const endLabel =
          currentView === "month" || currentView === "year"
            ? centerLabelForSinglePeriod(currentView, currentPeriod, baseLabels[0])
            : baseLabels[0] || currentPeriod || "";

        labels = ["", endLabel];
        displayCo2 = [0, realCo2];
        displayOrders = [0, realOrders];
      }
    }

    const co2MaxRaw = Math.max.apply(null, displayCo2.concat([0]));
    const ordersMaxRaw = Math.max.apply(null, displayOrders.concat([0]));

    let co2AxisMax;
    let ordersAxisMax;
    let co2Step;
    let ordersStep;

    if (currentView === "week") {
      co2AxisMax = ceilTo(Math.max(co2MaxRaw * 1.1, co2MaxRaw + 0.25), 0.25);
      ordersAxisMax = ceilTo(Math.max(ordersMaxRaw * 1.1, ordersMaxRaw + 1), 1);

      if (co2AxisMax <= co2MaxRaw) {
        co2AxisMax = Number((co2MaxRaw + 0.25).toFixed(2));
      }

      if (ordersAxisMax <= ordersMaxRaw) {
        ordersAxisMax = ordersMaxRaw + 1;
      }

      co2Step = co2AxisMax <= 2.5 ? 0.5 : 1;
      ordersStep = ordersAxisMax <= 5 ? 1 : Math.max(1, Math.ceil(ordersAxisMax / 6));
    } else {
      co2AxisMax = niceMax(co2MaxRaw * 1.01);
      ordersAxisMax = niceMax(ordersMaxRaw * 1.01);
      co2Step = niceStep(co2AxisMax);
      ordersStep = niceStep(ordersAxisMax);
    }

    chartInstance = new Chart(ctx, {
      type: "line",
      data: {
        labels: labels,
        datasets: [
          {
            label: "CO₂ (kg)",
            data: displayCo2,
            yAxisID: "y",
            tension: 0.35,
            borderWidth: 3,
            pointRadius: 5,
            pointHoverRadius: 6,
            fill: true,
            backgroundColor: "rgba(21,128,61,0.10)",
            borderColor: "#15803d",
            pointBackgroundColor: "#15803d",
            pointBorderColor: "#fff",
            pointBorderWidth: 2
          },
          {
            label: "Orders",
            data: displayOrders,
            yAxisID: "y1",
            tension: 0.35,
            borderWidth: 3,
            pointRadius: 5,
            pointHoverRadius: 6,
            fill: false,
            backgroundColor: "rgba(59,130,246,0.10)",
            borderColor: "#3b82f6",
            pointBackgroundColor: "#3b82f6",
            pointBorderColor: "#fff",
            pointBorderWidth: 2
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          intersect: false,
          mode: "index"
        },
        onClick: function (evt) {
          if (!chartInstance) {
            return;
          }

          const pointsHit = chartInstance.getElementsAtEventForMode(
            evt,
            "nearest",
            { intersect: true },
            true
          );

          if (!pointsHit.length) {
            return;
          }

          const idx = pointsHit[0].index;
          const snap = String(periods[idx] || selectedPeriod || "");
          const ok = !!snapshotSet[snap] && isValidSnap(currentView, snap);

          setAdminExportState(ok, currentView, snap);

          if (ok) {
            persistCanonicalState(currentView, snap, true);
            replaceUrlState(currentView, snap, true);
            loadAdminInsights(currentView, snap, true);
            fetchHotspots(currentView, snap);
          }
        },
        plugins: {
          legend: {
            position: "top",
            labels: {
              usePointStyle: true,
              boxWidth: 14
            }
          },
          tooltip: {
            mode: "index",
            intersect: false,
            callbacks: {
              title: function (items) {
                if (hasSinglePoint) {
                  return xTitle || baseLabels[0] || "";
                }

                return items && items[0] ? items[0].label : "";
              },
              label: function (context) {
                const idx = context.dataIndex;

                if (hasSinglePoint) {
                  if (context.dataset.label === "CO₂ (kg)") {
                    return "CO₂ (kg): " + (rawCo2[0] || 0);
                  }

                  return "Orders: " + (rawOrders[0] || 0);
                }

                if (context.dataset.label === "CO₂ (kg)") {
                  return "CO₂ (kg): " + (rawCo2[idx] || 0);
                }

                return "Orders: " + (rawOrders[idx] || 0);
              }
            }
          }
        },
        scales: {
          x: {
            grid: {
              display: false,
              drawBorder: false
            },
            ticks: {
              display: true,
              color: "#64748b",
              font: { size: 12 },
              callback: function (value) {
                const label = this.getLabelForValue(value);

                if (hasSinglePoint && value === 0) {
                  return "";
                }

                return label;
              }
            },
            title: {
              display: !!xTitle,
              text: xTitle,
              color: "#64748b",
              font: {
                size: 13,
                weight: "600"
              },
              padding: {
                top: 12
              }
            },
            border: {
              display: false
            }
          },
          y: {
            beginAtZero: true,
            position: "left",
            max: co2AxisMax,
            grid: {
              color: "#e5e7eb"
            },
            title: {
              display: true,
              text: "CO₂ Emissions (kg)",
              color: "#15803d"
            },
            ticks: {
              color: "#15803d",
              stepSize: co2Step
            }
          },
          y1: {
            beginAtZero: true,
            position: "right",
            max: ordersAxisMax,
            grid: {
              drawOnChartArea: false
            },
            title: {
              display: true,
              text: "Orders",
              color: "#3b82f6"
            },
            ticks: {
              color: "#3b82f6",
              stepSize: ordersStep,
              precision: 0
            }
          }
        }
      }
    });
  }

  function setInsightsError(msg) {
    const $box = $("#gcAdminInsights");

    if ($box.length) {
      $box.html('<div class="gc-empty gc-error">' + escHtml(msg) + "</div>");
    }
  }

  function updateInsights(view, date, hasSnapshot) {
    const el = document.getElementById("gcAdminInsights");

    if (!el) {
      return;
    }

    if (!hasSnapshot || !date) {
      el.innerHTML = '<div class="gc-empty">No snapshot available yet.</div>';
      return;
    }

    loadAdminInsights(view, date, true);
  }

  function loadAdminInsights(view, date, hasSnapshot) {
    const config = cfg();
    const el = document.getElementById("gcAdminInsights");

    if (!el) {
      return;
    }

    if (!hasSnapshot || !date) {
      el.innerHTML = '<div class="gc-empty">No snapshot available yet.</div>';
      return;
    }

    if (!config.ajaxurl || !config.nonceAdminInsights) {
      el.innerHTML = '<div class="gc-empty gc-error">Insights config missing.</div>';
      return;
    }

    abortRequest(insightsRequest);

    el.innerHTML = '<div class="gc-empty">Loading insights…</div>';

    insightsRequest = $.ajax({
      url: config.ajaxurl,
      method: "POST",
      dataType: "json",
      data: {
        action: "vcarb_admin_insights",
        nonce: config.nonceAdminInsights,
        view: view || "month",
        date: date || ""
      }
    })
      .done(function (res) {
        if (!res || !res.success) {
          const msg =
            res && res.data && res.data.message
              ? res.data.message
              : "Failed to load insights.";

          el.innerHTML = '<div class="gc-empty gc-error">' + escHtml(msg) + "</div>";
          return;
        }

        el.innerHTML =
          res && res.data && res.data.html
            ? res.data.html
            : '<div class="gc-empty">No insights.</div>';
      })
      .fail(function (xhr, status) {
        if (status === "abort") {
          return;
        }

        el.innerHTML = '<div class="gc-empty gc-error">Failed to load insights.</div>';
      })
      .always(function () {
        insightsRequest = null;

        if (window.gcInsightsInitFilters) {
          window.gcInsightsInitFilters(el);
        }
      });
  }

  function setSnapshotHelp(view, period, hasSnapshot) {
    const $help = $("[data-gc-snap-help]").first();

    if (!$help.length) {
      return;
    }

    if (!hasSnapshot || !period) {
      $help.text("No snapshot is available yet for this period.");
      return;
    }

    if (view === "week") {
      $help.text("Weekly view shows the selected weekly snapshot.");
      return;
    }

    if (view === "month") {
      $help.text("Monthly view is based on the selected monthly snapshot.");
      return;
    }

    if (view === "year") {
      $help.text("Yearly view is based on the selected yearly snapshot.");
      return;
    }

    $help.text("");
  }

  function setHotspotsVisibility(view) {
    const $panel = $("#gcHotspotsPanel");
    const $box = $("#gcHotspotsBody");

    if (!$panel.length || !$box.length) {
      return;
    }

    $panel.show();
    $box.html('<p class="gc-empty">Loading hotspots…</p>');
  }

  function setHotspotsError(msg) {
    const $box = $("#gcHotspotsBody");

    if ($box.length) {
      $box.html('<div class="gc-empty gc-error">' + escHtml(msg) + "</div>");
    }
  }

  function renderHotspots(items) {
    const $box = $("#gcHotspotsBody");

    if (!$box.length) {
      return;
    }

    if (!Array.isArray(items) || !items.length) {
      $box.html('<div class="gc-empty">No product hotspot data yet for this period.</div>');
      return;
    }

    const html = items
      .map(function (item) {
        const name = item && (item.product_name || item.name)
          ? item.product_name || item.name
          : "Product";

        const co2 = Number(
          item && (item.total_co2 || item.co2)
            ? item.total_co2 || item.co2
            : 0
        );

        const orders = Number(
          item && (item.orders || item.qty)
            ? item.orders || item.qty
            : 0
        );

        return (
          '<div class="gc-hotspot-row">' +
          '<div class="gc-hotspot-name">' + escHtml(name) + "</div>" +
          '<div class="gc-hotspot-meta">' +
          "<span><strong>" + co2.toFixed(2) + "</strong> kg CO₂</span>" +
          '<span class="gc-dot">•</span>' +
          "<span>" + escHtml(orders) + " items</span>" +
          "</div>" +
          "</div>"
        );
      })
      .join("");

    $box.html(html);
  }

  function renderPeriodBrowser(browser, currentView) {
    const $wrap = $(".gc-period-browser").first();

    if (!$wrap.length || !browser || typeof browser !== "object") {
      return;
    }

    const view = String(currentView || getCurrentView() || "month");

    const selected = String(
      browser.selected ||
      browser.current_date ||
      browser.date ||
      ""
    );

    const previous = String(
      browser.previous ||
      browser.prev_date ||
      ""
    );

    const next = String(
      browser.next ||
      browser.next_date ||
      ""
    );

    const latest = String(
      browser.latest ||
      browser.latest_date ||
      browser.current ||
      ""
    );

    const hasPrev = previous !== "" && isValidSnap(view, previous);
    const hasNext = next !== "" && isValidSnap(view, next);
    const hasLatest = latest !== "" && isValidSnap(view, latest);

    function makeUrl(v, d) {
      try {
        const url = new URL(window.location.href);

        url.searchParams.set("page", "vcarb-all-customers");
        url.searchParams.set("view", v);

        if (d && isValidSnap(v, d)) {
          url.searchParams.set("date", d);
        } else {
          url.searchParams.delete("date");
        }

        return url.toString();
      } catch (e) {
        let out = "admin.php?page=vcarb-all-customers&view=" + encodeURIComponent(v);

        if (d && isValidSnap(v, d)) {
          out += "&date=" + encodeURIComponent(d);
        }

        return out;
      }
    }

    const prevUrl = hasPrev ? makeUrl(view, previous) : "";
    const nextUrl = hasNext ? makeUrl(view, next) : "";
    const currentUrl = hasLatest ? makeUrl(view, latest) : makeUrl(view, "");

    const isLatest = hasLatest && selected !== "" && latest === selected;

    let html = "";

    if (hasPrev) {
      html +=
        '<a class="button button-secondary gc-period-browser__btn" href="' +
        escHtml(prevUrl) +
        '" data-gc-period-nav="prev">← Previous</a>';
    } else {
      html +=
        '<span class="button button-secondary gc-period-browser__btn disabled" aria-disabled="true">← Previous</span>';
    }

    html +=
      '<a class="button button-secondary gc-period-browser__btn gc-period-browser__btn--current gc-period-browser__current ' +
      (isLatest ? "is-current" : "") +
      '" href="' +
      escHtml(currentUrl) +
      '" data-gc-period-nav="current"' +
      (isLatest ? ' aria-current="true"' : "") +
      ">Current</a>";

    if (hasNext) {
      html +=
        '<a class="button button-secondary gc-period-browser__btn" href="' +
        escHtml(nextUrl) +
        '" data-gc-period-nav="next">Next →</a>';
    } else {
      html +=
        '<span class="button button-secondary gc-period-browser__btn disabled" aria-disabled="true">Next →</span>';
    }

    $wrap.html(html);
  }

  function fetchHotspots(view, period) {
    const config = cfg();
    const $box = $("#gcHotspotsBody");

    if (!$box.length) {
      return;
    }

    abortRequest(hotspotsRequest);

    if (!period) {
      $box.html('<div class="gc-empty">No snapshot selected.</div>');
      return;
    }

    if (!config.ajaxurl || !config.nonceHotspots) {
      $box.html('<div class="gc-empty gc-error">Hotspots nonce missing.</div>');
      return;
    }

    $box.html('<p class="gc-empty">Loading hotspots…</p>');

    hotspotsRequest = $.ajax({
      url: config.ajaxurl,
      method: "POST",
      dataType: "json",
      data: {
        action: "vcarb_get_hotspots",
        nonce: config.nonceHotspots,
        view: view,
        period: period
      }
    })
      .done(function (res) {
        if (!res || !res.success) {
          const msg =
            res && res.data && res.data.message
              ? res.data.message
              : "Unable to load hotspots.";

          $box.html('<div class="gc-empty gc-error">' + escHtml(msg) + "</div>");
          return;
        }

        renderHotspots(res && res.data ? res.data.items || [] : []);
      })
      .fail(function (xhr, status) {
        if (status === "abort") {
          return;
        }

        $box.html('<div class="gc-empty gc-error">Error loading hotspots.</div>');
      })
      .always(function () {
        hotspotsRequest = null;
      });
  }

  function fetchReport(view, explicitDate) {
    const config = cfg();

    if (!hasAjax()) {
      console.warn("vcarbChartAjax missing ajaxurl/nonceReport.");
      return;
    }

    abortRequest(reportRequest);
    abortRequest(insightsRequest);
    abortRequest(hotspotsRequest);

    setTableLoading();
    setHotspotsVisibility(view);

    const snap = isValidSnap(view, explicitDate) ? String(explicitDate) : "";

    reportRequest = $.ajax({
      url: config.ajaxurl,
      method: "POST",
      dataType: "json",
      data: {
        action: "vcarb_get_report",
        nonce: config.nonceReport,
        view: view,
        date: snap || ""
      }
    })
      .done(function (res) {
        if (!res || !res.success) {
          const msg =
            res && res.data && res.data.message
              ? res.data.message
              : "Unable to load report.";

          setTableError(msg);
          setInsightsError(msg);
          setHotspotsError(msg);
          setChartEmpty("No chart data available for this period.");
          return;
        }

        const data = res.data || {};
        const returnedView = String(data.view || view || "month");
        const returnedDate = String(data.date || "");
        const snapshot = data && typeof data.snapshot === "object" ? data.snapshot : {};
        const hasSnapshot = !!snapshot.has;
        const snapshotUpdated = String(snapshot.updated || "");
        const ok = hasSnapshot && isValidSnap(returnedView, returnedDate);

        const chartPayload = data && typeof data.chart === "object" ? data.chart : {};
        const hasChart =
          Array.isArray(chartPayload.labels) &&
          Array.isArray(chartPayload.orders) &&
          Array.isArray(chartPayload.co2) &&
          chartPayload.labels.length > 0;

        try {
          if (!hasChart) {
            setChartEmpty("No chart data available for this period.");
          } else {
            renderChart(chartPayload, returnedDate, returnedView);
          }
        } catch (err) {
          console.error("[VerdantCart Report] Chart render failed:", err);
          setChartEmpty("Chart could not be rendered for this period.");
        }

        renderTable(data.rows || []);
        setKpisFromReport(data);
        setSnapshotBadge(hasSnapshot, returnedDate, snapshotUpdated);
        setSnapshotHelp(returnedView, returnedDate, hasSnapshot);
        setAdminExportState(ok, returnedView, returnedDate);

        if (data.browser) {
          renderPeriodBrowser(data.browser, returnedView);
        }

        persistCanonicalState(returnedView, returnedDate, ok);
        replaceUrlState(returnedView, returnedDate, ok);
        updateInsights(returnedView, returnedDate, ok);

        if (ok) {
          fetchHotspots(returnedView, returnedDate);
        } else {
          $("#gcHotspotsBody").html(
            '<div class="gc-empty">No product hotspot data yet for this period.</div>'
          );
        }
      })
      .fail(function (xhr, status) {
        if (status === "abort") {
          return;
        }

        let msg = "Error loading report.";

        if (xhr && xhr.status) {
          msg = "Error loading report (HTTP " + xhr.status + ").";
        }

        console.error(
          "[VerdantCart Report] AJAX failed:",
          xhr && xhr.status,
          xhr && xhr.responseText
        );

        setTableError(msg);
        setInsightsError(msg);
        setHotspotsError(msg);
        setChartEmpty("No chart data available for this period.");
      })
      .always(function () {
        reportRequest = null;
      });
  }

  getTabs().on("click", "a.nav-tab", function (e) {
    const $tab = $(this);

    e.preventDefault();

    const view = String($tab.data("view") || "month");

    $(".gc-tabs a.nav-tab").removeClass("nav-tab-active is-active");
    $tab.addClass("nav-tab-active is-active");

    fetchReport(view, "");
  });

  $(document).on("click", ".gc-period-browser a", function (e) {
    const href = String($(this).attr("href") || "");

    if (!href) {
      return;
    }

    const parsed = getViewAndDateFromHref(href);
    const view = parsed.view;
    const date = parsed.date;

    e.preventDefault();

    $(".gc-tabs a.nav-tab").removeClass("nav-tab-active is-active");
    $('.gc-tabs a.nav-tab[data-view="' + view + '"]').addClass("nav-tab-active is-active");

    fetchReport(view, date);
  });

  (function init() {
    if (!hasAjax()) {
      console.warn("VerdantCart Carbon Reports admin JS: vcarbChartAjax is missing.");
      return;
    }

    const initialView = getCurrentView();

    $(".gc-tabs a.nav-tab").removeClass("nav-tab-active is-active");
    $('.gc-tabs a.nav-tab[data-view="' + initialView + '"]').addClass("nav-tab-active is-active");

    initExports();

    /*
 * On first page load / browser refresh, respect the selected URL/date
 * when valid. If no valid date exists, the PHP dataset resolver will
 * fall back to the latest available snapshot for the selected view.
 */
    const initialDate = getCurrentSnap(initialView);

    fetchReport(initialView, initialDate);
  })();
});