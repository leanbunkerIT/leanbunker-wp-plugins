<?php
/**
 * Plugin Name: WP Social Comments Clean
 * Description: Commenti compatti senza conflitti con il tema. Niente paginazione negli archivi.
 * Version: 5.0
 * Author: AI Assistant
 */

if (!defined('ABSPATH')) exit;

class WP_Social_Comments_Clean {

    private static $instance_count = 0;

    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('save_post', array($this, 'save_meta_box'));
        add_shortcode('wp_social_comments', array($this, 'render_comments'));
        add_filter('the_excerpt', array($this, 'auto_inject'), 20);
        add_action('wp_footer', array($this, 'enqueue_scripts'));
        add_action('wp_head', array($this, 'add_css'));
        
        add_action('wp_ajax_wsc_submit_comment', array($this, 'handle_ajax'));
        add_action('wp_ajax_nopriv_wsc_submit_comment', array($this, 'handle_ajax'));
    }

    public function add_meta_box() {
        add_meta_box('wsc_meta_box', '💬 Social Comments', array($this, 'meta_box_content'), 'post', 'side', 'default');
    }

    public function meta_box_content($post) {
        wp_nonce_field('wsc_save_meta', 'wsc_meta_nonce');
        $value = get_post_meta($post->ID, '_wsc_enable', true);
        ?>
        <p>
            <label style="display:flex; align-items:center; gap:10px;">
                <input type="checkbox" name="wsc_enable" value="1" <?php checked($value, 1); ?> style="width:18px; height:18px;" />
                <span style="font-weight:600;">Attiva negli archivi</span>
            </label>
        </p>
        <p style="font-size:12px; color:#666;">
            Mostra modulo commenti compatto. Nessun conflitto con il tema.
        </p>
        <?php
    }

    public function save_meta_box($post_id) {
        if (!isset($_POST['wsc_meta_nonce']) || !wp_verify_nonce($_POST['wsc_meta_nonce'], 'wsc_save_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        update_post_meta($post_id, '_wsc_enable', isset($_POST['wsc_enable']) ? 1 : 0);
    }

    public function render_comments($atts) {
        if (is_single()) return '';
        global $post;
        if (!$post || !comments_open($post->ID)) return '';

        // Incrementa contatore per ID univoci
        self::$instance_count++;
        $instance_id = 'wsc-' . $post->ID . '-' . self::$instance_count;
        $nonce = wp_create_nonce('wsc_nonce');
        
        // Prendi solo 3 commenti recenti (no paginazione)
        $comments = get_comments(array(
            'post_id' => $post->ID,
            'number'  => 3,
            'status'  => 'approve'
        ));

        ob_start();
        ?>
        <div class="wsc-wrapper" id="<?php echo $instance_id; ?>" data-post="<?php echo $post->ID; ?>" data-nonce="<?php echo $nonce; ?>">
            
            <div class="wsc-header">
                <span>💬</span>
                <span class="wsc-count"><?php echo count($comments); ?></span>
                <a href="<?php echo get_permalink($post->ID); ?>#comments" class="wsc-link">Vedi tutti</a>
            </div>

            <div class="wsc-comments">
                <?php if ($comments) : foreach ($comments as $comment) : ?>
                <div class="wsc-comment">
                    <div class="wsc-avatar"><?php echo get_avatar($comment, 28); ?></div>
                    <div class="wsc-body">
                        <div class="wsc-name"><?php echo esc_html($comment->comment_author); ?></div>
                        <div class="wsc-text"><?php echo esc_html(wp_trim_words($comment->comment_content, 10)); ?></div>
                    </div>
                </div>
                <?php endforeach; else : ?>
                <div class="wsc-empty">Nessun commento</div>
                <?php endif; ?>
            </div>

            <form class="wsc-form" onsubmit="WSC_Submit(event, '<?php echo $instance_id; ?>')">
                <input type="hidden" name="post_id" value="<?php echo $post->ID; ?>">
                <input type="hidden" name="nonce" value="<?php echo $nonce; ?>">
                <?php if (is_user_logged_in()) : $u = wp_get_current_user(); ?>
                <input type="hidden" name="author" value="<?php echo esc_attr($u->display_name); ?>">
                <input type="hidden" name="email" value="<?php echo esc_attr($u->user_email); ?>">
                <?php endif; ?>
                
                <?php if (!is_user_logged_in()) : ?>
                <div class="wsc-inputs">
                    <input type="text" name="author" placeholder="Nome" required>
                    <input type="email" name="email" placeholder="Email" required>
                </div>
                <?php endif; ?>
                
                <textarea name="comment" placeholder="Scrivi..." required></textarea>
                <button type="submit">Pubblica</button>
                <div class="wsc-msg"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function auto_inject($content) {
        if ((is_archive() || is_home()) && !is_single()) {
            global $post;
            if ($post && get_post_meta($post->ID, '_wsc_enable', true)) {
                $content .= do_shortcode('[wp_social_comments]');
            }
        }
        return $content;
    }

    public function handle_ajax() {
        check_ajax_referer('wsc_nonce', 'nonce');
        
        $post_id = intval($_POST['post_id']);
        $author = sanitize_text_field($_POST['author']);
        $email = sanitize_email($_POST['email']);
        $content = sanitize_textarea_field($_POST['comment']);

        if (empty($content) || empty($author) || empty($email)) {
            wp_send_json_error(array('message' => 'Compila tutti i campi'));
        }

        $data = array(
            'comment_post_ID' => $post_id,
            'comment_author' => $author,
            'comment_author_email' => $email,
            'comment_content' => $content,
            'comment_approved' => is_user_logged_in() ? 1 : (get_option('comment_moderation') == '1' ? 0 : 1)
        );

        $id = wp_new_comment($data);
        
        if ($id) {
            $comment = get_comment($id);
            $html = '<div class="wsc-comment wsc-new"><div class="wsc-avatar">'.get_avatar($comment, 28).'</div><div class="wsc-body"><div class="wsc-name">'.esc_html($comment->comment_author).'</div><div class="wsc-text">'.esc_html(wp_trim_words($comment->comment_content, 10)).'</div></div></div>';
            
            wp_send_json_success(array(
                'html' => $html,
                'message' => $comment->comment_approved ? 'Pubblicato!' : 'In approvazione',
                'approved' => $comment->comment_approved
            ));
        } else {
            wp_send_json_error(array('message' => 'Errore'));
        }
    }

    public function enqueue_scripts() {
        ?>
        <script>
        function WSC_Submit(e, id) {
            e.preventDefault();
            var el = document.getElementById(id);
            var form = el.querySelector('.wsc-form');
            var btn = form.querySelector('button');
            var msg = el.querySelector('.wsc-msg');
            var data = new FormData(form);
            data.append('action', 'wsc_submit_comment');

            btn.disabled = true;
            btn.textContent = '...';

            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {method: 'POST', body: data})
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    el.querySelector('.wsc-comments').insertAdjacentHTML('afterbegin', res.data.html);
                    form.querySelector('textarea').value = '';
                    msg.textContent = res.data.message;
                    msg.style.color = res.data.approved ? 'green' : 'orange';
                    var c = el.querySelector('.wsc-count');
                    if (c && res.data.approved) c.textContent = parseInt(c.textContent) + 1;
                } else {
                    msg.textContent = res.data.message;
                    msg.style.color = 'red';
                }
            })
            .catch(() => { msg.textContent = 'Errore'; msg.style.color = 'red'; })
            .finally(() => { btn.disabled = false; btn.textContent = 'Pubblica'; });
        }
        </script>
        <?php
    }

    public function add_css() {
        ?>
        <style>
        .wsc-wrapper { background:#fff; border:1px solid #e5e5e5; border-radius:8px; padding:12px; margin-top:15px; font-family:system-ui,-apple-system,sans-serif; }
        .wsc-header { display:flex; align-items:center; gap:8px; font-size:13px; color:#555; margin-bottom:10px; padding-bottom:8px; border-bottom:1px solid #eee; }
        .wsc-count { background:#f0f0f0; padding:2px 8px; border-radius:10px; font-weight:600; }
        .wsc-link { margin-left:auto; color:#0073aa; text-decoration:none; font-size:12px; }
        .wsc-link:hover { text-decoration:underline; }
        .wsc-comments { max-height:180px; overflow-y:auto; margin-bottom:12px; }
        .wsc-comment { display:flex; gap:8px; padding:8px; margin-bottom:8px; background:#f9f9f9; border-radius:6px; }
        .wsc-avatar img { width:28px; height:28px; border-radius:50%; }
        .wsc-body { flex:1; min-width:0; }
        .wsc-name { font-size:11px; font-weight:600; color:#333; margin-bottom:2px; }
        .wsc-text { font-size:12px; color:#555; line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .wsc-empty { text-align:center; color:#999; font-size:12px; padding:10px; }
        .wsc-form { border-top:1px solid #eee; padding-top:12px; }
        .wsc-inputs { display:flex; gap:8px; margin-bottom:8px; }
        .wsc-inputs input, .wsc-form textarea { width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; font-size:12px; box-sizing:border-box; }
        .wsc-form textarea { min-height:50px; resize:vertical; margin-bottom:8px; }
        .wsc-form button { background:#0073aa; color:#fff; border:none; padding:6px 14px; border-radius:6px; font-size:12px; cursor:pointer; }
        .wsc-form button:hover { background:#005a87; }
        .wsc-form button:disabled { background:#ccc; }
        .wsc-msg { font-size:11px; margin-top:6px; font-weight:600; }
        .wsc-new { animation:wscFade 0.4s ease; background:#e8f5e9; }
        @keyframes wscFade { from{opacity:0;transform:translateY(-5px);} to{opacity:1;transform:translateY(0);} }
        .wsc-comments::-webkit-scrollbar { width:5px; }
        .wsc-comments::-webkit-scrollbar-thumb { background:#ccc; border-radius:3px; }
        @media(max-width:600px) { .wsc-inputs{flex-direction:column;} .wsc-text{white-space:normal;} }
        </style>
        <?php
    }
}

new WP_Social_Comments_Clean();