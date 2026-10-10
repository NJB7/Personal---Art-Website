/* Work: finished pieces (artwork, essays, anything "done")
 *
 * A separate post type from blog posts, Projects, Pins and Journal entries.
 * Use it for finished work you want to collect on a gallery page.
 *
 *   [work_index]         a gallery of finished work (flat)
 *   [work_groups]        the same, grouped under category headings (or years)
 *   [work_from_project]  on a Project page: "Finished work" from that Project
 *   [work_details]       on a single Work page: year, materials, "From the project"
 *   [work_series]        tiles for your Series (bodies of work)
 *
 * A Series is a body of work. Pick one or more per piece in the editor.
 * Each Series has its own page (/series/name/) showing its pieces, plus an
 * optional cover image and statement (Work > Series).
 *
 * After first activating this, go to Settings > Permalinks and click Save
 * once so the /work/ addresses work.
 *
 * Categories and tags are shared with the rest of the site, so a Work in
 * Form > Printmaking appears next to your Projects and Journal entries.
 */


/* ============================================================
 * The Work post type
 * ============================================================ */

function wk_register_post_type() {

    register_post_type('work', [
        'labels' => [
            'name'          => 'Work',
            'singular_name' => 'Work',
            'add_new'       => 'Add New',
            'add_new_item'  => 'Add New Work',
            'edit_item'     => 'Edit Work',
            'view_item'     => 'View Work',
            'search_items'  => 'Search Work',
            'all_items'     => 'All Work',
            'menu_name'     => 'Work',
        ],
        'public'        => true,
        'hierarchical'  => false,
        'has_archive'   => false,
        'show_in_rest'  => true,
        'menu_icon'     => 'dashicons-art',
        'menu_position' => 7,
        'rewrite'       => ['slug' => 'work', 'with_front' => false],
        'supports'      => ['title', 'editor', 'thumbnail', 'excerpt', 'author', 'revisions', 'comments', 'custom-fields'],
        'taxonomies'    => ['category', 'post_tag'],
    ]);

    register_taxonomy_for_object_type('category', 'work');
    register_taxonomy_for_object_type('post_tag', 'work');
}
add_action('init', 'wk_register_post_type');


/* ============================================================
 * Series (bodies of work)
 * ============================================================ */

function wk_register_series() {

    register_taxonomy('series', 'work', [
        'labels' => [
            'name'          => 'Series',
            'singular_name' => 'Series',
            'menu_name'     => 'Series',
            'all_items'     => 'All Series',
            'edit_item'     => 'Edit Series',
            'view_item'     => 'View Series',
            'add_new_item'  => 'Add New Series',
            'new_item_name' => 'New Series name',
            'search_items'  => 'Search Series',
            'not_found'     => 'No series yet.',
        ],
        'public'            => true,
        'hierarchical'      => true, /* gives tick boxes in the editor; used flat */
        'show_in_rest'      => true,
        'show_admin_column' => true,
        'rewrite'           => ['slug' => 'series', 'with_front' => false],
    ]);
}
add_action('init', 'wk_register_series');

/* Optional cover image for a Series, picked from the Media Library. */
function wk_series_image_field($term_id = 0) {

    $image_id = $term_id ? (int) get_term_meta($term_id, '_series_image', true) : 0;
    $preview  = $image_id ? wp_get_attachment_image($image_id, 'thumbnail') : '';

    echo '<input type="hidden" name="series_image_id" id="series-image-id" value="' . (int) $image_id . '">';
    echo '<div id="series-image-preview" style="margin:.5em 0">' . $preview . '</div>';
    echo '<button type="button" class="button" id="series-image-choose">Choose image</button> ';
    echo '<button type="button" class="button" id="series-image-clear"' . ($image_id ? '' : ' style="display:none"') . '>Remove</button>';
    echo '<p class="description">Shown on the Series tile. If left blank, the image of one of its pieces is used.</p>';

    static $script = false;

    if ($script) {
        return;
    }

    $script = true;

    echo <<<'JS'
<script>
jQuery(function ($) {
    var frame;
    $('#series-image-choose').on('click', function (e) {
        e.preventDefault();
        if (!frame) {
            frame = wp.media({ title: 'Series cover image', button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false });
            frame.on('select', function () {
                var item = frame.state().get('selection').first().toJSON();
                var url = (item.sizes && item.sizes.thumbnail) ? item.sizes.thumbnail.url : item.url;
                $('#series-image-id').val(item.id);
                $('#series-image-preview').html('<img src="' + url + '" style="max-width:150px;height:auto">');
                $('#series-image-clear').show();
            });
        }
        frame.open();
    });
    $('#series-image-clear').on('click', function (e) {
        e.preventDefault();
        $('#series-image-id').val('');
        $('#series-image-preview').empty();
        $(this).hide();
    });
    /* After "Add New Series" saves by Ajax, clear the picture for the next one */
    $(document).ajaxComplete(function (event, xhr, settings) {
        if (settings && settings.data && String(settings.data).indexOf('action=add-tag') !== -1) {
            $('#series-image-id').val('');
            $('#series-image-preview').empty();
            $('#series-image-clear').hide();
        }
    });
});
</script>
JS;
}

