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
        add_action( 'wp_ajax_gowa_ajax_check_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_gowa_ajax_direct_send', array( $this, 'ajax_direct_send' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( $hook !== 'settings_page_notify-with-gowa' ) {
            return;
        }

        wp_enqueue_script(
            'nwg-admin-script',
            GOWA_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            GOWA_VERSION,
            true
        );

        wp_localize_script(
            'nwg-admin-script',
            'nwg_ajax',
            array(
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'gowa_admin_ajax_nonce' ),
            )
        );
    }

    public function add_menu_page() {
        add_options_page(
            __( 'Notify with GOWA', 'notify-with-gowa' ),
            __( 'Notify with GOWA', 'notify-with-gowa' ),
            'manage_options',
            'notify-with-gowa',
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

        $existing  = get_option( self::OPTION_NAME, array() );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array fields are sanitized key-by-key below.
        $input     = isset( $_POST['gowa_settings'] ) ? (array) wp_unslash( $_POST['gowa_settings'] ) : array();
        $sanitized = is_array( $existing ) ? $existing : array();
        $section   = isset( $_POST['gowa_tab_section'] ) ? sanitize_key( wp_unslash( $_POST['gowa_tab_section'] ) ) : '';

        // API settings
        if ( isset( $input['api_url'] ) ) {
            $sanitized['api_url'] = esc_url_raw( trim( $input['api_url'] ) );
        }
        if ( isset( $input['device_id'] ) ) {
            $sanitized['device_id'] = sanitize_text_field( trim( $input['device_id'] ) );
        }
        if ( isset( $input['auth_user'] ) ) {
            $sanitized['auth_user'] = sanitize_text_field( trim( $input['auth_user'] ) );
        }
        if ( isset( $input['auth_pass'] ) ) {
            $sanitized['auth_pass'] = sanitize_text_field( trim( $input['auth_pass'] ) );
        }
        if ( isset( $input['admin_phone'] ) ) {
            $sanitized['admin_phone'] = sanitize_text_field( trim( $input['admin_phone'] ) );
        }
        if ( isset( $input['default_country_code'] ) ) {
            $sanitized['default_country_code'] = preg_replace( '/[^0-9]/', '', trim( $input['default_country_code'] ) );
        }

        // WP notifications
        if ( isset( $_POST['gowa_tab_section'] ) && $_POST['gowa_tab_section'] === 'wp' ) {
            $sanitized['enable_wp_user_reg'] = ! empty( $input['enable_wp_user_reg'] ) ? 1 : 0;
            $sanitized['wp_user_reg_msg']    = sanitize_textarea_field( $input['wp_user_reg_msg'] ?? '' );
            $sanitized['enable_wp_comment']  = ! empty( $input['enable_wp_comment'] ) ? 1 : 0;
            $sanitized['wp_comment_msg']     = sanitize_textarea_field( $input['wp_comment_msg'] ?? '' );
        }

        // WooCommerce notifications
        if ( isset( $_POST['gowa_tab_section'] ) && $_POST['gowa_tab_section'] === 'wc' ) {
            $sanitized['async_delay_seconds']      = isset( $input['async_delay_seconds'] ) ? max( 0, (int) $input['async_delay_seconds'] ) : 0;
            $sanitized['enable_wc_admin_order']    = ! empty( $input['enable_wc_admin_order'] ) ? 1 : 0;
            $sanitized['wc_admin_order_msg']       = sanitize_textarea_field( $input['wc_admin_order_msg'] ?? '' );
            $sanitized['enable_wc_cust_process']   = ! empty( $input['enable_wc_cust_process'] ) ? 1 : 0;
            $sanitized['wc_cust_process_msg']      = sanitize_textarea_field( $input['wc_cust_process_msg'] ?? '' );
            $sanitized['enable_wc_cust_complete']  = ! empty( $input['enable_wc_cust_complete'] ) ? 1 : 0;
            $sanitized['wc_cust_complete_msg']     = sanitize_textarea_field( $input['wc_cust_complete_msg'] ?? '' );
            $sanitized['enable_wc_cust_cancelled'] = ! empty( $input['enable_wc_cust_cancelled'] ) ? 1 : 0;
            $sanitized['wc_cust_cancelled_msg']    = sanitize_textarea_field( $input['wc_cust_cancelled_msg'] ?? '' );
            $sanitized['enable_wc_low_stock']      = ! empty( $input['enable_wc_low_stock'] ) ? 1 : 0;
            $sanitized['wc_low_stock_msg']         = sanitize_textarea_field( $input['wc_low_stock_msg'] ?? '' );
        }

        // Uninstall data settings
        if ( isset( $_POST['gowa_tab_section'] ) && $_POST['gowa_tab_section'] === 'uninstall_data' ) {
            $sanitized['erase_data_on_uninstall'] = ! empty( $input['erase_data_on_uninstall'] ) ? 1 : 0;
        }

        update_option( self::OPTION_NAME, $sanitized );

        add_settings_error( 'gowa_messages', 'gowa_settings_saved', __( 'Settings saved successfully.', 'notify-with-gowa' ), 'updated' );
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

        $settings        = get_option( self::OPTION_NAME, array() );
        $plugin_version  = defined( 'GOWA_VERSION' ) ? GOWA_VERSION : 'unknown';
        $export_settings = $settings;

        if ( ! empty( $export_settings['auth_pass'] ) ) {
            $export_settings['auth_pass'] = base64_encode( $export_settings['auth_pass'] );
        }

        $export_payload = array(
            'plugin'      => 'notify-with-gowa',
            'version'     => $plugin_version,
            'exported_at' => current_time( 'mysql' ),
            'site_url'    => site_url(),
            'settings'    => $export_settings,
        );

        $filename = 'notify-with-gowa-settings-' . gmdate( 'Y-m-d_H-i' ) . '.json';

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

        $import_tmp_file = isset( $_FILES['gowa_import_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['gowa_import_file']['tmp_name'] ) ) : '';

        if ( empty( $import_tmp_file ) || ! is_uploaded_file( $import_tmp_file ) ) {
            add_settings_error( 'gowa_messages', 'gowa_import_err', __( 'Please choose a valid JSON file to import.', 'notify-with-gowa' ), 'error' );
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $json_content = file_get_contents( $import_tmp_file );
        $data         = json_decode( $json_content, true );

        if ( ! is_array( $data ) ) {
            add_settings_error( 'gowa_messages', 'gowa_import_err', __( 'Invalid JSON file format.', 'notify-with-gowa' ), 'error' );
            return;
        }

        $settings = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : $data;

        if ( is_array( $settings ) ) {
            $allowed_keys = array(
                'api_url', 'device_id', 'auth_user', 'auth_pass', 'admin_phone', 'default_country_code',
                'enable_wp_user_reg', 'wp_user_reg_msg', 'enable_wp_comment', 'wp_comment_msg',
                'enable_wc_admin_order', 'wc_admin_order_msg', 'enable_wc_cust_process', 'wc_cust_process_msg',
                'enable_wc_cust_complete', 'wc_cust_complete_msg', 'enable_wc_cust_cancelled', 'wc_cust_cancelled_msg',
                'enable_wc_low_stock', 'wc_low_stock_msg', 'async_delay_seconds',
            );

            $sanitized = array();
            foreach ( $allowed_keys as $key ) {
                if ( isset( $settings[ $key ] ) ) {
                    if ( in_array( $key, array( 'enable_wp_user_reg', 'enable_wp_comment', 'enable_wc_admin_order', 'enable_wc_cust_process', 'enable_wc_cust_complete', 'enable_wc_cust_cancelled', 'enable_wc_low_stock' ) ) ) {
                        $sanitized[ $key ] = ! empty( $settings[ $key ] ) ? 1 : 0;
                    } elseif ( $key === 'async_delay_seconds' ) {
                        $sanitized[ $key ] = max( 0, (int) $settings[ $key ] );
                    } elseif ( $key === 'api_url' ) {
                        $sanitized[ $key ] = esc_url_raw( trim( $settings[ $key ] ) );
                    } elseif ( in_array( $key, array( 'device_id', 'auth_user', 'admin_phone' ) ) ) {
                        $sanitized[ $key ] = sanitize_text_field( trim( $settings[ $key ] ) );
                    } elseif ( $key === 'auth_pass' ) {
                        $raw_pass = trim( $settings[ $key ] );
                        if ( ! empty( $raw_pass ) ) {
                            $decoded = base64_decode( $raw_pass, true );
                            if ( false !== $decoded && base64_encode( $decoded ) === $raw_pass ) {
                                $raw_pass = $decoded;
                            }
                        }
                        $sanitized[ $key ] = sanitize_text_field( $raw_pass );
                    } elseif ( $key === 'default_country_code' ) {
                        $sanitized[ $key ] = preg_replace( '/[^0-9]/', '', trim( $settings[ $key ] ) );
                    } else {
                        $sanitized[ $key ] = sanitize_textarea_field( $settings[ $key ] );
                    }
                }
            }

            if ( ! empty( $sanitized ) ) {
                update_option( self::OPTION_NAME, $sanitized );
                add_settings_error( 'gowa_messages', 'gowa_import_success', __( 'Settings imported successfully!', 'notify-with-gowa' ), 'updated' );
            } else {
                add_settings_error( 'gowa_messages', 'gowa_import_err', __( 'No recognized settings found in the file.', 'notify-with-gowa' ), 'error' );
            }
        } else {
            add_settings_error( 'gowa_messages', 'gowa_import_err', __( 'No settings found in the JSON file.', 'notify-with-gowa' ), 'error' );
        }
    }

    /**
     * AJAX Test Connection
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'gowa_admin_ajax_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'notify-with-gowa' ) ) );
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
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'notify-with-gowa' ) ) );
        }

        $phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

        if ( empty( $phone ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a recipient phone number.', 'notify-with-gowa' ) ) );
        }

        if ( empty( $message ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter a message to send.', 'notify-with-gowa' ) ) );
        }

        $result = GOWA_API::send_message( $phone, $message );
        if ( $result['success'] ) {
            wp_send_json_success( array(
                /* translators: %s is the recipient phone number */
                'message' => sprintf( __( 'WhatsApp message successfully delivered to %s!', 'notify-with-gowa' ), esc_html( $phone ) ),
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation only, no state changed.
        $active_tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'api';
        if ( ! in_array( $active_tab, array( 'api', 'wc', 'wp', 'test', 'tools' ), true ) ) {
            $active_tab = 'api';
        }
        $is_wc_active = class_exists( 'WooCommerce' );
        $ajax_nonce   = wp_create_nonce( 'gowa_admin_ajax_nonce' );
        ?>
        <div class="wrap gowa-admin-wrap" style="max-width: 1000px;">
            <h1><span class="dashicons dashicons-whatsapp" style="font-size: 32px; width: 32px; height: 32px; color: #25D366; vertical-align: middle;"></span> <?php esc_html_e( 'Notify with GOWA', 'notify-with-gowa' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Connect your self-hosted GOWA (Go WhatsApp Web Multi-Device) server for automated WordPress and WooCommerce alerts.', 'notify-with-gowa' ); ?></p>

            <?php settings_errors( 'gowa_messages' ); ?>

            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="?page=notify-with-gowa&tab=api" class="nav-tab <?php echo $active_tab === 'api' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'API & Gateway', 'notify-with-gowa' ); ?></a>
                <a href="?page=notify-with-gowa&tab=wc" class="nav-tab <?php echo $active_tab === 'wc' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Client & Order Messages', 'notify-with-gowa' ); ?></a>
                <a href="?page=notify-with-gowa&tab=wp" class="nav-tab <?php echo $active_tab === 'wp' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'WordPress Core Alerts', 'notify-with-gowa' ); ?></a>
                <a href="?page=notify-with-gowa&tab=test" class="nav-tab <?php echo $active_tab === 'test' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Direct Client Message / Test', 'notify-with-gowa' ); ?></a>
                <a href="?page=notify-with-gowa&tab=tools" class="nav-tab <?php echo $active_tab === 'tools' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Export / Import', 'notify-with-gowa' ); ?></a>
            </h2>

            <?php if ( $active_tab === 'api' ) : ?>
                <form method="post" action="">
                    <?php wp_nonce_field( 'gowa_save_settings', 'gowa_save_settings_nonce' ); ?>
                    <input type="hidden" name="gowa_tab_section" value="api">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="api_url"><?php esc_html_e( 'GOWA Server URL', 'notify-with-gowa' ); ?></label></th>
                            <td>
                                <input name="gowa_settings[api_url]" type="url" id="api_url" value="<?php echo esc_attr( $config['api_url'] ); ?>" class="regular-text" required placeholder="http://localhost:3000 or https://wa.yourdomain.com">
                                <p class="description"><?php esc_html_e( 'URL of your running GOWA REST API instance (e.g. http://localhost:3000 or https://wa.yourdomain.com).', 'notify-with-gowa' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="device_id"><?php esc_html_e( 'Device ID (Optional)', 'notify-with-gowa' ); ?></label></th>
                            <td>
                                <input name="gowa_settings[device_id]" type="text" id="device_id" value="<?php echo esc_attr( $config['device_id'] ); ?>" class="regular-text" placeholder="e.g. default or your-device-uuid">
                                <p class="description"><?php esc_html_e( 'Optional. Leave blank if using a single default device, or enter your device ID for GOWA v8+ multi-device.', 'notify-with-gowa' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="auth_user"><?php esc_html_e( 'Basic Auth Username', 'notify-with-gowa' ); ?></label></th>
                            <td>
                                <input name="gowa_settings[auth_user]" type="text" id="auth_user" value="<?php echo esc_attr( $config['auth_user'] ); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e( 'Optional. Username if GOWA was launched with basic authentication (-b=username:password).', 'notify-with-gowa' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="auth_pass"><?php esc_html_e( 'Basic Auth Password', 'notify-with-gowa' ); ?></label></th>
                            <td>
                                <input name="gowa_settings[auth_pass]" type="password" id="auth_pass" value="<?php echo esc_attr( $config['auth_pass'] ); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="default_country_code"><?php esc_html_e( 'Default Country Code', 'notify-with-gowa' ); ?></label></th>
                            <td>
                                <input name="gowa_settings[default_country_code]" type="text" id="default_country_code" value="<?php echo esc_attr( $config['default_country_code'] ?? '880' ); ?>" class="small-text" placeholder="880">
                                <p class="description"><?php esc_html_e( 'Country calling code without + (e.g. 880 for BD, 1 for US/CA, 91 for India, 44 for UK, 62 for Indonesia). Used to automatically prefix numbers entered with a leading 0 (e.g. 0184... becomes 880184...). Full international numbers with country code are used as-is.', 'notify-with-gowa' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="admin_phone"><?php esc_html_e( 'Store Admin WhatsApp Number(s)', 'notify-with-gowa' ); ?></label></th>
                            <td>
                                <input name="gowa_settings[admin_phone]" type="text" id="admin_phone" value="<?php echo esc_attr( $config['admin_phone'] ); ?>" class="regular-text" placeholder="e.g. 01700000000, 01800000000">
                                <p class="description"><?php esc_html_e( 'Admin phone number(s) for store alerts. You can enter multiple numbers separated by commas.', 'notify-with-gowa' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Save API Settings', 'notify-with-gowa' ) ); ?>
                </form>

            <?php elseif ( $active_tab === 'wc' ) : ?>
                <?php if ( ! $is_wc_active ) : ?>
                    <div class="notice notice-warning inline"><p><?php esc_html_e( 'WooCommerce is not active. Install and activate WooCommerce to use client order notifications.', 'notify-with-gowa' ); ?></p></div>
                <?php else : ?>
                    <form method="post" action="">
                        <?php wp_nonce_field( 'gowa_save_settings', 'gowa_save_settings_nonce' ); ?>
                        <input type="hidden" name="gowa_tab_section" value="wc">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="async_delay_seconds"><?php esc_html_e( 'Background Delay (Seconds)', 'notify-with-gowa' ); ?></label></th>
                                <td>
                                    <input name="gowa_settings[async_delay_seconds]" type="number" step="1" min="0" id="async_delay_seconds" value="<?php echo esc_attr( $settings['async_delay_seconds'] ?? 0 ); ?>" class="small-text">
                                    <p class="description"><?php esc_html_e( 'Delay in seconds before sending automated notifications. Set to 0 for instant Direct Dispatch (Recommended). If set to > 0, notifications are pushed to the WooCommerce Action Scheduler background queue so checkout speed is completely unaffected.', 'notify-with-gowa' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Client: Order Processing Message', 'notify-with-gowa' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="gowa_settings[enable_wc_cust_process]" value="1" <?php checked( $settings['enable_wc_cust_process'] ?? 0, 1 ); ?>>
                                        <strong><?php esc_html_e( 'Automatically send WhatsApp message to client when order is Received / Processing', 'notify-with-gowa' ); ?></strong>
                                    </label>
                                    <br><br>
                                    <textarea name="gowa_settings[wc_cust_process_msg]" rows="6" class="large-text"><?php echo esc_textarea( $settings['wc_cust_process_msg'] ?? '' ); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><?php esc_html_e( 'Client: Order Completed Message', 'notify-with-gowa' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="gowa_settings[enable_wc_cust_complete]" value="1" <?php checked( $settings['enable_wc_cust_complete'] ?? 0, 1 ); ?>>
                                        <strong><?php esc_html_e( 'Automatically send WhatsApp message to client when order is Completed', 'notify-with-gowa' ); ?></strong>
                                    </label>
                                    <br><br>
                                    <textarea name="gowa_settings[wc_cust_complete_msg]" rows="5" class="large-text"><?php echo esc_textarea( $settings['wc_cust_complete_msg'] ?? '' ); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><?php esc_html_e( 'Client: Order Cancelled Message', 'notify-with-gowa' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="gowa_settings[enable_wc_cust_cancelled]" value="1" <?php checked( $settings['enable_wc_cust_cancelled'] ?? 0, 1 ); ?>>
                                        <strong><?php esc_html_e( 'Automatically send WhatsApp message to client when order is Cancelled', 'notify-with-gowa' ); ?></strong>
                                    </label>
                                    <br><br>
                                    <textarea name="gowa_settings[wc_cust_cancelled_msg]" rows="4" class="large-text"><?php echo esc_textarea( $settings['wc_cust_cancelled_msg'] ?? '' ); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><?php esc_html_e( 'Admin: New Order Alert', 'notify-with-gowa' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="gowa_settings[enable_wc_admin_order]" value="1" <?php checked( $settings['enable_wc_admin_order'] ?? 0, 1 ); ?>>
                                        <strong><?php esc_html_e( 'Send WhatsApp alert to Admin when a new order is received', 'notify-with-gowa' ); ?></strong>
                                    </label>
                                    <br><br>
                                    <textarea name="gowa_settings[wc_admin_order_msg]" rows="5" class="large-text"><?php echo esc_textarea( $settings['wc_admin_order_msg'] ?? '' ); ?></textarea>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row"><?php esc_html_e( 'Admin: Low Stock Alert', 'notify-with-gowa' ); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="gowa_settings[enable_wc_low_stock]" value="1" <?php checked( $settings['enable_wc_low_stock'] ?? 0, 1 ); ?>>
                                        <strong><?php esc_html_e( 'Send WhatsApp alert to Admin on low inventory', 'notify-with-gowa' ); ?></strong>
                                    </label>
                                    <br><br>
                                    <textarea name="gowa_settings[wc_low_stock_msg]" rows="4" class="large-text"><?php echo esc_textarea( $settings['wc_low_stock_msg'] ?? '' ); ?></textarea>
                                </td>
                            </tr>
                        </table>

                        <div class="card" style="margin-top: 20px; padding: 15px; background: #fdfdfd; border-left: 4px solid #25D366;">
                            <h3 style="margin-top: 0;"><?php esc_html_e( 'Dynamic Message Placeholders', 'notify-with-gowa' ); ?></h3>
                            <p><?php esc_html_e( 'Use any of these tags in your message fields to automatically insert dynamic details:', 'notify-with-gowa' ); ?></p>
                            <p><code>{customer_name}</code>, <code>{customer_first_name}</code>, <code>{customer_last_name}</code>, <code>{customer_email}</code>, <code>{order_id}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{order_items}</code>, <code>{items_count}</code>, <code>{customer_note}</code>, <code>{billing_phone}</code>, <code>{shipping_address}</code>, <code>{shipping_method}</code>, <code>{payment_method}</code>, <code>{payment_url}</code>, <code>{order_date}</code>, <code>{site_name}</code>, <code>{site_url}</code></p>
                        </div>

                        <?php submit_button( __( 'Save Client & Order Message Settings', 'notify-with-gowa' ) ); ?>
                    </form>
                <?php endif; ?>

            <?php elseif ( $active_tab === 'wp' ) : ?>
                <form method="post" action="">
                    <?php wp_nonce_field( 'gowa_save_settings', 'gowa_save_settings_nonce' ); ?>
                    <input type="hidden" name="gowa_tab_section" value="wp">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'New User Registration', 'notify-with-gowa' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="gowa_settings[enable_wp_user_reg]" value="1" <?php checked( $settings['enable_wp_user_reg'] ?? 0, 1 ); ?>>
                                    <?php esc_html_e( 'Send WhatsApp alert to Admin when a new user registers', 'notify-with-gowa' ); ?>
                                </label>
                                <br><br>
                                <textarea name="gowa_settings[wp_user_reg_msg]" rows="5" class="large-text"><?php echo esc_textarea( $settings['wp_user_reg_msg'] ?? '' ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Tags: {site_name}, {site_url}, {username}, {email}, {user_id}, {date}', 'notify-with-gowa' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'New Comment Posted', 'notify-with-gowa' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="gowa_settings[enable_wp_comment]" value="1" <?php checked( $settings['enable_wp_comment'] ?? 0, 1 ); ?>>
                                    <?php esc_html_e( 'Send WhatsApp alert to Admin on new comments', 'notify-with-gowa' ); ?>
                                </label>
                                <br><br>
                                <textarea name="gowa_settings[wp_comment_msg]" rows="5" class="large-text"><?php echo esc_textarea( $settings['wp_comment_msg'] ?? '' ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Tags: {site_name}, {author}, {author_email}, {post_title}, {comment_content}, {comment_url}, {date}', 'notify-with-gowa' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button( __( 'Save WordPress Settings', 'notify-with-gowa' ) ); ?>
                </form>

            <?php elseif ( $active_tab === 'test' ) : ?>
                <!-- Direct Client WhatsApp Messenger -->
                <div class="card" style="margin-top: 20px; padding: 20px; background: #fff;">
                    <h2><?php esc_html_e( 'Direct Client Message / Test WhatsApp', 'notify-with-gowa' ); ?></h2>
                    <p><?php esc_html_e( 'Type any message in English below to send an instant test or message any client directly from WordPress:', 'notify-with-gowa' ); ?></p>
                    
                    <div id="gowa_direct_send_box">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="gowa_direct_phone"><?php esc_html_e( 'Client Phone Number', 'notify-with-gowa' ); ?></label></th>
                                <td>
                                    <input type="text" id="gowa_direct_phone" value="<?php echo esc_attr( $config['admin_phone'] ); ?>" class="regular-text" placeholder="e.g. 01700000000 or +8801700000000">
                                    <p class="description"><?php esc_html_e( 'Enter the phone number (with or without +). Local numbers starting with 0 will automatically use the configured Default Country Code.', 'notify-with-gowa' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="gowa_direct_message"><?php esc_html_e( 'Message to Text Client', 'notify-with-gowa' ); ?></label></th>
                                <td>
                                    <textarea id="gowa_direct_message" rows="5" class="large-text" placeholder="Type what you want to text the client...">Hello, thank you for contacting us! How can we help you today?</textarea>
                                </td>
                            </tr>
                        </table>
                        <p>
                            <button type="button" id="gowa_btn_direct_send" class="button button-primary button-large" style="background:#25D366; border-color:#1EBE5D; color:#fff;">
                                <span class="dashicons dashicons-whatsapp" style="vertical-align: middle; margin-top:-2px;"></span> <?php esc_html_e( 'Send Message via WhatsApp', 'notify-with-gowa' ); ?>
                            </button>
                        </p>
                        <div id="gowa_direct_send_status" style="margin-top: 15px; display: none;"></div>
                    </div>
                </div>

                <!-- Live Gateway Connection Diagnostics -->
                <div class="card" style="margin-top: 20px; padding: 20px; background: #fff;">
                    <h2><?php esc_html_e( 'Test Gateway Connection', 'notify-with-gowa' ); ?></h2>
                    <p><?php esc_html_e( 'Check if your WordPress server can communicate with your configured GOWA REST API gateway.', 'notify-with-gowa' ); ?></p>
                    <p>
                        <button type="button" id="gowa_btn_check_connection" class="button button-secondary button-large">
                            <span class="dashicons dashicons-rest-api" style="vertical-align: middle; margin-top:-2px;"></span> <?php esc_html_e( 'Check GOWA Connection', 'notify-with-gowa' ); ?>
                        </button>
                    </p>
                    <div id="gowa_conn_status_box" style="margin-top: 15px; display: none;"></div>
                </div>

            <?php elseif ( $active_tab === 'tools' ) : ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-top: 20px;">
                    <!-- Export Settings Card -->
                    <div class="card" style="padding: 20px; background: #fff; border-top: 4px solid #25D366; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-download" style="color: #25D366;"></span>
                            <?php esc_html_e( 'Export Settings', 'notify-with-gowa' ); ?>
                        </h2>
                        <p><?php esc_html_e( 'Download all your current GOWA settings (API configuration, admin numbers, and custom notification templates) as a JSON file backup.', 'notify-with-gowa' ); ?></p>
                        <form method="post" action="" style="margin-top: 20px;">
                            <?php wp_nonce_field( 'gowa_export_action', 'gowa_export_nonce' ); ?>
                            <button type="submit" class="button button-primary button-large" style="background:#25D366; border-color:#1EBE5D; color:#fff; display: inline-flex; align-items: center; gap: 6px;">
                                <span class="dashicons dashicons-download" style="margin-top: -2px;"></span>
                                <?php esc_html_e( 'Download Settings (.json)', 'notify-with-gowa' ); ?>
                            </button>
                        </form>
                    </div>

                    <!-- Import Settings Card -->
                    <div class="card" style="padding: 20px; background: #fff; border-top: 4px solid #0073aa; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-upload" style="color: #0073aa;"></span>
                            <?php esc_html_e( 'Import Settings', 'notify-with-gowa' ); ?>
                        </h2>
                        <p><?php esc_html_e( 'Upload a previously exported GOWA settings JSON file to restore your settings or migrate them to this store.', 'notify-with-gowa' ); ?></p>
                        <form method="post" action="" enctype="multipart/form-data" style="margin-top: 20px;">
                            <?php wp_nonce_field( 'gowa_import_action', 'gowa_import_nonce' ); ?>
                            <p>
                                <input type="file" name="gowa_import_file" accept=".json" required style="padding: 5px; border: 1px dashed #ccc; width: 100%; box-sizing: border-box; background: #fafafa;">
                            </p>
                            <p style="margin-top: 15px;">
                                <button type="submit" class="button button-secondary button-large" onclick="return confirm('<?php echo esc_js( __( 'Are you sure? Existing settings will be overwritten with the imported settings.', 'notify-with-gowa' ) ); ?>');" style="display: inline-flex; align-items: center; gap: 6px;">
                                    <span class="dashicons dashicons-upload" style="margin-top: -2px;"></span>
                                    <?php esc_html_e( 'Upload & Restore Settings', 'notify-with-gowa' ); ?>
                                </button>
                            </p>
                        </form>
                    </div>

                    <!-- Uninstall Data Removal Card -->
                    <div class="card" style="padding: 20px; background: #fff; border-top: 4px solid #dc3232; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-trash" style="color: #dc3232;"></span>
                            <?php esc_html_e( 'Uninstall Data Removal', 'notify-with-gowa' ); ?>
                        </h2>
                        <p><?php esc_html_e( 'By default, your settings and templates are safely preserved if this plugin is deleted. Check this box if you wish to permanently wipe all GOWA data from your database upon uninstallation.', 'notify-with-gowa' ); ?></p>
                        <form method="post" action="" style="margin-top: 20px;">
                            <?php wp_nonce_field( 'gowa_save_settings', 'gowa_save_settings_nonce' ); ?>
                            <input type="hidden" name="gowa_tab_section" value="uninstall_data">
                            <p style="margin-bottom: 20px;">
                                <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" name="gowa_settings[erase_data_on_uninstall]" value="1" <?php checked( $saved['erase_data_on_uninstall'] ?? 0, 1 ); ?> style="margin-top: 2px;">
                                    <span><strong><?php esc_html_e( 'Erase all GOWA data on plugin deletion', 'notify-with-gowa' ); ?></strong><br><span class="description"><?php esc_html_e( 'Permanently wipe settings from the database during uninstallation.', 'notify-with-gowa' ); ?></span></span>
                                </label>
                            </p>
                            <button type="submit" class="button button-secondary" style="display: inline-flex; align-items: center; gap: 6px;">
                                <span class="dashicons dashicons-saved" style="margin-top: -2px;"></span>
                                <?php esc_html_e( 'Save Uninstall Preference', 'notify-with-gowa' ); ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

new GOWA_Admin();
