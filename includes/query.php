<?php

namespace SimilarProperties;

if (!defined('ABSPATH')) exit;

function clean_price($price_raw) {
    if (!$price_raw) return 0;
    // Handle common currency formats
    $price_raw = str_replace(['$', '€', '£', ','], ['', '', '', ''], $price_raw);
    return floatval(trim($price_raw));
}

function get_search_radius_km() {
    $radius = get_option('sp_search_radius', 50);
    if (!is_numeric($radius) || $radius < 1 || $radius > 500) {
        return 50.0;
    }
    return (float) $radius;
}

// FM-SIMILAR: location-pin — ACF google_map field "Location on Map" (location_map), stored as an array with lat/lng.
function get_property_coordinates($post_id) {
    $map = get_post_meta($post_id, 'location_map', true);
    if (!is_array($map) || !isset($map['lat'], $map['lng'])) {
        return null;
    }
    if (!is_numeric($map['lat']) || !is_numeric($map['lng'])) {
        return null;
    }

    $lat = (float) $map['lat'];
    $lng = (float) $map['lng'];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        return null;
    }
    if ($lat === 0.0 && $lng === 0.0) {
        return null;
    }

    return ['lat' => $lat, 'lng' => $lng];
}

function distance_km($lat1, $lng1, $lat2, $lng2) {
    $earth_radius = 6371.0;
    $lat_delta = deg2rad($lat2 - $lat1);
    $lng_delta = deg2rad($lng2 - $lng1);
    $a = sin($lat_delta / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lng_delta / 2) ** 2;

    $a = max(0.0, min(1.0, $a));
    return $earth_radius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

// Fetch only this batch's pins; avoid loading every property's metadata into memory.
function get_coordinates_for_ids(array $ids) {
    if (!$ids) {
        return [];
    }

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta}
         WHERE meta_key = %s AND post_id IN ($placeholders)",
        array_merge(['location_map'], array_map('intval', $ids))
    ));

    if ($rows === null || $wpdb->last_error) {
        return false;
    }

    $coordinates = [];
    foreach ($rows as $row) {
        // ACF maps contain arrays of scalars. Never instantiate objects from stored metadata.
        $map = is_serialized($row->meta_value)
            ? unserialize(trim($row->meta_value), ['allowed_classes' => false])
            : null;
        if (!is_array($map) || !isset($map['lat'], $map['lng']) || !is_numeric($map['lat']) || !is_numeric($map['lng'])) {
            continue;
        }
        $lat = (float) $map['lat'];
        $lng = (float) $map['lng'];
        if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            continue;
        }
        $coordinates[(int) $row->post_id] = [$lat, $lng];
    }

    return $coordinates;
}

// FM-SIMILAR: cache-ids — rebuild a live WP_Query from stored IDs. A cached WP_Query unserializes as an incomplete object when the object cache starts before class-wp-query.php loads.
function query_from_ids(array $ids, $sorted_by_distance = false) {
    $ids = array_values(array_filter(array_map('intval', $ids)));

    if (!$ids) {
        // post__in 0 matches nothing. An empty WP_Query constructor would leave query vars unset.
        $query = new \WP_Query([
            'post_type'      => 'properties',
            'post__in'       => [0],
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);
    } else {
        $query = new \WP_Query([
            'post_type'      => 'properties',
            'post_status'    => 'publish',
            'post__in'       => $ids,
            'orderby'        => 'post__in',
            'posts_per_page' => count($ids),
            'no_found_rows'  => true,
            'has_password'   => false,
        ]);
    }

    if ($sorted_by_distance) {
        $query->set('sp_sorted_by_distance', 1);
    }

    return $query;
}

function distance_sorted_query(array $ids) {
    return query_from_ids($ids, true);
}

// Restrict the ID range only for this query instance, including during nested queries.
function query_candidate_batch(array $args, $last_id) {
    global $wpdb;
    $query = new \WP_Query();
    $where_filter = function ($where, $current_query) use ($query, $last_id, $wpdb) {
        if ($current_query === $query) {
            $where .= $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $last_id);
        }
        return $where;
    };

    add_filter('posts_where', $where_filter, 10, 2);
    try {
        $query->query($args);
        return $wpdb->last_error ? false : $query->posts;
    } finally {
        remove_filter('posts_where', $where_filter, 10);
    }
}

