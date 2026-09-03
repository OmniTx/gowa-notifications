<?php
/**
 * Plugin Name:       Notify with GOWA
 * Plugin URI:        https://github.com/omnitx/notify-with-gowa
 * Description:       Automated and custom notifications for WordPress and WooCommerce powered by the self-hosted GOWA (Go WhatsApp Web Multi-Device) REST API gateway.
 * Version:           1.4.8.8
 * Author:            Imran Ahmed
 * Author URI:        https://imran.mvp.bd
 * Text Domain:       notify-with-gowa
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GOWA_VERSION', '1.4.8.8' );
define( 'GOWA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GOWA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GOWA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main Plugin Class
 */
class Notify_With_GOWA {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once GOWA_PLUGIN_DIR . 'includes/class-gowa-api.php';
        require_once GOWA_PLUGIN_DIR . 'includes/class-notify-with-gowa.php';
        
        if ( class_exists( 'WooCommerce' ) || in_array( 'woocommerce/woocommerce.php', (array) get_option( 'active_plugins', array() ), true ) ) {
            require_once GOWA_PLUGIN_DIR . 'includes/class-gowa-woocommerce.php';
        } else {
            add_action( 'plugins_loaded', array( $this, 'load_woocommerce_integration' ), 20 );
        }

        // Only load the GitHub updater if bundled (Standalone GitHub builds)
        if ( is_admin() && file_exists( GOWA_PLUGIN_DIR . 'includes/class-gowa-updater.php' ) ) {
            require_once GOWA_PLUGIN_DIR . 'includes/class-gowa-updater.php';
        }

        if ( is_admin() ) {
            require_once GOWA_PLUGIN_DIR . 'admin/class-gowa-admin.php';
        }
    }

    public function load_woocommerce_integration() {
        if ( class_exists( 'WooCommerce' ) && ! class_exists( 'Notify_With_GOWA_WooCommerce' ) ) {
            if ( file_exists( GOWA_PLUGIN_DIR . 'includes/class-gowa-woocommerce.php' ) ) {
                require_once GOWA_PLUGIN_DIR . 'includes/class-gowa-woocommerce.php';
            }
        }
    }

    private function init_hooks() {
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
        add_filter( 'plugin_action_links_' . GOWA_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );

        // Action Scheduler asynchronous background worker
        add_action( 'notify_with_gowa_async_send', array( 'Notify_With_GOWA_API', 'handle_async_send' ), 10, 4 );

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
    }

    public function declare_hpos_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }

    public function add_action_links( $links ) {
        $settings_link = '<a href="' . admin_url( 'options-general.php?page=notify-with-gowa' ) . '">' . __( 'Settings', 'notify-with-gowa' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Get default plugin settings
     *
     * @return array
     */
    public static function get_defaults() {
        return array(
            'api_url'                 => 'http://localhost:3000',
            'device_id'               => '',
            'auth_user'               => '',
            'auth_pass'               => '',
            'default_country_code'    => '880',
            'admin_phone'             => '',
            'enable_wp_user_reg'      => 1,
            'wp_user_reg_msg'         => "🎉 *New User Registered*\n\nSite: {site_name}\nUsername: {username}\nEmail: {email}\nRegistered: {date}",
            'enable_wp_comment'       => 0,
            'wp_comment_msg'          => "💬 *New Comment on {site_name}*\n\nAuthor: {author}\nPost: {post_title}\nComment: {comment_content}",
            'enable_wc_admin_order'   => 1,
            'wc_admin_order_msg'      => "🛍️ *New Order Received! #{order_id}*\n\nCustomer: {customer_name}\nTotal: {order_total}\nItems:\n{order_items}\nPhone: {billing_phone}\nNotes: {customer_note}",
            'enable_wc_cust_process'  => 1,
            'wc_cust_process_msg'     => "Hello {customer_name},\n\nThank you for your order *#{order_id}* at {site_name}! We have received your order and it is currently being processed.\n\nOrdered Items:\n{order_items}\n\nOrder Total: {order_total}\n\nWe will contact you shortly for delivery.",
            'enable_wc_cust_complete' => 1,
            'wc_cust_complete_msg'    => "Hello {customer_name},\n\nYour order *#{order_id}* from {site_name} has been completed! 🎉\n\nThank you for shopping with us. Hope you enjoy our service!",
            'enable_wc_cust_cancelled' => 0,
            'wc_cust_cancelled_msg'   => "Hello {customer_name},\n\nYour order *#{order_id}* at {site_name} has been cancelled. If you have any questions, please contact our support team.",
            'enable_wc_low_stock'     => 0,
            'wc_low_stock_msg'        => "⚠️ *Low Stock Alert*\n\nProduct: {product_name} (ID: {product_id})\nRemaining Stock: {stock_quantity}",
            'async_delay_seconds'     => 0,
        );
    }

    public function activate() {
        $defaults = self::get_defaults();
        $existing = get_option( 'notify_with_gowa_settings', array() );
        $merged   = wp_parse_args( $existing, $defaults );
        update_option( 'notify_with_gowa_settings', $merged );
    }
}

if ( ! function_exists( 'notify_with_gowa' ) ) {
    function notify_with_gowa() {
        return Notify_With_GOWA::instance();
    }
}
notify_with_gowa();
