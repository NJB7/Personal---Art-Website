/* Project Journal: connects regular blog posts to Projects
 *
 * Journal entries are their own post type (Journal), separate from blog posts,
 * so they stay out of the blog feed and the WordPress Reader.
 *
 * In the editor
 *   Journal entries get a "Projects" box: tick one or more Projects (search box,
 *   indented tree, recently used first). New Projects appear automatically.
 *   Projects get a "Status" box: Idea, In progress or Finished.
 *
 * Shortcodes
 *   [project_journal]      On a Project page: a board of the posts ticked for it.
 *   [post_project_nav]     On the blog post template: "Part of: ..." plus
 *                          previous / next post within each project.
 *   [project_gallery]      A Project's sub-projects grouped by status, medium
 *                          or subject (uses your existing categories).
 *   [post_groups]          Posts grouped by category, tag, month or year,
 *                          for pages like the library.
 *
 * Needs the "Create Project Post Type Function" snippet (the Project type).
 * [project_gallery] needs the "Project Index & Tree" snippet.
 */


/* ============================================================
 * Shared helpers
 * ============================================================ */

function pj_slug_list($value) {
    return array_values(array_filter(array_map('sanitize_title', explode(',', (string) $value))));
}

function pj_styles() {

    static $done = false;

    if ($done) {
        return '';
    }

    $done = true;

    return '<style>
        .post-index { display: grid; grid-template-columns: repeat(auto-fill, minmax(var(--post-tile, 160px), 1fr)); gap: 1rem; margin: 1rem 0; }
        .post-tile { display: flex; flex-direction: column; align-items: center; text-align: center; text-decoration: none; color: inherit; padding: .75rem; border: 1px solid rgba(128,128,128,.3); border-radius: 8px; transition: transform .15s, box-shadow .15s; }
        .post-tile:hover, .post-tile:focus { transform: translateY(-3px); box-shadow: 0 6px 16px rgba(0,0,0,.15); }
        .post-tile-image { display: flex; align-items: center; justify-content: center; width: 100%; aspect-ratio: 1 / 1; }
        .post-tile-image img { max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain; }
        .post-tile--text { justify-content: center; min-height: calc(var(--post-tile, 160px) * .8); }
        .post-tile--text .post-tile-title { margin-top: 0; font-size: 1.15rem; }
        .post-tile-title { margin-top: .6rem; font-weight: 600; line-height: 1.25; }
        .post-tile-date, .post-tile-status { font-size: .8rem; opacity: .7; margin-top: .15rem; }
        .post-list { list-style: none; margin: .5rem 0 1.5rem; padding: 0; }
        .post-list li { display: flex; gap: 1em; padding: .35em 0; border-bottom: 1px solid rgba(128,128,128,.25); }
        .post-list-date { flex: 0 0 8.5em; opacity: .7; }
        .post-group-heading { margin-top: 1.5em; }
    </style>';
}

/* Image for a post: the featured image, otherwise the first image in its text. */
function pj_post_image($post, $title, $show_title) {

    $attr = ['alt' => $show_title ? '' : $title, 'loading' => 'lazy'];

    $thumb = get_the_post_thumbnail($post->ID, 'medium', $attr);

    if ($thumb) {
        return $thumb;
    }

    if (preg_match('/wp-image-(\d+)/', (string) $post->post_content, $m)) {
        return wp_get_attachment_image((int) $m[1], 'medium', false, $attr);
    }

    return '';
}

/* Draws a set of posts as tiles or as a list. Dates show month and year only. */
function pj_render_posts($posts, $layout, $show_date, $show_title, $tile_min) {

    $html = pj_styles();

    if ($layout === 'tiles') {

        $html .= '<div class="post-index" style="--post-tile:' . (int) $tile_min . 'px">';

        foreach ($posts as $post) {

            $title = $post->post_title !== '' ? $post->post_title : '(no title)';

            $thumb = pj_post_image($post, $title, $show_title);

            /* No picture: the box just shows the title */
            $html .= '<a class="post-tile' . ($thumb ? '' : ' post-tile--text') . '" href="' . esc_url(get_permalink($post)) . '">';

            if ($thumb) {
                $html .= '<span class="post-tile-image">' . $thumb . '</span>';
            }

            if ($show_title || !$thumb) {
                $html .= '<span class="post-tile-title">' . esc_html($title) . '</span>';
            }

            if ($show_date) {
                $html .= '<span class="post-tile-date">' . esc_html(get_the_date('F Y', $post)) . '</span>';
            }

            if ($post->post_status !== 'publish') {
                $html .= '<span class="post-tile-status">' . esc_html($post->post_status) . '</span>';
            }

            $html .= '</a>';
        }

        return $html . '</div>';
    }

    $html .= '<ul class="post-list">';

    foreach ($posts as $post) {

        $title = $post->post_title !== '' ? $post->post_title : '(no title)';

        $html .= '<li>';

        if ($show_date) {
            $html .= '<span class="post-list-date">' . esc_html(get_the_date('F Y', $post)) . '</span>';
        }

        $html .= '<span><a href="' . esc_url(get_permalink($post)) . '">' . esc_html($title) . '</a>';

        if ($post->post_status !== 'publish') {
            $html .= ' <em>(' . esc_html($post->post_status) . ')</em>';
        }

        $html .= '</span></li>';
    }

    return $html . '</ul>';
}


