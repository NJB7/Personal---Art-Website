<?php

/* =========================================================
   BOOK DATABASE
   Creates Authors, Books, Read Instances, and taxonomies.
   ========================================================= */


/* =========================================================
   0. HELPERS
   ========================================================= */

/**
 * The allowed values for every dropdown field.
 * These must match the values used by the Add Read form.
 */
function book_database_choices($name) {

    $choices = array(
        'gender' => array('Male', 'Female', 'Other'),
        'status' => array('Read', 'Currently Reading', 'To Read', 'DNF'),
        'type'   => array('Fiction', 'Nonfiction'),
        'rating' => array('1', '1.5', '2', '2.5', '3', '3.5', '4', '4.5', '5'),
        'format' => array('Print', 'eBook', 'Audiobook'),
    );

    return isset($choices[$name]) ? $choices[$name] : array();
}


/** Outputs <option> tags for a dropdown. */
function book_database_render_options($name, $current) {

    foreach (book_database_choices($name) as $choice) {
        printf(
            '<option value="%1$s"%2$s>%3$s</option>',
            esc_attr($choice),
            selected($current, $choice, false),
            esc_html($choice)
        );
    }
}


/** True only for a real calendar date written as YYYY-MM-DD. */
function book_database_is_valid_date($date) {

    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed && $parsed->format('Y-m-d') === $date;
}


/** Saves a dropdown value, or deletes the field when it is blank/invalid. */
function book_database_save_choice($post_id, $meta_key, $value, $allowed) {

    if (in_array($value, $allowed, true)) {
        update_post_meta($post_id, $meta_key, $value);
    } else {
        delete_post_meta($post_id, $meta_key);
    }
}


/** Saves a positive whole number, or deletes the field when blank/zero. */
function book_database_save_number($post_id, $meta_key, $value) {

    $value = absint($value);

    if ($value > 0) {
        update_post_meta($post_id, $meta_key, $value);
    } else {
        delete_post_meta($post_id, $meta_key);
    }
}


/** Common safety checks used by every save function. */
function book_database_can_save($post_id, $nonce_field, $nonce_action) {

    if (
        !isset($_POST[$nonce_field]) ||
        !wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST[$nonce_field])),
            $nonce_action
        )
    ) {
        return false;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return false;
    }

    return current_user_can('edit_post', $post_id);
}


/* =========================================================
   1. REGISTER CUSTOM POST TYPES
   Creates Author, Book, and Read Instance records.
   ========================================================= */

function register_book_database_post_types() {

    /* ---------- AUTHOR ----------
       "author" is also a WordPress core query variable and the
       core URL base for user archives, so the rewrite slug is
       changed to "authors" and the query var is turned off.
       After updating this snippet, go to
       Settings > Permalinks and click Save once.
       ---------- */

    register_post_type('author', array(

        'labels' => array(
            'name'          => 'Authors',
            'singular_name' => 'Author',
            'add_new_item'  => 'Add New Author',
            'edit_item'     => 'Edit Author',
            'new_item'      => 'New Author',
            'view_item'     => 'View Author',
            'search_items'  => 'Search Authors',
        ),

        'public'       => true,
        'show_in_rest' => true,
        'query_var'    => false,
        'rewrite'      => array('slug' => 'authors'),

        'supports' => array(
            'title',
            'editor',
            'thumbnail',
        ),

        'menu_icon' => 'dashicons-admin-users',
    ));


    /* ---------- BOOK ---------- */

    register_post_type('book', array(

        'labels' => array(
            'name'          => 'Books',
            'singular_name' => 'Book',
            'add_new_item'  => 'Add New Book',
            'edit_item'     => 'Edit Book',
            'new_item'      => 'New Book',
            'view_item'     => 'View Book',
            'search_items'  => 'Search Books',
        ),

        'public'       => true,
        'show_in_rest' => true,

        'supports' => array(
            'title',
            'editor',
            'thumbnail',
        ),

        'menu_icon' => 'dashicons-book',
    ));


    /* ---------- READ INSTANCE ---------- */

    register_post_type('read_instance', array(

        'labels' => array(
            'name'          => 'Read Instances',
            'singular_name' => 'Read Instance',
            'add_new_item'  => 'Add New Read Instance',
            'edit_item'     => 'Edit Read Instance',
            'new_item'      => 'New Read Instance',
            'view_item'     => 'View Read Instance',
            'search_items'  => 'Search Read Instances',
        ),

        'public'       => true,
        'show_in_rest' => true,

        'supports' => array(
            'title',
            'editor',
        ),

        'menu_icon' => 'dashicons-book-alt',
    ));
}

