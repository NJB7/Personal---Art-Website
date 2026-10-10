/* Convert to Journal: a Tools page for moving blog posts into the Journal
 * post type (and back again).
 *
 * Needs the "Project Journal" snippet to be active first (it creates the
 * Journal type). Where to find it: Tools > Convert to Journal in the admin.
 *
 * Converting keeps the text, images, featured image, categories, tags and
 * which Projects are ticked. Only the type changes, so the address changes
 * from the old blog-post address to /journal/post-name/ (old addresses
 * redirect automatically). You can switch this snippet off when you are done.
 */

function pj_convert_menu() {
    add_management_page('Convert to Journal', 'Convert to Journal', 'manage_options', 'pj-convert', 'pj_convert_page');
}
add_action('admin_menu', 'pj_convert_menu');

function pj_convert_list($type, $direction, $label, $precheck_projects) {

    $items = get_posts([
        'post_type'      => $type,
        'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ]);

    echo '<form method="post" style="margin-bottom:2em">';
    wp_nonce_field('pj_convert', 'pj_convert_nonce');
    echo '<input type="hidden" name="pj_direction" value="' . esc_attr($direction) . '">';

    if (!$items) {
        echo '<p>Nothing here yet.</p></form>';
        return;
    }

    echo '<table class="widefat striped" style="max-width:900px"><thead><tr>'
        . '<td class="check-column"><input type="checkbox" class="pj-all"></td>'
        . '<th>Title</th><th>Status</th><th>Categories</th><th>Projects ticked</th></tr></thead><tbody>';

    foreach ($items as $item) {

        $projects = [];

        foreach (get_post_meta($item->ID, '_post_project') as $project_id) {
            $projects[] = get_the_title((int) $project_id);
        }

        $cats = wp_get_post_categories($item->ID, ['fields' => 'names']);

        $checked = ($precheck_projects && $projects) ? ' checked' : '';

        echo '<tr><th class="check-column"><input type="checkbox" name="pj_ids[]" value="' . (int) $item->ID . '"' . $checked . '></th>'
            . '<td>' . esc_html(get_the_title($item) ?: '(no title)') . '</td>'
            . '<td>' . esc_html($item->post_status) . '</td>'
            . '<td>' . esc_html(implode(', ', $cats)) . '</td>'
            . '<td>' . esc_html(implode(', ', $projects)) . '</td></tr>';
    }

    echo '</tbody></table><p><button type="submit" class="button button-primary">' . esc_html($label) . '</button></p></form>';
}

function pj_convert_page() {

    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="wrap"><h1>Convert to Journal</h1>';

    if (!post_type_exists('journal')) {
        echo '<p>The Journal type is not active yet. Turn on the "Project Journal" snippet first, then reload this page.</p></div>';
        return;
    }

    if (isset($_POST['pj_convert_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pj_convert_nonce'])), 'pj_convert')) {

        $to   = (isset($_POST['pj_direction']) && $_POST['pj_direction'] === 'back') ? 'post' : 'journal';
        $from = $to === 'journal' ? 'post' : 'journal';
        $ids  = isset($_POST['pj_ids']) ? array_map('absint', (array) $_POST['pj_ids']) : [];
        $done = 0;

        foreach ($ids as $id) {

            if (!$id || get_post_type($id) !== $from || !current_user_can('edit_post', $id)) {
                continue;
            }

            $result = wp_update_post(['ID' => $id, 'post_type' => $to], true);

            if (!is_wp_error($result)) {
                $done++;
            }
        }

        flush_rewrite_rules(false);

        echo '<div class="notice notice-success"><p>' . (int) $done . ' ' . ($done === 1 ? 'entry' : 'entries')
            . ' moved to ' . ($to === 'journal' ? 'Journal' : 'blog posts') . '.</p></div>';
    }

    echo '<p>Back up your site first, and try one entry before moving the rest.</p>';

    echo '<h2>Blog posts &rarr; Journal</h2>';
    echo '<p>Posts that already have a Project ticked are pre-selected. Change the ticks as you like.</p>';
    pj_convert_list('post', 'to', 'Move selected to Journal', true);

    echo '<h2>Journal &rarr; Blog posts</h2>';
    echo '<p>To undo a conversion, tick the entries and move them back.</p>';
    pj_convert_list('journal', 'back', 'Move selected back to blog posts', false);

    echo '<script>document.querySelectorAll(".pj-all").forEach(function(box){box.addEventListener("change",function(){box.closest("table").querySelectorAll("input[name=\'pj_ids[]\']").forEach(function(c){c.checked=box.checked;});});});</script>';

    echo '</div>';
}