add_action('series_add_form_fields', function () {
    echo '<div class="form-field"><label>Cover image</label>';
    wk_series_image_field(0);
    echo '</div>';
});

add_action('series_edit_form_fields', function ($term) {
    echo '<tr class="form-field"><th scope="row"><label>Cover image</label></th><td>';
    wk_series_image_field($term->term_id);
    echo '</td></tr>';
});

function wk_save_series_image($term_id) {

    if (!isset($_POST['series_image_id'])) {
        return;
    }

    $image_id = absint($_POST['series_image_id']);

    if ($image_id && wp_attachment_is_image($image_id)) {
        update_term_meta($term_id, '_series_image', $image_id);
    } else {
        delete_term_meta($term_id, '_series_image');
    }
}
add_action('created_series', 'wk_save_series_image');
add_action('edited_series', 'wk_save_series_image');

add_action('admin_enqueue_scripts', function ($hook) {

    if (in_array($hook, ['edit-tags.php', 'term.php'], true) && isset($_GET['taxonomy']) && $_GET['taxonomy'] === 'series') {
        wp_enqueue_media();
    }
});

/* The picture for a Series tile: its cover image, otherwise the featured
   image of one of its pieces. */
function wk_series_cover($term) {

    $image_id = (int) get_term_meta($term->term_id, '_series_image', true);

    if ($image_id && wp_attachment_is_image($image_id)) {
        return wp_get_attachment_image($image_id, 'large', false, ['alt' => '', 'loading' => 'lazy']);
    }

    $found = get_posts([
        'post_type'      => 'work',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'tax_query'      => [['taxonomy' => 'series', 'field' => 'term_id', 'terms' => [(int) $term->term_id]]],
        'meta_query'     => [['key' => '_thumbnail_id', 'compare' => 'EXISTS']],
    ]);

    return $found ? get_the_post_thumbnail($found[0]->ID, 'large', ['alt' => '', 'loading' => 'lazy']) : '';
}


/* ============================================================
 * "Work details" box in the editor
 *   Year, Materials / size, and which Project it came from.
 *   All optional.
 * ============================================================ */

function wk_add_details_box() {
    add_meta_box('wk_details', 'Work details', 'wk_render_details_box', 'work', 'side', 'high');
}
add_action('add_meta_boxes', 'wk_add_details_box');

function wk_render_details_box($post) {

    wp_nonce_field('wk_details', 'wk_details_nonce');

    $year    = get_post_meta($post->ID, '_work_year', true);
    $details = get_post_meta($post->ID, '_work_details', true);
    $from    = (int) get_post_meta($post->ID, '_work_project', true);

    echo '<p><label for="wk_year"><strong>Year</strong></label><br>'
        . '<input type="text" id="wk_year" name="wk_year" value="' . esc_attr($year) . '" style="width:100%" placeholder="e.g. 2026"></p>';

    echo '<p><label for="wk_details"><strong>Materials / size</strong></label><br>'
        . '<input type="text" id="wk_details" name="wk_details" value="' . esc_attr($details) . '" style="width:100%" placeholder="e.g. Collagraph print, 20 x 30 cm"></p>';

    $projects = get_posts([
        'post_type'      => 'project',
        'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
        'posts_per_page' => -1,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ]);

    echo '<p><label for="wk_project"><strong>Made in Project</strong></label><br>';
    echo '<select id="wk_project" name="wk_project" style="width:100%"><option value="0">None</option>';

    if ($projects) {

        $known    = wp_list_pluck($projects, 'ID');
        $children = [];

        foreach ($projects as $project) {
            $parent = in_array((int) $project->post_parent, $known, true) ? (int) $project->post_parent : 0;
            $children[$parent][] = $project;
        }

        $print = function ($parent, $depth) use (&$print, $children, $from) {

            if (empty($children[$parent])) {
                return;
            }

            foreach ($children[$parent] as $project) {
                echo '<option value="' . (int) $project->ID . '"' . selected($from, (int) $project->ID, false) . '>'
                    . esc_html(str_repeat('— ', $depth) . ($project->post_title !== '' ? $project->post_title : '(no title)'))
                    . '</option>';
                $print((int) $project->ID, $depth + 1);
            }
        };

        $print(0, 0);
    }

    echo '</select></p>';
    echo '<p style="opacity:.7;margin:0">This Work will then appear on that Project\'s page.</p>';
}

