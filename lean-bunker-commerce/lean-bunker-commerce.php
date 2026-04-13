<?php
/**
 * Plugin Name: Lean Bunker Commerce
 * Description: Aree private + Configuratore prodotti + Carrello + Checkout. Tutto in un unico plugin. Zero JS, single file, multisite-ready.
 * Version: 2.0.3
 * Author: Riccardo Bastillo
 * Text Domain: lean-bunker-commerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lean_Bunker_Commerce {

    private $option_categories = 'lb_user_categories';
    private $meta_user_category = 'lb_user_category';
    private $meta_product_cart = 'lb_product_cart_enabled';
    private $meta_product_price = 'lb_product_price';
    private $meta_product_stock = 'lb_product_stock';
    private $meta_product_config = '_lean_bunker_commerce_config';
    private $meta_cart_items = 'lb_cart_items';
    private $post_type_order = 'lb_order';
    private $page_order_completed_slug = 'ordine-completato';

    public function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        add_action('init', array($this, 'register_post_types'));
        add_action('init', array($this, 'register_nav_menus'));
        add_action('init', array($this, 'create_order_completed_page'));

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'), 10, 2);
        add_action('show_user_profile', array($this, 'add_user_category_field'));
        add_action('edit_user_profile', array($this, 'add_user_category_field'));
        add_action('personal_options_update', array($this, 'save_user_category'));
        add_action('edit_user_profile_update', array($this, 'save_user_category'));

        add_shortcode('menu_utente', array($this, 'shortcode_menu_utente'));
        add_shortcode('aggiungi_al_carrello', array($this, 'shortcode_aggiungi_al_carrello'));
        add_shortcode('carrello', array($this, 'shortcode_carrello'));
        add_shortcode('miei_ordini', array($this, 'shortcode_miei_ordini'));
        add_shortcode('miei_acquisti', array($this, 'shortcode_miei_acquisti'));
        add_shortcode('checkout', array($this, 'shortcode_checkout'));
        add_shortcode('lean_calculator', array($this, 'shortcode_lean_calculator'));
        add_shortcode('ordine_completato', array($this, 'shortcode_ordine_completato'));

        add_action('template_redirect', array($this, 'handle_cart_actions'));
    }

    public function init() {
        load_plugin_textdomain('lean-bunker-commerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    // ============================================
    // CREA PAGINA ORDINE COMPLETATO
    // ============================================

    public function create_order_completed_page() {
        $page = get_page_by_path($this->page_order_completed_slug);
        
        if (!$page && !get_option('lb_order_completed_page_created')) {
            $page_id = wp_insert_post(array(
                'post_title' => __('Ordine Completato', 'lean-bunker-commerce'),
                'post_content' => '[ordine_completato]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_name' => $this->page_order_completed_slug
            ));
            
            if ($page_id) {
                update_option('lb_order_completed_page_created', true);
            }
        }
    }

    // ============================================
    // CATEGORIE UTENTI
    // ============================================

    private function get_categories() {
        $categories = get_option($this->option_categories, array());
        
        if (empty($categories)) {
            $categories = array(
                'cliente' => 'Clienti',
                'fornitore' => 'Fornitori',
                'staff' => 'Staff'
            );
            update_option($this->option_categories, $categories);
        }
        
        return $categories;
    }

    // ============================================
    // POST TYPE ORDINI
    // ============================================

    public function register_post_types() {
        register_post_type($this->post_type_order, array(
            'labels' => array(
                'name' => __('Ordini', 'lean-bunker-commerce'),
                'singular_name' => __('Ordine', 'lean-bunker-commerce'),
                'add_new' => __('Aggiungi Ordine', 'lean-bunker-commerce'),
                'add_new_item' => __('Nuovo Ordine', 'lean-bunker-commerce'),
                'edit_item' => __('Modifica Ordine', 'lean-bunker-commerce'),
                'view_item' => __('Vedi Ordine', 'lean-bunker-commerce'),
                'all_items' => __('Tutti gli Ordini', 'lean-bunker-commerce'),
                'not_found' => __('Nessun ordine trovato', 'lean-bunker-commerce'),
                'not_found_in_trash' => __('Nessun ordine nel cestino', 'lean-bunker-commerce'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'options-general.php',
            'capability_type' => 'post',
            'supports' => array('title'),
            'menu_icon' => 'dashicons-cart',
            'show_in_admin_bar' => false,
        ));
    }

    // ============================================
    // MENU DINAMICI PER CATEGORIE
    // ============================================

    public function register_nav_menus() {
        $categories = $this->get_categories();
        $locations = array();

        foreach ($categories as $slug => $name) {
            $locations['lb_user_area_' . $slug] = sprintf(__('Area Privata: %s', 'lean-bunker-commerce'), $name);
        }

        register_nav_menus($locations);
    }

    // ============================================
    // METABOX PRODOTTO
    // ============================================

    public function add_meta_boxes() {
        $post_types = get_post_types(array('public' => true), 'names');
        
        foreach ($post_types as $post_type) {
            add_meta_box(
                'lb_commerce_product',
                __('Lean Bunker - Carrello', 'lean-bunker-commerce'),
                array($this, 'render_product_metabox'),
                $post_type,
                'side',
                'high'
            );

            add_meta_box(
                'lb_commerce_configurator',
                __('Lean Bunker - Configuratore Prodotto', 'lean-bunker-commerce'),
                array($this, 'render_configurator_metabox'),
                $post_type,
                'normal',
                'default'
            );
        }

        // Metabox dettagli ordine
        add_meta_box(
            'lb_order_details',
            __('Dettagli Ordine', 'lean-bunker-commerce'),
            array($this, 'render_order_details_metabox'),
            $this->post_type_order,
            'normal',
            'high'
        );
    }

    public function render_product_metabox($post) {
        wp_nonce_field('lb_commerce_save_product', 'lb_commerce_nonce');

        $cart_enabled = get_post_meta($post->ID, $this->meta_product_cart, true);
        $price = get_post_meta($post->ID, $this->meta_product_price, true);
        $stock = get_post_meta($post->ID, $this->meta_product_stock, true);

        ?>
        <p>
            <label>
                <input type="checkbox" name="lb_cart_enabled" value="1" <?php checked($cart_enabled, '1'); ?> />
                <strong><?php _e('🛒 Aggiungi al carrello', 'lean-bunker-commerce'); ?></strong>
            </label>
        </p>

        <p>
            <label for="lb_product_price">
                <strong><?php _e('Prezzo (€)', 'lean-bunker-commerce'); ?></strong>
            </label><br/>
            <input type="number" id="lb_product_price" name="lb_product_price" 
                   value="<?php echo esc_attr($price); ?>" 
                   step="0.01" min="0" class="widefat" />
        </p>

        <p>
            <label for="lb_product_stock">
                <strong><?php _e('Quantità disponibile', 'lean-bunker-commerce'); ?></strong><br/>
                <small><?php _e('(0 = non disponibile, vuoto = illimitato)', 'lean-bunker-commerce'); ?></small>
            </label><br/>
            <input type="number" id="lb_product_stock" name="lb_product_stock" 
                   value="<?php echo esc_attr($stock); ?>" 
                   min="0" class="widefat" />
        </p>

        <hr style="margin: 15px 0;">

        <h4><?php _e('Shortcodes', 'lean-bunker-commerce'); ?></h4>
        <p><code>[aggiungi_al_carrello id="<?php echo $post->ID; ?>"]</code></p>
        <p><code>[lean_calculator id="<?php echo $post->ID; ?>"]</code></p>
        <?php
    }

    public function render_configurator_metabox($post) {
        wp_nonce_field('lb_commerce_save_configurator', 'lb_configurator_nonce');

        $config = get_post_meta($post->ID, $this->meta_product_config, true);
        $enable_form = !empty($config['enable_form']);
        $enable_cart = !empty($config['enable_cart']);
        $fields_raw = '';

        if (!empty($config['fields']) && is_array($config['fields'])) {
            foreach ($config['fields'] as $name => $field) {
                $line = $name . ' | ' . ($field['label'] ?? '') . ' | ' . ($field['type'] ?? 'text');
                if ($field['type'] === 'select' && !empty($field['options'])) {
                    $opts = [];
                    foreach ($field['options'] as $label => $value) {
                        $opts[] = $label . '|' . $value;
                    }
                    $line .= ' | ' . implode(', ', $opts);
                }
                $fields_raw .= $line . "\n";
            }
        }

        echo '<p><strong>' . __('Campi configurabili', 'lean-bunker-commerce') . '</strong></p>';
        echo '<textarea name="lb_config_fields" style="width:100%;height:150px;" placeholder="nome | etichetta | tipo | opzioni (opzionale)">' . esc_textarea($fields_raw) . '</textarea>';
        echo '<p><small>' . __('Ogni riga = un campo.<br/>Tipi ammessi: <code>select</code>, <code>number</code>.<br/>Esempio: <code>pavimento | Tipo pavimento | select | Legno|80, Ceramica|45</code>', 'lean-bunker-commerce') . '</small></p>';

        echo '<p><strong>' . __('Formula di calcolo', 'lean-bunker-commerce') . '</strong></p>';
        echo '<input type="text" name="lb_config_formula" value="' . esc_attr($config['formula'] ?? '') . '" style="width:100%;" />';
        echo '<p><small>' . __('Usa <code>{nome_campo}</code> per riferirti ai campi. Esempio: <code>{pavimento} * {mq}</code>', 'lean-bunker-commerce') . '</small></p>';

        echo '<p><label><input type="checkbox" name="lb_config_enable_form" value="1" ' . checked($enable_form, true, false) . '> ' . __('Abilita modulo richiesta dopo il calcolo', 'lean-bunker-commerce') . '</label></p>';
        echo '<p><label><input type="checkbox" name="lb_config_enable_cart" value="1" ' . checked($enable_cart, true, false) . '> ' . __('Abilita aggiunta al carrello dopo il calcolo', 'lean-bunker-commerce') . '</label></p>';

        echo '<hr style="margin:20px 0;">';
        echo '<h3>' . __('Esempio veloce', 'lean-bunker-commerce') . '</h3>';
        echo '<p><a href="' . wp_nonce_url(add_query_arg('lb_load_example', 1), 'lb_load_example') . '" class="button">' . __('Carica esempio pavimento', 'lean-bunker-commerce') . '</a></p>';
    }

    // ✅ METABOX DETTAGLI ORDINE COMPLETO
    public function render_order_details_metabox($post) {
        $user_id = get_post_meta($post->ID, 'lb_order_user_id', true);
        $user_email = get_post_meta($post->ID, 'lb_order_user_email', true);
        $items = get_post_meta($post->ID, 'lb_order_items', true);
        $total = get_post_meta($post->ID, 'lb_order_total', true);
        $status = get_post_meta($post->ID, 'lb_order_status', true);
        $payment_method = get_post_meta($post->ID, 'lb_order_payment_method', true);
        $date = get_post_meta($post->ID, 'lb_order_date', true);
        $billing = get_post_meta($post->ID, 'lb_order_billing', true);
        $shipping = get_post_meta($post->ID, 'lb_order_shipping', true);
        $notes = get_post_meta($post->ID, 'lb_order_notes', true);
        $user = $user_id ? get_userdata($user_id) : null;

        ?>
        <div style="padding: 15px; background: #f9f9f9; border-radius: 4px; margin-bottom: 20px;">
            <h3 style="margin-top: 0;"><?php _e('Informazioni Cliente', 'lean-bunker-commerce'); ?></h3>
            <?php if ($user): ?>
                <p><strong><?php _e('Utente:', 'lean-bunker-commerce'); ?></strong> 
                    <?php echo esc_html($user->display_name); ?> (ID: <?php echo esc_html($user_id); ?>)
                </p>
            <?php endif; ?>
            <p><strong><?php _e('Email:', 'lean-bunker-commerce'); ?></strong> <?php echo esc_html($user_email); ?></p>
            <p><strong><?php _e('Data ordine:', 'lean-bunker-commerce'); ?></strong> <?php echo date('d/m/Y H:i', strtotime($date)); ?></p>
            <p><strong><?php _e('Metodo pagamento:', 'lean-bunker-commerce'); ?></strong> 
                <?php echo ($payment_method === 'bank_transfer') ? __('Bonifico Bancario', 'lean-bunker-commerce') : esc_html($payment_method); ?>
            </p>
            <p><strong><?php _e('Stato:', 'lean-bunker-commerce'); ?></strong> 
                <?php if ($status === 'pending'): ?>
                    <span style="background: #fff3cd; color: #856404; padding: 4px 12px; border-radius: 12px; font-size: 0.9em;">
                        ⏳ <?php _e('In attesa', 'lean-bunker-commerce'); ?>
                    </span>
                <?php elseif ($status === 'completed'): ?>
                    <span style="background: #d4edda; color: #155724; padding: 4px 12px; border-radius: 12px; font-size: 0.9em;">
                        ✅ <?php _e('Completato', 'lean-bunker-commerce'); ?>
                    </span>
                <?php endif; ?>
            </p>
        </div>

        <div style="display: flex; gap: 20px; margin-bottom: 20px;">
            <div style="flex: 1; background: #f9f9f9; padding: 15px; border-radius: 4px;">
                <h3><?php _e('Fatturazione', 'lean-bunker-commerce'); ?></h3>
                <?php if (!empty($billing)): ?>
                    <p><strong><?php echo esc_html($billing['name']); ?></strong></p>
                    <p><?php echo esc_html($billing['address']); ?><br>
                    <?php echo esc_html($billing['city']); ?> (<?php echo esc_html($billing['province']); ?>) <?php echo esc_html($billing['postal_code']); ?><br>
                    <?php echo esc_html($billing['country']); ?></p>
                    <p><strong><?php _e('Telefono:', 'lean-bunker-commerce'); ?></strong> <?php echo esc_html($billing['phone'] ?? '-'); ?></p>
                <?php else: ?>
                    <p><?php _e('Nessun dato di fatturazione', 'lean-bunker-commerce'); ?></p>
                <?php endif; ?>
            </div>
            <div style="flex: 1; background: #f9f9f9; padding: 15px; border-radius: 4px;">
                <h3><?php _e('Spedizione', 'lean-bunker-commerce'); ?></h3>
                <?php if (!empty($shipping)): ?>
                    <?php if (!empty($shipping['same_as_billing'])): ?>
                        <p><em><?php _e('Uguale all\'indirizzo di fatturazione', 'lean-bunker-commerce'); ?></em></p>
                    <?php else: ?>
                        <p><strong><?php echo esc_html($shipping['name']); ?></strong></p>
                        <p><?php echo esc_html($shipping['address']); ?><br>
                        <?php echo esc_html($shipping['city']); ?> (<?php echo esc_html($shipping['province']); ?>) <?php echo esc_html($shipping['postal_code']); ?><br>
                        <?php echo esc_html($shipping['country']); ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p><?php _e('Nessun dato di spedizione', 'lean-bunker-commerce'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($notes)): ?>
            <div style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                <h3><?php _e('Note Ordine', 'lean-bunker-commerce'); ?></h3>
                <p><?php echo nl2br(esc_html($notes)); ?></p>
            </div>
        <?php endif; ?>

        <h3><?php _e('Prodotti Ordinati', 'lean-bunker-commerce'); ?></h3>
        <table class="widefat" style="width: 100%;">
            <thead>
                <tr>
                    <th><?php _e('Prodotto', 'lean-bunker-commerce'); ?></th>
                    <th style="text-align: center;"><?php _e('Quantità', 'lean-bunker-commerce'); ?></th>
                    <th style="text-align: right;"><?php _e('Prezzo', 'lean-bunker-commerce'); ?></th>
                    <th style="text-align: right;"><?php _e('Totale', 'lean-bunker-commerce'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (is_array($items) && !empty($items)): ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($item['product_title']); ?></strong>
                                <?php if (isset($item['product_id'])): ?>
                                    <br/><small style="color: #666;">ID: <?php echo esc_html($item['product_id']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;"><?php echo esc_html($item['quantity']); ?></td>
                            <td style="text-align: right;"><?php echo number_format(floatval($item['price']), 2, ',', '.'); ?> €</td>
                            <td style="text-align: right; font-weight: bold;"><?php echo number_format(floatval($item['total']), 2, ',', '.'); ?> €</td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 20px;">
                            <?php _e('Nessun prodotto trovato', 'lean-bunker-commerce'); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f9f9f9; font-weight: bold;">
                    <td colspan="3" style="text-align: right; padding: 12px;"><?php _e('Totale Ordine:', 'lean-bunker-commerce'); ?></td>
                    <td style="text-align: right; padding: 12px; font-size: 1.2em; color: #0073aa;">
                        <?php echo number_format(floatval($total), 2, ',', '.'); ?> €
                    </td>
                </tr>
            </tfoot>
        </table>

        <hr style="margin: 20px 0;">

        <h3><?php _e('Azioni', 'lean-bunker-commerce'); ?></h3>
        <p>
            <label>
                <input type="checkbox" name="lb_order_status_completed" value="1" <?php checked($status, 'completed'); ?> />
                <strong><?php _e('Segna come completato', 'lean-bunker-commerce'); ?></strong>
            </label>
        </p>
        <?php wp_nonce_field('lb_save_order_status', 'lb_order_status_nonce'); ?>
        <?php
    }

    public function save_meta_boxes($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Salva stato ordine
        if ($post->post_type === $this->post_type_order) {
            if (isset($_POST['lb_order_status_nonce']) && wp_verify_nonce($_POST['lb_order_status_nonce'], 'lb_save_order_status')) {
                $status = isset($_POST['lb_order_status_completed']) ? 'completed' : 'pending';
                update_post_meta($post_id, 'lb_order_status', $status);
            }
            return;
        }

        // Salva metabox carrello
        if (isset($_POST['lb_commerce_nonce']) && wp_verify_nonce($_POST['lb_commerce_nonce'], 'lb_commerce_save_product')) {
            if (isset($_POST['lb_cart_enabled'])) {
                update_post_meta($post_id, $this->meta_product_cart, '1');
            } else {
                delete_post_meta($post_id, $this->meta_product_cart);
            }

            if (isset($_POST['lb_product_price']) && !empty($_POST['lb_product_price'])) {
                update_post_meta($post_id, $this->meta_product_price, floatval($_POST['lb_product_price']));
            } else {
                delete_post_meta($post_id, $this->meta_product_price);
            }

            if (isset($_POST['lb_product_stock']) && $_POST['lb_product_stock'] !== '') {
                update_post_meta($post_id, $this->meta_product_stock, intval($_POST['lb_product_stock']));
            } else {
                delete_post_meta($post_id, $this->meta_product_stock);
            }
        }

        // Salva metabox configuratore
        if (isset($_POST['lb_configurator_nonce']) && wp_verify_nonce($_POST['lb_configurator_nonce'], 'lb_commerce_save_configurator')) {
            $fields_raw = explode("\n", trim($_POST['lb_config_fields'] ?? ''));
            $fields = [];

            foreach ($fields_raw as $line) {
                $line = trim($line);
                if (!$line) continue;

                $parts = array_map('trim', explode('|', $line, 4));
                if (count($parts) < 3) continue;

                $name = sanitize_key($parts[0]);
                $label = sanitize_text_field($parts[1]);
                $type = in_array($parts[2], ['select', 'number']) ? $parts[2] : 'text';

                $field_data = ['label' => $label, 'type' => $type];

                if ($type === 'select' && isset($parts[3])) {
                    $options = [];
                    $opt_list = explode(',', $parts[3]);
                    foreach ($opt_list as $opt) {
                        $opt = trim($opt);
                        if (!$opt) continue;
                        $opt_parts = explode('|', $opt, 2);
                        if (count($opt_parts) === 2) {
                            $opt_label = sanitize_text_field($opt_parts[0]);
                            $opt_value = floatval($opt_parts[1]);
                            $options[$opt_label] = $opt_value;
                        }
                    }
                    $field_data['options'] = $options;
                }

                $fields[$name] = $field_data;
            }

            $config = [
                'fields' => $fields,
                'formula' => sanitize_text_field($_POST['lb_config_formula'] ?? ''),
                'enable_form' => !empty($_POST['lb_config_enable_form']),
                'enable_cart' => !empty($_POST['lb_config_enable_cart'])
            ];

            if (!empty($fields)) {
                update_post_meta($post_id, $this->meta_product_config, $config);
            } else {
                delete_post_meta($post_id, $this->meta_product_config);
            }
        }
    }

    public function load_example_configurator() {
        if (isset($_GET['lb_load_example']) && wp_verify_nonce($_GET['_wpnonce'], 'lb_load_example')) {
            $_POST['lb_config_fields'] = "pavimento | Tipo pavimento | select | Legno|80, Ceramica|45, Marmo|120\nmq | Metri quadrati | number";
            $_POST['lb_config_formula'] = "{pavimento} * {mq}";
        }
    }

    // ============================================
    // CAMPO CATEGORIA UTENTE NEL PROFILO
    // ============================================

    public function add_user_category_field($user) {
        if (!current_user_can('edit_users')) {
            return;
        }

        $categories = $this->get_categories();
        $user_category = get_user_meta($user->ID, $this->meta_user_category, true);
        ?>
        <h2><?php _e('Categoria Utente - Lean Bunker Commerce', 'lean-bunker-commerce'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="lb_user_category"><?php _e('Assegna categoria', 'lean-bunker-commerce'); ?></label></th>
                <td>
                    <select name="lb_user_category" id="lb_user_category">
                        <option value=""><?php _e('— Nessuna categoria —', 'lean-bunker-commerce'); ?></option>
                        <?php foreach ($categories as $slug => $name): ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($user_category, $slug); ?>>
                                <?php echo esc_html($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php _e('Seleziona la categoria per assegnare il menu corretto a questo utente.', 'lean-bunker-commerce'); ?>
                    </p>
                    <?php wp_nonce_field('lb_save_user_category_' . $user->ID, 'lb_user_category_nonce'); ?>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_user_category($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        if (!isset($_POST['lb_user_category_nonce']) || !wp_verify_nonce($_POST['lb_user_category_nonce'], 'lb_save_user_category_' . $user_id)) {
            return;
        }

        if (isset($_POST['lb_user_category']) && !empty($_POST['lb_user_category'])) {
            $categories = $this->get_categories();
            $category = sanitize_text_field($_POST['lb_user_category']);
            
            if (array_key_exists($category, $categories)) {
                update_user_meta($user_id, $this->meta_user_category, $category);
            }
        } else {
            delete_user_meta($user_id, $this->meta_user_category);
        }
    }

    // ============================================
    // GESTIONE CARRELLO
    // ============================================

    private function get_cart() {
        if (!is_user_logged_in()) {
            return array();
        }

        $user_id = get_current_user_id();
        $cart = get_user_meta($user_id, $this->meta_cart_items, true);

        if (empty($cart) || !is_array($cart)) {
            return array();
        }

        return $cart;
    }

    private function save_cart($cart) {
        if (!is_user_logged_in()) {
            return false;
        }

        $user_id = get_current_user_id();
        return update_user_meta($user_id, $this->meta_cart_items, $cart);
    }

    private function add_to_cart($product_id, $quantity = 1, $dynamic_price = null) {
        if (!is_user_logged_in()) {
            return false;
        }

        $cart_enabled = get_post_meta($product_id, $this->meta_product_cart, true) === '1';
        if (!$cart_enabled) {
            $config = get_post_meta($product_id, $this->meta_product_config, true);
            if (empty($config['enable_cart'])) {
                return false;
            }
        }

        $stock = get_post_meta($product_id, $this->meta_product_stock, true);
        if ($stock !== '' && $stock !== false) {
            $stock = intval($stock);
            if ($stock < $quantity) {
                return false;
            }
        }

        $cart = $this->get_cart();
        $product_id = intval($product_id);
        $quantity = intval($quantity);
        $price = $dynamic_price;

        if ($price === null) {
            $price = get_post_meta($product_id, $this->meta_product_price, true);
            if (!$price) {
                return false;
            }
        }

        $cart[$product_id] = array(
            'quantity' => $quantity,
            'price' => floatval($price)
        );

        return $this->save_cart($cart);
    }

    private function remove_from_cart($product_id) {
        $cart = $this->get_cart();
        $product_id = intval($product_id);

        if (isset($cart[$product_id])) {
            unset($cart[$product_id]);
            return $this->save_cart($cart);
        }

        return false;
    }

    private function clear_cart() {
        if (!is_user_logged_in()) {
            return false;
        }

        $user_id = get_current_user_id();
        return delete_user_meta($user_id, $this->meta_cart_items);
    }

    public function handle_cart_actions() {
        $this->load_example_configurator();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (isset($_POST['lb_add_to_cart'])) {
            $this->handle_add_to_cart();
        } elseif (isset($_POST['lb_remove_from_cart'])) {
            $this->handle_remove_from_cart();
        } elseif (isset($_POST['lb_clear_cart'])) {
            $this->handle_clear_cart();
        } elseif (isset($_POST['lb_checkout'])) {
            $this->handle_checkout();
        }
    }

    private function handle_add_to_cart() {
        if (!isset($_POST['lb_product_id']) || !is_user_logged_in()) {
            return;
        }

        $product_id = intval($_POST['lb_product_id']);
        $quantity = isset($_POST['lb_quantity']) ? intval($_POST['lb_quantity']) : 1;
        $dynamic_price = isset($_POST['lb_dynamic_price']) ? floatval($_POST['lb_dynamic_price']) : null;

        if ($this->add_to_cart($product_id, $quantity, $dynamic_price)) {
            wp_redirect(add_query_arg('lb_cart_msg', 'added', wp_get_referer()));
            exit;
        }
    }

    private function handle_remove_from_cart() {
        if (!isset($_POST['lb_product_id']) || !is_user_logged_in()) {
            return;
        }

        $product_id = intval($_POST['lb_product_id']);
        $this->remove_from_cart($product_id);

        wp_redirect(add_query_arg('lb_cart_msg', 'removed', wp_get_referer()));
        exit;
    }

    private function handle_clear_cart() {
        if (!is_user_logged_in()) {
            return;
        }

        $this->clear_cart();
        wp_redirect(add_query_arg('lb_cart_msg', 'cleared', wp_get_referer()));
        exit;
    }

    // ✅ CHECKOUT CON DATI SPEDIZIONE/FATTURAZIONE
    private function handle_checkout() {
        if (!is_user_logged_in()) {
            return;
        }

        $cart = $this->get_cart();

        if (empty($cart)) {
            wp_redirect(add_query_arg('lb_cart_msg', 'empty', wp_get_referer()));
            exit;
        }

        // Validazione dati checkout
        $required_fields = array('billing_name', 'billing_address', 'billing_city', 'billing_province', 'billing_postal_code', 'billing_country');
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                wp_redirect(add_query_arg('lb_cart_msg', 'missing_data', wp_get_referer()));
                exit;
            }
        }

        // Salva dati fatturazione
        $billing = array(
            'name' => sanitize_text_field($_POST['billing_name']),
            'address' => sanitize_text_field($_POST['billing_address']),
            'city' => sanitize_text_field($_POST['billing_city']),
            'province' => sanitize_text_field($_POST['billing_province']),
            'postal_code' => sanitize_text_field($_POST['billing_postal_code']),
            'country' => sanitize_text_field($_POST['billing_country']),
            'phone' => sanitize_text_field($_POST['billing_phone'] ?? ''),
            'email' => sanitize_email($_POST['billing_email'] ?? wp_get_current_user()->user_email)
        );

        // Salva dati spedizione
        $shipping = array();
        if (!empty($_POST['shipping_same_as_billing'])) {
            $shipping['same_as_billing'] = true;
        } else {
            $shipping = array(
                'name' => sanitize_text_field($_POST['shipping_name'] ?? $billing['name']),
                'address' => sanitize_text_field($_POST['shipping_address'] ?? $billing['address']),
                'city' => sanitize_text_field($_POST['shipping_city'] ?? $billing['city']),
                'province' => sanitize_text_field($_POST['shipping_province'] ?? $billing['province']),
                'postal_code' => sanitize_text_field($_POST['shipping_postal_code'] ?? $billing['postal_code']),
                'country' => sanitize_text_field($_POST['shipping_country'] ?? $billing['country'])
            );
        }

        // Note ordine
        $notes = sanitize_textarea_field($_POST['order_notes'] ?? '');

        // Crea ordine
        $order_id = $this->create_order($cart, $billing, $shipping, $notes);

        if ($order_id) {
            $this->clear_cart();
            
            $page = get_page_by_path($this->page_order_completed_slug);
            $redirect_url = $page ? get_permalink($page->ID) : home_url();
            
            wp_redirect(add_query_arg(array(
                'lb_order' => $order_id,
                'lb_cart_msg' => 'completed'
            ), $redirect_url));
            exit;
        }
    }

    private function create_order($cart, $billing, $shipping, $notes) {
        $user_id = get_current_user_id();
        $user = get_userdata($user_id);

        $total = 0;
        $items = array();

        foreach ($cart as $product_id => $item_data) {
            $quantity = is_array($item_data) ? $item_data['quantity'] : $item_data;
            $price = is_array($item_data) ? $item_data['price'] : get_post_meta($product_id, $this->meta_product_price, true);
            
            if ($price) {
                $item_total = floatval($price) * intval($quantity);
                $total += $item_total;

                $items[] = array(
                    'product_id' => $product_id,
                    'product_title' => get_the_title($product_id),
                    'quantity' => $quantity,
                    'price' => $price,
                    'total' => $item_total
                );
            }
        }

        $order_post = array(
            'post_title' => 'Ordine #' . date('YmdHis') . '-' . $user_id,
            'post_type' => $this->post_type_order,
            'post_status' => 'publish',
            'post_author' => 1
        );

        $order_id = wp_insert_post($order_post);

        if ($order_id) {
            update_post_meta($order_id, 'lb_order_user_id', $user_id);
            update_post_meta($order_id, 'lb_order_user_email', $billing['email']);
            update_post_meta($order_id, 'lb_order_items', $items);
            update_post_meta($order_id, 'lb_order_total', $total);
            update_post_meta($order_id, 'lb_order_status', 'pending');
            update_post_meta($order_id, 'lb_order_payment_method', 'bank_transfer');
            update_post_meta($order_id, 'lb_order_date', current_time('mysql'));
            update_post_meta($order_id, 'lb_order_billing', $billing);
            update_post_meta($order_id, 'lb_order_shipping', $shipping);
            update_post_meta($order_id, 'lb_order_notes', $notes);

            $user_orders = get_user_meta($user_id, 'lb_user_orders', true);
            if (!is_array($user_orders)) {
                $user_orders = array();
            }
            $user_orders[] = $order_id;
            update_user_meta($user_id, 'lb_user_orders', $user_orders);

            // ✅ Invia email di conferma all'utente
            $this->send_order_confirmation_email($order_id, $user, $total, $billing);

            return $order_id;
        }

        return false;
    }

    // ✅ EMAIL DI CONFERMA
    private function send_order_confirmation_email($order_id, $user, $total, $billing) {
        $iban = get_option('lb_bank_iban', 'IT00X0000000000000000000000');
        $intestatario = get_option('lb_bank_intestatario', get_bloginfo('name'));
        
        $subject = __('Conferma ordine #' . $order_id, 'lean-bunker-commerce');
        $body = "Grazie per il tuo ordine su " . get_bloginfo('name') . "!\n\n";
        $body .= "Numero ordine: #" . $order_id . "\n";
        $body .= "Totale: " . number_format($total, 2, ',', '.') . " €\n\n";
        $body .= "DATI FATTURAZIONE\n";
        $body .= $billing['name'] . "\n";
        $body .= $billing['address'] . "\n";
        $body .= $billing['postal_code'] . " " . $billing['city'] . " (" . $billing['province'] . ")\n";
        $body .= $billing['country'] . "\n";
        $body .= "Email: " . $billing['email'] . "\n";
        if (!empty($billing['phone'])) {
            $body .= "Telefono: " . $billing['phone'] . "\n";
        }
        $body .= "\nCOORDINATE BANCARIE PER IL PAGAMENTO\n";
        $body .= "Intestatario: " . $intestatario . "\n";
        $body .= "IBAN: " . $iban . "\n";
        $body .= "Causale: Ordine #" . $order_id . "\n\n";
        $body .= "Dopo aver effettuato il bonifico, conserva la ricevuta. L'ordine verrà processato entro 24-48 ore lavorative.\n\n";
        $body .= "Grazie per aver scelto " . get_bloginfo('name') . "!\n";
        
        wp_mail($billing['email'], $subject, $body);
    }

    // ============================================
    // SHORTCODES
    // ============================================

    public function shortcode_menu_utente($atts) {
        if (!is_user_logged_in()) {
            return '<p class="lb-message">' . __('Devi essere loggato per vedere il menu.', 'lean-bunker-commerce') . '</p>';
        }

        $user_id = get_current_user_id();
        $user_category = get_user_meta($user_id, $this->meta_user_category, true);

        if (empty($user_category)) {
            return '<p class="lb-message">' . __('Nessun menu disponibile per il tuo profilo.', 'lean-bunker-commerce') . '</p>';
        }

        $categories = $this->get_categories();
        if (!array_key_exists($user_category, $categories)) {
            return '<p class="lb-message">' . __('Categoria non valida.', 'lean-bunker-commerce') . '</p>';
        }

        $menu_location = 'lb_user_area_' . $user_category;

        ob_start();
        wp_nav_menu(array(
            'theme_location' => $menu_location,
            'menu_class' => 'lb-user-menu lb-user-menu-' . sanitize_html_class($user_category),
            'container' => 'nav',
            'container_class' => 'lb-user-menu-container',
            'fallback_cb' => array($this, 'menu_fallback'),
            'echo' => true
        ));
        return ob_get_clean();
    }

    public function menu_fallback() {
        return '<p class="lb-message">' . __('Nessun menu configurato per la tua area.', 'lean-bunker-commerce') . '</p>';
    }

    public function shortcode_aggiungi_al_carrello($atts) {
        $atts = shortcode_atts(array(
            'id' => get_the_ID(),
            'quantity' => 1
        ), $atts);

        $product_id = intval($atts['id']);
        $quantity = intval($atts['quantity']);

        if (get_post_meta($product_id, $this->meta_product_cart, true) !== '1') {
            return '';
        }

        $price = get_post_meta($product_id, $this->meta_product_price, true);
        $in_stock = true;

        $stock = get_post_meta($product_id, $this->meta_product_stock, true);
        if ($stock !== '' && $stock !== false) {
            $stock = intval($stock);
            $in_stock = ($stock >= $quantity);
        }

        ob_start();
        ?>
        <form method="post" action="" class="lb-add-to-cart-form" style="display: inline;">
            <?php if ($price): ?>
                <span class="lb-price" style="font-weight: bold; margin-right: 10px;">
                    <?php echo number_format(floatval($price), 2, ',', '.'); ?> €
                </span>
            <?php endif; ?>

            <?php if ($in_stock): ?>
                <input type="hidden" name="lb_product_id" value="<?php echo esc_attr($product_id); ?>">
                <input type="hidden" name="lb_quantity" value="<?php echo esc_attr($quantity); ?>">
                <button type="submit" name="lb_add_to_cart" class="button">
                    🛒 <?php _e('Aggiungi al carrello', 'lean-bunker-commerce'); ?>
                </button>
            <?php else: ?>
                <span class="lb-out-of-stock" style="color: #dc3232; font-weight: bold;">
                    <?php _e('Non disponibile', 'lean-bunker-commerce'); ?>
                </span>
            <?php endif; ?>
        </form>
        <?php

        return ob_get_clean();
    }

    public function shortcode_carrello($atts) {
        if (!is_user_logged_in()) {
            return '<p class="lb-message">' . __('Devi essere loggato per vedere il carrello.', 'lean-bunker-commerce') . '</p>';
        }

        $cart = $this->get_cart();

        if (empty($cart)) {
            return '<div class="lb-cart-empty">
                <p style="text-align: center; padding: 40px; background: #f9f9f9; border-radius: 8px;">
                    🛒 ' . __('Il tuo carrello è vuoto.', 'lean-bunker-commerce') . '<br/><br/>
                    <a href="' . home_url() . '" class="button">' . __('Torna allo shop', 'lean-bunker-commerce') . '</a>
                </p>
            </div>';
        }

        $total = 0;
        $out_of_stock = false;

        ob_start();
        ?>
        <div class="lb-cart">
            <?php if (isset($_GET['lb_cart_msg'])): ?>
                <?php if ($_GET['lb_cart_msg'] === 'added'): ?>
                    <div class="lb-notice lb-notice-success">✅ <?php _e('Prodotto aggiunto al carrello!', 'lean-bunker-commerce'); ?></div>
                <?php elseif ($_GET['lb_cart_msg'] === 'removed'): ?>
                    <div class="lb-notice lb-notice-info">ℹ️ <?php _e('Prodotto rimosso dal carrello.', 'lean-bunker-commerce'); ?></div>
                <?php elseif ($_GET['lb_cart_msg'] === 'missing_data'): ?>
                    <div class="lb-notice lb-notice-error">❌ <?php _e('Compila tutti i campi obbligatori nel checkout.', 'lean-bunker-commerce'); ?></div>
                <?php endif; ?>
            <?php endif; ?>

            <table class="lb-cart-table" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr style="background: #f5f5f5;">
                        <th style="padding: 12px; text-align: left;"><?php _e('Prodotto', 'lean-bunker-commerce'); ?></th>
                        <th style="padding: 12px; text-align: center;"><?php _e('Quantità', 'lean-bunker-commerce'); ?></th>
                        <th style="padding: 12px; text-align: right;"><?php _e('Prezzo', 'lean-bunker-commerce'); ?></th>
                        <th style="padding: 12px; text-align: right;"><?php _e('Totale', 'lean-bunker-commerce'); ?></th>
                        <th style="padding: 12px; text-align: center;"><?php _e('Azioni', 'lean-bunker-commerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cart as $product_id => $item_data):
                        $product = get_post($product_id);
                        if (!$product) continue;

                        $quantity = is_array($item_data) ? $item_data['quantity'] : $item_data;
                        $price = is_array($item_data) ? $item_data['price'] : get_post_meta($product_id, $this->meta_product_price, true);
                        
                        if (!$price) continue;

                        $item_total = floatval($price) * intval($quantity);
                        $total += $item_total;

                        $stock = get_post_meta($product_id, $this->meta_product_stock, true);
                        $in_stock = true;
                        if ($stock !== '' && $stock !== false) {
                            $in_stock = (intval($stock) >= $quantity);
                            if (!$in_stock) $out_of_stock = true;
                        }
                    ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px;">
                                <strong><?php echo esc_html($product->post_title); ?></strong>
                                <?php if (!$in_stock): ?>
                                    <br/><span style="color: #dc3232; font-size: 0.9em;">⚠️ <?php _e('Quantità non disponibile', 'lean-bunker-commerce'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; text-align: center;"><?php echo esc_html($quantity); ?></td>
                            <td style="padding: 12px; text-align: right;"><?php echo number_format(floatval($price), 2, ',', '.'); ?> €</td>
                            <td style="padding: 12px; text-align: right;"><strong><?php echo number_format($item_total, 2, ',', '.'); ?> €</strong></td>
                            <td style="padding: 12px; text-align: center;">
                                <form method="post" action="" style="display: inline;">
                                    <input type="hidden" name="lb_product_id" value="<?php echo esc_attr($product_id); ?>">
                                    <button type="submit" name="lb_remove_from_cart" class="button button-link-delete" style="padding: 0; color: #dc3232;">
                                        🗑️ <?php _e('Rimuovi', 'lean-bunker-commerce'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f9f9f9; font-weight: bold;">
                        <td colspan="3" style="padding: 12px; text-align: right;"><?php _e('Totale:', 'lean-bunker-commerce'); ?></td>
                        <td style="padding: 12px; text-align: right; font-size: 1.2em; color: #0073aa;">
                            <?php echo number_format($total, 2, ',', '.'); ?> €
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <?php if ($out_of_stock): ?>
                <div class="lb-notice lb-notice-warning" style="margin-bottom: 20px;">
                    ⚠️ <?php _e('Alcuni prodotti non sono più disponibili nella quantità selezionata. Aggiorna il carrello prima di procedere.', 'lean-bunker-commerce'); ?>
                </div>
            <?php endif; ?>

            <div style="display: flex; gap: 10px;">
                <form method="post" action="">
                    <button type="submit" name="lb_clear_cart" class="button">
                        🗑️ <?php _e('Svuota carrello', 'lean-bunker-commerce'); ?>
                    </button>
                </form>

                <?php if (!$out_of_stock && $total > 0): ?>
                    <a href="<?php echo home_url('/checkout/'); ?>" class="button button-primary" style="margin-left: auto;">
                        💳 <?php _e('Procedi al checkout', 'lean-bunker-commerce'); ?> (<?php echo number_format($total, 2, ',', '.'); ?> €)
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    // ✅ SHORTCODE CHECKOUT CON FORM DATI
    public function shortcode_checkout($atts) {
        if (!is_user_logged_in()) {
            return '<p class="lb-message">' . __('Devi essere loggato per procedere al checkout.', 'lean-bunker-commerce') . '</p>';
        }

        $cart = $this->get_cart();

        if (empty($cart)) {
            return '<div class="lb-cart-empty">
                <p style="text-align: center; padding: 40px; background: #f9f9f9; border-radius: 8px;">
                    🛒 ' . __('Il tuo carrello è vuoto.', 'lean-bunker-commerce') . '<br/><br/>
                    <a href="' . home_url() . '" class="button">' . __('Torna allo shop', 'lean-bunker-commerce') . '</a>
                </p>
            </div>';
        }

        $total = 0;
        foreach ($cart as $product_id => $item_data) {
            $price = is_array($item_data) ? $item_data['price'] : get_post_meta($product_id, $this->meta_product_price, true);
            $quantity = is_array($item_data) ? $item_data['quantity'] : $item_data;
            
            if ($price) {
                $total += floatval($price) * intval($quantity);
            }
        }

        $user = wp_get_current_user();
        $iban = get_option('lb_bank_iban', 'IT00X0000000000000000000000');
        $intestatario = get_option('lb_bank_intestatario', get_bloginfo('name'));

        // Pre-compila dai dati utente
        $billing_name = $user->first_name . ' ' . $user->last_name;
        if (trim($billing_name) === '') {
            $billing_name = $user->display_name;
        }

        ob_start();
        ?>
        <div class="lb-checkout">
            <h2>💳 <?php _e('Checkout - Bonifico Bancario', 'lean-bunker-commerce'); ?></h2>

            <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                <h3><?php _e('Riepilogo ordine', 'lean-bunker-commerce'); ?></h3>
                <p><strong><?php _e('Totale ordine:', 'lean-bunker-commerce'); ?></strong> <span style="font-size: 1.3em; color: #0073aa; font-weight: bold;"><?php echo number_format($total, 2, ',', '.'); ?> €</span></p>
            </div>

            <form method="post" action="">
                <h3>🧾 <?php _e('Dati di Fatturazione', 'lean-bunker-commerce'); ?></h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; margin-bottom: 25px;">
                    <div>
                        <label for="billing_name"><strong><?php _e('Nome e Cognome *', 'lean-bunker-commerce'); ?></strong></label>
                        <input type="text" id="billing_name" name="billing_name" value="<?php echo esc_attr($billing_name); ?>" class="regular-text" required>
                    </div>
                    <div>
                        <label for="billing_email"><strong><?php _e('Email *', 'lean-bunker-commerce'); ?></strong></label>
                        <input type="email" id="billing_email" name="billing_email" value="<?php echo esc_attr($user->user_email); ?>" class="regular-text" required>
                    </div>
                    <div>
                        <label for="billing_phone"><?php _e('Telefono', 'lean-bunker-commerce'); ?></label>
                        <input type="tel" id="billing_phone" name="billing_phone" class="regular-text">
                    </div>
                    <div>
                        <label for="billing_address"><strong><?php _e('Indirizzo (via e numero) *', 'lean-bunker-commerce'); ?></strong></label>
                        <input type="text" id="billing_address" name="billing_address" class="regular-text" required>
                    </div>
                    <div>
                        <label for="billing_city"><strong><?php _e('Città *', 'lean-bunker-commerce'); ?></strong></label>
                        <input type="text" id="billing_city" name="billing_city" class="regular-text" required>
                    </div>
                    <div>
                        <label for="billing_province"><strong><?php _e('Provincia *', 'lean-bunker-commerce'); ?></strong></label>
                        <input type="text" id="billing_province" name="billing_province" class="regular-text" required maxlength="2">
                    </div>
                    <div>
                        <label for="billing_postal_code"><strong><?php _e('CAP *', 'lean-bunker-commerce'); ?></strong></label>
                        <input type="text" id="billing_postal_code" name="billing_postal_code" class="regular-text" required maxlength="5">
                    </div>
                    <div>
                        <label for="billing_country"><strong><?php _e('Paese *', 'lean-bunker-commerce'); ?></strong></label>
                        <input type="text" id="billing_country" name="billing_country" value="Italia" class="regular-text" required>
                    </div>
                </div>

                <h3>🚚 <?php _e('Indirizzo di Spedizione', 'lean-bunker-commerce'); ?></h3>
                <p>
                    <label>
                        <input type="checkbox" name="shipping_same_as_billing" value="1" checked>
                        <?php _e('Uguale all\'indirizzo di fatturazione', 'lean-bunker-commerce'); ?>
                    </label>
                </p>
                <div id="shipping-fields" style="display: none; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; margin-top: 15px;">
                    <div>
                        <label for="shipping_name"><?php _e('Nome e Cognome', 'lean-bunker-commerce'); ?></label>
                        <input type="text" id="shipping_name" name="shipping_name" class="regular-text">
                    </div>
                    <div>
                        <label for="shipping_address"><?php _e('Indirizzo (via e numero)', 'lean-bunker-commerce'); ?></label>
                        <input type="text" id="shipping_address" name="shipping_address" class="regular-text">
                    </div>
                    <div>
                        <label for="shipping_city"><?php _e('Città', 'lean-bunker-commerce'); ?></label>
                        <input type="text" id="shipping_city" name="shipping_city" class="regular-text">
                    </div>
                    <div>
                        <label for="shipping_province"><?php _e('Provincia', 'lean-bunker-commerce'); ?></label>
                        <input type="text" id="shipping_province" name="shipping_province" class="regular-text" maxlength="2">
                    </div>
                    <div>
                        <label for="shipping_postal_code"><?php _e('CAP', 'lean-bunker-commerce'); ?></label>
                        <input type="text" id="shipping_postal_code" name="shipping_postal_code" class="regular-text" maxlength="5">
                    </div>
                    <div>
                        <label for="shipping_country"><?php _e('Paese', 'lean-bunker-commerce'); ?></label>
                        <input type="text" id="shipping_country" name="shipping_country" value="Italia" class="regular-text">
                    </div>
                </div>

                <h3>📝 <?php _e('Note Ordine', 'lean-bunker-commerce'); ?></h3>
                <textarea name="order_notes" rows="3" class="regular-text" style="width: 100%;"></textarea>
                <p class="description"><?php _e('Istruzioni aggiuntive per la spedizione o altre note.', 'lean-bunker-commerce'); ?></p>

                <hr style="margin: 30px 0;">

                <div style="background: #fff3cd; padding: 20px; border-radius: 8px; border-left: 4px solid #ffc107; margin-bottom: 20px;">
                    <h3>🏦 <?php _e('Coordinate Bancarie', 'lean-bunker-commerce'); ?></h3>
                    <p><strong><?php _e('Intestatario:', 'lean-bunker-commerce'); ?></strong> <?php echo esc_html($intestatario); ?></p>
                    <p><strong><?php _e('IBAN:', 'lean-bunker-commerce'); ?></strong> <code><?php echo esc_html($iban); ?></code></p>
                    <p><strong><?php _e('Causale:', 'lean-bunker-commerce'); ?></strong> Ordine #<?php echo date('Ymd'); ?>-<?php echo $user->ID; ?></p>
                </div>

                <div style="background: #d4edda; padding: 20px; border-radius: 8px; border-left: 4px solid #28a745; margin-bottom: 20px;">
                    <h3>📋 <?php _e('Istruzioni', 'lean-bunker-commerce'); ?></h3>
                    <ol>
                        <li><?php _e('Compila i dati sopra e conferma l\'ordine', 'lean-bunker-commerce'); ?></li>
                        <li><?php _e('Riceverai una email con le coordinate per il bonifico', 'lean-bunker-commerce'); ?></li>
                        <li><?php _e('Effettua il bonifico entro 3 giorni', 'lean-bunker-commerce'); ?></li>
                        <li><?php _e('L\'ordine verrà processato entro 24-48 ore lavorative dal pagamento', 'lean-bunker-commerce'); ?></li>
                    </ol>
                </div>

                <p style="text-align: center;">
                    <button type="submit" name="lb_checkout" class="button button-primary button-large" style="font-size: 1.2em; padding: 15px 40px;">
                        ✅ <?php _e('Conferma Ordine - ', 'lean-bunker-commerce'); echo number_format($total, 2, ',', '.'); ?> €
                    </button>
                </p>

                <p style="text-align: center; margin-top: 20px; font-size: 0.9em; color: #666;">
                    <?php _e('Cliccando su "Conferma Ordine" accetti le condizioni di vendita e l\'informativa privacy.', 'lean-bunker-commerce'); ?>
                </p>
            </form>
        </div>

        <script>
        document.querySelector('input[name="shipping_same_as_billing"]').addEventListener('change', function() {
            document.getElementById('shipping-fields').style.display = this.checked ? 'none' : 'grid';
        });
        </script>
        <?php

        return ob_get_clean();
    }

    public function shortcode_miei_ordini($atts) {
        if (!is_user_logged_in()) {
            return '<p class="lb-message">' . __('Devi essere loggato per vedere i tuoi ordini.', 'lean-bunker-commerce') . '</p>';
        }

        $user_id = get_current_user_id();
        $user_orders = get_user_meta($user_id, 'lb_user_orders', true);

        if (empty($user_orders) || !is_array($user_orders)) {
            return '<p style="text-align: center; padding: 40px; background: #f9f9f9; border-radius: 8px;">
                📦 ' . __('Non hai ancora effettuato ordini.', 'lean-bunker-commerce') . '
            </p>';
        }

        $orders = array();
        foreach ($user_orders as $order_id) {
            $order = get_post($order_id);
            if ($order && $order->post_type === $this->post_type_order) {
                $orders[] = $order;
            }
        }

        usort($orders, function($a, $b) {
            return strtotime($b->post_date) - strtotime($a->post_date);
        });

        ob_start();
        ?>
        <div class="lb-my-orders">
            <h2>📦 <?php _e('I Miei Ordini', 'lean-bunker-commerce'); ?></h2>

            <?php if (isset($_GET['lb_cart_msg']) && $_GET['lb_cart_msg'] === 'completed'): ?>
                <div class="lb-notice lb-notice-success">
                    ✅ <?php _e('Ordine completato con successo! Riceverai una email di conferma a breve.', 'lean-bunker-commerce'); ?>
                </div>
            <?php endif; ?>

            <table class="lb-orders-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f5f5f5;">
                        <th style="padding: 12px; text-align: left;"><?php _e('Ordine', 'lean-bunker-commerce'); ?></th>
                        <th style="padding: 12px; text-align: left;"><?php _e('Data', 'lean-bunker-commerce'); ?></th>
                        <th style="padding: 12px; text-align: right;"><?php _e('Totale', 'lean-bunker-commerce'); ?></th>
                        <th style="padding: 12px; text-align: center;"><?php _e('Stato', 'lean-bunker-commerce'); ?></th>
                        <th style="padding: 12px; text-align: center;"><?php _e('Azioni', 'lean-bunker-commerce'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order):
                        $order_total = get_post_meta($order->ID, 'lb_order_total', true);
                        $order_status = get_post_meta($order->ID, 'lb_order_status', true);
                        $order_date = get_post_meta($order->ID, 'lb_order_date', true);
                    ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="padding: 12px;">
                                <strong><?php echo esc_html($order->post_title); ?></strong>
                            </td>
                            <td style="padding: 12px;">
                                <?php echo date('d/m/Y H:i', strtotime($order_date)); ?>
                            </td>
                            <td style="padding: 12px; text-align: right; font-weight: bold;">
                                <?php echo number_format(floatval($order_total), 2, ',', '.'); ?> €
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <?php if ($order_status === 'pending'): ?>
                                    <span style="background: #fff3cd; color: #856404; padding: 4px 12px; border-radius: 12px; font-size: 0.9em;">
                                        ⏳ <?php _e('In attesa', 'lean-bunker-commerce'); ?>
                                    </span>
                                <?php elseif ($order_status === 'completed'): ?>
                                    <span style="background: #d4edda; color: #155724; padding: 4px 12px; border-radius: 12px; font-size: 0.9em;">
                                        ✅ <?php _e('Completato', 'lean-bunker-commerce'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <a href="<?php echo get_permalink($order->ID); ?>" class="button button-small">
                                    👁️ <?php _e('Dettagli', 'lean-bunker-commerce'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php

        return ob_get_clean();
    }

    public function shortcode_miei_acquisti($atts) {
        if (!is_user_logged_in()) {
            return '<p class="lb-message">' . __('Devi essere loggato per vedere i tuoi acquisti.', 'lean-bunker-commerce') . '</p>';
        }

        $user_id = get_current_user_id();
        $user_orders = get_user_meta($user_id, 'lb_user_orders', true);

        if (empty($user_orders) || !is_array($user_orders)) {
            return '<p style="text-align: center; padding: 40px; background: #f9f9f9; border-radius: 8px;">
                📦 ' . __('Non hai ancora effettuato acquisti.', 'lean-bunker-commerce') . '
            </p>';
        }

        $products = array();
        foreach ($user_orders as $order_id) {
            $items = get_post_meta($order_id, 'lb_order_items', true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    $product_id = $item['product_id'];
                    if (!isset($products[$product_id])) {
                        $products[$product_id] = array(
                            'title' => $item['product_title'],
                            'quantity' => 0,
                            'total_spent' => 0
                        );
                    }
                    $products[$product_id]['quantity'] += $item['quantity'];
                    $products[$product_id]['total_spent'] += $item['total'];
                }
            }
        }

        if (empty($products)) {
            return '<p style="text-align: center; padding: 40px; background: #f9f9f9; border-radius: 8px;">
                📦 ' . __('Non hai ancora effettuato acquisti.', 'lean-bunker-commerce') . '
            </p>';
        }

        ob_start();
        ?>
        <div class="lb-my-purchases">
            <h2>🛍️ <?php _e('I Miei Acquisti', 'lean-bunker-commerce'); ?></h2>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
                <?php foreach ($products as $product_id => $data): ?>
                    <div style="border: 1px solid #ddd; border-radius: 8px; padding: 20px; background: #fff;">
                        <h3 style="margin-top: 0;"><?php echo esc_html($data['title']); ?></h3>
                        <p style="color: #666; margin: 10px 0;">
                            <strong><?php _e('Quantità totale:', 'lean-bunker-commerce'); ?></strong> <?php echo esc_html($data['quantity']); ?>
                        </p>
                        <p style="color: #0073aa; font-size: 1.2em; font-weight: bold; margin: 10px 0;">
                            <?php echo number_format($data['total_spent'], 2, ',', '.'); ?> €
                            <br/><small style="font-size: 0.8em; color: #666;"><?php _e('spesi in totale', 'lean-bunker-commerce'); ?></small>
                        </p>
                        <?php if (get_post_status($product_id) === 'publish'): ?>
                            <a href="<?php echo get_permalink($product_id); ?>" class="button" style="display: block; text-align: center; margin-top: 10px;">
                                👁️ <?php _e('Vedi prodotto', 'lean-bunker-commerce'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    public function shortcode_lean_calculator($atts) {
        $atts = shortcode_atts(array('id' => get_the_ID()), $atts);
        $post_id = intval($atts['id']);
        $config = get_post_meta($post_id, $this->meta_product_config, true);

        if (empty($config['fields']) || !is_array($config['fields'])) {
            return '<p>' . __('Configuratore non disponibile.', 'lean-bunker-commerce') . '</p>';
        }

        $fields = $config['fields'];
        $formula = $config['formula'] ?? '';
        $enable_form = !empty($config['enable_form']);
        $enable_cart = !empty($config['enable_cart']);
        $calc_result = null;
        $user_values = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lb_calc_submit'])) {
            foreach ($fields as $name => $field) {
                if ($field['type'] === 'select') {
                    $user_values[$name] = floatval($_POST[$name] ?? 0);
                } elseif ($field['type'] === 'number') {
                    $user_values[$name] = floatval($_POST[$name] ?? 0);
                }
            }

            $expr = $formula;
            foreach ($user_values as $var => $val) {
                $expr = str_replace('{' . $var . '}', $val, $expr);
            }

            if (preg_match('/^[0-9+\-*.\/\s\(\)]+$/', $expr)) {
                try {
                    ob_start();
                    $calc_result = eval("return ($expr);");
                    ob_end_clean();
                    if (!is_numeric($calc_result)) {
                        $calc_result = null;
                    }
                } catch (ParseError $e) {
                    $calc_result = null;
                }
            } else {
                $calc_result = null;
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lb_send_request'])) {
            $name = sanitize_text_field($_POST['lb_name'] ?? '');
            $email = sanitize_email($_POST['lb_email'] ?? '');
            $message = sanitize_textarea_field($_POST['lb_message'] ?? '');
            $total = floatval($_POST['lb_total'] ?? 0);
            $config_data = $_POST['lb_config'] ?? [];

            if (!$name || !$email || !is_email($email)) {
                return '<div class="lb-notice lb-notice-error"><p>' . __('Nome ed email sono obbligatori.', 'lean-bunker-commerce') . '</p></div>';
            }

            $to = get_option('admin_email');
            $subject = 'Nuova richiesta da ' . get_bloginfo('name');
            $body = "Hai ricevuto una nuova richiesta:\n\n";
            $body .= "Nome: $name\n";
            $body .= "Email: $email\n";
            $body .= "Totale: " . number_format($total, 2, ',', '.') . " €\n";
            $body .= "Prodotto: " . get_the_title($post_id) . "\n";
            $body .= "Configurazione:\n";
            foreach ($config_data as $key => $val) {
                $body .= "- $key: $val\n";
            }
            if ($message) {
                $body .= "\nMessaggio:\n$message";
            }

            $headers = array('Reply-To: ' . $email);
            if (wp_mail($to, $subject, $body, $headers)) {
                return '<div class="lb-notice lb-notice-success"><p>' . __('Grazie! La tua richiesta è stata inviata.', 'lean-bunker-commerce') . '</p></div>';
            } else {
                return '<div class="lb-notice lb-notice-error"><p>' . __('Errore nell\'invio. Controlla la configurazione email del sito.', 'lean-bunker-commerce') . '</p></div>';
            }
        }

        $output = '<div class="lb-calculator">';
        $output .= '<form method="post" action="">';

        foreach ($fields as $name => $field) {
            $output .= '<div class="form-field">';
            $output .= '<label for="lb_' . esc_attr($name) . '">' . esc_html($field['label']) . ':</label><br/>';

            if ($field['type'] === 'select') {
                $output .= '<select id="lb_' . esc_attr($name) . '" name="' . esc_attr($name) . '" required class="regular-select">';
                foreach ($field['options'] as $label => $value) {
                    $selected = (isset($_POST[$name]) && $_POST[$name] == $value) ? ' selected' : '';
                    $output .= '<option value="' . esc_attr($value) . '"' . $selected . '>' . esc_html($label) . ' (' . esc_html($value) . ')</option>';
                }
                $output .= '</select>';
            } elseif ($field['type'] === 'number') {
                $val = isset($_POST[$name]) ? floatval($_POST[$name]) : 1;
                $output .= '<input type="number" id="lb_' . esc_attr($name) . '" name="' . esc_attr($name) . '" step="0.01" min="0" value="' . esc_attr($val) . '" required class="regular-text" />';
            }
            $output .= '</div>';
        }

        $output .= '<div class="submit">';
        $output .= '<button type="submit" name="lb_calc_submit" class="button button-primary">' . __('Calcola Totale', 'lean-bunker-commerce') . '</button>';
        $output .= '</div>';

        $output .= '</form>';

        if ($calc_result !== null && is_numeric($calc_result)) {
            $formatted_result = number_format($calc_result, 2, ',', '.');
            $output .= '<div class="lb-result" style="margin-top:20px; padding:10px; background:#f9f9f9; border-left:4px solid #0073aa;"><strong>' . __('Totale:', 'lean-bunker-commerce') . ' ' . esc_html($formatted_result) . ' €</strong></div>';

            if ($enable_cart) {
                $output .= '<div class="lb-add-to-cart-button" style="margin-top:20px;">';
                $output .= '<form method="post" action="" class="lb-add-to-cart-form" style="display: inline;">';
                $output .= '<input type="hidden" name="lb_product_id" value="' . esc_attr($post_id) . '">';
                $output .= '<input type="hidden" name="lb_quantity" value="1">';
                $output .= '<input type="hidden" name="lb_dynamic_price" value="' . esc_attr($calc_result) . '">';
                $output .= '<button type="submit" name="lb_add_to_cart" class="button button-primary">🛒 ' . __('Aggiungi al carrello', 'lean-bunker-commerce') . '</button>';
                $output .= '</form>';
                $output .= '</div>';
            }

            if ($enable_form) {
                $output .= '<div class="lb-request-form" style="margin-top:20px;">';
                $output .= '<h3>' . __('Invia la tua richiesta', 'lean-bunker-commerce') . '</h3>';
                $output .= '<form method="post" action="">';
                $output .= '<div class="form-field"><label for="lb_name">' . __('Nome *', 'lean-bunker-commerce') . '</label><br/><input type="text" id="lb_name" name="lb_name" required class="regular-text" /></div>';
                $output .= '<div class="form-field"><label for="lb_email">' . __('Email *', 'lean-bunker-commerce') . '</label><br/><input type="email" id="lb_email" name="lb_email" required class="regular-text" /></div>';
                $output .= '<div class="form-field"><label for="lb_message">' . __('Messaggio', 'lean-bunker-commerce') . '</label><br/><textarea id="lb_message" name="lb_message" rows="3" class="regular-text"></textarea></div>';

                foreach ($user_values as $var => $val) {
                    $output .= '<input type="hidden" name="lb_config[' . esc_attr($var) . ']" value="' . esc_attr($val) . '" />';
                }
                $output .= '<input type="hidden" name="lb_total" value="' . esc_attr($calc_result) . '" />';
                $output .= '<input type="hidden" name="lb_product_id" value="' . esc_attr($post_id) . '" />';

                $output .= '<div class="submit"><button type="submit" name="lb_send_request" class="button button-primary">' . __('Invia richiesta', 'lean-bunker-commerce') . '</button></div>';
                $output .= '</form>';
                $output .= '</div>';
            }
        }

        $output .= '</div>';

        return $output;
    }

    public function shortcode_ordine_completato($atts) {
        if (!isset($_GET['lb_order'])) {
            return '<p class="lb-message">' . __('Nessun ordine trovato.', 'lean-bunker-commerce') . '</p>';
        }

        $order_id = intval($_GET['lb_order']);
        $order = get_post($order_id);

        if (!$order || $order->post_type !== $this->post_type_order) {
            return '<p class="lb-message">' . __('Ordine non valido.', 'lean-bunker-commerce') . '</p>';
        }

        $total = get_post_meta($order_id, 'lb_order_total', true);
        $billing = get_post_meta($order_id, 'lb_order_billing', true);
        $user_email = $billing['email'] ?? get_post_meta($order_id, 'lb_order_user_email', true);

        ob_start();
        ?>
        <div class="lb-order-completed" style="max-width: 800px; margin: 40px auto; padding: 40px; background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
            <div style="text-align: center; margin-bottom: 30px;">
                <div style="font-size: 4em; margin-bottom: 10px;">✅</div>
                <h1 style="color: #155724; margin-bottom: 10px;"><?php _e('Ordine Completato!', 'lean-bunker-commerce'); ?></h1>
                <p style="font-size: 1.1em; color: #666;"><?php _e('Grazie per il tuo acquisto.', 'lean-bunker-commerce'); ?></p>
            </div>

            <div style="background: #d4edda; padding: 20px; border-radius: 8px; border-left: 4px solid #28a745; margin-bottom: 30px;">
                <h3 style="margin-top: 0;"><?php _e('Riepilogo Ordine', 'lean-bunker-commerce'); ?></h3>
                <p><strong><?php _e('Numero ordine:', 'lean-bunker-commerce'); ?></strong> #<?php echo esc_html($order_id); ?></p>
                <p><strong><?php _e('Totale:', 'lean-bunker-commerce'); ?></strong> <span style="font-size: 1.3em; color: #0073aa; font-weight: bold;"><?php echo number_format(floatval($total), 2, ',', '.'); ?> €</span></p>
                <p><strong><?php _e('Email di conferma:', 'lean-bunker-commerce'); ?></strong> <?php echo esc_html($user_email); ?></p>
            </div>

            <div style="background: #fff3cd; padding: 20px; border-radius: 8px; border-left: 4px solid #ffc107; margin-bottom: 30px;">
                <h3><?php _e('Prossimi Passi', 'lean-bunker-commerce'); ?></h3>
                <ol style="line-height: 1.8;">
                    <li><?php _e('Controlla la tua email per la conferma d\'ordine con le coordinate bancarie', 'lean-bunker-commerce'); ?></li>
                    <li><?php _e('Effettua il bonifico entro 3 giorni', 'lean-bunker-commerce'); ?></li>
                    <li><?php _e('Conserva la ricevuta del pagamento', 'lean-bunker-commerce'); ?></li>
                    <li><?php _e('L\'ordine verrà processato entro 24-48 ore lavorative dal pagamento', 'lean-bunker-commerce'); ?></li>
                </ol>
            </div>

            <div style="display: flex; gap: 15px; justify-content: center;">
                <a href="<?php echo home_url(); ?>" class="button button-large" style="padding: 12px 30px; font-size: 1.1em;">
                    🏠 <?php _e('Torna alla Home', 'lean-bunker-commerce'); ?>
                </a>
                <a href="<?php echo home_url('/miei-ordini/'); ?>" class="button button-primary button-large" style="padding: 12px 30px; font-size: 1.1em;">
                    📦 <?php _e('I Miei Ordini', 'lean-bunker-commerce'); ?>
                </a>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    // ============================================
    // PAGINE ADMIN
    // ============================================

    public function add_admin_menu() {
        add_options_page(
            __('Lean Bunker Commerce', 'lean-bunker-commerce'),
            __('Lean Bunker Commerce', 'lean-bunker-commerce'),
            'manage_options',
            'lb-commerce-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('lb_commerce_settings', $this->option_categories, array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_categories'),
            'default' => array()
        ));

        register_setting('lb_commerce_settings', 'lb_bank_iban', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('lb_commerce_settings', 'lb_bank_intestatario', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));
    }

    public function sanitize_categories($input) {
        $output = array();
        
        if (!is_array($input)) {
            return $this->get_categories();
        }

        foreach ($input as $slug => $name) {
            $slug_clean = sanitize_title($slug);
            $name_clean = sanitize_text_field($name);
            
            if (!empty($slug_clean) && !empty($name_clean)) {
                $output[$slug_clean] = $name_clean;
            }
        }

        return $output;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lb_save_settings'])) {
            check_admin_referer('lb_commerce_settings');
            
            $iban = sanitize_text_field($_POST['lb_bank_iban'] ?? '');
            $intestatario = sanitize_text_field($_POST['lb_bank_intestatario'] ?? '');
            
            update_option('lb_bank_iban', $iban);
            update_option('lb_bank_intestatario', $intestatario);
            
            echo '<div class="notice notice-success"><p>' . __('Impostazioni salvate.', 'lean-bunker-commerce') . '</p></div>';
        }

        $categories = $this->get_categories();
        $iban = get_option('lb_bank_iban', '');
        $intestatario = get_option('lb_bank_intestatario', get_bloginfo('name'));
        ?>
        <div class="wrap">
            <h1>Lean Bunker Commerce v2.0.3</h1>

            <div class="notice notice-info">
                <p><strong><?php _e('Come funziona:', 'lean-bunker-commerce'); ?></strong></p>
                <ol>
                    <li><?php _e('Configura le categorie utenti e le coordinate bancarie qui sotto', 'lean-bunker-commerce'); ?></li>
                    <li><?php _e('Vai su <strong>Aspetto → Menu</strong> e crea menu per ogni categoria', 'lean-bunker-commerce'); ?></li>
                    <li><?php _e('Modifica un prodotto/post:', 'lean-bunker-commerce'); ?>
                        <ul style="margin-left: 20px; margin-top: 10px;">
                            <li><strong>Metabox lato:</strong> abilita carrello, imposta prezzo e stock</li>
                            <li><strong>Metabox centrale:</strong> configura campi personalizzati, formula di calcolo, e abilita modulo richiesta e/o aggiunta al carrello</li>
                        </ul>
                    </li>
                    <li><?php _e('Usa gli shortcode nelle pagine:', 'lean-bunker-commerce'); ?>
                        <ul style="margin-left: 20px; margin-top: 10px;">
                            <li><code>[menu_utente]</code> - Menu personalizzato per categoria</li>
                            <li><code>[aggiungi_al_carrello id="123"]</code> - Pulsante carrello semplice</li>
                            <li><code>[lean_calculator id="123"]</code> - Configuratore con calcolo dinamico</li>
                            <li><code>[carrello]</code> - Pagina carrello</li>
                            <li><code>[checkout]</code> - Pagina checkout con bonifico + dati spedizione/fatturazione</li>
                            <li><code>[miei_ordini]</code> - Lista ordini utente</li>
                            <li><code>[miei_acquisti]</code> - Prodotti acquistati</li>
                        </ul>
                    </li>
                </ol>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('lb_commerce_settings'); ?>

                <h2><?php _e('1. Coordinate Bancarie (Bonifico)', 'lean-bunker-commerce'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="lb_bank_intestatario"><?php _e('Intestatario', 'lean-bunker-commerce'); ?></label></th>
                        <td>
                            <input type="text" id="lb_bank_intestatario" name="lb_bank_intestatario" 
                                   value="<?php echo esc_attr($intestatario); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lb_bank_iban"><?php _e('IBAN', 'lean-bunker-commerce'); ?></label></th>
                        <td>
                            <input type="text" id="lb_bank_iban" name="lb_bank_iban" 
                                   value="<?php echo esc_attr($iban); ?>" class="regular-text" />
                            <p class="description"><?php _e('Coordinate per il pagamento tramite bonifico bancario.', 'lean-bunker-commerce'); ?></p>
                        </td>
                    </tr>
                </table>

                <hr style="margin: 40px 0;">

                <h2><?php _e('2. Categorie Utenti', 'lean-bunker-commerce'); ?></h2>
                <p class="description"><?php _e('Ogni categoria crea una location menu separata.', 'lean-bunker-commerce'); ?></p>
                
                <table class="form-table">
                    <?php
                    $i = 0;
                    foreach ($categories as $slug => $name):
                        $i++;
                    ?>
                        <tr>
                            <th scope="row">
                                <label><?php _e('Categoria', 'lean-bunker-commerce'); ?> <?php echo $i; ?></label>
                            </th>
                            <td>
                                <input type="text" 
                                       name="<?php echo $this->option_categories; ?>[<?php echo esc_attr($slug); ?>]" 
                                       value="<?php echo esc_attr($name); ?>" 
                                       class="regular-text"
                                       placeholder="<?php echo esc_attr($slug); ?>">
                                <p class="description">
                                    <code><?php echo esc_html($slug); ?></code> → 
                                    <strong><?php echo esc_html($name); ?></strong>
                                </p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <?php submit_button('Salva impostazioni', 'primary', 'lb_save_settings'); ?>
            </form>

            <hr style="margin: 40px 0;">

            <h2>📦 <?php _e('Esempio Pratico: Prodotto Configurabile', 'lean-bunker-commerce'); ?></h2>
            <div style="background: #f0f9ff; padding: 20px; border: 1px solid #2271b1; border-radius: 4px;">
                <p><strong>Prodotto "Pavimenti Personalizzati"</strong></p>
                <ul style="margin-left: 20px;">
                    <li>✏️ <strong>Metabox lato:</strong> spunta "Aggiungi al carrello", prezzo base €50/mq</li>
                    <li>🎨 <strong>Metabox centrale:</strong>
                        <ul style="margin-left: 20px;">
                            <li>Campo "Tipo pavimento" (select): Legno|80, Ceramica|45, Marmo|120</li>
                            <li>Campo "Metri quadrati" (number)</li>
                            <li>Formula: <code>{tipo_pavimento} * {mq}</code></li>
                            <li>✓ Abilita aggiunta al carrello dopo il calcolo</li>
                        </ul>
                    </li>
                    <li>📄 <strong>Pagina prodotto:</strong> inserisci <code>[lean_calculator]</code></li>
                    <li>✅ <strong>Cliente:</strong> calcola prezzo → aggiunge al carrello → checkout con dati spedizione/fatturazione → ordine completato + email di conferma</li>
                </ul>
            </div>
        </div>
        <?php
    }
}

new Lean_Bunker_Commerce();

// ============================================
// CSS DI BASE
// ============================================
add_action('wp_head', 'lb_commerce_inline_css');
function lb_commerce_inline_css() {
    ?>
    <style>
        .lb-user-menu-container {
            margin: 20px 0;
        }
        .lb-user-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .lb-user-menu li {
            margin: 5px 0;
        }
        .lb-user-menu a {
            display: block;
            padding: 10px 15px;
            background: #f5f5f5;
            text-decoration: none;
            color: #333;
            border-radius: 4px;
            transition: background 0.2s;
        }
        .lb-user-menu a:hover {
            background: #e0e0e0;
        }
        .lb-message {
            padding: 15px;
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            color: #856404;
            margin: 20px 0;
        }
        .lb-notice {
            padding: 12px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .lb-notice-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .lb-notice-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        .lb-notice-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
        }
        .lb-notice-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .lb-cart-table th,
        .lb-cart-table td {
            border: 1px solid #ddd;
        }
        .lb-orders-table th,
        .lb-orders-table td {
            border: 1px solid #ddd;
        }
        .lb-add-to-cart-form {
            display: inline;
        }
        .lb-calculator {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .lb-calculator .form-field {
            margin-bottom: 15px;
        }
        .lb-calculator label {
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
        }
        .lb-result {
            margin-top: 20px;
            padding: 15px;
            background: #e8f5e9;
            border-left: 4px solid #4caf50;
            font-size: 1.2em;
            font-weight: bold;
        }
        .lb-request-form {
            margin-top: 20px;
            padding: 20px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        .lb-add-to-cart-button {
            margin-top: 20px;
            padding: 10px 0;
        }
        .widefat th,
        .widefat td {
            padding: 10px;
        }
    </style>
    <?php
}