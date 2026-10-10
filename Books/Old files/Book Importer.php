<?php

/* =========================================================
   ONE-TIME IMPORT: LIBRARY FROM THE REVIEWED SPREADSHEET

   Adds Tools > Import Library (administrators only).

   Reads wordpress_book_database_import_review.xlsx directly
   (no CSV conversion needed) and creates:
   - Authors   (Authors sheet)
   - Books     (Books sheet, linked to their Author)
   - Read Instances (Read Instances sheet, linked to their Book,
                     with the Review as the post content)

   How to use it:
   1. Finish reviewing the spreadsheet in Excel and save it.
      Delete the Read Me and Problems sheets if you like; only
      Authors, Books and Read Instances are used.
   2. Open Tools > Import Library, upload the .xlsx and check
      the counts it shows.
   3. Click Start and leave the page open until it says
      Finished. It works through the rows in small batches.
   4. Afterwards, upload covers.zip with Tools > Import Book
      Covers, then deactivate or delete both snippets.

   Safe to re-run: every imported record remembers which
   spreadsheet row it came from, and rows already imported
   are skipped. If it stops part-way, upload the same file
   again and click Start.

   Not imported: BIPOC, LGBTQ+ and Disabled/Neurodiverse
   (to fill in later), Genre, Country Details, the Review
   Notes columns, the Books sheet's Country column and the
   Read Instances' Year / Read columns.

   Author ID list: the Import Library page has a button to
   download a CSV that pairs each spreadsheet ImportAuthorID
   with the author's WordPress ID, name and edit link, so the
   skipped fields can be matched and added later.
   ========================================================= */

add_action('admin_menu', function () {

    add_management_page(
        'Import Library',
        'Import Library',
        'manage_options',
        'book-library-import',
        'book_library_import_page'
    );
});


/* ---------- Reading the .xlsx (an .xlsx is a zip of XML files) ---------- */

function book_library_import_xml($zip, $name) {

    $xml = $zip->getFromName($name);

    if ($xml === false) {
        return false;
    }

    // Drop the default namespace so plain XPath works.
    $xml = preg_replace('/\sxmlns="[^"]*"/', '', $xml, 1);

    return simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
}


function book_library_import_col_index($ref) {

    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n       = 0;

    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }

    return $n - 1;
}


function book_library_import_read_xlsx($path) {

    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        return 'That file could not be opened as an .xlsx workbook.';
    }

    // Shared strings.
    $shared = array();
    $sst    = book_library_import_xml($zip, 'xl/sharedStrings.xml');

    if ($sst) {
        foreach ($sst->si as $si) {
            $text = '';
            foreach ($si->xpath('.//t') as $t) {
                $text .= (string) $t;
            }
            $shared[] = $text;
        }
    }

    // Sheet names -> files.
    $workbook = book_library_import_xml($zip, 'xl/workbook.xml');
    $rels     = book_library_import_xml($zip, 'xl/_rels/workbook.xml.rels');

    if (!$workbook || !$rels) {
        $zip->close();
        return 'That does not look like an Excel workbook.';
    }

    $targets = array();
    foreach ($rels->Relationship as $rel) {
        $targets[(string) $rel['Id']] = (string) $rel['Target'];
    }

    $wanted = array('Authors', 'Books', 'Read Instances');
    $sheets = array();

    foreach ($workbook->sheets->sheet as $sheet) {

        $name = (string) $sheet['name'];

        if (!in_array($name, $wanted, true)) {
            continue;
        }

        $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid   = isset($attrs['id']) ? (string) $attrs['id'] : '';

        if (!isset($targets[$rid])) {
            continue;
        }

        $target = ltrim($targets[$rid], '/');
        $sheets[$name] = (strpos($target, 'xl/') === 0) ? $target : 'xl/' . $target;
    }

    foreach ($wanted as $name) {
        if (!isset($sheets[$name])) {
            $zip->close();
            return 'The workbook has no "' . $name . '" sheet.';
        }
    }

    $data = array();

    foreach ($sheets as $name => $file) {

        $xml = book_library_import_xml($zip, $file);

        if (!$xml) {
            $zip->close();
            return 'Could not read the "' . $name . '" sheet.';
        }

        $header = null;
        $rows   = array();

        foreach ($xml->sheetData->row as $row) {

            $cells = array();

            foreach ($row->c as $c) {

                $type  = (string) $c['t'];
                $index = book_library_import_col_index((string) $c['r']);

                if ($type === 's') {
                    $value = isset($shared[(int) $c->v]) ? $shared[(int) $c->v] : '';
                } elseif ($type === 'inlineStr') {
                    $value = '';
                    foreach ($c->xpath('.//t') as $t) {
                        $value .= (string) $t;
                    }
                } else {
                    $value = (string) $c->v;
                }

                $cells[$index] = trim($value);
            }

            if ($header === null) {
                $header = $cells;
                continue;
            }

            $assoc = array();

            foreach ($header as $index => $label) {
                if ($label !== '' && isset($cells[$index]) && $cells[$index] !== '') {
                    $assoc[$label] = $cells[$index];
                }
            }

            if ($assoc) {
                $rows[] = $assoc;
            }
        }

        $data[$name] = $rows;
    }

    $zip->close();

    return $data;
}


