/* Shortcode: [add_journal]
 * Quick-entry form for Journal entries.
 * Title and content are required. Projects, categories, tags and a featured
 * image are optional. Requires the "Project Journal" snippet (it creates the
 * Journal type) and the Projects snippet to be active.
 *
 * Tip: link to  your-add-journal-page/?project=123  (123 = a Project's ID)
 * to open the form with that Project already ticked.
 */


/* ============================================================
 * AJAX: suggestions for tags and for projects
 * ============================================================ */

function quick_add_journal_ajax_suggest() {

    check_ajax_referer('quick_add_journal_suggest', 'nonce');

    if (!is_user_logged_in() || !current_user_can('edit_posts')) {
        wp_send_json_error();
    }

    $type = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';

    $search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';

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
                $results[] = ['id' => $term->name, 'label' => $term->name];
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

add_action('wp_ajax_quick_add_journal_suggest', 'quick_add_journal_ajax_suggest');


/* ============================================================
 * Helper: category checkboxes, indented to show the hierarchy
 * ============================================================ */

function quick_add_journal_render_categories($categories, $selected) {

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

            $html .= '<label class="quick-journal-cat" style="padding-left:' . ($depth * 1.6) . 'em">'
                . '<input type="checkbox" name="journal_cats[]" value="' . esc_attr($category->term_id) . '"'
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
 * The [add_journal] shortcode
 * ============================================================ */

function quick_add_journal_shortcode() {

    if (!is_user_logged_in()) {
        return '<p>You must be logged in to add a Journal entry.</p>';
    }

    if (!current_user_can('edit_posts')) {
        return '<p>You do not have permission to add Journal entries.</p>';
    }

    if (!post_type_exists('journal')) {
        return '<p>The Journal type is not active. Please turn on the "Project Journal" snippet first.</p>';
    }

    if (current_user_can('upload_files')) {
        wp_enqueue_media();
    }

    $message = '';

    $defaults = [
        'title'    => '',
        'content'  => '',
        'projects' => [],
        'cats'     => [],
        'tags'     => [],
    ];

    $values = $defaults;

    /* Open with a Project ticked: ?project=123 */
    if (isset($_GET['project'])) {

        $preset = absint($_GET['project']);

        if ($preset && get_post_type($preset) === 'project') {
            $values['projects'][] = $preset;
        }
    }

    $submitted = (
        isset($_POST['quick_add_journal_create']) &&
        isset($_POST['quick_add_journal_nonce']) &&
        wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['quick_add_journal_nonce'])),
            'quick_add_journal'
        )
    );

    if ($submitted) {

        $values['projects'] = [];

        $values['title'] = isset($_POST['journal_title'])
            ? sanitize_text_field(wp_unslash($_POST['journal_title']))
            : '';

        $values['content'] = isset($_POST['journal_content'])
            ? wp_kses_post(wp_unslash($_POST['journal_content']))
            : '';

        if (isset($_POST['journal_projects'])) {
            $ids = explode(',', sanitize_text_field(wp_unslash($_POST['journal_projects'])));
            foreach (array_filter(array_map('absint', $ids)) as $project_id) {
                if (get_post_type($project_id) === 'project') {
                    $values['projects'][] = $project_id;
                }
            }
        }

        if (isset($_POST['journal_cats'])) {
            foreach ((array) $_POST['journal_cats'] as $cat_id) {
                $cat_id = absint($cat_id);
                if ($cat_id && term_exists($cat_id, 'category')) {
                    $values['cats'][] = $cat_id;
                }
            }
        }

        if (isset($_POST['journal_tags'])) {
            $values['tags'] = array_values(array_filter(array_map(
                'trim',
                explode(',', sanitize_text_field(wp_unslash($_POST['journal_tags'])))
            )));
        }

        $status = sanitize_key(wp_unslash($_POST['quick_add_journal_create']));

        if ($status !== 'publish' || !current_user_can('publish_posts')) {
            $status = 'draft';
        }

        $form_id = isset($_POST['journal_form_id'])
            ? sanitize_key(wp_unslash($_POST['journal_form_id']))
            : '';

        if ($form_id === '' || !get_transient('quick_add_journal_' . $form_id)) {

            $message = '<div class="quick-journal-warning">
                This form was already submitted (or has expired), so nothing new was created.
                If you meant to add another entry, you can save again.
            </div>';

        } elseif ($values['title'] === '') {

            $message = '<div class="quick-journal-error">Please enter a title.</div>';

        } elseif (trim(wp_strip_all_tags($values['content'])) === '') {

            $message = '<div class="quick-journal-error">Please enter some content.</div>';

        } else {

            delete_transient('quick_add_journal_' . $form_id);

            $entry_id = wp_insert_post([
                'post_type'    => 'journal',
                'post_title'   => $values['title'],
                'post_content' => $values['content'],
                'post_status'  => $status,
            ], true);

            if (is_wp_error($entry_id)) {

                $message = '<div class="quick-journal-error">
                    There was a problem creating the entry:
                    ' . esc_html($entry_id->get_error_message()) . '
                </div>';

            } else {

                foreach (array_unique($values['projects']) as $project_id) {
                    add_post_meta($entry_id, '_post_project', $project_id);
                }

                if ($values['cats']) {
                    wp_set_post_categories($entry_id, $values['cats']);
                }

                if ($values['tags']) {
                    wp_set_post_tags($entry_id, $values['tags'], false);
                }

                /* Featured image: an uploaded file, or one picked from the Media Library */
                if (isset($_FILES['journal_image']) && !empty($_FILES['journal_image']['name'])) {

                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';

                    $attachment_id = media_handle_upload('journal_image', $entry_id);

                    if (!is_wp_error($attachment_id)) {
                        set_post_thumbnail($entry_id, $attachment_id);
                    }

                } elseif (!empty($_POST['journal_image_id']) && current_user_can('upload_files')) {

                    $library_id = absint($_POST['journal_image_id']);

                    if ($library_id && wp_attachment_is_image($library_id)) {
                        set_post_thumbnail($entry_id, $library_id);
                    }
                }

                if ($status === 'publish') {

                    $message = '<div class="quick-journal-success">
                        <strong>Journal entry published!</strong><br>
                        <a href="' . esc_url(get_permalink($entry_id)) . '">View the entry</a>
                    </div>';

                } else {

                    $message = '<div class="quick-journal-success">
                        <strong>Journal entry saved as a draft!</strong><br>
                        <a href="' . esc_url(get_edit_post_link($entry_id, '')) . '">Edit the draft</a>
                    </div>';
                }

                /* Start fresh, but keep the Projects ticked for the next entry */
                $kept_projects = $values['projects'];
                $values = $defaults;
                $values['projects'] = $kept_projects;
            }
        }
    }


    /* ============================================================
     * Prepare data for the form
     * ============================================================ */

    /* One-time ID so a page refresh cannot create duplicates */
    $form_id = wp_generate_uuid4();
    set_transient('quick_add_journal_' . sanitize_key($form_id), 1, DAY_IN_SECONDS);

    $tag_tokens = [];
    foreach ($values['tags'] as $tag_name) {
        $tag_tokens[] = ['id' => $tag_name, 'label' => $tag_name];
    }

    $project_tokens = [];
    foreach ($values['projects'] as $project_id) {
        $project_tokens[] = [
            'id'    => (string) $project_id,
            'label' => get_the_title($project_id) ?: '(no title)',
        ];
    }

    $categories  = get_categories(['hide_empty' => false]);
    $can_publish = current_user_can('publish_posts');
    $can_upload  = current_user_can('upload_files');


    /* ============================================================
     * Output form
     * ============================================================ */

    ob_start();
    ?>

    <style>
        .quick-add-journal { font-size: 1.2rem; line-height: 1.5; }

        .quick-add-journal .qj-label { font-weight: 600; display: block; margin: 1.4em 0 .45em; }

        .quick-add-journal input[type="text"],
        .quick-add-journal textarea {
            width: 100%; box-sizing: border-box;
            font-size: 1em; font-family: inherit;
            padding: .7em .8em; min-height: 3em;
        }
        .quick-add-journal textarea { line-height: 1.5; }

        .quick-add-journal input[type="file"] { font-size: 1em; padding: .6em 0; }

        .quick-add-journal button[type="submit"],
        .quick-add-journal .qj-media-button {
            font-size: 1.05em; font-family: inherit; font-weight: 600;
            padding: .8em 1.8em; min-height: 3em; cursor: pointer;
        }

        .quick-journal-success, .quick-journal-error, .quick-journal-warning {
            padding: .9em 1.1em; margin: 0 0 1.2em; border-left: 4px solid; border-radius: 3px;
            color: #000;
        }
        .quick-journal-success a, .quick-journal-error a, .quick-journal-warning a { color: #000; text-decoration: underline; }
        .quick-journal-success { background: #edfaef; border-color: #2e7d32; }
        .quick-journal-error   { background: #fcf0f1; border-color: #c62828; }
        .quick-journal-warning { background: #fff8e5; border-color: #f0b429; }
        .quick-journal-help { font-size: .85em; opacity: .75; margin: .4em 0 0; }

        .quick-journal-cats { max-height: 280px; overflow: auto; border: 1px solid #ccc; padding: .6em .9em; border-radius: 3px; }
        .quick-journal-cats label.quick-journal-cat { display: block; font-weight: normal; margin: .3em 0; cursor: pointer; }
        .quick-journal-cats input[type="checkbox"] { transform: scale(1.25); margin-right: .55em; }

        .quick-journal-tokenbox { position: relative; border: 1px solid #ccc; border-radius: 3px; padding: .5em; min-height: 3.2em; box-sizing: border-box; }
        .quick-journal-tokenbox .quick-journal-token-input { border: 0 !important; outline: 0; box-shadow: none !important; width: 100%; box-sizing: border-box; font-size: 1em; padding: .5em .4em; min-height: 2.4em; }
        .quick-journal-chip { display: inline-block; background: #eef1f5; color: #000; border-radius: 4px; padding: .25em .3em .25em .7em; margin: 0 .4em .4em 0; font-size: .95em; }
        .quick-journal-chip-remove { background: none; border: 0; color: #000; cursor: pointer; padding: 0 .5em; font-size: 1.2em; line-height: 1; }
        .quick-journal-suggestions { display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 20; background: #fff; border: 1px solid #ccc; max-height: 280px; overflow: auto; box-shadow: 0 4px 10px rgba(0,0,0,.12); }
        .quick-journal-suggestion { padding: .75em 1em; cursor: pointer; color: #222; font-size: 1em; }
        .quick-journal-suggestion:hover { background: #eef1f5; }

        .quick-journal-actions { margin-top: 2em; display: flex; gap: 1em; flex-wrap: wrap; }
    </style>

    <div class="quick-add-journal">

        <?php echo $message; ?>

        <form method="post" enctype="multipart/form-data" id="quick-journal-form">

            <?php wp_nonce_field('quick_add_journal', 'quick_add_journal_nonce'); ?>

            <input type="hidden" name="journal_form_id" value="<?php echo esc_attr($form_id); ?>">


            <!-- Title -->

            <label class="qj-label" for="quick-journal-title" style="margin-top:0">Title *</label>

            <input type="text" id="quick-journal-title" name="journal_title" value="<?php echo esc_attr($values['title']); ?>" required>


            <!-- Content -->

            <label class="qj-label" for="quick-journal-content">Content *</label>

            <textarea id="quick-journal-content" name="journal_content" rows="12" required><?php echo esc_textarea($values['content']); ?></textarea>

            <p class="quick-journal-help">
                Tip: type [[Project Title]] to link to a Project.
            </p>


            <!-- Projects -->

            <span class="qj-label">Projects</span>

            <div
                class="quick-journal-tokenbox"
                data-type="project"
                data-allow-new="0"
                data-initial="<?php echo esc_attr(wp_json_encode($project_tokens)); ?>"
            >
                <div class="quick-journal-selected"></div>

                <input type="text" class="quick-journal-token-input" placeholder="Start typing a project title..." autocomplete="off">

                <div class="quick-journal-suggestions"></div>

                <input type="hidden" name="journal_projects" class="quick-journal-token-hidden" value="">
            </div>

            <p class="quick-journal-help">
                Add as many as apply. This entry will appear in the journal of each one.
            </p>


            <!-- Categories -->

            <?php if ($categories) : ?>

                <span class="qj-label">Categories</span>

                <div class="quick-journal-cats">

                    <?php echo quick_add_journal_render_categories($categories, $values['cats']); ?>

                </div>

            <?php endif; ?>


            <!-- Tags -->

            <span class="qj-label">Tags</span>

            <div
                class="quick-journal-tokenbox"
                data-type="tag"
                data-allow-new="1"
                data-initial="<?php echo esc_attr(wp_json_encode($tag_tokens)); ?>"
            >
                <div class="quick-journal-selected"></div>

                <input type="text" class="quick-journal-token-input" placeholder="Start typing a tag..." autocomplete="off">

                <div class="quick-journal-suggestions"></div>

                <input type="hidden" name="journal_tags" class="quick-journal-token-hidden" value="">
            </div>

            <p class="quick-journal-help">
                Pick a suggestion, or press Enter (or comma) to create a new tag.
            </p>


            <!-- Image -->

            <label class="qj-label" for="quick-journal-image">Featured image (optional)</label>

            <input type="file" id="quick-journal-image" name="journal_image" accept="image/*">

            <?php if ($can_upload) : ?>

                <p class="quick-journal-help">Or use a picture that is already in your Media Library:</p>

                <input type="hidden" name="journal_image_id" id="quick-journal-image-id" value="">

                <div id="quick-journal-image-preview" style="margin:.4em 0"></div>

                <button type="button" id="quick-journal-media-button" class="qj-media-button">Choose from Media Library</button>

                <button type="button" id="quick-journal-media-clear" class="qj-media-button" style="display:none">Remove</button>

            <?php endif; ?>

            <p class="quick-journal-help">
                If you add no featured image, the first image in the entry is used.
            </p>


            <!-- Save -->

            <div class="quick-journal-actions">

                <button type="submit" name="quick_add_journal_create" value="draft">
                    Save Draft
                </button>

                <?php if ($can_publish) : ?>

                    <button type="submit" name="quick_add_journal_create" value="publish">
                        Publish Entry
                    </button>

                <?php endif; ?>

            </div>

        </form>

    </div>

    <script>

    document.addEventListener('DOMContentLoaded', function () {

        const form = document.getElementById('quick-journal-form');

        if (!form) {
            return;
        }

        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const nonce   = <?php echo wp_json_encode(wp_create_nonce('quick_add_journal_suggest')); ?>;


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
         * Media Library picker for the featured image
         * -------------------------------------------------------- */

        const mediaButton = document.getElementById('quick-journal-media-button');

        if (mediaButton && window.wp && wp.media) {

            const mediaClear   = document.getElementById('quick-journal-media-clear');
            const mediaId      = document.getElementById('quick-journal-image-id');
            const mediaPreview = document.getElementById('quick-journal-image-preview');
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

                        const fileInput = document.getElementById('quick-journal-image');
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


        /* --------------------------------------------------------
         * Token inputs (projects and tags)
         * -------------------------------------------------------- */

        function initTokenBox(box) {

            const type          = box.dataset.type;
            const allowNew      = box.dataset.allowNew === '1';
            const selectedEl    = box.querySelector('.quick-journal-selected');
            const input         = box.querySelector('.quick-journal-token-input');
            const suggestionsEl = box.querySelector('.quick-journal-suggestions');
            const hidden        = box.querySelector('.quick-journal-token-hidden');

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
                    chip.className = 'quick-journal-chip';

                    const text = document.createElement('span');
                    text.textContent = item.label;

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'quick-journal-chip-remove';
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
                    '?action=quick_add_journal_suggest' +
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
                            row.className = 'quick-journal-suggestion';
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

        document.querySelectorAll('.quick-journal-tokenbox').forEach(initTokenBox);

    });

    </script>

    <?php

    return ob_get_clean();
}

add_shortcode('add_journal', 'quick_add_journal_shortcode');
