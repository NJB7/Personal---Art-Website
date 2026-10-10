// Projects Wiki: tree hierarchy + two-way related links + shared categories/tags

/* ------------------------------------------------------------------
 * 1. Register the Project post type
 *    hierarchical => true gives the TREE (parent/child) structure.
 * ------------------------------------------------------------------ */
function pw_register_project_post_type() {
    register_post_type('project', array(
        'labels' => array(
            'name'               => 'Projects',
            'singular_name'      => 'Project',
            'add_new_item'       => 'Add New Project',
            'edit_item'          => 'Edit Project',
            'view_item'          => 'View Project',
            'search_items'       => 'Search Projects',
            'parent_item_colon'  => 'Parent Project:',
        ),
        'public'       => true,
        'hierarchical' => true,          // enables parent/child tree
        'has_archive'  => true,
        'show_in_rest' => true,          // block editor + REST API
        'menu_icon'    => 'dashicons-networking',
        'rewrite'      => array('slug' => 'projects', 'hierarchical' => true),
        'supports'     => array(
            'title', 'editor', 'thumbnail', 'excerpt', 'author',
            'revisions', 'page-attributes', 'comments', 'custom-fields',
        ),
        'taxonomies'   => array('category', 'post_tag'),
    ));
}
add_action('init', 'pw_register_project_post_type');

// Share the standard Categories and Tags
function pw_add_taxonomies_to_projects() {
    register_taxonomy_for_object_type('category', 'project');
    register_taxonomy_for_object_type('post_tag', 'project');
}
add_action('init', 'pw_add_taxonomies_to_projects');

// Expose the related-links field to the REST API
add_action('init', function () {
    register_post_meta('project', '_project_related', array(
        'type'         => 'array',
        'single'       => true,
        'show_in_rest' => array(
            'schema' => array('type' => 'array', 'items' => array('type' => 'integer')),
        ),
        'auth_callback' => function () { return current_user_can('edit_posts'); },
    ));
});

/* ------------------------------------------------------------------
 * 2. "Related Projects" meta box = the SPIDER WEB part.
 *    Links are two-way: linking A -> B also links B -> A.
 * ------------------------------------------------------------------ */
function pw_add_related_meta_box() {
    add_meta_box('pw_related', 'Related', 'pw_render_related_meta_box', 'project', 'side', 'default');
}
add_action('add_meta_boxes', 'pw_add_related_meta_box');

function pw_render_related_meta_box($post) {
    wp_nonce_field('pw_save_related', 'pw_related_nonce');

    $selected = get_post_meta($post->ID, '_project_related', true);
    $selected = is_array($selected) ? $selected : array();

    $projects = get_posts(array(
        'post_type'      => 'project',
        'post_status'    => array('publish', 'draft', 'private', 'pending'),
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'post__not_in'   => array($post->ID),
    ));

    echo '<p style="margin-top:0">Hold Ctrl/Cmd to select several.</p>';
    echo '<select name="project_related[]" multiple style="width:100%;min-height:180px">';
    foreach ($projects as $p) {
        printf(
            '<option value="%d"%s>%s</option>',
            $p->ID,
            selected(in_array($p->ID, $selected, true), true, false),
            esc_html($p->post_title ?: '(no title)')
        );
    }
    echo '</select>';
}

function pw_save_related($post_id) {
    if (!isset($_POST['pw_related_nonce']) || !wp_verify_nonce($_POST['pw_related_nonce'], 'pw_save_related')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $new = isset($_POST['project_related']) ? array_map('absint', (array) $_POST['project_related']) : array();
    $new = array_values(array_unique(array_diff($new, array($post_id))));
    $new = array_values(array_filter($new, function ($id) { return get_post_type($id) === 'project'; }));

    $old = get_post_meta($post_id, '_project_related', true);
    $old = is_array($old) ? $old : array();

    update_post_meta($post_id, '_project_related', $new);

    // Mirror the link onto the other entries (two-way)
    foreach (array_diff($new, $old) as $other) {
        $list = get_post_meta($other, '_project_related', true);
        $list = is_array($list) ? $list : array();
        if (!in_array($post_id, $list, true)) {
            $list[] = $post_id;
            update_post_meta($other, '_project_related', $list);
        }
    }
    // Remove mirrored link when unlinked
    foreach (array_diff($old, $new) as $other) {
        $list = get_post_meta($other, '_project_related', true);
        $list = is_array($list) ? $list : array();
        update_post_meta($other, '_project_related', array_values(array_diff($list, array($post_id))));
    }
}
add_action('save_post_project', 'pw_save_related');

/* ------------------------------------------------------------------
 * External links (Wikipedia, YouTube, articles, etc.)
 * Stored as an array of url + optional label in "_project_links".
 * One link per line:  https://example.com | Optional label
 * ------------------------------------------------------------------ */
function pw_parse_links($text) {
    $links = array();

    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $parts = array_map('trim', explode('|', $line, 2));
        $url   = $parts[0];

        // Allow "en.wikipedia.org/wiki/Cyanotype" without https://
        if (!preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            $url = 'https://' . $url;
        }

        $url = esc_url_raw($url, array('http', 'https'));
        if (!$url) continue;

        $links[] = array(
            'url'   => $url,
            'label' => isset($parts[1]) ? sanitize_text_field($parts[1]) : '',
        );
    }

    return $links;
}