/* ---------- Value helpers ---------- */

function book_library_import_id($value) {

    // "1078", "1078.0" and 1078 all mean the same ID.
    $value = trim((string) $value);

    return preg_replace('/\.0+$/', '', $value);
}


function book_library_import_choice($value, $allowed) {

    foreach ($allowed as $option) {
        if (strcasecmp((string) $value, $option) === 0) {
            return $option;
        }
    }

    return '';
}


function book_library_import_date($value) {

    $value = trim((string) $value);

    // A date Excel has turned into a serial number.
    if (is_numeric($value) && $value > 20000 && $value < 80000) {
        return gmdate('Y-m-d', (int) round(((float) $value - 25569) * 86400));
    }

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    return '';
}


function book_library_import_terms($value) {

    $terms = preg_split('/\s*[;,|]\s*/', (string) $value);

    return array_values(array_filter(array_map('trim', $terms), 'strlen'));
}


function book_library_import_flag($value) {

    return in_array(strtolower(trim((string) $value)), array('1', '1.0', 'true', 'yes', 'y'), true) ? '1' : '0';
}


function book_library_import_remove_dir($dir) {

    if (!$dir || !is_dir($dir)) {
        return;
    }

    foreach ((array) glob(trailingslashit($dir) . '*') as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    @rmdir($dir);
}


function book_library_import_clear() {

    $state = get_option('book_library_import_state');

    if (is_array($state) && !empty($state['dir'])) {
        book_library_import_remove_dir($state['dir']);
    }

    delete_option('book_library_import_state');
}


/* ---------- Step 1: upload ---------- */

function book_library_import_start() {

    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
        return 'This server is missing PHP\'s ZipArchive or SimpleXML extension, which are needed to read an .xlsx. Ask your host to enable them.';
    }

    if (empty($_FILES['library_xlsx']['tmp_name']) || !is_uploaded_file($_FILES['library_xlsx']['tmp_name'])) {
        return 'Choose the .xlsx file first.';
    }

    @set_time_limit(120);

    $data = book_library_import_read_xlsx($_FILES['library_xlsx']['tmp_name']);

    if (is_string($data)) {
        return $data;
    }

    $upload = wp_upload_dir();
    $dir    = trailingslashit($upload['basedir']) . 'book-library-import-' . wp_generate_password(12, false);

    if (!wp_mkdir_p($dir)) {
        return 'Could not create a temporary folder in your uploads directory.';
    }

    if (file_put_contents(trailingslashit($dir) . 'data.json', wp_json_encode($data)) === false) {
        book_library_import_remove_dir($dir);
        return 'Could not save the spreadsheet data to the temporary folder.';
    }

    book_library_import_clear();

    update_option('book_library_import_state', array(
        'dir'     => $dir,
        'counts'  => array(
            'authors' => count($data['Authors']),
            'books'   => count($data['Books']),
            'reads'   => count($data['Read Instances']),
        ),
        'pos'     => 0,
        'started' => false,
        'done'    => array('created' => 0, 'skipped' => 0, 'problems' => 0),
    ), false);

    return '';
}


/* ---------- Step 2: import in batches ---------- */

