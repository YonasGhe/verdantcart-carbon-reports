(function () {
    "use strict";

    if (window.__vcarbInsightsFiltersLoaded) {
        return;
    }

    window.__vcarbInsightsFiltersLoaded = true;

    function init(root) {
        root = root || document;

        if (!root.querySelectorAll) {
            return;
        }

        root.querySelectorAll(".gc-insights").forEach(function (wrap) {
            if (!wrap || wrap.dataset.gcFiltersBound === "1") {
                return;
            }

            wrap.dataset.gcFiltersBound = "1";

            var filters = wrap.querySelectorAll(".gc-insights__filter");
            var groups = wrap.querySelectorAll(".gc-insights__group");

            if (!filters.length || !groups.length) {
                return;
            }

            function apply(key) {
                key = String(key || "all").toLowerCase();

                filters.forEach(function (btn) {
                    var filterKey = String(btn.getAttribute("data-filter") || "").toLowerCase();
                    var active = filterKey === key;

                    btn.classList.toggle("is-active", active);
                    btn.setAttribute("aria-pressed", active ? "true" : "false");
                });

                groups.forEach(function (group) {
                    var type = String(group.getAttribute("data-group") || "").toLowerCase();
                    var show = key === "all" || type === key;

                    group.classList.toggle("is-hidden", !show);
                    group.setAttribute("aria-hidden", show ? "false" : "true");
                });
            }

            wrap.addEventListener("click", function (event) {
                var btn = event.target.closest(".gc-insights__filter");

                if (!btn || !wrap.contains(btn)) {
                    return;
                }

                event.preventDefault();
                apply(btn.getAttribute("data-filter"));
            });

            apply("all");
        });
    }

    window.vcarbInsightsInitFilters = init;

    /*
     * Shared alias used by the admin and dashboard scripts.
     */
    window.gcInsightsInitFilters = init;

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () {
            init(document);
        });
    } else {
        init(document);
    }
})();