// FM-SIMILAR: geo-radius — same type and price band, then keep pins inside the radius. No wider radius and no taxonomy fallback.
function query_within_radius($post_id, array $origin, $property_type, $min_price, $max_price, $limit) {
    $radius = get_search_radius_km();
    $limit = max(1, min(MAX_SIMILAR_PROPERTIES, (int) $limit));
    $batch_size = 200;
    $args = [
        'post_type'              => 'properties',
        'posts_per_page'         => $batch_size,
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'cache_results'          => false,
        'update_post_meta_cache' => false,
        'post__not_in'           => [$post_id],
        'post_status'            => 'publish',
        'has_password'           => false,
        'fields'                 => 'ids',
        'no_found_rows'          => true,
        'update_post_term_cache' => false,
        'meta_query'             => [
            [
                'key'     => 'price',
                'value'   => [$min_price, $max_price],
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            ],
        ],
        'tax_query' => [
            [
                'taxonomy' => 'pr_type',
                'field'    => 'term_id',
                'terms'    => $property_type,
            ],
        ],
    ];

    // Bound per-query memory and retain only the closest matches across all batches.
    $nearby = [];
    $last_id = 0;
    do {
        $candidate_ids = query_candidate_batch($args, $last_id);
        if ($candidate_ids === false) {
            return false;
        }
        $coordinates = get_coordinates_for_ids($candidate_ids);
        if ($coordinates === false) {
            return false;
        }
        foreach ($candidate_ids as $candidate_id) {
            $candidate_id = (int) $candidate_id;
            if (!isset($coordinates[$candidate_id])) {
                continue;
            }
            [$lat, $lng] = $coordinates[$candidate_id];
            $distance = distance_km($origin['lat'], $origin['lng'], $lat, $lng);
            if ($distance <= $radius) {
                $nearby[] = ['id' => $candidate_id, 'distance' => $distance];
            }
        }

        usort($nearby, function ($a, $b) {
            if ($a['distance'] === $b['distance']) {
                return $a['id'] <=> $b['id'];
            }
            return $a['distance'] <=> $b['distance'];
        });
        $nearby = array_slice($nearby, 0, $limit);
        if ($candidate_ids) {
            $next_id = (int) max($candidate_ids);
            if ($next_id <= $last_id) {
                return false; // Do not loop forever if another query filter removes ordering.
            }
            $last_id = $next_id;
        }
    } while (count($candidate_ids) === $batch_size);

    $ids = array_column(array_slice($nearby, 0, $limit), 'id');
    return distance_sorted_query($ids);
}

function get_similar_properties($post_id, $limit = MAX_SIMILAR_PROPERTIES) {
    // Validate current post first
    if (get_post_type($post_id) !== 'properties' 
        || get_post_status($post_id) !== 'publish'
        || post_password_required($post_id)
    ) {
        /*
        debug_log('Source property invalid or inaccessible', [
            'id' => $post_id,
            'type' => get_post_type($post_id),
            'status' => get_post_status($post_id)
        ], '⚠️');
        */
        return false;
    }

    if (!function_exists('get_field')) {
        // debug_log('ACF not active', null, '❌');
        return false;
    }

    // debug_log('Starting search', ['post_id' => $post_id, 'limit' => $limit], '🔄');
    
    // FM-SIMILAR: cache-ids — a hit is a list of post IDs, turned back into a WP_Query before anything reads ->posts.
    $generation = get_cache_generation();
    $cached = get_cached_properties($post_id, $generation);
    if (is_array($cached)) {
        return query_from_ids($cached['ids'], !empty($cached['sorted_by_distance']));
    }

    // One rebuild at a time, including across different property pages and PHP workers.
    $lock = acquire_rebuild_lock();
    if ($lock === false) {
        return false;
    }

    try {
        // Another worker may have filled this cache before we acquired the lock.
        $cached = get_cached_properties($post_id, $generation);
        if (is_array($cached)) {
            return query_from_ids($cached['ids'], !empty($cached['sorted_by_distance']));
        }
        return build_similar_properties($post_id, $limit, $generation);
    } finally {
        release_rebuild_lock($lock);
    }
}

