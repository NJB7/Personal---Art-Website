/* Shortcode: [project_link_errors]
 *
 * Put this on a page called "Link Errors". Only people who can edit other
 * people's posts (you, as the site owner) see anything on it.
 *
 * It reports:
 *   1. Missing pages    - [[Title]] links that do not match any Project
 *   2. Broken links     - web links (the External Links box, plus links
 *                         typed inside an entry) that fail when visited
 *   3. Dead related     - "Related" links to entries that no longer exist
 *   4. Uncategorised    - blog posts and Journal entries with no category
 *                         (or only the default "Uncategorized")
 *   5. Stale            - Projects not edited for a while
 *   6. Unlinked         - Projects with no Related links and no [[links]]
 *                         in or out
 *
 * Nothing is ever changed or deleted. These sections only flag things.
 *
 * Options: [project_link_errors stale_days="180" stale_skip_finished="yes"]
 *   stale_days           days without an edit before a Project counts as
 *                        stale (default 180)
 *   stale_skip_finished  yes (default) = Projects whose Status is Finished
 *                        are not flagged as stale; no = flag them too
 *
 * Missing pages and dead related links are checked every time the page loads.
 * Web links are checked when you press "Check links now", because visiting
 * every site takes a while. Results are saved until you check again.
 *
 * Requires the "Create Project Post Type Function" (Projects Wiki) snippet.
 */


/* ============================================================
 * Who may see this page
 * ============================================================ */

function pwle_can_view() {
    return is_user_logged_in() && current_user_can('edit_others_posts');
}


/* ============================================================
 * Data collection
 * ============================================================ */

function pwle_projects() {
    return get_posts([
        'post_type'      => 'project',
        'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ]);
}

/* [[Title]] links that do not match any Project. */
function pwle_missing_pages() {

    $titles = [];

    foreach (pwle_projects() as $project) {
        $titles[pw_norm_title($project->post_title)] = true;
    }

    $missing = [];

    foreach (pw_wiki_link_map() as $source => $list) {
        foreach ($list as $title) {

            $key = pw_norm_title($title);

            if ($key === '' || isset($titles[$key])) {
                continue;
            }

            if (!isset($missing[$key])) {
                $missing[$key] = ['title' => $title, 'sources' => []];
            }

            $missing[$key]['sources'][(int) $source] = true;
        }
    }

    ksort($missing);

    return $missing;
}

/* "Related" links pointing at entries that were deleted or trashed. */
function pwle_dead_related() {

    $dead = [];

    foreach (pwle_projects() as $project) {

        $related = get_post_meta($project->ID, '_project_related', true);

        if (!is_array($related)) {
            continue;
        }

        foreach ($related as $related_id) {

            $status = get_post_status($related_id);

            if (!$status || $status === 'trash' || get_post_type($related_id) !== 'project') {
                $dead[$project->ID][] = (int) $related_id;
            }
        }
    }

    return $dead;
}

/* Blog posts and Journal entries with no real category. */
function pwle_uncategorised() {

    $default = (int) get_option('default_category');

    $entries = get_posts([
        'post_type'      => ['post', 'journal'],
        'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
    ]);

    $out = [];

    foreach ($entries as $entry) {

        $terms = get_the_terms($entry->ID, 'category');

        if (is_wp_error($terms)) {
            continue;
        }

        $ids = $terms ? array_map('intval', wp_list_pluck($terms, 'term_id')) : [];
        $ids = array_diff($ids, [$default]);

        if (!$ids) {
            $out[] = $entry;
        }
    }

    return $out;
}

/* Projects not edited for $days days (oldest first). */
function pwle_stale_projects($days, $skip_finished = true) {

    $cutoff = time() - ($days * DAY_IN_SECONDS);
    $out    = [];

    foreach (pwle_projects() as $project) {

        if ($skip_finished && get_post_meta($project->ID, '_project_status', true) === 'finished') {
            continue;
        }

        $modified = strtotime($project->post_modified_gmt . ' UTC');

        if ($modified && $modified < $cutoff) {
            $out[] = ['post' => $project, 'modified' => $modified];
        }
    }

    usort($out, function ($a, $b) {
        return $a['modified'] <=> $b['modified'];
    });

    return $out;
}

/* Projects with no Related links and no [[links]] going in or out. */
function pwle_unlinked_projects() {

    $projects = pwle_projects();
    $by_title = [];
    $parents  = [];
    $linked   = [];

    foreach ($projects as $project) {
        $by_title[pw_norm_title($project->post_title)] = (int) $project->ID;
        if ($project->post_parent) {
            $parents[(int) $project->post_parent] = true;
        }
    }

    foreach ($projects as $project) {

        $related = get_post_meta($project->ID, '_project_related', true);

        if (!is_array($related)) {
            continue;
        }

        foreach ($related as $related_id) {

            $related_id = (int) $related_id;

            if ($related_id !== (int) $project->ID && get_post_type($related_id) === 'project' && get_post_status($related_id) !== 'trash') {
                $linked[(int) $project->ID] = true;
                $linked[$related_id]        = true;
            }
        }
    }

    foreach (pw_wiki_link_map() as $source => $titles) {

        foreach ($titles as $title) {

            $key = pw_norm_title($title);

            if (isset($by_title[$key]) && $by_title[$key] !== (int) $source) {
                $linked[(int) $source]  = true;
                $linked[$by_title[$key]] = true;
            }
        }
    }

    $out = [];

    foreach ($projects as $project) {

        if (isset($linked[(int) $project->ID])) {
            continue;
        }

        $out[] = [
            'post'    => $project,
            'in_tree' => $project->post_parent || isset($parents[(int) $project->ID]),
        ];
    }

    return $out;
}

/* Every web link on the site's Projects: url => [project IDs]. */
function pwle_urls() {

    $urls = [];

    foreach (pwle_projects() as $project) {

        $found = [];

        $links = get_post_meta($project->ID, '_project_links', true);

        if (is_array($links)) {
            foreach ($links as $link) {
                if (!empty($link['url'])) {
                    $found[] = $link['url'];
                }
            }
        }

        if (preg_match_all('/href=["\']([^"\']+)["\']/i', $project->post_content, $matches)) {
            foreach ($matches[1] as $href) {
                $found[] = $href;
            }
        }

        foreach ($found as $url) {

            $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));

            if ($url === '' || $url[0] === '#' || preg_match('/^(mailto|tel|javascript|sms):/i', $url)) {
                continue;
            }

            if (strpos($url, '//') === 0) {
                $url = 'https:' . $url;
            } elseif ($url[0] === '/') {
                $url = home_url($url);
            }

            if (!preg_match('#^https?://#i', $url)) {
                continue;
            }

            $urls[$url][$project->ID] = true;
        }
    }

    ksort($urls);

    return $urls;
}


