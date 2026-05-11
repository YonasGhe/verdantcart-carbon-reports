/**
 * VerdantCart Carbon Reports — verdantcart-dashboard.js
 *
 * Front dashboard:
 * - multiple .gc-ui instances
 * - export dropdown
 * - AJAX dashboard switching
 * - historical period browser
 * - chart rendering for week / month / year
 * - front score / hotspots / AI insights refresh
 */

(function () {
  "use strict";

  if (typeof window.jQuery === "undefined") {
    return;
  }

  const $ = window.jQuery;

  $(function () {
    function dashCfg() {
      if (typeof window.vcarbDashAjax !== "undefined") {
        return window.vcarbDashAjax;
      }

      /*
       * Temporary backward-compatible fallback.
       * Safe while browser/admin caches still contain the old localized object.
       */
      if (typeof window.amatorcarbonDashAjax !== "undefined") {
        return window.amatorcarbonDashAjax;
      }

      return {};
    }

    const DASH_CFG = dashCfg();

    if (!DASH_CFG.ajaxUrl || !DASH_CFG.nonce) {
      return;
    }

    const STRINGS = DASH_CFG.strings || {};
    const EMPTY_EXPORT_MSG =
      STRINGS.emptyExportMsg ||
      "Export is unavailable until data exists for this period.";
    const EMPTY_CHART_MSG =
      STRINGS.emptyChartMsg ||
      "No emissions data for this period yet.";
    const LOAD_ERROR_MSG =
      STRINGS.loadErrorMsg ||
      "Could not load dashboard data. Please try again.";
    const NETWORK_ERROR_MSG =
      STRINGS.networkErrorMsg ||
      "Network error. Please try again.";
    const LOADING_CHART_MSG =
      STRINGS.loadingChart ||
      "Loading emissions data…";
    const LABEL_ALL = STRINGS.all || "All";
    const LABEL_POSITIVE = STRINGS.positive || "Positive";
    const LABEL_WARNINGS = STRINGS.warnings || "Warnings";
    const LABEL_RISKS = STRINGS.risks || "Risks";
    const LABEL_RECOMMENDATIONS =
      STRINGS.recommendations || "Recommendations";
    const NO_INSIGHTS = STRINGS.noInsights || "No insights available yet.";
    const NO_RECOMMENDATIONS =
      STRINGS.noRecommendations || "No recommendations yet for this period.";
    const NOTHING_DETECTED =
      STRINGS.nothingDetected || "Nothing detected for this period.";
    const HOTSPOT_TITLE = STRINGS.carbonHotspot || "Carbon Hotspot";
    const HOTSPOT_SUB =
      STRINGS.topProducts || "Top emitting products for the selected period.";
    const HOTSPOT_NO_DATA = STRINGS.noData || "No data";
    const HOTSPOT_BIGGEST_DRIVER =
      STRINGS.biggestDriver || "Biggest driver:";
    const HOTSPOT_SUFFIX =
      STRINGS.productEmissions || "of product emissions.";

    function setUrlParams(view, date) {
      try {
        const url = new URL(window.location.href);
        const cleanView = String(view || "month");
        const cleanDate = String(date || "");

        url.searchParams.set("view", cleanView);

        if (cleanDate) {
          url.searchParams.set("date", cleanDate);
        } else {
          url.searchParams.delete("date");
        }

        window.history.replaceState({}, "", url.toString());
      } catch (e) {
        // no-op
      }
    }

    function escHtml(str) {
      return String(str == null ? "" : str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    function shortMonthLabel(period) {
      if (!/^\d{4}-\d{2}$/.test(period)) {
        return "";
      }

      const monthNum = parseInt(period.split("-")[1], 10);
      const months = [
        "Jan", "Feb", "Mar", "Apr", "May", "Jun",
        "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"
      ];

      return months[monthNum - 1] || "";
    }

    function ceilTo(value, step) {
      value = Number(value || 0);
      step = Number(step || 1);

      if (step <= 0) {
        step = 1;
      }

      return Math.ceil(value / step) * step;
    }

    function niceMax(value) {
      value = Number(value || 0);

      if (value <= 0) return 5;
      if (value <= 5) return 5;
      if (value <= 10) return 10;
      if (value <= 20) return 20;
      if (value <= 25) return 25;
      if (value <= 50) return 50;
      if (value <= 100) return 100;

      return Math.ceil(value / 10) * 10;
    }

    function niceStep(max) {
      max = Number(max || 0);

      if (max <= 5) return 1;
      if (max <= 10) return 2;
      if (max <= 20) return 4;
      if (max <= 25) return 5;
      if (max <= 50) return 10;
      if (max <= 100) return 20;

      return Math.ceil(max / 5);
    }

    function parseNumberFromText(text) {
      const m = (text || "").replace(/,/g, "").match(/-?\d+(\.\d+)?/);
      return m ? parseFloat(m[0]) : null;
    }

    function formatLikeOriginal(value, originalText) {
      const hasDecimal = /\d+\.\d+/.test(originalText || "");
      const decimalMatch = (originalText.split(".")[1] || "").match(/\d+/);
      const decimals = hasDecimal ? (decimalMatch ? decimalMatch[0].length : 2) : 0;

      const out = decimals
        ? value.toFixed(decimals)
        : Math.round(value).toString();

      return decimals ? out : out.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }

    function animateNumber(el, to, duration) {
      const original = el.textContent;
      const unitEl = el.querySelector(".gc-metric__unit");
      const start = parseNumberFromText(original) || 0;
      const startTime = performance.now();

      function tick(now) {
        const t = Math.min(1, (now - startTime) / duration);
        const eased = 1 - Math.pow(1 - t, 3);
        const current = start + (to - start) * eased;

        if (unitEl) {
          unitEl.remove();
          el.textContent = formatLikeOriginal(current, original) + " ";
          el.appendChild(unitEl);
        } else {
          el.textContent = formatLikeOriginal(current, original);
        }

        if (t < 1) {
          requestAnimationFrame(tick);
        }
      }

      requestAnimationFrame(tick);
    }

    function humanPeriodLabel(view, date) {
      view = String(view || "month");
      date = String(date || "");

      if (date) {
        if (view === "month" && /^\d{4}-\d{2}$/.test(date)) {
          const y = parseInt(date.slice(0, 4), 10);
          const m = parseInt(date.slice(5, 7), 10) - 1;
          const dt = new Date(y, m, 1);

          return dt.toLocaleDateString(undefined, {
            month: "long",
            year: "numeric"
          });
        }

        if (view === "week") {
          const match = date.match(/^(\d{4})-W(\d{2})$/);
          if (match) {
            return "Week " + match[2] + ", " + match[1];
          }
        }

        if (view === "year") {
          return date;
        }
      }

      const now = new Date();

      if (view === "year") {
        return String(now.getFullYear());
      }

      return now.toLocaleDateString(undefined, {
        month: "long",
        year: "numeric"
      });
    }

    function normalizeInsightsData(insights) {
      const empty = {
        positives: [],
        warnings: [],
        risks: [],
        recommendations: []
      };

      if (!insights || typeof insights !== "object") {
        return empty;
      }

      if (
        Array.isArray(insights.positives) ||
        Array.isArray(insights.warnings) ||
        Array.isArray(insights.risks) ||
        Array.isArray(insights.recommendations)
      ) {
        return {
          positives: Array.isArray(insights.positives) ? insights.positives : [],
          warnings: Array.isArray(insights.warnings) ? insights.warnings : [],
          risks: Array.isArray(insights.risks) ? insights.risks : [],
          recommendations: Array.isArray(insights.recommendations)
            ? insights.recommendations
            : []
        };
      }

      if (Array.isArray(insights)) {
        const out = {
          positives: [],
          warnings: [],
          risks: [],
          recommendations: []
        };

        insights.forEach((item) => {
          const type = String(item && item.type ? item.type : "warning");

          if (type === "positive" || type === "success") {
            out.positives.push(item);
          } else if (type === "risk") {
            out.risks.push(item);
          } else if (type === "recommendation") {
            out.recommendations.push(item);
          } else {
            out.warnings.push(item);
          }
        });

        return out;
      }

      return empty;
    }

    function renderInsightsItems(items, opts) {
      items = Array.isArray(items) ? items : [];
      opts = opts || {};

      if (!items.length) {
        return (
          '<div class="gc-insights__empty">' +
          escHtml(opts.empty || NOTHING_DETECTED) +
          "</div>"
        );
      }

      let html = '<ul class="gc-insights__list">';

      items.forEach((item) => {
        const title = String(item && item.title ? item.title : "").trim();
        const message = String(item && item.message ? item.message : "").trim();
        const actions = Array.isArray(item && item.actions) ? item.actions : [];

        html += '<li class="gc-insights__li">';

        if (title) {
          html += '<div class="gc-insights__liTitle">' + escHtml(title) + "</div>";
        }

        if (message) {
          html += '<div class="gc-insights__liMsg">' + escHtml(message) + "</div>";
        }

        if (actions.length) {
          html += '<ul class="gc-insights__actions">';

          actions.forEach((action) => {
            const text = String(action || "").trim();
            if (text) {
              html += "<li>" + escHtml(text) + "</li>";
            }
          });

          html += "</ul>";
        }

        html += "</li>";
      });

      html += "</ul>";
      return html;
    }

    function renderInsightsGroup(type, label, items) {
      return (
        '<div class="gc-insights__group" data-group="' +
        escHtml(type) +
        '">' +
        '<div class="gc-insights__card gc-insights__card--' +
        escHtml(type) +
        '">' +
        '<div class="gc-insights__cardHead">' +
        '<div class="gc-insights__badge gc-insights__badge--' +
        escHtml(type) +
        '">' +
        escHtml(label) +
        "</div>" +
        '<div class="gc-insights__count">' +
        String(Array.isArray(items) ? items.length : 0) +
        "</div>" +
        "</div>" +
        renderInsightsItems(items, {
          empty: NOTHING_DETECTED
        }) +
        "</div>" +
        "</div>"
      );
    }

    function renderInsightsHtml(insights) {
      const data = normalizeInsightsData(insights);
      const positives = data.positives || [];
      const warnings = data.warnings || [];
      const risks = data.risks || [];
      const recommendations = data.recommendations || [];

      const hasAny =
        positives.length ||
        warnings.length ||
        risks.length ||
        recommendations.length;

      if (!hasAny) {
        return '<div class="gc-empty">' + escHtml(NO_INSIGHTS) + "</div>";
      }

      let html = "";
      html += '<div class="gc-insights" data-context="front">';
      html += '<div class="gc-insights__header">';
      html += '<div class="gc-insights__headerLeft">';
      html += '<h2 class="gc-insights__title">Sustainability Insights</h2>';
      html += "</div>";
      html += "</div>";

      html +=
        '<div class="gc-insights__filters" role="tablist" aria-label="' +
        escHtml("Filter insights") +
        '">';
      html +=
        '<button type="button" class="gc-insights__filter is-active" data-filter="all">' +
        escHtml(LABEL_ALL) +
        "</button>";
      html +=
        '<button type="button" class="gc-insights__filter" data-filter="positive">' +
        escHtml(LABEL_POSITIVE) +
        "</button>";
      html +=
        '<button type="button" class="gc-insights__filter" data-filter="warning">' +
        escHtml(LABEL_WARNINGS) +
        "</button>";
      html +=
        '<button type="button" class="gc-insights__filter" data-filter="risk">' +
        escHtml(LABEL_RISKS) +
        "</button>";
      html += "</div>";

      html += '<div class="gc-insights__grid">';
      html += renderInsightsGroup("positive", LABEL_POSITIVE, positives);
      html += renderInsightsGroup("warning", LABEL_WARNINGS, warnings);
      html += renderInsightsGroup("risk", LABEL_RISKS, risks);
      html += "</div>";

      html += '<div class="gc-insights__recs">';
      html += '<h3 class="gc-insights__subtitle">' + escHtml(LABEL_RECOMMENDATIONS) + "</h3>";
      html += renderInsightsItems(recommendations, {
        empty: NO_RECOMMENDATIONS
      });
      html += "</div>";

      html += "</div>";
      return html;
    }

    function renderHotspotsHtml(hotspots) {
      hotspots = Array.isArray(hotspots) ? hotspots : [];

      const top = hotspots.length ? hotspots[0] : null;
      const bars = hotspots.slice(0, 3);
      const title = top && top.product_name ? String(top.product_name) : "";

      let html = "";
      html += '<div class="gc-panel__head">';
      html += "<div>";
      html += '<div class="gc-panel__kicker">INSIGHTS</div>';
      html += '<h3 class="gc-panel__title">' + escHtml(HOTSPOT_TITLE) + "</h3>";
      html += '<p class="gc-panel__sub">' + escHtml(HOTSPOT_SUB) + "</p>";
      html += "</div>";
      html += title
        ? '<span class="gc-chip gc-chip--good">' + escHtml(title) + "</span>"
        : '<span class="gc-chip">' + escHtml(HOTSPOT_NO_DATA) + "</span>";
      html += "</div>";

      if (!bars.length) {
        html +=
          '<div class="gc-panel__note">No product hotspots yet for this period. Add completed orders or run Backfill.</div>';
        return html;
      }

      html += '<div class="gc-bars">';

      bars.forEach((h, i) => {
        const name = String(h && h.product_name ? h.product_name : "");
        const percent = Math.max(
          0,
          Math.min(100, Number(h && h.percent ? h.percent : 0))
        );

        html += '<div class="gc-bar">';
        html += '<div class="gc-bar__top">';
        html += "<span>" + escHtml(name) + "</span>";
        html += "<strong>" + Math.round(percent) + "%</strong>";
        html += "</div>";
        html += '<div class="gc-bar__track">';
        html +=
          '<div class="gc-bar__fill' +
          (i > 0 ? " gc-bar__fill--muted" : "") +
          '" style="width:' +
          percent.toFixed(2) +
          '%"></div>';
        html += "</div>";
        html += "</div>";
      });

      html += "</div>";

      const topPercent = Math.max(
        0,
        Math.min(100, Number(top && top.percent ? top.percent : 0))
      );

      html += '<div class="gc-panel__note">';
      html += escHtml(HOTSPOT_BIGGEST_DRIVER) + " ";
      html +=
        "<strong>" +
        escHtml(String(top.product_name || "")) +
        "</strong> ";
      html += "(" + Math.round(topPercent) + "% " + escHtml(HOTSPOT_SUFFIX) + ")";
      html += "</div>";

      return html;
    }

    function updatePeriodText($ui, view, date) {
      const label = humanPeriodLabel(view, date);
      const $period = $ui.find(".gc-period").first();

      if ($period.length) {
        $period.text("📊 " + label);
      }
    }

    function updateScorePanel($ui, score) {
      score = score && typeof score === "object" ? score : {};

      const rawScore = score.score;
      const scoreVal =
        rawScore === null || rawScore === undefined || rawScore === ""
          ? null
          : Math.max(0, Math.min(100, parseInt(rawScore, 10)));

      const scoreDisplay =
        scoreVal === null || Number.isNaN(scoreVal) ? "—" : String(scoreVal);
      const ringP = scoreVal === null || Number.isNaN(scoreVal) ? 0 : scoreVal;

      let status = "—";

      if (scoreVal !== null && !Number.isNaN(scoreVal)) {
        if (score.label) {
          status = String(score.label);
        } else if (scoreVal >= 85) {
          status = "Excellent";
        } else if (scoreVal >= 70) {
          status = "Good";
        } else if (scoreVal >= 50) {
          status = "Fair";
        } else {
          status = "Needs work";
        }
      }

      const deltaText = String(score.delta_text || "—");
      const deltaClass = String(score.delta_class || "gc-neutral");

      const $panel = $ui.find(".gc-panel--score").first();
      if (!$panel.length) {
        return;
      }

      const $num = $panel.find(".gc-score__num").first();
      const $status = $panel.find(".gc-score__status").first();
      const $delta = $panel.find(".gc-score__delta").first();
      const $ring = $panel.find(".gc-score__ring").first();

      if ($num.length) {
        $num.text(scoreDisplay);
      }

      if ($status.length) {
        $status.text(status);
      }

      if ($delta.length) {
        $delta
          .removeClass("gc-up gc-down gc-neutral")
          .addClass(deltaClass)
          .text(deltaText);
      }

      if ($ring.length) {
        $ring[0].style.setProperty("--p", String(ringP));
      }
    }

    function closeExportBlock(block) {
      if (!block) {
        return;
      }

      block.classList.remove("is-open");

      const chev = block.querySelector(".gc-export__btn--chev");
      const main = block.querySelector(".gc-export__btn--main");
      const menu = block.querySelector(".gc-export__menu");

      if (chev) {
        chev.setAttribute("aria-expanded", "false");
      }

      if (main) {
        if (block.getAttribute("data-gc-snapshot") === "1") {
          main.removeAttribute("aria-disabled");
        }
      }

      if (menu) {
        menu.setAttribute("hidden", "");
      }

      block
        .querySelectorAll(".gc-export__menu a.gc-export__item")
        .forEach((a) => a.setAttribute("tabindex", "-1"));
    }

    if (!window.__vcarbExportGlobalBound) {
      window.__vcarbExportGlobalBound = true;

      document.addEventListener("click", function (e) {
        const openBlocks = document.querySelectorAll("[data-gc-export].is-open");

        if (!openBlocks.length) {
          return;
        }

        for (const block of openBlocks) {
          if (block.contains(e.target)) {
            continue;
          }

          closeExportBlock(block);
        }
      });

      document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") {
          return;
        }

        document
          .querySelectorAll("[data-gc-export].is-open")
          .forEach(closeExportBlock);
      });
    }

    (function initTabsPillOnce() {
      if (window.__vcarbTabsPillInit) {
        return;
      }

      window.__vcarbTabsPillInit = true;

      const SEL_TABS = ".gc-tabs";
      const SEL_TAB = ".gc-tab";
      const PILL_CLASS = "gc-tabs__pill";

      function ensurePill(tabs) {
        let pill = tabs.querySelector("." + PILL_CLASS);

        if (!pill) {
          pill = document.createElement("span");
          pill.className = PILL_CLASS;
          pill.setAttribute("aria-hidden", "true");
          tabs.insertBefore(pill, tabs.firstChild);
        }

        return pill;
      }

      function positionPill(tabs) {
        const pill = ensurePill(tabs);
        const active =
          tabs.querySelector(`${SEL_TAB}.is-active`) ||
          tabs.querySelector(`${SEL_TAB}[aria-selected="true"]`);

        if (!active) {
          pill.style.width = "0px";
          pill.style.transform = "translateX(0px)";
          return;
        }

        const tabsRect = tabs.getBoundingClientRect();
        const activeRect = active.getBoundingClientRect();
        const left = Math.round(activeRect.left - tabsRect.left);
        const width = Math.round(activeRect.width);

        pill.style.width = width + "px";
        pill.style.transform = `translateX(${left}px)`;
      }

      function wireTabs(tabs) {
        positionPill(tabs);

        tabs.addEventListener("click", function () {
          requestAnimationFrame(function () {
            positionPill(tabs);
          });
        });

        requestAnimationFrame(function () {
          positionPill(tabs);
        });

        setTimeout(function () {
          positionPill(tabs);
        }, 60);
      }

      function initAll() {
        document.querySelectorAll(SEL_TABS).forEach(wireTabs);
      }

      if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initAll);
      } else {
        initAll();
      }

      let raf = 0;

      window.addEventListener("resize", function () {
        cancelAnimationFrame(raf);
        raf = requestAnimationFrame(function () {
          document.querySelectorAll(SEL_TABS).forEach(positionPill);
        });
      });

      window.gcTabsPillRefresh = function () {
        document.querySelectorAll(SEL_TABS).forEach(positionPill);
      };
    })();

    if (!window.__vcarbCountUpBound) {
      window.__vcarbCountUpBound = true;

      document.addEventListener("DOMContentLoaded", function () {
        const metricEls = document.querySelectorAll(
          ".gc-metric__value:not(.gc-metric__value--small)"
        );

        metricEls.forEach((el) => {
          if (el.dataset.gcCounted === "1") {
            return;
          }

          const num = parseNumberFromText(el.textContent);
          if (num === null || !isFinite(num)) {
            return;
          }

          el.dataset.gcCounted = "1";
          animateNumber(el, num, 650);
        });
      });
    }

    if (!window.__vcarbDashGlobalsBound) {
      window.__vcarbDashGlobalsBound = true;

      document
        .querySelectorAll(".gc-ui[data-gc-instance]")
        .forEach((ui) => ui.classList.add("is-loading"));

      window.addEventListener("load", function () {
        setTimeout(function () {
          document
            .querySelectorAll(".gc-ui[data-gc-instance].is-loading")
            .forEach((ui) => ui.classList.remove("is-loading"));
        }, 600);
      });

      document.addEventListener("click", function (e) {
        const btn = e.target.closest("[data-gc-backfill-open]");

        if (!btn) {
          return;
        }

        window.location.href =
          DASH_CFG.backfillUrl ||
          "/wp-admin/admin.php?page=vcarb-backfill";
      });
    }

    const $instances = $(".gc-ui[data-gc-instance]").length
      ? $(".gc-ui[data-gc-instance]")
      : $(".gc-ui");

    $instances.each(function () {
      const $ui = $(this);

      if ($ui.data("__vcarbBound")) {
        return;
      }

      $ui.data("__vcarbBound", 1);

      const uid = $ui.data("gc-instance") ? String($ui.data("gc-instance")) : "";
      const initialView = $ui.data("view") || "month";

      let chart = null;
      let isFetching = false;
      const cache = Object.create(null);

      function idSel(suffix) {
        return uid ? $("#" + uid + suffix) : $("#gc" + suffix);
      }

      function $chartWrap() {
        return $ui.find(".gc-chart").first();
      }

      function announce(msg) {
        const $live = $ui.find(".gc-chart__live").first();

        if ($live.length) {
          $live.text(msg || "");
        }
      }

      function getChartCanvas() {
        let el = $ui.find(".gc-chart canvas").first()[0] || null;

        if (!el && uid) {
          el =
            document.getElementById(uid + "EmissionsChart") ||
            document.getElementById(uid + "CarbonChart");
        }

        if (!el) {
          el =
            document.getElementById("gcEmissionsChart") ||
            document.getElementById("gcCarbonChart");
        }

        return el;
      }

      function ensureChartSkeleton() {
        const $wrap = $chartWrap();

        if (!$wrap.length || $wrap.find(".gc-chart__skeleton").length) {
          return;
        }

        $wrap.prepend(
          '<div class="gc-chart__skeleton" aria-hidden="true">' +
          '<div class="gc-chart__sk-row">' +
          '<div class="gc-chart__sk-pill"></div>' +
          '<div class="gc-chart__sk-pill w2"></div>' +
          '<div class="gc-chart__sk-pill w3"></div>' +
          "</div>" +
          '<div class="gc-chart__sk-chart"></div>' +
          "</div>"
        );
      }

      function setLoading(on, msg) {
        const $wrap = $chartWrap();

        if (!$wrap.length) {
          return;
        }

        ensureChartSkeleton();
        $wrap.toggleClass("gc-chart--loading", !!on);
        $wrap.attr("aria-busy", on ? "true" : "false");

        const $ph = $wrap.find(".gc-chart__placeholder").first();

        if ($ph.length) {
          if (on) {
            $ph.text(msg || LOADING_CHART_MSG).show();
          } else {
            $ph.hide();
          }
        }

        if (on) {
          announce(msg || LOADING_CHART_MSG);
        }
      }

      function setEmpty(msg) {
        const $wrap = $chartWrap();

        if (!$wrap.length) {
          return;
        }

        $wrap
          .removeClass("gc-chart--loading gc-chart--error")
          .attr("aria-busy", "false");

        const $ph = $wrap.find(".gc-chart__placeholder").first();

        if ($ph.length) {
          $ph.text(msg || EMPTY_CHART_MSG).show();
        }

        announce(msg || EMPTY_CHART_MSG);
      }

      function setError(msg) {
        const $wrap = $chartWrap();

        if (!$wrap.length) {
          return;
        }

        $wrap
          .removeClass("gc-chart--loading")
          .addClass("gc-chart--error")
          .attr("aria-busy", "false");

        const $ph = $wrap.find(".gc-chart__placeholder").first();

        if ($ph.length) {
          $ph.text(msg || LOAD_ERROR_MSG).show();
        }

        announce(msg || LOAD_ERROR_MSG);
      }

      function clearChartState() {
        const $wrap = $chartWrap();

        if (!$wrap.length) {
          return;
        }

        $wrap
          .removeClass("gc-chart--loading gc-chart--error")
          .attr("aria-busy", "false");

        const $ph = $wrap.find(".gc-chart__placeholder").first();

        if ($ph.length) {
          $ph.hide();
        }

        announce("");
      }

      function updateMetrics(m) {
        const $total = idSel("MetricTotal");
        const $orders = idSel("MetricOrders");
        const $delta = idSel("MetricDelta");
        const $updated = idSel("MetricUpdated");

        if ($total.length) {
          const v = Number(m.total_co2 || 0).toFixed(2);
          const unitHtml = $total.find(".gc-metric__unit").length
            ? $total.find(".gc-metric__unit")[0].outerHTML
            : '<span class="gc-metric__unit">kg CO₂</span>';

          $total.html(v + " " + unitHtml);
        }

        if ($orders.length) {
          $orders.text(String(Number(m.orders || 0)));
        }

        if ($delta.length) {
          $delta.html(m.delta_html || "");
        }

        if ($updated.length) {
          const updated = String(m.updated || "");
          $updated.text(updated !== "" ? updated : "—");
        }
      }

      function applySnapshotState(snapshot) {
        const $export = $ui.find("[data-gc-export]").first();

        if (!$export.length) {
          return;
        }

        const has = !!(snapshot && snapshot.has);
        const msg = snapshot && snapshot.message ? String(snapshot.message) : "";

        const $main = $export.find(".gc-export__btn--main").first();
        const $chev = $export.find(".gc-export__btn--chev").first();
        const $menu = $export.find(".gc-export__menu").first();
        const $items = $export.find(".gc-export__menu a.gc-export__item");

        $export.attr("data-gc-snapshot", has ? "1" : "0");

        if (msg) {
          $export.attr("data-gc-snapshot-msg", msg);
        } else {
          $export.removeAttr("data-gc-snapshot-msg");
        }

        if (!has) {
          $export.removeClass("is-open");

          if ($main.length) {
            $main.attr("aria-disabled", "true");

            if (msg) {
              $main.attr("title", msg);
            }

            $main
              .off("click.gcExportDisabled")
              .on("click.gcExportDisabled", function (e) {
                e.preventDefault();
                e.stopPropagation();
                return false;
              });
          }

          if ($chev.length) {
            $chev
              .prop("disabled", true)
              .attr("aria-disabled", "true")
              .attr("aria-expanded", "false");

            if (msg) {
              $chev.attr("title", msg);
            }
          }

          if ($menu.length) {
            $menu.attr("hidden", true).attr("aria-hidden", "true");
          }

          $items.attr("tabindex", "-1");
        } else {
          if ($main.length) {
            $main.removeAttr("aria-disabled").removeAttr("title");
            $main.off("click.gcExportDisabled");
          }

          if ($chev.length) {
            $chev
              .prop("disabled", false)
              .removeAttr("aria-disabled")
              .removeAttr("title");
          }

          if ($menu.length) {
            $menu.attr("hidden", true).removeAttr("aria-hidden");
          }
        }
      }

      function getAnchorFromData(data) {
        if (data && data.snapshot && data.snapshot.period) {
          return String(data.snapshot.period);
        }

        if (data && data.date) {
          return String(data.date);
        }

        return "";
      }

      function parseViewDateFromHref(href, fallbackView) {
        try {
          const url = new URL(href, window.location.origin);

          return {
            view: String(url.searchParams.get("view") || fallbackView || "month"),
            date: String(url.searchParams.get("date") || "")
          };
        } catch (e) {
          return {
            view: String(fallbackView || "month"),
            date: ""
          };
        }
      }

      function renderPeriodBrowser(browser, fallbackView, fallbackDate) {
        const $browser = $ui.find(".gc-period-browser").first();

        if (!$browser.length) {
          return;
        }

        const data = browser && typeof browser === "object" ? browser : {};
        const currentDate = String(data.current_date || data.selected || fallbackDate || "");

        const prevUrl = String(data.prev_url || data.previous_url || "");
        const latestUrl = String(data.latest_url || data.current_url || "");
        const nextUrl = String(data.next_url || "");

        const prevHtml = prevUrl
          ? '<a class="gc-btn gc-btn--ghost gc-btn--sm" href="' +
          escHtml(prevUrl) +
          '" data-gc-period-nav="prev">← Previous</a>'
          : '<span class="gc-btn gc-btn--ghost gc-btn--sm is-disabled" aria-disabled="true">← Previous</span>';

        const latestHtml = latestUrl
          ? '<a class="gc-btn gc-btn--ghost gc-btn--sm" href="' +
          escHtml(latestUrl) +
          '" data-gc-period-nav="current">Current</a>'
          : '<span class="gc-btn gc-btn--ghost gc-btn--sm is-disabled" aria-disabled="true">Current</span>';

        const nextHtml = nextUrl
          ? '<a class="gc-btn gc-btn--ghost gc-btn--sm" href="' +
          escHtml(nextUrl) +
          '" data-gc-period-nav="next">Next →</a>'
          : '<span class="gc-btn gc-btn--ghost gc-btn--sm is-disabled" aria-disabled="true">Next →</span>';

        $browser.html(
          '<div class="gc-period-browser__actions" data-current-date="' +
          escHtml(currentDate) +
          '">' +
          prevHtml +
          latestHtml +
          nextHtml +
          "</div>"
        );
      }

      function ensureChart(el, initialData) {
        if (chart) {
          chart.destroy();
          chart = null;
        }

        const ctx = el.getContext("2d");

        chart = new Chart(ctx, {
          type: "line",
          data: {
            labels: initialData.labels || [],
            datasets: [
              {
                label: "CO₂ (kg)",
                data: initialData.co2 || [],
                tension: 0.35,
                borderWidth: 3,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: true,
                backgroundColor: "rgba(21,128,61,0.10)",
                borderColor: "#15803d",
                pointBackgroundColor: "#15803d",
                pointBorderColor: "#fff",
                pointBorderWidth: 2,
                yAxisID: "y",
                order: 1
              },
              {
                label: "Orders",
                data: initialData.orders || [],
                tension: 0.35,
                borderWidth: 3,
                pointRadius: 4,
                pointHoverRadius: 6,
                fill: false,
                borderColor: "#3b82f6",
                pointBackgroundColor: "#3b82f6",
                pointBorderColor: "#fff",
                pointBorderWidth: 2,
                yAxisID: "y1",
                order: 2
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
            plugins: {
              legend: {
                position: "top",
                labels: {
                  boxWidth: 24,
                  usePointStyle: true,
                  padding: 15,
                  font: { size: 13, weight: "600" }
                }
              },
              tooltip: {
                intersect: false,
                mode: "index",
                backgroundColor: "rgba(15, 23, 42, 0.95)",
                padding: 12,
                cornerRadius: 8
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
                  font: { size: 12 }
                },
                border: {
                  display: false
                }
              },
              y: {
                beginAtZero: true,
                position: "left",
                grid: {
                  color: "#e5e7eb"
                },
                title: {
                  display: true,
                  text: "CO₂ Emissions (kg)",
                  color: "#15803d"
                },
                ticks: {
                  color: "#15803d"
                }
              },
              y1: {
                beginAtZero: true,
                position: "right",
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
                  precision: 0
                }
              }
            }
          }
        });

        return chart;
      }

      function renderChart(chartData, metrics) {
        const el = getChartCanvas();

        if (!el) {
          setError("Chart canvas not found.");
          return;
        }

        if (typeof window.Chart === "undefined") {
          setError("Chart library is not loaded.");
          return;
        }

        const $chart = $ui.find(".gc-chart").first();
        const $placeholder = $ui.find(".gc-chart__placeholder").first();

        const baseLabels = Array.isArray(chartData && chartData.labels)
          ? chartData.labels.map(String)
          : [];

        const rawCo2 = Array.isArray(chartData && chartData.co2)
          ? chartData.co2.map(function (v) {
            const n = Number(v || 0);
            return Number.isFinite(n) ? n : 0;
          })
          : [];

        const rawOrders = Array.isArray(chartData && chartData.orders)
          ? chartData.orders.map(function (v) {
            const n = Number(v || 0);
            return Number.isFinite(n) ? n : 0;
          })
          : [];

        if (!baseLabels.length || !rawCo2.length || !rawOrders.length) {
          if (chart) {
            chart.data.labels = [];
            chart.data.datasets[0].data = [];
            chart.data.datasets[1].data = [];
            chart.update("none");
          }

          $chart.removeClass("is-ready").attr("aria-busy", "false");
          $placeholder.text(EMPTY_CHART_MSG).show();
          setEmpty(EMPTY_CHART_MSG);
          return;
        }

        clearChartState();
        setLoading(false);

        const currentView = String($ui.data("view") || "month");
        const currentDate = String($ui.data("date") || "");

        let labels = baseLabels.slice();
        let displayCo2 = rawCo2.slice();
        let displayOrders = rawOrders.slice();

        const metricsCo2 = Number(metrics && metrics.total_co2);
        const metricsOrders = Number(metrics && metrics.orders);

        const hasSinglePoint =
          baseLabels.length === 1 &&
          rawCo2.length === 1 &&
          rawOrders.length === 1;

        const isSinglePeriodVisual =
          (currentView === "month" || currentView === "week") && hasSinglePoint;

        if (isSinglePeriodVisual) {
          const realCo2 = Number.isFinite(metricsCo2)
            ? metricsCo2
            : Number(rawCo2[0] || 0);

          const realOrders = Number.isFinite(metricsOrders)
            ? metricsOrders
            : Number(rawOrders[0] || 0);

          const endLabel =
            currentView === "month"
              ? shortMonthLabel(currentDate) || baseLabels[0] || ""
              : baseLabels[0] || currentDate || "Week";

          labels = ["", endLabel];
          displayCo2 = [0, realCo2];
          displayOrders = [0, realOrders];
        }

        const co2MaxRaw = Math.max.apply(null, displayCo2.concat([0]));
        const ordersMaxRaw = Math.max.apply(null, displayOrders.concat([0]));

        let co2AxisMax;
        let ordersAxisMax;
        let co2Step;
        let ordersStep;

        if (currentView === "week") {
          co2AxisMax = ceilTo(
            Math.max(co2MaxRaw * 1.1, co2MaxRaw + 0.25),
            0.25
          );
          ordersAxisMax = ceilTo(
            Math.max(ordersMaxRaw * 1.1, ordersMaxRaw + 1),
            1
          );

          if (co2AxisMax <= co2MaxRaw) {
            co2AxisMax = Number((co2MaxRaw + 0.25).toFixed(2));
          }

          if (ordersAxisMax <= ordersMaxRaw) {
            ordersAxisMax = ordersMaxRaw + 1;
          }

          co2Step = co2AxisMax <= 2.5 ? 0.5 : 1;
          ordersStep =
            ordersAxisMax <= 5 ? 1 : Math.max(1, Math.ceil(ordersAxisMax / 6));
        } else {
          co2AxisMax = niceMax(Math.max(co2MaxRaw * 1.01, 1));
          ordersAxisMax = niceMax(Math.max(ordersMaxRaw * 1.01, 1));
          co2Step = niceStep(co2AxisMax);
          ordersStep = niceStep(ordersAxisMax);
        }

        const c = ensureChart(el, {
          labels: labels,
          co2: displayCo2,
          orders: displayOrders
        });

        c.data.labels = labels;
        c.data.datasets[0].data = displayCo2;
        c.data.datasets[1].data = displayOrders;
        c.data.datasets[0].order = 1;
        c.data.datasets[1].order = 2;

        c.options.plugins.tooltip.callbacks = {
          title(items) {
            if (isSinglePeriodVisual) {
              return currentView === "month"
                ? shortMonthLabel(currentDate) || baseLabels[0] || ""
                : baseLabels[0] || currentDate || "";
            }

            return (items && items[0] && items[0].label) || "";
          },
          label(context) {
            const idx = context.dataIndex;

            if (isSinglePeriodVisual && idx === 0) {
              return null;
            }

            if (context.dataset.label === "CO₂ (kg)") {
              return "CO₂ (kg): " + Number(displayCo2[idx] || 0).toFixed(2);
            }

            return "Orders: " + Number(displayOrders[idx] || 0);
          }
        };

        c.options.scales.x = {
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

              if (isSinglePeriodVisual && value === 0) {
                return "";
              }

              return label;
            }
          },
          border: {
            display: false
          }
        };

        c.options.scales.y = {
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
        };

        c.options.scales.y1 = {
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
        };

        $chart.addClass("is-ready").attr("aria-busy", "false");
        $placeholder.hide();

        requestAnimationFrame(function () {
          c.update();
          announce("Chart updated with " + baseLabels.length + " data points");
        });
      }

      function safeMakeUrl(base, view, snap) {
        if (!base) {
          return "";
        }

        try {
          const u = new URL(base, window.location.origin);
          u.searchParams.set("view", view || "month");

          if (snap) {
            u.searchParams.set("date", snap);
          } else {
            u.searchParams.delete("date");
          }

          return u.toString();
        } catch (e) {
          let url = String(base);
          const hasQ = url.indexOf("?") !== -1;

          url += (hasQ ? "&" : "?") + "view=" + encodeURIComponent(view || "month");

          if (snap) {
            url += "&date=" + encodeURIComponent(snap);
          }

          return url;
        }
      }

      function syncExportLinks(view, anchor) {
        const $export = $ui.find("[data-gc-export]").first();

        if (!$export.length) {
          return;
        }

        const hasSnap = String($export.attr("data-gc-snapshot") || "0") === "1";

        const baseCsv = String($export.attr("data-gc-export-csv-base") || "");
        const basePdf = String($export.attr("data-gc-export-pdf-base") || "");

        const csvUrl = safeMakeUrl(baseCsv, view, anchor);
        const pdfUrl = safeMakeUrl(basePdf, view, anchor);

        const $main = $export.find(".gc-export__btn--main").first();
        const $csvItem = $export.find('.gc-export__item[data-format="csv"]').first();
        const $pdfItem = $export.find('.gc-export__item[data-format="pdf"]').first();

        if ($csvItem.length && csvUrl) {
          $csvItem.attr("href", csvUrl);
        }

        if ($pdfItem.length && pdfUrl) {
          $pdfItem.attr("href", pdfUrl);
        }

        let format = "csv";

        try {
          const storageKey = "gc_export_last_format" + (uid ? "_" + uid : "");
          format =
            window.localStorage.getItem(storageKey) ||
            ($main.data("default-format") || "csv");
        } catch (e) {
          // no-op
        }

        if ($main.length) {
          if (format === "pdf" && pdfUrl) {
            $main.attr("href", pdfUrl);
          } else if (csvUrl) {
            $main.attr("href", csvUrl);
          }

          const $lbl = $main.find(".gc-export__label").first();
          if ($lbl.length) {
            $lbl.text(format === "pdf" ? "Export PDF" : "Export CSV");
          }
        }

        if (!hasSnap) {
          $main.attr("href", "#");

          if ($csvItem.length) {
            $csvItem.attr("href", "#");
          }

          if ($pdfItem.length) {
            $pdfItem.attr("href", "#");
          }
        }
      }

      function initTabsA11y() {
        const $tablist = $ui.find(".gc-tabs").first();

        if (!$tablist.length) {
          return null;
        }

        $tablist.attr("role", "tablist");
        const $tabs = $tablist.find(".gc-tab");

        function syncTabs() {
          const $active = $tabs.filter(".is-active").first();

          $tabs.each(function () {
            const $t = $(this);
            const isActive = $t.hasClass("is-active");

            $t.attr({
              role: "tab",
              "aria-selected": isActive ? "true" : "false",
              tabindex: isActive ? "0" : "-1"
            });
          });

          if (!$active.length && $tabs.length) {
            $tabs.first().attr("tabindex", "0");
          }
        }

        function focusByIndex(i) {
          const max = $tabs.length - 1;
          $tabs.eq(Math.max(0, Math.min(i, max))).trigger("focus");
        }

        function currentIndex() {
          const $cur = $(document.activeElement).closest(".gc-tab");
          let idx = $tabs.index($cur);

          if (idx < 0) {
            idx = $tabs.index($tabs.filter(".is-active").first());
          }

          return idx < 0 ? 0 : idx;
        }

        syncTabs();

        $tablist.off("keydown.gcTabsA11y").on(
          "keydown.gcTabsA11y",
          ".gc-tab",
          function (e) {
            const key = e.key;

            if (key === "ArrowRight" || key === "Right") {
              e.preventDefault();
              const idx = currentIndex();
              focusByIndex(idx + 1 > $tabs.length - 1 ? 0 : idx + 1);
              return;
            }

            if (key === "ArrowLeft" || key === "Left") {
              e.preventDefault();
              const idx = currentIndex();
              focusByIndex(idx - 1 < 0 ? $tabs.length - 1 : idx - 1);
              return;
            }

            if (key === "Home") {
              e.preventDefault();
              focusByIndex(0);
              return;
            }

            if (key === "End") {
              e.preventDefault();
              focusByIndex($tabs.length - 1);
              return;
            }

            if (key === "Enter" || key === " " || key === "Spacebar") {
              e.preventDefault();
              $(this).trigger("click");
            }
          }
        );

        return { syncTabs };
      }

      const tabsA11y = initTabsA11y();

      function refreshTabUI(activeTab, previousTab) {
        $ui.find(".gc-tabs .gc-tab").removeClass("is-active");

        if (activeTab && activeTab.length) {
          activeTab.addClass("is-active");
        } else if (previousTab && previousTab.length) {
          previousTab.addClass("is-active");
        }

        if (tabsA11y && tabsA11y.syncTabs) {
          tabsA11y.syncTabs();
        }

        if (window.gcTabsPillRefresh) {
          window.gcTabsPillRefresh();
        }
      }

      function initExportDropdown() {
        const $export = $ui.find("[data-gc-export]").first();

        if (!$export.length) {
          return;
        }

        const $main = $export.find(".gc-export__btn--main").first();
        const $chev = $export.find(".gc-export__btn--chev").first();
        const $menu = $export.find(".gc-export__menu").first();

        if (!$main.length || !$chev.length || !$menu.length) {
          return;
        }

        const storageKey = "gc_export_last_format" + (uid ? "_" + uid : "");

        function snapshotAllowed() {
          return String($export.attr("data-gc-snapshot") || "0") === "1";
        }

        function snapshotMsg() {
          return String($export.attr("data-gc-snapshot-msg") || EMPTY_EXPORT_MSG);
        }

        function getItems() {
          return $menu.find("a.gc-export__item:visible");
        }

        function setMenuTabbable(on) {
          getItems().attr("tabindex", on ? "0" : "-1");
        }

        function setMainLabelFromFormat(format) {
          const f = String(format || "").toLowerCase();
          const label = f === "pdf" ? "Export PDF" : "Export CSV";

          $main.find(".gc-export__label").text(label);
          $main.attr("aria-label", label);
        }

        function setMainHref(format) {
          const $item = $menu
            .find('.gc-export__item[data-format="' + format + '"]')
            .first();

          if ($item.length) {
            const href = String($item.attr("href") || "");
            if (href) {
              $main.attr("href", href);
            }
          }
        }

        function markLast(format) {
          $menu.find(".gc-export__item").removeClass("is-last");
          $menu
            .find('.gc-export__item[data-format="' + format + '"]')
            .addClass("is-last");
        }

        function focusItem(index) {
          const $items = getItems();

          if (!$items.length) {
            return;
          }

          const max = $items.length - 1;
          $items.eq(Math.max(0, Math.min(index, max))).trigger("focus");
        }

        function blurButtons() {
          $chev.trigger("blur");
          $main.trigger("blur");

          if ($chev[0]) {
            $chev[0].blur();
          }

          if ($main[0]) {
            $main[0].blur();
          }
        }

        if (!$main.find(".gc-export__label").length) {
          const $icon = $main.find(".gc-export__icon").first();
          const $label = $('<span class="gc-export__label">Export</span>');

          if ($icon.length) {
            $icon.after($label);
          } else {
            $main.append($label);
          }
        }

        const menuId =
          $menu.attr("id") ||
          ("gcExportMenu-" + Math.random().toString(16).slice(2));

        $menu.attr({
          id: menuId,
          role: "menu",
          hidden: true
        });

        $chev.attr({
          "aria-expanded": "false",
          "aria-controls": menuId,
          "aria-haspopup": "menu",
          type: "button"
        });

        getItems().attr("role", "menuitem");
        setMenuTabbable(false);

        function openMenu(focusFirst) {
          if (!snapshotAllowed()) {
            const msg = snapshotMsg();

            $main.attr("aria-disabled", "true").attr("title", msg);
            $chev
              .prop("disabled", true)
              .attr("aria-disabled", "true")
              .attr("title", msg);
            return;
          }

          $export.addClass("is-open");
          $menu.attr("hidden", false);
          $chev.attr("aria-expanded", "true");
          $main.attr("aria-disabled", "true");
          setMenuTabbable(true);

          if (focusFirst) {
            const $last = $menu.find("a.gc-export__item.is-last").first();
            ($last.length ? $last : getItems().first()).trigger("focus");
          }
        }

        function closeMenu(returnFocus) {
          $export.removeClass("is-open");
          $menu.attr("hidden", true);
          $chev.attr("aria-expanded", "false");

          if (snapshotAllowed()) {
            $main.removeAttr("aria-disabled").removeAttr("title");
            $chev
              .prop("disabled", false)
              .removeAttr("aria-disabled")
              .removeAttr("title");
          }

          setMenuTabbable(false);

          if (returnFocus) {
            $chev.trigger("focus");
          } else {
            blurButtons();
          }
        }

        function toggleMenu() {
          if ($export.hasClass("is-open")) {
            closeMenu(false);
          } else {
            openMenu(true);
          }
        }

        try {
          const saved = window.localStorage.getItem(storageKey);
          const fallback = $main.data("default-format") || "csv";
          const format = saved || fallback;

          markLast(format);
          setMainHref(format);
          setMainLabelFromFormat(format);
        } catch (e) {
          // no-op
        }

        getItems().each(function () {
          $(this).attr("target", "_blank").attr("rel", "noopener noreferrer");
        });

        $main.attr("target", "_blank").attr("rel", "noopener noreferrer");

        $chev.off(".gcExport");
        $main.off(".gcExport");
        $menu.off(".gcExport");
        $(document).off(".gcExport" + uid);

        $chev.on("click.gcExport", function (e) {
          e.preventDefault();
          e.stopPropagation();
          toggleMenu();
        });

        $chev.on("keydown.gcExport", function (e) {
          if (!snapshotAllowed()) {
            if (e.key === "Enter" || e.key === " " || e.key === "Spacebar") {
              e.preventDefault();
              e.stopPropagation();
            }
            return;
          }

          if (e.key === "ArrowDown" || e.key === "Down") {
            e.preventDefault();
            e.stopPropagation();

            if (!$export.hasClass("is-open")) {
              openMenu(true);
            } else {
              focusItem(0);
            }
          } else if (e.key === "ArrowUp" || e.key === "Up") {
            e.preventDefault();
            e.stopPropagation();

            if (!$export.hasClass("is-open")) {
              openMenu(true);
            } else {
              focusItem(getItems().length - 1);
            }
          } else if (e.key === "Enter" || e.key === " " || e.key === "Spacebar") {
            e.preventDefault();
            e.stopPropagation();
            toggleMenu();

            if ($export.hasClass("is-open")) {
              focusItem(0);
            }
          } else if (e.key === "Escape") {
            e.preventDefault();
            e.stopPropagation();
            closeMenu(true);
          }
        });

        $main.on("click.gcExport", function (e) {
          if (!snapshotAllowed()) {
            e.preventDefault();
            e.stopPropagation();
            return false;
          }

          if ($export.hasClass("is-open")) {
            e.preventDefault();
            e.stopPropagation();
            return false;
          }

          const href = String($main.attr("href") || "");
          if (!href || href === "#") {
            e.preventDefault();
            e.stopPropagation();
            return false;
          }
        });

        $menu.on("keydown.gcExport", "a.gc-export__item", function (e) {
          const $items = getItems();
          const idx = $items.index(this);

          if (e.key === "ArrowDown" || e.key === "Down") {
            e.preventDefault();
            e.stopPropagation();
            focusItem(idx + 1);
          } else if (e.key === "ArrowUp" || e.key === "Up") {
            e.preventDefault();
            e.stopPropagation();
            focusItem(idx - 1);
          } else if (e.key === "Home") {
            e.preventDefault();
            e.stopPropagation();
            focusItem(0);
          } else if (e.key === "End") {
            e.preventDefault();
            e.stopPropagation();
            focusItem($items.length - 1);
          } else if (e.key === "Escape") {
            e.preventDefault();
            e.stopPropagation();
            closeMenu(true);
          }
        });

        $menu.on("click.gcExport", "a.gc-export__item", function () {
          if (!snapshotAllowed()) {
            return false;
          }

          const format = $(this).data("format");

          if (format) {
            try {
              window.localStorage.setItem(storageKey, String(format));
            } catch (e) {
              // no-op
            }

            markLast(String(format));
            setMainHref(String(format));
            setMainLabelFromFormat(String(format));
          }

          closeMenu(false);
        });

        $(document).on("click.gcExport" + uid, function (e) {
          if (!$export.is(e.target) && $export.has(e.target).length === 0) {
            closeMenu(false);
          }
        });
      }

      function whenChartReady(cb) {
        if (typeof window.Chart !== "undefined") {
          cb();
          return;
        }

        let tries = 0;

        const t = setInterval(function () {
          tries++;

          if (typeof window.Chart !== "undefined" || tries > 50) {
            clearInterval(t);
            cb();
          }
        }, 100);
      }

      function fetchDashboard(view, hooks, forcedDate) {
        hooks = hooks || {};

        if (isFetching) {
          return;
        }

        isFetching = true;

        clearChartState();
        setLoading(true, LOADING_CHART_MSG);

        const bootedKey = "__vcarbBooted";
        const isFirstBoot = !$ui.data(bootedKey);

        if (isFirstBoot) {
          $ui.data(bootedKey, 1);
        }

        const cleanView = String(view || "month");
        const explicitDate = typeof forcedDate === "string" ? forcedDate : "";

        let sentDate = "";
        if (explicitDate !== "") {
          sentDate = explicitDate;
        }

        const cacheKey = cleanView + "|" + String(sentDate || "__latest__");

        function applyDataset(data) {
          const returnedView = String((data && data.view) || cleanView);
          const anchor = getAnchorFromData(data);

          if (data.snapshot) {
            applySnapshotState(data.snapshot);
          }

          $ui.attr("data-view", returnedView).data("view", returnedView);
          $ui.attr("data-date", anchor).data("date", anchor);

          if (data.metrics) {
            updateMetrics(data.metrics);
          }

          if (data.score) {
            updateScorePanel($ui, data.score);
          }

          renderChart(
            data.chart || { labels: [], co2: [], orders: [] },
            data.metrics || {}
          );

          const insights = data && data.insights ? data.insights : {};
          const hotspots = Array.isArray(data.hotspots) ? data.hotspots : [];

          const $insightsHost = uid
            ? $("#" + uid + "UserInsights")
            : $ui.find("[id$='UserInsights']").first();

          if ($insightsHost.length) {
            if (data.snapshot && data.snapshot.has === false) {
              $insightsHost.html(
                '<div class="gc-empty">' + escHtml(NO_INSIGHTS) + "</div>"
              );
            } else {
              $insightsHost.html(renderInsightsHtml(insights));
            }

            if (typeof window.gcInsightsInitFilters === "function") {
              window.gcInsightsInitFilters($insightsHost[0]);
            }
          }

          const $hotspotPanel = $ui.find(".gc-panel--hotspot").first();
          if ($hotspotPanel.length) {
            $hotspotPanel.html(renderHotspotsHtml(hotspots));
          }

          updatePeriodText($ui, returnedView, anchor);
          syncExportLinks(returnedView, anchor);

          if (data.browser) {
            renderPeriodBrowser(data.browser, returnedView, anchor);
          }

          $ui.find(".gc-metric__chip").text(
            returnedView.charAt(0).toUpperCase() + returnedView.slice(1)
          );

          const $noticeHost = $ui.find(".gc-dashboard-notice").first();

          if ($noticeHost.length) {
            if (data.notice) {
              $noticeHost.html(
                '<div class="gc-card gc-card--notice">' +
                escHtml(String(data.notice)) +
                "</div>"
              );
            } else {
              $noticeHost.empty();
            }
          }

          setUrlParams(returnedView, anchor);
        }

        if (cache[cacheKey]) {
          applyDataset(cache[cacheKey]);

          if (hooks.onSuccess) {
            hooks.onSuccess(cache[cacheKey]);
          }

          isFetching = false;
          setLoading(false);
          return;
        }

        return $.post(DASH_CFG.ajaxUrl, {
          action: "vcarb_dashboard_report",
          nonce: DASH_CFG.nonce,
          view: cleanView,
          date: sentDate
        })
          .done(function (res) {
            if (!res || !res.success) {
              setError(LOAD_ERROR_MSG);

              if (hooks.onError) {
                hooks.onError();
              }

              return;
            }

            const data = res.data || {};
            const returnedView = String(data.view || cleanView);
            const anchor = getAnchorFromData(data);
            const storeKey = returnedView + "|" + String(anchor || "__latest__");

            cache[storeKey] = data;
            applyDataset(data);

            if (hooks.onSuccess) {
              hooks.onSuccess(data);
            }
          })
          .fail(function () {
            setError(NETWORK_ERROR_MSG);

            if (hooks.onError) {
              hooks.onError();
            }
          })
          .always(function () {
            isFetching = false;
            setLoading(false);
          });
      }

      $ui.off("click.gcTabs").on("click.gcTabs", ".gc-tabs .gc-tab", function (e) {
        const $a = $(this);

        e.preventDefault();

        const view = String($a.data("view") || "month");
        const $prevActive = $ui.find(".gc-tabs .gc-tab.is-active").first();

        fetchDashboard(
          view,
          {
            onSuccess: function () {
              refreshTabUI($a, null);
            },
            onError: function () {
              refreshTabUI(null, $prevActive);
            }
          },
          ""
        );
      });

      $ui.off("click.gcPeriodNav").on(
        "click.gcPeriodNav",
        "[data-gc-period-nav]",
        function (e) {
          const $link = $(this);

          if ($link.is('[aria-disabled="true"]') || $link.hasClass("is-disabled")) {
            e.preventDefault();
            return;
          }

          const navType = String($link.data("gc-period-nav") || "");
          const href = String($link.attr("href") || "");

          e.preventDefault();

          if (navType === "latest" || navType === "current") {
            fetchDashboard(String($ui.data("view") || "month"), {}, "");
            return;
          }

          if (!href) {
            return;
          }

          const parsed = parseViewDateFromHref(
            href,
            String($ui.data("view") || "month")
          );

          fetchDashboard(parsed.view, {}, parsed.date);
        }
      );

      applySnapshotState({
        has: String($ui.data("has-snapshot") || "0") === "1",
        period: String($ui.data("date") || ""),
        message:
          String($ui.data("has-snapshot") || "0") === "1"
            ? ""
            : EMPTY_EXPORT_MSG
      });

      initExportDropdown();

      whenChartReady(function () {
        fetchDashboard(initialView);
      });
    });
  });
})();