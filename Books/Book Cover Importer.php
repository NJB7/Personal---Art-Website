/* =========================================================
   ONE-TIME IMPORT: BOOK COVERS AS FEATURED IMAGES

   Adds Tools > Import Book Covers (administrators only).

   1. Run the enrichment notebook. It creates covers.zip
      (the cover images plus covers_manifest.csv).
   2. Import your books into WordPress.
   3. Open Tools > Import Book Covers and upload covers.zip.
   4. Leave the page open until it says Finished. It works
      through the books a few at a time so it never times out.

   Each cover is matched to the Book with the same title
   (the Author is used to tell apart books that share a title)
   and set as that Book's Featured Image.

   - Books that already have a Featured Image are left alone,
     so it is safe to run again.
   - To keep storage small, only the Thumbnail and Medium
     sizes are generated for these images.
   - Temporary files are deleted when it finishes. You can
     then deactivate or delete this snippet.
   ========================================================= */

add_action('admin_menu', function () {

    add_management_page(
        'Import Book Covers',
        'Import Book Covers',
        'manage_options',
        'book-cover-import',
        'book_cover_import_page'
    );
});


/* ---------- Helpers ---------- */

function book_cover_import_norm($text) {

    $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $text = str_replace(
        array("\xE2\x80\x99", "\xE2\x80\x98", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xC2\xA0"),
        array("'", "'", '"', '"', ' '),
        $text
    );

    $text = preg_replace('/\s+/u', ' ', trim($text));

    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}


function book_cover_import_remove_dir($dir) {

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


function book_cover_import_clear() {

    $state = get_option('book_cover_import_state');

    if (is_array($state) && !empty($state['dir'])) {
        book_cover_import_remove_dir($state['dir']);
    }

    delete_option('book_cover_import_state');
}


/* ---------- Step 1: upload and unpack the zip ---------- */

function book_cover_import_start() {

    if (!class_exists('ZipArchive')) {
        return 'This server does not have PHP\'s ZipArchive extension, so it cannot open the zip. Ask your host to enable it.';
    }

    if (empty($_FILES['covers_zip']['tmp_name']) || !is_uploaded_file($_FILES['covers_zip']['tmp_name'])) {
        return 'Choose covers.zip first. (If you did, the file may be larger than your server allows for uploads.)';
    }

    $zip = new ZipArchive();

    if ($zip->open($_FILES['covers_zip']['tmp_name']) !== true) {
        return 'That file could not be opened as a zip.';
    }

    $manifest = $zip->getFromName('covers_manifest.csv');

    if ($manifest === false) {
        $zip->close();
        return 'covers_manifest.csv is missing from the zip. Re-run the notebook with the latest version to create it.';
    }

    // Unpack only the jpg files, flattened, into a temporary folder.
    $upload = wp_upload_dir();
    $dir    = trailingslashit($upload['basedir']) . 'book-cover-import-' . wp_generate_password(10, false);

    if (!wp_mkdir_p($dir)) {
        $zip->close();
        return 'Could not create a temporary folder in your uploads directory.';
    }

    $unpacked = array();

    for ($i = 0; $i < $zip->numFiles; $i++) {

        $stat = $zip->statIndex($i);
        $name = basename((string) $stat['name']);

        if (!preg_match('/^[A-Za-z0-9._-]+\.jpg$/', $name) || $stat['size'] > 3 * 1024 * 1024) {
            continue;
        }

        $data = $zip->getFromIndex($i);

        if ($data !== false && file_put_contents(trailingslashit($dir) . $name, $data) !== false) {
            $unpacked[$name] = true;
        }
    }

    $zip->close();

    // Read the manifest.
    $manifest = preg_replace('/^\xEF\xBB\xBF/', '', $manifest);
    $lines    = preg_split('/\r\n|\r|\n/', $manifest);
    $header   = null;
    $rows     = array();

    foreach ($lines as $line) {

        if (trim($line) === '') {
            continue;
        }

        $cells = str_getcsv($line);

        if ($header === null) {
            $header = array_flip($cells);
            continue;
        }

        $file = isset($header['File'], $cells[$header['File']]) ? $cells[$header['File']] : '';

        if ($file === '' || !isset($unpacked[$file])) {
            continue;
        }

        $rows[] = array(
            'file'   => $file,
            'title'  => isset($header['BookTitle'], $cells[$header['BookTitle']]) ? $cells[$header['BookTitle']] : '',
            'author' => isset($header['Author'], $cells[$header['Author']]) ? $cells[$header['Author']] : '',
        );
    }

    if (empty($rows)) {
        book_cover_import_remove_dir($dir);
        return 'No cover images were found in that zip.';
    }

    book_cover_import_clear();

    update_option('book_cover_import_state', array(
        'dir'    => $dir,
        'rows'   => $rows,
        'pos'    => 0,
        'counts' => array('attached' => 0, 'skipped' => 0, 'nomatch' => 0, 'ambiguous' => 0, 'error' => 0),
    ), false);

    return '';
}


/* ---------- Step 2: attach covers in small batches ---------- */

function book_cover_import_maps() {

    global $wpdb;

    $titles = array();

    $books = $wpdb->get_results(
        "SELECT ID, post_title FROM {$wpdb->posts}
         WHERE post_type = 'book' AND post_status NOT IN ('trash', 'auto-draft')"
    );

    foreach ($books as $book) {
        $titles[book_cover_import_norm($book->post_title)][] = (int) $book->ID;
    }

    $authors = array();

    $rows = $wpdb->get_results(
        "SELECT ID, post_title FROM {$wpdb->posts}
         WHERE post_type = 'author' AND post_status NOT IN ('trash', 'auto-draft')"
    );

    foreach ($rows as $row) {
        $authors[(int) $row->ID] = book_cover_import_norm($row->post_title);
    }

    return array($titles, $authors);
}


function book_cover_import_one($row, $titles, $authors, $dir) {

    $label = $row['title'] . ' (' . $row['file'] . ')';
    $path  = trailingslashit($dir) . $row['file'];

    if (!file_exists($path)) {
        return array('error', 'Missing file: ' . $label);
    }

    $candidates = isset($titles[book_cover_import_norm($row['title'])])
        ? $titles[book_cover_import_norm($row['title'])]
        : array();

    if (count($candidates) > 1 && $row['author'] !== '') {

        $wanted  = book_cover_import_norm($row['author']);
        $matched = array();

        foreach ($candidates as $id) {
            $author_id = (int) get_post_meta($id, '_book_author_id', true);
            if (isset($authors[$author_id]) && $authors[$author_id] === $wanted) {
                $matched[] = $id;
            }
        }

        if ($matched) {
            $candidates = $matched;
        }
    }

    if (count($candidates) === 0) {
        return array('nomatch', 'No book with this title: ' . $label);
    }

    if (count($candidates) > 1) {
        return array('ambiguous', 'More than one book with this title, left alone: ' . $label);
    }

    $book_id = $candidates[0];

    if (has_post_thumbnail($book_id)) {
        return array('skipped', 'Already has a Featured Image: ' . $row['title']);
    }

    $slug = sanitize_title($row['title']);

    $file_array = array(
        'name'     => ($slug !== '' ? substr($slug, 0, 80) : 'book-cover-' . $book_id) . '.jpg',
        'tmp_name' => $path,
    );

    $attachment_id = media_handle_sideload($file_array, $book_id, $row['title']);

    if (is_wp_error($attachment_id)) {
        @unlink($path);
        return array('error', 'Could not add the image for ' . $label . ': ' . $attachment_id->get_error_message());
    }

    update_post_meta($attachment_id, '_wp_attachment_image_alt', 'Cover of ' . $row['title']);
    set_post_thumbnail($book_id, $attachment_id);

    return array('attached', 'Attached: ' . $row['title']);
}


add_action('wp_ajax_book_cover_import_batch', function () {

    check_ajax_referer('book_cover_import', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not allowed.', 403);
    }

    $state = get_option('book_cover_import_state');

    if (!is_array($state) || empty($state['rows'])) {
        wp_send_json_error('There is nothing to import. Upload covers.zip first.');
    }

    @set_time_limit(120);

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    // Keep storage small: only Thumbnail and Medium copies are made.
    add_filter('intermediate_image_sizes_advanced', function ($sizes) {
        return array_intersect_key($sizes, array('thumbnail' => 1, 'medium' => 1));
    });

    list($titles, $authors) = book_cover_import_maps();

    $total = count($state['rows']);
    $log   = array();
    $end   = min($total, $state['pos'] + 8);

    while ($state['pos'] < $end) {

        $result = book_cover_import_one($state['rows'][$state['pos']], $titles, $authors, $state['dir']);

        $state['counts'][$result[0]]++;
        $state['pos']++;

        if ($result[0] !== 'attached' && $result[0] !== 'skipped') {
            $log[] = $result[1];
        }
    }

    $done = $state['pos'] >= $total;

    if ($done) {
        book_cover_import_clear();
    } else {
        update_option('book_cover_import_state', $state, false);
    }

    wp_send_json_success(array(
        'done'   => $done,
        'pos'    => $state['pos'],
        'total'  => $total,
        'counts' => $state['counts'],
        'log'    => $log,
    ));
});


/* ---------- The Tools page ---------- */

function book_cover_import_page() {

    if (!current_user_can('manage_options')) {
        return;
    }

    $error = '';

    if (isset($_POST['book_cover_import_cancel']) && check_admin_referer('book_cover_import_cancel')) {
        book_cover_import_clear();
    }

    if (isset($_POST['book_cover_import_upload']) && check_admin_referer('book_cover_import_upload')) {
        $error = book_cover_import_start();
    }

    $state = get_option('book_cover_import_state');
    ?>

    <div class="wrap">
        <h1>Import Book Covers</h1>

        <?php if ($error !== '') : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <?php if (is_array($state) && !empty($state['rows'])) : ?>

            <p>
                <strong id="bci-status">Starting...</strong>
                Leave this page open until it says Finished.
            </p>
            <progress id="bci-bar" value="<?php echo (int) $state['pos']; ?>" max="<?php echo count($state['rows']); ?>" style="width:100%;max-width:500px"></progress>
            <pre id="bci-log" style="max-height:300px;overflow:auto;background:#fff;padding:10px;border:1px solid #ccd0d4"></pre>

            <form method="post">
                <?php wp_nonce_field('book_cover_import_cancel'); ?>
                <input type="hidden" name="book_cover_import_cancel" value="1">
                <?php submit_button('Cancel and delete the temporary files', 'secondary'); ?>
            </form>

            <script>
            (function () {
                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var nonce   = <?php echo wp_json_encode(wp_create_nonce('book_cover_import')); ?>;
                var status  = document.getElementById('bci-status');
                var bar     = document.getElementById('bci-bar');
                var logBox  = document.getElementById('bci-log');

                function step() {
                    var body = new URLSearchParams({action: 'book_cover_import_batch', nonce: nonce});

                    fetch(ajaxUrl, {method: 'POST', body: body, credentials: 'same-origin'})
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (!res.success) {
                                status.textContent = 'Stopped: ' + res.data;
                                return;
                            }
                            var d = res.data, c = d.counts;
                            bar.max = d.total;
                            bar.value = d.pos;
                            if (d.log.length) {
                                logBox.textContent += d.log.join('\n') + '\n';
                            }
                            var summary = c.attached + ' attached, ' + c.skipped + ' already had an image, ' +
                                          c.nomatch + ' with no matching book, ' + c.ambiguous + ' ambiguous, ' + c.error + ' errors';
                            status.textContent = (d.done ? 'Finished. ' : d.pos + ' of ' + d.total + '. ') + summary + '.';
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
                Upload the <code>covers.zip</code> created by the enrichment notebook. Each cover becomes the
                Featured Image of the Book with the same title. Books that already have one are skipped.
            </p>

            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('book_cover_import_upload'); ?>
                <input type="hidden" name="book_cover_import_upload" value="1">
                <input type="file" name="covers_zip" accept=".zip">
                <?php submit_button('Upload and start'); ?>
            </form>

            <p>
                <small>
                    Only upload this after your books have been imported, and before you change any
                    book titles, because the match is by title.
                </small>
            </p>

        <?php endif; ?>
    </div>

    <?php
}
