<?php

namespace SimilarProperties;

if (!defined('ABSPATH')) exit;

function register_admin_menu() {
    add_options_page(
        'Similar Properties Settings',
        'Similar Properties',
        'manage_options',
        'similar-properties',
        __NAMESPACE__ . '\\render_admin_page'
    );
}

function render_admin_page() {
    // Handle cache clearing
    if (isset($_POST['clear_sp_cache']) && check_admin_referer('sp_clear_cache')) {
        $cleared = clear_all_caches();
        add_settings_error(
            'sp_messages',
            'sp_cache_cleared',
            sprintf('Cache cleared. Removed %d items.', $cleared),
            'updated'
        );
    }
    
    ?>
    <div class="wrap">
        <h1>Similar Properties Settings</h1>
        
        <?php settings_errors(); ?>
        
        <form method="post" action="options.php">
            <?php
            settings_fields('sp_settings');
            do_settings_sections('similar-properties');
            submit_button();
            ?>
        </form>
        
        <hr>
        
        <h2>Cache Management</h2>
        <form method="post">
            <?php wp_nonce_field('sp_clear_cache'); ?>
            <p>Clear all cached similar properties data.</p>
            <?php submit_button(
                'Clear Cache',
                'delete',
                'clear_sp_cache',
                false,
                ['onclick' => "return confirm('Are you sure you want to clear all caches?');"]
            ); ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', function () {
    register_setting('sp_settings', 'sp_price_tolerance', [
        'type' => 'number',
        'sanitize_callback' => function ($val) {
            $val = floatval($val);
            return ($val > 0 && $val <= 100) ? $val : 10;
        },
        'default' => 10
    ]);

    add_settings_section('sp_main', 'Main Settings', null, 'similar-properties');

    add_settings_field(
        'sp_price_tolerance',
        'Price Tolerance (%)',
        function () {
            $value = get_option('sp_price_tolerance', 10);
            echo '<input type="number" name="sp_price_tolerance" value="' . esc_attr($value) . '" min="1" max="100" step="0.1" />';
            echo '<p class="description">Percentage of the property price to use as tolerance range (e.g., 20% of $500,000 = ±$100,000)</p>';
        },
        'similar-properties',
        'sp_main'
    );
});