add_action('init', 'register_book_database_post_types');


/* =========================================================
   2. REGISTER TAXONOMIES
   Country, Genre, and Language are managed in WordPress.
   ========================================================= */

function register_book_database_taxonomies() {

    /* ---------- COUNTRY ---------- */

    register_taxonomy('author_country', 'author', array(

        'labels' => array(
            'name'          => 'Countries',
            'singular_name' => 'Country',
            'search_items'  => 'Search Countries',
            'all_items'     => 'All Countries',
            'edit_item'     => 'Edit Country',
            'add_new_item'  => 'Add New Country',
            'new_item_name' => 'New Country Name',
        ),

        'public'            => true,
        'show_in_rest'      => true,
        'hierarchical'      => false,
        'show_admin_column' => true,
    ));


    /* ---------- GENRE ---------- */

    register_taxonomy('book_genre', 'book', array(

        'labels' => array(
            'name'          => 'Genres',
            'singular_name' => 'Genre',
            'search_items'  => 'Search Genres',
            'all_items'     => 'All Genres',
            'edit_item'     => 'Edit Genre',
            'add_new_item'  => 'Add New Genre',
            'new_item_name' => 'New Genre Name',
        ),

        'public'            => true,
        'show_in_rest'      => true,
        'hierarchical'      => false,
        'show_admin_column' => true,
    ));


    /* ---------- LANGUAGE ----------
       Shared by Books (original language) and Read Instances
       (language read). Both can contain multiple languages.
       ---------- */

    register_taxonomy(
        'book_language',
        array('book', 'read_instance'),
        array(

            'labels' => array(
                'name'          => 'Languages',
                'singular_name' => 'Language',
                'search_items'  => 'Search Languages',
                'all_items'     => 'All Languages',
                'edit_item'     => 'Edit Language',
                'add_new_item'  => 'Add New Language',
                'new_item_name' => 'New Language Name',
            ),

            'public'            => true,
            'show_in_rest'      => true,
            'hierarchical'      => false,
            'show_admin_column' => true,
        )
    );
}

add_action('init', 'register_book_database_taxonomies');


/* =========================================================
   3. AUTHOR FIELDS
   Country is handled by the Country taxonomy.
   Name uses the normal WordPress Title field.
   About uses the normal WordPress Editor.
   Photo uses Featured Image.
   ========================================================= */

function add_author_fields_meta_box() {

    add_meta_box(
        'author_details',
        'Author Details',
        'display_author_fields',
        'author',
        'normal',
        'high'
    );
}

add_action('add_meta_boxes', 'add_author_fields_meta_box');


