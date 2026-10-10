add_filter('register_post_type_args', function ($args, $post_type) {

    if ($post_type === 'project') {

        $args['template'] = array(
            array('core/spacer', array('height' => '40px')),
            array('core/paragraph', array('placeholder' => 'Start writing...')),
            array('core/spacer', array('height' => '40px')),
            array('core/image'),
            array('core/spacer', array('height' => '40px')),
            array('core/separator'),
            array('core/shortcode', array('text' => '[project_children]')),
            array('core/shortcode', array('text' => '[project_related]')),
            array('core/shortcode', array('text' => '[project_links]')),
            array('core/shortcode', array('text' => '[project_videos]')),
        );
    }

    return $args;

}, 10, 2);


--------------


add_filter('register_post_type_args', function ($args, $post_type) {

    if ($post_type === 'project') {

        $args['template'] = array(
            array('core/spacer', array('height' => '40px')),
            array('core/paragraph', array('placeholder' => 'Start writing...')),
            array('core/spacer', array('height' => '40px')),
            array('core/image'),
            array('core/spacer', array('height' => '40px')),
        );
    }

    return $args;

}, 10, 2);
