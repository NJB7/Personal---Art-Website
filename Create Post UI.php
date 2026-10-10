/* Shortcode: [add_post]
 * Quick-entry form for standard WordPress posts.
 * Title and content are required. Categories, tags and a
 * featured image are optional.
 * No other snippets or plugins are required.
 */


/* ============================================================
 * AJAX: tag suggestions
 * ============================================================ */

function quick_add_post_ajax_suggest() {

    check_ajax_referer('quick_add_post_suggest', 'nonce');

    if (!is_user_logged_in() || !current_user_can('edit_posts')) {
        wp_send_json_error();
    }

    $search = isset($_GET['search'])
        ? sanitize_text_field(wp_unslash($_GET['search']))
        : '';

    if ($search === '') {
        wp_send_json_success([]);
    }

    $results = [];

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

    wp_send_json_success($results);
}

add_action(
    'wp_ajax_quick_add_post_suggest',
    'quick_add_post_ajax_suggest'
);


/* ============================================================
 * Helper: category checkboxes, indented to show the hierarchy
 * ============================================================ */

function quick_add_post_render_categories($categories, $selected) {

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

            $html .= '<label class="quick-post-cat" style="padding-left:' . ($depth * 1.6) . 'em">'
                . '<input type="checkbox" name="post_cats[]" value="' . esc_attr($category->term_id) . '"'
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
 * The [add_post] shortcode
 * ============================================================ */

function quick_add_post_shortcode() {

    if (!is_user_logged_in()) {
        return '<p>You must be logged in to add a post.</p>';
    }

    if (!current_user_can('edit_posts')) {
        return '<p>You do not have permission to add posts.</p>';
    }

    $message = '';

    $defaults = [
        'title'   => '',
        'content' => '',
        'cats'    => [],
        'tags'    => [],
    ];

    $values = $defaults;

    $submitted = (
        isset($_POST['quick_add_post_create']) &&
        isset($_POST['quick_add_post_nonce']) &&
        wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['quick_add_post_nonce'])),
            'quick_add_post'
        )
    );

    if ($submitted) {

        /* ----------------------------------------------------
         * Read whatever was posted
         * ---------------------------------------------------- */

        $values['title'] = isset($_POST['quick_post_title'])
            ? sanitize_text_field(wp_unslash($_POST['quick_post_title']))
            : '';

        $values['content'] = isset($_POST['quick_post_content'])
            ? wp_kses_post(wp_unslash($_POST['quick_post_content']))
            : '';

        if (isset($_POST['post_cats'])) {
            foreach ((array) $_POST['post_cats'] as $cat_id) {
                $cat_id = absint($cat_id);
                if ($cat_id && term_exists($cat_id, 'category')) {
                    $values['cats'][] = $cat_id;
                }
            }
        }

        if (isset($_POST['quick_post_tags'])) {
            $values['tags'] = array_values(array_filter(array_map(
                'trim',
                explode(',', sanitize_text_field(wp_unslash($_POST['quick_post_tags'])))
            )));
        }

        $status = sanitize_key(wp_unslash($_POST['quick_add_post_create']));

        if ($status !== 'publish' || !current_user_can('publish_posts')) {
            $status = 'draft';
        }

        $form_id = isset($_POST['quick_post_form_id'])
            ? sanitize_key(wp_unslash($_POST['quick_post_form_id']))
            : '';


        /* ----------------------------------------------------
         * Validate and create
         * ---------------------------------------------------- */

        if ($form_id === '' || !get_transient('quick_add_post_' . $form_id)) {

            $message = '<div class="quick-post-warning">
                This form was already submitted (or has expired), so nothing new was created.
                If you meant to add another entry, you can save again.
            </div>';

        } elseif ($values['title'] === '') {

            $message = '<div class="quick-post-error">Please enter a title.</div>';

        } elseif (trim(wp_strip_all_tags($values['content'])) === '') {

            $message = '<div class="quick-post-error">Please enter some content.</div>';

        } else {

            delete_transient('quick_add_post_' . $form_id);

            $new_post_id = wp_insert_post([
                'post_type'    => 'post',
                'post_title'   => $values['title'],
                'post_content' => $values['content'],
                'post_status'  => $status,
            ], true);

            if (is_wp_error($new_post_id)) {

                $message = '<div class="quick-post-error">
                    There was a problem creating the post:
                    ' . esc_html($new_post_id->get_error_message()) . '
                </div>';

            } else {

                /* If no category is ticked, WordPress keeps its default category */
                if ($values['cats']) {
                    wp_set_post_categories($new_post_id, $values['cats']);
                }

                if ($values['tags']) {
                    wp_set_post_tags($new_post_id, $values['tags'], false);
                }

                /* Optional featured image */
                if (
                    isset($_FILES['quick_post_image']) &&
                    !empty($_FILES['quick_post_image']['name'])
                ) {

                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';

                    $attachment_id = media_handle_upload('quick_post_image', $new_post_id);

                    if (!is_wp_error($attachment_id)) {
                        set_post_thumbnail($new_post_id, $attachment_id);
                    }
                }

                if ($status === 'publish') {

                    $message = '<div class="quick-post-success">
                        <strong>Post published!</strong><br>
                        <a href="' . esc_url(get_permalink($new_post_id)) . '">View the post</a>
                    </div>';

                } else {

                    $message = '<div class="quick-post-success">
                        <strong>Post saved as a draft!</strong><br>
                        <a href="' . esc_url(get_edit_post_link($new_post_id, '')) . '">Edit the draft post</a>
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
    set_transient('quick_add_post_' . sanitize_key($form_id), 1, DAY_IN_SECONDS);

    $tag_tokens = [];
    foreach ($values['tags'] as $tag_name) {
        $tag_tokens[] = ['id' => $tag_name, 'label' => $tag_name];
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
        .quick-add-post { font-size: 1.2rem; line-height: 1.5; }

        .quick-add-post .qp-label { font-weight: 600; display: block; margin: 1.4em 0 .45em; }

        .quick-add-post input[type="text"],
        .quick-add-post textarea,
        .quick-add-post select {
            width: 100%; box-sizing: border-box;
            font-size: 1em; font-family: inherit;
            padding: .7em .8em; min-height: 3em;
        }
        .quick-add-post textarea { line-height: 1.5; }

        .quick-add-post input[type="file"] { font-size: 1em; padding: .6em 0; }

        .quick-add-post button[type="submit"] {
            font-size: 1.05em; font-family: inherit; font-weight: 600;
            padding: .8em 1.8em; min-height: 3em; cursor: pointer;
        }

        .quick-post-success, .quick-post-error, .quick-post-warning {
            padding: .9em 1.1em; margin: 0 0 1.2em; border-left: 4px solid; border-radius: 3px;
            color: #000;
        }
        .quick-post-success a, .quick-post-error a, .quick-post-warning a { color: #000; text-decoration: underline; }
        .quick-post-success { background: #edfaef; border-color: #2e7d32; }
        .quick-post-error   { background: #fcf0f1; border-color: #c62828; }
        .quick-post-warning { background: #fff8e5; border-color: #f0b429; }
        .quick-post-help { font-size: .85em; opacity: .75; margin: .4em 0 0; }

        /* Categories (shown as a hierarchy) */
        .quick-post-cats { max-height: 280px; overflow: auto; border: 1px solid #ccc; padding: .6em .9em; border-radius: 3px; }
        .quick-post-cats label.quick-post-cat { display: block; font-weight: normal; margin: .3em 0; cursor: pointer; }
        .quick-post-cats input[type="checkbox"] { transform: scale(1.25); margin-right: .55em; }

        /* Tag box */
        .quick-post-tokenbox { position: relative; border: 1px solid #ccc; border-radius: 3px; padding: .5em; min-height: 3.2em; box-sizing: border-box; }
        .quick-post-tokenbox .quick-post-token-input { border: 0 !important; outline: 0; box-shadow: none !important; width: 100%; box-sizing: border-box; font-size: 1em; padding: .5em .4em; min-height: 2.4em; }
        .quick-post-chip { display: inline-block; background: #eef1f5; color: #000; border-radius: 4px; padding: .25em .3em .25em .7em; margin: 0 .4em .4em 0; font-size: .95em; }
        .quick-post-chip-remove { background: none; border: 0; color: #000; cursor: pointer; padding: 0 .5em; font-size: 1.2em; line-height: 1; }
        .quick-post-suggestions { display: none; position: absolute; left: 0; right: 0; top: 100%; z-index: 20; background: #fff; border: 1px solid #ccc; max-height: 280px; overflow: auto; box-shadow: 0 4px 10px rgba(0,0,0,.12); }
        .quick-post-suggestion { padding: .75em 1em; cursor: pointer; color: #222; font-size: 1em; }
        .quick-post-suggestion:hover { background: #eef1f5; }

        .quick-post-actions { margin-top: 2em; display: flex; gap: 1em; flex-wrap: wrap; }
    </style>

    <div class="quick-add-post">

        <?php echo $message; ?>

        <form
            method="post"
            enctype="multipart/form-data"
            id="quick-post-form"
        >

            <?php wp_nonce_field('quick_add_post', 'quick_add_post_nonce'); ?>

            <input type="hidden" name="quick_post_form_id" value="<?php echo esc_attr($form_id); ?>">


            <!-- Title -->

            <label class="qp-label" for="quick-post-title" style="margin-top:0">Title *</label>

            <input
                type="text"
                id="quick-post-title"
                name="quick_post_title"
                value="<?php echo esc_attr($values['title']); ?>"
                required
            >


            <!-- Content -->

            <label class="qp-label" for="quick-post-content">Content *</label>

            <textarea
                id="quick-post-content"
                name="quick_post_content"
                rows="12"
                required
            ><?php echo esc_textarea($values['content']); ?></textarea>


            <!-- Categories -->

            <?php if ($categories) : ?>

                <span class="qp-label">Categories</span>

                <div class="quick-post-cats">

                    <?php echo quick_add_post_render_categories($categories, $values['cats']); ?>

                </div>

            <?php endif; ?>


            <!-- Tags -->

            <span class="qp-label">Tags</span>

            <div
                class="quick-post-tokenbox"
                data-initial="<?php echo esc_attr(wp_json_encode($tag_tokens)); ?>"
            >
                <div class="quick-post-selected"></div>

                <input
                    type="text"
                    class="quick-post-token-input"
                    placeholder="Start typing a tag..."
                    autocomplete="off"
                >

                <div class="quick-post-suggestions"></div>

                <input type="hidden" name="quick_post_tags" class="quick-post-token-hidden" value="">
            </div>

            <p class="quick-post-help">
                Pick a suggestion, or press Enter (or comma) to create a new tag.
            </p>


            <!-- Image -->

            <label class="qp-label" for="quick-post-image">Featured image (optional)</label>

            <input
                type="file"
                id="quick-post-image"
                name="quick_post_image"
                accept="image/*"
            >


            <!-- Save -->

            <div class="quick-post-actions">

                <button type="submit" name="quick_add_post_create" value="draft">
                    Save Draft
                </button>

                <?php if ($can_publish) : ?>

                    <button type="submit" name="quick_add_post_create" value="publish">
                        Publish Post
                    </button>

                <?php endif; ?>

            </div>

        </form>

    </div>

    <script>

    document.addEventListener('DOMContentLoaded', function () {

        const form = document.getElementById('quick-post-form');

        if (!form) {
            return;
        }

        const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        const nonce   = <?php echo wp_json_encode(wp_create_nonce('quick_add_post_suggest')); ?>;


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
         * Token input (tags)
         * -------------------------------------------------------- */

        function initTokenBox(box) {

            const selectedEl    = box.querySelector('.quick-post-selected');
            const input         = box.querySelector('.quick-post-token-input');
            const suggestionsEl = box.querySelector('.quick-post-suggestions');
            const hidden        = box.querySelector('.quick-post-token-hidden');

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
                    chip.className = 'quick-post-chip';

                    const text = document.createElement('span');
                    text.textContent = item.label;

                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'quick-post-chip-remove';
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
                    '?action=quick_add_post_suggest' +
                    '&nonce=' + encodeURIComponent(nonce) +
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
                            row.className = 'quick-post-suggestion';
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

                if (event.key === 'Enter' || event.key === ',') {

                    event.preventDefault();

                    const typed = input.value.trim();

                    if (!typed) {
                        return;
                    }

                    const exact = current.find(function (item) {
                        return item.label.toLowerCase() === typed.toLowerCase();
                    });

                    add(exact || { id: typed, label: typed });

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

        form.querySelectorAll('.quick-post-tokenbox').forEach(initTokenBox);

    });

    </script>

    <?php

    return ob_get_clean();
}

add_shortcode('add_post', 'quick_add_post_shortcode');