function display_author_fields($post) {

    wp_nonce_field('save_author_fields', 'author_fields_nonce');

    $gender   = get_post_meta($post->ID, '_author_gender', true);
    $bipoc    = get_post_meta($post->ID, '_author_bipoc', true);
    $lgbtq    = get_post_meta($post->ID, '_author_lgbtq', true);
    $disabled = get_post_meta($post->ID, '_author_disabled_neurodivergent', true);
    ?>

    <p>
        <label for="author_gender"><strong>Gender</strong></label><br>

        <select name="author_gender" id="author_gender">
            <option value="">— Select —</option>
            <?php book_database_render_options('gender', $gender); ?>
        </select>
    </p>

    <p>
        <label>
            <input type="checkbox" name="author_bipoc" value="1"
                <?php checked($bipoc, '1'); ?>>
            BIPOC
        </label>
    </p>

    <p>
        <label>
            <input type="checkbox" name="author_lgbtq" value="1"
                <?php checked($lgbtq, '1'); ?>>
            LGBTQ+
        </label>
    </p>

    <p>
        <label>
            <input type="checkbox" name="author_disabled_neurodivergent" value="1"
                <?php checked($disabled, '1'); ?>>
            Disabled/Neurodivergent
        </label>
    </p>

    <?php
}


/* =========================================================
   4. BOOK FIELDS
   Author is a searchable linked Author record.
   Pages, Status, Fiction/Nonfiction, and Publication Year
   are stored as custom fields.
   Genre and Original Language use taxonomies.
   Cover uses Featured Image.
   ========================================================= */

function add_book_fields_meta_box() {

    add_meta_box(
        'book_details',
        'Book Details',
        'display_book_fields',
        'book',
        'normal',
        'high'
    );
}

add_action('add_meta_boxes', 'add_book_fields_meta_box');


function display_book_fields($post) {

    wp_nonce_field('save_book_fields', 'book_fields_nonce');

    $author_id        = get_post_meta($post->ID, '_book_author_id', true);
    $pages            = get_post_meta($post->ID, '_book_pages', true);
    $status           = get_post_meta($post->ID, '_book_status', true);
    $fiction          = get_post_meta($post->ID, '_book_fiction_nonfiction', true);
    $publication_year = get_post_meta($post->ID, '_book_publication_year', true);

    $author_title = $author_id ? get_the_title($author_id) : '';
    ?>

    <p>
        <label for="book_author_search"><strong>Author</strong></label><br>

        <input
            type="text"
            id="book_author_search"
            class="book-database-search"
            data-search-type="author"
            value="<?php echo esc_attr($author_title); ?>"
            placeholder="Start typing an author name..."
            autocomplete="off"
        >

        <input
            type="hidden"
            name="book_author_id"
            id="book_author_id"
            value="<?php echo esc_attr($author_id); ?>"
        >
    </p>

    <div id="book_author_results" class="book-database-search-results"></div>

    <p>
        <label for="book_pages"><strong>Pages</strong></label><br>

        <input
            type="number"
            name="book_pages"
            id="book_pages"
            value="<?php echo esc_attr($pages); ?>"
            min="0"
            step="1"
        >
    </p>

    <p>
        <label for="book_status"><strong>Status</strong></label><br>

        <select name="book_status" id="book_status">
            <option value="">— Select Status —</option>
            <?php book_database_render_options('status', $status); ?>
        </select>
    </p>

    <p>
        <label for="book_fiction_nonfiction"><strong>Fiction/Nonfiction</strong></label><br>

        <select name="book_fiction_nonfiction" id="book_fiction_nonfiction">
            <option value="">— Select —</option>
            <?php book_database_render_options('type', $fiction); ?>
        </select>
    </p>

    <p>
        <label for="book_publication_year"><strong>Publication Year</strong></label><br>

        <input
            type="number"
            name="book_publication_year"
            id="book_publication_year"
            value="<?php echo esc_attr($publication_year); ?>"
            min="0"
            max="9999"
            step="1"
        >
    </p>

    <p>
        <em>
            Genre and Original Language are managed using
            the taxonomy boxes on this screen.
        </em>
    </p>

    <?php
}


/* =========================================================
   5. READ INSTANCE FIELDS
   Book is a searchable linked Book record.
   Date, Rating, and Format are stored as custom fields.
   Language Read uses the shared Language taxonomy.
   Review uses the normal WordPress Editor.
   ========================================================= */

function add_read_instance_fields_meta_box() {

    add_meta_box(
        'read_instance_details',
        'Reading Details',
        'display_read_instance_fields',
        'read_instance',
        'normal',
        'high'
    );
}