/* Splits posts under a sub-heading for each of their tags (alphabetical).
   A post with two tags appears under both; untagged posts go under $other. */
function pj_render_by_tag($posts, $layout, $show_date, $show_title, $tile_min, $sub_heading, $other, $limit) {

    $by_tag = [];
    $none   = [];

    foreach ($posts as $post) {

        $tags = get_the_terms($post->ID, 'post_tag');

        if (!$tags || is_wp_error($tags)) {
            $none[] = $post;
            continue;
        }

        foreach ($tags as $tag) {
            $by_tag[$tag->name][] = $post;
        }
    }

    ksort($by_tag, SORT_NATURAL | SORT_FLAG_CASE);

    if ($none) {
        $by_tag[$other] = $none;
    }

    $out = '';

    foreach ($by_tag as $label => $tag_posts) {

        if ($limit) {
            $tag_posts = array_slice($tag_posts, 0, $limit);
        }

        if ($label !== '') {
            $out .= '<' . $sub_heading . ' class="post-subgroup-heading">' . esc_html($label) . '</' . $sub_heading . '>';
        }

        $out .= pj_render_posts($tag_posts, $layout, $show_date, $show_title, $tile_min);
    }

    return $out;
}

/* ============================================================
 * [post_groups]
 *
 * Attributes (all optional):
 *   by          category (default), tag, month or year
 *   under       categories only. A category slug: its subcategories become
 *               the headings, and posts further down roll up to them
 *               (under="form" gives one heading per subcategory of Form).
 *               "top" = only top-level categories get headings.
 *   include     only these category/tag slugs, comma separated
 *   exclude     leave out these slugs, e.g. exclude="uncategorized"
 *   min         smallest number of posts a heading needs (default 1)
 *   sort_groups name (default), count (most posts first), recent
 *               (most recently written-in first)
 *   order       DESC = newest first (default), ASC = oldest first
 *   limit       maximum posts under each heading (default: all)
 *   layout      list (default, with dates) or tiles
 *   show_date   yes (default) or no
 *   show_title  yes (default) or no (tiles only)
 *   heading     h2 (default), h3, h4 or p
 *   size        minimum tile width in pixels (default 160)
 *   post_type   post (default). Use journal for Journal entries, project for
 *               Projects, or several together: post_type="post,journal".
 *   category    only posts in these category slugs (and their subcategories),
 *               e.g. category="books". Use it with by="tag" to get one
 *               heading per tag for a single category.
 *   title       auto (default) = when under= or category= names a single
 *               category, start with that category's name (e.g. "Books") as
 *               the page title. title="Text" uses your own words; title="no"
 *               turns it off. The headings below it then move down a level.
 *   flat        yes = one plain list of the matching posts with no group
 *               headings, e.g. [post_groups category="podcasts" flat="yes"]
 *   subgroup    tag = under each heading, add a smaller heading for every
 *               tag used there (e.g. Books > Fiction, Non-fiction).
 *   other       heading for posts with no tag when using subgroup="tag"
 *               (default "Other"; other="" shows them without a heading)
 *   none        text shown when nothing matches (default "No posts found.").
 *               none="" shows nothing at all.
 * ============================================================ */