/* ============================================================
 * Checking one web link
 *   ok        = the page loads
 *   broken    = no response, not found (404/410) or a server error
 *   uncertain = the site refuses automated visits (401/403/429...).
 *               The link may be fine; open it yourself to be sure.
 * ============================================================ */

function pwle_check_url($url) {

    $args = [
        'timeout'     => 7,
        'redirection' => 5,
        'user-agent'  => 'Mozilla/5.0 (compatible; link checker for ' . home_url() . ')',
    ];

    $response = wp_safe_remote_head($url, $args);
    $code     = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

    /* Many sites refuse HEAD requests, so retry with a small GET */
    if ($code === 0 || $code >= 400) {

        $retry = wp_safe_remote_get($url, array_merge($args, ['limit_response_size' => 20000]));

        $retry_code = is_wp_error($retry) ? 0 : (int) wp_remote_retrieve_response_code($retry);

        if ($retry_code !== 0 || is_wp_error($response)) {
            $response = $retry;
            $code     = $retry_code;
        }
    }

    $message = '';

    if ($code === 0) {

        $status  = 'broken';
        $message = is_wp_error($response) ? $response->get_error_message() : 'No response';

    } elseif ($code === 404 || $code === 410) {

        $status  = 'broken';
        $message = 'Page not found (' . $code . ')';

    } elseif ($code >= 500) {

        $status  = 'broken';
        $message = 'Server error (' . $code . ')';

    } elseif ($code >= 400) {

        $status  = 'uncertain';
        $message = 'The site refused the check (' . $code . ')';

    } else {

        $status = 'ok';
    }

    return [
        'status'  => $status,
        'code'    => $code,
        'message' => $message,
        'checked' => time(),
    ];
}

/* Checks the links a few at a time so the page never times out */
function pwle_ajax_check_links() {

    check_ajax_referer('pwle_check', 'nonce');

    if (!pwle_can_view()) {
        wp_send_json_error();
    }

    @set_time_limit(90);

    $offset  = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
    $urls    = array_keys(pwle_urls());
    $total   = count($urls);
    $results = $offset === 0 ? [] : get_option('pwle_results', []);
    $batch   = array_slice($urls, $offset, 3);

    foreach ($batch as $url) {
        $results[$url] = pwle_check_url($url);
    }

    update_option('pwle_results', $results, false);

    $next = $offset + count($batch);
    $done = $next >= $total;

    if ($done) {
        update_option('pwle_last_run', time(), false);
    }

    wp_send_json_success([
        'next'  => $next,
        'total' => $total,
        'done'  => $done,
    ]);
}

