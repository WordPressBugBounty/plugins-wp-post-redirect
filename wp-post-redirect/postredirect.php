<?php
/*
Plugin Name: WP Post Redirect
Description: Redirect your posts to an external link by adding the url into a new metabox. Simple and efficient!
Version: 2.0.0
Text Domain: wp-post-redirect
Author: Marco Milesi
Author Email: milesimarco@outlook.com
Author URI: http://www.marcomilesi.com
License: GPLv2 or later
*/

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WP_Post_Redirect' ) ) :

class WP_Post_Redirect {

    const META_KEY = '_prurl';
    const OPTION_PAGE_SLUG = 'wp-redirect-option-page';
    const HTTP_STATUS = 301;
    const OPTION_CPTS = 'wppr_enabled_cpts';

    public function __construct() {
        // Admin columns
        add_filter( 'manage_post_posts_columns', [ $this, 'add_redirect_column' ] );
        add_action( 'manage_post_posts_custom_column', [ $this, 'render_redirect_column' ], 10, 2 );

        // Metabox
        add_action( 'add_meta_boxes', [ $this, 'add_redirect_metabox' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_redirect_metabox_to_cpts' ] );
        add_action( 'save_post', [ $this, 'save_redirect_meta' ], 10, 2 );

        // Redirection
        add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );
        add_filter( 'post_link', [ $this, 'filter_post_link' ], 10, 2 );

        // Permalink preview
        add_filter( 'get_sample_permalink_html', [ $this, 'show_redirect_in_permalink' ], 10, 4 );

        // Plugin action link
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_plugin_action_link' ] );

        // Options page
        add_action( 'admin_menu', [ $this, 'add_options_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        
        // Export redirections
        add_action('admin_init', [ $this, 'handle_export_redirections' ]);
    }

    public function register_settings() {
        register_setting( 'wppr_options_group', self::OPTION_CPTS, [
            'type' => 'array',
            'sanitize_callback' => [ $this, 'sanitize_cpts_option' ],
            'default' => [ 'post' ],
        ] );
    }

    public function sanitize_cpts_option( $input ) {
        $post_types = get_post_types( [ 'public' => true ], 'names' );
        $input = is_array( $input ) ? $input : [];
        $input = array_intersect( $input, array_keys( $post_types ) );
        // Always ensure 'post' is present
        if ( !in_array( 'post', $input, true ) ) {
            $input[] = 'post';
        }
        return $input;
    }

    public function add_redirect_column( $columns ) {
        $columns['wp-post-redirect'] = __( 'Redirect', 'wp-post-redirect' );
        return $columns;
    }

    public function render_redirect_column( $column_key, $post_id ) {
        if ( $column_key === 'wp-post-redirect' ) {
            $redirect = get_post_meta( $post_id, self::META_KEY, true );
            if ( $redirect ) {
                echo '<a href="' . esc_url( $redirect ) . '" target="_blank">' . esc_url( $redirect ) . '</a>';
                echo '<style>#post-' . $post_id . ' { background: rgb(255 255 0 / 30%); }</style>';
            } else {
                echo '-';
            }
        }
    }

    public function add_redirect_metabox() {
        add_meta_box(
            'wpr_redirect_url',
            __( 'Redirect', 'wp-post-redirect' ),
            [ $this, 'render_redirect_metabox' ],
            'post',
            'side',
            'core'
        );
    }

    public function add_redirect_metabox_to_cpts() {
        $post_types = get_post_types( [ 'public' => true ], 'names' );
        $enabled = get_option( self::OPTION_CPTS, [ 'post' ] );
        foreach ( $post_types as $type ) {
            if ( in_array( $type, $enabled, true ) ) {
                add_meta_box(
                    'wpr_redirect_url',
                    __( 'Redirect', 'wp-post-redirect' ),
                    [ $this, 'render_redirect_metabox' ],
                    $type,
                    'side',
                    'core'
                );
            }
        }
    }

    public function render_redirect_metabox( $post ) {
        // Security: Nonce field
        wp_nonce_field( plugin_basename( __FILE__ ), 'prurlmeta_noncename' );
        $prurl = get_post_meta( $post->ID, self::META_KEY, true );

        // Accessibility: Label for input
        echo '<label for="wppr-redirect-url" style="font-weight:bold;display:block;margin-bottom:6px;">URL</label>';

        // Input with improved styling and placeholder
        echo '<input type="url" id="wppr-redirect-url" name="' . esc_attr( self::META_KEY ) . '" value="' . esc_attr( $prurl ) . '" class="widefat" style="margin-bottom:8px;" placeholder="https://example.com/" autocomplete="off" />';
        
        // Description with subtle styling
        echo '<p class="description" style="color:#666;margin:0;">' . esc_html__( 'Enter a full URL (including http:// or https://) to redirect this post. Leave blank for no redirect.', 'wp-post-redirect' ) . '</p>';
    }

    public function save_redirect_meta( $post_id, $post ) {
        // Verify nonce
        if ( ! isset( $_POST['prurlmeta_noncename'] ) || ! wp_verify_nonce( $_POST['prurlmeta_noncename'], plugin_basename( __FILE__ ) ) ) {
            return;
        }
        // Check permissions
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        // Don't save during autosave or for revisions
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( $post->post_type === 'revision' ) {
            return;
        }
        // Save or delete meta
        $value = isset( $_POST[ self::META_KEY ] ) ? trim( $_POST[ self::META_KEY ] ) : '';
        if ( $value ) {
            update_post_meta( $post_id, self::META_KEY, $value );
        } else {
            delete_post_meta( $post_id, self::META_KEY );
        }
    }

    public function maybe_redirect() {
        if ( is_singular() ) {
            $id = get_queried_object_id();
            $post = get_post( $id );
            $enabled = get_option( self::OPTION_CPTS, [ 'post' ] );
            if ( $post && in_array( $post->post_type, $enabled, true ) ) {
                $link = $this->get_redirect_url( $id );
                if ( $link ) {
                    wp_redirect( $link, self::HTTP_STATUS );
                    exit;
                }
            }
        }
    }

    public function filter_post_link( $link, $postarg = null ) {
        if ( is_admin() ) {
            return $link;
        }
        $id = 0;
        if ( is_object( $postarg ) ) {
            $id = $postarg->ID;
        } elseif ( is_int( $postarg ) ) {
            $id = $postarg;
        }
        if ( ! $id && isset( $GLOBALS['post']->ID ) ) {
            $id = $GLOBALS['post']->ID;
        }
        $post = get_post( $id );
        $enabled = get_option( self::OPTION_CPTS, [ 'post' ] );
        if ( $post && in_array( $post->post_type, $enabled, true ) ) {
            $redirect = $this->get_redirect_url( $id );
            return $redirect ? $redirect : $link;
        }
        return $link;
    }

    public function get_redirect_url( $id ) {
        static $placeholders;
        $redirect = get_post_meta( absint( $id ), self::META_KEY, true );
        if ( $redirect ) {
            if ( ! isset( $placeholders ) ) {
                $placeholders = apply_filters( 'redirect_placeholders', [
                    '%home%' => get_home_url(),
                    '%site%' => get_site_url(),
                ] );
            }
            return str_replace( array_keys( $placeholders ), array_values( $placeholders ), $redirect );
        }
        return false;
    }

    public function show_redirect_in_permalink( $return, $id, $new_title, $new_slug ) {
        $redirect = $this->get_redirect_url( $id );
        if ( $redirect ) {
            $return = "<strong>" . __( "Redirect:", 'wp-post-redirect' ) . "</strong> " . esc_html( $redirect ) . "<style>#titlediv {margin-bottom: 30px;}</style><br/>" . $return;
        }
        return $return;
    }

    public function add_plugin_action_link( $links ) {
        $url = admin_url( 'options-general.php?page=' . self::OPTION_PAGE_SLUG );
        $links[] = '<a href="' . esc_url( $url ) . '">' . __( 'Redirections', 'wp-post-redirect' ) . '</a>';
        return $links;
    }

    public function add_options_page() {
        add_options_page(
            __( 'WP Post Redirect', 'wp-post-redirect' ),
            __( 'WP Post Redirect', 'wp-post-redirect' ),
            'manage_options',
            self::OPTION_PAGE_SLUG,
            [ $this, 'render_options_page' ]
        );
    }

    public function render_options_page() {
        global $wpdb;
        $all_cpts = get_post_types( [ 'public' => true ], 'objects' );
        $enabled_cpts = get_option( self::OPTION_CPTS, [ 'post' ] );

        echo '<div class="wrap">';
        echo '<h1 style="display:flex;align-items:center;gap:10px;">' . esc_html__( 'WP Post Redirect', 'wp-post-redirect' ) . '</h1>';
        echo '<h2 style="font-weight:normal;color:#666;">' . esc_html__( 'Settings', 'wp-post-redirect' ) . '</h2>';

        // CPT selection form
        echo '<form method="post" action="options.php" style="margin-bottom:32px;">';
        settings_fields( 'wppr_options_group' );
        echo '<table class="form-table"><tr><th scope="row">' . esc_html__( 'Enable Redirect Metabox for:', 'wp-post-redirect' ) . '</th><td>';
        foreach ( $all_cpts as $cpt ) {
            $checked = in_array( $cpt->name, $enabled_cpts, true ) ? 'checked' : '';
            $disabled = $cpt->name === 'post' ? 'disabled' : '';
            echo '<label style="margin-right:20px;">';
            echo '<input type="checkbox" name="' . esc_attr( self::OPTION_CPTS ) . '[]" value="' . esc_attr( $cpt->name ) . '" ' . $checked . ' ' . $disabled . ' />';
            echo esc_html( $cpt->labels->singular_name );
            if ( $cpt->name === 'post' ) {
                echo ' <span style="color:#666;">(' . esc_html__( 'always enabled', 'wp-post-redirect' ) . ')</span>';
            }
            echo '</label>';
        }
        echo '</td></tr></table>';
        submit_button();
        echo '</form>';

        echo '<h2 style="font-weight:normal;color:#666;">' . esc_html__( 'Active Redirections', 'wp-post-redirect' ) . '</h2>';
        echo '<div style="margin: 20px 0 24px 0;">';
        echo '<a href="' . esc_url( wp_nonce_url(admin_url('options-general.php?page=' . self::OPTION_PAGE_SLUG . '&wppr_export=1'), 'wppr_export_action', 'wppr_export_nonce') ) . '" class="button button-secondary">' . esc_html__('Export Redirections (CSV)', 'wp-post-redirect') . '</a>';
        echo '</div>';

        // Query for all redirections with post type, title, date, and URL
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID as post_id, p.post_type, p.post_title, p.post_date, pm.meta_value 
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                 ORDER BY p.post_type ASC, p.ID ASC",
                self::META_KEY
            )
        );

        $active = [];
        $inactive = [];
        foreach ( $results as $r ) {
            $post_type = $r->post_type;
            if ( in_array( $post_type, $enabled_cpts, true ) ) {
                $active[] = $r;
            } else {
                $inactive[] = $r;
            }
        }

        // Table rendering function
        $render_table = function( $rows, $caption ) {
            if ( empty( $rows ) ) {
                echo '<p>' . esc_html__( 'None found.', 'wp-post-redirect' ) . '</p>';
                return;
            }
            echo '<h3>' . esc_html( $caption ) . '</h3>';
            echo '<table class="wp-list-table widefat fixed striped wppr-table">';
            echo '<thead>
                    <tr>
                        <th class="manage-column">' . esc_html__( 'Post ID', 'wp-post-redirect' ) . '</th>
                        <th class="manage-column">' . esc_html__( 'Post Type', 'wp-post-redirect' ) . '</th>
                        <th class="manage-column">' . esc_html__( 'Title', 'wp-post-redirect' ) . '</th>
                        <th class="manage-column">' . esc_html__( 'Published Date', 'wp-post-redirect' ) . '</th>
                        <th class="manage-column">' . esc_html__( 'Redirect URL', 'wp-post-redirect' ) . '</th>
                    </tr>
                </thead>
                <tbody>';
            foreach ( $rows as $r ) {
                $post_id = intval( $r->post_id );
                $post_status = get_post_status( $post_id );
                if ( ! $post_status ) {
                    continue;
                }
                $title = get_the_title( $post_id );
                $edit_link = get_edit_post_link( $post_id );
                $redirect_url = esc_url( $r->meta_value );
                $post_type_obj = get_post_type_object( $r->post_type );
                $post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : esc_html( $r->post_type );
                $published_date = esc_html( mysql2date( get_option( 'date_format' ), $r->post_date ) );

                echo '<tr>';
                echo '<td>' . esc_html( $post_id ) . '</td>';
                echo '<td>' . esc_html( $post_type_label ) . '</td>';
                echo '<td><a href="' . esc_url( $edit_link ) . '" target="_blank">' . esc_html( $title ) . '</a></td>';
                echo '<td>' . $published_date . '</td>';
                echo '<td><a href="' . $redirect_url . '" target="_blank" rel="noopener noreferrer">' . $redirect_url . '</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        };

        // Active redirects table
        $render_table( $active, __( 'Active Redirections', 'wp-post-redirect' ) );
        // Inactive redirects table
        $render_table( $inactive, __( 'Inactive Redirections (CPT disabled)', 'wp-post-redirect' ) );

        echo '<style>
            .wppr-table th, .wppr-table td { padding: 8px 10px; }
            .wppr-table tr:nth-child(even) { background: #f9f9f9; }
            .wppr-table th { background: #f1f1f1; }
            .wppr-table a { color: #0073aa; text-decoration: none; }
            .wppr-table a:hover { text-decoration: underline; }
            .dashicons-randomize { font-size: 32px; vertical-align: middle; }
        </style>';

        echo '</div>';
    }

    public function handle_export_redirections() {
        if (
            isset($_GET['wppr_export']) &&
            check_admin_referer('wppr_export_action', 'wppr_export_nonce') &&
            current_user_can('manage_options')
        ) {
            global $wpdb;
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID as post_id, p.post_type, p.post_title, p.post_date, pm.meta_value 
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE pm.meta_key = %s
                     ORDER BY p.post_type ASC, p.ID ASC",
                    self::META_KEY
                )
            );
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="wp-post-redirects.csv"');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Post ID', 'Post Type', 'Post Title', 'Published Date', 'Redirect URL']);
            foreach ($results as $row) {
                $post_type_obj = get_post_type_object( $row->post_type );
                $post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : $row->post_type;
                $published_date = mysql2date( get_option( 'date_format' ), $row->post_date );
                fputcsv($output, [
                    $row->post_id,
                    $post_type_label,
                    $row->post_title,
                    $published_date,
                    $row->meta_value
                ]);
            }
            fclose($output);
            exit;
        }
    }
}

new WP_Post_Redirect();

endif; // End if class_exists