<?php

namespace SimilarProperties;

if (!defined('ABSPATH')) exit;

function clear_property_cache($post_id) {
    $cache_key = 'sp_similar_r_' . $post_id;
    delete_transient($cache_key);
    wp_cache_delete($cache_key);
    // FM-SIMILAR: coordinate-index — a saved pin changes who is nearby, so the shared index is rebuilt on the next search.
    delete_transient('sp_coordinate_index');
}

function get_cached_properties($post_id) {
    $cache_key = 'sp_similar_r_' . $post_id;
    $cached = wp_cache_get($cache_key);
    
    if ($cached !== false) {
        // debug_log('Using object cache', ['post_id' => $post_id], '📦');
        return $cached;
    }
    
    $transient = get_transient($cache_key);
    if (!empty($transient)) {
        wp_cache_set($cache_key, $transient);
        //debug_log('Using transient cache', ['post_id' => $post_id], '📦');
        return $transient;
    }
    
    return false;
}

function set_cached_properties($post_id, $data) {
    $cache_key = 'sp_similar_r_' . $post_id;
    wp_cache_set($cache_key, $data);
    set_transient($cache_key, $data, CACHE_DURATION);
}

function clear_all_caches() {
    global $wpdb;
    
    // Get all transients with our prefix
    $transients = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE %s",
            '_transient_sp_similar_%'
        )
    );
    
    $count = 0;
    foreach ($transients as $transient) {
        $key = str_replace('_transient_', '', $transient);
        delete_transient($key);
        wp_cache_delete(str_replace('_transient_', '', $key));
        $count++;
    }
    
    delete_transient('sp_coordinate_index');

    // debug_log('Cleared all caches', ['count' => $count], '🧹');
    return $count;
}
