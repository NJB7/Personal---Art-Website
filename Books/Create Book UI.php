/* ============================================================
 * ADD READ
 *
 * Refresh / double-submit protection:
 *  1. The form is submitted with fetch(), so errors never lose
 *     what you typed, and the button is disabled while saving.
 *  2. Every form render carries a one-time token. If the same
 *     token is ever submitted twice (double click, back button,
 *     resubmit), the second submission creates nothing.
 *  3. After success the page reloads as a normal GET, so
 *     refreshing it can never resubmit the form.
 *
 * Dropdown values here must match the admin screens
 * (see "Create Book Database").
 * ============================================================ */


/* ============================================================
 * HELPERS
 * ============================================================ */

function quick_add_read_is_valid_date($date) {
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed && $parsed->format('Y-m-d') === $date;
}

function quick_add_read_post($key, $default = '') {
    return isset($_POST[$key])
        ? sanitize_text_field(wp_unslash($_POST[$key]))
        : $default;
}

function quick_add_read_split_terms($value) {
    $names = array_filter(array_map('trim', explode(',', $value)));

    return array_values(array_unique($names));
}

function quick_add_read_assign_terms($post_id, $value, $taxonomy, $label) {
    $names = quick_add_read_split_terms($value);

    if (empty($names)) {
        return;
    }

    // Terms that don't exist yet are created here, when the form is saved.
    $result = wp_set_object_terms($post_id, $names, $taxonomy, false);

    if (is_wp_error($result)) {
        throw new Exception(
            'There was a problem adding the ' . $label . ': ' .
            $result->get_error_message()
        );
    }
}

/** Finds an existing Author/Book with exactly this title. */
function quick_add_read_find_post($post_type, $title, $author_id = 0) {
    $args = [
        'post_type'      => $post_type,
        'title'          => $title,
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ];

    if ($author_id) {
        $args['meta_query'] = [[
            'key'   => '_book_author_id',
            'value' => $author_id,
        ]];
    }

    $ids = get_posts($args);

    return $ids ? (int) $ids[0] : 0;
}

/**
 * Ends the request for the form handler.
 * - fetch() submissions get JSON back.
 * - Plain submissions get a redirect to the form page.
 * A short "flash" message is stored so the page can show it once.
 */
function quick_add_read_respond($type, $message, $read_instance_id = 0) {
    $page_url = is_singular()
        ? get_permalink(get_queried_object_id())
        : home_url('/');

    $is_ajax = !empty($_POST['quick_add_read_ajax']);

    if ($type === 'success' || !$is_ajax) {
        set_transient(
            'quick_add_read_flash_' . get_current_user_id(),
            [
                'type'    => $type,
                'message' => $message,
                'id'      => absint($read_instance_id),
            ],
            5 * MINUTE_IN_SECONDS
        );
    }

    if ($is_ajax) {
        if ($type === 'success') {
            wp_send_json_success(['redirect' => $page_url]);
        }

        wp_send_json_error(['message' => $message]);
    }

    wp_safe_redirect($page_url);
    exit;
}


/* ============================================================
 * SEARCH ENDPOINTS
 * ============================================================ */

