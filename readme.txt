=== VerdantCart Carbon Reports ===
Contributors: greencart2026
Tags: woocommerce, carbon footprint, emissions, sustainability, reporting
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Estimate WooCommerce order emissions and review carbon reports with dashboards, trends, exports, and product insights.

== Description ==

VerdantCart Carbon Reports helps WooCommerce stores estimate carbon emissions from orders and review reporting data over time.

The plugin calculates estimated emissions for eligible WooCommerce orders, aggregates reporting data by period, and displays results in dashboards for both store-level and customer-level reporting.

Main reporting features include:

* Estimated carbon emissions per WooCommerce order
* Monthly, weekly, and yearly reporting views
* Store-level and customer-level dashboards
* Trend comparison against previous periods
* Product hotspot reporting
* CSV and PDF exports
* Sustainability insights
* Snapshot-based reporting for stable historical results

VerdantCart Carbon Reports is useful for:

* WooCommerce stores that want visibility into estimated order emissions
* Merchants preparing internal sustainability summaries
* Brands monitoring emission trends over time
* Store owners who need exportable reporting data

This plugin provides operational sustainability reporting based on WooCommerce order data. It is not a certified ESG report, GHG Protocol report, legal compliance document, or verified carbon audit.

== Features ==

* Estimate emissions for WooCommerce orders
* View reporting by month, week, and year
* Review store-level reporting data
* Review customer-level reporting data
* Compare reporting periods
* Identify higher-impact products with hotspot reporting
* Use snapshot-based reporting for stable results
* Export reports as CSV or PDF
* View sustainability insights
* Run backfill for historical WooCommerce orders

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Make sure **WooCommerce** is installed and activated.
4. Open **VerdantCart Carbon Reports** from the WordPress admin menu.
5. Create a completed order or run **Backfill** to generate historical reporting data.

== Usage ==

After activation, the plugin can:

* Estimate emissions for eligible WooCommerce orders
* Aggregate reporting data for supported periods
* Display reporting inside store and customer dashboards

You can use the dashboards to:

* View emissions by reporting period
* Review store and customer reporting data
* Monitor trends over time
* Identify higher-impact products
* Export reports for further use

The customer-facing dashboard can be displayed with this shortcode:

`[vcarb_dashboard]`

Legacy shortcodes from earlier builds may still work for compatibility, but `[vcarb_dashboard]` is the recommended shortcode.

== How It Works ==

VerdantCart Carbon Reports estimates emissions using internal calculation rules applied to WooCommerce order data.

Reporting data is stored inside your WordPress installation and organized into aggregated snapshots used by dashboards, comparisons, insights, hotspots, and exports.

The plugin uses snapshot-based reporting so historical reporting periods remain stable instead of being recalculated during normal dashboard page loads.

== Data & Privacy ==

All core calculations are performed locally in your WordPress installation.

The plugin does not require sending WooCommerce order data to an external service for its core reporting features.

== Requirements ==

* WordPress 6.4 or higher
* PHP 8.0 or higher
* WooCommerce 8.0 or higher

== Frequently Asked Questions ==

= Why does my dashboard show "No snapshot available"? =

That reporting period does not yet have generated snapshot data. Create a completed order or run the **Backfill** tool to generate reporting data.

= How are emissions calculated? =

Emissions are estimated using internal calculation rules applied to WooCommerce order data.

= Can I export reports? =

Yes. The plugin supports CSV and print-ready PDF exports.

= Which reporting periods are supported? =

The plugin supports month, week, and year reporting views.

= Does this plugin require WooCommerce? =

Yes. WooCommerce must be installed and active.

= Does the plugin send order data to an external service? =

No. Core reporting calculations are performed locally inside your WordPress installation.

= Is this a certified ESG or carbon audit report? =

No. VerdantCart Carbon Reports provides operational sustainability summaries and estimated emissions reporting. It is not a certified ESG report, GHG Protocol report, legal compliance document, or verified carbon audit.

== Screenshots ==

1. Dashboard overview
2. Emissions trend chart
3. Product hotspot reporting
4. Sustainability insights
5. CSV and PDF exports

== Changelog ==

= 1.1.0 =
* Updated internal naming to the VerdantCart Carbon Reports namespace.
* Improved dashboard, AJAX, export, and backfill naming consistency.
* Improved snapshot-based reporting flow for month, week, and year views.
* Improved admin dashboard period navigation and export handling.
* Improved compatibility during migration from earlier internal AmatorCarbon builds.
* Preserved existing reporting table names to avoid losing historical data.
* Added safer uninstall cleanup for current and legacy lightweight options.
* Removed local paid feature gating from the WordPress.org build.

= 1.0.2 =
* Improved VerdantCart branding consistency.
* Cleaned language folder contents.
* Improved dashboard and reporting asset organization.
* Minor Plugin Check cleanup.

= 1.0.1 =
* Updated branding to VerdantCart Carbon Reports.
* Cleaned up readme content and plugin metadata.
* Improved submission readiness and compatibility updates.

= 1.0.0 =
* Initial public release.
* Estimated carbon emission tracking for WooCommerce orders.
* Month, week, and year reporting views.
* Sustainability insights.
* Product hotspot reporting.
* CSV and PDF export support.
* Backfill support for historical orders.

== Upgrade Notice ==

= 1.1.0 =
Improves VerdantCart naming consistency, snapshot-based reporting, dashboard/export handling, and migration compatibility from earlier internal builds.

= 1.0.2 =
Branding consistency, language folder cleanup, and minor asset/reporting polish.

= 1.0.1 =
Branding, metadata, and readme update.

= 1.0.0 =
Initial public release of VerdantCart Carbon Reports.