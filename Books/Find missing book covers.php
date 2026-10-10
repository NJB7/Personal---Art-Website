<?php

/* =========================================================
   FIND MISSING COVERS

   Adds Tools > Find Missing Covers (administrators only).

   Lists every Book with status Read or Currently Reading that has no cover, 25 at a time. For each one you
   can search Open Library and Google Books, see a few candidates, and
   click the right one. The server downloads it, shrinks it to 400px
   wide and sets it as the book's cover. Nothing is applied until you
   click a cover.

   - "Search all on this page" runs the search for every row in turn.
   - The search box on each row is editable: shorten a title, drop a
     subtitle, or add an ISBN, then click Search again.
   - "Edit book" opens the normal edit screen if you'd rather upload
     a cover yourself.
   - A book that gets a cover disappears from this list next time
     the page loads.

   This snippet is self-contained. Delete it when you are done.
   ========================================================= */

// OPTIONAL: paste a free Google Books API key between the quotes to stop
// Google limiting your searches. Leave it empty to search without one.
if (!defined('MISSING_COVERS_GOOGLE_KEY')) {
    define('MISSING_COVERS_GOOGLE_KEY', '');
}

add_action('admin_menu', function () {

    add_management_page(
        'Find Missing Covers',
        'Find Missing Covers',
        'manage_options',
        'find-missing-covers',
        'missing_covers_page'
    );
});


/* ---------- Searching ---------- */

// Which source failed and why, so the page can say so instead of showing nothing.
global $missing_covers_notes;
$missing_covers_notes = array();

function missing_covers_http_json($url) {

    global $missing_covers_notes;

    $name = (strpos($url, 'googleapis') !== false) ? 'Google Books' : 'Open Library';

    $response = wp_remote_get($url, array(
        'timeout'    => 10,
        'user-agent' => 'BookLog/1.0 (' . home_url() . ')',
    ));

    if (is_wp_error($response)) {
        $missing_covers_notes[$name] = $name . ' could not be reached (' . $response->get_error_message() . ')';
        return null;
    }

    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200) {
        $missing_covers_notes[$name] = $name . ($code === 429 ? ' is limiting requests right now' : ' returned error ' . $code);
        return null;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    return is_array($data) ? $data : null;
}


function missing_covers_open_library($title, $author, $isbn) {

    $query = array('limit' => 8, 'fields' => 'title,author_name,cover_i,first_publish_year');

    if ($isbn !== '') {
        $query['isbn'] = $isbn;
    } else {
        $query['title'] = $title;
        if ($author !== '') {
            $query['author'] = $author;
        }
    }

    $data = missing_covers_http_json('https://openlibrary.org/search.json?' . http_build_query($query));
    $out  = array();

    if ($data && !empty($data['docs'])) {
        foreach ($data['docs'] as $doc) {

            $cover_id = isset($doc['cover_i']) ? absint($doc['cover_i']) : 0;

            if (!$cover_id) {
                continue;
            }

            $out[] = array(
                'source' => 'ol',
                'ref'    => (string) $cover_id,
                'thumb'  => 'https://covers.openlibrary.org/b/id/' . $cover_id . '-M.jpg',
                'title'  => isset($doc['title']) ? (string) $doc['title'] : '',
                'author' => !empty($doc['author_name'][0]) ? (string) $doc['author_name'][0] : '',
                'year'   => isset($doc['first_publish_year']) ? (string) $doc['first_publish_year'] : '',
                'label'  => 'Open Library',
            );
        }
    }

    return $out;
}


