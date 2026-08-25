<?php
/**
 * GOWA API Client, Async Queue & Universal Diagnostics
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
     * Schedule a WhatsApp message to be sent asynchronously in the background via Action Scheduler.
     * Prevents customer checkout delay or timeouts. Falls back to direct send if Action Scheduler is unavailable.
     *
     * @param string $raw_phone Recipient phone number
     * @param string $message Message text
     * @param WC_Order|null $order Optional order object
     * @param string $event_label Event context (e.g. order_received, order_completed)
     * @param int $delay_seconds Seconds to delay before sending
     * @return array
     */
    public static function schedule_message( $raw_phone, $message, $order = null, $event_label = 'notification', $delay_seconds = 0 ) {
        $order_id = ( $order && method_exists( $order, 'get_id' ) ) ? $order->get_id() : 0;
        $delay_seconds = (int) $delay_seconds;

        // Use WooCommerce Action Scheduler for background processing if delay is requested
        if ( $delay_seconds > 0 && function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time() + $delay_seconds,
                'gowa_async_send_message',
                array(
                    'raw_phone'   => $raw_phone,
                    'message'     => $message,
                    'order_id'    => $order_id,
                    'event_label' => $event_label,
                ),
                'notify-with-gowa'
            );

            return array(
                'success' => true,
                'queued'  => true,
                'message' => s/* translators: %1$s is the URL, %2$s is the error message */`n            printf( __( 'Message scheduled for background delivery in %d seconds.', 'notify-with-gowa' ), $delay_seconds ),
            );
        }

        // Direct fallback if Action Scheduler is not loaded
        return self::send_message( $raw_phone, $message, $order, $event_label );
    }

    /**
     * Background execution callback for Action Scheduler
     */
    public static function handle_async_send( $raw_phone, $message, $order_id = 0, $event_label = 'notification' ) {
        $order = ( $order_id > 0 && function_exists( 'wc_get_order' ) ) ? wc_get_order( $order_id ) : null;
        return self::send_message( $raw_phone, $message, $order, $event_label );
    }

    /**
     * Send a WhatsApp message via GOWA API immediately
     *
     * @param string $raw_phone Recipient phone number
     * @param string $message Message text
     * @param WC_Order|null $order Optional order object for logging
     * @param string $event_label Event context
     * @return array
     */
    public static function send_message( $raw_phone, $message, $order = null, $event_label = 'notification' ) {
        $config = self::get_config();

        // Native WooCommerce Logger
        $logger      = function_exists( 'wc_get_logger' ) ? wc_get_logger() : null;
        $log_context = array( 'source' => 'gowa_whatsapp_api' );

        $phone = self::format_phone( $raw_phone );
        if ( empty( $phone ) ) {
            $err = s/* translators: %1$s is the URL, %2$s is the error message */`n            printf( __( 'Invalid recipient phone number: %s', 'notify-with-gowa' ), esc_html( $raw_phone ) );
            if ( $order && method_exists( $order, 'add_order_note' ) ) {
                $order->add_order_note( "❌ WhatsApp Error ({$event_label}): " . $err );
            }
            if ( $logger ) {
                $logger->error( "GOWA Send Error [{$event_label}]: {$err}", $log_context );
            }
            return array( 'success' => false, 'message' => $err );
        }

        if ( empty( $config['api_url'] ) ) {
            $err = __( 'GOWA API URL is not configured. Please configure your settings in Settings > Notify with GOWA.', 'notify-with-gowa' );
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
            'timeout'     => 15,
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
                'message' => s/* translators: %1$s is the URL, %2$s is the error message */`n            printf( __( 'Connection failed to %1$s: %2$s', 'notify-with-gowa' ), esc_url( $endpoint ), $error_msg ),
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
                'message'    => s/* translators: %1$s is the URL, %2$s is the error message */`n            printf( __( 'WhatsApp message delivered to %1$s (ID: %2$s)', 'notify-with-gowa' ), esc_html( $phone ), esc_html( $msg_id ) ),
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
                'message' => __( 'GOWA API URL is empty. Please enter your server URL.', 'notify-with-gowa' ),
            );
        }

        $headers = array( 'Accept' => 'application/json' );
        if ( ! empty( $config['auth_user'] ) && ! empty( $config['auth_pass'] ) ) {
            $headers['Authorization'] = 'Basic ' . base64_encode( "{$config['auth_user']}:{$config['auth_pass']}" );
        }

        if ( ! empty( $config['device_id'] ) ) {
            $headers['X-Device-Id'] = $config['device_id'];
        }

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
                    'message'  => __( 'Authentication Failed (401 Unauthorized). Please check your Basic Auth Username and Password.', 'notify-with-gowa' ),
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
                'message'   => __( 'Connected to GOWA Server successfully!', 'notify-with-gowa' ),
                'api_url'   => $api_url,
                'endpoint'  => $connected_endpoint,
                'device_id' => $config['device_id'],
                'data'      => $success_payload,
            );
        }

        return array(
            'success' => false,
            'message' => s/* translators: %1$s is the URL, %2$s is the error message */`n            printf( __( 'Could not connect to GOWA server at %1$s. %2$s', 'notify-with-gowa' ), esc_url( $api_url ), ( $last_error ? 'Error: ' . $last_error : 'Server returned an invalid HTTP response.' ) ),
            'api_url' => $api_url,
            'error'   => $last_error,
        );
    }

    /**
     * Universal Phone Number Normalizer
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

    public static function log_error( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            
        }
    }
}