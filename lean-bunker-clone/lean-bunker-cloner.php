<?php
/**
 * Plugin Name: Lean Bunker Cloner
 * Description: Clona articoli, pagine e CPT con un clic. Single file, zero JS, zero bloat.
 * Version: 0.0.1
 * Author: Riccardo Bastillo
 * License: GPL-3.0+
 */

if (!defined('ABSPATH')) exit;

// Aggiungi il link "Clona" nell'elenco admin
add_filter('post_row_actions', 'lean_bunker_cloner_row_action', 10, 2);
add_filter('page_row_actions', 'lean_bunker_cloner_row_action', 10, 2);
function lean_bunker_cloner_row_action($actions, $post) {
    if (!current_user_can('edit_post', $post->ID)) return $actions;
    if ($post->post_type === 'revision') return $actions;

    $url = wp_nonce_url(
        add_query_arg([
            'action' => 'lean_clone',
            'post' => $post->ID
        ], admin_url('admin.php')),
        'lean_clone_' . $post->ID
    );

    $actions['clone'] = '<a href="' . esc_url($url) . '" aria-label="Clona questo elemento">Clona</a>';
    return $actions;
}

// Gestione clonazione
add_action('admin_init', 'lean_bunker_cloner_handle_clone');
function lean_bunker_cloner_handle_clone() {
    if (!isset($_GET['action']) || $_GET['action'] !== 'lean_clone') return;
    if (!isset($_GET['post'])) return;

    $post_id = intval($_GET['post']);
    if (!wp_verify_nonce($_GET['_wpnonce'], 'lean_clone_' . $post_id)) {
        wp_die('Nonce non valido.');
    }

    if (!current_user_can('edit_post', $post_id)) {
        wp_die('Non autorizzato.');
    }

    $post = get_post($post_id);
    if (!$post) {
        wp_die('Post non trovato.');
    }

    // === UNA SOLA RIGA PER CLONARE TUTTO ===
    $new_id = wp_insert_post([
        'post_title'     => $post->post_title . ' (Copia)',
        'post_content'   => $post->post_content,
        'post_excerpt'   => $post->post_excerpt,
        'post_status'    => 'draft',
        'post_type'      => $post->post_type,
        'post_author'    => get_current_user_id(),
        'ping_status'    => $post->ping_status,
        'comment_status' => $post->comment_status,
        'post_parent'    => $post->post_parent,
        'menu_order'     => $post->menu_order,
        'post_password'  => $post->post_password,
        'post_name'      => '',
    ]);

    if (is_wp_error($new_id)) {
        wp_die('Errore durante la clonazione.');
    }

    // Copia metadati
    $meta = get_post_meta($post_id);
    foreach ($meta as $key => $values) {
        foreach ($values as $value) {
            add_post_meta($new_id, $key, maybe_unserialize($value));
        }
    }

    // Copia tassonomie
    $taxonomies = get_object_taxonomies($post->post_type);
    foreach ($taxonomies as $taxonomy) {
        $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
        if (!empty($terms) && !is_wp_error($terms)) {
            wp_set_object_terms($new_id, $terms, $taxonomy);
        }
    }

    // Reindirizza all'editor della copia
    wp_redirect(admin_url('post.php?post=' . $new_id . '&action=edit'));
    exit;
}