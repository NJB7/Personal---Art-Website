/* Media Categories
 *
 * Gives your Media Library categories (for example "Book covers"), plus a
 * dropdown to include or leave out categories wherever you pick a picture.
 * Anything you have not categorised counts as "uncategorised", so you can hide
 * book covers and see just the rest.
 *
 * What it adds:
 *   1. A "Media categories" list under Media (add, rename, nest them).
 *   2. A filter dropdown in the Media Library (both the grid and the list
 *      view) and in every picture picker: the block editor's featured image
 *      and "Image > Media Library", and your Add Journal / Project / Work forms.
 *        - All categories
 *        - Uncategorised only
 *        - Only: <a category>
 *        - Everything except: <a category>
 *   3. Media > Sort into categories: files pictures automatically. For
 *      example every picture used as a Book's cover becomes "Book covers".
 *      It only ever adds a category to pictures that have none yet; it never
 *      removes or changes anything.
 *   4. In the Media Library's List view, tick pictures and choose
 *      "Add to category: ..." (or "Remove from all categories") from the
 *      Bulk actions menu.
 *
 * Safe to leave switched on.
 */

function mcat_tax() {
    return 'media_category';
}

add_action('init', function () {

    register_taxonomy(mcat_tax(), 'attachment', [
        'labels' => [
            'name'          => 'Media categories',
            'singular_name' => 'Media category',
            'menu_name'     => 'Media categories',
            'all_items'     => 'All media categories',
            'edit_item'     => 'Edit media category',
            'add_new_item'  => 'Add media category',
            'search_items'  => 'Search media categories',
        ],
        'hierarchical'      => true,
        'public'            => false,
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'show_in_nav_menus' => false,
        'rewrite'           => false,
        'query_var'         => false,
        'update_count_callback' => '_update_generic_term_count',
    ]);
});

/* All categories, in tree order, as objects with a ->mcat_label that shows nesting. */
function mcat_terms() {

    $terms = get_terms(['taxonomy' => mcat_tax(), 'hide_empty' => false, 'orderby' => 'name']);

    if (is_wp_error($terms) || !$terms) {
        return [];
    }

    $children = [];

    foreach ($terms as $term) {
        $children[(int) $term->parent][] = $term;
    }

    $out  = [];
    $walk = function ($parent, $depth) use (&$walk, $children, &$out) {

        if (empty($children[$parent])) {
            return;
        }

        foreach ($children[$parent] as $term) {
            $term->mcat_label = str_repeat('– ', $depth) . $term->name;
            $out[]            = $term;
            $walk((int) $term->term_id, $depth + 1);
        }
    };

    $walk(0, 0);

    /* Categories whose parent is missing still show up */
    foreach ($terms as $term) {
        if (!isset($term->mcat_label)) {
            $term->mcat_label = $term->name;
            $out[]            = $term;
        }
    }

    return $out;
}

/* Turns a filter choice ("none", "in:12", "out:12") into a tax_query clause. */
function mcat_tax_query($value) {

    if ($value === 'none') {
        return ['taxonomy' => mcat_tax(), 'operator' => 'NOT EXISTS'];
    }

    if (preg_match('/^(in|out):(\d+)$/', (string) $value, $m)) {
        return [
            'taxonomy'         => mcat_tax(),
            'field'            => 'term_id',
            'terms'            => [(int) $m[2]],
            'include_children' => true,
            'operator'         => $m[1] === 'in' ? 'IN' : 'NOT IN',
        ];
    }

    return null;
}


/* ============================================================
 * 1. Filter in the Media Library LIST view
 * ============================================================ */

add_action('restrict_manage_posts', function ($post_type) {

    global $pagenow;

    if ($pagenow !== 'upload.php') {
        return;
    }

    $terms = mcat_terms();

    if (!$terms) {
        return;
    }

    $current = isset($_GET['mcat']) ? sanitize_text_field(wp_unslash($_GET['mcat'])) : '';

    echo '<select name="mcat" id="mcat-filter">';
    echo '<option value="">All categories</option>';
    echo '<option value="none"' . selected($current, 'none', false) . '>Uncategorised only</option>';

    echo '<optgroup label="Only…">';
    foreach ($terms as $term) {
        echo '<option value="in:' . (int) $term->term_id . '"' . selected($current, 'in:' . $term->term_id, false) . '>'
            . esc_html($term->mcat_label) . '</option>';
    }
    echo '</optgroup>';

    echo '<optgroup label="Everything except…">';
    foreach ($terms as $term) {
        echo '<option value="out:' . (int) $term->term_id . '"' . selected($current, 'out:' . $term->term_id, false) . '>'
            . esc_html($term->mcat_label) . '</option>';
    }
    echo '</optgroup>';

    echo '</select>';
});

