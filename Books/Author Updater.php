<?php

/* =========================================================
   ONE-TIME IMPORT: AUTHOR DETAILS (BIPOC, LGBTQ+, Disabled)

   Adds Tools > Import Author Details (administrators only).

   Use this after Tools > Import Library, once you have filled in
   the BIPOC, LGBTQ+ and Disabled/Neurodiverse columns on the
   Authors sheet of the spreadsheet.

   1. In Excel, fill in the three columns on the Authors sheet
      (1 = yes, 0 = no) and save. Leave the ImportAuthorID column
      exactly as it is: it is how each row finds its author.
   2. Open Tools > Import Author Details and upload the .xlsx.
   3. Read the report it shows.

   Rules:
   - A filled-in cell sets that field on the author.
   - A blank cell leaves the author's field alone, so nothing you
     ticked by hand is wiped.
   - Rows whose author is not on the site are listed in the report.
   - Safe to run as many times as you like.

   Only these three fields are touched. This snippet is
   self-contained, so it works even after you remove the
   Import Library snippet. Delete it when you are done.
   ========================================================= */

add_action('admin_menu', function () {

    add_management_page(
        'Import Author Details',
        'Import Author Details',
        'manage_options',
        'author-details-import',
        'author_details_import_page'
    );
});


/* ---------- Reading the Authors sheet of an .xlsx ---------- */

function author_details_import_xml($zip, $name) {

    $xml = $zip->getFromName($name);

    if ($xml === false) {
        return false;
    }

    // Drop the default namespace so plain XPath works.
    $xml = preg_replace('/\sxmlns="[^"]*"/', '', $xml, 1);

    return simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
}


function author_details_import_col_index($ref) {

    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n       = 0;

    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }

    return $n - 1;
}