function pw_links_to_text($links) {
    $lines = array();

    if (is_array($links)) {
        foreach ($links as $link) {
            if (empty($link['url'])) continue;
            $lines[] = $link['url'] . (!empty($link['label']) ? ' | ' . $link['label'] : '');
        }
    }

    return implode("\n", $lines);
}

function pw_add_links_meta_box() {
    add_meta_box('pw_links', 'External Links', 'pw_render_links_meta_box', 'project', 'normal', 'default');
}
add_action('add_meta_boxes', 'pw_add_links_meta_box');

function pw_render_links_meta_box($post) {
    wp_nonce_field('pw_save_links', 'pw_links_nonce');

    $links = get_post_meta($post->ID, '_project_links', true);

    echo '<p style="margin-top:0">One link per line. Add a label after a <code>|</code> if you like. YouTube and Vimeo links are also embedded as videos.</p>';
    echo '<textarea name="project_links" rows="5" style="width:100%" placeholder="https://en.wikipedia.org/wiki/Cyanotype | Wikipedia">'
        . esc_textarea(pw_links_to_text($links)) . '</textarea>';
}

function pw_save_links($post_id) {
    if (!isset($_POST['pw_links_nonce']) || !wp_verify_nonce($_POST['pw_links_nonce'], 'pw_save_links')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $text  = isset($_POST['project_links']) ? wp_unslash($_POST['project_links']) : '';
    $links = pw_parse_links($text);

    if ($links) {
        update_post_meta($post_id, '_project_links', $links);
    } else {
        delete_post_meta($post_id, '_project_links');
    }
}
add_action('save_post_project', 'pw_save_links');

/* ------------------------------------------------------------------
 * Helpers: who can see which entries
 * Published entries are visible to everyone. Drafts, pending and private
 * entries are only listed/linked for signed-in users who are allowed to
 * view them.
 * ------------------------------------------------------------------ */
function pw_user_can_see($post_id) {
    return in_array(get_post_status($post_id), array('publish', 'private', 'draft', 'pending', 'future'), true)
        && current_user_can('read_post', $post_id);
}

function pw_status_label($post_id) {
    $status = get_post_status($post_id);
    return ($status && $status !== 'publish') ? ' <em>(' . esc_html($status) . ')</em>' : '';
}

/* ------------------------------------------------------------------
 * Map of which entries contain which [[Page Title]] links.
 * Used for backlinks and for the Link Errors page. Cached for a day and
 * cleared whenever a Project is saved, trashed or deleted.
 * ------------------------------------------------------------------ */
function pw_norm_title($title) {
    return strtolower(trim(html_entity_decode(wp_strip_all_tags((string) $title), ENT_QUOTES, 'UTF-8')));
}

function pw_wiki_link_map() {
    $map = get_transient('pw_wiki_link_map');
    if (is_array($map)) return $map;

    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT ID, post_content FROM {$wpdb->posts}
         WHERE post_type IN ('project','post')
           AND post_status IN ('publish','private','draft','pending','future')
           AND post_content LIKE '%[[%'"
    );

    $map = array();
    foreach ((array) $rows as $row) {
        if (preg_match_all('/\[\[(.+?)\]\]/', $row->post_content, $m)) {
            foreach ($m[1] as $t) {
                $t = trim(wp_strip_all_tags($t));
                if ($t !== '') $map[(int) $row->ID][] = $t;
            }
        }
    }

    set_transient('pw_wiki_link_map', $map, DAY_IN_SECONDS);
    return $map;
}

