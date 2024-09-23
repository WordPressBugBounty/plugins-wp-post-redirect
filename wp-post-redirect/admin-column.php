<?php

    add_filter('manage_post_posts_columns', function($columns) {
        return array_merge($columns, ['wp-post-redirect' => __('Redirect', 'wp-post-redirect')]);
    });
    
    add_action('manage_post_posts_custom_column', function($column_key, $post_id) {
        if ($column_key == 'wp-post-redirect') {
            $redirect = get_post_meta($post_id, '_prurl', true);
            if ($redirect) {
                echo '<a href="'.esc_url( get_post_meta($post_id, '_prurl', true) ).'" alt="Redirect" target="_blank">'.esc_url( get_post_meta($post_id, '_prurl', true) ).'</a>';
                echo '<style>#post-'.$post_id.' { background: rgb(255 255 0 / 30%); }</style>';
            } else {
                echo '-';
            }
        }
    }, 10, 2);

?>