function missing_covers_google_books($title, $author, $isbn) {

    $q = $isbn !== ''
        ? 'isbn:' . $isbn
        : 'intitle:' . $title . ($author !== '' ? ' inauthor:' . $author : '');

    $params = array('q' => $q, 'maxResults' => 6, 'printType' => 'books');

    if (MISSING_COVERS_GOOGLE_KEY !== '') {
        $params['key'] = MISSING_COVERS_GOOGLE_KEY;
    }

    $data = missing_covers_http_json('https://www.googleapis.com/books/v1/volumes?' . http_build_query($params));

    $out = array();

    if ($data && !empty($data['items'])) {
        foreach ($data['items'] as $item) {

            $volume_id = isset($item['id']) ? (string) $item['id'] : '';
            $info      = isset($item['volumeInfo']) ? $item['volumeInfo'] : array();

            if (!preg_match('/^[A-Za-z0-9_-]+$/', $volume_id) || empty($info['imageLinks'])) {
                continue;
            }

            $out[] = array(
                'source' => 'gb',
                'ref'    => $volume_id,
                'thumb'  => 'https://books.google.com/books/content?id=' . rawurlencode($volume_id) . '&printsec=frontcover&img=1&zoom=1',
                'title'  => isset($info['title']) ? (string) $info['title'] : '',
                'author' => !empty($info['authors'][0]) ? (string) $info['authors'][0] : '',
                'year'   => !empty($info['publishedDate']) ? substr((string) $info['publishedDate'], 0, 4) : '',
                'label'  => 'Google Books',
            );
        }
    }

    return $out;
}


// Lower-case, no accents/punctuation, no leading "a/an/the".
function missing_covers_norm($text) {

    $text = strtolower(remove_accents((string) $text));
    $text = preg_replace('/[^a-z0-9 ]+/', ' ', $text);
    $text = preg_replace('/^(a|an|the) /', '', trim(preg_replace('/\s+/', ' ', $text)));

    return trim($text);
}

// Is this result plausibly the book we searched for? Stops loose matches
// such as a different book that merely shares a word or two.
function missing_covers_relevant($cover, $title, $author, $isbn) {

    if ($isbn !== '') {
        return true;   // an ISBN search is already exact
    }

    $wanted = missing_covers_norm($title);
    $got    = missing_covers_norm($cover['title']);

    if ($wanted === '' || $got === '') {
        return false;
    }

    $title_ok = ($wanted === $got)
        || strpos($got, $wanted) !== false
        || strpos($wanted, $got) !== false;

    if (!$title_ok) {
        similar_text($wanted, $got, $percent);
        $title_ok = $percent >= 80;
    }

    if (!$title_ok) {
        return false;
    }

    // If both sides name an author, the surnames must agree.
    if ($author !== '' && $cover['author'] !== '') {

        $want_words = explode(' ', missing_covers_norm($author));
        $surname    = end($want_words);

        if ($surname !== '' && strpos(missing_covers_norm($cover['author']), $surname) === false) {
            return false;
        }
    }

    return true;
}


add_action('wp_ajax_missing_covers_search', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    check_ajax_referer('missing_covers', 'nonce');

    $title  = isset($_GET['title'])  ? sanitize_text_field(wp_unslash($_GET['title']))  : '';
    $author = isset($_GET['author']) ? sanitize_text_field(wp_unslash($_GET['author'])) : '';
    $isbn   = isset($_GET['isbn'])   ? strtoupper(preg_replace('/[^0-9Xx]/', '', wp_unslash($_GET['isbn']))) : '';

    if ($title === '' && $isbn === '') {
        wp_send_json_error(array('message' => 'Nothing to search for.'));
    }

    // Try the title as given, then without a subtitle, then without the author.
    $short    = trim(preg_split('/\s*[:\x{2014}]\s*/u', $title)[0]);
    $attempts = array(array($title, $author));

    if ($short !== '' && $short !== $title) {
        $attempts[] = array($short, $author);
    }

    if ($author !== '') {
        $attempts[] = array($short !== '' ? $short : $title, '');
    }

    $results = array();
    $seen    = array();

    $add = function ($found) use (&$results, &$seen, $title, $author, $isbn) {

        foreach ($found as $cover) {

            if (!missing_covers_relevant($cover, $title, $author, $isbn)) {
                continue;
            }

            $key = $cover['source'] . $cover['ref'];

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $results[]  = $cover;
            }
        }
    };

    // Open Library first (no limits to worry about).
    foreach ($attempts as $attempt) {

        $add(missing_covers_open_library($attempt[0], $attempt[1], $isbn));

        if (count($results) >= 3) {
            break;
        }
    }

    // Google Books only if that wasn't enough, and at most twice, because
    // Google limits how many searches a site can make.
    if (count($results) < 3) {

        $google_tries = 0;

        foreach ($attempts as $attempt) {

            if ($google_tries >= 2 || count($results) >= 3) {
                break;
            }

            $google_tries++;
            $add(missing_covers_google_books($attempt[0], $attempt[1], $isbn));

            if (isset($GLOBALS['missing_covers_notes']['Google Books'])) {
                break;   // it is refusing us; don't keep asking
            }
        }
    }

    global $missing_covers_notes;
    $notes = implode('. ', $missing_covers_notes);

    if (!$results) {
        wp_send_json_error(array('message' => 'No matching covers found.' . ($notes !== '' ? ' ' . $notes . '.' : '') . ' Try a shorter title or add an ISBN.'));
    }

    wp_send_json_success(array('covers' => array_slice($results, 0, 12), 'notes' => $notes));
});