// Returns the rows of the Authors sheet (each an array keyed by column
// heading), or an error message as a string.
function author_details_import_read_authors($path) {

    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        return 'That file could not be opened as an .xlsx workbook.';
    }

    $shared = array();
    $sst    = author_details_import_xml($zip, 'xl/sharedStrings.xml');

    if ($sst) {
        foreach ($sst->si as $si) {
            $text = '';
            foreach ($si->xpath('.//t') as $t) {
                $text .= (string) $t;
            }
            $shared[] = $text;
        }
    }

    $workbook = author_details_import_xml($zip, 'xl/workbook.xml');
    $rels     = author_details_import_xml($zip, 'xl/_rels/workbook.xml.rels');

    if (!$workbook || !$rels) {
        $zip->close();
        return 'That does not look like an Excel workbook.';
    }

    $targets = array();
    foreach ($rels->Relationship as $rel) {
        $targets[(string) $rel['Id']] = (string) $rel['Target'];
    }

    $file = '';

    foreach ($workbook->sheets->sheet as $sheet) {

        if ((string) $sheet['name'] !== 'Authors') {
            continue;
        }

        $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid   = isset($attrs['id']) ? (string) $attrs['id'] : '';

        if (isset($targets[$rid])) {
            $target = ltrim($targets[$rid], '/');
            $file   = (strpos($target, 'xl/') === 0) ? $target : 'xl/' . $target;
        }
    }

    if ($file === '') {
        $zip->close();
        return 'The workbook has no "Authors" sheet.';
    }

    $xml = author_details_import_xml($zip, $file);
    $zip->close();

    if (!$xml) {
        return 'Could not read the "Authors" sheet.';
    }

    $header = null;
    $rows   = array();

    foreach ($xml->sheetData->row as $row) {

        $cells = array();

        foreach ($row->c as $c) {

            $type  = (string) $c['t'];
            $index = author_details_import_col_index((string) $c['r']);

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

    return $rows;
}


/* ---------- Applying the values ---------- */

// '1' for yes, '0' for no, or null when the cell is blank or not understood.
function author_details_import_flag($value) {

    $value = strtolower(trim((string) $value));

    if (in_array($value, array('1', '1.0', 'true', 'yes', 'y'), true)) {
        return '1';
    }

    if (in_array($value, array('0', '0.0', 'false', 'no', 'n'), true)) {
        return '0';
    }

    return null;
}


function author_details_import_run($path) {

    global $wpdb;

    @set_time_limit(300);

    $rows = author_details_import_read_authors($path);

    if (is_string($rows)) {
        return $rows;
    }

    // Spreadsheet author ID => WordPress author ID.
    $map  = array();
    $meta = $wpdb->get_results(
        "SELECT m.post_id, m.meta_value
         FROM {$wpdb->postmeta} m
         INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
         WHERE m.meta_key = '_import_author_id'
           AND p.post_type = 'author'
           AND p.post_status NOT IN ('trash', 'auto-draft')"
    );

    foreach ($meta as $row) {
        $map[$row->meta_value] = (int) $row->post_id;
    }

    if (empty($map)) {
        return 'No authors on this site came from the spreadsheet import, so there is nothing to match. Run Tools > Import Library first.';
    }

    $fields = array(
        'BIPOC'                 => '_author_bipoc',
        'LGBTQ+'                => '_author_lgbtq',
        'Disabled/Neurodiverse' => '_author_disabled_neurodivergent',
    );

    $report = array(
        'rows'       => count($rows),
        'authors'    => 0,   // authors that had at least one change
        'changed'    => 0,   // individual field values changed
        'unchanged'  => 0,   // values already correct
        'blank'      => 0,   // rows with nothing filled in
        'missing'    => array(),
        'unreadable' => array(),
    );

    foreach ($rows as $index => $row) {

        $key = isset($row['ImportAuthorID']) ? preg_replace('/\.0+$/', '', $row['ImportAuthorID']) : '';
        $nm  = isset($row['Author']) ? $row['Author'] : '';

        // Any of the three fields filled in?
        $any = false;

        foreach ($fields as $column => $meta_key) {
            if (isset($row[$column])) {
                $any = true;
            }
        }

        if (!$any) {
            $report['blank']++;
            continue;
        }

        if ($key === '' || !isset($map[$key])) {
            $report['missing'][] = 'Row ' . ($index + 2) . ': ' . ($nm !== '' ? $nm : '(no name)') . ' (ID ' . ($key !== '' ? $key : 'blank') . ')';
            continue;
        }

        $post_id = $map[$key];
        $touched = false;

        foreach ($fields as $column => $meta_key) {

            if (!isset($row[$column])) {
                continue;
            }

            $value = author_details_import_flag($row[$column]);

            if ($value === null) {
                $report['unreadable'][] = 'Row ' . ($index + 2) . ': ' . ($nm !== '' ? $nm : 'ID ' . $key) . ' - "' . $row[$column] . '" in ' . $column . ' is not 1 or 0, left alone.';
                continue;
            }

            if ((string) get_post_meta($post_id, $meta_key, true) === $value) {
                $report['unchanged']++;
                continue;
            }

            update_post_meta($post_id, $meta_key, $value);
            $report['changed']++;
            $touched = true;
        }

        if ($touched) {
            $report['authors']++;
        }
    }

    return $report;
}


/* ---------- The Tools page ---------- */

function author_details_import_page() {

    if (!current_user_can('manage_options')) {
        return;
    }

    $result = null;

    if (isset($_POST['author_details_import']) && check_admin_referer('author_details_import')) {

        if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
            $result = 'This server is missing PHP\'s ZipArchive or SimpleXML extension, which are needed to read an .xlsx. Ask your host to enable them.';
        } elseif (empty($_FILES['authors_xlsx']['tmp_name']) || !is_uploaded_file($_FILES['authors_xlsx']['tmp_name'])) {
            $result = 'Choose the .xlsx file first.';
        } else {
            $result = author_details_import_run($_FILES['authors_xlsx']['tmp_name']);
        }
    }
    ?>

    <div class="wrap">
        <h1>Import Author Details</h1>

        <?php if (is_string($result)) : ?>

            <div class="notice notice-error"><p><?php echo esc_html($result); ?></p></div>

        <?php elseif (is_array($result)) : ?>

            <div class="notice notice-success">
                <p><strong>Finished.</strong></p>
                <ul>
                    <li><?php echo (int) $result['authors']; ?> author(s) updated (<?php echo (int) $result['changed']; ?> field values changed).</li>
                    <li><?php echo (int) $result['unchanged']; ?> value(s) were already correct.</li>
                    <li><?php echo (int) $result['blank']; ?> row(s) had nothing filled in, so were skipped.</li>
                    <li><?php echo count($result['missing']); ?> row(s) did not match an author on this site.</li>
                    <li><?php echo count($result['unreadable']); ?> value(s) were not 1 or 0.</li>
                </ul>
            </div>

            <?php if ($result['missing'] || $result['unreadable']) : ?>
                <pre style="max-height:300px;overflow:auto;background:#fff;padding:10px;border:1px solid #ccd0d4"><?php
                    echo esc_html(implode("\n", array_merge(array_slice($result['missing'], 0, 200), array_slice($result['unreadable'], 0, 200))));
                ?></pre>
            <?php endif; ?>

        <?php endif; ?>

        <p>
            Upload the spreadsheet once the <strong>BIPOC</strong>, <strong>LGBTQ+</strong> and
            <strong>Disabled/Neurodiverse</strong> columns on the Authors sheet are filled in
            (1 = yes, 0 = no). Blank cells leave the author alone.
        </p>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('author_details_import'); ?>
            <input type="hidden" name="author_details_import" value="1">
            <input type="file" name="authors_xlsx" accept=".xlsx">
            <?php submit_button('Upload and apply'); ?>
        </form>
    </div>

    <?php
}
