<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles AI-powered generation (summaries, Q&A blocks) via OpenAI.
 */
class WPRankLab_AI {
    
    /**
     * Singleton instance.
     *
     * @var WPRankLab_AI|null
     */
    protected static $instance = null;
    
    /**
     * Get singleton instance.
     *
     * @return WPRankLab_AI
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Get OpenAI API key from plugin settings.
     *
     * @return string
     */
    public function get_api_key() {
        $settings = get_option( WPRANKLAB_OPTION_SETTINGS, array() );
        return isset( $settings['openai_api_key'] ) ? trim( (string) $settings['openai_api_key'] ) : '';
    }
    
    /**
     * Check whether AI generation is available (API key present).
     *
     * @return bool
     */
    public function is_available() {
        return '' !== $this->get_api_key();
    }
    
    /**
     * Generate an AI-friendly summary for a post.
     *
     * @param int $post_id
     *
     * @return string|WP_Error
     */
    public function generate_summary_for_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'wpranklab_ai_no_post', __( 'Post not found.', 'wpranklab' ) );
        }
        
        $title   = (string) $post->post_title;
        $content = (string) $post->post_content;
        
        $prompt = sprintf(
            "You are an assistant that writes concise, neutral summaries of web pages, optimized for AI search engines such as ChatGPT, Perplexity, Gemini, and Claude.\n\n" .
            "Write a clear summary of the following page in 3–6 sentences. Focus on:\n" .
            "- Main topic\n" .
            "- Key points\n" .
            "- Who it is for\n" .
            "- Why it is useful\n\n" .
            "Do not use headings, bullet lists, or HTML. Just plain text.\n\n" .
            "Title: %s\n\nContent:\n%s",
            $title,
            wp_strip_all_tags( $content )
            );
        
        return $this->call_chat_api( $prompt );
    }
    
    /**
     * Generate an AI Q&A / FAQ block for a post.
     *
     * @param int $post_id
     *
     * @return string|WP_Error
     */
    public function generate_qa_for_post( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'wpranklab_ai_no_post', __( 'Post not found.', 'wpranklab' ) );
        }
        
        $title   = (string) $post->post_title;
        $content = (string) $post->post_content;
        
        $prompt = sprintf(
            "You are an assistant that creates FAQ-style Q&A blocks to help AI search engines understand a page.\n\n" .
            "Based on the page below, create 3–6 of the most important question-and-answer pairs a user might ask.\n\n" .
            "Format your response exactly like this (plain text):\n" .
            "Q: Question 1\nA: Answer 1\nQ: Question 2\nA: Answer 2\n...\n\n" .
            "Do not add any extra commentary.\n\n" .
            "Title: %s\n\nContent:\n%s",
            $title,
            wp_strip_all_tags( $content )
            );
        
        return $this->call_chat_api( $prompt );
    }
    
    /**
     * Call OpenAI chat completion API (gpt-5.1-mini) with a simple text prompt.
     *
     * @param string $prompt
     *
     * @return string|WP_Error
     */
    protected function call_chat_api( $prompt ) {
        $api_key = $this->get_api_key();
        if ( '' === $api_key ) {
            return new WP_Error( 'wpranklab_ai_no_key', __( 'OpenAI API key is not configured.', 'wpranklab' ) );
        }
        
        $endpoint = 'https://api.openai.com/v1/chat/completions';
        
        $body = array(
            'model'    => 'gpt-4.1-mini',
            'messages' => array(
                array(
                    'role'    => 'system',
                    'content' => 'You are a helpful assistant that writes concise, SEO-aware content for websites, optimized for AI-driven search engines.',
                ),
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            ),
            'temperature' => 0.6,
            'max_tokens'  => 700,
        );
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        );
        
        $response = wp_remote_post( $endpoint, $args );
        
        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'wpranklab_ai_http_error',
                sprintf(
                    /* translators: %s: error message */
                    __( 'Error calling OpenAI API: %s', 'wpranklab' ),
                    $response->get_error_message()
                    )
                );
        }
        
        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );
        
        if ( 200 !== $code || empty( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error(
                'wpranklab_ai_bad_response',
                __( 'OpenAI API returned an unexpected response.', 'wpranklab' )
                );
        }
        
        $text = trim( (string) $data['choices'][0]['message']['content'] );
        
        return $text;
    }
}
