<?php
/**
 * Plugin Name: Lean Bunker Semantic Linker
 * Description: Topic cluster automatici con protezioni SEO avanzate. Admin UI completa, Safe Mode, filtri semantici.
 * Version: 3.1.0
 * Author: Riccardo Bastillo
 * License: GPL-3.0+
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Text Domain: lb-semantic-linker
 */

if (!defined('ABSPATH')) exit;

// ========================
// 1. ADMIN MENU
// ========================
add_action('admin_menu', 'lb_semantic_admin_menu');
function lb_semantic_admin_menu() {
    add_menu_page(
        __('Semantic Linker', 'lb-semantic-linker'),
        __('Semantic Linker', 'lb-semantic-linker'),
        'manage_options',
        'lb-semantic-linker',
        'lb_semantic_admin_dashboard',
        'dashicons-networking',
        81
    );
    
    add_submenu_page(
        'lb-semantic-linker',
        __('Settings', 'lb-semantic-linker'),
        __('Settings', 'lb-semantic-linker'),
        'manage_options',
        'lb-semantic-linker',
        'lb_semantic_admin_dashboard'
    );
    
    add_submenu_page(
        'lb-semantic-linker',
        __('How to Display', 'lb-semantic-linker'),
        __('How to Display', 'lb-semantic-linker'),
        'manage_options',
        'lb-semantic-linker-display',
        'lb_semantic_admin_display_guide'
    );
    
    add_submenu_page(
        'lb-semantic-linker',
        __('Test & Debug', 'lb-semantic-linker'),
        __('Test & Debug', 'lb-semantic-linker'),
        'manage_options',
        'lb-semantic-linker-debug',
        'lb_semantic_admin_debug'
    );
    
    add_submenu_page(
        'lb-semantic-linker',
        __('Documentation', 'lb-semantic-linker'),
        __('Documentation', 'lb-semantic-linker'),
        'manage_options',
        'lb-semantic-linker-docs',
        'lb_semantic_admin_docs'
    );
}

