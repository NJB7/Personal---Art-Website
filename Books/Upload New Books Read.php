<?php

/* =========================================================
   ONE-TIME IMPORT: A MONTH OF READS FROM A CSV

   Adds Tools > Import Monthly Reads (administrators only).

   For a spreadsheet saved as CSV with these columns:
     Title, Authors, ISBN/UID, Format, Read Status, Last Date Read,
     Star Rating, Review, Tags
   (the order does not matter, ISBN/UID and Tags are optional).

   1. Upload the CSV. Nothing is saved yet: you get a preview showing,
      for every row, whether the Book and Author already exist (they are
      reused) or will be created, and what Read Instance will be added.
   2. Fix any spelling in the preview (titles and authors are editable),
      untick any row you don't want, set the Language Read, and click
      Import.

   What it creates
     Authors   one per name, reused if the person is already on the site.
               Several authors on a row: all are created, the first is
               the Book's author. Country, gender and so on stay blank.
     Books     reused if the same title (ignoring a subtitle or a series
               note in brackets) by the same author is already there; a
               new Book otherwise, with status Read. The ISBN is kept in
               a hidden field (_book_isbn).
     Reads     one Read Instance per row, titled "Read MMM dd, yyyy", with
               Date Read, Rating, Format, Language Read, and the review as
               its text. A row that matches an existing read of the same
               Book, date and format is skipped, so running this twice
               does not double anything.

   Notes
     - Ratings are rounded to the nearest half star (3.75 becomes 4).
     - Tags are not imported.
     - File text saved by older Excel (Windows-1252) is converted, so
       accents such as n-tilde come through. A character the file already
       lost (it shows as "?") is matched against authors already on the
       site, or left for you to correct in the preview.
     - New Books have no cover: use Tools > Find Missing Covers afterwards.

   Delete this snippet when you are done.
   ========================================================= */

add_action('admin_menu', function () {

    add_management_page(
        'Import Monthly Reads',
        'Import Monthly Reads',
        'manage_options',
        'monthly-reads-import',
        'monthly_reads_page'
    );
});


/* ---------- Helpers ---------- */

// Lower-case, no accents, only letters, numbers and spaces (optionally keeping ? and the broken-character mark).
function monthly_reads_norm($text, $keep_wild = false) {

    $text = strtolower(remove_accents(html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8')));
    $text = preg_replace($keep_wild ? '/[^a-z0-9?\x{FFFD} ]+/u' : '/[^a-z0-9 ]+/u', ' ', $text);

    return trim(preg_replace('/\s+/', ' ', $text));
}

function monthly_reads_short_title($title) {

    $title = html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8');
    $title = preg_replace('/\([^)]*\)/', ' ', $title);
    $title = preg_split('/\s*[:\x{2014}]\s*/u', $title)[0];
    $title = monthly_reads_norm($title);

    return trim(preg_replace('/^(a|an|the|le|la|les|l|un|une|el|los|las|der|die|das) /', '', $title));
}

function monthly_reads_author_key($name) {

    $words = array_filter(explode(' ', monthly_reads_norm($name)));
    sort($words);

    return implode(' ', $words);
}

function monthly_reads_clean_review($html) {

    $text = preg_replace('#<br\s*/?>#i', "\n", (string) $html);
    $text = preg_replace('#</(div|p)>#i', "\n\n", $text);
    $text = wp_strip_all_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace("/[ \t]+\n/", "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    return trim($text);
}

function monthly_reads_rating($value) {

    $value = trim((string) $value);

    if ($value === '' || !is_numeric($value)) {
        return '';
    }

    $rating = round(((float) $value) * 2) / 2;

    if ($rating < 1) {
        return '';
    }

    $rating = min(5, $rating);

    return ($rating == floor($rating)) ? (string) (int) $rating : (string) $rating;
}

function monthly_reads_format($value) {

    $value = strtolower(trim((string) $value));
    $map   = array(
        'audiobook' => 'Audiobook', 'audio' => 'Audiobook',
        'print' => 'Print', 'paperback' => 'Print', 'hardcover' => 'Print', 'physical' => 'Print',
        'ebook' => 'eBook', 'e-book' => 'eBook', 'kindle' => 'eBook', 'digital' => 'eBook',
    );

    return isset($map[$value]) ? $map[$value] : '';
}

function monthly_reads_status($value) {

    $value = strtolower(trim((string) $value));
    $map   = array(
        'read' => 'Read', 'currently-reading' => 'Currently Reading', 'currently reading' => 'Currently Reading',
        'to-read' => 'To Read', 'to read' => 'To Read', 'dnf' => 'DNF', 'did-not-finish' => 'DNF',
    );

    return isset($map[$value]) ? $map[$value] : 'Read';
}

function monthly_reads_date($value) {

    $value = trim((string) $value);

    foreach (array('!Y/m/d', '!Y-m-d', '!n/j/Y') as $format) {

        $parsed = DateTime::createFromFormat($format, $value, wp_timezone());

        if ($parsed && $parsed->format(substr($format, 1)) === $value) {
            return $parsed->format('Y-m-d');
        }
    }

    return '';
}

function monthly_reads_status_rank($status) {

    $ranks = array('Read' => 4, 'Currently Reading' => 3, 'DNF' => 2, 'To Read' => 1);

    return isset($ranks[$status]) ? $ranks[$status] : 0;
}


/* ---------- Reading the CSV ---------- */

// Returns the rows (each keyed by column heading) or an error message.
function monthly_reads_parse($raw) {

    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) $raw);

    if ($raw === '') {
        return 'That file is empty.';
    }

    // Some files mix encodings (Windows-1252 accents beside UTF-8 curly quotes).
    // Keep every valid UTF-8 character as it is and convert only the stray bytes.
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = preg_replace_callback(
            '/([\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|([\x80-\xFF])/',
            function ($m) {
                return $m[1] !== '' ? $m[1] : mb_convert_encoding($m[2], 'UTF-8', 'Windows-1252');
            },
            $raw
        );
    }

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $raw);
    rewind($handle);

    $header = null;
    $rows   = array();

    while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {

        if ($cells === array(null)) {
            continue;
        }

        if ($header === null) {
            $header = array_map('trim', $cells);
            continue;
        }

        $row = array();

        foreach ($header as $index => $label) {
            $row[$label] = isset($cells[$index]) ? trim($cells[$index]) : '';
        }

        if (($row['Title'] ?? '') === '') {
            continue;
        }

        $rows[] = $row;
    }

    fclose($handle);

    foreach (array('Title', 'Authors') as $needed) {
        if ($header === null || !in_array($needed, $header, true)) {
            return 'The CSV needs a "' . $needed . '" column.';
        }
    }

    return $rows;
}


