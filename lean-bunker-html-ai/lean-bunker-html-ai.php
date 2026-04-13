<?php
/**
 * Plugin Name: Lean Bunker HTML AI
 * Description: Genera HTML strutturato con AI direttamente nell'editor classico. Compatibile con TinyMCE e LB Native Editor.
 * Version: 1.1.2
 * Author: Riccardo Bastillo
 * License: GPL-3.0+
 */

if (!defined('ABSPATH')) exit;

class LeanBunkerHTMLAI {

    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (is_admin()) {
            add_action('add_meta_boxes', [$this, 'add_meta_box']);
            add_action('admin_menu', [$this, 'add_admin_page']);
            add_action('wp_ajax_lean_ai_generate', [$this, 'ajax_generate']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_inline_js']);
        }
    }

    // === METABOX NELL'EDITOR CLASSICO ===
    public function add_meta_box() {
        $post_types = get_post_types(['public' => true], 'names');
        foreach ($post_types as $type) {
            add_meta_box('lean_ai_html', '🧠 Lean Bunker HTML AI', [$this, 'meta_box_html'], $type, 'side', 'high');
        }
    }

    public function meta_box_html($post) {
        wp_nonce_field('lean_ai_nonce', 'lean_ai_nonce');
        $prompt = get_post_meta($post->ID, '_lean_ai_local_prompt', true);
        echo '<p><label for="lean_ai_prompt">Prompt specifico:</label></p>';
        echo '<textarea id="lean_ai_prompt" name="lean_ai_prompt" style="width:100%;height:80px;">' . esc_textarea($prompt) . '</textarea>';
        echo '<p><button type="button" class="button" id="lean_ai_generate_btn">Genera con AI</button></p>';
        echo '<p class="description">Es: "Pagina sull\'F40 con FAQ e CTA verso /auto/classiche/"</p>';
    }

