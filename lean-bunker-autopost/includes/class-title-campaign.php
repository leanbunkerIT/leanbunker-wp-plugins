<?php
/**
 * Lean_Title_Campaign
 *
 * Generates full articles from a list of titles using AI and publishes them
 * on a configurable weekly schedule (specific days + time of day).
 *
 * Schedule logic:
 *  - Admin configures: days[] (e.g. ['monday','friday']) + schedule_time ('09:00').
 *  - The cron (every 15 min) calls maybe_process().
 *  - maybe_process() checks: today ∈ days AND abs(now − scheduled_ts) ≤ WINDOW_SECONDS
 *    AND campaign has not already run in this window.
 *  - If all checks pass, process() publishes `posts_per_run` articles.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Lean_Title_Campaign {

    /** Seconds on either side of the scheduled time we consider "now". */
    const WINDOW_SECONDS  = 840; // 14 min — fits inside the 15-min cron window

    const OPT_LAST_PREFIX = 'lean_autopost_title_last_';
    const OPT_USED_PREFIX = 'lean_autopost_title_used_';

    private Lean_AI_Client      $ai;
    private Lean_Text_Processor $text;

    public function __construct( Lean_AI_Client $ai, Lean_Text_Processor $text ) {
        $this->ai   = $ai;
        $this->text = $text;
    }

    /**
     * Run the campaign only if the current day/time matches the schedule
     * and the campaign has not already run in this time-window.
     */
    public function maybe_process( array $cfg, string $id ): void {
        if ( ! $this->is_scheduled_now( $cfg, $id ) ) return;

        $this->process( $cfg, $id );
    }

    /**
     * Run the campaign immediately, bypassing the schedule check.
     * Used for manual "Run now" from the admin.
     */
    public function process( array $cfg, string $id ): void {
        $pending   = $this->get_pending_titles( $cfg, $id );
        $per_run   = max( 1, min( 5, absint( $cfg['posts_per_run'] ?? 1 ) ) );
        $published = 0;

        foreach ( $pending as $title ) {
            if ( $published >= $per_run ) break;

            $content = $this->generate_content( $cfg, $title );
            if ( empty( $content ) ) {
                $this->log( "Title [{$id}] AI vuoto per: {$title}" );
                continue;
            }

            if ( $this->publish( $cfg, $title, $content ) ) {
                $this->mark_title_used( $title, $id );
                $published++;
                $this->log( "Title [{$id}] pubblicato: {$title}" );
            }
        }

        if ( $published > 0 ) {
            update_option( self::OPT_LAST_PREFIX . $id, time() );
        }

        $this->log( "Title [{$id}] ciclo: {$published}/{$per_run}" );
    }

    // -----------------------------------------------------------------------
    // Schedule check
    // -----------------------------------------------------------------------

    private function is_scheduled_now( array $cfg, string $id ): bool {
        $days = $cfg['schedule_days'] ?? [];
        $time = $cfg['schedule_time'] ?? '';

        if ( empty( $days ) || empty( $time ) ) return false;

        // Use site's configured timezone
        $now      = current_time( 'timestamp' );
        $day_name = strtolower( date( 'l', $now ) ); // e.g. 'monday'

        if ( ! in_array( $day_name, $days, true ) ) return false;

        // Build a Unix timestamp for today at the configured HH:MM
        $scheduled_ts = strtotime( date( 'Y-m-d', $now ) . ' ' . $time );
        if ( ! $scheduled_ts ) return false;

        // We are outside the publication window
        if ( abs( $now - $scheduled_ts ) > self::WINDOW_SECONDS ) return false;

        // Already ran in this window — avoid double-publishing
        $last = (int) get_option( self::OPT_LAST_PREFIX . $id, 0 );
        if ( abs( $last - $scheduled_ts ) <= self::WINDOW_SECONDS ) return false;

        return true;
    }

    // -----------------------------------------------------------------------
    // Title helpers
    // -----------------------------------------------------------------------

    /** Return titles that have not been published yet. */
    private function get_pending_titles( array $cfg, string $id ): array {
        $all  = array_filter( array_map( 'trim', explode( "\n", $cfg['titles'] ?? '' ) ) );
        $used = (array) get_option( self::OPT_USED_PREFIX . $id, [] );
        return array_values( array_diff( $all, $used ) );
    }

    private function mark_title_used( string $title, string $id ): void {
        $used   = (array) get_option( self::OPT_USED_PREFIX . $id, [] );
        $used[] = $title;
        update_option( self::OPT_USED_PREFIX . $id, $used );
    }

    // -----------------------------------------------------------------------
    // AI content generation
    // -----------------------------------------------------------------------

    private function generate_content( array $cfg, string $title ): string {
        $sys = "Sei un giornalista professionista italiano.\n"
             . "Scrivi un articolo completo di almeno 4 paragrafi basandoti SOLO sul titolo fornito.\n"
             . "REGOLE:\n"
             . "1. OUTPUT: SOLO HTML WordPress (<h2> sottotitoli, <p> paragrafi, <ul>/<li> liste se utili)\n"
             . "2. NIENTE markdown, introduzioni, saluti, copyright\n"
             . "3. Inizia direttamente con <h2> o <p>\n"
             . "4. Approfondisci l'argomento con fatti, contesto e analisi\n";

        if ( ! empty( $cfg['custom_prompt'] ) ) {
            $sys .= "\nISTRUZIONI PERSONALIZZATE: " . trim( $cfg['custom_prompt'] );
        }

        $raw = $this->ai->call( $sys, $title, 0.6 );
        if ( empty( $raw ) ) return '';

        $clean = $this->text->clean_ai_output( $raw );

        if ( strlen( $clean ) >= 300 && preg_match( '/<(h[2-6]|p)[\s>]/i', $clean ) ) {
            return $clean;
        }

        return '';
    }

    // -----------------------------------------------------------------------
    // Publishing
    // -----------------------------------------------------------------------

    private function publish( array $cfg, string $title, string $content ): bool {
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
