// Display the entire Pin as a link to its Pin URL
function display_pin_image_link() {

    $pin_url = get_post_meta(get_the_ID(), '_pin_url', true);

    if (!$pin_url) {
        return '';
    }

    $output = '<a href="' . esc_url($pin_url) . '" class="full-pin-link">';

    if (has_post_thumbnail()) {
        $output .= get_the_post_thumbnail(get_the_ID(), 'large');
    }

    $output .= '<div class="full-pin-content">';

    $output .= '<h2>' . esc_html(get_the_title()) . '</h2>';

    $output .= apply_filters('the_content', get_the_content());

    $output .= '</div>';

    $output .= '</a>';

    return $output;
}

add_shortcode('pin_image_link', 'display_pin_image_link');
