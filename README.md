# Notify with GOWA for WordPress & WooCommerce

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759b.svg)](https://wordpress.org)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-Compatible-96588a.svg)](https://woocommerce.com)

Automated and custom notifications for WordPress and WooCommerce powered by the self-hosted **[GOWA (Go WhatsApp Web Multi-Device)](https://github.com/aldinokemal/go-whatsapp-web-multidevice)** REST API gateway.

Developed by **[Imran Ahmed](https://imran.mvp.bd)** ([@omnitx](https://github.com/omnitx)).

---

## 🚀 Features

- **100% Self-Hosted & Private**: Connects directly to your own self-hosted GoWA REST API gateway. No third-party recurring subscription fees or Meta per-template charges.
- **Asynchronous Background Queue**: Integrated with WooCommerce Action Scheduler for zero-delay, non-blocking checkout performance.
- **Multi-Admin Alert Routing**: Supports multiple comma-separated admin numbers for instant store alerts.
- **WooCommerce Order Notifications**:
  - Automatically send order receipt & processing notifications to clients.
  - Automatically notify clients when their order is completed/delivered.
  - Automatically notify clients if an order is cancelled.
  - Store Admin alerts for new orders and low stock / out-of-stock inventory.
- **Direct Client Messaging Panel (Order Metabox)**:
  - Send custom messages to clients directly from the WooCommerce Order details screen.
  - Quick template shortcuts (Order Update, Courier/Tracking info, Payment Reminders).
  - Automatically adds sent messages to WooCommerce Order Notes.
- **WordPress Core Alerts**:
  - Instant admin notifications for new user registrations.
  - Instant admin notifications for new comments.
- **Expanded Dynamic Placeholders**:
  - Full control over templates using: `{customer_name}`, `{customer_first_name}`, `{customer_last_name}`, `{customer_email}`, `{order_id}`, `{order_number}`, `{order_total}`, `{order_items}`, `{items_count}`, `{customer_note}`, `{billing_phone}`, `{shipping_address}`, `{shipping_method}`, `{payment_method}`, `{payment_url}`, `{order_date}`, `{site_name}`, `{site_url}`.
- **Universal Phone Number Normalization**:
  - Configurable Default Country Code (e.g. `880` for Bangladesh, `1` for USA/Canada, `91` for India, `44` for UK, `62` for Indonesia).
  - Automatically prefixes local numbers starting with `0` (e.g. `01700000000` with country code `880` becomes `8801700000000@s.whatsapp.net`).
  - Supports international formats with `+` or without.
- **WooCommerce Native Logging**:
  - Detailed logging using `wc_get_logger()` under source `gowa_whatsapp_api` (viewable in **WooCommerce > Status > Logs**).
- **Export & Import Settings**:
  - One-click export and import of all plugin configurations, API details, and customized notification templates via JSON (with credential obfuscation).
- **Live Diagnostics & Test Tool**:
  - Instant AJAX connection testing and message dispatcher with color-coded diagnostic responses.
- **HPOS Compatible**:
  - Declares full compatibility with WooCommerce High-Performance Order Storage (HPOS) and standard custom post types.
- **Safe Lifecycle & Data Privacy**:
  - Clean uninstall via `uninstall.php` only deletes data upon explicit plugin deletion, preserving your configuration during deactivation.

---

## 📦 Installation

1. Download the latest release from the repository or download `notify-with-gowa.zip`.
2. In your WordPress Admin Dashboard, go to **Plugins > Add New > Upload Plugin**.
3. Upload `notify-with-gowa.zip` and click **Activate**.
4. Navigate to **Settings > Notify with GOWA** to configure your server.

---

## ⚙️ Configuration

1. Go to **Settings > Notify with GOWA > API & Gateway**:
   - **GOWA Server URL**: Enter your GOWA REST API URL (e.g., `https://wa.yourdomain.com` or `http://localhost:3000`).
   - **Device ID**: Enter your device UUID if using GOWA multi-device (or leave blank for default).
   - **Basic Auth**: Enter your username and password if GOWA is secured with basic auth.
   - **Default Country Code**: Enter your country calling code without `+` (e.g. `880` for BD, `1` for US, `91` for India).
   - **Store Admin WhatsApp Number(s)**: Enter your admin phone number(s) to receive alerts (supports multiple numbers separated by commas).
2. Go to the **Direct Client Message / Test** tab and click **Check GOWA Connection** to verify server connectivity.
3. Under **Client & Order Messages**, customize your notification templates as desired.

---

## 👤 Author & Credits

- **Author**: Imran Ahmed
- **Website**: [imran.mvp.bd](https://imran.mvp.bd)
- **GitHub**: [github.com/omnitx](https://github.com/omnitx)
- **Email**: [imranomnitx@duck.com](mailto:imranomnitx@duck.com)

---

## 📄 License

This project is licensed under the GNU General Public License v2.0 or later. See [LICENSE](LICENSE) for details.