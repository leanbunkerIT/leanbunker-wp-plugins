<?php
/**
 * Plugin Name: Lean Autopost
 * Description: Genera articoli automatici da Sitemap con Together AI. v3.3.0: Batch per sitemap, citazione fonte, soglia similarità configurabile.
 * Version: 3.3.0
 * Author: Riccardo Bastillo
 * Requires at least: WordPress 4.9
 * Requires PHP: 8.0
 * Text Domain: lean-autopost
 */
if (!defined('ABSPATH')) exit;
if (defined('LEAN_AUTOPOST_LOADED')) return;
define('LEAN_AUTOPOST_LOADED', true);

class Lean_Autopost {
    private string $opt_sitemaps  = 'lean_autopost_sitemaps';
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

    /* ================= MENU & HANDLE ================= */
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
                    'sitemap_url'   => esc_url_raw(trim($_POST['sitemap_url'] ?? '')),
                    'post_type'     => sanitize_text_field($_POST['post_type'] ?? 'post'),
                    'taxonomy'      => sanitize_text_field($_POST['taxonomy'] ?? ''),
                    'term'          => sanitize_text_field($_POST['term'] ?? ''),
                    'category'      => sanitize_text_field($_POST['category'] ?? ''),
                    'custom_prompt' => wp_kses_post($_POST['custom_prompt'] ?? ''),
                    'change_title'  => !empty($_POST['change_title']),
                    'batch_size'    => max(1, min(5, absint($_POST['batch_size'] ?? 1))),
                    'cite_source'   => !empty($_POST['cite_source']),
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
                $items = get_option($this->opt_sitemaps, []);
                unset($items[sanitize_text_field($_GET['id'])]);
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
                'qwen_model'       => sanitize_text_field($_POST['qwen_model'] ?: 'Qwen/Qwen2.5-7B-Instruct-Turbo'),
                'min_len'          => max(100, absint($_POST['min_len'] ?? 300)),
                'sim_threshold'    => max(30, min(80, absint($_POST['sim_threshold'] ?? 65))),
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
                    <tr><th><label><?= esc_html__('Lunghezza minima articolo (caratteri)', 'lean-autopost') ?></label></th><td><input type="number" name="min_len" value="<?= absint($s['min_len'] ?? 300) ?>" min="100"></td></tr>
                    <tr><th><label><?= esc_html__('Soglia similarità riscrittura (30–80%)', 'lean-autopost') ?></label></th><td><input type="number" name="sim_threshold" value="<?= absint($s['sim_threshold'] ?? 65) ?>" min="30" max="80"> <small><?= esc_html__('Valori bassi = riscrittura più aggressiva', 'lean-autopost') ?></small></td></tr>
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
                <table class="wp-list-table widefat fixed striped">
                    <thead><tr><th><?= esc_html__('URL Sitemap') ?></th><th><?= esc_html__('Batch') ?></th><th><?= esc_html__('Cita fonte') ?></th><th><?= esc_html__('Ultima Esecuzione') ?></th><th><?= esc_html__('Azioni') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $id => $item):
                        $last_time = get_option("lean_autopost_sm_last_run_{$id}", 0);
                        $last_str  = $last_time ? human_time_diff($last_time, time()) . ' ' . __('fa') : __('Mai');
                        $edit_url  = add_query_arg('edit', $id, $base);
                        $del_url   = wp_nonce_url(add_query_arg(['action' => 'delete_sitemap', 'id' => $id], $base), 'del');
                        $run_url   = wp_nonce_url(add_query_arg(['action' => 'run_sitemap', 'id' => $id], $base), 'run');
                    ?>
                    <tr>
                        <td><?= esc_html($item['sitemap_url']) ?></td>
                        <td><?= absint($item['batch_size'] ?? 1) ?></td>
                        <td><?= !empty($item['cite_source']) ? '✓' : '–' ?></td>
                        <td><?= esc_html($last_str) ?></td>
                        <td>
                            <a href="<?= esc_url($edit_url) ?>"><?= esc_html__('Modifica') ?></a> |
                            <a href="<?= esc_url($del_url) ?>" onclick="return confirm('Eliminare?')"><?= esc_html__('Elimina') ?></a> |
                            <a href="<?= esc_url($run_url) ?>"><?= esc_html__('Esegui') ?></a>
                        </td>
                    </tr>
                    <?php endforeach; ?></tbody>
                </table>
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
                    <tr><th><label>URL Sitemap</label></th><td><input type="url" name="sitemap_url" value="<?= esc_attr($data['sitemap_url'] ?? '') ?>" required class="widefat"></td></tr>
                    <tr><th><label>Post Type</label></th><td><select name="post_type"><?= $this->opts_html('post_type', $data['post_type'] ?? 'post') ?></select></td></tr>
                    <tr><th><label>Tassonomia</label></th><td><select name="taxonomy" id="tax-select"><option value=""><?= esc_html__('-- Nessuna --') ?></option><?= $this->opts_html('tax', $data['taxonomy'] ?? '', $taxonomies) ?></select></td></tr>
                    <tr><th><label>Termine</label></th><td><select name="term" id="term-select"><option value=""><?= esc_html__('-- Seleziona --') ?></option><?php foreach($terms as $t): ?><option value="<?= esc_attr($t->slug) ?>" <?= selected($data['term'] ?? '', $t->slug, false) ?>><?= esc_html($t->name) ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><label>Categoria (fallback)</label></th><td><input type="text" name="category" value="<?= esc_attr($data['category'] ?? '') ?>"></td></tr>
                    <tr><th><label><?= esc_html__('Batch per ciclo (1–5)', 'lean-autopost') ?></label></th><td><input type="number" name="batch_size" min="1" max="5" value="<?= absint($data['batch_size'] ?? 1) ?>"></td></tr>
                    <tr><th><label>Prompt AI (opzionale)</label></th><td><textarea name="custom_prompt" rows="3" class="large-text"><?= esc_textarea($data['custom_prompt'] ?? '') ?></textarea></td></tr>
                    <tr><th><label>Cambia Titolo?</label></th><td><input type="checkbox" name="change_title" value="1" <?= checked(!empty($data['change_title']), true, false) ?>></td></tr>
                    <tr><th><label><?= esc_html__('Cita fonte nel testo', 'lean-autopost') ?></label></th><td><input type="checkbox" name="cite_source" value="1" <?= checked(!empty($data['cite_source']), true, false) ?>></td></tr>
                </table>
                <?php submit_button(__('Salva', 'lean-autopost'), 'primary', 'save_sitemap'); ?>
            </form>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tax = document.getElementById('tax-select'), term = document.getElementById('term-select');
            if (tax && term) tax.onchange = function() {
                if (!this.value) { term.innerHTML = '<option value="">-- Nessuno --</option>'; return; }
                const x = new XMLHttpRequest(); x.open('POST', ajaxurl, true);
                x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                x.onreadystatechange = function() {
                    if (x.readyState === 4 && x.status === 200) try {
                        const d = JSON.parse(x.responseText);
                        let h = '<option value="">-- Seleziona --</option>';
                        d.forEach(t => h += `<option value="${t.slug}">${t.name}</option>`);
                        term.innerHTML = h;
                    } catch(e) {}
                };
                x.send('action=lean_autopost_terms&_ajax_nonce=<?= esc_js($ajax_nonce) ?>&taxonomy=' + encodeURIComponent(this.value));
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
    private function get_model(): string   { return $this->get_settings()['qwen_model'] ?? 'Qwen/Qwen2.5-7B-Instruct-Turbo'; }

    private function log(string $msg): void {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('[Lean Autopost] ' . $msg);
        }
    }

    private function ai_call(string $system, string $user, float $temp = 0.2): string {
        $res = wp_remote_post('https://api.together.ai/v1/chat/completions', [
            'headers' => ['Authorization' => 'Bearer ' . $this->get_api_key(), 'Content-Type' => 'application/json'],
            'body' => json_encode(['model' => $this->get_model(), 'messages' => [['role'=>'system','content'=>$system], ['role'=>'user','content'=>$user]], 'temperature'=>$temp]),
            'timeout' => 45
        ]);
        if (is_wp_error($res)) { $this->log('AI Error: ' . $res->get_error_message()); return ''; }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return $data['choices'][0]['message']['content'] ?? '';
    }

    private function verify_rewrite(string $original, string $rewritten, int $threshold = 65): bool {
        if (empty($rewritten) || strlen($rewritten) < 100) return false;
        similar_text(strtolower(strip_tags($original)), strtolower(strip_tags($rewritten)), $percent);
        $ok = $percent <= $threshold;
        $this->log(sprintf('Similarità: %d%% (%s)', $percent, $ok ? 'OK' : 'RIFIUTATO'));
        return $ok;
    }

    private function extract_plain_text(string $html): string {
        if (strlen($html) < 100) return '';
        $html = preg_replace('#<(script|style|nav|footer|aside)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<!--.*?-->#s', '', $html);
        $enc  = mb_detect_encoding($html, ['UTF-8','ISO-8859-1','Windows-1252'], true);
        if ($enc && $enc !== 'UTF-8') $html = mb_convert_encoding($html, 'UTF-8', $enc);
        $dom = new DOMDocument(); libxml_use_internal_errors(true);
        if (!$dom->loadHTML('<?xml encoding="UTF-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) return '';
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        foreach ([
            '//div[contains(@class,"share") or contains(@class,"social") or contains(@class,"nav") or contains(@class,"copyright")]',
            '//*[contains(text(),"Condividi") or contains(text(),"prevPage") or contains(text(),"nextPage") or contains(text(),"Riproduzione riservata") or contains(text(),"Copyright")]'
        ] as $q) {
            foreach ($xpath->query($q) ?: [] as $n) if ($n->parentNode) $n->parentNode->removeChild($n);
        }
        foreach (['//article','//main','//div[contains(@class,"entry-content") or contains(@class,"post-content")]','//div[@id="content"]'] as $q) {
            $nodes = $xpath->query($q);
            if ($nodes && $nodes->length > 0) {
                $text = trim(preg_replace('/\s+/u', ' ', $nodes->item(0)->textContent));
                if (strlen($text) >= 100) return $text;
            }
        }
        return trim(preg_replace('/\s+/u', ' ', $dom->textContent));
    }

    private function format_as_paragraphs(string $text): string {
        $parts = [];
        foreach (preg_split('/\n\s*\n|\r\n\r\n|(?<=[.!?])\s+(?=[A-ZÀ-Û])/', $text) as $p) {
            $p = trim(preg_replace('/\s+/u', ' ', $p));
            if (strlen($p) >= 50) $parts[] = '<p>' . wp_kses_post($p) . '</p>';
        }
        return implode("\n\n", $parts) ?: '<p>' . wp_kses_post(wp_trim_words($text, 50)) . '</p>';
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

    private function mark_processed(string $url, string $source_id): void {
        $cache = get_option($this->opt_processed, []);
        $cache["$source_id:$url"] = time();
        if (count($cache) > 10000) { arsort($cache); $cache = array_slice($cache, 0, 10000, true); }
        update_option($this->opt_processed, $cache);
    }

    private function is_processed(string $url, string $source_id): bool {
        return isset(get_option($this->opt_processed, [])["$source_id:$url"]);
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

    /* ================= PROCESSOR ================= */
    private function process_sitemap(array $src, string $id): void {
        $api = $this->get_api_key();
        if (empty($src['sitemap_url']) || !$api) return;
        $urls = $this->parse_sitemap($src['sitemap_url']);
        if (empty($urls)) return;

        $settings      = $this->get_settings();
        $batch         = max(1, min(5, absint($src['batch_size'] ?? 1)));
        $min_len       = max(100, absint($settings['min_len'] ?? 300));
        $sim_threshold = max(30, min(80, absint($settings['sim_threshold'] ?? 65)));
        $published     = 0;

        foreach ($urls as $url) {
            if ($published >= $batch) break;
            if ($this->is_processed($url, $id)) continue;

            $res = wp_remote_get($url, ['timeout'=>15, 'sslverify'=>false]);
            if (is_wp_error($res) || empty(wp_remote_retrieve_body($res))) { $this->mark_processed($url, $id); continue; }

            $plain = $this->extract_plain_text(wp_remote_retrieve_body($res));
            if (strlen($plain) < 100) { $this->mark_processed($url, $id); continue; }

            $title       = wp_trim_words($plain, 12);
            $source_note = !empty($src['cite_source']) ? "\n\nFonte: " . wp_parse_url($url, PHP_URL_HOST) : '';
            $final       = '';

            // AI Rewrite
            $sys = "Sei un giornalista italiano. Riscrivi COMPLETAMENTE questa notizia:\n"
                 . "- CAMBIA frasi, struttura, lessico; MANTIENI i fatti\n"
                 . "- OUTPUT: SOLO HTML WordPress (<h2> sottotitoli, <p> paragrafi)\n"
                 . "- VIETATO: copiare frasi originali, markdown, saluti, copyright\n"
                 . "- Inizia con <h2> o <p>. Scrivi 3-4 paragrafi minimi.";
            if (!empty($src['custom_prompt'])) $sys .= "\n" . trim($src['custom_prompt']);

            $rewritten = $this->ai_call($sys, $plain, 0.5);

            if (!empty($rewritten)) {
                $rewritten = preg_replace('/^```[\w]*\s*/m', '', $rewritten);
                $rewritten = preg_replace('/\s*```$/m', '', $rewritten);
                $rewritten = preg_replace('/^.*?(?=<h[2-6]|<p)/is', '', $rewritten);
                $rewritten = trim($rewritten);
                if (strlen($rewritten) >= $min_len &&
                    preg_match('/<(h[2-6]|p|ul)[\s>]/i', $rewritten) &&
                    $this->verify_rewrite($plain, $rewritten, $sim_threshold)) {
                    $final = $rewritten . $source_note;
                    $this->log('AI rewrite OK');
                }
            }

            if (empty($final)) {
                $final = $this->format_as_paragraphs($plain) . $source_note;
                $this->log('Fallback formattazione automatica');
            }

            // AI Title
            if (!empty($src['change_title']) && strlen($plain) > 50) {
                $t = $this->ai_call('SOLO un titolo giornalistico italiano (max 100 char). NIENTE ALTRO.', wp_trim_words($plain, 300), 0.7);
                $t = trim(wp_strip_all_tags($t));
                if (strlen($t) >= 5 && strlen($t) <= 100) $title = sanitize_text_field($t);
            }

            if ($this->publish($src, $title, $final)) {
                $this->mark_processed($url, $id);
                $published++;
                update_option("lean_autopost_sm_last_run_{$id}", time());
                $this->log("Pubblicato: $title");
            }
        }
        $this->log("Ciclo completato: {$published}/{$batch} articoli");
    }

    /* ================= CRON ================= */
    public function cron_run(): void {
        if (get_transient('lean_autopost_cron_lock')) return;
        set_transient('lean_autopost_cron_lock', true, 120);

        $items = get_option($this->opt_sitemaps, []);
        if (empty($items)) { delete_transient('lean_autopost_cron_lock'); return; }
        $ids   = array_keys($items);
        $last  = get_option('lean_autopost_sm_last_id', '');
        $start = ($last && in_array($last, $ids, true)) ? (array_search($last, $ids, true) + 1) % count($ids) : 0;

        for ($i = 0; $i < count($ids); $i++) {
            $idx = ($start + $i) % count($ids);
            $id  = $ids[$idx];
            if (time() - get_option("lean_autopost_sm_last_run_{$id}", 0) >= 300) {
                $this->process_sitemap($items[$id], $id);
                update_option('lean_autopost_sm_last_id', $id);
                break;
            }
        }
        delete_transient('lean_autopost_cron_lock');
    }
}

$lean_autopost_instance = new Lean_Autopost();
register_activation_hook(__FILE__, ['Lean_Autopost', 'activate']);
register_deactivation_hook(__FILE__, ['Lean_Autopost', 'deactivate']);
add_action('wp_ajax_lean_autopost_terms', fn() => $lean_autopost_instance->ajax_terms());