/* ---------- What is already on the site ---------- */

function monthly_reads_maps() {

    $maps = array('authors' => array(), 'author_keys' => array(), 'books' => array());

    $author_ids = get_posts(array(
        'post_type' => 'author', 'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
        'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
    ));

    foreach ($author_ids as $id) {
        $name = get_the_title($id);
        $maps['authors'][$id] = monthly_reads_norm($name);
        $maps['author_keys'][monthly_reads_author_key($name)] = (int) $id;
    }

    $book_ids = get_posts(array(
        'post_type' => 'book', 'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
        'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
    ));

    update_meta_cache('post', $book_ids);

    foreach ($book_ids as $id) {

        $author_id = (int) get_post_meta($id, '_book_author_id', true);

        $maps['books'][] = array(
            'id'         => (int) $id,
            'short'      => monthly_reads_short_title(get_the_title($id)),
            'author_id'  => $author_id,
            'author_key' => $author_id ? monthly_reads_author_key(get_the_title($author_id)) : '',
        );
    }

    return $maps;
}

function monthly_reads_find_author($name, $maps) {

    $norm = monthly_reads_norm($name, true);

    if ($norm === '') {
        return 0;
    }

    // A character the file lost shows as ? or a replacement mark: treat it as "any one letter".
    if (preg_match('/[?\x{FFFD}]/u', $norm)) {

        $pattern = '';

        foreach (preg_split('//u', $norm, -1, PREG_SPLIT_NO_EMPTY) as $char) {
            $pattern .= ($char === '?' || $char === "\u{FFFD}") ? '.' : preg_quote($char, '/');
        }

        foreach ($maps['authors'] as $id => $existing) {
            if (preg_match('/^' . $pattern . '$/u', $existing)) {
                return (int) $id;
            }
        }

        return 0;
    }

    $key = monthly_reads_author_key($name);

    return isset($maps['author_keys'][$key]) ? $maps['author_keys'][$key] : 0;
}

