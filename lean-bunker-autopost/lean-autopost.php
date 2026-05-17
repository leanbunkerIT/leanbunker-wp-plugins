<?php
/**
 * Plugin Name:  Lean Autopost
 * Description:  Pubblica articoli automatici da Feed/Sitemap con rotazione configurabile e da Liste di Titoli con scheduling per giorni e orari specifici. Architettura modulare.
 * Version:      4.0.0
 * Author:       Riccardo Bastillo
 * Requires at least: WordPress 5.0
 * Requires PHP: 8.0
 * Text Domain:  lean-autopost
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( defined( 'LEAN_AUTOPOST_LOADED' ) ) return;
define( 'LEAN_AUTOPOST_LOADED', true );
define( 'LEAN_AUTOPOST_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEAN_AUTOPOST_VER', '4.0.0' );

// Load modules
foreach ( [
    'class-text-processor',
    'class-ai-client',
    'class-feed-campaign',
    'class-title-campaign',
    'class-admin',
] as $module ) {
    require_once LEAN_AUTOPOST_DIR . "includes/{$module}.php";
}

/**
 * Lean_Autopost
 *
 * Bootstrap class. Wires WordPress hooks, owns the cron callback and exposes
 * helpers consumed by the sub-classes.
 */
class Lean_Autopost {

    /** WordPress option keys (kept identical to v3 for backward compatibility). */
    const OPT_CAMPAIGNS = 'lean_autopost_sitemaps';
    const OPT_PROCESSED = 'lean_autopost_processed_urls';
    const OPT_SETTINGS  = 'lean_autopost_settings';

    /** WP cron hook name (kept identical to v3 so existing schedules keep firing). */
    const CRON_HOOK     = 'lean_autopost_run_sitemaps';

    /** Custom cron-schedule key. */
    const CRON_SCHEDULE = 'lean_autopost_15min';

    private Lean_Admin $admin;

    public function __construct() {
        add_filter( 'cron_schedules', [ $this, 'register_schedule' ] );
        add_action( self::CRON_HOOK,  [ $this, 'cron_run' ] );
        $this->admin = new Lean_Admin( $this );
    }

    // -------------------------------------------------------------------------
    // Cron schedule
    // -------------------------------------------------------------------------

    /** Register the 15-minute interval used by this plugin. */
    public function register_schedule( array $schedules ): array {
        $schedules[ self::CRON_SCHEDULE ] = [
            'interval' => 900,
            'display'  => __( 'Ogni 15 minuti', 'lean-autopost' ),
        ];
        return $schedules;
    }

    public static function activate(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        // Schedule fires every 15 min; individual campaigns control their own frequency.
        wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    // -------------------------------------------------------------------------
    // Accessors (used by sub-classes)
    // -------------------------------------------------------------------------

    public function get_settings(): array {
        return get_option( self::OPT_SETTINGS, [] );
    }

    public function get_campaigns(): array {
        return get_option( self::OPT_CAMPAIGNS, [] );
    }

    public function get_admin(): Lean_Admin {
        return $this->admin;
    }

    // -------------------------------------------------------------------------
    // Cron callback — dispatches to the correct campaign processor
    // -------------------------------------------------------------------------

    public function cron_run(): void {
        if ( get_transient( 'lean_autopost_cron_lock' ) ) return;
        set_transient( 'lean_autopost_cron_lock', 1, 60 );

        try {
            $campaigns = $this->get_campaigns();
            if ( empty( $campaigns ) ) return;

            $settings = $this->get_settings();
            $api_key  = $settings['together_api_key'] ?? '';
            if ( empty( $api_key ) ) return;

            $ai   = new Lean_AI_Client( $api_key, $settings['qwen_model'] ?? 'Qwen/Qwen2.5-7B-Instruct-Turbo' );
            $text = new Lean_Text_Processor( $settings );

            foreach ( $campaigns as $id => $cfg ) {
                // Backward compat: old entries without 'type' are treated as feed campaigns.
                $type = $cfg['type'] ?? 'feed';

                if ( $type === 'feed' ) {
                    ( new Lean_Feed_Campaign( $ai, $text ) )->maybe_process( $cfg, $id );
                } elseif ( $type === 'title' ) {
                    ( new Lean_Title_Campaign( $ai, $text ) )->maybe_process( $cfg, $id );
                }
            }
        } finally {
            delete_transient( 'lean_autopost_cron_lock' );
        }
    }

    // -------------------------------------------------------------------------
    // Manual run (called from admin "Esegui" action, bypasses schedule)
    // -------------------------------------------------------------------------

    public function run_campaign( array $cfg, string $id ): void {
        $settings = $this->get_settings();
        $api_key  = $settings['together_api_key'] ?? '';
        if ( empty( $api_key ) ) return;

        $ai   = new Lean_AI_Client( $api_key, $settings['qwen_model'] ?? 'Qwen/Qwen2.5-7B-Instruct-Turbo' );
        $text = new Lean_Text_Processor( $settings );
        $type = $cfg['type'] ?? 'feed';

        if ( $type === 'feed' ) {
            ( new Lean_Feed_Campaign( $ai, $text ) )->process( $cfg, $id );
        } elseif ( $type === 'title' ) {
            ( new Lean_Title_Campaign( $ai, $text ) )->process( $cfg, $id );
        }
    }
}

// Bootstrap
$lean_autopost = new Lean_Autopost();
register_activation_hook(   __FILE__, [ 'Lean_Autopost', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Lean_Autopost', 'deactivate' ] );
add_action( 'wp_ajax_lean_autopost_terms', fn() => $lean_autopost->get_admin()->ajax_terms() );
