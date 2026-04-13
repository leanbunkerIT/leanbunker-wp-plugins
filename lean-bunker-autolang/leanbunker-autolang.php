<?php
/**
 * Plugin Name: Lean Bunker AutoTranslate
 * Plugin URI: https://italfaber.com
 * Description: Traduzione Google Translate con SEO ottimizzato: hreflang, sitemap, schema.org, canonical self-referential. WordPress.com Agency compatible.
 * Version: 1.3.1
 * Author: Italfaber
 * Author URI: https://italfaber.com
 * License: GPL-3.0+
 * Text Domain: lean-bunker-autotranslate
 */

if (!defined('ABSPATH')) exit;

define('LB_AT_VERSION', '1.3.1');
define('LB_AT_LANGS', 'it,en,de,fr,es,pt,ru,ja,zh-CN,ar,pl,nl,sv,tr');
define('LB_AT_COOKIE', 'lb_autotranslate_lang');
define('LB_AT_PARAM', 'lang');

// === HOOK PRINCIPALI ===
add_action('init', 'lb_at_init');
add_action('wp_head', 'lb_at_seo_tags', 1);
add_action('wp_footer', 'lb_autotranslate_inject', 99999);
add_filter('language_attributes', 'lb_at_language_attributes');

// === SITEMAP NATIVA WP (5.5+) - Implementazione sicura ===
add_filter('wp_sitemaps_posts_entry', 'lb_at_sitemap_add_hreflang_safe', 10, 3);

// === INIZIALIZZAZIONE ===
function lb_at_init() {
    // Rewrite rules per URL /en/ (funziona su Agency/Business e self-hosted)
    add_rewrite_rule('^([a-z]{2})(?:-([A-Z]{2}))?/(.+)?$', 'index.php?lang=$1-$2&pagename=$3', 'top');
    add_rewrite_rule('^([a-z]{2})(?:-([A-Z]{2}))?/?$', 'index.php?lang=$1-$2', 'top');
    
    // Aggiungi var query per lang
    add_rewrite_tag('%lang%', '([a-z]{2}(?:-[A-Z]{2})?)');
    
    // Flush rewrite rules solo all'attivazione
    if (get_option('lb_at_version') !== LB_AT_VERSION) {
        flush_rewrite_rules();
        update_option('lb_at_version', LB_AT_VERSION);
    }
}

// Hook attivazione plugin
register_activation_hook(__FILE__, 'lb_at_activate');
function lb_at_activate() {
    lb_at_init();
    flush_rewrite_rules();
}

// === FUNZIONI DI SUPPORTO ===

/**
 * Ottiene la lingua corrente
 * @return string Codice lingua (es. 'it', 'en')
 */
function lb_at_get_current_lang() {
    $langs = explode(',', LB_AT_LANGS);
    $default = 'it';
    
    // Priorità: GET > Cookie > Default
    if (!empty($_GET[LB_AT_PARAM]) && in_array($_GET[LB_AT_PARAM], $langs)) {
        return sanitize_text_field($_GET[LB_AT_PARAM]);
    }
    
    // Supporto formato lungo (es. en-US → en)
    if (!empty($_GET[LB_AT_PARAM])) {
        $short = explode('-', $_GET[LB_AT_PARAM])[0];
        if (in_array($short, $langs)) return $short;
    }
    
    if (!empty($_COOKIE[LB_AT_COOKIE]) && in_array($_COOKIE[LB_AT_COOKIE], $langs)) {
        return sanitize_text_field($_COOKIE[LB_AT_COOKIE]);
    }
    
    return $default;
}

/**
 * Ottiene URL base senza parametro lang
 * @return string URL pulito
 */
function lb_at_get_base_url() {
    $protocol = is_ssl() ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return remove_query_arg(LB_AT_PARAM, $protocol . $host . $uri);
}

/**
 * Ottiene URL corrente completo (per canonical self-referential)
 * @return string URL completo con lang se presente
 */
