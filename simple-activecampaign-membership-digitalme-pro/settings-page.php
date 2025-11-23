<?php

if ( ! function_exists( 'sacd_admin_menu' ) ) {
    function sacd_admin_menu() {
        add_options_page(
            __( 'Simple ActiveCampaign Membership DigitalME', 'sacd' ),
            __( 'Simple ActiveCampaign Membership DigitalME', 'sacd' ),
            'manage_options',
            'sacd-settings',
            'sacd_settings_page'
        );
        add_menu_page(
            __( 'Simple ActiveCampaign Membership DigitalME Logs', 'sacd' ),
            __( 'Simple ActiveCampaign Membership DigitalME Logs', 'sacd' ),
            'manage_options',
            'sacd-logs',
            'sacd_logs_page',
            'dashicons-welcome-write-blog'
        );
    }
    add_action( 'admin_menu', 'sacd_admin_menu' );

    function sacd_settings_page() {
?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Simple ActiveCampaign Membership DigitalME', 'sacd' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields( 'sacd_options_group' );
                    do_settings_sections( 'sacd-settings' );
                    submit_button();
                ?>
            </form>
        </div>
<?php
    }
}

if ( ! function_exists( 'sacd_admin_init' ) ) {
    function sacd_admin_init() {
        register_setting(
            'sacd_options_group',
            'sacd_url',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sacd_validate_url',
                'default'           => '',
            )
        );
        register_setting(
            'sacd_options_group',
            'sacd_api_key',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            )
        );
        add_settings_section(
            'sacd_main_section',
            __( 'Settings', 'sacd' ),
            null,
            'sacd-settings'
        );
        add_settings_field(
            'sacd_url',
            __( 'ActiveCampaign URL', 'sacd' ),
            'sacd_url_field_render',
            'sacd-settings',
            'sacd_main_section'
        );
        add_settings_field(
            'sacd_api_key',
            __( 'ActiveCampaign API Key', 'sacd' ),
            'sacd_api_key_field_render',
            'sacd-settings',
            'sacd_main_section'
        );
    }
    add_action( 'admin_init', 'sacd_admin_init' );

    function sacd_url_field_render() {
        $value = get_option( 'sacd_url' );
        echo '<input type="text" name="sacd_url" value="' . esc_attr( $value ) . '" />';
    }
    
    function sacd_api_key_field_render() {
        $value = get_option( 'sacd_api_key' );
        echo '<input type="text" name="sacd_api_key" value="' . esc_attr( $value ) . '" />';
    }

    function sacd_validate_url( $input ) {
        if ( filter_var( $input, FILTER_VALIDATE_URL ) ) {
            return esc_url_raw( $input );
        }
        add_settings_error(
            'sacd_url',
            'invalid-url',
            __( 'Please enter a valid URL', 'sacd' ),
            'error'
        );
        return get_option( 'sacd_url' );
    }
}