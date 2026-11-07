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

function get_cache_generation() {
    return (string) get_option('sp_cache_generation', '1');
}

function property_cache_key($post_id, $generation = null) {
    $generation = $generation === null ? get_cache_generation() : $generation;
    return 'sp_similar_v2_' . $generation . '_' . (int) $post_id;
}

function clear_property_cache($post_id) {
    // A changed listing can affect recommendations on other property pages too.
    clear_all_caches();
}

function clear_property_cache_after_acf_save($post_id) {
    // ACF writes the map and price after save_post_properties runs.
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

function get_cached_properties($post_id, $generation = null) {
    // WordPress automatically uses persistent object caching for transients when available.
    return cached_properties_payload(get_transient(property_cache_key($post_id, $generation)));
}

function set_cached_properties($post_id, array $ids, $sorted_by_distance = false, $duration = CACHE_DURATION, $generation = null) {
    $payload = [
        'ids' => array_values(array_filter(array_map('intval', $ids))),
        'sorted_by_distance' => (bool) $sorted_by_distance,
    ];
    return set_transient(property_cache_key($post_id, $generation), $payload, $duration);
}

function clear_all_caches() {
    // Switch namespaces instead of scanning the options table. Old entries expire naturally.
    return update_option('sp_cache_generation', wp_generate_uuid4(), false);
}
