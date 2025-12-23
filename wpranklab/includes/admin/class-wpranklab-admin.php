<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin functionality.
 */
class WPRankLab_Admin {

    /**
     * Settings.
     *
     * @var array
     */
    protected $settings;

    /**
     * License.
     *
     * @var array
     */
    protected $license;

    /**
     * Init admin hooks.
     */
    public function init() {
        $this->settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
        $this->license  = get_option( WPRANKLAB_OPTION_LICENSE, array() );

        add_action( 'admin_menu', array( $this, 'register_menus' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Manual license check handler.
        add_action( 'admin_post_wpranklab_check_license', array( $this, 'handle_check_license' ) );

        // Post editor metabox.
        add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );

        // Manual post-level scan.
        add_action( 'admin_post_wpranklab_scan_post', array( $this, 'handle_scan_post' ) );

        // Global scan for all content.
        add_action( 'admin_post_wpranklab_scan_all', array( $this, 'handle_scan_all' ) );

		        // AI generation: summary + Q&A.
        add_action( 'admin_post_wpranklab_generate_summary', array( $this, 'handle_generate_summary' ) );
        add_action( 'admin_post_wpranklab_generate_qa', array( $this, 'handle_generate_qa' ) );
        add_action( 'admin_post_wpranklab_insert_summary', array( $this, 'handle_insert_summary' ) );
        add_action( 'admin_post_wpranklab_insert_qa', array( $this, 'handle_insert_qa' ) );
        
        add_action(
            'admin_post_wpranklab_insert_missing_topic',
            array( $this, 'handle_insert_missing_topic' )
            );
        
        add_action( 'wp_ajax_wpranklab_missing_topic_section', array( $this, 'ajax_missing_topic_section' ) );
        
        add_action( 'admin_post_wpranklab_toggle_schema', array( $this, 'handle_toggle_schema' ) );
        
        add_action( 'admin_post_wpranklab_insert_internal_link', array( $this, 'handle_insert_internal_link' ) );
        
        add_action( 'wp_ajax_wpranklab_internal_link_block', array( $this, 'ajax_internal_link_block' ) );
        
        add_action( 'admin_notices', array( $this, 'maybe_show_internal_link_notice' ) );

        // Dev helper: add backdated score snapshots for testing weekly deltas (no OpenAI calls).
        add_action( 'admin_post_wpranklab_add_test_snapshot', array( $this, 'handle_add_test_snapshot' ) );
        
        add_action( 'wp_ajax_wpranklab_show_license_form', function () {
            check_ajax_referer( 'wpranklab_license_nonce' );
            update_option( 'wpranklab_show_license_form', 1 );
            wp_send_json_success();
        });
            

    }

    /**
     * Dev helper to create a (backdated) history snapshot without calling OpenAI.
     *
     * URL: /wp-admin/admin-post.php?action=wpranklab_add_test_snapshot&post_id=123&days=7&_wpnonce=...
     */
    public function handle_add_test_snapshot() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'wpranklab' ) );
        }

        check_admin_referer( 'wpranklab_add_test_snapshot' );

        $post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
        $days    = isset( $_GET['days'] ) ? (int) $_GET['days'] : 0;
        $days    = max( 0, min( 60, $days ) );

        if ( ! $post_id || ! get_post( $post_id ) ) {
            wp_die( esc_html__( 'Invalid post.', 'wpranklab' ) );
        }

        // Only allow in dev mode (prevents accidental data pollution on prod sites).
        $settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
        if ( empty( $settings['dev_mode'] ) ) {
            wp_die( esc_html__( 'Dev Mode is disabled.', 'wpranklab' ) );
        }

        $score = get_post_meta( $post_id, '_wpranklab_visibility_score', true );
        $score = is_numeric( $score ) ? (int) $score : rand( 20, 95 );

        $date = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

        $key     = '_wpranklab_visibility_history';
        $history = get_post_meta( $post_id, $key, true );

        // Support history stored either as PHP array (preferred) or JSON string (back-compat).
        if ( is_string( $history ) && '' !== $history ) {
            $decoded = json_decode( $history, true );
            if ( is_array( $decoded ) ) {
                $history = $decoded;
            }
        }
        if ( ! is_array( $history ) ) {
            $history = array();
        }

        // Index by date to ensure 1 snapshot per day.
        $by_date = array();
        foreach ( $history as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $d = isset( $row['date'] ) ? (string) $row['date'] : '';
            $s = isset( $row['score'] ) ? (int) $row['score'] : null;
            if ( '' === $d || null === $s ) {
                continue;
            }
            $by_date[ $d ] = array( 'date' => $d, 'score' => $s );
        }

        $by_date[ $date ] = array( 'date' => $date, 'score' => $score );
        ksort( $by_date );

        $history = array_values( $by_date );
        if ( count( $history ) > 60 ) {
            $history = array_slice( $history, -60 );
        }

        update_post_meta( $post_id, $key, $history );

        // Redirect back to editor with a friendly notice flag.
        $url = add_query_arg(
            array(
                'post'                 => $post_id,
                'action'               => 'edit',
                'wpranklab_hist_test'   => '1',
                'wpranklab_hist_days'   => $days,
            ),
            admin_url( 'post.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Register admin menus.
     */
    public function register_menus() {
        $cap = 'manage_options';

        add_menu_page(
            __( 'WPRankLab', 'wpranklab' ),
            __( 'WPRankLab', 'wpranklab' ),
            $cap,
            'wpranklab',
            array( $this, 'render_dashboard_page' ),
            'dashicons-chart-line',
            59
        );

        add_submenu_page(
            'wpranklab',
            __( 'Settings', 'wpranklab' ),
            __( 'Settings', 'wpranklab' ),
            $cap,
            'wpranklab-settings',
            array( $this, 'render_settings_page' )
        );

        add_submenu_page(
            'wpranklab',
            __( 'License', 'wpranklab' ),
            __( 'License', 'wpranklab' ),
            $cap,
            'wpranklab-license',
            array( $this, 'render_license_page' )
        );

        add_submenu_page(
            'wpranklab',
            __( 'Upgrade to Pro', 'wpranklab' ),
            __( 'Upgrade to Pro', 'wpranklab' ),
            $cap,
            'wpranklab-upgrade',
            array( $this, 'render_upgrade_page' )
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        // General settings.
        register_setting(
            'wpranklab_settings_group',
            WPRANKLAB_OPTION_SETTINGS,
            array( $this, 'sanitize_settings' )
        );

        add_settings_section(
            'wpranklab_settings_main',
            __( 'General Settings', 'wpranklab' ),
            '__return_false',
            'wpranklab-settings'
        );

        add_settings_field(
            'wpranklab_openai_api_key',
            __( 'OpenAI API Key', 'wpranklab' ),
            array( $this, 'field_openai_api_key' ),
            'wpranklab-settings',
            'wpranklab_settings_main'
        );

        // Development controls (to reduce API usage during testing).
        add_settings_field(
            'wpranklab_dev_mode',
            __( 'Development Mode (No API Calls)', 'wpranklab' ),
            array( $this, 'field_dev_mode' ),
            'wpranklab-settings',
            'wpranklab_settings_main'
        );

        add_settings_field(
            'wpranklab_ai_scan_mode',
            __( 'AI Scan Mode', 'wpranklab' ),
            array( $this, 'field_ai_scan_mode' ),
            'wpranklab-settings',
            'wpranklab_settings_main'
        );

        add_settings_field(
            'wpranklab_ai_cache_minutes',
            __( 'AI Response Cache (minutes)', 'wpranklab' ),
            array( $this, 'field_ai_cache_minutes' ),
            'wpranklab-settings',
            'wpranklab_settings_main'
        );

        add_settings_field(
            'wpranklab_weekly_email',
            __( 'Weekly Report Emails', 'wpranklab' ),
            array( $this, 'field_weekly_email' ),
            'wpranklab-settings',
            'wpranklab_settings_main'
        );

        // License settings.
        register_setting(
            'wpranklab_license_group',
            WPRANKLAB_OPTION_LICENSE,
            array( $this, 'sanitize_license' )
        );
    }

    /**
     * Sanitize general settings.
     */
    public function sanitize_settings( $input ) {
        $output = $this->settings;

        $output['openai_api_key'] = isset( $input['openai_api_key'] )
            ? sanitize_text_field( $input['openai_api_key'] )
            : '';

        $output['weekly_email'] = isset( $input['weekly_email'] ) ? (int) $input['weekly_email'] : 0;

        // Dev/testing.
        $output['dev_mode'] = isset( $input['dev_mode'] ) ? (int) $input['dev_mode'] : 0;

        $mode = isset( $input['ai_scan_mode'] ) ? sanitize_text_field( (string) $input['ai_scan_mode'] ) : 'full';
        if ( ! in_array( $mode, array( 'quick', 'full' ), true ) ) {
            $mode = 'full';
        }
        $output['ai_scan_mode'] = $mode;

        $cache_minutes = isset( $input['ai_cache_minutes'] ) ? (int) $input['ai_cache_minutes'] : 0;
        if ( $cache_minutes < 0 ) {
            $cache_minutes = 0;
        }
        // Reasonable upper bound (1 week) to avoid accidental "forever" caching.
        if ( $cache_minutes > 10080 ) {
            $cache_minutes = 10080;
        }
        $output['ai_cache_minutes'] = $cache_minutes;

        return $output;
    }

    /**
     * Dev Mode field (disables OpenAI calls and uses fixtures).
     */
    public function field_dev_mode() {
        $value = isset( $this->settings['dev_mode'] ) ? (int) $this->settings['dev_mode'] : 0;
        ?>
        <label>
            <input type="checkbox"
                   id="wpranklab_dev_mode"
                   name="<?php echo esc_attr( WPRANKLAB_OPTION_SETTINGS ); ?>[dev_mode]"
                   value="1" <?php checked( 1, $value ); ?> />
            <?php esc_html_e( 'Enable fixture responses (no OpenAI API calls). Great for UI + storage testing.', 'wpranklab' ); ?>
        </label>
        <?php
    }

    /**
     * AI Scan Mode field.
     */
    public function field_ai_scan_mode() {
        $value = isset( $this->settings['ai_scan_mode'] ) ? (string) $this->settings['ai_scan_mode'] : 'full';
        ?>
        <select id="wpranklab_ai_scan_mode" name="<?php echo esc_attr( WPRANKLAB_OPTION_SETTINGS ); ?>[ai_scan_mode]">
            <option value="quick" <?php selected( 'quick', $value ); ?>><?php esc_html_e( 'Quick (lower tokens)', 'wpranklab' ); ?></option>
            <option value="full" <?php selected( 'full', $value ); ?>><?php esc_html_e( 'Full (best quality)', 'wpranklab' ); ?></option>
        </select>
        <p class="description"><?php esc_html_e( 'Quick mode reduces max tokens to help avoid rate limits during development.', 'wpranklab' ); ?></p>
        <?php
    }

    /**
     * AI response cache minutes.
     */
    public function field_ai_cache_minutes() {
        $value = isset( $this->settings['ai_cache_minutes'] ) ? (int) $this->settings['ai_cache_minutes'] : 0;
        ?>
        <input type="number"
               id="wpranklab_ai_cache_minutes"
               name="<?php echo esc_attr( WPRANKLAB_OPTION_SETTINGS ); ?>[ai_cache_minutes]"
               value="<?php echo esc_attr( $value ); ?>"
               min="0" max="10080" step="1" style="width:100px;" />
        <p class="description"><?php esc_html_e( 'Cache identical AI requests for this many minutes (0 disables caching).', 'wpranklab' ); ?></p>
        <?php
    }

    /**
     * Sanitize license settings.
     *
     * Only sanitizes and resets status when key changes.
     * Remote validation is handled separately by the License Manager (cron/manual).
     */
    public function sanitize_license( $input ) {
        $output = $this->license;

        $current_key = isset( $this->license['license_key'] ) ? $this->license['license_key'] : '';
        $new_key = isset( $input['license_key'] ) ? sanitize_text_field( $input['license_key'] ) : '';

        if ( $new_key !== $current_key ) {
            $output['license_key']        = $new_key;
            $output['status']             = 'inactive';
            $output['expires_at']         = '';
            $output['allowed_version']    = '';
            $output['bound_domain']       = '';
            $output['kill_switch_active'] = 0;
            $output['last_check']         = 0;
        }

        return $output;
    }

    /**
     * Manual "Check License Now" handler.
     */
    public function handle_check_license() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'wpranklab' ) );
        }

        check_admin_referer( 'wpranklab_check_license' );

        $code   = 'unknown';
        $status = 'inactive';

        if ( class_exists( 'WPRankLab_License_Manager' ) ) {
            $manager = WPRankLab_License_Manager::get_instance();
            $license = $manager->validate_license( true );
            $status  = isset( $license['status'] ) ? $license['status'] : 'inactive';
            $kill    = ! empty( $license['kill_switch_active'] );

            if ( empty( $license['license_key'] ) ) {
                $code = 'no-key';
            } elseif ( $kill ) {
                $code = 'kill';
            } elseif ( 'active' === $status ) {
                $code = 'active';
            } else {
                $code = 'not-active';
            }
        } else {
            $code = 'no-manager';
        }

        $redirect = add_query_arg(
            array(
                'page'              => 'wpranklab-license',
                'wpranklab_check'   => $code,
                'wpranklab_status'  => $status,
            ),
            admin_url( 'admin.php' )
        );

        wp_redirect( $redirect );
        exit;
    }

    /**
     * Enqueue admin CSS/JS.
     */
    public function enqueue_assets( $hook ) {
        // Load on WPRankLab pages.
        $is_wpranklab_screen = ( strpos( $hook, 'wpranklab' ) !== false );

        // Load on post editor screens where the metabox appears.
        $is_post_editor = in_array( $hook, array( 'post.php', 'post-new.php' ), true );

        if ( ! $is_wpranklab_screen && ! $is_post_editor ) {
            return;
        }

        wp_enqueue_style(
            'wpranklab-admin',
            WPRANKLAB_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WPRANKLAB_VERSION
        );

        wp_enqueue_script(
            'wpranklab-admin',
            WPRANKLAB_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WPRANKLAB_VERSION,
            true
        );
        
        wp_localize_script(
            'wpranklab-admin',
            'wpranklabAdmin',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wpranklab_missing_topic_section' ),
            )
            );
        
    }

    /**
     * Dashboard page.
     */
        /**
     * Dashboard page.
     */
    public function render_dashboard_page() {
        $scan_done  = isset( $_GET['wpranklab_scan_all'] ) && 'done' === $_GET['wpranklab_scan_all'];
        $scan_count = isset( $_GET['wpranklab_scan_count'] ) ? (int) $_GET['wpranklab_scan_count'] : 0;
        ?>
        <div class="wrap wpranklab-wrap">
            <h1><?php esc_html_e( 'WPRankLab – AI Visibility Overview', 'wpranklab' ); ?></h1>

            <?php if ( $scan_done ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        printf(
                            esc_html__( 'AI Visibility scan completed for %d items.', 'wpranklab' ),
                            $scan_count
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <p><?php esc_html_e( 'This dashboard will evolve to show your AI Visibility Score, trends, and top recommendations. For now you can trigger a full-site scan to populate scores for all posts and pages.', 'wpranklab' ); ?></p>

            <?php if ( wpranklab_is_pro_active() ) : ?>
                <p><strong><?php esc_html_e( 'Pro license is active. Pro features will be enabled as they are implemented.', 'wpranklab' ); ?></strong></p>
            <?php else : ?>
                <p><strong><?php esc_html_e( 'You are currently using the Free plan or your Pro license is not active.', 'wpranklab' ); ?></strong></p>
            <?php endif; ?>

            <hr />

            <h2><?php esc_html_e( 'Scan All Content', 'wpranklab' ); ?></h2>
            <p><?php esc_html_e( 'Run an AI Visibility scan for all supported post types (posts and pages by default). This may take a moment on large sites.', 'wpranklab' ); ?></p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wpranklab_scan_all' ); ?>
                <input type="hidden" name="action" value="wpranklab_scan_all" />
                <?php submit_button( __( 'Scan All Content Now', 'wpranklab' ), 'primary', 'wpranklab_scan_all_btn', false ); ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Recent AI Visibility History', 'wpranklab' ); ?></h2>
            <p><?php esc_html_e( 'These snapshots are taken weekly and summarize your site-wide AI Visibility.', 'wpranklab' ); ?></p>

            <?php
            if ( class_exists( 'WPRankLab_History' ) ) {
                $history = WPRankLab_History::get_instance();
                
                $is_pro = function_exists( 'wpranklab_is_pro_active' ) && wpranklab_is_pro_active();
                
                // Free: fetch extra rows so we can show a locked teaser.
                // Pro: fetch unlimited.
                $rows = $is_pro
                ? $history->get_recent_snapshots()     // unlimited
                : $history->get_recent_snapshots(12);  // fetch more than 4 so teaser exists
            } else {
                $rows   = array();
                $is_pro = false;
            }
            

            if ( ! empty( $rows ) ) : 
            
            $visible_rows = $is_pro ? $rows : array_slice( $rows, 0, 4 );
            
            // show up to 4 locked rows as teaser
            $locked_rows  = $is_pro ? array() : array_slice( $rows, 4, 4 );
            
            
            
            ?>
            
            
            
                <table class="widefat striped" style="max-width: 600px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'wpranklab' ); ?></th>
                            <th><?php esc_html_e( 'Avg Score', 'wpranklab' ); ?></th>
                            <th><?php esc_html_e( 'Scanned Items', 'wpranklab' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $visible_rows as $row ) : ?>

                            <tr>
                                <td><?php echo esc_html( $row['snapshot_date'] ); ?></td>
                                <td>
                                    <?php
                                    echo is_null( $row['avg_score'] )
                                        ? esc_html__( 'N/A', 'wpranklab' )
                                        : esc_html( round( $row['avg_score'], 1 ) );
                                    ?>
                                </td>
                                <td><?php echo (int) $row['scanned_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ( ! $is_pro && ! empty( $locked_rows ) ) : ?>
    <div class="wpranklab-locked-history" style="position:relative; max-width:600px; margin-top:12px;">
        <div style="filter: blur(2.5px); pointer-events:none; user-select:none;">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'wpranklab' ); ?></th>
                        <th><?php esc_html_e( 'Avg Score', 'wpranklab' ); ?></th>
                        <th><?php esc_html_e( 'Scanned Items', 'wpranklab' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $locked_rows as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row['snapshot_date'] ); ?></td>
                            <td>
                                <?php
                                echo is_null( $row['avg_score'] )
                                    ? esc_html__( 'N/A', 'wpranklab' )
                                    : esc_html( round( $row['avg_score'], 1 ) );
                                ?>
                            </td>
                            <td><?php echo (int) $row['scanned_count']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="wpranklab-locked-overlay"
             style="position:absolute; inset:0; background:rgba(255,255,255,.86); display:flex; align-items:center; justify-content:center; flex-direction:column; gap:8px;">
            <p style="margin:0;"><strong><?php esc_html_e( 'Upgrade to Pro to unlock full history', 'wpranklab' ); ?></strong></p>
            <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wpranklab-upgrade' ) ); ?>">
                <?php esc_html_e( 'Upgrade to Pro', 'wpranklab' ); ?>
            </a>
        </div>
    </div>
<?php endif; ?>
                
                
                
            <?php else : ?>
                <p><?php esc_html_e( 'No history available yet. The weekly snapshot will be recorded automatically, or you can trigger a site-wide scan to collect scores.', 'wpranklab' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
    /**
     * Settings page.
     */
    public function render_settings_page() {
        $this->settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
        ?>
        <div class="wrap wpranklab-wrap">
            <h1><?php esc_html_e( 'WPRankLab Settings', 'wpranklab' ); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'wpranklab_settings_group' );
                do_settings_sections( 'wpranklab-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php 
    }

    /**
     * License page.
     */
    public function render_license_page() {
        $this->license = get_option( WPRANKLAB_OPTION_LICENSE, array() );
        $status = isset( $this->license['status'] ) ? $this->license['status'] : 'inactive';
        $has_key   = ! empty( $this->license['license_key'] );
        $show_form = isset( $_GET['wpranklab_show_license_form'] ) && '1' === (string) sanitize_text_field( wp_unslash( $_GET['wpranklab_show_license_form'] ) );
        ?>
        <div class="wrap wpranklab-wrap">
            <h1><?php esc_html_e( 'WPRankLab License', 'wpranklab' ); ?></h1>

            <?php
            // Show success message when settings are saved.
            if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php esc_html_e( 'License settings saved.', 'wpranklab' ); ?>
                        <?php
                        if ( ! empty( $this->license['license_key'] ) ) {
                            printf(
                                ' %s <strong>%s</strong>.',
                                esc_html__( 'Current status:', 'wpranklab' ),
                                esc_html( $status )
                            );
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            // Show result of "Check License Now".
            if ( isset( $_GET['wpranklab_check'] ) ) {
                $check_code  = sanitize_text_field( wp_unslash( $_GET['wpranklab_check'] ) );
                $check_status = isset( $_GET['wpranklab_status'] ) ? sanitize_text_field( wp_unslash( $_GET['wpranklab_status'] ) ) : $status;

                if ( 'active' === $check_code ) : ?>
                    <div class="notice notice-success is-dismissible">
                        <p>
                            <?php esc_html_e( 'License validated successfully. Pro features are enabled as long as the license remains active.', 'wpranklab' ); ?>
                            <?php
                            printf(
                                ' %s <strong>%s</strong>.',
                                esc_html__( 'Status:', 'wpranklab' ),
                                esc_html( $check_status )
                            );
                            ?>
                        </p>
                    </div>
                <?php elseif ( 'no-key' === $check_code ) : ?>
                    <div class="notice notice-warning is-dismissible">
                        <p><?php esc_html_e( 'No license key entered. Please enter a license key before checking.', 'wpranklab' ); ?></p>
                    </div>
                <?php elseif ( 'kill' === $check_code ) : ?>
                    <div class="notice notice-error is-dismissible">
                        <p><?php esc_html_e( 'The license server has activated a kill-switch for this license. All Pro features are disabled.', 'wpranklab' ); ?></p>
                    </div>
                <?php elseif ( 'not-active' === $check_code ) : ?>
                    <div class="notice notice-error is-dismissible">
                        <p>
                            <?php esc_html_e( 'The license could not be validated as active. Please check your key or contact support.', 'wpranklab' ); ?>
                            <?php
                            printf(
                                ' %s <strong>%s</strong>.',
                                esc_html__( 'Status:', 'wpranklab' ),
                                esc_html( $check_status )
                            );
                            ?>
                        </p>
                    </div>
                <?php elseif ( 'no-manager' === $check_code ) : ?>
                    <div class="notice notice-error is-dismissible">
                        <p><?php esc_html_e( 'License manager is not available. Please check the plugin files.', 'wpranklab' ); ?></p>
                    </div>
                <?php endif;
            }
            ?>

            <?php if ( ! $has_key && ! $show_form ) : ?>
                <p><?php esc_html_e( 'You are on the Free plan. Free does not require a license key (Yoast-style).', 'wpranklab' ); ?></p>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wpranklab-upgrade' ) ); ?>">
                        <?php esc_html_e( 'Upgrade to Pro', 'wpranklab' ); ?>
                    </a>
                    <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wpranklab-license', 'wpranklab_show_license_form' => '1' ), admin_url( 'admin.php' ) ) ); ?>" style="margin-left:8px;">
                        <?php esc_html_e( 'I have a license key', 'wpranklab' ); ?>
                    </a>
                </p>
            <?php else : ?>
                <p><?php esc_html_e( 'Enter your license key to activate WPRankLab Pro features.', 'wpranklab' ); ?></p>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'wpranklab_license_group' );
                ?>
                <table class="form-table">
                    
                   
                  <?php  if ( ! wpranklab_should_show_license_form() ) : ?>
    <p>
        <?php esc_html_e( 'You are using the Free version of WPRankLab.', 'wpranklab' ); ?>
    </p>

    <p>
        <a href="https://your-upgrade-url"
           class="button button-primary">
            <?php esc_html_e( 'Upgrade to Pro', 'wpranklab' ); ?>
        </a>

        <button type="button"
                class="button"
                id="wpranklab-show-license-form">
            <?php esc_html_e( 'I already have a license key', 'wpranklab' ); ?>
        </button>
    </p>
<?php endif; ?>
                   
                   
                   <?php if ( wpranklab_should_show_license_form() ) : ?> 
                    <tr>
                        <th scope="row">
                            <label for="wpranklab_license_key"><?php esc_html_e( 'License Key', 'wpranklab' ); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="wpranklab_license_key"
                                   name="<?php echo esc_attr( WPRANKLAB_OPTION_LICENSE ); ?>[license_key]"
                                   value="<?php echo isset( $this->license['license_key'] ) ? esc_attr( $this->license['license_key'] ) : ''; ?>"
                                   class="regular-text" />
                            <?php if ( ! empty( $status ) ) : ?>
                                <p class="description">
                                    <?php
                                    printf(
                                        esc_html__( 'Current status: %s', 'wpranklab' ),
                                        esc_html( $status )
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <?php endif; ?>
                
                
                </table>

                <?php submit_button( __( 'Save License', 'wpranklab' ) ); ?>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;">
                <?php wp_nonce_field( 'wpranklab_check_license' ); ?>
                <input type="hidden" name="action" value="wpranklab_check_license" />
                <?php submit_button( __( 'Check License Now', 'wpranklab' ), 'secondary', 'wpranklab_check_license_btn', false ); ?>
            </form>

            <p><em><?php esc_html_e( 'License validation uses the configured license server endpoint. Pro features are only available while the license is active and not kill-switched.', 'wpranklab' ); ?></em></p>
        </div>
    
    <?php // --- WPRankLab: reveal license form (Yoast-style) --- ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('wpranklab-show-license-form');
    if (!btn) return;

    btn.addEventListener('click', function () {
        const data = new URLSearchParams();
        data.append('action', 'wpranklab_show_license_form');
        data.append('_wpnonce', '<?php echo wp_create_nonce('wpranklab_license_nonce'); ?>');

        fetch(ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        }).then(() => {
            window.location.reload();
        });
    });
});
</script>
<?php
// --- end reveal license form ---
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
       
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    }

    /**
     * Upgrade page.
     */
    public function render_upgrade_page() {
        ?>
        <div class="wrap wpranklab-wrap">
            <h1><?php esc_html_e( 'Upgrade to WPRankLab Pro', 'wpranklab' ); ?></h1>
            <p><?php esc_html_e( 'Pro unlocks deep AI visibility analysis, historical data, automated summaries, Q&A blocks, and more.', 'wpranklab' ); ?></p>
            <p>
                <a href="https://wpranklab.com/" target="_blank" class="button button-primary">
                    <?php esc_html_e( 'Go to WPRankLab Pro Website', 'wpranklab' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Field: OpenAI API key.
     */
    public function field_openai_api_key() {
        $value = isset( $this->settings['openai_api_key'] ) ? $this->settings['openai_api_key'] : '';
        ?>
        <input type="password"
               id="wpranklab_openai_api_key"
               name="<?php echo esc_attr( WPRANKLAB_OPTION_SETTINGS ); ?>[openai_api_key]"
               value="<?php echo esc_attr( $value ); ?>"
               class="regular-text" />
        <p class="description">
            <?php esc_html_e( 'Enter your OpenAI API key. This will be used to generate AI summaries, Q&A blocks, and recommendations.', 'wpranklab' ); ?>
        </p>
        <?php
    }

    /**
     * Field: weekly email toggle.
     */
    public function field_weekly_email() {
        $enabled = isset( $this->settings['weekly_email'] ) ? (int) $this->settings['weekly_email'] : 0;
        ?>
        <label>
            <input type="checkbox"
                   name="<?php echo esc_attr( WPRANKLAB_OPTION_SETTINGS ); ?>[weekly_email]"
                   value="1" <?php checked( $enabled, 1 ); ?> />
            <?php esc_html_e( 'Send weekly AI Visibility report emails.', 'wpranklab' ); ?>
        </label>
        <?php
    }

    /**
     * Register AI Visibility metabox.
     */
    public function register_meta_boxes() {
        $post_types = apply_filters(
            'wpranklab_meta_box_post_types',
            array( 'post', 'page' )
        );

        foreach ( $post_types as $screen ) {
            add_meta_box(
                'wpranklab_ai_visibility',
                __( 'WPRankLab AI Visibility', 'wpranklab' ),
                array( $this, 'render_ai_visibility_metabox' ),
                $screen,
                'side',
                'high'
            );
        }
    }

  /**
     * Render AI Visibility metabox in the post editor.
     *
     * @param WP_Post $post
     */
    public function render_ai_visibility_metabox( $post ) {
        $score    = get_post_meta( $post->ID, '_wpranklab_visibility_score', true );
        $last_run = get_post_meta( $post->ID, '_wpranklab_visibility_last_run', true );
        $metrics  = get_post_meta( $post->ID, '_wpranklab_visibility_data', true );
        if ( ! is_array( $metrics ) ) {
            $metrics = array();
        }

        $score_int = is_numeric( $score ) ? (int) $score : null;

        $color_class = 'wpranklab-score-neutral';
        $label       = __( 'Not scanned yet', 'wpranklab' );

        if ( null !== $score_int ) {
            if ( $score_int >= 80 ) {
                $color_class = 'wpranklab-score-green';
                $label       = __( 'Great for AI', 'wpranklab' );
            } elseif ( $score_int >= 50 ) {
                $color_class = 'wpranklab-score-orange';
                $label       = __( 'Needs improvement', 'wpranklab' );
            } else {
                $color_class = 'wpranklab-score-red';
                $label       = __( 'Low AI visibility', 'wpranklab' );
            }
        }

        $scan_done = isset( $_GET['wpranklab_scan'] ) && '1' === $_GET['wpranklab_scan'];

        // AI-generated content.
        $ai_summary = get_post_meta( $post->ID, '_wpranklab_ai_summary', true );
        $ai_qa      = get_post_meta( $post->ID, '_wpranklab_ai_qa_block', true );

        $is_pro   = function_exists( 'wpranklab_is_pro_active' ) && wpranklab_is_pro_active();
        $settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
        $has_key  = ! empty( $settings['openai_api_key'] );
        $is_dev_mode = ! empty( $settings['dev_mode'] );

        // Per-post score history for weekly trend + deltas.
        $history_raw = get_post_meta( $post->ID, '_wpranklab_visibility_history', true );
        $history     = array();
        if ( is_array( $history_raw ) ) {
            $history = $history_raw;
        } elseif ( is_string( $history_raw ) && '' !== $history_raw ) {
            // Back-compat if stored as JSON string.
            $decoded = json_decode( $history_raw, true );
            if ( is_array( $decoded ) ) {
                $history = $decoded;
            }
        }

        // Normalize and sort by date ASC.
        $by_date = array();
        foreach ( (array) $history as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $d = isset( $row['date'] ) ? (string) $row['date'] : '';
            $s = isset( $row['score'] ) ? (int) $row['score'] : null;
            if ( '' === $d || null === $s ) {
                continue;
            }
            $by_date[ $d ] = array( 'date' => $d, 'score' => $s );
        }
        ksort( $by_date );
        $history = array_values( $by_date );

        // Compute deltas.
        $delta_last = null;
        $delta_week = null;
        if ( count( $history ) >= 2 ) {
            $last = $history[ count( $history ) - 1 ];
            $prev = $history[ count( $history ) - 2 ];
            $delta_last = (int) $last['score'] - (int) $prev['score'];

            // Find closest snapshot at least 7 days older than last.
            $last_date_ts = strtotime( $last['date'] . ' 00:00:00' );
            $cutoff_ts    = $last_date_ts ? ( $last_date_ts - ( 7 * DAY_IN_SECONDS ) ) : null;
            if ( $cutoff_ts ) {
                $candidate = null;
                foreach ( $history as $row ) {
                    $ts = strtotime( $row['date'] . ' 00:00:00' );
                    if ( $ts && $ts <= $cutoff_ts ) {
                        $candidate = $row;
                    }
                }
                if ( $candidate ) {
                    $delta_week = (int) $last['score'] - (int) $candidate['score'];
                }
            }
        }


        // Messages from actions.
        $ai_msg = '';
        if ( isset( $_GET['wpranklab_ai'] ) ) {
            $code = sanitize_text_field( wp_unslash( $_GET['wpranklab_ai'] ) );
            if ( 'summary_ok' === $code ) {
                $ai_msg = __( 'AI summary generated successfully.', 'wpranklab' );
            } elseif ( 'summary_err' === $code ) {
                $ai_msg = __( 'Could not generate AI summary. Please try again.', 'wpranklab' );
            } elseif ( 'qa_ok' === $code ) {
                $ai_msg = __( 'AI Q&A block generated successfully.', 'wpranklab' );
            } elseif ( 'qa_err' === $code ) {
                $ai_msg = __( 'Could not generate AI Q&A block. Please try again.', 'wpranklab' );
            } elseif ( 'insert_ok' === $code ) {
                $ai_msg = __( 'AI content was inserted into the post.', 'wpranklab' );
            }
        }
        ?>
        <div class="wpranklab-meta-box">
            <?php if ( $scan_done ) : ?>
                <p class="wpranklab-scan-message">
                    <?php esc_html_e( 'AI Visibility scan completed for this content.', 'wpranklab' ); ?>
                </p>
            <?php endif; ?>

            <?php if ( $ai_msg ) : ?>
                <p class="notice notice-info" style="padding:4px 6px;margin:0 0 6px;border-left:3px solid #0073aa;background:#f0f6fc;">
                    <?php echo esc_html( $ai_msg ); ?>
                </p>
            <?php endif; ?>

            <div class="wpranklab-score-badge <?php echo esc_attr( $color_class ); ?>">
                <?php
                if ( null === $score_int ) {
                    esc_html_e( 'No score yet', 'wpranklab' );
                } else {
                    printf(
                        esc_html__( '%d / 100', 'wpranklab' ),
                        $score_int
                    );
                }
                ?>
            </div>
            <p class="wpranklab-score-label"><?php echo esc_html( $label ); ?></p>

            <?php if ( $last_run ) : ?>
                <p class="wpranklab-last-run">
                    <?php
                    printf(
                        esc_html__( 'Last analyzed: %s', 'wpranklab' ),
                        esc_html( $last_run )
                    );
                    ?>
                </p>
            <?php else : ?>
                <p class="wpranklab-last-run">
                    <?php esc_html_e( 'No AI Visibility scan has been run yet. Click "Update" or use the button below to analyze this content.', 'wpranklab' ); ?>
                </p>
            <?php endif; ?>

            <?php if ( $is_dev_mode ) : ?>
                <?php
                $base = admin_url( 'admin-post.php' );
                $u7  = add_query_arg( array( 'action' => 'wpranklab_add_test_snapshot', 'post_id' => $post->ID, 'days' => 7 ), $base );
                $u14 = add_query_arg( array( 'action' => 'wpranklab_add_test_snapshot', 'post_id' => $post->ID, 'days' => 14 ), $base );
                $u0  = add_query_arg( array( 'action' => 'wpranklab_add_test_snapshot', 'post_id' => $post->ID, 'days' => 0 ), $base );
                $u7  = wp_nonce_url( $u7, 'wpranklab_add_test_snapshot' );
                $u14 = wp_nonce_url( $u14, 'wpranklab_add_test_snapshot' );
                $u0  = wp_nonce_url( $u0, 'wpranklab_add_test_snapshot' );
                ?>
                <div style="margin:8px 0 10px;padding:8px;border:1px dashed #c3c4c7;background:#f6f7f7;">
                    <div style="font-weight:600;margin:0 0 6px;"><?php esc_html_e( 'Dev tools', 'wpranklab' ); ?></div>
                    <div style="font-size:12px;line-height:1.6;">
                        <a class="button button-small" href="<?php echo esc_url( $u7 ); ?>"><?php esc_html_e( 'Add snapshot (7d ago)', 'wpranklab' ); ?></a>
                        <a class="button button-small" href="<?php echo esc_url( $u14 ); ?>" style="margin-left:6px;"><?php esc_html_e( 'Add snapshot (14d ago)', 'wpranklab' ); ?></a>
                        <a class="button button-small" href="<?php echo esc_url( $u0 ); ?>" style="margin-left:6px;"><?php esc_html_e( 'Add snapshot (today)', 'wpranklab' ); ?></a>
                        <div style="margin-top:6px;color:#646970;">
                            <?php esc_html_e( 'Creates history entries without calling OpenAI (for testing weekly deltas).', 'wpranklab' ); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ( isset( $_GET['wpranklab_hist_test'] ) && '1' === (string) $_GET['wpranklab_hist_test'] ) : ?>
                <div class="notice notice-success" style="margin:8px 0 10px;">
                    <p>
                        <?php
                        $days = isset( $_GET['wpranklab_hist_days'] ) ? (int) $_GET['wpranklab_hist_days'] : 0;
                        printf( esc_html__( 'Test snapshot added (%d day(s) back).', 'wpranklab' ), $days );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $history ) ) : ?>
                <div class="wpranklab-history-box" style="margin:8px 0 10px;padding:8px;border:1px solid #dcdcde;background:#fff;">
                    <div style="font-weight:600;margin:0 0 6px;"><?php esc_html_e( 'Score change', 'wpranklab' ); ?></div>

                    <div style="font-size:12px;line-height:1.4;">
                        <?php if ( null !== $delta_last ) : ?>
                            <div>
                                <?php
                                $sign = $delta_last > 0 ? '+' : '';
                                printf( esc_html__( 'Since last scan: %s%d', 'wpranklab' ), esc_html( $sign ), (int) $delta_last );
                                ?>
                            </div>
                        <?php endif; ?>

                        <?php if ( null !== $delta_week ) : ?>
                            <div>
                                <?php
                                $sign = $delta_week > 0 ? '+' : '';
                                printf( esc_html__( 'Vs 7+ days ago: %s%d', 'wpranklab' ), esc_html( $sign ), (int) $delta_week );
                                ?>
                            </div>
                        <?php endif; ?>

                        <details style="margin-top:6px;">
                            <summary style="cursor:pointer;"><?php esc_html_e( 'History (latest)', 'wpranklab' ); ?></summary>
                            <ul style="margin:6px 0 0 18px;">
                                <?php
                                $slice = array_slice( $history, -6 );
                                $slice = array_reverse( $slice );
                                foreach ( $slice as $row ) {
                                    printf( '<li>%s — <strong>%d</strong></li>', esc_html( $row['date'] ), (int) $row['score'] );
                                }
                                ?>
                            </ul>
                        </details>
                    </div>
                </div>
            <?php endif; ?>


            <?php if ( ! empty( $metrics ) ) : ?>
                <details class="wpranklab-metrics">
                    <summary><?php esc_html_e( 'View analysis details', 'wpranklab' ); ?></summary>
                    <ul>
                        <?php if ( isset( $metrics['word_count'] ) ) : ?>
                            <li><?php printf( esc_html__( 'Word count: %d', 'wpranklab' ), (int) $metrics['word_count'] ); ?></li>
                        <?php endif; ?>
                        <?php if ( isset( $metrics['h2_count'] ) || isset( $metrics['h3_count'] ) ) : ?>
                            <li>
                                <?php
                                printf(
                                    esc_html__( 'Headings (H2/H3): %d / %d', 'wpranklab' ),
                                    (int) ( $metrics['h2_count'] ?? 0 ),
                                    (int) ( $metrics['h3_count'] ?? 0 )
                                );
                                ?>
                            </li>
                        <?php endif; ?>
                        <?php if ( isset( $metrics['internal_links'] ) ) : ?>
                            <li>
                                <?php
                                printf(
                                    esc_html__( 'Internal links: %d', 'wpranklab' ),
                                    (int) $metrics['internal_links']
                                );
                                ?>
                            </li>
                        <?php endif; ?>
                        <?php if ( isset( $metrics['question_marks'] ) ) : ?>
                            <li>
                                <?php
                                printf(
                                    esc_html__( 'Questions detected: %d', 'wpranklab' ),
                                    (int) $metrics['question_marks']
                                );
                                ?>
                            </li>
                        <?php endif; ?>
                                <?php
            // Show detected entities (Pro-only feature, but safe to call)
            if ( class_exists( 'WPRankLab_Entities' ) ) :
                $entities_service   = WPRankLab_Entities::get_instance();
                $entities_for_post  = $entities_service->get_entities_for_post( $post->ID );

                if ( ! empty( $entities_for_post ) ) :
                    ?>
                    <li>
                        <strong><?php esc_html_e( 'Entities detected:', 'wpranklab' ); ?></strong><br />
                        <?php
                        $labels = array();

                        foreach ( $entities_for_post as $entity ) {
                            $name = isset( $entity['name'] ) ? $entity['name'] : '';
                            $type = isset( $entity['type'] ) ? $entity['type'] : '';
                            if ( '' === $name ) {
                                continue;
                            }

                            $label = $name;
                            if ( '' !== $type ) {
                                $label .= ' (' . $type . ')';
                            }

                            $labels[] = esc_html( $label );
                        }

                        echo implode( ', ', $labels );
                        ?>
                    </li>
                    <?php
                endif;
            endif;
            ?>
                    
                    
                    
                    </ul>
                </details>
            <?php endif; ?>

            <?php
            
            // ------------------- START: AI Visibility Breakdown -------------------
            // Ensure analyzer class exists
            if ( class_exists( 'WPRankLab_Analyzer' ) ) {
                //$analyzer_metrics = WPRankLab_Analyzer::analyze_post( $post->ID );
                //$signals = WPRankLab_Analyzer::get_signals_for_post( $analyzer_metrics );
                
                $analyzer = WPRankLab_Analyzer::get_instance();
                $analyzer_metrics = $analyzer->analyze_post( $post->ID );
                
                if ( is_array( $analyzer_metrics ) ) {
                    
                    $signals = WPRankLab_Analyzer::get_signals_for_post( $post->ID, $analyzer_metrics );
                    
                    echo '<h4 style="margin-top:15px;">' . esc_html__( 'AI Visibility Breakdown', 'wpranklab' ) . '</h4>';
                    echo '<ul style="margin:0; padding-left:18px;">';
                    
                    $is_pro = function_exists( 'wpranklab_is_pro_active' ) && wpranklab_is_pro_active();
                    
                
                foreach ( $signals as $index => $signal ) {
                    // First two signals are free; others are Pro in this scheme.
                    $is_advanced = ( $index >= 2 );
                    
                    if ( ! $is_pro && $is_advanced ) {
                        // Locked (Free user)
                        echo '<li style="margin-bottom:8px; opacity:0.55;">';
                        echo '<span style="display:inline-block;width:10px;height:10px;background:#888;border-radius:50%;margin-right:8px;vertical-align:middle;"></span>';
                        echo esc_html( $signal['text'] ) . ' <em>(' . esc_html__( 'Pro only', 'wpranklab' ) . ')</em>';
                        echo '</li>';
                        continue;
                    }
                    
                    // Map status to color
                    $color = '#888';
                    if ( 'green' === $signal['status'] ) $color = '#27ae60';
                    if ( 'orange' === $signal['status'] ) $color = '#f39c12';
                    if ( 'red' === $signal['status'] ) $color = '#e74c3c';
                    
                    echo '<li style="margin-bottom:8px;">';
                    echo '<span style="display:inline-block;width:10px;height:10px;background:' . esc_attr( $color ) . ';border-radius:50%;margin-right:8px;vertical-align:middle;"></span>';
                    echo esc_html( $signal['text'] );
                    echo '</li>';
                }
            }
                echo '</ul>';
            }
            // -------------------- END: AI Visibility Breakdown --------------------
            ?>
            <hr />

<h4><?php esc_html_e( 'Missing Topics (Pro)', 'wpranklab' ); ?></h4>
<?php
$mt_data  = get_post_meta( $post->ID, '_wpranklab_missing_topics', true );
$mt_error = get_post_meta( $post->ID, '_wpranklab_missing_topics_error', true );

if ( ! $is_pro ) {
    echo '<p><em>' . esc_html__( 'Available in WPRankLab Pro.', 'wpranklab' ) . '</em></p>';
} elseif ( ! $has_key ) {
    echo '<p><em>' . esc_html__( 'OpenAI API key is not configured in WPRankLab Settings.', 'wpranklab' ) . '</em></p>';
} elseif ( $mt_error ) {
    echo '<p style="color:#b32d2e;">' . esc_html( $mt_error ) . '</p>';
    echo '<p><em>' . esc_html__( 'Run the scan again to retry.', 'wpranklab' ) . '</em></p>';
} elseif ( is_array( $mt_data ) && ! empty( $mt_data['missing_topics'] ) && is_array( $mt_data['missing_topics'] ) ) {
    echo '<ul style="margin:0; padding-left:18px;">';
    $inserted = (array) get_post_meta( $post->ID, '_wpranklab_inserted_missing_topics', true );
    
    foreach ( $mt_data['missing_topics'] as $index => $item ) {
        
        $topic    = isset( $item['topic'] ) ? (string) $item['topic'] : '';
        $reason   = isset( $item['reason'] ) ? (string) $item['reason'] : '';
        $priority = isset( $item['priority'] ) ? (string) $item['priority'] : '';
        
        if ( '' === $topic ) {
            continue;
        }
        
        echo '<li style="margin-bottom:10px;">';
        echo '<strong>' . esc_html( $topic ) . '</strong>';
        
        if ( $priority ) {
            echo ' <small style="opacity:0.7;">(' . esc_html( $priority ) . ')</small>';
        }
        
        if ( $reason ) {
            echo '<br /><span style="opacity:0.85;">' . esc_html( $reason ) . '</span>';
        }
        
        if ( in_array( $topic, $inserted, true ) ) {
            echo '<br /><em style="color:#2ecc71;">' . esc_html__( 'Inserted', 'wpranklab' ) . '</em>';
        } else {
            $url = wp_nonce_url(
                admin_url(
                    'admin-post.php?action=wpranklab_insert_missing_topic'
                    . '&post_id=' . (int) $post->ID
                    . '&topic_index=' . (int) $index
                    ),
                'wpranklab_insert_missing_topic_' . (int) $post->ID . '_' . (int) $index
                );
            
            echo '<br />';
            echo '<a href="' . esc_url( $url ) . '" class="button button-small wpranklab-insert-missing-topic" data-postid="' . (int) $post->ID . '" data-topic="' . esc_attr( $topic ) . '">Insert section</a>';
            
        }
        
        echo '</li>';
    }
    
    echo '</ul>';

    if ( ! empty( $mt_data['suggested_questions'] ) && is_array( $mt_data['suggested_questions'] ) ) {
        echo '<details style="margin-top:8px;">';
        echo '<summary>' . esc_html__( 'Suggested questions to add', 'wpranklab' ) . '</summary>';
        echo '<ul style="padding-left:18px;">';
        foreach ( array_slice( $mt_data['suggested_questions'], 0, 8 ) as $q ) {
            $q = trim( (string) $q );
            if ( '' === $q ) continue;
            echo '<li>' . esc_html( $q ) . '</li>';
        }
        echo '</ul>';
        echo '</details>';
    }

} else {
    echo '<p><em>' . esc_html__( 'Run “AI Visibility Scan” to generate missing topic suggestions.', 'wpranklab' ) . '</em></p>';
}
            
   
?>

<hr />
<h4><?php esc_html_e( 'Schema Recommendations (Pro)', 'wpranklab' ); ?></h4>
<?php
$schema = get_post_meta( $post->ID, '_wpranklab_schema_recommendations', true );

if ( ! $is_pro ) {
    echo '<p><em>' . esc_html__( 'Available in WPRankLab Pro.', 'wpranklab' ) . '</em></p>';
} elseif ( ! is_array( $schema ) || empty( $schema['recommended'] ) ) {
    echo '<p><em>' . esc_html__( 'Run “AI Visibility Scan” to generate schema recommendations.', 'wpranklab' ) . '</em></p>';
} else {

    if ( ! empty( $schema['existing'] ) && is_array( $schema['existing'] ) ) {
        $existing_labels = array();
        foreach ( $schema['existing'] as $k => $v ) {
            if ( $v ) {
                $existing_labels[] = strtoupper( $k );
            }
        }
        if ( ! empty( $existing_labels ) ) {
            echo '<p><small style="opacity:0.8;">' . esc_html__( 'Detected:', 'wpranklab' ) . ' ' . esc_html( implode( ', ', $existing_labels ) ) . '</small></p>';
        }
    }

    $enabled_schema = get_post_meta( $post->ID, '_wpranklab_schema_enabled', true );
    if ( ! is_array( $enabled_schema ) ) {
        $enabled_schema = array();
    }
    
    
    
    foreach ( $schema['recommended'] as $i => $item ) {
        $type   = isset( $item['type'] ) ? (string) $item['type'] : '';
        $reason = isset( $item['reason'] ) ? (string) $item['reason'] : '';
        $jsonld = isset( $item['jsonld'] ) ? (string) $item['jsonld'] : '';

        if ( '' === $type ) continue;

        echo '<div style="margin-bottom:10px; padding:8px; border:1px solid #e5e5e5; border-radius:6px;">';
        echo '<strong>' . esc_html( $type ) . '</strong>';
        if ( $reason ) {
            echo '<br /><span style="opacity:0.85;">' . esc_html( $reason ) . '</span>';
        }

        if ( $jsonld ) {
            $ta_id = 'wpranklab_schema_' . (int) $post->ID . '_' . (int) $i;
            echo '<textarea readonly id="' . esc_attr( $ta_id ) . '" style="width:100%; margin-top:8px; font-family:monospace;" rows="7">';
            echo esc_textarea( $jsonld );
            echo '</textarea>';

            echo '<button type="button" class="button button-small wpranklab-copy-schema" data-target="' . esc_attr( $ta_id ) . '" style="margin-top:6px;">';
            echo esc_html__( 'Copy JSON-LD', 'wpranklab' );
            echo '</button>';
            
            // Warn if FAQ schema is still a template (placeholders).
            if ( false !== strpos( $jsonld, 'QUESTION_1' ) || false !== strpos( $jsonld, 'ANSWER_1' ) ) {
                echo '<div style="margin-top:6px;"><small style="color:#b32d2e;">'
                . esc_html__( 'FAQ schema is a template. Add an AI Q&A block (or Q&A headings) and rescan to auto-fill.', 'wpranklab' )
                . '</small></div>';
            }
            
            // Warn if HowTo schema is still a template (placeholders).
            if ( 'HowTo' === $type && false !== strpos( $jsonld, 'Describe step 1' ) ) {
                echo '<div style="margin-top:6px;"><small style="color:#b32d2e;">'
                . esc_html__( 'HowTo schema is a template. Add a clear ordered list of steps (1,2,3...) or "Step 1: ..." format, then rescan to auto-fill.', 'wpranklab' )
                . '</small></div>';
            }
            
            
            
            $is_enabled = isset( $enabled_schema[ $type ] );
            
            $mode = $is_enabled ? 'disable' : 'enable';
            
            $toggle_url = wp_nonce_url(
                admin_url(
                    'admin-post.php?action=wpranklab_toggle_schema'
                    . '&post_id=' . (int) $post->ID
                    . '&type=' . rawurlencode( $type )
                    . '&mode=' . $mode
                    ),
                'wpranklab_toggle_schema_' . (int) $post->ID . '_' . $type . '_' . $mode
                );
            
            echo ' <a href="' . esc_url( $toggle_url ) . '" class="button button-small" style="margin-left:6px;">';
            echo $is_enabled ? esc_html__( 'Disable output', 'wpranklab' ) : esc_html__( 'Enable output', 'wpranklab' );
            echo '</a>';
            
            if ( $is_enabled ) {
                echo '<div style="margin-top:6px;"><small style="color:#1e7e34;">' . esc_html__( 'Enabled: outputting on frontend.', 'wpranklab' ) . '</small></div>';
            }
            
        }

        echo '</div>';
    }
}

?>

<hr />
<h4><?php esc_html_e( 'Internal Link Suggestions (Pro)', 'wpranklab' ); ?></h4>
<?php
$sugs = get_post_meta( $post->ID, '_wpranklab_internal_link_suggestions', true );

if ( ! $is_pro ) {
    echo '<p><em>' . esc_html__( 'Available in WPRankLab Pro.', 'wpranklab' ) . '</em></p>';
} elseif ( ! is_array( $sugs ) || empty( $sugs ) ) {
    echo '<p><em>' . esc_html__( 'Run “AI Visibility Scan” to generate internal link suggestions.', 'wpranklab' ) . '</em></p>';
} else {
    echo '<ul style="margin:0; padding-left:18px;">';
    foreach ( $sugs as $i => $s ) {
        $url    = isset( $s['url'] ) ? (string) $s['url'] : '';
        $title  = isset( $s['title'] ) ? (string) $s['title'] : '';
        $reason = isset( $s['reason'] ) ? (string) $s['reason'] : '';
        if ( '' === $url || '' === $title ) continue;

        echo '<li style="margin-bottom:10px;">';
        echo '<strong><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a></strong>';
        if ( $reason ) {
            echo '<br /><small style="opacity:0.8;">' . esc_html( $reason ) . '</small>';
        }
        $insert_url = wp_nonce_url(
            admin_url(
                'admin-post.php?action=wpranklab_insert_internal_link'
                . '&post_id=' . (int) $post->ID
                . '&target_id=' . (int) $s['target_id']
                ),
            'wpranklab_insert_internal_link_' . (int) $post->ID . '_' . (int) $s['target_id']
            );
        
        echo '<br />';
        echo '<a href="' . esc_url( $insert_url ) . '"
    class="button button-small wpranklab-insert-internal-link"
    data-postid="' . (int) $post->ID . '"
    data-targetid="' . (int) $s['target_id'] . '"
    data-url="' . esc_url( $url ) . '"
    data-anchor="' . esc_attr( $title ) . '">
    Insert link
</a>';
        
        echo '</li>';
    }
    echo '</ul>';
}



            // Manual scan button (existing feature).
            $scan_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'action'            => 'wpranklab_scan_post',
                        'wpranklab_post_id' => (int) $post->ID,
                    ),
                    admin_url( 'admin-post.php' )
                ),
                'wpranklab_scan_post'
            );
            ?>
            <p style="margin-top: 8px;">
                <a href="<?php echo esc_url( $scan_url ); ?>"
                   class="button button-secondary"
                   onclick="return confirm('<?php echo esc_js( __( 'This will reload the page. Any unsaved changes will be lost. Please click Update to save your content before running the scan. Continue?', 'wpranklab' ) ); ?>');">
                    <?php esc_html_e( 'Run AI Visibility Scan Now', 'wpranklab' ); ?>
                </a>
            </p>

            <hr />

            <h4><?php esc_html_e( 'AI Summary (Pro)', 'wpranklab' ); ?></h4>
            <?php
            $can_use_ai = $is_pro && $has_key;
            if ( ! $is_pro ) {
                echo '<p><em>' . esc_html__( 'Available in WPRankLab Pro.', 'wpranklab' ) . '</em></p>';
            } elseif ( ! $has_key ) {
                echo '<p><em>' . esc_html__( 'OpenAI API key is not configured in WPRankLab Settings.', 'wpranklab' ) . '</em></p>';
            }

            if ( $can_use_ai ) {
                $gen_summary_url = wp_nonce_url(
                    add_query_arg(
                        array(
                            'action'            => 'wpranklab_generate_summary',
                            'wpranklab_post_id' => (int) $post->ID,
                        ),
                        admin_url( 'admin-post.php' )
                    ),
                    'wpranklab_generate_summary'
                );
                ?>
                <p>
                    <a href="<?php echo esc_url( $gen_summary_url ); ?>" class="button button-secondary">
                        <?php esc_html_e( 'Generate AI Summary', 'wpranklab' ); ?>
                    </a>
                </p>
                <?php
                if ( ! empty( $ai_summary ) ) {
                    $insert_summary_url = wp_nonce_url(
                        add_query_arg(
                            array(
                                'action'            => 'wpranklab_insert_summary',
                                'wpranklab_post_id' => (int) $post->ID,
                            ),
                            admin_url( 'admin-post.php' )
                        ),
                        'wpranklab_insert_summary'
                    );
                    ?>
                    <div class="wpranklab-ai-block">
                        <p>
                            <button type="button"
                                    class="button wpranklab-copy-btn"
                                    data-wpranklab-copy-target="wpranklab-ai-summary-text-<?php echo (int) $post->ID; ?>">
                                <?php esc_html_e( 'Copy', 'wpranklab' ); ?>
                            </button>
                            <a href="<?php echo esc_url( $insert_summary_url ); ?>" class="button">
                                <?php esc_html_e( 'Insert Into Post', 'wpranklab' ); ?>
                            </a>
                        </p>
                        <div id="wpranklab-ai-summary-text-<?php echo (int) $post->ID; ?>" class="wpranklab-ai-text">
                            <?php echo nl2br( esc_html( $ai_summary ) ); ?>
                        </div>
                    </div>
                    <?php
                }
            }

            ?>

            <hr />

            <h4><?php esc_html_e( 'AI Q&A Block (Pro)', 'wpranklab' ); ?></h4>
            <?php
            if ( $can_use_ai ) {
                $gen_qa_url = wp_nonce_url(
                    add_query_arg(
                        array(
                            'action'            => 'wpranklab_generate_qa',
                            'wpranklab_post_id' => (int) $post->ID,
                        ),
                        admin_url( 'admin-post.php' )
                    ),
                    'wpranklab_generate_qa'
                );
                ?>
                <p>
                    <a href="<?php echo esc_url( $gen_qa_url ); ?>" class="button button-secondary">
                        <?php esc_html_e( 'Generate AI Q&A Block', 'wpranklab' ); ?>
                    </a>
                </p>
                <?php
                if ( ! empty( $ai_qa ) ) {
                    $insert_qa_url = wp_nonce_url(
                        add_query_arg(
                            array(
                                'action'            => 'wpranklab_insert_qa',
                                'wpranklab_post_id' => (int) $post->ID,
                            ),
                            admin_url( 'admin-post.php' )
                        ),
                        'wpranklab_insert_qa'
                    );
                    ?>
                    <div class="wpranklab-ai-block">
                        <p>
                            <button type="button"
                                    class="button wpranklab-copy-btn"
                                    data-wpranklab-copy-target="wpranklab-ai-qa-text-<?php echo (int) $post->ID; ?>">
                                <?php esc_html_e( 'Copy', 'wpranklab' ); ?>
                            </button>
                            <a href="<?php echo esc_url( $insert_qa_url ); ?>" class="button">
                                <?php esc_html_e( 'Insert Into Post', 'wpranklab' ); ?>
                            </a>
                        </p>
                        <div id="wpranklab-ai-qa-text-<?php echo (int) $post->ID; ?>" class="wpranklab-ai-text">
                            <?php echo nl2br( esc_html( $ai_qa ) ); ?>
                        </div>
                    </div>
                    <?php
                }
            }

            if ( ! wpranklab_is_pro_active() ) : ?>
                <div class="wpranklab-upgrade-hint">
                    <p>
                        <?php esc_html_e( 'Upgrade to WPRankLab Pro to unlock AI-generated summaries, Q&A blocks, and deeper AI visibility analysis.', 'wpranklab' ); ?>
                    </p>
                    <p>
                        <a href="https://wpranklab.com/" target="_blank" class="button button-primary">
                            <?php esc_html_e( 'Upgrade to Pro', 'wpranklab' ); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }


    /**
     * Handle manual post-level scan from the metabox.
     */
    public function handle_scan_post() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'wpranklab' ) );
        }

        check_admin_referer( 'wpranklab_scan_post' );

        $post_id = isset( $_GET['wpranklab_post_id'] ) ? (int) $_GET['wpranklab_post_id'] : 0;

        if ( $post_id && class_exists( 'WPRankLab_Analyzer' ) ) {
            
            // Flag this run as a manual scan so Pro modules can safely do API work.
            set_transient( 'wpranklab_force_missing_topics_' . $post_id, 1, 60 );
            
            set_transient( 'wpranklab_force_schema_' . $post_id, 1, 60 );
            
            set_transient( 'wpranklab_force_internal_links_' . $post_id, 1, 60 );
            
            
            $analyzer = WPRankLab_Analyzer::get_instance();
            $analyzer->analyze_post( $post_id );
        }
        

        if ( $post_id ) {
            $redirect = add_query_arg(
                array(
                    'wpranklab_scan' => '1',
                ),
                get_edit_post_link( $post_id, 'raw' )
            );
        } else {
            $redirect = admin_url();
        }

        wp_redirect( $redirect );
        exit;
    }

    /**
     * Handle global scan for all content from the dashboard.
     */
    public function handle_scan_all() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'wpranklab' ) );
        }

        check_admin_referer( 'wpranklab_scan_all' );

        $post_types = apply_filters(
            'wpranklab_analyzer_post_types',
            array( 'post', 'page' )
        );

        $args = array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        );

        $query   = new WP_Query( $args );
        $scanned = 0;

        if ( $query->have_posts() && class_exists( 'WPRankLab_Analyzer' ) ) {
            $analyzer = WPRankLab_Analyzer::get_instance();
            foreach ( $query->posts as $post_id ) {
                $analyzer->analyze_post( $post_id );
                $scanned++;
            }
        }

        wp_reset_postdata();

        $redirect = add_query_arg(
            array(
                'page'                  => 'wpranklab',
                'wpranklab_scan_all'    => 'done',
                'wpranklab_scan_count'  => $scanned,
            ),
            admin_url( 'admin.php' )
        );

        wp_redirect( $redirect );
        exit;
    }

    /**
     * Handle AI summary generation.
     */
    public function handle_generate_summary() {
        wpranklab_require_pro();
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'wpranklab' ) );
        }
        
        check_admin_referer( 'wpranklab_generate_summary' );
        
        $post_id = isset( $_GET['wpranklab_post_id'] ) ? (int) $_GET['wpranklab_post_id'] : 0;
        if ( ! $post_id ) {
            wp_die( esc_html__( 'Missing post ID.', 'wpranklab' ) );
        }
        
        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) {
            wp_die( esc_html__( 'AI features are only available in WPRankLab Pro.', 'wpranklab' ) );
        }
        
        $ai = class_exists( 'WPRankLab_AI' ) ? WPRankLab_AI::get_instance() : null;
        if ( ! $ai || ! $ai->is_available() ) {
            $redirect = add_query_arg(
                array(
                    'wpranklab_ai' => 'summary_err',
                ),
                get_edit_post_link( $post_id, 'raw' )
                );
            wp_redirect( $redirect );
            exit;
        }
        
        $result = $ai->generate_summary_for_post( $post_id );
        if ( is_wp_error( $result ) ) {
            $redirect = add_query_arg(
                array(
                    'wpranklab_ai' => 'summary_err',
                ),
                get_edit_post_link( $post_id, 'raw' )
                );
        } else {
            update_post_meta( $post_id, '_wpranklab_ai_summary', $result );
            $redirect = add_query_arg(
                array(
                    'wpranklab_ai' => 'summary_ok',
                ),
                get_edit_post_link( $post_id, 'raw' )
                );
        }
        
        wp_redirect( $redirect );
        exit;
    }
    

    /**
     * Handle AI Q&A generation.
     */
    public function handle_generate_qa() {
        wpranklab_require_pro();
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'wpranklab' ) );
        }

        check_admin_referer( 'wpranklab_generate_qa' );

        $post_id = isset( $_GET['wpranklab_post_id'] ) ? (int) $_GET['wpranklab_post_id'] : 0;
        if ( ! $post_id ) {
            wp_die( esc_html__( 'Missing post ID.', 'wpranklab' ) );
        }

        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) {
            wp_die( esc_html__( 'AI features are only available in WPRankLab Pro.', 'wpranklab' ) );
        }

        $ai = class_exists( 'WPRankLab_AI' ) ? WPRankLab_AI::get_instance() : null;
        if ( ! $ai || ! $ai->is_available() ) {
            $redirect = add_query_arg(
                array(
                    'wpranklab_ai' => 'qa_err',
                ),
                get_edit_post_link( $post_id, 'raw' )
            );
            wp_redirect( $redirect );
            exit;
        }

        $result = $ai->generate_qa_for_post( $post_id );
        if ( is_wp_error( $result ) ) {
            $redirect = add_query_arg(
                array(
                    'wpranklab_ai' => 'qa_err',
                ),
                get_edit_post_link( $post_id, 'raw' )
            );
        } else {
            update_post_meta( $post_id, '_wpranklab_ai_qa_block', $result );
            $redirect = add_query_arg(
                array(
                    'wpranklab_ai' => 'qa_ok',
                ),
                get_edit_post_link( $post_id, 'raw' )
            );
        }

        wp_redirect( $redirect );
        exit;
    }

    /**
     * Insert AI summary into post content (append at bottom).
     */
    public function handle_insert_summary() {
        
        wpranklab_require_pro();
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'wpranklab' ) );
        }

        check_admin_referer( 'wpranklab_insert_summary' );

        $post_id = isset( $_GET['wpranklab_post_id'] ) ? (int) $_GET['wpranklab_post_id'] : 0;
        if ( ! $post_id ) {
            wp_die( esc_html__( 'Missing post ID.', 'wpranklab' ) );
        }

        $summary = get_post_meta( $post_id, '_wpranklab_ai_summary', true );
        if ( ! empty( $summary ) ) {
            $content = get_post_field( 'post_content', $post_id );
            $content = (string) $content . "\n\n" . $summary;

            wp_update_post(
                array(
                    'ID'           => $post_id,
                    'post_content' => $content,
                )
            );
        }

        $redirect = add_query_arg(
            array(
                'wpranklab_ai' => 'insert_ok',
            ),
            get_edit_post_link( $post_id, 'raw' )
        );

        wp_redirect( $redirect );
        exit;
    }

    /**
     * Insert AI Q&A block into post content (append at bottom).
     */
    public function handle_insert_qa() {
        
        wpranklab_require_pro();
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'wpranklab' ) );
        }

        check_admin_referer( 'wpranklab_insert_qa' );

        $post_id = isset( $_GET['wpranklab_post_id'] ) ? (int) $_GET['wpranklab_post_id'] : 0;
        if ( ! $post_id ) {
            wp_die( esc_html__( 'Missing post ID.', 'wpranklab' ) );
        }

        $qa_block = get_post_meta( $post_id, '_wpranklab_ai_qa_block', true );
        if ( ! empty( $qa_block ) ) {
            $content = get_post_field( 'post_content', $post_id );
            $content = (string) $content . "\n\n" . $qa_block;

            wp_update_post(
                array(
                    'ID'           => $post_id,
                    'post_content' => $content,
                )
            );
        }

        $redirect = add_query_arg(
            array(
                'wpranklab_ai' => 'insert_ok',
            ),
            get_edit_post_link( $post_id, 'raw' )
        );

        wp_redirect( $redirect );
        exit;
    }
    
    /**
     * Insert a missing topic as an H2 section (Pro).
     */
    public function handle_insert_missing_topic() {
        
        wpranklab_require_pro();
        
        
        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) {
            wp_die( esc_html__( 'Pro license required.', 'wpranklab' ) );
        }
        
        $post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
        $index   = isset( $_GET['topic_index'] ) ? (int) $_GET['topic_index'] : -1;
        
        check_admin_referer( 'wpranklab_insert_missing_topic_' . $post_id . '_' . $index );
        
        if ( $post_id <= 0 || $index < 0 ) {
            wp_die( esc_html__( 'Invalid request.', 'wpranklab' ) );
        }
        
        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_die( esc_html__( 'Post not found.', 'wpranklab' ) );
        }
        
        $mt_data = get_post_meta( $post_id, '_wpranklab_missing_topics', true );
        if ( ! is_array( $mt_data ) || empty( $mt_data['missing_topics'][ $index ] ) ) {
            wp_die( esc_html__( 'Missing topic not found.', 'wpranklab' ) );
        }
        
        $topic = trim( (string) $mt_data['missing_topics'][ $index ]['topic'] );
        if ( '' === $topic ) {
            wp_die( esc_html__( 'Invalid topic.', 'wpranklab' ) );
        }
        
        // Prevent duplicate inserts
        $inserted = (array) get_post_meta( $post_id, '_wpranklab_inserted_missing_topics', true );
        if ( in_array( $topic, $inserted, true ) ) {
            wp_die( esc_html__( 'This topic was already inserted.', 'wpranklab' ) );
        }
        
        if ( ! class_exists( 'WPRankLab_AI' ) ) {
            wp_die( esc_html__( 'AI engine unavailable.', 'wpranklab' ) );
        }
        
        $ai = WPRankLab_AI::get_instance();
        if ( ! $ai || ! $ai->is_available() ) {
            wp_die( esc_html__( 'AI is not configured.', 'wpranklab' ) );
        }
        
        $section = $ai->generate_missing_topic_section( $post_id, $topic );
        if ( is_wp_error( $section ) ) {
            wp_die( esc_html( $section->get_error_message() ) );
        }
        
        $new_content = rtrim( $post->post_content ) . "\n\n" . $section . "\n\n";
        
        wp_update_post( array(
            'ID'           => $post_id,
            'post_content' => $new_content,
        ) );
        
        $inserted[] = $topic;
        update_post_meta( $post_id, '_wpranklab_inserted_missing_topics', $inserted );
        
        wp_redirect( get_edit_post_link( $post_id, 'raw' ) );
        exit;
    }
    
    /**
     * AJAX: Generate missing-topic section HTML (for cursor insertion in Block Editor).
     */
    public function ajax_missing_topic_section() {

        wpranklab_require_pro();
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
        }
        
        check_ajax_referer( 'wpranklab_missing_topic_section', 'nonce' );
        
        // Pro gate.
        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) {
            wp_send_json_error( array( 'message' => 'Pro license required.' ), 403 );
        }
        
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $topic   = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
        
        if ( $post_id <= 0 || '' === $topic ) {
            wp_send_json_error( array( 'message' => 'Invalid request.' ), 400 );
        }
        
        if ( ! class_exists( 'WPRankLab_AI' ) ) {
            wp_send_json_error( array( 'message' => 'AI engine unavailable.' ), 500 );
        }
        
        $ai = WPRankLab_AI::get_instance();
        if ( ! $ai || ! method_exists( $ai, 'generate_missing_topic_section' ) ) {
            wp_send_json_error( array( 'message' => 'Missing topic section generator not found.' ), 500 );
        }
        
        $section = $ai->generate_missing_topic_section( $post_id, $topic );
        if ( is_wp_error( $section ) ) {
            wp_send_json_error( array( 'message' => $section->get_error_message() ), 500 );
        }
        
        wp_send_json_success( array(
            'html'  => (string) $section,
            'topic' => (string) $topic,
        ) );
    }
    
    /**
     * Enable/Disable schema output for a post (Pro).
     */
    public function handle_toggle_schema() {
        
        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) {
            wp_die( esc_html__( 'Pro license required.', 'wpranklab' ) );
        }
        
        $post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
        $type    = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
        $mode    = isset( $_GET['mode'] ) ? sanitize_text_field( wp_unslash( $_GET['mode'] ) ) : '';
        
        if ( $post_id <= 0 || '' === $type || ! in_array( $mode, array( 'enable', 'disable' ), true ) ) {
            wp_die( esc_html__( 'Invalid request.', 'wpranklab' ) );
        }
        
        check_admin_referer( 'wpranklab_toggle_schema_' . $post_id . '_' . $type . '_' . $mode );
        
        if ( ! class_exists( 'WPRankLab_Schema' ) ) {
            wp_die( esc_html__( 'Schema module unavailable.', 'wpranklab' ) );
        }
        
        $schema = WPRankLab_Schema::get_instance();
        
        if ( 'enable' === $mode ) {
            
            $reco = get_post_meta( $post_id, '_wpranklab_schema_recommendations', true );
            if ( ! is_array( $reco ) || empty( $reco['recommended'] ) ) {
                wp_die( esc_html__( 'No schema recommendations found. Run a scan first.', 'wpranklab' ) );
            }
            
            $jsonld = '';
            foreach ( $reco['recommended'] as $item ) {
                if ( isset( $item['type'] ) && (string) $item['type'] === $type && ! empty( $item['jsonld'] ) ) {
                    $jsonld = (string) $item['jsonld'];
                    break;
                }
            }
            
            if ( '' === $jsonld ) {
                wp_die( esc_html__( 'Schema JSON not found for this type.', 'wpranklab' ) );
            }
            
            $ok = $schema->enable_schema_for_post( $post_id, $type, $jsonld );
            if ( ! $ok ) {
                wp_die( esc_html__( 'Could not enable schema (invalid JSON).', 'wpranklab' ) );
            }
            
        } else {
            $schema->disable_schema_for_post( $post_id, $type );
        }
        
        wp_redirect( get_edit_post_link( $post_id, 'raw' ) );
        exit;
    }
    
    /**
     * Fallback: append internal link to post content.
     */
    public function handle_insert_internal_link() {
        
        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) {
            wp_die( esc_html__( 'Pro license required.', 'wpranklab' ) );
        }
        
        $post_id   = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
        $target_id = isset( $_GET['target_id'] ) ? (int) $_GET['target_id'] : 0;
        
        if ( $post_id <= 0 || $target_id <= 0 ) {
            wp_die( esc_html__( 'Invalid request.', 'wpranklab' ) );
        }
        
        check_admin_referer( 'wpranklab_insert_internal_link_' . $post_id . '_' . $target_id );
        
        $post   = get_post( $post_id );
        $target = get_post( $target_id );
        if ( ! $post || ! $target ) {
            wp_die( esc_html__( 'Post not found.', 'wpranklab' ) );
        }
        
        $url    = get_permalink( $target_id );
        $anchor = get_the_title( $target_id );
        
        $post = get_post( $post_id );
        $target_url = get_permalink( $target_id );
        
        if ( $target_url && false !== strpos( (string) $post->post_content, $target_url ) ) {
            // Redirect back with a message flag (no JSON output in admin-post).
            $edit = add_query_arg(
            array( 'wpranklab_il' => 'already_linked' ),
            get_edit_post_link( $post_id, 'raw' )
            );
            wp_redirect( $edit );
            exit;
        }
        
        
        $html = '<p><a href="' . esc_url( $url ) . '">' . esc_html( $anchor ) . '</a></p>';
        
        // Append safely
        $post->post_content .= "\n\n" . $html;
        wp_update_post( array(
            'ID'           => $post_id,
            'post_content' => $post->post_content,
        ) );
        
        wp_redirect( get_edit_post_link( $post_id, 'raw' ) );
        exit;
    }
    
    
    /**
     * AJAX: generate internal link block HTML.
     */
    public function ajax_internal_link_block() {
        
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
        }
        
        check_ajax_referer( 'wpranklab_missing_topic_section', 'nonce' ); // reuse existing nonce
        
        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) {
            wp_send_json_error( array( 'message' => 'Pro required.' ), 403 );
        }
        
        $post_id   = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        $target_id = isset( $_POST['target_id'] ) ? (int) $_POST['target_id'] : 0;
        
        if ( $post_id <= 0 || $target_id <= 0 ) {
            wp_send_json_error( array( 'message' => 'Invalid request.' ), 400 );
        }
        
        $url    = get_permalink( $target_id );
        $anchor = get_the_title( $target_id );
        
        if ( ! $url || ! $anchor ) {
            wp_send_json_error( array( 'message' => 'Target not found.' ), 404 );
        }
        
        $post = get_post( $post_id );
        if ( $post && false !== strpos( $post->post_content, get_permalink( $target_id ) ) ) {
            wp_send_json_error( array(
                'message' => 'This post is already linked.'
            ), 409 );
        }
        
        
        $html = '<p><a href="' . esc_url( $url ) . '">' . esc_html( $anchor ) . '</a></p>';
        
        wp_send_json_success( array(
            'html' => $html,
        ) );
    }
    
    public function maybe_show_internal_link_notice() {
        
        if ( empty( $_GET['wpranklab_il'] ) ) {
            return;
        }
        
        $flag = sanitize_text_field( wp_unslash( $_GET['wpranklab_il'] ) );
        
        if ( 'already_linked' === $flag ) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . esc_html__( 'That internal link already exists in this post.', 'wpranklab' )
                . '</p></div>';
        }
    }
    

}

function wpranklab_should_show_license_form() {
    if ( wpranklab_is_pro_active() ) {
        return true;
    }
    
    return (bool) get_option( 'wpranklab_show_license_form', false );
}
