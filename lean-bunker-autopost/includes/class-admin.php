<?php
/**
 * Lean_Admin
 *
 * All WordPress admin UI: menu, campaign list, new/edit forms, settings page
 * and AJAX handler for taxonomy→terms dynamic loading.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Lean_Admin {

    private Lean_Autopost $plugin;

    /** Days available for title-campaign scheduling. */
    private const DAYS = [
        'monday'    => 'Lunedì',
        'tuesday'   => 'Martedì',
        'wednesday' => 'Mercoledì',
        'thursday'  => 'Giovedì',
        'friday'    => 'Venerdì',
        'saturday'  => 'Sabato',
        'sunday'    => 'Domenica',
    ];

    /** Feed-rotation interval options (minutes → label). */
    private const ROTATION_OPTS = [
        5    => '5 min',
        15   => '15 min',
        30   => '30 min',
        60   => '1 ora',
        120  => '2 ore',
        240  => '4 ore',
        360  => '6 ore',
        720  => '12 ore',
        1440 => '24 ore',
    ];

    public function __construct( Lean_Autopost $plugin ) {
        $this->plugin = $plugin;
        add_action( 'admin_menu', [ $this, 'register_menus' ] );
        add_action( 'admin_init', [ $this, 'handle_requests' ] );
    }

    // =========================================================================
    // Menus
    // =========================================================================

    public function register_menus(): void {
        add_menu_page(
            __( 'Lean Autopost', 'lean-autopost' ),
            __( 'Lean Autopost', 'lean-autopost' ),
            'manage_options',
            'lean-autopost',
            [ $this, 'render_list' ],
            'dashicons-admin-post'
        );
        add_submenu_page(
            'lean-autopost',
            __( 'Impostazioni', 'lean-autopost' ),
            __( 'Impostazioni', 'lean-autopost' ),
            'manage_options',
            'lean-autopost-settings',
            [ $this, 'render_settings' ]
        );
    }

    // =========================================================================
    // Request handling (admin_init)
    // =========================================================================

    public function handle_requests(): void {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) return;

        $page = $_GET['page'] ?? '';
        if ( ! in_array( $page, [ 'lean-autopost', 'lean-autopost-settings' ], true ) ) return;

        if ( $page === 'lean-autopost' ) {
            $this->handle_campaign_actions();
        }

        if ( $page === 'lean-autopost-settings' && isset( $_POST['save_api_settings'] ) ) {
            $this->handle_settings_save();
        }
    }

    // -------------------------------------------------------------------------
    // Campaign CRUD + run/reset
    // -------------------------------------------------------------------------

    private function handle_campaign_actions(): void {

        // Save (create / update)
        if ( isset( $_POST['save_campaign'] ) ) {
            if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'lean_autopost_save' ) ) {
                wp_die( __( 'Security check failed.', 'lean-autopost' ) );
            }
            $id   = sanitize_key( $_POST['id'] ?? uniqid( 'cp_' ) );
            $type = sanitize_text_field( $_POST['campaign_type'] ?? 'feed' );
            $data = ( $type === 'title' )
                ? $this->sanitize_title_campaign()
                : $this->sanitize_feed_campaign();
            $data['type'] = $type;

            $campaigns        = $this->plugin->get_campaigns();
            $campaigns[ $id ] = $data;
            update_option( Lean_Autopost::OPT_CAMPAIGNS, $campaigns );

            wp_safe_redirect( admin_url( 'admin.php?page=lean-autopost&saved=1' ) );
            exit;
        }

        // Delete
        if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && $_GET['action'] === 'delete_campaign' ) {
            if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'del' ) ) {
                wp_die( __( 'Security check failed.', 'lean-autopost' ) );
            }
            $id        = sanitize_key( $_GET['id'] );
            $campaigns = $this->plugin->get_campaigns();
            unset( $campaigns[ $id ] );
            update_option( Lean_Autopost::OPT_CAMPAIGNS, $campaigns );
            wp_safe_redirect( admin_url( 'admin.php?page=lean-autopost' ) );
            exit;
        }

        // Manual run
        if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && $_GET['action'] === 'run_campaign' ) {
            if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'run' ) ) {
                wp_die( __( 'Security check failed.', 'lean-autopost' ) );
            }
            $id        = sanitize_key( $_GET['id'] );
            $campaigns = $this->plugin->get_campaigns();
            if ( isset( $campaigns[ $id ] ) ) {
                $this->plugin->run_campaign( $campaigns[ $id ], $id );
            }
            wp_safe_redirect( admin_url( 'admin.php?page=lean-autopost&ran=1' ) );
            exit;
        }

        // Reset used titles
        if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && $_GET['action'] === 'reset_titles' ) {
            if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'reset' ) ) {
                wp_die( __( 'Security check failed.', 'lean-autopost' ) );
            }
            $id = sanitize_key( $_GET['id'] );
            delete_option( Lean_Title_Campaign::OPT_USED_PREFIX . $id );
            wp_safe_redirect( admin_url( 'admin.php?page=lean-autopost&reset=1' ) );
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // Sanitization
    // -------------------------------------------------------------------------

    private function sanitize_feed_campaign(): array {
        return [
            'sitemap_url'       => esc_url_raw( trim( $_POST['sitemap_url'] ?? '' ) ),
            'post_type'         => sanitize_text_field( $_POST['post_type'] ?? 'post' ),
            'taxonomy'          => sanitize_text_field( $_POST['taxonomy'] ?? '' ),
            'term'              => sanitize_text_field( $_POST['term'] ?? '' ),
            'category'          => sanitize_text_field( $_POST['category'] ?? '' ),
            'custom_prompt'     => wp_kses_post( $_POST['custom_prompt'] ?? '' ),
            'change_title'      => ! empty( $_POST['change_title'] ),
            'batch_size'        => max( 1, min( 5, absint( $_POST['batch_size'] ?? 1 ) ) ),
            'cite_source'       => ! empty( $_POST['cite_source'] ),
            'rotation_interval' => max( 5, absint( $_POST['rotation_interval'] ?? 60 ) ),
        ];
    }

    private function sanitize_title_campaign(): array {
        // Sanitize title list: strip HTML, limit line length
        $raw_lines = explode( "\n", wp_unslash( $_POST['titles'] ?? '' ) );
        $titles    = implode(
            "\n",
            array_filter( array_map( fn( $t ) => substr( sanitize_text_field( $t ), 0, 200 ), $raw_lines ) )
        );

        // Validate days
        $allowed_days = array_keys( self::DAYS );
        $raw_days     = (array) ( $_POST['schedule_days'] ?? [] );
        $days         = array_values( array_intersect(
            array_map( 'sanitize_text_field', $raw_days ),
            $allowed_days
        ) );

        // Validate HH:MM
        $time = sanitize_text_field( $_POST['schedule_time'] ?? '09:00' );
        if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
            $time = '09:00';
        }

        return [
            'titles'        => $titles,
            'schedule_days' => $days,
            'schedule_time' => $time,
            'posts_per_run' => max( 1, min( 5, absint( $_POST['posts_per_run'] ?? 1 ) ) ),
            'post_type'     => sanitize_text_field( $_POST['post_type'] ?? 'post' ),
            'taxonomy'      => sanitize_text_field( $_POST['taxonomy'] ?? '' ),
            'term'          => sanitize_text_field( $_POST['term'] ?? '' ),
            'category'      => sanitize_text_field( $_POST['category'] ?? '' ),
            'custom_prompt' => wp_kses_post( $_POST['custom_prompt'] ?? '' ),
        ];
    }

    private function handle_settings_save(): void {
        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'lean_autopost_settings' ) ) {
            wp_die( __( 'Security check failed.', 'lean-autopost' ) );
        }
        update_option( Lean_Autopost::OPT_SETTINGS, [
            'together_api_key' => sanitize_text_field( $_POST['together_api_key'] ?? '' ),
            'qwen_model'       => sanitize_text_field( $_POST['qwen_model'] ?: 'Qwen/Qwen2.5-7B-Instruct-Turbo' ),
            'min_len'          => max( 100, absint( $_POST['min_len'] ?? 300 ) ),
            'sim_threshold'    => max( 30, min( 80, absint( $_POST['sim_threshold'] ?? 65 ) ) ),
        ] );
        wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=lean-autopost-settings' ) ) );
        exit;
    }

    // =========================================================================
    // Render: campaign list
    // =========================================================================

    public function render_list(): void {
        $base = admin_url( 'admin.php?page=lean-autopost' );

        if ( isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Campagna salvata.', 'lean-autopost' ) . '</p></div>';
        }
        if ( isset( $_GET['ran'] ) ) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Elaborazione avviata.', 'lean-autopost' ) . '</p></div>';
        }
        if ( isset( $_GET['reset'] ) ) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Titoli usati azzerati.', 'lean-autopost' ) . '</p></div>';
        }

        // Show edit/new form
        if ( isset( $_GET['edit'] ) || isset( $_GET['new'] ) ) {
            $id        = isset( $_GET['edit'] ) ? sanitize_key( $_GET['edit'] ) : null;
            $campaigns = $this->plugin->get_campaigns();
            $this->render_form( $id, $campaigns[ $id ] ?? [] );
            return;
        }

        $campaigns = $this->plugin->get_campaigns();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?= esc_html__( 'Lean Autopost – Campagne', 'lean-autopost' ) ?></h1>
            <a href="<?= esc_url( add_query_arg( 'new', '1', $base ) ) ?>" class="page-title-action">
                + <?= esc_html__( 'Nuova campagna', 'lean-autopost' ) ?>
            </a>
            <hr class="wp-header-end">

            <?php if ( empty( $campaigns ) ) : ?>
                <p><?= esc_html__( 'Nessuna campagna configurata.', 'lean-autopost' ) ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr>
                        <th><?= esc_html__( 'Dettagli', 'lean-autopost' ) ?></th>
                        <th style="width:80px;"><?= esc_html__( 'Tipo', 'lean-autopost' ) ?></th>
                        <th><?= esc_html__( 'Programmazione', 'lean-autopost' ) ?></th>
                        <th style="width:130px;"><?= esc_html__( 'Ultima esecuzione', 'lean-autopost' ) ?></th>
                        <th style="width:220px;"><?= esc_html__( 'Azioni', 'lean-autopost' ) ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $campaigns as $id => $cfg ) :
                        $is_feed = ( ( $cfg['type'] ?? 'feed' ) === 'feed' );

                        $last_key = $is_feed
                            ? Lean_Feed_Campaign::OPT_LAST_PREFIX . $id
                            : Lean_Title_Campaign::OPT_LAST_PREFIX . $id;
                        $last     = (int) get_option( $last_key, 0 );
                        $last_str = $last
                            ? human_time_diff( $last, time() ) . ' ' . __( 'fa', 'lean-autopost' )
                            : __( 'Mai', 'lean-autopost' );

                        $detail = $is_feed
                            ? esc_html( $cfg['sitemap_url'] ?? '—' )
                            : esc_html( wp_trim_words( str_replace( "\n", ', ', $cfg['titles'] ?? '' ), 8 ) );

                        if ( $is_feed ) {
                            $interval = absint( $cfg['rotation_interval'] ?? 60 );
                            $sched    = isset( self::ROTATION_OPTS[ $interval ] )
                                ? self::ROTATION_OPTS[ $interval ]
                                : $interval . ' min';
                            $sched    = sprintf( __( 'Ogni %s', 'lean-autopost' ), $sched );
                        } else {
                            $days  = array_map( 'ucfirst', $cfg['schedule_days'] ?? [] );
                            $sched = $days
                                ? implode( ', ', $days ) . ' — ' . ( $cfg['schedule_time'] ?? '' )
                                : '—';
                        }

                        $edit_url = add_query_arg( 'edit', $id, $base );
                        $del_url  = wp_nonce_url( add_query_arg( [ 'action' => 'delete_campaign', 'id' => $id ], $base ), 'del' );
                        $run_url  = wp_nonce_url( add_query_arg( [ 'action' => 'run_campaign',    'id' => $id ], $base ), 'run' );
                    ?>
                    <tr>
                        <td><?= $detail ?></td>
                        <td><?= $is_feed ? '📡 Feed' : '📝 Titoli' ?></td>
                        <td><?= esc_html( $sched ) ?></td>
                        <td><?= esc_html( $last_str ) ?></td>
                        <td>
                            <a href="<?= esc_url( $edit_url ) ?>"><?= esc_html__( 'Modifica', 'lean-autopost' ) ?></a> |
                            <a href="<?= esc_url( $run_url ) ?>"><?= esc_html__( 'Esegui', 'lean-autopost' ) ?></a> |
                            <a href="<?= esc_url( $del_url ) ?>"
                               onclick="return confirm('<?= esc_js( __( 'Eliminare questa campagna?', 'lean-autopost' ) ) ?>')">
                                <?= esc_html__( 'Elimina', 'lean-autopost' ) ?>
                            </a>
                            <?php if ( ! $is_feed ) :
                                $reset_url = wp_nonce_url( add_query_arg( [ 'action' => 'reset_titles', 'id' => $id ], $base ), 'reset' );
                            ?>
                                | <a href="<?= esc_url( $reset_url ) ?>"
                                     onclick="return confirm('<?= esc_js( __( 'Azzerare i titoli già usati?', 'lean-autopost' ) ) ?>')">
                                    <?= esc_html__( 'Reset titoli', 'lean-autopost' ) ?>
                                  </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // =========================================================================
    // Render: new/edit form
    // =========================================================================

    private function render_form( ?string $id, array $data ): void {
        $base       = admin_url( 'admin.php?page=lean-autopost' );
        $type       = $data['type'] ?? ( isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'feed' );
        $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
        $terms      = ! empty( $data['taxonomy'] ) ? get_terms( $data['taxonomy'], [ 'hide_empty' => false ] ) : [];
        if ( is_wp_error( $terms ) ) $terms = [];
        $ajax_nonce = wp_create_nonce( 'lean_autopost_ajax' );
        ?>
        <div class="wrap">
            <h1><?= $id
                ? esc_html__( 'Modifica campagna', 'lean-autopost' )
                : esc_html__( 'Nuova campagna', 'lean-autopost' ) ?></h1>
            <a href="<?= esc_url( $base ) ?>">&larr; <?= esc_html__( 'Torna alla lista', 'lean-autopost' ) ?></a>

            <form method="post" action="<?= esc_url( $base ) ?>" style="margin-top:20px;" id="lean-campaign-form">
                <?php wp_nonce_field( 'lean_autopost_save', '_wpnonce' ); ?>
                <input type="hidden" name="id" value="<?= esc_attr( $id ?? uniqid( 'cp_' ) ) ?>">

                <!-- Campaign type selector -->
                <table class="form-table">
                    <tr>
                        <th><?= esc_html__( 'Tipo campagna', 'lean-autopost' ) ?></th>
                        <td>
                            <label style="margin-right:16px;">
                                <input type="radio" name="campaign_type" value="feed" id="type-feed"
                                    <?= checked( $type, 'feed', false ) ?>>
                                <?= esc_html__( '📡 Feed / Sitemap', 'lean-autopost' ) ?>
                            </label>
                            <label>
                                <input type="radio" name="campaign_type" value="title" id="type-title"
                                    <?= checked( $type, 'title', false ) ?>>
                                <?= esc_html__( '📝 Lista Titoli', 'lean-autopost' ) ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <!-- Feed-specific fields -->
                <div id="fields-feed" style="display:<?= $type !== 'title' ? 'block' : 'none' ?>;">
                    <h2><?= esc_html__( 'Impostazioni Feed', 'lean-autopost' ) ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="sitemap_url"><?= esc_html__( 'URL Sitemap', 'lean-autopost' ) ?></label></th>
                            <td><input type="url" id="sitemap_url" name="sitemap_url"
                                    value="<?= esc_attr( $data['sitemap_url'] ?? '' ) ?>" class="widefat"></td>
                        </tr>
                        <tr>
                            <th><label for="rotation_interval"><?= esc_html__( 'Intervallo di rotazione', 'lean-autopost' ) ?></label></th>
                            <td>
                                <select id="rotation_interval" name="rotation_interval">
                                    <?php foreach ( self::ROTATION_OPTS as $val => $label ) : ?>
                                        <option value="<?= $val ?>"
                                            <?= selected( absint( $data['rotation_interval'] ?? 60 ), $val, false ) ?>>
                                            <?= esc_html( $label ) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    <?= esc_html__( 'Quanto spesso questa campagna viene elaborata dal cron.', 'lean-autopost' ) ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="batch_size"><?= esc_html__( 'Articoli per ciclo (1–5)', 'lean-autopost' ) ?></label></th>
                            <td><input type="number" id="batch_size" name="batch_size"
                                    min="1" max="5" value="<?= absint( $data['batch_size'] ?? 1 ) ?>"></td>
                        </tr>
                        <tr>
                            <th><?= esc_html__( 'Cambia titolo con AI', 'lean-autopost' ) ?></th>
                            <td><input type="checkbox" name="change_title" value="1"
                                    <?= checked( ! empty( $data['change_title'] ), true, false ) ?>></td>
                        </tr>
                        <tr>
                            <th><?= esc_html__( 'Cita la fonte nel testo', 'lean-autopost' ) ?></th>
                            <td><input type="checkbox" name="cite_source" value="1"
                                    <?= checked( ! empty( $data['cite_source'] ), true, false ) ?>></td>
                        </tr>
                    </table>
                </div>

                <!-- Title-specific fields -->
                <div id="fields-title" style="display:<?= $type === 'title' ? 'block' : 'none' ?>;">
                    <h2><?= esc_html__( 'Impostazioni Lista Titoli', 'lean-autopost' ) ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="titles"><?= esc_html__( 'Titoli (uno per riga)', 'lean-autopost' ) ?></label></th>
                            <td>
                                <textarea id="titles" name="titles" rows="8" class="large-text"
                                    placeholder="<?= esc_attr__( "Es:\nCome funziona la fotosintesi\n10 consigli per dormire meglio\nStoria del Rinascimento italiano", 'lean-autopost' ) ?>"><?= esc_textarea( $data['titles'] ?? '' ) ?></textarea>
                                <p class="description">
                                    <?= esc_html__( "L'AI genererà un articolo completo per ogni titolo. I titoli già usati sono tracciati e non vengono ripubblicati.", 'lean-autopost' ) ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><?= esc_html__( 'Giorni di pubblicazione', 'lean-autopost' ) ?></th>
                            <td>
                                <?php foreach ( self::DAYS as $key => $label ) :
                                    $checked = in_array( $key, $data['schedule_days'] ?? [], true );
                                ?>
                                <label style="margin-right:12px;display:inline-block;">
                                    <input type="checkbox" name="schedule_days[]"
                                        value="<?= esc_attr( $key ) ?>" <?= checked( $checked, true, false ) ?>>
                                    <?= esc_html( $label ) ?>
                                </label>
                                <?php endforeach; ?>
                                <p class="description">
                                    <?= esc_html__( 'La campagna si attiva solo nei giorni selezionati.', 'lean-autopost' ) ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="schedule_time"><?= esc_html__( 'Orario di pubblicazione', 'lean-autopost' ) ?></label></th>
                            <td>
                                <input type="time" id="schedule_time" name="schedule_time"
                                    value="<?= esc_attr( $data['schedule_time'] ?? '09:00' ) ?>">
                                <p class="description">
                                    <?= esc_html__( "Fuso orario del sito. La pubblicazione avviene entro ~15 minuti dall'orario impostato.", 'lean-autopost' ) ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="posts_per_run"><?= esc_html__( 'Articoli per esecuzione (1–5)', 'lean-autopost' ) ?></label></th>
                            <td><input type="number" id="posts_per_run" name="posts_per_run"
                                    min="1" max="5" value="<?= absint( $data['posts_per_run'] ?? 1 ) ?>"></td>
                        </tr>
                    </table>
                </div>

                <!-- Common publication fields -->
                <h2><?= esc_html__( 'Pubblicazione', 'lean-autopost' ) ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="post_type"><?= esc_html__( 'Post Type', 'lean-autopost' ) ?></label></th>
                        <td><select id="post_type" name="post_type"><?= $this->post_type_options( $data['post_type'] ?? 'post' ) ?></select></td>
                    </tr>
                    <tr>
                        <th><label for="tax-select"><?= esc_html__( 'Tassonomia', 'lean-autopost' ) ?></label></th>
                        <td>
                            <select name="taxonomy" id="tax-select">
                                <option value=""><?= esc_html__( '-- Nessuna --', 'lean-autopost' ) ?></option>
                                <?= $this->taxonomy_options( $data['taxonomy'] ?? '', $taxonomies ) ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="term-select"><?= esc_html__( 'Termine', 'lean-autopost' ) ?></label></th>
                        <td>
                            <select name="term" id="term-select">
                                <option value=""><?= esc_html__( '-- Seleziona --', 'lean-autopost' ) ?></option>
                                <?php foreach ( $terms as $t ) : ?>
                                    <option value="<?= esc_attr( $t->slug ) ?>"
                                        <?= selected( $data['term'] ?? '', $t->slug, false ) ?>>
                                        <?= esc_html( $t->name ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="category"><?= esc_html__( 'Categoria (fallback)', 'lean-autopost' ) ?></label></th>
                        <td><input type="text" id="category" name="category"
                                value="<?= esc_attr( $data['category'] ?? '' ) ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="custom_prompt"><?= esc_html__( 'Prompt AI personalizzato', 'lean-autopost' ) ?></label></th>
                        <td>
                            <textarea id="custom_prompt" name="custom_prompt" rows="3" class="large-text"
                                placeholder="<?= esc_attr__( 'Es: Usa tono giornalistico, evidenzia i dati, linguaggio semplice', 'lean-autopost' ) ?>"><?= esc_textarea( $data['custom_prompt'] ?? '' ) ?></textarea>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Salva campagna', 'lean-autopost' ), 'primary', 'save_campaign' ); ?>
            </form>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Toggle feed/title field sections based on campaign type
            var radios = document.querySelectorAll('[name="campaign_type"]');
            function updateFields() {
                var val = (document.querySelector('[name="campaign_type"]:checked') || {}).value;
                document.getElementById('fields-feed').style.display  = (val !== 'title') ? 'block' : 'none';
                document.getElementById('fields-title').style.display = (val === 'title')  ? 'block' : 'none';
            }
            radios.forEach(function (r) { r.addEventListener('change', updateFields); });

            // Taxonomy → terms AJAX
            var tax  = document.getElementById('tax-select');
            var term = document.getElementById('term-select');
            if (tax && term) {
                tax.addEventListener('change', function () {
                    if (!this.value) {
                        term.innerHTML = '<option value=""><?= esc_js( __( '-- Nessuno --', 'lean-autopost' ) ) ?></option>';
                        return;
                    }
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxurl, true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onreadystatechange = function () {
                        if (xhr.readyState === 4 && xhr.status === 200) {
                            try {
                                var data = JSON.parse(xhr.responseText);
                                var html = '<option value=""><?= esc_js( __( '-- Seleziona --', 'lean-autopost' ) ) ?></option>';
                                data.forEach(function (t) {
                                    html += '<option value="' + t.slug + '">' + t.name + '</option>';
                                });
                                term.innerHTML = html;
                            } catch (e) {}
                        }
                    };
                    xhr.send(
                        'action=lean_autopost_terms' +
                        '&_ajax_nonce=<?= esc_js( $ajax_nonce ) ?>' +
                        '&taxonomy=' + encodeURIComponent(this.value)
                    );
                });
            }
        });
        </script>
        <?php
    }

    // =========================================================================
    // Render: settings page
    // =========================================================================

    public function render_settings(): void {
        $s = $this->plugin->get_settings();
        ?>
        <div class="wrap">
            <h1><?= esc_html__( 'Impostazioni Lean Autopost', 'lean-autopost' ) ?></h1>
            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?= esc_html__( 'Impostazioni salvate.', 'lean-autopost' ) ?></p>
                </div>
            <?php endif; ?>
            <form method="post" action="<?= esc_url( admin_url( 'admin.php?page=lean-autopost-settings' ) ) ?>">
                <?php wp_nonce_field( 'lean_autopost_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="together_api_key"><?= esc_html__( 'Together AI API Key', 'lean-autopost' ) ?></label></th>
                        <td><input type="text" id="together_api_key" name="together_api_key"
                                value="<?= esc_attr( $s['together_api_key'] ?? '' ) ?>" class="regular-text" size="50"></td>
                    </tr>
                    <tr>
                        <th><label for="qwen_model"><?= esc_html__( 'Modello AI', 'lean-autopost' ) ?></label></th>
                        <td><input type="text" id="qwen_model" name="qwen_model"
                                value="<?= esc_attr( $s['qwen_model'] ?? 'Qwen/Qwen2.5-7B-Instruct-Turbo' ) ?>" class="regular-text" size="50"></td>
                    </tr>
                    <tr>
                        <th><label for="min_len"><?= esc_html__( 'Lunghezza minima articolo (caratteri)', 'lean-autopost' ) ?></label></th>
                        <td><input type="number" id="min_len" name="min_len"
                                value="<?= absint( $s['min_len'] ?? 300 ) ?>" min="100" max="5000"></td>
                    </tr>
                    <tr>
                        <th><label for="sim_threshold"><?= esc_html__( 'Soglia similarità riscrittura (30–80%)', 'lean-autopost' ) ?></label></th>
                        <td>
                            <input type="number" id="sim_threshold" name="sim_threshold"
                                value="<?= absint( $s['sim_threshold'] ?? 65 ) ?>" min="30" max="80">
                            <p class="description">
                                <?= esc_html__( 'Valori più bassi richiedono riscrittura più aggressiva. Predefinito: 65%.', 'lean-autopost' ) ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Salva', 'lean-autopost' ), 'primary', 'save_api_settings' ); ?>
            </form>
        </div>
        <?php
    }

    // =========================================================================
    // AJAX: taxonomy → terms
    // =========================================================================

    public function ajax_terms(): void {
        check_ajax_referer( 'lean_autopost_ajax', '_ajax_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die();

        $tax = sanitize_text_field( $_POST['taxonomy'] ?? '' );
        if ( ! taxonomy_exists( $tax ) ) {
            wp_send_json( [] );
        }

        $terms = get_terms( $tax, [ 'hide_empty' => false ] );
        wp_send_json(
            is_wp_error( $terms )
                ? []
                : array_map( fn( $t ) => [ 'slug' => $t->slug, 'name' => $t->name ], $terms )
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function post_type_options( string $current ): string {
        return implode( '', array_map(
            fn( $p ) => sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $p->name ),
                selected( $current, $p->name, false ),
                esc_html( $p->labels->singular_name )
            ),
            get_post_types( [ 'public' => true ], 'objects' )
        ) );
    }

    private function taxonomy_options( string $current, array $taxonomies ): string {
        return implode( '', array_map(
            fn( $t ) => sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr( $t->name ),
                selected( $current, $t->name, false ),
                esc_html( $t->labels->singular_name )
            ),
            $taxonomies
        ) );
    }
}
