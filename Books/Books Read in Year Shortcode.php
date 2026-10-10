// BOOKS READ BY YEAR SHORTCODE
// Displays Books that have at least one Read Instance in the specified year,
// with the most recently read at the top.
//
// Usage:  [books_read year="2026"]                 (most recent at the top)
//         [books_read year="2026" order="asc"]      (oldest at the top)
//         [books_read]              (defaults to the current year)
//
// Administrators can add debug="1" to see which Read Instances each book found.
//
// The old [books_read_2016] ... style shortcodes still work too.

// Turns a rating such as 4 or 3.5 into star icons (3.5 = three stars and a half).
function book_read_year_stars($rating) {

    $rating = (float) $rating;

    if ($rating < 0.5 || $rating > 5) {
        return '';
    }

    $full = (int) floor($rating);
    $half = ($rating - $full) >= 0.5;

    return str_repeat('★', $full) . ($half ? '½' : '');
}


function display_books_read_by_year($year, $debug = false, $order = 'desc') {

    $year = (int) $year;

    if ($year < 1900 || $year > 2100) {
        return '';
    }

    // Visitors only see published records.
    $statuses = current_user_can('edit_posts')
        ? array('publish', 'draft', 'private')
        : array('publish');

    // Find Read Instances whose Date Read falls inside this year.
    $read_instances = get_posts(array(
        'post_type'      => 'read_instance',
        'post_status'    => $statuses,
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => '_read_instance_date_read',
                'value'   => array($year . '-01-01', $year . '-12-31'),
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ),
        ),
    ));

    if (empty($read_instances)) {
        return '';
    }

    // Remember the earliest date each Book was read this year (and which
    // Read Instance it was). A Book that was reread during the year is
    // only listed once, using its first read of the year.
    $first_read = array();
    $first_id   = array();
    $with_review = array();
    $debug_lines = array();

    foreach ($read_instances as $instance) {

        $book_id = (int) get_post_meta($instance->ID, '_read_instance_book_id', true);
        $date    = (string) get_post_meta($instance->ID, '_read_instance_date_read', true);

        if (!$book_id) {
            continue;
        }

        // A Read Instance from this year that has a review written in it.
        // (Records made by the old Add Read form kept the review in
        // _read_instance_review; the Cleanup tool moves those into the editor.)
        $has_review = trim($instance->post_content) !== ''
            || trim((string) get_post_meta($instance->ID, '_read_instance_review', true)) !== '';

        $debug_lines[$book_id][] = '#' . $instance->ID . ' (' . ($date !== '' ? $date : 'no date') . ', '
            . $instance->post_status . ', review: ' . ($has_review ? 'yes' : 'no') . ')';

        if ($has_review && (!isset($with_review[$book_id]) || strcmp($date, $with_review[$book_id]['date']) > 0)) {
            $with_review[$book_id] = array('id' => $instance->ID, 'date' => $date);
        }

        if (!isset($first_read[$book_id]) || strcmp($date, $first_read[$book_id]) < 0) {
            $first_read[$book_id] = $date;
            $first_id[$book_id]   = $instance->ID;
        }
    }

    if (empty($first_read)) {
        return '';
    }

    // Most recent first (or oldest first with order="asc").
    // Books read on the same day keep a steady order, newest record first.
    $direction = (strtolower((string) $order) === 'asc') ? 1 : -1;

    uksort($first_read, function ($a, $b) use ($first_read, $first_id, $direction) {

        $by_date = strcmp($first_read[$a], $first_read[$b]);

        if ($by_date !== 0) {
            return $direction * $by_date;
        }

        return $direction * ($first_id[$a] <=> $first_id[$b]);
    });

    $book_ids = array_keys($first_read);

    // Get the Books, keeping the order above.
    $books = get_posts(array(
        'post_type'      => 'book',
        'post_status'    => $statuses,
        'posts_per_page' => -1,
        'post__in'       => $book_ids,
        'orderby'        => 'post__in',
    ));

    if (empty($books)) {
        return '';
    }

    // Totals for the top of the page (a book reread this year counts once).
    $total_books = count($books);
    $total_pages = 0;

    foreach ($books as $book) {
        $total_pages += (int) get_post_meta($book->ID, '_book_pages', true);
    }

    $output = '<div class="books-read-' . esc_attr($year) . '">';

    $output .= '<p class="books-read-summary"><strong>' . number_format_i18n($total_books) . '</strong> '
        . ($total_books === 1 ? 'book' : 'books')
        . ' &middot; <strong>' . number_format_i18n($total_pages) . '</strong> '
        . ($total_pages === 1 ? 'page' : 'pages') . '</p>';

    foreach ($books as $book) {

        $permalink = esc_url(get_permalink($book->ID));
        $title     = esc_html(get_the_title($book->ID));

        // Author
        $author_html = '';
        $author_id   = (int) get_post_meta($book->ID, '_book_author_id', true);

        if ($author_id && get_post_type($author_id) === 'author' && in_array(get_post_status($author_id), $statuses, true)) {
            $author_html = '<a href="' . esc_url(get_permalink($author_id)) . '">'
                . esc_html(get_the_title($author_id)) . '</a>';
        }

        // Date and rating come from the first read of the year.
        $instance_id = isset($first_id[$book->ID]) ? $first_id[$book->ID] : 0;
        $date        = $instance_id ? (string) get_post_meta($instance_id, '_read_instance_date_read', true) : '';
        $rating      = $instance_id ? (string) get_post_meta($instance_id, '_read_instance_rating', true) : '';

        $date_html = '';

        if ($date !== '') {
            $parsed    = DateTime::createFromFormat('!Y-m-d', $date, wp_timezone());
            $date_html = esc_html($parsed ? wp_date(get_option('date_format'), $parsed->getTimestamp(), wp_timezone()) : $date);
        }

        $debug_html = '';

        if ($debug && current_user_can('manage_options')) {
            $debug_html = '<div style="font-size:12px;opacity:.7">Debug - Read Instances found for ' . (int) $year . ': '
                . esc_html(isset($debug_lines[$book->ID]) ? implode('; ', $debug_lines[$book->ID]) : 'none')
                . '. Review link goes to: '
                . esc_html(isset($with_review[$book->ID]) ? '#' . $with_review[$book->ID]['id'] : 'nothing (no read this year has a review)')
                . '</div>';
        }

        // Layout (styled by .book-read-year-* in Additional CSS)
        $output .= '<div class="book-read-year">';

        $output .= '<div class="book-read-year-cover">';

        if (has_post_thumbnail($book->ID)) {
            $output .= '<a href="' . $permalink . '">'
                . get_the_post_thumbnail($book->ID, 'medium', array('alt' => 'Cover of ' . get_the_title($book->ID), 'loading' => 'lazy'))
                . '</a>';
        }

        $output .= '</div>';

        $output .= '<div class="book-read-year-info">';
        $output .= '<div class="book-read-year-title"><a href="' . $permalink . '">' . $title . '</a></div>';

        if ($author_html !== '') {
            $output .= '<div class="book-read-year-author">by ' . $author_html . '</div>';
        }

        if ($rating !== '') {
            $stars = book_read_year_stars($rating);

            if ($stars !== '') {
                $output .= '<div class="book-read-year-rating" role="img" aria-label="'
                    . esc_attr($rating . ' out of 5 stars') . '" title="' . esc_attr($rating . ' out of 5') . '">'
                    . $stars . '</div>';
            }
        }

        // Date, followed on the same line by a link to the review (if there is one).
        $review_html = '';

        if (isset($with_review[$book->ID])) {
            $review_html = '<a class="book-read-year-review" href="'
                . esc_url(get_permalink($with_review[$book->ID]['id'])) . '">Read my review</a>';
        }

        if ($date_html !== '' || $review_html !== '') {
            $output .= '<div class="book-read-year-date">';
            $output .= $date_html !== '' ? 'Read: ' . $date_html : '';
            $output .= ($date_html !== '' && $review_html !== '') ? ' &middot; ' : '';
            $output .= $review_html;
            $output .= '</div>';
        }

        $output .= $debug_html;
        $output .= '</div>';
        $output .= '</div>';
    }

    $output .= '</div>';

    return $output;
}


