<?php
/**
 * Book Blurbs and Author Bios
 *
 * 1. Gives the Add Read form (Create Book UI) a way to fill in a new book's blurb
 *    and a new author's bio, but only when the match is certain. Otherwise blank.
 * 2. Adds Tools > Find Missing Blurbs & Bios: lists books and authors with an empty
 *    description, searches for options, and saves one only when you click it.
 *
 * Blurbs are saved as the Book's main content, bios as the Author's main content
 * (the same text box you see in the editor).
 *
 * Sources: Open Library and Google Books for blurbs; Open Library and Wikipedia
 * (only via Open Library's own link for that author) for bios.
 * Uses MISSING_COVERS_GOOGLE_KEY from the Find Missing Covers snippet if you set one.
 */

add_action('admin_menu', function () {

    add_management_page(
        'Find Missing Blurbs & Bios',
        'Find Missing Blurbs & Bios',
        'manage_options',
        'find-missing-blurbs',
        'book_text_page'
    );
});


/* ---------- Helpers ---------- */

function book_text_http_json($url, $source = '') {
    global $book_text_notes;

    if (!is_array($book_text_notes)) {
        $book_text_notes = array();
    }

    $name = $source !== '' ? $source : ((strpos($url, 'googleapis') !== false) ? 'Google Books' : 'Open Library');

    $args = array(
        'timeout'    => 10,
        'user-agent' => 'BookLog/1.0 (' . home_url() . ')',
    );

    $response = wp_remote_get($url, $args);

    // Open Library sometimes has a hiccup (500-503): wait a moment and try once more.
    if (!is_wp_error($response) && in_array(wp_remote_retrieve_response_code($response), array(500, 502, 503, 504), true)) {
        sleep(2);
        $response = wp_remote_get($url, $args);
    }

    if (is_wp_error($response)) {
        $book_text_notes[$name] = $name . ' could not be reached';
        return null;
    }

    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200) {
        if ($code !== 404) {
            $book_text_notes[$name] = $name . ($code === 429 ? ' is limiting requests right now' : ($code >= 500 ? ' had a server problem (error ' . $code . '), try again in a few minutes' : ' returned error ' . $code));
        }
        return null;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    return is_array($data) ? $data : null;
}

function book_text_norm($text) {
    $text = strtolower(remove_accents(html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8')));

    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]+/', ' ', $text)));
}

function book_text_short_title($title) {
    $title = html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8');
    $title = preg_replace('/\([^)]*\)/', ' ', $title);
    $parts = preg_split('/\s*[:\x{2014}]\s*/u', $title);
    $title = book_text_norm($parts[0]);

    return trim(preg_replace('/^(a|an|the|le|la|les|l|un|une|el|los|las|der|die|das) /', '', $title));
}

function book_text_same_title($a, $b) {
    $a = book_text_short_title($a);
    $b = book_text_short_title($b);

    if ($a === '' || $b === '') {
        return false;
    }

    if ($a === $b) {
        return true;
    }

    similar_text($a, $b, $percent);

    return $percent >= 85;
}

function book_text_author_matches($found, $wanted) {
    $found  = book_text_norm($found);
    $wanted = book_text_norm($wanted);

    if ($found === '' || $wanted === '') {
        return true;   // nothing to compare with
    }

    if ($found === $wanted) {
        return true;
    }

    $f = explode(' ', $found);
    $w = explode(' ', $wanted);

    // Same surname and same first initial ("J. K. Rowling" vs "Joanne Rowling" passes too).
    return end($f) === end($w) && $f[0][0] === $w[0][0];
}

// Turns a description from a web source into plain paragraphs.
function book_text_clean($text) {

    $text = (string) $text;

    // Open Library often appends source lists after a line of dashes.
    $text = preg_split('/\r?\n\s*-{3,}|\r?\n\s*\*{3,}|\r?\n\s*_{3,}/', $text)[0];
    $text = preg_replace('/\(\[?[^()]*\]\[\d+\]\)/', '', $text);         // ([source][1])
    $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $text);        // [text](url)
    $text = preg_replace('/\[([^\]]+)\]\[\d+\]/', '$1', $text);          // [text][1]
    $text = preg_replace('/^\s*\[\d+\]:.*$/m', '', $text);
    $text = preg_replace('/<br\s*\/?>|<\/p>/i', "\n\n", $text);
    $text = wp_strip_all_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\*\*|__/', '', $text);
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", trim($text));

    return trim($text);
}

