<?php
/**
 * Plugin Name: Lean Autopost
 * Description: Genera articoli automatici da Sitemap con Together AI. v3.2.5: Riscrittura verificata, prompt funzionante, formattazione paragrafi.
 * Version: 3.2.5
 * Author: Riccardo Bastillo
 * Requires at least: WordPress 4.9
 * Requires PHP: 8.0
 * Text Domain: lean-autopost
 */
if (!defined('ABSPATH')) exit;
if (defined('LEAN_AUTOPOST_LOADED')) return;
define('LEAN_AUTOPOST_LOADED', true);

class Lean_Autopost {
    private string $opt_sitemaps = 'lean_autopost_sitemaps';
    private string $opt_processed = 'lean_autopost_processed_urls';
    private string $opt_settings  = 'lean_autopost_settings';

    public function __construct() {
        add_filter('cron_schedules', [$this, 'add_schedule']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handle']);
        add_action('lean_autopost_run_sitemaps', [$this, 'cron_run']);
    }

    public function add_schedule(array $schedules): array {
        $schedules['every_5_minutes'] = ['interval' => 300, 'display' => __('Ogni 5 minuti', 'lean-autopost')];
        return $schedules;
    }

    public static function activate(): void {
        wp_clear_scheduled_hook('lean_autopost_run_sitemaps');
        wp_schedule_event(time(), 'every_5_minutes', 'lean_autopost_run_sitemaps');
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('lean_autopost_run_sitemaps');
    }

    public function menu(): void {
        add_menu_page('Lean Autopost', 'Lean Autopost', 'manage_options', 'lean-autopost', [$this, 'render_list'], 'dashicons-admin-post');
        add_submenu_page('lean-autopost', 'Impostazioni', 'Impostazioni', 'manage_options', 'lean-autopost-settings', [$this, 'settings_page']);
    }

    public function handle(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        $page = $_GET['page'] ?? '';
        if (!in_array($page, ['lean-autopost', 'lean-autopost-settings'], true)) return;

        if ($page === 'lean-autopost') {
            if (isset($_POST['save_sitemap'])) {
                if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'lean_autopost_save')) wp_die(__('Security check failed.', 'lean-autopost'));
                $id   = sanitize_text_field($_POST['id'] ?? uniqid('sm_'));
                $data = [
                    'sitemap_url'    => esc_url_raw(trim($_POST['sitemap_url'] ?? '')),
                    'post_type'      => sanitize_text_field($_POST['post_type'] ?? 'post'),
                    'taxonomy'       => sanitize_text_field($_POST['taxonomy'] ?? ''),
                    'term'           => sanitize_text_field($_POST['term'] ?? ''),
                    'category'       => sanitize_text_field($_POST['category'] ?? ''),
                    'custom_prompt'  => wp_kses_post($_POST['custom_prompt'] ?? ''),
                    'change_title'   => !empty($_POST['change_title']),
                ];
                if (empty($data['sitemap_url'])) wp_die(__('L\'URL della sitemap è obbligatorio.', 'lean-autopost'));
                $items = get_option($this->opt_sitemaps, []);
                $items[$id] = $data;
                update_option($this->opt_sitemaps, $items);
                wp_safe_redirect(admin_url('admin.php?page=lean-autopost&saved=1'));
                exit;
            }
            if (isset($_GET['action'], $_GET['id'], $_GET['_wpnonce']) && $_GET['action'] === 'delete_sitemap') {
                if (!wp_verify_nonce($_GET['_wpnonce'], 'del')) wp_die(__('Security check failed.', 'lean-autopost'));
                $id = sanitize_text_field($_GET['id']);
                $items = get_option($this->opt_sitemaps, []);
                unset($items[$id]);
                update_option($this->opt_sitemaps, $items);
                wp_safe_redirect(admin_url('admin.php?page=lean-autopost'));
                exit;
            }
            if (isset($_GET['action'], $_GET['id'], $_GET['_wpnonce']) && $_GET['action'] === 'run_sitemap') {
                if (!wp_verify_nonce($_GET['_wpnonce'], 'run')) wp_die(__('Security check failed.', 'lean-autopost'));
                $id = sanitize_text_field($_GET['id']);
                $items = get_option($this->opt_sitemaps, []);
                if (isset($items[$id])) $this->process_sitemap($items[$id], $id);
                wp_safe_redirect(admin_url('admin.php?page=lean-autopost&ran=1'));
                exit;
            }
        }