function post_groups_shortcode($atts) {

    $atts = shortcode_atts([
        'by'          => 'category',
        'under'       => '',
        'include'     => '',
        'exclude'     => '',
        'min'         => 1,
        'sort_groups' => 'name',
        'order'       => 'DESC',
        'limit'       => 0,
        'layout'      => 'list',
        'show_date'   => 'yes',
        'show_title'  => 'yes',
        'heading'     => 'h2',
        'size'        => 160,
        'post_type'   => 'post',
        'category'    => '',
        'title'       => 'auto',
        'flat'        => 'no',
        'subgroup'    => '',
        'other'       => 'Other',
        'none'        => 'No posts found.',
    ], $atts, 'post_groups');

    $by          = in_array($atts['by'], ['category', 'tag', 'month', 'year'], true) ? $atts['by'] : 'category';
    $order       = strtoupper($atts['order']) === 'ASC' ? 'ASC' : 'DESC';
    $layout      = $atts['layout'] === 'tiles' ? 'tiles' : 'list';
    $show_date   = strtolower($atts['show_date']) !== 'no';
    $show_title  = strtolower($atts['show_title']) !== 'no';
    $heading     = in_array($atts['heading'], ['h2', 'h3', 'h4', 'p'], true) ? $atts['heading'] : 'h2';
    $min         = max(1, (int) $atts['min']);
    $limit       = max(0, (int) $atts['limit']);
    $tile_min    = max(80, absint($atts['size']));
    $include     = pj_slug_list($atts['include']);
    $exclude     = pj_slug_list($atts['exclude']);
    $sort_groups = in_array($atts['sort_groups'], ['name', 'count', 'recent'], true) ? $atts['sort_groups'] : 'name';
    $post_type   = array_values(array_filter(
        array_map('sanitize_key', explode(',', (string) $atts['post_type'])),
        'post_type_exists'
    )) ?: ['post'];
    $only_cats   = pj_slug_list($atts['category']);
    $subgroup    = strtolower($atts['subgroup']) === 'tag' ? 'tag' : '';

    $under_id = 0;
    $top_only = false;

    if ($by === 'category' && $atts['under'] !== '') {

        if (strtolower(trim($atts['under'])) === 'top') {

            $top_only = true;

        } else {

            $under = get_category_by_slug(sanitize_title($atts['under']));

            if (!$under) {
                return '<p>Category "' . esc_html($atts['under']) . '" was not found.</p>';
            }

            $under_id = (int) $under->term_id;
        }
    }

    /* One query for every post, then sort them into groups */

    $query_args = [
        'post_type'      => $post_type,
        'post_status'    => ['publish', 'private'],
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => $order,
        'no_found_rows'  => true,
    ];

    if ($only_cats) {
        $query_args['category_name'] = implode(',', $only_cats);
    }

    $query = new WP_Query($query_args);

    $groups = [];

    $add = function ($key, $label, $post) use (&$groups) {

        if (!isset($groups[$key])) {
            $groups[$key] = ['label' => $label, 'posts' => [], 'latest' => 0];
        }

        $groups[$key]['posts'][$post->ID] = $post;
        $groups[$key]['latest'] = max($groups[$key]['latest'], (int) get_post_timestamp($post));
    };

    foreach ($query->posts as $post) {

        if (!current_user_can('read_post', $post->ID)) {
            continue;
        }

        if ($by === 'month' || $by === 'year') {

            $stamp = (int) get_post_timestamp($post);

            if ($by === 'month') {
                $add('d' . wp_date('Y-m', $stamp), wp_date('F Y', $stamp), $post);
            } else {
                $add('d' . wp_date('Y', $stamp), wp_date('Y', $stamp), $post);
            }

            continue;
        }

        $taxonomy = $by === 'tag' ? 'post_tag' : 'category';
        $terms    = get_the_terms($post->ID, $taxonomy);

        if (!$terms || is_wp_error($terms)) {
            continue;
        }

        foreach ($terms as $term) {

            $target = $term;

            if ($under_id || $top_only) {

                /* Walk up to the heading this category rolls up into */
                $chain  = array_merge([(int) $term->term_id], get_ancestors($term->term_id, 'category', 'taxonomy'));
                $target = null;

                if ($top_only) {

                    $target = get_term((int) end($chain), 'category');

                } else {

                    foreach ($chain as $cid) {
                        $candidate = get_term((int) $cid, 'category');
                        if ($candidate && !is_wp_error($candidate) && (int) $candidate->parent === $under_id) {
                            $target = $candidate;
                            break;
                        }
                    }
                }

                if (!$target || is_wp_error($target)) {
                    continue;
                }
            }

            if ($include && !in_array($target->slug, $include, true)) {
                continue;
            }

            if (in_array($target->slug, $exclude, true)) {
                continue;
            }

            $add('t' . $target->term_id, $target->name, $post);
        }
    }

    /* Keep headings with enough posts, then sort them */

    $groups = array_filter($groups, function ($group) use ($min) {
        return count($group['posts']) >= $min;
    });

    if ($by === 'month' || $by === 'year') {

        $order === 'ASC' ? ksort($groups) : krsort($groups);

    } else {

        uasort($groups, function ($a, $b) use ($sort_groups) {

            if ($sort_groups === 'count') {
                return count($b['posts']) <=> count($a['posts']) ?: strcasecmp($a['label'], $b['label']);
            }

            if ($sort_groups === 'recent') {
                return $b['latest'] <=> $a['latest'];
            }

            return strcasecmp($a['label'], $b['label']);
        });
    }

    if (!$groups) {
        return $atts['none'] !== '' ? '<p class="post-groups-none">' . esc_html($atts['none']) . '</p>' : '';
    }

    /* Title: the name of the category this list is about */
    $title_text = '';
    $title_mode = trim((string) $atts['title']);

    if (strtolower($title_mode) === 'auto') {

        $title_term = null;

        if ($under_id) {
            $title_term = get_term($under_id, 'category');
        } elseif (count($only_cats) === 1) {
            $title_term = get_category_by_slug($only_cats[0]);
        }

        if ($title_term && !is_wp_error($title_term)) {
            $title_text = $title_term->name;
        }

    } elseif (strtolower($title_mode) !== 'no') {

        $title_text = $title_mode;
    }

    $out = '';

    if ($title_text !== '') {

        $out .= '<h2 class="post-groups-title">' . esc_html($title_text) . '</h2>';

        /* Move the group headings down a level so the title stands above them */
        if ($heading === 'h2') {
            $heading = 'h3';
        }
    }

    if (strtolower($atts['flat']) === 'yes') {

        $all = [];

        foreach ($groups as $group) {
            foreach ($group['posts'] as $post_id => $post) {
                $all[$post_id] = $post;
            }
        }

        $all = array_values($all);

        usort($all, function ($a, $b) use ($order) {
            $result = get_post_timestamp($a) <=> get_post_timestamp($b);
            return $order === 'ASC' ? $result : -$result;
        });

        if ($limit) {
            $all = array_slice($all, 0, $limit);
        }

        return $out . pj_render_posts($all, $layout, $show_date, $show_title, $tile_min);
    }

    foreach ($groups as $group) {

        $posts = array_values($group['posts']);

        if ($limit) {
            $posts = array_slice($posts, 0, $limit);
        }

        $out .= '<' . $heading . ' class="post-group-heading">' . esc_html($group['label']) . '</' . $heading . '>';

        if ($subgroup === 'tag') {

            $sub_heading = ['h2' => 'h3', 'h3' => 'h4'][$heading] ?? 'p';

            $out .= pj_render_by_tag($group['posts'], $layout, $show_date, $show_title, $tile_min, $sub_heading, $atts['other'], $limit);

        } else {

            $out .= pj_render_posts($posts, $layout, $show_date, $show_title, $tile_min);
        }
    }

    return $out;
}

