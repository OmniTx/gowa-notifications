<?php
/**
 * GOWA Admin Settings & UI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GOWA_Admin {

    const OPTION_NAME = 'gowa_whatsapp_settings';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'save_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_export_settings' ) );
        add_action( 'admin_init', array( $this, 'handle_import_settings' ) );

        // AJAX handlers for instant live testing & messaging
        add_action( 'wp_ajax_gowa_ajax_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_gowa_ajax_direct_send', array( $this, 'ajax_direct_send' ) );
    }

    public function add_menu_page() {
        add_options_page(
            __( 'GOWA WhatsApp Settings', 'gowa-whatsapp' ),
            __( 'GOWA WhatsApp', 'gowa-whatsapp' ),
            'manage_options',
            'gowa-whatsapp',
            array( $this, 'render_settings_page' )
        );
    }

    public function save_settings() {
        if ( ! isset( $_POST['gowa_save_settings_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['gowa_save_settings_nonce'] ), 'gowa_save_settings' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $input = isset( $_POST['gowa_settings'] ) ? (array) $_POST['gowa_settings'] : array();
        $sanitized = array();

        // API settings
        $sanitized['api_url']              = esc_url_raw( trim( $input['api_url'] ?? 'http://localhost:3000' ) );
        $sanitized['device_id']            = sanitize_text_field( trim( $input['device_id'] ?? '' ) );
        $sanitized['auth_user']            = sanitize_text_field( trim( $input['auth_user'] ?? '' ) );
        $sanitized['auth_pass']            = sanitize_text_field( trim( $input['auth_pass'] ?? '' ) );
        $sanitized['admin_phone']          = sanitize_text_field( trim( $input['admin_phone'] ?? '' ) );
        $sanitized['default_country_code'] = preg_replace( '/[^0-9]/', '', trim( $input['default_country_code'] ?? '880' ) );

        // WP notifications
        $sanitized['enable_wp_user_reg'] = ! empty( $input['enable_wp_user_reg'] ) ? 1 : 0;
        $sanitized['wp_user_reg_msg']    = sanitize_textarea_field( $input['wp_user_reg_msg'] ?? '' );
        $sanitized['enable_wp_comment']  = ! empty( $input['enable_wp_comment'] ) ? 1 : 0;
        $sanitized['wp_comment_msg']     = sanitize_textarea_field( $input['wp_comment_msg'] ?? '' );

        // WooCommerce notifications
        $sanitized['enable_wc_admin_order']   = ! empty( $input['enable_wc_admin_order'] ) ? 1 : 0;
        $sanitized['wc_admin_order_msg']      = sanitize_textarea_field( $input['wc_admin_order_msg'] ?? '' );
        $sanitized['enable_wc_cust_process']  = ! empty( $input['enable_wc_cust_process'] ) ? 1 : 0;
        $sanitized['wc_cust_process_msg']     = sanitize_textarea_field( $input['wc_cust_process_msg'] ?? '' );
        $sanitized['enable_wc_cust_complete'] = ! empty( $input['enable_wc_cust_complete'] ) ? 1 : 0;
        $sanitized['wc_cust_complete_msg']    = sanitize_textarea_field( $input['wc_cust_complete_msg'] ?? '' );
        $sanitized['enable_wc_cust_cancelled']= ! empty( $input['enable_wc_cust_cancelled'] ) ? 1 : 0;
        $sanitized['wc_cust_cancelled_msg']   = sanitize_textarea_field( $input['wc_cust_cancelled_msg'] ?? '' );
        $sanitized['enable_wc_low_stock']     = ! empty( $input['enable_wc_low_stock'] ) ? 1 : 0;
        $sanitized['wc_low_stock_msg']        = sanitize_textarea_field( $input['wc_low_stock_msg'] ?? '' );

        update_option( self::OPTION_NAME, $sanitized );

        add_settings_error( 'gowa_messages', 'gowa_settings_saved', __( 'Settings saved successfully.', 'gowa-whatsapp' ), 'updated' );
    }

    /**
     * Export Settings as JSON file download
     */
    public function handle_export_settings() {
        if ( ! isset( $_POST['gowa_export_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['gowa_export_nonce'] ), 'gowa_export_action' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings       = get_option( self::OPTION_NAME, array() );
        $plugin_version = defined( 'GOWA_VERSION' ) ? GOWA_VERSION : '1.3.3';

        $export_payload = array(
            'plugin'      => 'gowa-whatsapp-notifications',
            'version'     => $plugin_version,
            'exported_at' => current_time( 'mysql' ),
            'site_url'    => site_url(),
            'settings'    => $settings,
        );

        $filename = 'gowa-whatsapp-settings-' . date( 'Y-m-d_H-i' ) . '.json';

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Expires: 0' );

        echo wp_json_encode( $export_payload, JSON_PRETTY_PRINT );
        exit;
    }

    /**
     * Import Settings from uploaded JSON file
     */
    public function handle_import_settings() {
        if ( ! isset( $_POST['gowa_import_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['gowa_import_nonce'] ), 'gowa_import_action' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( empty( $_FILES['gowa_import_file']['tmp_name'] ) ) {
            add_settings_error( 'gowa_messages', 'gowa_import_err', __( 'Please choose a valid JSON file to import.', 'gowa-whatsapp' ), 'error' );
            return;
        }

        $json_content = file_get_contents( $_FILES['gowa_import_file']['tmp_name'] );
        $data         = json_decode( $json_content, true );

        if ( ! is_array( $data ) ) {
            add_settings_error( 'gowa_messages', 'gowa_import_err', __( 'Invalid JSON file format.', 'gowa-whatsapp' ), 'error' );
            return;
        }

        $settings = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : $data;

        if ( ! empty( $settings ) && is_array( $settings ) ) {
            $allowed_keys = array(
                'api_url', 'device_id', 'auth_user', 'auth_pass', 'default_country_code', 'admin_phone',
                'enable_wp_user_reg', 'wp_user_reg_msg', 'enable_wp_comment', 'wp_comment_msg',
                'enable_wc_admin_order', 'wc_admin_order_msg', 'enable_wc_cust_process', 'wc_cust_process_msg',
                'enable_wc_cust_complete', 'wc_cust_complete_msg', 'enable_wc_cust_cancelled', 'wc_cust_cancelled_msg',
                'enable_wc_low_stock', 'wc_low_stock_msg'
            );

            $sanitized = array();
            foreach ( $allowed_keys as $key ) {
                if ( isset( $settings[ $key ] ) ) {
                    if ( in_array( $key, array( 'enable_wp_user_reg', 'enable_wp_comment', 'enable_wc_admin_order', 'enable_wc_cust_process', 'enable_wc_cust_complete', 'enable_wc_cust_cancelled', 'enable_wc_low_stock' ) ) ) {
                        $sanitized[ $key ] = ! empty( $settings[ $key ] ) ? 1 : 0;
                    } elseif ( $key === 'api_url' ) {
                        $sanitized[ $key ] = esc_url_raw( trim( $settings[ $key ] ) );
                    } elseif ( in_array( $key, array( 'device_id', 'auth_user', 'auth_pass', 'admin_phone' ) ) ) {
                        $sanitized[ $key ] = sanitize_text_field( trim( $settings[ $key ] ) );
                    } elseif ( $key === 'default_country_code' ) {
                        $sanitized[ $key ] = preg_replace( '/[^0-9]/', '', trim( $settings[ $key ] ) );
                    } else {
                        $sanitized[ $key ] = sanitize_textarea_field( $settings[ $key ] );
                    }
                }
            }

            if ( ! empty( $sanitized ) ) {
                update_option( self::OPTION_NAME, $sanitized );
                add_settings_error( 'gowa_messages', 'gowa_import_success', __( 'Settings imported successfully!', 'gowa-whatsapp' ), 'updated' );
            } else {
                add_settings_error( 'gowa_messages', 'gowa_import_err', __( 'No recognized settings found in the file.', 'gowa-whatsapp' ), 'error' );
            }
        } else {
            add_settings_error( 'gowa_messages', 'gowa_import_err', __( 'No settings found in the JSON file.', 'gowa-whatsapp' ), 'error' );
        }
    }

    /**
     * AJAX Test Connection
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'gowa_admin_ajax_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gowa-whatsapp' ) ) );
        }

        $result = GOWA_API::check_connection();
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * AJAX Direct Message Send
     */
    public function ajax_direct_send() {
        check_ajax_referer( 'gowa_admin_ajax_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gowa-whatsapp' ) ) );
        }

        $phone   = isset( $_POST['phone'] ) ? sanitize_text_field( $_POST['phone'] ) : '';
        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( $_POST['message'] ) : '';

        if ( empty( $phone ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a recipient phone number.', 'gowa-whatsapp' ) ) );
        }

        if ( empty( $message ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a message to send.', 'gowa-whatsapp' ) ) );
        }

        $result = GOWA_API::send_message( $phone, $message );
        if ( $result['success'] ) {
            wp_send_json_success( array(
                'message' => sprintf( __( 'WhatsApp message successfully delivered to %s!', 'gowa-whatsapp' ), esc_html( $phone ) ),
                'data'    => $result,
            ) );
        } else {
            wp_send_json_error( array(
                'message' => $result['message'],
                'debug'   => $result,
            ) );
        }
    }

    /**
     * Get default plugin settings
     *
     * @return array
     */
    public static function get_defaults() {
        return GOWA_WhatsApp_Plugin::get_defaults();
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $defaults = self::get_defaults();
        $saved    = get_option( self::OPTION_NAME, array() );
        $settings = wp_parse_args( $saved, $defaults );

        // If message templates are empty in saved settings, fallback to defaults so textareas are pre-populated
        foreach ( array( 'wp_user_reg_msg', 'wp_comment_msg', 'wc_admin_order_msg', 'wc_cust_process_msg', 'wc_cust_complete_msg', 'wc_cust_cancelled_msg', 'wc_low_stock_msg' ) as $tpl_key ) {
            if ( empty( $settings[ $tpl_key ] ) && ! empty( $defaults[ $tpl_key ] ) ) {
                $settings[ $tpl_key ] = $defaults[ $tpl_key ];
            }
        }
        $config       = GOWA_API::get_config();
        $active_tab   = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'api';
        $is_wc_active = class_exists( 'WooCommerce' );
        $ajax_nonce   = wp_create_nonce( 'gowa_admin_ajax_nonce' );
        ?>
        <div class="wrap gowa-admin-wrap" style="max-width: 1000px;">
            <h1><span class="dashicons dashicons-whatsapp" style="font-size: 32px; width: 32px; height: 32px; color: #25D366; vertical-align: middle;"></span> <?php esc_html_e( 'GOWA WhatsApp Notifications', 'gowa-whatsapp' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Connect your self-hosted GOWA (Go WhatsApp Web Multi-Device) server for automated WordPress and WooCommerce alerts.', 'gowa-whatsapp' ); ?></p>

            <?php settings_errors( 'gowa_messages' ); ?>

            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="?page=gowa-whatsapp&tab=api" class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'API & Gateway', 'gowa-whatsapp' ); ?></a>
                <a href="?page=gowa-whatsapp&tab=wc" class="nav-tab <?php echo $active_tab === 'wc' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Client & Order Messages', 'gowa-whatsapp' ); ?></a>
                <a href="?page=gowa-whatsapp&tab=wp" class="nav-tab <?php echo $active_tab === 'wp' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'WordPress Core Alerts', 'gowa-whatsapp' ); ?></a>
                <a href="?page=gowa-whatsapp&tab=test" class="nav-tab <?php echo $active_tab === 'test' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Direct Client Message / Test', 'gowa-whatsapp' ); ?></a>
                <a href="?page=gowa-whatsapp&tab=tools" class="nav-tab <?php echo $active_tab === 'tools' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Export / Import', 'gowa-whatsapp' ); ?></a>
            </h2>

            <?php if ( $active_tab === 'api' ) : ?>
                <form method="post" action="">
                    <?php wp_nonce_field( 'gowa_save_settings', 'gowa_save_settings_nonce' ); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="api_url"><?php esc_html_e( 'GOWA Server URL', 'gowa-whatsapp' ); ?></label></th>
                            <td>
                                <input name="gowa_settings[api_url]" type="url" id="api_url" value="<?php echo esc_attr( $config['api_url'] ); ?>" class="regular-text" required placeholder="http://localhost:3000 or https://wa.yourdomain.com">
                                <p class="description"><?php esc_html_e( 'URL of your running GOWA REST API instance (e.g. http://localhost:3000 or https://wa.yourdomain.com).', 'gowa-whatsapp' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="device_id"><?php esc_html_e( 'Device ID (Optional)', 'gowa-whatsapp' ); ?></label></th>
                            <td>
                                <input name="gowa_settings[device_id]" type="text" id="device_id" value="<?php echo esc_attr( $config['device_id'] ); ?>" class="regular-text" placeholder="e.g. default or your-device-uuid">
                                <p class="description"><?php esc_html_e( 'Optional. Leave blank if using a single default device, or enter your device ID for GOWA v8+ multi-device.', 'gowa-whatsapp' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="auth_user"><?php esc_html_e( 'Basic Auth Username', 'gowa-whatsapp' ); ?></label></th>
                            <td>
                                <input name="gowa_settings[auth_user]" type="text" id="auth_user" value="<?php echo esc_attr( $config['auth_user'] ); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e( 'Optional. Username if GOWA was launched with basic authentication (-b=username:password).', 'gowa-whatsapp' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="auth_pass"><?php esc_html_e( 'Basic Auth Password', 'gowa-whatsapp' ); ?></label></th>
                            <td>
                                <input name="gowa_settings[auth_pass]" type="password" id="auth_pass" value="<?php echo esc_attr( $config['auth_pass'] ); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="default_country_code"><?php esc_html_e( 'Default Country Code', 'gowa-whatsapp' ); ?></label></th>
                            <td>
                                <input name="gowa_settings[default_country_code]" type="text" id="default_country_code" value="<?php echo esc_attr( $config['default_country_code'] ?? '880' ); ?>" class="small-text" placeholder="880">
                                <p class="description"><?php esc_html_e( 'Country calling code without + (e.g. 880 for BD, 1 for US/CA, 91 for India, 44 for UK, 62 for Indonesia). Used to automatically prefix numbers entered with a leading 0 (e.g. 0184... becomes 880184...). Full international numbers with country code are used as-is.', 'gowa-whatsapp' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admin_phone"><?php esc_html_e( 'Store Admin WhatsApp Number', 'gowa-whatsapp' ); ?></label></th>
                            <td>
                                <input name="gowa_settings[admin_phone]" type="text" id="admin_phone" value="<?php echo esc_attr( $config['admin_phone'] ); ?>" class="regular-text" placeholder="e.g. 8801700000000 or 14155552671">
                                <p class="description"><?php esc_html_e( 'Admin phone number for store notifications.', 'gowa-whatsapp' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Save API Settings', 'gowa-whatsapp' ) ); ?>
                </form>

            <?php elseif ( $active_tab === 'wc' ) : ?>
                <?php if ( ! $is_wc_active ) : ?>
                    <div class="notice notice-warning inline"><p><?php esc_html_e( 'WooCommerce is not active. Install and activate WooCommerce to use client order notifications.', 'gowa-whatsapp' ); ?></p></div>
                <?php else : ?>
                    <form method="post" action="">
                        <?php wp_nonce_field( 'gowa_save_settings', 'gowa_save_settings_nonce' ); ?>
                        <!-- Preserve API Settings -->
                        <input type="hidden" name="gowa_settings[api_url]" value="<?php echo esc_attr( $settings['api_url'] ?? '' ); ?>">
                        <input type="hidden" name="gowa_settings[device_id]" value="<?php echo esc_attr( $settings['device_id'] ?? '' ); ?>">
                        <input type="hidden" name="gowa_settings[auth_user]" value="<?php echo esc_attr( $settings['auth_user'] ?? '' ); ?>">
                        <input type="hidden" name="gowa_settings[auth_pass]" value="<?php echo esc_attr( $settings['auth_pass'] ?? '' ); ?>">
                        <input type="hidden" name="gowa_settings[default_country_code]" value="<?php echo esc_attr( $settings['default_country_code'] ?? '880' ); ?>">
                        <input type="hidden" name="gowa_settings[admin_phone]" value="<?php echo esc_attr( $settings['admin_phone'] ?? '' ); ?>">
                        <!-- Preserve WP Settings -->
                        <input type="hidden" name="gowa_settings[enable_wp_user_reg]" value="<?php echo esc_attr( $settings['enable_wp_user_reg'] ?? 0 ); ?>">
                        <input type="hidden" name="gowa_settings[wp_user_reg_msg]" value="<?php echo esc_attr( $settings['wp_user_reg_msg'] ?? '' ); ?>">
                        <input type="hidden" name="gowa_settings[enable_wp_comment]" value="<?php echo esc_attr( $settings['enable_wp_comment'] ?? 0 ); ?>">
                        <input type="hidden" name="gowa_settings[wp_comment_msg]" value="<?php echo esc_attr( $settings['wp_comment_msg'] ?? '' ); ?>">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Client: Order Processing Message', 'gowa-whatsapp' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="gowa_settings[enable_wc_cust_process]" value="1" <?php checked( $settings['enable_wc_cust_process'] ?? 0, 1 ); ?>>
                                        <strong><?php esc_html_e( 'Automatically send WhatsApp message to client when order is Received / Processing', 'gowa-whatsapp' ); ?></strong>
                                    </label>
                                    <br><br>
                                    <textarea name="gowa_settings[wc_cust_process_msg]" rows="6" class="large-text"><?php echo esc_textarea( $settings['wc_cust_process_msg'] ?? '' ); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><?php esc_html_e( 'Client: Order Completed Message', 'gowa-whatsapp' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="gowa_settings[enable_wc_cust_complete]" value="1" <?php checked( $settings['enable_wc_cust_complete'] ?? 0, 1 ); ?>>
                                        <strong><?php esc_html_e( 'Automatically send WhatsApp message to client when order is Completed', 'gowa-whatsapp' ); ?></strong>
                                    </label>
                                    <br><br>
                                    <textarea name="gowa_settings[wc_cust_complete_msg]" rows="5" class="large-text"><?php echo esc_textarea( $settings['wc_cust_complete_msg'] ?? '' ); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><?php esc_html_e( 'Client: Order Cancelled Message', 'gowa-whatsapp' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="gowa_settings[enable_wc_cust_cancelled]" value="1" <?php checked( $settings['enable_wc_cust_cancelled'] ?? 0, 1 ); ?>>
                                        <strong><?php esc_html_e( 'Automatically send WhatsApp message to client when order is Cancelled', 'gowa-whatsapp' ); ?></strong>
                                    </label>
                                    <br><br>
                                    <textarea name="gowa_settings[wc_cust_cancelled_msg]" rows="4" class="large-text"><?php echo esc_textarea( $settings['wc_cust_cancelled_msg'] ?? '' ); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><?php esc_html_e( 'Admin: New Order Alert', 'gowa-whatsapp' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="gowa_settings[enable_wc_admin_order]" value="1" <?php checked( $settings['enable_wc_admin_order'] ?? 0, 1 ); ?>>
                                        <strong><?php esc_html_e( 'Send WhatsApp alert to Admin when a new order is received', 'gowa-whatsapp' ); ?></strong>
                                    </label>
                                    <br><br>
                                    <textarea name="gowa_settings[wc_admin_order_msg]" rows="5" class="large-text"><?php echo esc_textarea( $settings['wc_admin_order_msg'] ?? '' ); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><?php esc_html_e( 'Admin: Low Stock Alert', 'gowa-whatsapp' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="gowa_settings[enable_wc_low_stock]" value="1" <?php checked( $settings['enable_wc_low_stock'] ?? 0, 1 ); ?>>
                                        <strong><?php esc_html_e( 'Send WhatsApp alert to Admin on low inventory', 'gowa-whatsapp' ); ?></strong>
                                    </label>
                                    <br><br>
                                    <textarea name="gowa_settings[wc_low_stock_msg]" rows="4" class="large-text"><?php echo esc_textarea( $settings['wc_low_stock_msg'] ?? '' ); ?></textarea>
                                </td>
                            </tr>
                        </table>

                        <div class="card" style="margin-top: 20px; padding: 15px; background: #fdfdfd; border-left: 4px solid #25D366;">
                            <h3 style="margin-top: 0;"><?php esc_html_e( 'Dynamic Message Placeholders', 'gowa-whatsapp' ); ?></h3>
                            <p><?php esc_html_e( 'Use any of these tags in your message fields to automatically insert dynamic details:', 'gowa-whatsapp' ); ?></p>
                            <p><code>{customer_name}</code>, <code>{customer_first_name}</code>, <code>{order_id}</code>, <code>{order_total}</code>, <code>{order_items}</code>, <code>{customer_note}</code>, <code>{billing_phone}</code>, <code>{shipping_address}</code>, <code>{payment_method}</code>, <code>{order_date}</code>, <code>{site_name}</code>, <code>{site_url}</code></p>
                        </div>

                        <?php submit_button( __( 'Save Client & Order Message Settings', 'gowa-whatsapp' ) ); ?>
                    </form>
                <?php endif; ?>

            <?php elseif ( $active_tab === 'wp' ) : ?>
                <form method="post" action="">
                    <?php wp_nonce_field( 'gowa_save_settings', 'gowa_save_settings_nonce' ); ?>
                    <!-- Preserve API Settings -->
                    <input type="hidden" name="gowa_settings[api_url]" value="<?php echo esc_attr( $settings['api_url'] ?? '' ); ?>">
                    <input type="hidden" name="gowa_settings[device_id]" value="<?php echo esc_attr( $settings['device_id'] ?? '' ); ?>">
                    <input type="hidden" name="gowa_settings[auth_user]" value="<?php echo esc_attr( $settings['auth_user'] ?? '' ); ?>">
                    <input type="hidden" name="gowa_settings[auth_pass]" value="<?php echo esc_attr( $settings['auth_pass'] ?? '' ); ?>">
                    <input type="hidden" name="gowa_settings[default_country_code]" value="<?php echo esc_attr( $settings['default_country_code'] ?? '880' ); ?>">
                    <input type="hidden" name="gowa_settings[admin_phone]" value="<?php echo esc_attr( $settings['admin_phone'] ?? '' ); ?>">
                    <!-- Preserve WC Settings -->
                    <input type="hidden" name="gowa_settings[enable_wc_admin_order]" value="<?php echo esc_attr( $settings['enable_wc_admin_order'] ?? 0 ); ?>">
                    <input type="hidden" name="gowa_settings[wc_admin_order_msg]" value="<?php echo esc_attr( $settings['wc_admin_order_msg'] ?? '' ); ?>">
                    <input type="hidden" name="gowa_settings[enable_wc_cust_process]" value="<?php echo esc_attr( $settings['enable_wc_cust_process'] ?? 0 ); ?>">
                    <input type="hidden" name="gowa_settings[wc_cust_process_msg]" value="<?php echo esc_attr( $settings['wc_cust_process_msg'] ?? '' ); ?>">
                    <input type="hidden" name="gowa_settings[enable_wc_cust_complete]" value="<?php echo esc_attr( $settings['enable_wc_cust_complete'] ?? 0 ); ?>">
                    <input type="hidden" name="gowa_settings[wc_cust_complete_msg]" value="<?php echo esc_attr( $settings['wc_cust_complete_msg'] ?? '' ); ?>">
                    <input type="hidden" name="gowa_settings[enable_wc_cust_cancelled]" value="<?php echo esc_attr( $settings['enable_wc_cust_cancelled'] ?? 0 ); ?>">
                    <input type="hidden" name="gowa_settings[wc_cust_cancelled_msg]" value="<?php echo esc_attr( $settings['wc_cust_cancelled_msg'] ?? '' ); ?>">
                    <input type="hidden" name="gowa_settings[enable_wc_low_stock]" value="<?php echo esc_attr( $settings['enable_wc_low_stock'] ?? 0 ); ?>">
                    <input type="hidden" name="gowa_settings[wc_low_stock_msg]" value="<?php echo esc_attr( $settings['wc_low_stock_msg'] ?? '' ); ?>">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'New User Registration', 'gowa-whatsapp' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="gowa_settings[enable_wp_user_reg]" value="1" <?php checked( $settings['enable_wp_user_reg'] ?? 0, 1 ); ?>>
                                    <?php esc_html_e( 'Send WhatsApp alert to Admin when a new user registers', 'gowa-whatsapp' ); ?>
                                </label>
                                <br><br>
                                <textarea name="gowa_settings[wp_user_reg_msg]" rows="5" class="large-text"><?php echo esc_textarea( $settings['wp_user_reg_msg'] ?? '' ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Tags: {site_name}, {site_url}, {username}, {email}, {user_id}, {date}', 'gowa-whatsapp' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'New Comment Posted', 'gowa-whatsapp' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="gowa_settings[enable_wp_comment]" value="1" <?php checked( $settings['enable_wp_comment'] ?? 0, 1 ); ?>>
                                    <?php esc_html_e( 'Send WhatsApp alert to Admin on new comments', 'gowa-whatsapp' ); ?>
                                </label>
                                <br><br>
                                <textarea name="gowa_settings[wp_comment_msg]" rows="5" class="large-text"><?php echo esc_textarea( $settings['wp_comment_msg'] ?? '' ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Tags: {site_name}, {author}, {author_email}, {post_title}, {comment_content}, {comment_url}, {date}', 'gowa-whatsapp' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Save WordPress Settings', 'gowa-whatsapp' ) ); ?>
                </form>

            <?php elseif ( $active_tab === 'test' ) : ?>
                <!-- Direct Client WhatsApp Messenger -->
                <div class="card" style="margin-top: 20px; padding: 20px; background: #fff;">
                    <h2><?php esc_html_e( 'Direct Client Message / Test WhatsApp', 'gowa-whatsapp' ); ?></h2>
                    <p><?php esc_html_e( 'Type any message in English below to send an instant test or message any client directly from WordPress:', 'gowa-whatsapp' ); ?></p>
                    
                    <div id="gowa_direct_send_box">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="gowa_direct_phone"><?php esc_html_e( 'Client Phone Number', 'gowa-whatsapp' ); ?></label></th>
                                <td>
                                    <input type="text" id="gowa_direct_phone" value="<?php echo esc_attr( $config['admin_phone'] ); ?>" class="regular-text" placeholder="e.g. 01700000000 or +8801700000000">
                                    <p class="description"><?php esc_html_e( 'Enter the phone number (with or without +). Local numbers starting with 0 will automatically use the configured Default Country Code.', 'gowa-whatsapp' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="gowa_direct_message"><?php esc_html_e( 'Message to Text Client', 'gowa-whatsapp' ); ?></label></th>
                                <td>
                                    <textarea id="gowa_direct_message" rows="5" class="large-text" placeholder="Type what you want to text the client...">Hello, thank you for contacting us! How can we help you today?</textarea>
                                </td>
                            </tr>
                        </table>
                        <p>
                            <button type="button" id="gowa_btn_direct_send" class="button button-primary button-large" style="background:#25D366; border-color:#1EBE5D; color:#fff;">
                                <span class="dashicons dashicons-whatsapp" style="vertical-align: middle; margin-top:-2px;"></span> <?php esc_html_e( 'Send Message via WhatsApp', 'gowa-whatsapp' ); ?>
                            </button>
                        </p>
                        <div id="gowa_direct_send_status" style="margin-top: 15px; display: none;"></div>
                    </div>
                </div>

                <!-- Live Gateway Connection Diagnostics -->
                <div class="card" style="margin-top: 20px; padding: 20px; background: #fff;">
                    <h2><?php esc_html_e( 'Test Gateway Connection', 'gowa-whatsapp' ); ?></h2>
                    <p><?php esc_html_e( 'Check if your WordPress server can communicate with your configured GOWA REST API gateway.', 'gowa-whatsapp' ); ?></p>
                    <p>
                        <button type="button" id="gowa_btn_check_connection" class="button button-secondary button-large">
                            <span class="dashicons dashicons-rest-api" style="vertical-align: middle; margin-top:-2px;"></span> <?php esc_html_e( 'Check GOWA Connection', 'gowa-whatsapp' ); ?>
                        </button>
                    </p>
                    <div id="gowa_conn_status_box" style="margin-top: 15px; display: none;"></div>
                </div>

                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    var ajaxNonce = '<?php echo esc_js( $ajax_nonce ); ?>';

                    // 1. Direct Message Handler
                    $('#gowa_btn_direct_send').on('click', function(e) {
                        e.preventDefault();
                        var btn = $(this);
                        var statusBox = $('#gowa_direct_send_status');
                        var phone = $('#gowa_direct_phone').val().trim();
                        var message = $('#gowa_direct_message').val().trim();

                        if (!phone) {
                            alert('Please enter a valid client phone number.');
                            return;
                        }
                        if (!message) {
                            alert('Please enter a message to text the client.');
                            return;
                        }

                        btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0; vertical-align:middle;"></span> Sending...');
                        statusBox.hide().html('');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'gowa_ajax_direct_send',
                                phone: phone,
                                message: message,
                                nonce: ajaxNonce
                            },
                            success: function(res) {
                                btn.prop('disabled', false).html('<span class="dashicons dashicons-whatsapp" style="vertical-align: middle; margin-top:-2px;"></span> Send Message via WhatsApp');
                                if (res.success) {
                                    statusBox.html('<div class="notice notice-success inline" style="padding:10px 15px; margin:0;"><p style="font-weight:bold; color:#155724;">✓ ' + res.data.message + '</p></div>').fadeIn();
                                } else {
                                    var errMsg = res.data ? res.data.message : 'Unknown error';
                                    statusBox.html('<div class="notice notice-error inline" style="padding:10px 15px; margin:0;"><p style="font-weight:bold; color:#721c24;">✕ Send Failed: ' + errMsg + '</p></div>').fadeIn();
                                }
                            },
                            error: function(xhr, status, error) {
                                btn.prop('disabled', false).html('<span class="dashicons dashicons-whatsapp" style="vertical-align: middle; margin-top:-2px;"></span> Send Message via WhatsApp');
                                statusBox.html('<div class="notice notice-error inline" style="padding:10px 15px; margin:0;"><p style="font-weight:bold; color:#721c24;">✕ Server AJAX Error: ' + error + '</p></div>').fadeIn();
                            }
                        });
                    });

                    // 2. Gateway Connection Check Handler
                    $('#gowa_btn_check_connection').on('click', function(e) {
                        e.preventDefault();
                        var btn = $(this);
                        var statusBox = $('#gowa_conn_status_box');

                        btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0; vertical-align:middle;"></span> Checking GOWA Gateway...');
                        statusBox.hide().html('');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'gowa_ajax_test_connection',
                                nonce: ajaxNonce
                            },
                            success: function(res) {
                                btn.prop('disabled', false).html('<span class="dashicons dashicons-rest-api" style="vertical-align: middle; margin-top:-2px;"></span> Check GOWA Connection');
                                if (res.success) {
                                    var html = '<div class="notice notice-success inline" style="padding:15px; border-left:4px solid #46b450; background:#f4fbf4;">';
                                    html += '<h3 style="margin:0 0 8px 0; color:#155724;">✓ ' + res.data.message + '</h3>';
                                    html += '<p style="margin:0;"><strong>Active URL:</strong> <code>' + res.data.api_url + '</code></p>';
                                    if (res.data.endpoint) {
                                        html += '<p style="margin:4px 0 0 0;"><strong>Active Endpoint:</strong> <code>' + res.data.endpoint + '</code></p>';
                                    }
                                    html += '</div>';
                                    statusBox.html(html).fadeIn();
                                } else {
                                    var errMsg = res.data ? res.data.message : 'Connection failed';
                                    var html = '<div class="notice notice-error inline" style="padding:15px; border-left:4px solid #dc3232; background:#fff5f5;">';
                                    html += '<h3 style="margin:0 0 8px 0; color:#721c24;">✕ Connection Failed</h3>';
                                    html += '<p style="margin:0; font-size:14px;">' + errMsg + '</p>';
                                    html += '<div style="margin-top:10px; padding:10px; background:#fff; border:1px solid #e5e5e5; font-size:13px;">';
                                    html += '<strong>Troubleshooting:</strong>';
                                    html += '<ul style="list-style:disc; margin-left:20px; margin-top:5px; margin-bottom:0;">';
                                    html += '<li>Ensure your GOWA server is running and accessible from this WordPress host.</li>';
                                    html += '<li>If you use Basic Auth, verify your username and password under <strong>API & Gateway</strong>.</li>';
                                    html += '</ul></div></div>';
                                    statusBox.html(html).fadeIn();
                                }
                            },
                            error: function(xhr, status, error) {
                                btn.prop('disabled', false).html('<span class="dashicons dashicons-rest-api" style="vertical-align: middle; margin-top:-2px;"></span> Check GOWA Connection');
                                statusBox.html('<div class="notice notice-error inline" style="padding:15px;"><p style="font-weight:bold; color:#721c24;">✕ AJAX Error: ' + error + '</p></div>').fadeIn();
                            }
                        });
                    });
                });
                </script>
            <?php elseif ( $active_tab === 'tools' ) : ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-top: 20px;">
                    <!-- Export Settings Card -->
                    <div class="card" style="padding: 20px; background: #fff; border-top: 4px solid #25D366; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-download" style="color: #25D366;"></span>
                            <?php esc_html_e( 'Export Settings', 'gowa-whatsapp' ); ?>
                        </h2>
                        <p><?php esc_html_e( 'Download all your current GOWA WhatsApp settings (API configuration, admin numbers, and custom notification templates) as a JSON file backup.', 'gowa-whatsapp' ); ?></p>
                        <form method="post" action="" style="margin-top: 20px;">
                            <?php wp_nonce_field( 'gowa_export_action', 'gowa_export_nonce' ); ?>
                            <button type="submit" class="button button-primary button-large" style="background:#25D366; border-color:#1EBE5D; color:#fff; display: inline-flex; align-items: center; gap: 6px;">
                                <span class="dashicons dashicons-download" style="margin-top: -2px;"></span>
                                <?php esc_html_e( 'Download Settings (.json)', 'gowa-whatsapp' ); ?>
                            </button>
                        </form>
                    </div>

                    <!-- Import Settings Card -->
                    <div class="card" style="padding: 20px; background: #fff; border-top: 4px solid #0073aa; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-upload" style="color: #0073aa;"></span>
                            <?php esc_html_e( 'Import Settings', 'gowa-whatsapp' ); ?>
                        </h2>
                        <p><?php esc_html_e( 'Upload a previously exported GOWA settings JSON file to restore your settings or migrate them to this store.', 'gowa-whatsapp' ); ?></p>
                        <form method="post" action="" enctype="multipart/form-data" style="margin-top: 20px;">
                            <?php wp_nonce_field( 'gowa_import_action', 'gowa_import_nonce' ); ?>
                            <p>
                                <input type="file" name="gowa_import_file" accept=".json" required style="padding: 5px; border: 1px dashed #ccc; width: 100%; box-sizing: border-box; background: #fafafa;">
                            </p>
                            <p style="margin-top: 15px;">
                                <button type="submit" class="button button-secondary button-large" onclick="return confirm('<?php echo esc_js( __( 'Are you sure? Existing settings will be overwritten with the imported settings.', 'gowa-whatsapp' ) ); ?>');" style="display: inline-flex; align-items: center; gap: 6px;">
                                    <span class="dashicons dashicons-upload" style="margin-top: -2px;"></span>
                                    <?php esc_html_e( 'Upload & Restore Settings', 'gowa-whatsapp' ); ?>
                                </button>
                            </p>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

new GOWA_Admin();