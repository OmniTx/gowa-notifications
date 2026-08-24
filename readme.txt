=== GOWA Notifications ===
Contributors: omnitx, imranahmed
Donate link: https://imran.mvp.bd
Tags: notifications, woocommerce alerts, order notifications, gateway, messaging
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated and custom notifications for WordPress and WooCommerce powered by the self-hosted GOWA (Go WhatsApp Web Multi-Device) REST API gateway.

== Description ==

**GOWA Notifications** bridges your WordPress site and WooCommerce store directly with your self-hosted [GOWA (Go WhatsApp Web Multi-Device)](https://github.com/aldinokemal/go-whatsapp-web-multidevice) server.

Developed by [Imran Ahmed](https://imran.mvp.bd) ([omnitx](https://github.com/omnitx)).

### Key Features
* **100% Self-Hosted & Private**: Uses your own self-hosted GOWA REST API gateway without recurring third-party subscriptions or Meta per-template charges.
* **Asynchronous Background Queue**: Built-in integration with Action Scheduler ensures non-blocking, instantaneous checkout performance for store customers.
* **WooCommerce Order Notifications**:
  * Send receipt/processing notification to client upon order placement.
  * Send completion notification to client when order is completed.
  * Send cancellation notification to client when order is cancelled.
  * Send new order alerts and low stock alerts to store admin.
* **Direct Client Messaging & Metabox**:
  * Send custom messages directly to clients from the WooCommerce Order details page.
  * Quick templates for Order Updates, Shipping/Courier details, and Payment Reminders.
* **WordPress Core Alerts**: Instant notifications for new user registrations and comments.
* **Customizable Message Templates**: Full template control with dynamic placeholders (`{customer_name}`, `{order_id}`, `{order_total}`, `{order_items}`, `{customer_note}`, `{shipping_address}`, `{payment_method}`, `{site_name}`).
* **Universal Phone Number Normalizer**: Automatic country code prefixing (e.g. `880`, `1`, `91`, `44`, `62`).
* **Native WooCommerce Logger**: Full debug and transaction logs stored under WooCommerce > Status > Logs (`gowa_whatsapp_api`).
* **Export & Import Settings**: Easily backup, export, and restore all plugin configurations and notification templates via JSON.
* **Live AJAX Testing & Diagnostics**: Instant connection checker and test message dispatcher in the admin dashboard.

== Third-Party Service Disclosure ==

This plugin connects to a self-hosted instance of GOWA (Go WhatsApp Web Multi-Device REST API gateway) hosted by the site administrator to dispatch messages.
* GOWA Open Source Server: https://github.com/aldinokemal/go-whatsapp-web-multidevice
* Service Endpoint: Configured by the site administrator in Settings > GOWA Notifications.

== Installation ==

1. Upload the `gowa-notifications` folder to `/wp-content/plugins/` or upload the `.zip` file via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin in WordPress.
3. Go to **Settings > GOWA Notifications** and configure your server URL, credentials, and admin phone.
4. Verify your connection on the **Direct Client Message / Test** tab.

== Changelog ==

= 1.4.1 =
* Feature: Added multi-admin support (enter multiple phone numbers separated by commas).
* Feature: Added new dynamic message placeholders: {payment_url}, {shipping_method}, {items_count}, and {customer_email}.
* Fix: Resolved admin menu slug mismatch on Plugins page.

= 1.4.0 =
* Feature: Added Action Scheduler asynchronous background queueing for non-blocking notification dispatch.
* Rebrand: Renamed plugin to GOWA Notifications to abide by WordPress.org trademark guidelines.
* Fix: Formatted `{shipping_address}` tag to clean plain text (removed HTML `<br/>` tags).
* Security: Obfuscated password in JSON settings export via Base64 encoding.
* GitHub Updater Support: Integrated automatic updates for standalone GitHub installations.

= 1.3.3 =
* Feature: Added Settings Export & Import tool to backup and restore configurations as JSON.
* Fix: Auto-populate default notification templates in settings fields when empty.
* Improvement: Safely merge missing default settings on activation.

= 1.3.2 =

* Tweak: Use `woocommerce_checkout_order_processed` hook for order receipts to ensure complete order data and prevent false triggers from admin orders, imports, or trash restores.
* Fix: Rewritten `parse_order_tags()` now shows variation attributes, handles missing product names, filters line items only, and includes proper fallbacks.
* Fix: Added item count validation before sending order notifications.

= 1.3.1 =
* Updater: prefer CI-built release asset over raw zipball

= 1.3.0 =
* Added: Automatic update checks from GitHub Releases, with one-click update from the Plugins page.
* Fixed: Fatal error on new user registration / new comment notifications caused by a call to an undefined method.

= 1.2.0 =
* Initial public release.

== Author ==

* **Author**: Imran Ahmed
* **Website**: [imran.mvp.bd](https://imran.mvp.bd)
* **GitHub**: [github.com/omnitx](https://github.com/omnitx)
* **Email**: imranomnitx@duck.com