add_action('wp_ajax_pwle_check_links', 'pwle_ajax_check_links');


/* ============================================================
 * The [project_link_errors] shortcode
 * ============================================================ */

function pwle_entry_links($ids) {

    $out = [];

    foreach (array_keys($ids) as $id) {

        $edit = get_edit_post_link($id);

        $out[] = '<a href="' . esc_url($edit ?: get_permalink($id)) . '">'
            . esc_html(get_the_title($id) ?: '(no title)')
            . '</a>';
    }

    return implode(', ', $out);
}

function pwle_shortcode($atts = []) {

    $atts = shortcode_atts([
        'stale_days'          => 180,
        'stale_skip_finished' => 'yes',
    ], $atts, 'project_link_errors');

    $stale_days = max(1, (int) $atts['stale_days']);

    if (!pwle_can_view()) {
        return '<p>This page is only visible to the site owner.</p>';
    }

    if (!function_exists('pw_wiki_link_map')) {
        return '<p>Please update the "Create Project Post Type Function" snippet to its latest version first.</p>';
    }

    $missing   = pwle_missing_pages();
    $uncat     = pwle_uncategorised();
    $stale     = pwle_stale_projects($stale_days, strtolower($atts['stale_skip_finished']) !== 'no');
    $unlinked  = pwle_unlinked_projects();
    $dead    = pwle_dead_related();
    $urls    = pwle_urls();
    $results = get_option('pwle_results', []);
    $last    = (int) get_option('pwle_last_run', 0);

    $broken    = [];
    $uncertain = [];
    $unchecked = 0;

    foreach ($urls as $url => $ids) {

        if (empty($results[$url])) {
            $unchecked++;
            continue;
        }

        if ($results[$url]['status'] === 'broken') {
            $broken[$url] = $results[$url];
        } elseif ($results[$url]['status'] === 'uncertain') {
            $uncertain[$url] = $results[$url];
        }
    }

    $link_row = function ($url, $result, $ids) {
        return '<tr>'
            . '<td><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a></td>'
            . '<td>' . esc_html($result['message']) . '</td>'
            . '<td>' . pwle_entry_links($ids) . '</td>'
            . '</tr>';
    };

    ob_start();
    ?>

    <style>
        .project-link-errors table { width: 100%; border-collapse: collapse; margin: .5em 0 1.5em; }
        .project-link-errors th, .project-link-errors td { text-align: left; vertical-align: top; padding: .5em .6em; border-bottom: 1px solid rgba(128,128,128,.35); word-break: break-word; }
        .project-link-errors button { font-size: 1em; padding: .6em 1.2em; cursor: pointer; }
        .project-link-errors .pwle-status { margin-left: .75em; }
    </style>

    <div class="project-link-errors">

        <h2>Missing pages</h2>

        <?php if ($missing) : ?>

            <p>These <code>[[links]]</code> do not match any Project yet.</p>

            <table>
                <thead><tr><th>Page that does not exist</th><th>Mentioned in</th></tr></thead>
                <tbody>
                <?php foreach ($missing as $item) : ?>
                    <tr>
                        <td><?php echo esc_html($item['title']); ?></td>
                        <td><?php echo pwle_entry_links($item['sources']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php else : ?>

            <p>None. Every <code>[[link]]</code> points to a page that exists.</p>

        <?php endif; ?>


        <h2>Broken links</h2>

        <p>
            <button type="button" id="pwle-check">Check links now</button>
            <span class="pwle-status" id="pwle-status">
                <?php
                if ($last) {
                    echo esc_html('Last checked ' . human_time_diff($last) . ' ago.');
                } else {
                    echo 'Not checked yet.';
                }

                if ($unchecked && $last) {
                    echo esc_html(' ' . $unchecked . ' newer link' . ($unchecked === 1 ? '' : 's') . ' not checked yet.');
                }
                ?>
            </span>
        </p>

        <?php if ($broken) : ?>

            <table>
                <thead><tr><th>Link</th><th>Problem</th><th>Found in</th></tr></thead>
                <tbody>
                <?php foreach ($broken as $url => $result) : ?>
                    <?php echo $link_row($url, $result, $urls[$url]); ?>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php elseif ($last) : ?>

            <p>No broken links found.</p>

        <?php endif; ?>

        <?php if ($uncertain) : ?>

            <h3>Could not be checked</h3>

            <p>These sites refuse automatic checks. The links may be fine, so open one to be sure.</p>

            <table>
                <thead><tr><th>Link</th><th>Result</th><th>Found in</th></tr></thead>
                <tbody>
                <?php foreach ($uncertain as $url => $result) : ?>
                    <?php echo $link_row($url, $result, $urls[$url]); ?>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>


        <h2>Related entries that no longer exist</h2>

        <?php if ($dead) : ?>

            <table>
                <thead><tr><th>Entry</th><th>Missing related entries</th></tr></thead>
                <tbody>
                <?php foreach ($dead as $project_id => $related_ids) : ?>
                    <tr>
                        <td><?php echo pwle_entry_links([$project_id => true]); ?></td>
                        <td><?php echo esc_html(count($related_ids) . ' deleted or trashed'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p>Open the entry and re-save its Related box to clear these.</p>

        <?php else : ?>

            <p>None.</p>

        <?php endif; ?>

        <h2>Uncategorised posts and journal entries</h2>

        <?php if ($uncat) : ?>

            <p>These have no category, or only the default one.</p>

            <table>
                <thead><tr><th>Entry</th><th>Type</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ($uncat as $entry) :
                    $type_obj = get_post_type_object($entry->post_type); ?>
                    <tr>
                        <td><?php echo pwle_entry_links([$entry->ID => true]); ?></td>
                        <td><?php echo esc_html($type_obj ? $type_obj->labels->singular_name : $entry->post_type); ?></td>
                        <td><?php echo esc_html(ucfirst($entry->post_status)); ?></td>
                        <td><?php echo esc_html(get_the_date('M Y', $entry)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php else : ?>

            <p>None. Every post and journal entry has a category.</p>

        <?php endif; ?>


        <h2>Stale projects</h2>

        <?php if ($stale) : ?>

            <p>Not edited for <?php echo (int) $stale_days; ?> days or more, oldest first.</p>

            <table>
                <thead><tr><th>Project</th><th>Last edited</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($stale as $item) :
                    $status_key = get_post_meta($item['post']->ID, '_project_status', true);
                    $status_all = function_exists('pj_statuses') ? pj_statuses() : [];
                    $status_txt = isset($status_all[$status_key]) ? $status_all[$status_key] : '—'; ?>
                    <tr>
                        <td><?php echo pwle_entry_links([$item['post']->ID => true]); ?></td>
                        <td><?php echo esc_html(human_time_diff($item['modified']) . ' ago'); ?></td>
                        <td><?php echo esc_html($status_txt); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php else : ?>

            <p>None. Everything has been edited in the last <?php echo (int) $stale_days; ?> days.</p>

        <?php endif; ?>


        <h2>Projects with no links</h2>

        <?php if ($unlinked) : ?>

            <p>No Related links and no <code>[[links]]</code> in or out. "In tree" shows whether the page at least has a parent or sub-projects.</p>

            <table>
                <thead><tr><th>Project</th><th>In tree</th></tr></thead>
                <tbody>
                <?php foreach ($unlinked as $item) : ?>
                    <tr>
                        <td><?php echo pwle_entry_links([$item['post']->ID => true]); ?></td>
                        <td><?php echo $item['in_tree'] ? 'Yes' : 'No'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

        <?php else : ?>

            <p>None. Every Project is linked to something.</p>

        <?php endif; ?>

    </div>

    <script>

    document.addEventListener('DOMContentLoaded', function () {

        const button = document.getElementById('pwle-check');
        const status = document.getElementById('pwle-status');

        if (!button) {
            return;
        }

        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const nonce   = <?php echo wp_json_encode(wp_create_nonce('pwle_check')); ?>;

        function run(offset) {

            const body = new FormData();
            body.append('action', 'pwle_check_links');
            body.append('nonce', nonce);
            body.append('offset', offset);

            return fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (data) {

                    if (!data.success) {
                        throw new Error('Not allowed');
                    }

                    const info = data.data;

                    status.textContent = 'Checked ' + Math.min(info.next, info.total) + ' of ' + info.total + '...';

                    if (info.done) {
                        status.textContent = 'Done. Reloading...';
                        window.location.reload();
                        return;
                    }

                    return run(info.next);
                });
        }

        button.addEventListener('click', function () {

            button.disabled = true;
            status.textContent = 'Starting...';

            run(0).catch(function () {
                status.textContent = 'Something went wrong. Please try again.';
                button.disabled = false;
            });
        });
    });

    </script>

    <?php

    return ob_get_clean();
}

add_shortcode('project_link_errors', 'pwle_shortcode');
