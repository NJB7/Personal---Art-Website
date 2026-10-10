// Add a Pin URL field to Pins
function add_pin_url_field() {
    add_meta_box(
        'pin_url',
        'Pin URL',
        'display_pin_url_field',
        'pin',
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'add_pin_url_field');


// Display the Pin URL field
function display_pin_url_field($post) {
    $pin_url = get_post_meta($post->ID, '_pin_url', true);
    ?>

    <label for="pin_url">Website URL:</label>

    <input
        type="url"
        id="pin_url"
        name="pin_url"
        value="<?php echo esc_attr($pin_url); ?>"
        style="width: 100%;"
        placeholder="https://example.com"
    >

    <?php
}


// Save the Pin URL
function save_pin_url($post_id) {

    if (isset($_POST['pin_url'])) {
        update_post_meta(
            $post_id,
            '_pin_url',
            esc_url_raw($_POST['pin_url'])
        );
    }

}
add_action('save_post_pin', 'save_pin_url');