function lb_at_get_current_url() {
    $protocol = is_ssl() ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $url = $protocol . $host . $uri;
    
    // Pulisci parametri duplicati
    $url = remove_query_arg(LB_AT_PARAM, $url);
    
    // Aggiungi lang corrente se non è italiano
    $current_lang = lb_at_get_current_lang();
    if ($current_lang !== 'it') {
        $url = add_query_arg(LB_AT_PARAM, $current_lang, $url);
    }
    
    return $url;
}

/**
 * Costruisce URL per una lingua specifica
 */
function lb_at_build_lang_url($lang, $base_url) {
    if ($lang === 'it') {
        return $base_url;
    }
    return add_query_arg(LB_AT_PARAM, $lang, $base_url);
}

// === ATTRIBUTO LANG SU <HTML> ===
function lb_at_language_attributes($output) {
    $lang = lb_at_get_current_lang();
    
    if (preg_match('/lang=["\']([^"\']+)["\']/', $output)) {
        return preg_replace('/lang=["\'][^"\']+["\']/', 'lang="' . esc_attr($lang) . '"', $output);
    }
    return $output . ' lang="' . esc_attr($lang) . '"';
}

// === SEO TAGS: Hreflang, Canonical, Meta ===
/**
 * FIX v1.3.1: Canonical self-referential per ogni lingua
 * Questo risolve l'errore "URL is not indexable"
 */
function lb_at_seo_tags() {
    $langs = explode(',', LB_AT_LANGS);
    $current_lang = lb_at_get_current_lang();
    $base_url = lb_at_get_base_url();
    $current_url = lb_at_get_current_url();
    
    // ✅ CANONICAL SELF-REFERENTIAL (FIX v1.3.1)
    // Ogni lingua ha canonical su se stessa, non sull'italiano
    echo '<link rel="canonical" href="' . esc_url($current_url) . '" />' . "\n";
    
    // Hreflang per tutte le lingue (incluso self-referential)
    foreach ($langs as $lang) {
        $lang_url = lb_at_build_lang_url($lang, $base_url);
        echo '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . esc_url($lang_url) . '" />' . "\n";
    }
    
    // x-default punta alla versione italiana
    echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($base_url) . '" />' . "\n";
    
    // Meta per rendering Googlebot
    echo '<meta name="googlebot" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1" />' . "\n";
    
    // Meta language
    echo '<meta http-equiv="Content-Language" content="' . esc_attr($current_lang) . '" />' . "\n";
    
    // NO noindex di default (permetti indicizzazione)
    // Se vuoi attivare noindex per lingue non italiane:
    // add_filter('lb_at_noindex_non_default', '__return_true');
    if (apply_filters('lb_at_noindex_non_default', false) && $current_lang !== 'it') {
        echo '<meta name="robots" content="noindex, follow" />' . "\n";
    }
}

// === SCHEMA.ORG MULTILINGUA ===
function lb_at_schema_multilingual() {
    $langs = explode(',', LB_AT_LANGS);
    $current_lang = lb_at_get_current_lang();
    $base_url = lb_at_get_base_url();
    
    $alternates = [];
    foreach ($langs as $lang) {
        $alternates[] = [
            'language' => $lang,
            'href' => lb_at_build_lang_url($lang, $base_url)
        ];
    }
    
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'inLanguage' => $current_lang,
        'availableLanguage' => $langs,
        'potentialAction' => [
            '@type' => 'TranslateAction',
            'targetLanguage' => $current_lang
        ]
    ];
    
    if (is_singular()) {
        $schema['@type'] = ['WebPage', 'Article'];
        $schema['headline'] = get_the_title();
        $schema['datePublished'] = get_the_date('c');
        $schema['dateModified'] = get_the_modified_date('c');
    }
    
    ?>
    <script type="application/ld+json">
    <?php echo json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); ?>
    </script>
    <?php
}
add_action('wp_head', 'lb_at_schema_multilingual', 99);

// === SITEMAP: Implementazione sicura per hreflang ===
/**
 * Aggiunge hreflang alla sitemap nativa WordPress
 * FIX: Controlli di sicurezza per non rompere la sitemap
 */
