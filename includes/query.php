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

    return $earth_radius * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

// FM-SIMILAR: coordinate-index — one shared list of published pins, rebuilt when a property is saved.
function get_coordinate_index() {
    $index = get_transient('sp_coordinate_index');
    if (is_array($index)) {
        return $index;
    }

    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT pm.post_id, pm.meta_value
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = %s
           AND p.post_type = %s
           AND p.post_status = %s
           AND p.post_password = ''",
        'location_map',
        'properties',
        'publish'
    ));

    $index = [];
    foreach ($rows as $row) {
        $map = maybe_unserialize($row->meta_value);
        if (!is_array($map) || !isset($map['lat'], $map['lng']) || !is_numeric($map['lat']) || !is_numeric($map['lng'])) {
            continue;
        }
        $lat = (float) $map['lat'];
        $lng = (float) $map['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            continue;
        }
        $index[(int) $row->post_id] = [$lat, $lng];
    }

    set_transient('sp_coordinate_index', $index, CACHE_DURATION);
    return $index;
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

// FM-SIMILAR: geo-radius — same type and price band, then keep pins inside the radius. No wider radius and no taxonomy fallback.
function query_within_radius($post_id, array $origin, $property_type, $min_price, $max_price, $limit) {
    $radius = get_search_radius_km();
    $index = get_coordinate_index();

    $candidate_ids = (new \WP_Query([
        'post_type'              => 'properties',
        'posts_per_page'         => -1,
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
    ]))->posts;

    $nearby = [];
    foreach ($candidate_ids as $candidate_id) {
        $candidate_id = (int) $candidate_id;
        if (!isset($index[$candidate_id])) {
            continue;
        }
        [$lat, $lng] = $index[$candidate_id];
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
    $cached = get_cached_properties($post_id);
    if (is_array($cached)) {
        return query_from_ids($cached['ids'], !empty($cached['sorted_by_distance']));
    }

    // Get taxonomy terms
    $property_type = wp_get_post_terms($post_id, 'pr_type', ['fields' => 'ids']);
    $location = wp_get_post_terms($post_id, 'location', ['fields' => 'ids']);
    if (empty($property_type)) {
        // debug_log('No property type found', ['post_id' => $post_id], '⚠️');
        return false;
    }

    // Get and clean price
    $price_raw = get_field('price', $post_id);
    $price = clean_price($price_raw);
    /*
    debug_log('Price info', [
        'raw' => $price_raw,
        'cleaned' => $price,
        'type' => gettype($price)
    ], '💰');
    */

    // Bail out if price is too high (corrupt)
    if ($price > 1000000000) {
        error_log("❌ Price too high ($price) — aborting query for post ID $post_id");
        return false;
    }

    // Bail out if price invalid
    if (!$price || $price < 1) return false;

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
    if ($coords) {
        $query = query_within_radius($post_id, $coords, $property_type, $min_price, $max_price, $limit);
        set_cached_properties($post_id, wp_list_pluck($query->posts, 'ID'), true);
        return $query;
    }

    // FM-SIMILAR: no-pin source — match the location taxonomy, then drop that filter if there are too few. This path does not require a map pin.
    // Main query args
    $args = [
        'post_type'      => 'properties',
        'posts_per_page' => $limit,
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

    global $wpdb;



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
    if ($query->found_posts < $limit) {
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
    set_cached_properties($post_id, wp_list_pluck($query->posts, 'ID'), false);

    return $query;
}