function wk_save_details($post_id) {

    if (!isset($_POST['wk_details_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wk_details_nonce'])), 'wk_details')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $year    = isset($_POST['wk_year']) ? sanitize_text_field(wp_unslash($_POST['wk_year'])) : '';
    $details = isset($_POST['wk_details']) ? sanitize_text_field(wp_unslash($_POST['wk_details'])) : '';
    $from    = isset($_POST['wk_project']) ? absint($_POST['wk_project']) : 0;

    foreach (['_work_year' => $year, '_work_details' => $details] as $key => $value) {
        if ($value === '') {
            delete_post_meta($post_id, $key);
        } else {
            update_post_meta($post_id, $key, $value);
        }
    }

    if ($from && get_post_type($from) === 'project') {
        update_post_meta($post_id, '_work_project', $from);
    } else {
        delete_post_meta($post_id, '_work_project');
    }
}
add_action('save_post_work', 'wk_save_details');


/* ============================================================
 * Helpers
 * ============================================================ */

/* Year shown for a Work: the Year box, otherwise the year it was published. */
function wk_year($post) {

    $year = trim((string) get_post_meta($post->ID, '_work_year', true));

    return $year !== '' ? $year : get_the_date('Y', $post);
}

/* A Project's ID plus the IDs of everything beneath it. */
function wk_project_family($project_id) {

    $ids   = [(int) $project_id];
    $queue = [(int) $project_id];

    while ($queue) {

        $kids = get_posts([
            'post_type'      => 'project',
            'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
            'post_parent__in' => $queue,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);

        $queue = array_diff(array_map('intval', $kids), $ids);
        $ids   = array_merge($ids, $queue);
    }

    return $ids;
}

/* Finds Work. Options: cat_in, cat_not_in (category IDs), no_cat (only
   Work with no category), tag (slugs), ids, projects (project IDs),
   orderby, order, limit. */
function wk_get_works($o = []) {

    $o = array_merge([
        'cat_in'     => [],
        'cat_not_in' => [],
        'no_cat'     => false,
        'series_in'  => [],
        'no_series'  => false,
        'tag'        => '',
        'ids'        => [],
        'projects'   => [],
        'orderby'    => 'date',
        'order'      => 'DESC',
        'limit'      => 0,
    ], $o);

    $orderby = in_array($o['orderby'], ['date', 'title', 'menu_order', 'modified'], true) ? $o['orderby'] : 'date';

    $args = [
        'post_type'      => 'work',
        'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
        'posts_per_page' => ($o['limit'] > 0 && $o['orderby'] !== 'year') ? (int) $o['limit'] : -1,
        'orderby'        => $orderby,
        'order'          => strtoupper($o['order']) === 'ASC' ? 'ASC' : 'DESC',
        'no_found_rows'  => true,
    ];

    $tax = [];

    if ($o['cat_in']) {
        $tax[] = ['taxonomy' => 'category', 'field' => 'term_id', 'terms' => array_map('intval', (array) $o['cat_in']), 'include_children' => true];
    }

    if ($o['cat_not_in']) {
        $tax[] = ['taxonomy' => 'category', 'field' => 'term_id', 'terms' => array_map('intval', (array) $o['cat_not_in']), 'include_children' => true, 'operator' => 'NOT IN'];
    }

    if ($o['no_cat']) {
        $tax[] = ['taxonomy' => 'category', 'operator' => 'NOT EXISTS'];
    }

    if ($o['series_in']) {
        $tax[] = ['taxonomy' => 'series', 'field' => 'term_id', 'terms' => array_map('intval', (array) $o['series_in'])];
    }

    if ($o['no_series']) {
        $tax[] = ['taxonomy' => 'series', 'operator' => 'NOT EXISTS'];
    }

    if ($o['tag']) {
        $tax[] = ['taxonomy' => 'post_tag', 'field' => 'slug', 'terms' => (array) $o['tag']];
    }

    if ($tax) {
        $tax['relation']   = 'AND';
        $args['tax_query'] = $tax;
    }

    if ($o['ids']) {
        $args['post__in'] = array_map('intval', (array) $o['ids']);
    }

    if ($o['projects']) {
        $args['meta_query'] = [[
            'key'     => '_work_project',
            'value'   => array_map('intval', (array) $o['projects']),
            'compare' => 'IN',
        ]];
    }

    $query = new WP_Query($args);

    $works = array_values(array_filter($query->posts, function ($work) {
        return current_user_can('read_post', $work->ID);
    }));

    if ($o['orderby'] === 'year') {

        usort($works, function ($a, $b) use ($o) {
            $cmp = (int) wk_year($a) <=> (int) wk_year($b);
            return strtoupper($o['order']) === 'ASC' ? $cmp : -$cmp;
        });

        if ($o['limit'] > 0) {
            $works = array_slice($works, 0, (int) $o['limit']);
        }
    }

    return $works;
}

function wk_styles() {

    static $done = false;

    if ($done) {
        return '';
    }

    $done = true;

    return '<style>
        .work-gallery { column-width: var(--work-col, 220px); column-gap: 1.25rem; margin: 1rem 0 1.5rem; }
        .work-item { display: block; break-inside: avoid; margin: 0 0 1.25rem; text-decoration: none; color: inherit; }
        .work-item:hover .work-image, .work-item:focus .work-image { opacity: .88; }
        .work-image img { display: block; width: 100%; height: auto; border-radius: 3px; }
        .work-caption { margin-top: .45rem; line-height: 1.25; font-size: .95rem; }
        .work-title { font-weight: 600; }
        .work-year, .work-status { opacity: .7; font-size: .85em; }
        .work-noimage { display: block; padding: 1rem; border: 1px solid rgba(128,128,128,.35); border-radius: 3px; }
        .work-noimage .work-excerpt { margin-top: .4rem; font-size: .9rem; opacity: .85; }
        .work-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(var(--work-col, 220px), 1fr)); gap: 1.25rem; margin: 1rem 0 1.5rem; }
        .work-grid .work-item { margin: 0; }
        .work-grid .work-image { display: flex; align-items: center; justify-content: center; aspect-ratio: 1 / 1; }
        .work-grid .work-image img { width: auto; max-width: 100%; max-height: 100%; object-fit: contain; }
        .work-group-heading { margin-top: 2em; margin-bottom: 1em; }
        .work-series .work-image { outline: 1px solid rgba(128,128,128,.35); outline-offset: 4px; }
    </style>';
}

