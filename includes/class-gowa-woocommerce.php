<?php
/**
 * GOWA WooCommerce Integration, Order Actions & Dropdown
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GOWA_WooCommerce {

    public function __construct() {
        // Automatic Order Status Triggers
        add_action( 'woocommerce_new_order', array( $this, 'on_new_order_receipt' ), 20, 1 );
        add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_completed' ), 20, 1 );
        add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_cancelled' ), 20, 2 );

        // Stock alerts
        add_action( 'woocommerce_low_stock', array( $this, 'on_low_stock' ), 20, 1 );
        add_action( 'woocommerce_no_stock', array( $this, 'on_no_stock' ), 20, 1 );

        // Order Actions Dropdown
        add_filter( 'woocommerce_order_actions', array( $this, 'add_order_actions' ) );
        add_action( 'woocommerce_order_action_test_whatsapp_receipt', array( $this, 'process_action_test_receipt' ) );
        add_action( 'woocommerce_order_action_test_whatsapp_completed', array( $this, 'process_action_test_completed' ) );

        // Admin Order Metabox (Send Custom Message to Client)
        add_action( 'add_meta_boxes', array( $this, 'register_order_metabox' ) );
        add_action( 'wp_ajax_gowa_send_order_custom_msg', array( $this, 'ajax_send_order_custom_msg' ) );
    }

    public function add_order_actions( $actions ) {
        $actions['test_whatsapp_receipt']   = __( 'Send WhatsApp Receipt (Order Received)', 'gowa-notifications' );
        $actions['test_whatsapp_completed'] = __( 'Send WhatsApp (Order Completed)', 'gowa-notifications' );
        return $actions;
    }

    public function process_action_test_receipt( $order ) {
        $order_id = is_numeric( $order ) ? $order : $order->get_id();
        $this->on_new_order_receipt( $order_id );
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'WhatsApp "Order Received" message triggered! Check Order Notes for delivery status.', 'gowa-notifications' ) . '</p></div>';
        });
    }

    public function process_action_test_completed( $order ) {
        $order_id = is_numeric( $order ) ? $order : $order->get_id();
        $this->on_order_completed( $order_id );
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'WhatsApp "Order Completed" message triggered! Check Order Notes for delivery status.', 'gowa-notifications' ) . '</p></div>';
        });
    }

    public function on_new_order_receipt( $order_id ) {
        $settings = get_option( 'gowa_whatsapp_settings', array() );
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // 1. Send Receipt to Client in Background Queue
        if ( ! empty( $settings['enable_wc_cust_process'] ) ) {
            $customer_phone = $order->get_billing_phone();
            if ( ! empty( $customer_phone ) ) {
                $default_tpl = ! empty( $settings['wc_cust_process_msg'] ) ? $settings['wc_cust_process_msg'] : "Hello {customer_name},\n\nThank you for your order *#{order_id}* at {site_name}! We have received your order and it is currently being processed.\n\nTotal: {order_total}\nItems: {order_items}\n\nWe will contact you shortly for delivery.";
                $message     = $this->parse_order_tags( $default_tpl, $order );
                GOWA_API::queue_message( $customer_phone, $message, $order, 'order_received' );
            }
        }

        // 2. Send Alert to Admin in Background Queue
        if ( ! empty( $settings['enable_wc_admin_order'] ) && ! empty( $settings['admin_phone'] ) ) {
            $admin_tpl = ! empty( $settings['wc_admin_order_msg'] ) ? $settings['wc_admin_order_msg'] : "🛍️ *New Order Received! #{order_id}*\n\nCustomer: {customer_name}\nTotal: {order_total}\nItems: {order_items}\nPhone: {billing_phone}";
            $admin_msg = $this->parse_order_tags( $admin_tpl, $order );
            GOWA_API::queue_message( $settings['admin_phone'], $admin_msg, $order, 'admin_new_order' );
        }
    }

    public function on_order_completed( $order_id, $order = null ) {
        $settings = get_option( 'gowa_whatsapp_settings', array() );
        if ( empty( $settings['enable_wc_cust_complete'] ) ) {
            return;
        }

        if ( ! $order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        $customer_phone = $order->get_billing_phone();
        if ( empty( $customer_phone ) ) {
            return;
        }

        $template = ! empty( $settings['wc_cust_complete_msg'] ) ? $settings['wc_cust_complete_msg'] : "Hello {customer_name},\n\nYour order *#{order_id}* has been completed! 🎉\n\nThank you for shopping with {site_name}.";
        $message  = $this->parse_order_tags( $template, $order );

        GOWA_API::queue_message( $customer_phone, $message, $order, 'order_completed' );
    }

    public function on_order_cancelled( $order_id, $order = null ) {
        $settings = get_option( 'gowa_whatsapp_settings', array() );
        if ( empty( $settings['enable_wc_cust_cancelled'] ) ) {
            return;
        }

        if ( ! $order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        $customer_phone = $order->get_billing_phone();
        if ( empty( $customer_phone ) ) {
            return;
        }

        $template = ! empty( $settings['wc_cust_cancelled_msg'] ) ? $settings['wc_cust_cancelled_msg'] : "Hello {customer_name},\n\nYour order *#{order_id}* at {site_name} has been cancelled.";
        $message  = $this->parse_order_tags( $template, $order );

        GOWA_API::queue_message( $customer_phone, $message, $order, 'order_cancelled' );
    }

    public function on_low_stock( $product ) {
        $settings = get_option( 'gowa_whatsapp_settings', array() );
        if ( empty( $settings['enable_wc_low_stock'] ) || empty( $settings['admin_phone'] ) ) {
            return;
        }

        $template = ! empty( $settings['wc_low_stock_msg'] ) ? $settings['wc_low_stock_msg'] : "⚠️ *Low Stock Alert*\n\nProduct: {product_name} (ID: {product_id})\nRemaining: {stock_quantity}";
        $tags = array(
            '{product_id}'     => $product->get_id(),
            '{product_name}'   => $product->get_name(),
            '{stock_quantity}' => $product->get_stock_quantity(),
            '{site_name}'      => get_bloginfo( 'name' ),
        );

        $message = str_replace( array_keys( $tags ), array_values( $tags ), $template );
        GOWA_API::queue_message( $settings['admin_phone'], $message, null, 'low_stock' );
    }

    public function on_no_stock( $product ) {
        $this->on_low_stock( $product );
    }

    public function register_order_metabox() {
        $screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id( 'shop_order' )
            : 'shop_order';

        add_meta_box(
            'gowa_whatsapp_order_metabox',
            __( 'WhatsApp - Send Message to Client', 'gowa-notifications' ),
            array( $this, 'render_order_metabox' ),
            $screen,
            'side',
            'high'
        );
    }

    public function render_order_metabox( $post_or_order_object ) {
        $order = ( $post_or_order_object instanceof WC_Order ) ? $post_or_order_object : wc_get_order( $post_or_order_object->ID );
        if ( ! $order ) {
            return;
        }

        $phone = $order->get_billing_phone();
        $first_name = $order->get_billing_first_name();
        $order_id = $order->get_id();
        ?>
        <div class="gowa-order-box" style="padding: 5px 0;">
            <p style="margin-top:0;">
                <label for="gowa_client_phone"><strong><?php esc_html_e( 'Client WhatsApp Number:', 'gowa-notifications' ); ?></strong></label>
                <input type="text" id="gowa_client_phone" class="widefat" value="<?php echo esc_attr( $phone ); ?>" placeholder="e.g. 01700000000">
            </p>

            <p>
                <label for="gowa_client_custom_text"><strong><?php esc_html_e( 'Custom Message to Client:', 'gowa-notifications' ); ?></strong></label>
                <textarea id="gowa_client_custom_text" rows="5" class="widefat" placeholder="Type message to text client..."><?php echo esc_textarea( sprintf( "Hello %s, regarding your order #%s:\n\n", $first_name, $order_id ) ); ?></textarea>
            </p>

            <div style="margin-bottom: 10px;">
                <small style="color: #666; display: block; margin-bottom: 5px;"><?php esc_html_e( 'Quick Templates:', 'gowa-notifications' ); ?></small>
                <button type="button" class="button button-small gowa-tpl-btn" data-tpl="<?php echo esc_attr( sprintf( "Hello %s, your order #%s has been received and is being prepared!", $first_name, $order_id ) ); ?>">Order Update</button>
                <button type="button" class="button button-small gowa-tpl-btn" data-tpl="<?php echo esc_attr( sprintf( "Hello %s, your order #%s is ready for delivery! Tracking details: ", $first_name, $order_id ) ); ?>">Shipping Info</button>
                <button type="button" class="button button-small gowa-tpl-btn" data-tpl="<?php echo esc_attr( sprintf( "Hello %s, quick reminder regarding your order #%s. Total: %s", $first_name, $order_id, html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) ) ) ); ?>">Payment</button>
            </div>

            <p style="margin-bottom:0;">
                <button type="button" id="gowa_btn_send_custom_msg" class="button button-primary widefat" style="text-align:center; height:34px; line-height:32px; background:#25D366; border-color:#1EBE5D;">
                    <span class="dashicons dashicons-whatsapp" style="vertical-align: middle; margin-top:-2px;"></span> <?php esc_html_e( 'Send WhatsApp to Client', 'gowa-notifications' ); ?>
                </button>
            </p>
            <div id="gowa_order_msg_status" style="margin-top: 8px; font-weight: bold; display: none;"></div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.gowa-tpl-btn').on('click', function(e) {
                e.preventDefault();
                $('#gowa_client_custom_text').val($(this).data('tpl'));
            });

            $('#gowa_btn_send_custom_msg').on('click', function(e) {
                e.preventDefault();
                var phone = $('#gowa_client_phone').val().trim();
                var msg   = $('#gowa_client_custom_text').val().trim();
                var btn   = $(this);
                var status = $('#gowa_order_msg_status');

                if (!phone) {
                    alert('Please enter a valid client phone number.');
                    return;
                }
                if (!msg) {
                    alert('Please enter a message to text the client.');
                    return;
                }

                btn.prop('disabled', true).text('Sending...');
                status.hide().removeClass('notice-success notice-error');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'gowa_send_order_custom_msg',
                        order_id: '<?php echo esc_js( $order_id ); ?>',
                        phone: phone,
                        message: msg,
                        nonce: '<?php echo esc_js( wp_create_nonce( 'gowa_order_msg_nonce' ) ); ?>'
                    },
                    success: function(res) {
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-whatsapp" style="vertical-align: middle; margin-top:-2px;"></span> Send WhatsApp to Client');
                        if (res.success) {
                            status.css('color', '#46b450').text('✓ ' + res.data.message).fadeIn();
                        } else {
                            status.css('color', '#dc3232').text('✕ ' + res.data.message).fadeIn();
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-whatsapp" style="vertical-align: middle; margin-top:-2px;"></span> Send WhatsApp to Client');
                        status.css('color', '#dc3232').text('Server communication error.').fadeIn();
                    }
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_send_order_custom_msg() {
        check_ajax_referer( 'gowa_order_msg_nonce', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gowa-notifications' ) ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $phone    = isset( $_POST['phone'] ) ? sanitize_text_field( $_POST['phone'] ) : '';
        $message  = isset( $_POST['message'] ) ? sanitize_textarea_field( $_POST['message'] ) : '';

        if ( empty( $phone ) || empty( $message ) ) {
            wp_send_json_error( array( 'message' => __( 'Phone number or message is empty.', 'gowa-notifications' ) ) );
        }

        $order = wc_get_order( $order_id );
        $result = GOWA_API::send_message( $phone, $message, $order, 'manual_custom_msg' );

        if ( $result['success'] ) {
            wp_send_json_success( array( 'message' => __( 'Message successfully sent to client via WhatsApp!', 'gowa-notifications' ) ) );
        } else {
            wp_send_json_error( array( 'message' => $result['message'] ) );
        }
    }

    /**
     * Parse placeholders for WC Order
     */
    public function parse_order_tags( $template, $order ) {
        $items_summary = array();
        foreach ( $order->get_items() as $item ) {
            $items_summary[] = "• " . $item->get_name() . " (x" . $item->get_quantity() . ")";
        }

        $items_str = implode( "\n", $items_summary );

        $customer_note = $order->get_customer_note();
        if ( empty( $customer_note ) ) {
            $customer_note = 'None';
        }

        $total_formatted = wp_strip_all_tags( html_entity_decode( $order->get_formatted_order_total() ) );

        // Clean up shipping address to clean, comma-separated plain text (strips <br/> tags)
        $raw_address   = $order->get_formatted_shipping_address() ? $order->get_formatted_shipping_address() : $order->get_formatted_billing_address();
        $clean_address = wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), ', ', $raw_address ) );
        $clean_address = trim( preg_replace( '/\s*,\s*/', ', ', $clean_address ), " ,\t\n\r\0\x0B" );

        $tags = array(
            '{site_name}'           => get_bloginfo( 'name' ),
            '{site_url}'            => site_url(),
            '{order_id}'            => $order->get_id(),
            '{order_number}'        => $order->get_order_number(),
            '{order_status}'        => wc_get_order_status_name( $order->get_status() ),
            '{customer_name}'       => $order->get_formatted_billing_full_name(),
            '{customer_first_name}' => $order->get_billing_first_name(),
            '{customer_last_name}'  => $order->get_billing_last_name(),
            '{customer_email}'      => $order->get_billing_email(),
            '{billing_phone}'       => $order->get_billing_phone(),
            '{customer_note}'       => $customer_note,
            '{order_total}'         => $total_formatted,
            '{order_items}'         => $items_str,
            '{shipping_address}'    => $clean_address,
            '{payment_method}'      => $order->get_payment_method_title(),
            '{order_date}'          => $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
        );

        $parsed = str_replace( array_keys( $tags ), array_values( $tags ), $template );
        return apply_filters( 'gowa_parsed_order_message', $parsed, $order );
    }
}

new GOWA_WooCommerce();