function monthly_reads_find_book($title, $author_id, $first_author_name, $maps) {

    $short = monthly_reads_short_title($title);
    $key   = monthly_reads_author_key($first_author_name);

    foreach ($maps['books'] as $book) {

        if ($book['short'] !== $short) {
            continue;
        }

        if (($author_id && $book['author_id'] === $author_id) || ($key !== '' && $book['author_key'] === $key)) {
            return $book['id'];
        }
    }

    return 0;
}

function monthly_reads_find_read($book_id, $date, $format) {

    if (!$book_id || $date === '') {
        return 0;
    }

    $meta = array(
        'relation' => 'AND',
        array('key' => '_read_instance_book_id', 'value' => $book_id),
        array('key' => '_read_instance_date_read', 'value' => $date),
    );

    if ($format !== '') {
        $meta[] = array('key' => '_read_instance_format', 'value' => $format);
    }

    $found = get_posts(array(
        'post_type' => 'read_instance', 'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
        'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => $meta,
    ));

    return $found ? (int) $found[0] : 0;
}

// Works out what would happen for one row.
function monthly_reads_plan($row, $maps) {

    $names = array_values(array_filter(array_map('trim', explode(',', (string) ($row['Authors'] ?? '')))));

    $authors = array();

    foreach ($names as $name) {
        $authors[] = array('name' => $name, 'id' => monthly_reads_find_author($name, $maps));
    }

    $primary_id   = $authors ? $authors[0]['id'] : 0;
    $primary_name = $authors ? $authors[0]['name'] : '';
    $book_id      = monthly_reads_find_book((string) $row['Title'], $primary_id, $primary_name, $maps);

    $status = monthly_reads_status($row['Read Status'] ?? '');
    $date   = monthly_reads_date($row['Last Date Read'] ?? '');
    $format = monthly_reads_format($row['Format'] ?? '');

    $has_read = ($status === 'Read' && $date !== '');

    return array(
        'authors'  => $authors,
        'book_id'  => $book_id,
        'status'   => $status,
        'date'     => $date,
        'format'   => $format,
        'rating'   => monthly_reads_rating($row['Star Rating'] ?? ''),
        'review'   => monthly_reads_clean_review($row['Review'] ?? ''),
        'has_read' => $has_read,
        'existing' => $has_read ? monthly_reads_find_read($book_id, $date, $format) : 0,
        'suspect'  => (bool) preg_match('/[?\x{FFFD}]/u', $row['Title'] . ' ' . $row['Authors']) && (count(array_filter($authors, function ($a) { return !$a['id']; })) > 0),
    );
}


/* ---------- Saving ---------- */

function monthly_reads_apply($row, &$maps, $language) {

    $plan  = monthly_reads_plan($row, $maps);
    $lines = array();

    // Authors.
    $author_ids = array();

    foreach ($plan['authors'] as $author) {

        $id = $author['id'];

        if (!$id) {

            $id = wp_insert_post(array(
                'post_type' => 'author', 'post_title' => wp_slash($author['name']), 'post_status' => 'publish',
            ), true);

            if (is_wp_error($id) || !$id) {
                return array('error' => 'Could not create author "' . $author['name'] . '".');
            }

            $id = (int) $id;
            $maps['authors'][$id] = monthly_reads_norm($author['name']);
            $maps['author_keys'][monthly_reads_author_key($author['name'])] = $id;
            $lines[] = 'new author "' . $author['name'] . '"';
        }

        $author_ids[] = $id;
    }

    $primary_id = $author_ids ? $author_ids[0] : 0;

    // Book.
    $book_id = $plan['book_id'];

    if (!$book_id) {

        $book_id = wp_insert_post(array(
            'post_type' => 'book', 'post_title' => wp_slash((string) $row['Title']), 'post_status' => 'publish',
        ), true);

        if (is_wp_error($book_id) || !$book_id) {
            return array('error' => 'Could not create book "' . $row['Title'] . '".');
        }

        $book_id = (int) $book_id;

        if ($primary_id) {
            update_post_meta($book_id, '_book_author_id', $primary_id);
        }

        update_post_meta($book_id, '_book_status', $plan['status']);

        $isbn = trim((string) ($row['ISBN/UID'] ?? ''));

        if ($isbn !== '') {
            update_post_meta($book_id, '_book_isbn', sanitize_text_field($isbn));
        }

        $maps['books'][] = array(
            'id' => $book_id, 'short' => monthly_reads_short_title($row['Title']), 'author_id' => $primary_id,
            'author_key' => $plan['authors'] ? monthly_reads_author_key($plan['authors'][0]['name']) : '',
        );

        $lines[] = 'new book';

    } else {

        $current = (string) get_post_meta($book_id, '_book_status', true);

        if (monthly_reads_status_rank($plan['status']) > monthly_reads_status_rank($current)) {
            update_post_meta($book_id, '_book_status', $plan['status']);
        }

        $lines[] = 'existing book #' . $book_id;
    }

    // Read Instance.
    if (!$plan['has_read']) {
        return array('book_id' => $book_id, 'read_id' => 0, 'note' => implode(', ', $lines) . ', no read added (status ' . $plan['status'] . ' or no date)');
    }

    if (monthly_reads_find_read($book_id, $plan['date'], $plan['format'])) {
        return array('book_id' => $book_id, 'read_id' => 0, 'note' => implode(', ', $lines) . ', read already there, skipped');
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $plan['date'], wp_timezone());
    $title  = 'Read ' . ($parsed ? wp_date('M d, Y', $parsed->getTimestamp(), wp_timezone()) : $plan['date']);

    $read_id = wp_insert_post(array(
        'post_type' => 'read_instance', 'post_title' => wp_slash($title),
        'post_content' => wp_slash($plan['review']), 'post_status' => 'publish',
    ), true);

    if (is_wp_error($read_id) || !$read_id) {
        return array('error' => 'Could not create the read for "' . $row['Title'] . '".');
    }

    update_post_meta($read_id, '_read_instance_book_id', $book_id);
    update_post_meta($read_id, '_read_instance_date_read', $plan['date']);

    if ($plan['rating'] !== '') {
        update_post_meta($read_id, '_read_instance_rating', $plan['rating']);
    }

    if ($plan['format'] !== '') {
        update_post_meta($read_id, '_read_instance_format', $plan['format']);
    }

    if ($language !== '') {
        wp_set_object_terms($read_id, $language, 'book_language', false);
    }

    return array('book_id' => $book_id, 'read_id' => (int) $read_id, 'note' => implode(', ', $lines) . ', read added');
}