add_action('pre_get_posts', function ($query) {

    global $pagenow;

    if (!is_admin() || !$query->is_main_query() || $pagenow !== 'upload.php') {
        return;
    }

    $value = isset($_GET['mcat']) ? sanitize_text_field(wp_unslash($_GET['mcat'])) : '';
    $tax   = mcat_tax_query($value);

    if (!$tax) {
        return;
    }

    $existing   = (array) $query->get('tax_query');
    $existing[] = $tax;
    $query->set('tax_query', $existing);
});


/* ============================================================
 * 2. Filter in the Media Library GRID and the picture pickers
 *    (block editor, featured image, your Add forms)
 * ============================================================ */

add_filter('ajax_query_attachments_args', function ($query) {

    if (!isset($_REQUEST['query']) || !is_array($_REQUEST['query']) || !isset($_REQUEST['query']['mcat'])) {
        return $query;
    }

    $value = sanitize_text_field(wp_unslash($_REQUEST['query']['mcat']));
    $tax   = mcat_tax_query($value);

    if ($tax) {
        $existing   = isset($query['tax_query']) ? (array) $query['tax_query'] : [];
        $existing[] = $tax;
        $query['tax_query'] = $existing;
    }

    return $query;
});

add_action('wp_enqueue_media', function () {

    if (!taxonomy_exists(mcat_tax())) {
        return;
    }

    $terms = [];

    foreach (mcat_terms() as $term) {
        $terms[] = ['id' => (int) $term->term_id, 'name' => $term->mcat_label];
    }

    if (!$terms) {
        return;
    }

    $js = 'window.mcatFilterData = ' . wp_json_encode(['terms' => $terms]) . ';' . <<<'JS'

    (function () {
        if (!window.wp || !wp.media || !wp.media.view || !wp.media.view.AttachmentFilters || !wp.media.view.AttachmentsBrowser) return;
        if (wp.media.view.mcatPatched) return;
        wp.media.view.mcatPatched = true;

        var data = window.mcatFilterData || {terms: []};

        var Filter = wp.media.view.AttachmentFilters.extend({
            id: 'media-category-filter',
            createFilters: function () {
                var filters = {};
                var p = 10;

                filters.all = {text: 'All categories', props: {mcat: ''}, priority: p++};
                filters.none = {text: 'Uncategorised only', props: {mcat: 'none'}, priority: p++};

                data.terms.forEach(function (t) {
                    filters['in' + t.id] = {text: 'Only: ' + t.name, props: {mcat: 'in:' + t.id}, priority: p++};
                });

                data.terms.forEach(function (t) {
                    filters['out' + t.id] = {text: 'Everything except: ' + t.name, props: {mcat: 'out:' + t.id}, priority: p++};
                });

                this.filters = filters;
            }
        });

        var Original = wp.media.view.AttachmentsBrowser;

        wp.media.view.AttachmentsBrowser = Original.extend({
            createToolbar: function () {
                Original.prototype.createToolbar.call(this);

                this.toolbar.set('mcatFilter', new Filter({
                    controller: this.controller,
                    model: this.collection.props,
                    priority: -70
                }).render());
            }
        });
    })();

JS;

    wp_add_inline_script('media-views', $js, 'after');
});


/* ============================================================
 * 3. Bulk actions in the Media Library list view
 * ============================================================ */

add_filter('bulk_actions-upload', function ($actions) {

    $terms = mcat_terms();

    foreach ($terms as $term) {
        $actions['mcat_add_' . $term->term_id] = 'Add to category: ' . $term->name;
    }

    if ($terms) {
        $actions['mcat_clear'] = 'Remove from all categories';
    }

    return $actions;
});

add_filter('handle_bulk_actions-upload', function ($redirect, $action, $ids) {

    $ids = array_map('absint', (array) $ids);
    $n   = 0;

    if ($action === 'mcat_clear') {

        foreach ($ids as $id) {
            if (current_user_can('edit_post', $id)) {
                wp_set_object_terms($id, [], mcat_tax());
                $n++;
            }
        }

    } elseif (preg_match('/^mcat_add_(\d+)$/', (string) $action, $m)) {

        foreach ($ids as $id) {
            if (current_user_can('edit_post', $id)) {
                wp_set_object_terms($id, [(int) $m[1]], mcat_tax(), true);
                $n++;
            }
        }

    } else {
        return $redirect;
    }

    return add_query_arg('mcat_done', $n, $redirect);
}, 10, 3);

