/* Unattached Journal Entries (admin tool)
 *
 * Journal > Unattached entries. Lists every Journal entry that is not attached
 * to a Project (including entries whose project has since been deleted) and
 * lets you choose a project for each one, then save them all in one go.
 *
 * What you can do:
 *   - Pick a project (and optionally a second one) for each entry.
 *   - Tick several entries and give them all the same project in one click.
 *   - Tap a "Looks related" suggestion, taken from project names mentioned in
 *     the entry, to fill the picker for you. Nothing is saved until you press
 *     Save.
 *   - Tick "No project needed" on an entry that is meant to stand alone. It
 *     disappears from the list (and can be brought back from the
 *     "Skipped" view).
 *
 * Nothing is ever deleted, and entries you leave alone are not changed. It only
 * writes the same hidden _post_project field the Projects box on the entry
 * screen uses.
 *
 * Needs the Project/Journal snippet to be active (for the journal and project
 * post types). Safe to leave switched on.
 */

function uje_skip_key() {
    return '_pj_skip_project_check';
}

function uje_statuses() {
    return ['publish', 'draft', 'private', 'pending', 'future'];
}

/* All projects, in tree order, as [id, label] pairs for a dropdown. */
function uje_project_choices() {

    $projects = get_posts([
        'post_type'      => 'project',
        'post_status'    => uje_statuses(),
        'posts_per_page' => -1,
        'orderby'        => 'menu_order title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ]);

    $known    = wp_list_pluck($projects, 'ID');
    $children = [];

    foreach ($projects as $project) {
        $parent = in_array((int) $project->post_parent, $known, true) ? (int) $project->post_parent : 0;
        $children[$parent][] = $project;
    }

    $choices = [];

    $walk = function ($parent, $depth) use (&$walk, $children, &$choices) {

        if (empty($children[$parent])) {
            return;
        }

        foreach ($children[$parent] as $project) {

            $label = get_the_title($project) ?: '(no title)';

            if ($project->post_status !== 'publish') {
                $label .= ' (' . $project->post_status . ')';
            }

            $choices[] = [(int) $project->ID, str_repeat('– ', $depth) . $label];

            $walk((int) $project->ID, $depth + 1);
        }
    };

    $walk(0, 0);

    return $choices;
}

/* Journal entries (optionally blog posts too) with no working project link. */
function uje_find_entries($types, $valid_ids, $want_skipped) {

    $entries = get_posts([
        'post_type'      => $types,
        'post_status'    => uje_statuses(),
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ]);

    $found = [];

    foreach ($entries as $entry) {

        $linked = array_map('intval', get_post_meta($entry->ID, '_post_project'));

        if (array_intersect($linked, $valid_ids)) {
            continue;
        }

        $skipped = get_post_meta($entry->ID, uje_skip_key(), true) === '1';

        if ($skipped !== $want_skipped) {
            continue;
        }

        $found[] = $entry;
    }

    return $found;
}

/* Projects whose names appear in the entry's title or text, longest names first. */
function uje_suggest($entry, $titles) {

    $hay = mb_strtolower($entry->post_title . ' ' . mb_substr(wp_strip_all_tags($entry->post_content), 0, 6000));

    $hits = [];

    foreach ($titles as $id => $title) {
        if (mb_strlen($title) >= 4 && strpos($hay, $title) !== false) {
            $hits[$id] = mb_strlen($title);
        }
    }

    arsort($hits);

    return array_slice(array_keys($hits), 0, 3);
}

add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=journal',
        'Unattached entries',
        'Unattached entries',
        'edit_posts',
        'unattached-journal',
        'uje_render_page'
    );
});