// Remember-which-row-it-came-from maps: spreadsheet ID => WordPress ID.
function book_library_import_map($meta_key) {

    global $wpdb;

    $map  = array();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
        $meta_key
    ));

    foreach ($rows as $row) {
        $map[$row->meta_value] = (int) $row->post_id;
    }

    return $map;
}


add_action('wp_ajax_book_library_import_batch', function () {

    check_ajax_referer('book_library_import', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not allowed.', 403);
    }

    $state = get_option('book_library_import_state');

    if (!is_array($state) || empty($state['started'])) {
        wp_send_json_error('Nothing is waiting to be imported. Upload the spreadsheet and click Start.');
    }

    $data = json_decode((string) @file_get_contents(trailingslashit($state['dir']) . 'data.json'), true);

    if (!is_array($data)) {
        wp_send_json_error('The temporary data file is missing. Upload the spreadsheet again.');
    }

    @set_time_limit(120);
    wp_defer_term_counting(true);

    $n_authors = count($data['Authors']);
    $n_books   = count($data['Books']);
    $n_reads   = count($data['Read Instances']);
    $total     = $n_authors + $n_books + $n_reads;

    $authors_map = book_library_import_map('_import_author_id');
    $books_map   = book_library_import_map('_import_book_id');
    $reads_map   = book_library_import_map('_import_read_row');
    $read_keys   = null; // built the first time a Read Instance row is reached

    $genders  = array('Male', 'Female', 'Other');
    $statuses = array('Read', 'Currently Reading', 'To Read', 'DNF');
    $types    = array('Fiction', 'Nonfiction');
    $formats  = array('Print', 'eBook', 'Audiobook');

    $log = array();
    $end = min($total, $state['pos'] + 60);

    while ($state['pos'] < $end) {

        $pos = $state['pos'];
        $state['pos']++;

        /* ----- Authors ----- */

        if ($pos < $n_authors) {

            $row  = $data['Authors'][$pos];
            $key  = book_library_import_id(isset($row['ImportAuthorID']) ? $row['ImportAuthorID'] : '');
            $name = isset($row['Author']) ? $row['Author'] : '';

            if ($key === '' || $name === '') {
                $state['done']['problems']++;
                $log[] = 'Authors row ' . ($pos + 2) . ': missing ID or name, skipped.';
                continue;
            }

            if (isset($authors_map[$key])) {
                $state['done']['skipped']++;
                continue;
            }

            $id = wp_insert_post(array(
                'post_type'   => 'author',
                'post_title'  => wp_slash($name),
                'post_status' => 'publish',
            ), true);

            if (is_wp_error($id) || !$id) {
                $state['done']['problems']++;
                $log[] = 'Could not create author "' . $name . '".';
                continue;
            }

            $authors_map[$key] = (int) $id;

            update_post_meta($id, '_import_author_id', $key);

            $gender = isset($row['Gender']) ? book_library_import_choice($row['Gender'], $genders) : '';

            if ($gender !== '') {
                update_post_meta($id, '_author_gender', $gender);
            }

            // BIPOC, LGBTQ+ and Disabled/Neurodiverse are deliberately not imported yet.
            // Use the author ID list (Tools > Import Library) to add them later.

            if (!empty($row['Country'])) {
                wp_set_object_terms($id, array($row['Country']), 'author_country');
            }

            $state['done']['created']++;
            continue;
        }

        /* ----- Books ----- */

        if ($pos < $n_authors + $n_books) {

            $row   = $data['Books'][$pos - $n_authors];
            $key   = book_library_import_id(isset($row['ImportBookID']) ? $row['ImportBookID'] : '');
            $title = isset($row['BookTitle']) ? $row['BookTitle'] : '';

            if ($key === '' || $title === '') {
                $state['done']['problems']++;
                $log[] = 'Books row ' . ($pos - $n_authors + 2) . ': missing ID or title, skipped.';
                continue;
            }

            if (isset($books_map[$key])) {
                $state['done']['skipped']++;
                continue;
            }

            $id = wp_insert_post(array(
                'post_type'   => 'book',
                'post_title'  => wp_slash($title),
                'post_status' => 'publish',
            ), true);

            if (is_wp_error($id) || !$id) {
                $state['done']['problems']++;
                $log[] = 'Could not create book "' . $title . '".';
                continue;
            }

            $books_map[$key] = (int) $id;

            update_post_meta($id, '_import_book_id', $key);

            $author_key = book_library_import_id(isset($row['ImportAuthorID']) ? $row['ImportAuthorID'] : '');

            if ($author_key !== '' && isset($authors_map[$author_key])) {
                update_post_meta($id, '_book_author_id', $authors_map[$author_key]);
            } elseif ($author_key !== '') {
                $log[] = 'Book "' . $title . '": author ID ' . $author_key . ' is not on the Authors sheet.';
            }

            $status = isset($row['Status']) ? book_library_import_choice($row['Status'], $statuses) : '';
            update_post_meta($id, '_book_status', $status !== '' ? $status : 'Read');

            $type = isset($row['Fiction/Nonfiction']) ? book_library_import_choice($row['Fiction/Nonfiction'], $types) : '';

            if ($type !== '') {
                update_post_meta($id, '_book_fiction_nonfiction', $type);
            }

            $pages = isset($row['Pages']) ? (int) round((float) $row['Pages']) : 0;

            if ($pages > 0) {
                update_post_meta($id, '_book_pages', $pages);
            }

            $year = isset($row['PublicationYear']) ? (int) round((float) $row['PublicationYear']) : 0;

            if ($year > 0) {
                update_post_meta($id, '_book_publication_year', $year);
            }

            if (!empty($row['OriginalLanguage'])) {
                wp_set_object_terms($id, book_library_import_terms($row['OriginalLanguage']), 'book_language');
            }

            if (!empty($row['Genre'])) {
                wp_set_object_terms($id, book_library_import_terms($row['Genre']), 'book_genre');
            }

            $state['done']['created']++;
            continue;
        }

        /* ----- Read Instances ----- */

        $index = $pos - $n_authors - $n_books;
        $row   = $data['Read Instances'][$index];

        // A key built from the row's own details (not its position), so the same read
        // is recognised even if the spreadsheet is sorted, filtered or trimmed down.
        // Identical rows (same book, date, rating, format, language) get -1, -2, ...
        if ($read_keys === null) {

            $read_keys = array();
            $counts    = array();

            foreach ($data['Read Instances'] as $i => $r) {

                $sig = md5(implode('|', array(
                    book_library_import_id(isset($r['Book']) ? $r['Book'] : ''),
                    isset($r['DateRead']) ? $r['DateRead'] : '',
                    isset($r['Rating']) ? $r['Rating'] : '',
                    isset($r['Format']) ? $r['Format'] : '',
                    isset($r['LanguageRead']) ? $r['LanguageRead'] : '',
                )));

                $counts[$sig] = isset($counts[$sig]) ? $counts[$sig] + 1 : 1;
                $read_keys[$i] = 'r' . substr($sig, 0, 16) . '-' . $counts[$sig];
            }
        }

        $key = $read_keys[$index];

        if (isset($reads_map[$key])) {
            $state['done']['skipped']++;
            continue;
        }

        $book_key = book_library_import_id(isset($row['Book']) ? $row['Book'] : '');

        if ($book_key === '' || !isset($books_map[$book_key])) {
            $state['done']['problems']++;
            $log[] = 'Read Instances row ' . ($index + 2) . ' ("' . (isset($row['BookTitle']) ? $row['BookTitle'] : '') . '"): book ID ' . $book_key . ' was not found, skipped.';
            continue;
        }

        $book_id = $books_map[$book_key];

        $title = get_post_field('post_title', $book_id);

        // Date first, because the title is "Read" plus the date, e.g. "Read Oct 02, 2026".
        $date       = !empty($row['DateRead']) ? book_library_import_date($row['DateRead']) : '';
        $read_title = 'Read';

        if ($date !== '') {
            $date_object = DateTime::createFromFormat('!Y-m-d', $date, wp_timezone());

            if ($date_object) {
                $read_title = 'Read ' . wp_date('M d, Y', $date_object->getTimestamp(), wp_timezone());
            }
        }

        $id = wp_insert_post(array(
            'post_type'    => 'read_instance',
            'post_title'   => wp_slash($read_title),
            'post_content' => wp_slash(isset($row['Review']) ? $row['Review'] : ''),
            'post_status'  => 'publish',
        ), true);

        if (is_wp_error($id) || !$id) {
            $state['done']['problems']++;
            $log[] = 'Could not create a Read Instance for "' . $title . '" (row ' . ($index + 2) . ').';
            continue;
        }

        update_post_meta($id, '_import_read_row', $key);
        update_post_meta($id, '_read_instance_book_id', $book_id);

        if (!empty($row['DateRead'])) {

            if ($date !== '') {
                update_post_meta($id, '_read_instance_date_read', $date);
            } else {
                $log[] = 'Read Instances row ' . ($index + 2) . ' ("' . $title . '"): date "' . $row['DateRead'] . '" is not YYYY-MM-DD, left blank.';
            }
        }

        if (!empty($row['Rating'])) {

            $rating = (float) $row['Rating'];

            if ($rating >= 0.5 && $rating <= 5 && fmod($rating * 2, 1) == 0) {
                update_post_meta($id, '_read_instance_rating', $rating);
            }
        }

        $format = isset($row['Format']) ? book_library_import_choice($row['Format'], $formats) : '';

        if ($format !== '') {
            update_post_meta($id, '_read_instance_format', $format);
        }

        if (!empty($row['LanguageRead'])) {
            wp_set_object_terms($id, book_library_import_terms($row['LanguageRead']), 'book_language');
        }

        $state['done']['created']++;
    }

    wp_defer_term_counting(false);

    $finished = $state['pos'] >= $total;

    if ($finished) {
        $result = $state['done'];
        book_library_import_clear();
        $state['done'] = $result;
    } else {
        update_option('book_library_import_state', $state, false);
    }

    wp_send_json_success(array(
        'done'  => $finished,
        'pos'   => $state['pos'],
        'total' => $total,
        'stats' => $state['done'],
        'log'   => $log,
    ));
});