function book_text_ol_text($value) {

    if (is_array($value)) {
        $value = isset($value['value']) ? $value['value'] : '';
    }

    return book_text_clean($value);
}

function book_text_google_url($params) {

    if (defined('MISSING_COVERS_GOOGLE_KEY') && MISSING_COVERS_GOOGLE_KEY !== '') {
        $params['key'] = MISSING_COVERS_GOOGLE_KEY;
    }

    return 'https://www.googleapis.com/books/v1/volumes?' . http_build_query($params);
}


/* ---------- Language ---------- */

// Language names (as used in your Language terms) and their codes.
function book_text_language_code($names) {

    $map = array(
        'english' => 'en', 'french' => 'fr', 'spanish' => 'es', 'german' => 'de',
        'italian' => 'it', 'portuguese' => 'pt', 'dutch' => 'nl', 'arabic' => 'ar',
    );

    if (!is_array($names)) {
        $names = preg_split('/\s*,\s*/', (string) $names);
    }

    foreach ($names as $name) {
        $key = strtolower(trim((string) $name));

        if (isset($map[$key])) {
            return $map[$key];
        }
    }

    return '';
}

// Best guess at the language of a passage ('' if it can't tell).
function book_text_detect_language($text) {

    static $words = null;

    if ($words === null) {
        $words = array(
            'en' => 'the and of to in is was that with for as his her he she by on from an which are has had their it',
            'fr' => 'le la les des du de et est une un dans que qui pour par sur avec son sa ses au aux il elle ce cette sont',
            'es' => 'el la los las del de y es una un en que por para con su sus al se como lo fue sobre entre',
            'de' => 'der die das und ist ein eine den dem des mit von zu auf für nicht sich auch als bei wird sind im',
            'it' => 'il lo la gli le di del della e è un una che per con su nel nella sono come ha dei delle anche',
            'pt' => 'o os as do da dos das e é um uma que para com por em no na se como foi sua seu mais ao',
            'nl' => 'de het een en van is dat op te in voor met zijn niet aan door als ook naar bij uit wordt',
        );
    }

    // Arabic is recognised by its script.
    $letters = preg_match_all('/\p{L}/u', (string) $text);
    $arabic  = preg_match_all('/\p{Arabic}/u', (string) $text);

    if ($letters >= 20 && $arabic / $letters > 0.5) {
        return 'ar';
    }

    $tokens = preg_split('/[^\p{L}]+/u', mb_strtolower((string) $text), -1, PREG_SPLIT_NO_EMPTY);

    if (count($tokens) < 8) {
        return '';
    }

    $counts = array_count_values($tokens);
    $scores = array();

    foreach ($words as $code => $list) {
        $score = 0;

        foreach (array_unique(explode(' ', $list)) as $word) {
            if (isset($counts[$word])) {
                $score += $counts[$word];
            }
        }

        $scores[$code] = $score;
    }

    arsort($scores);

    $codes = array_keys($scores);
    $top   = $scores[$codes[0]];
    $next  = $scores[$codes[1]];

    return ($top >= 3 && $top >= $next * 1.5) ? $codes[0] : '';
}

// Options in the wanted language come first; the rest keep their order.
function book_text_sort_by_language($options, $wanted) {

    if ($wanted === '') {
        return $options;
    }

    foreach ($options as $i => &$option) {
        $option['_i'] = $i;
    }
    unset($option);

    usort($options, function ($a, $b) use ($wanted) {

        $rank = function ($o) use ($wanted) {
            if ($o['lang'] === $wanted) return 0;
            if ($o['lang'] === '')      return 1;   // can't tell
            return 2;
        };

        return ($rank($a) <=> $rank($b)) ?: ($a['_i'] <=> $b['_i']);
    });

    return $options;
}