add_action('add_meta_boxes', 'add_read_instance_fields_meta_box');


function display_read_instance_fields($post) {

    wp_nonce_field('save_read_instance_fields', 'read_instance_fields_nonce');

    $book_id   = get_post_meta($post->ID, '_read_instance_book_id', true);
    $date_read = get_post_meta($post->ID, '_read_instance_date_read', true);
    $rating    = get_post_meta($post->ID, '_read_instance_rating', true);
    $format    = get_post_meta($post->ID, '_read_instance_format', true);

    $book_title = $book_id ? get_the_title($book_id) : '';
    ?>

    <p>
        <label for="read_instance_book_search"><strong>Book</strong></label><br>

        <input
            type="text"
            id="read_instance_book_search"
            class="book-database-search"
            data-search-type="book"
            value="<?php echo esc_attr($book_title); ?>"
            placeholder="Start typing a book title..."
            autocomplete="off"
        >

        <input
            type="hidden"
            name="read_instance_book_id"
            id="read_instance_book_id"
            value="<?php echo esc_attr($book_id); ?>"
        >
    </p>

    <div id="read_instance_book_results" class="book-database-search-results"></div>

    <p>
        <label for="read_instance_date_read"><strong>Date Read</strong></label><br>

        <input
            type="date"
            name="read_instance_date_read"
            id="read_instance_date_read"
            value="<?php echo esc_attr($date_read); ?>"
        >
    </p>

    <p>
        <label for="read_instance_rating"><strong>Rating</strong></label><br>

        <select name="read_instance_rating" id="read_instance_rating">
            <option value="">— Select Rating —</option>
            <?php book_database_render_options('rating', $rating); ?>
        </select>
    </p>

    <p>
        <label for="read_instance_format"><strong>Format</strong></label><br>

        <select name="read_instance_format" id="read_instance_format">
            <option value="">— Select Format —</option>
            <?php book_database_render_options('format', $format); ?>
        </select>
    </p>

    <p>
        <em>
            Language Read is managed using the Language
            taxonomy box on this screen. The review goes in the
            main editor above.
        </em>
    </p>

    <?php
}


/* =========================================================
   6. SAVE AUTHOR FIELDS
   Saves Gender and the three checkbox fields.
   Choosing "— Select —" clears the field.
   ========================================================= */

function save_author_fields($post_id) {

    if (!book_database_can_save($post_id, 'author_fields_nonce', 'save_author_fields')) {
        return;
    }

    /* Gender */

    $gender = isset($_POST['author_gender'])
        ? sanitize_text_field(wp_unslash($_POST['author_gender']))
        : '';

    book_database_save_choice(
        $post_id,
        '_author_gender',
        $gender,
        book_database_choices('gender')
    );

    /* Checkboxes */

    update_post_meta(
        $post_id,
        '_author_bipoc',
        isset($_POST['author_bipoc']) ? '1' : '0'
    );

    update_post_meta(
        $post_id,
        '_author_lgbtq',
        isset($_POST['author_lgbtq']) ? '1' : '0'
    );

    update_post_meta(
        $post_id,
        '_author_disabled_neurodivergent',
        isset($_POST['author_disabled_neurodivergent']) ? '1' : '0'
    );
}

add_action('save_post_author', 'save_author_fields');


/* =========================================================
   7. SAVE BOOK FIELDS
   Saves Author relationship, Pages, Status,
   Fiction/Nonfiction, and Publication Year.
   Blank or zero values clear the field.
   ========================================================= */

