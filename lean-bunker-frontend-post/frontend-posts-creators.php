<?php
/**
 * Plugin Name: Frontend Post Creator & Manager
 * Description: Permette agli utenti di creare, elencare e modificare articoli dal frontend.
 * Version: 2.0
 * Author: Tuo Nome
 */

if (!defined('ABSPATH')) exit;

// ===================================================================
// SHORTCODE 1: [frontend_post_form] - Creazione e Modifica
// ===================================================================
function fpc_render_frontend_form() {
    // Controllo permessi di scrittura
    if (!current_user_can('edit_posts')) {
        return '<p class="fpc-message error">Non hai i permessi per creare articoli.</p>';
    }

    $message = '';
    $message_type = '';
    $edit_post_id = isset($_GET['fpc_edit']) ? intval($_GET['fpc_edit']) : 0;
    $edit_post = null;

    // Se siamo in modalità modifica, recuperiamo i dati del post
    if ($edit_post_id > 0) {
        $edit_post = get_post($edit_post_id);
        // Sicurezza: l'utente può modificare solo i propri post
        if (!$edit_post || $edit_post->post_author != get_current_user_id()) {
            $message = 'Articolo non trovato o non autorizzato.';
            $message_type = 'error';
            $edit_post = null;
            $edit_post_id = 0;
        }
    }

    // Gestione Invio Form (Create o Update)
    if (isset($_POST['fpc_submit_post']) && isset($_POST['fpc_nonce'])) {
        if (!wp_verify_nonce($_POST['fpc_nonce'], 'fpc_create_post_action')) {
            $message = 'Errore di sicurezza.';
            $message_type = 'error';
        } else {
            $title       = sanitize_text_field($_POST['post_title']);
            $content     = wp_kses_post($_POST['post_content']);
            $category_id = intval($_POST['post_category']);
            $tags_input  = sanitize_text_field($_POST['post_tags']);
            $post_id     = intval($_POST['post_id']); // Se esiste, è un update
            
            // Gestione Immagine
            $thumbnail_id = 0;
            if (current_user_can('upload_files') && !empty($_FILES['post_thumbnail']['name'])) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                $attachment_id = media_handle_upload('post_thumbnail', 0);
                if (!is_wp_error($attachment_id)) {
                    $thumbnail_id = $attachment_id;
                }
            }

            $post_data = array(
                'ID'            => $post_id, // Se 0, WP crea nuovo post
                'post_title'    => $title,
                'post_content'  => $content,
                'post_author'   => get_current_user_id(),
                'post_category' => array($category_id),
                'tags_input'    => $tags_input,
            );

            // Se sto modificando un post esistente, mantengo lo stato, altrimenti 'pending'
            if ($post_id > 0) {
                $existing_post = get_post($post_id);
                $post_data['post_status'] = $existing_post->post_status;
            } else {
                $post_data['post_status'] = 'pending';
            }

            $result_id = wp_insert_post($post_data);

            if (!is_wp_error($result_id)) {
                if ($thumbnail_id) {
                    set_post_thumbnail($result_id, $thumbnail_id);
                }
                $message = $post_id > 0 ? 'Articolo aggiornato!' : 'Articolo creato!';
                $message_type = 'success';
                // Reset edit mode dopo salvataggio
                $edit_post_id = 0; 
                $edit_post = null;
            } else {
                $message = 'Errore nel salvataggio.';
                $message_type = 'error';
            }
        }
    }

    // Pre-popola i dati se in modifica
    $prefill_title   = $edit_post ? $edit_post->post_title : '';
    $prefill_content = $edit_post ? $edit_post->post_content : '';
    $prefill_cat     = $edit_post ? get_the_category($edit_post->ID)[0]->term_id : '';
    $prefill_tags    = $edit_post ? implode(', ', wp_get_post_tags($edit_post->ID, array('fields' => 'names'))) : '';
    $prefill_thumb   = $edit_post ? get_the_post_thumbnail_url($edit_post->ID) : '';

    ob_start();
    ?>
    <style>
        .fpc-form-container { max-width: 800px; margin: 20px auto; padding: 20px; border: 1px solid #ddd; background: #fff; border-radius: 5px; }
        .fpc-form-group { margin-bottom: 15px; }
        .fpc-form-group label { display: block; font-weight: bold; margin-bottom: 5px; }
        .fpc-form-group input[type="text"], .fpc-form-group select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .fpc-submit-btn { background: #0073aa; color: #fff; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .fpc-submit-btn:hover { background: #005177; }
        .fpc-message { padding: 10px; margin-bottom: 15px; border-radius: 4px; }
        .fpc-message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .fpc-message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .wp-editor-wrap { border: 1px solid #ccc; border-radius: 4px; overflow: hidden; }
        .fpc-current-thumb { margin-top: 10px; max-width: 200px; display: block; }
        .fpc-cancel-link { display: inline-block; margin-left: 10px; color: #666; text-decoration: none; }
    </style>

    <div class="fpc-form-container">
        <h2><?php echo $edit_post_id > 0 ? 'Modifica Articolo' : 'Crea Nuovo Articolo'; ?></h2>

        <?php if ($message): ?>
            <div class="fpc-message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('fpc_create_post_action', 'fpc_nonce'); ?>
            <input type="hidden" name="post_id" value="<?php echo $edit_post_id; ?>">

            <div class="fpc-form-group">
                <label for="post_title">Titolo</label>
                <input type="text" name="post_title" value="<?php echo esc_attr($prefill_title); ?>" required>
            </div>

            <div class="fpc-form-group">
                <label for="post_content">Contenuto</label>
                <?php 
                wp_editor($prefill_content, 'post_content', array(
                    'media_buttons' => current_user_can('upload_files'),
                    'textarea_name' => 'post_content',
                    'textarea_rows' => 10,
                )); 
                ?>
            </div>

            <?php if (current_user_can('upload_files')): ?>
            <div class="fpc-form-group">
                <label for="post_thumbnail">Immagine in Evidenza</label>
                <input type="file" name="post_thumbnail" accept="image/*">
                <?php if ($prefill_thumb): ?>
                    <img src="<?php echo esc_url($prefill_thumb); ?>" class="fpc-current-thumb" alt="Attuale">
                    <small>Carica una nuova immagine per sostituirla.</small>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="fpc-form-group">
                <label for="post_category">Categoria</label>
                <?php
                wp_dropdown_categories(array(
                    'show_option_none' => 'Seleziona categoria',
                    'name'             => 'post_category',
                    'selected'         => $prefill_cat,
                    'hide_empty'       => 0,
                ));
                ?>
            </div>

            <div class="fpc-form-group">
                <label for="post_tags">Tag (separati da virgola)</label>
                <input type="text" name="post_tags" value="<?php echo esc_attr($prefill_tags); ?>">
            </div>

            <div class="fpc-form-group">
                <input type="submit" name="fpc_submit_post" class="fpc-submit-btn" value="<?php echo $edit_post_id > 0 ? 'Aggiorna Articolo' : 'Invia Articolo'; ?>">
                <?php if ($edit_post_id > 0): ?>
                    <a href="<?php echo remove_query_arg('fpc_edit'); ?>" class="fpc-cancel-link">Annulla</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('frontend_post_form', 'fpc_render_frontend_form');

// ===================================================================
// SHORTCODE 2: [frontend_post_list] - Lista e Gestione
// ===================================================================
function fpc_render_frontend_list() {
    if (!current_user_can('edit_posts')) {
        return '<p class="fpc-message error">Accesso negato.</p>';
    }

    $message = '';
    
    // Gestione Eliminazione
    if (isset($_GET['fpc_delete']) && isset($_GET['nonce'])) {
        $delete_id = intval($_GET['fpc_delete']);
        if (wp_verify_nonce($_GET['nonce'], 'fpc_delete_post_' . $delete_id)) {
            $post = get_post($delete_id);
            if ($post && $post->post_author == get_current_user_id()) {
                wp_trash_post($delete_id);
                $message = 'Articolo spostato nel cestino.';
            }
        }
    }

    // Query Post Utente
    $args = array(
        'author'         => get_current_user_id(),
        'post_type'      => 'post',
        'post_status'    => array('publish', 'pending', 'draft', 'future'),
        'posts_per_page' => 10,
        'orderby'        => 'date',
        'order'          => 'DESC',
    );
    $query = new WP_Query($args);

    ob_start();
    ?>
    <style>
        .fpc-list-container { max-width: 1000px; margin: 20px auto; overflow-x: auto; }
        .fpc-table { width: 100%; border-collapse: collapse; background: #fff; }
        .fpc-table th, .fpc-table td { padding: 12px; border: 1px solid #ddd; text-align: left; }
        .fpc-table th { background: #f9f9f9; font-weight: bold; }
        .fpc-status { padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; }
        .status-publish { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-draft { background: #e2e3e5; color: #383d41; }
        .fpc-actions a { margin-right: 10px; text-decoration: none; }
        .fpc-actions .edit { color: #0073aa; }
        .fpc-actions .delete { color: #dc3545; }
        .fpc-message { padding: 10px; margin-bottom: 15px; background: #d4edda; color: #155724; border-radius: 4px; }
        .fpc-pagination { margin-top: 20px; text-align: center; }
    </style>

    <div class="fpc-list-container">
        <h2>I Miei Articoli</h2>
        
        <?php if ($message): ?>
            <div class="fpc-message"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($query->have_posts()): ?>
            <table class="fpc-table">
                <thead>
                    <tr>
                        <th>Titolo</th>
                        <th>Categoria</th>
                        <th>Stato</th>
                        <th>Data</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($query->have_posts()): $query->the_post(); ?>
                    <tr>
                        <td><?php the_title(); ?></td>
                        <td><?php $cats = get_the_category(); echo $cats ? $cats[0]->name : '-'; ?></td>
                        <td>
                            <span class="fpc-status status-<?php echo get_post_status(); ?>">
                                <?php echo get_post_status_object(get_post_status())->label; ?>
                            </span>
                        </td>
                        <td><?php echo get_the_date('d/m/Y'); ?></td>
                        <td class="fpc-actions">
                            <a href="<?php echo add_query_arg('fpc_edit', get_the_ID()); ?>" class="edit">Modifica</a>
                            <a href="<?php echo wp_nonce_url(add_query_arg('fpc_delete', get_the_ID()), 'fpc_delete_post_' . get_the_ID()); ?>" 
                               class="delete" 
                               onclick="return confirm('Sei sicuro di voler eliminare questo articolo?');">Elimina</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Nessun articolo trovato.</p>
        <?php endif; ?>
        wp_reset_postdata();
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('frontend_post_list', 'fpc_render_frontend_list');