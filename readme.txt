# 🌿 VerdantCart Carbon Reports

[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759B?logo=wordpress)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-96588A?logo=woocommerce)](https://woocommerce.com)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**Carbon analytics and reporting for WooCommerce stores.**

VerdantCart Carbon Reports helps WooCommerce store owners estimate order emissions, review carbon reporting trends, identify product hotspots, and export structured reports from inside WordPress.

---

## 📸 Live Demo

👉 **[View Live Demo](https://bean-and-bond.myshopify.com)** - See carbon tracking in action on a real coffee roaster store

---

## 📸 Plugin Screenshots

### Admin Carbon Dashboard
![Carbon Dashboard](assets/images/dashboard-preview.png)

*Store-wide emissions dashboard with monthly/weekly/yearly views*

### Customer Frontend View
![Customer Dashboard](assets/images/customer-view.png)

*Frontend dashboard showing customers their individual impact*

### Hotspot Analysis
![Hotspot Reporting](assets/images/hotspot-reporting.png)

*Identify which products contribute most to your carbon footprint*

---

## 🚀 Overview

VerdantCart Carbon Reports is a WooCommerce-focused reporting plugin built to make store emissions visible in a practical and structured way.

Instead of behaving like a generic carbon calculator, it works from WooCommerce order activity and saved reporting snapshots so merchants can review carbon data in a more stable and auditable format.

---

## 💡 Core Idea

**Carbon data should behave like financial data.**

That means it should be:

- ✅ tied to real store activity
- ✅ based on saved reporting snapshots
- ✅ visible in clear dashboards
- ✅ comparable over time
- ✅ exportable
- ✅ useful for operational review

---

## ✨ Features

### 📊 Admin Reporting

| Feature | Description |
|---------|-------------|
| Store-wide dashboard | Complete carbon reporting overview |
| Multi-period views | Monthly, weekly, and yearly reporting |
| Snapshot reporting | Consistent, auditable data |
| Customer breakdown | Per-period customer reporting |
| Product hotspots | Identify emission-heavy products |
| Sustainability insights | Smart, actionable recommendations |

### 🏠 Front Dashboard

- Customer-facing dashboard shortcode
- Logged-in user reporting view
- Month, week, and year period tabs
- Snapshot-aware reporting display
- Frontend metrics, trends, hotspots, and insights

### 📄 Exports

- CSV export
- PDF-style printable report export
- Snapshot-based export validation
- Export audit support

### 🛒 WooCommerce Integration

- Tracks eligible WooCommerce orders
- Calculates estimated emissions from product weights
- Supports historical backfill
- Updates aggregate reporting periods
- Supports store-level and customer-level reporting

---

## 📋 Requirements

| Requirement | Version |
|-------------|---------|
| WordPress | 6.4 or later |
| PHP | 8.0 or later |
| WooCommerce | 8.0 or later |

---

## 📦 Installation

### Manual Installation

1. Download the plugin from this repository
2. Upload `verdantcart-carbon-reports` to your `/wp-content/plugins/` directory
3. Activate **VerdantCart Carbon Reports** in WordPress
4. Make sure WooCommerce is active
5. Open the plugin admin pages from the WordPress dashboard

---

## 🔧 Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[verdantcart_dashboard]` | Main customer carbon dashboard |
| `[vccr_dashboard]` | Alternative dashboard shortcode |

---

## 📁 Main Plugin File

```text
verdantcart-ai-reports.php