/* ---------- Searching ---------- */

// Open Library docs for this title and author that really are the same book.
function book_text_ol_docs($title, $author) {

    $query = array(
        'title'  => $title,
        'limit'  => 6,
        'fields' => 'key,title,author_name,author_key',
    );

    if ($author !== '') {
        $query['author'] = $author;
    }

    $ol   = book_text_http_json('https://openlibrary.org/search.json?' . http_build_query($query), 'Open Library');
    $docs = array();

    if ($ol && !empty($ol['docs'])) {
        foreach ($ol['docs'] as $doc) {

            $found_author = !empty($doc['author_name'][0]) ? $doc['author_name'][0] : '';

            if (empty($doc['key']) || empty($doc['title'])) continue;
            if (!book_text_same_title($doc['title'], $title)) continue;
            if ($author !== '' && !book_text_author_matches($found_author, $author)) continue;

            $docs[] = $doc;
        }
    }

    return $docs;
}

// Possible blurbs: array of [source, matched, text].
function book_text_blurb_options($title, $author, $wanted_lang = 'en') {

    $options = array();
    $seen    = array();

    $add = function ($source, $matched, $text) use (&$options, &$seen) {

        $text = book_text_clean($text);

        if (strlen($text) < 60) return;

        $key = substr(book_text_norm($text), 0, 80);

        if (isset($seen[$key])) return;

        $seen[$key] = true;
        $options[]  = array('source' => $source, 'matched' => $matched, 'text' => $text, 'lang' => book_text_detect_language($text));
    };

    foreach (array_slice(book_text_ol_docs($title, $author), 0, 3) as $doc) {

        $work = book_text_http_json('https://openlibrary.org' . $doc['key'] . '.json', 'Open Library');

        if ($work && !empty($work['description'])) {
            $add('Open Library', $doc['title'] . (!empty($doc['author_name'][0]) ? ' by ' . $doc['author_name'][0] : ''), book_text_ol_text($work['description']));
        }
    }

    $gb = book_text_http_json(book_text_google_url(array(
        'q'          => 'intitle:' . $title . ($author !== '' ? ' inauthor:' . $author : ''),
        'maxResults' => 5,
        'printType'  => 'books',
    )), 'Google Books');

    if ($gb && !empty($gb['items'])) {
        foreach ($gb['items'] as $item) {

            $info         = isset($item['volumeInfo']) ? $item['volumeInfo'] : array();
            $found_author = !empty($info['authors'][0]) ? $info['authors'][0] : '';

            if (empty($info['title']) || empty($info['description'])) continue;
            if (!book_text_same_title($info['title'], $title)) continue;
            if ($author !== '' && !book_text_author_matches($found_author, $author)) continue;

            $add('Google Books', $info['title'] . ($found_author !== '' ? ' by ' . $found_author : ''), $info['description']);
        }
    }

    return book_text_sort_by_language($options, $wanted_lang);
}