/* ---------- Author ID list (CSV) ---------- */

// Pairs each spreadsheet ImportAuthorID with the WordPress author ID, so fields
// that were left out of the import can be matched and added later.
add_action('admin_post_book_library_author_ids', function () {

    if (!current_user_can('manage_options')) {
        wp_die('Not allowed.', 403);
    }

    check_admin_referer('book_library_author_ids');

    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_title, m.meta_value AS import_id
         FROM {$wpdb->postmeta} m
         INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
         WHERE m.meta_key = '_import_author_id'
           AND p.post_type = 'author'
           AND p.post_status NOT IN ('trash', 'auto-draft')
         ORDER BY CAST(m.meta_value AS UNSIGNED), p.ID"
    );

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="author-ids.csv"');

    $out = fopen('php://output', 'w');

    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads accents correctly

    fputcsv($out, array('ImportAuthorID', 'WordPressAuthorID', 'Author', 'EditLink'));

    foreach ($rows as $row) {

        $name = (string) $row->post_title;

        // Stop spreadsheet programs treating a name as a formula.
        if ($name !== '' && strpos('=+-@', $name[0]) !== false) {
            $name = "'" . $name;
        }

        fputcsv($out, array(
            $row->import_id,
            $row->ID,
            $name,
            admin_url('post.php?post=' . (int) $row->ID . '&action=edit'),
        ));
    }

    fclose($out);
    exit;
});


