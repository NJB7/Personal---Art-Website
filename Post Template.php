<?php

add_filter('register_post_type_args', function ($args, $post_type) {

    if (in_array($post_type, array('post'), true)) {

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