// Possible bios: array of [source, matched, text]. The author is tied to the
// book (the same Open Library record must match both), so a namesake is not used.
function book_text_bio_options($author, $book_title) {

    $options = array();
    $keys    = array();

    if ($author === '') {
        return $options;
    }

    foreach (book_text_ol_docs($book_title, $author) as $doc) {

        $names = isset($doc['author_name']) ? (array) $doc['author_name'] : array();
        $ids   = isset($doc['author_key'])  ? (array) $doc['author_key']  : array();

        foreach ($names as $i => $name) {
            if (isset($ids[$i]) && book_text_author_matches($name, $author) && book_text_norm($name) !== '') {
                $keys[$ids[$i]] = $name;
            }
        }
    }

    foreach (array_slice($keys, 0, 2, true) as $key => $name) {

        $record = book_text_http_json('https://openlibrary.org/authors/' . rawurlencode($key) . '.json', 'Open Library');

        if (!$record) continue;

        if (!empty($record['bio'])) {
            $text = book_text_ol_text($record['bio']);

            if (strlen($text) >= 60) {
                $options[] = array('source' => 'Open Library', 'matched' => $name, 'text' => $text, 'lang' => book_text_detect_language($text));
            }
        }

        // Open Library's own Wikipedia link for this author.
        if (!empty($record['wikipedia']) && preg_match('~wikipedia\.org/wiki/([^?#]+)~', $record['wikipedia'], $m)) {

            $wiki = book_text_http_json('https://en.wikipedia.org/api/rest_v1/page/summary/' . $m[1], 'Wikipedia');

            if ($wiki && ($wiki['type'] ?? '') === 'standard' && !empty($wiki['extract']) && strlen($wiki['extract']) >= 60) {
                $options[] = array(
                    'source'  => 'Wikipedia',
                    'matched' => isset($wiki['title']) ? $wiki['title'] : $name,
                    'text'    => book_text_clean($wiki['extract']),
                    'lang'    => 'en',
                );
            }
        }
    }

    return book_text_sort_by_language($options, 'en');
}

// For the Add Read form: the first match, or '' when nothing is certain.
// Never throws, and never holds up saving for long.
function book_text_auto($title, $author, $want_bio = true, $language_names = '') {

    $result = array('blurb' => '', 'bio' => '');

    try {
        $title  = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $title));
        $wanted = book_text_language_code($language_names);
        $wanted = $wanted !== '' ? $wanted : 'en';
        $blurbs = book_text_blurb_options($title, $author, $wanted);

        // Only a blurb in the right language (or one whose language can't be told).
        if ($blurbs && ($blurbs[0]['lang'] === $wanted || $blurbs[0]['lang'] === '')) {
            $result['blurb'] = $blurbs[0]['text'];
        }

        if ($want_bio) {
            $bios = book_text_bio_options($author, $title);

            if ($bios && ($bios[0]['lang'] === 'en' || $bios[0]['lang'] === '')) {
                $result['bio'] = $bios[0]['text'];
            }
        }
    } catch (Throwable $e) {
        // Leave both blank.
    }

    return $result;
}


/* ---------- AJAX ---------- */

add_action('wp_ajax_book_text_search', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    check_ajax_referer('book_text', 'nonce');

    global $book_text_notes;
    $book_text_notes = array();

    $kind   = (isset($_GET['kind']) && $_GET['kind'] === 'author') ? 'author' : 'book';
    $title  = isset($_GET['title'])  ? sanitize_text_field(wp_unslash($_GET['title']))  : '';
    $author = isset($_GET['author']) ? sanitize_text_field(wp_unslash($_GET['author'])) : '';

    $lang = isset($_GET['lang']) ? sanitize_text_field(wp_unslash($_GET['lang'])) : '';

    $options = $kind === 'author'
        ? book_text_bio_options($author, $title)
        : book_text_blurb_options($title, $author, $lang !== '' ? $lang : 'en');

    wp_send_json_success(array(
        'options' => $options,
        'notes'   => implode('. ', $book_text_notes),
    ));
});

add_action('wp_ajax_book_text_apply', function () {

    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    check_ajax_referer('book_text', 'nonce');

    $id   = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
    $text = isset($_POST['text']) ? sanitize_textarea_field(wp_unslash($_POST['text'])) : '';

    if (!$id || !in_array(get_post_type($id), array('book', 'author'), true)) {
        wp_send_json_error(array('message' => 'That record could not be found.'));
    }

    if (trim($text) === '') {
        wp_send_json_error(array('message' => 'There was no text to save.'));
    }

    if (trim(get_post_field('post_content', $id)) !== '') {
        wp_send_json_error(array('message' => 'This already has text, so it was left alone.'));
    }

    $result = wp_update_post(array('ID' => $id, 'post_content' => wp_slash($text)), true);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success();
});


