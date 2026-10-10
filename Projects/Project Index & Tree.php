/* Shortcodes for showing Projects on a page
 *
 * [project_index]   Clickable logo tiles (featured image + title)
 * [project_groups]  Logo tiles grouped under category headings
 * [project_tree]    Nested outline of every project and sub-project
 *
 * Requires the "Projects Wiki" snippet (the Project post type).
 */


/* ============================================================
 * [project_index]
 *
 * Attributes (all optional):
 *   parent      "0" top-level only (default), "current" = children of the
 *               page this shortcode is on, a project ID, a project slug
 *               (e.g. "project-a" or "project-a/sub-project"),
 *               or "all" for every level.
 *               If you use tag or category, the default becomes "all".
 *   tag         one or more tag slugs, comma separated
 *   category    one or more category slugs, comma separated
 *   orderby     "menu_order title" (default), title, date, modified, rand
 *   order       ASC (default) or DESC
 *   ids         hand-pick projects by ID, comma separated
 *   limit       maximum number of tiles (default: all)
 *   show_title  yes (default) or no
 *   size        minimum tile width in pixels (default 160)
 *   image_size  WordPress image size (default "medium")
 *   none        text shown when nothing matches (default: nothing)
 * ============================================================ */

function project_index_shortcode($atts) {

    $atts = shortcode_atts([
        'parent'     => '',
        'tag'        => '',
        'category'   => '',
        'ids'        => '',
        'none'       => '',
        'orderby'    => 'menu_order title',
        'order'      => 'ASC',
        'limit'      => -1,
        'show_title' => 'yes',
        'size'       => 160,
        'image_size' => 'medium',
    ], $atts, 'project_index');

    /* ---- Clean up the attributes ---- */

    $slug_list = function ($value) {
        $parts = array_filter(array_map('sanitize_title', explode(',', (string) $value)));
        return implode(',', $parts);
    };

    $tag      = $slug_list($atts['tag']);
    $category = $slug_list($atts['category']);

    $ids  = array_filter(array_map('absint', explode(',', (string) $atts['ids'])));
    $none = $atts['none'] !== '' ? '<p>' . esc_html($atts['none']) . '</p>' : '';

    $allowed_orderby = ['menu_order title', 'title', 'date', 'modified', 'menu_order', 'rand'];
    $orderby = in_array($atts['orderby'], $allowed_orderby, true) ? $atts['orderby'] : 'menu_order title';
    $order   = strtoupper($atts['order']) === 'DESC' ? 'DESC' : 'ASC';

    $limit      = (int) $atts['limit'];
    $show_title = strtolower($atts['show_title']) !== 'no';
    $tile_min   = max(80, absint($atts['size']));
    $image_size = sanitize_key($atts['image_size']) ?: 'medium';

    /* ---- Work out which level to show ---- */

    $parent_attr = strtolower(trim($atts['parent']));

    if ($parent_attr === '') {
        $parent_attr = ($tag || $category || $ids) ? 'all' : '0';
    }

    $any_parent = false;
    $parent     = 0;

    if ($parent_attr === 'all') {

        $any_parent = true;

    } elseif ($parent_attr === 'current') {

        $parent = (int) (get_the_ID() ?: get_queried_object_id());

    } elseif (ctype_digit($parent_attr)) {

        $parent = (int) $parent_attr;

    } else {

        $found = get_page_by_path(trim($atts['parent'], '/'), OBJECT, 'project');

        if (!$found) {
            return $none;
        }

        $parent = $found->ID;
    }

    /* ---- Query ---- */

    $args = [
        'post_type'      => 'project',
        'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
        'posts_per_page' => $limit > 0 ? $limit : -1,
        'orderby'        => $orderby,
        'order'          => $order,
        'no_found_rows'  => true,
    ];

    if (!$any_parent) {
        $args['post_parent'] = $parent;
    }

    if ($ids) {
        $args['post__in'] = $ids;
    }

    if ($tag) {
        $args['tag'] = $tag;
    }

    if ($category) {
        $args['category_name'] = $category;
    }

    $query = new WP_Query($args);

    /* Published projects for everyone; drafts/private only for people allowed to see them */
    $projects = array_filter($query->posts, function ($project) {
        return current_user_can('read_post', $project->ID);
    });

    if (!$projects) {
        return $none;
    }

    /* ---- Output ---- */

    static $styled = false;

    $html = '';

    if (!$styled) {

        $styled = true;

        $html .= '<style>
            .project-index { display: grid; grid-template-columns: repeat(auto-fill, minmax(var(--project-tile, 160px), 1fr)); gap: 1rem; margin: 1rem 0; }
            .project-tile { display: flex; flex-direction: column; align-items: center; text-align: center; text-decoration: none; color: inherit; padding: .75rem; border: 1px solid rgba(128,128,128,.3); border-radius: 8px; transition: transform .15s, box-shadow .15s; }
            .project-tile:hover, .project-tile:focus { transform: translateY(-3px); box-shadow: 0 6px 16px rgba(0,0,0,.15); }
            .project-tile-image { display: flex; align-items: center; justify-content: center; width: 100%; aspect-ratio: 1 / 1; }
            .project-tile-image img { max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain; }
            .project-tile--text { justify-content: center; min-height: calc(var(--project-tile, 160px) * .8); }
            .project-tile--text .project-tile-title { margin-top: 0; font-size: 1.15rem; }
            .project-tile-title { margin-top: .6rem; font-weight: 600; line-height: 1.25; }
            .project-tile-status { font-size: .75rem; opacity: .7; margin-top: .15rem; }
        </style>';
    }

    $html .= '<div class="project-index" style="--project-tile:' . (int) $tile_min . 'px">';

    foreach ($projects as $project) {

        $title = $project->post_title !== '' ? $project->post_title : '(no title)';

        $thumb = get_the_post_thumbnail($project->ID, $image_size, [
            'alt'     => $show_title ? '' : $title,
            'loading' => 'lazy',
        ]);

        /* No picture: the box just shows the title (even if titles are switched off,
           since there is nothing else to say what the box is) */
        $html .= '<a class="project-tile' . ($thumb ? '' : ' project-tile--text') . '" href="' . esc_url(get_permalink($project)) . '">';

        if ($thumb) {
            $html .= '<span class="project-tile-image">' . $thumb . '</span>';
        }

        if ($show_title || !$thumb) {
            $html .= '<span class="project-tile-title">' . esc_html($title) . '</span>';
        }

        if ($project->post_status !== 'publish') {
            $html .= '<span class="project-tile-status">' . esc_html($project->post_status) . '</span>';
        }

        $html .= '</a>';
    }

    $html .= '</div>';

    return $html;
}

