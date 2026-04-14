<?php
/**
 * Lean_Text_Processor
 *
 * Utilities for HTML text extraction, rewrite verification and paragraph formatting.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Lean_Text_Processor {

    private int $min_length;
    private int $sim_threshold;

    public function __construct( array $settings = [] ) {
        $this->min_length    = absint( $settings['min_len'] ?? 300 );
        $this->sim_threshold = max( 30, min( 80, absint( $settings['sim_threshold'] ?? 65 ) ) );
    }

    public function get_min_length(): int    { return $this->min_length; }
    public function get_sim_threshold(): int { return $this->sim_threshold; }

    /**
     * Extract clean plain text from raw HTML.
     * Removes scripts, styles, navigation and other noise nodes,
     * then prefers article/main/entry-content zones.
     */
    public function extract_plain_text( string $html ): string {
        if ( strlen( $html ) < 100 ) return '';

        $html = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $html );
        $html = preg_replace( '#<!--.*?-->#s', '', $html );

        $enc = mb_detect_encoding( $html, [ 'UTF-8', 'ISO-8859-1', 'Windows-1252' ], true );
        if ( $enc && $enc !== 'UTF-8' ) {
            $html = mb_convert_encoding( $html, 'UTF-8', $enc );
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors( true );
        if ( ! $dom->loadHTML( '<?xml encoding="UTF-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ) {
            libxml_clear_errors();
            return '';
        }
        libxml_clear_errors();

        $xpath = new DOMXPath( $dom );

        // Remove noise nodes
        $noise = [
            '//script', '//style', '//nav', '//footer', '//aside',
            '//div[contains(@class,"share") or contains(@class,"social") or contains(@class,"navigation") or contains(@class,"copyright")]',
            '//*[contains(text(),"prevPageLabel") or contains(text(),"nextPageLabel") or contains(text(),"Condividi") or contains(text(),"Riproduzione riservata") or contains(text(),"Copyright")]',
        ];
        foreach ( $noise as $q ) {
            $nodes = $xpath->query( $q );
            if ( $nodes ) {
                foreach ( iterator_to_array( $nodes ) as $node ) {
                    if ( $node->parentNode ) $node->parentNode->removeChild( $node );
                }
            }
        }

        // Try preferred content zones
        $zones = [
            '//article',
            '//main',
            '//div[contains(@class,"entry-content") or contains(@class,"post-content")]',
            '//div[@id="content"]',
        ];
        foreach ( $zones as $q ) {
            $nodes = $xpath->query( $q );
            if ( $nodes && $nodes->length > 0 ) {
                $text = trim( preg_replace( '/\s+/u', ' ', $nodes->item( 0 )->textContent ) );
                if ( strlen( $text ) >= 100 ) return $text;
            }
        }

        $body = $xpath->query( '//body' )->item( 0 );
        return $body ? trim( preg_replace( '/\s+/u', ' ', $body->textContent ) ) : '';
    }

    /**
     * Return true when the rewritten text is sufficiently different from the original.
     */
    public function verify_rewrite( string $original, string $rewritten ): bool {
        if ( empty( $rewritten ) || strlen( $rewritten ) < 100 ) return false;

        $a = strtolower( strip_tags( $original ) );
        $b = strtolower( strip_tags( $rewritten ) );
        similar_text( $a, $b, $percent );

        $ok = $percent <= $this->sim_threshold;
        $this->log( sprintf( 'Similarità: %.1f%% (%s)', $percent, $ok ? 'OK' : 'RIFIUTATO' ) );
        return $ok;
    }

    /**
     * Convert plain text to simple HTML paragraphs (fallback when AI fails).
     */
    public function format_as_paragraphs( string $text ): string {
        $text = trim( $text );
        if ( empty( $text ) ) return '';

        $parts = [];
        foreach ( preg_split( '/\n\s*\n|\r\n\r\n|(?<=[.!?])\s+(?=[A-ZÀ-Û])/', $text ) as $p ) {
            $p = trim( preg_replace( '/\s+/u', ' ', $p ) );
            $p = trim( preg_replace( '/(prevPageLabel|nextPageLabel|Condividi|Riproduzione riservata|Copyright).*/i', '', $p ) );
            if ( strlen( $p ) >= 50 ) {
                $parts[] = '<p>' . wp_kses_post( $p ) . '</p>';
            }
        }

        return $parts
            ? implode( "\n\n", $parts )
            : '<p>' . wp_kses_post( wp_trim_words( $text, 50 ) ) . '</p>';
    }

    /** Strip markdown fences and AI preambles from output. */
    public function clean_ai_output( string $text ): string {
        $text = preg_replace( '/^```[\w]*\s*/m', '', $text );
        $text = preg_replace( '/\s*```$/m', '', $text );
        $text = preg_replace( '/^.*?(?=<h[2-6]|<p)/is', '', $text );
        return trim( $text );
    }

    protected function log( string $msg ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Lean Autopost] ' . $msg );
        }
    }
}