add_shortcode('post_groups', 'post_groups_shortcode');




/* ============================================================
 * 0. The Journal post type
 *    After first activating this, go to Settings > Permalinks and click
 *    Save once so the /journal/ addresses work.
 * ============================================================ */

function pj_register_journal_post_type() {

    register_post_type('journal', [
        'labels' => [
            'name'          => 'Journal',
            'singular_name' => 'Journal Entry',
            'add_new'       => 'Add New',
            'add_new_item'  => 'Add New Journal Entry',
            'edit_item'     => 'Edit Journal Entry',
            'view_item'     => 'View Journal Entry',
            'search_items'  => 'Search Journal',
            'all_items'     => 'All Journal Entries',
            'menu_name'     => 'Journal',
        ],
        'public'        => true,
        'hierarchical'  => false,
        'has_archive'   => false,
        'show_in_rest'  => true,
        'menu_icon'     => 'dashicons-book-alt',
        'menu_position' => 6,
        'rewrite'       => ['slug' => 'journal', 'with_front' => false],
        'supports'      => ['title', 'editor', 'thumbnail', 'excerpt', 'author', 'revisions', 'comments', 'custom-fields'],
        'taxonomies'    => ['category', 'post_tag'],
        'template'      => [
            ['core/spacer', ['height' => '40px']],
            ['core/paragraph', ['placeholder' => 'Start writing...']],
            ['core/spacer', ['height' => '40px']],
            ['core/image'],
            ['core/spacer', ['height' => '40px']],
        ],
    ]);

    register_taxonomy_for_object_type('category', 'journal');
    register_taxonomy_for_object_type('post_tag', 'journal');
}
add_action('init', 'pj_register_journal_post_type');