function pw_clear_wiki_link_map() {
    delete_transient('pw_wiki_link_map');
}
add_action('save_post_project', 'pw_clear_wiki_link_map');
add_action('save_post_post', 'pw_clear_wiki_link_map');
add_action('deleted_post', 'pw_clear_wiki_link_map');
add_action('trashed_post', 'pw_clear_wiki_link_map');
add_action('untrashed_post', 'pw_clear_wiki_link_map');

/* ------------------------------------------------------------------
 * 3. Wiki-style [[Page Title]] inline links
 * ------------------------------------------------------------------ */
function pw_wiki_links($content) {
    // [[Title]] links work in Projects and in regular blog posts
    if (!in_array(get_post_type(), array('project', 'post'), true)) return $content;

    return preg_replace_callback('/\[\[(.+?)\]\]/', function ($m) {
        $title = trim(wp_strip_all_tags($m[1]));
        $found = get_posts(array(
            'post_type'      => 'project',
            'post_status'    => array('publish', 'private', 'draft', 'pending', 'future'),
            'title'          => $title,
            'posts_per_page' => 10,
        ));
        foreach ($found as $candidate) {
            if (pw_user_can_see($candidate->ID)) {
                return '<a class="wiki-link" href="' . esc_url(get_permalink($candidate)) . '">' . esc_html($title) . '</a>';
            }
        }
        return '<span class="wiki-link wiki-link-missing" title="No project with this title yet">' . esc_html($title) . '</span>';
    }, $content);
}
add_filter('the_content', 'pw_wiki_links', 9);

/* ------------------------------------------------------------------
 * 4. Show breadcrumbs, sub-pages and related links on single Projects
 * ------------------------------------------------------------------ */
/*
 * AUTOMATIC SECTIONS
 * By default, every section below is added around the Post Content block
 * of a single Project page, whatever the template looks like.
 * If you place the sections yourself in the Site Editor (using the
 * [project_...] shortcodes), change `true` to `false` here so they
 * are not shown twice.
 */
function pw_auto_sections() {
    return false;
}

// Each function returns the HTML for one section, or '' if there is nothing to show.

function pw_section_breadcrumbs($id) {
    $ancestors = array_reverse(get_post_ancestors($id));
    if (!$ancestors) return '';

    $crumbs = array('Projects'); // plain text on purpose: the Projects archive page is not in use
    foreach ($ancestors as $a) {
        if (!pw_user_can_see($a)) continue;
        $crumbs[] = '<a href="' . esc_url(get_permalink($a)) . '">' . esc_html(get_the_title($a)) . '</a>';
    }

    return '<nav class="project-breadcrumbs">' . implode(' &raquo; ', $crumbs) . '</nav>';
}

function pw_section_children($id) {
    $children = get_children(array(
        'post_parent' => $id, 'post_type' => 'project',
        'post_status' => array('publish', 'private', 'draft', 'pending', 'future'),
        'orderby' => 'menu_order title', 'order' => 'ASC',
    ));
    $children = array_filter($children, function ($c) { return pw_user_can_see($c->ID); });
    if (!$children) return '';

    $out = '<section class="project-children"><h3>In this section</h3><ul>';
    foreach ($children as $c) {
        $out .= '<li><a href="' . esc_url(get_permalink($c)) . '">' . esc_html($c->post_title) . '</a>' . pw_status_label($c->ID) . '</li>';
    }
    return $out . '</ul></section>';
}

function pw_section_related($id) {
    $related = get_post_meta($id, '_project_related', true);
    if (!is_array($related) || !$related) return '';

    $items = '';
    foreach ($related as $r) {
        if (pw_user_can_see($r)) {
            $items .= '<li><a href="' . esc_url(get_permalink($r)) . '">' . esc_html(get_the_title($r)) . '</a>' . pw_status_label($r) . '</li>';
        }
    }

    return $items ? '<section class="project-related"><h3>Related</h3><ul>' . $items . '</ul></section>' : '';
}

// Returns the embed HTML for a YouTube/Vimeo link, or '' if it isn't a video
// (or the video could not be embedded). Cached per page load.
function pw_video_embed($url) {
    static $cache = array();

    if (isset($cache[$url])) return $cache[$url];

    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $html = '';

    if (preg_match('/(^|\.)(youtube\.com|youtu\.be|vimeo\.com)$/', $host)) {
        $embed = wp_oembed_get($url, array('width' => 640));
        if ($embed) $html = $embed;
    }

    return $cache[$url] = $html;
}

