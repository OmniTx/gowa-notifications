<?php
/**
 * GOWA API Client & Universal Diagnostics
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GOWA_API {

    /**
     * Get API configuration from WordPress options
     *
     * @return array
     */
    public static function get_config() {
        $settings = get_option( 'gowa_whatsapp_settings', array() );

        $api_url    = ! empty( $settings['api_url'] ) ? $settings['api_url'] : 'http://localhost:3000';
        $device_id  = ! empty( $settings['device_id'] ) ? $settings['device_id'] : '';
        $auth_user  = ! empty( $settings['auth_user'] ) ? $settings['auth_user'] : '';
        $auth_pass  = ! empty( $settings['auth_pass'] ) ? $settings['auth_pass'] : '';
        $admin_tel  = ! empty( $settings['admin_phone'] ) ? $settings['admin_phone'] : '';
        $country_cc = ! empty( $settings['default_country_code'] ) ? $settings['default_country_code'] : '880';

        return array(
            'api_url'              => rtrim( trim( $api_url ), '/' ),
            'device_id'            => trim( $device_id ),
            'auth_user'            => trim( $auth_user ),
            'auth_pass'            => trim( $auth_pass ),
            'admin_phone'          => trim( $admin_tel ),
            'default_country_code' => preg_replace( '/[^0-9]/', '', $country_cc ),
        );
    }

    /**
     * Send a WhatsApp message via GOWA API
     *
     * @param string $raw_phone Recipient phone number
     * @param string $message Message text
     * @param WC_Order|null $order Optional order object for logging
     * @param string $event_label Event context (e.g. order_received, order_completed)
     * @return array
     */
    public static function send_message( $raw_phone, $message, $order = null, $event_label = 'notification' ) {
        $config = self::get_config();

        // Native WooCommerce Logger
        $logger      = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
        $log_context = array( 'source' => 'gowa_whatsapp_api' );

        $phone = self::format_phone( $raw_phone );
        if ( empty( $phone ) ) {
            $err = sprintf( __( 'Invalid recipient phone number: %s', 'gowa-whatsapp' ), esc_html( $raw_phone ) );
            if ( $order && method_exists( $order, 'add_order_note' ) ) {
                $order->add_order_note( "❌ WhatsApp Error ({$event_label}): " . $err );
            }
            if ( $logger ) {
                $logger->error( "GOWA Send Error [{$event_label}]: {$err}", $log_context );
            }
            return array( 'success' => false, 'message' => $err );
        }

        if ( empty( $config['api_url'] ) ) {
            $err = __( 'GOWA API URL is not configured. Please configure your settings in Settings > GOWA WhatsApp.', 'gowa-whatsapp' );
            if ( $order && method_exists( $order, 'add_order_note' ) ) {
                $order->add_order_note( "❌ WhatsApp Error ({$event_label}): " . $err );
            }
            return array( 'success' => false, 'message' => $err );
        }

        $endpoint = $config['api_url'];
        if ( substr( $endpoint, -13 ) !== '/send/message' ) {
            $endpoint .= '/send/message';
        }

        $headers = array(
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        );

        if ( ! empty( $config['auth_user'] ) && ! empty( $config['auth_pass'] ) ) {
            $headers['Authorization'] = 'Basic ' . base64_encode( "{$config['auth_user']}:{$config['auth_pass']}" );
        }

        if ( ! empty( $config['device_id'] ) ) {
            $headers['X-Device-Id'] = $config['device_id'];
            $endpoint = add_query_arg( 'device_id', rawurlencode( $config['device_id'] ), $endpoint );
        }

        $payload = array(
            'phone'   => $phone,
            'message' => $message,
        );

        $response = wp_remote_post( $endpoint, array(
            'method'      => 'POST',
            'timeout'     => 20,
            'httpversion' => '1.0',
            'blocking'    => true,
            'headers'     => $headers,
            'body'        => wp_json_encode( $payload ),
            'sslverify'   => false,
        ) );

        $order_id = ( $order && method_exists( $order, 'get_id' ) ) ? $order->get_id() : 0;

        // Connection Error Handling
        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            if ( $order && method_exists( $order, 'add_order_note' ) ) {
                $order->add_order_note( "❌ WhatsApp Error ({$event_label}): Connection failed - " . $error_msg );
            }
            if ( $logger ) {
                $logger->error( "Order #{$order_id} [{$event_label}] - Connection failed: " . $error_msg, $log_context );
            }
            self::log_error( 'GOWA API Connection Failed: ' . $error_msg );
            return array(
                'success' => false,
                'message' => sprintf( __( 'Connection failed to %s: %s', 'gowa-whatsapp' ), esc_url( $endpoint ), $error_msg ),
                'debug'   => array( 'endpoint' => $endpoint, 'error' => $error_msg ),
            );
        }

        // Parse API Response
        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        $is_success = ( $status_code === 200 || $status_code === 201 ) && (
            ! isset( $data['code'] ) || $data['code'] == 200 || $data['code'] === 'SUCCESS' || $data['code'] === 0 || ( isset( $data['status'] ) && $data['status'] === 'success' )
        );

        if ( $is_success ) {
            $msg_id = isset( $data['data']['message_id'] )
                ? $data['data']['message_id']
                : ( isset( $data['results']['message_id'] ) ? $data['results']['message_id'] : 'OK' );

            if ( $order && method_exists( $order, 'add_order_note' ) ) {
                $order->add_order_note( "✅ WhatsApp ({$event_label}) sent successfully. (ID: {$msg_id})" );
            }
            if ( $logger ) {
                $logger->info( "Order #{$order_id} [{$event_label}] - Successfully sent to {$phone}. (ID: {$msg_id}) | Raw: {$body}", $log_context );
            }

            return array(
                'success'    => true,
                'message'    => sprintf( __( 'WhatsApp message delivered to %s (ID: %s)', 'gowa-whatsapp' ), esc_html( $phone ), esc_html( $msg_id ) ),
                'message_id' => $msg_id,
                'data'       => $data,
            );
        } else {
            $err_msg = isset( $data['message'] ) ? $data['message'] : ( 'HTTP ' . $status_code . ': ' . $body );
            if ( $order && method_exists( $order, 'add_order_note' ) ) {
                $order->add_order_note( "❌ WhatsApp API error ({$event_label}, HTTP {$status_code}): {$err_msg}" );
            }
            if ( $logger ) {
                $logger->error( "Order #{$order_id} [{$event_label}] - API Error Response (HTTP {$status_code}): " . $body, $log_context );
            }
            self::log_error( "GOWA API Error ({$status_code}): " . $body );

            return array(
                'success' => false,
                'message' => $err_msg,
                'code'    => $status_code,
                'raw'     => $body,
            );
        }
    }

    /**
     * Check connection to GOWA server
     *
     * @return array
     */
    public static function check_connection() {
        $config = self::get_config();
        $api_url = $config['api_url'];

        if ( empty( $api_url ) ) {
            return array(
                'success' => false,
                'message' => __( 'GOWA API URL is empty. Please enter your server URL.', 'gowa-whatsapp' ),
            );
        }

        $headers = array( 'Accept' => 'application/json' );
        if ( ! empty( $config['auth_user'] ) && ! empty( $config['auth_pass'] ) ) {
            $headers['Authorization'] = 'Basic ' . base64_encode( "{$config['auth_user']}:{$config['auth_pass']}" );
        }

        if ( ! empty( $config['device_id'] ) ) {
            $headers['X-Device-Id'] = $config['device_id'];
        }

        // Endpoints to check
        $test_paths = array(
            '/app/status',
            '/app/devices',
            '/health',
            '/app/info',
            '/devices',
            '/',
        );

        $last_error = '';
        $connected_endpoint = '';
        $success_payload = null;

        foreach ( $test_paths as $path ) {
            $url = $api_url . $path;
            if ( ! empty( $config['device_id'] ) && $path !== '/health' ) {
                $url = add_query_arg( 'device_id', rawurlencode( $config['device_id'] ), $url );
            }

            $res = wp_remote_get( $url, array(
                'headers'   => $headers,
                'timeout'   => 12,
                'sslverify' => false,
            ) );

            if ( is_wp_error( $res ) ) {
                $last_error = $res->get_error_message();
                continue;
            }

            $code = wp_remote_retrieve_response_code( $res );
            $body = wp_remote_retrieve_body( $res );
            $data = json_decode( $body, true );

            if ( $code === 401 ) {
                return array(
                    'success'  => false,
                    'message'  => __( 'Authentication Failed (401 Unauthorized). Please check your Basic Auth Username and Password.', 'gowa-whatsapp' ),
                    'endpoint' => $url,
                );
            }

            if ( $code >= 200 && $code < 300 ) {
                $connected_endpoint = $url;
                $success_payload = $data ? $data : $body;
                break;
            }
        }

        if ( ! empty( $connected_endpoint ) ) {
            return array(
                'success'   => true,
                'message'   => __( 'Connected to GOWA Server successfully!', 'gowa-whatsapp' ),
                'api_url'   => $api_url,
                'endpoint'  => $connected_endpoint,
                'device_id' => $config['device_id'],
                'data'      => $success_payload,
            );
        }

        return array(
            'success' => false,
            'message' => sprintf( __( 'Could not connect to GOWA server at %s. %s', 'gowa-whatsapp' ), esc_url( $api_url ), ( $last_error ? 'Error: ' . $last_error : 'Server returned an invalid HTTP response.' ) ),
            'api_url' => $api_url,
            'error'   => $last_error,
        );
    }

    /**
     * Universal Phone Number Normalizer
     * 
     * Handles:
     * 1. JID strings (e.g. 8801700000000@s.whatsapp.net) -> returns trimmed
     * 2. Leading '+' (e.g. +8801700000000, +14155552671) -> strips '+'
     * 3. Leading '00' (e.g. 008801700000000) -> strips '00'
     * 4. Leading '0' with Configured Country Code:
     *    - BD (880): 01700000000 -> 8801700000000
     *    - ID (62): 08123456789 -> 628123456789
     *    - UK (44): 07123456789 -> 447123456789
     *    - IN (91): 09876543210 -> 919876543210
     * 5. Plain International numbers (e.g. 8801700000000, 14155552671) -> untouched
     *
     * @param string $phone
     * @return string
     */
    public static function format_phone( $phone ) {
        if ( strpos( $phone, '@' ) !== false ) {
            return trim( $phone );
        }

        $cleaned = preg_replace( '/[^0-9]/', '', $phone );
        if ( empty( $cleaned ) ) {
            return '';
        }

        $config     = self::get_config();
        $default_cc = ! empty( $config['default_country_code'] ) ? $config['default_country_code'] : '880';

        // 1. Strip leading international '00'
        if ( substr( $cleaned, 0, 2 ) === '00' ) {
            $cleaned = substr( $cleaned, 2 );
        }
        // 2. Handle single leading 0 (local national trunk format)
        elseif ( substr( $cleaned, 0, 1 ) === '0' && ! empty( $default_cc ) ) {
            $cleaned = $default_cc . substr( $cleaned, 1 );
        }

        return $cleaned . '@s.whatsapp.net';
    }

    /**
     * Log to WP debug log
     */
    public static function log_error( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[GOWA WhatsApp] ' . $message );
        }
    }
}