/* Draws Work as a gallery (natural proportions, Pinterest-style columns)
   or as square tiles. Essays and other Work with no image show a text card. */
function wk_render($works, $layout, $show_title, $show_year, $size) {

    $html = wk_styles();

    $class = $layout === 'tiles' ? 'work-grid' : 'work-gallery';

    $html .= '<div class="' . $class . '" style="--work-col:' . (int) $size . 'px">';

    foreach ($works as $work) {

        /* A Series tile (from series="collapse" or [work_series]) */
        if ($work instanceof WP_Term) {

            $link = get_term_link($work);

            if (is_wp_error($link)) {
                continue;
            }

            $cover = wk_series_cover($work);
            $count = (int) $work->count;

            $html .= '<a class="work-item work-series" href="' . esc_url($link) . '">';

            if ($cover) {
                $html .= '<span class="work-image">' . $cover . '</span>';
                $html .= '<span class="work-caption"><span class="work-title">' . esc_html($work->name) . '</span><br>'
                    . '<span class="work-year">Series &middot; ' . $count . ($count === 1 ? ' piece' : ' pieces') . '</span></span>';
            } else {
                $html .= '<span class="work-noimage"><span class="work-title">' . esc_html($work->name) . '</span><br>'
                    . '<span class="work-year">Series &middot; ' . $count . ($count === 1 ? ' piece' : ' pieces') . '</span></span>';
            }

            $html .= '</a>';

            continue;
        }

        $title = $work->post_title !== '' ? $work->post_title : '(no title)';

        $image = get_the_post_thumbnail($work->ID, 'large', [
            'alt'     => $show_title ? '' : $title,
            'loading' => 'lazy',
        ]);

        if (!$image && preg_match('/wp-image-(\d+)/', (string) $work->post_content, $m)) {
            $image = wk_attachment_image($m[1], $show_title ? '' : $title);
        }

        $html .= '<a class="work-item" href="' . esc_url(get_permalink($work)) . '">';

        if ($image) {

            $html .= '<span class="work-image">' . $image . '</span>';

            if ($show_title || $show_year) {
                $html .= '<span class="work-caption">';
                if ($show_title) {
                    $html .= '<span class="work-title">' . esc_html($title) . '</span>';
                }
                if ($show_year) {
                    $html .= ($show_title ? '<br>' : '') . '<span class="work-year">' . esc_html(wk_year($work)) . '</span>';
                }
                $html .= '</span>';
            }

        } else {

            $excerpt = wp_trim_words(wp_strip_all_tags(has_excerpt($work) ? $work->post_excerpt : $work->post_content), 24);

            $html .= '<span class="work-noimage"><span class="work-title">' . esc_html($title) . '</span>';

            if ($show_year) {
                $html .= '<br><span class="work-year">' . esc_html(wk_year($work)) . '</span>';
            }

            if ($excerpt !== '') {
                $html .= '<span class="work-excerpt" style="display:block">' . esc_html($excerpt) . '</span>';
            }

            $html .= '</span>';
        }

        if ($work->post_status !== 'publish') {
            $html .= '<span class="work-status">' . esc_html($work->post_status) . '</span>';
        }

        $html .= '</a>';
    }

    return $html . '</div>';
}