/* ---------- The page ---------- */

function book_text_page() {

    if (!current_user_can('manage_options')) {
        return;
    }

    $kind     = (isset($_GET['kind']) && $_GET['kind'] === 'author') ? 'author' : 'book';
    $per_page = 15;
    $paged    = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

    $ordered = book_text_ordered_ids($kind);
    $total   = count($ordered);
    $pages_n = max(1, (int) ceil($total / $per_page));
    $paged   = min($paged, $pages_n);
    $slice   = array_slice($ordered, ($paged - 1) * $per_page, $per_page);

    $query = new WP_Query(array(
        'post_type'      => $kind,
        'post_status'    => 'any',
        'post__in'       => $slice ? $slice : array(0),
        'orderby'        => 'post__in',
        'posts_per_page' => $per_page,
        'no_found_rows'  => true,
    ));

    $base = admin_url('tools.php?page=find-missing-blurbs');
    ?>

    <div class="wrap">
        <h1>Find Missing Blurbs &amp; Bios</h1>

        <h2 class="nav-tab-wrapper">
            <a class="nav-tab <?php echo $kind === 'book' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($base); ?>">Book blurbs</a>
            <a class="nav-tab <?php echo $kind === 'author' ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($base . '&kind=author'); ?>">Author bios</a>
        </h2>

        <p>
            <strong><?php echo (int) $total; ?></strong>
            <?php echo $kind === 'book' ? 'book(s) with status Read or Currently Reading have' : 'author(s) have'; ?> no text.
            Currently reading comes first, then the most recently read.
            Search, check that the match is the right <?php echo $kind === 'book' ? 'book' : 'author'; ?>, then click <em>Use this</em>.
            Nothing is saved until you do. Edit the title or author box and search again if it found nothing.
        </p>

        <?php if ($query->have_posts()) : ?>

            <p><button type="button" class="button button-primary" id="bt-search-all">Search all on this page</button></p>

            <style>
                .bt-row { background:#fff; border:1px solid #ccd0d4; padding:12px 16px; margin-bottom:12px; }
                .bt-row h3 { margin:0 0 8px; }
                .bt-row input[type=text] { width:260px; max-width:100%; }
                .bt-results { margin-top:12px; }
                .bt-option { border:1px solid #ccd0d4; padding:10px 12px; margin-bottom:8px; background:#fafafa; }
                .bt-option p { margin:6px 0; max-height:150px; overflow:auto; white-space:pre-wrap; }
                .bt-status { margin-left:8px; }
                .bt-done { opacity:.6; }
            </style>

            <?php while ($query->have_posts()) : $query->the_post();

                $post_id = get_the_ID();

                if ($kind === 'book') {
                    $author_id = (int) get_post_meta($post_id, '_book_author_id', true);
                    $author    = $author_id ? get_the_title($author_id) : '';
                    $title     = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', get_the_title()));
                    $lang_terms = wp_get_post_terms($post_id, 'book_language', array('fields' => 'names'));
                    $row_lang   = book_text_language_code(is_wp_error($lang_terms) ? array() : $lang_terms);
                } else {
                    $row_lang = 'en';
                    // Authors are matched through one of their books.
                    $author = get_the_title();
                    $books  = get_posts(array(
                        'post_type'      => 'book',
                        'post_status'    => array('publish', 'draft', 'private'),
                        'posts_per_page' => 1,
                        'meta_key'       => '_book_author_id',
                        'meta_value'     => $post_id,
                        'orderby'        => 'date',
                        'order'          => 'DESC',
                    ));
                    $title = $books ? trim(preg_replace('/\s*\([^)]*\)\s*$/', '', get_the_title($books[0]))) : '';
                }
                ?>

                <div class="bt-row" data-id="<?php echo (int) $post_id; ?>" data-lang="<?php echo esc_attr($row_lang !== '' ? $row_lang : 'en'); ?>">
                    <h3><?php echo esc_html(get_the_title()); ?><?php echo ($kind === 'book' && $author !== '') ? ' <small>by ' . esc_html($author) . '</small>' : ''; ?></h3>

                    <input type="text" class="bt-title" value="<?php echo esc_attr($title); ?>" aria-label="Book title" placeholder="Book title">
                    <input type="text" class="bt-author" value="<?php echo esc_attr($author); ?>" aria-label="Author" placeholder="Author">

                    <button type="button" class="button bt-search">Search</button>
                    <a class="button" href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" target="_blank" rel="noopener">Edit</a>
                    <span class="bt-status"></span>

                    <div class="bt-results"></div>
                </div>

            <?php endwhile; wp_reset_postdata(); ?>

            <?php
            $links = paginate_links(array(
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'current'   => $paged,
                'total'     => $pages_n,
                'prev_text' => '&laquo; Previous',
                'next_text' => 'Next &raquo;',
            ));

            if ($links) {
                echo '<div class="tablenav"><div class="tablenav-pages">' . $links . '</div></div>';
            }
            ?>

            <script>
            (function () {
                const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                const nonce = <?php echo wp_json_encode(wp_create_nonce('book_text')); ?>;
                const kind = <?php echo wp_json_encode($kind); ?>;

                function search(row) {
                    return new Promise(function (resolve) {
                        const status = row.querySelector('.bt-status');
                        const results = row.querySelector('.bt-results');

                        results.innerHTML = '';
                        status.textContent = 'Searching…';

                        fetch(ajaxUrl + '?action=book_text_search&nonce=' + encodeURIComponent(nonce) +
                            '&kind=' + kind + '&lang=' + encodeURIComponent(row.dataset.lang || 'en') +
                            '&title=' + encodeURIComponent(row.querySelector('.bt-title').value) +
                            '&author=' + encodeURIComponent(row.querySelector('.bt-author').value))
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                if (!data.success) { status.textContent = 'The search did not work.'; return; }

                                const options = data.data.options;
                                const notes = data.data.notes ? ' (' + data.data.notes + ')' : '';

                                if (!options.length) {
                                    status.textContent = 'No certain match found' + notes + '. Leave it blank, or adjust the boxes and search again.';
                                    return;
                                }

                                status.textContent = options.length + ' option(s)' + notes + '.';

                                options.forEach(function (option) {
                                    const box = document.createElement('div');
                                    box.className = 'bt-option';

                                    const head = document.createElement('strong');
                                    head.textContent = option.source + ' — matched: ' + option.matched + (option.lang ? ' — language: ' + option.lang : '');

                                    const text = document.createElement('p');
                                    text.textContent = option.text;

                                    const use = document.createElement('button');
                                    use.type = 'button';
                                    use.className = 'button button-primary';
                                    use.textContent = 'Use this';
                                    use.addEventListener('click', function () {
                                        use.disabled = true;

                                        const body = new URLSearchParams();
                                        body.append('action', 'book_text_apply');
                                        body.append('nonce', nonce);
                                        body.append('post_id', row.dataset.id);
                                        body.append('text', option.text);

                                        fetch(ajaxUrl, { method: 'POST', body: body })
                                            .then(function (r) { return r.json(); })
                                            .then(function (saved) {
                                                if (saved.success) {
                                                    results.innerHTML = '';
                                                    status.textContent = 'Saved.';
                                                    row.classList.add('bt-done');
                                                } else {
                                                    use.disabled = false;
                                                    status.textContent = (saved.data && saved.data.message) || 'Could not save.';
                                                }
                                            })
                                            .catch(function () { use.disabled = false; status.textContent = 'Could not save.'; });
                                    });

                                    box.append(head, text, use);
                                    results.appendChild(box);
                                });
                            })
                            .catch(function () { status.textContent = 'The search did not work.'; })
                            .then(resolve);
                    });
                }

                document.querySelectorAll('.bt-row').forEach(function (row) {
                    row.querySelector('.bt-search').addEventListener('click', function () { search(row); });
                });

                document.getElementById('bt-search-all').addEventListener('click', async function () {
                    this.disabled = true;

                    for (const row of document.querySelectorAll('.bt-row:not(.bt-done)')) {
                        await search(row);
                    }

                    this.disabled = false;
                });
            })();
            </script>

        <?php else : ?>

            <div class="notice notice-success inline"><p>Nothing is missing here.</p></div>

        <?php endif; ?>
    </div>
    <?php
}