/* Post types that count as journal entries when reading.
   Regular posts are still included so a post that already has a Project
   ticked keeps working until you convert it. */
function pj_entry_types() {
    return ['journal', 'post'];
}

/* Old blog-post addresses of converted entries: send visitors to the new one. */
add_action('template_redirect', function () {

    if (!is_404()) {
        return;
    }

    $name = sanitize_title((string) get_query_var('name'));

    if ($name === '') {
        return;
    }

    $found = get_posts([
        'name'           => $name,
        'post_type'      => 'journal',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ]);

    if ($found) {
        wp_safe_redirect(get_permalink($found[0]), 301);
        exit;
    }
});


/* ============================================================
 * 1. Project status (Idea / In progress / Finished)
 * ============================================================ */

function pj_statuses() {
    return [
        'in-progress' => 'In progress',
        'idea'        => 'Idea',
        'finished'    => 'Finished',
    ];
}

function pj_add_status_box() {
    add_meta_box('pj_status', 'Status', 'pj_render_status_box', 'project', 'side', 'high');
}
add_action('add_meta_boxes', 'pj_add_status_box');

function pj_render_status_box($post) {

    wp_nonce_field('pj_status', 'pj_status_nonce');

    $current = get_post_meta($post->ID, '_project_status', true);

    echo '<select name="pj_status" style="width:100%">';
    echo '<option value="">Not set</option>';

    foreach (pj_statuses() as $key => $label) {
        echo '<option value="' . esc_attr($key) . '"' . selected($current, $key, false) . '>' . esc_html($label) . '</option>';
    }

    echo '</select>';
}

function pj_save_status($post_id) {

    if (!isset($_POST['pj_status_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pj_status_nonce'])), 'pj_status')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $value = isset($_POST['pj_status']) ? sanitize_key(wp_unslash($_POST['pj_status'])) : '';

    if (isset(pj_statuses()[$value])) {
        update_post_meta($post_id, '_project_status', $value);
    } else {
        delete_post_meta($post_id, '_project_status');
    }
}
add_action('save_post_project', 'pj_save_status');

/* [project_status]  A small "Status: In progress" line for the Project template. */
function project_status_shortcode() {

    $id = get_the_ID() ?: get_queried_object_id();

    if (!$id || get_post_type($id) !== 'project') {
        return '';
    }

    $value    = get_post_meta($id, '_project_status', true);
    $statuses = pj_statuses();

    return isset($statuses[$value])
        ? '<p class="project-status">Status: ' . esc_html($statuses[$value]) . '</p>'
        : '';
}
add_shortcode('project_status', 'project_status_shortcode');


/* ============================================================
 * 2. "Projects" picker on blog posts
 *    Saved as hidden post meta _post_project (one row per project).
 * ============================================================ */

function pj_add_projects_box() {
    add_meta_box('pj_projects', 'Projects', 'pj_render_projects_box', 'journal', 'side', 'high');
}
add_action('add_meta_boxes', 'pj_add_projects_box');

