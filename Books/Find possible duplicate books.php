<?php

/* =========================================================
   FIND DUPLICATE BOOKS

   Adds Tools > Find Duplicate Books (administrators only).

   Lists groups of Books that look like the same book: the same title
   (ignoring capitals, accents, punctuation, a leading "The", a series note
   in brackets, or a subtitle after a colon) by the same author (Amos Oz and
   "Oz, Amos" count as the same).

   For each group you choose the book to KEEP, then click
   "Merge into the selected book". That:
     - moves every Read Instance (your reviews, ratings, dates) from the
       others onto the book you kept;
     - fills in anything the kept book is missing (pages, publication year,
       fiction/nonfiction, author, cover, genres, languages) from the others;
     - keeps the better Status (Read beats Currently Reading, DNF, To Read);
     - moves the others to the Trash, so nothing is lost for good.
   Optionally (ticked by default) a moved Read Instance with no review is
   trashed instead when the kept book already has a read on the same date
   with the same rating: that is the usual double-entry.

   "Not duplicates" hides a group (for books that merely share a title).
   The Trash can be emptied later from Books > Trash once you are happy.

   This snippet is self-contained. Delete it when you are done.
   ========================================================= */

add_action('admin_menu', function () {

    add_management_page(
        'Find Duplicate Books',
        'Find Duplicate Books',
        'manage_options',
        'find-duplicate-books',
        'book_dupes_page'
    );
});


/* ---------- Matching ---------- */