/* ---------- The Tools page ---------- */

function book_library_import_page() {

    if (!current_user_can('manage_options')) {
        return;
    }

    $error = '';

    if (isset($_POST['book_library_import_cancel']) && check_admin_referer('book_library_import_cancel')) {
        book_library_import_clear();
    }

    if (isset($_POST['book_library_import_upload']) && check_admin_referer('book_library_import_upload')) {
        $error = book_library_import_start();
    }

    $state = get_option('book_library_import_state');

    if (is_array($state) && !$state['started'] && isset($_POST['book_library_import_go']) && check_admin_referer('book_library_import_go')) {
        $state['started'] = true;
        update_option('book_library_import_state', $state, false);
    }
    ?>

    <div class="wrap">
        <h1>Import Library</h1>

        <?php if ($error !== '') : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <?php if (is_array($state) && empty($state['started'])) :

            $existing = array();

            foreach (array('author' => 'Authors', 'book' => 'Books', 'read_instance' => 'Read Instances') as $type => $label) {
                $count = wp_count_posts($type);
                $existing[$label] = (int) $count->publish + (int) $count->draft + (int) $count->private;
            }
            ?>

            <p>
                The spreadsheet contains
                <strong><?php echo (int) $state['counts']['authors']; ?> authors</strong>,
                <strong><?php echo (int) $state['counts']['books']; ?> books</strong> and
                <strong><?php echo (int) $state['counts']['reads']; ?> read instances</strong>.
            </p>

            <?php if (array_sum($existing) > 0) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <strong>Your site already has records:</strong>
                        <?php
                        $parts = array();
                        foreach ($existing as $label => $count) {
                            $parts[] = $count . ' ' . $label;
                        }
                        echo esc_html(implode(', ', $parts));
                        ?>.
                        This import does not check for them, so anything also in the spreadsheet will be
                        created a second time. Only continue if those records are test data you will delete,
                        or are not in the spreadsheet.
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" style="display:inline-block">
                <?php wp_nonce_field('book_library_import_go'); ?>
                <input type="hidden" name="book_library_import_go" value="1">
                <?php submit_button('Start import', 'primary', 'submit', false); ?>
            </form>

            <form method="post" style="display:inline-block">
                <?php wp_nonce_field('book_library_import_cancel'); ?>
                <input type="hidden" name="book_library_import_cancel" value="1">
                <?php submit_button('Cancel', 'secondary', 'submit', false); ?>
            </form>

        <?php elseif (is_array($state)) : ?>

            <p>
                <strong id="bli-status">Starting...</strong>
                Leave this page open until it says Finished.
            </p>
            <progress id="bli-bar" value="<?php echo (int) $state['pos']; ?>" max="<?php echo (int) array_sum($state['counts']); ?>" style="width:100%;max-width:500px"></progress>
            <pre id="bli-log" style="max-height:300px;overflow:auto;background:#fff;padding:10px;border:1px solid #ccd0d4"></pre>

            <script>
            (function () {
                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var nonce   = <?php echo wp_json_encode(wp_create_nonce('book_library_import')); ?>;
                var status  = document.getElementById('bli-status');
                var bar     = document.getElementById('bli-bar');
                var logBox  = document.getElementById('bli-log');

                function step() {
                    var body = new URLSearchParams({action: 'book_library_import_batch', nonce: nonce});

                    fetch(ajaxUrl, {method: 'POST', body: body, credentials: 'same-origin'})
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (!res.success) {
                                status.textContent = 'Stopped: ' + res.data;
                                return;
                            }
                            var d = res.data, s = d.stats;
                            bar.max = d.total;
                            bar.value = d.pos;
                            if (d.log.length) {
                                logBox.textContent += d.log.join('\n') + '\n';
                            }
                            status.textContent = (d.done ? 'Finished. ' : d.pos + ' of ' + d.total + '. ') +
                                s.created + ' created, ' + s.skipped + ' already imported, ' + s.problems + ' problems.';
                            if (!d.done) { step(); }
                        })
                        .catch(function () {
                            status.textContent = 'Connection problem. Reload this page to carry on where it stopped.';
                        });
                }

                step();
            })();
            </script>

        <?php else : ?>

            <p>
                Upload the reviewed <code>wordpress_book_database_import_review.xlsx</code> (saved from Excel).
                It creates Authors, Books and Read Instances and links them together.
            </p>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('book_library_import_upload'); ?>
                <input type="hidden" name="book_library_import_upload" value="1">
                <input type="file" name="library_xlsx" accept=".xlsx">
                <?php submit_button('Upload and check'); ?>
            </form>

        <?php endif; ?>

        <?php
        global $wpdb;

        $imported_authors = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_import_author_id'"
        );

        if ($imported_authors > 0) :
            $csv_url = wp_nonce_url(admin_url('admin-post.php?action=book_library_author_ids'), 'book_library_author_ids');
            ?>
            <hr>
            <p>
                <a class="button" href="<?php echo esc_url($csv_url); ?>">Download author ID list (CSV)</a>
                <br>
                <small>
                    Pairs each ImportAuthorID from the spreadsheet with the author's WordPress ID, name and
                    edit link, for adding the fields that were left out of the import (BIPOC, LGBTQ+,
                    Disabled/Neurodiverse).
                </small>
            </p>
        <?php endif; ?>
    </div>

    <?php
}