/* ---------- Applying a chosen cover ---------- */

/**
 * Google returns a grey "image not available" picture (not an error) when a
 * larger size of a cover doesn't exist. This remembers what that picture
 * looks like, by asking for a book that can't exist, so it can be refused.
 */
function missing_covers_google_placeholder($zoom) {

    $key  = 'missing_covers_gb_placeholder_' . $zoom;
    $info = get_transient($key);

    if ($info === false) {

        $info = array('hash' => '', 'w' => 0, 'h' => 0);
        $tmp  = download_url('https://books.google.com/books/content?id=zzzzzzzzzzzz&printsec=frontcover&img=1&zoom=' . (int) $zoom, 20);

        if (!is_wp_error($tmp)) {
            $dims = @getimagesize($tmp);
            $info = array('hash' => md5_file($tmp), 'w' => $dims ? (int) $dims[0] : 0, 'h' => $dims ? (int) $dims[1] : 0);
            @unlink($tmp);
        }

        set_transient($key, $info, WEEK_IN_SECONDS);
    }

    return $info;
}

function missing_covers_is_google_placeholder($path, $zoom) {

    $known = missing_covers_google_placeholder($zoom);
    $dims  = @getimagesize($path);

    if ($known['hash'] !== '' && md5_file($path) === $known['hash']) {
        return true;
    }

    return $dims && $known['w'] && (int) $dims[0] === $known['w'] && (int) $dims[1] === $known['h'];
}

/**
 * A tiny fingerprint of a picture: its brightness on a 16 x 24 grid, and how
 * much of it is near-white. Used to tell a real cover from a scanned page of
 * text. Returns null if the image can't be read.
 */
function missing_covers_signature($path) {

    if (!function_exists('imagecreatefromstring')) {
        return null;
    }

    $data  = @file_get_contents($path);
    $image = $data ? @imagecreatefromstring($data) : false;

    if (!$image) {
        return null;
    }

    $tiny = imagescale($image, 16, 24);
    imagedestroy($image);

    if (!$tiny) {
        return null;
    }

    $gray  = array();
    $white = 0;

    for ($y = 0; $y < 24; $y++) {
        for ($x = 0; $x < 16; $x++) {
            $rgb  = imagecolorat($tiny, $x, $y);
            $v    = (int) round((0.299 * (($rgb >> 16) & 255)) + (0.587 * (($rgb >> 8) & 255)) + (0.114 * ($rgb & 255)));
            $gray[] = $v;

            if ($v >= 225) {
                $white++;
            }
        }
    }

    imagedestroy($tiny);

    return array('gray' => $gray, 'white' => $white / 384);
}

/**
 * Is the larger picture the same book cover as the small one? A scanned
 * page of text (mostly white), or anything that looks quite different from
 * the small cover, is refused so the small one is kept.
 */
function missing_covers_same_cover($small_path, $large_path) {

    $a = missing_covers_signature($small_path);
    $b = missing_covers_signature($large_path);

    if (!$a || !$b) {
        return false;   // can't check: stay with the small one
    }

    // A text page: mostly white, while the small cover is not.
    if ($b['white'] >= 0.75 && $a['white'] < 0.6) {
        return false;
    }

    $diff = 0;

    for ($i = 0; $i < 384; $i++) {
        $diff += abs($a['gray'][$i] - $b['gray'][$i]);
    }

    return ($diff / 384) <= 35;
}

/**
 * Does this picture look like a "no image available" placeholder: nearly
 * all white or pale grey, with no dark marks at all? (Real covers, even
 * light ones, have some dark text or artwork.)
 */
