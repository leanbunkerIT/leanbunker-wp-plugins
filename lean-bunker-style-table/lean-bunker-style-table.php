<?php
/**
 * Plugin Name: Uni.today Responsive Tables
 * Plugin URI: https://uni.today
 * Description: Trasforma le tabelle standard di WordPress in tabelle moderne, belle e responsive per il sito uni.today.
 * Version: 1.0.0
 * Author: Uni.today Dev
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * 1. Carica gli stili CSS nel frontend
 */
function uni_table_enqueue_styles() {
    $css = "
    /* Contenitore per lo scroll orizzontale su mobile */
    .uni-table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin-bottom: 2rem;
        border-radius: 8px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        border: 1px solid #e5e7eb;
        background: #fff;
    }

    /* Stile della tabella */
    .uni-table-wrapper table {
        width: 100%;
        border-collapse: collapse;
        min-width: 600px; /* Forza lo scroll su schermi piccoli */
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
        font-size: 15px;
        color: #374151;
    }

    /* Intestazioni */
    .uni-table-wrapper thead tr {
        background-color: #f3f4f6; /* Grigio chiaro moderno */
        text-align: left;
    }

    .uni-table-wrapper th {
        padding: 12px 16px;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.05em;
        color: #4b5563;
        border-bottom: 2px solid #e5e7eb;
    }

    /* Celle */
    .uni-table-wrapper td {
        padding: 12px 16px;
        border-bottom: 1px solid #e5e7eb;
        line-height: 1.5;
    }

    /* Ultima riga senza bordo */
    .uni-table-wrapper tbody tr:last-of-type td {
        border-bottom: none;
    }

    /* Effetto Zebra (righe alternate) */
    .uni-table-wrapper tbody tr:nth-of-type(even) {
        background-color: #f9fafb;
    }

    /* Effetto Hover */
    .uni-table-wrapper tbody tr:hover {
        background-color: #eff6ff; /* Un azzurro molto tenue */
        transition: background-color 0.2s ease;
    }

    /* Stile specifico per mobile */
    @media (max-width: 768px) {
        .uni-table-wrapper {
            box-shadow: none;
            border: none;
            background: transparent;
        }
        .uni-table-wrapper table {
            background: #fff;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }
    }
    ";
    
    // Iniettiamo il CSS direttamente nell'head per semplicità
    echo '<style type="text/css">' . $css . '</style>';
}
add_action('wp_head', 'uni_table_enqueue_styles');

/**
 * 2. Funzione per avvolgere automaticamente le tabelle nel contenuto
 * Questo rende responsive anche le tabelle create con i blocchi standard di WP
 */
function uni_table_make_responsive($content) {
    // Cerca i tag <table> e li avvolge nel div .uni-table-wrapper
    // Usiamo una regex semplice ma efficace
    $content = preg_replace_callback(
        '/<table([^>]*)>(.*?)<\/table>/is',
        function($matches) {
            return '<div class="uni-table-wrapper"><table' . $matches[1] . '>' . $matches[2] . '</table></div>';
        },
        $content
    );
    return $content;
}
add_filter('the_content', 'uni_table_make_responsive');

/**
 * 3. Shortcode [uni_table] per uso manuale
 * Uso: [uni_table]<tr><td>Dato</td></tr>[/uni_table]
 */
function uni_table_shortcode($atts, $content = null) {
    // Se l'utente usa lo shortcode, assumiamo che stia incollando HTML grezzo
    // Quindi non serve il filtro 'the_content' sopra, gestiamo qui.
    return '<div class="uni-table-wrapper"><table class="uni-responsive-table">' . do_shortcode($content) . '</table></div>';
}
add_shortcode('uni_table', 'uni_table_shortcode');