/* Remove Spacers (admin tool)
 *
 * Tools > Remove Spacers. Finds Spacer blocks of a chosen height, or any
 * height within a range (default 40-55px), in your posts, pages, Journal,
 * Projects, Work and Pins, lists which entries have them (and the exact
 * heights found), and lets you tick the ones to clean. Nothing is changed
 * until you press the button, and only the entries you leave ticked.
 *
 * Safe to use:
 *   - The original text of each cleaned entry is kept (a copy is stored on the
 *     entry, and WordPress also keeps its normal revision), so "Restore" can
 *     put it back.
 *   - Only Spacer blocks of the height (or within the range) you type are
 *     removed. A range compares pixel heights only.
 *   - Spacers in Site Editor templates and patterns are not touched.
 *
 * Use it, then switch the snippet off.
 */

function rsp_post_types() {

    $types = get_post_types(['public' => true], 'names');
    unset($types['attachment']);

    return array_values($types);
}

/* Matches one whole Spacer block: its comment markers and the div between. */
function rsp_pattern() {
    return '#<!-- wp:spacer(?: (\{.*?\}))? -->.*?<!-- /wp:spacer -->[ \t]*\n?#s';
}

/* Reads a block's height from its JSON; no height means the default 100px. */
function rsp_block_height($json) {

    if ($json === '' || $json === null) {
        return '100px';
    }

    $data = json_decode($json, true);

    return (is_array($data) && isset($data['height'])) ? (string) $data['height'] : '100px';
}

/* Turns what you typed into something to match against:
 *   "42px" or "42"    one exact height
 *   "40-55"           any height from 40px to 55px (inclusive)
 *   "40 to 55px"      the same
 * Returns ['range'=>bool, 'min'/'max' or 'value', 'raw' (to put back in the box), 'label'] */
function rsp_spec($value) {

    $value = strtolower(preg_replace('/\s+/', '', (string) $value));

    if ($value === '') {
        $value = '40-55';
    }

    if (preg_match('/^(\d+(?:\.\d+)?)(?:px)?(?:-|–|to)(\d+(?:\.\d+)?)(?:px)?$/u', $value, $m)) {

        $a = (float) $m[1];
        $b = (float) $m[2];

        if ($a > $b) {
            list($a, $b) = [$b, $a];
        }

        return ['range' => true, 'min' => $a, 'max' => $b, 'raw' => $a . '-' . $b, 'label' => $a . '–' . $b . 'px'];
    }

    if (ctype_digit($value)) {
        $value .= 'px';
    }

    if (preg_match('/^\d+(\.\d+)?(px|em|rem|vh|%)$/', $value)) {
        return ['range' => false, 'value' => $value, 'raw' => $value, 'label' => $value];
    }

    return rsp_spec('40-55');
}

function rsp_matches($block_height, $spec) {

    if (empty($spec['range'])) {
        return $block_height === $spec['value'];
    }

    /* A range only compares pixel heights */
    if (!preg_match('/^(\d+(?:\.\d+)?)px$/i', $block_height, $m)) {
        return false;
    }

    $h = (float) $m[1];

    return $h >= $spec['min'] && $h <= $spec['max'];
}

