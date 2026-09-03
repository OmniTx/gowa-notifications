<?php
/**
 * Fired when the plugin is deleted/uninstalled via WordPress Plugins screen.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$nwg_settings = get_option( 'notify_with_gowa_settings', array() );
if ( empty( $nwg_settings ) ) {
    $nwg_settings = get_option( 'gowa_whatsapp_settings', array() );
}

// Only erase data if the administrator explicitly enabled the removal setting
if ( ! empty( $nwg_settings['erase_data_on_uninstall'] ) ) {
    // Delete the plugin options from the wp_options database table
    delete_option( 'notify_with_gowa_settings' );
    delete_option( 'gowa_whatsapp_settings' );

    // For multisite installations, delete option across all network blogs
    if ( is_multisite() && function_exists( 'get_sites' ) ) {
        $nwg_blog_ids = get_sites( array( 'fields' => 'ids' ) );
        foreach ( $nwg_blog_ids as $nwg_blog_id ) {
            switch_to_blog( $nwg_blog_id );
            delete_option( 'notify_with_gowa_settings' );
            delete_option( 'gowa_whatsapp_settings' );
            restore_current_blog();
        }
    }
}