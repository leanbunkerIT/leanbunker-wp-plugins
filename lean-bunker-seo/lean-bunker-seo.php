<?php
/**
 * Plugin Name: Lean Bunker SEO
 * Description: Genera meta title, meta description, Open Graph, Schema.org e breadcrumb con logica nativa WordPress. Invia sitemap a Bing, Yandex, Baidu. Ping Google "di cortesia". Knowledge Graph semantico automatico. Zero dipendenze esterne. Tutto in un file. 🤖 v2.5.0: Codice espanso, sintassi verificata, attivazione isolata, zero minificazione.
 * Version: 2.5.0
 * Author: Riccardo Bastillo
 * Author URI: https://leanbunker.com
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lean-bunker-seo
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.7
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) {
    exit;
}

class Lean_Bunker_SEO {
    private $option_prefix = 'lb_seo_';
    
    private const ALLOWED_SCHEMA_TYPES = [
        'WebSite', 'WebPage', 'CollectionPage', 'Article', 'Person', 
        'Organization', 'ImageObject', 'BreadcrumbList', 'DefinedTerm', 'Thing'
    ];
    
    private const PING_ENDPOINTS = [
        'google' => 'https://www.google.com/ping?sitemap=',
        'bing'   => 'https://www.bing.com/ping?sitemap=',
        'yandex' => 'https://webmaster.yandex.com/ping?sitemap=',
    ];

    private const ALLOWED_AI_RULES = ['noai', 'noimageai', 'noai-embed'];

    public function __construct() {
        add_action('init', [$this, 'on_init']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('category_edit_form_fields', [$this, 'taxonomy_metabox_html'], 10, 2);
        add_action('post_tag_edit_form_fields', [$this, 'taxonomy_metabox_html'], 10, 2);
        add_action('edited_category', [$this, 'save_taxonomy_meta']);
        add_action('edited_post_tag', [$this, 'save_taxonomy_meta']);
        add_action('save_post', [$this, 'save_meta'], 10, 2);
        add_shortcode('lb_seo_description', [$this, 'shortcode_seo_desc']);
        add_shortcode('lb_breadcrumb', [$this, 'shortcode_breadcrumb']);
        add_action('transition_post_status', [$this, 'auto_generate_on_publish'], 10, 3);
        
        add_action('wp_ajax_lb_seo_generate', [$this, 'ajax_generate']);
        add_action('wp_ajax_lb_seo_get_missing', [$this, 'ajax_get_missing_posts']);
        add_action('wp_ajax_lb_seo_generate_single', [$this, 'ajax_generate_single']);
        add_action('wp_ajax_lb_seo_get_missing_taxonomies', [$this, 'ajax_get_missing_taxonomies']);
        add_action('wp_ajax_lb_seo_generate_taxonomy_single', [$this, 'ajax_generate_taxonomy_single']);
        add_action('wp_ajax_lb_seo_manual_ping', [$this, 'ajax_manual_ping']);

        add_filter('pre_get_document_title', [$this, 'get_seo_title'], 20);
        add_action('wp_head', [$this, 'output_seo_meta'], 1);
        add_action('wp_head', [$this, 'output_combined_robots_meta'], 2);
        add_action('wp_footer', [$this, 'output_schema_markup'], 1);
        add_filter('the_content', [$this, 'add_nofollow_external_links']);
        add_filter('robots_txt', [$this, 'add_sitemap_to_robots'], 10, 2);
        add_action('plugins_loaded', [$this, 'schedule_pinger']);

        if (get_option($this->option_prefix . 'cleanup_duplicates') === '1') {
            add_action('template_redirect', [$this, 'cleanup_duplicate_meta'], 0);
        }

        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('init', [$this, 'add_llms_txt_rewrite']);
        add_action('template_redirect', [$this, 'serve_llms_txt']);
        add_action('plugins_loaded', [$this, 'schedule_ai_pinger']);
        add_filter('robots_txt', [$this, 'add_ai_robots_directives'], 11);
        add_action('add_meta_boxes', [$this, 'add_ai_metabox']);
        add_action('save_post', [$this, 'save_ai_meta'], 11, 2);
        add_action('wp_ajax_lb_seo_manual_ai_ping', [$this, 'ajax_manual_ai_ping']);

        do_action('lean_bunker_seo_after_init', $this);
    }

    public function on_init() {
        if (get_option('lb_seo_pending_rewrite')) {
            flush_rewrite_rules(false);
            delete_option('lb_seo_pending_rewrite');
        }
    }

    // ============================================================================
    // 🤖 AI & LLM: LLMS.TXT + AI PING SYSTEM
    // ============================================================================
    public function add_query_vars($vars) { 
        $vars[] = 'lb_llms_txt'; 
        return $vars; 
    }
    
    public function add_llms_txt_rewrite() {
        add_rewrite_rule('^llms\.txt$', 'index.php?lb_llms_txt=1', 'top');
        add_rewrite_rule('^llms-full\.txt$', 'index.php?lb_llms_txt=full', 'top');
    }
    
    public function serve_llms_txt() {
        if (!get_query_var('lb_llms_txt') || get_option($this->option_prefix . 'enable_llms_txt', '1') !== '1') {
            return;
        }
        $full = get_query_var('lb_llms_txt') === 'full';
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Robots-Tag: noindex, noarchive');
        echo $this->generate_llms_content($full);
        exit;
    }
    
    private function generate_llms_content($full = false) {
        $site_name   = esc_html(get_bloginfo('name'));
        $site_desc   = esc_html(get_bloginfo('description'));
        $home        = untrailingslashit(home_url());
        $llms_url    = $home . '/llms.txt';
        $llms_full   = $home . '/llms-full.txt';
        $admin_email = sanitize_email(get_bloginfo('admin_email'));

        $content  = "# {$site_name}\n";
        $content .= "> {$site_desc}\n";
        $content .= "URL: {$home}\nLLMS: {$llms_url}\nLLMS-Full: {$llms_full}\nContact: {$admin_email}\n\n";
        $content .= "## AI Crawler Directives\n";
        $content .= "- Respect `robots.txt` directives\n";
        $content .= "- Do not train foundational models without explicit written consent\n";
        $content .= "- Attribute source when generating answers\n";
        $content .= "- Cache responses responsibly (max 24h)\n";
        $content .= "- Use `llms-full.txt` only for comprehensive indexing\n\n";
        $content .= "## Site Sections\n";
        
        $cats = $this->get_top_categories(5);
        if (!empty($cats) && !is_wp_error($cats)) {
            foreach ($cats as $cat) {
                $link = get_category_link($cat->term_id);
                if (!is_wp_error($link) && !empty($link)) {
                    $content .= "- [" . sanitize_text_field($cat->name) . "](" . esc_url_raw($link) . ")\n";
                }
            }
        } else { 
            $content .= "- " . __('No public sections available', 'lean-bunker-seo') . "\n"; 
        }
        $content .= "\n";

        if ($full) {
            $content .= "## Recent Updates\n";
            $recent = get_posts([
                'numberposts' => 20, 
                'post_status' => 'publish', 
                'post_type' => 'post',
                'fields' => ['ID', 'post_title', 'post_name'], 
                'no_found_rows' => true,
                'update_post_meta_cache' => false, 
                'update_post_term_cache' => false, 
                'cache_results' => false
            ]);
            if (!empty($recent)) {
                foreach ($recent as $p) {
                    $title = sanitize_text_field($p->post_title ?: __('Untitled', 'lean-bunker-seo'));
                    $link  = get_permalink($p->ID);
                    if (!is_wp_error($link) && !empty($link)) {
                        $content .= "- [{$title}](" . esc_url_raw($link) . ")\n";
                    }
                }
            } else { 
                $content .= "- " . __('No recent updates', 'lean-bunker-seo') . "\n"; 
            }
            $content .= "\n";
        }
        
        $content .= "---\n";
        $content .= sprintf(__("Generated by Lean Bunker SEO v%s\n", 'lean-bunker-seo'), '2.5.0');
        $content .= sprintf(__("Last updated: %s\n", 'lean-bunker-seo'), gmdate('Y-m-d\TH:i:s\Z'));
        return $content;
    }

    public function schedule_ai_pinger() { 
        if (!wp_next_scheduled('lean_bunker_ai_ping')) {
            add_action('lean_bunker_ai_ping', [$this, 'ping_ai_engines']);
        }
    }
    
    public function reschedule_ai_pinger() {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('lean_bunker_ai_ping');
        }
        $interval = get_option($this->option_prefix . 'ai_ping_interval');
        if ($interval && in_array($interval, ['hourly', 'twicedaily', 'daily', 'weekly']) && function_exists('wp_schedule_event')) {
            wp_schedule_event(time(), $interval, 'lean_bunker_ai_ping');
        }
    }
    
    public function ping_ai_engines() {
        if (get_option($this->option_prefix . 'enable_llms_txt', '1') !== '1') return;
        $raw = get_option($this->option_prefix . 'ai_ping_endpoints', '');
        if (empty($raw)) return;
        
        $llms_url = home_url('/llms.txt');
        $stats = get_option('lbs_ai_ping_stats', []); 
        $log = [];
        
        foreach (array_filter(array_map('trim', explode("\n", $raw))) as $line) {
            [$name, $endpoint] = array_pad(explode('|', $line, 2), 2, '');
            $name = trim($name) ?: __('Custom AI', 'lean-bunker-seo'); 
            $endpoint = trim($endpoint);
            
            if (!$endpoint || !filter_var($endpoint, FILTER_VALIDATE_URL)) { 
                $log[$name] = ['status' => 'skipped', 'reason' => 'invalid_url']; 
                continue; 
            }
            
            $args = [
                'timeout' => 3, 
                'blocking' => false, 
                'sslverify' => true,
                'user-agent' => 'LeanBunkerSEO/2.5.0-AI', 
                'httpversion' => '1.1'
            ];
            
            try { 
                wp_remote_get(add_query_arg('url', urlencode($llms_url), $endpoint), $args); 
                $log[$name] = ['status' => 'sent', 'http_code' => null]; 
                $stats[$name] = ($stats[$name] ?? 0) + 1; 
            } catch (Exception $e) { 
                $log[$name] = ['status' => 'error', 'reason' => $e->getMessage()]; 
            }
        }
        
        if (!empty($log)) update_option('lbs_last_ai_ping_log', ['timestamp' => time(), 'results' => $log]);
        if (!empty($stats)) update_option('lbs_ai_ping_stats', $stats);
    }
    
    public function ajax_manual_ai_ping() {
        check_admin_referer('lb_seo_manual_ai_ping', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized', 'lean-bunker-seo')], 403);
            wp_die();
        }
        if (get_option($this->option_prefix . 'enable_llms_txt', '1') !== '1') {
            wp_send_json_error(['message' => __('llms.txt non abilitato.', 'lean-bunker-seo')]);
        }
        if (empty(get_option($this->option_prefix . 'ai_ping_endpoints', ''))) {
            wp_send_json_error(['message' => __('Nessun endpoint AI configurato.', 'lean-bunker-seo')]);
        }
        
        $this->ping_ai_engines();
        wp_send_json_success(['message' => __('Ping AI inviati in background!', 'lean-bunker-seo')]);
    }

    // ============================================================================
    // 🛡️ AI ROBOTS.TXT & META
    // ============================================================================
    public function add_ai_robots_directives($output) {
        if (get_option($this->option_prefix . 'ai_robots_enable') !== '1') return $output;
        
        $ai_section = "\n# AI & LLM Crawler Directives (Lean Bunker SEO)\n";
        foreach (['blocked' => 'ai_robots_blocked_bots', 'allowed' => 'ai_robots_allowed_bots'] as $type => $opt) {
            $bots = array_filter(array_map('trim', explode("\n", get_option($this->option_prefix . $opt, ''))));
            foreach ($bots as $bot) { 
                $bot = sanitize_text_field($bot); 
                if ($bot) {
                    $ai_section .= "User-agent: {$bot}\n" . ($type === 'blocked' ? "Disallow: /" : "Allow: /") . "\n";
                }
            }
        }
        $ai_section .= sanitize_textarea_field(get_option($this->option_prefix . 'ai_robots_custom', '')) . "\n";
        return $output . $ai_section;
    }
    
    public function add_ai_metabox() {
        foreach (get_post_types(['public' => true], 'names') as $type) {
            if ($type === 'attachment') continue;
            add_meta_box('lb_ai_metabox', '🤖 ' . __('Istruzioni AI / LLM', 'lean-bunker-seo'), [$this, 'ai_metabox_html'], $type, 'side', 'default');
        }
    }
    
    public function ai_metabox_html($post) {
        $rules = (array) get_post_meta($post->ID, '_lb_ai_robots', true);
        wp_nonce_field('lb_ai_save_meta', '_lb_ai_nonce');
        ?>
        <div style="padding:8px 0; font-size:13px;">
            <p style="margin:0 0 8px; color:#666;"><?php esc_html_e('Controlla come i crawler AI trattano questo contenuto.', 'lean-bunker-seo'); ?></p>
            <?php foreach (['noai' => __('Non usare per training AI', 'lean-bunker-seo'), 'noimageai' => __('Non usare immagini per AI', 'lean-bunker-seo'), 'noai-embed' => __('Non incorporare contenuti AI', 'lean-bunker-seo')] as $val => $label): ?>
            <label style="display:block; margin:4px 0; cursor:pointer;">
                <input type="checkbox" name="lb_ai_rules[]" value="<?php echo esc_attr($val); ?>" <?php checked(in_array($val, $rules)); ?>>
                <code><?php echo esc_html($val); ?></code> <?php echo esc_html($label); ?>
            </label>
            <?php endforeach; ?>
        </div>
        <?php
    }
    
    public function save_ai_meta($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id) || $post->post_type === 'revision') return;
        if (!isset($_POST['_lb_ai_nonce']) || !wp_verify_nonce($_POST['_lb_ai_nonce'], 'lb_ai_save_meta')) return;
        
        $raw_rules = isset($_POST['lb_ai_rules']) ? (array) $_POST['lb_ai_rules'] : [];
        $valid_rules = array_filter(array_intersect($raw_rules, self::ALLOWED_AI_RULES), 'is_string');
        update_post_meta($post_id, '_lb_ai_robots', array_values($valid_rules));
    }

    // ============================================================================
    // 🔥 KNOWLEDGE GRAPH HELPER FUNCTIONS
    // ============================================================================
    private function get_top_categories($limit = 3) {
        $args = ['taxonomy' => 'category', 'orderby' => 'count', 'order' => 'DESC', 'number' => $limit, 'hide_empty' => true, 'fields' => 'ids'];
        $term_ids = get_terms($args);
        if (empty($term_ids) || is_wp_error($term_ids)) { 
            $args['taxonomy'] = 'post_tag'; 
            $term_ids = get_terms($args); 
        }
        return (!empty($term_ids) && !is_wp_error($term_ids)) ? array_filter(array_map('get_term', $term_ids)) : [];
    }
    
    private function get_post_primary_category($post_id) {
        $categories = get_the_category($post_id); 
        if (empty($categories) || is_wp_error($categories)) return false;
        if (function_exists('yoast_get_primary_term_id')) {
            $primary = yoast_get_primary_term_id('category', $post_id);
            if ($primary) { 
                $main_cat = get_term($primary, 'category'); 
                if ($main_cat && !is_wp_error($main_cat)) return $main_cat; 
            }
        }
        return $this->get_deepest_category($categories);
    }
    
    private function get_deepest_category($cats) { 
        $d = null; $mx = 0; 
        foreach ($cats as $c) { 
            if (is_wp_error($c)) continue; 
            $dp = $this->get_category_depth($c); 
            if ($dp > $mx) { $mx = $dp; $d = $c; } 
        } 
        return $d ?: ($cats[0] ?? null); 
    }
    
    private function get_category_depth($cat) { 
        if (is_wp_error($cat)) return 0; 
        $d = 0; 
        while ($cat && $cat->parent) { 
            $d++; 
            $cat = get_term($cat->parent, 'category'); 
            if (is_wp_error($cat)) break; 
        } 
        return $d; 
    }
    
    private function get_page_hierarchy($id) { 
        $h = []; $p = get_post($id); 
        while ($p && $p->post_parent) { 
            $par = get_post($p->post_parent); 
            if (!$par || is_wp_error($par)) break; 
            array_unshift($h, $par); $p = $par; 
        } 
        return $h; 
    }
    
    private function get_category_hierarchy($c) { 
        $h = []; $cur = $c; 
        while ($cur && !is_wp_error($cur)) { 
            array_unshift($h, $cur); 
            if ($cur->parent) $cur = get_term($cur->parent, 'category'); else break; 
        } 
        return $h; 
    }

    // ============================================================================
    // 🧹 PULIZIA METATAG DUPLICATI
    // ============================================================================
    public function cleanup_duplicate_meta() { 
        if (is_admin() || defined('DOING_AJAX') || defined('DOING_CRON') || wp_doing_ajax() || ob_get_level() > 0) return; 
        if (ob_start([$this, 'cleanup_buffer_callback'])) register_shutdown_function([$this, 'safe_buffer_end']); 
    }
    
    public function cleanup_buffer_callback($buffer) { 
        if (empty($buffer) || !is_string($buffer) || (strpos($buffer, '<html') === false && strpos($buffer, '<!DOCTYPE') === false)) return $buffer; 
        $buffer = $this->remove_duplicate_meta_tags($buffer, 'name', 'description'); 
        foreach (['title', 'description', 'url', 'image'] as $prop) $buffer = $this->remove_duplicate_meta_tags($buffer, 'property', "og:{$prop}"); 
        return $buffer; 
    }
    
    private function remove_duplicate_meta_tags($buffer, $attr, $val) { 
        if (empty($buffer) || empty($attr) || empty($val)) return $buffer; 
        $pattern = '/<meta(?=[^>]*\b' . preg_quote($attr, '/') . '\s*=\s*["\']' . preg_quote($val, '/') . '["\'])[^>]*>/si'; 
        preg_match_all($pattern, $buffer, $matches, PREG_OFFSET_CAPTURE); 
        if (count($matches[0]) > 1) { 
            $last_match = array_pop($matches[0]); 
            $buffer = preg_replace($pattern, '', $buffer); 
            $buffer = substr_replace($buffer, $last_match[0], $last_match[1], 0); 
        } 
        return $buffer; 
    }
    
    public function safe_buffer_end() { 
        while (@ob_get_level() > 0) { @ob_end_flush(); } 
    }

    // ============================================================================
    // SEO: META TAGS & ROBOTS
    // ============================================================================
    public function get_seo_title($title) {
        if (!is_singular()) return $title; 
        $post_id = get_queried_object_id(); if (!$post_id) return $title;
        if ($t = get_post_meta($post_id, '_lb_seo_title', true)) return $t;
        $tpl = get_option($this->option_prefix . 'title_template', '{title} - {sitename}'); 
        $post_title = wp_strip_all_tags(get_the_title($post_id)); 
        $site_name = get_bloginfo('name'); $sep = apply_filters('document_title_separator', '-'); $cat = '';
        if (get_post_type($post_id) === 'post' && ($cats = get_the_category($post_id))) $cat = $cats[0]->name;
        $tpl = str_replace(['{title}', '{sitename}', '{category}', '{sep}'], [$post_title, $site_name, $cat, $sep], $tpl);
        if (strpos($tpl, '{') !== false) $tpl = "{$post_title} {$sep} {$site_name}";
        $tpl = trim($tpl);
        return (mb_strlen($tpl) > 60) ? mb_substr($tpl, 0, 57) . '...' : $tpl;
    }
    
    public function output_seo_meta() {
        if (is_category() || is_tag() || is_tax()) { $this->output_taxonomy_seo(); return; }
        if (!is_singular()) return; 
        $post_id = get_queried_object_id(); if (!$post_id) return;
        
        $title = get_post_meta($post_id, '_lb_seo_title', true) ?: wp_strip_all_tags(get_the_title($post_id));
        $desc  = get_post_meta($post_id, '_lb_seo_desc', true);
        if (!$desc) { 
            $desc = get_the_excerpt($post_id); 
            if (!$desc) { 
                $post = get_post($post_id); 
                $desc = $post ? wp_trim_words(wp_strip_all_tags($post->post_content ?? ''), 25, '...') : ''; 
            } 
        }
        $url = get_permalink($post_id); 
        $image = get_the_post_thumbnail_url($post_id, 'large') ?: get_site_icon_url(512) ?: get_option($this->option_prefix . 'og_fallback_image');
        $og_type = (get_post_type($post_id) === 'post') ? 'article' : 'website';
        
        if ($desc) echo '<meta name="description" content="' . esc_attr(wp_strip_all_tags($desc)) . '">' . "\n";
        if ($url) echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
        
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr(wp_strip_all_tags($desc)) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:type" content="' . esc_attr($og_type) . '">' . "\n";
        
        if ($image) { 
            echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
            $image_id = get_post_thumbnail_id($post_id);
            if ($image_id) { 
                $meta = wp_get_attachment_metadata($image_id); 
                $width = isset($meta['width']) ? absint($meta['width']) : 1200; 
                $height = isset($meta['height']) ? absint($meta['height']) : 630; 
            } else { 
                $width = 1200; $height = 630; 
            }
            echo '<meta property="og:image:width" content="' . $width . '">' . "\n";
            echo '<meta property="og:image:height" content="' . $height . '">' . "\n";
        }
    }
    
    private function output_taxonomy_seo() {
        $term = get_queried_object(); if (!$term || is_wp_error($term)) return;
        $title = get_term_meta($term->term_id, '_lb_seo_title', true) ?: "{$term->name} - " . get_bloginfo('name');
        $desc = get_term_meta($term->term_id, '_lb_seo_desc', true); 
        if (!$desc && !empty($term->description)) $desc = wp_trim_words(wp_strip_all_tags($term->description), 25, '...');
        $url = get_term_link($term); if (is_wp_error($url)) return;
        
        if ($desc) echo '<meta name="description" content="' . esc_attr(wp_strip_all_tags($desc)) . '">' . "\n";
        echo '<link rel="canonical" href="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        if ($desc) echo '<meta property="og:description" content="' . esc_attr(wp_strip_all_tags($desc)) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n<meta property=\"og:type\" content=\"website\">\n";
    }

    public function output_combined_robots_meta() {
        $directives = [];
        if (is_search() && get_option($this->option_prefix . 'noindex_search') === '1') $directives[] = 'noindex,follow';
        if ((is_category() || is_tag() || is_author() || is_date()) && get_option($this->option_prefix . 'noindex_archives') === '1') $directives[] = 'noindex,follow';
        
        $id = get_queried_object_id(); 
        if ($id) {
            $custom_robots = is_tax() ? get_term_meta($id, '_lb_seo_robots', true) : get_post_meta($id, '_lb_seo_robots', true); 
            if ($custom_robots) $directives[] = $custom_robots;
            $ai_rules = get_post_meta($id, '_lb_ai_robots', true); 
            if (is_array($ai_rules) && !empty($ai_rules)) $directives = array_merge($directives, array_intersect(['noai', 'noimageai', 'noai-embed'], $ai_rules));
        }
        
        if (!empty($directives)) { 
            $merged = []; 
            foreach ($directives as $d) $merged = array_merge($merged, array_map('trim', explode(',', $d))); 
            echo '<meta name="robots" content="' . esc_attr(implode(',', array_unique(array_filter($merged)))) . '">' . "\n"; 
        }
    }

    public function add_nofollow_external_links($content) {
        if (get_option($this->option_prefix . 'nofollow_external') !== '1') return $content;
        $home_host = parse_url(home_url(), PHP_URL_HOST); if (!$home_host) return $content;
        return preg_replace_callback('/<a\s+([^>]*href=["\']https?:\/\/(?!' . preg_quote($home_host, '/') . ')[^"\']+["\'][^>]*)>/i', function($m) { 
            return preg_match('/rel=["\'][^"\']*nofollow/i', $m[0]) ? $m[0] : str_replace('<a', '<a rel="nofollow"', $m[0]); 
        }, $content);
    }

    // ============================================================================
    // SCHEMA.ORG JSON-LD
    // ============================================================================
    public function output_schema_markup() {
        if (is_category() || is_tag() || is_tax()) { $term = get_queried_object(); if ($term && !is_wp_error($term)) $this->output_taxonomy_schema($term); return; }
        if (!is_singular()) return; 
        global $post; if (!$post || !($post instanceof WP_Post) || $post->post_status !== 'publish') return;
        $json = $this->generate_complete_schema($post->ID); if ($json) echo $json;
    }
    
    private function generate_complete_schema($post_id) {
        $schemas = [];
        if ($w = $this->generate_website_schema()) $schemas[] = $w;
        if ($wp = $this->generate_webpage_schema($post_id)) $schemas[] = $wp; else { $p = get_permalink($post_id); if ($p) $schemas[] = ['@type' => 'WebPage', '@id' => esc_url($p), 'url' => esc_url($p), 'name' => get_the_title($post_id)]; }
        if (get_post_type($post_id) === 'post' && ($a = $this->generate_article_schema($post_id))) $schemas[] = $a;
        if ($au = $this->generate_author_schema($post_id)) $schemas[] = $au;
        if ($pub = $this->generate_publisher_schema()) $schemas[] = $pub;
        if ($img = $this->generate_image_schema($post_id)) $schemas[] = $img;
        if ($bc = $this->generate_breadcrumb_schema($post_id)) $schemas[] = $bc;
        
        $schemas = array_values(array_filter($schemas, function($s) { 
            return is_array($s) && isset($s['@type']) && in_array($s['@type'], self::ALLOWED_SCHEMA_TYPES) && (!isset($s['@id']) || $s['@id']); 
        }));
        
        if (count($schemas) < 3) { 
            $p = get_permalink($post_id); if (!$p) return false; 
            $schemas = [
                ['@type' => 'WebSite', '@id' => home_url() . '#website', 'url' => home_url(), 'name' => get_bloginfo('name')], 
                ['@type' => 'WebPage', '@id' => esc_url($p), 'url' => esc_url($p), 'name' => get_the_title($post_id)], 
                ['@type' => 'Organization', '@id' => home_url() . '#organization', 'name' => get_bloginfo('name'), 'url' => home_url()]
            ]; 
        }
        
        return '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@graph' => $schemas], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>';
    }
    
    private function output_taxonomy_schema($term) {
        $url = get_term_link($term); if (is_wp_error($url)) return;
        $graph = array_filter([
            $this->generate_website_schema(), 
            ['@type' => 'CollectionPage', '@id' => esc_url($url), 'url' => esc_url($url), 'name' => $term->name, 'description' => !empty($term->description) ? wp_strip_all_tags($term->description) : '', 'isPartOf' => ['@id' => home_url() . '#website'], 'about' => ['@id' => home_url() . '#organization'], 'mainEntity' => ['@type' => 'DefinedTerm', 'name' => $term->name, 'inDefinedTermSet' => home_url()]], 
            $this->generate_publisher_schema() ? [$this->generate_publisher_schema()] : []
        ]);
        echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@graph' => array_values($graph)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>';
    }
    
    private function generate_website_schema() { 
        $home = home_url(); if (!$home) return false; 
        $s = ['@type' => 'WebSite', '@id' => esc_url($home) . '#website', 'url' => esc_url($home), 'name' => get_bloginfo('name'), 'description' => get_bloginfo('description'), 'publisher' => ['@id' => esc_url($home) . '#organization'], 'about' => ['@id' => esc_url($home) . '#organization'], 'potentialAction' => ['@type' => 'SearchAction', 'target' => esc_url($home) . '?s={search_term_string}', 'query-input' => 'required name=search_term_string']]; 
        $topics = $this->get_top_categories(3); 
        if (!empty($topics)) $s['about'] = array_map(function($c) { return ['@type' => 'Thing', 'name' => $c->name, 'sameAs' => get_category_link($c->term_id)]; }, $topics); 
        return $s; 
    }
    
    private function generate_webpage_schema($post_id) { 
        $post = get_post($post_id); $url = get_permalink($post_id); if (!$post || !$url) return false; 
        $s = ['@type' => 'WebPage', '@id' => esc_url($url) . '#webpage', 'url' => esc_url($url), 'name' => get_the_title($post_id), 'isPartOf' => ['@id' => home_url() . '#website'], 'datePublished' => get_the_date('c', $post_id), 'dateModified' => get_the_modified_date('c', $post_id), 'inLanguage' => get_locale(), 'potentialAction' => ['@type' => 'ReadAction', 'target' => esc_url($url)]]; 
        if ($img = $this->generate_image_schema($post_id) ?? false) $s['primaryImageOfPage'] = ['@id' => $img['@id']]; 
        if ($ex = get_the_excerpt($post_id)) $s['description'] = wp_strip_all_tags($ex); 
        return $s; 
    }
    
    private function generate_article_schema($post_id) { 
        $post = get_post($post_id); $url = get_permalink($post_id); if (!$post || !$url) return false; 
        $s = ['@type' => 'Article', '@id' => esc_url($url) . '#article', 'isPartOf' => ['@id' => esc_url($url) . '#webpage'], 'headline' => get_the_title($post_id), 'datePublished' => get_the_date('c', $post_id), 'dateModified' => get_the_modified_date('c', $post_id), 'author' => ['@id' => get_author_posts_url($post->post_author) . '#author'], 'publisher' => ['@id' => home_url() . '#organization'], 'mainEntityOfPage' => ['@id' => esc_url($url) . '#webpage'], 'inLanguage' => get_locale()]; 
        if ($cat = $this->get_post_primary_category($post_id)) $s['about'] = ['@type' => 'Thing', 'name' => $cat->name, 'sameAs' => get_category_link($cat->term_id)]; 
        if (has_post_thumbnail($post_id)) { $img = $this->generate_image_schema($post_id); if ($img) { $s['thumbnailUrl'] = $img['url'] ?? ''; if (isset($img['@id'])) $s['image'] = ['@id' => $img['@id']]; } } 
        if ($ex = get_the_excerpt($post_id)) $s['description'] = wp_strip_all_tags($ex); 
        $s['timeRequired'] = 'PT' . max(1, round(str_word_count(strip_tags($post->post_content)) / 200)) . 'M'; 
        if ($tags = get_the_tags($post_id)) $s['keywords'] = implode(', ', wp_list_pluck($tags, 'name')); 
        $s['speakable'] = ['@type' => 'SpeakableSpecification', 'xpath' => ['//head/title', '//article//h1[1]', '//*[@itemprop="headline"]']]; 
        if ($cats = get_the_category($post_id)) $s['articleSection'] = $cats[0]->name; 
        return $s; 
    }
    
    private function generate_author_schema($post_id) { 
        $post = get_post($post_id); if (!$post) return false; 
        $au_url = get_author_posts_url($post->post_author); if (!$au_url) return false; 
        $s = ['@type' => 'Person', '@id' => esc_url($au_url) . '#author', 'name' => get_the_author_meta('display_name', $post->post_author), 'url' => esc_url($au_url), 'sameAs' => [esc_url($au_url)], 'worksFor' => ['@id' => home_url() . '#organization']]; 
        if ($img = $this->get_author_image($post->post_author)) $s['image'] = ['@type' => 'ImageObject', 'url' => esc_url($img)]; 
        if ($desc = get_the_author_meta('description', $post->post_author)) $s['description'] = wp_strip_all_tags($desc); 
        return $s; 
    }
    
    private function generate_publisher_schema() { 
        $home = home_url(); if (!$home) return false; 
        $s = ['@type' => 'Organization', '@id' => esc_url($home) . '#organization', 'name' => get_bloginfo('name'), 'url' => esc_url($home)]; 
        $logo = $this->get_site_logo_url(); if ($logo) $s['logo'] = ['@type' => 'ImageObject', '@id' => esc_url($home) . '#logo', 'url' => esc_url($logo), 'width' => 600, 'height' => 60]; 
        $s['sameAs'] = $this->get_social_profiles(); 
        $topics = $this->get_top_categories(5); if (!empty($topics)) $s['knowsAbout'] = array_map(fn($c) => $c->name, $topics); 
        return $s; 
    }
    
    private function generate_image_schema($post_id) { 
        if (!has_post_thumbnail($post_id)) return false; 
        $thumb_id = get_post_thumbnail_id($post_id); $url = get_the_post_thumbnail_url($post_id, 'full'); if (!$url) return false; 
        $meta = wp_get_attachment_metadata($thumb_id); 
        $s = ['@type' => 'ImageObject', '@id' => esc_url($url) . '#image', 'url' => esc_url($url)]; 
        if (isset($meta['width'])) $s['width'] = absint($meta['width']); 
        if (isset($meta['height'])) $s['height'] = absint($meta['height']); 
        if ($alt = get_post_meta($thumb_id, '_wp_attachment_image_alt', true)) $s['caption'] = sanitize_text_field($alt); 
        return $s; 
    }
    
    private function generate_breadcrumb_schema($post_id) { 
        $post = get_post($post_id); if (!$post) return false; 
        $has_cat = has_category('', $post_id); $has_par = $post->post_parent > 0; if (!$has_cat && !$has_par) return false; 
        $items = [['@type' => 'ListItem', 'position' => 1, 'item' => ['@type' => 'WebPage', '@id' => home_url(), 'name' => __('Home', 'lean-bunker-seo')]]]; $pos = 2; 
        if ($has_cat && get_post_type($post_id) === 'post') { if ($cat = $this->get_post_primary_category($post_id)) $items[] = ['@type' => 'ListItem', 'position' => $pos++, 'item' => ['@type' => 'WebPage', '@id' => get_category_link($cat->term_id), 'name' => $cat->name]]; } 
        elseif ($has_par) { foreach ($this->get_page_hierarchy($post_id) as $p) $items[] = ['@type' => 'ListItem', 'position' => $pos++, 'item' => ['@type' => 'WebPage', '@id' => get_permalink($p->ID), 'name' => $p->post_title]]; } 
        $items[] = ['@type' => 'ListItem', 'position' => $pos, 'item' => ['@type' => 'WebPage', '@id' => esc_url(get_permalink($post_id)), 'name' => get_the_title($post_id)]]; 
        return ['@type' => 'BreadcrumbList', '@id' => esc_url(get_permalink($post_id)) . '#breadcrumb', 'itemListElement' => $items]; 
    }
    
    private function get_site_logo_url() { 
        $id = get_theme_mod('custom_logo'); if ($id) { $l = wp_get_attachment_image_src($id, 'full'); if ($l && !is_wp_error($l)) return $l[0]; } return get_site_icon_url(512) ?: ''; 
    }
    
    private function get_author_image($id) { $u = get_avatar_url($id, ['size' => 250]); return ($u && strpos($u, 'gravatar.com') === false) ? $u : false; }
    
    private function get_social_profiles() { 
        $profiles = []; 
        if (function_exists('YoastSEO')) { 
            $yoast = YoastSEO(); 
            if (is_object($yoast) && isset($yoast->meta) && method_exists($yoast->meta, 'for_current_page')) { 
                $page_meta = $yoast->meta->for_current_page(); 
                if (is_object($page_meta) && isset($page_meta->twitter_social_profiles) && is_array($page_meta->twitter_social_profiles)) return array_map('esc_url', $page_meta->twitter_social_profiles); 
            } 
        } 
        foreach ([get_theme_mod('facebook_url'), get_theme_mod('twitter_url'), get_theme_mod('linkedin_url'), get_theme_mod('instagram_url')] as $u) if ($u) $profiles[] = esc_url($u); 
        return $profiles; 
    }

    // ============================================================================
    // BREADCRUMB SHORTCODE
    // ============================================================================
    public function shortcode_breadcrumb($atts = []) {
        $a = shortcode_atts(['home_label' => __('Home', 'lean-bunker-seo'), 'separator' => '&raquo;', 'wrap_before' => '<nav class="lb-breadcrumb" aria-label="Breadcrumb">', 'wrap_after' => '</nav>', 'before' => '', 'after' => '', 'show_schema' => 'true'], $atts, 'lb_breadcrumb'); 
        $items = $this->get_breadcrumb_items(); if (empty($items)) return ''; 
        $out = $a['wrap_before'] . $a['before']; $c = count($items); $p = 1; 
        foreach ($items as $it) { 
            $last = ($p === $c); 
            $out .= $last ? '<span class="lb-breadcrumb-current">' . esc_html($it['name']) . '</span>' : '<a href="' . esc_url($it['url']) . '">' . esc_html($it['name']) . '</a>'; 
            if (!$last) $out .= ' <span class="lb-breadcrumb-separator">' . $a['separator'] . '</span> '; 
            $p++; 
        } 
        $out .= $a['after'] . $a['wrap_after']; 
        if ($a['show_schema'] === 'true' && ($sc = $this->generate_breadcrumb_schema_from_items($items))) $out .= $sc; 
        return $out; 
    }
    
    private function get_breadcrumb_items() { 
        $items = [['name' => __('Home', 'lean-bunker-seo'), 'url' => home_url()]]; 
        if (is_front_page() || is_home()) return $items; 
        global $post; 
        if (is_singular()) { 
            if (get_post_type() === 'post' && has_category() && ($cats = get_the_category($post->ID)) && ($cat = $this->get_post_primary_category($post->ID))) { 
                foreach ($this->get_category_hierarchy($cat) as $c) $items[] = ['name' => $c->name, 'url' => get_category_link($c->term_id)]; 
            } elseif ($post->post_parent) foreach ($this->get_page_hierarchy($post->ID) as $p) $items[] = ['name' => $p->post_title, 'url' => get_permalink($p->ID)]; 
            $items[] = ['name' => get_the_title($post->ID), 'url' => get_permalink($post->ID)]; 
        } elseif (is_category() || is_tag() || is_tax()) { 
            $term = get_queried_object(); 
            if (is_category() && $term->parent) { $p = $this->get_category_hierarchy($term); array_pop($p); foreach ($p as $x) $items[] = ['name' => $x->name, 'url' => get_category_link($x->term_id)]; } 
            $items[] = ['name' => $term->name, 'url' => get_term_link($term)]; 
        } elseif (is_author()) { $au = get_queried_object(); $items[] = ['name' => $au->display_name, 'url' => get_author_posts_url($au->ID)]; } 
        elseif (is_date()) { 
            if (is_year()) $items[] = ['name' => get_the_date('Y'), 'url' => get_year_link(get_query_var('year'))]; 
            elseif (is_month()) $items[] = ['name' => get_the_date('F Y'), 'url' => get_month_link(get_query_var('year'), get_query_var('monthnum'))]; 
            elseif (is_day()) $items[] = ['name' => get_the_date('F j, Y'), 'url' => get_day_link(get_query_var('year'), get_query_var('monthnum'), get_query_var('day'))]; 
        } elseif (is_search()) $items[] = ['name' => sprintf(__('Risultati per: %s', 'lean-bunker-seo'), get_search_query()), 'url' => '']; 
        elseif (is_404()) $items[] = ['name' => __('Pagina non trovata', 'lean-bunker-seo'), 'url' => '']; 
        return $items; 
    }
    
    private function generate_breadcrumb_schema_from_items($items) { 
        if (count($items) < 2) return ''; 
        $li = []; $p = 1; 
        foreach ($items as $it) $li[] = ['@type' => 'ListItem', 'position' => $p++, 'item' => ['@type' => 'WebPage', '@id' => !empty($it['url']) ? esc_url($it['url']) : home_url(), 'name' => $it['name']]]; 
        return '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@graph' => [['@type' => 'BreadcrumbList', '@id' => home_url() . '#breadcrumb', 'itemListElement' => $li]]], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>'; 
    }

    // ============================================================================
    // ADMIN & AJAX
    // ============================================================================
    public function admin_menu() { add_options_page('Lean Bunker SEO', 'Lean Bunker SEO', 'manage_options', 'lean-bunker-seo', [$this, 'admin_page']); }
    
    public function admin_page() {
        if (!current_user_can('manage_options')) return;
        
        if (isset($_POST['lb_seo_save'])) {
            check_admin_referer('lb_seo_nonce');
            $opts = [
                'og_fallback_image' => 'esc_url_raw', 'title_template' => 'sanitize_text_field', 
                'noindex_archives' => 'int', 'noindex_search' => 'int', 'nofollow_external' => 'int', 
                'sitemap_ping_interval' => 'sanitize_text_field', 'baidu_token' => 'sanitize_text_field', 
                'cleanup_duplicates' => 'int', 'enable_llms_txt' => 'int', 
                'ai_ping_interval' => 'sanitize_text_field', 'ai_ping_endpoints' => 'sanitize_textarea_field', 
                'ai_robots_enable' => 'int', 'ai_robots_blocked_bots' => 'sanitize_textarea_field', 
                'ai_robots_allowed_bots' => 'sanitize_textarea_field', 'ai_robots_custom' => 'sanitize_textarea_field'
            ];
            foreach ($opts as $k => $fn) update_option($this->option_prefix . $k, $fn === 'int' ? (isset($_POST[$k]) ? '1' : '0') : ($fn($_POST[$k] ?? '')));
            $this->reschedule_pinger(); $this->reschedule_ai_pinger();
            $old_llms = get_option($this->option_prefix . 'enable_llms_txt', '0');
            $new_llms = isset($_POST['enable_llms_txt']) ? '1' : '0';
            if ($old_llms !== $new_llms) update_option('lb_seo_pending_rewrite', true);
            echo '<div class="notice notice-success"><p>✅ ' . __('Impostazioni salvate.', 'lean-bunker-seo') . '</p></div>';
        }
        
        $og = get_option($this->option_prefix . 'og_fallback_image'); 
        $tpl = get_option($this->option_prefix . 'title_template', '{title} - {sitename}');
        $ni_arc = get_option($this->option_prefix . 'noindex_archives', '0'); 
        $ni_ser = get_option($this->option_prefix . 'noindex_search', '0');
        $nf_ext = get_option($this->option_prefix . 'nofollow_external', '0'); 
        $pi_int = get_option($this->option_prefix . 'sitemap_ping_interval', '');
        $bd_tok = get_option($this->option_prefix . 'baidu_token', ''); 
        $cl_dup = get_option($this->option_prefix . 'cleanup_duplicates', '0');
        $en_llms = get_option($this->option_prefix . 'enable_llms_txt', '1'); 
        $ai_pi = get_option($this->option_prefix . 'ai_ping_interval', '');
        $ai_ep = get_option($this->option_prefix . 'ai_ping_endpoints', ''); 
        $ai_rb = get_option($this->option_prefix . 'ai_robots_enable', '0');
        $ai_blk = get_option($this->option_prefix . 'ai_robots_blocked_bots', "GPTBot\nCCBot\nDiffbot\nOmgilibot");
        $ai_all = get_option($this->option_prefix . 'ai_robots_allowed_bots', ''); 
        $ai_cus = get_option($this->option_prefix . 'ai_robots_custom', '');
        $plog = get_option('lbs_last_ping_log', []); $pnxt = wp_next_scheduled('lean_bunker_sitemap_ping');
        $alog = get_option('lbs_last_ai_ping_log', []); $anxt = wp_next_scheduled('lean_bunker_ai_ping');
        ?>
        <div class="wrap" style="max-width:900px;">
            <h1>Lean Bunker SEO <small style="font-size:0.6em; color:#666;">v2.5.0</small></h1>
            <div style="background:#f0f6ff; padding:15px; border-left:4px solid #2271b1; margin-bottom:20px;">
                <strong>✨ <?php esc_html_e('Plugin completamente autonomo', 'lean-bunker-seo'); ?></strong> - Meta tags, Open Graph, Schema.org e breadcrumb nativi.<br>
                <strong style="color:#2271b1;">🤖 v2.5.0:</strong> <?php esc_html_e('Codice espanso, sintassi verificata, attivazione isolata, zero minificazione.', 'lean-bunker-seo'); ?>
            </div>
            <form method="post">
                <?php wp_nonce_field('lb_seo_nonce'); ?>
                <table class="form-table">
                    <tr><th scope="row"><?php esc_html_e('Open Graph Fallback Image', 'lean-bunker-seo'); ?></th><td><input type="url" name="og_fallback_image" value="<?php echo esc_attr($og); ?>" class="regular-text"><p class="description"><?php esc_html_e('URL immagine fallback (1200×630).', 'lean-bunker-seo'); ?></p></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Title Template', 'lean-bunker-seo'); ?></th><td><input type="text" name="title_template" value="<?php echo esc_attr($tpl); ?>" class="regular-text"><p class="description"><?php esc_html_e('Token:', 'lean-bunker-seo'); ?> <code>{title}</code>, <code>{sitename}</code>, <code>{category}</code>, <code>{sep}</code></p></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Pulizia Metatag Duplicati', 'lean-bunker-seo'); ?></th><td><label><input type="checkbox" name="cleanup_duplicates" value="1" <?php checked($cl_dup, '1'); ?>> <?php esc_html_e('Attiva', 'lean-bunker-seo'); ?></label><p class="description"><?php esc_html_e('Usa solo se vedi duplicati nel sorgente HTML.', 'lean-bunker-seo'); ?></p></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Noindex/Nofollow', 'lean-bunker-seo'); ?></th><td><label><input type="checkbox" name="noindex_archives" value="1" <?php checked($ni_arc, '1'); ?>> <?php esc_html_e('Archivi', 'lean-bunker-seo'); ?></label><br><label><input type="checkbox" name="noindex_search" value="1" <?php checked($ni_ser, '1'); ?>> <?php esc_html_e('Ricerca', 'lean-bunker-seo'); ?></label><br><label><input type="checkbox" name="nofollow_external" value="1" <?php checked($nf_ext, '1'); ?>> <?php esc_html_e('Link esterni', 'lean-bunker-seo'); ?></label></td></tr>
                </table>
                <hr><h2>📡 <?php esc_html_e('Sitemap Auto-Ping', 'lean-bunker-seo'); ?></h2>
                <?php if (!empty($plog)): ?>
                <div style="background:#f8f9f9; padding:15px; margin-bottom:20px; border-left:4px solid #11a0d2;">
                    <p><strong><?php esc_html_e('Ultimo:', 'lean-bunker-seo'); ?></strong> <?php echo date('d/m/Y H:i:s', $plog['timestamp'] ?? time()); ?> | <strong><?php esc_html_e('Prossimo:', 'lean-bunker-seo'); ?></strong> <?php echo $pnxt ? date('d/m/Y H:i:s', $pnxt) : __('Non schedulato', 'lean-bunker-seo'); ?></p>
                    <button type="button" id="lb-manual-ping" class="button button-secondary"><span class="dashicons dashicons-update"></span> <?php esc_html_e('Ping Ora', 'lean-bunker-seo'); ?></button> <span id="lb-ping-status"></span>
                </div><?php endif; ?>
                <table class="form-table">
                    <tr><th scope="row"><?php esc_html_e('Frequenza', 'lean-bunker-seo'); ?></th><td><select name="sitemap_ping_interval"><?php foreach ([''=>__('Disabilitato', 'lean-bunker-seo'),'hourly'=>__('Ora', 'lean-bunker-seo'),'twicedaily'=>__('2x/giorno', 'lean-bunker-seo'),'daily'=>__('Giornaliero', 'lean-bunker-seo'),'weekly'=>__('Settimanale', 'lean-bunker-seo'),'monthly'=>__('Mensile', 'lean-bunker-seo')] as $v=>$l) echo "<option value=\"$v\" ".selected($pi_int,$v,false).">$l</option>"; ?></select></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Baidu Token', 'lean-bunker-seo'); ?></th><td><input type="text" name="baidu_token" value="<?php echo esc_attr($bd_tok); ?>" class="regular-text"></td></tr>
                </table>
                <hr><h2>🤖 <?php esc_html_e('AI & LLM Integration', 'lean-bunker-seo'); ?></h2>
                <?php if (!empty($alog) && $en_llms === '1'): ?>
                <div style="background:#f8f9f9; padding:15px; margin-bottom:20px; border-left:4px solid #7f54b3;">
                    <p><strong><?php esc_html_e('Ultimo AI:', 'lean-bunker-seo'); ?></strong> <?php echo date('d/m/Y H:i:s', $alog['timestamp'] ?? time()); ?> | <strong><?php esc_html_e('Prossimo:', 'lean-bunker-seo'); ?></strong> <?php echo $anxt ? date('d/m/Y H:i:s', $anxt) : __('Non schedulato', 'lean-bunker-seo'); ?></p>
                    <button type="button" id="lb-manual-ai-ping" class="button button-secondary"><span class="dashicons dashicons-brain"></span> <?php esc_html_e('Ping AI Ora', 'lean-bunker-seo'); ?></button> <span id="lb-ai-ping-status"></span>
                </div><?php endif; ?>
                <table class="form-table">
                    <tr><th scope="row"><?php esc_html_e('Abilita llms.txt', 'lean-bunker-seo'); ?></th><td><label><input type="checkbox" name="enable_llms_txt" value="1" <?php checked($en_llms, '1'); ?>> <?php esc_html_e('Genera file', 'lean-bunker-seo'); ?></label></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Frequenza AI Ping', 'lean-bunker-seo'); ?></th><td><select name="ai_ping_interval"><?php foreach ([''=>__('Disabilitato', 'lean-bunker-seo'),'hourly'=>__('Ora', 'lean-bunker-seo'),'twicedaily'=>__('2x/giorno', 'lean-bunker-seo'),'daily'=>__('Giornaliero', 'lean-bunker-seo')] as $v=>$l) echo "<option value=\"$v\" ".selected($ai_pi,$v,false).">$l</option>"; ?></select></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Endpoint AI', 'lean-bunker-seo'); ?></th><td><textarea name="ai_ping_endpoints" rows="4" class="large-text"><?php echo esc_textarea($ai_ep); ?></textarea><p class="description"><?php esc_html_e('Formato:', 'lean-bunker-seo'); ?> <code><?php esc_html_e('Nome|URL', 'lean-bunker-seo'); ?></code> (<?php esc_html_e('uno per riga', 'lean-bunker-seo'); ?>)</p></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Controllo Bot AI', 'lean-bunker-seo'); ?></th><td><label><input type="checkbox" name="ai_robots_enable" value="1" <?php checked($ai_rb, '1'); ?>> <?php esc_html_e('Abilita direttive', 'lean-bunker-seo'); ?></label></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Bot Bloccati', 'lean-bunker-seo'); ?></th><td><textarea name="ai_robots_blocked_bots" rows="3" class="large-text"><?php echo esc_textarea($ai_blk); ?></textarea></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Bot Consentiti', 'lean-bunker-seo'); ?></th><td><textarea name="ai_robots_allowed_bots" rows="2" class="large-text"><?php echo esc_textarea($ai_all); ?></textarea></td></tr>
                    <tr><th scope="row"><?php esc_html_e('Regole Custom', 'lean-bunker-seo'); ?></th><td><textarea name="ai_robots_custom" rows="3" class="large-text"><?php echo esc_textarea($ai_cus); ?></textarea></td></tr>
                </table>
                <hr><h2>🔄 <?php esc_html_e('Completa contenuti esistenti', 'lean-bunker-seo'); ?></h2>
                <button id="lb-bulk-start" class="button button-secondary"><?php esc_html_e('Avvia completamento', 'lean-bunker-seo'); ?></button>
                <div id="lb-bulk-status" style="margin-top:10px; padding:10px; background:#f8f9f9; display:none;"></div>
                <p class="submit"><input type="submit" name="lb_seo_save" class="button button-primary" value="<?php esc_attr_e('Salva impostazioni', 'lean-bunker-seo'); ?>"></p>
            </form>
        </div>
        <script>
        jQuery(document).ready(function($) {
            function ajaxPing(action, nonce, btnId, statusId, successMsg) {
                var $b = $('#' + btnId), $s = $('#' + statusId);
                $b.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: spin 1s linear infinite;"></span> <?php echo esc_js(__('Invio...', 'lean-bunker-seo')); ?>'); 
                $s.html('');
                $.post(ajaxurl, {action: action, nonce: nonce}, function(r) {
                    $b.prop('disabled', false).html($('#' + btnId).data('orig'));
                    var msg = (r.data && r.data.message) ? r.data.message : (r.success ? successMsg : '<?php echo esc_js(__('Errore', 'lean-bunker-seo')); ?>');
                    $s.html(r.success ? '<span style="color:#46b450;">✅ ' + msg + '</span>' : '<span style="color:#dc3232;">❌ ' + msg + '</span>');
                }).fail(function() { 
                    $b.prop('disabled', false).html($('#' + btnId).data('orig')); 
                    $s.html('<span style="color:#dc3232;">❌ <?php echo esc_js(__('Connessione fallita', 'lean-bunker-seo')); ?></span>'); 
                });
            }
            $('#lb-manual-ping').data('orig', '<span class="dashicons dashicons-update"></span> <?php echo esc_js(__('Ping Sitemap Ora', 'lean-bunker-seo')); ?>').on('click', function() { 
                ajaxPing('lb_seo_manual_ping', '<?php echo wp_create_nonce("lb_seo_manual_ping"); ?>', 'lb-manual-ping', 'lb-ping-status', '<?php echo esc_js(__('Ping Sitemap inviati!', 'lean-bunker-seo')); ?>'); 
            });
            $('#lb-manual-ai-ping').data('orig', '<span class="dashicons dashicons-brain"></span> <?php echo esc_js(__('Ping AI Ora', 'lean-bunker-seo')); ?>').on('click', function() { 
                ajaxPing('lb_seo_manual_ai_ping', '<?php echo wp_create_nonce("lb_seo_manual_ai_ping"); ?>', 'lb-manual-ai-ping', 'lb-ai-ping-status', '<?php echo esc_js(__('Ping AI inviati in background!', 'lean-bunker-seo')); ?>'); 
            });
            $('#lb-bulk-start').on('click', function() {
                var $btn = $(this), $status = $('#lb-bulk-status'), processed = 0;
                $btn.prop('disabled', true).text('<?php echo esc_js(__('In corso...', 'lean-bunker-seo')); ?>'); 
                $status.show().html('<?php echo esc_js(__('Ricerca contenuti mancanti…', 'lean-bunker-seo')); ?>');
                function getBatch() {
                    $.post(ajaxurl, { action: 'lb_seo_get_missing', nonce: '<?php echo wp_create_nonce("lb_seo_bulk"); ?>' }, function(res) {
                        if (!res.success || res.data.length === 0) { 
                            $status.html('✅ <?php echo esc_js(__('Completato!', 'lean-bunker-seo')); ?> ' + processed + ' <?php echo esc_js(__('elementi.', 'lean-bunker-seo')); ?>'); 
                            $btn.text('<?php echo esc_js(__('Fatto!', 'lean-bunker-seo')); ?>'); 
                            delete_transient('lb_seo_bulk_lock'); 
                            return; 
                        }
                        processBatch(res.data);
                    });
                }
                function processBatch(ids) {
                    if (ids.length === 0) { getBatch(); return; }
                    var id = ids.shift();
                    $.post(ajaxurl, { action: 'lb_seo_generate_single', post_id: id, nonce: '<?php echo wp_create_nonce("lb_seo_bulk"); ?>' }, function() {
                        processed++;
                        $status.html('<?php echo esc_js(__('Processati:', 'lean-bunker-seo')); ?> ' + processed + ' | <?php echo esc_js(__('In coda:', 'lean-bunker-seo')); ?> ' + ids.length);
                        setTimeout(function() { processBatch(ids); }, 200);
                    });
                }
                getBatch();
            });
        });
        </script>
        <style>@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }</style>
        <?php
    }
    
    public function enqueue_scripts($hook) { if ('post.php' !== $hook && 'post-new.php' !== $hook && 'settings_page_lean-bunker-seo' !== $hook) return; wp_enqueue_script('jquery'); }
    public function add_metabox() { foreach (get_post_types(['public' => true], 'names') as $type) { if ($type === 'attachment') continue; add_meta_box('lb_seo_metabox', 'Lean Bunker SEO', [$this, 'metabox_html'], $type, 'normal', 'high'); } }
    public function metabox_html($post) {
        $t = get_post_meta($post->ID, '_lb_seo_title', true); $d = get_post_meta($post->ID, '_lb_seo_desc', true); $r = get_post_meta($post->ID, '_lb_seo_robots', true);
        ?><div style="background:#f8f9f9; padding:12px; border-left:4px solid #2271b1;">
            <p><strong><?php esc_html_e('Meta Title (max 60)', 'lean-bunker-seo'); ?></strong><br><input type="text" name="_lb_seo_title" value="<?php echo esc_attr($t); ?>" style="width:100%; max-width:600px;" maxlength="60"></p>
            <p><strong><?php esc_html_e('Meta Description (max 155)', 'lean-bunker-seo'); ?></strong><br><textarea name="_lb_seo_desc" rows="3" style="width:100%; max-width:600px;" maxlength="155"><?php echo esc_textarea($d); ?></textarea></p>
            <p><strong><?php esc_html_e('Meta Robots', 'lean-bunker-seo'); ?></strong><br><select name="_lb_seo_robots" style="width:100%; max-width:600px;"><option value=""><?php esc_html_e('Default', 'lean-bunker-seo'); ?></option><option value="noindex,follow" <?php selected($r,'noindex,follow'); ?>><?php esc_html_e('Noindex, follow', 'lean-bunker-seo'); ?></option><option value="noindex,nofollow" <?php selected($r,'noindex,nofollow'); ?>><?php esc_html_e('Noindex, nofollow', 'lean-bunker-seo'); ?></option><option value="index,nofollow" <?php selected($r,'index,nofollow'); ?>><?php esc_html_e('Index, nofollow', 'lean-bunker-seo'); ?></option></select></p>
            <?php wp_nonce_field('lb_seo_save_meta', '_lb_seo_nonce'); ?>
            <button type="button" id="lb-generate-seo" class="button button-secondary"><?php esc_html_e('Genera meta tag', 'lean-bunker-seo'); ?></button> <span id="lb-seo-status" style="margin-left:10px; color:#2271b1;"></span>
            <script>
            jQuery(document).ready(function($){
                $('#lb-generate-seo').on('click',function(){
                    $('#lb-seo-status').text('<?php echo esc_js(__('Generazione…', 'lean-bunker-seo')); ?>');
                    $.post(ajaxurl,{action:'lb_seo_generate',post_id:<?php echo (int)$post->ID; ?>,nonce:'<?php echo wp_create_nonce("lb_seo_generate"); ?>'},function(r){
                        if(r.success){
                            $('input[name="_lb_seo_title"]').val(r.data.title);
                            $('textarea[name="_lb_seo_desc"]').val(r.data.desc);
                            $('#lb-seo-status').text('✅ <?php echo esc_js(__('Generato!', 'lean-bunker-seo')); ?>');
                        } else {
                            $('#lb-seo-status').text('❌ <?php echo esc_js(__('Errore', 'lean-bunker-seo')); ?>');
                        }
                    });
                });
            });
            </script>
        </div><?php
    }
    public function taxonomy_metabox_html($term, $taxonomy) {
        $t = get_term_meta($term->term_id, '_lb_seo_title', true); $d = get_term_meta($term->term_id, '_lb_seo_desc', true); $r = get_term_meta($term->term_id, '_lb_seo_robots', true);
        ?><tr class="form-field"><th scope="row"><label><?php esc_html_e('Lean Bunker SEO', 'lean-bunker-seo'); ?></label></th><td><div style="background:#f8f9f9; padding:15px; border-left:4px solid #2271b1; margin-top:10px;">
            <p><strong><?php esc_html_e('Meta Title', 'lean-bunker-seo'); ?></strong><br><input type="text" name="_lb_seo_title" value="<?php echo esc_attr($t); ?>" style="width:100%;" maxlength="60"></p>
            <p><strong><?php esc_html_e('Meta Description', 'lean-bunker-seo'); ?></strong><br><textarea name="_lb_seo_desc" rows="3" style="width:100%;" maxlength="155"><?php echo esc_textarea($d); ?></textarea></p>
            <p><strong><?php esc_html_e('Meta Robots', 'lean-bunker-seo'); ?></strong><br><select name="_lb_seo_robots" style="width:100%;"><option value=""><?php esc_html_e('Default', 'lean-bunker-seo'); ?></option><option value="noindex,follow" <?php selected($r,'noindex,follow'); ?>><?php esc_html_e('Noindex, follow', 'lean-bunker-seo'); ?></option><option value="noindex,nofollow" <?php selected($r,'noindex,nofollow'); ?>><?php esc_html_e('Noindex, nofollow', 'lean-bunker-seo'); ?></option></select></p>
            <?php wp_nonce_field('lb_seo_save_taxonomy', '_lb_seo_nonce_taxonomy'); ?></div></td></tr><?php
    }
    
    public function save_meta($post_id, $post) { 
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE || !current_user_can('edit_post', $post_id) || $post->post_type === 'revision' || !isset($_POST['_lb_seo_nonce']) || !wp_verify_nonce($_POST['_lb_seo_nonce'], 'lb_seo_save_meta')) return; 
        if (isset($_POST['_lb_seo_title'])) update_post_meta($post_id, '_lb_seo_title', sanitize_text_field($_POST['_lb_seo_title'])); 
        if (isset($_POST['_lb_seo_desc'])) update_post_meta($post_id, '_lb_seo_desc', sanitize_textarea_field($_POST['_lb_seo_desc'])); 
        if (isset($_POST['_lb_seo_robots'])) { 
            $v = $_POST['_lb_seo_robots']; 
            update_post_meta($post_id, '_lb_seo_robots', in_array($v, ['','noindex,follow','noindex,nofollow','index,nofollow']) ? sanitize_text_field($v) : ''); 
        } 
    }
    public function save_taxonomy_meta($term_id) { 
        if (!isset($_POST['_lb_seo_nonce_taxonomy']) || !wp_verify_nonce($_POST['_lb_seo_nonce_taxonomy'], 'lb_seo_save_taxonomy')) return; 
        if (isset($_POST['_lb_seo_title'])) update_term_meta($term_id, '_lb_seo_title', sanitize_text_field($_POST['_lb_seo_title'])); 
        if (isset($_POST['_lb_seo_desc'])) update_term_meta($term_id, '_lb_seo_desc', sanitize_textarea_field($_POST['_lb_seo_desc'])); 
        if (isset($_POST['_lb_seo_robots'])) { 
            $v = $_POST['_lb_seo_robots']; 
            update_term_meta($term_id, '_lb_seo_robots', in_array($v, ['','noindex,follow','noindex,nofollow']) ? sanitize_text_field($v) : ''); 
        } 
    }
    public function auto_generate_on_publish($new, $old, $post) { if ($new === 'publish' && $old !== 'publish' && !wp_is_post_revision($post)) { if (!get_post_meta($post->ID, '_lb_seo_title', true) || !get_post_meta($post->ID, '_lb_seo_desc', true)) $this->generate_for_post($post->ID); } }
    
    private function generate_for_post($id) {
        $post = get_post($id); if (!$post) return ['title'=>'','desc'=>''];
        $ht = get_post_meta($id, '_lb_seo_title', true); $hd = get_post_meta($id, '_lb_seo_desc', true);
        if ($ht && $hd) return ['title'=>$ht, 'desc'=>$hd];
        if (!$ht) {
            $tpl = get_option($this->option_prefix . 'title_template', '{title} - {sitename}');
            $pt = wp_strip_all_tags($post->post_title); $sn = get_bloginfo('name'); $sep = apply_filters('document_title_separator', '-'); $cat = '';
            if (get_post_type($id) === 'post' && ($cats = get_the_category($id))) $cat = $cats[0]->name;
            $t = str_replace(['{title}','{sitename}','{category}','{sep}'], [$pt,$sn,$cat,$sep], $tpl);
            if (strpos($t,'{')!==false) $t = "{$pt} {$sep} {$sn}";
            $t = trim($t); if (mb_strlen($t)>60) $t = mb_substr($t,0,57).'...';
            update_post_meta($id, '_lb_seo_title', sanitize_text_field($t));
        } else $t = $ht;
        if (!$hd) {
            $d = $post->post_excerpt ?: wp_trim_words(wp_strip_all_tags($post->post_content), 28, '...');
            if (!$d) $d = wp_trim_words(wp_strip_all_tags($post->post_title), 15, '...');
            if (mb_strlen($d)>155) $d = mb_substr($d,0,152).'...';
            update_post_meta($id, '_lb_seo_desc', sanitize_textarea_field($d));
        } else $d = $hd;
        return ['title'=>$t, 'desc'=>$d];
    }
    
    private function generate_for_taxonomy($id, $tax) { $term = get_term($id, $tax); if (!$term || is_wp_error($term)) return; $ht = get_term_meta($id, '_lb_seo_title', true); $hd = get_term_meta($id, '_lb_seo_desc', true); if (!$ht) { $t = $term->name . ' - ' . get_bloginfo('name'); if (mb_strlen($t)>60) $t = mb_substr($t,0,57).'...'; update_term_meta($id, '_lb_seo_title', sanitize_text_field($t)); } if (!$hd) { $d = !empty($term->description) ? wp_strip_all_tags($term->description) : sprintf(__('Tutti gli articoli in %s', 'lean-bunker-seo'), $term->name); if (mb_strlen($d)>155) $d = mb_substr($d,0,152).'...'; update_term_meta($id, '_lb_seo_desc', sanitize_textarea_field($d)); } }
    
    public function ajax_generate() { check_ajax_referer('lb_seo_generate', 'nonce'); if (!current_user_can('edit_post', $_POST['post_id'] ?? 0)) wp_die(); wp_send_json_success($this->generate_for_post(intval($_POST['post_id']))); }
    
    public function ajax_get_missing_posts() { check_ajax_referer('lb_seo_bulk', 'nonce'); if (!current_user_can('manage_options')) { wp_send_json_error(['message' => __('Unauthorized', 'lean-bunker-seo')], 403); wp_die(); } if (get_transient('lb_seo_bulk_lock')) wp_send_json_error(['message' => __('Bulk in corso. Attendi...', 'lean-bunker-seo')]); set_transient('lb_seo_bulk_lock', 1, 120); $pts = get_post_types(['public'=>true], 'names'); unset($pts['attachment']); $q = new WP_Query(['post_type'=>array_values($pts), 'post_status'=>'publish', 'posts_per_page'=>10, 'fields'=>'ids', 'no_found_rows'=>true, 'cache_results'=>false, 'meta_query'=>['relation'=>'OR', ['key'=>'_lb_seo_title','value'=>'','compare'=>'NOT EXISTS'], ['key'=>'_lb_seo_title','value'=>'','compare'=>'='], ['key'=>'_lb_seo_desc','value'=>'','compare'=>'NOT EXISTS'], ['key'=>'_lb_seo_desc','value'=>'','compare'=>'=']]]); if (empty($q->posts)) delete_transient('lb_seo_bulk_lock'); wp_send_json_success(array_map('intval', $q->posts)); }
    public function ajax_generate_single() { check_ajax_referer('lb_seo_bulk', 'nonce'); if (!current_user_can('manage_options')) { wp_send_json_error(['message' => __('Unauthorized', 'lean-bunker-seo')], 403); wp_die(); } $id = intval($_POST['post_id'] ?? 0); if (!$id) { delete_transient('lb_seo_bulk_lock'); wp_send_json_error('ID mancante'); } $this->generate_for_post($id); wp_cache_delete($id, 'posts'); wp_reset_postdata(); wp_send_json_success(); }
    public function ajax_get_missing_taxonomies() { check_ajax_referer('lb_seo_bulk', 'nonce'); if (!current_user_can('manage_options')) { wp_send_json_error(['message' => __('Unauthorized', 'lean-bunker-seo')], 403); wp_die(); } $miss = []; foreach (get_taxonomies(['public'=>true], 'names') as $tax) { $terms = get_terms(['taxonomy'=>$tax, 'hide_empty'=>false, 'number'=>10-count($miss), 'cache_results'=>false, 'meta_query'=>['relation'=>'OR', ['key'=>'_lb_seo_title','value'=>'','compare'=>'NOT EXISTS'], ['key'=>'_lb_seo_title','value'=>'','compare'=>'='], ['key'=>'_lb_seo_desc','value'=>'','compare'=>'NOT EXISTS'], ['key'=>'_lb_seo_desc','value'=>'','compare'=>'=']]]); if (!empty($terms) && !is_wp_error($terms)) foreach ($terms as $t) { $miss[] = ['id'=>$t->term_id, 'taxonomy'=>$tax, 'name'=>$t->name]; if (count($miss)>=10) break 2; } } wp_send_json_success($miss); }
    public function ajax_generate_taxonomy_single() { check_ajax_referer('lb_seo_bulk', 'nonce'); if (!current_user_can('manage_options')) { wp_send_json_error(['message' => __('Unauthorized', 'lean-bunker-seo')], 403); wp_die(); } $id = intval($_POST['term_id'] ?? 0); $tax = sanitize_text_field($_POST['taxonomy'] ?? ''); if (!$id || !$tax) wp_send_json_error('Dati mancanti'); $this->generate_for_taxonomy($id, $tax); wp_send_json_success(); }
    public function ajax_manual_ping() { check_admin_referer('lb_seo_manual_ping', 'nonce'); if (!current_user_can('manage_options')) { wp_send_json_error(['message' => __('Unauthorized', 'lean-bunker-seo')], 403); wp_die(); } $u = home_url('/wp-sitemap.xml'); $r = wp_remote_head($u, ['timeout'=>5, 'sslverify'=>true]); if (is_wp_error($r) || wp_remote_retrieve_response_code($r)!==200) wp_send_json_error(['message'=>__('Sitemap non disponibile', 'lean-bunker-seo')]); $this->ping_search_engines(); wp_send_json_success(['message'=>__('Ping inviati!', 'lean-bunker-seo')]); }
    public function shortcode_seo_desc() { if (!is_singular()) return ''; $id = get_queried_object_id(); if (!$id) return ''; $d = get_post_meta($id, '_lb_seo_desc', true); if (!$d) { $p = get_post($id); $d = $p->post_excerpt ?: wp_trim_words($p->post_content, 25, '...'); } return '<div class="lb-seo-preview" style="font-size:0.9em; color:#555; padding:8px; background:#f9f9f9; border-left:3px solid #ccc;">' . esc_html($d) . '</div>'; }

    public function schedule_pinger() { if (!wp_next_scheduled('lean_bunker_sitemap_ping')) add_action('lean_bunker_sitemap_ping', [$this, 'ping_search_engines']); }
    public function reschedule_pinger() { if (function_exists('wp_clear_scheduled_hook')) wp_clear_scheduled_hook('lean_bunker_sitemap_ping'); $int = get_option($this->option_prefix . 'sitemap_ping_interval'); if ($int && in_array($int, ['hourly','twicedaily','daily','weekly','monthly'])) wp_schedule_event(time(), $int, 'lean_bunker_sitemap_ping'); }
    private function is_sitemap_accessible() { $r = wp_remote_head(home_url('/wp-sitemap.xml'), ['timeout'=>3, 'sslverify'=>true]); return !is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200; }
    public function ping_search_engines() { if (!$this->is_sitemap_accessible()) { $this->log_ping_error('Sitemap non accessibile'); return; } $url = home_url('/wp-sitemap.xml'); $enc = urlencode($url); $eps = ['google'=>self::PING_ENDPOINTS['google'].$enc, 'bing'=>self::PING_ENDPOINTS['bing'].$enc, 'yandex'=>self::PING_ENDPOINTS['yandex'].$enc]; $bt = get_option($this->option_prefix . 'baidu_token') ?: (defined('LEAN_BUNKER_BAIDU_TOKEN') ? LEAN_BUNKER_BAIDU_TOKEN : ''); if ($bt) $eps['baidu'] = "https://ziyuan.baidu.com/urls?site=" . urlencode(home_url()) . "&token=" . urlencode($bt); $res = []; $stats = get_option('lbs_ping_stats', []); foreach ($eps as $eng => $ep) { $r = wp_remote_get($ep, ['timeout'=>8, 'blocking'=>true, 'sslverify'=>true, 'user-agent'=>'LeanBunkerSEO/2.5.0']); if (is_wp_error($r)) $res[$eng] = ['status'=>'error', 'message'=>$r->get_error_message(), 'http_code'=>null]; else { $c = wp_remote_retrieve_response_code($r); $b = wp_remote_retrieve_body($r); $res[$eng] = ['status'=>$c===200?'success':'error', 'message'=>$c===200?__('Successo', 'lean-bunker-seo'):__('HTTP ', 'lean-bunker-seo').$c, 'http_code'=>$c, 'response'=>substr($b,0,200)]; if (!isset($stats[$eng])) $stats[$eng]=0; $stats[$eng]++; } } update_option('lbs_last_ping_log', ['timestamp'=>time(), 'sitemap_url'=>$url, 'results'=>$res, 'next_scheduled'=>wp_next_scheduled('lean_bunker_sitemap_ping')]); update_option('lbs_ping_stats', $stats); set_transient('lbs_sitemap_last_ping', time(), DAY_IN_SECONDS); }
    private function log_ping_error($msg) { $l = get_option('lbs_ping_error_log', []); $l[] = ['timestamp'=>time(), 'message'=>$msg]; update_option('lbs_ping_error_log', array_slice($l, -20)); }
    public function add_sitemap_to_robots($output, $public) { if ($public && strpos($output, 'Sitemap:') === false && function_exists('home_url')) $output .= "\nSitemap: " . home_url('/wp-sitemap.xml') . "\n"; return $output; }
}

// Istanza globale
$lean_bunker_seo = new Lean_Bunker_SEO();

/**
 * ✅ ATTIVAZIONE PROCEDURALE PURA (v2.5.0)
 * Fuori dalla classe, zero dipendenze OOP, safe-check su tutte le funzioni WP.
 */