function save_book_fields($post_id) {

    if (!book_database_can_save($post_id, 'book_fields_nonce', 'save_book_fields')) {
        return;
    }

    /* Linked Author */

    $author_id = isset($_POST['book_author_id'])
        ? absint($_POST['book_author_id'])
        : 0;

    if ($author_id && get_post_type($author_id) === 'author') {
        update_post_meta($post_id, '_book_author_id', $author_id);
    } else {
        delete_post_meta($post_id, '_book_author_id');
    }

    /* Pages */

    book_database_save_number(
        $post_id,
        '_book_pages',
        isset($_POST['book_pages']) ? $_POST['book_pages'] : 0
    );

    /* Status */

    book_database_save_choice(
        $post_id,
        '_book_status',
        isset($_POST['book_status'])
            ? sanitize_text_field(wp_unslash($_POST['book_status']))
            : '',
        book_database_choices('status')
    );

    /* Fiction / Nonfiction */

    book_database_save_choice(
        $post_id,
        '_book_fiction_nonfiction',
        isset($_POST['book_fiction_nonfiction'])
            ? sanitize_text_field(wp_unslash($_POST['book_fiction_nonfiction']))
            : '',
        book_database_choices('type')
    );

    /* Publication Year */

    book_database_save_number(
        $post_id,
        '_book_publication_year',
        isset($_POST['book_publication_year']) ? $_POST['book_publication_year'] : 0
    );
}

add_action('save_post_book', 'save_book_fields');


/* =========================================================
   8. SAVE READ INSTANCE FIELDS
   Saves Book relationship, Date Read, Rating, and Format.
   Blank values clear the field.
   ========================================================= */

function save_read_instance_fields($post_id) {

    if (!book_database_can_save($post_id, 'read_instance_fields_nonce', 'save_read_instance_fields')) {
        return;
    }

    /* Linked Book */

    $book_id = isset($_POST['read_instance_book_id'])
        ? absint($_POST['read_instance_book_id'])
        : 0;

    if ($book_id && get_post_type($book_id) === 'book') {
        update_post_meta($post_id, '_read_instance_book_id', $book_id);
    } else {
        delete_post_meta($post_id, '_read_instance_book_id');
    }

    /* Date Read (must be a real YYYY-MM-DD date) */

    $date_read = isset($_POST['read_instance_date_read'])
        ? sanitize_text_field(wp_unslash($_POST['read_instance_date_read']))
        : '';

    if (book_database_is_valid_date($date_read)) {
        update_post_meta($post_id, '_read_instance_date_read', $date_read);
    } else {
        delete_post_meta($post_id, '_read_instance_date_read');
    }

    /* Rating */

    book_database_save_choice(
        $post_id,
        '_read_instance_rating',
        isset($_POST['read_instance_rating'])
            ? sanitize_text_field(wp_unslash($_POST['read_instance_rating']))
            : '',
        book_database_choices('rating')
    );

    /* Format */

    book_database_save_choice(
        $post_id,
        '_read_instance_format',
        isset($_POST['read_instance_format'])
            ? sanitize_text_field(wp_unslash($_POST['read_instance_format']))
            : '',
        book_database_choices('format')
    );
}

add_action('save_post_read_instance', 'save_read_instance_fields');


/* =========================================================
   9. AJAX SEARCH
   Provides searchable Author and Book selectors.
   Only logged-in users who can edit posts can search,
   and every request must carry a valid nonce.
   ========================================================= */

function book_database_ajax_search() {

    check_ajax_referer('book_database_search', 'nonce');

    if (!current_user_can('edit_posts')) {
        wp_send_json_error();
    }

    $search = isset($_POST['search'])
        ? sanitize_text_field(wp_unslash($_POST['search']))
        : '';

    $type = isset($_POST['type'])
        ? sanitize_key($_POST['type'])
        : '';

    if (
        strlen($search) < 2 ||
        !in_array($type, array('author', 'book'), true)
    ) {
        wp_send_json_success(array());
    }

    $results = get_posts(array(
        'post_type'      => $type,
        'post_status'    => array('publish', 'draft', 'private'),
        'posts_per_page' => 20,
        'orderby'        => 'title',
        'order'          => 'ASC',
        's'              => $search,
    ));

    $output = array();

    foreach ($results as $result) {
        $output[] = array(
            'id'    => $result->ID,
            'title' => $result->post_title,
        );
    }

    wp_send_json_success($output);
}

