/* ------------------------------------------------------------------
 * Show Projects in Category / Tag archives
 *    (Remove this block if you want archives to stay posts-only.)
 * ------------------------------------------------------------------ */
add_action('pre_get_posts', function ($query) {
    if (!is_admin() && $query->is_main_query() && ($query->is_category() || $query->is_tag())) {
        $query->set('post_type', array('post', 'project', 'journal', 'work'));
    }
});