function pj_render_projects_box($post) {

    wp_nonce_field('pj_projects', 'pj_projects_nonce');

    $selected = array_map('intval', get_post_meta($post->ID, '_post_project'));

    $projects = get_posts([
        'post_type'      => 'project',
        'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
        'posts_per_page' => -1,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ]);

    if (!$projects) {
        echo '<p>No Projects yet.</p>';
        return;
    }

    $known    = wp_list_pluck($projects, 'ID');
    $children = [];

    foreach ($projects as $project) {
        $parent = in_array((int) $project->post_parent, $known, true) ? (int) $project->post_parent : 0;
        $children[$parent][] = $project;
    }

    /* Projects used on the latest few posts, as quick-pick buttons */
    $recent = [];

    $latest = get_posts([
        'post_type'      => pj_entry_types(),
        'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
        'posts_per_page' => 30,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_key'       => '_post_project',
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ]);

    foreach ($latest as $post_id) {
        foreach (get_post_meta($post_id, '_post_project') as $project_id) {
            $project_id = (int) $project_id;
            if (in_array($project_id, $known, true) && !in_array($project_id, $recent, true)) {
                $recent[] = $project_id;
            }
        }
    }

    $recent = array_slice($recent, 0, 5);

    echo '<div id="pj-projects-box">';

    if ($recent) {
        echo '<p style="margin:0 0 6px"><strong>Recent:</strong> ';
        foreach ($recent as $project_id) {
            echo '<button type="button" class="button button-small pj-recent" data-id="' . (int) $project_id . '" style="margin:0 4px 4px 0">'
                . esc_html(get_the_title($project_id)) . '</button>';
        }
        echo '</p>';
    }

    echo '<input type="search" id="pj-search" placeholder="Search projects..." style="width:100%;margin-bottom:6px">';
    echo '<div id="pj-list" style="max-height:240px;overflow:auto;border:1px solid #ddd;padding:6px">';

    $print = function ($parent, $depth) use (&$print, $children, $selected) {

        if (empty($children[$parent])) {
            return;
        }

        foreach ($children[$parent] as $project) {

            $label = get_the_title($project) ?: '(no title)';

            if ($project->post_status !== 'publish') {
                $label .= ' (' . $project->post_status . ')';
            }

            echo '<label class="pj-item" style="display:block;padding:2px 0 2px ' . (int) ($depth * 16) . 'px">'
                . '<input type="checkbox" name="pj_projects[]" value="' . (int) $project->ID . '"' . checked(in_array((int) $project->ID, $selected, true), true, false) . '> '
                . esc_html($label) . '</label>';

            $print((int) $project->ID, $depth + 1);
        }
    };

    $print(0, 0);

    echo '</div></div>';

    echo '<script>' . <<<'JS'
    (function () {
        var box = document.getElementById('pj-projects-box');
        if (!box) return;

        var search = box.querySelector('#pj-search');
        var items = box.querySelectorAll('.pj-item');

        search.addEventListener('input', function () {
            var q = search.value.toLowerCase();
            items.forEach(function (item) {
                item.style.display = item.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : 'block';
            });
        });

        search.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') e.preventDefault();
        });

        box.querySelectorAll('.pj-recent').forEach(function (button) {
            button.addEventListener('click', function () {
                var cb = box.querySelector('input[value="' + button.getAttribute('data-id') + '"]');
                if (cb) {
                    cb.checked = !cb.checked;
                    cb.scrollIntoView({block: 'nearest'});
                }
            });
        });
    })();
    
JS
    . '</script>';
}

function pj_save_projects($post_id) {

    if (!isset($_POST['pj_projects_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pj_projects_nonce'])), 'pj_projects')) {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    $ids = isset($_POST['pj_projects']) ? array_unique(array_map('absint', (array) $_POST['pj_projects'])) : [];

    delete_post_meta($post_id, '_post_project');

    foreach ($ids as $project_id) {
        if ($project_id && get_post_type($project_id) === 'project') {
            add_post_meta($post_id, '_post_project', $project_id);
        }
    }
}
add_action('save_post_journal', 'pj_save_projects');


/* ============================================================
 * Finding the posts that belong to one or more projects
 * ============================================================ */

function pj_posts_for_projects($project_ids, $order = 'ASC', $limit = 0) {

    $project_ids = array_values(array_filter(array_map('absint', (array) $project_ids)));

    if (!$project_ids) {
        return [];
    }

    $query = new WP_Query([
        'post_type'      => pj_entry_types(),
        'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => strtoupper($order) === 'DESC' ? 'DESC' : 'ASC',
        'no_found_rows'  => true,
        'meta_query'     => [[
            'key'     => '_post_project',
            'value'   => $project_ids,
            'compare' => 'IN',
        ]],
    ]);

    $seen  = [];
    $posts = [];

    foreach ($query->posts as $post) {

        if (isset($seen[$post->ID]) || !current_user_can('read_post', $post->ID)) {
            continue;
        }

        $seen[$post->ID] = true;
        $posts[] = $post;
    }

    return $limit > 0 ? array_slice($posts, 0, $limit) : $posts;
}


