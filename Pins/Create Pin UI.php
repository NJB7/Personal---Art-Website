/* Shortcode: [add_pin]
 * Fetches webpage title, description and image, and adds tags
 * A one-time form ID stops a page refresh from creating a duplicate Pin.
 *
 * Nothing blocks you: if the webpage cannot be read, or the title, image or
 * URL is missing or fails, the Pin form still opens (or the Pin still saves)
 * with that part blank, and tells you what to fill in. A "Skip fetching and
 * enter details myself" button opens the blank form directly.
 */

/* Tidy a typed address: trims it and adds https:// if it was left off.
 * Returns '' if it still is not a usable web address. */
function quick_add_pin_clean_url($raw) {

    $raw = trim((string) $raw);

    if ($raw === '') {
        return '';
    }

    if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $raw)) {
        $raw = 'https://' . ltrim($raw, '/');
    }

    return esc_url_raw($raw, ['http', 'https']);
}


/* ============================================================
 * AJAX: Find existing tags
 * ============================================================ */

function quick_add_pin_tag_suggestions() {

    if (!is_user_logged_in() || !current_user_can('edit_posts')) {
        wp_send_json_error();
    }

    check_ajax_referer('quick_add_pin_tags', 'nonce');

    $search = isset($_GET['search'])
        ? sanitize_text_field(wp_unslash($_GET['search']))
        : '';

    if (strlen($search) < 1) {
        wp_send_json_success([]);
    }

    $terms = get_terms([
        'taxonomy'   => 'post_tag',
        'search'     => $search,
        'hide_empty' => false,
        'number'     => 10,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    if (is_wp_error($terms)) {
        wp_send_json_success([]);
    }

    $results = [];

    foreach ($terms as $term) {
        $results[] = [
            'name' => $term->name,
            'slug' => $term->slug,
        ];
    }

    wp_send_json_success($results);
}

add_action(
    'wp_ajax_quick_add_pin_tag_suggestions',
    'quick_add_pin_tag_suggestions'
);


/* ============================================================
 * Create the Add Pin form
 * ============================================================ */

function quick_add_pin_shortcode() {

    if (!is_user_logged_in()) {
        return '<p>You must be logged in to use the Add Pin form.</p>';
    }

    if (!current_user_can('edit_posts')) {
        return '<p>You do not have permission to use the Add Pin form.</p>';
    }

    $message = '';
    $preview = null;

    /* ========================================================
     * Create the Pin
     * ======================================================== */

    if (
        isset($_POST['quick_add_pin_create']) &&
        isset($_POST['quick_add_pin_nonce']) &&
        wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['quick_add_pin_nonce'])),
            'quick_add_pin_create'
        )
    ) {

        $url = isset($_POST['pin_url'])
            ? quick_add_pin_clean_url(sanitize_text_field(wp_unslash($_POST['pin_url'])))
            : '';

        $notes = [];

        $title = isset($_POST['pin_title'])
            ? sanitize_text_field(wp_unslash($_POST['pin_title']))
            : '';

        $description = isset($_POST['pin_description'])
            ? sanitize_textarea_field(wp_unslash($_POST['pin_description']))
            : '';

        $tags = isset($_POST['pin_tags'])
            ? sanitize_text_field(wp_unslash($_POST['pin_tags']))
            : '';

        $image_url = isset($_POST['pin_image_url'])
            ? esc_url_raw(wp_unslash($_POST['pin_image_url']))
            : '';

        $status = isset($_POST['pin_status'])
            ? sanitize_key($_POST['pin_status'])
            : 'draft';

        if (!in_array($status, ['draft', 'publish'], true)) {
            $status = 'draft';
        }

        /* One-time form ID: each review form can be used once only */
        $form_id = isset($_POST['pin_form_id'])
            ? sanitize_key(wp_unslash($_POST['pin_form_id']))
            : '';

        $token_key = 'quick_add_pin_form_' . get_current_user_id() . '_' . $form_id;

        $form_token_ok = ($form_id !== '' && get_transient($token_key));

        if (!$form_token_ok) {

            $message = '<div class="quick-pin-warning">
                This Pin was already saved, so it was not added a second time.
                (This happens when the page is refreshed after saving.)
            </div>';

        } else {

            /* Use the form ID up now, so a refresh cannot repeat the save */
            delete_transient($token_key);

            /* A Pin with no title is kept as a draft, never published */
            if ($title === '') {
                $notes[] = 'The title is blank (it is called "Untitled Pin" for now).';

                if ($status === 'publish') {
                    $status  = 'draft';
                    $notes[] = 'Because there was no title, it was saved as a draft instead of being published.';
                }
            }

            $pin_id = wp_insert_post([
                'post_type'    => 'pin',
                'post_title'   => $title ?: 'Untitled Pin',
                'post_content' => $description,
                'post_status'  => $status,
            ], true);

            if (is_wp_error($pin_id)) {

                $message = '<div class="quick-pin-error">
                    There was a problem creating the Pin:
                    ' . esc_html($pin_id->get_error_message()) . '
                </div>';

            } else {

                /* Save the Pin URL */
                if ($url !== '') {
                    update_post_meta($pin_id, '_pin_url', $url);
                } else {
                    $notes[] = 'The web address was missing or not usable, so the URL is blank.';
                }


                /* ====================================================
                 * Save tags
                 * ==================================================== */

                if (!empty($tags)) {

                    $tag_names = array_map(
                        'trim',
                        explode(',', $tags)
                    );

                    $tag_names = array_filter($tag_names);

                    if (!empty($tag_names)) {

                        wp_set_post_tags(
                            $pin_id,
                            $tag_names,
                            false
                        );
                    }
                }


                /* ====================================================
                 * Handle images
                 * ==================================================== */

                $attachment_id = 0;

                try {

                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';


                /* ----------------------------------------------------
                 * FIRST: Use manually uploaded replacement image
                 * ---------------------------------------------------- */

                if (
                    isset($_FILES['pin_image']) &&
                    !empty($_FILES['pin_image']['name'])
                ) {

                    $attachment_id = media_handle_upload(
                        'pin_image',
                        $pin_id
                    );

                    if (is_wp_error($attachment_id)) {
                        $notes[] = 'The image you uploaded could not be added (' . $attachment_id->get_error_message() . ').';
                        $attachment_id = 0;
                    }
                }


                /* ----------------------------------------------------
                 * SECOND: Download webpage image if no replacement
                 * was uploaded.
                 * ---------------------------------------------------- */

                if (!$attachment_id && !empty($image_url)) {

                    $image_response = wp_remote_get($image_url, [
                        'timeout'     => 20,
                        'redirection' => 5,
                        'user-agent'  => 'WordPress Quick Add Pin/' . get_bloginfo('version'),
                    ]);

                    if (!is_wp_error($image_response)) {

                        $image_body = wp_remote_retrieve_body(
                            $image_response
                        );

                        $image_type = wp_remote_retrieve_header(
                            $image_response,
                            'content-type'
                        );

                        if (
                            !empty($image_body) &&
                            strpos((string) $image_type, 'image/') === 0
                        ) {

                            $image_filename = basename(
                                wp_parse_url(
                                    $image_url,
                                    PHP_URL_PATH
                                )
                            );

                            if (empty($image_filename)) {
                                $image_filename = 'pin-image.jpg';
                            }

                            $image_filename = sanitize_file_name(
                                $image_filename
                            );

                            if (empty($image_filename)) {
                                $image_filename = 'pin-image.jpg';
                            }

                            $upload = wp_upload_bits(
                                $image_filename,
                                null,
                                $image_body
                            );

                            if (empty($upload['error'])) {

                                $filetype = wp_check_filetype(
                                    $upload['file'],
                                    null
                                );

                                $attachment = [
                                    'post_mime_type' => $filetype['type'],
                                    'post_title'     => sanitize_text_field(
                                        pathinfo(
                                            $image_filename,
                                            PATHINFO_FILENAME
                                        )
                                    ),
                                    'post_content'   => '',
                                    'post_status'    => 'inherit',
                                ];

                                $attachment_id = wp_insert_attachment(
                                    $attachment,
                                    $upload['file'],
                                    $pin_id
                                );

                                if (!is_wp_error($attachment_id)) {

                                    $metadata = wp_generate_attachment_metadata(
                                        $attachment_id,
                                        $upload['file']
                                    );

                                    if (
                                        !is_wp_error($metadata) &&
                                        !empty($metadata)
                                    ) {
                                        wp_update_attachment_metadata(
                                            $attachment_id,
                                            $metadata
                                        );
                                    }

                                } else {
                                    $attachment_id = 0;
                                }
                            }
                        }
                    }
                }


                /* Set the image as featured image */
                if ($attachment_id) {

                    set_post_thumbnail(
                        $pin_id,
                        $attachment_id
                    );

                } else {

                    $notes[] = !empty($image_url)
                        ? 'The webpage image could not be downloaded, so the featured image is blank.'
                        : 'There is no featured image.';
                }

                } catch (\Throwable $e) {

                    $notes[] = 'The image step failed, so the featured image is blank.';
                }

                $notes_html = '';

                if ($notes) {

                    $notes_html = '<br><br><strong>Left blank for you to fill in:</strong><ul>';

                    foreach (array_unique($notes) as $note) {
                        $notes_html .= '<li>' . esc_html($note) . '</li>';
                    }

                    $notes_html .= '</ul>';
                }


                /* ====================================================
                 * Success message
                 * ==================================================== */

                if ($status === 'publish') {

                    $message = '<div class="quick-pin-success">
                        <strong>Pin published!</strong><br>
                        <a href="' . esc_url(get_permalink($pin_id)) . '">
                            View the Pin
                        </a> &middot;
                        <a href="' . esc_url(get_edit_post_link($pin_id, '')) . '">
                            Edit the Pin
                        </a>'
                        . $notes_html . '
                    </div>';

                } else {

                    $message = '<div class="quick-pin-success">
                        <strong>Pin created as a draft!</strong><br>
                        <a href="' . esc_url(get_edit_post_link($pin_id, '')) . '">
                            Edit the draft Pin
                        </a>'
                        . $notes_html . '
                    </div>';
                }

                $preview = null;
            }
        }
    }


    /* ============================================================
     * Fetch webpage information
     * ============================================================ */

    if (
        (isset($_POST['quick_add_pin_fetch']) || isset($_POST['quick_add_pin_manual'])) &&
        isset($_POST['quick_add_pin_nonce']) &&
        wp_verify_nonce(
            sanitize_text_field(wp_unslash($_POST['quick_add_pin_nonce'])),
            'quick_add_pin_fetch'
        )
    ) {

        $raw_url = isset($_POST['pin_url'])
            ? trim(sanitize_text_field(wp_unslash($_POST['pin_url'])))
            : '';

        $url = quick_add_pin_clean_url($raw_url);

        /* What to show if the page cannot be read: a blank form to fill in */
        $blank_preview = [
            'url'         => $url !== '' ? $url : $raw_url,
            'title'       => '',
            'description' => '',
            'image'       => '',
        ];

        $host = strtolower(
            (string) wp_parse_url($url, PHP_URL_HOST)
        );

        $is_instagram = (
            $host === 'instagram.com' ||
            $host === 'www.instagram.com' ||
            $host === 'm.instagram.com'
        );

        if (isset($_POST['quick_add_pin_manual'])) {

            /* "Skip fetching": go straight to the blank form */

            $preview = $blank_preview;

            $message = '<div class="quick-pin-warning">
                Nothing was fetched. Fill in whatever you like below.
            </div>';

        } elseif ($raw_url === '') {

            $message = '<div class="quick-pin-error">
                Please enter a webpage URL, or use "Skip fetching" to enter the details yourself.
            </div>';

        } elseif ($url === '' || !wp_http_validate_url($url)) {

            $preview = $blank_preview;

            $message = '<div class="quick-pin-warning">
                That address could not be checked, so nothing was fetched.
                Fill in the title, description and image yourself. You can also fix the URL below.
            </div>';

        } elseif ($is_instagram) {

            /* Instagram does not allow reliable webpage fetching */

            $preview = $blank_preview;

            $message = '<div class="quick-pin-warning">
                Instagram pages cannot be automatically read.
                Please enter the title and description manually,
                and upload an image if desired.
            </div>';

        } else {

            $response = wp_remote_get($url, [
                'timeout'     => 15,
                'redirection' => 5,
                'user-agent'  => 'WordPress Quick Add Pin/' . get_bloginfo('version'),
            ]);

            $status_code = is_wp_error($response)
                ? 0
                : (int) wp_remote_retrieve_response_code($response);

            $html = is_wp_error($response)
                ? ''
                : wp_remote_retrieve_body($response);

            if (is_wp_error($response)) {

                $preview = $blank_preview;

                $message = '<div class="quick-pin-warning">
                    That webpage could not be fetched ('
                    . esc_html($response->get_error_message()) .
                    '). Fill in the title, description and image yourself.
                </div>';

            } elseif ($status_code < 200 || $status_code >= 400 || empty($html)) {

                $preview = $blank_preview;

                $message = '<div class="quick-pin-warning">
                    The webpage could not be read (HTTP '
                    . esc_html($status_code) .
                    '). Fill in the title, description and image yourself.
                </div>';

            } else {

                $title       = '';
                $description = '';
                $image       = '';

                try {

                    if (!class_exists('DOMDocument')) {
                        throw new \Exception('No HTML reader available');
                    }

                    libxml_use_internal_errors(true);

                    $dom = new DOMDocument();

                    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);

                    $xpath = new DOMXPath($dom);

                    $first = function ($query) use ($xpath) {
                        $node = $xpath->query($query);
                        return ($node && $node->length) ? trim($node->item(0)->nodeValue) : '';
                    };

                    $title = $first('//meta[@property="og:title"]/@content');

                    if (!$title) {
                        $title = $first('//title');
                    }

                    $description = $first('//meta[@property="og:description"]/@content');

                    if (!$description) {
                        $description = $first('//meta[@name="description"]/@content');
                    }

                    $image = $first('//meta[@property="og:image"]/@content');

                    if (!$image) {
                        $image = $first('//meta[@name="twitter:image"]/@content');
                    }

                    if ($image) {
                        $image = quick_add_pin_make_absolute_url($image, $url);
                    }

                } catch (\Throwable $e) {

                    /* Leave whatever was not found blank */
                }

                $preview = [
                    'url'         => $url,
                    'title'       => $title,
                    'description' => $description,
                    'image'       => $image,
                ];

                if (!$title || !$description || !$image) {

                    $missing = [];

                    if (!$title) {
                        $missing[] = 'a title';
                    }

                    if (!$description) {
                        $missing[] = 'a description';
                    }

                    if (!$image) {
                        $missing[] = 'an image';
                    }

                    $message = '<div class="quick-pin-warning">
                        The webpage did not provide ' . esc_html(implode(', ', $missing)) . '.
                        Fill in anything missing below.
                    </div>';
                }
            }
        }
    }


    /* ============================================================
     * Output form
     * ============================================================ */

    ob_start();
    ?>

    <style>
        /* Message boxes: black text so it reads on the light backgrounds */
        .quick-add-pin .quick-pin-error,
        .quick-add-pin .quick-pin-warning,
        .quick-add-pin .quick-pin-success,
        .quick-add-pin .quick-pin-error *,
        .quick-add-pin .quick-pin-warning *,
        .quick-add-pin .quick-pin-success * {
            color: #000 !important;
        }
    </style>

    <div class="quick-add-pin">

        <?php echo $message; ?>

        <?php if (!$preview) : ?>

            <form method="post">

                <?php
                wp_nonce_field(
                    'quick_add_pin_fetch',
                    'quick_add_pin_nonce'
                );
                ?>

                <p>
                    <label for="quick-pin-url">
                        <strong>Webpage URL</strong>
                    </label>
                </p>

                <p>
                    <input
                        type="url"
                        id="quick-pin-url"
                        name="pin_url"
                        placeholder="https://..."
                        required
                    >
                </p>

                <p>
                    <button
                        type="submit"
                        name="quick_add_pin_fetch"
                        value="1"
                        class="quick-pin-button"
                    >
                        Fetch Page
                    </button>

                    <button
                        type="submit"
                        name="quick_add_pin_manual"
                        value="1"
                        formnovalidate
                        class="quick-pin-secondary-button"
                    >
                        Skip fetching and enter details myself
                    </button>
                </p>

            </form>


        <?php else : ?>

            <div class="quick-pin-preview">

                <h2>Review Pin</h2>


                <?php if ($preview['image']) : ?>

                    <div class="quick-pin-image-preview">

                        <img
                            src="<?php echo esc_url($preview['image']); ?>"
                            alt=""
                        >

                    </div>

                <?php else : ?>

                    <div class="quick-pin-no-image">
                        No image was found on the webpage.
                    </div>

                <?php endif; ?>


                <form
                    method="post"
                    enctype="multipart/form-data"
                >

                    <?php
                    wp_nonce_field(
                        'quick_add_pin_create',
                        'quick_add_pin_nonce'
                    );

                    /* One-time form ID, used up when the Pin is saved */
                    $new_form_id = wp_generate_uuid4();

                    set_transient(
                        'quick_add_pin_form_' . get_current_user_id() . '_' . sanitize_key($new_form_id),
                        1,
                        DAY_IN_SECONDS
                    );
                    ?>

                    <input
                        type="hidden"
                        name="pin_form_id"
                        value="<?php echo esc_attr($new_form_id); ?>"
                    >


                    <!-- Title -->

                    <p>
                        <label for="quick-pin-title">
                            <strong>Title</strong>
                        </label>
                    </p>

                    <p>
                        <input
                            type="text"
                            id="quick-pin-title"
                            name="pin_title"
                            value="<?php echo esc_attr($preview['title']); ?>"
                        >
                    </p>


                    <!-- Description -->

                    <p>
                        <label for="quick-pin-description">
                            <strong>Description</strong>
                        </label>
                    </p>

                    <p>
                        <textarea
                            id="quick-pin-description"
                            name="pin_description"
                            rows="5"
                        ><?php echo esc_textarea($preview['description']); ?></textarea>
                    </p>


                    <!-- Tags -->

                    <p>
                        <strong>Tags</strong>
                    </p>

                    <div class="quick-pin-tag-box">

                        <div
                            class="quick-pin-selected-tags"
                            id="quick-pin-selected-tags"
                        ></div>

                        <input
                            type="text"
                            id="quick-pin-tag-input"
                            class="quick-pin-tag-input"
                            placeholder="Start typing a tag..."
                            autocomplete="off"
                        >

                        <div
                            class="quick-pin-tag-suggestions"
                            id="quick-pin-tag-suggestions"
                        ></div>

                    </div>

                    <!-- This is what gets submitted to WordPress -->
                    <input
                        type="hidden"
                        id="quick-pin-tags-hidden"
                        name="pin_tags"
                        value=""
                    >

                    <p class="quick-pin-help">
                        Start typing to find an existing tag. Select a suggestion,
                        or press Enter to create a new tag.
                    </p>


                    <!-- URL -->

                    <p>
                        <label for="quick-pin-url-edit">
                            <strong>Webpage URL</strong>
                        </label>
                    </p>

                    <p>
                        <input
                            type="text"
                            id="quick-pin-url-edit"
                            name="pin_url"
                            value="<?php echo esc_attr($preview['url']); ?>"
                            placeholder="https://..."
                        >
                    </p>


                    <!-- Image URL -->

                    <input
                        type="hidden"
                        name="pin_image_url"
                        value="<?php echo esc_attr($preview['image']); ?>"
                    >


                    <!-- Replacement image -->

                    <p>
                        <label for="quick-pin-image">
                            <strong>Replace Image</strong>
                        </label>
                    </p>

                    <p>
                        <input
                            type="file"
                            id="quick-pin-image"
                            name="pin_image"
                            accept="image/*"
                        >
                    </p>

                    <p class="quick-pin-help">
                        Leave this empty to use the webpage image shown above.
                    </p>


                    <!-- Create / Publish -->

                    <p>
                        <strong>What would you like to do?</strong>
                    </p>

                    <p class="quick-pin-actions">

                        <button
                            type="submit"
                            name="quick_add_pin_create"
                            value="1"
                            class="quick-pin-button"
                            onclick="this.form.pin_status.value='draft';"
                        >
                            Create Draft Pin
                        </button>

                        <button
                            type="submit"
                            name="quick_add_pin_create"
                            value="1"
                            class="quick-pin-publish-button"
                            onclick="this.form.pin_status.value='publish';"
                        >
                            Publish Pin
                        </button>

                    </p>

                    <input
                        type="hidden"
                        name="pin_status"
                        value="draft"
                    >

                </form>


                <!-- Fetch Again -->

                <form method="post">

                    <?php
                    wp_nonce_field(
                        'quick_add_pin_fetch',
                        'quick_add_pin_nonce'
                    );
                    ?>

                    <input
                        type="hidden"
                        name="pin_url"
                        value="<?php echo esc_attr($preview['url']); ?>"
                    >

                    <button
                        type="submit"
                        name="quick_add_pin_fetch"
                        value="1"
                        class="quick-pin-secondary-button"
                    >
                        Fetch Again
                    </button>

                </form>

            </div>

        <?php endif; ?>

    </div>

    <script>

    document.addEventListener('DOMContentLoaded', function () {

        const input = document.getElementById('quick-pin-tag-input');
        const suggestions = document.getElementById('quick-pin-tag-suggestions');
        const selectedContainer = document.getElementById('quick-pin-selected-tags');
        const hiddenInput = document.getElementById('quick-pin-tags-hidden');

        if (!input || !suggestions || !selectedContainer || !hiddenInput) {
            return;
        }

        const selectedTags = [];


        /* --------------------------------------------------------
         * Update the hidden comma-separated field
         * -------------------------------------------------------- */

        function updateHiddenField() {

            hiddenInput.value = selectedTags.join(', ');
        }


        /* --------------------------------------------------------
         * Display selected tags
         * -------------------------------------------------------- */

        function renderSelectedTags() {

            selectedContainer.innerHTML = '';

            selectedTags.forEach(function (tag, index) {

                const tagElement = document.createElement('span');
                tagElement.className = 'quick-pin-tag';

                const text = document.createElement('span');
                text.textContent = tag;

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'quick-pin-tag-remove';
                removeButton.textContent = '×';
                removeButton.setAttribute(
                    'aria-label',
                    'Remove ' + tag
                );

                removeButton.addEventListener('click', function () {

                    selectedTags.splice(index, 1);

                    renderSelectedTags();
                    updateHiddenField();
                });

                tagElement.appendChild(text);
                tagElement.appendChild(removeButton);

                selectedContainer.appendChild(tagElement);
            });
        }


        /* --------------------------------------------------------
         * Add a tag
         * -------------------------------------------------------- */

        function addTag(tag) {

            tag = tag.trim();

            if (!tag) {
                return;
            }

            const alreadyExists = selectedTags.some(function (existing) {

                return existing.toLowerCase() === tag.toLowerCase();

            });

            if (!alreadyExists) {

                selectedTags.push(tag);

                renderSelectedTags();
                updateHiddenField();
            }

            input.value = '';
            suggestions.innerHTML = '';
            suggestions.style.display = 'none';
        }


        /* --------------------------------------------------------
         * Show existing tag suggestions
         * -------------------------------------------------------- */

        function searchTags() {

            const search = input.value.trim();

            if (!search) {

                suggestions.innerHTML = '';
                suggestions.style.display = 'none';

                return;
            }

            const url =
                '<?php echo esc_url(admin_url('admin-ajax.php')); ?>' +
                '?action=quick_add_pin_tag_suggestions' +
                '&nonce=<?php echo esc_attr(wp_create_nonce('quick_add_pin_tags')); ?>' +
                '&search=' +
                encodeURIComponent(search);

            fetch(url)
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {

                    suggestions.innerHTML = '';

                    if (
                        !data.success ||
                        !data.data ||
                        !data.data.length
                    ) {

                        suggestions.style.display = 'none';

                        return;
                    }

                    data.data.forEach(function (tag) {

                        const suggestion =
                            document.createElement('div');

                        suggestion.className =
                            'quick-pin-tag-suggestion';

                        suggestion.textContent = tag.name;

                        suggestion.addEventListener(
                            'click',
                            function () {
                                addTag(tag.name);
                            }
                        );

                        suggestions.appendChild(suggestion);
                    });

                    suggestions.style.display = 'block';
                })
                .catch(function () {

                    suggestions.innerHTML = '';
                    suggestions.style.display = 'none';
                });
        }


        /* --------------------------------------------------------
         * Typing
         * -------------------------------------------------------- */

        input.addEventListener('input', function () {
            searchTags();
        });


        /* --------------------------------------------------------
         * Enter creates a new tag
         * -------------------------------------------------------- */

        input.addEventListener('keydown', function (event) {

            if (event.key === 'Enter') {

                event.preventDefault();

                const firstSuggestion =
                    suggestions.querySelector(
                        '.quick-pin-tag-suggestion'
                    );

                if (firstSuggestion) {

                    firstSuggestion.click();

                } else if (input.value.trim()) {

                    addTag(input.value);
                }
            }

            /* Allow comma as an alternative way to add a tag */
            if (event.key === ',') {

                event.preventDefault();

                if (input.value.trim()) {
                    addTag(input.value);
                }
            }
        });


        /* --------------------------------------------------------
         * Close suggestions when clicking elsewhere
         * -------------------------------------------------------- */

        document.addEventListener('click', function (event) {

            if (!event.target.closest('.quick-pin-tag-box')) {

                suggestions.innerHTML = '';
                suggestions.style.display = 'none';
            }
        });

    });

    </script>

    <?php

    return ob_get_clean();
}


/* ============================================================
 * Convert relative image URLs to absolute URLs
 * ============================================================ */

function quick_add_pin_make_absolute_url($image_url, $page_url) {

    if (preg_match('#^https?://#i', $image_url)) {
        return $image_url;
    }

    if (strpos($image_url, '//') === 0) {

        $parts = wp_parse_url($page_url);

        if (!empty($parts['scheme'])) {
            return $parts['scheme'] . ':' . $image_url;
        }
    }

    $parts = wp_parse_url($page_url);

    if (!empty($parts['scheme']) && !empty($parts['host'])) {

        $base = $parts['scheme'] . '://' . $parts['host'];

        if (strpos($image_url, '/') === 0) {
            return $base . $image_url;
        }

        $path = isset($parts['path'])
            ? dirname($parts['path'])
            : '';

        if ($path === '\\' || $path === '.') {
            $path = '';
        }

        return rtrim(
            $base . '/' . trim($path, '/'),
            '/'
        ) . '/' . ltrim($image_url, '/');
    }

    return $image_url;
}

add_shortcode('add_pin', 'quick_add_pin_shortcode');