add_action('wp_ajax_book_database_search', 'book_database_ajax_search');


/* =========================================================
   10. SEARCH FIELD JAVASCRIPT
   Adds live search behavior to the Author and Book fields.
   ========================================================= */

function book_database_is_editing_screen($hook) {

    if ($hook !== 'post.php' && $hook !== 'post-new.php') {
        return false;
    }

    $screen = get_current_screen();

    return $screen && in_array(
        $screen->post_type,
        array('author', 'book', 'read_instance'),
        true
    );
}


function book_database_admin_scripts($hook) {

    if (!book_database_is_editing_screen($hook)) {
        return;
    }

    wp_enqueue_script('jquery');

    wp_add_inline_script(
        'jquery',
        'var bookDatabaseSearch = ' . wp_json_encode(array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('book_database_search'),
        )) . ';',
        'after'
    );

    $javascript = <<<'JS'
jQuery(document).ready(function ($) {

    $('.book-database-search').each(function () {

        var input = $(this);
        var type = input.data('search-type');
        var resultsBox = $('#' + input.attr('id').replace('_search', '_results'));
        var hiddenField = input.siblings('input[type="hidden"]');

        var searchTimer;
        var requestCounter = 0;

        input.on('input', function () {

            var search = $.trim(input.val());

            clearTimeout(searchTimer);

            // Any newer keystroke makes older responses stale.
            requestCounter++;
            var thisRequest = requestCounter;

            // Editing the text after selecting a record clears the stored ID.
            hiddenField.val('');
            resultsBox.empty().hide();

            if (search.length < 2) {
                return;
            }

            searchTimer = setTimeout(function () {

                $.ajax({
                    url: bookDatabaseSearch.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'book_database_search',
                        nonce: bookDatabaseSearch.nonce,
                        search: search,
                        type: type
                    },
                    success: function (response) {

                        if (thisRequest !== requestCounter) {
                            return;
                        }

                        if (!response.success || !response.data.length) {
                            return;
                        }

                        resultsBox.empty();

                        $.each(response.data, function (index, item) {
                            resultsBox.append(
                                $('<div>')
                                    .addClass('book-database-search-result')
                                    .text(item.title)
                                    .attr('data-id', item.id)
                            );
                        });

                        resultsBox.show();
                    }
                });

            }, 250);
        });

        resultsBox.on('click', '.book-database-search-result', function () {

            var selected = $(this);

            input.val(selected.text());
            hiddenField.val(selected.data('id'));
            resultsBox.empty().hide();
        });

        $(document).on('click', function (event) {

            if (!$(event.target).closest(input.add(resultsBox)).length) {
                resultsBox.empty().hide();
            }
        });
    });
});
JS;

    wp_add_inline_script('jquery', $javascript);
}

add_action('admin_enqueue_scripts', 'book_database_admin_scripts');


/* =========================================================
   11. SEARCH FIELD STYLING
   Makes search results look like a simple dropdown.
   ========================================================= */

function book_database_admin_styles($hook) {

    if (!book_database_is_editing_screen($hook)) {
        return;
    }

    $css = "
        .book-database-search {
            width: 100%;
            max-width: 500px;
        }

        .book-database-search-results {
            display: none;
            position: absolute;
            z-index: 9999;
            width: 500px;
            max-width: calc(100% - 40px);
            background: #fff;
            border: 1px solid #8c8f94;
            box-shadow: 0 2px 5px rgba(0,0,0,0.15);
        }

        .book-database-search-result {
            padding: 8px 10px;
            cursor: pointer;
        }

        .book-database-search-result:hover {
            background: #f0f0f1;
        }
    ";

    wp_add_inline_style('common', $css);
}

add_action('admin_enqueue_scripts', 'book_database_admin_styles');