        if ($page === 'lean-autopost-settings' && isset($_POST['save_api_settings'])) {
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'lean_autopost_settings')) wp_die(__('Security check failed.'));
            update_option($this->opt_settings, [
                'together_api_key' => sanitize_text_field($_POST['together_api_key'] ?? ''),
                'qwen_model'       => sanitize_text_field($_POST['qwen_model'] ?: 'Qwen/Qwen2.5-7B-Instruct-Turbo')
            ]);
            wp_safe_redirect(add_query_arg('updated', '1', admin_url('admin.php?page=lean-autopost-settings')));
            exit;
        }
    }

    public function settings_page(): void {
        $s = get_option($this->opt_settings, []);
        ?>
        <div class="wrap">
            <h1><?= esc_html__('Impostazioni', 'lean-autopost') ?></h1>
            <?php if (isset($_GET['updated'])): ?><div class="notice notice-success"><p><?= esc_html__('Impostazioni salvate.', 'lean-autopost') ?></p></div><?php endif; ?>
            <form method="post" action="<?= esc_url(admin_url('admin.php?page=lean-autopost-settings')) ?>">
                <?php wp_nonce_field('lean_autopost_settings'); ?>
                <table class="form-table">
                    <tr><th><label>Together AI API Key</label></th><td><input type="text" name="together_api_key" value="<?= esc_attr($s['together_api_key'] ?? '') ?>" class="regular-text" size="50"></td></tr>
                    <tr><th><label>Modello AI</label></th><td><input type="text" name="qwen_model" value="<?= esc_attr($s['qwen_model'] ?? 'Qwen/Qwen2.5-7B-Instruct-Turbo') ?>" class="regular-text" size="50"></td></tr>
                </table>
                <?php submit_button(__('Salva', 'lean-autopost'), 'primary', 'save_api_settings'); ?>
            </form>
        </div>
        <?php
    }

    public function render_list(): void {
        if (isset($_GET['saved'])) echo '<div class="notice notice-success"><p>' . esc_html__('Salvato con successo.', 'lean-autopost') . '</p></div>';
        if (isset($_GET['ran']))   echo '<div class="notice notice-info"><p>' . esc_html__('Elaborazione avviata.', 'lean-autopost') . '</p></div>';
        
        if (isset($_GET['edit']) || isset($_GET['new'])) {
            $id = $_GET['edit'] ?? null;
            $items = get_option($this->opt_sitemaps, []);
            $this->render_form($id, $items[$id] ?? []);
            return;
        }
        
        $items = get_option($this->opt_sitemaps, []);
        $base  = admin_url('admin.php?page=lean-autopost');
        ?>
        <div class="wrap">
            <h1><?= esc_html__('Sitemap Autopost') ?> <a href="<?= esc_url(add_query_arg('new', '1', $base)) ?>" class="page-title-action">+ <?= __('Nuova', 'lean-autopost') ?></a></h1>
            <?php if (empty($items)): ?>
                <p><?= esc_html__('Nessuna sitemap configurata.', 'lean-autopost') ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped"><thead><tr><th><?= esc_html__('URL Sitemap') ?></th><th><?= esc_html__('Ultima Esecuzione') ?></th><th><?= esc_html__('Azioni') ?></th></tr></thead><tbody>
                <?php foreach ($items as $id => $item):
                    $last_time = get_option("lean_autopost_sm_last_run_{$id}", 0);
                    $last_str  = $last_time ? human_time_diff($last_time, time()) . ' ' . __('fa') : __('Mai');
                    $edit_url  = add_query_arg('edit', $id, $base);
                    $del_url   = wp_nonce_url(add_query_arg(['action' => 'delete_sitemap', 'id' => $id], $base), 'del');
                    $run_url   = wp_nonce_url(add_query_arg(['action' => 'run_sitemap', 'id' => $id], $base), 'run');
                ?>
                <tr>
                    <td><?= esc_html($item['sitemap_url']) ?></td>
                    <td><?= esc_html($last_str) ?></td>
                    <td>
                        <a href="<?= esc_url($edit_url) ?>"><?= esc_html__('Modifica') ?></a> |
                        <a href="<?= esc_url($del_url) ?>" onclick="return confirm('Eliminare?')"><?= esc_html__('Elimina') ?></a> |
                        <a href="<?= esc_url($run_url) ?>"><?= esc_html__('Esegui') ?></a>
                    </td>
                </tr>
                <?php endforeach; ?></tbody></table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_form(?string $id, array $data): void {
        $base       = admin_url('admin.php?page=lean-autopost');
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        $terms      = !empty($data['taxonomy']) ? get_terms($data['taxonomy'], ['hide_empty' => false]) : [];
        if (is_wp_error($terms)) $terms = [];
        $ajax_nonce = wp_create_nonce('lean_autopost_ajax');
        ?>
        <div class="wrap">
            <h1><?= $id ? esc_html__('Modifica Sitemap') : esc_html__('Nuova Sitemap') ?></h1>
            <a href="<?= esc_url($base) ?>">&larr; <?= esc_html__('Indietro') ?></a>
            <form method="post" action="<?= esc_url($base) ?>" style="margin-top:20px;">
                <?php wp_nonce_field('lean_autopost_save', '_wpnonce'); ?>
                <input type="hidden" name="id" value="<?= esc_attr($id ?? uniqid('sm_')) ?>">
                <table class="form-table">
                    <tr><th><label>URL Sitemap (es. sitemap.xml)</label></th><td><input type="text" name="sitemap_url" value="<?= esc_attr($data['sitemap_url'] ?? '') ?>" required class="widefat"></td></tr>
                    <tr><th><label>Post Type</label></th><td><select name="post_type"><?= $this->opts_html('post_type', $data['post_type'] ?? 'post') ?></select></td></tr>
                    <tr><th><label>Tassonomia</label></th><td><select name="taxonomy" id="tax-select"><option value=""><?= esc_html__('-- Nessuna --') ?></option><?= $this->opts_html('tax', $data['taxonomy'] ?? '', $taxonomies) ?></select></td></tr>
                    <tr><th><label>Termine</label></th><td><select name="term" id="term-select"><option value=""><?= esc_html__('-- Seleziona --') ?></option><?php foreach($terms as $t): ?><option value="<?= esc_attr($t->slug) ?>" <?= selected($data['term'] ?? '', $t->slug, false) ?>><?= esc_html($t->name) ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><label>Categoria (fallback)</label></th><td><input type="text" name="category" value="<?= esc_attr($data['category'] ?? '') ?>"></td></tr>
                    <tr><th><label>Prompt AI (opzionale)</label></th><td><textarea name="custom_prompt" rows="4" class="large-text" placeholder="Es: Usa tono giornalistico, evidenzia i dati importanti, mantieni un linguaggio semplice"><?= esc_textarea($data['custom_prompt'] ?? '') ?></textarea><br><small style="color:#666">Istruzioni specifiche per la riscrittura AI</small></td></tr>
                    <tr><th><label>Cambia Titolo?</label></th><td><input type="checkbox" name="change_title" value="1" <?= checked(!empty($data['change_title']), true, false) ?>></td></tr>
                </table>
                <?php submit_button(__('Salva', 'lean-autopost'), 'primary', 'save_sitemap'); ?>
            </form>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var tax = document.getElementById('tax-select'), term = document.getElementById('term-select');
            if(tax && term) tax.onchange = function() {
                if(!this.value) { term.innerHTML = '<option value="">-- Nessuno --</option>'; return; }
                var x = new XMLHttpRequest(); x.open('POST', ajaxurl, true); 
                x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
                x.onreadystatechange = function() { 
                    if(x.readyState===4 && x.status===200) try {
                        var d = JSON.parse(x.responseText), h = '<option value="">-- Seleziona --</option>';
                        for(var i=0;i<d.length;i++) h+='<option value="'+d[i].slug+'">'+d[i].name+'</option>'; 
                        term.innerHTML=h;
                    } catch(e){} 
                };
                x.send('action=lean_autopost_terms&_ajax_nonce=<?= esc_js($ajax_nonce) ?>&taxonomy='+encodeURIComponent(this.value));
            };
        });
        </script>
        <?php
    }

    private function opts_html(string $type, string $current, $list = []): string {
        if ($type === 'post_type') {
            return implode('', array_map(fn($p) => "<option value=\"".esc_attr($p->name)."\" ".selected($current, $p->name, false).">".esc_html($p->labels->singular_name)."</option>", get_post_types(['public'=>true],'objects')));
        }
        return implode('', array_map(fn($t) => "<option value=\"".esc_attr($t->name)."\" ".selected($current, $t->name, false).">".esc_html($t->labels->singular_name)."</option>", $list));
    }

    public function ajax_terms(): void {
        check_ajax_referer('lean_autopost_ajax', '_ajax_nonce');
        if (!current_user_can('manage_options')) wp_die();
        $tax = sanitize_text_field($_POST['taxonomy'] ?? '');
        if (!taxonomy_exists($tax)) wp_send_json([]);
        $terms = get_terms($tax, ['hide_empty' => false]);
        wp_send_json(is_wp_error($terms) ? [] : array_map(fn($t) => ['slug' => $t->slug, 'name' => $t->name], $terms));
    }

    /* ================= CORE LOGIC ================= */
    private function get_settings(): array { return get_option($this->opt_settings, []); }
    private function get_api_key(): string { return $this->get_settings()['together_api_key'] ?? ''; }
    private function get_model(): string { return $this->get_settings()['qwen_model'] ?? 'Qwen/Qwen2.5-7B-Instruct-Turbo'; }
    
    private function ai_call(string $system, string $user, float $temp = 0.2): string {
        $res = wp_remote_post('https://api.together.ai/v1/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $this->get_api_key(), 'Content-Type' => 'application/json'],
            'body' => json_encode(['model' => $this->get_model(), 'messages' => [['role'=>'system','content'=>$system], ['role'=>'user','content'=>$user]], 'temperature'=>$temp]),
            'timeout' => 30
        ]);
        if (is_wp_error($res)) { error_log('Lean AI Error: '.$res->get_error_message()); return ''; }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Verifica se il testo è stato effettivamente riscritto
     */
    private function verify_rewrite(string $original, string $rewritten): bool {
        if (empty($rewritten) || strlen($rewritten) < 100) return false;
        
        $orig_plain = strtolower(strip_tags($original));
        $rewr_plain = strtolower(strip_tags($rewritten));
        
        similar_text($orig_plain, $rewr_plain, $percent);
        
        if ($percent > 65) {
            error_log(sprintf('[Lean] Riscrittura rifiutata: similarità %d%%', $percent));
            return false;
        }
        
        error_log(sprintf('[Lean] Riscrittura valida: similarità %d%%', $percent));
        return true;
    }

    /**
     * Estrae testo pulito dall'HTML
     */
    private function extract_plain_text(string $html): string {
        if (empty($html) || strlen($html) < 100) return '';
        
        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<!--.*?-->#s', '', $html);
        
        $enc = mb_detect_encoding($html, ['UTF-8','ISO-8859-1','Windows-1252'], true);
        if ($enc && $enc !== 'UTF-8') $html = mb_convert_encoding($html, 'UTF-8', $enc);
        
        $dom = new DOMDocument(); 
        libxml_use_internal_errors(true);
        if (!$dom->loadHTML('<?xml encoding="UTF-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) return '';
        libxml_clear_errors(); 
        
        $xpath = new DOMXPath($dom);
        
        $remove_queries = [
            '//script', '//style', '//nav', '//footer', '//aside', 
            '//div[contains(@class,"share") or contains(@class,"social") or contains(@class,"navigation") or contains(@class,"copyright")]',
            '//*[contains(text(),"prevPageLabel") or contains(text(),"nextPageLabel") or contains(text(),"Condividi") or contains(text(),"Riproduzione riservata") or contains(text(),"Copyright")]'
        ];
        
        foreach ($remove_queries as $query) {
            $nodes = $xpath->query($query);
            $to_remove = [];
            foreach ($nodes as $node) $to_remove[] = $node;
            foreach ($to_remove as $node) {
                if ($node->parentNode) $node->parentNode->removeChild($node);
            }
        }
        
        foreach (['//article', '//main', '//div[contains(@class,"entry-content") or contains(@class,"post-content")]', '//div[@id="content"]'] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length > 0) {
                $text = $nodes->item(0)->textContent;
                $text = trim(preg_replace('/\s+/', ' ', $text));
                if (strlen($text) >= 100) return $text;
            }
        }
        
        $body = $xpath->query('//body')->item(0);
        if ($body) {
            $text = $body->textContent;
            return trim(preg_replace('/\s+/', ' ', $text));
        }
        
        return '';
    }

    /**
     * Formatta testo in paragrafi HTML
     */
    private function format_as_paragraphs(string $text): string {
        $text = trim($text);
        if (empty($text)) return '';
        
        $paragraphs = preg_split('/\n\s*\n|\r\n\r\n|(?<=[.!?])\s+(?=[A-ZÀ-Û])/', $text);
        $html_parts = [];
        
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if (strlen($p) < 30) continue;
            
            $p = preg_replace('/(prevPageLabel|nextPageLabel|Condividi|Riproduzione riservata|Copyright).*/i', '', $p);
            $p = trim($p);
            
            if (strlen($p) >= 50) {
                $html_parts[] = '<p>' . wp_kses_post($p) . '</p>';
            }
        }
        
        return implode("\n\n", $html_parts);
    }

    private function parse_sitemap(string $url): array {
        $res = wp_remote_get($url, ['timeout'=>20, 'sslverify'=>false]);
        if (is_wp_error($res) || empty(wp_remote_retrieve_body($res))) return [];
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string(wp_remote_retrieve_body($res));
        if (!$xml) return [];
        $urls = [];
        if ($xml->getName() === 'sitemapindex') {
            foreach ($xml->sitemap as $sub) $urls = array_merge($urls, $this->parse_sitemap((string)$sub->loc));
        } elseif ($xml->getName() === 'urlset') {
            foreach ($xml->url as $u) $urls[] = (string)$u->loc;
        }
        return array_unique($urls);
    }

    private function mark_processed(string $url): void {
        $cache = get_option($this->opt_processed, []);
        $cache[$url] = time();
        if (count($cache) > 10000) { arsort($cache); $cache = array_slice($cache, 0, 10000, true); }
        update_option($this->opt_processed, $cache);
    }

    private function publish(array $cfg, string $title, string $content): bool {
        if (empty($title) || empty($content)) return false;
        $post_id = wp_insert_post([
            'post_title'   => sanitize_text_field($title), 
            'post_content' => $content, 
            'post_status'  => 'publish', 
            'post_type'    => $cfg['post_type'] ?? 'post', 
            'post_author'  => 1
        ]);
        if (!$post_id || is_wp_error($post_id)) return false;
        
        if (!empty($cfg['taxonomy']) && !empty($cfg['term'])) {
            $t = get_term_by('slug', $cfg['term'], $cfg['taxonomy']);
            if ($t) wp_set_post_terms($post_id, [$t->term_id], $cfg['taxonomy']);
        } elseif (!empty($cfg['category'])) {
            $c = term_exists($cfg['category'], 'category');
            $cid = is_array($c) ? $c['term_id'] : (int)$c;
            if (!$cid) $cid = wp_create_category($cfg['category']);
            wp_set_post_categories($post_id, [$cid]);
        }
        return true;
    }

    /* ================= PROCESSOR (CORRETTO) ================= */
    private function process_sitemap(array $src, string $id): void {
        $api = $this->get_api_key(); 
        if (empty($src['sitemap_url']) || !$api) return;
        
        $urls = $this->parse_sitemap($src['sitemap_url']); 
        if (empty($urls)) return;
        
        // Ultimi 5 post non processati
        $cache = get_option($this->opt_processed, []);
        $targets = [];
        foreach ($urls as $u) { 
            if (!isset($cache[$u]) && count($targets) < 5) { 
                $targets[] = $u;
            } 
        }
        
        if (empty($targets)) return;
        
        foreach ($targets as $target) {
            $res = wp_remote_get($target, ['timeout'=>15, 'sslverify'=>false]);
            if (is_wp_error($res) || empty(wp_remote_retrieve_body($res))) continue;
            
            // Estrae SOLO testo pulito
            $plain = $this->extract_plain_text(wp_remote_retrieve_body($res));
            if (empty($plain) || strlen($plain) < 100) {
                $this->mark_processed($target);
                continue;
            }

            $title = wp_trim_words($plain, 10);
            $final = ''; // IMPORTANTE: parte vuoto!

            // === AI REWRITE CON PROMPT MIGLIORATO ===
            $sys = "Sei un giornalista professionista italiano. DEVI riscrivere COMPLETAMENTE questa notizia:\n\n"
                 . "REGOLE OBBLIGATORIE:\n"
                 . "1. CAMBIA tutte le frasi, la struttura e il lessico\n"
                 . "2. MANTIENI solo i fatti essenziali\n"
                 . "3. OUTPUT: SOLO paragrafi HTML <p> e sottotitoli <h2>\n"
                 . "4. VIETATO copiare frasi dall'originale (massimo 30% similarità)\n"
                 . "5. NIENTE markdown, saluti, copyright, note legali\n"
                 . "6. Inizia direttamente con <h2> o <p>\n"
                 . "7. Scrivi almeno 3-4 paragrafi ben strutturati\n\n";
            
            if (!empty($src['custom_prompt'])) {
                $sys .= "ISTRUZIONI PERSONALIZZATE: " . trim($src['custom_prompt']) . "\n\n";
            }
            
            $sys .= "TESTO ORIGINALE DA RISCRIRE:\n";

            $rewritten = $this->ai_call($sys, $plain, 0.5); // Temperatura più alta

            if (!empty($rewritten)) {
                // Pulizia markdown
                $rewritten = preg_replace('/^```[\w]*\s*/m', '', $rewritten);
                $rewritten = preg_replace('/\s*```$/m', '', $rewritten);
                $rewritten = preg_replace('/^.*?(?=<h[2-6]|<p)/is', '', $rewritten);
                $rewritten = trim($rewritten);

                // Validazione: tag HTML + similarità accettabile
                if (strlen($rewritten) >= 150 && 
                    preg_match('/<(h[2-6]|p|ul)[\s>]/i', $rewritten) && 
                    $this->verify_rewrite($plain, $rewritten)) {
                    $final = $rewritten;
                    error_log('[Lean] AI rewrite riuscito e verificato');
                }
            }

            // FALLBACK: Formatta automaticamente in paragrafi
            if (empty($final)) {
                $final = $this->format_as_paragraphs($plain);
                error_log('[Lean] Usato fallback formattazione automatica');
            }

            // AI Title
            if (!empty($src['change_title']) && strlen($plain) > 50) {
                $t = $this->ai_call("Crea SOLO un titolo giornalistico accattivante in italiano (max 100 caratteri). NIENTE ALTRO.", wp_trim_words($plain, 300), 0.7);
                $t = trim(wp_strip_all_tags($t));
                if (strlen($t) >= 5 && strlen($t) <= 100) {
                    $title = sanitize_text_field($t);
                }
            }

            if ($this->publish($src, $title, $final)) {
                $this->mark_processed($target);
                update_option("lean_autopost_sm_last_run_{$id}", time());
                error_log('[Lean] Pubblicato: ' . $title);
            }
        }
    }

    /* ================= CRON ================= */
    public function cron_run(): void {
        if (get_transient('lean_autopost_cron_lock')) return;
        set_transient('lean_autopost_cron_lock', true, 45);

        $items = get_option($this->opt_sitemaps, []); if (empty($items)) return;
        $ids = array_keys($items);
        $last = get_option('lean_autopost_sm_last_id', '');
        $start = ($last && in_array($last, $ids, true)) ? (array_search($last, $ids, true) + 1) % count($ids) : 0;
        
        for ($i=0; $i<count($ids); $i++) {
            $idx = ($start + $i) % count($ids);
            $id = $ids[$idx];
            if (time() - get_option("lean_autopost_sm_last_run_{$id}", 0) >= 300) {
                $this->process_sitemap($items[$id], $id);
                update_option('lean_autopost_sm_last_id', $id);
                break;
            }
        }
    }
}

$lean_autopost_instance = new Lean_Autopost();
register_activation_hook(__FILE__, ['Lean_Autopost', 'activate']);
register_deactivation_hook(__FILE__, ['Lean_Autopost', 'deactivate']);
add_action('wp_ajax_lean_autopost_terms', fn() => $lean_autopost_instance->ajax_terms());