function missing_covers_looks_blank($path) {

    if (!function_exists('imagecreatefromstring')) {
        return false;
    }

    $data  = @file_get_contents($path);
    $image = $data ? @imagecreatefromstring($data) : false;

    if (!$image) {
        return false;
    }

    $small = imagescale($image, 48, 72);
    imagedestroy($image);

    if (!$small) {
        return false;
    }

    $white = 0;
    $dark  = 0;

    for ($y = 0; $y < 72; $y++) {
        for ($x = 0; $x < 48; $x++) {
            $rgb = imagecolorat($small, $x, $y);
            $v   = (0.299 * (($rgb >> 16) & 255)) + (0.587 * (($rgb >> 8) & 255)) + (0.114 * ($rgb & 255));

            if ($v >= 215) {
                $white++;
            }

            if ($v < 150) {
                $dark++;
            }
        }
    }

    imagedestroy($small);

    $total = 48 * 72;

    return ($white / $total) >= 0.6 && ($dark / $total) < 0.01;
}

/**
 * Downloads the chosen cover to a temporary file. Returns its path, or a
 * WP_Error. For Google, the same small picture shown in the preview is
 * fetched first and a larger one is used only if it is real, not the
 * "image not available" placeholder.
 */
function missing_covers_download($source, $ref) {

    if ($source === 'ol' && preg_match('/^\d+$/', $ref)) {

        $tmp = download_url('https://covers.openlibrary.org/b/id/' . $ref . '-L.jpg', 20);

        return is_wp_error($tmp) ? new WP_Error('download', 'Could not download it: ' . $tmp->get_error_message()) : $tmp;
    }

    if ($source !== 'gb' || !preg_match('/^[A-Za-z0-9_-]+$/', $ref)) {
        return new WP_Error('bad_cover', 'That cover was not recognised.');
    }

    $base  = 'https://books.google.com/books/content?id=' . rawurlencode($ref) . '&printsec=frontcover&img=1&zoom=';
    $small = download_url($base . '1', 20);

    if (is_wp_error($small)) {
        return new WP_Error('download', 'Could not download it: ' . $small->get_error_message());
    }

    if (missing_covers_is_google_placeholder($small, 1)) {
        @unlink($small);
        return new WP_Error('placeholder', 'Google has no real image for that one ("image not available"). Try another.');
    }

    $large = download_url($base . '2', 20);

    if (!is_wp_error($large)) {

        $small_dims = @getimagesize($small);
        $large_dims = @getimagesize($large);

        if ($large_dims && $small_dims && $large_dims[0] > $small_dims[0] && !missing_covers_is_google_placeholder($large, 2) && missing_covers_same_cover($small, $large)) {
            @unlink($small);
            return $large;
        }

        @unlink($large);
    }

    return $small;
}



function missing_covers_sideload($source, $ref, $book_id, $book_title) {

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = missing_covers_download($source, $ref);

    if (is_wp_error($tmp)) {
        return $tmp;
    }

    $size = @getimagesize($tmp);

    if (!$size || $size[0] < 60 || $size[1] < 60) {
        @unlink($tmp);
        return new WP_Error('empty', 'That cover turned out to be empty. Try another.');
    }

    if (missing_covers_looks_blank($tmp)) {
        @unlink($tmp);
        return new WP_Error('blank', 'That picture is a blank "image not available" placeholder. Try another.');
    }

    $ext    = ($size['mime'] === 'image/png') ? 'png' : (($size['mime'] === 'image/gif') ? 'gif' : 'jpg');
    $editor = wp_get_image_editor($tmp);

    if (!is_wp_error($editor)) {

        if ($size[0] > 400) {
            $editor->resize(400, null, false);
        }

        $editor->set_quality(80);
        $saved = $editor->save($tmp . '.jpg', 'image/jpeg');

        if (!is_wp_error($saved) && !empty($saved['path'])) {
            @unlink($tmp);
            $tmp = $saved['path'];
            $ext = 'jpg';
        }
    }

    $attachment_id = media_handle_sideload(array(
        'name'     => sanitize_file_name(sanitize_title($book_title) . '-cover-' . strtolower(wp_generate_password(6, false, false)) . '.' . $ext),
        'tmp_name' => $tmp,
    ), $book_id, $book_title);

    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        return new WP_Error('save', 'Could not save it: ' . $attachment_id->get_error_message());
    }

    return absint($attachment_id);
}


