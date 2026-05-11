jQuery(function ($) {
    "use strict";

    if (window.__vcarbBackfillInitBound) {
        return;
    }

    window.__vcarbBackfillInitBound = true;

    function getConfig() {
        if (typeof window.vcarbBackfillAjax !== "undefined") {
            return window.vcarbBackfillAjax;
        }

        if (typeof window.amatorcarbonBackfillAjax !== "undefined") {
            return window.amatorcarbonBackfillAjax;
        }

        return {};
    }

    var CFG = getConfig();

    if (!CFG.ajaxurl || !CFG.nonce) {
        console.error("VerdantCart Carbon Reports backfill JS: missing localized config.");
        return;
    }

    var ACTIONS = CFG.actions || {};

    var ACTION_START = ACTIONS.start || "vcarb_backfill_start";
    var ACTION_BATCH = ACTIONS.batch || "vcarb_backfill_batch";
    var ACTION_STOP = ACTIONS.stop || "vcarb_backfill_stop";

    var running = false;
    var includeCounted = 1;
    var batchRequest = null;
    var startRequest = null;
    var stopRequest = null;

    function escHtml(value) {
        return String(value == null ? "" : value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function getStatusRoot() {
        return $("#gcBackfillStatus");
    }

    function getStartButton() {
        return $("#gcBackfillStart");
    }

    function getStopButton() {
        return $("#gcBackfillStop");
    }

    function getIncludeCountedInput() {
        return $("#gcBackfillIncludeCounted");
    }

    function setStatus(html) {
        var $root = getStatusRoot();

        if ($root.length) {
            $root.html(html);
        }
    }

    function toggleButtons(isRunning) {
        getStartButton().prop("disabled", !!isRunning);
        getStopButton().prop("disabled", !isRunning);
        getIncludeCountedInput().prop("disabled", !!isRunning);
    }

    function abortRequest(request) {
        if (request && typeof request.abort === "function") {
            try {
                request.abort();
            } catch (e) {
                // No-op.
            }
        }
    }

    function baseStatusData(message) {
        return {
            page: 0,
            total_pages: 0,
            processed_total: 0,
            updated_total: 0,
            skipped_total: 0,
            last_order_id: "",
            message: message || "",
            stopped: false,
            done: false
        };
    }

    function normalizeData(data) {
        data = data && typeof data === "object" ? data : {};

        return {
            page: Number(data.page || 0),
            total_pages: Number(data.total_pages || 0),
            processed_total: Number(data.processed_total || 0),
            updated_total: Number(data.updated_total || 0),
            skipped_total: Number(data.skipped_total || 0),
            last_order_id: data.last_order_id != null ? String(data.last_order_id) : "",
            message: data.message != null ? String(data.message) : "",
            stopped: !!data.stopped,
            done: !!data.done
        };
    }

    function statusBadge(mode) {
        if (mode === "done") {
            return '<span class="gc-status-badge gc-status-badge--ok">✅ Complete</span>';
        }

        if (mode === "stopped") {
            return '<span class="gc-status-badge gc-status-badge--warn">⏸ Stopped</span>';
        }

        if (mode === "error") {
            return '<span class="gc-status-badge gc-status-badge--err">⚠ Failed</span>';
        }

        return '<span class="gc-status-badge gc-status-badge--info">⏳ Running</span>';
    }

    function progressPct(data) {
        var page = Number(data.page || 0);
        var totalPages = Number(data.total_pages || 0);

        if (page <= 0 || totalPages <= 0) {
            return 0;
        }

        return Math.max(0, Math.min(100, Math.round((page / totalPages) * 100)));
    }

    function modeLogText(mode) {
        if (mode === "done") {
            return "Backfill completed successfully.";
        }

        if (mode === "stopped") {
            return "Backfill stopped. You can start again when ready.";
        }

        if (mode === "error") {
            return "Backfill failed. Check the message above and your server logs.";
        }

        return "Processing batches… keep this page open.";
    }

    function renderStatus(rawData, mode) {
        var data = normalizeData(rawData);
        var pct = progressPct(data);
        var message = data.message !== "" ? escHtml(data.message) : "";

        var html =
            '<div class="gc-status-ui">' +
            '<div class="gc-status-top">' +
            '<div class="gc-status-title">' +
            "<strong>Status</strong>" +
            statusBadge(mode) +
            "</div>" +
            '<div class="gc-status-meta">' +
            (data.last_order_id !== ""
                ? "<span><strong>Last order:</strong> " + escHtml(data.last_order_id) + "</span>"
                : "") +
            "</div>" +
            "</div>" +
            '<div class="gc-progress" aria-hidden="true">' +
            '<div class="gc-progress__bar" style="width:' + pct + '%"></div>' +
            "</div>" +
            '<div class="gc-progress__label">' +
            escHtml(pct) + "% complete" +
            (mode === "running" ? '<span class="gc-muted"> · working…</span>' : "") +
            "</div>" +
            '<div class="gc-stats">' +
            '<div class="gc-stat">' +
            '<div class="gc-stat__label">Processed total</div>' +
            '<div class="gc-stat__value">' + escHtml(data.processed_total) + "</div>" +
            "</div>" +
            '<div class="gc-stat">' +
            '<div class="gc-stat__label">Updated total</div>' +
            '<div class="gc-stat__value">' + escHtml(data.updated_total) + "</div>" +
            "</div>" +
            '<div class="gc-stat">' +
            '<div class="gc-stat__label">Skipped total</div>' +
            '<div class="gc-stat__value">' + escHtml(data.skipped_total) + "</div>" +
            "</div>" +
            '<div class="gc-stat">' +
            '<div class="gc-stat__label">Include counted</div>' +
            '<div class="gc-stat__value">' + (includeCounted ? "Yes" : "No") + "</div>" +
            "</div>" +
            "</div>" +
            (message !== "" ? '<div class="gc-status-msg">' + message + "</div>" : "") +
            '<div class="gc-status-log">' +
            escHtml(modeLogText(mode)) +
            "</div>" +
            "</div>";

        setStatus(html);
    }

    function setRunningState(isRunning) {
        running = !!isRunning;
        toggleButtons(running);
    }

    function extractErrorMessage(response, fallback) {
        if (
            response &&
            response.data &&
            typeof response.data.message === "string" &&
            response.data.message !== ""
        ) {
            return response.data.message;
        }

        if (response && response.data) {
            try {
                return JSON.stringify(response.data);
            } catch (e) {
                // No-op.
            }
        }

        return fallback || "Unknown error";
    }

    function stopAllRequests() {
        abortRequest(batchRequest);
        abortRequest(startRequest);
        abortRequest(stopRequest);

        batchRequest = null;
        startRequest = null;
        stopRequest = null;
    }

    function runBatch() {
        if (!running) {
            return;
        }

        abortRequest(batchRequest);

        batchRequest = $.post(CFG.ajaxurl, {
            action: ACTION_BATCH,
            nonce: CFG.nonce
        })
            .done(function (response) {
                var data;

                if (!response || !response.success) {
                    setRunningState(false);

                    renderStatus(
                        baseStatusData(extractErrorMessage(response, "Backfill batch failed.")),
                        "error"
                    );

                    return;
                }

                data = normalizeData(response.data);

                if (data.stopped) {
                    setRunningState(false);
                    renderStatus(data, "stopped");
                    return;
                }

                if (data.done) {
                    setRunningState(false);
                    renderStatus(data, "done");
                    return;
                }

                renderStatus(data, "running");
                window.setTimeout(runBatch, 350);
            })
            .fail(function (xhr, status) {
                if (status === "abort") {
                    return;
                }

                setRunningState(false);

                renderStatus(
                    baseStatusData(
                        "AJAX error: HTTP " +
                        (xhr && xhr.status ? xhr.status : 0) +
                        ". If this says already running, clear the saved backfill state/options."
                    ),
                    "error"
                );

                console.error(
                    "Backfill batch AJAX failed",
                    xhr && xhr.status,
                    xhr && xhr.responseText
                );
            })
            .always(function () {
                batchRequest = null;
            });
    }

    getStartButton().on("click", function (event) {
        event.preventDefault();

        if (running) {
            return;
        }

        stopAllRequests();

        includeCounted = getIncludeCountedInput().is(":checked") ? 1 : 0;

        setRunningState(true);
        renderStatus(baseStatusData("Starting backfill…"), "running");

        startRequest = $.post(CFG.ajaxurl, {
            action: ACTION_START,
            nonce: CFG.nonce,
            include_counted: includeCounted
        })
            .done(function (response) {
                if (!response || !response.success) {
                    setRunningState(false);

                    renderStatus(
                        baseStatusData(extractErrorMessage(response, "Failed to start backfill.")),
                        "error"
                    );

                    return;
                }

                window.setTimeout(runBatch, 250);
            })
            .fail(function (xhr, status) {
                if (status === "abort") {
                    return;
                }

                setRunningState(false);

                renderStatus(
                    baseStatusData(
                        "Start failed: HTTP " +
                        (xhr && xhr.status ? xhr.status : 0) +
                        ". Check console and debug.log."
                    ),
                    "error"
                );

                console.error(
                    "Backfill start AJAX failed",
                    xhr && xhr.status,
                    xhr && xhr.responseText
                );
            })
            .always(function () {
                startRequest = null;
            });
    });

    getStopButton().on("click", function (event) {
        event.preventDefault();

        abortRequest(stopRequest);

        stopRequest = $.post(CFG.ajaxurl, {
            action: ACTION_STOP,
            nonce: CFG.nonce
        })
            .done(function (response) {
                setRunningState(false);
                abortRequest(batchRequest);
                batchRequest = null;

                if (response && response.success) {
                    renderStatus(baseStatusData("Stop request accepted."), "stopped");
                    return;
                }

                renderStatus(
                    baseStatusData(extractErrorMessage(response, "Stop request failed.")),
                    "error"
                );
            })
            .fail(function (xhr, status) {
                if (status === "abort") {
                    return;
                }

                setRunningState(false);
                abortRequest(batchRequest);
                batchRequest = null;

                renderStatus(
                    baseStatusData(
                        "Stop failed: HTTP " + (xhr && xhr.status ? xhr.status : 0)
                    ),
                    "error"
                );

                console.error(
                    "Backfill stop AJAX failed",
                    xhr && xhr.status,
                    xhr && xhr.responseText
                );
            })
            .always(function () {
                stopRequest = null;
            });
    });

    toggleButtons(false);
});