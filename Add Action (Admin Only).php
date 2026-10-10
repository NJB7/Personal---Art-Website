// One list of admin-only pages, used by both hooks below
function nba_admin_only_pages() {
    return ['add-content', 'add-post', 'add-pin', 'add-project', 'add-book', 'add-journal', 'add-work', 'link-errors'];
}

// Show a 404 to anyone who isn't an admin
add_action('template_redirect', function () {

    if (is_page(nba_admin_only_pages()) && !current_user_can('manage_options')) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }
});

// Hide the same pages from site search results
add_action('pre_get_posts', function ($query) {

    if (is_admin() || !$query->is_main_query() || !$query->is_search()) {
        return;
    }

    $hidden_ids = get_posts([
        'post_type'      => 'page',
        'post_name__in'  => nba_admin_only_pages(),
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);

    if ($hidden_ids) {
        $query->set(
            'post__not_in',
            array_merge((array) $query->get('post__not_in'), $hidden_ids)
        );
    }
});