add_filter('wp_sitemaps_posts_entry', 'lb_at_sitemap_add_hreflang_safe', 10, 3);
function lb_at_sitemap_add_hreflang_safe($url_entry, $post_type, $page) {
    
    // SAFETY: Se non è un array valido, restituisci così com'è
    if (!is_array($url_entry) || !isset($url_entry['loc'])) {
        return $url_entry;
    }
    
    $langs = explode(',', LB_AT_LANGS);
    $base_url = $url_entry['loc'];
    
    // Inizializza alternates array se non esiste
    $url_entry['alternates'] = $url_entry['alternates'] ?? [];
    
    foreach ($langs as $lang) {
        // Salta la lingua default
        if ($lang === 'it') continue;
        
        $lang_url = add_query_arg(LB_AT_PARAM, $lang, $base_url);
        
        // Evita duplicati
        $exists = false;
        foreach ($url_entry['alternates'] as $alt) {
            if (($alt['hreflang'] ?? '') === $lang) {
                $exists = true;
                break;
            }
        }
        if ($exists) continue;
        
        $url_entry['alternates'][] = [
            'hreflang' => sanitize_text_field($lang),
            'href' => esc_url($lang_url)
        ];
    }
    
    return $url_entry;
}

// === INJECT JAVASCRIPT (con cache localStorage) ===
function lb_autotranslate_inject() {
    $langs = explode(',', LB_AT_LANGS);
    $current = lb_at_get_current_lang();
    
    $config = [
        'cookie' => LB_AT_COOKIE,
        'param' => LB_AT_PARAM,
        'langs' => $langs,
        'current' => $current,
        'home' => home_url('/'),
        'version' => LB_AT_VERSION,
        'ajax_url' => admin_url('admin-ajax.php'),
        'labels' => [
            'it' => ['IT', 'Italiano', '🇮'], 'en' => ['EN', 'English', '🇬🇧'],
            'de' => ['DE', 'Deutsch', '🇩🇪'], 'fr' => ['FR', 'Français', '🇫🇷'],
            'es' => ['ES', 'Español', '🇪🇸'], 'pt' => ['PT', 'Português', '🇵🇹'],
            'ru' => ['RU', 'Русский', '🇷'], 'ja' => ['JA', '日本語', '🇯🇵'],
            'zh-CN' => ['ZH', '中文', '🇨🇳'], 'ar' => ['AR', 'العربية', '🇸🇦'],
            'pl' => ['PL', 'Polski', '🇵'], 'nl' => ['NL', 'Nederlands', '🇳'],
            'sv' => ['SV', 'Svenska', '🇸🇪'], 'tr' => ['TR', 'Türkçe', '🇹🇷']
        ]
    ];
    ?>
    <!-- Lean Bunker AutoTranslate v<?php echo esc_html(LB_AT_VERSION); ?> -->
    <style>
        #google_translate_element,.goog-te-banner-frame,.goog-te-gadget,
        .skiptranslate,iframe.goog-te-menu-frame{display:none!important}
        body{top:0!important}
        #lb-at-switcher{position:fixed;bottom:20px;right:20px;z-index:999999;
            background:#fff;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.15);
            border:1px solid #e0e0e0;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
        #lb-at-switcher-header{background:#0073aa;color:#fff;padding:10px 14px;
            cursor:pointer;display:flex;align-items:center;justify-content:space-between;font-size:13px}
        #lb-at-switcher-header:hover{background:#006799}
        #lb-at-switcher-content{display:none;padding:10px;min-width:180px;max-height:400px;overflow-y:auto}
        #lb-at-switcher.open #lb-at-switcher-content{display:block}
        .lb-at-lang-btn{display:flex;align-items:center;gap:10px;width:100%;
            padding:8px 10px;border:none;background:transparent;text-align:left;
            cursor:pointer;border-radius:4px;font-size:12px;color:#333;transition:background .2s}
        .lb-at-lang-btn:hover{background:#f0f0f1}
        .lb-at-lang-btn.active{background:#0073aa;color:#fff;font-weight:600}
        .lb-at-flag{font-size:18px}
        .lb-at-code{font-weight:600;min-width:28px}
        .lb-at-name{opacity:.7;font-size:11px}
        #lb-at-status{position:fixed;bottom:80px;right:20px;background:#4caf50;
            color:#fff;padding:6px 12px;border-radius:4px;font-size:11px;
            z-index:999998;opacity:0;transition:opacity .3s;pointer-events:none;max-width:200px}
        #lb-at-status.visible{opacity:1}
        #lb-at-status.error{background:#f44336}
        #lb-at-reload-notice{position:fixed;top:20px;right:20px;background:#2196F3;
            color:#fff;padding:12px 16px;border-radius:6px;font-size:12px;
            z-index:999999;opacity:0;pointer-events:none;transition:opacity .3s;max-width:250px}
        #lb-at-reload-notice.visible{opacity:1}
        @media(max-width:768px){#lb-at-switcher{bottom:10px;right:10px}
            #lb-at-status{bottom:70px;right:10px}}
    </style>
    <div id="lb-at-status"></div>
    <div id="lb-at-reload-notice">🔄 Ricaricamento...</div>
    <script>
    (function(w,d){
        'use strict';
        var CFG = <?php echo json_encode($config); ?>;
        var LANG = CFG.current;
        var COOKIE = CFG.cookie;
        var PARAM = CFG.param;
        var LANGS = CFG.langs;
        var LABELS = CFG.labels;
        var _lb_lock = false;
        
        // === CACHE LOCALSTORAGE (7 giorni) ===
        function getCache(page, lang) {
            try {
                var key = 'lb_cache_' + page + '_' + lang;
                var data = localStorage.getItem(key);
                if (data) {
                    var parsed = JSON.parse(data);
                    if (Date.now() < parsed.expires) return parsed.content;
                    localStorage.removeItem(key);
                }
            } catch(e) {}
            return null;
        }
        function setCache(page, lang, content) {
            try {
                var key = 'lb_cache_' + page + '_' + lang;
                localStorage.setItem(key, JSON.stringify({
                    content: content,
                    expires: Date.now() + (7 * 24 * 60 * 60 * 1000)
                }));
            } catch(e) {}
        }
        
        function showStatus(msg, isError){
            var el = d.getElementById('lb-at-status');
            if(!el) return;
            el.textContent = msg;
            el.className = isError ? 'visible error' : 'visible';
            setTimeout(function(){ el.className = ''; }, 3000);
        }
        function showReloadNotice(){
            var el = d.getElementById('lb-at-reload-notice');
            if(el){ el.classList.add('visible'); setTimeout(function(){ el.classList.remove('visible'); }, 2000); }
        }
        function getCookie(name){
            var c = d.cookie.match('(^|;)\\s*'+name+'\\s*=\\s*([^;]+)');
            return c ? decodeURIComponent(c[2]) : null;
        }
        function setCookie(name,val,days){
            var e = new Date(); e.setTime(e.getTime()+days*864e5);
            d.cookie = name+'='+encodeURIComponent(val)+';expires='+e.toUTCString()+';path=/;samesite=lax';
        }
        function getPref(){ try{ var ls = localStorage.getItem(COOKIE); if(ls) return ls; }catch(e){} return getCookie(COOKIE); }
        function setPref(v){ try{ localStorage.setItem(COOKIE, v); }catch(e){ setCookie(COOKIE, v, 365); } }
        
        function addLangParam(url, lang){
            try{
                var u = new URL(url, w.location.origin);
                u.searchParams.delete(PARAM);
                if(lang && lang !== 'it') u.searchParams.set(PARAM, lang);
                return u.toString();
            }catch(e){
                var clean = url.replace(new RegExp('[?&]'+PARAM+'=[a-z-]+', 'g'), '');
                if(lang && lang !== 'it'){ var sep = clean.indexOf('?') !== -1 ? '&' : '?'; return clean + sep + PARAM + '=' + lang; }
                return clean;
            }
        }
        function getLangFromURL(){
            try{ var url = new URL(w.location.href); var param = url.searchParams.get(PARAM); if(param && LANGS.indexOf(param) !== -1) return param; }catch(e){}
            return null;
        }
        function forceGoogleTranslate(lang){
            var cookiename = 'googtrans';
            var cookievalue = '/it/'+lang;
            var domain = w.location.hostname;
            d.cookie = cookiename+'='+encodeURIComponent(cookievalue)+';path=/;domain='+domain;
            d.cookie = cookiename+'='+encodeURIComponent(cookievalue)+';path=/';
            var select = d.querySelector('#google_translate_element select');
            if(select && select.value !== lang){
                select.value = lang;
                if(typeof Event === 'function') {
                    select.dispatchEvent(new Event('change'));
                } else {
                    select.fireEvent('onchange');
                }
            }
        }
        function initGoogleTranslate(targetLang){
            var cached = getCache(window.location.pathname, targetLang);
            if (cached) { console.log('[LB] Cache hit:', targetLang); return; }
            
            if(d.getElementById('google-translate-script')){
                setTimeout(function(){ forceGoogleTranslate(targetLang); setTimeout(function(){ w.location.reload(); }, 800); }, 300);
                return;
            }
            var el = d.createElement('div'); el.id = 'google_translate_element'; el.style.display = 'none'; d.body.appendChild(el);
            var script = d.createElement('script'); script.id = 'google-translate-script'; script.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit'; script.async = true;
            script.onerror = function(){ showStatus('Errore Google Translate', true); };
            d.body.appendChild(script);
        }
        w.googleTranslateElementInit = function(){
            try{
                new google.translate.TranslateElement({ 
                    pageLanguage: 'it', 
                    includedLanguages: LANGS.join(','), 
                    layout: google.translate.TranslateElement.InlineLayout.SIMPLE, 
                    autoDisplay: false 
                }, 'google_translate_element');
                if(LANG && LANG !== 'it') { setTimeout(function(){ forceGoogleTranslate(LANG); }, 200); }
            }catch(e){ showStatus('Errore traduttore', true); }
        };
        function applyLanguage(lang, savePref){
            if(_lb_lock || LANGS.indexOf(lang) === -1) return false;
            _lb_lock = true;
            LANG = lang;
            if(savePref !== false) setPref(lang);
            var newUrl = addLangParam(w.location.href, lang);
            if(newUrl !== w.location.href){
                w.history.replaceState({lang: lang}, '', newUrl);
                showReloadNotice();
                if(lang !== 'it') { initGoogleTranslate(lang); } 
                else { setTimeout(function(){ w.location.reload(); }, 400); }
            }
            updateSwitcher();
            w.dispatchEvent(new CustomEvent('lbAutotranslateLangChanged', {detail: {lang: lang}}));
            setTimeout(function(){ _lb_lock = false; }, 800);
            return true;
        }
        function createSwitcher(){
            if(d.getElementById('lb-at-switcher')) return;
            var switcher = d.createElement('div'); switcher.id = 'lb-at-switcher';
            var lbl = LABELS[LANG] || [LANG.toUpperCase(), LANG, '🌐'];
            var langButtons = LANGS.map(function(l){
                var lb = LABELS[l] || [l.toUpperCase(), l, '🌐'];
                var active = l === LANG ? ' active' : '';
                return '<button class="lb-at-lang-btn'+active+'" data-lang="'+l+'"><span class="lb-at-flag">'+lb[2]+'</span><span class="lb-at-code">'+lb[0]+'</span><span class="lb-at-name">'+lb[1]+'</span></button>';
            }).join('');
            switcher.innerHTML = '<div id="lb-at-switcher-header"><span>'+lbl[2]+' <strong>'+lbl[0]+'</strong> Traduci</span><span style="font-size:10px;opacity:0.8">▼</span></div><div id="lb-at-switcher-content">'+langButtons+'</div>';
            d.body.appendChild(switcher);
            switcher.querySelector('#lb-at-switcher-header').onclick = function(e){ e.stopPropagation(); switcher.classList.toggle('open'); };
            switcher.querySelectorAll('.lb-at-lang-btn').forEach(function(btn){ 
                btn.onclick = function(e){ e.stopPropagation(); var newLang = this.dataset.lang; if(newLang !== LANG){ applyLanguage(newLang, true); switcher.classList.remove('open'); } }; 
            });
            d.addEventListener('click', function(e){ if(!switcher.contains(e.target)) switcher.classList.remove('open'); });
        }
        function updateSwitcher(){
            var header = d.querySelector('#lb-at-switcher-header');
            if(!header) return;
            var lbl = LABELS[LANG] || [LANG.toUpperCase(), LANG, '🌐'];
            header.innerHTML = '<span>'+lbl[2]+' <strong>'+lbl[0]+'</strong> Traduci</span><span style="font-size:10px;opacity:0.8">▼</span>';
            d.querySelectorAll('.lb-at-lang-btn').forEach(function(btn){ btn.classList.toggle('active', btn.dataset.lang === LANG); });
        }
        function init(){
            if(_lb_lock) return; _lb_lock = true;
            var urlLang = getLangFromURL();
            if(urlLang){ LANG = urlLang; setPref(urlLang); }
            else{ var stored = getPref(); if(stored && LANGS.indexOf(stored) !== -1) LANG = stored; }
            createSwitcher();
            if(LANG !== 'it') initGoogleTranslate(LANG);
            updateSwitcher();
            _lb_lock = false;
        }
        if(d.readyState === 'loading') d.addEventListener('DOMContentLoaded', init); else init();
        w.LBAutoTranslate = { 
            lang: function(){ return LANG; }, 
            set: function(l){ return applyLanguage(l, true); }, 
            url: function(l){ return addLangParam(w.location.href.split('#')[0], l||LANG); }, 
            reset: function(){ applyLanguage('it', true); },
            cache: { get: getCache, set: setCache }
        };
    })(window, document);
    </script>
    <!-- /Lean Bunker AutoTranslate -->
    <?php
}

// === SHORTCODE ===
add_shortcode('lb_translate_switcher', 'lb_at_switcher_shortcode');
function lb_at_switcher_shortcode($atts){
    $langs = explode(',', LB_AT_LANGS);
    $current = lb_at_get_current_lang();
    $labels = [
        'it'=>['IT','Italiano','🇮'], 'en'=>['EN','English','🇬🇧'],
        'de'=>['DE','Deutsch','🇩🇪'], 'fr'=>['FR','Français','🇫🇷'],
        'es'=>['ES','Español','🇪🇸'], 'pt'=>['PT','Português','🇵🇹'],
        'ru'=>['RU','Русский','🇷'], 'ja'=>['JA','日本語','🇯🇵'],
        'zh-CN'=>['ZH','中文','🇨🇳'], 'ar'=>['AR','العربية','🇸🇦'],
        'pl'=>['PL','Polski','🇵'], 'nl'=>['NL','Nederlands','🇳'],
        'sv'=>['SV','Svenska','🇸🇪'], 'tr'=>['TR','Türkçe','🇹🇷']
    ];
    $base = remove_query_arg(LB_AT_PARAM, $_SERVER['REQUEST_URI'] ?? '/');
    $out = '<div style="display:flex;gap:8px;flex-wrap:wrap;padding:12px;background:#f9f9f9;border-radius:6px" role="navigation" aria-label="Selettore lingua">';
    foreach($langs as $l){
        $lbl = $labels[$l] ?? [strtoupper($l), $l, '🌐'];
        $active = $l===$current ? ' aria-current="true" style="font-weight:bold;background:#0073aa;color:#fff"' : '';
        $url = add_query_arg(LB_AT_PARAM, $l, $base);
        $out .= '<a href="'.esc_url($url).'"'.$active.' style="padding:6px 12px;border:1px solid #ddd;border-radius:4px;text-decoration:none;font-size:12px" hreflang="'.esc_attr($l).'">'.$lbl[2].' '.$lbl[0].'</a>';
    }
    $out .= '</div>';
    return $out;
}

// === FILTRI DOCUMENTATI ===
/**
 * Filter: lb_at_canonical_url
 * Modifica URL canonical
 */

/**
 * Filter: lb_at_noindex_non_default
 * Attiva noindex per lingue non italiane
 */

/**
 * Action: lb_autotranslate_lang_changed
 * JS event quando cambia lingua
 */