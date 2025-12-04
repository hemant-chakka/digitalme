<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fired during plugin activation.
 */
class WPRankLab_Activator {

    /**
     * Activation hook.
     */
    public static function activate() {
        self::create_tables();
        self::init_options();
        self::schedule_cron_events();
    }

    /**
     * Create custom DB tables for history and audit queue.
     */
    protected static function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $history_table = $wpdb->prefix . WPRANKLAB_TABLE_HISTORY;
        $audit_table   = $wpdb->prefix . WPRANKLAB_TABLE_AUDIT_Q;

        // Weekly history table.
        $sql_history = "CREATE TABLE {$history_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            visibility_score float DEFAULT NULL,
            visibility_delta float DEFAULT NULL,
            week_start date NOT NULL,
            data longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY week_start (week_start)
        ) {$charset_collate};";

        // Audit queue table.
        $sql_audit = "CREATE TABLE {$audit_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int(11) NOT NULL DEFAULT 0,
            last_error text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta( $sql_history );
        dbDelta( $sql_audit );
    }

    /**
     * Initialize basic plugin options.
     */
    protected static function init_options() {
        $default_settings = array(
            'plan'            => 'free', // 'free' or 'pro' – actual status is license-driven.
            'openai_api_key'  => '',
            'weekly_email'    => 1,
            'email_day'       => 'monday',
            'email_time'      => '09:00',
        );

        if ( ! get_option( WPRANKLAB_OPTION_SETTINGS ) ) {
            add_option( WPRANKLAB_OPTION_SETTINGS, $default_settings );
        }

        $default_license = array(
            'license_key'        => '',
            'status'             => 'inactive', // 'inactive', 'active', 'expired', 'invalid'
            'expires_at'         => '',
            'last_check'         => '',
            'allowed_version'    => '',
            'bound_domain'       => '',
            'kill_switch_active' => 0,
        );

        if ( ! get_option( WPRANKLAB_OPTION_LICENSE ) ) {
            add_option( WPRANKLAB_OPTION_LICENSE, $default_license );
        }
    }

    /**
     * Schedule cron events (license check, weekly email, scans).
     */
    protected static function schedule_cron_events() {
        if ( ! wp_next_scheduled( 'wpranklab_daily_license_check' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpranklab_daily_license_check' );
        }

        if ( ! wp_next_scheduled( 'wpranklab_weekly_report' ) ) {
            // Weekly; we'll refine day/time later.
            wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', 'wpranklab_weekly_report' );
        }
    }
}