function uje_render_page() {

    if (!current_user_can('edit_posts')) {
        return;
    }

    $choices   = uje_project_choices();
    $valid_ids = array_map(function ($c) {
        return $c[0];
    }, $choices);

    $include_posts = !empty($_REQUEST['uje_posts']);
    $show_skipped  = !empty($_REQUEST['uje_skipped']);
    $types         = $include_posts ? ['journal', 'post'] : ['journal'];
    $per_page      = 40;
    $paged         = max(1, isset($_REQUEST['paged']) ? absint($_REQUEST['paged']) : 1);
    $message       = '';

    /* ---------------- save ---------------- */

    if (isset($_POST['uje_action']) && check_admin_referer('uje_run', 'uje_nonce')) {

        if ($_POST['uje_action'] === 'save') {

            $rows     = isset($_POST['uje_p']) ? (array) wp_unslash($_POST['uje_p']) : [];
            $skipbox  = isset($_POST['uje_skip']) ? (array) wp_unslash($_POST['uje_skip']) : [];
            $attached = 0;
            $skipped  = 0;

            foreach ($rows as $id => $picks) {

                $id   = absint($id);
                $post = get_post($id);

                if (!$post || !in_array($post->post_type, ['journal', 'post'], true) || !current_user_can('edit_post', $id)) {
                    continue;
                }

                $picks = array_unique(array_filter(array_map('absint', (array) $picks)));
                $picks = array_values(array_filter($picks, function ($pid) use ($valid_ids) {
                    return in_array($pid, $valid_ids, true);
                }));

                if ($picks) {

                    /* Also clears links to projects that no longer exist */
                    delete_post_meta($id, '_post_project');

                    foreach ($picks as $pid) {
                        add_post_meta($id, '_post_project', $pid);
                    }

                    delete_post_meta($id, uje_skip_key());
                    $attached++;

                } elseif (!empty($skipbox[$id])) {

                    update_post_meta($id, uje_skip_key(), '1');
                    $skipped++;
                }
            }

            $bits = [];

            if ($attached) {
                $bits[] = 'attached ' . $attached . ' entr' . ($attached === 1 ? 'y' : 'ies') . ' to projects';
            }

            if ($skipped) {
                $bits[] = 'set aside ' . $skipped . ' as needing no project';
            }

            $message = $bits
                ? '<div class="notice notice-success"><p>Done: ' . esc_html(implode(' and ', $bits)) . '.</p></div>'
                : '<div class="notice notice-info"><p>Nothing to save. Choose a project (or tick "No project needed") for at least one entry first.</p></div>';
        }

        if ($_POST['uje_action'] === 'unskip') {

            $ids   = isset($_POST['uje_ids']) ? array_map('absint', (array) $_POST['uje_ids']) : [];
            $moved = 0;

            foreach ($ids as $id) {
                if (current_user_can('edit_post', $id)) {
                    delete_post_meta($id, uje_skip_key());
                    $moved++;
                }
            }

            $message = '<div class="notice notice-success"><p>Put ' . (int) $moved . ' entr' . ($moved === 1 ? 'y' : 'ies') . ' back in the list.</p></div>';
        }
    }

    /* ---------------- list ---------------- */

    $all    = uje_find_entries($types, $valid_ids, $show_skipped);
    $total  = count($all);
    $pages  = max(1, (int) ceil($total / $per_page));
    $paged  = min($paged, $pages);
    $shown  = array_slice($all, ($paged - 1) * $per_page, $per_page);

    $base = add_query_arg(array_filter([
        'post_type'   => 'journal',
        'page'        => 'unattached-journal',
        'uje_posts'   => $include_posts ? 1 : null,
        'uje_skipped' => $show_skipped ? 1 : null,
    ]), admin_url('edit.php'));

    $titles = [];

    foreach ($choices as $choice) {
        $titles[$choice[0]] = mb_strtolower(trim(get_the_title($choice[0])));
    }

    $options = '';

    foreach ($choices as $choice) {
        $options .= '<option value="' . (int) $choice[0] . '">' . esc_html($choice[1]) . '</option>';
    }

    echo '<style>
        .uje-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 14px;margin:0 0 10px;max-width:760px}
        .uje-head{display:flex;flex-wrap:wrap;gap:4px 12px;align-items:baseline;margin-bottom:4px}
        .uje-head a{font-weight:600;font-size:14px}
        .uje-meta{color:#646970;font-size:12px}
        .uje-excerpt{margin:4px 0 8px;color:#3c434a}
        .uje-pick{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px}
        .uje-pick select{min-width:200px;max-width:100%}
        .uje-hints{margin:0 0 6px;font-size:12px}
        .uje-hints .button{margin:0 4px 4px 0}
        .uje-foot{display:flex;flex-wrap:wrap;gap:4px 18px;font-size:13px}
        .uje-bar{background:#f6f7f7;border:1px solid #c3c4c7;border-radius:4px;padding:10px 14px;margin:12px 0;max-width:760px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
        .uje-bar select{max-width:100%}
    </style>';

    echo '<div class="wrap"><h1>Unattached entries</h1>' . $message;

    /* view options */
    echo '<form method="get" style="margin:1em 0">'
        . '<input type="hidden" name="post_type" value="journal">'
        . '<input type="hidden" name="page" value="unattached-journal">'
        . '<label style="margin-right:16px"><input type="checkbox" name="uje_posts" value="1"' . checked($include_posts, true, false) . '> Include blog posts</label>'
        . '<label style="margin-right:16px"><input type="checkbox" name="uje_skipped" value="1"' . checked($show_skipped, true, false) . '> Show the ones I set aside</label>'
        . '<button class="button">Update list</button></form>';

    /* ---- skipped view ---- */
    if ($show_skipped) {

        if (!$all) {
            echo '<p>No entries have been set aside.</p></div>';
            return;
        }

        echo '<p>These entries were marked "No project needed". Tick any to put them back in the main list.</p>';
        echo '<form method="post">';
        wp_nonce_field('uje_run', 'uje_nonce');
        echo '<input type="hidden" name="uje_skipped" value="1">' . ($include_posts ? '<input type="hidden" name="uje_posts" value="1">' : '');

        foreach ($shown as $entry) {
            echo '<div class="uje-card"><label><input type="checkbox" name="uje_ids[]" value="' . (int) $entry->ID . '"> '
                . '<a href="' . esc_url(get_edit_post_link($entry->ID)) . '" target="_blank">'
                . esc_html($entry->post_title !== '' ? $entry->post_title : '(no title)') . '</a> '
                . '<span class="uje-meta">' . esc_html(get_the_date('j M Y', $entry)) . '</span></label></div>';
        }

        echo '<p><button type="submit" name="uje_action" value="unskip" class="button">Put ticked entries back in the list</button></p></form></div>';
        return;
    }

    /* ---- main view ---- */

    if (!$choices) {
        echo '<p>There are no Projects yet, so there is nothing to attach entries to.</p></div>';
        return;
    }

    if (!$all) {
        echo '<div class="notice notice-success inline"><p>Every ' . ($include_posts ? 'journal entry and blog post' : 'journal entry') . ' is attached to a project (or set aside).</p></div></div>';
        return;
    }

    echo '<p><strong>' . (int) $total . '</strong> ' . ($include_posts ? 'entries' : 'journal entr' . ($total === 1 ? 'y' : 'ies'))
        . ' without a project. Choose a project for each, then press Save. Entries you leave alone are not changed.</p>';

    echo '<form method="post" id="uje-form">';
    wp_nonce_field('uje_run', 'uje_nonce');
    echo '<input type="hidden" name="paged" value="' . (int) $paged . '">'
        . ($include_posts ? '<input type="hidden" name="uje_posts" value="1">' : '');

    /* bulk bar */
    echo '<div class="uje-bar">'
        . '<label><input type="checkbox" id="uje-all"> Tick all</label>'
        . '<select id="uje-bulk-project"><option value="">Give ticked entries this project…</option>' . $options . '</select>'
        . '<button type="button" class="button" id="uje-bulk-apply">Fill in</button>'
        . '<span class="description">This only fills the pickers below. Press Save to keep it.</span>'
        . '</div>';

    foreach ($shown as $entry) {

        $id      = (int) $entry->ID;
        $title   = $entry->post_title !== '' ? $entry->post_title : '(no title)';
        $excerpt = wp_trim_words(wp_strip_all_tags(strip_shortcodes($entry->post_content)), 28, '…');
        $hints   = uje_suggest($entry, $titles);
        $status  = $entry->post_status !== 'publish' ? ' · ' . $entry->post_status : '';

        echo '<div class="uje-card" data-id="' . $id . '">';

        echo '<div class="uje-head"><label><input type="checkbox" class="uje-tick"> </label>'
            . '<a href="' . esc_url(get_edit_post_link($id)) . '" target="_blank">' . esc_html($title) . '</a>'
            . '<span class="uje-meta">' . esc_html(get_the_date('j M Y', $entry)) . esc_html($status)
            . ($entry->post_type === 'post' ? ' · blog post' : '') . '</span></div>';

        if ($excerpt !== '') {
            echo '<p class="uje-excerpt">' . esc_html($excerpt) . '</p>';
        }

        echo '<div class="uje-pick">'
            . '<select class="uje-sel" name="uje_p[' . $id . '][]"><option value="">Project…</option>' . $options . '</select>'
            . '<select class="uje-sel uje-sel2" name="uje_p[' . $id . '][]"><option value="">Also in (optional)…</option>' . $options . '</select>'
            . '</div>';

        if ($hints) {
            echo '<p class="uje-hints">Looks related: ';
            foreach ($hints as $pid) {
                echo '<button type="button" class="button button-small uje-hint" data-pid="' . (int) $pid . '">'
                    . esc_html(get_the_title($pid)) . '</button>';
            }
            echo '</p>';
        }

        echo '<div class="uje-foot"><label><input type="checkbox" name="uje_skip[' . $id . ']" value="1"> No project needed</label></div>';

        echo '</div>';
    }

    echo '<p><button type="submit" name="uje_action" value="save" class="button button-primary button-large">Save</button></p>';
    echo '</form>';

    /* paging */
    if ($pages > 1) {
        echo '<p>Page ' . (int) $paged . ' of ' . (int) $pages . ' &nbsp; ';
        if ($paged > 1) {
            echo '<a class="button" href="' . esc_url(add_query_arg('paged', $paged - 1, $base)) . '">Previous</a> ';
        }
        if ($paged < $pages) {
            echo '<a class="button" href="' . esc_url(add_query_arg('paged', $paged + 1, $base)) . '">Next</a>';
        }
        echo '</p><p class="description">Save before changing page: choices on this page are only kept when you press Save.</p>';
    }

    echo '</div>';

    echo '<script>' . <<<'JS'
    (function () {
        var form = document.getElementById('uje-form');
        if (!form) return;

        var all = document.getElementById('uje-all');
        var bulkSelect = document.getElementById('uje-bulk-project');
        var bulkApply = document.getElementById('uje-bulk-apply');

        all.addEventListener('change', function () {
            form.querySelectorAll('.uje-tick').forEach(function (box) { box.checked = all.checked; });
        });

        bulkApply.addEventListener('click', function () {
            if (!bulkSelect.value) return;
            form.querySelectorAll('.uje-card').forEach(function (card) {
                var tick = card.querySelector('.uje-tick');
                if (tick && tick.checked) {
                    card.querySelector('.uje-sel').value = bulkSelect.value;
                }
            });
        });

        form.querySelectorAll('.uje-hint').forEach(function (button) {
            button.addEventListener('click', function () {
                var card = button.closest('.uje-card');
                var first = card.querySelector('.uje-sel');
                var second = card.querySelector('.uje-sel2');
                var pid = button.getAttribute('data-pid');
                if (!first.value || first.value === pid) {
                    first.value = pid;
                } else {
                    second.value = pid;
                }
            });
        });
    })();

JS
    . '</script>';
}
