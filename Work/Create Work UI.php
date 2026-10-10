/* Shortcode: [add_work]
 * Quick-entry form for finished Work (artwork, essays, anything "done").
 * Nothing is strictly required. If something is left blank, or a part cannot
 * be saved (an image that will not upload, a missing title), the Work is still
 * saved and the form tells you what was left blank so you can fill it in later
 * in the editor. A Work with no title is saved as a draft, never published.
 * Requires the "Work" snippet to be active.
 */


/* ============================================================
 * AJAX: suggestions for tags
 * ============================================================ */

function quick_add_work_ajax_suggest() {

    check_ajax_referer('quick_add_work_suggest', 'nonce');

    if (!is_user_logged_in() || !current_user_can('edit_posts')) {
        wp_send_json_error();
    }

    $type = isset($_GET['type'])
        ? sanitize_key(wp_unslash($_GET['type']))
        : '';

    $search = isset($_GET['search'])
        ? sanitize_text_field(wp_unslash($_GET['search']))
        : '';

    if ($search === '') {
        wp_send_json_success([]);
    }

    $results = [];

    if ($type === 'tag') {

        $terms = get_terms([
            'taxonomy'   => 'post_tag',
            'search'     => $search,
            'hide_empty' => false,
            'number'     => 10,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $results[] = [
                    'id'    => $term->name,
                    'label' => $term->name,
                ];
            }
        }

    }

    wp_send_json_success($results);
}

add_action(
    'wp_ajax_quick_add_work_suggest',
    'quick_add_work_ajax_suggest'
);


/* ============================================================
 * Helper: category checkboxes, indented to show the hierarchy
 * ============================================================ */

function quick_add_work_render_categories($categories, $selected) {

    $by_parent = [];

    foreach ($categories as $category) {
        $by_parent[(int) $category->parent][] = $category;
    }

    $render = function ($parent_id, $depth) use (&$render, $by_parent, $selected) {

        if (empty($by_parent[$parent_id])) {
            return '';
        }

        $html = '';

        foreach ($by_parent[$parent_id] as $category) {

            $html .= '<label class="quick-work-cat" style="padding-left:' . ($depth * 1.6) . 'em">'
                . '<input type="checkbox" name="work_cats[]" value="' . esc_attr($category->term_id) . '"'
                . checked(in_array((int) $category->term_id, $selected, true), true, false)
                . '> ' . esc_html($category->name)
                . '</label>';

            $html .= $render((int) $category->term_id, $depth + 1);
        }

        return $html;
    };

    return $render(0, 0);
}


/* ============================================================
 * The [add_work] shortcode
 * ============================================================ */