add_action('wp_ajax_missing_covers_apply', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    check_ajax_referer('missing_covers', 'nonce');

    $book_id = isset($_POST['book_id']) ? absint($_POST['book_id']) : 0;
    $source  = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : '';
    $ref     = isset($_POST['ref']) ? sanitize_text_field(wp_unslash($_POST['ref'])) : '';

    if (!$book_id || get_post_type($book_id) !== 'book') {
        wp_send_json_error(array('message' => 'That book could not be found.'));
    }

    $attachment_id = missing_covers_sideload($source, $ref, $book_id, get_the_title($book_id));

    if (is_wp_error($attachment_id)) {
        wp_send_json_error(array('message' => $attachment_id->get_error_message()));
    }

    set_post_thumbnail($book_id, $attachment_id);

    wp_send_json_success();
});


/* ---------- Removing a wrong cover ---------- */

add_action('wp_ajax_missing_covers_remove', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    check_ajax_referer('missing_covers', 'nonce');

    $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
    $book_id       = $attachment_id ? (int) wp_get_post_parent_id($attachment_id) : 0;

    if (!$book_id || get_post_type($book_id) !== 'book' || (int) get_post_thumbnail_id($book_id) !== $attachment_id) {
        wp_send_json_error(array('message' => 'That cover is no longer on a book.'));
    }

    // Deleting the image also clears it as the book's cover.
    wp_delete_attachment($attachment_id, true);

    wp_send_json_success();
});


/* ---------- Giving a cover a new address ----------
 * Images are remembered by their web address (by browsers and by image
 * networks). If a placeholder once lived at an address, the right picture
 * can keep looking like the placeholder there. This copies the picture to a
 * brand-new file name, makes it the cover, and deletes the old one.
 */

add_action('wp_ajax_missing_covers_refresh', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    check_ajax_referer('missing_covers', 'nonce');

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
    $book_id       = $attachment_id ? (int) wp_get_post_parent_id($attachment_id) : 0;

    if (!$book_id || get_post_type($book_id) !== 'book' || (int) get_post_thumbnail_id($book_id) !== $attachment_id) {
        wp_send_json_error(array('message' => 'That cover is no longer on a book.'));
    }

    $file = get_attached_file($attachment_id);

    if (!$file || !file_exists($file)) {
        wp_send_json_error(array('message' => 'The picture file is missing.'));
    }

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $tmp = wp_tempnam('cover');

    if (!$tmp || !@copy($file, $tmp)) {
        wp_send_json_error(array('message' => 'Could not copy the picture.'));
    }

    $title  = get_the_title($book_id);
    $new_id = media_handle_sideload(array(
        'name'     => sanitize_file_name(sanitize_title($title) . '-cover-' . strtolower(wp_generate_password(6, false, false)) . '.' . $ext),
        'tmp_name' => $tmp,
    ), $book_id, $title);

    if (is_wp_error($new_id)) {
        @unlink($tmp);
        wp_send_json_error(array('message' => $new_id->get_error_message()));
    }

    set_post_thumbnail($book_id, $new_id);
    wp_delete_attachment($attachment_id, true);

    wp_send_json_success();
});


/* ---------- The Tools page ---------- */

