<?php

namespace SimilarProperties;

if (!defined('ABSPATH')) exit;

// The unique options-table key provides a cross-worker lock without an object cache.
function acquire_rebuild_lock() {
    global $wpdb;
    $key = 'sp_rebuild_lock';
    $token = wp_json_encode(['owner' => wp_generate_uuid4(), 'expires' => time() + 300]);
    // Read the database directly so a stale options cache cannot affect ownership.
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        $key
    ));
    if ($existing !== null) {
        $data = json_decode($existing, true);
        if (is_array($data) && isset($data['expires']) && $data['expires'] > time()) {
            return false;
        }
        // Delete only the expired value we observed, never a newer worker's lock.
        release_rebuild_lock($existing);
    }

    // INSERT IGNORE cannot replace a lock acquired between the read and this insert.
    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
        $key,
        $token,
        'no'
    ));
    return $inserted === 1 ? $token : false;
}

function release_rebuild_lock($token) {
    global $wpdb;
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        'sp_rebuild_lock',
        $token
    ));
    if ($deleted) {
        wp_cache_delete('sp_rebuild_lock', 'options');
    }
}

function clear_property_cache($post_id) {
    $cache_key = 'sp_similar_r_' . $post_id;
    delete_transient($cache_key);
    wp_cache_delete($cache_key);
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