// Entries that link to this one with [[Title]] in their text.
function pw_section_backlinks($id) {
    $me = pw_norm_title(get_post_field('post_title', $id));
    if ($me === '') return '';

    $items = '';
    foreach (pw_wiki_link_map() as $source => $titles) {
        if ((int) $source === (int) $id || !pw_user_can_see($source)) continue;

        foreach ($titles as $t) {
            if (pw_norm_title($t) === $me) {
                $items .= '<li><a href="' . esc_url(get_permalink($source)) . '">' . esc_html(get_the_title($source)) . '</a>' . pw_status_label($source) . '</li>';
                break;
            }
        }
    }

    return $items ? '<section class="project-backlinks"><h3>Linked from</h3><ul>' . $items . '</ul></section>' : '';
}

function pw_section_links($id) {
    $links = get_post_meta($id, '_project_links', true);
    if (!is_array($links) || !$links) return '';

    $items = '';
    foreach ($links as $link) {
        if (empty($link['url'])) continue;

        // Videos that are embedded in the Videos section are not listed here.
        if (pw_video_embed($link['url'])) continue;

        $label = isset($link['label']) ? $link['label'] : '';
        $short = preg_replace('/^www\./', '', strtolower((string) wp_parse_url($link['url'], PHP_URL_HOST)));

        $items .= '<li><a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener noreferrer">'
            . esc_html($label !== '' ? $label : $short) . '</a>'
            . ($label !== '' ? ' <small>(' . esc_html($short) . ')</small>' : '')
            . '</li>';
    }

    return $items ? '<section class="project-links"><h3>External Links</h3><ul>' . $items . '</ul></section>' : '';
}

function pw_section_videos($id) {
    $links = get_post_meta($id, '_project_links', true);
    if (!is_array($links) || !$links) return '';

    $videos = '';
    foreach ($links as $link) {
        if (empty($link['url'])) continue;

        $embed = pw_video_embed($link['url']);

        if ($embed) {
            $videos .= '<div class="project-video">' . $embed . '</div>';
        }
    }

    return $videos
        ? '<style>.project-video{margin:1em 0}.project-video iframe{max-width:100%}</style>'
            . '<section class="project-videos"><h3>Videos</h3>' . $videos . '</section>'
        : '';
}

function pw_section_same_tags($id) {
    $tag_ids = wp_get_post_tags($id, array('fields' => 'ids'));
    if (!$tag_ids) return '';

    $child_ids = get_posts(array(
        'post_type'      => 'project',
        'post_status'    => array('publish', 'private', 'draft', 'pending', 'future'),
        'post_parent'    => $id,
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));

    $same_tag = get_posts(array(
        'post_type'      => 'project',
        'post_status'    => array('publish', 'private', 'draft', 'pending', 'future'),
        'posts_per_page' => 10,
        // Leave out this entry's parents (the breadcrumbs show them) and its
        // direct children (the "In this section" list shows them)
        'post__not_in'   => array_merge(array($id), get_post_ancestors($id), $child_ids),
        'tag__in'        => $tag_ids,
    ));
    $same_tag = array_filter($same_tag, function ($p) { return pw_user_can_see($p->ID); });
    if (!$same_tag) return '';

    $out = '<section class="project-same-tags"><h3>Shares tags with</h3><ul>';
    foreach ($same_tag as $p) {
        $out .= '<li><a href="' . esc_url(get_permalink($p)) . '">' . esc_html($p->post_title) . '</a>' . pw_status_label($p->ID) . '</li>';
    }
    return $out . '</ul></section>';
}

// Shortcodes, for placing each section yourself in a template:
// [project_breadcrumbs] [project_children] [project_related] [project_backlinks]
// [project_links] [project_videos] [project_same_tags]
foreach (array('breadcrumbs', 'children', 'related', 'backlinks', 'links', 'videos', 'same_tags') as $pw_section) {
    add_shortcode('project_' . $pw_section, function () use ($pw_section) {
        // Inside a template (outside the Post Content block) get_the_ID() can be empty,
        // so fall back to the page being viewed.
        $id = get_the_ID();
        if (!$id) $id = get_queried_object_id();
        if (!$id || get_post_type($id) !== 'project') return '';
        return call_user_func('pw_section_' . $pw_section, $id);
    });
}

function pw_project_navigation($content) {
    if (!pw_auto_sections()) return $content;
    if (!is_singular('project') || !in_the_loop() || !is_main_query()) return $content;

    $id = get_the_ID();

    return pw_section_breadcrumbs($id)
        . $content
        . pw_section_children($id)
        . pw_section_related($id)
        . pw_section_backlinks($id)
        . pw_section_links($id)
        . pw_section_videos($id)
        . pw_section_same_tags($id);
}
add_filter('the_content', 'pw_project_navigation', 20);


