<?php

/**
 * Template for individual similar property card
 * 
 * @var WP_Post $similar The property post object
 * @var int $similar_id The property post ID
 */

namespace SimilarProperties;

if (!defined('ABSPATH')) exit;

$location = function_exists('fm_location_tax') ? fm_location_tax($similar_id) : ['name' => ''];
?>
<div class="card card-property">

    <a class="permalink" href="<?php echo esc_url(get_permalink($similar_id)); ?>" rel="bookmark"></a>

    <figure role="img" aria-label="<?php echo esc_html($similar->post_title);?>" class="ratio ratio-1x1">
        <?php echo get_the_post_thumbnail($similar_id, 'medium_large', array('alt' => esc_attr($similar->post_title), 'loading' => 'lazy', 'decoding' => 'async', 'fetchpriority' => 'low')); ?>
    </figure>

    <div class="card-body">

        <h3 class="card-title no-glot"><?php echo esc_html($similar->post_title); ?></h3>

        <address>
            <span><i class="fui-location"></i></span>
            <span><?php echo esc_html($location['name'] ?? ''); ?></span>
        </address>

        <?php
        // These numeric/boolean fields need no ACF formatting; WP_Query has primed their metadata.
        $pr_size_raw = get_post_meta($similar_id, 'property_size', true);
        $l_size_raw  = get_post_meta($similar_id, 'land_size', true);
        $bedrooms_raw = get_post_meta($similar_id, 'bedrooms', true);
        $baths_raw   = get_post_meta($similar_id, 'bathrooms', true);
        $hidden_price = get_post_meta($similar_id, 'hidden_price', true);
        $price = get_post_meta($similar_id, 'price', true);
        
        $pr_size     = $pr_size_raw ? round(floatval($pr_size_raw)) : 'n.a.';
        $l_size      = $l_size_raw ? round(floatval($l_size_raw)) : 'n.a.';
        $bedrooms    = $bedrooms_raw ? floatval($bedrooms_raw) : 'n.a.';
        $baths       = $baths_raw ? floatval($baths_raw) : 'n.a.';
        ?>

        <div class="pr-data">
            <div class="label"><span class="inline">Property Size</span></div>
            <div class="label right"><span class="inline">Land Size</span></div>
            <div class="unit" data-unit="<?php echo $pr_size; ?>"><span class="inline"><?php echo nFormat($pr_size); ?><br />sqm</span></div>
            <div class="unit right" data-unit="<?php echo $l_size; ?>"><span class="inline"><?php echo nFormat($l_size); ?><br />sqm</span></div>
            <div class="label"><span class="inline">Bedrooms</span></div>
            <div class="label right"><span class="inline">Bathrooms</span></div>
            <div class="unit"><span class="inline"><?php echo $bedrooms; ?></span></div>
            <div class="unit right"><span class="inline"><?php echo $baths; ?></span></div>
        </div>

    </div>

    <div class="card-footer">

        <?php
        if ($hidden_price || empty($price)) :
            echo '<span class="poa">';
            _e('Price on request', 'fmedia');
            echo '</span>';
        else : ?>
            <span class="price number no-glot" data-price="<?php echo esc_attr($price); ?>">
                <?php echo nFormat($price, 'el_EL') ?> <small>&euro;</small>
            </span>
        <?php
        endif;
        ?>

    </div>


</div>