<?php
/**
 * Plugin Name:         ActiveMembPlus
 * Description:         Allow or disallow a specific ActiveCampaign tag to either show or not show a page
 * Version:             1.4.0
 * Requires at least:   4.7
 * Requires PHP:        7.0
 * Author:              ActiveMemb
 * Author URI:          https://activememb.com/
 * License:             GPL-2.0+
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

if ( ! defined( 'SACD_VERSION' ) ) {
    define( 'SACD_VERSION', '1.2.1' );
}

if ( ! defined( 'SACD_PRO_USE_CACHE' ) ) {
    define( 'SACD_PRO_USE_CACHE', true ); // Set to false to disable caching
}

require_once plugin_dir_path( __FILE__ ) . 'functions.php';
require_once plugin_dir_path( __FILE__ ) . 'class-sacd.php';


// === 2FA Timeout Settings ===
// Helper functions for settings values
function sacd_get_timeout_minutes() {
    $m = (int) get_option('sacd_2fa_timeout_minutes', 10);
    return $m > 0 ? $m : 10;
}

function sacd_get_autofill_enabled() {
    return get_option('sacd_autofill_enabled', 1) ? 1 : 0;
}


if ( ! function_exists( 'sacd_update_check' ) ) {

    function sacd_update_check()
    {
        $current_version = get_option( 'sacd_version', '1.0.0' );
        if ( version_compare( $current_version, '1.1.0', '<' ) ) {
            global $wpdb;
            $sacd_log_table  = $wpdb->prefix . 'sacd_logs';
            $charset_collate = $wpdb->get_charset_collate();
            $sql             = "CREATE TABLE $sacd_log_table (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                slug varchar(255) NOT NULL,
                email varchar(255) NULL,
                ip_address varchar(255) NOT NULL,
                protected TINYINT(1) DEFAULT 1,
                created datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id)
            ) $charset_collate;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );
        }
        update_option( 'sacd_version', SACD_VERSION );
    }

    add_action( 'admin_init', 'sacd_update_check' );
}

if ( ! function_exists( 'sacd_logs_page' ) ) {

    function sacd_logs_page()
    {
        ?>
        <div class="wrap">
            <h1><?php _e( 'ActiveMemb Logs', 'sacd' ); ?></h1>
            <?php
            $sacd_logs = new Sacd_Logs_Table_List();
            $sacd_logs->prepare_items();
            $sacd_logs->display();
            ?>
        </div>
<?php
    }

    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

    class Sacd_Logs_Table_List extends WP_List_Table
    {

        public function __construct()
        {
            parent::__construct( [
                'singular' => 'sacd',
                'plural'   => 'sacds',
                'ajax'     => false,
            ] );
        }

        public function get_columns()
        {
            return [
                'slug'       => __( 'Slug', 'sacd' ),
                'email'      => __( 'Email', 'sacd' ),
                'ip_address' => __( 'IP Address', 'sacd' ),
                'protected'  => __( 'Protected', 'sacd' ),
                'created'    => __( 'Created At', 'sacd' ),
            ];
        }

        public function prepare_items()
        {
            global $wpdb;
            $sacd_logs    = $wpdb->prefix . 'sacd_logs';
            $per_page     = 10;
            $current_page = $this->get_pagenum();
            $offset       = ( $current_page - 1 ) * $per_page;
            $total_items  = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM $sacd_logs" ) );
            $items        = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $sacd_logs ORDER BY created DESC LIMIT $offset, $per_page",
            ), ARRAY_A );
            $this->items  = $items;
            $this->set_pagination_args( [
                'total_items' => $total_items,
                'per_page'    => $per_page,
                'total_pages' => ceil( $total_items / $per_page ),
            ] );
            $this->_column_headers = [ $this->get_columns(), [], [] ];
        }

        public function column_default( $item, $column_name )
        {
            return $item[ $column_name ];
        }

        public function column_protected( $item )
        {
            return $item['protected'] ? __( 'Yes', 'sacd' ) : __( 'No', 'sacd' );
        }

    }

}

if ( ! function_exists( 'sacd_enqueue_scripts' ) ) {

    function sacd_enqueue_scripts()
    {
        wp_enqueue_script(
            'sacd-script',
            plugin_dir_url( __FILE__ ) . 'js/script.js',
            ['jquery'],
            filemtime( plugin_dir_path( __FILE__ ) . 'js/script.js' ),
            true
        );
    }

    add_action( 'wp_enqueue_scripts', 'sacd_enqueue_scripts', 20 );
}

if ( ! function_exists( 'sacd_init' ) ) {

    function sacd_init()
    {
        if ( get_option( 'sacd_license_active', false ) ) {
            wp_register_script(
                'ac-form-block-script',
                plugins_url( 'js/block-pro.js', __FILE__ ),
                array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-edit-post', 'wp-components', 'wp-plugins', 'wp-data' ),
                filemtime( plugin_dir_path( __FILE__ ) . 'js/block-pro.js' )
            );
        } else {
            wp_register_script(
                'ac-form-block-script',
                plugins_url( 'js/block.js', __FILE__ ),
                array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-edit-post', 'wp-components', 'wp-plugins', 'wp-data' ),
                filemtime( plugin_dir_path( __FILE__ ) . 'js/block.js' )
            );
        }
        wp_localize_script( 'ac-form-block-script', 'sacd', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'sacd_nonce' ),
            'apiUrl'  => get_option( 'sacd_url' ),
        ) );
        register_block_type( 'sacd/ac-form-block', array(
            'editor_script' => 'ac-form-block-script',
        ) );
        $postTypes = [ 'post', 'page' ];
        foreach ( $postTypes as $postType ) {
            register_post_meta( $postType, 'sacd_tag_id', array(
                'show_in_rest'  => true,
                'type'          => 'string',
                'single'        => true,
                'auth_callback' => function () {
                    return current_user_can( 'edit_posts' );
                }
            ) );
            register_post_meta( $postType, 'sacd_disallowed_tag_id', array(
                'show_in_rest'  => true,
                'type'          => 'string',
                'single'        => true,
                'auth_callback' => function () {
                    return current_user_can( 'edit_posts' );
                }
            ) );
            register_post_meta( $postType, 'sacd_fallback_url', array(
                'show_in_rest'  => true,
                'type'          => 'string',
                'single'        => true,
                'auth_callback' => function () {
                    return current_user_can( 'edit_posts' );
                }
            ) );
            register_post_meta( $postType, 'sacd_2fa', array(
                'show_in_rest'  => true,
                'type'          => 'string',
                'single'        => true,
                'auth_callback' => function () {
                    return current_user_can( 'edit_posts' );
                }
            ) );
        }
    }

    add_action( 'init', 'sacd_init' );
}

if ( ! function_exists( 'sacd_get_ac_forms' ) ) {

    function sacd_get_ac_forms()
    {
        check_ajax_referer( 'sacd_nonce', 'nonce' );
        $api_url  = get_option( 'sacd_url' );
        $api_key  = get_option( 'sacd_api_key' );
        $response = wp_remote_get( $api_url . '/api/3/forms', array(
            'headers' => array(
                'Api-Token' => $api_key,
            ),
        ) );
        $body     = wp_remote_retrieve_body( $response );
        $data     = json_decode( $body );
        wp_send_json_success( $data );
    }

    add_action( 'wp_ajax_sacd_get_ac_forms', 'sacd_get_ac_forms' );
}

if ( ! function_exists( 'sacd_get_ac_tags' ) ) {

    function sacd_get_ac_tags()
    {
        check_ajax_referer( 'sacd_nonce', 'nonce' );
        $api_url  = get_option( 'sacd_url' );
        $api_key  = get_option( 'sacd_api_key' );
        $response = wp_remote_get( $api_url . '/api/3/tags?limit=100', array(
            'headers' => array(
                'Api-Token' => $api_key,
            ),
        ) );
        $body     = wp_remote_retrieve_body( $response );
        $data     = json_decode( $body );
        wp_send_json_success( $data );
    }

    add_action( 'wp_ajax_sacd_get_ac_tags', 'sacd_get_ac_tags' );
}

if ( ! function_exists( 'sacd_protect_content' ) ) {

    function get_sacd_tags_data( $post_id )
    {
        $sacd_tag_id            = sacd_get_tags( $post_id, 'sacd_tag_id' );
        $sacd_disallowed_tag_id = sacd_get_tags( $post_id, 'sacd_disallowed_tag_id' );
        $sacd_2fa               = get_post_meta( $post_id, 'sacd_2fa', true );
        $sacd_tag_id            = array_filter( (array) $sacd_tag_id );
        $sacd_disallowed_tag_id = array_filter( (array) $sacd_disallowed_tag_id );

        return [
            'sacd_tag_id'            => $sacd_tag_id,
            'sacd_disallowed_tag_id' => $sacd_disallowed_tag_id,
            'sacd_2fa'               => $sacd_2fa
        ];
    }

    function sacd_check_activecampaign_connection_pro()
    {

        $email   = sacd_get_email();
        $api_url = get_option( 'sacd_url' );
        $api_key = get_option( 'sacd_api_key' );

        if ( ! $email ) {
            return false;
        }

        $protected     = false;
        // Each login session has a unique token
        $session_token = wp_get_session_token();
        $cache_key     = 'sacdc_protect_content_' . md5( $session_token );

        // Check cache first
        $cached = get_transient( $cache_key );
        if ( $cached !== false && SACD_PRO_USE_CACHE ) {
            // Add cahce later for implementation
        }

        $response = wp_remote_get( $api_url . '/api/3/contacts?email=' . $email, array(
            'headers' => array(
                'Api-Token' => $api_key,
            ),
        ) );

        if ( ! is_wp_error( $response ) ) {
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body );

            if ( isset( $data->contacts[0]->id ) ) {
                $response = wp_remote_get( $api_url . '/api/3/contacts/' . $data->contacts[0]->id . '/contactTags', array(
                    'headers' => array(
                        'Api-Token' => $api_key,
                    ),
                ) );
                if ( ! is_wp_error( $response ) ) {
                    $body = wp_remote_retrieve_body( $response );
                    $data = json_decode( $body );

                    // Save for a long time, since it’s session-token keyed
                    // set_transient( $cache_key, $data, DAY_IN_SECONDS );

                    return $data;
                }
            }
        }

        return [];
    }

    function sacd_protect_content()
    {
        $email       = sacd_get_email();
        $tagData     = get_sacd_tags_data( get_the_ID() );
        $contactTags = sacd_check_activecampaign_connection_pro()->contactTags ?? [];

        $sacd_tag_id            = $tagData['sacd_tag_id'] ?? [];
        $sacd_disallowed_tag_id = $tagData['sacd_disallowed_tag_id'] ?? [];
        $hasAllowed             = false;
        $hasDisallowed          = false;

        $protected = true;

        if ( ! empty( $sacd_disallowed_tag_id ) ) {

            if ( $email && $contactTags ) {
                $hasDisallowed = sacd_check_tags( $sacd_disallowed_tag_id, $contactTags );
                $protected     = $hasDisallowed;

                // If both allowed & disallowed tags exist
                if ( ! empty( $sacd_tag_id ) ) {
                    $hasAllowed = sacd_check_tags( $sacd_tag_id, $contactTags );

                    // If contact has neither allowed nor disallowed tags, stay protected
                    if ( ! $hasDisallowed && ! $hasAllowed ) {
                        $protected = true;
                    }
                }
            }
        } else {
            $protected = false;
        }

        if ( ! empty( $sacd_tag_id ) && ! $protected ) {

            if ( $email && $contactTags ) {
                $hasAllowed = sacd_check_tags( $sacd_tag_id, $contactTags );
                if ( $hasAllowed ) {
                    $protected = false;
                } elseif ( ! $hasAllowed && ! $hasDisallowed ) {
                    $protected = true;
                } else {
                    $protected = false;
                }
            } else {
                $email     = null;
                $protected = true;
            }

            sacd_create_logs( $email, $protected );
        }

        if ( empty( $sacd_disallowed_tag_id ) && empty( $sacd_tag_id ) ) {
            $protected = false;
        }

        return $protected;
    }

    function sacd_template_redirect()
    {
        global $post;

        if ( ! is_singular() ) {
            return;
        }

        $post_id = $post->ID;

        $email                  = sacd_get_email();
        $tagData                = get_sacd_tags_data( $post_id );
        $sacd_tag_id            = $tagData['sacd_tag_id'];
        $sacd_disallowed_tag_id = $tagData['sacd_disallowed_tag_id'];

        if ( ! $email && empty( $sacd_tag_id ) && $sacd_disallowed_tag_id ) {
            return;
        }

        if ( ! $email && ( $sacd_tag_id || $sacd_disallowed_tag_id ) ) {
            $fallback_url = get_post_meta( get_the_ID(), 'sacd_fallback_url', true );
            if ( ! $fallback_url ) {
                $fallback_url = home_url();
            }
            
			$sacd_2fa = get_post_meta($post_id, 'sacd_2fa', true);
    if ($sacd_2fa !== 'yes'){
			
			
			wp_redirect($fallback_url );
            exit;
	}
	
	if(! $email && $sacd_2fa == 'yes'){
        wp_redirect( $fallback_url );
            exit;
    }

	}
	}

    add_action( 'template_redirect', 'sacd_template_redirect' );

	// ---- 2FA Session Timeout Enforcement (WooCommerce-aware) ----

add_action('template_redirect', function() {
    if (!is_singular()) return;

    $sacd_enabled = get_post_meta(get_the_ID(), 'sacd_2fa', true);
    if (!$sacd_enabled) return;

    // SESSION START
    if (!session_id()) @session_start();

    // Timeout in seconds
	$limit_seconds = sacd_get_timeout_minutes() * 60;



	$email = sacd_get_email();

    // TIMEOUT LOGIC
    if (isset($_SESSION['ac2fa_last_activity']) && (time() - (int) $_SESSION['ac2fa_last_activity']) > $limit_seconds) {
        unset($_SESSION['ac2fa_last_activity']);

        // Remove plugin/cookie tokens
        if (isset($_COOKIE['sacd_token'])) {
            setcookie('sacd_token', '', time() - 3600, '/');
        }
        if (isset($_COOKIE['sacd_token_time'])) {
            setcookie('sacd_token_time', '', time() - 3600, '/');
        }

        // Destroy PHP session and log out of WP
        @session_unset();
        @session_destroy();

        if (is_user_logged_in()) wp_logout();
        @session_write_close();

        //wp_safe_redirect(esc_url_raw($login_page));
        //exit;
    }

    $_SESSION['ac2fa_last_activity'] = time();
});

add_action('wp_footer', function () {
    if (!is_singular()) return;
    $post_id = get_the_ID();
    $sacd_2fa = get_post_meta($post_id, 'sacd_2fa', true);
    if ($sacd_2fa !== 'yes') return;

    $timeout_ms = sacd_get_timeout_minutes() * 60 * 1000;
    echo "<script>(function(){setTimeout(function(){window.location.reload();},".$timeout_ms.");})();</script>";
});


    // Protect post content
    function filter_pro_sacd_content( $content )
    {
        if ( ! is_singular() ) {
            return $content;
        }

        $post_id   = get_the_ID();
        $protected = sacd_protect_content();

        $email                  = sacd_get_email();
        $tagData                = get_sacd_tags_data( $post_id );
        $sacd_tag_id            = $tagData['sacd_tag_id'];
        $sacd_disallowed_tag_id = $tagData['sacd_disallowed_tag_id'];
        $sacd_2fa               = $tagData['sacd_2fa'];
        $emails_verified        = isset( $_SESSION['sacd_emails_verified'] ) ? $_SESSION['sacd_emails_verified'] : [];

        $building_block = function ( $content ) {
            return "<div style='display:flex;flex-direction: column;align-items: center;justify-content: center;'><div style='border-radius:10px;width: 70%;display:flex;flex-direction: column;align-items: center;justify-content: center;border: 1px solid #ccc;padding: 10px;margin: 10px 0;background: #f9f9f9;padding: 30px 0;'>{$content}</div></div>";
        };

        if ( ! $email && empty( $sacd_tag_id ) && $sacd_disallowed_tag_id ) {
            return $content;
        }

        if ( $protected ) {
            if ( $email && ! isset( $emails_verified[ $email ] ) ) {
                sacd_send_verification( $post_id, $email );
                $emails_verified[ $email ]                  = strtotime( '+10 minutes' );
                $_SESSION['sacd_emails_verified'][ $email ] = $emails_verified;
                $content                                    = '<p style="text-align: center;margin:0;"><img src="' . plugin_dir_url( __FILE__ ) . 'loading.gif" style="width: 50px;" /><br />' . __( 'Please check your email to view the content', 'ams' ) . '</p>';
                return $content;
            }

            $content = "<p style='text-align: center; margin:0;color:red;font-weight:500;'>You don't have access to this page. Please contact the website owner</p>";
            return $content;
        }

        if ( $sacd_tag_id || $sacd_disallowed_tag_id ) {

            $sacd_tokens = get_option( 'sacd_tokens', [] );

            if ( $email ) {
                if ( isset( $_GET['sacd-token'] ) ) {
                    $token = sanitize_text_field( $_GET['sacd-token'] );

                    if ( isset( $sacd_tokens[ $email ][ $token ] ) ) {
                        if ( time() > strtotime( $sacd_tokens[ $email ][ $token ] ) && 'yes' === $sacd_2fa ) {
                            sacd_send_verification( $post_id, $email );
                            $content = '<p style="text-align: center;margin:0;"><img src="' . plugin_dir_url( __FILE__ ) . 'loading.gif" style="width: 50px;" /><br/ >' . __( 'Token has expired', 'ams' ) . '<br />' . __( 'Please check your email to view the content', 'ams' ) . '</p>';
                            return $content;
                        } else {
                            $emails_verified[ $email ]                  = strtotime( '+10 minutes' );
                            $_SESSION['sacd_emails_verified'][ $email ] = $emails_verified;
                        }
                    }
                }

                if ( isset( $emails_verified[ $email ] ) ) {
                    if ( time() > $_SESSION['sacd_emails_verified'][ $email ] ) {
                        sacd_send_verification( $post_id, $email );
                        $content = '<p style="text-align: center;margin:0;"><img src="' . plugin_dir_url( __FILE__ ) . 'loading.gif" style="width: 50px;" /><br />' . __( 'Please check your email to view the content', 'ams' ) . '</p>';
                    }
                } else {
                    sacd_send_verification( $post_id, $email );
                    $content = '<p style="text-align: center;margin:0;"><img src="' . plugin_dir_url( __FILE__ ) . 'loading.gif" style="width: 50px;" /><br />' . __( 'Please check your email to view the content', 'ams' ) . '</p>';
                }

            } else {
                $content = '<p style="text-align: center;margin:0;"><img src="' . plugin_dir_url( __FILE__ ) . 'loading.gif" style="width: 50px;" /><br />' . __( 'Please check your email to view the content', 'ams' ) . '</p>';
            }
        }

        return $content;
    }

    add_filter( 'the_content', 'filter_pro_sacd_content' );
}

function sacd_pro_admin_menu()
{
    $path = plugin_dir_path( __FILE__ ) . 'assets/logo.svg';

    if ( file_exists( $path ) ) {
        $icon_svg = 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( $path ) );
    } else {
        error_log( 'Logo not found at: ' . $path );
        $icon_svg = 'dashicons-admin-generic'; // fallback icon
    }

    add_menu_page(
        __( 'ActiveMemb Plus', 'sacd' ), // Page title
        __( 'ActiveMemb Plus', 'sacd' ), // Menu title
        'manage_options', // Capability
        'sacd', // Menu slug
        'sacd_pro_license_management_page', // Callback function
        $icon_svg, // Inline SVG
        6 // Position
    );
}

add_action( 'admin_menu', 'sacd_pro_admin_menu' );

function sacd_pro_license_management_page()
{
    if ( isset( $_POST['license_key'] ) ) {
        $license_key = sanitize_text_field( $_POST['license_key'] );
        $url         = sanitize_text_field( $_POST['sacd_url'] );
        $api_key     = sanitize_text_field( $_POST['sacd_api_key'] );
        if ( $license_key && ! get_option( 'sacd_license_active', false ) ) {
            $license_key = sanitize_text_field( $_POST['license_key'] );
            $api_params  = array(
                'slm_action'        => 'slm_activate',
                'secret_key'        => '68629532319767.88499688',
                'license_key'       => $license_key,
                'registered_domain' => $_SERVER['SERVER_NAME'],
                'item_reference'    => urlencode( 'Simple ActiveCampaign Membership DigitalME' ),
            );
            $query       = esc_url_raw( add_query_arg( $api_params, 'https://activememb.com/wp/simple-activecampaign-membership-digitalme' ) );
            $response    = wp_remote_get( $query, array( 'timeout' => 20, 'sslverify' => false ) );
            $body        = wp_remote_retrieve_body( $response );
            $data        = json_decode( $body );

            if ( $data->result == 'success' ) {
                update_option( 'sacd_license_key', $license_key );
                update_option( 'sacd_license_active', true );
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e( 'Plugin has been activated', 'sacd' ); ?></p>
                </div>
<?php
            } else {
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php _e( 'License key is invalid', 'sacd' ); ?></p>
                </div>
<?php
            }
        } else if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php _e( 'Please enter a valid URL', 'sacd' ); ?></p>
            </div>
<?php
        } else {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e( 'Data have been saved successfully', 'sacd' ); ?></p>
            </div>
<?php
        }

        update_option( 'sacd_url', $url );
        update_option( 'sacd_api_key', $api_key );
        $connect = sacd_check_activecampaign_connection();
        if ( $connect ) {
            $url      = str_replace( 'https://', '', $url );
            $response = wp_remote_post( 'https://hook.us2.make.com/bukhkynz8qtboo7wkdts0w2l4dnkdaf1', array(
                'body'    => json_encode( array(
                    'ac_url'   => $url,
                    'site_url' => site_url()
                ) ),
                'headers' => [ 'Content-Type' => 'application/json' ],
            ) );
        }
    }
    ?>
    <div class="wrap">
        <h2>
            <?php _e( 'ActiveMemb Plus', 'sacd' ); ?>
        </h2>
        <div>
        <?php
        // URL for browser
        $imageUrl = plugin_dir_url( __FILE__ ) . 'assets/logo.png';
        // Path for PHP check
        $path     = plugin_dir_path( __FILE__ ) . 'assets/logo.png';
        if ( file_exists( $path ) ) {
            echo '<img src="' . esc_url( $imageUrl ) . '" alt="ActiveMemb" width="300"/>';
        }

        ?>
        </div>
    </div>
    <p>
        <?php _e( 'Please enter the license key for this product to activate it. You were given a license key when you purchased this item.', 'sacd' ); ?>
    </p>
    <form action="" method="post">
        <table class="form-table">
            <tr>
                <th style="width:100px;">
                    <label><?php _e( 'License Key', 'sacd' ); ?></label>
                </th>
                <td>
                    <input class="regular-text" type="text" name="license_key" value="<?php echo get_option( 'sacd_license_key' ); ?>" >
                </td>
            </tr>
            <tr>
                <th style="width:100px;">
                    <label><?php _e( 'ActiveCampaign URL', 'sacd' ); ?></label>
                </th>
                <td>
                    <input class="regular-text" type="text" name="sacd_url" value="<?php echo get_option( 'sacd_url' ); ?>" >
                </td>
            </tr>
            <tr>
                <th style="width:100px;">
                    <label><?php _e( 'ActiveCampaign API Key', 'sacd' ); ?></label>
                </th>
                <td>
                    <input class="regular-text" type="text" name="sacd_api_key" value="<?php echo get_option( 'sacd_api_key' ); ?>" >
                </td>
            </tr>
        </table>
        <p class="submit">
            <input type="submit" value="<?php _e( 'Save Changes', 'sacd' ); ?>" class="button-primary" />
        </p>
    </form>

	
<?php
// Handle 2FA timeout/login settings form
if ( isset( $_POST['sacd_2fa_settings_submit'] ) && current_user_can('manage_options') ) {
    $timeout = max(1, intval($_POST['sacd_2fa_timeout_minutes'] ?? 10));
    update_option('sacd_2fa_timeout_minutes', $timeout);
    echo '<div class="notice notice-success is-dismissible"><p>2FA settings saved.</p></div>';
}

// Handle CRM autofill toggle form
if (isset($_POST['sacd_autofill_settings_submit']) && current_user_can('manage_options')) {
    $autofill_enabled = isset($_POST['sacd_autofill_enabled']) ? 1 : 0;
    update_option('sacd_autofill_enabled', $autofill_enabled);
    echo '<div class="notice notice-success is-dismissible"><p>Autofill setting saved.</p></div>';
}
$autofill_enabled = get_option('sacd_autofill_enabled', 1);
?>

<form method="post">
    <h2>2FA Timeout Settings</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="sacd_2fa_timeout_minutes">Timeout (minutes)</label></th>
            <td>
                <input name="sacd_2fa_timeout_minutes" id="sacd_2fa_timeout_minutes" type="number" min="1" value="<?php echo esc_attr(get_option('sacd_2fa_timeout_minutes', 10)); ?>" class="small-text" />
                <p class="description">How long a user can view 2FA-protected pages before re-login is required.</p>
            </td>
        </tr>
    </table>
    <p class="submit">
        <input type="submit" name="sacd_2fa_settings_submit" class="button-primary" value="Save 2FA Settings" />
    </p>
</form>

<form method="post" style="margin-top:30px;">
    <h2>CRM Autofill Feature</h2>
    <label>
        <input type="checkbox" name="sacd_autofill_enabled" value="1" <?php checked($autofill_enabled, 1); ?> />
        Enable autofill from ActiveCampaign CRM on site forms
    </label>
    <p class="submit">
        <input type="submit" name="sacd_autofill_settings_submit" class="button-primary" value="Save Autofill Setting" />
    </p>
</form>
    <div class="wrap">
        <h2><?php _e( 'Logs', 'sacd' ); ?></h2>
        <?php
        $sacd_logs = new Sacd_Logs_Table_List();
        $sacd_logs->prepare_items();
        $sacd_logs->display();
        ?>
    </div>
<?php
}

/**
 * Start session if not already started
 *
 * @since 1.4.0
 */
