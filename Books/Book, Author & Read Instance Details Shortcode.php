// AUTHOR DETAILS SHORTCODE
// Displays custom Author fields on the Author template as one tight block
// of lines: Gender, Country, then BIPOC / LGBTQ+ / Disabled/Neurodivergent
// (these three only appear when they apply).

function display_author_details() {

    if (get_post_type() !== 'author') {
        return '';
    }

    $post_id = get_the_ID();

    $gender   = get_post_meta($post_id, '_author_gender', true);
    $bipoc    = get_post_meta($post_id, '_author_bipoc', true);
    $lgbtq    = get_post_meta($post_id, '_author_lgbtq', true);
    $disabled = get_post_meta($post_id, '_author_disabled_neurodivergent', true);

    $lines = array();

    if ($gender !== '') {
        $lines[] = 'Gender: ' . esc_html($gender);
    }

    // Country (the Country terms on the Author).
    $countries = wp_get_post_terms($post_id, 'author_country', array('fields' => 'names'));

    if (!is_wp_error($countries) && !empty($countries)) {
        $lines[] = 'Country: ' . esc_html(implode(', ', $countries));
    }

    if ($bipoc) {
        $lines[] = 'BIPOC: Yes';
    }

    if ($lgbtq) {
        $lines[] = 'LGBTQ+: Yes';
    }

    if ($disabled) {
        $lines[] = 'Disabled/Neurodivergent: Yes';
    }

    if (empty($lines)) {
        return '';
    }

    // One paragraph with line breaks, so there is no gap between rows.
    return '<p class="author-details">' . implode('<br>', $lines) . '</p>';
}

add_shortcode('author_details', 'display_author_details');


// BOOK DETAILS SHORTCODE
// Displays custom Book fields on the Book template as one tight block of
// lines: Author, Pages, Status, Type, Publication Year, Original Language.

function display_book_details() {

    if (get_post_type() !== 'book') {
        return '';
    }

    $post_id = get_the_ID();

    $author_id          = get_post_meta($post_id, '_book_author_id', true);
    $pages              = get_post_meta($post_id, '_book_pages', true);
    $status             = get_post_meta($post_id, '_book_status', true);
    $fiction_nonfiction = get_post_meta($post_id, '_book_fiction_nonfiction', true);
    $publication_year   = get_post_meta($post_id, '_book_publication_year', true);

    $lines = array();

    if ($author_id) {
        $author_name = get_the_title($author_id);

        if ($author_name) {
            $lines[] = 'Author: <a href="' . esc_url(get_permalink($author_id)) . '">' .
                esc_html($author_name) . '</a>';
        }
    }

    // Zero is treated as "not set".
    if ($pages) {
        $lines[] = 'Pages: ' . esc_html($pages);
    }

    if ($status !== '') {
        $lines[] = 'Status: ' . esc_html($status);
    }

    if ($fiction_nonfiction !== '') {
        $lines[] = 'Type: ' . esc_html($fiction_nonfiction);
    }

    if ($publication_year) {
        $lines[] = 'Publication Year: ' . esc_html($publication_year);
    }

    // Original Language (the Language terms on the Book).
    $languages = wp_get_post_terms($post_id, 'book_language', array('fields' => 'names'));

    if (!is_wp_error($languages) && !empty($languages)) {
        $lines[] = 'Original Language: ' . esc_html(implode(', ', $languages));
    }

    if (empty($lines)) {
        return '';
    }

    // One paragraph with line breaks, so there is no gap between rows.
    return '<p class="book-details">' . implode('<br>', $lines) . '</p>';
}

add_shortcode('book_details', 'display_book_details');


// DATE HELPER
// Turns a saved YYYY-MM-DD date into the site's date format.

function book_database_format_date($date) {

    if (!is_string($date) || $date === '') {
        return '';
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $date, wp_timezone());

    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        return $date;
    }

    return wp_date(
        get_option('date_format'),
        $parsed->getTimestamp(),
        wp_timezone()
    );
}


// READ INSTANCES BY BOOK SHORTCODE
// Displays all Read Instances linked to the current Book,
// newest first. Read Instances with no date go last.

function display_book_read_instances() {

    // Make sure we're viewing a Book record.
    if (get_post_type() !== 'book') {
        return '';
    }

    $book_id = get_the_ID();

    // Visitors only see published records.
    $statuses = current_user_can('edit_posts')
        ? array('publish', 'draft', 'private')
        : array('publish');

    // Let the database find only this Book's Read Instances.
    $read_instances = get_posts(array(
        'post_type'      => 'read_instance',
        'post_status'    => $statuses,
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => '_read_instance_book_id',
                'value'   => $book_id,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ),
        ),
    ));

    if (empty($read_instances)) {
        return '';
    }

    // Newest first; undated reads sort to the end.
    usort($read_instances, function ($a, $b) {
        $date_a = (string) get_post_meta($a->ID, '_read_instance_date_read', true);
        $date_b = (string) get_post_meta($b->ID, '_read_instance_date_read', true);

        return strcmp($date_b, $date_a);
    });

    $output = '<div class="book-read-instances">';

    foreach ($read_instances as $instance) {

        $date_read = get_post_meta($instance->ID, '_read_instance_date_read', true);
        $rating    = get_post_meta($instance->ID, '_read_instance_rating', true);

        $label = $date_read !== ''
            ? 'Read ' . book_database_format_date($date_read)
            : 'Read (date not recorded)';

        $output .= '<p class="book-read-instance">';
        $output .= '<a href="' . esc_url(get_permalink($instance->ID)) . '">';
        $output .= esc_html($label);
        $output .= '</a>';

        if ($rating !== '') {
            $output .= ' &middot; Rating: ' . esc_html($rating);
        }

        $output .= '</p>';
    }

    $output .= '</div>';

    return $output;
}

