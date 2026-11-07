<?php

namespace SimilarProperties;

if (!defined('ABSPATH')) exit;

function clear_property_cache($post_id) {
    $cache_key = 'sp_similar_r_' . $post_id;
    delete_transient($cache_key);
    wp_cache_delete($cache_key);

    // FM-SIMILAR: coordinate-index — only a property with a map pin can change who is nearby.
    if (function_exists(__NAMESPACE__ . '\\get_property_coordinates') && get_property_coordinates($post_id)) {
        delete_transient('sp_coordinate_index');
    }
}

function clear_property_cache_after_acf_save($post_id) {
    // save_post_properties runs before ACF writes location_map, so a pin added in this save is visible only here.
    if (!is_numeric($post_id) || get_post_type((int) $post_id) !== 'properties') {
        return;
    }

    clear_property_cache((int) $post_id);
}

function cached_properties_payload($cached) {
    // FM-SIMILAR: cache-ids — ignore a previously stored WP_Query; only an ID list is a cache hit.
    if (!is_array($cached) || !array_key_exists('ids', $cached) || !is_array($cached['ids'])) {
        return false;
    }

    return [
        'ids' => array_values(array_filter(array_map('intval', $cached['ids']))),
        'sorted_by_distance' => !empty($cached['sorted_by_distance']),
    ];
}

function get_cached_properties($post_id) {
    $cache_key = 'sp_similar_r_' . $post_id;
    $cached = cached_properties_payload(wp_cache_get($cache_key));

    if ($cached !== false) {
        // debug_log('Using object cache', ['post_id' => $post_id], '📦');
        return $cached;
    }

    $transient = cached_properties_payload(get_transient($cache_key));
    if ($transient !== false) {
        wp_cache_set($cache_key, $transient);
        //debug_log('Using transient cache', ['post_id' => $post_id], '📦');
        return $transient;
    }

    return false;
}

function set_cached_properties($post_id, array $ids, $sorted_by_distance = false) {
    $cache_key = 'sp_similar_r_' . $post_id;
    $payload = [
        'ids' => array_values(array_filter(array_map('intval', $ids))),
        'sorted_by_distance' => (bool) $sorted_by_distance,
    ];
    wp_cache_set($cache_key, $payload);
    set_transient($cache_key, $payload, CACHE_DURATION);
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
