<?php

if ( ! function_exists( 'sacd_check_activecampaign_connection' ) ) {

    function sacd_check_activecampaign_connection()
    {
        $api_url  = get_option( 'sacd_url' );
        $api_key  = get_option( 'sacd_api_key' );
        $response = wp_remote_get( $api_url . '/api/3/users/me', [
            'headers' => [
                'Api-Token' => $api_key,
            ]
        ] );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 200 ) {
            return true;
        } else {
            return false;
        }
    }

}

if ( ! function_exists( 'sacd_check_tags' ) ) {

    function sacd_check_tags( $tagIds, $tags )
    {
        $found = false;
        foreach ( $tags as $tag ) {
            if ( in_array( $tag->tag, $tagIds ) ) {
                $found = true;
                break;
            }
        }
        return $found;
    }

}

if ( ! function_exists( 'sacd_create_logs' ) ) {

    function sacd_create_logs( $email, $protected )
    {
        global $post, $wpdb;

        // Ensure $post is valid
        $slug = isset( $post->post_name ) ? sanitize_text_field( $post->post_name ) : '';

        // Sanitize inputs
        $email     = sanitize_email( $email );
        $protected = (int) $protected;
        $ip        = sacd_get_ip_address();

        // Prepare data
        $data = [
            'slug'       => $slug,
            'email'      => $email,
            'ip_address' => $ip,
            'protected'  => $protected,
            'created_at' => current_time( 'mysql' ),
        ];

        // Prepare format for better SQL safety
        $format = [ '%s', '%s', '%s', '%d', '%s' ];

        // Insert into the logs table
        $table_name = $wpdb->prefix . 'sacd_logs';
        $result     = $wpdb->insert( $table_name, $data, $format );

        if ( false === $result ) {
            error_log( 'SACD Log insert failed: ' . $wpdb->last_error );
        }

        return $result;
    }

}

if ( ! function_exists( 'sacd_get_email' ) ) {

    function sacd_get_email()
    {
        $email = false;
        if ( is_user_logged_in() ) {
            $user  = wp_get_current_user();
            $email = $user->user_email;
        } else if ( isset( $_COOKIE['sacd_email'] ) ) {
            $cookie_email = sanitize_text_field( wp_unslash( $_COOKIE['sacd_email'] ) );
            $email        = base64_decode( $cookie_email );
        }

        return $email;
    }

}

if ( ! function_exists( 'sacd_get_ip_address' ) ) {

    function sacd_get_ip_address()
    {
        if ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        return $_SERVER['REMOTE_ADDR'];
    }

}

if ( ! function_exists( 'sacd_get_tags' ) ) {

    function sacd_get_tags( $post_id, $type )
    {
        $tagsJson = get_post_meta( $post_id, $type, true );
        $tags     = json_decode( $tagsJson );

        if ( json_last_error() === JSON_ERROR_NONE ) {
            return $tags;
        }
        return [];
    }

}