/* ============================================================
 * 3. [project_journal]
 *
 * On a Project page (the Single item: Project template is the easiest
 * place). Shows a board of the blog posts ticked for this Project.
 * Prints nothing when there are none.
 *
 * Attributes (all optional):
 *   order             ASC = oldest first (default, reads as the story of the
 *                     project), DESC = newest first
 *   include_children  yes = also show posts ticked for sub-projects
 *                     (good for a parent like Cyanotype). Default no.
 *   layout            tiles (default) or list
 *   show_date         yes (default, month and year only) or no
 *   title             heading text (default "Journal"); title="" for none
 *   limit             maximum posts (default: all)
 *   size              minimum tile width in pixels (default 160)
 *   tag               use a tag slug instead of Project links. Works on any
 *                     page, e.g. [project_journal tag="cyanotype"]
 * ============================================================ */

function project_journal_shortcode($atts) {

    $atts = shortcode_atts([
        'order'            => 'ASC',
        'include_children' => 'no',
        'layout'           => 'tiles',
        'show_date'        => 'yes',
        'title'            => 'Journal',
        'limit'            => 0,
        'size'             => 160,
        'tag'              => '',
    ], $atts, 'project_journal');

    $order     = strtoupper($atts['order']) === 'DESC' ? 'DESC' : 'ASC';
    $layout    = $atts['layout'] === 'list' ? 'list' : 'tiles';
    $show_date = strtolower($atts['show_date']) !== 'no';
    $limit     = max(0, (int) $atts['limit']);
    $tile_min  = max(80, absint($atts['size']));
    $tags      = pj_slug_list($atts['tag']);

    if ($tags) {

        $query = new WP_Query([
            'post_type'      => pj_entry_types(),
            'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
            'posts_per_page' => $limit ?: -1,
            'orderby'        => 'date',
            'order'          => $order,
            'no_found_rows'  => true,
            'tag_slug__in'   => $tags,
        ]);

        $posts = array_values(array_filter($query->posts, function ($post) {
            return current_user_can('read_post', $post->ID);
        }));

    } else {

        $id = get_the_ID() ?: get_queried_object_id();

        if (!$id || get_post_type($id) !== 'project') {
            return '';
        }

        $ids = [(int) $id];

        if (strtolower($atts['include_children']) === 'yes') {

            $all = get_posts([
                'post_type'      => 'project',
                'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
                'posts_per_page' => -1,
                'no_found_rows'  => true,
            ]);

            foreach (get_page_children($id, $all) as $child) {
                $ids[] = (int) $child->ID;
            }
        }

        $posts = pj_posts_for_projects($ids, $order, $limit);
    }

    if (!$posts) {
        return '';
    }

    $out = '<section class="project-journal">';

    if ($atts['title'] !== '') {
        $out .= '<h3>' . esc_html($atts['title']) . '</h3>';
    }

    return $out . pj_render_posts($posts, $layout, $show_date, true, $tile_min) . '</section>';
}

add_shortcode('project_journal', 'project_journal_shortcode');


/* ============================================================
 * 4. [post_project_nav]
 *
 * For the blog post template, under the post content. Shows
 * "Part of: Project" and, for each project, links to the previous and
 * next post in it. Prints nothing for posts with no project.
 * ============================================================ */

function post_project_nav_shortcode() {

    $id = get_the_ID() ?: get_queried_object_id();

    if (!$id || !in_array(get_post_type($id), pj_entry_types(), true)) {
        return '';
    }

    $projects = [];

    foreach (get_post_meta($id, '_post_project') as $project_id) {

        $project_id = (int) $project_id;

        if (get_post_type($project_id) === 'project' && current_user_can('read_post', $project_id)) {
            $projects[$project_id] = true;
        }
    }

    if (!$projects) {
        return '';
    }

    $links = [];

    foreach (array_keys($projects) as $project_id) {
        $links[] = '<a href="' . esc_url(get_permalink($project_id)) . '">' . esc_html(get_the_title($project_id)) . '</a>';
    }

    $out = '<nav class="post-project-nav"><p class="post-project-part">Part of: ' . implode(', ', $links) . '</p>';

    foreach (array_keys($projects) as $project_id) {

        $siblings = pj_posts_for_projects([$project_id], 'ASC');
        $position = null;

        foreach ($siblings as $index => $sibling) {
            if ((int) $sibling->ID === (int) $id) {
                $position = $index;
                break;
            }
        }

        if ($position === null) {
            continue;
        }

        $prev = $position > 0 ? $siblings[$position - 1] : null;
        $next = $position < count($siblings) - 1 ? $siblings[$position + 1] : null;

        if (!$prev && !$next) {
            continue;
        }

        $parts = [];

        if ($prev) {
            $parts[] = '<a href="' . esc_url(get_permalink($prev)) . '">&larr; ' . esc_html(get_the_title($prev)) . '</a>';
        }

        if ($next) {
            $parts[] = '<a href="' . esc_url(get_permalink($next)) . '">' . esc_html(get_the_title($next)) . ' &rarr;</a>';
        }

        $label = count($projects) > 1 ? esc_html(get_the_title($project_id)) . ': ' : '';

        $out .= '<p class="post-project-step">' . $label . implode(' &middot; ', $parts) . '</p>';
    }

    return $out . '</nav>';
}