function book_dupes_norm($text) {

    $text = strtolower(remove_accents(html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8')));
    $text = preg_replace('/[^a-z0-9 ]+/', ' ', $text);
    $text = trim(preg_replace('/\s+/', ' ', $text));

    return $text;
}

// Title without bracketed notes, without a subtitle, without a leading A/An/The.
function book_dupes_short_title($title) {

    $title = html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8');
    $title = preg_replace('/\([^)]*\)/', ' ', $title);
    $title = preg_split('/\s*[:\x{2014}]\s*/u', $title)[0];
    $title = book_dupes_norm($title);

    return trim(preg_replace('/^(a|an|the|le|la|les|l|un|une|el|los|las|der|die|das) /', '', $title));
}

// "Amos Oz" and "Oz, Amos" give the same key: the words, sorted.
function book_dupes_author_key($name) {

    $words = array_filter(explode(' ', book_dupes_norm($name)));
    sort($words);

    return implode(' ', $words);
}

function book_dupes_status_rank($status) {

    $ranks = array('Read' => 4, 'Currently Reading' => 3, 'DNF' => 2, 'To Read' => 1);

    return isset($ranks[$status]) ? $ranks[$status] : 0;
}


/**
 * Returns the groups of likely duplicates, each an array of book rows,
 * with the suggested keeper first.
 */
function book_dupes_find() {

    global $wpdb;

    $ids = get_posts(array(
        'post_type'      => 'book',
        'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ));

    if (!$ids) {
        return array();
    }

    update_meta_cache('post', $ids);

    // Number of Read Instances per book.
    $reads  = array();
    $counts = $wpdb->get_results(
        "SELECT m.meta_value AS book_id, COUNT(*) AS n
         FROM {$wpdb->postmeta} m
         INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
         WHERE m.meta_key = '_read_instance_book_id'
           AND p.post_type = 'read_instance'
           AND p.post_status NOT IN ('trash', 'auto-draft')
         GROUP BY m.meta_value"
    );

    foreach ($counts as $row) {
        $reads[(int) $row->book_id] = (int) $row->n;
    }

    $author_names = array();
    $buckets      = array();

    foreach ($ids as $id) {

        $title     = get_the_title($id);
        $author_id = (int) get_post_meta($id, '_book_author_id', true);

        if ($author_id && !isset($author_names[$author_id])) {
            $author_names[$author_id] = get_the_title($author_id);
        }

        $author = $author_id ? $author_names[$author_id] : '';
        $short  = book_dupes_short_title($title);

        if ($short === '') {
            continue;
        }

        $key = $short . '|' . book_dupes_author_key($author);

        $buckets[$key][] = array(
            'id'        => (int) $id,
            'title'     => $title,
            'author'    => $author,
            'pages'     => (int) get_post_meta($id, '_book_pages', true),
            'year'      => (int) get_post_meta($id, '_book_publication_year', true),
            'status'    => (string) get_post_meta($id, '_book_status', true),
            'reads'     => isset($reads[(int) $id]) ? $reads[(int) $id] : 0,
            'thumb'     => (int) get_post_thumbnail_id($id),
            'exact'     => book_dupes_norm($title),
        );
    }

    $ignored = (array) get_option('book_dupes_ignored', array());
    $groups  = array();

    foreach ($buckets as $key => $books) {

        if (count($books) < 2 || in_array(md5($key), $ignored, true)) {
            continue;
        }

        // Suggested keeper: most reads, then has a cover, then most details, then oldest.
        usort($books, function ($a, $b) {

            $score = function ($x) {
                return ($x['reads'] * 100) + ($x['thumb'] ? 10 : 0) + ($x['pages'] ? 2 : 0) + ($x['year'] ? 1 : 0);
            };

            $by_score = $score($b) <=> $score($a);

            return $by_score !== 0 ? $by_score : ($a['id'] <=> $b['id']);
        });

        $exact = true;

        foreach ($books as $book) {
            if ($book['exact'] !== $books[0]['exact']) {
                $exact = false;
            }
        }

        $groups[] = array('key' => md5($key), 'exact' => $exact, 'books' => $books);
    }

    usort($groups, function ($a, $b) {
        return strcmp($a['books'][0]['exact'], $b['books'][0]['exact']);
    });

    return $groups;
}


/* ---------- Merging ---------- */

add_action('wp_ajax_book_dupes_merge', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    check_ajax_referer('book_dupes', 'nonce');

    $winner_id = isset($_POST['winner_id']) ? absint($_POST['winner_id']) : 0;
    $losers    = isset($_POST['loser_ids']) ? array_filter(array_map('absint', (array) $_POST['loser_ids'])) : array();
    $dedupe    = !empty($_POST['dedupe']);

    if (!$winner_id || get_post_type($winner_id) !== 'book' || !$losers) {
        wp_send_json_error(array('message' => 'Choose the book to keep first.'));
    }

    $moved   = 0;
    $removed = 0;

    // Reads the kept book already has, by date, so double entries can be spotted.
    $existing = array();

    $winner_reads = get_posts(array(
        'post_type'      => 'read_instance',
        'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_key'       => '_read_instance_book_id',
        'meta_value'     => $winner_id,
    ));

    foreach ($winner_reads as $read_id) {
        $date = (string) get_post_meta($read_id, '_read_instance_date_read', true);

        if ($date !== '') {
            $existing[$date][] = (string) get_post_meta($read_id, '_read_instance_rating', true);
        }
    }

    foreach ($losers as $loser_id) {

        if ($loser_id === $winner_id || get_post_type($loser_id) !== 'book') {
            continue;
        }

        // 1. Read Instances.
        $loser_reads = get_posts(array(
            'post_type'      => 'read_instance',
            'post_status'    => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => -1,
            'meta_key'       => '_read_instance_book_id',
            'meta_value'     => $loser_id,
        ));

        foreach ($loser_reads as $read) {

            $date   = (string) get_post_meta($read->ID, '_read_instance_date_read', true);
            $rating = (string) get_post_meta($read->ID, '_read_instance_rating', true);
            $review = trim($read->post_content) !== '' || trim((string) get_post_meta($read->ID, '_read_instance_review', true)) !== '';

            if ($dedupe && !$review && $date !== '' && isset($existing[$date])
                && ($rating === '' || in_array($rating, $existing[$date], true))) {
                wp_trash_post($read->ID);
                $removed++;
                continue;
            }

            update_post_meta($read->ID, '_read_instance_book_id', $winner_id);

            if ($date !== '') {
                $existing[$date][] = $rating;
            }

            $moved++;
        }

        // 2. Fill in what the kept book is missing.
        foreach (array('_book_pages', '_book_publication_year', '_book_fiction_nonfiction', '_book_author_id') as $meta_key) {

            $have = get_post_meta($winner_id, $meta_key, true);
            $lose = get_post_meta($loser_id, $meta_key, true);

            $have_nothing = ((string) $have === '' || (string) $have === '0');
            $lose_has     = ((string) $lose !== '' && (string) $lose !== '0');

            if ($have_nothing && $lose_has) {
                update_post_meta($winner_id, $meta_key, $lose);
            }
        }

        $winner_status = (string) get_post_meta($winner_id, '_book_status', true);
        $loser_status  = (string) get_post_meta($loser_id, '_book_status', true);

        if (book_dupes_status_rank($loser_status) > book_dupes_status_rank($winner_status)) {
            update_post_meta($winner_id, '_book_status', $loser_status);
        }

        // 3. Cover.
        if (!has_post_thumbnail($winner_id) && has_post_thumbnail($loser_id)) {
            $thumb_id = (int) get_post_thumbnail_id($loser_id);
            set_post_thumbnail($winner_id, $thumb_id);
            wp_update_post(array('ID' => $thumb_id, 'post_parent' => $winner_id));
        }

        // 4. Genres and languages: add the other book's, keep the kept book's.
        foreach (array('book_genre', 'book_language') as $taxonomy) {

            $term_ids = wp_get_object_terms($loser_id, $taxonomy, array('fields' => 'ids'));

            if (!is_wp_error($term_ids) && $term_ids) {
                wp_set_object_terms($winner_id, array_map('intval', $term_ids), $taxonomy, true);
            }
        }

        // 5. Trash the duplicate (recoverable) and note where it went.
        update_post_meta($loser_id, '_merged_into_book', $winner_id);
        wp_trash_post($loser_id);
    }

    wp_send_json_success(array('moved' => $moved, 'removed' => $removed));
});


add_action('wp_ajax_book_dupes_ignore', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    check_ajax_referer('book_dupes', 'nonce');

    $key = isset($_POST['key']) ? preg_replace('/[^a-f0-9]/', '', wp_unslash($_POST['key'])) : '';

    if ($key === '') {
        wp_send_json_error();
    }

    $ignored   = (array) get_option('book_dupes_ignored', array());
    $ignored[] = $key;

    update_option('book_dupes_ignored', array_values(array_unique($ignored)), false);

    wp_send_json_success();
});


/* ---------- The Tools page ---------- */

function book_dupes_page() {

    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_GET['reset_ignored']) && check_admin_referer('book_dupes_reset')) {
        delete_option('book_dupes_ignored');
    }

    $per_page = 15;
    $paged    = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $groups   = book_dupes_find();
    $total    = count($groups);
    $pages    = max(1, (int) ceil($total / $per_page));
    $show     = array_slice($groups, ($paged - 1) * $per_page, $per_page);
    $ignored  = count((array) get_option('book_dupes_ignored', array()));
    ?>

    <div class="wrap">
        <h1>Find Duplicate Books</h1>

        <p>
            <strong><?php echo (int) $total; ?></strong> group(s) of possible duplicates.
            In each group, pick the book to <strong>keep</strong> (the suggestion is the one with the most reads,
            then a cover, then the most details), then click Merge. The other books go to the Trash,
            and their reads, reviews and missing details move to the one you kept.
        </p>

        <?php if ($ignored) : ?>
            <p>
                <?php echo (int) $ignored; ?> group(s) hidden as "not duplicates".
                <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('reset_ignored', '1'), 'book_dupes_reset')); ?>">Show them again</a>
            </p>
        <?php endif; ?>

        <?php if ($show) : ?>
            <p>
                <button type="button" class="button button-primary" id="bd-merge-all">Merge all <?php echo count($show); ?> on this page</button>
                <span class="description">Uses the book currently selected in each group. Check the selections first.</span>
                <span id="bd-all-status" style="margin-left:8px;"></span>
            </p>
        <?php endif; ?>

        <style>
            .bd-group { background:#fff; border:1px solid #ccd0d4; padding:12px 16px; margin-bottom:16px; }
            .bd-group h3 { margin:0 0 4px; }
            .bd-books { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:12px; margin:12px 0; }
            .bd-book { border:1px solid #ccd0d4; padding:8px; display:flex; gap:10px; cursor:pointer; align-items:flex-start; }
            .bd-book.bd-keep { border:2px solid #2271b1; background:#f0f6fc; }
            .bd-book img { width:64px; height:auto; display:block; }
            .bd-nocover { width:64px; height:96px; background:#f0f0f1; display:flex; align-items:center; justify-content:center; font-size:11px; color:#777; text-align:center; }
            .bd-book small { display:block; color:#50575e; }
            .bd-done { opacity:.5; }
            .bd-status { margin-left:8px; }
        </style>

        <?php if (!$show) : ?>

            <div class="notice notice-success inline"><p>No duplicates found.</p></div>

        <?php endif; ?>

        <?php foreach ($show as $group) : ?>

            <div class="bd-group" data-key="<?php echo esc_attr($group['key']); ?>">
                <h3><?php echo esc_html($group['books'][0]['title']); ?></h3>
                <small><?php echo $group['exact'] ? 'Same title' : 'Similar titles (one has a subtitle or bracketed note)'; ?>, same author</small>

                <div class="bd-books">
                    <?php foreach ($group['books'] as $index => $book) : ?>
                        <label class="bd-book<?php echo $index === 0 ? ' bd-keep' : ''; ?>">
                            <input type="radio" name="keep_<?php echo esc_attr($group['key']); ?>" value="<?php echo (int) $book['id']; ?>"<?php checked($index, 0); ?>>

                            <?php if ($book['thumb']) : ?>
                                <?php echo wp_get_attachment_image($book['thumb'], 'thumbnail', false, array('loading' => 'lazy')); ?>
                            <?php else : ?>
                                <span class="bd-nocover">no cover</span>
                            <?php endif; ?>

                            <span>
                                <strong><?php echo esc_html($book['title']); ?></strong>
                                <small>by <?php echo esc_html($book['author'] !== '' ? $book['author'] : 'unknown'); ?></small>
                                <small>
                                    <?php echo (int) $book['reads']; ?> read(s)
                                    &middot; <?php echo $book['pages'] ? (int) $book['pages'] . ' pages' : 'no pages'; ?>
                                    &middot; <?php echo $book['year'] ? (int) $book['year'] : 'no year'; ?>
                                </small>
                                <small>
                                    <?php echo esc_html($book['status'] !== '' ? $book['status'] : 'no status'); ?>
                                    &middot; ID <?php echo (int) $book['id']; ?>
                                    &middot; <a href="<?php echo esc_url(get_edit_post_link($book['id'])); ?>" target="_blank" rel="noopener">Edit</a>
                                    &middot; <a href="<?php echo esc_url(get_permalink($book['id'])); ?>" target="_blank" rel="noopener">View</a>
                                </small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <p>
                    <label>
                        <input type="checkbox" class="bd-dedupe" checked>
                        Trash a moved read with no review if the kept book already has a read on the same date with the same rating
                    </label>
                </p>

                <button type="button" class="button button-primary bd-merge">Merge into the selected book</button>
                <button type="button" class="button bd-ignore">Not duplicates</button>
                <span class="bd-status"></span>
            </div>

        <?php endforeach; ?>

        <?php
        $links = paginate_links(array(
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'current'   => $paged,
            'total'     => $pages,
            'prev_text' => '&laquo; Previous',
            'next_text' => 'Next &raquo;',
        ));

        if ($links) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . $links . '</div></div>';
        }
        ?>
    </div>

    <script>
    (function () {
        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const nonce = <?php echo wp_json_encode(wp_create_nonce('book_dupes')); ?>;

        document.querySelectorAll('.bd-group').forEach(function (group) {

            const status = group.querySelector('.bd-status');

            // Highlight the book that will be kept.
            group.addEventListener('change', function () {
                group.querySelectorAll('.bd-book').forEach(function (card) {
                    card.classList.toggle('bd-keep', card.querySelector('input').checked);
                });
            });

            group.mergeNow = function (askFirst) {

                const button = group.querySelector('.bd-merge');
                const radios = Array.prototype.slice.call(group.querySelectorAll('input[type=radio]'));
                const keep = radios.filter(function (r) { return r.checked; })[0];

                if (!keep || group.classList.contains('bd-done')) { return Promise.resolve(false); }

                const losers = radios.filter(function (r) { return !r.checked; }).map(function (r) { return r.value; });

                if (askFirst && !window.confirm('Keep the selected book and move ' + losers.length + ' other book(s) to the Trash, moving their reads across?')) {
                    return Promise.resolve(false);
                }

                button.disabled = true;
                status.textContent = 'Merging…';

                const body = new URLSearchParams();
                body.append('action', 'book_dupes_merge');
                body.append('nonce', nonce);
                body.append('winner_id', keep.value);
                losers.forEach(function (id) { body.append('loser_ids[]', id); });

                if (group.querySelector('.bd-dedupe').checked) {
                    body.append('dedupe', '1');
                }

                return fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            status.textContent = 'Merged. ' + data.data.moved + ' read(s) moved' +
                                (data.data.removed ? ', ' + data.data.removed + ' double entry(ies) trashed' : '') + '.';
                            group.classList.add('bd-done');
                            group.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
                            return true;
                        }

                        button.disabled = false;
                        status.textContent = data && data.data && data.data.message ? data.data.message : 'That did not work.';
                        return false;
                    })
                    .catch(function () {
                        button.disabled = false;
                        status.textContent = 'That did not work.';
                        return false;
                    });
            };

            group.querySelector('.bd-merge').addEventListener('click', function () { group.mergeNow(true); });

            group.querySelector('.bd-ignore').addEventListener('click', function () {
                const body = new URLSearchParams();
                body.append('action', 'book_dupes_ignore');
                body.append('nonce', nonce);
                body.append('key', group.getAttribute('data-key'));

                fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            group.classList.add('bd-done');
                            status.textContent = 'Hidden.';
                            group.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
                        }
                    });
            });
        });
        const mergeAll = document.getElementById('bd-merge-all');

        if (mergeAll) {
            mergeAll.addEventListener('click', function () {
                const groups = Array.prototype.slice.call(document.querySelectorAll('.bd-group:not(.bd-done)'));

                if (!groups.length || !window.confirm('Merge ' + groups.length + ' group(s)? In each, the selected book is kept and the others go to the Trash with their reads moved across.')) {
                    return;
                }

                const allStatus = document.getElementById('bd-all-status');
                let done = 0;

                mergeAll.disabled = true;

                groups.reduce(function (chain, group) {
                    return chain.then(function () {
                        allStatus.textContent = 'Merging ' + (done + 1) + ' of ' + groups.length + '…';

                        return group.mergeNow(false).then(function (ok) {
                            if (ok) { done++; }
                        });
                    });
                }, Promise.resolve()).then(function () {
                    allStatus.textContent = 'Done: ' + done + ' of ' + groups.length + ' merged. Reload the page for the next set.';
                    mergeAll.disabled = false;
                });
            });
        }
    })();
    </script>

    <?php
}
