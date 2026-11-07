<?php
/**
 * Plugin Name: FM - Similar Properties
 * Description: Custom plugin for displaying similar properties based on type, price range, and distance from the map pin.
 * Version: 1.1.3
 * Author: Future Media
 * Author URI: https://futuremedia.gr
 */

namespace SimilarProperties;

if (!defined('ABSPATH')) exit;

// Load debug first so it's available everywhere
// require_once __DIR__ . '/includes/debug.php';

// Add constants
const MAX_SIMILAR_PROPERTIES = 6;
const DISPLAY_PROPERTIES = 3;
const CACHE_DURATION = DAY_IN_SECONDS;
const PRICE_TOLERANCE = 10;

function init_plugin() {
    require_once __DIR__ . '/includes/query.php';
    require_once __DIR__ . '/includes/cache.php';
    require_once __DIR__ . '/includes/admin.php';
    require_once __DIR__ . '/includes/render.php';
    
    register_shortcodes();
}

function check_dependencies() {
    static $checked = null;
    
    if ($checked !== null) {
        return $checked;
    }

    $checked = true;
    $missing = [];

    if (!function_exists('get_field')) {
        $missing[] = 'Advanced Custom Fields (ACF) plugin';
        $checked = false;
        
        // Add admin notice for missing ACF
        add_action('admin_notices', function() {
            echo '<div class="error"><p>📌 Similar Properties: ACF plugin is required but not installed.</p></div>';
        });
    }
    
    // Add status notice in admin
    add_action('admin_notices', function() use ($checked) {
        $class = $checked ? 'updated' : 'error';
        $icon = $checked ? '✅' : '❌';
        echo sprintf(
            '<div class="%s"><p>%s FM Similar Properties: Dependency check %s</p></div>',
            esc_attr($class),
            $icon,
            $checked ? 'passed' : 'failed'
        );
    });

    return $checked;
}

function activate_plugin() {
    //debug_log('Plugin activation started', null, '🚀');
    
    if (!check_dependencies()) {
        // Add activation failure notice
        add_action('admin_notices', function() {
            echo '<div class="error"><p>⛔ FM Similar Properties: Plugin activation aborted - dependencies not met</p></div>';
        });
        
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('FM Similar Properties plugin activation failed: Required dependencies are missing.');
    }
    
    // Set default options
    if (!get_option('sp_price_tolerance')) {
        update_option('sp_price_tolerance', 10);
    }

    if (!get_option('sp_search_radius')) {
        update_option('sp_search_radius', 50);
    }
    
    // Create assets directory if it doesn't exist
    $css_dir = plugin_dir_path(__FILE__) . 'assets/css';
    if (!file_exists($css_dir)) {
        wp_mkdir_p($css_dir);
    }
    
    //debug_log('Plugin activated successfully', null, '✅');
}

// Remove plugins_loaded hook and use activation hook instead
register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate_plugin');

// Load components on init
add_action('init', __NAMESPACE__ . '\init_plugin', 20);

function enqueue_styles() {
    wp_enqueue_style(
        'similar-properties',
        plugins_url('assets/css/similar-properties.css', __FILE__),
        [],
        filemtime(plugin_dir_path(__FILE__) . 'assets/css/similar-properties.css')
    );
}

add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_styles');

// Keep these hooks
add_action('admin_menu', __NAMESPACE__ . '\\register_admin_menu');
add_action('save_post_properties', __NAMESPACE__ . '\\clear_property_cache');
add_action('acf/save_post', __NAMESPACE__ . '\\clear_property_cache_after_acf_save', 20);