    public function save_meta_box($post_id) {
        if (!isset($_POST['lean_ai_nonce']) || !wp_verify_nonce($_POST['lean_ai_nonce'], 'lean_ai_nonce')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (isset($_POST['lean_ai_prompt'])) {
            update_post_meta($post_id, '_lean_ai_local_prompt', sanitize_textarea_field($_POST['lean_ai_prompt']));
        }
    }

    // === PAGINA ADMIN: SOLO API KEY ===
    public function add_admin_page() {
        add_options_page('Lean Bunker HTML AI', 'LB HTML AI', 'manage_options', 'lean-bunker-html-ai', [$this, 'admin_page_html']);
    }

    public function admin_page_html() {
        if (isset($_POST['lean_ai_save'])) {
            check_admin_referer('lean_ai_save_settings');
            update_option('lean_ai_together_key', sanitize_text_field($_POST['together_key'] ?? ''));
            echo '<div class="notice notice-success"><p>✅ API key salvata.</p></div>';
        }

        $key = get_option('lean_ai_together_key', '');
        ?>
        <div class="wrap" style="max-width:700px;">
            <h1>Lean Bunker HTML AI</h1>
            <form method="post">
                <?php wp_nonce_field('lean_ai_save_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Together API Key</th>
                        <td>
                            <input type="password" name="together_key" value="<?php echo esc_attr($key); ?>" class="regular-text" autocomplete="off">
                            <p class="description">Ottienila da <a href="https://api.together.xyz" target="_blank">Together.xyz</a>.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Salva API Key', 'primary', 'lean_ai_save'); ?>
            </form>
        </div>
        <?php
    }

    // === AJAX: GENERAZIONE CON AI ===
    public function ajax_generate() {
        check_ajax_referer('lean_ai_nonce', 'nonce');

        if (!current_user_can('edit_posts')) wp_die('Non autorizzato', 403);

        $post_id = intval($_POST['post_id'] ?? 0);
        $local_prompt = sanitize_textarea_field($_POST['prompt'] ?? '');
        if (!$local_prompt || !$post_id) wp_die('Prompt mancante', 400);

        $api_key = get_option('lean_ai_together_key', '');
        if (!$api_key) wp_die('API key non configurata nelle impostazioni.', 400);

        // Prompt fisso, ottimizzato — niente configurazione globale
        $full_prompt = trim("
Sei un generatore HTML per WordPress. Segui queste regole:
- Rispondi SOLO con HTML valido (niente markdown, niente backtick).
- NON usare <html>, <head>, <body>, <style>, <script>, style=\"\", ID o commenti.
- Usa SEMPRE queste classi:
  • Sezioni principali: <section class=\"content-section\">
  • Titoli di sezione: <h2 class=\"section-title\">
  • Pulsanti CTA: <a href=\"...\" class=\"cta-button\">
  • FAQ: <section class=\"faq-block\"><details><summary>Domanda</summary><p>Risposta</p></details></section>
- Mantieni la struttura accessibile, responsive e leggibile.
- Ora genera il contenuto per: $local_prompt
");

        $response = wp_remote_post('https://api.together.xyz/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model' => 'Qwen/Qwen2.5-7B-Instruct-Turbo',
                'messages' => [['role' => 'user', 'content' => $full_prompt]],
                'max_tokens' => 2000,
                'temperature' => 0.7,
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) wp_die('Errore API: ' . $response->get_error_message(), 500);

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['choices'][0]['message']['content'])) wp_die('Risposta AI vuota o non valida.', 500);

        $html = $body['choices'][0]['message']['content'];
        $html = preg_replace('/^```(?:html)?\s*|\s*```$/m', '', $html); // rimuovi backtick

        // Sanitizzazione mirata
        $allowed = [
            'a' => ['href' => true, 'class' => true, 'title' => true],
            'section' => ['class' => true],
            'div' => ['class' => true],
            'h1' => ['class' => true], 'h2' => ['class' => true], 'h3' => ['class' => true],
            'p' => [], 'ul' => [], 'ol' => [], 'li' => [],
            'details' => ['class' => true], 'summary' => [],
            'blockquote' => ['class' => true],
            'strong' => [], 'em' => []
        ];
        $clean_html = wp_kses($html, $allowed);

        wp_send_json_success(['html' => $clean_html]);
    }

    // === JAVASCRIPT INLINE (VANILLA) ===
    public function enqueue_inline_js($hook) {
        if (!in_array($hook, ['post-new.php', 'post.php'])) return;

        $js = "
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.getElementById('lean_ai_generate_btn');
            if (!btn) return;

            btn.addEventListener('click', async function() {
                const prompt = document.getElementById('lean_ai_prompt').value;
                const postId = document.getElementById('post_ID').value;

                if (!prompt.trim()) {
                    alert('Inserisci un prompt.');
                    return;
                }

                if (!confirm('Sostituire il contenuto dell\\'editor con la risposta AI?')) return;

                btn.disabled = true;
                btn.textContent = '🧠 Generazione...';

                try {
                    const res = await fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'lean_ai_generate',
                            nonce: '" . wp_create_nonce('lean_ai_nonce') . "',
                            post_id: postId,
                            prompt: prompt
                        })
                    });

                    const data = await res.json();
                    if (data.success) {
                        const html = data.data.html;

                        // 🔑 INTEGRAZIONE UNIVERSALE
                        let inserted = false;

                        // 1. Prova con il tuo editor nativo (LBEditor API)
                        if (window.LBEditor && typeof window.LBEditor.setContent === 'function') {
                            window.LBEditor.setContent(html);
                            inserted = true;
                        }
                        // 2. Prova con TinyMCE (se attivo)
                        else if (window.tinymce && tinymce.activeEditor) {
                            tinymce.activeEditor.setContent(html);
                            inserted = true;
                        }
                        // 3. Fallback: textarea classica
                        else {
                            const textarea = document.getElementById('content');
                            if (textarea) {
                                textarea.value = html;
                                inserted = true;
                            }
                        }

                        if (inserted) {
                            alert('✅ Contenuto generato con successo!');
                        } else {
                            alert('❌ Impossibile inserire il contenuto nell\\'editor.');
                        }
                    } else {
                        alert('❌ Errore: ' + (data.data?.message || 'sconosciuto'));
                    }
                } catch (e) {
                    alert('❌ Errore di rete: ' + e.message);
                } finally {
                    btn.disabled = false;
                    btn.textContent = 'Genera con AI';
                }
            });
        });";

        wp_add_inline_script('jquery', $js);
    }
}

// Avvia il plugin
add_action('plugins_loaded', function() {
    LeanBunkerHTMLAI::get_instance();
});

// Salva il metabox
add_action('save_post', [LeanBunkerHTMLAI::get_instance(), 'save_meta_box']);

// 🔧 Correzione per Query Monitor: registra json2 se mancante (v1.1.1)
add_action('admin_enqueue_scripts', function() {
    if (!wp_script_is('json2', 'registered')) {
        wp_register_script('json2', includes_url('js/json2.min.js'), [], '20150503', true);
    }
});