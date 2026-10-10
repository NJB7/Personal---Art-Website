/* Shortcode: [add_project]
 * Quick-entry form for the "Project" wiki post type.
 * Title and content are required. Parent project, categories, tags,
 * related projects and a featured image are optional.
 * Requires the "Projects Wiki" snippet to be active.
 */


/* ============================================================
 * AJAX: suggestions for tags and for projects
 * ============================================================ */

function quick_add_project_ajax_suggest() {

    check_ajax_referer('quick_add_project_suggest', 'nonce');

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

    } elseif ($type === 'project') {

        $projects = get_posts([
            'post_type'      => 'project',
            'post_status'    => ['publish', 'draft', 'private', 'pending'],
            'posts_per_page' => 10,
            's'              => $search,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        foreach ($projects as $project) {
            $results[] = [
                'id'    => (string) $project->ID,
                'label' => $project->post_title !== '' ? $project->post_title : '(no title)',
            ];
        }
    }

    wp_send_json_success($results);
}

add_action(
    'wp_ajax_quick_add_project_suggest',
    'quick_add_project_ajax_suggest'
);


/* ============================================================
 * Helper: save related projects (two-way links)
 * Uses the same "_project_related" field as the Projects Wiki snippet.
 * ============================================================ */

function quick_add_project_set_related($post_id, $related_ids) {

    $related_ids = array_map('absint', (array) $related_ids);
    $related_ids = array_values(array_unique(array_filter($related_ids)));

    $related_ids = array_values(array_filter(
        $related_ids,
        function ($id) use ($post_id) {
            return $id !== (int) $post_id && get_post_type($id) === 'project';
        }
    ));

    update_post_meta($post_id, '_project_related', $related_ids);

    foreach ($related_ids as $other) {

        $list = get_post_meta($other, '_project_related', true);
        $list = is_array($list) ? $list : [];

        if (!in_array((int) $post_id, $list, true)) {
            $list[] = (int) $post_id;
            update_post_meta($other, '_project_related', $list);
        }
    }
}


/* ============================================================
 * Helper: category checkboxes, indented to show the hierarchy
 * ============================================================ */

function quick_add_project_render_categories($categories, $selected) {

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

            $html .= '<label class="quick-project-cat" style="padding-left:' . ($depth * 1.6) . 'em">'
                . '<input type="checkbox" name="project_cats[]" value="' . esc_attr($category->term_id) . '"'
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
 * The [add_project] shortcode
 * ============================================================ */

function quick_add_project_shortcode() {

    if (!is_user_logged_in()) {
        return '<p>You must be logged in to add a Project.</p>';
    }

    if (!current_user_can('edit_posts')) {
        return '<p>You do not have permission to add Projects.</p>';
    }

    if (!post_type_exists('project')) {
        return '<p>The Project post type is not active. Please activate the Projects Wiki snippet first.</p>';
    }

    /* Loads the Media Library picker used by the featured image field */
    if (current_user_can('upload_files')) {
        wp_enqueue_media();
    }

    $message = '';

    $defaults = [
        'title'   => '',
        'content' => '',
        'parent'  => 0,
        'pstatus' => '',
        'cats'    => [],
        'tags'    => [],
        'related' => [],
        'links'   => '',
    ];

    $values = $defaults;

    $submitted = (
        isset($_POST['quick_add_project_create']) &&
        isset($_POST['quick_add_project_nonce']) &&
        wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['quick_add_project_nonce'])),
            'quick_add_project'
        )
    );

    if ($submitted) {

        /* ----------------------------------------------------
         * Read whatever was posted
         * ---------------------------------------------------- */

        $values['title'] = isset($_POST['project_title'])
            ? sanitize_text_field(wp_unslash($_POST['project_title']))
            : '';

        $values['content'] = isset($_POST['project_content'])
            ? wp_kses_post(wp_unslash($_POST['project_content']))
            : '';

        $parent = isset($_POST['project_parent'])
            ? absint($_POST['project_parent'])
            : 0;

        if ($parent && get_post_type($parent) === 'project') {
            $values['parent'] = $parent;
        }

        /* Project status (Idea / In progress / Finished), from the Project Journal snippet */
        $posted_status = isset($_POST['project_status'])
            ? sanitize_key(wp_unslash($_POST['project_status']))
            : '';

        if (function_exists('pj_statuses') && isset(pj_statuses()[$posted_status])) {
            $values['pstatus'] = $posted_status;
        }

        if (isset($_POST['project_cats'])) {
            foreach ((array) $_POST['project_cats'] as $cat_id) {
                $cat_id = absint($cat_id);
                if ($cat_id && term_exists($cat_id, 'category')) {
                    $values['cats'][] = $cat_id;
                }
            }
        }

        if (isset($_POST['project_tags'])) {
            $values['tags'] = array_values(array_filter(array_map(
                'trim',
                explode(',', sanitize_text_field(wp_unslash($_POST['project_tags'])))
            )));
        }

        if (isset($_POST['project_related'])) {
            $ids = explode(',', sanitize_text_field(wp_unslash($_POST['project_related'])));
            foreach (array_filter(array_map('absint', $ids)) as $related_id) {
                if (get_post_type($related_id) === 'project') {
                    $values['related'][] = $related_id;
                }
            }
        }

        $values['links'] = isset($_POST['project_links'])
            ? sanitize_textarea_field(wp_unslash($_POST['project_links']))
            : '';

        $status = sanitize_key(wp_unslash($_POST['quick_add_project_create']));

        if ($status !== 'publish' || !current_user_can('publish_posts')) {
            $status = 'draft';
        }

        $form_id = isset($_POST['project_form_id'])
            ? sanitize_key(wp_unslash($_POST['project_form_id']))
            : '';


        /* ----------------------------------------------------
         * Validate and create
         * ---------------------------------------------------- */

        if ($form_id === '' || !get_transient('quick_add_project_' . $form_id)) {

            $message = '<div class="quick-project-warning">
                This form was already submitted (or has expired), so nothing new was created.
                If you meant to add another entry, you can save again.
            </div>';

        } elseif ($values['title'] === '') {

            $message = '<div class="quick-project-error">Please enter a title.</div>';

        } elseif (trim(wp_strip_all_tags($values['content'])) === '') {

            $message = '<div class="quick-project-error">Please enter some content.</div>';

        } else {

            delete_transient('quick_add_project_' . $form_id);

            $project_id = wp_insert_post([
                'post_type'    => 'project',
                'post_title'   => $values['title'],
                'post_content' => $values['content'],
                'post_status'  => $status,
                'post_parent'  => $values['parent'],
            ], true);

            if (is_wp_error($project_id)) {

                $message = '<div class="quick-project-error">
                    There was a problem creating the Project:
                    ' . esc_html($project_id->get_error_message()) . '
                </div>';

            } else {

                if ($values['pstatus'] !== '') {
                    update_post_meta($project_id, '_project_status', $values['pstatus']);
                }

                if ($values['cats']) {
                    wp_set_post_categories($project_id, $values['cats']);
                }

                if ($values['tags']) {
                    wp_set_post_tags($project_id, $values['tags'], false);
                }

                if ($values['related']) {
                    quick_add_project_set_related($project_id, $values['related']);
                }

                if ($values['links'] !== '' && function_exists('pw_parse_links')) {

                    $parsed_links = pw_parse_links($values['links']);

                    if ($parsed_links) {
                        update_post_meta($project_id, '_project_links', $parsed_links);
                    }
                }

                /* Optional featured image */
                if (
                    isset($_FILES['project_image']) &&
                    !empty($_FILES['project_image']['name'])
                ) {

                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';

                    $attachment_id = media_handle_upload('project_image', $project_id);

                    if (!is_wp_error($attachment_id)) {
                        set_post_thumbnail($project_id, $attachment_id);
                    }

                } elseif (!empty($_POST['project_image_id']) && current_user_can('upload_files')) {

                    /* Image picked from the Media Library instead of uploaded */
                    $library_id = absint($_POST['project_image_id']);

                    if ($library_id && wp_attachment_is_image($library_id)) {
                        set_post_thumbnail($project_id, $library_id);
                    }
                }

                if ($status === 'publish') {

                    $message = '<div class="quick-project-success">
                        <strong>Project published!</strong><br>
                        <a href="' . esc_url(get_permalink($project_id)) . '">View the Project</a>
                    </div>';

                } else {

                    $message = '<div class="quick-project-success">
                        <strong>Project saved as a draft!</strong><br>
                        <a href="' . esc_url(get_edit_post_link($project_id, '')) . '">Edit the draft Project</a>
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
    set_transient('quick_add_project_' . sanitize_key($form_id), 1, DAY_IN_SECONDS);

    $tag_tokens = [];
    foreach ($values['tags'] as $tag_name) {
        $tag_tokens[] = ['id' => $tag_name, 'label' => $tag_name];
    }

    $related_tokens = [];
    foreach ($values['related'] as $related_id) {
        $related_tokens[] = [
            'id'    => (string) $related_id,
            'label' => get_the_title($related_id) ?: '(no title)',
        ];
    }

    $parent_dropdown = wp_dropdown_pages([
        'post_type'         => 'project',
        'post_status'       => ['publish', 'draft', 'private'],
        'selected'          => $values['parent'],
        'name'              => 'project_parent',
        'id'                => 'quick-project-parent',
        'show_option_none'  => '— None (top level) —',
        'option_none_value' => '0',
        'sort_column'       => 'menu_order, post_title',
        'echo'              => 0,
    ]);

    $categories  = get_categories(['hide_empty' => false]);
    $can_publish = current_user_can('publish_posts');


    /* ============================================================
     * Output form
     * ============================================================ */

    ob_start();
    ?>

    <style>
        /* ---- Size controls: change these numbers to make everything bigger or smaller ---- */
        .quick-add-project { font-size: 1.2rem; line-height: 1.5; }

        .quick-add-project .qp-label { font-weight: 600; display: block; margin: 1.4em 0 .45em; }

        .quick-add-project input[type="text"],
        .quick-add-project textarea,
        .quick-add-project select {
            width: 100%; box-sizing: border-box;
            font-size: 1em; font-family: inherit;
            padding: .7em .8em; min-height: 3em;
        }
        .quick-add-project textarea { line-height: 1.5; }

        .quick-add-project input[type="file"] { font-size: 1em; padding: .6em 0; }

        .quick-add-project button[type="submit"] {
            font-size: 1.05em; font-family: inherit; font-weight: 600;
            padding: .8em 1.8em; min-height: 3em; cursor: pointer;
        }

        .quick-project-success, .quick-project-error, .quick-project-warning {
            padding: .9em 1.1em; margin: 0 0 1.2em; border-left: 4px solid; border-radius: 3px;
            color: #000;
        }
        .quick-project-success a, .quick-project-error a, .quick-project-warning a { color: #000; text-decoration: underline; }
        .quick-project-success { background: #edfaef; border-color: #2e7d32; }
        .quick-project-error   { background: #fcf0f1; border-color: #c62828; }
        .quick-project-warning { background: #fff8e5; border-color: #f0b429; }
        .quick-project-help { font-size: .85em; opacity: .75; margin: .4em 0 0; }

        /* Categories (shown as a hierarchy) */
        .quick-project-cats { max-height: 280px; overflow: auto; border: 1px solid #ccc; padding: .6em .9em; border-radius: 3px; }
        .quick-project-cats label.quick-project-cat { display: block; font-weight: normal; margin: .3em 0; cursor: pointer; }
        .quick-project-cats input[type="checkbox"] { transform: scale(1.25); margin-right: .55em; }

        /* Tag and related-project boxes */
        .quick-project-tokenbox { position: relative; border: 1px solid #ccc; border-radius: 3px; padding: .5em; min-height: 3.2em; box-sizing: border-box; }
        .quick-project-tokenbox .quick-project-token-input { border: 0 !important; outline: 0; box-shadow: none !important; width: 100%; box-sizing: border-box; font-size: 1em; padding: .5em .4em; min-height: 2.4em; }
        .quick-project-chip { display: inline-block; background: #eef1f5; color: #000; border-radius: 4px; padding: .25em .3em .25em .7em; margin: 0 .4em .4em 0; font-size: .95em; }
        .quick-project-chip-remove { background: none; border: 0; color: #000; cursor: pointer; padding: 0 .5em; font-size: 1.2em; line-height: 1; }
        .quick-project-suggestions { display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 20; background: #fff; border: 1px solid #ccc; max-height: 280px; overflow: auto; box-shadow: 0 4px 10px rgba(0,0,0,.12); }
        .quick-project-suggestion { padding: .75em 1em; cursor: pointer; color: #222; font-size: 1em; }
        .quick-project-suggestion:hover { background: #eef1f5; }

        .quick-project-actions { margin-top: 2em; display: flex; gap: 1em; flex-wrap: wrap; }
    </style>

    <div class="quick-add-project">

        <?php echo $message; ?>

        <form
            method="post"
            enctype="multipart/form-data"
            id="quick-project-form"
        >

            <?php wp_nonce_field('quick_add_project', 'quick_add_project_nonce'); ?>

            <input type="hidden" name="project_form_id" value="<?php echo esc_attr($form_id); ?>">


            <!-- Title -->

            <label class="qp-label" for="quick-project-title" style="margin-top:0">Title *</label>

            <input
                type="text"
                id="quick-project-title"
                name="project_title"
                value="<?php echo esc_attr($values['title']); ?>"
                required
            >


            <!-- Content -->

            <label class="qp-label" for="quick-project-content">Content *</label>

            <textarea
                id="quick-project-content"
                name="project_content"
                rows="12"
                required
            ><?php echo esc_textarea($values['content']); ?></textarea>

            <p class="quick-project-help">
                Tip: type [[Another Project Title]] to link to another Project.
            </p>


            <!-- Parent -->

            <label class="qp-label" for="quick-project-parent">Parent project</label>

            <?php if ($parent_dropdown) : ?>

                <?php echo $parent_dropdown; ?>

            <?php else : ?>

                <p class="quick-project-help">No Projects exist yet, so this one will be top level.</p>
                <input type="hidden" name="project_parent" value="0">

            <?php endif; ?>


            <!-- Status -->

            <?php if (function_exists('pj_statuses')) : ?>

                <label class="qp-label" for="quick-project-status">Status</label>

                <select id="quick-project-status" name="project_status">

                    <option value="">Not set</option>

                    <?php foreach (pj_statuses() as $status_key => $status_label) : ?>

                        <option value="<?php echo esc_attr($status_key); ?>"<?php selected($values['pstatus'], $status_key); ?>>
                            <?php echo esc_html($status_label); ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            <?php endif; ?>


            <!-- Categories -->

            <?php if ($categories) : ?>

                <span class="qp-label">Categories</span>

                <div class="quick-project-cats">

                    <?php echo quick_add_project_render_categories($categories, $values['cats']); ?>

                </div>

            <?php endif; ?>


            <!-- Tags -->

            <span class="qp-label">Tags</span>

            <div
                class="quick-project-tokenbox"
                data-type="tag"
                data-allow-new="1"
                data-initial="<?php echo esc_attr(wp_json_encode($tag_tokens)); ?>"
            >
                <div class="quick-project-selected"></div>

                <input
                    type="text"
                    class="quick-project-token-input"
                    placeholder="Start typing a tag..."
                    autocomplete="off"
                >

                <div class="quick-project-suggestions"></div>

                <input type="hidden" name="project_tags" class="quick-project-token-hidden" value="">
            </div>

            <p class="quick-project-help">
                Pick a suggestion, or press Enter (or comma) to create a new tag.
            </p>


            <!-- Related projects -->

            <span class="qp-label">Related projects</span>

            <div
                class="quick-project-tokenbox"
                data-type="project"
                data-allow-new="0"
                data-initial="<?php echo esc_attr(wp_json_encode($related_tokens)); ?>"
            >
                <div class="quick-project-selected"></div>

                <input
                    type="text"
                    class="quick-project-token-input"
                    placeholder="Start typing a project title..."
                    autocomplete="off"
                >

                <div class="quick-project-suggestions"></div>

                <input type="hidden" name="project_related" class="quick-project-token-hidden" value="">
            </div>

            <p class="quick-project-help">
                Links are two-way: the other project will link back to this one.
            </p>


            <!-- External links -->

            <label class="qp-label" for="quick-project-links">External links (optional)</label>

            <textarea
                id="quick-project-links"
                name="project_links"
                rows="4"
                placeholder="https://en.wikipedia.org/wiki/Cyanotype | Wikipedia&#10;https://www.youtube.com/watch?v=..."
            ><?php echo esc_textarea($values['links']); ?></textarea>

            <p class="quick-project-help">
                One link per line. Add a label after a | if you like.
                YouTube and Vimeo links are also shown as videos on the page.
            </p>


            <!-- Image -->

            <label class="qp-label" for="quick-project-image">Featured image (optional)</label>

            <input
                type="file"
                id="quick-project-image"
                name="project_image"
                accept="image/*"
            >

            <?php if (current_user_can('upload_files')) : ?>

                <p class="qp-help" style="margin:.8em 0 .4em">Or use a picture that is already in your Media Library:</p>

                <input type="hidden" name="project_image_id" id="quick-project-image-id" value="">

                <div id="quick-project-image-preview" style="margin:.4em 0"></div>

                <button type="button" id="quick-project-media-button" class="qp-media-button" style="font-size:1.1em;padding:.7em 1.2em;cursor:pointer">
                    Choose from Media Library
                </button>

                <button type="button" id="quick-project-media-clear" class="qp-media-button" style="font-size:1.1em;padding:.7em 1.2em;cursor:pointer;display:none">
                    Remove
                </button>

            <?php endif; ?>


            <!-- Save -->

            <div class="quick-project-actions">

                <button type="submit" name="quick_add_project_create" value="draft">
                    Save Draft
                </button>

                <?php if ($can_publish) : ?>

                    <button type="submit" name="quick_add_project_create" value="publish">
                        Publish Project
                    </button>

                <?php endif; ?>

            </div>

        </form>

    </div>

    <script>

    document.addEventListener('DOMContentLoaded', function () {

        const form = document.getElementById('quick-project-form');

        if (!form) {
            return;
        }

        /* Media Library picker for the featured image */
        const mediaButton = document.getElementById('quick-project-media-button');

        if (mediaButton && window.wp && wp.media) {

            const mediaClear   = document.getElementById('quick-project-media-clear');
            const mediaId      = document.getElementById('quick-project-image-id');
            const mediaPreview = document.getElementById('quick-project-image-preview');
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

                        const fileInput = document.getElementById('quick-project-image');
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
        const nonce   = <?php echo wp_json_encode(wp_create_nonce('quick_add_project_suggest')); ?>;


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
         * Token inputs (tags and related projects)
         * -------------------------------------------------------- */

        function initTokenBox(box) {

            const type          = box.dataset.type;
            const allowNew      = box.dataset.allowNew === '1';
            const selectedEl    = box.querySelector('.quick-project-selected');
            const input         = box.querySelector('.quick-project-token-input');
            const suggestionsEl = box.querySelector('.quick-project-suggestions');
            const hidden        = box.querySelector('.quick-project-token-hidden');

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
                    chip.className = 'quick-project-chip';

                    const text = document.createElement('span');
                    text.textContent = item.label;

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'quick-project-chip-remove';
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
                    '?action=quick_add_project_suggest' +
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
                            row.className = 'quick-project-suggestion';
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

        document.querySelectorAll('.quick-project-tokenbox').forEach(initTokenBox);

    });

    </script>

    <?php

    return ob_get_clean();
}

add_shortcode('add_project', 'quick_add_project_shortcode');