add_shortcode('post_project_nav', 'post_project_nav_shortcode');


/* ============================================================
 * 5. [project_gallery]
 *
 * Sub-projects as logo tiles under headings. Place it on a Project page
 * (e.g. Cyanotype) to see its runs, or on a normal page with parent="0".
 *
 * Attributes (all optional):
 *   by            status (default): In progress, Idea, Finished
 *                 medium: headings are the subcategories of your Form category
 *                 subject: headings are the subcategories of your Topics category
 *   parent        "current" (default on a Project page), "0" for top-level
 *                 projects, or a Project ID
 *   medium_slug   category slug used for "medium" (default form)
 *   subject_slug  category slug used for "subject" (default topics)
 *   unset         heading for projects with no status (default: none, tiles shown without a heading)
 *   heading       h2 (default), h3, h4 or p
 *   show_title, size   same as [project_index]
 * ============================================================ */

function project_gallery_shortcode($atts) {

    if (!function_exists('project_index_shortcode') || !function_exists('project_groups_shortcode')) {
        return '<p>[project_gallery] needs the "Project Index &amp; Tree" snippet to be active.</p>';
    }

    $atts = shortcode_atts([
        'by'           => 'status',
        'parent'       => '',
        'medium_slug'  => 'form',
        'subject_slug' => 'topics',
        'unset'        => '',
        'heading'      => 'h2',
        'show_title'   => 'yes',
        'size'         => 160,
    ], $atts, 'project_gallery');

    $heading = in_array($atts['heading'], ['h2', 'h3', 'h4', 'p'], true) ? $atts['heading'] : 'h2';
    $by      = in_array($atts['by'], ['status', 'medium', 'subject'], true) ? $atts['by'] : 'status';

    $parent_attr = strtolower(trim($atts['parent']));

    if ($parent_attr === '') {
        $current     = get_the_ID() ?: get_queried_object_id();
        $parent_attr = ($current && get_post_type($current) === 'project') ? 'current' : '0';
    }

    $parent = $parent_attr === 'current'
        ? (int) (get_the_ID() ?: get_queried_object_id())
        : absint($parent_attr);

    /* On a Project page with no sub-projects (e.g. a single run), show nothing */
    if ($parent > 0 && !get_posts([
        'post_type'      => 'project',
        'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
        'post_parent'    => $parent,
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ])) {
        return '';
    }

    if ($by === 'medium' || $by === 'subject') {

        return project_groups_shortcode([
            'under'      => $by === 'medium' ? $atts['medium_slug'] : $atts['subject_slug'],
            'parent'     => (string) $parent,
            'heading'    => $heading,
            'show_title' => $atts['show_title'],
            'size'       => $atts['size'],
        ]);
    }

    $base = [
        'post_type'      => 'project',
        'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
        'post_parent'    => $parent,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ];

    $sections = [];

    foreach (pj_statuses() as $key => $label) {
        $sections[$label] = get_posts($base + [
            'meta_key'   => '_project_status',
            'meta_value' => $key,
        ]);
    }

    $sections[$atts['unset']] = get_posts($base + [
        'meta_query' => [
            'relation' => 'OR',
            ['key' => '_project_status', 'compare' => 'NOT EXISTS'],
            ['key' => '_project_status', 'value' => ''],
        ],
    ]);

    $out = '';

    foreach ($sections as $label => $ids) {

        if (!$ids) {
            continue;
        }

        $group = project_index_shortcode([
            'ids'        => implode(',', $ids),
            'none'       => '',
            'show_title' => $atts['show_title'],
            'size'       => $atts['size'],
        ]);

        if ($group !== '') {
            $out .= ($label !== '' ? '<' . $heading . ' class="project-group-heading">' . esc_html($label) . '</' . $heading . '>' : '') . $group;
        }
    }

    return $out;
}

add_shortcode('project_gallery', 'project_gallery_shortcode');