function build_similar_properties($post_id, $limit, $generation = null) {
    global $wpdb;
    $limit = max(1, min(MAX_SIMILAR_PROPERTIES, (int) $limit));
    // Get taxonomy terms
    $property_type = wp_get_post_terms($post_id, 'pr_type', ['fields' => 'ids']);
    if (is_wp_error($property_type) || $wpdb->last_error) {
        return false;
    }
    if (empty($property_type)) {
        set_cached_properties($post_id, [], false, 300, $generation);
        return query_from_ids([]);
    }

    // Get and clean price
    $price_raw = get_field('price', $post_id);
    if ($wpdb->last_error) {
        return false;
    }
    $price = clean_price($price_raw);
    /*
    debug_log('Price info', [
        'raw' => $price_raw,
        'cleaned' => $price,
        'type' => gettype($price)
    ], '💰');
    */

    // Incomplete listings cannot match until their data changes; retry after five minutes.
    if (!is_finite($price) || $price < 1 || $price > 1000000000) {
        set_cached_properties($post_id, [], false, 300, $generation);
        return query_from_ids([]);
    }

    // Load tolerance percentage from settings (default 10%)
    $tolerance_percentage = get_option('sp_price_tolerance', 10);
    $tolerance_percentage = is_numeric($tolerance_percentage) && $tolerance_percentage > 0 && $tolerance_percentage <= 100 ? floatval($tolerance_percentage) : 10;

    // Calculate tolerance as percentage of price
    $tolerance = ($price * $tolerance_percentage) / 100;

    // error_log("📏 Using tolerance: {$tolerance_percentage}% = $tolerance");

    $min_price = $price - $tolerance;
    $max_price = $price + $tolerance;

    // error_log("✅ Query range: $min_price - $max_price");

    // FM-SIMILAR: geo-radius — a listing with a map pin is matched by distance. Closest results are returned in order.
    $coords = get_property_coordinates($post_id);
    if ($wpdb->last_error) {
        return false;
    }
    if ($coords) {
        $query = query_within_radius($post_id, $coords, $property_type, $min_price, $max_price, $limit);
        if (!$query instanceof \WP_Query || $wpdb->last_error) {
            return false;
        }
        set_cached_properties($post_id, wp_list_pluck($query->posts, 'ID'), true, CACHE_DURATION, $generation);
        return $query;
    }

    // FM-SIMILAR: no-pin source — match the location taxonomy, then drop that filter if there are too few. This path does not require a map pin.
    $location = wp_get_post_terms($post_id, 'location', ['fields' => 'ids']);
    if (is_wp_error($location) || $wpdb->last_error) {
        return false;
    }

    // Main query args
    $args = [
        'post_type'      => 'properties',
        'posts_per_page' => $limit,
        'no_found_rows'  => true,
        'post__not_in'   => [$post_id],
        'post_status'    => 'publish',
        'has_password'   => false,
        'meta_query'     => [
            [
                'key'     => 'price',
                'value'   => [$min_price, $max_price],
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC'
            ]
        ],
        'tax_query' => [
            'relation' => 'AND',
            [
                'taxonomy' => 'pr_type',
                'field'    => 'term_id',
                'terms'    => $property_type,
            ],
            [
                'taxonomy' => 'location',
                'field'    => 'term_id',
                'terms'    => $location,
                'operator' => 'IN',
            ]
        ],
    ];

    // Debug the query args and limit
    /*
    debug_log('Query configuration', [
        'requested_limit' => $limit,
        'posts_per_page' => $args['posts_per_page'],
        'price_range' => "$min_price - $max_price",
        'property_type' => $property_type,
        'location' => $location
    ], '🔍');
    */

    $query = new \WP_Query($args);
    /*
    debug_log('Query results', [
        'found_posts' => $query->found_posts,
        'post_count' => $query->post_count,
        'max_num_pages' => $query->max_num_pages
    ], '📊');
    */

    // Remove filters using namespace
    // remove_filter('posts_request', __NAMESPACE__ . '\log_sql_query', 999);
    // remove_filter('get_meta_sql', __NAMESPACE__ . '\log_meta_sql', 999);

    // Fallback: drop location filter if too few results
    if ($wpdb->last_error) {
        return false;
    }
    if ($query->post_count < $limit) {
        /*
        debug_log('Insufficient results, trying without location', [
            'found' => $query->found_posts,
            'needed' => $limit
        ], '⚠️');
        */
        
        $args['tax_query'] = [ $args['tax_query'][0] ]; // Keep only property_type
        $query = new \WP_Query($args);
        
        /*
        debug_log('Fallback query results', [
            'found_posts' => $query->found_posts,
            'post_count' => $query->post_count,
            'max_num_pages' => $query->max_num_pages
        ], '📊');
        */
    }

    // Cache result
    if ($wpdb->last_error) {
        return false;
    }
    set_cached_properties($post_id, wp_list_pluck($query->posts, 'ID'), false, CACHE_DURATION, $generation);

    return $query;
}
