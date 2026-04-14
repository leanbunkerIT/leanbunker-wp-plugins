<?php
/**
 * Lean_Feed_Campaign
 *
 * Processes a feed/sitemap campaign: fetches unprocessed URLs, rewrites them
 * with AI and publishes them as WordPress posts.
 *
 * New in v4: configurable `rotation_interval` (minutes) replaces the
 * hard-coded 5-minute minimum.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Lean_Feed_Campaign {

    const OPT_LAST_PREFIX = 'lean_autopost_sm_last_run_';
    const OPT_PROCESSED   = 'lean_autopost_processed_urls';

    private Lean_AI_Client      $ai;
    private Lean_Text_Processor $text;

    public function __construct( Lean_AI_Client $ai, Lean_Text_Processor $text ) {
        $this->ai   = $ai;
        $this->text = $text;
    }

    /**
     * Run the campaign only if the configured rotation interval has elapsed.
     */
    public function maybe_process( array $cfg, string $id ): void {
        $interval_minutes = max( 5, absint( $cfg['rotation_interval'] ?? 60 ) );
        $last_run         = (int) get_option( self::OPT_LAST_PREFIX . $id, 0 );

        if ( time() - $last_run < $interval_minutes * 60 ) return;

        $this->process( $cfg, $id );
    }

    /**
     * Run the campaign immediately, bypassing the rotation check.
     * Used for manual "Run now" triggered from the admin.
     */
    public function process( array $cfg, string $id ): void {
        if ( empty( $cfg['sitemap_url'] ) ) return;

        $urls = $this->parse_sitemap( $cfg['sitemap_url'] );
        if ( empty( $urls ) ) return;

        $batch     = max( 1, min( 5, absint( $cfg['batch_size'] ?? 1 ) ) );
        $cache     = get_option( self::OPT_PROCESSED, [] );
        $published = 0;

        foreach ( $urls as $url ) {
            if ( $published >= $batch ) break;
            if ( isset( $cache[ $url ] ) ) continue;

            $body = $this->fetch_url( $url );
            if ( ! $body ) {
                $this->mark_processed( $url );
                continue;
            }

            $plain = $this->text->extract_plain_text( $body );
            if ( strlen( $plain ) < 100 ) {
                $this->mark_processed( $url );
                continue;
            }

            $title   = wp_trim_words( $plain, 12 );
            $content = $this->rewrite( $cfg, $plain );

            // Optional AI title
            if ( ! empty( $cfg['change_title'] ) && strlen( $plain ) > 50 ) {
                $t = $this->ai->call(
                    'SOLO un titolo giornalistico italiano (max 100 caratteri). NIENTE ALTRO.',
                    wp_trim_words( $plain, 300 ),
                    0.7
                );
                $t = trim( wp_strip_all_tags( $t ) );
                if ( strlen( $t ) >= 5 && strlen( $t ) <= 100 ) {
                    $title = sanitize_text_field( $t );
                }
            }

            // Optional source citation
            if ( ! empty( $cfg['cite_source'] ) ) {
                $host     = wp_parse_url( $url, PHP_URL_HOST );
                $content .= "\n\n<p><em>" . sprintf(
                    /* translators: %s: domain name */
                    __( 'Fonte: %s', 'lean-autopost' ),
                    esc_html( $host )
                ) . '</em></p>';
            }

            if ( $this->publish( $cfg, $title, $content ) ) {
                $this->mark_processed( $url );
                update_option( self::OPT_LAST_PREFIX . $id, time() );
                $published++;
                $this->log( "Feed [{$id}] pubblicato: {$title}" );
            }
        }

        $this->log( "Feed [{$id}] ciclo completato: {$published}/{$batch}" );
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function rewrite( array $cfg, string $plain ): string {
        $sys = "Sei un giornalista professionista italiano. DEVI riscrivere COMPLETAMENTE questa notizia:\n\n"
             . "REGOLE OBBLIGATORIE:\n"
             . "1. CAMBIA tutte le frasi, la struttura e il lessico\n"
             . "2. MANTIENI solo i fatti essenziali\n"
             . "3. OUTPUT: SOLO HTML WordPress (<h2> sottotitoli, <p> paragrafi)\n"
             . "4. VIETATO copiare frasi dall'originale\n"
             . "5. NIENTE markdown, saluti, copyright, note legali\n"
             . "6. Inizia direttamente con <h2> o <p>\n"
             . "7. Scrivi almeno 3–4 paragrafi ben strutturati\n";

        if ( ! empty( $cfg['custom_prompt'] ) ) {
            $sys .= "\nISTRUZIONI PERSONALIZZATE: " . trim( $cfg['custom_prompt'] );
        }

        $raw = $this->ai->call( $sys, $plain, 0.5 );

        if ( ! empty( $raw ) ) {
            $clean = $this->text->clean_ai_output( $raw );
            if (
                strlen( $clean ) >= $this->text->get_min_length()
                && preg_match( '/<(h[2-6]|p|ul)[\s>]/i', $clean )
                && $this->text->verify_rewrite( $plain, $clean )
            ) {
                return $clean;
            }
        }

        $this->log( "Feed: AI fallback su formattazione automatica" );
        return $this->text->format_as_paragraphs( $plain );
    }

    private function parse_sitemap( string $url ): array {
        $res = wp_remote_get( $url, [ 'timeout' => 20, 'sslverify' => false ] );
        if ( is_wp_error( $res ) || empty( wp_remote_retrieve_body( $res ) ) ) return [];

        libxml_use_internal_errors( true );
        $xml = @simplexml_load_string( wp_remote_retrieve_body( $res ) );
        libxml_clear_errors();

        if ( ! $xml ) return [];

        $urls = [];
        if ( $xml->getName() === 'sitemapindex' ) {
            foreach ( $xml->sitemap as $sub ) {
                $urls = array_merge( $urls, $this->parse_sitemap( (string) $sub->loc ) );
            }
        } elseif ( $xml->getName() === 'urlset' ) {
            foreach ( $xml->url as $u ) {
                $urls[] = (string) $u->loc;
            }
        }

        return array_unique( $urls );
    }

    private function fetch_url( string $url ): string {
        $res = wp_remote_get( $url, [ 'timeout' => 15, 'sslverify' => false ] );
        return ! is_wp_error( $res ) ? (string) wp_remote_retrieve_body( $res ) : '';
    }

    private function mark_processed( string $url ): void {
        $cache         = get_option( self::OPT_PROCESSED, [] );
        $cache[ $url ] = time();

        if ( count( $cache ) > 10000 ) {
            arsort( $cache );
            $cache = array_slice( $cache, 0, 10000, true );
        }

        update_option( self::OPT_PROCESSED, $cache );
    }

    private function publish( array $cfg, string $title, string $content ): bool {
        if ( empty( $title ) || empty( $content ) ) return false;

        $post_id = wp_insert_post( [
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => $cfg['post_type'] ?? 'post',
            'post_author'  => 1,
        ] );

        if ( ! $post_id || is_wp_error( $post_id ) ) return false;

        $this->assign_terms( $post_id, $cfg );
        return true;
    }

    private function assign_terms( int $post_id, array $cfg ): void {
        if ( ! empty( $cfg['taxonomy'] ) && ! empty( $cfg['term'] ) ) {
            $term = get_term_by( 'slug', $cfg['term'], $cfg['taxonomy'] );
            if ( $term ) wp_set_post_terms( $post_id, [ $term->term_id ], $cfg['taxonomy'] );
        } elseif ( ! empty( $cfg['category'] ) ) {
            $c   = term_exists( $cfg['category'], 'category' );
            $cid = is_array( $c ) ? (int) $c['term_id'] : (int) $c;
            if ( ! $cid ) $cid = (int) wp_create_category( $cfg['category'] );
            if ( $cid ) wp_set_post_categories( $post_id, [ $cid ] );
        }
    }

    private function log( string $msg ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Lean Autopost] ' . $msg );
        }
    }
}