function missing_covers_page() {

    if (!current_user_can('manage_options')) {
        return;
    }

    $per_page = 25;
    $paged    = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

    $query = new WP_Query(array(
        'post_type'      => 'book',
        'post_status'    => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'orderby'        => array('date' => 'DESC', 'ID' => 'DESC'),   // most recently added first
        'meta_query'     => array(
            'relation' => 'AND',
            // No cover...
            array(
                'relation' => 'OR',
                array('key' => '_thumbnail_id', 'compare' => 'NOT EXISTS'),
                array('key' => '_thumbnail_id', 'value' => '', 'compare' => '='),
            ),
            // ...and only books you have read or are reading.
            array(
                'key'     => '_book_status',
                'value'   => array('Read', 'Currently Reading'),
                'compare' => 'IN',
            ),
        ),
    ));
    ?>

    <div class="wrap">
        <h1>Find Missing Covers</h1>

        <p>
            <strong><?php echo (int) $query->found_posts; ?></strong> book(s) with status Read or Currently Reading have no cover.
            Search, then click the right cover to use it. Nothing is changed until you click one.
        </p>

        <?php if ($query->have_posts()) : ?>

            <p><button type="button" class="button button-primary" id="mc-search-all">Search all on this page</button></p>

            <style>
                .mc-row { background:#fff; border:1px solid #ccd0d4; padding:12px 16px; margin-bottom:12px; }
                .mc-row h3 { margin:0 0 8px; }
                .mc-row input[type=text] { width:260px; max-width:100%; }
                .mc-results { display:none; grid-template-columns:repeat(auto-fill, minmax(110px, 1fr)); gap:12px; margin-top:12px; }
                .mc-option { padding:6px; border:1px solid #ccd0d4; background:#fff; cursor:pointer; text-align:left; }
                .mc-option:hover { outline:3px solid #2271b1; }
                .mc-option img { width:100%; height:auto; display:block; }
                .mc-status { margin-left:8px; }
                .mc-done { opacity:.6; }
            </style>

            <?php while ($query->have_posts()) : $query->the_post();

                $book_id   = get_the_ID();
                $author_id = (int) get_post_meta($book_id, '_book_author_id', true);
                $author    = $author_id ? get_the_title($author_id) : '';
                $title     = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', get_the_title()));
                ?>

                <div class="mc-row" data-book-id="<?php echo (int) $book_id; ?>">
                    <h3><?php echo esc_html(get_the_title()); ?><?php echo $author !== '' ? ' <small>by ' . esc_html($author) . '</small>' : ''; ?></h3>

                    <input type="text" class="mc-title" value="<?php echo esc_attr($title); ?>" aria-label="Title to search">
                    <input type="text" class="mc-author" value="<?php echo esc_attr($author); ?>" aria-label="Author to search" placeholder="Author">
                    <input type="text" class="mc-isbn" value="" aria-label="ISBN (optional)" placeholder="ISBN (optional)" style="width:140px">

                    <button type="button" class="button mc-search">Search</button>
                    <a class="button" href="<?php echo esc_url(get_edit_post_link($book_id)); ?>" target="_blank" rel="noopener">Edit book</a>
                    <span class="mc-status"></span>

                    <div class="mc-results"></div>
                </div>

            <?php endwhile; wp_reset_postdata(); ?>

            <?php
            $links = paginate_links(array(
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'current'   => $paged,
                'total'     => $query->max_num_pages,
                'prev_text' => '&laquo; Previous',
                'next_text' => 'Next &raquo;',
            ));

            if ($links) {
                echo '<div class="tablenav"><div class="tablenav-pages">' . $links . '</div></div>';
            }
            ?>

        <?php else : ?>

            <div class="notice notice-success inline"><p>Every book has a cover.</p></div>

        <?php endif; ?>

        <?php
        // Covers added through this tool (file names end in -cover), newest first,
        // so a wrong one can be removed and the book goes back on the list above.
        global $wpdb;

        $recent = $wpdb->get_results(
            "SELECT a.ID AS attachment_id, a.post_parent AS book_id
             FROM {$wpdb->posts} a
             INNER JOIN {$wpdb->posts} b ON b.ID = a.post_parent AND b.post_type = 'book'
             INNER JOIN {$wpdb->postmeta} t ON t.post_id = b.ID AND t.meta_key = '_thumbnail_id' AND t.meta_value = a.ID
             WHERE a.post_type = 'attachment' AND a.guid LIKE '%-cover.%'
             ORDER BY a.ID DESC
             LIMIT 150"
        );
        ?>

        <?php
        // Flag pictures that look like a blank "image not available" placeholder, and show them first.
        $flagged = array();
        $normal  = array();

        foreach ((array) $recent as $item) {
            $file = get_attached_file((int) $item->attachment_id);

            if ($file && file_exists($file) && missing_covers_looks_blank($file)) {
                $flagged[] = $item;
            } else {
                $normal[] = $item;
            }
        }

        $recent = array_merge($flagged, $normal);
        ?>

        <?php if ($recent) : ?>
            <h2 style="margin-top:32px">Recently added covers</h2>
            <p>Wrong one? Click <strong>Remove cover</strong>. The picture is deleted and the book goes back on the list above (after you reload).</p>

            <p>
                <button type="button" class="button" id="mc-refresh-all">Give all of these new addresses</button>
                <span class="description">Use this if a book page shows the right cover but another page still shows "image not available".</span>
            </p>

            <?php if ($flagged) : ?>
                <p>
                    <strong><?php echo count($flagged); ?></strong> of these look like a blank "image not available" picture (marked in red).
                    <button type="button" class="button button-primary" id="mc-remove-flagged">Remove those <?php echo count($flagged); ?></button>
                </p>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px;">
                <?php foreach ($recent as $item) : ?>
                    <?php $is_flagged = in_array($item, $flagged, true); ?>
                    <div class="mc-recent<?php echo $is_flagged ? ' mc-flagged' : ''; ?>" data-attachment-id="<?php echo (int) $item->attachment_id; ?>" style="background:#fff;border:<?php echo $is_flagged ? '2px solid #d63638' : '1px solid #ccd0d4'; ?>;padding:6px;">
                        <?php echo wp_get_attachment_image((int) $item->attachment_id, 'medium', false, array('style' => 'width:100%;height:auto;display:block;', 'loading' => 'lazy')); ?>
                        <small style="display:block;margin:4px 0;"><?php echo esc_html(get_the_title((int) $item->book_id)); ?></small>
                        <button type="button" class="button button-small mc-remove">Remove cover</button>
                        <button type="button" class="button button-small mc-refresh" title="Copies the picture to a new file name">New address</button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const nonce = <?php echo wp_json_encode(wp_create_nonce('missing_covers')); ?>;

        function search(row) {
            const status = row.querySelector('.mc-status');
            const box = row.querySelector('.mc-results');
            const button = row.querySelector('.mc-search');

            box.innerHTML = '';
            box.style.display = 'none';
            status.textContent = 'Searching…';
            button.disabled = true;

            const url = ajaxUrl + '?action=missing_covers_search&nonce=' + encodeURIComponent(nonce) +
                '&title=' + encodeURIComponent(row.querySelector('.mc-title').value) +
                '&author=' + encodeURIComponent(row.querySelector('.mc-author').value) +
                '&isbn=' + encodeURIComponent(row.querySelector('.mc-isbn').value);

            return fetch(url)
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data || !data.success || !data.data || !Array.isArray(data.data.covers)) {
                        status.textContent = data && data.data && data.data.message ? data.data.message : 'The search did not work.';
                        return;
                    }

                    status.textContent = 'Click the right cover.' + (data.data.notes ? ' (' + data.data.notes + ')' : '');

                    data.data.covers.forEach(function (cover) {
                        const option = document.createElement('button');
                        option.type = 'button';
                        option.className = 'mc-option';
                        option.title = cover.title + (cover.author ? ' - ' + cover.author : '');

                        const image = document.createElement('img');
                        image.src = cover.thumb;
                        image.alt = 'Cover of ' + cover.title;
                        image.loading = 'lazy';

                        const caption = document.createElement('small');
                        caption.style.display = 'block';
                        caption.textContent = (cover.year ? cover.year + ' · ' : '') + cover.label;

                        option.appendChild(image);
                        option.appendChild(caption);
                        option.addEventListener('click', function () { apply(row, cover); });

                        box.appendChild(option);
                    });

                    box.style.display = 'grid';
                })
                .catch(function () {
                    status.textContent = 'The search did not work.';
                })
                .then(function () {
                    button.disabled = false;
                });
        }

        function apply(row, cover) {
            const status = row.querySelector('.mc-status');
            const box = row.querySelector('.mc-results');

            status.textContent = 'Saving…';
            box.style.pointerEvents = 'none';

            const body = new URLSearchParams();
            body.append('action', 'missing_covers_apply');
            body.append('nonce', nonce);
            body.append('book_id', row.getAttribute('data-book-id'));
            body.append('source', cover.source);
            body.append('ref', cover.ref);

            fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        status.textContent = 'Cover saved.';
                        box.innerHTML = '';
                        box.style.display = 'none';
                        row.classList.add('mc-done');
                        return;
                    }

                    status.textContent = data && data.data && data.data.message ? data.data.message : 'Could not save that cover.';
                    box.style.pointerEvents = '';
                })
                .catch(function () {
                    status.textContent = 'Could not save that cover.';
                    box.style.pointerEvents = '';
                });
        }

        document.querySelectorAll('.mc-row').forEach(function (row) {
            row.querySelector('.mc-search').addEventListener('click', function () { search(row); });
        });

        function removeCover(card) {
            const button = card.querySelector('.mc-remove');
            button.disabled = true;
            button.textContent = 'Removing…';

            const body = new URLSearchParams();
            body.append('action', 'missing_covers_remove');
            body.append('nonce', nonce);
            body.append('attachment_id', card.getAttribute('data-attachment-id'));

            return fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        card.style.opacity = '.4';
                        card.classList.add('mc-removed');
                        button.textContent = 'Removed';
                        return;
                    }

                    button.disabled = false;
                    button.textContent = data && data.data && data.data.message ? data.data.message : 'Could not remove';
                })
                .catch(function () {
                    button.disabled = false;
                    button.textContent = 'Could not remove';
                });
        }

        document.querySelectorAll('.mc-recent').forEach(function (card) {
            card.querySelector('.mc-remove').addEventListener('click', function () { removeCover(card); });
        });

        function refreshCover(card) {
            const button = card.querySelector('.mc-refresh');
            button.disabled = true;
            button.textContent = 'Working…';

            const body = new URLSearchParams();
            body.append('action', 'missing_covers_refresh');
            body.append('nonce', nonce);
            body.append('attachment_id', card.getAttribute('data-attachment-id'));

            return fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        card.style.opacity = '.4';
                        card.classList.add('mc-removed');
                        button.textContent = 'Done';
                        return;
                    }

                    button.disabled = false;
                    button.textContent = data && data.data && data.data.message ? data.data.message : 'Could not do that';
                })
                .catch(function () {
                    button.disabled = false;
                    button.textContent = 'Could not do that';
                });
        }

        document.querySelectorAll('.mc-recent').forEach(function (card) {
            card.querySelector('.mc-refresh').addEventListener('click', function () { refreshCover(card); });
        });

        const refreshAll = document.getElementById('mc-refresh-all');

        if (refreshAll) {
            refreshAll.addEventListener('click', function () {
                const cards = Array.prototype.slice.call(document.querySelectorAll('.mc-recent:not(.mc-flagged):not(.mc-removed)'));

                if (!window.confirm('Give ' + cards.length + ' cover(s) a new file name? Nothing looks different, but each is copied and the old copy deleted.')) {
                    return;
                }

                refreshAll.disabled = true;

                cards.reduce(function (chain, card) {
                    return chain.then(function () { return refreshCover(card); });
                }, Promise.resolve()).then(function () {
                    refreshAll.textContent = 'Done. Reload the page.';
                });
            });
        }

        const removeFlagged = document.getElementById('mc-remove-flagged');

        if (removeFlagged) {
            removeFlagged.addEventListener('click', function () {
                const cards = Array.prototype.slice.call(document.querySelectorAll('.mc-flagged:not(.mc-removed)'));

                if (!window.confirm('Remove ' + cards.length + ' blank cover(s)? Those books go back on the missing list.')) {
                    return;
                }

                removeFlagged.disabled = true;

                cards.reduce(function (chain, card) {
                    return chain.then(function () { return removeCover(card); });
                }, Promise.resolve()).then(function () {
                    removeFlagged.textContent = 'Done. Reload the page.';
                });
            });
        }

        const all = document.getElementById('mc-search-all');

        if (all) {
            all.addEventListener('click', function () {
                const rows = Array.prototype.slice.call(document.querySelectorAll('.mc-row:not(.mc-done)'));
                all.disabled = true;

                rows.reduce(function (chain, row) {
                    return chain.then(function () { return search(row); });
                }, Promise.resolve()).then(function () {
                    all.disabled = false;
                });
            });
        }
    })();
    </script>

    <?php
}