add_shortcode('book_read_instances', 'display_book_read_instances');


// READ INSTANCE DETAILS SHORTCODE
// Displays custom Read Instance fields as one tight block of lines:
// Book, Pages, Rating (as stars), Language Read.

// Turns a rating such as 4 or 3.5 into star icons (3.5 = three stars and a half).
function book_database_rating_stars($rating) {

    $rating = (float) $rating;

    if ($rating < 0.5 || $rating > 5) {
        return '';
    }

    $full = (int) floor($rating);

    return str_repeat('★', $full) . (($rating - $full) >= 0.5 ? '½' : '');
}


function display_read_instance_details() {

    if (get_post_type() !== 'read_instance') {
        return '';
    }

    $post_id = get_the_ID();

    $book_id   = get_post_meta($post_id, '_read_instance_book_id', true);
    $rating    = get_post_meta($post_id, '_read_instance_rating', true);

    $lines = array();

    if ($book_id) {

        $book_title = get_the_title($book_id);

        if ($book_title) {
            $lines[] = 'Book: <a href="' . esc_url(get_permalink($book_id)) . '">' .
                esc_html($book_title) . '</a>';
        }

        // Pages belong to the Book.
        $pages = (int) get_post_meta($book_id, '_book_pages', true);

        if ($pages > 0) {
            $lines[] = 'Pages: ' . esc_html($pages);
        }
    }

    $stars = book_database_rating_stars($rating);

    if ($stars !== '') {
        $lines[] = 'Rating: <span class="read-instance-stars" title="' .
            esc_attr($rating . ' out of 5') . '">' . $stars . '</span>';
    }

    // Language Read (the Language terms on the Read Instance).
    $languages = wp_get_post_terms($post_id, 'book_language', array('fields' => 'names'));

    if (!is_wp_error($languages) && !empty($languages)) {
        $lines[] = 'Language Read: ' . esc_html(implode(', ', $languages));
    }

    if (empty($lines)) {
        return '';
    }

    // One paragraph with line breaks, so there is no gap between rows.
    return '<p class="read-instance-details">' . implode('<br>', $lines) . '</p>';
}

add_shortcode('read_instance_details', 'display_read_instance_details');


// READ INSTANCE COVER
// A Read Instance has no cover of its own, so it borrows its Book's Featured
// Image. The same image file is used (nothing is copied or duplicated in the
// Media Library), and a Featured Image block on the Read Instance template shows
// it automatically. If a Read Instance ever gets a Featured Image of its own,
// that one is used instead.

add_filter('get_post_metadata', function ($value, $post_id, $meta_key, $single) {

    if ($meta_key !== '_thumbnail_id' || get_post_type($post_id) !== 'read_instance') {
        return $value;
    }

    // Looking up the Read Instance's own image below would trigger this filter again.
    static $busy = false;

    if ($busy) {
        return $value;
    }

    $busy = true;

    $own = get_post_meta($post_id, '_thumbnail_id', true);

    $thumbnail_id = $own;

    if (!$own) {
        $book_id = (int) get_post_meta($post_id, '_read_instance_book_id', true);
        $thumbnail_id = $book_id ? get_post_thumbnail_id($book_id) : 0;
    }

    $busy = false;

    return $thumbnail_id ? array($thumbnail_id) : $value;

}, 10, 4);


// [read_instance_cover]  Shows the Book's cover on a Read Instance page, for templates
// that use a shortcode or HTML block instead of a Featured Image block.
// Optional: size="medium" (thumbnail, medium or full).

function display_read_instance_cover($atts) {

    if (get_post_type() !== 'read_instance') {
        return '';
    }

    $atts = shortcode_atts(array('size' => 'medium'), $atts, 'read_instance_cover');

    $book_id = (int) get_post_meta(get_the_ID(), '_read_instance_book_id', true);

    if (!$book_id || !has_post_thumbnail($book_id)) {
        return '';
    }

    return '<div class="read-instance-cover">' .
        get_the_post_thumbnail($book_id, $atts['size'], array('alt' => 'Cover of ' . get_the_title($book_id))) .
        '</div>';
}

add_shortcode('read_instance_cover', 'display_read_instance_cover');


// BOOKS BY AUTHOR SHORTCODE
// Displays Books linked to the current Author.

function display_author_books() {

    if (get_post_type() !== 'author') {
        return '';
    }

    $author_id = get_the_ID();

    // Everyone can see published Books.
    // Users who can edit posts can also see drafts and private Books.
    $post_status = current_user_can('edit_posts')
        ? array('publish', 'draft', 'private')
        : array('publish');

    $books = get_posts(array(
        'post_type'      => 'book',
        'post_status'    => $post_status,
        'posts_per_page' => -1,
        'meta_key'       => '_book_author_id',
        'meta_value'     => $author_id,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ));

    if (empty($books)) {
        return '';
    }

    $output = '<div class="author-books">';

    foreach ($books as $book) {
        $output .= '<p class="author-book">';
        $output .= '<a href="' . esc_url(get_permalink($book->ID)) . '">';
        $output .= esc_html(get_the_title($book->ID));
        $output .= '</a>';
        $output .= '</p>';
    }

    $output .= '</div>';

    return $output;
}

add_shortcode('author_books', 'display_author_books');