add_shortcode('project_index', 'project_index_shortcode');


/* Space above and below the category headings of [project_groups] and
   [project_tree under=""]. Change the two em values to taste. Printed once. */
function project_heading_style() {

    static $printed = false;

    if ($printed) {
        return '';
    }

    $printed = true;

    return '<style>.project-group-heading { margin-top: 2em; margin-bottom: 1em; }</style>';
}

/* ============================================================
 * [project_groups]
 *
 * Logo tiles grouped under a heading for each category.
 * Top-level projects only by default, so wiki articles stay tucked
 * away under their projects. A project in two categories appears
 * under both; projects with no category go under "Other".
 *
 *   under       one category slug. Shows only that category's own
 *               subcategories as the headings, e.g. under="form" gives
 *               a heading for each subcategory of Form. Projects filed in
 *               Form itself but in no subcategory go under the "other"
 *               heading. Use one shortcode per page, e.g. under="form"
 *               on one page and under="topics" on another.
 *   categories  category slugs, in the order you want them shown
 *               (default: every top-level category, alphabetically)
 *   top_level   yes (default) = only top-level categories get a heading;
 *               projects in a subcategory appear under their top-level
 *               parent. no = every category and subcategory gets a heading.
 *   parent      "0" top level (default), "current", or a project ID
 *   heading     h2 (default), h3, h4 or p
 *   other       heading for uncategorised projects (default "Other")
 *   show_title, size   same as [project_index]
 * ============================================================ */

