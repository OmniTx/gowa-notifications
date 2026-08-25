<?php
/**
 * Fired when the plugin is deleted/uninstalled via WordPress Plugins screen.
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Delete the plugin options from the wp_options database table
delete_option( 'gowa_whatsapp_settings' );

// For multisite installations, delete option across all network blogs
if ( is_multisite() && function_exists( 'get_sites' ) ) {
    $gowa_blog_ids = get_sites( array( 'fields' => 'ids' ) );
    foreach ( $gowa_blog_ids as $gowa_blog_id ) {
        switch_to_blog( $gowa_blog_id );
        delete_option( 'gowa_whatsapp_settings' );
        restore_current_blog();
    }
}