function wk_attachment_image($id, $alt) {

    $img = wp_get_attachment_image((int) $id, 'large', false, ['alt' => $alt, 'loading' => 'lazy']);

    return $img ?: '';
}

/* Replaces the pieces that belong to a Series with one tile per Series.
   Pieces in no Series stay as they are. Returns a mixed list of Work posts
   and Series terms. With orderby="date" they are interleaved by date;
   otherwise single pieces come first, then the Series by name. */
function wk_collapse_series($works, $orderby = 'date', $order = 'DESC') {

    $singles = [];
    $series  = [];

    foreach ($works as $work) {

        $terms = get_the_terms($work->ID, 'series');
        $time  = strtotime($work->post_date_gmt . ' UTC');

        if (!$terms || is_wp_error($terms)) {
            $singles[] = ['obj' => $work, 'time' => $time];
            continue;
        }

        foreach ($terms as $term) {

            if (!isset($series[$term->term_id])) {
                $series[$term->term_id] = ['obj' => $term, 'time' => $time];
            } elseif ($time > $series[$term->term_id]['time']) {
                $series[$term->term_id]['time'] = $time;
            }
        }
    }

    $series = array_values($series);

    if (in_array($orderby, ['date', 'year'], true)) {

        $items = array_merge($singles, $series);

        usort($items, function ($a, $b) use ($order) {
            $cmp = $a['time'] <=> $b['time'];
            return strtoupper($order) === 'ASC' ? $cmp : -$cmp;
        });

    } else {

        usort($series, function ($a, $b) {
            return strcasecmp($a['obj']->name, $b['obj']->name);
        });

        $items = array_merge($singles, $series);
    }

    return array_column($items, 'obj');
}

function wk_heading($name, $tag) {
    return '<' . $tag . ' class="work-group-heading">' . esc_html($name) . '</' . $tag . '>';
}

function wk_slug_list($value) {
    return array_values(array_filter(array_map('sanitize_title', explode(',', (string) $value))));
}


/* ============================================================
 * [work_index]
 *
 *   category    category slug(s), comma separated (subcategories included)
 *   tag         tag slug(s)
 *   project     "current", a Project ID or a slug: only Work made in it
 *   include_children  yes (default) = also Work from its sub-projects
 *   ids         hand-pick by ID
 *   orderby     date (default), year, title, menu_order, modified
 *   order       DESC (default, newest first) or ASC
 *   limit       maximum pieces
 *   layout      gallery (default; natural proportions) or tiles (squares)
 *   show_title  yes (default) or no
 *   show_year   yes (default) or no
 *   size        minimum column width in pixels (default 220)
 *   none        text shown when nothing matches (default: nothing)
 *   series      none = only pieces that are in no Series; current = the
 *               Series page being viewed (use on the Series template);
 *               or one or more Series slugs
 * ============================================================ */

function wk_resolve_projects($value, $include_children) {

    $value = trim((string) $value);

    if ($value === '') {
        return [];
    }

    if ($value === 'current') {
        $id = (int) (get_the_ID() ?: get_queried_object_id());
    } elseif (ctype_digit($value)) {
        $id = (int) $value;
    } else {
        $found = get_page_by_path(trim($value, '/'), OBJECT, 'project');
        $id    = $found ? (int) $found->ID : 0;
    }

    if (!$id) {
        return [-1];
    }

    return $include_children ? wk_project_family($id) : [$id];
}

