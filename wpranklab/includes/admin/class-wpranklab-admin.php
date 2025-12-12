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

        return $output;
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
                $rows    = $history->get_recent_snapshots( 4 );
            } else {
                $rows = array();
            }

            if ( ! empty( $rows ) ) : ?>
                <table class="widefat striped" style="max-width: 600px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'wpranklab' ); ?></th>
                            <th><?php esc_html_e( 'Avg Score', 'wpranklab' ); ?></th>
                            <th><?php esc_html_e( 'Scanned Items', 'wpranklab' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) : ?>
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

            <p><?php esc_html_e( 'Enter your license key to activate WPRankLab Pro features.', 'wpranklab' ); ?></p>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'wpranklab_license_group' );
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wpranklab_license_key"><?php esc_html_e( 'License Key', 'wpranklab' ); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="wpranklab_license_key"
                                   name="<?php echo esc_attr( WPRANKLAB_OPTION_LICENSE ); ?>[license_key]; ?>"
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
        <?php
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





}
