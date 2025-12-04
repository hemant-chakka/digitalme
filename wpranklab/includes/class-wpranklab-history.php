<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles history snapshots and weekly emails.
 */
class WPRankLab_History {

    /**
     * Singleton.
     *
     * @var WPRankLab_History|null
     */
    protected static $instance = null;

    /**
     * Get instance.
     *
     * @return WPRankLab_History
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Init hooks.
     */
    public function init() {
        // Ensure history table exists.
        $this->maybe_create_table();

        // Hook weekly report event (already scheduled by activator).
        add_action( 'wpranklab_weekly_report', array( $this, 'handle_weekly_event' ) );
    }

    /**
     * Create history table if it does not exist.
     */
    protected function maybe_create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wpranklab_history';

        // Check if table exists.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        if ( $exists === $table_name ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "
        CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            snapshot_date DATE NOT NULL,
            avg_score FLOAT NULL,
            scanned_count INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY snapshot_date (snapshot_date)
        ) {$charset_collate};
        ";

        dbDelta( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
    }

    /**
     * Handle weekly cron: record snapshot + send email.
     */
    public function handle_weekly_event() {
        $snapshot = $this->record_snapshot();
        $this->send_weekly_email( $snapshot );
    }

    /**
     * Record a history snapshot for the current site state.
     *
     * @return array Snapshot data.
     */
    public function record_snapshot() {
        global $wpdb;

        $history_table = $wpdb->prefix . 'wpranklab_history';

        // Get all posts/pages with a visibility score.
        $post_types = apply_filters(
            'wpranklab_analyzer_post_types',
            array( 'post', 'page' )
        );

        $meta_key = '_wpranklab_visibility_score';

        if ( empty( $post_types ) || ! is_array( $post_types ) ) {
            $post_types = array( 'post', 'page' );
        }

        $placeholders = implode(
            ', ',
            array_fill( 0, count( $post_types ), '%s' )
        );

        $sql = $wpdb->prepare(
            "
            SELECT pm.meta_value AS score
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = %s
              AND p.post_type IN ($placeholders)
              AND p.post_status = 'publish'
            ",
            array_merge( array( $meta_key ), $post_types )
        );

        $rows = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $scores = array();
        if ( $rows ) {
            foreach ( $rows as $row ) {
                if ( is_numeric( $row ) ) {
                    $scores[] = (float) $row;
                }
            }
        }

        $scanned_count = count( $scores );
        $avg_score     = $scanned_count > 0 ? array_sum( $scores ) / $scanned_count : null;

        $today = current_time( 'Y-m-d' );

        $snapshot = array(
            'snapshot_date' => $today,
            'avg_score'     => $avg_score,
            'scanned_count' => $scanned_count,
        );

        // Insert into history table.
        $wpdb->insert(
            $history_table,
            array(
                'snapshot_date' => $today,
                'avg_score'     => $avg_score,
                'scanned_count' => $scanned_count,
            ),
            array( '%s', '%f', '%d' )
        ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return $snapshot;
    }

    /**
     * Get last N snapshots.
     *
     * @param int $limit
     *
     * @return array
     */
    public function get_recent_snapshots( $limit = 4 ) {
        global $wpdb;

        $history_table = $wpdb->prefix . 'wpranklab_history';

        $limit = (int) $limit;
        if ( $limit <= 0 ) {
            $limit = 4;
        }

        $sql = $wpdb->prepare(
            "SELECT snapshot_date, avg_score, scanned_count
             FROM {$history_table}
             ORDER BY snapshot_date DESC
             LIMIT %d",
            $limit
        );

        $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return $rows ? $rows : array();
    }

    /**
     * Send weekly email based on latest snapshot.
     *
     * @param array $snapshot
     */
    public function send_weekly_email( $snapshot ) {
        // If there is no data yet, do not send.
        if ( empty( $snapshot ) ) {
            return;
        }

        $is_pro  = function_exists( 'wpranklab_is_pro_active' ) && wpranklab_is_pro_active();
        $site    = get_bloginfo( 'name' );
        $to      = get_option( 'admin_email' );
        $subject = sprintf(
            /* translators: %s: site name. */
            __( 'Your Weekly AI Visibility Update — %s', 'wpranklab' ),
            $site
        );

        $avg_score     = is_null( $snapshot['avg_score'] ) ? __( 'N/A', 'wpranklab' ) : round( $snapshot['avg_score'], 1 );
        $scanned_count = (int) $snapshot['scanned_count'];
        $date          = $snapshot['snapshot_date'];

        // Compare with previous snapshot to determine up/down.
        $trend_arrow = '';
        $trend_label = __( 'No previous data', 'wpranklab' );

        $recent = $this->get_recent_snapshots( 2 );
        if ( count( $recent ) >= 2 ) {
            $current = $recent[0];
            $prev    = $recent[1];

            if ( ! is_null( $current['avg_score'] ) && ! is_null( $prev['avg_score'] ) ) {
                if ( $current['avg_score'] > $prev['avg_score'] ) {
                    $trend_arrow = '↑';
                    $trend_label = __( 'Visibility improved since last week.', 'wpranklab' );
                } elseif ( $current['avg_score'] < $prev['avg_score'] ) {
                    $trend_arrow = '↓';
                    $trend_label = __( 'Visibility decreased since last week.', 'wpranklab' );
                } else {
                    $trend_arrow = '→';
                    $trend_label = __( 'Visibility is stable compared to last week.', 'wpranklab' );
                }
            }
        }

        if ( ! $is_pro ) {
            // Free email: simple.
            $body  = '';
            $body .= sprintf( __( "Date: %s
", 'wpranklab' ), $date );
            $body .= sprintf( __( "AI Visibility Score: %s %s
", 'wpranklab' ), $avg_score, $trend_arrow );
            $body .= sprintf( __( "Scanned items: %d

", 'wpranklab' ), $scanned_count );
            $body .= $trend_label . "\n\n";
            $body .= __( 'Upgrade to WPRankLab Pro to unlock full AI visibility insights, historical charts, and detailed recommendations.', 'wpranklab' ) . "\n";
            $body .= "https://wpranklab.com/\n";
        } else {
            // Pro email: richer content (still plain text for now).
            $body  = '';
            $body .= sprintf( __( "Date: %s
", 'wpranklab' ), $date );
            $body .= sprintf( __( "AI Visibility Score: %s %s
", 'wpranklab' ), $avg_score, $trend_arrow );
            $body .= sprintf( __( "Scanned items: %d

", 'wpranklab' ), $scanned_count );
            $body .= $trend_label . "\n\n";
            $body .= __( "In future versions, this email will also include:\n- Citation rank\n- AI / crawler visits\n- Detailed week summary\n- Top recommendations for next week\n", 'wpranklab' );
            $body .= "\n";
            $body .= __( 'Open your full AI Visibility report in WordPress:', 'wpranklab' ) . "\n";
            $body .= admin_url( 'admin.php?page=wpranklab' ) . "\n";
        }

        /**
         * Filter the email before sending.
         *
         * @param array  $email {'to','subject','body','headers'}
         * @param array  $snapshot
         * @param bool   $is_pro
         */
        $email = apply_filters(
            'wpranklab_weekly_email',
            array(
                'to'      => $to,
                'subject' => $subject,
                'body'    => $body,
                'headers' => '',
            ),
            $snapshot,
            $is_pro
        );

        if ( ! empty( $email['to'] ) && ! empty( $email['subject'] ) && ! empty( $email['body'] ) ) {
            wp_mail( $email['to'], $email['subject'], $email['body'], $email['headers'] );
        }
    }
}