function work_index_shortcode($atts) {

    $a = shortcode_atts([
        'category'         => '',
        'tag'              => '',
        'project'          => '',
        'include_children' => 'yes',
        'ids'              => '',
        'orderby'          => 'date',
        'order'            => 'DESC',
        'limit'            => 0,
        'layout'           => 'gallery',
        'show_title'       => 'yes',
        'show_year'        => 'yes',
        'size'             => 220,
        'none'             => '',
        'series'           => '',
    ], $atts, 'work_index');

    $cat_ids = [];

    foreach (wk_slug_list($a['category']) as $slug) {
        $term = get_category_by_slug($slug);
        if ($term) {
            $cat_ids[] = (int) $term->term_id;
        } else {
            return '';
        }
    }

    /* series: "" = all, "none" = only pieces in no series, "current" = the
       Series page being viewed, or one or more series slugs */
    $series_in = [];
    $no_series = false;
    $sv        = strtolower(trim($a['series']));

    if ($sv === 'none') {

        $no_series = true;

    } elseif ($sv === 'current') {

        $obj = get_queried_object();

        if ($obj instanceof WP_Term && $obj->taxonomy === 'series') {
            $series_in[] = (int) $obj->term_id;
        } else {
            return '';
        }

    } elseif ($sv !== '') {

        foreach (wk_slug_list($sv) as $slug) {
            $term = get_term_by('slug', $slug, 'series');
            if ($term) {
                $series_in[] = (int) $term->term_id;
            } else {
                return '';
            }
        }
    }

    $works = wk_get_works([
        'cat_in'    => $cat_ids,
        'series_in' => $series_in,
        'no_series' => $no_series,
        'tag'      => wk_slug_list($a['tag']),
        'ids'      => array_filter(array_map('absint', explode(',', (string) $a['ids']))),
        'projects' => wk_resolve_projects($a['project'], strtolower($a['include_children']) !== 'no'),
        'orderby'  => strtolower($a['orderby']),
        'order'    => $a['order'],
        'limit'    => (int) $a['limit'],
    ]);

    if (!$works) {
        return $a['none'] !== '' ? '<p>' . esc_html($a['none']) . '</p>' : '';
    }

    return wk_render(
        $works,
        strtolower($a['layout']) === 'tiles' ? 'tiles' : 'gallery',
        strtolower($a['show_title']) !== 'no',
        strtolower($a['show_year']) !== 'no',
        (int) $a['size']
    );
}
add_shortcode('work_index', 'work_index_shortcode');


/* ============================================================
 * [work_groups]
 *
 * Work grouped under headings, like [project_groups].
 *
 *   under       category slug: its subcategories become the headings
 *               (Work in deeper categories rolls up into them), e.g.
 *               under="form". Work filed in that category but in no
 *               subcategory goes under the "other" heading.
 *               Without under: every top-level category.
 *   by          category (default) or year
 *   exclude     category slugs to leave out, e.g. exclude="uncategorized"
 *   other       heading for the left-overs (default "Other")
 *   heading     h2 (default), h3, h4 or p
 *   order       DESC (default) / ASC: order of the pieces, and of the
 *               years when by="year"
 *   series      show (default) = every piece listed, as before
 *               collapse = pieces in a Series are replaced by one Series
 *                          tile, so nothing appears twice
 *               hide = only pieces that are in no Series
 *   layout, show_title, show_year, size, tag, project   as [work_index]
 * ============================================================ */