// IDs of books (or authors) with no text, in this order: currently reading first
// (newest added first), then by most recent read date. For authors, their books
// decide the order. Anything with no read or current book goes last.
function book_text_ordered_ids($kind) {

    global $wpdb;

    $empty = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = %s AND post_status IN ('publish','draft','pending','private')
         AND TRIM(post_content) = ''",
        $kind
    ));

    if (!$empty) {
        return array();
    }

    $status = array();   // book id => status
    foreach ($wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_book_status'") as $row) {
        $status[(int) $row->post_id] = $row->meta_value;
    }

    $last_read = array();   // book id => latest date read
    foreach ($wpdb->get_results(
        "SELECT b.meta_value AS book_id, MAX(d.meta_value) AS last_read
         FROM {$wpdb->postmeta} b
         INNER JOIN {$wpdb->postmeta} d ON d.post_id = b.post_id AND d.meta_key = '_read_instance_date_read'
         WHERE b.meta_key = '_read_instance_book_id'
         GROUP BY b.meta_value"
    ) as $row) {
        $last_read[(int) $row->book_id] = (string) $row->last_read;
    }

    $added = array();   // post id => date added
    foreach ($wpdb->get_results("SELECT ID, post_date FROM {$wpdb->posts} WHERE post_type IN ('book','author')") as $row) {
        $added[(int) $row->ID] = $row->post_date;
    }

    // Rank of one book: [group, date]. Group 0 = reading, 1 = read, 2 = other.
    $rank = function ($book_id) use ($status, $last_read, $added) {

        $st = isset($status[$book_id]) ? $status[$book_id] : '';

        if ($st === 'Currently Reading') {
            return array(0, isset($added[$book_id]) ? $added[$book_id] : '');
        }

        if ($st === 'Read') {
            return array(1, isset($last_read[$book_id]) ? $last_read[$book_id] : '');
        }

        return array(2, isset($added[$book_id]) ? $added[$book_id] : '');
    };

    $best = array();   // post id => [group, date]

    if ($kind === 'book') {
        foreach ($empty as $id) {
            $r = $rank((int) $id);

            // Only books you have read or are reading.
            if ($r[0] < 2) {
                $best[(int) $id] = $r;
            }
        }
    } else {
        foreach ($empty as $id) {
            $best[(int) $id] = array(3, '');
        }

        foreach ($wpdb->get_results("SELECT post_id, meta_value AS author_id FROM {$wpdb->postmeta} WHERE meta_key = '_book_author_id'") as $row) {

            $author_id = (int) $row->author_id;

            if (!isset($best[$author_id])) continue;

            $r   = $rank((int) $row->post_id);
            $cur = $best[$author_id];

            // Better group wins; within a group, the later date wins.
            if ($r[0] < $cur[0] || ($r[0] === $cur[0] && strcmp($r[1], $cur[1]) > 0)) {
                $best[$author_id] = $r;
            }
        }
    }

    $ids = array_keys($best);

    usort($ids, function ($a, $b) use ($best, $added) {

        if ($best[$a][0] !== $best[$b][0]) {
            return $best[$a][0] <=> $best[$b][0];
        }

        // Newest first; records with no date go last in their group.
        $by_date = strcmp($best[$b][1], $best[$a][1]);

        if ($by_date !== 0) {
            return $by_date;
        }

        return strcmp(isset($added[$b]) ? $added[$b] : '', isset($added[$a]) ? $added[$a] : '') ?: ($b <=> $a);
    });

    return $ids;
}
