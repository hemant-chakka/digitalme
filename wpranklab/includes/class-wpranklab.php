<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Core plugin class.
 */
class WPRankLab {

    /**
     * Admin instance.
     *
     * @var WPRankLab_Admin
     */
    protected $admin;

    /**
     * License manager instance.
     *
     * @var WPRankLab_License_Manager
     */
    protected $license_manager;

    /**
     * Initialize the plugin.
     */
    public function __construct() {
        // Future: init public hooks, cron, scanners, etc.
    }

    /**
     * Register all hooks.
     */
    public function run() {
        // Initialize license manager first so other components can check Pro status.
        if ( class_exists( 'WPRankLab_License_Manager' ) ) {
            $this->license_manager = WPRankLab_License_Manager::get_instance();
            $this->license_manager->init();
        }

        // Initialize analyzer and its hooks.
        if ( class_exists( 'WPRankLab_Analyzer' ) ) {
            $analyzer = WPRankLab_Analyzer::get_instance();
            add_action( 'save_post', array( $analyzer, 'handle_save_post' ), 20, 2 );
        }

        // Initialize history manager (weekly snapshots + emails).
        if ( class_exists( 'WPRankLab_History' ) ) {
            $history = WPRankLab_History::get_instance();
            $history->init();
        }
        
        // Initialize Pro missing topic detector (manual scans only).
        if ( class_exists( 'WPRankLab_Missing_Topics' ) ) {
            $mt = WPRankLab_Missing_Topics::get_instance();
            $mt->init();
        }
        
        if ( class_exists( 'WPRankLab_Schema' ) ) {
            WPRankLab_Schema::get_instance()->init();
        }
        
        if ( class_exists( 'WPRankLab_Internal_Links' ) ) {
            WPRankLab_Internal_Links::get_instance()->init();
        }
        
        if ( is_admin() && class_exists( 'WPRankLab_Admin' ) ) {
            $this->load_admin();
        }
        

        // Future: add more cron hooks, REST routes, etc.
    }

    /**
     * Load admin functionality.
     */
    protected function load_admin() {
        $this->admin = new WPRankLab_Admin();
        $this->admin->init();
    }
}
