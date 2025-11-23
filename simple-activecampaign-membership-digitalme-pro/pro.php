<?php

function sacd_pro_init()
{
    if ( isset( $_GET['sacd-login'] ) && isset( $_GET['sacd-user'] ) ) {
        $login       = sanitize_text_field( $_GET['sacd-login'] );
        $user        = sanitize_text_field( $_GET['sacd-user'] );
        $user_id     = base64_decode( $user );
        $login_token = get_user_meta( $user_id, 'sacd_login_token', true );
        if ( $login == $login_token ) {
            wp_set_auth_cookie( $user_id, true );
            delete_user_meta( $user_id, 'sacd_login_token' );
            wp_redirect( home_url() );
            exit;
        }
    }
}

add_action( 'init', 'sacd_pro_init' );

function sacd_pro_login_enqueue_scripts()
{
    ?>
    <style>
        .user-pass-wrap {
            display: none !important;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const passwordField = document.getElementById('user_pass');
            if (passwordField) {
                passwordField.value = 'password';
            }
        });
    </script>
<?php
}

add_action( 'login_enqueue_scripts', 'sacd_pro_login_enqueue_scripts' );

function sacd_pro_authenticate( $user, $username, $password )
{

    if ( ! empty( $username ) ) {
        $user_object = get_user_by( 'login', $username );
        if ( ! $user_object && is_email( $username ) ) {
            $user_object = get_user_by( 'email', $username );
        }
        if ( $user_object ) {
            $login_token = uniqid();
            update_user_meta( $user_object->ID, 'sacd_login_token', $login_token );
            $message = 'Click <a href="' . site_url( '?sacd-login=' . $login_token . '&sacd-user=' . base64_encode( $user_object->ID ) ) . '">here</a> to login to ' . get_bloginfo( 'name' );
            wp_mail( $username, __( 'Login to ', 'sacd' ) . get_bloginfo( 'name' ), $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
            wp_redirect( wp_login_url() . '?sacd-email=sent' );
            exit;
        } else {
            $api_url  = get_option( 'sacd_url' );
            $api_key  = get_option( 'sacd_api_key' );
            $response = wp_remote_get( $api_url . '/api/3/contacts?email=' . $username, array(
                'headers' => array(
                    'Api-Token' => $api_key,
                ),
            ) );
            $body     = wp_remote_retrieve_body( $response );
            $data     = json_decode( $body );
            if ( isset( $data->contacts[0]->email ) ) {
                wp_create_user( $username, $username, $username );
                $user_object = get_user_by( 'email', $username );
                $login_token = uniqid();
                update_user_meta( $user_object->ID, 'sacd_login_token', $login_token );
                $message = 'Click <a href="' . site_url( '?sacd-login=' . $login_token . '&user=' . base64_encode( $user_object->ID ) ) . '">here</a> to login to ' . get_bloginfo( 'name' );
                wp_mail( $username, __( 'Login to ', 'sacd' ) . get_bloginfo( 'name' ), $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
                wp_redirect( wp_login_url() . '?sacd-email=sent' );
                exit;
            } else {
                return new WP_Error( 'invalid_user', __( 'Invalid username or email.', 'sacd' ) );
            }
        }
    }
    return $user;
}

add_filter( 'authenticate', 'sacd_pro_authenticate', 10, 3 );

function sacd_pro_login_message( $message )
{
    if ( isset( $_GET['sacd-email'] ) ) {
        $message = '<div class="notice notice-success"><p>' . __( 'Please click link in your email to login', 'sacd' ) . '</p></div>';
    }
    return $message;
}

add_filter( 'login_message', 'sacd_pro_login_message' );

function sacd_pro_ac_contact( $attributes )
{
    $attributes = shortcode_atts( array(
        'field' => 'firstname',
    ), $attributes, 'ac-contact' );
    $api_url    = get_option( 'sacd_url' );
    $api_key    = get_option( 'sacd_api_key' );
    $email      = sacd_get_email();
    $response   = wp_remote_get( $api_url . '/api/3/contacts?email=' . $email, array(
        'headers' => array(
            'Api-Token' => $api_key,
        ),
    ) );
    $body       = wp_remote_retrieve_body( $response );
    $data       = json_decode( $body, true );
    if ( isset( $data['contacts'][0][ $attributes['field'] ] ) ) {
        return $data['contacts'][0][ $attributes['field'] ];
    }
    return '';
}

add_shortcode( 'ac-contact', 'sacd_pro_ac_contact' );

function sacd_pro_send_verification( $post_id, $email )
{
    if ( $email ) {
        $token       = uniqid();
        $sacd_tokens = get_option( 'sacd_tokens', [] );
        $message     = 'Click <a href="' . get_permalink( $post_id ) . '?sacd-token=' . $token . '">here</a> to view the content';
        wp_mail( $email, __( 'Content Protection', 'sacd' ), $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
        if ( ! isset( $sacd_tokens[ $email ] ) ) {
            $sacd_tokens[ $email ] = [];
        }
        $sacd_tokens[ $email ][ $token ] = date( 'Y-m-d H:i:s', strtotime( '+10 minutes' ) );
        update_option( 'sacd_tokens', $sacd_tokens );
    }
}