function quick_add_read_book_search() {
    if (!is_user_logged_in() || !current_user_can('edit_posts')) wp_send_json_error();
    check_ajax_referer('quick_add_read_search', 'nonce');

    $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
    if (strlen($search) < 2) wp_send_json_success([]);

    $books = get_posts([
        'post_type' => 'book',
        'post_status' => ['publish','draft','pending','private'],
        'posts_per_page' => 10,
        's' => $search,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $results = [];
    foreach ($books as $book) {
        $author_id = get_post_meta($book->ID, '_book_author_id', true);
        $author_name = '';
        if ($author_id) {
            $author_post = get_post($author_id);
            if ($author_post) $author_name = $author_post->post_title;
        }
        $results[] = ['id'=>$book->ID,'title'=>$book->post_title,'author'=>$author_name];
    }
    wp_send_json_success($results);
}
add_action('wp_ajax_quick_add_read_book_search','quick_add_read_book_search');


function quick_add_read_author_search() {
    if (!is_user_logged_in() || !current_user_can('edit_posts')) wp_send_json_error();
    check_ajax_referer('quick_add_read_search', 'nonce');

    $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';
    if (strlen($search) < 2) wp_send_json_success([]);

    $authors = get_posts([
        'post_type' => 'author',
        'post_status' => ['publish','draft','pending','private'],
        'posts_per_page' => 10,
        's' => $search,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $results = [];
    foreach ($authors as $author) {
        $results[] = ['id'=>$author->ID,'name'=>$author->post_title];
    }
    wp_send_json_success($results);
}
add_action('wp_ajax_quick_add_read_author_search','quick_add_read_author_search');


/*
 * Taxonomy search only SUGGESTS existing terms.
 * New terms are no longer created while you type; they are created
 * when the form is saved, so abandoning the form leaves nothing behind.
 */
function quick_add_read_taxonomy_search() {
    if (!is_user_logged_in() || !current_user_can('edit_posts')) {
        wp_send_json_error();
    }

    check_ajax_referer('quick_add_read_search', 'nonce');

    $taxonomy = isset($_GET['taxonomy'])
        ? sanitize_key($_GET['taxonomy'])
        : '';

    $search = isset($_GET['search'])
        ? sanitize_text_field(wp_unslash($_GET['search']))
        : '';

    // Language Read shares the book_language taxonomy.
    $allowed_taxonomies = [
        'book_genre',
        'book_language',
        'author_country',
    ];

    if (!in_array($taxonomy, $allowed_taxonomies, true)) {
        wp_send_json_error();
    }

    if (strlen($search) < 1) {
        wp_send_json_success([]);
    }

    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'search'     => $search,
        'hide_empty' => false,
        'number'     => 10,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    if (is_wp_error($terms)) {
        wp_send_json_success([]);
    }

    $results = [];

    foreach ($terms as $term) {
        $results[] = [
            'id'   => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
        ];
    }

    wp_send_json_success($results);
}
add_action('wp_ajax_quick_add_read_taxonomy_search', 'quick_add_read_taxonomy_search');


/* ============================================================
 * COVER SUGGESTIONS (Open Library + Google Books)
 *
 * "Find cover" in the form shows a few candidates. Picking one sends
 * only a source ("ol" or "gb") and an ID; the server builds the image
 * address itself, so it can only ever download from those two sites.
 * ============================================================ */

// Uses the Google Books key from the Find Missing Covers snippet, if you set one there.
function quick_add_read_google_url($params) {

    if (defined('MISSING_COVERS_GOOGLE_KEY') && MISSING_COVERS_GOOGLE_KEY !== '') {
        $params['key'] = MISSING_COVERS_GOOGLE_KEY;
    }

    return 'https://www.googleapis.com/books/v1/volumes?' . http_build_query($params);
}

function quick_add_read_http_json($url) {
    global $quick_add_read_notes;

    $name = (strpos($url, 'googleapis') !== false) ? 'Google Books' : 'Open Library';

    $response = wp_remote_get($url, [
        'timeout'    => 10,
        'user-agent' => 'BookLog/1.0 (' . home_url() . ')',
    ]);

    if (is_wp_error($response)) {
        $quick_add_read_notes[$name] = $name . ' could not be reached';
        return null;
    }

    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200) {
        $quick_add_read_notes[$name] = $name . ($code === 429 ? ' is limiting requests right now' : ' returned error ' . $code);
        return null;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    return is_array($data) ? $data : null;
}

function quick_add_read_cover_search() {
    if (!is_user_logged_in() || !current_user_can('edit_posts')) wp_send_json_error();
    check_ajax_referer('quick_add_read_search', 'nonce');

    $title  = isset($_GET['title'])  ? sanitize_text_field(wp_unslash($_GET['title']))  : '';
    $author = isset($_GET['author']) ? sanitize_text_field(wp_unslash($_GET['author'])) : '';
    $isbn   = isset($_GET['isbn'])   ? strtoupper(preg_replace('/[^0-9Xx]/', '', wp_unslash($_GET['isbn']))) : '';

    if ($title === '' && $isbn === '') {
        wp_send_json_error(['message' => 'Enter a title (or ISBN) first.']);
    }

    $results = [];
    $seen    = [];

    /* ---- Open Library ---- */

    $query = ['limit' => 8, 'fields' => 'title,author_name,cover_i,first_publish_year'];

    if ($isbn !== '') {
        $query['isbn'] = $isbn;
    } else {
        $query['title'] = $title;
        if ($author !== '') $query['author'] = $author;
    }

    $ol = quick_add_read_http_json('https://openlibrary.org/search.json?' . http_build_query($query));

    if ($ol && !empty($ol['docs'])) {
        foreach ($ol['docs'] as $doc) {
            $cover_id = isset($doc['cover_i']) ? absint($doc['cover_i']) : 0;

            if (!$cover_id || isset($seen['ol' . $cover_id])) continue;

            $seen['ol' . $cover_id] = true;

            $results[] = [
                'source' => 'ol',
                'ref'    => (string) $cover_id,
                'thumb'  => 'https://covers.openlibrary.org/b/id/' . $cover_id . '-M.jpg',
                'title'  => isset($doc['title']) ? (string) $doc['title'] : '',
                'author' => !empty($doc['author_name'][0]) ? (string) $doc['author_name'][0] : '',
                'year'   => isset($doc['first_publish_year']) ? (string) $doc['first_publish_year'] : '',
                'label'  => 'Open Library',
            ];
        }
    }

    /* ---- Google Books ---- */

    $q = $isbn !== ''
        ? 'isbn:' . $isbn
        : 'intitle:' . $title . ($author !== '' ? ' inauthor:' . $author : '');

    $gb = quick_add_read_http_json(quick_add_read_google_url([
        'q' => $q, 'maxResults' => 6, 'printType' => 'books',
    ]));

    if ($gb && !empty($gb['items'])) {
        foreach ($gb['items'] as $item) {
            $volume_id = isset($item['id']) ? (string) $item['id'] : '';
            $info      = isset($item['volumeInfo']) ? $item['volumeInfo'] : [];

            if (!preg_match('/^[A-Za-z0-9_-]+$/', $volume_id) || empty($info['imageLinks'])) continue;

            if (isset($seen['gb' . $volume_id])) continue;

            $seen['gb' . $volume_id] = true;

            $results[] = [
                'source' => 'gb',
                'ref'    => $volume_id,
                'thumb'  => 'https://books.google.com/books/content?id=' . rawurlencode($volume_id) . '&printsec=frontcover&img=1&zoom=1',
                'title'  => isset($info['title']) ? (string) $info['title'] : '',
                'author' => !empty($info['authors'][0]) ? (string) $info['authors'][0] : '',
                'year'   => !empty($info['publishedDate']) ? substr((string) $info['publishedDate'], 0, 4) : '',
                'label'  => 'Google Books',
            ];
        }
    }

    if (!$results) {
        wp_send_json_error(['message' => 'No covers found. You can upload one yourself below.']);
    }

    wp_send_json_success($results);
}
add_action('wp_ajax_quick_add_read_cover_search', 'quick_add_read_cover_search');


/* ============================================================
 * BOOK DETAILS LOOKUP (pages, year published, ISBN)
 *
 * Looks the book up on Open Library, then Google Books for anything
 * still missing. Only results whose title and author really match are
 * offered. The form fills in what it finds, and everything stays
 * editable, so nothing here is final until you save.
 * ============================================================ */

function quick_add_read_norm($text) {
    $text = strtolower(remove_accents(html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8')));

    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', $text)));
}

function quick_add_read_short_title($title) {
    $title = html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8');
    $title = preg_replace('/\([^)]*\)/', ' ', $title);
    $title = preg_split('/\s*[:\x{2014}]\s*/u', $title)[0];
    $title = quick_add_read_norm($title);

    return trim(preg_replace('/^(a|an|the|le|la|les|l|un|une|el|los|las|der|die|das) /', '', $title));
}

function quick_add_read_details_relevant($found_title, $found_author, $title, $author) {
    $wanted = quick_add_read_short_title($title);
    $got    = quick_add_read_short_title($found_title);

    if ($wanted === '' || $got === '') {
        return false;
    }

    $ok = ($wanted === $got);

    if (!$ok) {
        similar_text($wanted, $got, $percent);
        $ok = $percent >= 85;
    }

    if (!$ok) {
        return false;
    }

    if ($author !== '' && $found_author !== '') {
        $words   = explode(' ', quick_add_read_norm($author));
        $surname = end($words);

        if ($surname !== '' && strpos(quick_add_read_norm($found_author), $surname) === false) {
            return false;
        }
    }

    return true;
}

function quick_add_read_book_details() {
    global $quick_add_read_notes;
    $quick_add_read_notes = [];

    if (!is_user_logged_in() || !current_user_can('edit_posts')) wp_send_json_error();
    check_ajax_referer('quick_add_read_search', 'nonce');

    $title  = isset($_GET['title'])  ? sanitize_text_field(wp_unslash($_GET['title']))  : '';
    $author = isset($_GET['author']) ? sanitize_text_field(wp_unslash($_GET['author'])) : '';

    if ($title === '') {
        wp_send_json_error(['message' => 'Enter the book title first.']);
    }

    $sane_pages = function ($n) { $n = (int) $n; return ($n >= 20 && $n <= 3000) ? $n : 0; };
    $sane_year  = function ($n) { $n = (int) $n; return ($n >= 1000 && $n <= 2100) ? $n : 0; };

    $cands = [];

    /* ---- Open Library ---- */

    $query = ['title' => $title, 'limit' => 6, 'fields' => 'title,author_name,first_publish_year,number_of_pages_median,isbn'];

    if ($author !== '') {
        $query['author'] = $author;
    }

    $ol = quick_add_read_http_json('https://openlibrary.org/search.json?' . http_build_query($query));

    if ($ol && !empty($ol['docs'])) {
        foreach ($ol['docs'] as $doc) {

            $found_title  = isset($doc['title']) ? (string) $doc['title'] : '';
            $found_author = !empty($doc['author_name'][0]) ? (string) $doc['author_name'][0] : '';

            if (!quick_add_read_details_relevant($found_title, $found_author, $title, $author)) continue;

            $isbn = '';

            foreach ((array) ($doc['isbn'] ?? []) as $candidate) {
                if (preg_match('/^97[89]\d{10}$/', $candidate)) { $isbn = $candidate; break; }
            }

            if ($isbn === '') {
                foreach ((array) ($doc['isbn'] ?? []) as $candidate) {
                    if (preg_match('/^\d{9}[\dX]$/', $candidate)) { $isbn = $candidate; break; }
                }
            }

            $cands[] = [
                'title'  => $found_title,
                'author' => $found_author,
                'year'   => $sane_year($doc['first_publish_year'] ?? 0),
                'pages'  => $sane_pages($doc['number_of_pages_median'] ?? 0),
                'isbn'   => $isbn,
                'source' => 'Open Library',
            ];
        }
    }

    /* ---- Google Books, if something is still missing ---- */

    $need = !$cands || !$cands[0]['pages'] || !$cands[0]['year'] || !$cands[0]['isbn'];

    if ($need) {

        $gb = quick_add_read_http_json(quick_add_read_google_url([
            'q'          => 'intitle:' . $title . ($author !== '' ? ' inauthor:' . $author : ''),
            'maxResults' => 5,
            'printType'  => 'books',
        ]));

        if ($gb && !empty($gb['items'])) {
            foreach ($gb['items'] as $item) {

                $info         = isset($item['volumeInfo']) ? $item['volumeInfo'] : [];
                $found_title  = isset($info['title']) ? (string) $info['title'] : '';
                $found_author = !empty($info['authors'][0]) ? (string) $info['authors'][0] : '';

                if (!quick_add_read_details_relevant($found_title, $found_author, $title, $author)) continue;

                $isbn13 = '';
                $isbn10 = '';

                foreach ((array) ($info['industryIdentifiers'] ?? []) as $id) {
                    if (($id['type'] ?? '') === 'ISBN_13') $isbn13 = (string) $id['identifier'];
                    if (($id['type'] ?? '') === 'ISBN_10') $isbn10 = (string) $id['identifier'];
                }

                $cands[] = [
                    'title'  => $found_title,
                    'author' => $found_author,
                    'year'   => $sane_year(substr((string) ($info['publishedDate'] ?? ''), 0, 4)),
                    'pages'  => $sane_pages($info['pageCount'] ?? 0),
                    'isbn'   => $isbn13 !== '' ? $isbn13 : $isbn10,
                    'source' => 'Google Books',
                ];
            }
        }
    }

    $notes = $quick_add_read_notes ? implode('. ', $quick_add_read_notes) . '.' : '';

    if (!$cands) {
        wp_send_json_error(['message' => 'No reliable match found, so please enter the details yourself. ' . $notes]);
    }

    // The first suggestion fills any gap from the other matches.
    $best    = $cands[0];
    $sources = [$best['source'] => true];

    foreach ($cands as $other) {
        foreach (['pages', 'year', 'isbn'] as $field) {
            if (!$best[$field] && $other[$field]) {
                $best[$field] = $other[$field];
                $sources[$other['source']] = true;
            }
        }
    }

    $best['source'] = implode(' + ', array_keys($sources));
    $cands[0]       = $best;

    // Drop repeats.
    $unique = [];
    $seen   = [];

    foreach ($cands as $cand) {
        $key = $cand['year'] . '|' . $cand['pages'] . '|' . $cand['isbn'];

        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[]   = $cand;
        }
    }

    wp_send_json_success(['candidates' => array_slice($unique, 0, 6), 'notes' => $notes]);
}
add_action('wp_ajax_quick_add_read_book_details', 'quick_add_read_book_details');


/**
 * Google returns a grey "image not available" picture (not an error) when a
 * larger size of a cover doesn't exist. This remembers what that picture
 * looks like, by asking for a book that can't exist, so it can be refused.
 */
function quick_add_read_google_placeholder($zoom) {

    $key  = 'quick_add_read_gb_placeholder_' . $zoom;
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

function quick_add_read_is_google_placeholder($path, $zoom) {

    $known = quick_add_read_google_placeholder($zoom);
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
function quick_add_read_signature($path) {

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
function quick_add_read_same_cover($small_path, $large_path) {

    $a = quick_add_read_signature($small_path);
    $b = quick_add_read_signature($large_path);

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
function quick_add_read_looks_blank($path) {

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
function quick_add_read_download($source, $ref) {

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

    if (quick_add_read_is_google_placeholder($small, 1)) {
        @unlink($small);
        return new WP_Error('placeholder', 'Google has no real image for that one ("image not available"). Try another.');
    }

    $large = download_url($base . '2', 20);

    if (!is_wp_error($large)) {

        $small_dims = @getimagesize($small);
        $large_dims = @getimagesize($large);

        if ($large_dims && $small_dims && $large_dims[0] > $small_dims[0] && !quick_add_read_is_google_placeholder($large, 2) && quick_add_read_same_cover($small, $large)) {
            @unlink($small);
            return $large;
        }

        @unlink($large);
    }

    return $small;
}



/**
 * Downloads the chosen suggested cover, shrinks it to 400px wide and
 * adds it to the Media Library. Returns the attachment ID, or throws.
 */
function quick_add_read_sideload_cover($source, $ref, $book_id, $book_title) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $tmp = quick_add_read_download($source, $ref);

    if (is_wp_error($tmp)) {
        throw new Exception('The chosen cover could not be used: ' . $tmp->get_error_message());
    }

    $size = @getimagesize($tmp);

    if (!$size || $size[0] < 60 || $size[1] < 60) {
        @unlink($tmp);
        throw new Exception('The chosen cover turned out to be empty. Pick another or upload one yourself.');
    }

    if (quick_add_read_looks_blank($tmp)) {
        @unlink($tmp);
        throw new Exception('The chosen cover is a blank "image not available" picture. Pick another or upload one yourself.');
    }

    // Keep it small, like the rest of your covers (400px wide JPEG).
    $file_ext = ($size['mime'] === 'image/png') ? 'png' : (($size['mime'] === 'image/gif') ? 'gif' : 'jpg');
    $editor   = wp_get_image_editor($tmp);

    if (!is_wp_error($editor)) {
        if ($size[0] > 400) {
            $editor->resize(400, null, false);
        }

        $editor->set_quality(80);
        $saved = $editor->save($tmp . '.jpg', 'image/jpeg');

        if (!is_wp_error($saved) && !empty($saved['path'])) {
            @unlink($tmp);
            $tmp      = $saved['path'];
            $file_ext = 'jpg';
        }
    }

    $attachment_id = media_handle_sideload([
        'name'     => sanitize_file_name(sanitize_title($book_title) . '-cover-' . strtolower(wp_generate_password(6, false, false)) . '.' . $file_ext),
        'tmp_name' => $tmp,
    ], $book_id, $book_title);

    if (is_wp_error($attachment_id)) {
        @unlink($tmp);
        throw new Exception('The chosen cover could not be saved: ' . $attachment_id->get_error_message());
    }

    return absint($attachment_id);
}


/* ============================================================
 * PROCESS ADD READ FORM
 * ============================================================ */

function quick_add_read_process_form() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['quick_add_read_create'])) return;

    if (!is_user_logged_in() || !current_user_can('edit_posts')) {
        quick_add_read_respond('error', 'You do not have permission to create books or reads.');
    }

    if (
        !isset($_POST['quick_add_read_nonce']) ||
        !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['quick_add_read_nonce'])),
            'quick_add_read_create'
        )
    ) {
        quick_add_read_respond('error', 'Security check failed. Please reload the page and try again.');
    }

    /* ---------- One-time submission token ---------- */

    $token = preg_replace(
        '/[^a-zA-Z0-9\-]/',
        '',
        isset($_POST['quick_add_read_token']) ? wp_unslash($_POST['quick_add_read_token']) : ''
    );

    if ($token === '') {
        quick_add_read_respond('error', 'This form has expired. Please reload the page and try again.');
    }

    $token_key = 'quick_add_read_' . md5(get_current_user_id() . '|' . $token);
    $previous  = get_transient($token_key);

    if ($previous !== false) {
        if ($previous === 'pending') {
            quick_add_read_respond('error', 'This form is already being saved. Please wait a moment.');
        }

        // Already saved: do NOT create anything again.
        quick_add_read_respond(
            'success',
            'This read was already saved, so nothing new was added.',
            absint($previous)
        );
    }

    // Claim the token straight away so a second click is blocked.
    set_transient($token_key, 'pending', 2 * MINUTE_IN_SECONDS);

    $created_ids = []; // Everything we create, so a failure can be undone.

    try {

        /* ====================================================
         * READ AND VALIDATE INPUT (nothing is created yet)
         * ==================================================== */

        $book_id = isset($_POST['existing_book_id']) ? absint($_POST['existing_book_id']) : 0;
        $create_new_book = isset($_POST['create_new_book']) && $_POST['create_new_book'] === '1';
        $book_title = quick_add_read_post('book_title');

        $author_id = isset($_POST['existing_author_id']) ? absint($_POST['existing_author_id']) : 0;
        $create_new_author = isset($_POST['create_new_author']) && $_POST['create_new_author'] === '1';
        $author_name = quick_add_read_post('author_name');

        $author_gender = quick_add_read_post('author_gender');
        $author_bipoc = isset($_POST['author_bipoc']) ? '1' : '0';
        $author_lgbtq = isset($_POST['author_lgbtq']) ? '1' : '0';
        $author_disabled = isset($_POST['author_disabled_neurodivergent']) ? '1' : '0';
        $author_country = quick_add_read_post('author_country');

        $book_status = quick_add_read_post('book_status', 'Read');

        // "read" = log a finished read; "reading" = just add the book as Currently Reading.
        $is_reading = (quick_add_read_post('entry_type', 'read') === 'reading');
        $isbn       = strtoupper(preg_replace('/[^0-9Xx]/', '', quick_add_read_post('book_isbn')));
        $fiction_nonfiction = quick_add_read_post('fiction_nonfiction');
        $publication_year = isset($_POST['publication_year']) ? absint($_POST['publication_year']) : 0;
        $pages = isset($_POST['book_pages']) ? absint($_POST['book_pages']) : 0;
        $genres = quick_add_read_post('book_genres');
        $languages = quick_add_read_post('book_languages');

        $rating = quick_add_read_post('read_rating');
        $format = quick_add_read_post('read_format');
        $date_read = quick_add_read_post('date_read');
        $read_language = quick_add_read_post('read_language');
        $review = isset($_POST['read_review']) ? sanitize_textarea_field(wp_unslash($_POST['read_review'])) : '';

        if (!in_array($author_gender, ['', 'Male', 'Female', 'Other'], true)) $author_gender = '';
        if (!in_array($book_status, ['Read', 'Currently Reading', 'To Read', 'DNF'], true)) $book_status = 'Read';
        if ($is_reading) $book_status = 'Currently Reading';
        if (!in_array($fiction_nonfiction, ['', 'Fiction', 'Nonfiction'], true)) $fiction_nonfiction = '';
        if (!in_array($rating, ['', '1', '1.5', '2', '2.5', '3', '3.5', '4', '4.5', '5'], true)) $rating = '';
        if (!in_array($format, ['', 'Print', 'eBook', 'Audiobook'], true)) $format = '';

        if (!$is_reading && $date_read !== '' && !quick_add_read_is_valid_date($date_read)) {
            throw new Exception('Please enter a valid Date Read.');
        }

        $has_cover = !empty($_FILES['book_cover']['name']);

        // A suggested cover (only used if no file was uploaded by hand).
        $cover_source = quick_add_read_post('book_cover_source');
        $cover_ref    = quick_add_read_post('book_cover_ref');
        $use_suggested_cover = !$has_cover && $cover_source !== '' && $cover_ref !== '';

        if ($has_cover) {
            $cover_type = wp_check_filetype(sanitize_file_name($_FILES['book_cover']['name']));

            if (empty($cover_type['type']) || strpos($cover_type['type'], 'image/') !== 0) {
                throw new Exception('The cover must be an image file (JPG, PNG, GIF or WebP).');
            }
        }

        if ($book_id) {
            $existing_book = get_post($book_id);
            if (!$existing_book || $existing_book->post_type !== 'book') {
                throw new Exception('The selected book could not be found.');
            }

        } else {
            if (!$create_new_book) {
                throw new Exception('Please select an existing book or click Create New Book.');
            }

            if ($book_title === '') {
                throw new Exception('Please enter a book title.');
            }

            if ($author_id) {
                $existing_author = get_post($author_id);
                if (!$existing_author || $existing_author->post_type !== 'author') {
                    throw new Exception('The selected author could not be found.');
                }

            } elseif ($create_new_author) {
                if ($author_name === '') {
                    throw new Exception('Please enter a new author name.');
                }

                if (quick_add_read_find_post('author', $author_name)) {
                    throw new Exception(
                        'An author named "' . $author_name . '" already exists. ' .
                        'Search for them and select them instead of creating a new author.'
                    );
                }

            } else {
                throw new Exception('Please select an existing author or click Create New Author.');
            }

            if ($author_id && quick_add_read_find_post('book', $book_title, $author_id)) {
                throw new Exception(
                    'That author already has a book titled "' . $book_title . '". ' .
                    'Search for the book and select it instead of creating a new one.'
                );
            }
        }

        /* ====================================================
         * CREATE NEW BOOK (and author if needed)
         * ==================================================== */

        if (!$book_id) {

            // Blurb and author bio, only when they can be matched with certainty
            // (needs the "Book Blurbs and Author Bios" snippet). Otherwise left blank.
            $auto_text = function_exists('book_text_auto')
                ? book_text_auto($book_title, $author_name, !$author_id)
                : ['blurb' => '', 'bio' => ''];

            if (!$author_id) {
                $new_author_id = wp_insert_post([
                    'post_type'    => 'author',
                    'post_title'   => wp_slash($author_name),
                    'post_content' => wp_slash($auto_text['bio']),
                    'post_status'  => 'publish',
                ], true);

                if (is_wp_error($new_author_id)) {
                    throw new Exception('WordPress could not create the author: ' . $new_author_id->get_error_message());
                }

                if (!$new_author_id) {
                    throw new Exception('WordPress returned no Author ID.');
                }

                $author_id = absint($new_author_id);
                $created_ids[] = $author_id;

                if ($author_gender !== '') {
                    update_post_meta($author_id, '_author_gender', $author_gender);
                }

                update_post_meta($author_id, '_author_bipoc', $author_bipoc);
                update_post_meta($author_id, '_author_lgbtq', $author_lgbtq);
                update_post_meta($author_id, '_author_disabled_neurodivergent', $author_disabled);

                quick_add_read_assign_terms($author_id, $author_country, 'author_country', 'author country');
            }

            $new_book_id = wp_insert_post([
                'post_type'    => 'book',
                'post_title'   => wp_slash($book_title),
                'post_content' => wp_slash($auto_text['blurb']),
                'post_status'  => 'publish',
            ], true);

            if (is_wp_error($new_book_id)) {
                throw new Exception('WordPress could not create the book: ' . $new_book_id->get_error_message());
            }

            if (!$new_book_id) {
                throw new Exception('WordPress returned no Book ID.');
            }

            $book_id = absint($new_book_id);
            $created_ids[] = $book_id;

            update_post_meta($book_id, '_book_author_id', $author_id);
            update_post_meta($book_id, '_book_status', $book_status);

            if ($fiction_nonfiction !== '') {
                update_post_meta($book_id, '_book_fiction_nonfiction', $fiction_nonfiction);
            }

            if ($publication_year > 0) {
                update_post_meta($book_id, '_book_publication_year', $publication_year);
            }

            if ($pages > 0) {
                update_post_meta($book_id, '_book_pages', $pages);
            }

            if (in_array(strlen($isbn), [10, 13], true)) {
                update_post_meta($book_id, '_book_isbn', $isbn);
            }

            // Genre and Original Language only apply to a newly created book,
            // so logging a read never overwrites an existing book's terms.
            quick_add_read_assign_terms($book_id, $genres, 'book_genre', 'genre');
            quick_add_read_assign_terms($book_id, $languages, 'book_language', 'language');

            if ($has_cover) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                // The picture is named after the book in the Media Library.
                $attachment_id = media_handle_upload('book_cover', $book_id, array(
                    'post_title' => $book_title,
                ));

                if (is_wp_error($attachment_id)) {
                    throw new Exception(
                        'There was a problem uploading the cover image: ' .
                        $attachment_id->get_error_message()
                    );
                }

                $created_ids[] = absint($attachment_id);
                update_post_meta($attachment_id, '_wp_attachment_image_alt', 'Cover of ' . $book_title);
                set_post_thumbnail($book_id, $attachment_id);
            }

            if ($use_suggested_cover) {
                $attachment_id = quick_add_read_sideload_cover($cover_source, $cover_ref, $book_id, $book_title);

                $created_ids[] = $attachment_id;
                update_post_meta($attachment_id, '_wp_attachment_image_alt', 'Cover of ' . $book_title);
                set_post_thumbnail($book_id, $attachment_id);
            }

        } else {
            // Logging a read of an existing book: it is no longer unread.
            $current_status = get_post_meta($book_id, '_book_status', true);

            if ($is_reading) {
                update_post_meta($book_id, '_book_status', 'Currently Reading');
            } elseif (in_array($current_status, ['', 'To Read', 'Currently Reading'], true)) {
                update_post_meta($book_id, '_book_status', 'Read');
            }
        }

        /* ====================================================
         * CREATE READ INSTANCE
         * The review is stored as the post content.
         * ==================================================== */

        $read_instance_id = 0;

        if (!$is_reading) {

        // Title is "Read" plus the date, e.g. "Read Oct 02, 2026".
        $book_title_for_read = 'Read';

        if ($date_read !== '') {
            $read_date_object = DateTime::createFromFormat('!Y-m-d', $date_read, wp_timezone());

            if ($read_date_object) {
                $book_title_for_read = 'Read ' . wp_date('M d, Y', $read_date_object->getTimestamp(), wp_timezone());
            }
        }

        $read_instance_id = wp_insert_post([
            'post_type'    => 'read_instance',
            'post_title'   => wp_slash($book_title_for_read),
            'post_content' => wp_slash($review),
            'post_status'  => 'publish',
        ], true);

        if (is_wp_error($read_instance_id)) {
            throw new Exception(
                'WordPress could not create the Read Instance: ' .
                $read_instance_id->get_error_message()
            );
        }

        if (!$read_instance_id) {
            throw new Exception('WordPress returned no Read Instance ID.');
        }

        $read_instance_id = absint($read_instance_id);
        $created_ids[] = $read_instance_id;

        update_post_meta($read_instance_id, '_read_instance_book_id', $book_id);

        if ($date_read !== '') {
            update_post_meta($read_instance_id, '_read_instance_date_read', $date_read);
        }

        if ($rating !== '') {
            update_post_meta($read_instance_id, '_read_instance_rating', $rating);
        }

        if ($format !== '') {
            update_post_meta($read_instance_id, '_read_instance_format', $format);
        }

        quick_add_read_assign_terms($read_instance_id, $read_language, 'book_language', 'language read');

        }   // end of: if (!$is_reading)

    } catch (Throwable $e) {

        // Undo anything created before the failure, newest first.
        foreach (array_reverse($created_ids) as $created_id) {
            wp_delete_post($created_id, true);
        }

        // Let the same form be submitted again after fixing the problem.
        delete_transient($token_key);

        quick_add_read_respond('error', $e->getMessage());
    }

    // Remember this token for a day: resubmitting it creates nothing.
    $result_id = $is_reading ? $book_id : $read_instance_id;

    set_transient($token_key, $result_id, DAY_IN_SECONDS);

    quick_add_read_respond(
        'success',
        $is_reading ? 'Added to Currently Reading!' : 'Read Instance created!',
        $result_id
    );
}
add_action('template_redirect','quick_add_read_process_form');


/* ============================================================
 * SHORTCODE: [add_read]
 * ============================================================ */

function quick_add_read_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to use the Add Read form.</p>';
    }

    if (!current_user_can('edit_posts')) {
        return '<p>You do not have permission to use the Add Read form.</p>';
    }

    // Show the saved message once, then forget it (so a refresh is clean).
    $message = '';
    $flash_key = 'quick_add_read_flash_' . get_current_user_id();
    $flash = get_transient($flash_key);

    if ($flash) {
        delete_transient($flash_key);
    }

    if (is_array($flash) && !empty($flash['message'])) {

        if ($flash['type'] === 'success') {
            $edit_link = !empty($flash['id'])
                ? get_edit_post_link(absint($flash['id']), '')
                : '';

            $message = '<div class="quick-read-success"><strong>' . esc_html($flash['message']) . '</strong>' .
                ($edit_link ? '<br><br><a href="' . esc_url($edit_link) . '">' . (get_post_type(absint($flash['id'])) === 'book' ? 'Edit the Book' : 'Edit the Read Instance') . '</a>' : '') .
                '</div>';

        } else {
            $message = '<div class="quick-read-error"><strong>There was a problem creating the Read:</strong><br>' .
                esc_html($flash['message']) .
                '</div>';
        }
    }

    ob_start();
    ?>

    <div class="quick-add-read">
        <?php echo $message; ?>

        <div id="quick-read-ajax-message"></div>

        <form method="post"
              id="quick-add-read-form"
              enctype="multipart/form-data"
              action="<?php echo esc_url(get_permalink()); ?>"
              onkeydown="if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA' && event.target.tagName !== 'BUTTON') { event.preventDefault(); }">

            <?php wp_nonce_field('quick_add_read_create','quick_add_read_nonce'); ?>

            <input type="hidden" name="quick_add_read_create" value="1">
            <input type="hidden" name="quick_add_read_token" value="<?php echo esc_attr(wp_generate_uuid4()); ?>">

            <p><strong>What are you adding?</strong></p>
            <p>
                <label><input type="radio" name="entry_type" value="read" checked> I finished it (add a read)</label><br>
                <label><input type="radio" name="entry_type" value="reading"> I'm reading it now (just the book and author)</label>
            </p>

            <h2>Book</h2>

            <p><label for="quick-read-book-search"><strong>Search for a book</strong></label></p>
            <p><input type="text" id="quick-read-book-search" placeholder="Start typing a book title..." autocomplete="off"></p>

            <div id="quick-read-book-suggestions" class="quick-read-suggestions"></div>
            <div id="quick-read-selected-book" class="quick-read-selected" style="display:none;"></div>

            <input type="hidden" id="quick-read-existing-book-id" name="existing_book_id" value="">

            <p>
                <button type="button"
                        id="quick-read-create-new-book-button"
                        class="quick-read-secondary-button"
                        onclick="
                            document.getElementById('quick-read-existing-book-id').value = '';
                            document.getElementById('quick-read-selected-book').style.display = 'none';
                            document.getElementById('quick-read-book-suggestions').style.display = 'none';
                            document.getElementById('quick-read-new-book').style.display = 'block';
                            document.getElementById('quick-read-create-new-book').value = '1';
                            this.style.display = 'none';
                            var typedTitle = document.getElementById('quick-read-book-search').value.trim();
                            var titleBox = document.getElementById('quick-read-book-title');
                            if (typedTitle !== '' && titleBox.value.trim() === '') {
                                titleBox.value = typedTitle;
                                document.getElementById('quick-read-book-search').value = '';
                            }
                            titleBox.focus();
                        ">
                    + Create New Book
                </button>
            </p>

            <div id="quick-read-new-book" style="display:none;">

                <input type="hidden" name="create_new_book" id="quick-read-create-new-book" value="0">

                <p><label for="quick-read-book-title"><strong>Book Title</strong></label></p>
                <p><input type="text" id="quick-read-book-title" name="book_title"></p>

                <p><label for="quick-read-author-search"><strong>Author</strong></label></p>
                <p><input type="text" id="quick-read-author-search" placeholder="Start typing an author..." autocomplete="off"></p>

                <div id="quick-read-author-suggestions" class="quick-read-suggestions"></div>
                <div id="quick-read-selected-author" class="quick-read-selected" style="display:none;"></div>

                <input type="hidden" id="quick-read-existing-author-id" name="existing_author_id" value="">

                <p>
                    <button type="button"
                            id="quick-read-new-author-button"
                            class="quick-read-secondary-button"
                            onclick="
                                document.getElementById('quick-read-existing-author-id').value = '';
                                document.getElementById('quick-read-selected-author').style.display = 'none';
                                document.getElementById('quick-read-author-suggestions').style.display = 'none';
                                document.getElementById('quick-read-new-author').style.display = 'block';
                                document.getElementById('quick-read-create-new-author').value = '1';
                                this.style.display = 'none';
                                var typedAuthor = document.getElementById('quick-read-author-search').value.trim();
                                var authorBox = document.getElementById('quick-read-author-name');
                                if (typedAuthor !== '' && authorBox.value.trim() === '') {
                                    authorBox.value = typedAuthor;
                                    document.getElementById('quick-read-author-search').value = '';
                                }
                                authorBox.focus();
                            ">
                        + Create New Author
                    </button>
                </p>

                <div id="quick-read-new-author" style="display:none;">

                    <input type="hidden" name="create_new_author" id="quick-read-create-new-author" value="0">

                    <p><label for="quick-read-author-name"><strong>New Author Name</strong></label></p>
                    <p><input type="text" id="quick-read-author-name" name="author_name"></p>

                    <p><strong>Country</strong></p>
                    <div class="quick-read-taxonomy-box">
                        <div id="quick-read-selected-author-countries" class="quick-read-selected-tags"></div>
                        <input type="text"
                               id="quick-read-author-country-input"
                               placeholder="Start typing a country..."
                               autocomplete="off">
                        <div id="quick-read-author-country-suggestions" class="quick-read-suggestions"></div>
                    </div>
                    <input type="hidden" id="quick-read-author-country-hidden" name="author_country" value="">

                    <p><label for="quick-read-author-gender"><strong>Gender</strong></label></p>
                    <p>
                        <select id="quick-read-author-gender" name="author_gender">
                            <option value="">—</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </p>

                    <p><strong>Identity</strong></p>
                    <p>
                        <label>
                            <input type="checkbox" name="author_bipoc" value="1">
                            BIPOC
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="author_lgbtq" value="1">
                            LGBTQ+
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="author_disabled_neurodivergent" value="1">
                            Disabled / Neurodivergent
                        </label>
                    </p>

                </div>

                <div id="quick-read-status-wrap">
                <p><label for="quick-read-book-status"><strong>Book Status</strong></label></p>
                <p>
                    <select id="quick-read-book-status" name="book_status">
                        <option value="Read" selected>Read</option>
                        <option value="To Read">To Read</option>
                        <option value="Currently Reading">Currently Reading</option>
                        <option value="DNF">DNF</option>
                    </select>
                </p>
                </div>

                <p><label for="quick-read-fiction"><strong>Fiction / Nonfiction</strong></label></p>
                <p>
                    <select id="quick-read-fiction" name="fiction_nonfiction">
                        <option value="">—</option>
                        <option value="Fiction">Fiction</option>
                        <option value="Nonfiction">Nonfiction</option>
                    </select>
                </p>

                <p><label for="quick-read-publication-year"><strong>Publication Year</strong></label></p>
                <p>
                    <input type="number"
                           id="quick-read-publication-year"
                           name="publication_year"
                           min="0"
                           max="2100">
                </p>

                <p><label for="quick-read-pages"><strong>Pages</strong></label></p>
                <p>
                    <input type="number"
                           id="quick-read-pages"
                           name="book_pages"
                           min="0">
                </p>

                <p><label for="quick-read-isbn"><strong>ISBN</strong></label></p>
                <p><input type="text" id="quick-read-isbn" name="book_isbn" autocomplete="off"></p>

                <p>
                    <button type="button" id="quick-read-lookup-details" class="quick-read-secondary-button">
                        Look up pages, year and ISBN
                    </button>
                </p>
                <div id="quick-read-details-status"></div>
                <div id="quick-read-details-results"></div>

                <p><strong>Genre</strong></p>
                <div class="quick-read-taxonomy-box">
                    <div id="quick-read-selected-genres" class="quick-read-selected-tags"></div>
                    <input type="text"
                           id="quick-read-genre-input"
                           placeholder="Start typing a genre..."
                           autocomplete="off">
                    <div id="quick-read-genre-suggestions" class="quick-read-suggestions"></div>
                </div>
                <input type="hidden" id="quick-read-genres-hidden" name="book_genres" value="">

                <p><strong>Original Language</strong></p>
                <div class="quick-read-taxonomy-box">
                    <div id="quick-read-selected-languages" class="quick-read-selected-tags"></div>
                    <input type="text"
                           id="quick-read-language-input"
                           placeholder="Start typing a language..."
                           autocomplete="off">
                    <div id="quick-read-language-suggestions" class="quick-read-suggestions"></div>
                </div>
                <input type="hidden" id="quick-read-languages-hidden" name="book_languages" value="">

                <p><strong>Cover Image</strong></p>
                <p>
                    <button type="button" id="quick-read-find-cover" class="quick-read-secondary-button">
                        Find cover
                    </button>
                </p>

                <div id="quick-read-cover-status"></div>
                <div id="quick-read-cover-results"
                     style="display:none;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:12px;margin-bottom:12px;"></div>

                <input type="hidden" id="quick-read-cover-source" name="book_cover_source" value="">
                <input type="hidden" id="quick-read-cover-ref" name="book_cover_ref" value="">

                <p><label for="quick-read-cover">Or upload one yourself (this replaces any cover chosen above)</label></p>
                <p>
                    <input type="file"
                           id="quick-read-cover"
                           name="book_cover"
                           accept="image/*">
                </p>

            </div>

            <div id="quick-read-read-section">

            <h2>Read Instance</h2>

            <p><label for="quick-read-date"><strong>Date Read</strong></label></p>
            <p><input type="date" id="quick-read-date" name="date_read"></p>

            <p><label for="quick-read-rating"><strong>Rating</strong></label></p>
            <p>
                <select id="quick-read-rating" name="read_rating">
                    <option value="">—</option>
                    <option value="1">1</option>
                    <option value="1.5">1.5</option>
                    <option value="2">2</option>
                    <option value="2.5">2.5</option>
                    <option value="3">3</option>
                    <option value="3.5">3.5</option>
                    <option value="4">4</option>
                    <option value="4.5">4.5</option>
                    <option value="5">5</option>
                </select>
            </p>

            <p><label for="quick-read-format"><strong>Format</strong></label></p>
            <p>
                <select id="quick-read-format" name="read_format">
                    <option value="">—</option>
                    <option value="Print">Print</option>
                    <option value="eBook">eBook</option>
                    <option value="Audiobook">Audiobook</option>
                </select>
            </p>

            <p><strong>Language Read</strong></p>
            <div class="quick-read-taxonomy-box">
                <div id="quick-read-selected-read-languages" class="quick-read-selected-tags"></div>
                <input type="text"
                       id="quick-read-read-language-input"
                       placeholder="Start typing a language..."
                       autocomplete="off">
                <div id="quick-read-read-language-suggestions" class="quick-read-suggestions"></div>
            </div>
            <input type="hidden"
                   id="quick-read-read-languages-hidden"
                   name="read_language"
                   value="">

            <p><label for="quick-read-review"><strong>Review</strong></label></p>
            <p>
                <textarea id="quick-read-review"
                          name="read_review"
                          rows="6"></textarea>
            </p>

            </div>

            <p>
                <button type="submit" class="quick-read-button">
                    Add Read
                </button>
            </p>

        </form>
    </div>

    <script>
    (function () {
        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const nonce = <?php echo wp_json_encode(wp_create_nonce('quick_add_read_search')); ?>;

        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = value;
            return div.innerHTML;
        }

        function debounce(fn, delay) {
            let timer;

            return function () {
                const args = arguments;
                clearTimeout(timer);
                timer = setTimeout(function () { fn.apply(null, args); }, delay);
            };
        }

        function hideBox(box) {
            box.innerHTML = '';
            box.style.display = 'none';
        }

        /*
         * Live search with a short delay. If a newer keystroke arrives,
         * older responses are ignored so results never show out of order.
         */
        function createSearcher(input, minLength, buildUrl, onResults, onClear) {
            let counter = 0;

            const run = debounce(function () {
                const search = input.value.trim();
                const requestId = counter;

                fetch(buildUrl(search))
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (requestId !== counter) return;

                        onResults(
                            search,
                            data && data.success && Array.isArray(data.data) ? data.data : []
                        );
                    })
                    .catch(function () {
                        if (requestId !== counter) return;

                        onResults(search, []);
                    });
            }, 250);

            input.addEventListener('input', function () {
                counter++;

                if (input.value.trim().length < minLength) {
                    onClear();
                    return;
                }

                run();
            });
        }

        /* ---------- Book search ---------- */

        const bookSearch = document.getElementById('quick-read-book-search');
        const bookSuggestions = document.getElementById('quick-read-book-suggestions');
        const selectedBook = document.getElementById('quick-read-selected-book');
        const existingBookId = document.getElementById('quick-read-existing-book-id');
        const newBookButton = document.getElementById('quick-read-create-new-book-button');
        const newBook = document.getElementById('quick-read-new-book');
        const createNewBook = document.getElementById('quick-read-create-new-book');

        function selectBook(book) {
            existingBookId.value = book.id;
            selectedBook.innerHTML =
                '<strong>' + escapeHtml(book.title) + '</strong>' +
                (book.author ? '<br><span>by ' + escapeHtml(book.author) + '</span>' : '') +
                ' <button type="button" id="quick-read-remove-book">×</button>';

            selectedBook.style.display = 'block';
            bookSearch.value = '';
            hideBox(bookSuggestions);
            newBook.style.display = 'none';
            createNewBook.value = '0';
            newBookButton.style.display = 'block';

            const removeButton = document.getElementById('quick-read-remove-book');

            if (removeButton) {
                removeButton.addEventListener('click', function () {
                    existingBookId.value = '';
                    selectedBook.innerHTML = '';
                    selectedBook.style.display = 'none';
                    newBookButton.style.display = 'block';
                });
            }
        }

        if (bookSearch) {
            createSearcher(
                bookSearch,
                2,
                function (search) {
                    return ajaxUrl +
                        '?action=quick_add_read_book_search' +
                        '&nonce=' + encodeURIComponent(nonce) +
                        '&search=' + encodeURIComponent(search);
                },
                function (search, items) {
                    bookSuggestions.innerHTML = '';

                    if (!items.length) {
                        bookSuggestions.style.display = 'none';
                        return;
                    }

                    items.forEach(function (book) {
                        const item = document.createElement('div');
                        item.className = 'quick-read-suggestion';

                        item.innerHTML =
                            '<strong>' + escapeHtml(book.title) + '</strong>' +
                            (book.author
                                ? '<br><small>by ' + escapeHtml(book.author) + '</small>'
                                : '');

                        item.addEventListener('click', function () {
                            selectBook(book);
                        });

                        bookSuggestions.appendChild(item);
                    });

                    bookSuggestions.style.display = 'block';
                },
                function () { hideBox(bookSuggestions); }
            );
        }

        /* ---------- Author search ---------- */

        const authorSearch = document.getElementById('quick-read-author-search');
        const authorSuggestions = document.getElementById('quick-read-author-suggestions');
        const selectedAuthor = document.getElementById('quick-read-selected-author');
        const existingAuthorId = document.getElementById('quick-read-existing-author-id');
        const newAuthorButton = document.getElementById('quick-read-new-author-button');
        const newAuthor = document.getElementById('quick-read-new-author');
        const createNewAuthor = document.getElementById('quick-read-create-new-author');

        function selectAuthor(author) {
            existingAuthorId.value = author.id;
            selectedAuthor.innerHTML =
                '<strong>' + escapeHtml(author.name) + '</strong>' +
                ' <button type="button" id="quick-read-remove-author">×</button>';

            selectedAuthor.style.display = 'block';
            authorSearch.value = '';
            hideBox(authorSuggestions);
            newAuthor.style.display = 'none';
            createNewAuthor.value = '0';
            newAuthorButton.style.display = 'block';

            const removeButton = document.getElementById('quick-read-remove-author');

            if (removeButton) {
                removeButton.addEventListener('click', function () {
                    existingAuthorId.value = '';
                    selectedAuthor.innerHTML = '';
                    selectedAuthor.style.display = 'none';
                    newAuthorButton.style.display = 'block';
                });
            }
        }

        if (authorSearch) {
            createSearcher(
                authorSearch,
                2,
                function (search) {
                    return ajaxUrl +
                        '?action=quick_add_read_author_search' +
                        '&nonce=' + encodeURIComponent(nonce) +
                        '&search=' + encodeURIComponent(search);
                },
                function (search, items) {
                    authorSuggestions.innerHTML = '';

                    if (!items.length) {
                        authorSuggestions.style.display = 'none';
                        return;
                    }

                    items.forEach(function (author) {
                        const item = document.createElement('div');
                        item.className = 'quick-read-suggestion';
                        item.textContent = author.name;

                        item.addEventListener('click', function () {
                            selectAuthor(author);
                        });

                        authorSuggestions.appendChild(item);
                    });

                    authorSuggestions.style.display = 'block';
                },
                function () { hideBox(authorSuggestions); }
            );
        }

        /* ---------- Genre / language / country pickers ----------
         * Picking a suggestion selects an existing term.
         * "+ Add" selects a brand-new term; it is only created
         * in WordPress when the form is saved.
         */

        function setupTaxonomyPicker(inputId, suggestionsId, selectedId, hiddenId, taxonomy) {
            const input = document.getElementById(inputId);
            const suggestions = document.getElementById(suggestionsId);
            const selectedContainer = document.getElementById(selectedId);
            const hidden = document.getElementById(hiddenId);

            if (!input || !suggestions || !selectedContainer || !hidden) return;

            const selected = [];
            let lastItems = [];

            function updateHidden() {
                hidden.value = selected.join(', ');
            }

            function render() {
                selectedContainer.innerHTML = '';

                selected.forEach(function (term, index) {
                    const element = document.createElement('span');
                    element.className = 'quick-read-tag';

                    const text = document.createElement('span');
                    text.textContent = term;

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.textContent = '×';
                    remove.className = 'quick-read-tag-remove';

                    remove.addEventListener('click', function () {
                        selected.splice(index, 1);
                        render();
                        updateHidden();
                    });

                    element.appendChild(text);
                    element.appendChild(remove);
                    selectedContainer.appendChild(element);
                });
            }

            function addTerm(term) {
                // Commas separate terms in the saved value, so never keep one.
                term = term.replace(/,/g, ' ').replace(/\s+/g, ' ').trim();

                if (!term) return;

                const exists = selected.some(function (existing) {
                    return existing.toLowerCase() === term.toLowerCase();
                });

                if (!exists) {
                    selected.push(term);
                    render();
                    updateHidden();
                }

                input.value = '';
                lastItems = [];
                hideBox(suggestions);
            }

            function findExact(search) {
                return lastItems.find(function (term) {
                    return term.name.toLowerCase() === search.toLowerCase();
                });
            }

            createSearcher(
                input,
                1,
                function (search) {
                    return ajaxUrl +
                        '?action=quick_add_read_taxonomy_search' +
                        '&nonce=' + encodeURIComponent(nonce) +
                        '&taxonomy=' + encodeURIComponent(taxonomy) +
                        '&search=' + encodeURIComponent(search);
                },
                function (search, items) {
                    lastItems = items;
                    suggestions.innerHTML = '';

                    items.forEach(function (term) {
                        const item = document.createElement('div');
                        item.className = 'quick-read-suggestion';
                        item.textContent = term.name;

                        item.addEventListener('click', function () {
                            addTerm(term.name);
                        });

                        suggestions.appendChild(item);
                    });

                    if (!findExact(search)) {
                        const addItem = document.createElement('div');
                        addItem.className = 'quick-read-suggestion quick-read-create-term';
                        addItem.innerHTML = '<strong>+ Add "' + escapeHtml(search) + '"</strong>';

                        addItem.addEventListener('click', function () {
                            addTerm(search);
                        });

                        suggestions.appendChild(addItem);
                    }

                    suggestions.style.display = 'block';
                },
                function () {
                    lastItems = [];
                    hideBox(suggestions);
                }
            );

            // Results from an older keystroke must never be used by Enter.
            input.addEventListener('input', function () {
                lastItems = [];
            });

            input.addEventListener('keydown', function (event) {
                const typed = input.value.trim();

                if (event.key === 'Enter') {
                    event.preventDefault();

                    if (!typed) return;

                    const exact = findExact(typed);

                    if (exact) {
                        addTerm(exact.name);
                    } else if (lastItems.length) {
                        addTerm(lastItems[0].name);
                    } else {
                        addTerm(typed);
                    }
                }

                if (event.key === ',') {
                    event.preventDefault();

                    if (!typed) return;

                    const exact = findExact(typed);
                    addTerm(exact ? exact.name : typed);
                }
            });
        }

        setupTaxonomyPicker(
            'quick-read-genre-input',
            'quick-read-genre-suggestions',
            'quick-read-selected-genres',
            'quick-read-genres-hidden',
            'book_genre'
        );

        setupTaxonomyPicker(
            'quick-read-language-input',
            'quick-read-language-suggestions',
            'quick-read-selected-languages',
            'quick-read-languages-hidden',
            'book_language'
        );

        setupTaxonomyPicker(
            'quick-read-author-country-input',
            'quick-read-author-country-suggestions',
            'quick-read-selected-author-countries',
            'quick-read-author-country-hidden',
            'author_country'
        );

        // Language Read shares the same Language taxonomy as Original Language.
        setupTaxonomyPicker(
            'quick-read-read-language-input',
            'quick-read-read-language-suggestions',
            'quick-read-selected-read-languages',
            'quick-read-read-languages-hidden',
            'book_language'
        );

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.quick-read-taxonomy-box')) {
                document.querySelectorAll(
                    '.quick-read-taxonomy-box .quick-read-suggestions'
                ).forEach(function (box) {
                    box.style.display = 'none';
                });
            }

            if (
                !event.target.closest('#quick-read-book-search') &&
                !event.target.closest('#quick-read-book-suggestions')
            ) {
                if (bookSuggestions) bookSuggestions.style.display = 'none';
            }

            if (
                !event.target.closest('#quick-read-author-search') &&
                !event.target.closest('#quick-read-author-suggestions')
            ) {
                if (authorSuggestions) authorSuggestions.style.display = 'none';
            }
        });

        /* ---------- Cover suggestions ---------- */

        (function () {
            const findButton   = document.getElementById('quick-read-find-cover');
            const resultsBox   = document.getElementById('quick-read-cover-results');
            const statusBox    = document.getElementById('quick-read-cover-status');
            const sourceField  = document.getElementById('quick-read-cover-source');
            const refField     = document.getElementById('quick-read-cover-ref');
            const fileInput    = document.getElementById('quick-read-cover');

            if (!findButton) return;

            function clearChoice() {
                sourceField.value = '';
                refField.value = '';

                resultsBox.querySelectorAll('.quick-read-cover-option').forEach(function (option) {
                    option.style.outline = 'none';
                });
            }

            function authorName() {
                const typedNew = document.getElementById('quick-read-author-name');
                const picked = document.querySelector('#quick-read-selected-author strong');

                if (picked && picked.textContent.trim()) return picked.textContent.trim();

                return typedNew ? typedNew.value.trim() : '';
            }

            // Uploading a file yourself replaces a suggested cover.
            if (fileInput) {
                fileInput.addEventListener('change', function () {
                    if (fileInput.files && fileInput.files.length) {
                        clearChoice();
                        statusBox.textContent = 'Your uploaded file will be used.';
                    }
                });
            }

            findButton.addEventListener('click', function () {
                const title = document.getElementById('quick-read-book-title').value.trim();
                const isbn = document.getElementById('quick-read-isbn').value.trim();

                if (!title && !isbn) {
                    statusBox.textContent = 'Enter the book title (or an ISBN) first.';
                    return;
                }

                clearChoice();
                resultsBox.innerHTML = '';
                resultsBox.style.display = 'none';
                statusBox.textContent = 'Searching…';
                findButton.disabled = true;

                fetch(ajaxUrl +
                    '?action=quick_add_read_cover_search' +
                    '&nonce=' + encodeURIComponent(nonce) +
                    '&title=' + encodeURIComponent(title) +
                    '&author=' + encodeURIComponent(authorName()) +
                    '&isbn=' + encodeURIComponent(isbn))
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data || !data.success || !Array.isArray(data.data)) {
                            statusBox.textContent = data && data.data && data.data.message
                                ? data.data.message
                                : 'The search did not work. You can upload a cover yourself below.';
                            return;
                        }

                        statusBox.textContent = 'Click the right cover, or upload your own below if none are right.';

                        data.data.forEach(function (cover) {
                            const option = document.createElement('button');
                            option.type = 'button';
                            option.className = 'quick-read-cover-option';
                            option.style.cssText = 'padding:6px;border:1px solid #ccc;background:transparent;cursor:pointer;text-align:left;';

                            const image = document.createElement('img');
                            image.src = cover.thumb;
                            image.alt = 'Cover of ' + cover.title;
                            image.loading = 'lazy';
                            image.style.cssText = 'width:100%;height:auto;display:block;';

                            const caption = document.createElement('small');
                            caption.style.display = 'block';
                            caption.textContent = (cover.year ? cover.year + ' · ' : '') + cover.label;

                            option.appendChild(image);
                            option.appendChild(caption);

                            option.addEventListener('click', function () {
                                if (sourceField.value === cover.source && refField.value === cover.ref) {
                                    clearChoice();
                                    statusBox.textContent = 'No cover chosen.';
                                    return;
                                }

                                clearChoice();
                                sourceField.value = cover.source;
                                refField.value = cover.ref;
                                option.style.outline = '3px solid #c0c';

                                if (fileInput) fileInput.value = '';

                                statusBox.textContent = 'Cover chosen. Click it again to unselect.';
                            });

                            resultsBox.appendChild(option);
                        });

                        resultsBox.style.display = 'grid';
                    })
                    .catch(function () {
                        statusBox.textContent = 'The search did not work. You can upload a cover yourself below.';
                    })
                    .then(function () {
                        findButton.disabled = false;
                    });
            });
        })();

        /* ---------- Currently reading / finished ---------- */

        (function () {
            const readSection = document.getElementById('quick-read-read-section');
            const statusWrap = document.getElementById('quick-read-status-wrap');
            const submit = document.querySelector('#quick-add-read-form .quick-read-button');

            function reading() {
                const checked = document.querySelector('input[name="entry_type"]:checked');
                return checked && checked.value === 'reading';
            }

            function apply() {
                const isReading = reading();

                if (readSection) readSection.style.display = isReading ? 'none' : '';
                if (statusWrap) statusWrap.style.display = isReading ? 'none' : '';

                window.quickReadSubmitLabel = isReading ? 'Add Currently Reading' : 'Add Read';

                if (submit && !submit.disabled) submit.textContent = window.quickReadSubmitLabel;
            }

            document.querySelectorAll('input[name="entry_type"]').forEach(function (radio) {
                radio.addEventListener('change', apply);
            });

            apply();
        })();

        /* ---------- Look up pages, year and ISBN ---------- */

        (function () {
            const button = document.getElementById('quick-read-lookup-details');
            const statusBox = document.getElementById('quick-read-details-status');
            const results = document.getElementById('quick-read-details-results');
            const pages = document.getElementById('quick-read-pages');
            const year = document.getElementById('quick-read-publication-year');
            const isbn = document.getElementById('quick-read-isbn');

            if (!button) return;

            function authorName() {
                const picked = document.querySelector('#quick-read-selected-author strong');
                const typed = document.getElementById('quick-read-author-name');

                if (picked && picked.textContent.trim()) return picked.textContent.trim();

                return typed ? typed.value.trim() : '';
            }

            function fill(cover, overwrite) {
                if (cover.pages && (overwrite || !pages.value)) pages.value = cover.pages;
                if (cover.year && (overwrite || !year.value)) year.value = cover.year;
                if (cover.isbn && (overwrite || !isbn.value)) isbn.value = cover.isbn;
            }

            function describe(cover) {
                return [
                    cover.year ? cover.year : 'year ?',
                    cover.pages ? cover.pages + ' pages' : 'pages ?',
                    cover.isbn ? 'ISBN ' + cover.isbn : 'ISBN ?'
                ].join(' · ') + ' (' + cover.source + ')';
            }

            button.addEventListener('click', function () {
                const title = document.getElementById('quick-read-book-title').value.trim();

                if (!title) {
                    statusBox.textContent = 'Enter the book title first.';
                    return;
                }

                results.innerHTML = '';
                statusBox.textContent = 'Looking up…';
                button.disabled = true;

                fetch(ajaxUrl +
                    '?action=quick_add_read_book_details' +
                    '&nonce=' + encodeURIComponent(nonce) +
                    '&title=' + encodeURIComponent(title) +
                    '&author=' + encodeURIComponent(authorName()))
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data || !data.success || !data.data || !Array.isArray(data.data.candidates)) {
                            statusBox.textContent = data && data.data && data.data.message
                                ? data.data.message
                                : 'The lookup did not work. Please enter the details yourself.';
                            return;
                        }

                        const list = data.data.candidates;

                        fill(list[0], false);

                        statusBox.textContent = 'Filled in what was found (' + list[0].source +
                            '). Please check it, and correct anything that looks wrong. ' +
                            (list.length > 1 ? 'Other possible matches are below. ' : '') +
                            (data.data.notes || '');

                        if (list.length > 1) {
                            list.forEach(function (cover) {
                                const option = document.createElement('button');
                                option.type = 'button';
                                option.className = 'quick-read-secondary-button';
                                option.style.cssText = 'display:block;margin:4px 0;text-align:left;';
                                option.textContent = 'Use: ' + describe(cover);
                                option.addEventListener('click', function () {
                                    fill(cover, true);
                                    statusBox.textContent = 'Filled in from that match. Check it before saving.';
                                });
                                results.appendChild(option);
                            });
                        }
                    })
                    .catch(function () {
                        statusBox.textContent = 'The lookup did not work. Please enter the details yourself.';
                    })
                    .then(function () {
                        button.disabled = false;
                    });
            });
        })();

        /* ---------- Submit without reloading ----------
         * Errors appear above the form and keep everything you typed.
         * Success reloads the page as a normal GET, so refreshing
         * afterwards can never submit the form again.
         */

        const form = document.getElementById('quick-add-read-form');
        const messageBox = document.getElementById('quick-read-ajax-message');
        const submitButton = form ? form.querySelector('.quick-read-button') : null;
        let submitting = false;

        function resetSubmitState() {
            submitting = false;

            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = window.quickReadSubmitLabel || 'Add Read';
            }
        }

        function showError(text) {
            messageBox.innerHTML = '';

            const box = document.createElement('div');
            box.className = 'quick-read-error';

            const heading = document.createElement('strong');
            heading.textContent = 'There was a problem creating the Read:';

            box.appendChild(heading);
            box.appendChild(document.createElement('br'));
            box.appendChild(document.createTextNode(text));

            messageBox.appendChild(box);
            messageBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        if (form && submitButton) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();

                if (submitting) return;

                submitting = true;
                submitButton.disabled = true;
                submitButton.textContent = 'Saving…';
                messageBox.innerHTML = '';

                const formData = new FormData(form);
                formData.append('quick_add_read_ajax', '1');

                fetch(form.action, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data && data.success && data.data && data.data.redirect) {
                            window.location.href = data.data.redirect;
                            return;
                        }

                        const error = new Error('Request failed');
                        error.userMessage = data && data.data && data.data.message
                            ? data.data.message
                            : 'Something went wrong while creating the Read.';
                        throw error;
                    })
                    .catch(function (error) {
                        showError(
                            error && error.userMessage
                                ? error.userMessage
                                : 'Could not confirm that the read was saved. Please check your Read Instances before trying again.'
                        );
                        resetSubmitState();
                    });
            });

            // Coming back with the browser's Back button: unlock the form.
            window.addEventListener('pageshow', function (event) {
                if (event.persisted) resetSubmitState();
            });
        }

    })();
    </script>

    <?php
    return ob_get_clean();
}

add_shortcode('add_read','quick_add_read_shortcode');