function work_groups_shortcode($atts) {

    $a = shortcode_atts([
        'under'            => '',
        'by'               => 'category',
        'exclude'          => '',
        'other'            => 'Other',
        'heading'          => 'h2',
        'order'            => 'DESC',
        'orderby'          => 'date',
        'layout'           => 'gallery',
        'show_title'       => 'yes',
        'show_year'        => 'yes',
        'size'             => 220,
        'tag'              => '',
        'project'          => '',
        'include_children' => 'yes',
        'series'           => 'show',
    ], $atts, 'work_groups');

    $heading    = in_array($a['heading'], ['h2', 'h3', 'h4', 'p'], true) ? $a['heading'] : 'h2';
    $layout     = strtolower($a['layout']) === 'tiles' ? 'tiles' : 'gallery';
    $show_title = strtolower($a['show_title']) !== 'no';
    $show_year  = strtolower($a['show_year']) !== 'no';
    $size       = (int) $a['size'];

    $base = [
        'tag'      => wk_slug_list($a['tag']),
        'projects' => wk_resolve_projects($a['project'], strtolower($a['include_children']) !== 'no'),
        'orderby'  => strtolower($a['orderby']),
        'order'    => $a['order'],
    ];

    /* series: show (default) = every piece as before; collapse = pieces in a
       Series become one Series tile, so nothing appears twice; hide = only
       pieces that are in no Series */
    $series_mode = strtolower($a['series']);
    $series_mode = in_array($series_mode, ['collapse', 'hide'], true) ? $series_mode : 'show';

    if ($series_mode === 'hide') {
        $base['no_series'] = true;
    }

    $prepare = function ($works) use ($series_mode, $base) {
        return $series_mode === 'collapse'
            ? wk_collapse_series($works, $base['orderby'], $base['order'])
            : $works;
    };

    $out = '';

    /* ---- grouped by year ---- */

    if (strtolower($a['by']) === 'year') {

        $by_year = [];

        foreach (wk_get_works($base) as $work) {
            $by_year[wk_year($work)][] = $work;
        }

        if (strtoupper($a['order']) === 'ASC') {
            ksort($by_year);
        } else {
            krsort($by_year);
        }

        foreach ($by_year as $year => $works) {
            $out .= wk_heading($year, $heading) . wk_render($prepare($works), $layout, $show_title, false, $size);
        }

        return $out;
    }

    /* ---- grouped by category ---- */

    $exclude = wk_slug_list($a['exclude']);
    $under   = null;

    if ($a['under'] !== '') {

        $under = get_category_by_slug(sanitize_title($a['under']));

        if (!$under) {
            return '';
        }

        $categories = get_categories(['parent' => $under->term_id, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);

    } else {

        $categories = get_categories(['parent' => 0, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
    }

    $categories = array_values(array_filter($categories, function ($cat) use ($exclude) {
        return !in_array($cat->slug, $exclude, true);
    }));

    foreach ($categories as $cat) {

        $works = wk_get_works($base + ['cat_in' => [$cat->term_id]]);

        if ($works) {
            $out .= wk_heading($cat->name, $heading) . wk_render($prepare($works), $layout, $show_title, $show_year, $size);
        }
    }

    /* Left-overs */
    if ($a['other'] !== '') {

        $extra = $under
            ? ['cat_in' => [$under->term_id], 'cat_not_in' => wp_list_pluck($categories, 'term_id')]
            : ['no_cat' => true];

        $works = wk_get_works($base + $extra);

        if ($works) {

            if ($under && !$categories) {
                $out .= wk_render($prepare($works), $layout, $show_title, $show_year, $size);
            } else {
                $out .= wk_heading($a['other'], $heading) . wk_render($prepare($works), $layout, $show_title, $show_year, $size);
            }
        }
    }

    return $out;
}
add_shortcode('work_groups', 'work_groups_shortcode');


/* ============================================================
 * [work_from_project]
 *
 * "Single item: Project" template. The finished Work made in this Project
 * (and its sub-projects). Prints nothing when there is none.
 *
 *   title             heading text (default "Finished work"); title="no" for none
 *   include_children  yes (default) or no
 *   heading           h2 (default), h3, h4 or p
 *   layout, show_title, show_year, size, orderby, order, limit   as [work_index]
 * ============================================================ */

function work_from_project_shortcode($atts) {

    $a = shortcode_atts([
        'title'            => 'Finished work',
        'include_children' => 'yes',
        'heading'          => 'h2',
        'layout'           => 'gallery',
        'show_title'       => 'yes',
        'show_year'        => 'yes',
        'size'             => 180,
        'orderby'          => 'date',
        'order'            => 'DESC',
        'limit'            => 0,
    ], $atts, 'work_from_project');

    $project_id = (int) (get_the_ID() ?: get_queried_object_id());

    if (!$project_id || get_post_type($project_id) !== 'project') {
        return '';
    }

    $works = wk_get_works([
        'projects' => strtolower($a['include_children']) === 'no' ? [$project_id] : wk_project_family($project_id),
        'orderby'  => strtolower($a['orderby']),
        'order'    => $a['order'],
        'limit'    => (int) $a['limit'],
    ]);

    if (!$works) {
        return '';
    }

    $heading = in_array($a['heading'], ['h2', 'h3', 'h4', 'p'], true) ? $a['heading'] : 'h2';
    $out     = '';

    if (strtolower($a['title']) !== 'no' && $a['title'] !== '') {
        $out .= '<' . $heading . '>' . esc_html($a['title']) . '</' . $heading . '>';
    }

    return $out . wk_render(
        $works,
        strtolower($a['layout']) === 'tiles' ? 'tiles' : 'gallery',
        strtolower($a['show_title']) !== 'no',
        strtolower($a['show_year']) !== 'no',
        (int) $a['size']
    );
}
add_shortcode('work_from_project', 'work_from_project_shortcode');


/* ============================================================
 * [work_details]
 *
 * "Single item: Work" template. A line such as
 *   2026 · Collagraph print, 20 x 30 cm · From the project: Collagraph · Series: Allerdale Hall
 * Prints nothing if the Work has no details.
 * ============================================================ */

function work_details_shortcode() {

    $id = (int) (get_the_ID() ?: get_queried_object_id());

    if (!$id || get_post_type($id) !== 'work') {
        return '';
    }

    $post    = get_post($id);
    $parts   = [];
    $details = trim((string) get_post_meta($id, '_work_details', true));
    $from    = (int) get_post_meta($id, '_work_project', true);

    $parts[] = esc_html(wk_year($post));

    if ($details !== '') {
        $parts[] = esc_html($details);
    }

    if ($from && get_post_status($from) && current_user_can('read_post', $from)) {
        $parts[] = 'From the project: <a href="' . esc_url(get_permalink($from)) . '">' . esc_html(get_the_title($from)) . '</a>';
    }

    $series = get_the_terms($id, 'series');

    if ($series && !is_wp_error($series)) {

        $links = [];

        foreach ($series as $term) {
            $url = get_term_link($term);
            if (!is_wp_error($url)) {
                $links[] = '<a href="' . esc_url($url) . '">' . esc_html($term->name) . '</a>';
            }
        }

        if ($links) {
            $parts[] = 'Series: ' . implode(', ', $links);
        }
    }

    return '<p class="work-details">' . implode(' &middot; ', $parts) . '</p>';
}
add_shortcode('work_details', 'work_details_shortcode');


/* ============================================================
 * [work_series]
 *
 * Tiles for your Series (bodies of work): the cover image, the name and the
 * number of pieces. Each tile opens that Series' page.
 *
 *   category    only Series that have a piece in these category slug(s)
 *   orderby     recent (default; newest piece first), name, count
 *   order       DESC (default) or ASC (for recent and count); name is A-Z
 *   limit       maximum Series
 *   layout      gallery (default) or tiles
 *   size        minimum column width in pixels (default 220)
 *   title       heading above the tiles (default none)
 *   heading     h2 (default), h3, h4 or p
 *   none        text shown when there are no Series (default: nothing)
 *
 * Example: [work_series title="Bodies of work"]
 * Prints nothing when there are no Series.
 * ============================================================ */

function work_series_shortcode($atts) {

    $a = shortcode_atts([
        'category' => '',
        'orderby'  => 'recent',
        'order'    => 'DESC',
        'limit'    => 0,
        'layout'   => 'gallery',
        'size'     => 220,
        'title'    => '',
        'heading'  => 'h2',
        'none'     => '',
    ], $atts, 'work_series');

    $cat_ids = [];

    foreach (wk_slug_list($a['category']) as $slug) {
        $term = get_category_by_slug($slug);
        if ($term) {
            $cat_ids[] = (int) $term->term_id;
        } else {
            return '';
        }
    }

    /* Work out which Series have pieces (newest piece first) */
    $series = [];

    foreach (wk_get_works(['cat_in' => $cat_ids, 'orderby' => 'date', 'order' => 'DESC']) as $work) {

        $terms = get_the_terms($work->ID, 'series');

        if (!$terms || is_wp_error($terms)) {
            continue;
        }

        foreach ($terms as $term) {

            if (!isset($series[$term->term_id])) {
                $series[$term->term_id] = ['term' => $term, 'count' => 0, 'time' => strtotime($work->post_date_gmt . ' UTC')];
            }

            $series[$term->term_id]['count']++;
        }
    }

    if (!$series) {
        return $a['none'] !== '' ? '<p>' . esc_html($a['none']) . '</p>' : '';
    }

    $series  = array_values($series);
    $desc    = strtoupper($a['order']) !== 'ASC';
    $orderby = strtolower($a['orderby']);

    usort($series, function ($x, $y) use ($orderby, $desc) {

        if ($orderby === 'name') {
            return strcasecmp($x['term']->name, $y['term']->name);
        }

        $cmp = $orderby === 'count' ? $x['count'] <=> $y['count'] : $x['time'] <=> $y['time'];

        return $desc ? -$cmp : $cmp;
    });

    if ((int) $a['limit'] > 0) {
        $series = array_slice($series, 0, (int) $a['limit']);
    }

    $items = [];

    foreach ($series as $row) {
        $row['term']->count = $row['count'];
        $items[] = $row['term'];
    }

    $heading = in_array($a['heading'], ['h2', 'h3', 'h4', 'p'], true) ? $a['heading'] : 'h2';
    $out     = '';

    if ($a['title'] !== '') {
        $out .= wk_heading($a['title'], $heading);
    }

    return $out . wk_render($items, strtolower($a['layout']) === 'tiles' ? 'tiles' : 'gallery', true, false, (int) $a['size']);
}
add_shortcode('work_series', 'work_series_shortcode');