// MAIN SHORTCODE: [books_read year="2026"]

add_shortcode('books_read', function ($atts) {

    $atts = shortcode_atts(
        array('year' => gmdate('Y'), 'debug' => '', 'order' => 'desc'),
        $atts,
        'books_read'
    );

    return display_books_read_by_year($atts['year'], $atts['debug'] === '1', $atts['order']);
});


// LEGACY SHORTCODES: [books_read_2016] and so on.
// Registered automatically from 2016 through next year, so
// nothing needs adding each January.

for ($books_read_year = 2016; $books_read_year <= (int) gmdate('Y') + 1; $books_read_year++) {

    add_shortcode('books_read_' . $books_read_year, function () use ($books_read_year) {
        return display_books_read_by_year($books_read_year);
    });
}


// CURRENTLY READING SHORTCODE: [books_currently_reading]
// Shows every Book whose status is "Currently Reading", laid out exactly like
// the Books Read list (same cover, title, author styling).
//
// Usage:  [books_currently_reading]                       (newest added first)
//         [books_currently_reading order="asc"]           (oldest added first)
//         [books_currently_reading none="Nothing right now."]

function display_books_currently_reading($order = 'desc', $none = '') {

    $statuses = current_user_can('edit_posts')
        ? array('publish', 'draft', 'private')
        : array('publish');

    $books = get_posts(array(
        'post_type'      => 'book',
        'post_status'    => $statuses,
        'posts_per_page' => -1,
        'meta_key'       => '_book_status',
        'meta_value'     => 'Currently Reading',
        'orderby'        => 'date',
        'order'          => (strtolower((string) $order) === 'asc') ? 'ASC' : 'DESC',
    ));

    if (empty($books)) {
        return $none !== '' ? '<p class="books-currently-reading-none">' . esc_html($none) . '</p>' : '';
    }

    $total_books = count($books);
    $total_pages = 0;

    foreach ($books as $book) {
        $total_pages += (int) get_post_meta($book->ID, '_book_pages', true);
    }

    $output = '<div class="books-currently-reading">';

    $output .= '<p class="books-read-summary"><strong>' . number_format_i18n($total_books) . '</strong> '
        . ($total_books === 1 ? 'book' : 'books');

    if ($total_pages > 0) {
        $output .= ' &middot; <strong>' . number_format_i18n($total_pages) . '</strong> '
            . ($total_pages === 1 ? 'page' : 'pages');
    }

    $output .= '</p>';

    foreach ($books as $book) {

        $permalink = esc_url(get_permalink($book->ID));

        $author_html = '';
        $author_id   = (int) get_post_meta($book->ID, '_book_author_id', true);

        if ($author_id && get_post_type($author_id) === 'author' && in_array(get_post_status($author_id), $statuses, true)) {
            $author_html = '<a href="' . esc_url(get_permalink($author_id)) . '">'
                . esc_html(get_the_title($author_id)) . '</a>';
        }

        $pages = (int) get_post_meta($book->ID, '_book_pages', true);

        $output .= '<div class="book-read-year">';
        $output .= '<div class="book-read-year-cover">';

        if (has_post_thumbnail($book->ID)) {
            $output .= '<a href="' . $permalink . '">'
                . get_the_post_thumbnail($book->ID, 'medium', array('alt' => 'Cover of ' . get_the_title($book->ID), 'loading' => 'lazy'))
                . '</a>';
        }

        $output .= '</div>';
        $output .= '<div class="book-read-year-info">';
        $output .= '<div class="book-read-year-title"><a href="' . $permalink . '">' . esc_html(get_the_title($book->ID)) . '</a></div>';

        if ($author_html !== '') {
            $output .= '<div class="book-read-year-author">by ' . $author_html . '</div>';
        }

        if ($pages > 0) {
            $output .= '<div class="book-read-year-date">' . number_format_i18n($pages) . ' pages</div>';
        }

        $output .= '</div></div>';
    }

    $output .= '</div>';

    return $output;
}

add_shortcode('books_currently_reading', function ($atts) {

    $atts = shortcode_atts(array('order' => 'desc', 'none' => ''), $atts, 'books_currently_reading');

    return display_books_currently_reading($atts['order'], $atts['none']);
});