// ========================
// 2. ADMIN DASHBOARD (Settings)
// ========================
function lb_semantic_admin_dashboard() {
    if (isset($_POST['lb_semantic_save_settings'])) {
        check_admin_referer('lb_semantic_settings_nonce');
        
        $settings = [
            'auto_insert' => isset($_POST['auto_insert']) ? '1' : '0',
            'auto_position' => sanitize_text_field($_POST['auto_position'] ?? 'after_first'),
            'auto_max_links' => (int)($_POST['auto_max_links'] ?? 5),
            'auto_style' => sanitize_text_field($_POST['auto_style'] ?? 'default'),
            'include_post_types' => isset($_POST['include_post_types']) ? array_map('sanitize_text_field', $_POST['include_post_types']) : ['post'],
            'max_age_days' => (int)($_POST['max_age_days'] ?? 90),
            'enable_mobile' => isset($_POST['enable_mobile']) ? '1' : '0',
            'exclude_categories' => isset($_POST['exclude_categories']) ? array_map('intval', $_POST['exclude_categories']) : [],
            'safe_mode' => isset($_POST['safe_mode']) ? '1' : '0',
            'context_filter' => isset($_POST['context_filter']) ? '1' : '0',
            'custom_css' => sanitize_textarea_field($_POST['custom_css'] ?? '')
        ];
        
        update_option('lb_semantic_settings', $settings);
        
        // Pulisci cache se necessario
        if (isset($_POST['clear_cache'])) {
            lb_semantic_clear_all_clusters();
        }
        
        echo '<div class="notice notice-success"><p>' . __('✅ Settings saved successfully!', 'lb-semantic-linker') . '</p></div>';
    }
    
    $settings = get_option('lb_semantic_settings', lb_semantic_get_default_settings());
    
    // Warning se Safe Mode disattivato
    if ($settings['safe_mode'] !== '1') {
        echo '<div class="notice notice-warning"><p><strong>⚠️ ' . __('Safe Mode is disabled!', 'lb-semantic-linker') . '</strong><br>' . __('The plugin may create semantically weak links. Recommended only for sites with very precise taxonomies.', 'lb-semantic-linker') . '</p></div>';
    }
    
    ?>
    <div class="wrap">
        <h1>
            <span class="dashicons dashicons-networking" style="font-size: 28px; vertical-align: middle;"></span>
            <?php _e('Lean Bunker Semantic Linker', 'lb-semantic-linker'); ?>
        </h1>
        
        <div id="lb-semantic-tabs">
            <nav class="nav-tab-wrapper" style="border-bottom: 1px solid #ccc; padding-bottom: 0;">
                <a href="#general" class="nav-tab nav-tab-active"><?php _e('General Settings', 'lb-semantic-linker'); ?></a>
                <a href="#display" class="nav-tab"><?php _e('Display Options', 'lb-semantic-linker'); ?></a>
                <a href="#semantic" class="nav-tab"><?php _e('Semantic Safety', 'lb-semantic-linker'); ?></a>
                <a href="#advanced" class="nav-tab"><?php _e('Advanced', 'lb-semantic-linker'); ?></a>
                <a href="#cache" class="nav-tab"><?php _e('Cache & Performance', 'lb-semantic-linker'); ?></a>
            </nav>
            
            <div id="general" class="lb-semantic-tab-content" style="display: block;">
                <form method="post">
                    <?php wp_nonce_field('lb_semantic_settings_nonce'); ?>
                    
                    <h2><?php _e('Automatic Insertion', 'lb-semantic-linker'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Enable automatic insertion', 'lb-semantic-linker'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="auto_insert" value="1" <?php checked($settings['auto_insert'], '1'); ?>>
                                    <?php _e('Insert related articles automatically after the first paragraph', 'lb-semantic-linker'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('If disabled, you must use the shortcode [lb_related_articles] manually.', 'lb-semantic-linker'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php _e('Position', 'lb-semantic-linker'); ?></th>
                            <td>
                                <label>
                                    <input type="radio" name="auto_position" value="after_first" <?php checked($settings['auto_position'], 'after_first'); ?>>
                                    <?php _e('After first paragraph', 'lb-semantic-linker'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="auto_position" value="end" <?php checked($settings['auto_position'], 'end'); ?>>
                                    <?php _e('At the end of content', 'lb-semantic-linker'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="auto_position" value="both" <?php checked($settings['auto_position'], 'both'); ?>>
                                    <?php _e('Both positions', 'lb-semantic-linker'); ?>
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php _e('Number of links', 'lb-semantic-linker'); ?></th>
                            <td>
                                <input type="number" name="auto_max_links" value="<?php echo esc_attr($settings['auto_max_links']); ?>" min="1" max="10" style="width: 80px;">
                                <p class="description"><?php _e('Recommended: 3-5 links per article', 'lb-semantic-linker'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php _e('Default style', 'lb-semantic-linker'); ?></th>
                            <td>
                                <label>
                                    <input type="radio" name="auto_style" value="default" <?php checked($settings['auto_style'], 'default'); ?>>
                                    <strong><?php _e('Default', 'lb-semantic-linker'); ?></strong> - <?php _e('Highlighted box with left border', 'lb-semantic-linker'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="auto_style" value="compact" <?php checked($settings['auto_style'], 'compact'); ?>>
                                    <strong><?php _e('Compact', 'lb-semantic-linker'); ?></strong> - <?php _e('Sidebar-friendly compact style', 'lb-semantic-linker'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="auto_style" value="minimal" <?php checked($settings['auto_style'], 'minimal'); ?>>
                                    <strong><?php _e('Minimal', 'lb-semantic-linker'); ?></strong> - <?php _e('Simple list without box', 'lb-semantic-linker'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(__('Save Settings', 'lb-semantic-linker'), 'primary', 'lb_semantic_save_settings'); ?>
                </form>
            </div>
            
            <div id="display" class="lb-semantic-tab-content" style="display: none;">
                <h2><?php _e('Post Types & Content', 'lb-semantic-linker'); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('lb_semantic_settings_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Include post types', 'lb-semantic-linker'); ?></th>
                            <td>
                                <?php
                                $all_post_types = get_post_types(['public' => true], 'objects');
                                foreach ($all_post_types as $post_type => $obj) {
                                    $checked = in_array($post_type, $settings['include_post_types']);
                                    ?>
                                    <label style="display: block; margin-bottom: 5px;">
                                        <input type="checkbox" name="include_post_types[]" value="<?php echo esc_attr($post_type); ?>" <?php checked($checked); ?>>
                                        <?php echo esc_html($obj->label); ?> (<?php echo esc_html($post_type); ?>)
                                    </label>
                                    <?php
                                }
                                ?>
                                <p class="description"><?php _e('Select which post types should show related articles', 'lb-semantic-linker'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php _e('Exclude categories', 'lb-semantic-linker'); ?></th>
                            <td>
                                <?php
                                $categories = get_categories(['hide_empty' => false, 'number' => 50]);
                                if (!empty($categories)) {
                                    foreach ($categories as $cat) {
                                        $checked = in_array($cat->term_id, $settings['exclude_categories']);
                                        ?>
                                        <label style="display: block; margin-bottom: 3px;">
                                            <input type="checkbox" name="exclude_categories[]" value="<?php echo esc_attr($cat->term_id); ?>" <?php checked($checked); ?>>
                                            <?php echo esc_html($cat->name); ?>
                                        </label>
                                        <?php
                                    }
                                } else {
                                    echo '<p class="description">' . __('No categories found', 'lb-semantic-linker') . '</p>';
                                }
                                ?>
                                <p class="description"><?php _e('Related articles will not be shown on posts in these categories', 'lb-semantic-linker'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(__('Save Settings', 'lb-semantic-linker'), 'primary', 'lb_semantic_save_settings'); ?>
                </form>
            </div>
            
            <div id="semantic" class="lb-semantic-tab-content" style="display: none;">
                <h2><?php _e('🛡️ Semantic Safety Settings', 'lb-semantic-linker'); ?></h2>
                <p style="background: #e8f5e9; padding: 15px; border-left: 4px solid #4caf50; border-radius: 4px;">
                    <?php _e('<strong>Safe Mode</strong> ensures only high-quality, semantically relevant links are shown. Recommended for all sites.', 'lb-semantic-linker'); ?>
                </p>
                
                <form method="post">
                    <?php wp_nonce_field('lb_semantic_settings_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <span class="dashicons dashicons-shield" style="color: #4caf50; font-size: 20px; vertical-align: middle;"></span>
                                <?php _e('Safe Mode', 'lb-semantic-linker'); ?>
                            </th>
                            <td>
                                <label>
                                    <input type="checkbox" name="safe_mode" value="1" <?php checked($settings['safe_mode'], '1'); ?> id="safe_mode_toggle">
                                    <strong><?php _e('Enable Safe Mode (recommended)', 'lb-semantic-linker'); ?></strong>
                                </label>
                                <p class="description">
                                    <?php _e('Only shows links with high semantic relevance. May show fewer links but ensures quality and SEO safety.', 'lb-semantic-linker'); ?>
                                </p>
                                
                                <div style="margin-top: 15px; padding: 15px; background: #e8f5e9; border-radius: 4px; display: <?php echo ($settings['safe_mode'] === '1') ? 'block' : 'none'; ?>;" id="safe-mode-details">
                                    <p><strong><?php _e('Safe Mode applies:', 'lb-semantic-linker'); ?></strong></p>
                                    <ul>
                                        <li><?php _e('Minimum relevance score: 12/20', 'lb-semantic-linker'); ?></li>
                                        <li><?php _e('Maximum 1 link per semantic entity', 'lb-semantic-linker'); ?></li>
                                        <li><?php _e('No cross-context linking (e.g., politics → sports)', 'lb-semantic-linker'); ?></li>
                                        <li><?php _e('Articles older than 180 days excluded from news linking', 'lb-semantic-linker'); ?></li>
                                        <li><?php _e('At least 2 high-quality links required (otherwise shows nothing)', 'lb-semantic-linker'); ?></li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php _e('Context Filter', 'lb-semantic-linker'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="context_filter" value="1" <?php checked($settings['context_filter'], '1'); ?>>
                                    <strong><?php _e('Enable cross-context filtering', 'lb-semantic-linker'); ?></strong>
                                </label>
                                <p class="description">
                                    <?php _e('Prevents linking between unrelated topics (e.g., politics → sports, finance → culture). Automatically detects context from categories.', 'lb-semantic-linker'); ?>
                                </p>
                                <p style="margin-top: 10px; font-size: 13px; color: #666;">
                                    <strong><?php _e('Note:', 'lb-semantic-linker'); ?></strong> <?php _e('Works best if your site has clear category structures like "Politics", "Sports", "Finance", etc.', 'lb-semantic-linker'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(__('Save Settings', 'lb-semantic-linker'), 'primary', 'lb_semantic_save_settings'); ?>
                </form>
            </div>
            
            <div id="advanced" class="lb-semantic-tab-content" style="display: none;">
                <h2><?php _e('Advanced Settings', 'lb-semantic-linker'); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('lb_semantic_settings_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Maximum article age', 'lb-semantic-linker'); ?></th>
                            <td>
                                <input type="number" name="max_age_days" value="<?php echo esc_attr($settings['max_age_days']); ?>" min="0" max="3650" style="width: 100px;">
                                <p class="description">
                                    <?php _e('Articles older than this will not be suggested (0 = no limit). Recommended: 90 days for news sites, 365 for blogs.', 'lb-semantic-linker'); ?>
                                </p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php _e('Mobile devices', 'lb-semantic-linker'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_mobile" value="1" <?php checked($settings['enable_mobile'], '1'); ?>>
                                    <?php _e('Show related articles on mobile devices', 'lb-semantic-linker'); ?>
                                </label>
                                <p class="description"><?php _e('Disable to improve mobile performance or if you have mobile-specific themes', 'lb-semantic-linker'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row"><?php _e('Custom CSS', 'lb-semantic-linker'); ?></th>
                            <td>
                                <textarea name="custom_css" rows="8" cols="60" style="width: 100%; font-family: monospace;" placeholder="/* Add your custom CSS here */"><?php echo esc_textarea($settings['custom_css']); ?></textarea>
                                <p class="description">
                                    <?php _e('Add custom CSS to override default styles. Example:', 'lb-semantic-linker'); ?><br>
                                    <code>.lb-semantic-links { background: #fff; border-left: 4px solid #0073aa; }</code>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(__('Save Settings', 'lb-semantic-linker'), 'primary', 'lb_semantic_save_settings'); ?>
                </form>
            </div>
            
            <div id="cache" class="lb-semantic-tab-content" style="display: none;">
                <h2><?php _e('Cache Management', 'lb-semantic-linker'); ?></h2>
                
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0;"><?php _e('📊 Cache Status', 'lb-semantic-linker'); ?></h3>
                    <?php
                    global $wpdb;
                    $transient_count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                        '_transient_lb_cluster_%'
                    ));
                    ?>
                    <p><strong><?php _e('Active clusters:', 'lb-semantic-linker'); ?></strong> <?php echo number_format($transient_count); ?></p>
                    <p><strong><?php _e('Cache duration:', 'lb-semantic-linker'); ?></strong> 6 hours (transient) + persistent backup</p>
                    <p><strong><?php _e('Last cron run:', 'lb-semantic-linker'); ?></strong> 
                        <?php
                        $last_cron = get_option('lb_semantic_last_cron');
                        if ($last_cron) {
                            echo human_time_diff($last_cron, current_time('timestamp')) . ' ago';
                        } else {
                            _e('Never (first run pending)', 'lb-semantic-linker');
                        }
                        ?>
                    </p>
                </div>
                
                <form method="post">
                    <?php wp_nonce_field('lb_semantic_settings_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Clear all cache', 'lb-semantic-linker'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="clear_cache" value="1">
                                    <strong><?php _e('Clear all semantic clusters cache', 'lb-semantic-linker'); ?></strong>
                                </label>
                                <p class="description">
                                    <?php _e('This will delete all cached clusters. They will be rebuilt automatically via CRON or on next post publish.', 'lb-semantic-linker'); ?>
                                </p>
                                <p style="color: #d63638; font-weight: bold;">
                                    <?php _e('⚠️ Warning: This may cause slower page loads until cache is rebuilt', 'lb-semantic-linker'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(__('Save & Clear Cache', 'lb-semantic-linker'), 'secondary', 'lb_semantic_save_settings'); ?>
                </form>
                
                <hr style="margin: 40px 0;">
                
                <h3><?php _e('🔧 Manual Cluster Rebuild', 'lb-semantic-linker'); ?></h3>
                <p><?php _e('Force rebuild all clusters now (may take several minutes on large sites):', 'lb-semantic-linker'); ?></p>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('lb_semantic_rebuild_nonce'); ?>
                    <input type="hidden" name="action" value="lb_semantic_rebuild_clusters">
                    <?php submit_button(__('Rebuild All Clusters Now', 'lb-semantic-linker'), 'secondary'); ?>
                </form>
                
                <hr style="margin: 40px 0;">
                
                <h3><?php _e('📊 Cron Schedule', 'lb-semantic-linker'); ?></h3>
                <?php
                $cron_timestamp = wp_next_scheduled('lb_semantic_build_clusters');
                if ($cron_timestamp) {
                    $next_run = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $cron_timestamp);
                    echo '<p><strong>' . __('Next automatic rebuild:', 'lb-semantic-linker') . '</strong> ' . $next_run . '</p>';
                    echo '<p><strong>' . __('Frequency:', 'lb-semantic-linker') . '</strong> Every 6 hours</p>';
                } else {
                    echo '<p style="color: #d63638;"><strong>' . __('⚠️ CRON not scheduled!', 'lb-semantic-linker') . '</strong></p>';
                    echo '<p>' . __('Try deactivating and reactivating the plugin.', 'lb-semantic-linker') . '</p>';
                }
                ?>
            </div>
        </div>
        
        <div style="margin-top: 40px; padding: 20px; background: #f0f6fc; border-left: 4px solid #0073aa; border-radius: 4px;">
            <h3 style="margin-top: 0;"><?php _e('📚 Need Help?', 'lb-semantic-linker'); ?></h3>
            <p>
                <a href="<?php echo admin_url('admin.php?page=lb-semantic-linker-display'); ?>" class="button">
                    <?php _e('📖 How to Display Related Articles', 'lb-semantic-linker'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=lb-semantic-linker-debug'); ?>" class="button">
                    <?php _e('🔍 Test & Debug', 'lb-semantic-linker'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=lb-semantic-linker-docs'); ?>" class="button">
                    <?php _e('📚 Full Documentation', 'lb-semantic-linker'); ?>
                </a>
            </p>
        </div>
    </div>
    
    <style>
    #lb-semantic-tabs .nav-tab {
        margin-right: 5px;
        padding: 10px 15px;
    }
    #lb-semantic-tabs .nav-tab-active {
        background: #f0f6fc;
        border-bottom-color: #f0f6fc;
    }
    #lb-semantic-tabs .lb-semantic-tab-content {
        padding: 20px 0;
        border-top: 1px solid #ccc;
    }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        $('#lb-semantic-tabs .nav-tab').on('click', function(e) {
            e.preventDefault();
            var target = $(this).attr('href');
            
            $('.nav-tab').removeClass('nav-tab-active');
            $('.lb-semantic-tab-content').hide();
            
            $(this).addClass('nav-tab-active');
            $(target).show();
        });
        
        $('#safe_mode_toggle').on('change', function() {
            $('#safe-mode-details').toggle(this.checked);
        });
    });
    </script>
    <?php
}

// ========================
// 3. DISPLAY GUIDE PAGE
// ========================
function lb_semantic_admin_display_guide() {
    ?>
    <div class="wrap">
        <h1>
            <span class="dashicons dashicons-admin-appearance" style="font-size: 28px; vertical-align: middle;"></span>
            <?php _e('How to Display Related Articles', 'lb-semantic-linker'); ?>
        </h1>
        
        <div class="lb-semantic-guide-container" style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 30px;">
            
            <!-- Method 1: Automatic -->
            <div style="background: #fff; padding: 25px; border: 1px solid #ddd; border-radius: 8px;">
                <h2 style="color: #0073aa; margin-top: 0;">
                    <span class="dashicons dashicons-yes-alt" style="font-size: 24px; vertical-align: middle;"></span>
                    <?php _e('Method 1: Automatic Insertion', 'lb-semantic-linker'); ?>
                </h2>
                
                <p style="font-size: 16px; line-height: 1.6;">
                    <?php _e('The easiest way! Related articles are automatically inserted after the first paragraph of each post.', 'lb-semantic-linker'); ?>
                </p>
                
                <h3 style="margin-top: 20px;"><?php _e('How to enable:', 'lb-semantic-linker'); ?></h3>
                <ol style="line-height: 1.8;">
                    <li><?php _e('Go to <strong>Semantic Linker → Settings</strong>', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Check <strong>"Enable automatic insertion"</strong>', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Choose position, style, and number of links', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Click <strong>"Save Settings"</strong>', 'lb-semantic-linker'); ?></li>
                </ol>
                
                <div style="background: #f8f9fa; padding: 15px; border-left: 4px solid #0073aa; margin-top: 20px;">
                    <h4 style="margin-top: 0;"><?php _e('💡 Pro Tips:', 'lb-semantic-linker'); ?></h4>
                    <ul style="line-height: 1.8;">
                        <li><?php _e('<strong>Position:</strong> "After first paragraph" gives best engagement', 'lb-semantic-linker'); ?></li>
                        <li><?php _e('<strong>Number of links:</strong> 3-5 is optimal for UX and SEO', 'lb-semantic-linker'); ?></li>
                        <li><?php _e('<strong>Style:</strong> "Default" works best for most themes', 'lb-semantic-linker'); ?></li>
                    </ul>
                </div>
                
                <div style="background: #fff8e1; padding: 15px; border-left: 4px solid #ffb300; margin-top: 20px;">
                    <h4 style="margin-top: 0; color: #5d4037;"><?php _e('⚠️ Important:', 'lb-semantic-linker'); ?></h4>
                    <p style="margin: 0;">
                        <?php _e('If you use the shortcode <code>[lb_related_articles]</code> in a post, automatic insertion will be disabled for that post to avoid duplicates.', 'lb-semantic-linker'); ?>
                    </p>
                </div>
            </div>
            
            <!-- Method 2: Shortcode -->
            <div style="background: #fff; padding: 25px; border: 1px solid #ddd; border-radius: 8px;">
                <h2 style="color: #0073aa; margin-top: 0;">
                    <span class="dashicons dashicons-editor-code" style="font-size: 24px; vertical-align: middle;"></span>
                    <?php _e('Method 2: Shortcode', 'lb-semantic-linker'); ?>
                </h2>
                
                <p style="font-size: 16px; line-height: 1.6;">
                    <?php _e('Use the shortcode to display related articles exactly where you want in your content.', 'lb-semantic-linker'); ?>
                </p>
                
                <h3 style="margin-top: 20px;"><?php _e('Basic usage:', 'lb-semantic-linker'); ?></h3>
                <div style="background: #23282d; color: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; margin: 15px 0; overflow-x: auto;">
                    <code style="color: #f8f9fa;">[lb_related_articles]</code>
                </div>
                
                <h3 style="margin-top: 20px;"><?php _e('Examples:', 'lb-semantic-linker'); ?></h3>
                <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px; vertical-align: top;"><strong><?php _e('3 articles, compact style:', 'lb-semantic-linker'); ?></strong></td>
                        <td style="padding: 10px; vertical-align: top;">
                            <code style="background: #f8f9fa; padding: 5px; border-radius: 3px;">[lb_related_articles count="3" style="compact"]</code>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px; vertical-align: top;"><strong><?php _e('Custom title:', 'lb-semantic-linker'); ?></strong></td>
                        <td style="padding: 10px; vertical-align: top;">
                            <code style="background: #f8f9fa; padding: 5px; border-radius: 3px;">[lb_related_articles title="Continue Reading"]</code>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px; vertical-align: top;"><strong><?php _e('Minimal style:', 'lb-semantic-linker'); ?></strong></td>
                        <td style="padding: 10px; vertical-align: top;">
                            <code style="background: #f8f9fa; padding: 5px; border-radius: 3px;">[lb_related_articles style="minimal"]</code>
                        </td>
                    </tr>
                </table>
                
                <h3 style="margin-top: 20px;"><?php _e('All parameters:', 'lb-semantic-linker'); ?></h3>
                <table style="width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 14px;">
                    <thead>
                        <tr style="background: #f8f9fa;">
                            <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Parameter</th>
                            <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Values</th>
                            <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;">Default</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 10px; vertical-align: top;"><code>count</code></td>
                            <td style="padding: 10px; vertical-align: top;">1-10</td>
                            <td style="padding: 10px; vertical-align: top;">5</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 10px; vertical-align: top;"><code>title</code></td>
                            <td style="padding: 10px; vertical-align: top;">Any text</td>
                            <td style="padding: 10px; vertical-align: top;">"📖 Articoli correlati"</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 10px; vertical-align: top;"><code>style</code></td>
                            <td style="padding: 10px; vertical-align: top;"><code>default</code>, <code>compact</code>, <code>minimal</code></td>
                            <td style="padding: 10px; vertical-align: top;"><code>default</code></td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="background: #e8f5e9; padding: 15px; border-left: 4px solid #4caf50; margin-top: 20px;">
                    <h4 style="margin-top: 0; color: #2e7d32;"><?php _e('✅ Where to use:', 'lb-semantic-linker'); ?></h4>
                    <ul style="line-height: 1.8; margin: 0;">
                        <li><?php _e('Classic Editor: Paste in content area', 'lb-semantic-linker'); ?></li>
                        <li><?php _e('Gutenberg: Use "Shortcode" block', 'lb-semantic-linker'); ?></li>
                        <li><?php _e('Page Builders: Use shortcode widget/element', 'lb-semantic-linker'); ?></li>
                        <li><?php _e('Theme files: <code>&lt;?php echo do_shortcode(\'[lb_related_articles]\'); ?&gt;</code>', 'lb-semantic-linker'); ?></li>
                    </ul>
                </div>
            </div>
            
            <!-- Method 3: Widget -->
            <div style="background: #fff; padding: 25px; border: 1px solid #ddd; border-radius: 8px; grid-column: span 2;">
                <h2 style="color: #0073aa; margin-top: 0;">
                    <span class="dashicons dashicons-admin-generic" style="font-size: 24px; vertical-align: middle;"></span>
                    <?php _e('Method 3: Widget', 'lb-semantic-linker'); ?>
                </h2>
                
                <p style="font-size: 16px; line-height: 1.6;">
                    <?php _e('Add related articles to your sidebar, footer, or any widget area.', 'lb-semantic-linker'); ?>
                </p>
                
                <h3 style="margin-top: 20px;"><?php _e('How to use:', 'lb-semantic-linker'); ?></h3>
                <ol style="line-height: 1.8;">
                    <li><?php _e('Go to <strong>Appearance → Widgets</strong>', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Find <strong>"Articoli Correlati"</strong> widget', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Drag it to your desired widget area (sidebar, footer, etc.)', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Configure title, number of articles, and style', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Click <strong>"Save"</strong>', 'lb-semantic-linker'); ?></li>
                </ol>
                
                <div style="background: #f8f9fa; padding: 15px; border-left: 4px solid #0073aa; margin-top: 20px;">
                    <h4 style="margin-top: 0;"><?php _e('🎨 Widget Configuration:', 'lb-semantic-linker'); ?></h4>
                    <ul style="line-height: 1.8; margin: 0;">
                        <li><strong><?php _e('Title:', 'lb-semantic-linker'); ?></strong> <?php _e('Custom title for the widget (optional)', 'lb-semantic-linker'); ?></li>
                        <li><strong><?php _e('Number of articles:', 'lb-semantic-linker'); ?></strong> <?php _e('How many related articles to show (1-10)', 'lb-semantic-linker'); ?></li>
                        <li><strong><?php _e('Style:', 'lb-semantic-linker'); ?></strong> 
                            <ul style="margin: 5px 0 0 20px;">
                                <li><code><?php _e('Default', 'lb-semantic-linker'); ?></code>: <?php _e('Highlighted box (best for content areas)', 'lb-semantic-linker'); ?></li>
                                <li><code><?php _e('Compact', 'lb-semantic-linker'); ?></code>: <?php _e('Compact style (best for sidebars)', 'lb-semantic-linker'); ?></li>
                                <li><code><?php _e('Minimal', 'lb-semantic-linker'); ?></code>: <?php _e('Simple list (minimalist design)', 'lb-semantic-linker'); ?></li>
                            </ul>
                        </li>
                    </ul>
                </div>
                
                <div style="background: #e3f2fd; padding: 15px; border-left: 4px solid #2196f3; margin-top: 20px;">
                    <h4 style="margin-top: 0; color: #1565c0;"><?php _e('💡 Best Practices:', 'lb-semantic-linker'); ?></h4>
                    <ul style="line-height: 1.8; margin: 0;">
                        <li><?php _e('<strong>Sidebar:</strong> Use "Compact" style with 3-4 articles', 'lb-semantic-linker'); ?></li>
                        <li><?php _e('<strong>Footer:</strong> Use "Default" style with 5 articles', 'lb-semantic-linker'); ?></li>
                        <li><?php _e('<strong>Below content:</strong> Use widget area below content with "Default" style', 'lb-semantic-linker'); ?></li>
                    </ul>
                </div>
            </div>
            
            <!-- Disable on Single Post -->
            <div style="background: #fff; padding: 25px; border: 1px solid #ddd; border-radius: 8px; grid-column: span 2;">
                <h2 style="color: #d32f2f; margin-top: 0;">
                    <span class="dashicons dashicons-no-alt" style="font-size: 24px; vertical-align: middle;"></span>
                    <?php _e('How to Disable on Specific Posts', 'lb-semantic-linker'); ?>
                </h2>
                
                <p style="font-size: 16px; line-height: 1.6;">
                    <?php _e('You can disable related articles on specific posts or pages.', 'lb-semantic-linker'); ?>
                </p>
                
                <h3 style="margin-top: 20px;"><?php _e('Method 1: Using Custom Field', 'lb-semantic-linker'); ?></h3>
                <ol style="line-height: 1.8;">
                    <li><?php _e('Edit the post where you want to disable related articles', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Enable "Custom Fields" in Screen Options (top right)', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Add custom field:', 'lb-semantic-linker'); ?></li>
                </ol>
                
                <table style="width: 100%; border-collapse: collapse; margin: 15px 0; background: #f8f9fa;">
                    <tr>
                        <td style="padding: 10px; border: 1px solid #ddd;"><strong>Name:</strong></td>
                        <td style="padding: 10px; border: 1px solid #ddd;">lb_semantic_disable</td>
                    </tr>
                    <tr>
                        <td style="padding: 10px; border: 1px solid #ddd;"><strong>Value:</strong></td>
                        <td style="padding: 10px; border: 1px solid #ddd;">1</td>
                    </tr>
                </table>
                
                <h3 style="margin-top: 20px;"><?php _e('Method 2: Using Filter (for developers)', 'lb-semantic-linker'); ?></h3>
                <p><?php _e('Add this to your theme\'s functions.php or custom plugin:', 'lb-semantic-linker'); ?></p>
                <div style="background: #23282d; color: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; margin: 15px 0; overflow-x: auto;">
                    <code style="color: #f8f9fa;">
// Disable on specific post IDs<br>
add_filter('lb_semantic_skip_post_types', function($types) {<br>
&nbsp;&nbsp;&nbsp;&nbsp;if (is_singular() && in_array(get_the_ID(), [123, 456, 789])) {<br>
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;return ['post', 'page', 'any']; // All post types<br>
&nbsp;&nbsp;&nbsp;&nbsp;}<br>
&nbsp;&nbsp;&nbsp;&nbsp;return $types;<br>
});
                    </code>
                </div>
                
                <h3 style="margin-top: 20px;"><?php _e('Method 3: Exclude Categories', 'lb-semantic-linker'); ?></h3>
                <p>
                    <?php _e('Go to <strong>Semantic Linker → Settings → Display Options</strong> and select categories where you don\'t want to show related articles.', 'lb-semantic-linker'); ?>
                </p>
            </div>
            
        </div>
        
        <div style="margin-top: 40px; padding: 20px; background: #e8f5e9; border-left: 4px solid #4caf50; border-radius: 4px;">
            <h3 style="margin-top: 0; color: #2e7d32;">
                <span class="dashicons dashicons-lightbulb" style="font-size: 24px; vertical-align: middle;"></span>
                <?php _e('Quick Reference', 'lb-semantic-linker'); ?>
            </h3>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 15px;">
                <div style="background: #fff; padding: 15px; border-radius: 4px;">
                    <h4 style="margin-top: 0;"><?php _e('Automatic', 'lb-semantic-linker'); ?></h4>
                    <p><?php _e('Settings → Enable automatic insertion', 'lb-semantic-linker'); ?></p>
                </div>
                
                <div style="background: #fff; padding: 15px; border-radius: 4px;">
                    <h4 style="margin-top: 0;"><?php _e('Shortcode', 'lb-semantic-linker'); ?></h4>
                    <p><code>[lb_related_articles]</code></p>
                </div>
                
                <div style="background: #fff; padding: 15px; border-radius: 4px;">
                    <h4 style="margin-top: 0;"><?php _e('Widget', 'lb-semantic-linker'); ?></h4>
                    <p><?php _e('Appearance → Widgets → "Articoli Correlati"', 'lb-semantic-linker'); ?></p>
                </div>
                
                <div style="background: #fff; padding: 15px; border-radius: 4px;">
                    <h4 style="margin-top: 0;"><?php _e('Disable', 'lb-semantic-linker'); ?></h4>
                    <p><?php _e('Custom field: lb_semantic_disable = 1', 'lb-semantic-linker'); ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// ========================
// 4. DEBUG PAGE
// ========================
function lb_semantic_admin_debug() {
    ?>
    <div class="wrap">
        <h1>
            <span class="dashicons dashicons-admin-tools" style="font-size: 28px; vertical-align: middle;"></span>
            <?php _e('Test & Debug', 'lb-semantic-linker'); ?>
        </h1>
        
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
            <h2 style="margin-top: 0;"><?php _e('🧪 Test Related Articles', 'lb-semantic-linker'); ?></h2>
            <p><?php _e('Enter a post ID to see what related articles would be shown:', 'lb-semantic-linker'); ?></p>
            
            <form method="post" style="margin-top: 15px;">
                <?php wp_nonce_field('lb_semantic_test_nonce'); ?>
                <input type="number" name="test_post_id" placeholder="<?php _e('Post ID', 'lb-semantic-linker'); ?>" style="width: 150px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <input type="submit" name="lb_semantic_test" class="button button-primary" value="<?php _e('Test', 'lb-semantic-linker'); ?>">
            </form>
        </div>
        
        <?php
        if (isset($_POST['lb_semantic_test'])) {
            check_admin_referer('lb_semantic_test_nonce');
            
            $post_id = (int)$_POST['test_post_id'];
            $post = get_post($post_id);
            
            if ($post && $post->post_status === 'publish') {
                $entities = lb_semantic_get_post_entities($post_id);
                $links = lb_semantic_select_best_links($post_id, 10);
                
                echo '<div style="background: #fff; padding: 25px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 30px;">';
                echo '<h2>' . __('Test Results for Post ID: ', 'lb-semantic-linker') . $post_id . '</h2>';
                echo '<h3>' . get_the_title($post_id) . '</h3>';
                echo '<p><a href="' . get_permalink($post_id) . '" target="_blank">' . get_permalink($post_id) . '</a></p>';
                
                echo '<hr style="margin: 20px 0;">';
                
                echo '<h3>' . __('📊 Detected Entities:', 'lb-semantic-linker') . '</h3>';
                echo '<ul>';
                foreach ($entities as $e) {
                    echo '<li><strong>' . esc_html($e['type']) . '</strong> (' . $e['post_type'] . '): ' . esc_html($e['name']) . ' (priority: ' . $e['priority'] . ')</li>';
                }
                echo '</ul>';
                
                echo '<h3>' . __('🔗 Related Articles Found:', 'lb-semantic-linker') . ' ' . count($links) . '</h3>';
                if (!empty($links)) {
                    echo '<table class="wp-list-table widefat fixed striped">';
                    echo '<thead><tr><th>' . __('Title', 'lb-semantic-linker') . '</th><th>' . __('URL', 'lb-semantic-linker') . '</th><th>' . __('Score', 'lb-semantic-linker') . '</th><th>' . __('Date', 'lb-semantic-linker') . '</th><th>' . __('Entity', 'lb-semantic-linker') . '</th></tr></thead>';
                    echo '<tbody>';
                    foreach ($links as $l) {
                        $score_color = ($l['score'] >= 12) ? '#4caf50' : '#f44336';
                        echo '<tr>';
                        echo '<td><a href="' . esc_url($l['url']) . '" target="_blank">' . esc_html($l['title']) . '</a></td>';
                        echo '<td><small>' . esc_url($l['url']) . '</small></td>';
                        echo '<td><span style="color: ' . $score_color . '; font-weight: bold;">' . $l['score'] . '/20</span></td>';
                        echo '<td>' . $l['date'] . '</td>';
                        echo '<td><code>' . esc_html($l['entity']) . '</code></td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                } else {
                    echo '<p style="color: #d63638;"><strong>' . __('⚠️ No related articles found!', 'lb-semantic-linker') . '</strong></p>';
                    echo '<p>' . __('Possible reasons:', 'lb-semantic-linker') . '</p>';
                    echo '<ul>';
                    echo '<li>' . __('No other posts share the same taxonomy terms', 'lb-semantic-linker') . '</li>';
                    echo '<li>' . __('Other posts are too old (check "Maximum article age" setting)', 'lb-semantic-linker') . '</li>';
                    echo '<li>' . __('Cache not built yet (wait for CRON or rebuild manually)', 'lb-semantic-linker') . '</li>';
                    echo '<li>' . __('Safe Mode active: score too low (< 12)', 'lb-semantic-linker') . '</li>';
                    echo '</ul>';
                }
                
                echo '</div>';
                
                // Preview box
                if (!empty($links)) {
                    echo '<div style="background: #fff; padding: 25px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 30px;">';
                    echo '<h2>' . __('🎨 Preview (Default Style)', 'lb-semantic-linker') . '</h2>';
                    echo lb_semantic_build_default_box(array_slice($links, 0, 5), '📖 Articoli correlati');
                    echo '</div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>' . __('❌ Post not found or not published!', 'lb-semantic-linker') . '</p></div>';
            }
        }
        ?>
        
        <div style="background: #fff; padding: 25px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 30px;">
            <h2><?php _e('📊 System Information', 'lb-semantic-linker'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Plugin Version', 'lb-semantic-linker'); ?></th>
                    <td>3.1.0</td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('WordPress Version', 'lb-semantic-linker'); ?></th>
                    <td><?php echo get_bloginfo('version'); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('PHP Version', 'lb-semantic-linker'); ?></th>
                    <td><?php echo PHP_VERSION; ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Multisite', 'lb-semantic-linker'); ?></th>
                    <td><?php echo is_multisite() ? __('Yes', 'lb-semantic-linker') : __('No', 'lb-semantic-linker'); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Safe Mode', 'lb-semantic-linker'); ?></th>
                    <td>
                        <?php
                        $settings = get_option('lb_semantic_settings', lb_semantic_get_default_settings());
                        echo ($settings['safe_mode'] === '1') ? '<span style="color: #4caf50; font-weight: bold;">✅ Enabled</span>' : '<span style="color: #f44336; font-weight: bold;">❌ Disabled</span>';
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Context Filter', 'lb-semantic-linker'); ?></th>
                    <td>
                        <?php
                        echo ($settings['context_filter'] === '1') ? '<span style="color: #4caf50; font-weight: bold;">✅ Enabled</span>' : '<span style="color: #666; font-weight: bold;">⚠️ Disabled</span>';
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Active Post Types', 'lb-semantic-linker'); ?></th>
                    <td>
                        <?php
                        $post_types = get_post_types(['public' => true], 'objects');
                        $active = [];
                        foreach ($settings['include_post_types'] as $pt) {
                            if (isset($post_types[$pt])) {
                                $active[] = $post_types[$pt]->label . ' (' . $pt . ')';
                            }
                        }
                        echo implode(', ', $active);
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Cron Scheduled', 'lb-semantic-linker'); ?></th>
                    <td>
                        <?php
                        $cron = wp_next_scheduled('lb_semantic_build_clusters');
                        echo $cron ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $cron) : __('No', 'lb-semantic-linker');
                        ?>
                    </td>
                </tr>
            </table>
        </div>
        
        <div style="background: #fff; padding: 25px; border: 1px solid #ddd; border-radius: 8px;">
            <h2><?php _e('🔧 Tools', 'lb-semantic-linker'); ?></h2>
            
            <h3><?php _e('Rebuild Clusters for Specific Post', 'lb-semantic-linker'); ?></h3>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin-top: 15px;">
                <?php wp_nonce_field('lb_semantic_rebuild_post_nonce'); ?>
                <input type="hidden" name="action" value="lb_semantic_rebuild_post_clusters">
                <input type="number" name="post_id" placeholder="<?php _e('Post ID', 'lb-semantic-linker'); ?>" style="width: 150px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <input type="submit" class="button" value="<?php _e('Rebuild Clusters', 'lb-semantic-linker'); ?>">
            </form>
            
            <hr style="margin: 30px 0;">
            
            <h3><?php _e('View All Active Clusters', 'lb-semantic-linker'); ?></h3>
            <form method="post">
                <?php wp_nonce_field('lb_semantic_view_clusters_nonce'); ?>
                <input type="submit" name="view_clusters" class="button" value="<?php _e('Show Clusters', 'lb-semantic-linker'); ?>">
            </form>
            
            <?php
            if (isset($_POST['view_clusters'])) {
                check_admin_referer('lb_semantic_view_clusters_nonce');
                
                global $wpdb;
                $clusters = $wpdb->get_col($wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                    'lb_cluster_backup_%'
                ));
                
                echo '<div style="margin-top: 20px; background: #f8f9fa; padding: 15px; border-radius: 4px;">';
                echo '<h4>' . sprintf(__('Total Clusters: %d', 'lb-semantic-linker'), count($clusters)) . '</h4>';
                echo '<details style="margin-top: 10px;"><summary style="cursor: pointer; font-weight: bold;">' . __('Show all cluster keys', 'lb-semantic-linker') . '</summary>';
                echo '<ul style="max-height: 300px; overflow-y: auto; margin-top: 10px;">';
                foreach ($clusters as $cluster_key) {
                    echo '<li><code>' . esc_html($cluster_key) . '</code></li>';
                }
                echo '</ul></details>';
                echo '</div>';
            }
            ?>
        </div>
    </div>
    <?php
}

// ========================
// 5. DOCUMENTATION PAGE
// ========================
function lb_semantic_admin_docs() {
    ?>
    <div class="wrap">
        <h1>
            <span class="dashicons dashicons-book" style="font-size: 28px; vertical-align: middle;"></span>
            <?php _e('Documentation', 'lb-semantic-linker'); ?>
        </h1>
        
        <div style="background: #f0f6fc; padding: 20px; border-radius: 8px; margin-bottom: 30px;">
            <h2 style="margin-top: 0;"><?php _e('📚 Quick Start Guide', 'lb-semantic-linker'); ?></h2>
            <p style="font-size: 16px; line-height: 1.6;">
                <?php _e('Lean Bunker Semantic Linker automatically analyzes your content and creates topic clusters to link related articles together. Here\'s how to get started:', 'lb-semantic-linker'); ?>
            </p>
            
            <ol style="line-height: 1.8; font-size: 16px;">
                <li><strong><?php _e('Install & Activate', 'lb-semantic-linker'); ?></strong> - <?php _e('The plugin works automatically', 'lb-semantic-linker'); ?></li>
                <li><strong><?php _e('Configure Settings', 'lb-semantic-linker'); ?></strong> - <?php _e('Go to Semantic Linker → Settings', 'lb-semantic-linker'); ?></li>
                <li><strong><?php _e('Enable Safe Mode', 'lb-semantic-linker'); ?></strong> - <?php _e('Recommended for SEO safety', 'lb-semantic-linker'); ?></li>
                <li><strong><?php _e('Choose Display Method', 'lb-semantic-linker'); ?></strong> - <?php _e('Automatic, Shortcode, or Widget', 'lb-semantic-linker'); ?></li>
                <li><strong><?php _e('Test', 'lb-semantic-linker'); ?></strong> - <?php _e('Use the Debug page to verify everything works', 'lb-semantic-linker'); ?></li>
            </ol>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 30px;">
            
            <!-- How It Works -->
            <div style="background: #fff; padding: 25px; border: 1px solid #ddd; border-radius: 8px;">
                <h2 style="color: #0073aa;">
                    <span class="dashicons dashicons-admin-tools" style="font-size: 24px; vertical-align: middle;"></span>
                    <?php _e('How It Works', 'lb-semantic-linker'); ?>
                </h2>
                
                <h3><?php _e('1. Automatic Analysis', 'lb-semantic-linker'); ?></h3>
                <p><?php _e('The plugin analyzes your site structure and identifies:', 'lb-semantic-linker'); ?></p>
                <ul style="line-height: 1.8;">
                    <li><?php _e('Taxonomies (categories, tags, custom taxonomies)', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Post types (posts, pages, custom post types)', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Content relationships', 'lb-semantic-linker'); ?></li>
                </ul>
                
                <h3><?php _e('2. Cluster Building', 'lb-semantic-linker'); ?></h3>
                <p><?php _e('For each taxonomy term, the plugin builds a cluster of related posts:', 'lb-semantic-linker'); ?></p>
                <ul style="line-height: 1.8;">
                    <li><?php _e('Runs via CRON every 6 hours', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Updates instantly when you publish a post', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Caches results for fast performance', 'lb-semantic-linker'); ?></li>
                </ul>
                
                <h3><?php _e('3. Smart Selection', 'lb-semantic-linker'); ?></h3>
                <p><?php _e('When displaying related articles, the plugin:', 'lb-semantic-linker'); ?></p>
                <ul style="line-height: 1.8;">
                    <li><?php _e('Extracts entities from the current post', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Finds posts in the same clusters', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Scores them by relevance and recency', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Selects the best matches', 'lb-semantic-linker'); ?></li>
                </ul>
            </div>
            
            <!-- Features -->
            <div style="background: #fff; padding: 25px; border: 1px solid #ddd; border-radius: 8px;">
                <h2 style="color: #0073aa;">
                    <span class="dashicons dashicons-star-filled" style="font-size: 24px; vertical-align: middle;"></span>
                    <?php _e('Features', 'lb-semantic-linker'); ?>
                </h2>
                
                <h3><?php _e('🚀 Performance Optimized', 'lb-semantic-linker'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li><?php _e('Pre-built clusters via CRON', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Zero impact on page load speed', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Efficient caching system', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Optimized database queries', 'lb-semantic-linker'); ?></li>
                </ul>
                
                <h3><?php _e('🎯 Smart Linking', 'lb-semantic-linker'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li><?php _e('Contextual relevance scoring', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Recency-based ranking', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('No self-linking', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Duplicate prevention', 'lb-semantic-linker'); ?></li>
                </ul>
                
                <h3><?php _e('🛡️ SEO Safety', 'lb-semantic-linker'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li><?php _e('<strong>Safe Mode:</strong> Minimum score 12/20', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('<strong>Context Filter:</strong> Blocks cross-topic linking', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('<strong>1 Link per Entity:</strong> Forces semantic diversity', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('<strong>Recency Decay:</strong> Penalizes old content', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('<strong>Minimum 2 Links:</strong> Shows nothing if quality too low', 'lb-semantic-linker'); ?></li>
                </ul>
                
                <h3><?php _e('🔧 Flexible Display', 'lb-semantic-linker'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li><?php _e('Automatic insertion', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Shortcode with parameters', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Widget for sidebars', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Multiple styling options', 'lb-semantic-linker'); ?></li>
                </ul>
                
                <h3><?php _e('🌐 Multisite Ready', 'lb-semantic-linker'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li><?php _e('Works on single sites and multisite networks', 'lb-semantic-linker'); ?></li>
                    <li><?php _e('Per-site cluster isolation', 'lb-semantic-linker'); ?></li>
                </ul>
            </div>
            
            <!-- FAQ -->
            <div style="background: #fff; padding: 25px; border: 1px solid #ddd; border-radius: 8px; grid-column: span 2;">
                <h2 style="color: #0073aa;">
                    <span class="dashicons dashicons-sos" style="font-size: 24px; vertical-align: middle;"></span>
                    <?php _e('Frequently Asked Questions', 'lb-semantic-linker'); ?>
                </h2>
                
                <h3><?php _e('Q: Will this slow down my website?', 'lb-semantic-linker'); ?></h3>
                <p><strong><?php _e('A:', 'lb-semantic-linker'); ?></strong> <?php _e('No! All heavy processing happens in the background via CRON. The frontend only reads cached data.', 'lb-semantic-linker'); ?></p>
                
                <h3><?php _e('Q: How often are clusters updated?', 'lb-semantic-linker'); ?></h3>
                <p><strong><?php _e('A:', 'lb-semantic-linker'); ?></strong> <?php _e('Clusters update automatically when you publish a post, and via CRON every 6 hours for maintenance.', 'lb-semantic-linker'); ?></p>
                
                <h3><?php _e('Q: Can I use this on a large site with 100k+ posts?', 'lb-semantic-linker'); ?></h3>
                <p><strong><?php _e('A:', 'lb-semantic-linker'); ?></strong> <?php _e('Yes! The plugin is designed for large sites. It uses batch processing and efficient caching to handle any size.', 'lb-semantic-linker'); ?></p>
                
                <h3><?php _e('Q: Does it work with custom post types?', 'lb-semantic-linker'); ?></h3>
                <p><strong><?php _e('A:', 'lb-semantic-linker'); ?></strong> <?php _e('Yes! You can enable it for any public post type in the settings.', 'lb-semantic-linker'); ?></p>
                
                <h3><?php _e('Q: Can I exclude specific posts or categories?', 'lb-semantic-linker'); ?></h3>
                <p><strong><?php _e('A:', 'lb-semantic-linker'); ?></strong> <?php _e('Yes! Use the custom field <code>lb_semantic_disable = 1</code> or exclude categories in settings.', 'lb-semantic-linker'); ?></p>
                
                <h3><?php _e('Q: What if no related articles are found?', 'lb-semantic-linker'); ?></h3>
                <p><strong><?php _e('A:', 'lb-semantic-linker'); ?></strong> <?php _e('Nothing is displayed. The plugin is smart enough to only show links when relevant content exists.', 'lb-semantic-linker'); ?></p>
                
                <h3><?php _e('Q: What is Safe Mode?', 'lb-semantic-linker'); ?></h3>
                <p><strong><?php _e('A:', 'lb-semantic-linker'); ?></strong> <?php _e('Safe Mode ensures only high-quality, semantically relevant links are shown. It applies minimum score thresholds, blocks cross-context linking, and requires at least 2 high-quality links before showing anything.', 'lb-semantic-linker'); ?></p>
                
                <h3><?php _e('Q: Can I customize the styling?', 'lb-semantic-linker'); ?></h3>
                <p><strong><?php _e('A:', 'lb-semantic-linker'); ?></strong> <?php _e('Yes! Use the Custom CSS field in settings, or override the classes <code>.lb-semantic-links</code>, <code>.lb-semantic-links-default</code>, etc.', 'lb-semantic-linker'); ?></p>
            </div>
            
        </div>
        
        <div style="margin-top: 40px; padding: 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; color: #fff;">
            <h2 style="margin-top: 0; color: #fff;">
                <span class="dashicons dashicons-sos" style="font-size: 28px; vertical-align: middle;"></span>
                <?php _e('Need More Help?', 'lb-semantic-linker'); ?>
            </h2>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
                <div>
                    <h3 style="color: #fff; margin-top: 0;"><?php _e('📖 Documentation', 'lb-semantic-linker'); ?></h3>
                    <p><?php _e('Full user guide and API documentation', 'lb-semantic-linker'); ?></p>
                </div>
                
                <div>
                    <h3 style="color: #fff; margin-top: 0;"><?php _e('🐛 Bug Reports', 'lb-semantic-linker'); ?></h3>
                    <p><?php _e('Report issues and request features', 'lb-semantic-linker'); ?></p>
                </div>
                
                <div>
                    <h3 style="color: #fff; margin-top: 0;"><?php _e('⭐ Support', 'lb-semantic-linker'); ?></h3>
                    <p><?php _e('Get help from the community', 'lb-semantic-linker'); ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// ========================
// 6. SETTINGS HELPER
// ========================
function lb_semantic_get_default_settings() {
    return [
        'auto_insert' => '1',
        'auto_position' => 'after_first',
        'auto_max_links' => 5,
        'auto_style' => 'default',
        'include_post_types' => ['post'],
        'max_age_days' => 90,
        'enable_mobile' => '1',
        'exclude_categories' => [],
        'safe_mode' => '1', // ✅ Safe Mode attivato di default
        'context_filter' => '1', // ✅ Context Filter attivato di default
        'custom_css' => ''
    ];
}

// ========================
// 7. HOOKS GLOBALI (corretti)
// ========================
add_action('transition_post_status', 'lb_semantic_update_clusters_on_publish', 10, 3);
add_action('lb_semantic_update_single_cluster', 'lb_semantic_build_cluster_for_term', 10, 3);
add_action('lb_semantic_build_clusters', 'lb_semantic_cron_build_clusters');
add_action('save_post', 'lb_semantic_invalidate_cluster', 10, 2);
add_action('trashed_post', 'lb_semantic_clear_all_clusters');
add_action('deleted_post', 'lb_semantic_clear_all_clusters');
add_action('widgets_init', 'lb_semantic_register_widget');
add_filter('the_content', 'lb_semantic_insert_links', 20);
add_shortcode('lb_related_articles', 'lb_semantic_shortcode_related_articles');
add_shortcode('lb_semantic_debug', 'lb_semantic_debug_shortcode');

// ✅ FIX 1: Registra cron schedules PRIMA
add_filter('cron_schedules', 'lb_semantic_add_cron_intervals');
function lb_semantic_add_cron_intervals($schedules) {
    $schedules['six_hours'] = [
        'interval' => 6 * HOUR_IN_SECONDS,
        'display' => __('Ogni 6 ore', 'lb-semantic-linker')
    ];
    return $schedules;
}

// ========================
// 8. ATTIVAZIONE/DEATTIVAZIONE
// ========================
register_activation_hook(__FILE__, 'lb_semantic_activate');
function lb_semantic_activate() {
    if (!wp_next_scheduled('lb_semantic_build_clusters')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'six_hours', 'lb_semantic_build_clusters');
    }
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, 'lb_semantic_deactivate');
function lb_semantic_deactivate() {
    wp_clear_scheduled_hook('lb_semantic_build_clusters');
    flush_rewrite_rules();
    lb_semantic_clear_all_clusters();
}

// ========================
// 9. EVENT-DRIVEN
// ========================
function lb_semantic_update_clusters_on_publish($new_status, $old_status, $post) {
    if ($new_status !== 'publish') return;
    if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) return;
    if (!in_array($post->post_type, get_post_types(['public' => true]))) return;
    
    $taxonomies = get_object_taxonomies($post->post_type);
    
    foreach ($taxonomies as $taxonomy) {
        $terms = get_the_terms($post->ID, $taxonomy);
        
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                wp_schedule_single_event(time() + 5, 'lb_semantic_update_single_cluster', [
                    $taxonomy,
                    $term->term_id,
                    $post->post_type
                ]);
            }
        }
    }
}

// ========================
// 10. BUILD CLUSTER (con ottimizzazioni query)
// ========================
function lb_semantic_build_cluster_for_term($taxonomy, $term_id, $post_type) {
    $max_age_days = lb_semantic_get_max_age_for_post_type($post_type);
    
    $args = [
        'post_type' => $post_type,
        'post_status' => 'publish',
        'posts_per_page' => 15,
        'orderby' => 'date',
        'order' => 'DESC',
        'fields' => 'ids',
        'no_found_rows' => true, // ✅ FIX 3
        'update_post_meta_cache' => false, // ✅ FIX 3
        'update_post_term_cache' => false, // ✅ FIX 3
        'tax_query' => [
            [
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $term_id
            ]
        ]
    ];
    
    if ($max_age_days > 0) {
        $args['date_query'] = [
            [
                'after' => $max_age_days . ' days ago'
            ]
        ];
    }
    
    $query = new WP_Query($args);
    $post_ids = $query->posts;
    
    if (empty($post_ids)) {
        delete_transient(lb_semantic_get_transient_key($taxonomy, $term_id, $post_type));
        delete_option(lb_semantic_get_backup_key($taxonomy, $term_id, $post_type));
        return;
    }
    
    $cluster = [];
    foreach ($post_ids as $post_id) {
        $cluster[] = [
            'ID' => $post_id,
            'title' => get_the_title($post_id),
            'url' => get_permalink($post_id),
            'date' => get_the_date('Y-m-d', $post_id),
            'post_type' => $post_type
        ];
    }
    
    $transient_key = lb_semantic_get_transient_key($taxonomy, $term_id, $post_type);
    $backup_key = lb_semantic_get_backup_key($taxonomy, $term_id, $post_type);
    
    set_transient($transient_key, $cluster, 6 * HOUR_IN_SECONDS);
    update_option($backup_key, $cluster, false);
}

function lb_semantic_get_transient_key($taxonomy, $term_id, $post_type) {
    return 'lb_cluster_' . $taxonomy . '_' . $term_id . '_' . $post_type;
}

function lb_semantic_get_backup_key($taxonomy, $term_id, $post_type) {
    return 'lb_cluster_backup_' . $taxonomy . '_' . $term_id . '_' . $post_type;
}

// ========================
// 11. GET TASSONOMIE
// ========================
function lb_semantic_get_all_public_taxonomies() {
    $post_types = get_post_types(['public' => true], 'names');
    $taxonomies = [];
    
    foreach ($post_types as $post_type) {
        $taxs = get_object_taxonomies($post_type, 'objects');
        
        foreach ($taxs as $tax_name => $tax_obj) {
            if (in_array($tax_name, ['post_format', 'nav_menu', 'link_category'])) continue;
            
            $terms_count = wp_count_terms($tax_name, ['hide_empty' => true]);
            if (is_wp_error($terms_count) || $terms_count < 2) continue;
            
            if (!isset($taxonomies[$tax_name])) {
                $taxonomies[$tax_name] = [
                    'name' => $tax_obj->label,
                    'post_types' => [],
                    'terms_count' => $terms_count,
                    'hierarchical' => $tax_obj->hierarchical
                ];
            }
            
            if (!in_array($post_type, $taxonomies[$tax_name]['post_types'])) {
                $taxonomies[$tax_name]['post_types'][] = $post_type;
            }
        }
    }
    
    return $taxonomies;
}

// ========================
// 12. BATCH PROCESSING (con correzioni array)
// ========================
function lb_semantic_cron_build_clusters() {
    if (!defined('DOING_CRON') || !DOING_CRON) return 0;
    
    $start_time = microtime(true);
    $max_execution = 120;
    $processed = 0;
    
    $resume = get_option('lb_semantic_build_resume', []);
    $current_taxonomy = $resume['taxonomy'] ?? null;
    $current_post_type = $resume['post_type'] ?? null;
    $current_term_index = $resume['term_index'] ?? 0;
    $completed = $resume['completed'] ?? [];
    
    $taxonomies = lb_semantic_get_all_public_taxonomies();
    
    if (empty($taxonomies)) {
        delete_option('lb_semantic_build_resume');
        return 0;
    }
    
    // Filtra tassonomie già completate
    foreach ($completed as $done) {
        $done_key = $done['taxonomy'] . '_' . $done['post_type'];
        foreach ($taxonomies as $tax_name => $tax_data) {
            foreach ($tax_data['post_types'] as $pt) {
                if ($tax_name . '_' . $pt === $done_key) {
                    $taxonomies[$tax_name]['post_types'] = array_diff($taxonomies[$tax_name]['post_types'], [$pt]);
                    if (empty($taxonomies[$tax_name]['post_types'])) {
                        unset($taxonomies[$tax_name]);
                    }
                }
            }
        }
    }
    
    if (empty($taxonomies)) {
        delete_option('lb_semantic_build_resume');
        return 0;
    }
    
    // Se abbiamo un punto di ripresa, partiamo da lì
    if ($current_taxonomy && $current_post_type && isset($taxonomies[$current_taxonomy])) {
        $current_tax = $current_taxonomy;
        $current_pt = $current_post_type;
        
        // ✅ FIX 1: Ri-indicizza array dopo array_diff()
        $taxonomies[$current_tax]['post_types'] = array_values($taxonomies[$current_tax]['post_types']);
        
        $terms = get_terms([
            'taxonomy' => $current_tax,
            'hide_empty' => true,
            'number' => 100,
            'offset' => $current_term_index,
            'fields' => 'ids',
            'orderby' => 'count',
            'order' => 'DESC'
        ]);
    } else {
        // Nuova tassonomia + post_type
        reset($taxonomies);
        $current_tax = key($taxonomies);
        // ✅ FIX 1: Ri-indicizza prima di accedere
        $taxonomies[$current_tax]['post_types'] = array_values($taxonomies[$current_tax]['post_types']);
        $current_pt = $taxonomies[$current_tax]['post_types'][0];
        
        $terms = get_terms([
            'taxonomy' => $current_tax,
            'hide_empty' => true,
            'number' => 100,
            'fields' => 'ids',
            'orderby' => 'count',
            'order' => 'DESC'
        ]);
    }
    
    if (is_wp_error($terms) || empty($terms)) {
        $completed[] = ['taxonomy' => $current_tax, 'post_type' => $current_pt];
        
        // ✅ FIX 2: Gestione sicura next post type
        $pt_keys = array_values($taxonomies[$current_tax]['post_types']);
        $pos = array_search($current_pt, $pt_keys, true);
        $next_pt_index = ($pos === false) ? null : $pos + 1;
        
        if ($next_pt_index !== null && isset($pt_keys[$next_pt_index])) {
            $next_pt = $pt_keys[$next_pt_index];
            update_option('lb_semantic_build_resume', [
                'taxonomy' => $current_tax,
                'post_type' => $next_pt,
                'term_index' => 0,
                'completed' => $completed
            ]);
            return $processed; // ✅ ESCI
        }
        
        // Passa alla prossima tassonomia
        update_option('lb_semantic_build_resume', [
            'taxonomy' => null,
            'post_type' => null,
            'term_index' => 0,
            'completed' => $completed
        ]);
        return $processed; // ✅ ESCI
    }
    
    // Processa termini in batch
    foreach ($terms as $index => $term_id) {
        if ((microtime(true) - $start_time) > $max_execution) {
            update_option('lb_semantic_build_resume', [
                'taxonomy' => $current_tax,
                'post_type' => $current_pt,
                'term_index' => $current_term_index + $index,
                'completed' => $completed
            ]);
            return $processed; // ✅ ESCI
        }
        
        lb_semantic_build_cluster_for_term($current_tax, $term_id, $current_pt);
        $processed++;
    }
    
    // Questo post_type per questa tassonomia è completo
    $completed[] = ['taxonomy' => $current_tax, 'post_type' => $current_pt];
    
    // Prova prossimo post_type nella stessa tassonomia
    $pt_keys = array_values($taxonomies[$current_tax]['post_types']);
    $pos = array_search($current_pt, $pt_keys, true);
    $next_pt_index = ($pos === false) ? null : $pos + 1;
    
    if ($next_pt_index !== null && isset($pt_keys[$next_pt_index])) {
        $next_pt = $pt_keys[$next_pt_index];
        update_option('lb_semantic_build_resume', [
            'taxonomy' => $current_tax,
            'post_type' => $next_pt,
            'term_index' => 0,
            'completed' => $completed
        ]);
        return $processed; // ✅ ESCI
    }
    
    // Se ci sono ancora tassonomie, continua al prossimo giro di CRON
    $remaining = 0;
    foreach ($taxonomies as $tax_data) {
        $remaining += count($tax_data['post_types']);
    }
    
    if ($remaining > 1) {
        update_option('lb_semantic_build_resume', [
            'taxonomy' => null,
            'post_type' => null,
            'term_index' => 0,
            'completed' => $completed
        ]);
        return $processed; // ✅ ESCI
    }
    
    // Tutto completato
    delete_option('lb_semantic_build_resume');
    return $processed;
}

// ========================
// 13. CROSS-CONTEXT FILTER (PROTEZIONE SEO)
// ========================
function lb_semantic_filter_cross_context($cluster, $current_post_id) {
    $settings = get_option('lb_semantic_settings', lb_semantic_get_default_settings());
    
    // Se context filter disattivato, restituisci cluster invariato
    if ($settings['context_filter'] !== '1') {
        return $cluster;
    }
    
    $current_cats = wp_get_post_categories($current_post_id, ['fields' => 'slugs']);
    if (empty($current_cats)) {
        return $cluster;
    }
    
    // Mappa contesti (configurabile in futuro tramite settings)
    $context_map = [
        'politica' => ['sport', 'calcio', 'finanza', 'economia', 'tecnologia', 'motori', 'gossip'],
        'sport' => ['politica', 'finanza', 'economia', 'gossip'],
        'finanza' => ['sport', 'calcio', 'cultura', 'gossip'],
        'tecnologia' => ['politica', 'sport', 'gossip'],
        'cultura' => ['finanza', 'economia', 'sport'],
        'economia' => ['sport', 'cultura', 'gossip'],
        'news' => ['sport', 'gossip'],
        'attualita' => ['sport', 'gossip']
    ];
    
    // Versione inglese
    $context_map_en = [
        'politics' => ['sport', 'football', 'soccer', 'finance', 'economy', 'technology', 'cars', 'gossip'],
        'sport' => ['politics', 'finance', 'economy', 'gossip'],
        'finance' => ['sport', 'football', 'culture', 'gossip'],
        'technology' => ['politics', 'sport', 'gossip'],
        'culture' => ['finance', 'economy', 'sport'],
        'economy' => ['sport', 'culture', 'gossip'],
        'news' => ['sport', 'gossip'],
        'current-affairs' => ['sport', 'gossip']
    ];
    
    // Unisci le mappe
    $context_map = array_merge($context_map, $context_map_en);
    
    // Trova contesto corrente
    $excluded = [];
    foreach ($context_map as $main_context => $excluded_contexts) {
        if (in_array($main_context, $current_cats)) {
            $excluded = $excluded_contexts;
            break;
        }
    }
    
    if (empty($excluded)) {
        return $cluster;
    }
    
    // Filtra cluster
    $filtered = array_filter($cluster, function($item) use ($excluded) {
        $item_cats = wp_get_post_categories($item['ID'], ['fields' => 'slugs']);
        return count(array_intersect($item_cats, $excluded)) === 0;
    });
    
    return array_values($filtered); // Reset keys
}

// ========================
// 14. RECENCY DECAY (PROTEZIONE SEO)
// ========================
function lb_semantic_apply_recency_decay($cluster, $current_post_date) {
    $current_timestamp = strtotime($current_post_date);
    $filtered = [];
    
    foreach ($cluster as $item) {
        $item_timestamp = strtotime($item['date']);
        $age_diff_days = abs($current_timestamp - $item_timestamp) / DAY_IN_SECONDS;
        
        // Penalizza articoli con differenza > 60 giorni
        if ($age_diff_days > 60) {
            $item['score'] = ($item['score'] ?? 0) * 0.5;
        }
        
        // Esclusione hard per differenza > 180 giorni (news)
        if ($age_diff_days <= 180) {
            $filtered[] = $item;
        }
    }
    
    return $filtered;
}

// ========================
// 15. GET CLUSTER (con protezioni)
// ========================
function lb_semantic_get_cluster($taxonomy, $term_id, $post_type) {
    // Verifica che il post_type sia associato alla tassonomia
    $tax_obj = get_taxonomy($taxonomy);
    if (!$tax_obj || !in_array($post_type, $tax_obj->object_type)) {
        return [];
    }
    
    $transient_key = lb_semantic_get_transient_key($taxonomy, $term_id, $post_type);
    
    $cluster = get_transient($transient_key);
    
    if (false !== $cluster) {
        return lb_semantic_filter_cluster_by_age($cluster, $post_type);
    }
    
    $backup_key = lb_semantic_get_backup_key($taxonomy, $term_id, $post_type);
    $backup = get_option($backup_key, []);
    
    if (!empty($backup)) {
        set_transient($transient_key, $backup, 1 * HOUR_IN_SECONDS);
        return lb_semantic_filter_cluster_by_age($backup, $post_type);
    }
    
    return lb_semantic_fallback_live_query($taxonomy, $term_id, $post_type);
}

function lb_semantic_filter_cluster_by_age($cluster, $post_type) {
    $max_age_days = lb_semantic_get_max_age_for_post_type($post_type);
    
    if ($max_age_days <= 0) return $cluster;
    
    $cutoff = strtotime('-' . $max_age_days . ' days');
    $filtered = [];
    
    foreach ($cluster as $item) {
        $item_date = strtotime($item['date']);
        if ($item_date >= $cutoff) $filtered[] = $item;
    }
    
    return $filtered;
}

function lb_semantic_fallback_live_query($taxonomy, $term_id, $post_type) {
    static $fallback_count = 0;
    
    if ($fallback_count >= 2) return [];
    
    $fallback_count++;
    
    $max_age_days = lb_semantic_get_max_age_for_post_type($post_type);
    
    $args = [
        'post_type' => $post_type,
        'post_status' => 'publish',
        'posts_per_page' => 10,
        'orderby' => 'date',
        'order' => 'DESC',
        'fields' => 'ids',
        'no_found_rows' => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'tax_query' => [
            [
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $term_id
            ]
        ]
    ];
    
    if ($max_age_days > 0) {
        $args['date_query'] = [
            [
                'after' => $max_age_days . ' days ago'
            ]
        ];
    }
    
    $query = new WP_Query($args);
    
    if (!$query->have_posts()) return [];
    
    $cluster = [];
    foreach ($query->posts as $post_id) {
        $cluster[] = [
            'ID' => $post_id,
            'title' => get_the_title($post_id),
            'url' => get_permalink($post_id),
            'date' => get_the_date('Y-m-d', $post_id),
            'post_type' => $post_type
        ];
    }
    
    $backup_key = lb_semantic_get_backup_key($taxonomy, $term_id, $post_type);
    update_option($backup_key, $cluster, false);
    
    return $cluster;
}

// ========================
// 16. ETÀ MASSIMA PER POST TYPE
// ========================
function lb_semantic_get_max_age_for_post_type($post_type) {
    $age_map = [
        'post' => 90,
        'news' => 90,
        'article' => 90,
        'product' => 0,
        'portfolio' => 730,
        'page' => 0,
        'download' => 365
    ];
    
    return $age_map[$post_type] ?? 180;
}

// ========================
// 17. SELEZIONE ENTITÀ (con guardia)
// ========================
function lb_semantic_get_post_entities($post_id) {
    $entities = [];
    $max_entities = 3;
    $selected = 0;
    
    $post_type = get_post_type($post_id);
    $taxonomies = get_object_taxonomies($post_type, 'objects');
    
    uasort($taxonomies, function($a, $b) {
        if ($a->hierarchical && !$b->hierarchical) return -1;
        if (!$a->hierarchical && $b->hierarchical) return 1;
        return strcmp($a->name, $b->name);
    });
    
    foreach ($taxonomies as $tax_name => $tax_obj) {
        if ($selected >= $max_entities) break;
        
        if (in_array($tax_name, ['post_format'])) continue;
        
        // Verifica che il post_type sia associato alla tassonomia
        if (!in_array($post_type, $tax_obj->object_type)) continue;
        
        $terms = get_the_terms($post_id, $tax_name);
        
        if ($terms && !is_wp_error($terms)) {
            if ($tax_obj->hierarchical) {
                $child_terms = array_filter($terms, function($t) {
                    return $t->parent !== 0;
                });
                
                if (!empty($child_terms)) {
                    $term = reset($child_terms);
                    $entities[] = [
                        'type' => $tax_name,
                        'term_id' => $term->term_id,
                        'name' => $term->name,
                        'priority' => 10,
                        'post_type' => $post_type
                    ];
                    $selected++;
                } elseif (!empty($terms)) {
                    $term = reset($terms);
                    $entities[] = [
                        'type' => $tax_name,
                        'term_id' => $term->term_id,
                        'name' => $term->name,
                        'priority' => 8,
                        'post_type' => $post_type
                    ];
                    $selected++;
                }
            } else {
                $term = reset($terms);
                $entities[] = [
                    'type' => $tax_name,
                    'term_id' => $term->term_id,
                    'name' => $term->name,
                    'priority' => 5,
                    'post_type' => $post_type
                ];
                $selected++;
            }
        }
    }
    
    return $entities;
}

// ========================
// 18. SELEZIONE LINK (con protezioni SEO)
// ========================
function lb_semantic_select_best_links($post_id, $max_links = 5) {
    $entities = lb_semantic_get_post_entities($post_id);
    
    if (empty($entities)) return [];
    
    $candidates = [];
    $used_urls = [];
    $used_entities = []; // ✅ Protezione: 1 link per entità
    $current_post_url = get_permalink($post_id);
    $current_post_date = get_the_date('Y-m-d', $post_id);
    
    foreach ($entities as $entity) {
        $cluster = lb_semantic_get_cluster($entity['type'], $entity['term_id'], $entity['post_type']);
        
        if (empty($cluster)) continue;
        
        // ✅ Applica cross-context filter
        $cluster = lb_semantic_filter_cross_context($cluster, $post_id);
        
        foreach ($cluster as $item) {
            // Evita duplicati e post corrente
            if (isset($used_urls[$item['url']]) || $item['url'] === $current_post_url) continue;
            
            // Calcola punteggio
            $score = $entity['priority'];
            $score += (time() - strtotime($item['date'])) < (7 * DAY_IN_SECONDS) ? 3 : 0;
            $score += (time() - strtotime($item['date'])) < (30 * DAY_IN_SECONDS) ? 1 : 0;
            
            // ✅ Applica recency decay
            $item_age_days = (time() - strtotime($item['date'])) / DAY_IN_SECONDS;
            $current_age_days = (time() - strtotime($current_post_date)) / DAY_IN_SECONDS;
            $age_diff = abs($item_age_days - $current_age_days);
            
            if ($age_diff > 60) {
                $score = $score * 0.7; // Penalizza articoli con grande differenza di età
            }
            
            $candidates[] = [
                'title' => $item['title'],
                'url' => $item['url'],
                'entity' => $entity['name'],
                'entity_type' => $entity['type'],
                'score' => $score,
                'date' => $item['date']
            ];
            
            $used_urls[$item['url']] = true;
        }
    }
    
    // ✅ Protezione Safe Mode: soglia minima score
    $settings = get_option('lb_semantic_settings', lb_semantic_get_default_settings());
    $MIN_SCORE = ($settings['safe_mode'] === '1') ? 12 : 8;
    
    $candidates = array_filter($candidates, function($c) use ($MIN_SCORE) {
        return ($c['score'] ?? 0) >= $MIN_SCORE;
    });
    
    // ✅ Protezione Safe Mode: minimo 2 link di qualità
    if ($settings['safe_mode'] === '1' && count($candidates) < 2) {
        return []; // Meglio niente che rumore
    }
    
    // Ordina per score
    usort($candidates, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    
    // ✅ Protezione: massimo 1 link per entità (forza diversità semantica)
    $final_links = [];
    foreach ($candidates as $candidate) {
        $entity_key = $candidate['entity_type'] . '_' . sanitize_title($candidate['entity']);
        
        if (!isset($used_entities[$entity_key])) {
            $final_links[] = $candidate;
            $used_entities[$entity_key] = true;
            
            if (count($final_links) >= $max_links) break;
        }
    }
    
    return $final_links;
}

// ========================
// 19. SHORTCODE + OUTPUT
// ========================
function lb_semantic_shortcode_related_articles($atts = []) {
    $atts = shortcode_atts([
        'count' => 5,
        'title' => '',
        'style' => 'default'
    ], $atts, 'lb_related_articles');
    
    if (!is_singular()) return '';
    
    $post_id = get_the_ID();
    if (!$post_id) return '';
    
    $count = (int)$atts['count'];
    $count = max(1, min($count, 10));
    
    $links = lb_semantic_select_best_links($post_id, $count);
    
    if (empty($links)) return '';
    
    if (empty($atts['title'])) {
        $title = '📖 Articoli correlati';
    } else {
        $title = esc_html($atts['title']);
    }
    
    $style = $atts['style'];
    
    if ($style === 'compact') {
        return lb_semantic_build_compact_box($links, $title);
    } elseif ($style === 'minimal') {
        return lb_semantic_build_minimal_box($links, $title);
    } else {
        return lb_semantic_build_default_box($links, $title);
    }
}

function lb_semantic_build_default_box($links, $title) {
    $output = '<div class="lb-semantic-links lb-semantic-links-default" style="margin: 2em 0; padding: 1.5em; background: #f8f9fa; border-left: 4px solid #0073aa; border-radius: 4px;">';
    $output .= '<h3 style="margin-top: 0; font-size: 1.1em; color: #0073aa;">' . $title . '</h3>';
    $output .= '<ul style="list-style: none; padding-left: 0; margin-bottom: 0;">';
    
    foreach ($links as $link) {
        $output .= '<li style="margin-bottom: 0.5em; padding-left: 1.2em; position: relative;">';
        $output .= '<span style="position: absolute; left: 0; color: #0073aa;">›</span>';
        $output .= '<a href="' . esc_url($link['url']) . '" style="color: #0073aa; text-decoration: none; font-weight: 500;">';
        $output .= esc_html($link['title']);
        $output .= '</a>';
        $output .= '</li>';
    }
    
    $output .= '</ul>';
    $output .= '</div>';
    
    return $output;
}

function lb_semantic_build_compact_box($links, $title) {
    $output = '<div class="lb-semantic-links lb-semantic-links-compact" style="margin: 1.5em 0;">';
    $output .= '<h4 style="margin: 0 0 1em 0; font-size: 1em; color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 0.5em;">' . $title . '</h4>';
    $output .= '<ul style="list-style: none; padding-left: 0; margin: 0;">';
    
    foreach ($links as $link) {
        $output .= '<li style="margin-bottom: 0.75em; padding-left: 1em; position: relative; font-size: 0.95em;">';
        $output .= '<span style="position: absolute; left: 0; color: #666; font-size: 1.2em;">•</span>';
        $output .= '<a href="' . esc_url($link['url']) . '" style="color: #0073aa; text-decoration: none; line-height: 1.4;">';
        $output .= esc_html($link['title']);
        $output .= '</a>';
        $output .= '</li>';
    }
    
    $output .= '</ul>';
    $output .= '</div>';
    
    return $output;
}

function lb_semantic_build_minimal_box($links, $title) {
    $output = '<div class="lb-semantic-links lb-semantic-links-minimal" style="margin: 1em 0;">';
    
    if (!empty($title)) {
        $output .= '<p style="margin: 0 0 0.75em 0; font-weight: 600; color: #333;">' . $title . '</p>';
    }
    
    $output .= '<ul style="list-style: none; padding-left: 0; margin: 0; font-size: 0.95em;">';
    
    foreach ($links as $link) {
        $output .= '<li style="margin-bottom: 0.5em;">';
        $output .= '<a href="' . esc_url($link['url']) . '" style="color: #0073aa; text-decoration: underline;">';
        $output .= esc_html($link['title']);
        $output .= '</a>';
        $output .= '</li>';
    }
    
    $output .= '</ul>';
    $output .= '</div>';
    
    return $output;
}

// ========================
// 20. INSERIMENTO AUTOMATICO (con protezioni)
// ========================
function lb_semantic_insert_links($content) {
    if (is_admin() || !is_main_query() || !in_the_loop() || is_feed() || is_preview()) return $content;
    
    $post_id = get_the_ID();
    if (!$post_id) return $content;
    
    // Check if disabled via custom field
    if (get_post_meta($post_id, 'lb_semantic_disable', true) === '1') {
        return $content;
    }
    
    // Check if shortcode already present
    if (has_shortcode($content, 'lb_related_articles')) return $content;
    
    // Get settings
    $settings = get_option('lb_semantic_settings', lb_semantic_get_default_settings());
    
    // Check if auto-insert enabled
    if ($settings['auto_insert'] !== '1') return $content;
    
    // Check post type
    if (!in_array(get_post_type($post_id), $settings['include_post_types'])) return $content;
    
    // Check excluded categories
    if (!empty($settings['exclude_categories'])) {
        $post_cats = wp_get_post_categories($post_id);
        if (array_intersect($post_cats, $settings['exclude_categories'])) {
            return $content;
        }
    }
    
    // Check mobile
    if ($settings['enable_mobile'] !== '1' && wp_is_mobile()) {
        return $content;
    }
    
    $max_links = $settings['auto_max_links'];
    $links = lb_semantic_select_best_links($post_id, $max_links);
    
    if (empty($links)) return $content;
    
    $box_html = '';
    
    if ($settings['auto_style'] === 'compact') {
        $box_html = lb_semantic_build_compact_box($links, '📖 Articoli correlati');
    } elseif ($settings['auto_style'] === 'minimal') {
        $box_html = lb_semantic_build_minimal_box($links, '📖 Articoli correlati');
    } else {
        $box_html = lb_semantic_build_default_box($links, '📖 Articoli correlati');
    }
    
    // Apply custom CSS if exists
    if (!empty($settings['custom_css'])) {
        $box_html .= '<style>' . $settings['custom_css'] . '</style>';
    }
    
    // Insert based on position
    if ($settings['auto_position'] === 'after_first') {
        if (strpos($content, '</p>') !== false) {
            $content = preg_replace('/(<\/p>)/', '$1' . $box_html, $content, 1);
        } else {
            $content .= $box_html;
        }
    } elseif ($settings['auto_position'] === 'end') {
        $content .= $box_html;
    } elseif ($settings['auto_position'] === 'both') {
        if (strpos($content, '</p>') !== false) {
            $content = preg_replace('/(<\/p>)/', '$1' . $box_html, $content, 1);
        }
        $content .= $box_html;
    }
    
    return $content;
}

// ========================
// 21. PULIZIA CACHE (multisite-safe)
// ========================
function lb_semantic_invalidate_cluster($post_id, $post) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if ($post->post_status !== 'publish') return;
    
    $entities = lb_semantic_get_post_entities($post_id);
    
    foreach ($entities as $entity) {
        $transient_key = lb_semantic_get_transient_key($entity['type'], $entity['term_id'], $entity['post_type']);
        delete_transient($transient_key);
    }
}

function lb_semantic_clear_all_clusters() {
    global $wpdb;
    
    // ✅ FIX 5: Gestione multisite
    if (is_multisite()) {
        // Su multisite, non fare wipe globale (troppo pericoloso)
        return;
    }
    
    // Su single site, pulisci transient
    $transient_names = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} 
         WHERE option_name LIKE %s 
         OR option_name LIKE %s",
        '_transient_lb_cluster_%',
        '_transient_timeout_lb_cluster_%'
    ));
    
    foreach ($transient_names as $name) {
        $key = str_replace(['_transient_', '_transient_timeout_'], '', $name);
        delete_transient($key);
    }
    
    // Cancella backup
    $backup_names = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} 
         WHERE option_name LIKE %s",
        'lb_cluster_backup_%'
    ));
    
    foreach ($backup_names as $name) {
        delete_option($name);
    }
}

// ========================
// 22. DEBUG SHORTCODE
// ========================
function lb_semantic_debug_shortcode() {
    if (!current_user_can('manage_options')) return '';
    
    $post_id = get_the_ID();
    $entities = lb_semantic_get_post_entities($post_id);
    $links = lb_semantic_select_best_links($post_id, 10);
    
    $output = '<div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffa500;">';
    $output .= '<h3>🔍 Lean Bunker Semantic Linker - Debug</h3>';
    
    $output .= '<h4>📊 Entità rilevate:</h4>';
    $output .= '<ul>';
    foreach ($entities as $e) {
        $output .= '<li><strong>' . esc_html($e['type']) . '</strong> (' . $e['post_type'] . '): ' . esc_html($e['name']) . ' (priorità: ' . $e['priority'] . ')</li>';
    }
    $output .= '</ul>';
    
    $output .= '<h4>🔗 Link selezionati:</h4>';
    $output .= '<ul>';
    foreach ($links as $l) {
        $output .= '<li>' . esc_html($l['title']) . '<br>';
        $output .= '<small style="color:#666">Entità: ' . esc_html($l['entity']) . ' | Score: ' . $l['score'] . ' | ' . $l['date'] . '</small><br>';
        $output .= '<a href="' . esc_url($l['url']) . '">' . esc_url($l['url']) . '</a></li>';
    }
    $output .= '</ul>';
    
    $output .= '<hr style="margin: 20px 0; border: none; border-top: 1px dashed #ccc;">';
    $output .= '<h4>📝 Shortcode:</h4>';
    $output .= '<p><code>[lb_related_articles]</code></p>';
    $output .= '<p><code>[lb_related_articles count="3" style="compact"]</code></p>';
    
    $output .= '</div>';
    
    return $output;
}

// ========================
// 23. WIDGET
// ========================
function lb_semantic_register_widget() {
    register_widget('LB_Semantic_Widget');
}

class LB_Semantic_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'lb_semantic_widget',
            __('Articoli Correlati', 'lb-semantic-linker'),
            ['description' => __('Mostra contenuti correlati automaticamente', 'lb-semantic-linker')]
        );
    }
    
    public function widget($args, $instance) {
        if (!is_singular()) return;
        
        echo $args['before_widget'];
        
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $count = !empty($instance['count']) ? (int)$instance['count'] : 5;
        $style = !empty($instance['style']) ? $instance['style'] : 'compact';
        
        if ($title) {
            echo $args['before_title'] . apply_filters('widget_title', $title) . $args['after_title'];
        }
        
        echo do_shortcode('[lb_related_articles count="' . $count . '" style="' . esc_attr($style) . '"]');
        
        echo $args['after_widget'];
    }
    
    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $count = !empty($instance['count']) ? (int)$instance['count'] : 5;
        $style = !empty($instance['style']) ? $instance['style'] : 'compact';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php _e('Titolo:', 'lb-semantic-linker'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('count')); ?>"><?php _e('Numero articoli:', 'lb-semantic-linker'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('count')); ?>" name="<?php echo esc_attr($this->get_field_name('count')); ?>" type="number" min="1" max="10" value="<?php echo esc_attr($count); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('style')); ?>"><?php _e('Stile:', 'lb-semantic-linker'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('style')); ?>" name="<?php echo esc_attr($this->get_field_name('style')); ?>">
                <option value="default" <?php selected($style, 'default'); ?>><?php _e('Default', 'lb-semantic-linker'); ?></option>
                <option value="compact" <?php selected($style, 'compact'); ?>><?php _e('Compatto', 'lb-semantic-linker'); ?></option>
                <option value="minimal" <?php selected($style, 'minimal'); ?>><?php _e('Minimale', 'lb-semantic-linker'); ?></option>
            </select>
        </p>
        <?php
    }
    
    public function update($new_instance, $old_instance) {
        if (!current_user_can('edit_theme_options')) return $old_instance;
        
        return [
            'title' => sanitize_text_field($new_instance['title']),
            'count' => (int)max(1, min((int)$new_instance['count'], 10)),
            'style' => in_array($new_instance['style'], ['default', 'compact', 'minimal']) ? $new_instance['style'] : 'compact'
        ];
    }
}