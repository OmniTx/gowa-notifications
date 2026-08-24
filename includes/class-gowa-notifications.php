<?php
/**
 * GOWA WordPress Core Event Notifications
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GOWA_Notifications {

    public function __construct() {
        add_action( 'user_register', array( $this, 'on_user_register' ), 10, 1 );
        add_action( 'comment_post', array( $this, 'on_new_comment' ), 10, 3 );
    }

    public function on_user_register( $user_id ) {
        $settings = get_option( 'gowa_whatsapp_settings', array() );

        if ( empty( $settings['enable_wp_user_reg'] ) || empty( $settings['admin_phone'] ) ) {
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $template = ! empty( $settings['wp_user_reg_msg'] ) ? $settings['wp_user_reg_msg'] : "🎉 *New User Registered*\n\nSite: {site_name}\nUsername: {username}\nEmail: {email}\nRegistered: {date}";

        $tags = array(
            '{site_name}'  => get_bloginfo( 'name' ),
            '{site_url}'   => site_url(),
            '{username}'   => $user->user_login,
            '{email}'      => $user->user_email,
            '{user_id}'    => $user_id,
            '{date}'       => current_time( 'mysql' ),
        );

        $message      = str_replace( array_keys( $tags ), array_values( $tags ), $template );
        $admin_phones = array_filter( array_map( 'trim', explode( ',', $settings['admin_phone'] ) ) );
        foreach ( $admin_phones as $phone ) {
            GOWA_API::queue_message( $phone, $message, null, 'wp_user_register' );
        }
    }

    public function on_new_comment( $comment_id, $comment_approved, $commentdata ) {
        $settings = get_option( 'gowa_whatsapp_settings', array() );

        if ( empty( $settings['enable_wp_comment'] ) || empty( $settings['admin_phone'] ) ) {
            return;
        }

        $post = get_post( $commentdata['comment_post_ID'] );
        $post_title = $post ? $post->post_title : 'N/A';

        $template = ! empty( $settings['wp_comment_msg'] ) ? $settings['wp_comment_msg'] : "💬 *New Comment on {site_name}*\n\nAuthor: {author}\nPost: {post_title}\nComment: {comment_content}";

        $tags = array(
            '{site_name}'        => get_bloginfo( 'name' ),
            '{author}'           => $commentdata['comment_author'],
            '{author_email}'     => $commentdata['comment_author_email'],
            '{post_title}'       => $post_title,
            '{comment_content}'  => wp_trim_words( $commentdata['comment_content'], 40, '...' ),
            '{comment_url}'      => get_comment_link( $comment_id ),
            '{date}'             => current_time( 'mysql' ),
        );

        $message      = str_replace( array_keys( $tags ), array_values( $tags ), $template );
        $admin_phones = array_filter( array_map( 'trim', explode( ',', $settings['admin_phone'] ) ) );
        foreach ( $admin_phones as $phone ) {
            GOWA_API::queue_message( $phone, $message, null, 'wp_comment' );
        }
    }
}

new GOWA_Notifications();