/* ---------- The Tools page ---------- */

function monthly_reads_page() {

    if (!current_user_can('manage_options')) {
        return;
    }

    $error   = '';
    $preview = null;
    $results = null;

    /* Step 2: the confirmed import. */
    if (isset($_POST['monthly_reads_confirm']) && check_admin_referer('monthly_reads')) {

        $token = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_POST['token'] ?? ''));
        $rows  = get_transient('monthly_reads_' . $token);

        if (!is_array($rows)) {
            $error = 'That preview has expired. Upload the CSV again.';
        } else {

            @set_time_limit(300);

            $language = isset($_POST['language']) ? sanitize_text_field(wp_unslash($_POST['language'])) : '';
            $maps     = monthly_reads_maps();
            $results  = array();

            foreach ($rows as $index => $row) {

                if (empty($_POST['include'][$index])) {
                    continue;
                }

                // Use the title and authors as edited in the preview.
                if (isset($_POST['title'][$index])) {
                    $row['Title'] = sanitize_text_field(wp_unslash($_POST['title'][$index]));
                }

                if (isset($_POST['authors'][$index])) {
                    $row['Authors'] = sanitize_text_field(wp_unslash($_POST['authors'][$index]));
                }

                $results[] = array('title' => $row['Title'], 'result' => monthly_reads_apply($row, $maps, $language));
            }

            delete_transient('monthly_reads_' . $token);
        }
    }

    /* Step 1: the upload and preview. */
    if (isset($_POST['monthly_reads_upload']) && check_admin_referer('monthly_reads')) {

        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $error = 'Choose the CSV file first.';
        } else {

            $rows = monthly_reads_parse(file_get_contents($_FILES['csv']['tmp_name']));

            if (is_string($rows)) {
                $error = $rows;
            } elseif (!$rows) {
                $error = 'No rows were found in that file.';
            } else {

                $token = wp_generate_password(20, false, false);
                set_transient('monthly_reads_' . $token, $rows, HOUR_IN_SECONDS);

                $maps    = monthly_reads_maps();
                $plans   = array();

                foreach ($rows as $index => $row) {
                    $plans[$index] = monthly_reads_plan($row, $maps);
                }

                $preview = array('token' => $token, 'rows' => $rows, 'plans' => $plans);
            }
        }
    }
    ?>

    <div class="wrap">
        <h1>Import Monthly Reads</h1>

        <?php if ($error !== '') : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <?php if (is_array($results)) : ?>

            <div class="notice notice-success"><p><strong>Finished.</strong> <?php echo count($results); ?> row(s) processed.</p></div>

            <table class="widefat striped" style="max-width:1000px">
                <thead><tr><th>Title</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($results as $item) : ?>
                    <tr>
                        <td><?php echo esc_html($item['title']); ?></td>
                        <td>
                            <?php if (isset($item['result']['error'])) : ?>
                                <strong style="color:#b32d2e"><?php echo esc_html($item['result']['error']); ?></strong>
                            <?php else : ?>
                                <?php echo esc_html($item['result']['note']); ?>
                                <?php if (!empty($item['result']['read_id'])) : ?>
                                    (<a href="<?php echo esc_url(get_edit_post_link($item['result']['read_id'])); ?>">edit read</a>)
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p>New books have no cover yet: open Tools &gt; Find Missing Covers.</p>

        <?php elseif ($preview) : ?>

            <p>
                <strong>Preview, nothing saved yet.</strong>
                Check each row. Titles and authors can be edited (several authors separated by commas;
                the first is the Book's author). Untick a row to leave it out.
            </p>

            <form method="post">
                <?php wp_nonce_field('monthly_reads'); ?>
                <input type="hidden" name="monthly_reads_confirm" value="1">
                <input type="hidden" name="token" value="<?php echo esc_attr($preview['token']); ?>">

                <p>
                    <label>Language Read for every row:
                        <input type="text" name="language" value="English" style="width:140px">
                    </label>
                    (leave blank for none)
                </p>

                <table class="widefat striped" style="max-width:1300px">
                    <thead>
                        <tr>
                            <th>Import</th><th>Title</th><th>Authors</th><th>Book</th><th>Authors found</th>
                            <th>Date</th><th>Rating</th><th>Format</th><th>Review</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview['rows'] as $index => $row) :
                        $plan = $preview['plans'][$index];
                        $skip = $plan['existing'] > 0;
                        $raw  = trim((string) ($row['Star Rating'] ?? ''));
                        ?>
                        <tr<?php echo $plan['suspect'] ? ' style="background:#fcf0f1"' : ''; ?>>
                            <td><input type="checkbox" name="include[<?php echo (int) $index; ?>]" value="1"<?php checked(!$skip); ?>></td>
                            <td><input type="text" name="title[<?php echo (int) $index; ?>]" value="<?php echo esc_attr($row['Title']); ?>" style="width:100%;min-width:200px"></td>
                            <td><input type="text" name="authors[<?php echo (int) $index; ?>]" value="<?php echo esc_attr($row['Authors']); ?>" style="width:100%;min-width:140px"></td>
                            <td>
                                <?php echo $plan['book_id']
                                    ? 'existing #' . (int) $plan['book_id']
                                    : '<strong>new</strong>'; ?>
                                <?php if ($skip) : ?><br><em>read already there</em><?php endif; ?>
                            </td>
                            <td>
                                <?php foreach ($plan['authors'] as $author) : ?>
                                    <?php echo esc_html($author['name']); ?>:
                                    <?php echo $author['id'] ? 'existing' : '<strong>new</strong>'; ?><br>
                                <?php endforeach; ?>
                                <?php if ($plan['suspect']) : ?><em>check the spelling</em><?php endif; ?>
                            </td>
                            <td><?php echo esc_html($plan['date'] !== '' ? $plan['date'] : ($row['Last Date Read'] ?: '-')); ?></td>
                            <td>
                                <?php echo esc_html($plan['rating'] !== '' ? $plan['rating'] : '-'); ?>
                                <?php if ($raw !== '' && $plan['rating'] !== '' && (float) $plan['rating'] !== (float) $raw) : ?>
                                    <br><em>(was <?php echo esc_html($raw); ?>)</em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($plan['format'] !== '' ? $plan['format'] : '-'); ?></td>
                            <td><?php echo $plan['review'] !== '' ? (int) mb_strlen($plan['review']) . ' characters' : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button('Import these rows'); ?>
            </form>

        <?php else : ?>

            <p>Upload the CSV (columns: Title, Authors, ISBN/UID, Format, Read Status, Last Date Read, Star Rating, Review, Tags).
               You will see a preview before anything is saved.</p>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('monthly_reads'); ?>
                <input type="hidden" name="monthly_reads_upload" value="1">
                <input type="file" name="csv" accept=".csv,text/csv">
                <?php submit_button('Upload and preview'); ?>
            </form>

        <?php endif; ?>
    </div>

    <?php
}