add_action('admin_notices', function () {

    global $pagenow;

    if ($pagenow !== 'upload.php' || !isset($_GET['mcat_done'])) {
        return;
    }

    $n = absint($_GET['mcat_done']);

    echo '<div class="notice notice-success is-dismissible"><p>Updated the categories of ' . $n . ' file' . ($n === 1 ? '' : 's') . '.</p></div>';
});


/* ============================================================
 * 4. Media > Sort into categories (automatic filing)
 * ============================================================ */

function mcat_sort_types() {

    /* post type => suggested category name */
    $all = [
        'book'    => 'Book covers',
        'work'    => 'Artwork',
        'pin'     => 'Pin images',
        'project' => 'Project images',
        'journal' => 'Journal images',
        'post'    => 'Blog images',
        'page'    => 'Page images',
    ];

    return array_filter($all, 'post_type_exists', ARRAY_FILTER_USE_KEY);
}

/* Every image used as a featured image, or attached directly, for one post type. */
function mcat_ids_for_type($type) {

    $posts = get_posts([
        'post_type'      => $type,
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    if (!$posts) {
        return [];
    }

    $ids = [];

    foreach ($posts as $post_id) {
        $thumb = (int) get_post_thumbnail_id($post_id);
        if ($thumb) {
            $ids[] = $thumb;
        }
    }

    $kids = get_posts([
        'post_type'       => 'attachment',
        'post_status'     => 'inherit',
        'post_parent__in' => $posts,
        'posts_per_page'  => -1,
        'fields'          => 'ids',
        'no_found_rows'   => true,
    ]);

    $ids = array_unique(array_merge($ids, array_map('intval', $kids)));

    return array_values(array_filter($ids, 'wp_attachment_is_image'));
}

/* Only the ones that have no category yet. */
function mcat_untagged($ids) {

    if (!$ids) {
        return [];
    }

    $rows = wp_get_object_terms($ids, mcat_tax(), ['fields' => 'all_with_object_id']);

    if (is_wp_error($rows)) {
        return $ids;
    }

    $tagged = array_map('intval', wp_list_pluck($rows, 'object_id'));

    return array_values(array_diff($ids, $tagged));
}

function mcat_term_id($name) {

    $found = term_exists($name, mcat_tax());

    if (!$found) {
        $found = wp_insert_term($name, mcat_tax());
    }

    if (is_wp_error($found)) {
        return 0;
    }

    return (int) (is_array($found) ? $found['term_id'] : $found);
}

/* ============================================================
 * Where a picture is really used
 *
 * WordPress's own "Uploaded to" column only records the entry that was open
 * when the file was first uploaded. It is never updated when the picture is
 * later used somewhere else, and it is not cleared when it stops being used.
 * This works out the real answer: entries that use it as the featured image,
 * and entries that show it in their text.
 * ============================================================ */

function mcat_used_on($attachment_id) {

    global $wpdb;

    $attachment_id = (int) $attachment_id;
    $found         = [];

    $featured = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
        $attachment_id
    ));

    foreach ($featured as $post_id) {
        $found[(int) $post_id] = 'featured image';
    }

    $in_text = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_status IN ('publish','draft','private','pending','future')
           AND post_type NOT IN ('revision','attachment','nav_menu_item','customize_changeset','oembed_cache')
           AND post_content REGEXP %s
         LIMIT 20",
        'wp-image-' . $attachment_id . '([^0-9]|$)'
    ));

    foreach ($in_text as $post_id) {
        if (!isset($found[(int) $post_id])) {
            $found[(int) $post_id] = 'in the text';
        }
    }

    $out = [];

    foreach ($found as $post_id => $how) {

        $post = get_post($post_id);

        if ($post) {
            $out[] = ['id' => $post_id, 'title' => $post->post_title !== '' ? $post->post_title : '(no title)', 'type' => $post->post_type, 'how' => $how];
        }
    }

    return $out;
}

function mcat_used_html($used) {

    if (!$used) {
        return '<span style="color:#646970">Not used anywhere</span>';
    }

    $lines = [];

    foreach (array_slice($used, 0, 4) as $item) {

        $object = get_post_type_object($item['type']);
        $label  = $object ? $object->labels->singular_name : $item['type'];

        $lines[] = '<a href="' . esc_url(get_edit_post_link($item['id'])) . '">' . esc_html($item['title']) . '</a>'
            . ' <span style="color:#646970">(' . esc_html($label) . ', ' . esc_html($item['how']) . ')</span>';
    }

    if (count($used) > 4) {
        $lines[] = '<span style="color:#646970">and ' . (count($used) - 4) . ' more</span>';
    }

    return implode('<br>', $lines);
}