/* The heights of every matching Spacer in some content, e.g. ['42px', '42px', '50px'] */
function rsp_matching($content, $spec) {

    $found = [];

    if (preg_match_all(rsp_pattern(), $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {

            $height = rsp_block_height(isset($match[1]) ? $match[1] : '');

            if (rsp_matches($height, $spec)) {
                $found[] = $height;
            }
        }
    }

    return $found;
}

function rsp_count($content, $spec) {
    return count(rsp_matching($content, $spec));
}

function rsp_remove($content, $spec) {

    return preg_replace_callback(rsp_pattern(), function ($m) use ($spec) {
        return rsp_matches(rsp_block_height(isset($m[1]) ? $m[1] : ''), $spec) ? '' : $m[0];
    }, $content);
}

/* "42px ×3, 50px" */
function rsp_breakdown($heights) {

    $counts = array_count_values($heights);
    ksort($counts);
    $bits = [];

    foreach ($counts as $height => $n) {
        $bits[] = $height . ($n > 1 ? ' ×' . $n : '');
    }

    return implode(', ', $bits);
}

add_action('admin_menu', function () {
    add_management_page('Remove Spacers', 'Remove Spacers', 'manage_options', 'remove-spacers', 'rsp_render_page');
});

function rsp_render_page() {

    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;

    $spec    = rsp_spec(isset($_REQUEST['rsp_height']) ? wp_unslash($_REQUEST['rsp_height']) : '40-55');
    $height  = $spec['raw'];
    $label   = $spec['label'];
    $message = '';

    /* ---------------- actions ---------------- */

    if (isset($_POST['rsp_action']) && check_admin_referer('rsp_run', 'rsp_nonce')) {

        $ids = isset($_POST['rsp_ids']) ? array_map('absint', (array) $_POST['rsp_ids']) : [];

        if ($_POST['rsp_action'] === 'remove') {

            $cleaned = 0;
            $blocks  = 0;

            foreach ($ids as $id) {

                $post = get_post($id);

                if (!$post || !current_user_can('edit_post', $id)) {
                    continue;
                }

                $n = rsp_count($post->post_content, $spec);

                if (!$n) {
                    continue;
                }

                /* Keep the very first original, even if run again later */
                if (get_post_meta($id, '_rsp_backup', true) === '') {
                    update_post_meta($id, '_rsp_backup', wp_slash($post->post_content));
                }

                wp_update_post(wp_slash([
                    'ID'           => $id,
                    'post_content' => rsp_remove($post->post_content, $spec),
                ]));

                $cleaned++;
                $blocks += $n;
            }

            $message = '<div class="notice notice-success"><p>Removed ' . (int) $blocks . ' spacer' . ($blocks === 1 ? '' : 's')
                . ' from ' . (int) $cleaned . ' entr' . ($cleaned === 1 ? 'y' : 'ies') . '.</p></div>';
        }

        if ($_POST['rsp_action'] === 'restore') {

            $restored = 0;

            foreach ($ids as $id) {

                $backup = get_post_meta($id, '_rsp_backup', true);

                if ($backup === '' || !current_user_can('edit_post', $id)) {
                    continue;
                }

                wp_update_post(wp_slash(['ID' => $id, 'post_content' => $backup]));
                delete_post_meta($id, '_rsp_backup');
                $restored++;
            }

            $message = '<div class="notice notice-success"><p>Restored ' . (int) $restored . ' entr' . ($restored === 1 ? 'y' : 'ies') . ' to how they were.</p></div>';
        }
    }

    /* ---------------- scan ---------------- */

    $types = rsp_post_types();
    $marks = implode(',', array_fill(0, count($types), '%s'));

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_title, post_type, post_status, post_content
         FROM {$wpdb->posts}
         WHERE post_content LIKE %s
           AND post_status IN ('publish','draft','private','pending','future')
           AND post_type IN ($marks)
         ORDER BY post_type, post_title",
        array_merge(['%wp:spacer%'], $types)
    ));

    $found = [];

    foreach ($rows as $row) {

        $heights = rsp_matching($row->post_content, $spec);

        if ($heights) {
            $found[] = ['row' => $row, 'count' => count($heights), 'detail' => rsp_breakdown($heights)];
        }
    }

    $backed_up = get_posts([
        'post_type'      => $types,
        'post_status'    => ['publish', 'draft', 'private', 'pending', 'future'],
        'posts_per_page' => -1,
        'meta_key'       => '_rsp_backup',
        'no_found_rows'  => true,
    ]);

    echo '<div class="wrap"><h1>Remove Spacers</h1>' . $message;

    /* height form */
    echo '<form method="get" style="margin:1em 0"><input type="hidden" name="page" value="remove-spacers">';
    echo '<label>Spacer height to find: <input type="text" name="rsp_height" value="' . esc_attr($height) . '" size="12"></label> ';
    echo '<button class="button">Scan</button> <span class="description">One height (42px) or a range (40-55)</span></form>';

    if (!$found) {

        echo '<p>No Spacer blocks of height <strong>' . esc_html($label) . '</strong> found.</p>';

    } else {

        $total = array_sum(wp_list_pluck($found, 'count'));

        echo '<p>Found <strong>' . (int) $total . '</strong> spacer' . ($total === 1 ? '' : 's') . ' of height <strong>' . esc_html($label)
            . '</strong> in <strong>' . count($found) . '</strong> entr' . (count($found) === 1 ? 'y' : 'ies')
            . '. Untick any entry you want to leave alone.</p>';

        echo '<form method="post">';
        wp_nonce_field('rsp_run', 'rsp_nonce');
        echo '<input type="hidden" name="rsp_height" value="' . esc_attr($height) . '">';
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr>'
            . '<td class="check-column"><input type="checkbox" id="rsp-all" checked></td>'
            . '<th>Entry</th><th>Type</th><th>Status</th><th>Spacers</th></tr></thead><tbody>';

        foreach ($found as $item) {

            $row   = $item['row'];
            $edit  = get_edit_post_link($row->ID);
            $title = $row->post_title !== '' ? $row->post_title : '(no title)';

            echo '<tr><th class="check-column"><input type="checkbox" class="rsp-box" name="rsp_ids[]" value="' . (int) $row->ID . '" checked></th>'
                . '<td><a href="' . esc_url($edit) . '" target="_blank">' . esc_html($title) . '</a></td>'
                . '<td>' . esc_html($row->post_type) . '</td>'
                . '<td>' . esc_html($row->post_status) . '</td>'
                . '<td>' . (int) $item['count'] . ' <span style="color:#646970">(' . esc_html($item['detail']) . ')</span></td></tr>';
        }

        echo '</tbody></table>';
        echo '<p><button type="submit" name="rsp_action" value="remove" class="button button-primary" '
            . 'onclick="return confirm(\'Remove the spacers from the ticked entries? You can restore them afterwards.\');">'
            . 'Remove spacers from ticked entries</button></p></form>';

        echo '<script>document.getElementById("rsp-all").addEventListener("change",function(e){document.querySelectorAll(".rsp-box").forEach(function(b){b.checked=e.target.checked;});});</script>';
    }

    /* restore */
    if ($backed_up) {

        echo '<h2 style="margin-top:2em">Undo</h2>';
        echo '<p>These entries were cleaned. Tick any to put back exactly how they were before.</p>';
        echo '<form method="post">';
        wp_nonce_field('rsp_run', 'rsp_nonce');
        echo '<input type="hidden" name="rsp_height" value="' . esc_attr($height) . '">';
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';

        foreach ($backed_up as $post) {
            echo '<tr><th class="check-column"><input type="checkbox" name="rsp_ids[]" value="' . (int) $post->ID . '"></th>'
                . '<td><a href="' . esc_url(get_edit_post_link($post->ID)) . '" target="_blank">' . esc_html($post->post_title !== '' ? $post->post_title : '(no title)') . '</a></td>'
                . '<td>' . esc_html($post->post_type) . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '<p><button type="submit" name="rsp_action" value="restore" class="button">Restore ticked entries</button></p></form>';
        echo '<p class="description">Once you are happy with the result, the saved copies can stay; they are small and hidden.</p>';
    }

    echo '</div>';
}
