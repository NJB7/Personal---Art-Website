<?php

// Create a function that defines our new "Pin" content type
function create_pin_post_type() {

    register_post_type('pin', array(
        'labels' => array(
            'name'          => 'Pins',
            'singular_name' => 'Pin',
        ),
        'public' => true,
        'show_in_rest' => true,
        'supports' => array(
            'title',
            'editor',
            'thumbnail',
        ),
    ));
}

add_action('init', 'create_pin_post_type');


// Allow Pins to use the standard WordPress Categories and Tags
function add_taxonomies_to_pins() {
    register_taxonomy_for_object_type('category', 'pin');
    register_taxonomy_for_object_type('post_tag', 'pin');
}

add_action('init', 'add_taxonomies_to_pins');


// // Exclude Pin usage from the Tag Cloud count,

// add_filter('render_block', function($block_content, $block) {

//     if (
//         isset($block['blockName']) &&
//         $block['blockName'] === 'core/tag-cloud'
//     ) {
//         $tags = get_terms(array(
//             'taxonomy'   => 'post_tag',
//             'hide_empty' => true,
//             'object_ids' => get_posts(array(
//                 'post_type'      => array('post', 'project', 'book', 'journal'),
//                 'post_status'    => 'publish',
//                 'posts_per_page' => -1,
//                 'fields'         => 'ids',
//             )),
//         ));

//         if (!is_wp_error($tags)) {
//             // Rebuild the Tag Cloud using only regular blog-post tags.
//             $block_content = wp_tag_cloud(array(
//                 'taxonomy' => 'post_tag',
//                 'include'  => wp_list_pluck($tags, 'term_id'),
//                 'echo'     => false,
//             ));
//         }
//     }

//     return $block_content;

// }, 10, 2);