function project_groups_shortcode($atts) {

    $atts = shortcode_atts([
        'under'      => '',
        'categories' => '',
        'top_level'  => 'yes',
        'parent'     => '0',
        'heading'    => 'h2',
        'other'      => 'Other',
        'show_title' => 'yes',
        'size'       => 160,
    ], $atts, 'project_groups');

    $parent_attr = strtolower(trim($atts['parent']));

    if ($parent_attr === 'current') {
        $parent = (int) (get_the_ID() ?: get_queried_object_id());
    } elseif (ctype_digit($parent_attr)) {
        $parent = (int) $parent_attr;
    } else {
        $parent = 0;
    }

    $heading = in_array($atts['heading'], ['h2', 'h3', 'h4', 'p'], true) ? $atts['heading'] : 'h2';

    $under = null;

    if ($atts['under'] !== '' && $atts['categories'] === '') {

        $under = get_category_by_slug(sanitize_title($atts['under']));

        if (!$under) {
            return '<p>Category "' . esc_html($atts['under']) . '" was not found.</p>';
        }

        $categories = get_categories([
            'parent'     => $under->term_id,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

    } elseif ($atts['categories'] !== '') {

        $categories = [];

        foreach (array_filter(array_map('sanitize_title', explode(',', $atts['categories']))) as $slug) {
            $term = get_category_by_slug($slug);
            if ($term) {
                $categories[] = $term;
            }
        }

    } else {

        $category_args = [
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];

        if (strtolower($atts['top_level']) !== 'no') {
            $category_args['parent'] = 0;
        }

        $categories = get_categories($category_args);
    }

    $out = project_heading_style();

    foreach ($categories as $category) {

        $group = project_index_shortcode([
            'category'   => $category->slug,
            'parent'     => (string) $parent,
            'none'       => '',
            'show_title' => $atts['show_title'],
            'size'       => $atts['size'],
        ]);

        if ($group !== '') {
            $out .= '<' . $heading . ' class="project-group-heading">'
                . esc_html($category->name)
                . '</' . $heading . '>' . $group;
        }
    }

    /* Projects left over: in no category, or (with under=) in the parent
       category but in none of its subcategories */
    if ($atts['categories'] === '' && $atts['other'] !== '') {

        if ($under) {

            $tax_query = [
                'relation' => 'AND',
                [
                    'taxonomy'         => 'category',
                    'field'            => 'term_id',
                    'terms'            => [$under->term_id],
                    'include_children' => true,
                ],
            ];

            if ($categories) {
                $tax_query[] = [
                    'taxonomy'         => 'category',
                    'field'            => 'term_id',
                    'terms'            => wp_list_pluck($categories, 'term_id'),
                    'include_children' => true,
                    'operator'         => 'NOT IN',
                ];
            }

        } else {

            $tax_query = [[
                'taxonomy' => 'category',
                'operator' => 'NOT EXISTS',
            ]];
        }

        $uncategorised = get_posts([
            'post_type'      => 'project',
            'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
            'post_parent'    => $parent,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'tax_query'      => $tax_query,
        ]);

        if ($uncategorised) {

            $group = project_index_shortcode([
                'ids'        => implode(',', $uncategorised),
                'none'       => '',
                'show_title' => $atts['show_title'],
                'size'       => $atts['size'],
            ]);

            if ($group !== '') {

                /* With under= and no subcategories at all, skip the "Other" heading */
                if ($under && !$categories) {
                    $out .= $group;
                } else {
                    $out .= '<' . $heading . ' class="project-group-heading">'
                        . esc_html($atts['other'])
                        . '</' . $heading . '>' . $group;
                }
            }
        }
    }

    return $out;
}

add_shortcode('project_groups', 'project_groups_shortcode');


/* ============================================================
 * [project_tree]
 *
 * A nested outline of every project and sub-project.
 *   parent  project ID to start from (default 0 = everything),
 *           or "current" for the project page it is placed on
 *   depth   how many levels to show (default 0 = all)
 *   under   a category slug. Groups the outline under a heading for each of
 *           that category's subcategories, e.g. under="form" gives a heading
 *           per Form subcategory (Video, Visual Arts, Website), each with its
 *           projects and their sub-projects nested below. Projects filed in
 *           the category but in none of its subcategories go under "other".
 *   heading h2 (default), h3, h4 or p: the heading level used with under=
 *   other   heading for those leftover projects (default "Other")
 * ============================================================ */

/* Nested <li> items for a list of projects, with their sub-projects below. */
function project_tree_render($projects, $level, $max_depth) {

    $html = '';

    foreach ($projects as $project) {

        if (!current_user_can('read_post', $project->ID)) {
            continue;
        }

        $html .= '<li><a href="' . esc_url(get_permalink($project)) . '">'
            . esc_html($project->post_title !== '' ? $project->post_title : '(no title)') . '</a>';

        if ($project->post_status !== 'publish') {
            $html .= ' <em>(' . esc_html($project->post_status) . ')</em>';
        }

        if ($max_depth === 0 || $level < $max_depth) {

            $kids = get_posts([
                'post_type'      => 'project',
                'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
                'post_parent'    => $project->ID,
                'posts_per_page' => -1,
                'orderby'        => 'menu_order title',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ]);

            $inner = $kids ? project_tree_render($kids, $level + 1, $max_depth) : '';

            if ($inner !== '') {
                $html .= '<ul>' . $inner . '</ul>';
            }
        }

        $html .= '</li>';
    }

    return $html;
}

/* The grouped version, used when under="..." is given. */
function project_tree_grouped($atts) {

    $under = get_category_by_slug(sanitize_title($atts['under']));

    if (!$under) {
        return '<p>Category "' . esc_html($atts['under']) . '" was not found.</p>';
    }

    $heading   = in_array($atts['heading'], ['h2', 'h3', 'h4', 'p'], true) ? $atts['heading'] : 'h2';
    $max_depth = absint($atts['depth']);

    $parent_attr = strtolower(trim((string) $atts['parent']));
    $parent      = $parent_attr === 'current'
        ? (int) (get_the_ID() ?: get_queried_object_id())
        : absint($parent_attr);

    $base = [
        'post_type'      => 'project',
        'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
        'post_parent'    => $parent,
        'posts_per_page' => -1,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ];

    $subcategories = get_categories([
        'parent'     => $under->term_id,
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    $sections = [];

    foreach ($subcategories as $category) {

        $sections[$category->name] = get_posts($base + ['tax_query' => [[
            'taxonomy'         => 'category',
            'field'            => 'term_id',
            'terms'            => [$category->term_id],
            'include_children' => true,
        ]]]);
    }

    /* Filed in the main category itself but in none of its subcategories */
    $leftover_query = [
        'relation' => 'AND',
        [
            'taxonomy'         => 'category',
            'field'            => 'term_id',
            'terms'            => [$under->term_id],
            'include_children' => true,
        ],
    ];

    if ($subcategories) {
        $leftover_query[] = [
            'taxonomy'         => 'category',
            'field'            => 'term_id',
            'terms'            => wp_list_pluck($subcategories, 'term_id'),
            'include_children' => true,
            'operator'         => 'NOT IN',
        ];
    }

    $leftover = get_posts($base + ['tax_query' => $leftover_query]);

    if ($leftover) {
        $sections[$subcategories ? $atts['other'] : $under->name] = $leftover;
    }

    $out = project_heading_style();

    foreach ($sections as $label => $projects) {

        $items = project_tree_render($projects, 1, $max_depth);

        if ($items === '') {
            continue;
        }

        $out .= '<' . $heading . ' class="project-group-heading">' . esc_html($label) . '</' . $heading . '>'
            . '<ul class="project-tree">' . $items . '</ul>';
    }

    return $out;
}

function project_tree_shortcode($atts) {

    $atts = shortcode_atts([
        'parent'  => 0,
        'depth'   => 0,
        'under'   => '',
        'heading' => 'h2',
        'other'   => 'Other',
    ], $atts, 'project_tree');

    if ($atts['under'] !== '') {
        return project_tree_grouped($atts);
    }

    $statuses = current_user_can('edit_others_posts')
        ? 'publish,private,draft,pending'
        : 'publish';

    $tree_parent = strtolower(trim((string) $atts['parent'])) === 'current'
        ? (int) (get_the_ID() ?: get_queried_object_id())
        : absint($atts['parent']);

    $list = wp_list_pages([
        'post_type'   => 'project',
        'post_status' => $statuses,
        'title_li'    => '',
        'echo'        => 0,
        'child_of'    => $tree_parent,
        'depth'       => absint($atts['depth']),
        'sort_column' => 'menu_order, post_title',
    ]);

    if (!$list) {
        return '<p>No projects yet.</p>';
    }

    return '<ul class="project-tree">' . $list . '</ul>';
}

add_shortcode('project_tree', 'project_tree_shortcode');
