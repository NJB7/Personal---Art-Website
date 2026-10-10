/* Shortcode: [project_table]
 *
 * A sortable, filterable table of Projects: one row per Project, one column
 * per property. Click a column heading to sort. Type in the filter box to
 * narrow the rows. Nothing is hard coded: new Projects, categories and tags
 * appear on their own.
 *
 * Works best with the "Project Journal" snippet (status and journal counts)
 * but still runs without it.
 *
 * Options (all optional):
 *   columns        which columns, in order. Default:
 *                  title,status,medium,subject,tags,journal,edited
 *                  Available: title, status, medium, subject, tags,
 *                  parent, journal, links, edited, created
 *   status         only these statuses, comma separated: idea, in-progress,
 *                  finished, none (= no status set)
 *   under          category slug: only Projects in it or its subcategories
 *                  e.g. under="form"
 *   category       one or more category slugs (subcategories included)
 *   tag            one or more tag slugs
 *   parent         "current" (sub-projects of this page), a Project ID, or
 *                  a slug. Default: all Projects at every level
 *   medium_slug    category that holds your mediums (default form)
 *   subject_slug   category that holds your subjects (default topics)
 *   orderby        title (default), modified, date, menu_order
 *   order          ASC (default) or DESC
 *   limit          maximum rows
 *   filter         yes (default) or no: show the filter box
 *
 * Examples:
 *   [project_table]
 *   [project_table status="in-progress" orderby="modified" order="DESC"]
 *   [project_table under="form" columns="title,medium,status,edited"]
 *   [project_table parent="current" columns="title,status,journal"]
 */

function ptbl_column_labels() {
    return [
        'title'   => 'Project',
        'status'  => 'Status',
        'medium'  => 'Medium',
        'subject' => 'Subject',
        'tags'    => 'Tags',
        'parent'  => 'Part of',
        'journal' => 'Journal entries',
        'links'   => 'Related',
        'edited'  => 'Last edited',
        'created' => 'Started',
    ];
}

/* IDs of a category's subcategories (not the category itself). */
function ptbl_descendants($slug) {

    $term = get_term_by('slug', sanitize_title($slug), 'category');

    if (!$term) {
        return [];
    }

    $ids = get_term_children($term->term_id, 'category');

    return is_wp_error($ids) ? [] : array_map('intval', $ids);
}

/* Published journal entries per Project: [project id => count]. */
function ptbl_journal_counts() {

    global $wpdb;

    $types = function_exists('pj_entry_types') ? pj_entry_types() : ['journal', 'post'];
    $marks = implode(',', array_fill(0, count($types), '%s'));

    $sql = $wpdb->prepare(
        "SELECT pm.meta_value AS pid, COUNT(DISTINCT p.ID) AS n
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_post_project'
           AND p.post_status = 'publish'
           AND p.post_type IN ($marks)
         GROUP BY pm.meta_value",
        $types
    );

    $out = [];

    foreach ((array) $wpdb->get_results($sql) as $row) {
        $out[(int) $row->pid] = (int) $row->n;
    }

    return $out;
}