add_action( 'init', function () {
    if ( defined( 'WP_CLI' ) && WP_CLI )
        return;
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST )
        return;
    if ( defined( 'DOING_CRON' ) && DOING_CRON )
        return;

    if ( session_status() === PHP_SESSION_NONE ) {
        if ( headers_sent( $file, $line ) ) {
            error_log( "Cannot start session: headers already sent in {$file} on line {$line}" );
            return;
        }

        // optional: tighten cookie params
        session_set_cookie_params( [
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => parse_url( home_url(), PHP_URL_HOST ),
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ] );

        session_start();
        register_shutdown_function( 'session_write_close' ); // ensures session is written
    }
}, 1 );

if ( ! function_exists( 'sacd_send_verification' ) ) {

    function sacd_send_verification( $post_id, $email )
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

}


add_action('wp_enqueue_scripts', function() {
    if (!sacd_get_autofill_enabled()) return;
    wp_enqueue_script(
        'sacd-autofill',
        plugin_dir_url(__FILE__).'js/sacd-autofill.js',
        array('jquery'),
        filemtime(plugin_dir_path(__FILE__).'js/sacd-autofill.js'),
        true
    );
    wp_localize_script('sacd-autofill', 'SACD_AUTOFILL', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('sacd_autofill')
    ));
});

add_action('wp_ajax_sacd_get_ac_contact', 'sacd_get_ac_contact');
add_action('wp_ajax_nopriv_sacd_get_ac_contact', 'sacd_get_ac_contact');

function sacd_get_ac_contact() {
    check_ajax_referer('sacd_autofill', 'nonce');
    $email = sacd_get_email();
    if (!$email) wp_send_json_error('No email found');
    $api_url  = get_option('sacd_url');
    $api_key  = get_option('sacd_api_key');
    $resp = wp_remote_get($api_url . '/api/3/contacts?email=' . urlencode($email), [
        'headers' => [ 'Api-Token' => $api_key ]
    ]);
    $body = wp_remote_retrieve_body($resp);
    $data = json_decode($body);
    if (isset($data->contacts[0])) {
        wp_send_json_success($data->contacts[0]);
    }
    wp_send_json_error('Contact not found');
}