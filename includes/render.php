<?php

namespace SimilarProperties;

if (!defined('ABSPATH')) exit;

function render_similar_properties($atts = []) {
    //debug_log('Shortcode called', $atts, '🔁');

    // Only check context and basic post validity here
    if (!is_singular('properties')) {
        //debug_log('Not on single properties page', null, '⚠️');
        return '';
    }

    global $post;
    $post_id = isset($post) ? $post->ID : 0;

    if (!$post_id) {
        //debug_log('No valid post ID found', null, '⚠️');
        return '';
    }

    $atts = shortcode_atts(['count' => MAX_SIMILAR_PROPERTIES], $atts);
    $query = get_similar_properties($post_id, MAX_SIMILAR_PROPERTIES); // Always fetch up to 6

    if (!$query || !$query->have_posts()) {
        //debug_log('No similar properties found', null, '⚠️');
        return '';
    }

    ob_start();
    $total_posts = count($query->posts);
    $max_display = DISPLAY_PROPERTIES;

    // Get all post IDs efficiently
    $posts_to_show = wp_list_pluck($query->posts, 'ID');

    // Randomize if needed
    if ($total_posts > $max_display) {
        /*
        debug_log('Randomizing posts', [
            'total_found' => $total_posts,
            'will_show' => $max_display
        ], '🎲');
        */

        // Shuffle the array of post IDs
        shuffle($posts_to_show);
        $posts_to_show = array_slice($posts_to_show, 0, $max_display);
    }

    // Start output
    echo '<section class="similar-properties">';
    echo '<div class="wrap">';
    echo '<h2 class="serif">Similar Properties</h2>';
    echo '<div class="similar-grid">';

    // Display selected posts
    foreach ($posts_to_show as $similar_id) {
        $similar = get_post($similar_id);
        include plugin_dir_path(dirname(__FILE__)) . 'templates/property-card.php';
    }

    echo '</div>';
    echo '</section>';

    echo '<script> console.log("Similar Properties found: ' . esc_js($total_posts) . '"); console.log("Will show: ' . esc_js($max_display) . '");</script>';

    return ob_get_clean();
}

function register_shortcodes() {
    add_shortcode('similar_properties', __NAMESPACE__ . '\\render_similar_properties');
}

function get_template_path($template_name) {
    $default_path = plugin_dir_path(dirname(__FILE__)) . 'templates/' . $template_name;

    return apply_filters(
        'similar_properties_template_path',
        $default_path,
        $template_name
    );
}