function quick_add_work_shortcode() {

    if (!is_user_logged_in()) {
        return '<p>You must be logged in to add Work.</p>';
    }

    if (!current_user_can('edit_posts')) {
        return '<p>You do not have permission to add Work.</p>';
    }

    if (!post_type_exists('work')) {
        return '<p>The Work post type is not active. Please activate the Work snippet first.</p>';
    }

    /* Loads the Media Library picker used by the featured image field */
    if (current_user_can('upload_files')) {
        wp_enqueue_media();
    }

    $message = '';

    $defaults = [
        'title'   => '',
        'content' => '',
        'year'    => '',
        'details' => '',
        'project' => 0,
        'series'  => [],
        'series_new' => '',
        'cats'    => [],
        'tags'    => [],
    ];

    $values = $defaults;
    $notes  = [];

    $submitted = (
        isset($_POST['quick_add_work_create']) &&
        isset($_POST['quick_add_work_nonce']) &&
        wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['quick_add_work_nonce'])),
            'quick_add_work'
        )
    );

    if ($submitted) {

        /* ----------------------------------------------------
         * Read whatever was posted
         * ---------------------------------------------------- */

        $values['title'] = isset($_POST['work_title'])
            ? sanitize_text_field(wp_unslash($_POST['work_title']))
            : '';

        $values['content'] = isset($_POST['work_content'])
            ? wp_kses_post(wp_unslash($_POST['work_content']))
            : '';

        $values['year'] = isset($_POST['work_year'])
            ? sanitize_text_field(wp_unslash($_POST['work_year']))
            : '';

        $values['details'] = isset($_POST['work_details'])
            ? sanitize_text_field(wp_unslash($_POST['work_details']))
            : '';

        $from = isset($_POST['work_project']) ? absint($_POST['work_project']) : 0;

        if ($from && get_post_type($from) === 'project') {
            $values['project'] = $from;
        } elseif ($from) {
            $notes[] = 'The Project could not be found, so "Made in Project" was left blank.';
        }

        if (isset($_POST['work_series']) && taxonomy_exists('series')) {
            foreach ((array) $_POST['work_series'] as $series_id) {
                $series_id = absint($series_id);
                if ($series_id && term_exists($series_id, 'series')) {
                    $values['series'][] = $series_id;
                }
            }
        }

        $values['series_new'] = isset($_POST['work_series_new'])
            ? sanitize_text_field(wp_unslash($_POST['work_series_new']))
            : '';

        if (isset($_POST['work_cats'])) {
            foreach ((array) $_POST['work_cats'] as $cat_id) {
                $cat_id = absint($cat_id);
                if ($cat_id && term_exists($cat_id, 'category')) {
                    $values['cats'][] = $cat_id;
                } elseif ($cat_id) {
                    $notes[] = 'A category could not be found, so it was left out.';
                }
            }
        }

        if (isset($_POST['work_tags'])) {
            $values['tags'] = array_values(array_filter(array_map(
                'trim',
                explode(',', sanitize_text_field(wp_unslash($_POST['work_tags'])))
            )));
        }

        $status = sanitize_key(wp_unslash($_POST['quick_add_work_create']));

        if ($status !== 'publish' || !current_user_can('publish_posts')) {
            $status = 'draft';
        }

        $form_id = isset($_POST['work_form_id'])
            ? sanitize_key(wp_unslash($_POST['work_form_id']))
            : '';


        /* ----------------------------------------------------
         * Validate and create
         * ---------------------------------------------------- */

        if ($form_id === '' || !get_transient('quick_add_work_' . $form_id)) {

            $message = '<div class="quick-work-warning">
                This form was already submitted (or has expired), so nothing new was created.
                If you meant to add another entry, you can save again.
            </div>';

        } else {

            delete_transient('quick_add_work_' . $form_id);

            /* Nothing is required. A Work with no title is kept as a draft. */
            if ($values['title'] === '' && $status === 'publish') {
                $status  = 'draft';
                $notes[] = 'There was no title, so it was saved as a draft instead of being published.';
            }

            /* Let WordPress save even when the title and content are both blank */
            $allow_blank = function () {
                return false;
            };

            add_filter('wp_insert_post_empty_content', $allow_blank, 99);

            $work_id = wp_insert_post([
                'post_type'    => 'work',
                'post_title'   => $values['title'],
                'post_content' => $values['content'],
                'post_status'  => $status,
            ], true);

            remove_filter('wp_insert_post_empty_content', $allow_blank, 99);

            if (is_wp_error($work_id)) {

                $message = '<div class="quick-work-error">
                    There was a problem creating the Work:
                    ' . esc_html($work_id->get_error_message()) . '
                </div>';

            } else {

                /* Each optional part is tried on its own, so one failure never loses the rest */
                $try = function ($what, $callback) use (&$notes) {
                    try {
                        $callback();
                    } catch (\Throwable $e) {
                        $notes[] = $what . ' could not be saved, so it was left blank.';
                    }
                };

                $try('The categories', function () use ($work_id, $values) {
                    if ($values['cats']) {
                        wp_set_post_categories($work_id, $values['cats']);
                    }
                });

                $try('The series', function () use ($work_id, $values) {

                    if (!taxonomy_exists('series')) {
                        return;
                    }

                    if ($values['series']) {
                        wp_set_object_terms($work_id, $values['series'], 'series');
                    }

                    if ($values['series_new'] !== '') {
                        /* A name that does not exist yet creates a new Series */
                        wp_set_object_terms($work_id, $values['series_new'], 'series', true);
                    }
                });

                $try('The tags', function () use ($work_id, $values) {
                    if ($values['tags']) {
                        wp_set_post_tags($work_id, $values['tags'], false);
                    }
                });

                $try('The year, materials or Project', function () use ($work_id, $values) {

                    if ($values['year'] !== '') {
                        update_post_meta($work_id, '_work_year', $values['year']);
                    }

                    if ($values['details'] !== '') {
                        update_post_meta($work_id, '_work_details', $values['details']);
                    }

                    if ($values['project']) {
                        update_post_meta($work_id, '_work_project', $values['project']);
                    }
                });

                /* Optional featured image: upload first, then the Media Library choice */
                $try('The featured image', function () use ($work_id, &$notes) {

                    $done = false;

                    if (isset($_FILES['work_image']) && !empty($_FILES['work_image']['name'])) {

                        if (!empty($_FILES['work_image']['error'])) {

                            $notes[] = 'The image did not upload (it may be too large for the site), so the featured image was left blank.';

                        } else {

                            require_once ABSPATH . 'wp-admin/includes/file.php';
                            require_once ABSPATH . 'wp-admin/includes/media.php';
                            require_once ABSPATH . 'wp-admin/includes/image.php';

                            $attachment_id = media_handle_upload('work_image', $work_id);

                            if (is_wp_error($attachment_id)) {
                                $notes[] = 'The image could not be added (' . $attachment_id->get_error_message() . '), so the featured image was left blank.';
                            } elseif (set_post_thumbnail($work_id, $attachment_id)) {
                                $done = true;
                            } else {
                                $notes[] = 'The image uploaded but could not be set as the featured image. It is in your Media Library.';
                            }
                        }
                    }

                    if (!$done && !empty($_POST['work_image_id']) && current_user_can('upload_files')) {

                        $library_id = absint($_POST['work_image_id']);

                        if ($library_id && wp_attachment_is_image($library_id) && set_post_thumbnail($work_id, $library_id)) {
                            $done = true;
                        } else {
                            $notes[] = 'The Media Library image could not be used, so the featured image was left blank.';
                        }
                    }
                });

                if ($values['title'] === '') {
                    $notes[] = 'The title is blank.';
                }

                if (trim(wp_strip_all_tags($values['content'])) === '') {
                    $notes[] = 'The content is blank.';
                }

                $notes_html = '';

                if ($notes) {

                    $notes_html = '<br><br><strong>Left blank for you to fill in:</strong><ul>';

                    foreach (array_unique($notes) as $note) {
                        $notes_html .= '<li>' . esc_html($note) . '</li>';
                    }

                    $notes_html .= '</ul>';
                }

                $edit_link = '<a href="' . esc_url(get_edit_post_link($work_id, '')) . '">Edit the Work</a>';

                if ($status === 'publish') {

                    $message = '<div class="quick-work-success">
                        <strong>Work published!</strong><br>
                        <a href="' . esc_url(get_permalink($work_id)) . '">View the Work</a> &middot; ' . $edit_link
                        . $notes_html . '
                    </div>';

                } else {

                    $message = '<div class="quick-work-success">
                        <strong>Work saved as a draft!</strong><br>'
                        . $edit_link
                        . $notes_html . '
                    </div>';
                }

                /* Start fresh, ready for the next entry */
                $values = $defaults;
            }
        }
    }


    /* ============================================================
     * Prepare data for the form
     * ============================================================ */

    /* One-time ID so a page refresh cannot create duplicates */
    $form_id = wp_generate_uuid4();
    set_transient('quick_add_work_' . sanitize_key($form_id), 1, DAY_IN_SECONDS);

    $tag_tokens = [];
    foreach ($values['tags'] as $tag_name) {
        $tag_tokens[] = ['id' => $tag_name, 'label' => $tag_name];
    }

    $project_dropdown = wp_dropdown_pages([
        'post_type'         => 'project',
        'post_status'       => ['publish', 'draft', 'private'],
        'selected'          => $values['project'],
        'name'              => 'work_project',
        'id'                => 'quick-work-project',
        'show_option_none'  => '— None —',
        'option_none_value' => '0',
        'sort_column'       => 'menu_order, post_title',
        'echo'              => 0,
    ]);

    $all_series = taxonomy_exists('series')
        ? get_terms(['taxonomy' => 'series', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC'])
        : [];

    if (is_wp_error($all_series)) {
        $all_series = [];
    }

    $categories  = get_categories(['hide_empty' => false]);
    $can_publish = current_user_can('publish_posts');


    /* ============================================================
     * Output form
     * ============================================================ */

    ob_start();
    ?>

    <style>
        /* ---- Size controls: change these numbers to make everything bigger or smaller ---- */
        .quick-add-work { font-size: 1.2rem; line-height: 1.5; }

        .quick-add-work .qp-label { font-weight: 600; display: block; margin: 1.4em 0 .45em; }

        .quick-add-work input[type="text"],
        .quick-add-work textarea,
        .quick-add-work select {
            width: 100%; box-sizing: border-box;
            font-size: 1em; font-family: inherit;
            padding: .7em .8em; min-height: 3em;
        }
        .quick-add-work textarea { line-height: 1.5; }

        .quick-add-work input[type="file"] { font-size: 1em; padding: .6em 0; }

        .quick-add-work button[type="submit"] {
            font-size: 1.05em; font-family: inherit; font-weight: 600;
            padding: .8em 1.8em; min-height: 3em; cursor: pointer;
        }

        .quick-work-success, .quick-work-error, .quick-work-warning {
            padding: .9em 1.1em; margin: 0 0 1.2em; border-left: 4px solid; border-radius: 3px;
            color: #000;
        }
        .quick-work-success a, .quick-work-error a, .quick-work-warning a { color: #000; text-decoration: underline; }
        .quick-work-success { background: #edfaef; border-color: #2e7d32; }
        .quick-work-error   { background: #fcf0f1; border-color: #c62828; }
        .quick-work-warning { background: #fff8e5; border-color: #f0b429; }
        .quick-work-help { font-size: .85em; opacity: .75; margin: .4em 0 0; }

        /* Categories (shown as a hierarchy) */
        .quick-work-cats { max-height: 280px; overflow: auto; border: 1px solid #ccc; padding: .6em .9em; border-radius: 3px; }
        .quick-work-cats label.quick-work-cat { display: block; font-weight: normal; margin: .3em 0; cursor: pointer; }
        .quick-work-cats input[type="checkbox"] { transform: scale(1.25); margin-right: .55em; }

        /* Tag box */
        .quick-work-tokenbox { position: relative; border: 1px solid #ccc; border-radius: 3px; padding: .5em; min-height: 3.2em; box-sizing: border-box; }
        .quick-work-tokenbox .quick-work-token-input { border: 0 !important; outline: 0; box-shadow: none !important; width: 100%; box-sizing: border-box; font-size: 1em; padding: .5em .4em; min-height: 2.4em; }
        .quick-work-chip { display: inline-block; background: #eef1f5; color: #000; border-radius: 4px; padding: .25em .3em .25em .7em; margin: 0 .4em .4em 0; font-size: .95em; }
        .quick-work-chip-remove { background: none; border: 0; color: #000; cursor: pointer; padding: 0 .5em; font-size: 1.2em; line-height: 1; }
        .quick-work-suggestions { display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 20; background: #fff; border: 1px solid #ccc; max-height: 280px; overflow: auto; box-shadow: 0 4px 10px rgba(0,0,0,.12); }
        .quick-work-suggestion { padding: .75em 1em; cursor: pointer; color: #222; font-size: 1em; }
        .quick-work-suggestion:hover { background: #eef1f5; }

        .quick-work-actions { margin-top: 2em; display: flex; gap: 1em; flex-wrap: wrap; }
    </style>

    <div class="quick-add-work">

        <?php echo $message; ?>

        <form
            method="post"
            enctype="multipart/form-data"
            id="quick-work-form"
        >

            <?php wp_nonce_field('quick_add_work', 'quick_add_work_nonce'); ?>

            <input type="hidden" name="work_form_id" value="<?php echo esc_attr($form_id); ?>">


            <!-- Title -->

            <label class="qp-label" for="quick-work-title" style="margin-top:0">Title</label>

            <input
                type="text"
                id="quick-work-title"
                name="work_title"
                value="<?php echo esc_attr($values['title']); ?>"
            >


            <!-- Content -->

            <label class="qp-label" for="quick-work-content">Content</label>

            <textarea
                id="quick-work-content"
                name="work_content"
                rows="12"
            ><?php echo esc_textarea($values['content']); ?></textarea>

            <p class="quick-work-help">
                For an essay, write it here. For a finished piece, a short description is enough.
                The picture goes in the Featured image field below.
            </p>


            <!-- Year -->

            <label class="qp-label" for="quick-work-year">Year</label>

            <input
                type="text"
                id="quick-work-year"
                name="work_year"
                value="<?php echo esc_attr($values['year']); ?>"
                placeholder="e.g. <?php echo esc_attr(gmdate('Y')); ?> (blank = the year it is published)"
            >


            <!-- Materials / size -->

            <label class="qp-label" for="quick-work-details">Materials / size</label>

            <input
                type="text"
                id="quick-work-details"
                name="work_details"
                value="<?php echo esc_attr($values['details']); ?>"
                placeholder="e.g. Collagraph print, 20 x 30 cm"
            >


            <!-- Made in Project -->

            <label class="qp-label" for="quick-work-project">Made in Project</label>

            <?php if ($project_dropdown) : ?>

                <?php echo $project_dropdown; ?>

                <p class="quick-work-help">The piece will then also appear on that Project's page.</p>

            <?php else : ?>

                <p class="quick-work-help">No Projects exist yet.</p>
                <input type="hidden" name="work_project" value="0">

            <?php endif; ?>


            <!-- Series -->

            <?php if (taxonomy_exists('series')) : ?>

                <span class="qp-label">Series (body of work)</span>

                <?php if ($all_series) : ?>

                    <div class="quick-work-cats" style="max-height:200px">

                        <?php foreach ($all_series as $series_term) : ?>

                            <label class="quick-work-cat">
                                <input type="checkbox" name="work_series[]" value="<?php echo esc_attr($series_term->term_id); ?>"<?php checked(in_array((int) $series_term->term_id, $values['series'], true)); ?>>
                                <?php echo esc_html($series_term->name); ?>
                            </label>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

                <input
                    type="text"
                    name="work_series_new"
                    value="<?php echo esc_attr($values['series_new']); ?>"
                    placeholder="Or start a new series: type its name"
                    style="margin-top:.6em"
                >

                <p class="quick-work-help">Tick any that apply. A piece can be in more than one, or none.</p>

            <?php endif; ?>


            <!-- Categories -->

            <?php if ($categories) : ?>

                <span class="qp-label">Categories</span>

                <div class="quick-work-cats">

                    <?php echo quick_add_work_render_categories($categories, $values['cats']); ?>

                </div>

            <?php endif; ?>


            <!-- Tags -->

            <span class="qp-label">Tags</span>

            <div
                class="quick-work-tokenbox"
                data-type="tag"
                data-allow-new="1"
                data-initial="<?php echo esc_attr(wp_json_encode($tag_tokens)); ?>"
            >
                <div class="quick-work-selected"></div>

                <input
                    type="text"
                    class="quick-work-token-input"
                    placeholder="Start typing a tag..."
                    autocomplete="off"
                >

                <div class="quick-work-suggestions"></div>

                <input type="hidden" name="work_tags" class="quick-work-token-hidden" value="">
            </div>

            <p class="quick-work-help">
                Pick a suggestion, or press Enter (or comma) to create a new tag.
            </p>


            <!-- Image -->

            <label class="qp-label" for="quick-work-image">Featured image (the picture of the piece)</label>

            <input
                type="file"
                id="quick-work-image"
                name="work_image"
                accept="image/*"
            >

            <?php if (current_user_can('upload_files')) : ?>

                <p class="qp-help" style="margin:.8em 0 .4em">Or use a picture that is already in your Media Library:</p>

                <input type="hidden" name="work_image_id" id="quick-work-image-id" value="">

                <div id="quick-work-image-preview" style="margin:.4em 0"></div>

                <button type="button" id="quick-work-media-button" class="qp-media-button" style="font-size:1.1em;padding:.7em 1.2em;cursor:pointer">
                    Choose from Media Library
                </button>

                <button type="button" id="quick-work-media-clear" class="qp-media-button" style="font-size:1.1em;padding:.7em 1.2em;cursor:pointer;display:none">
                    Remove
                </button>

            <?php endif; ?>


            <!-- Save -->

            <div class="quick-work-actions">

                <button type="submit" name="quick_add_work_create" value="draft">
                    Save Draft
                </button>

                <?php if ($can_publish) : ?>

                    <button type="submit" name="quick_add_work_create" value="publish">
                        Publish Work
                    </button>

                <?php endif; ?>

            </div>

        </form>

    </div>

    <script>

    document.addEventListener('DOMContentLoaded', function () {

        const form = document.getElementById('quick-work-form');

        if (!form) {
            return;
        }

        /* Media Library picker for the featured image */
        const mediaButton = document.getElementById('quick-work-media-button');

        if (mediaButton && window.wp && wp.media) {

            const mediaClear   = document.getElementById('quick-work-media-clear');
            const mediaId      = document.getElementById('quick-work-image-id');
            const mediaPreview = document.getElementById('quick-work-image-preview');
            let frame = null;

            mediaButton.addEventListener('click', function () {

                if (!frame) {

                    frame = wp.media({
                        title: 'Choose a featured image',
                        button: { text: 'Use this image' },
                        library: { type: 'image' },
                        multiple: false
                    });

                    frame.on('select', function () {

                        const item = frame.state().get('selection').first().toJSON();
                        const size = item.sizes && item.sizes.medium ? item.sizes.medium : item;

                        mediaId.value = item.id;
                        mediaPreview.innerHTML = '';

                        const img = document.createElement('img');
                        img.src = size.url;
                        img.alt = '';
                        img.style.maxWidth = '200px';
                        img.style.height = 'auto';
                        mediaPreview.appendChild(img);

                        mediaClear.style.display = '';

                        const fileInput = document.getElementById('quick-work-image');
                        if (fileInput) {
                            fileInput.value = '';
                        }
                    });
                }

                frame.open();
            });

            mediaClear.addEventListener('click', function () {
                mediaId.value = '';
                mediaPreview.innerHTML = '';
                mediaClear.style.display = 'none';
            });

        } else if (mediaButton) {

            mediaButton.style.display = 'none';
        }

        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const nonce   = <?php echo wp_json_encode(wp_create_nonce('quick_add_work_suggest')); ?>;


        /* Pressing Enter in a single-line field should never submit the form */

        form.addEventListener('keydown', function (event) {

            if (
                event.key === 'Enter' &&
                event.target.tagName === 'INPUT' &&
                event.target.type !== 'submit'
            ) {
                event.preventDefault();
            }
        });


        /* --------------------------------------------------------
         * Token inputs (tags)
         * -------------------------------------------------------- */

        function initTokenBox(box) {

            const type          = box.dataset.type;
            const allowNew      = box.dataset.allowNew === '1';
            const selectedEl    = box.querySelector('.quick-work-selected');
            const input         = box.querySelector('.quick-work-token-input');
            const suggestionsEl = box.querySelector('.quick-work-suggestions');
            const hidden        = box.querySelector('.quick-work-token-hidden');

            let selected = [];
            let current  = [];
            let timer    = null;
            let requestCounter = 0;

            try {
                selected = JSON.parse(box.dataset.initial || '[]');
            } catch (e) {
                selected = [];
            }

            function sync() {
                hidden.value = selected.map(function (item) {
                    return item.id;
                }).join(',');
            }

            function isSelected(id) {
                return selected.some(function (item) {
                    return String(item.id).toLowerCase() === String(id).toLowerCase();
                });
            }

            function render() {

                selectedEl.innerHTML = '';

                selected.forEach(function (item, index) {

                    const chip = document.createElement('span');
                    chip.className = 'quick-work-chip';

                    const text = document.createElement('span');
                    text.textContent = item.label;

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'quick-work-chip-remove';
                    remove.textContent = '×';
                    remove.setAttribute('aria-label', 'Remove ' + item.label);

                    remove.addEventListener('click', function () {
                        selected.splice(index, 1);
                        render();
                        sync();
                    });

                    chip.appendChild(text);
                    chip.appendChild(remove);
                    selectedEl.appendChild(chip);
                });
            }

            function hideSuggestions() {
                suggestionsEl.innerHTML = '';
                suggestionsEl.style.display = 'none';
                current = [];
            }

            function add(item) {

                if (!item || !String(item.id).trim()) {
                    return;
                }

                if (!isSelected(item.id)) {
                    selected.push(item);
                    render();
                    sync();
                }

                input.value = '';
                hideSuggestions();
            }

            function search() {

                const term = input.value.trim();

                if (!term) {
                    hideSuggestions();
                    return;
                }

                const myRequest = ++requestCounter;

                const url = ajaxUrl +
                    '?action=quick_add_work_suggest' +
                    '&nonce=' + encodeURIComponent(nonce) +
                    '&type=' + encodeURIComponent(type) +
                    '&search=' + encodeURIComponent(term);

                fetch(url, { credentials: 'same-origin' })
                    .then(function (response) {
                        return response.json();
                    })
                    .then(function (data) {

                        if (myRequest !== requestCounter) {
                            return;
                        }

                        const results = (data.success && data.data) ? data.data : [];

                        current = results.filter(function (item) {
                            return !isSelected(item.id);
                        });

                        suggestionsEl.innerHTML = '';

                        if (!current.length) {
                            suggestionsEl.style.display = 'none';
                            return;
                        }

                        current.forEach(function (item) {

                            const row = document.createElement('div');
                            row.className = 'quick-work-suggestion';
                            row.textContent = item.label;

                            row.addEventListener('click', function () {
                                add(item);
                            });

                            suggestionsEl.appendChild(row);
                        });

                        suggestionsEl.style.display = 'block';
                    })
                    .catch(function () {
                        hideSuggestions();
                    });
            }

            input.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(search, 200);
            });

            input.addEventListener('keydown', function (event) {

                const isEnter = event.key === 'Enter';
                const isComma = event.key === ',' && allowNew;

                if (isEnter || isComma) {

                    event.preventDefault();

                    const typed = input.value.trim();

                    if (!typed) {
                        return;
                    }

                    const exact = current.find(function (item) {
                        return item.label.toLowerCase() === typed.toLowerCase();
                    });

                    if (exact) {
                        add(exact);
                    } else if (allowNew) {
                        add({ id: typed, label: typed });
                    } else if (current.length) {
                        add(current[0]);
                    }

                } else if (
                    event.key === 'Backspace' &&
                    !input.value &&
                    selected.length
                ) {

                    selected.pop();
                    render();
                    sync();
                }
            });

            document.addEventListener('click', function (event) {
                if (!box.contains(event.target)) {
                    hideSuggestions();
                }
            });

            render();
            sync();
        }

        document.querySelectorAll('.quick-work-tokenbox').forEach(initTokenBox);

    });

    </script>

    <?php

    return ob_get_clean();
}

add_shortcode('add_work', 'quick_add_work_shortcode');