function ptbl_shortcode($atts) {

    $a = shortcode_atts([
        'columns'      => 'title,status,medium,subject,tags,journal,edited',
        'status'       => '',
        'under'        => '',
        'category'     => '',
        'tag'          => '',
        'parent'       => '',
        'medium_slug'  => 'form',
        'subject_slug' => 'topics',
        'orderby'      => 'title',
        'order'        => 'ASC',
        'limit'        => 0,
        'filter'       => 'yes',
    ], $atts, 'project_table');

    if (!post_type_exists('project')) {
        return '';
    }

    /* ---- columns ---- */

    $labels  = ptbl_column_labels();
    $columns = [];

    foreach (explode(',', strtolower($a['columns'])) as $col) {
        $col = trim($col);
        if (isset($labels[$col]) && !in_array($col, $columns, true)) {
            $columns[] = $col;
        }
    }

    if (!$columns) {
        $columns = ['title'];
    }

    /* ---- which Projects ---- */

    $can_see_all = current_user_can('edit_others_posts');

    $args = [
        'post_type'      => 'project',
        'post_status'    => $can_see_all ? ['publish', 'private', 'draft', 'pending', 'future'] : 'publish',
        'posts_per_page' => -1,
        'no_found_rows'  => true,
        'order'          => strtoupper($a['order']) === 'DESC' ? 'DESC' : 'ASC',
    ];

    $orderby = strtolower($a['orderby']);
    $args['orderby'] = in_array($orderby, ['title', 'modified', 'date', 'menu_order'], true) ? $orderby : 'title';

    $tax_query = [];

    if ($a['under'] !== '') {

        $under_term = get_term_by('slug', sanitize_title($a['under']), 'category');

        if ($under_term) {
            $tax_query[] = [
                'taxonomy'         => 'category',
                'field'            => 'term_id',
                'terms'            => array_merge([(int) $under_term->term_id], ptbl_descendants($a['under'])),
                'include_children' => false,
            ];
        } else {
            return '';
        }
    }

    if ($a['category'] !== '') {
        $tax_query[] = [
            'taxonomy' => 'category',
            'field'    => 'slug',
            'terms'    => array_map('sanitize_title', array_map('trim', explode(',', $a['category']))),
        ];
    }

    if ($a['tag'] !== '') {
        $tax_query[] = [
            'taxonomy' => 'post_tag',
            'field'    => 'slug',
            'terms'    => array_map('sanitize_title', array_map('trim', explode(',', $a['tag']))),
        ];
    }

    if ($tax_query) {
        $tax_query['relation'] = 'AND';
        $args['tax_query']     = $tax_query;
    }

    if ($a['parent'] !== '') {

        if ($a['parent'] === 'current') {
            $args['post_parent'] = (int) (get_the_ID() ?: get_queried_object_id());
        } elseif (is_numeric($a['parent'])) {
            $args['post_parent'] = (int) $a['parent'];
        } else {
            $found = get_page_by_path(trim($a['parent'], '/'), OBJECT, 'project');
            $args['post_parent'] = $found ? (int) $found->ID : -1;
        }
    }

    $projects = get_posts($args);

    /* ---- status filter (done here so "none" works) ---- */

    $wanted = array_filter(array_map('trim', explode(',', strtolower($a['status']))));

    if ($wanted) {

        $projects = array_values(array_filter($projects, function ($p) use ($wanted) {

            $s = get_post_meta($p->ID, '_project_status', true);

            return in_array($s === '' ? 'none' : $s, $wanted, true);
        }));
    }

    $limit = (int) $a['limit'];

    if ($limit > 0) {
        $projects = array_slice($projects, 0, $limit);
    }

    if (!$projects) {
        return '';
    }

    /* ---- lookups used by several columns ---- */

    $statuses = function_exists('pj_statuses') ? pj_statuses() : [
        'idea' => 'Idea', 'in-progress' => 'In progress', 'finished' => 'Finished',
    ];

    $medium_ids  = in_array('medium', $columns, true)  ? ptbl_descendants($a['medium_slug'])  : [];
    $subject_ids = in_array('subject', $columns, true) ? ptbl_descendants($a['subject_slug']) : [];
    $journal     = in_array('journal', $columns, true) ? ptbl_journal_counts() : [];

    $cat_names = function ($post_id, $only_ids) {

        $terms = get_the_terms($post_id, 'category');

        if (!$terms || is_wp_error($terms) || !$only_ids) {
            return [];
        }

        $out = [];

        foreach ($terms as $term) {
            if (in_array((int) $term->term_id, $only_ids, true)) {
                $out[] = $term;
            }
        }

        return $out;
    };

    $term_links = function ($terms) {

        $html = [];

        foreach ($terms as $term) {
            $url    = get_term_link($term);
            $html[] = is_wp_error($url)
                ? esc_html($term->name)
                : '<a href="' . esc_url($url) . '">' . esc_html($term->name) . '</a>';
        }

        return implode(', ', $html);
    };

    static $instance = 0;
    $instance++;

    ob_start();

    if ($instance === 1) : ?>

        <style>
            .ptbl-filter { width: 100%; max-width: 22em; box-sizing: border-box; padding: .5em .7em; font: inherit; margin: 0 0 .4em; }
            .ptbl-count { font-size: .85em; opacity: .7; margin: 0 0 .6em; }
            .ptbl-scroll { overflow-x: auto; }
            .ptbl table { width: 100%; border-collapse: collapse; margin: 0 0 1.5em; }
            .ptbl th, .ptbl td { text-align: left; vertical-align: top; padding: .55em .7em; border-bottom: 1px solid rgba(128,128,128,.35); }
            .ptbl th { white-space: nowrap; font-weight: 600; }
            .ptbl th[data-sortable] { cursor: pointer; user-select: none; }
            .ptbl th[data-sortable]:hover { text-decoration: underline; }
            .ptbl th .ptbl-arrow { display: inline-block; width: 1em; opacity: .6; }
            .ptbl td.ptbl-num, .ptbl th.ptbl-num { text-align: right; }
            .ptbl .ptbl-draft { opacity: .65; font-size: .85em; }
        </style>

        <script>
        document.addEventListener('DOMContentLoaded', function () {

            document.querySelectorAll('.ptbl').forEach(function (wrap) {

                const table  = wrap.querySelector('table');
                const body   = table.tBodies[0];
                const filter = wrap.querySelector('.ptbl-filter');
                const count  = wrap.querySelector('.ptbl-count');
                let sortCol  = -1;
                let sortDir  = 1;

                function rows() {
                    return Array.prototype.slice.call(body.rows);
                }

                function updateCount() {
                    const total   = rows().length;
                    const visible = rows().filter(function (r) { return r.style.display !== 'none'; }).length;
                    if (count) {
                        count.textContent = visible === total
                            ? total + (total === 1 ? ' project' : ' projects')
                            : visible + ' of ' + total + ' projects';
                    }
                }

                function value(row, col) {
                    const cell = row.cells[col];
                    const v = cell.getAttribute('data-sort');
                    return v !== null ? v : cell.textContent.trim().toLowerCase();
                }

                table.querySelectorAll('th[data-sortable]').forEach(function (th) {

                    th.addEventListener('click', function () {

                        const col = th.cellIndex;
                        sortDir = (sortCol === col) ? -sortDir : 1;
                        sortCol = col;

                        const sorted = rows().sort(function (a, b) {

                            const x = value(a, col);
                            const y = value(b, col);
                            const nx = parseFloat(x);
                            const ny = parseFloat(y);

                            /* Blank values always go last */
                            if (x === '' && y !== '') { return 1; }
                            if (y === '' && x !== '') { return -1; }

                            if (!isNaN(nx) && !isNaN(ny) && String(nx) === x && String(ny) === y) {
                                return (nx - ny) * sortDir;
                            }

                            return x.localeCompare(y, undefined, { numeric: true }) * sortDir;
                        });

                        sorted.forEach(function (r) { body.appendChild(r); });

                        table.querySelectorAll('.ptbl-arrow').forEach(function (s) { s.textContent = ''; });
                        th.querySelector('.ptbl-arrow').textContent = sortDir === 1 ? '▲' : '▼';
                    });
                });

                if (filter) {
                    filter.addEventListener('input', function () {

                        const words = filter.value.toLowerCase().split(/\s+/).filter(Boolean);

                        rows().forEach(function (r) {
                            const text = r.textContent.toLowerCase();
                            const show = words.every(function (w) { return text.indexOf(w) !== -1; });
                            r.style.display = show ? '' : 'none';
                        });

                        updateCount();
                    });
                }

                updateCount();
            });
        });
        </script>

    <?php endif; ?>

    <div class="ptbl">

        <?php if (strtolower($a['filter']) !== 'no') : ?>
            <input type="search" class="ptbl-filter" placeholder="Filter, e.g. cyanotype in progress" aria-label="Filter projects">
        <?php endif; ?>

        <p class="ptbl-count"></p>

        <div class="ptbl-scroll">
            <table>
                <thead>
                    <tr>
                        <?php foreach ($columns as $col) :
                            $numeric = in_array($col, ['journal', 'links'], true); ?>
                            <th data-sortable="1" data-col="<?php echo esc_attr($col); ?>"<?php echo $numeric ? ' class="ptbl-num"' : ''; ?>>
                                <?php echo esc_html($labels[$col]); ?> <span class="ptbl-arrow"></span>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($projects as $p) : ?>
                    <tr>
                    <?php foreach ($columns as $col) :

                        $sort  = null;
                        $html  = '';
                        $class = '';

                        switch ($col) {

                            case 'title':
                                $title = get_the_title($p) !== '' ? get_the_title($p) : '(no title)';
                                $html  = '<a href="' . esc_url(get_permalink($p)) . '">' . esc_html($title) . '</a>';
                                if ($p->post_status !== 'publish') {
                                    $html .= ' <span class="ptbl-draft">(' . esc_html($p->post_status) . ')</span>';
                                }
                                $sort = strtolower($title);
                                break;

                            case 'status':
                                $key  = get_post_meta($p->ID, '_project_status', true);
                                $text = isset($statuses[$key]) ? $statuses[$key] : '';
                                $html = esc_html($text);
                                $sort = strtolower($text);
                                break;

                            case 'medium':
                                $terms = $cat_names($p->ID, $medium_ids);
                                $html  = $term_links($terms);
                                $sort  = strtolower(implode(', ', wp_list_pluck($terms, 'name')));
                                break;

                            case 'subject':
                                $terms = $cat_names($p->ID, $subject_ids);
                                $html  = $term_links($terms);
                                $sort  = strtolower(implode(', ', wp_list_pluck($terms, 'name')));
                                break;

                            case 'tags':
                                $terms = get_the_terms($p->ID, 'post_tag');
                                $terms = ($terms && !is_wp_error($terms)) ? $terms : [];
                                $html  = $term_links($terms);
                                $sort  = strtolower(implode(', ', wp_list_pluck($terms, 'name')));
                                break;

                            case 'parent':
                                if ($p->post_parent) {
                                    $html = '<a href="' . esc_url(get_permalink($p->post_parent)) . '">' . esc_html(get_the_title($p->post_parent)) . '</a>';
                                    $sort = strtolower(get_the_title($p->post_parent));
                                }
                                break;

                            case 'journal':
                                $n     = isset($journal[(int) $p->ID]) ? $journal[(int) $p->ID] : 0;
                                $html  = $n ? (int) $n : '';
                                $sort  = (string) $n;
                                $class = 'ptbl-num';
                                break;

                            case 'links':
                                $rel   = get_post_meta($p->ID, '_project_related', true);
                                $n     = is_array($rel) ? count($rel) : 0;
                                $html  = $n ? (int) $n : '';
                                $sort  = (string) $n;
                                $class = 'ptbl-num';
                                break;

                            case 'edited':
                                $ts   = strtotime($p->post_modified_gmt . ' UTC');
                                $html = esc_html(date_i18n('M j, Y', $ts + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS)));
                                $sort = (string) $ts;
                                break;

                            case 'created':
                                $ts   = strtotime($p->post_date_gmt . ' UTC');
                                $html = esc_html(get_the_date('M Y', $p));
                                $sort = (string) $ts;
                                break;
                        }
                        ?>
                        <td<?php echo $class ? ' class="' . esc_attr($class) . '"' : ''; ?><?php echo $sort !== null ? ' data-sort="' . esc_attr($sort) . '"' : ''; ?>><?php echo $html; ?></td>
                    <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>

    <?php

    return ob_get_clean();
}

add_shortcode('project_table', 'ptbl_shortcode');
