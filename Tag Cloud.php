// Tag Cloud: count tags used on blog posts, Projects, Journal entries and Books
// (Pins are left out). Add or remove post types in the list below.
add_filter('render_block', function ($block_content, $block) {

    if (empty($block['blockName']) || $block['blockName'] !== 'core/tag-cloud') {
        return $block_content;
    }

    $post_ids = get_posts(array(
        'post_type'      => array('post', 'project', 'journal', 'book', 'work'),
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ));

    if (!$post_ids) {
        return $block_content;
    }

    $tags = get_terms(array(
        'taxonomy'   => 'post_tag',
        'hide_empty' => true,
        'object_ids' => $post_ids,
    ));

    if (is_wp_error($tags) || !$tags) {
        return $block_content;
    }

    return wp_tag_cloud(array(
        'taxonomy' => 'post_tag',
        'include'  => wp_list_pluck($tags, 'term_id'),
        'echo'     => false,
    ));

}, 10, 2);