add_filter('manage_media_columns', function ($columns) {
    $columns['mcat_used'] = 'Actually used on';
    return $columns;
});

add_action('manage_media_custom_column', function ($column, $attachment_id) {

    if ($column === 'mcat_used') {
        echo mcat_used_html(mcat_used_on($attachment_id));
    }
}, 10, 2);

add_action('admin_menu', function () {
    add_media_page('Sort into categories', 'Sort into categories', 'upload_files', 'media-sort-categories', 'mcat_sort_page');
});

function mcat_sort_page() {

    if (!current_user_can('upload_files')) {
        return;
    }

    $types   = mcat_sort_types();
    $message = '';

    if (isset($_POST['mcat_sort']) && check_admin_referer('mcat_sort', 'mcat_nonce')) {

        $ticked = isset($_POST['mcat_type']) ? array_map('sanitize_key', (array) wp_unslash($_POST['mcat_type'])) : [];
        $names  = isset($_POST['mcat_name']) ? (array) wp_unslash($_POST['mcat_name']) : [];
        $done   = [];

        foreach ($ticked as $type) {

            if (!isset($types[$type])) {
                continue;
            }

            $name = isset($names[$type]) ? sanitize_text_field($names[$type]) : '';

            if ($name === '') {
                continue;
            }

            $term_id = mcat_term_id($name);

            if (!$term_id) {
                continue;
            }

            $count = 0;

            foreach (mcat_untagged(mcat_ids_for_type($type)) as $attachment_id) {
                wp_set_object_terms($attachment_id, [$term_id], mcat_tax(), true);
                $count++;
            }

            $done[] = $count . ' to "' . $name . '"';
        }

        $message = $done
            ? '<div class="notice notice-success"><p>Filed: ' . esc_html(implode(', ', $done)) . '.</p></div>'
            : '<div class="notice notice-info"><p>Nothing was filed. Tick at least one row and give it a category name.</p></div>';
    }

    /* Filing pictures ticked in the review list below */
    if (isset($_POST['mcat_review']) && check_admin_referer('mcat_review', 'mcat_review_nonce')) {

        $ids      = isset($_POST['mcat_pic']) ? array_map('absint', (array) $_POST['mcat_pic']) : [];
        $new_name = isset($_POST['mcat_review_new']) ? sanitize_text_field(wp_unslash($_POST['mcat_review_new'])) : '';
        $term_id  = $new_name !== '' ? mcat_term_id($new_name) : (isset($_POST['mcat_review_term']) ? absint($_POST['mcat_review_term']) : 0);
        $count    = 0;

        if ($term_id && term_exists($term_id, mcat_tax())) {

            foreach ($ids as $id) {
                if ($id && current_user_can('edit_post', $id)) {
                    wp_set_object_terms($id, [$term_id], mcat_tax(), true);
                    $count++;
                }
            }

            $message .= '<div class="notice notice-success"><p>Filed ' . (int) $count . ' picture' . ($count === 1 ? '' : 's') . '.</p></div>';

        } else {

            $message .= '<div class="notice notice-info"><p>Nothing was filed. Tick some pictures and choose (or type) a category first.</p></div>';
        }
    }

    echo '<div class="wrap"><h1>Sort into categories</h1>' . $message;

    echo '<p>This files pictures by where you use them. For each row, every picture used as that kind of entry\'s featured image '
        . '(or attached to it) that has <strong>no category yet</strong> is added to the category you name. '
        . 'It never changes a picture that already has a category, and it never removes anything. '
        . 'You can run it again whenever you add more.</p>';

    echo '<form method="post">';
    wp_nonce_field('mcat_sort', 'mcat_nonce');
    echo '<table class="widefat striped" style="max-width:760px"><thead><tr>'
        . '<td class="check-column"></td><th>Pictures used by</th><th>Not yet categorised</th><th>File them in this category</th></tr></thead><tbody>';

    foreach ($types as $type => $default) {

        $object = get_post_type_object($type);
        $label  = $object ? $object->labels->name : $type;
        $count  = count(mcat_untagged(mcat_ids_for_type($type)));

        echo '<tr><th class="check-column"><input type="checkbox" name="mcat_type[]" value="' . esc_attr($type) . '"'
            . ($type === 'book' ? ' checked' : '') . '></th>'
            . '<td>' . esc_html($label) . '</td>'
            . '<td>' . (int) $count . '</td>'
            . '<td><input type="text" name="mcat_name[' . esc_attr($type) . ']" value="' . esc_attr($default) . '" class="regular-text"></td></tr>';
    }

    echo '</tbody></table>';
    echo '<p><button type="submit" name="mcat_sort" value="1" class="button button-primary">File the ticked rows</button></p>';
    echo '<p class="description">Books is ticked to start with. Leave the others unticked if you want those pictures to stay "uncategorised" for now.</p>';
    echo '</form>';

    /* ---------------- review the rest ---------------- */

    $per_page = 40;
    $paged    = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);

    $left = new WP_Query([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => 'image',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'tax_query'      => [['taxonomy' => mcat_tax(), 'operator' => 'NOT EXISTS']],
    ]);

    echo '<h2 style="margin-top:2em">Pictures with no category</h2>';

    if (!$left->have_posts()) {

        echo '<p>Every picture has a category.</p></div>';
        return;
    }

    echo '<p><strong>' . (int) $left->found_posts . '</strong> picture' . ($left->found_posts === 1 ? '' : 's') . ' still uncategorised. '
        . 'The "Used on" column shows where each one is really used. Pictures used by a Book are ticked for you. '
        . 'Tick or untick, choose a category, and press the button.</p>';

    $terms = mcat_terms();

    echo '<form method="post" action="' . esc_url(add_query_arg('paged', $paged)) . '">';
    wp_nonce_field('mcat_review', 'mcat_review_nonce');

    echo '<p><select name="mcat_review_term"><option value="">File ticked pictures in…</option>';
    foreach ($terms as $term) {
        echo '<option value="' . (int) $term->term_id . '"' . selected(strtolower($term->name), strtolower(mcat_review_default_name()), false) . '>' . esc_html($term->mcat_label) . '</option>';
    }
    echo '</select> or a new category: <input type="text" name="mcat_review_new" placeholder="Name" class="regular-text"> '
        . '<button type="submit" name="mcat_review" value="1" class="button button-primary">File ticked pictures</button> '
        . '<label style="margin-left:12px"><input type="checkbox" id="mcat-pic-all"> Tick all</label></p>';

    echo '<table class="widefat striped" style="max-width:980px"><thead><tr><td class="check-column"></td><th>Picture</th><th>File</th><th>Used on</th></tr></thead><tbody>';

    foreach ($left->posts as $picture) {

        $used   = mcat_used_on($picture->ID);
        $isbook = false;

        foreach ($used as $item) {
            if ($item['type'] === 'book') {
                $isbook = true;
            }
        }

        echo '<tr><th class="check-column"><input type="checkbox" class="mcat-pic" name="mcat_pic[]" value="' . (int) $picture->ID . '"' . ($isbook ? ' checked' : '') . '></th>'
            . '<td>' . wp_get_attachment_image($picture->ID, [70, 70]) . '</td>'
            . '<td><a href="' . esc_url(get_edit_post_link($picture->ID)) . '" target="_blank">' . esc_html($picture->post_title !== '' ? $picture->post_title : '(no title)') . '</a><br>'
            . '<span style="color:#646970">' . esc_html(basename((string) get_attached_file($picture->ID))) . '</span></td>'
            . '<td>' . mcat_used_html($used) . '</td></tr>';
    }

    echo '</tbody></table></form>';

    if ($left->max_num_pages > 1) {

        echo '<p>Page ' . (int) $paged . ' of ' . (int) $left->max_num_pages . ' &nbsp; ';

        if ($paged > 1) {
            echo '<a class="button" href="' . esc_url(add_query_arg('paged', $paged - 1)) . '">Previous</a> ';
        }

        if ($paged < $left->max_num_pages) {
            echo '<a class="button" href="' . esc_url(add_query_arg('paged', $paged + 1)) . '">Next</a>';
        }

        echo '</p><p class="description">File the ticked pictures before changing page. Once filed, they leave this list.</p>';
    }

    echo '<script>document.getElementById("mcat-pic-all").addEventListener("change",function(e){document.querySelectorAll(".mcat-pic").forEach(function(b){b.checked=e.target.checked;});});</script>';

    echo '</div>';
}

/* The category name offered first in the review list. */
function mcat_review_default_name() {
    return function_exists('bcc_category_name') ? bcc_category_name() : 'Book covers';
}