function lean_bunker_seo_activate() {
    $prefix = 'lb_seo_';
    
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('lean_bunker_sitemap_ping');
        wp_clear_scheduled_hook('lean_bunker_ai_ping');
    }
    
    $pi_int = get_option($prefix . 'sitemap_ping_interval');
    if ($pi_int && in_array($pi_int, ['hourly','twicedaily','daily','weekly','monthly']) && function_exists('wp_schedule_event')) {
        wp_schedule_event(time(), $pi_int, 'lean_bunker_sitemap_ping');
    }
    
    $ai_int = get_option($prefix . 'ai_ping_interval');
    if ($ai_int && in_array($ai_int, ['hourly','twicedaily','daily','weekly']) && function_exists('wp_schedule_event')) {
        wp_schedule_event(time(), $ai_int, 'lean_bunker_ai_ping');
    }
    
    if (get_option($prefix . 'enable_llms_txt', '1') === '1') {
        update_option('lb_seo_pending_rewrite', true);
    }
    
    if (function_exists('delete_transient')) {
        delete_transient('lb_seo_bulk_lock');
    }
}
register_activation_hook(__FILE__, 'lean_bunker_seo_activate');

function lean_bunker_seo_deactivate() {
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('lean_bunker_sitemap_ping');
        wp_clear_scheduled_hook('lean_bunker_ai_ping');
    }
    if (function_exists('delete_transient')) {
        delete_transient('lb_seo_bulk_lock');
    }
}
register_deactivation_hook(__FILE__, 'lean_bunker_seo_deactivate');