<?php
if ( ! class_exists( 'SACD' ) ) {

    class SACD
    {

        public function __construct()
        {

            if ( self::is_license_active() ) {
                require_once plugin_dir_path( __FILE__ ) . 'pro.php';
                add_action( 'admin_enqueue_scripts', [ $this, 'sacd_admin_enqueue_scripts' ] );
                add_action( 'wp_ajax_clear_activememb_cache_action', [ $this, 'sacd_clear_activememb_cache_handler' ] );
            } else {
                add_action( 'admin_notices', [ $this, 'sacd_admin_notices' ], 10 );
            }

            add_action( 'plugins_loaded', [ $this, 'sacd_init_woocommerce' ], 20 );
        }

        /**
         * Get the sacd token from request safely.
         *
         * Will look in $_GET, $_REQUEST, and finally parse REQUEST_URI for a sacd_token=... fragment.
         *
         * @return string|false Token string or false if not found.
         * @since 1.4.0
         */
        function sacd_get_token_from_request()
        {
            // 1) Preferred: explicit GET param
            if ( isset( $_GET['sacd-token'] ) && '' !== $_GET['sacd-token'] ) {
                return sanitize_text_field( wp_unslash( $_GET['sacd-token'] ) );
            }

            // 2) Also check $_REQUEST (covers POST or cookie in weird setups)
            if ( isset( $_REQUEST['sacd-token'] ) && '' !== $_REQUEST['sacd-token'] ) {
                return sanitize_text_field( wp_unslash( $_REQUEST['sacd-token'] ) );
            }

            // 3) Fallback: parse REQUEST_URI query string manually (useful if server mangled $_GET)
            if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
                $uri   = wp_unslash( $_SERVER['REQUEST_URI'] ); // raw URI including query
                $parts = wp_parse_url( $uri );

                if ( ! empty( $parts['query'] ) ) {
                    parse_str( $parts['query'], $qs );
                    if ( ! empty( $qs['sacd-token'] ) ) {
                        return sanitize_text_field( $qs['sacd-token'] );
                    }
                }

                // 4) Optional: token might be in the path like /verify/sacd_token/abcd
                // Use regex to find 'sacd_token/...' or 'token/...' patterns if you expect that.
                if ( ! empty( $parts['path'] ) ) {
                    if ( preg_match( '#sacd-token/([A-Za-z0-9\-_]+)#', $parts['path'], $m ) ) {
                        return sanitize_text_field( $m[1] );
                    }
                }
            }

            return false;
        }

        /**
         * Registers WooCommerce-only hooks. Called on plugins_loaded.
         * @since 1.4.0
         */
        public function sacd_init_woocommerce()
        {
            // only attach WC hooks if WooCommerce exists
            if ( ! class_exists( 'WooCommerce' ) ) {
                return;
            }

            // @since 1.4.0
            add_action( 'init', function () {

                $token = $this->sacd_get_token_from_request();

                if ( ! $token ) {
                    // no token on this request — safe early exit
                    return;
                }

                $sacd_tokens = get_option( 'sacd_tokens', [] );

                if ( isset( $_GET['sacd-token'] ) ) {

                    $email = sacd_get_email();
                    $token = sanitize_text_field( wp_unslash( $_GET['sacd-token'] ) );

                    if ( isset( $sacd_tokens[ $email ][ $token ] ) && isset( $_SESSION['sacd_emails_verified'] ) ) {

                        if ( ! $email ) {
                            exit;
                        }

                        $user = get_user_by( 'email', $email );

                        if ( ! $user ) {
                            $username = $email;
                            $user_id  = wp_create_user( $username, $username, $username );

                            if ( ! is_wp_error( $user_id ) ) {
                                // Assign WooCommerce customer role
                                $user = new WP_User( $user_id );
                                $user->set_role( 'customer' );
                                update_user_meta( $user_object->ID, 'sacd_login_token', $login_token );

                                // Log them in right away
                                wp_clear_auth_cookie();
                                wp_set_current_user( $user_id );
                                wp_set_auth_cookie( $user_id, true );
                            }
                        } else {
                            wp_clear_auth_cookie();
                            wp_set_current_user( $user->ID );
                            wp_set_auth_cookie( $user->ID, true ); // "true" keeps user logged in longer

                        }

                        wp_safe_redirect( remove_query_arg( 'sacd-token', esc_url_raw( $_SERVER['REQUEST_URI'] ) ) );
                        exit;
                    }
                }
            }, 1 );

            // Classic checkout (wrap inside a closure or call method)
            add_action( 'woocommerce_checkout_init', function () {
                add_filter( 'woocommerce_checkout_fields', [ $this, 'set_checkout_field_defaults' ], 99, 1 );
            } );

            // Blocks checkout
            add_filter(
                'woocommerce_blocks_checkout_field_data',
                [ $this, 'set_checkout_field_defaults' ],
                10,
                1
            );

            // admin CSS tweak but only when WC is active (optional: move to admin_enqueue_scripts with condition)
            add_action( 'admin_enqueue_scripts', function () {
                echo '<style>
            .toplevel_page_sacd > div.wp-menu-image.svg {
                background-size: 30px auto!important;
            }
        </style>';
            } );

            // blocks/assets
            add_action( 'enqueue_block_assets', [ $this, 'sacd_enqueue_block_assets' ] );
        }

        /**
         * Enqueue the script for prefilling checkout fields with user data.
         * This function is hooked into the 'enqueue_block_assets' action, which is triggered
         * whenever a block is rendered on the frontend.
         * It will detect if the checkout block or shortcode is present in the post content, and
         * if so, enqueue the script and pass the user's contact data to it.
         * The script will then use the contact data to prefill the checkout fields.
         */
        public function sacd_enqueue_block_assets()
        {
            global $post;

            // Only proceed if we have a post context
            if ( ! $post instanceof WP_Post ) {
                return;
            }

            // Detect checkout block or shortcode in post content
            $has_checkout =
                has_block( 'woocommerce/checkout', $post )
                || has_shortcode( $post->post_content, 'woocommerce_checkout' );

            if ( ! $has_checkout ) {
                return;
            }

            // Enqueue script (will run wherever the checkout is embedded)
            wp_enqueue_script(
                'sacd-prefill-checkout',
                plugin_dir_url( __FILE__ ) . 'js/prefill-checkout.js',
                ['jquery'],
                '1.4',
                true
            );

            // Prepare your prefill data
            $user        = wp_get_current_user();
            $user_email  = $user && $user->user_email ? $user->user_email : sacd_get_email();
            $sacd_tokens = get_option( 'sacd_tokens', [] );

            if ( ! $user_email ) {
                $user_email = do_shortcode( "[ac-contact field='email']" );
            }

            if ( ! isset( $sacd_tokens[ $user_email ] ) ) {
                $user_email = false;
            }

            if ( $user_email ) {
                $contactData = $this->sacd_get_contact_data_once( $user_email );
                $prefills    = $this->sacd_map_contact_to_prefills( $contactData, $user );
            } else {
                $prefills = [];
            }

            wp_localize_script( 'sacd-prefill-checkout', 'checkoutFieldPrefills', $prefills );
        }

        /**
         * Retrieve contact data from ActiveCampaign API
         *
         * @param string $email
         * @return object|false Contact data or false if not found
         */
        public function sacd_get_contact_data_once( $email )
        {

            // Each login session has a unique token
            $session_token = wp_get_session_token();
            $cache_key     = 'sacd_contact_data_' . md5( $session_token );

            // Check cache first
            $cached = get_transient( $cache_key );

            if ( $cached !== false ) {
                // return $cached; TODO: remove later
            }

            // Not cached → fetch from API
            $api_url = get_option( 'sacd_url' );
            $api_key = get_option( 'sacd_api_key' );

            $response = wp_remote_get( $api_url . '/api/3/contacts?email=' . rawurlencode( $email ), array(
                'headers' => array(
                    'Api-Token' => $api_key,
                ),
            ) );

            if ( is_wp_error( $response ) ) {
                return false;
            }

            $body       = wp_remote_retrieve_body( $response );
            $data       = json_decode( $body );
            $contact_id = $data->contacts[0]->id ?? null;

            if ( $contact_id ) {

                $response = wp_remote_get( "{$api_url}/api/3/contacts/{$contact_id}?include=fieldValues", [
                    'headers' => [
                        'Api-Token' => $api_key,
                    ],
                ] );

                if ( ! is_wp_error( $response ) ) {
                    $body         = wp_remote_retrieve_body( $response );
                    $contact_data = json_decode( $body );

                    $data = json_decode( wp_remote_retrieve_body( $response ) );

                    $contact      = $data->contact ?? null;
                    $field_values = $data->fieldValues ?? [];

                    $field_map = [
                        'STREET_ADDRESS_1' => 1,
                        'CITY'             => 2,
                        'STATE'            => 3,
                        'POSTCODE'         => 4,
                        'PHONE'            => 5,
                    ];

                    $custom = [];
                    foreach ( $field_values as $fv ) {
                        foreach ( $field_map as $key => $field_id ) {
                            if ( (int) $fv->field === (int) $field_id ) {
                                $custom[ strtolower( $key ) ] = $fv->value;
                            }
                        }
                    }

                    $data = [
                        'first_name'       => $contact->firstName ?? '',
                        'last_name'        => $contact->lastName ?? '',
                        'email'            => $contact->email ?? '',
                        'phone'            => $contact->phone ?? '',
                        'street_address_1' => $custom['street_address_1'] ?? '',
                        'city'             => $custom['city'] ?? '',
                        'state'            => $custom['state'] ?? '',
                        'postcode'         => $custom['postcode'] ?? '',
                    ];

                }

                set_transient( $cache_key, $data, DAY_IN_SECONDS );

                return $data;
            }

            error_log( 'No contact found for email: ' . $email );
            return false;

        }

        /**
         * Enqueue the admin script.
         *
         * The admin script is responsible for loading the JS necessary for the admin interface.
         *
         * It will load the script from the js directory, and enqueue it with the 'jquery' dependency.
         *
         * If the file does not exist, it will log an error to the error log.
         */
        public function sacd_admin_enqueue_scripts()
        {
            $script_path = plugin_dir_path( __FILE__ ) . 'js/admin-script.js';
            $script_url  = plugin_dir_url( __FILE__ ) . 'js/admin-script.js';

            if ( file_exists( $script_path ) ) {
                wp_enqueue_script(
                    'sacd-admin-script',
                    $script_url,
                    ['jquery'],
                    filemtime( $script_path ),
                    true
                );

                wp_localize_script(
                    'sacd-admin-script',
                    'sacdAdminAjax',
                    array(
                        'ajax_url' => admin_url( 'admin-ajax.php' ),
                        'nonce'    => wp_create_nonce( 'activememb_cache_ajax_nonce' )
                    )
                );

            } else {
                error_log( 'SACD: admin-script.js not found at ' . $script_path );
            }
        }

        public function sacd_clear_activememb_cache_handler()
        {

            // Check for the nonce to ensure security.
            check_ajax_referer( 'activememb_cache_ajax_nonce', 'nonce' );

            // Process the AJAX request.
            if ( ! current_user_can( 'manage_options' ) ) { // Check user permissions.
                wp_send_json_error( 'Permission denied' );
                wp_die();
            }

            global $wpdb;

            $prefixes      = [ 'sacdc_protect_content_', 'sacd_contact_data_' ];
            $total_deleted = 0;

            foreach ( $prefixes as $prefix ) {
                error_log( 'Clearing cache with prefix: ' . $prefix );

                $like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                        $like
                    )
                );

                // Delete timeouts too
                $like_timeout = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                        $like_timeout
                    )
                );

                if ( $deleted_main === false || $deleted_timeout === false ) {
                    error_log( 'SQL Error: ' . $wpdb->last_error );
                    wp_send_json_error( [
                        'message' => "Error clearing cache for prefix: {$prefix}",
                        'error'   => $wpdb->last_error,
                    ] );
                }

                $total_deleted += (int) $deleted_main + (int) $deleted_timeout;
            }

            if ( function_exists( 'wp_cache_flush' ) ) {
                wp_cache_flush(); // First attempt
            }

            // Force clear transient group if supported
            if ( isset( $wp_object_cache ) && method_exists( $wp_object_cache, 'delete' ) ) {
                $wp_object_cache->delete( $cache_key, 'transient' );
            }

            wp_send_json_success( [
                'message'      => 'Cache cleared successfully.',
                'deleted_rows' => $total_deleted,
            ] );

            wp_die(); // Always call this at the end of an AJAX handler.

        }

        /**
         * Enqueue a script to prefill checkout fields from user profile on page load.
         *
         * This function is only called when the user is logged in and on the checkout page.
         * It sends a request to the ActiveCampaign API to get the user's contact information
         * and then prefills the checkout fields with the retrieved values.
         * The script is wrapped in a try/catch block to prevent errors from breaking the page.
         * It also uses MutationObserver to observe changes to the DOM and refill the checkout
         * fields if necessary.
         */
        private function sacd_map_contact_to_prefills( $contactData, $wp_user = null )
        {
            if ( ! isset( $prefill ) || ! is_array( $prefill ) ) {
                $prefill = [];
            }

            /** Merge data from wp_user (if available) */
            if ( $wp_user && is_object( $wp_user ) ) {
                $prefill = array_merge(
                    $prefill,
                    array_filter( [
                        'first_name' => $wp_user->first_name ?? null,
                        'last_name'  => $wp_user->last_name ?? null,
                        'email'      => $wp_user->user_email ?? null,
                    ] )
                );
            }

            /** Merge data from contactData (flat array) */
            if ( isset( $contactData ) && is_array( $contactData ) && ! empty( $contactData ) ) {
                $prefill = array_merge(
                    $prefill,
                    array_filter( [
                        'first_name'       => $contactData['first_name'] ?? null,
                        'last_name'        => $contactData['last_name'] ?? null,
                        'email'            => $contactData['email'] ?? null,
                        'phone'            => $contactData['phone'] ?? null,
                        'street_address_1' => $contactData['street_address_1'] ?? $contactData['address'] ?? null,
                        'city'             => $contactData['city'] ?? null,
                        'state'            => $contactData['state'] ?? null,
                        'postcode'         => $contactData['postcode'] ?? $contactData['zip'] ?? null,
                    ] )
                );
            }

            /** Handle contactData objects (fieldValues / fields) if provided */
            if ( is_object( $contactData ) ) {
                $c = $contactData;

                // fieldValues array
                if ( isset( $c->fieldValues ) && is_array( $c->fieldValues ) ) {
                    foreach ( $c->fieldValues as $fv ) {
                        $name  = strtolower( $fv->field ?? $fv->fieldLabel ?? '' );
                        $value = $fv->value ?? '';
                        if ( $value === '' )
                            continue;

                        if ( str_contains( $name, 'address' ) || str_contains( $name, 'street' ) ) {
                            $prefill['street_address_1'] = $value;
                        } elseif ( str_contains( $name, 'city' ) ) {
                            $prefill['city'] = $value;
                        } elseif ( str_contains( $name, 'state' ) ) {
                            $prefill['state'] = $value;
                        } elseif ( str_contains( $name, 'zip' ) || str_contains( $name, 'postcode' ) ) {
                            $prefill['postcode'] = $value;
                        } elseif ( str_contains( $name, 'phone' ) ) {
                            $prefill['phone'] = $value;
                        }
                    }
                }

                // fields array
                if ( isset( $c->fields ) && is_array( $c->fields ) ) {
                    foreach ( $c->fields as $fld ) {
                        $label = strtolower( $fld->title ?? $fld->label ?? '' );
                        $val   = $fld->value ?? '';
                        if ( $val === '' )
                            continue;

                        if ( str_contains( $label, 'address' ) || str_contains( $label, 'street' ) ) {
                            $prefill['street_address_1'] = $val;
                        } elseif ( str_contains( $label, 'city' ) ) {
                            $prefill['city'] = $val;
                        } elseif ( str_contains( $label, 'state' ) ) {
                            $prefill['state'] = $val;
                        } elseif ( str_contains( $label, 'zip' ) || str_contains( $label, 'postcode' ) ) {
                            $prefill['postcode'] = $val;
                        }
                    }
                }
            }

            return $prefill;
        }

        // 3) Provide server-side defaults using woocommerce_checkout_fields (classic checkout)
        public function set_checkout_field_defaults( $fields )
        {

            if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
                return $fields;
            }

            $user       = wp_get_current_user();
            $user_email = $user && $user->user_email ? $user->user_email : sacd_get_email();

            if ( ! $user_email ) {
                $user_email = do_shortcode( "[ac-contact field='email']" );
            }

            // Use existing helper to fetch contact data (should be cached inside)
            $contactData = $this->sacd_get_contact_data_once( $user_email );
            $prefills    = $this->sacd_map_contact_to_prefills( $contactData, $user );

            // Billing Fields
            if ( ! empty( $prefills['first_name'] ) ) {
                $fields['billing']['billing_first_name']['default'] = $prefills['first_name'];
            }
            if ( ! empty( $prefills['last_name'] ) ) {
                $fields['billing']['billing_last_name']['default'] = $prefills['last_name'];
            }
            if ( ! empty( $prefills['email'] ) ) {
                $fields['billing']['billing_email']['default'] = $prefills['email'];
            }
            if ( ! empty( $prefills['phone'] ) ) {
                $fields['billing']['billing_phone']['default'] = $prefills['phone'];
            }
            if ( ! empty( $prefills['street_address_1'] ) ) {
                $fields['billing']['billing_address_1']['default'] = $prefills['street_address_1'];
            }
            if ( ! empty( $prefills['city'] ) ) {
                $fields['billing']['billing_city']['default'] = $prefills['city'];
            }
            if ( ! empty( $prefills['state'] ) ) {
                $fields['billing']['billing_state']['default'] = $prefills['state'];
            }
            if ( ! empty( $prefills['postcode'] ) ) {
                $fields['billing']['billing_postcode']['default'] = $prefills['postcode'];
            }

            // Shipping Fields
            if ( ! empty( $prefills['first_name'] ) ) {
                $fields['shipping']['shipping_first_name']['default'] = $prefills['first_name'];
            }
            if ( ! empty( $prefills['last_name'] ) ) {
                $fields['shipping']['shipping_last_name']['default'] = $prefills['last_name'];
            }
            if ( ! empty( $prefills['phone'] ) ) {
                $fields['shipping']['shipping_phone']['default'] = $prefills['phone'];
            }
            if ( ! empty( $prefills['street_address_1'] ) ) {
                $fields['shipping']['shipping_address_1']['default'] = $prefills['street_address_1'];
            }
            if ( ! empty( $prefills['city'] ) ) {
                $fields['shipping']['shipping_city']['default'] = $prefills['city'];
            }
            if ( ! empty( $prefills['state'] ) ) {
                $fields['shipping']['shipping_state']['default'] = $prefills['state'];
            }
            if ( ! empty( $prefills['postcode'] ) ) {
                $fields['shipping']['shipping_postcode']['default'] = $prefills['postcode'];
            }

            return $fields;
        }

        /**
         * Checks if the license for the plugin is active.
         *
         * @return bool True if the license is active, false otherwise.
         */
        private function is_license_active()
        {
            return get_option( 'sacd_license_active', false );
        }

        public function sacd_admin_notices()
        {
            $path = plugin_dir_path( __FILE__ ) . 'assets/logo.svg';

            if ( file_exists( $path ) ) {
                $icon_svg      = 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( $path ) );
                // Show the actual SVG image
                $icon_svg_html = '<img src="' . esc_attr( $icon_svg ) . '" alt="Logo" style="height:35px;vertical-align:middle;margin-right:5px;">';
            } else {
                error_log( 'Logo not found at: ' . $path );
                $icon_svg_html = '<span class="dashicons dashicons-admin-generic"></span>'; // fallback icon
            }

            echo '<div class="notice notice-error" style="padding:20px 10px;display:flex;flex-direction:row;gap:15px;">' .
                $icon_svg_html . '
                <div><strong>ActiveMemb Pro:</strong> Please activate your license to enable all features.
                <br/> <a href="' . admin_url( 'admin.php?page=sacd' ) . '">Activate License</a></div>
            </div>';
        }

    }

    // instantiate
    new SACD();
}
