<?php
/**
* Plugin Name: Lean Bunker Framework
* Description: Costruttore nativo WP con CPT, Campi, Formule, Gruppi, Relazioni, Shortcode Display e Form Frontend.
* Version: 0.0.16
* License: GPL2
*/
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_Native_Builder {
    private static $schema;

    public static function init() {
        self::$schema = get_option( 'wpnb_schema', [] );
        add_action( 'admin_menu', [ self::class, 'menu' ] );
        add_action( 'admin_init', [ self::class, 'handle_save' ] );
        add_action( 'init', [ self::class, 'register' ] );
        add_action( 'add_meta_boxes', [ self::class, 'meta_boxes' ] );
        // ✅ FIX: Aggiunti argomenti 10, 2 per compatibilità WP
        add_action( 'save_post', [ self::class, 'save_meta' ], 10, 2 );
        add_action( 'admin_footer', [ self::class, 'inject_js' ] );
        
        // Shortcode & Frontend
        add_shortcode( 'wpnb_display', [ self::class, 'sc_display' ] );
        add_shortcode( 'wpnb_form',    [ self::class, 'sc_form' ] );
        add_action( 'admin_post_nopriv_wpnb_form_submit', [ self::class, 'handle_frontend_form' ] );
        add_action( 'admin_post_wpnb_form_submit',        [ self::class, 'handle_frontend_form' ] );
    }

    public static function menu() {
        add_menu_page( 'Entity Builder', 'Struttura Dati', 'manage_options', 'wpnb-builder', [ self::class, 'page' ], 'dashicons-admin-tools' );
    }

    public static function page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Costruttore Struttura Dati</h1>
            <p class="description">Definisci entità, campi, relazioni e formule. Clicca "Salva struttura" per applicare.</p>
            <?php if ( isset( $_GET['wpnb-saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>Struttura salvata correttamente.</p></div>
            <?php endif; ?>
            <form id="wpnb-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="wpnb_save">
                <?php wp_nonce_field( 'wpnb_save', 'wpnb_nonce' ); ?>
                <input type="hidden" id="wpnb-payload" name="wpnb_payload">
                <div id="wpnb-cpts" data-schema="<?php echo esc_attr( wp_json_encode( self::$schema ) ); ?>" style="margin-top:20px;"></div>
                <p class="submit">
                    <button type="button" id="wpnb-add-cpt" class="button">Aggiungi entità</button>
                    <button type="submit" class="button button-primary">Salva struttura</button>
                </p>
            </form>
        </div>
        <style>
            #wpnb-cpts { display: grid; grid-template-columns: repeat(auto-fill, minmax(450px, 1fr)); gap: 16px; }
            .wpnb-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,0.04); }
            .wpnb-card-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #eee; background: #f9f9f9; }
            .wpnb-card-body { padding: 12px 16px; }
            .wpnb-field { background: #f9f9f9; border: 1px solid #ddd; padding: 10px; margin-bottom: 8px; border-radius: 3px; }
            .wpnb-row { display: flex; gap: 8px; margin-bottom: 6px; flex-wrap: wrap; align-items: center; }
            .wpnb-field input.regular-text, .wpnb-field select { height: 30px; }
            .wpnb-opts-wrap { background: #fff; border: 1px dashed #ccc; padding: 8px; margin: 6px 0; border-radius: 3px; }
            .wpnb-opt-row { display: flex; gap: 6px; align-items: center; margin-bottom: 4px; }
            .wpnb-group-fields { background: #f0f6fc; border: 1px dashed #2271b1; padding: 10px; margin: 8px 0 0; border-radius: 4px; }
            .wpnb-group-header { font-size: 13px; font-weight: 600; color: #2271b1; margin-bottom: 6px; }
            .wpnb-dynamic-builder { background: #f9f9f9; border: 1px solid #ddd; padding: 12px; border-radius: 4px; margin-bottom: 8px; }
            .wpnb-repeater-row { background: #fff; border: 1px solid #eee; padding: 8px; margin: 6px 0; border-radius: 3px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
            .wpnb-target-input { display:none; margin-top:4px; }
            .wpnb-form-wrap { max-width:600px; margin:20px auto; background:#fff; padding:20px; border:1px solid #ddd; border-radius:4px; }
            .wpnb-form-wrap input, .wpnb-form-wrap select, .wpnb-form-wrap textarea { width:100%; margin-bottom:12px; padding:8px; border:1px solid #8c8f94; border-radius:3px; }
            .wpnb-display { line-height:1.6; } .wpnb-display strong { display:inline-block; min-width:120px; }
        </style>
        <?php
    }

    public static function handle_save() {
        if ( 'wpnb_save' !== ( $_REQUEST['action'] ?? '' ) ) return;
        check_admin_referer( 'wpnb_save', 'wpnb_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permessi insufficienti' );

        $raw = wp_unslash( $_POST['wpnb_payload'] ?? '' );
        $schema = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $schema ) ) wp_die( 'Dati non validi' );

        $clean = array_map( fn( $cpt ) => [
            'id' => sanitize_key( $cpt['id'] ?? '' ),
            'label' => sanitize_text_field( $cpt['label'] ?? '' ),
            'slug'  => sanitize_key( $cpt['slug'] ?? '' ),
            'fields' => self::sanitize_fields( $cpt['fields'] ?? [] )
        ], $schema );

        update_option( 'wpnb_schema', $clean );
        wp_redirect( add_query_arg( 'wpnb-saved', '1', wp_get_referer() ?: admin_url( 'admin.php?page=wpnb-builder' ) ) );
        exit;
    }

    public static function sanitize_fields( $fields ) {
        return array_map( function( $f ) {
            $f['label']   = sanitize_text_field( $f['label'] ?? '' );
            $f['name']    = sanitize_key( $f['name'] ?? '' );
            $allowed      = [ 'text', 'number', 'date', 'select', 'radio', 'formula', 'group', 'builder', 'relation' ];
            $f['type']    = in_array( $f['type'] ?? '', $allowed, true ) ? $f['type'] : 'text';
            $f['formula'] = sanitize_text_field( $f['formula'] ?? '' );
            if ( $f['type'] === 'relation' ) $f['target_cpt'] = sanitize_key( $f['target_cpt'] ?? '' );
            if ( in_array( $f['type'], [ 'select', 'radio' ], true ) ) {
                $f['options'] = array_map( fn( $o ) => [
                    'label' => sanitize_text_field( $o['label'] ?? '' ),
                    'value' => floatval( $o['value'] ?? 0 )
                ], $f['options'] ?? [] );
            }
            if ( $f['type'] === 'group' && ! empty( $f['group_fields'] ) ) {
                $f['group_fields'] = self::sanitize_fields( $f['group_fields'] );
            }
            return $f;
        }, $fields );
    }

    public static function register() {
        foreach ( self::$schema as $cpt ) {
            if ( empty( $cpt['slug'] ) || empty( $cpt['label'] ) ) continue;
            register_post_type( $cpt['slug'], [
                'label' => $cpt['label'], 'public' => true, 'show_ui' => true, 'show_in_rest' => true,
                'supports' => [ 'title', 'editor', 'custom-fields' ], 'menu_icon' => 'dashicons-portfolio'
            ]);
        }
    }

    public static function meta_boxes() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->base !== 'post' ) return;
        $cpt = $screen->post_type;
        $cpt_def = array_filter( self::$schema, fn( $c ) => $c['slug'] === $cpt );
        if ( empty( $cpt_def ) ) return;
        foreach ( reset( $cpt_def )['fields'] as $f ) {
            add_meta_box( "wpnb_{$f['name']}", esc_html( $f['label'] ?: $f['name'] ), [ self::class, 'render_field' ], $cpt, 'normal', 'default', $f );
        }
    }

    public static function render_field( $post, $box ) {
        $f = $box['args'];
        $key = "wpnb_{$f['name']}";
        $val = get_post_meta( $post->ID, $key, true );
        // Calcola la formula se è vuota
        if ( $f['type'] === 'formula' && empty( $val ) ) $val = self::calc( $post->ID, $f['formula'] );
        
        echo '<p><label for="' . esc_attr( $key ) . '">' . esc_html( $f['label'] ?: $f['name'] ) . '</label><br>';
        if ( $f['type'] === 'group' ) {
            echo '<div class="wpnb-repeater-wrap" data-key="' . esc_attr( $key ) . '">';
            echo '<div class="wpnb-repeater-items"></div>';
            echo '<button type="button" class="button wpnb-add-row">Aggiungi riga</button>';
            echo '<script type="text/html" id="tpl-rep-' . $key . '">';
            echo '<div class="wpnb-repeater-row">';
            foreach ( $f['group_fields'] ?? [] as $gf ) {
                echo '<div style="flex:1;min-width:120px;"><label style="font-size:11px;color:#666;">' . esc_html( $gf['label'] ) . '</label><br>';
                if ( $gf['type'] === 'select' ) {
                    echo '<select name="wpnb_grp[' . esc_attr( $key ) . '][' . $gf['name'] . '][]" style="width:100%;">';
                    foreach ( $gf['options'] ?? [] as $o ) echo '<option value="' . esc_attr( $o['value'] ) . '">' . esc_html( $o['label'] ) . '</option>';
                    echo '</select>';
                } elseif ( in_array( $gf['type'], [ 'number', 'date' ], true ) ) {
                    echo '<input type="' . esc_attr( $gf['type'] ) . '" name="wpnb_grp[' . esc_attr( $key ) . '][' . $gf['name'] . '][]" class="regular-text" style="width:100%;">';
                } else {
                    echo '<input type="text" name="wpnb_grp[' . esc_attr( $key ) . '][' . $gf['name'] . '][]" class="regular-text" style="width:100%;">';
                }
                echo '</div>';
            }
            echo '<button type="button" class="button button-small wpnb-rm-row" style="align-self:flex-end;">Rimuovi</button></div></script></div>';
        } elseif ( $f['type'] === 'builder' ) {
            echo '<div class="wpnb-dynamic-builder" data-key="' . esc_attr( $key ) . '" data-schema="' . esc_attr( $val ?: '[]' ) . '"></div>';
            echo '<input type="hidden" name="wpnb_builder_' . esc_attr( $f['name'] ) . '" class="wpnb-builder-payload">';
            echo '<p class="description">Struttura campi specifica per questo contenuto.</p>';
        } elseif ( $f['type'] === 'relation' ) {
            $posts = get_posts( [ 'post_type' => $f['target_cpt'], 'posts_per_page' => -1, 'post_status' => 'publish' ] );
            echo '<select name="' . $key . '" id="' . $key . '" style="width:100%;">';
            echo '<option value="">-- Seleziona record --</option>';
            foreach ( $posts as $p ) echo '<option value="' . $p->ID . '" ' . selected( $val, $p->ID, false ) . '>' . esc_html( $p->post_title ?: 'ID ' . $p->ID ) . '</option>';
            echo '</select>';
        } elseif ( $f['type'] === 'select' ) {
            echo '<select name="' . $key . '" id="' . $key . '" style="width:100%;">';
            foreach ( $f['options'] ?? [] as $o ) echo '<option value="' . esc_attr( $o['value'] ) . '" ' . selected( $val, $o['value'], false ) . '>' . esc_html( $o['label'] ) . ' (Valore: ' . $o['value'] . ')</option>';
            echo '</select>';
        } elseif ( $f['type'] === 'radio' ) {
            foreach ( $f['options'] ?? [] as $o ) echo '<label style="margin-right:12px;"><input type="radio" name="' . $key . '" value="' . esc_attr( $o['value'] ) . '" ' . checked( $val, $o['value'], false ) . '> ' . esc_html( $o['label'] ) . '</label>';
        } else {
            $readonly = ( $f['type'] === 'formula' ) ? 'readonly style="background:#f9f9f9;"' : '';
            echo '<input type="text" name="' . $key . '" id="' . $key . '" value="' . esc_attr( $val ) . '" class="regular-text" placeholder="Valore (opzionale)" ' . $readonly . '>';
        }
        if ( $f['type'] === 'formula' ) echo '<span class="description" style="display:block;margin-top:4px;">Formula: <code>' . esc_html( $f['formula'] ) . '</code></span>';
        echo '</p>';
    }

    // ✅ FIX JS: Rimosso get_current_screen(), ora si basa sul DOM
    public static function inject_js() {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var uid = function() { return 'e' + Date.now().toString(36); };
            var container = document.getElementById('wpnb-cpts');
            var isGlobal = !!container;
            var isPost = !!document.getElementById('post'); // Verifica se siamo in un post editor
            
            if (!isGlobal && !isPost) return;

            function tplOpt(l, v) {
                return '<div class="wpnb-opt-row"><input type="text" class="regular-text wpnb-opt-label" value="'+(l||'')+'" placeholder="Etichetta" style="flex:1;"><input type="number" step="any" class="regular-text wpnb-opt-val" value="'+(v||0)+'" placeholder="0" style="width:100px;"><button type="button" class="button button-small wpnb-rm-opt">Rimuovi</button></div>';
            }
            function tplField(l, n, t, v, f, opts, groupFields, targetCpt) {
                var isOpts = (t === 'select' || t === 'radio');
                var isForm = (t === 'formula');
                var isGrp  = (t === 'group');
                var isRel  = (t === 'relation');
                var optsHTML = '';
                if (isOpts && opts && opts.length) opts.forEach(function(o){ optsHTML += tplOpt(o.label, o.value); });
                var grpHTML = isGrp ? '<div class="wpnb-group-fields" style="display:block;"><div class="wpnb-group-header">Campi del gruppo <button type="button" class="button button-small wpnb-add-gf">Aggiungi campo</button></div><div class="wpnb-grp-list"></div></div>' : '';
                var relHTML = isRel ? '<input type="text" class="regular-text wpnb-target-cpt wpnb-target-input" value="'+(targetCpt||'')+'" placeholder="Slug entità target (es. azienda)" style="display:block;">' : '';
                return '<div class="wpnb-field" data-id="'+uid()+'" data-type="'+t+'">'+
                    '<div class="wpnb-row">'+
                    '<input type="text" class="regular-text wpnb-label" value="'+(l||'')+'" placeholder="Etichetta" style="flex:1;">'+
                    '<select class="wpnb-type" style="width:140px;">'+
                    '<option value="text" '+(t==='text'?'selected':'')+'>Testo</option>'+
                    '<option value="number" '+(t==='number'?'selected':'')+'>Numero</option>'+
                    '<option value="date" '+(t==='date'?'selected':'')+'>Data</option>'+
                    '<option value="select" '+(t==='select'?'selected':'')+'>Menu a tendina</option>'+
                    '<option value="radio" '+(t==='radio'?'selected':'')+'>Radio Button</option>'+
                    '<option value="formula" '+(t==='formula'?'selected':'')+'>Formula</option>'+
                    '<option value="group" '+(t==='group'?'selected':'')+'>Gruppo / Ripetitore</option>'+
                    '<option value="builder" '+(t==='builder'?'selected':'')+'>Builder Dinamico</option>'+
                    '<option value="relation" '+(t==='relation'?'selected':'')+'>🔗 Relazione</option>'+
                    '</select>'+
                    '<input type="text" class="regular-text wpnb-name" value="'+(n||'')+'" placeholder="Slug" style="width:150px;">'+
                    '<button type="button" class="button button-small wpnb-rm-field">Rimuovi</button>'+
                    '</div>'+
                    '<div class="wpnb-opts-wrap" style="display:'+(isOpts?'block':'none')+'">'+
                    '<p style="margin:0 0 4px;font-size:12px;color:#666;">Opzioni (Etichetta -> Valore numerico):</p>'+
                    '<div class="wpnb-opts-list">'+optsHTML+'</div>'+
                    '<button type="button" class="button wpnb-add-opt">Aggiungi opzione</button>'+
                    '</div>'+
                    '<input type="text" class="regular-text wpnb-formula" value="'+(f||'')+'" placeholder="Formula" style="display:'+(isForm?'block':'none')+';margin-top:6px;width:100%;">'+
                    relHTML+
                    '<input type="text" class="regular-text wpnb-value" value="'+(v||'')+'" placeholder="Valore (opzionale)" style="margin-top:4px;">'+
                    grpHTML+
                    '</div>';
            }
            function tplCard(id, label, slug) {
                return '<div class="wpnb-card" data-id="'+uid()+'">'+
                    '<div class="wpnb-card-header">'+
                    '<input type="text" class="regular-text wpnb-cpt-label" value="'+(label||'')+'" placeholder="Nome entità" style="flex:1;">'+
                    '<input type="text" class="regular-text wpnb-cpt-slug" value="'+(slug||'')+'" placeholder="Slug" readonly style="background:#f9f9f9;width:160px;margin:0 8px;">'+
                    '<button type="button" class="button button-small wpnb-rm-cpt">Rimuovi</button>'+
                    '</div>'+
                    '<div class="wpnb-card-body wpnb-fields"></div>'+
                    '<button type="button" class="button wpnb-add-field" style="margin:0 16px 12px;">Aggiungi campo</button>'+
                    '</div>';
            }
            function toggleType(field) {
                if (!field) return;
                var t = field.querySelector('.wpnb-type').value;
                field.querySelector('.wpnb-opts-wrap').style.display = (t==='select'||t==='radio') ? 'block' : 'none';
                field.querySelector('.wpnb-formula').style.display = (t==='formula') ? 'block' : 'none';
                var grpBox = field.querySelector('.wpnb-group-fields');
                if(grpBox) grpBox.style.display = (t==='group') ? 'block' : 'none';
                var relBox = field.querySelector('.wpnb-target-cpt');
                if(relBox) relBox.style.display = (t==='relation') ? 'block' : 'none';
            }
            function collect(ctx) {
                var res = [];
                ctx.querySelectorAll(':scope > .wpnb-field').forEach(function(f){
                    var t = f.querySelector('.wpnb-type').value;
                    var node = { label: f.querySelector('.wpnb-label').value, name: f.querySelector('.wpnb-name').value, type: t, value: f.querySelector('.wpnb-value').value, formula: f.querySelector('.wpnb-formula').value || '' };
                    if(t==='relation') node.target_cpt = f.querySelector('.wpnb-target-cpt')?.value || '';
                    if(t==='select'||t==='radio'){
                        node.options = [];
                        f.querySelectorAll('.wpnb-opt-row').forEach(function(o){
                            node.options.push({label:o.querySelector('.wpnb-opt-label').value, value:parseFloat(o.querySelector('.wpnb-opt-val').value)||0});
                        });
                    }
                    if(t==='group') node.group_fields = collect(f.querySelector('.wpnb-grp-list'));
                    res.push(node);
                });
                return res;
            }
            function renderSchema(schema) {
                if(!schema || !Array.isArray(schema)) return;
                schema.forEach(function(cpt){
                    var cardHtml = tplCard(cpt.id, cpt.label, cpt.slug);
                    container.insertAdjacentHTML('beforeend', cardHtml);
                    var card = container.lastElementChild;
                    var fieldsContainer = card.querySelector('.wpnb-fields');
                    (cpt.fields||[]).forEach(function(f){
                        fieldsContainer.insertAdjacentHTML('beforeend', tplField(f.label, f.name, f.type, f.value, f.formula, f.options, f.group_fields, f.target_cpt));
                        if(f.type==='group' && f.group_fields) renderGroupFields(fieldsContainer.lastElementChild.querySelector('.wpnb-grp-list'), f.group_fields);
                    });
                });
            }
            function renderGroupFields(grpContainer, fields) {
                fields.forEach(function(f){
                    grpContainer.insertAdjacentHTML('beforeend', tplField(f.label, f.name, f.type, f.value, f.formula, f.options, f.group_fields, f.target_cpt));
                    var last = grpContainer.lastElementChild;
                    if(f.type==='group' && f.group_fields) renderGroupFields(last.querySelector('.wpnb-grp-list'), f.group_fields);
                });
            }
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('button');
                if (!btn) return;
                if (btn.id === 'wpnb-add-cpt') container.insertAdjacentHTML('beforeend', tplCard());
                else if (btn.classList.contains('wpnb-rm-cpt')) {
                    if(confirm('Cancellare questa entità?')) btn.closest('.wpnb-card').remove();
                }
                else if (btn.classList.contains('wpnb-add-field')) btn.previousElementSibling.insertAdjacentHTML('beforeend', tplField());
                else if (btn.classList.contains('wpnb-add-gf')) btn.closest('.wpnb-group-fields').querySelector('.wpnb-grp-list').insertAdjacentHTML('beforeend', tplField());
                else if (btn.classList.contains('wpnb-rm-field')) {
                    if(confirm('Cancellare questo campo?')) btn.closest('.wpnb-field').remove();
                }
                else if (btn.classList.contains('wpnb-add-opt')) btn.closest('.wpnb-field').querySelector('.wpnb-opts-list').insertAdjacentHTML('beforeend', tplOpt());
                else if (btn.classList.contains('wpnb-rm-opt')) e.target.closest('.wpnb-opt-row').remove();
            });
            document.addEventListener('change', function(e){ if(e.target.classList.contains('wpnb-type')) toggleType(e.target.closest('.wpnb-field')); });
            document.addEventListener('input', function(e){
                if(e.target.classList.contains('wpnb-cpt-label')) e.target.closest('.wpnb-card').querySelector('.wpnb-cpt-slug').value = e.target.value.toLowerCase().replace(/[^a-z0-9]/g, '_');
                if(e.target.classList.contains('wpnb-label')) e.target.closest('.wpnb-field').querySelector('.wpnb-name').value = e.target.value.toLowerCase().replace(/[^a-z0-9]/g, '_');
            });
            if(isGlobal) {
                var initial = JSON.parse(container.dataset.schema || '[]');
                renderSchema(initial);
                document.getElementById('wpnb-form').addEventListener('submit', function(e){
                    e.preventDefault();
                    var schema = [];
                    document.querySelectorAll('.wpnb-card').forEach(function(card){
                        schema.push({ id: card.dataset.id || uid(), label: card.querySelector('.wpnb-cpt-label').value, slug: card.querySelector('.wpnb-cpt-slug').value, fields: collect(card.querySelector('.wpnb-fields')) });
                    });
                    document.getElementById('wpnb-payload').value = JSON.stringify(schema);
                    this.submit();
                });
            }
            if(isPost) {
                document.querySelectorAll('.wpnb-repeater-wrap').forEach(function(el){
                    var tpl = document.getElementById('tpl-rep-' + el.dataset.key);
                    el.querySelector('.wpnb-add-row').addEventListener('click', function(){ el.querySelector('.wpnb-repeater-items').insertAdjacentHTML('beforeend', tpl.innerHTML); });
                    el.addEventListener('click', function(e){ if(e.target.classList.contains('wpnb-rm-row')) e.target.closest('.wpnb-repeater-row').remove(); });
                });
                document.querySelectorAll('.wpnb-dynamic-builder').forEach(function(el){
                    var payload = el.nextElementSibling;
                    var schema = JSON.parse(el.dataset.schema || '[]');
                    schema.forEach(function(f){ el.insertAdjacentHTML('beforeend', tplField(f.label, f.name, f.type, f.value, f.formula, f.options, f.group_fields, f.target_cpt)); });
                    if(!el.querySelector('.wpnb-field')) el.insertAdjacentHTML('beforeend', tplField());
                    var addBtn = document.createElement('button');
                    addBtn.type = 'button'; addBtn.className = 'button'; addBtn.textContent = 'Aggiungi campo';
                    el.after(addBtn); addBtn.addEventListener('click', function(){ el.insertAdjacentHTML('beforeend', tplField()); });
                    el.addEventListener('change', function(e){ if(e.target.classList.contains('wpnb-type')) toggleType(e.target.closest('.wpnb-field')); });
                    document.getElementById('post').addEventListener('submit', function(){ payload.value = JSON.stringify(collect(el)); });
                });
            }
        });
        </script>
        <?php
    }

    // ✅ FIX: Aggiunto parametro $post per compatibilità WP
    public static function save_meta( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        $cpt = get_post_type( $post_id );
        $cpt_def = array_filter( self::$schema, fn( $c ) => $c['slug'] === $cpt );
        if ( empty( $cpt_def ) ) return;
        foreach ( reset( $cpt_def )['fields'] as $f ) {
            $key = "wpnb_{$f['name']}";
            if ( $f['type'] === 'group' ) {
                $raw = $_POST['wpnb_grp'][ $key ] ?? [];
                $clean = []; $count = count( $raw[ array_key_first( $raw ) ?? '' ] ?? [] );
                for ( $i = 0; $i < $count; $i++ ) { $row = []; foreach ( $raw as $col => $vals ) $row[ $col ] = is_numeric( $vals[ $i ] ?? '' ) ? floatval( $vals[ $i ] ) : sanitize_text_field( $vals[ $i ] ?? '' ); $clean[] = $row; }
                update_post_meta( $post_id, $key, wp_json_encode( $clean ) );
            } elseif ( $f['type'] === 'builder' ) {
                $data = json_decode( wp_unslash( $_POST[ 'wpnb_builder_' . $f['name'] ] ?? '' ), true );
                if ( is_array( $data ) ) update_post_meta( $post_id, $key, wp_json_encode( array_map( fn($b) => [ 'label'=>sanitize_text_field($b['label']??''), 'name'=>sanitize_key($b['name']??''), 'type'=>in_array($b['type']??'', ['text','number','date','select','radio','formula'],true)?$b['type']:'text', 'value'=>in_array($b['type']??'', ['number','select','radio'],true)?floatval($b['value']??0):sanitize_text_field($b['value']??'') ], $data ) ) );
            } elseif ( $f['type'] === 'relation' ) {
                update_post_meta( $post_id, $key, intval( $_POST[ $key ] ?? 0 ) );
            } elseif ( isset( $_POST[ $key ] ) ) {
                $raw = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
                $val = $f['type'] === 'formula' ? self::calc( $post_id, $f['formula'] ) : ( in_array( $f['type'], ['select','radio','number'], true ) ? floatval( $raw ) : $raw );
                update_post_meta( $post_id, $key, $val );
            }
        }
    }

    // ✅ FIX CRITICO: Sostituito strtr con preg_replace per evitare conflitti di variabili
    private static function calc( $post_id, $formula ) {
        if ( empty( $formula ) ) return 0;
        $meta = get_post_meta( $post_id ); 
        $vars = [];
        
        // 1. Trova variabili (es. kg, prezzo, ecc.)
        preg_match_all( '/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $formula, $m );
        
        // 2. Associa valori ai campi trovati
        foreach ( array_unique( $m[1] ) as $v ) {
            $val = $meta[ "wpnb_{$v}" ][0] ?? 0;
            $vars[ $v ] = is_numeric( $val ) ? (float) $val : 0;
        }

        // 3. Ordina le variabili per lunghezza (la più lunga prima) per evitare conflitti (es. "val" vs "valore")
        uksort( $vars, function($a, $b) { return strlen($b) - strlen($a); });
        
        // 4. Sostituzione sicura con Regex (\b = word boundary)
        $expr = $formula;
        foreach ( $vars as $var => $val ) {
            $expr = preg_replace( '/\b' . preg_quote($var, '/') . '\b/', $val, $expr );
        }

        // 5. Verifica che l'espressione contenga solo numeri e operatori
        if ( ! preg_match( '/^[\d\s\+\-\*\/\.\(\)]+$/', $expr ) ) return 0;

        // 6. Calcolo sicuro
        return @eval( "return $expr;" );
    }

    /* ─────────────────────────────────────────────
    SHORTCODE & FRONTEND
    ────────────────────────────────────────────── */
    public static function sc_display( $atts ) {
        $atts = shortcode_atts( [ 'id' => '', 'slug' => '', 'field' => '' ], $atts );
        $post_id = $atts['id'] ?: get_the_ID();
        if ( ! $post_id ) return '';
        $post_type = get_post_type( $post_id );
        $cpt_def = array_filter( self::$schema, fn($c) => $c['slug'] === $post_type );
        if ( empty($cpt_def) ) return '<p>Entità non configurata.</p>';
        $fields = reset($cpt_def)['fields'];
        if ( $atts['field'] ) {
            $f = array_filter($fields, fn($f) => $f['name'] === $atts['field']);
            return empty($f) ? '' : self::render_value( reset($f), $post_id );
        }
        $out = '<div class="wpnb-display">';
        foreach($fields as $f) $out .= self::render_value($f, $post_id);
        return $out . '</div>';
    }

    public static function sc_form( $atts ) {
        $atts = shortcode_atts( [ 'slug' => '', 'id' => '', 'title' => '' ], $atts );
        $cpt_def = array_filter( self::$schema, fn($c) => $c['slug'] === $atts['slug'] );
        if ( empty($cpt_def) ) return '<p>Entità non trovata. Verifica lo slug.</p>';
        $cpt = reset($cpt_def);
        $fields = $cpt['fields'];
        $post_id = $atts['id'] ?: 0;
        ob_start();
        echo '<div class="wpnb-form-wrap">';
        if ( isset( $_GET['wpnb_success'] ) ) echo '<div class="notice notice-success"><p>Dati salvati correttamente.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="wpnb_form_submit">';
        echo wp_nonce_field('wpnb_form', '_wpnonce', true, false);
        echo '<input type="hidden" name="wpnb_cpt_slug" value="'.esc_attr($atts['slug']).'">';
        echo '<input type="hidden" name="wpnb_post_id" value="'.intval($post_id).'">';
        if ( ! $post_id ) {
            echo '<input type="text" name="wpnb_title" placeholder="Titolo/Nome" class="regular-text" value="'.esc_attr($atts['title']).'" required>';
        }
        foreach($fields as $f) self::render_fe_field($f, $post_id);
        echo '<button type="submit" class="button button-primary">Salva Dati</button>';
        echo '</form></div>';
        return ob_get_clean();
    }

    private static function render_fe_field( $f, $post_id ) {
        $key = "wpnb_{$f['name']}";
        $val = get_post_meta( $post_id, $key, true );
        if ( $f['type'] === 'formula' && empty( $val ) ) $val = self::calc( $post_id, $f['formula'] );
        echo '<div style="margin-bottom:12px;"><label>'.esc_html($f['label']).'</label><br>';
        if ( $f['type'] === 'group' ) {
            echo '<div class="wpnb-repeater-wrap" data-key="'.$key.'"><div class="wpnb-repeater-items"></div><button type="button" class="button wpnb-add-row" style="margin-top:5px;">Aggiungi riga</button>';
            echo '<script type="text/html" id="tpl-rep-'.$key.'"><div class="wpnb-repeater-row">';
            foreach ( $f['group_fields'] ?? [] as $gf ) {
                echo '<div style="flex:1;min-width:100px;"><label style="font-size:11px;">'.esc_html($gf['label']).'</label><br>';
                if($gf['type']==='select'){ echo '<select name="wpnb_grp['.$key.']['.$gf['name'].'][]" style="width:100%;">'; foreach($gf['options']??[] as $o) echo '<option value="'.$o['value'].'">'.$o['label'].'</option>'; echo '</select>'; }
                elseif(in_array($gf['type'],['number','date'],true)){ echo '<input type="'.$gf['type'].'" name="wpnb_grp['.$key.']['.$gf['name'].'][]" style="width:100%;">'; }
                else { echo '<input type="text" name="wpnb_grp['.$key.']['.$gf['name'].'][]" style="width:100%;">'; }
                echo '</div>';
            }
            echo '<button type="button" class="button button-small wpnb-rm-row">Rimuovi</button></div></script></div>';
            add_action('wp_footer', function() use($key){ echo "<script>document.querySelector('[data-key={$key}] .wpnb-add-row').addEventListener('click',function(){document.querySelector('[data-key={$key}] .wpnb-repeater-items').insertAdjacentHTML('beforeend',document.getElementById('tpl-rep-{$key}').innerHTML)});document.querySelector('[data-key={$key}]').addEventListener('click',function(e){if(e.target.classList.contains('wpnb-rm-row'))e.target.closest('.wpnb-repeater-row').remove()});</script>"; }, 99);
        } elseif ( $f['type'] === 'relation' ) {
            $posts = get_posts( [ 'post_type' => $f['target_cpt'], 'posts_per_page' => -1, 'post_status' => 'publish' ] );
            echo '<select name="'.$key.'" style="width:100%;"><option value="">-- Seleziona --</option>';
            foreach($posts as $p) echo '<option value="'.$p->ID.'" '.selected($val,$p->ID,false).'>'.esc_html($p->post_title).'</option>';
            echo '</select>';
        } elseif ( $f['type'] === 'select' ) {
            echo '<select name="'.$key.'" style="width:100%;">';
            foreach($f['options']??[] as $o) echo '<option value="'.$o['value'].'" '.selected($val,$o['value'],false).'>'.esc_html($o['label']).'</option>';
            echo '</select>';
        } elseif ( $f['type'] === 'radio' ) {
            foreach($f['options']??[] as $o) echo '<label style="margin-right:10px;"><input type="radio" name="'.$key.'" value="'.$o['value'].'" '.checked($val,$o['value'],false).'> '.esc_html($o['label']).'</label>';
        } else {
            $r = ($f['type']==='formula')?'readonly style="background:#f0f0f1;"':'';
            echo '<input type="text" name="'.$key.'" value="'.esc_attr($val).'" class="regular-text" placeholder="Valore" '.$r.'>';
        }
        if($f['type']==='formula') echo '<small style="color:#666;">Formula: '.esc_html($f['formula']).'</small>';
        echo '</div>';
    }

    private static function render_value( $f, $post_id ) {
        $key = "wpnb_{$f['name']}";
        $val = get_post_meta( $post_id, $key, true );
        if($f['type']==='formula' && empty($val)) $val = self::calc($post_id, $f['formula']);
        if($f['type']==='group'){
            $data = json_decode($val, true);
            if(empty($data)) return '';
            $out = '<table style="width:100%;border-collapse:collapse;margin:8px 0;"><tr>';
            foreach($f['group_fields']??[] as $gf) $out .= '<th style="border:1px solid #ddd;padding:6px;text-align:left;background:#f9f9f9;">'.esc_html($gf['label']).'</th>';
            $out .= '</tr>';
            foreach($data as $row){ $out .= '<tr>'; foreach($f['group_fields']??[] as $gf) $out .= '<td style="border:1px solid #ddd;padding:6px;">'.esc_html($row[$gf['name']]??'').'</td>'; $out .= '</tr>'; }
            return '<div style="margin:8px 0;"><strong>'.esc_html($f['label']).':</strong> '.$out.'</table></div>';
        }
        if($f['type']==='relation'){
            $rel = get_post($val);
            return '<div style="margin:4px 0;"><strong>'.esc_html($f['label']).':</strong> '.($rel ? '<a href="'.get_permalink($rel->ID).'">'.esc_html($rel->post_title).'</a>' : 'Nessun record selezionato').'</div>';
        }
        if(in_array($f['type'],['select','radio'])){
            $opt = array_filter($f['options']??[], fn($o)=>$o['value']==$val);
            $val = reset($opt)['label'] ?? $val;
        }
        return '<div style="margin:4px 0;"><strong>'.esc_html($f['label']).':</strong> '.esc_html($val).'</div>';
    }

    public static function handle_frontend_form() {
        check_admin_referer('wpnb_form');
        $slug = sanitize_key($_POST['wpnb_cpt_slug'] ?? '');
        $post_id = intval($_POST['wpnb_post_id'] ?? 0);
        $cpt_def = array_filter(self::$schema, fn($c) => $c['slug'] === $slug);
        if(empty($cpt_def)) wp_die('Entità non valida');
        $cpt = reset($cpt_def);
        if($post_id && get_post($post_id)){
            wp_update_post(['ID'=>$post_id, 'post_title'=>sanitize_text_field($_POST['wpnb_title']??$cpt['label'])]);
        } else {
            $post_id = wp_insert_post(['post_type'=>$slug, 'post_title'=>sanitize_text_field($_POST['wpnb_title']??$cpt['label']), 'post_status'=>'publish']);
        }
        if(is_wp_error($post_id)) wp_die('Errore salvataggio: '.$post_id->get_error_message());
        foreach($cpt['fields'] as $f){
            $key = "wpnb_{$f['name']}";
            if($f['type']==='group'){
                $raw = $_POST['wpnb_grp'][$key] ?? []; $clean=[]; $count=count($raw[array_key_first($raw)??'']??[]);
                for($i=0;$i<$count;$i++){$row=[]; foreach($raw as $col=>$vals) $row[$col]=is_numeric($vals[$i]??'')?floatval($vals[$i]):sanitize_text_field($vals[$i]??''); $clean[]=$row;}
                update_post_meta($post_id, $key, wp_json_encode($clean));
            } elseif($f['type']==='relation') {
                update_post_meta($post_id, $key, intval($_POST[$key]??0));
            } elseif(isset($_POST[$key])){
                $raw = sanitize_text_field(wp_unslash($_POST[$key]));
                $val = $f['type']==='formula' ? self::calc($post_id, $f['formula']) : (in_array($f['type'],['select','radio','number'],true)?floatval($raw):$raw);
                update_post_meta($post_id, $key, $val);
            }
        }
        // ✅ FIX: Sicurezza Redirect
        wp_safe_redirect(add_query_arg('wpnb_success','1', wp_get_referer() ?: home_url()));
        exit;
    }
}
WP_Native_Builder::init();