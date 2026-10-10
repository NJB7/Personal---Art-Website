/* Book Cover Category (automatic)
 *
 * Whenever a picture is set as the featured image of a Book, it is filed in
 * the "Book covers" media category. It works however the cover gets there:
 * your Add Book form, the editor, or an import. Saving an existing Book also
 * files its current cover.
 *
 * It only ever adds the category; it never removes or changes anything else.
 *
 * Needs the "Media Categories" snippet to be active (it creates the
 * categories). For covers that are already in your library, run
 * Media > Sort into categories once.
 *
 * To use a different category name, change it just below.
 */

function bcc_category_name() {
    return 'Book covers';
}

function bcc_term_id() {

    if (!taxonomy_exists('media_category')) {
        return 0;
    }

    /* Matches "Book covers" or "Book cover", whichever you already made */
    foreach ([bcc_category_name(), 'Book cover', 'Book Covers', 'Book Cover'] as $name) {

        $found = term_exists($name, 'media_category');

        if ($found) {
            return (int) (is_array($found) ? $found['term_id'] : $found);
        }
    }

    $made = wp_insert_term(bcc_category_name(), 'media_category');

    return is_wp_error($made) ? 0 : (int) $made['term_id'];
}

function bcc_file_cover($attachment_id) {

    $attachment_id = absint($attachment_id);

    if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
        return;
    }

    $term_id = bcc_term_id();

    if ($term_id) {
        wp_set_object_terms($attachment_id, [$term_id], 'media_category', true);
    }
}

/* A featured image is stored as the _thumbnail_id field of the Book. */
function bcc_on_thumbnail($meta_id, $post_id, $meta_key, $meta_value) {

    if ($meta_key !== '_thumbnail_id' || get_post_type($post_id) !== 'book') {
        return;
    }

    bcc_file_cover($meta_value);
}
add_action('added_post_meta', 'bcc_on_thumbnail', 10, 4);
add_action('updated_post_meta', 'bcc_on_thumbnail', 10, 4);

/* Saving a Book also files whatever its cover is right now. */
add_action('save_post_book', function ($post_id) {

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    $thumb = get_post_thumbnail_id($post_id);

    if ($thumb) {
        bcc_file_cover($thumb);
    }
});
