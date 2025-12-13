<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pro: Schema recommendation engine (deterministic).
 *
 * Generates schema recommendations and JSON-LD templates.
 * Runs only on manual scans (flagged by a transient).
 */
class WPRankLab_Schema {
    
    protected static $instance = null;
    
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function init() {
        add_action( 'wpranklab_after_analyze_post', array( $this, 'maybe_generate_recommendations' ), 30, 2 );
        add_action( 'wp_head', array( $this, 'output_enabled_schema' ), 20 );
        
    }
    
    /**
     * Only run on manual scans (to avoid doing heavier work on every save).
     */
    public function maybe_generate_recommendations( $post_id, $metrics ) {
        
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return;
        }
        
        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) {
            return;
        }
        
        // Manual scan flag (use the same style as Missing Topics).
        $flag_key = 'wpranklab_force_schema_' . $post_id;
        $forced   = (bool) get_transient( $flag_key );
        
        if ( ! $forced ) {
            return;
        }
        
        delete_transient( $flag_key );
        
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }
        
        $content = (string) $post->post_content;
        $title   = (string) $post->post_title;
        
        $existing = $this->detect_existing_schema( $content );
        $reco     = $this->build_recommendations( $post, $content, $metrics, $existing );
        
        update_post_meta( $post_id, '_wpranklab_schema_recommendations', $reco );
        update_post_meta( $post_id, '_wpranklab_schema_last_run', current_time( 'mysql' ) );
    }
    
    /**
     * Best-effort schema detection from content.
     */
    protected function detect_existing_schema( $content ) {
        $found = array(
            'faq'    => false,
            'howto'  => false,
            'article'=> false,
        );
        
        // Very rough heuristics:
        // - JSON-LD presence
        if ( preg_match( '/application\/ld\+json/i', $content ) ) {
            // Not enough to classify, but indicates some schema may already exist.
            // We'll still recommend if our specific types aren't detected.
        }
        
        // - FAQ block / FAQ keywords
        if ( preg_match( '/FAQPage/i', $content ) || preg_match( '/wp:yoast\/faq-block|schema\.org\/FAQPage/i', $content ) ) {
            $found['faq'] = true;
        }
        
        // - HowTo schema markers or step-like patterns
        if ( preg_match( '/HowTo/i', $content ) || preg_match( '/schema\.org\/HowTo/i', $content ) ) {
            $found['howto'] = true;
        }
        
        // - Article schema markers
        if ( preg_match( '/schema\.org\/Article|NewsArticle|BlogPosting/i', $content ) ) {
            $found['article'] = true;
        }
        
        return $found;
    }
    
    /**
     * Build recommendations + JSON-LD templates.
     */
    protected function build_recommendations( $post, $content, $metrics, $existing ) {
        
        $post_id = (int) $post->ID;
        
        $reco = array(
            'existing' => $existing,
            'recommended' => array(), // list of items: type, reason, jsonld
        );
        
        // Always recommend Article (unless already present).
        if ( empty( $existing['article'] ) ) {
            $reco['recommended'][] = array(
                'type'   => 'Article',
                'reason' => __( 'Most AI search engines benefit from clear Article metadata (headline, author, dates).', 'wpranklab' ),
                'jsonld' => $this->jsonld_article( $post ),
            );
        }
        
        // Recommend FAQPage if we detect Q&A signals (from metrics or content) and not already present.
        $has_qa_signal = ! empty( $metrics['has_ai_qa'] ) || ( isset( $metrics['question_marks'] ) && (int) $metrics['question_marks'] >= 2 );
        $looks_like_faq = ( false !== stripos( $content, 'faq' ) ) || preg_match( '/<h2[^>]*>.*\?/i', $content );
        
        if ( empty( $existing['faq'] ) && ( $has_qa_signal || $looks_like_faq ) ) {
            $reco['recommended'][] = array(
                'type'   => 'FAQPage',
                'reason' => __( 'FAQ schema helps AI extract Q&A pairs and improves answer-style visibility.', 'wpranklab' ),
                'jsonld' => $this->jsonld_faq_template( $post_id ),
            );
        }
        
        // Recommend HowTo if we detect steps.
        $looks_like_steps =
        preg_match( '/\bstep\s*1\b/i', $content ) ||
        preg_match( '/<ol\b[^>]*>/i', $content ) ||
        preg_match( '/\bhow to\b/i', $content );
        
        if ( empty( $existing['howto'] ) && $looks_like_steps ) {
            $reco['recommended'][] = array(
                'type'   => 'HowTo',
                'reason' => __( 'HowTo schema makes step-by-step instructions explicit for AI and rich results.', 'wpranklab' ),
                'jsonld' => $this->jsonld_howto_template( $post_id ),
            );
        }
        
        return $reco;
    }
    
    protected function jsonld_article( $post ) {
        $post_id = (int) $post->ID;
        
        $data = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Article',
            'headline' => get_the_title( $post_id ),
            'datePublished' => get_the_date( 'c', $post_id ),
            'dateModified'  => get_the_modified_date( 'c', $post_id ),
            'mainEntityOfPage' => get_permalink( $post_id ),
            'author' => array(
                '@type' => 'Person',
                'name'  => get_the_author_meta( 'display_name', $post->post_author ),
            ),
            'publisher' => array(
                '@type' => 'Organization',
                'name'  => get_bloginfo( 'name' ),
            ),
        );
        
        return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    }
    
    /**
     * FAQ template: user can replace placeholders with real Q&A or we can auto-fill later.
     */
    protected function jsonld_faq_template( $post_id ) {
        $data = array(
            '@context' => 'https://schema.org',
            '@type'    => 'FAQPage',
            'mainEntity' => array(
                array(
                    '@type' => 'Question',
                    'name'  => 'QUESTION_1',
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => 'ANSWER_1',
                    ),
                ),
                array(
                    '@type' => 'Question',
                    'name'  => 'QUESTION_2',
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => 'ANSWER_2',
                    ),
                ),
            ),
        );
        
        return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    }
    
    protected function jsonld_howto_template( $post_id ) {
        $data = array(
            '@context' => 'https://schema.org',
            '@type'    => 'HowTo',
            'name'     => get_the_title( $post_id ),
            'step'     => array(
                array(
                    '@type' => 'HowToStep',
                    'name'  => 'Step 1',
                    'text'  => 'Describe step 1',
                ),
                array(
                    '@type' => 'HowToStep',
                    'name'  => 'Step 2',
                    'text'  => 'Describe step 2',
                ),
            ),
        );
        
        return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    }
    
    /**
     * Frontend: output enabled JSON-LD for the current singular post.
     */
    public function output_enabled_schema() {
        
        // Pro gate + kill-switch safety
        if ( ! function_exists( 'wpranklab_is_pro_active' ) || ! wpranklab_is_pro_active() ) {
            return;
        }
        
        if ( ! is_singular() ) {
            return;
        }
        
        $post_id = get_queried_object_id();
        if ( ! $post_id ) {
            return;
        }
        
        $enabled = get_post_meta( $post_id, '_wpranklab_schema_enabled', true );
        if ( ! is_array( $enabled ) || empty( $enabled ) ) {
            return;
        }
        
        foreach ( $enabled as $type => $jsonld ) {
            $type  = sanitize_text_field( (string) $type );
            $json  = (string) $jsonld;
            
            if ( '' === $type || '' === $json ) {
                continue;
            }
            
            // Validate JSON before output
            $decoded = json_decode( $json, true );
            if ( ! is_array( $decoded ) ) {
                continue;
            }
            
            echo "\n" . '<script type="application/ld+json" data-wpranklab-schema="' . esc_attr( $type ) . '">';
            echo wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES );
            echo '</script>' . "\n";
        }
    }
    
    /**
     * Enable a schema item (stores JSON-LD under postmeta).
     */
    public function enable_schema_for_post( $post_id, $type, $jsonld ) {
        
        $post_id = (int) $post_id;
        $type    = sanitize_text_field( (string) $type );
        $jsonld  = (string) $jsonld;
        
        if ( $post_id <= 0 || '' === $type || '' === $jsonld ) {
            return false;
        }
        
        $decoded = json_decode( $jsonld, true );
        if ( ! is_array( $decoded ) ) {
            return false;
        }
        
        $enabled = get_post_meta( $post_id, '_wpranklab_schema_enabled', true );
        if ( ! is_array( $enabled ) ) {
            $enabled = array();
        }
        
        $enabled[ $type ] = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        update_post_meta( $post_id, '_wpranklab_schema_enabled', $enabled );
        
        return true;
    }
    
    /**
     * Disable a schema item.
     */
    public function disable_schema_for_post( $post_id, $type ) {
        
        $post_id = (int) $post_id;
        $type    = sanitize_text_field( (string) $type );
        
        if ( $post_id <= 0 || '' === $type ) {
            return false;
        }
        
        $enabled = get_post_meta( $post_id, '_wpranklab_schema_enabled', true );
        if ( ! is_array( $enabled ) ) {
            return false;
        }
        
        if ( isset( $enabled[ $type ] ) ) {
            unset( $enabled[ $type ] );
            update_post_meta( $post_id, '_wpranklab_schema_enabled', $enabled );
        }
        
        return true;
    }
    
}
