// Display Pins with infinite scrolling
function display_pins_by_tag($atts) {

    $atts = shortcode_atts(
        array(
            'tag' => '',
        ),
        $atts
    );

    $tag = sanitize_title($atts['tag']);

    // Logged-in users can see their own drafts.
    $post_status = array('publish');

    if (is_user_logged_in()) {
        $post_status = array('publish', 'draft');
    }

    $args = array(
        'post_type'      => 'pin',
        'posts_per_page' => 20,
        'paged'          => 1,
        'post_status'    => $post_status,
        'tag'            => $tag,
    );

    // Only allow the current logged-in user to see their own drafts.
    if (is_user_logged_in()) {
        $args['author'] = get_current_user_id();
    }

    $query = new WP_Query($args);

    if (!$query->have_posts()) {
        return '<p>No Pins found.</p>';
    }

    $output = '<div class="pin-infinite-wrapper"';
    $output .= ' data-tag="' . esc_attr($tag) . '"';
    $output .= ' data-page="1"';
    $output .= ' data-max-pages="' . esc_attr($query->max_num_pages) . '"';
    $output .= ' data-nonce="' . esc_attr(wp_create_nonce('pin_infinite_scroll')) . '">';

    $output .= '<div class="pin-collection">';

    while ($query->have_posts()) {
        $query->the_post();

        $pin_url = get_post_meta(get_the_ID(), '_pin_url', true);

        $output .= '<div class="pin-card">';

        if ($pin_url) {

            // Make the entire Pin card clickable.
            $output .= '<a href="' . esc_url($pin_url) . '" class="pin-card-link" target="_blank" rel="noopener noreferrer">';

            if (has_post_thumbnail()) {
                $output .= get_the_post_thumbnail(get_the_ID(), 'large');
            }

            $output .= '<h2>' . esc_html(get_the_title()) . '</h2>';

            $content = apply_filters('the_content', get_the_content());

            if (trim(wp_strip_all_tags($content)) !== '') {
                $output .= '<div class="pin-description">' . $content . '</div>';
            }

            $output .= '</a>';

        } else {

            // Pins without a URL are displayed normally.
            if (has_post_thumbnail()) {
                $output .= get_the_post_thumbnail(get_the_ID(), 'large');
            }

            $output .= '<h2>' . esc_html(get_the_title()) . '</h2>';

            $content = apply_filters('the_content', get_the_content());

            if (trim(wp_strip_all_tags($content)) !== '') {
                $output .= '<div class="pin-description">' . $content . '</div>';
            }
        }

        $output .= '</div>';
    }

    wp_reset_postdata();

    $output .= '</div>';

    // Loading indicator
    $output .= '<div class="pin-loading" style="display:none;">Loading more Pins...</div>';

    // End marker
    $output .= '<div class="pin-scroll-end"></div>';

    $output .= '</div>';

    return $output;
}

add_shortcode('pin_collection', 'display_pins_by_tag');


// Load the next batch of Pins
function load_more_pins() {

    check_ajax_referer('pin_infinite_scroll', 'nonce');

    $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
    $tag  = isset($_POST['tag']) ? sanitize_title($_POST['tag']) : '';

    $post_status = array('publish');

    if (is_user_logged_in()) {
        $post_status = array('publish', 'draft');
    }

    $args = array(
        'post_type'      => 'pin',
        'posts_per_page' => 20,
        'paged'          => $page,
        'post_status'    => $post_status,
        'tag'            => $tag,
    );

    if (is_user_logged_in()) {
        $args['author'] = get_current_user_id();
    }

    $query = new WP_Query($args);

    if (!$query->have_posts()) {
        wp_send_json_success(array(
            'html'     => '',
            'has_more' => false,
        ));
    }

    $output = '';

    while ($query->have_posts()) {
        $query->the_post();

        $pin_url = get_post_meta(get_the_ID(), '_pin_url', true);

        $output .= '<div class="pin-card">';

        if ($pin_url) {

            // Make the entire Pin card clickable.
            $output .= '<a href="' . esc_url($pin_url) . '" class="pin-card-link" target="_blank" rel="noopener noreferrer">';

            if (has_post_thumbnail()) {
                $output .= get_the_post_thumbnail(get_the_ID(), 'large');
            }

            $output .= '<h2>' . esc_html(get_the_title()) . '</h2>';

            $output .= apply_filters('the_content', get_the_content());

            $output .= '</a>';

        } else {

            // Pins without a URL are displayed normally.
            if (has_post_thumbnail()) {
                $output .= get_the_post_thumbnail(get_the_ID(), 'large');
            }

            $output .= '<h2>' . esc_html(get_the_title()) . '</h2>';

            $output .= apply_filters('the_content', get_the_content());
        }

        $output .= '</div>';
    }

    wp_reset_postdata();

    wp_send_json_success(array(
        'html'     => $output,
        'has_more' => ($page < $query->max_num_pages),
    ));
}

add_action('wp_ajax_load_more_pins', 'load_more_pins');
add_action('wp_ajax_nopriv_load_more_pins', 'load_more_pins');
