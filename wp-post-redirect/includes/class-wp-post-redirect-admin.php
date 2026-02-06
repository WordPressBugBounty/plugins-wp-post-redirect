<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WP_Post_Redirect_Admin {

    /**
     * Parent plugin instance.
     * @var WP_Post_Redirect
     */
    private $parent;

    public function __construct( $parent ) {
        $this->parent = $parent;

        // Admin columns
        add_filter( 'manage_post_posts_columns', [ $this, 'add_redirect_column' ] );
        add_action( 'manage_post_posts_custom_column', [ $this, 'render_redirect_column' ], 10, 2 );

        // Metabox
        add_action( 'add_meta_boxes', [ $this, 'add_redirect_metabox_to_cpts' ] );
        add_action( 'save_post', [ $this, 'save_redirect_meta' ], 10, 2 );

        // Permalink preview
        add_filter( 'get_sample_permalink_html', [ $this, 'show_redirect_in_permalink' ], 10, 4 );

        // Options page
        add_action( 'admin_menu', [ $this, 'add_options_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        
        // Export redirections
        add_action('admin_init', [ $this, 'handle_export_redirections' ]);

        // AJAX search for internal content
        add_action( 'wp_ajax_wppr_search_posts', [ $this, 'ajax_search_posts' ] );
    }

    public function ajax_search_posts() {
        check_ajax_referer( 'wppr_metabox_nonce', 'nonce' );
        $query = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';
        
        $posts = get_posts( [
            'post_type' => get_post_types( [ 'public' => true ] ),
            's' => $query,
            'posts_per_page' => 10,
        ] );

        $results = [];
        foreach ( $posts as $post ) {
            $results[] = [
                'id' => $post->ID,
                'title' => $post->post_title . ' (' . $post->post_type . ')',
            ];
        }

        wp_send_json_success( $results );
    }

    public function register_settings() {
        register_setting( 'wppr_options_group', WP_Post_Redirect::OPTION_CPTS, [
            'type' => 'array',
            'sanitize_callback' => [ $this, 'sanitize_cpts_option' ],
            'default' => [ 'post' ],
        ] );

        register_setting( 'wppr_options_group', WP_Post_Redirect::OPTION_HTTP_STATUS, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 301,
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
            $redirect = $this->parent->get_redirect_url( $post_id );
            if ( $redirect ) {
                $raw_value = get_post_meta( $post_id, WP_Post_Redirect::META_KEY, true );
                $blank = get_post_meta( $post_id, WP_Post_Redirect::META_TARGET_BLANK, true );
                $nofollow = get_post_meta( $post_id, WP_Post_Redirect::META_REL_NOFOLLOW, true );
                
                $target = $blank === '1' ? ' target="_blank"' : '';
                $rel = [];
                if ( $blank === '1' ) $rel[] = 'noopener';
                if ( $nofollow === '1' ) $rel[] = 'nofollow';
                $rel_attr = ! empty( $rel ) ? ' rel="' . implode( ' ', $rel ) . '"' : '';

                $display_url = is_numeric( $raw_value ) ? '🏠 ' . get_the_title( $raw_value ) : $redirect;

                echo '<a href="' . esc_url( $redirect ) . '"' . $target . $rel_attr . ' title="' . esc_attr( $redirect ) . '">' . esc_html( $display_url ) . '</a>';
                echo '<style>#post-' . $post_id . ' { background: rgb(255 255 0 / 30%); }</style>';
            } else {
                echo '-';
            }
        }
    }

    public function add_redirect_metabox_to_cpts() {
        $post_types = get_post_types( [ 'public' => true ], 'names' );
        $enabled = get_option( WP_Post_Redirect::OPTION_CPTS, [ 'post' ] );
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
        wp_nonce_field( plugin_basename( WP_Post_Redirect::PLUGIN_FILE ), 'prurlmeta_noncename' );
        
        $raw_value = get_post_meta( $post->ID, WP_Post_Redirect::META_KEY, true );
        $internal_id = is_numeric( $raw_value ) ? $raw_value : '';
        $prurl = ! is_numeric( $raw_value ) ? $raw_value : '';
        
        $blank = get_post_meta( $post->ID, WP_Post_Redirect::META_TARGET_BLANK, true );
        $nofollow = get_post_meta( $post->ID, WP_Post_Redirect::META_REL_NOFOLLOW, true );
        $post_status = get_post_meta( $post->ID, WP_Post_Redirect::META_HTTP_STATUS, true );
        $global_status = get_option( WP_Post_Redirect::OPTION_HTTP_STATUS, 301 );
        
        $internal_title = $internal_id ? get_the_title( $internal_id ) : '';

        ?>
        <div class="wppr-metabox-content">
            <div style="margin-bottom: 20px;">
                <label style="font-weight:600;display:block;margin-bottom:8px;">
                    <?php _e( 'Redirect Type', 'wp-post-redirect' ); ?>
                </label>
                <select id="wppr-redirect-type" name="wppr_redirect_type" class="widefat" style="margin-bottom:10px;">
                    <option value="external" <?php selected( empty($internal_id), true ); ?>><?php _e( 'External URL', 'wp-post-redirect' ); ?></option>
                    <option value="internal" <?php selected( !empty($internal_id), true ); ?>><?php _e( 'Internal Content', 'wp-post-redirect' ); ?></option>
                </select>
            </div>

            <div id="wppr-external-wrapper" style="<?php echo !empty($internal_id) ? 'display:none;' : ''; ?>">
                <p>
                    <label for="wppr-redirect-url" style="font-weight:600;display:block;margin-bottom:8px;">
                        <?php _e( 'Destination URL', 'wp-post-redirect' ); ?>
                    </label>
                    <input type="url" id="wppr-redirect-url" name="wppr_external_url" 
                           value="<?php echo esc_url( $prurl ); ?>" class="widefat" 
                           placeholder="https://example.com/" autocomplete="off" 
                           style="padding: 8px; border-radius: 4px;" />
                </p>
            </div>

            <div id="wppr-internal-wrapper" style="<?php echo empty($internal_id) ? 'display:none;' : ''; ?>">
                <p>
                    <label style="font-weight:600;display:block;margin-bottom:8px;">
                        <?php _e( 'Search Internal Content', 'wp-post-redirect' ); ?>
                    </label>
                    <input type="text" id="wppr-search-input" class="widefat" placeholder="<?php esc_attr_e( 'Search post, page...', 'wp-post-redirect' ); ?>" autocomplete="off" style="padding: 8px; border-radius: 4px;" />
                    <input type="hidden" id="wppr-internal-id" name="wppr_internal_id" value="<?php echo esc_attr( $internal_id ); ?>" />
                    <div id="wppr-search-results" style="background:#fff;border:1px solid #ccc;display:none;max-height:150px;overflow-y:auto;margin-top:2px;position:relative;z-index:99;"></div>
                    <div id="wppr-selected-content" style="margin-top:8px; <?php echo empty($internal_id) ? 'display:none;' : ''; ?>">
                        <span class="dashicons dashicons-admin-links" style="font-size:16px;width:16px;height:16px;margin-top:2px;"></span>
                        <span id="wppr-selected-title" style="font-weight:500;"><?php echo esc_html( $internal_title ); ?></span>
                        <a href="#" id="wppr-clear-internal" style="color:red;text-decoration:none;margin-left:8px;font-size:11px;">[<?php _e( 'remove', 'wp-post-redirect' ); ?>]</a>
                    </div>
                </p>
            </div>

            <p style="margin-top:15px;">
                <label for="wppr-redirect-status" style="font-weight:600;display:block;margin-bottom:8px;">
                    <?php _e( 'HTTP Status', 'wp-post-redirect' ); ?>
                </label>
                <select id="wppr-redirect-status" name="<?php echo esc_attr( WP_Post_Redirect::META_HTTP_STATUS ); ?>" class="widefat">
                    <option value="" <?php selected( $post_status, '' ); ?>><?php printf( __( 'Default (%d)', 'wp-post-redirect' ), $global_status ); ?></option>
                    <option value="301" <?php selected( $post_status, '301' ); ?>>301 <?php _e( 'Moved Permanently', 'wp-post-redirect' ); ?></option>
                    <option value="307" <?php selected( $post_status, '307' ); ?>>307 <?php _e( 'Temporary Redirect', 'wp-post-redirect' ); ?></option>
                    <option value="308" <?php selected( $post_status, '308' ); ?>>308 <?php _e( 'Permanent Redirect', 'wp-post-redirect' ); ?></option>
                </select>
            </p>
            
            <div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 15px;">
                <p style="margin-bottom: 8px;">
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( WP_Post_Redirect::META_TARGET_BLANK ); ?>" value="1" <?php checked( $blank, '1' ); ?> />
                        <?php _e( 'Open in new tab', 'wp-post-redirect' ); ?>
                    </label>
                </p>
                <p style="margin-bottom: 8px;">
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr( WP_Post_Redirect::META_REL_NOFOLLOW ); ?>" value="1" <?php checked( $nofollow, '1' ); ?> />
                        <?php _e( 'Add rel="nofollow"', 'wp-post-redirect' ); ?>
                    </label>
                </p>
            </div>

            <p class="description" style="margin-top: 15px; font-style: italic; color: #666;">
                <?php _e( 'Enter the URL or select a content where you want to redirect. Options affect menu links as well.', 'wp-post-redirect' ); ?>
            </p>
        </div>
        <script>
        (function($){
            $(document).ready(function(){
                const $typeSelect = $('#wppr-redirect-type');
                const $externalWrp = $('#wppr-external-wrapper');
                const $internalWrp = $('#wppr-internal-wrapper');
                const $searchInput = $('#wppr-search-input');
                const $resultsBox = $('#wppr-search-results');
                const $internalId = $('#wppr-internal-id');
                const $selectedWrp = $('#wppr-selected-content');
                const $selectedTitle = $('#wppr-selected-title');
                const $externalUrl = $('#wppr-redirect-url');

                $typeSelect.on('change', function(){
                    if($(this).val() === 'external'){
                        $externalWrp.show();
                        $internalWrp.hide();
                    } else {
                        $externalWrp.hide();
                        $internalWrp.show();
                    }
                });

                let timer;
                $searchInput.on('input', function(){
                    clearTimeout(timer);
                    const q = $(this).val();
                    if(q.length < 3) {
                        $resultsBox.hide();
                        return;
                    }

                    timer = setTimeout(function(){
                        $.ajax({
                            url: ajaxurl,
                            data: {
                                action: 'wppr_search_posts',
                                q: q,
                                nonce: '<?php echo wp_create_nonce( "wppr_metabox_nonce" ); ?>'
                            },
                            success: function(res){
                                if(res.success && res.data.length > 0){
                                    $resultsBox.empty().show();
                                    res.data.forEach(function(item){
                                        $resultsBox.append('<div class="wppr-search-item" data-id="'+item.id+'" data-title="'+item.title+'" style="padding:8px;cursor:pointer;border-bottom:1px solid #eee;">'+item.title+'</div>');
                                    });
                                }
                            }
                        });
                    }, 300);
                });

                $(document).on('click', '.wppr-search-item', function(){
                    const id = $(this).data('id');
                    const title = $(this).data('title');
                    $internalId.val(id);
                    $selectedTitle.text(title);
                    $selectedWrp.show();
                    $resultsBox.hide();
                    $searchInput.val('');
                });

                $('#wppr-clear-internal').on('click', function(e){
                    e.preventDefault();
                    $internalId.val('');
                    $selectedWrp.hide();
                });
                
                $(document).on('click', function(e){
                    if(!$(e.target).closest('#wppr-internal-wrapper').length) $resultsBox.hide();
                });
            });
        })(jQuery);
        </script>
        <style>
            .wppr-search-item:hover { background: #f0f0f0; }
            .edit-post-meta-boxes-area #wpr_redirect_url .inside { padding-bottom: 10px; }
            .wppr-metabox-content input[type="url"]:focus, .wppr-metabox-content input[type="text"]:focus {
                border-color: #2271b1;
                box-shadow: 0 0 0 1px #2271b1;
            }
        </style>
        <?php
    }

    public function save_redirect_meta( $post_id, $post ) {
        // Verify nonce
        if ( ! isset( $_POST['prurlmeta_noncename'] ) || ! wp_verify_nonce( $_POST['prurlmeta_noncename'], plugin_basename( WP_Post_Redirect::PLUGIN_FILE ) ) ) {
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
        
        // Unify saving logic into META_KEY
        $type = isset( $_POST['wppr_redirect_type'] ) ? $_POST['wppr_redirect_type'] : 'external';
        $external_url = isset( $_POST['wppr_external_url'] ) ? trim( $_POST['wppr_external_url'] ) : '';
        $internal_id = isset( $_POST['wppr_internal_id'] ) ? absint( $_POST['wppr_internal_id'] ) : 0;
        
        // Determine final value based on selected type
        $final_value = '';
        if ( $type === 'internal' ) {
            $final_value = $internal_id ? $internal_id : '';
        } else {
            $final_value = $external_url;
        }

        if ( $final_value ) {
            update_post_meta( $post_id, WP_Post_Redirect::META_KEY, $final_value );
            
            // Save additional options
            $blank = isset( $_POST[ WP_Post_Redirect::META_TARGET_BLANK ] ) ? '1' : '0';
            update_post_meta( $post_id, WP_Post_Redirect::META_TARGET_BLANK, $blank );

            $nofollow = isset( $_POST[ WP_Post_Redirect::META_REL_NOFOLLOW ] ) ? '1' : '0';
            update_post_meta( $post_id, WP_Post_Redirect::META_REL_NOFOLLOW, $nofollow );

            $status = isset( $_POST[ WP_Post_Redirect::META_HTTP_STATUS ] ) && $_POST[ WP_Post_Redirect::META_HTTP_STATUS ] !== '' ? absint( $_POST[ WP_Post_Redirect::META_HTTP_STATUS ] ) : '';
            if ( $status ) {
                update_post_meta( $post_id, WP_Post_Redirect::META_HTTP_STATUS, $status );
            } else {
                delete_post_meta( $post_id, WP_Post_Redirect::META_HTTP_STATUS );
            }
        } else {
            delete_post_meta( $post_id, WP_Post_Redirect::META_KEY );
            delete_post_meta( $post_id, WP_Post_Redirect::META_TARGET_BLANK );
            delete_post_meta( $post_id, WP_Post_Redirect::META_REL_NOFOLLOW );
            delete_post_meta( $post_id, WP_Post_Redirect::META_HTTP_STATUS );
        }
        // Always delete legacy internal ID meta if it exists
        delete_post_meta( $post_id, '_prurl_internal_id' );
    }

    public function show_redirect_in_permalink( $return, $id, $new_title, $new_slug ) {
        $redirect = $this->parent->get_redirect_url( $id );
        if ( $redirect ) {
            $return = "<strong>" . __( "Redirect:", 'wp-post-redirect' ) . "</strong> " . esc_html( $redirect ) . "<style>#titlediv {margin-bottom: 30px;}</style><br/>" . $return;
        }
        return $return;
    }

    public function add_options_page() {
        add_options_page(
            __( 'WP Post Redirect', 'wp-post-redirect' ),
            __( 'WP Post Redirect', 'wp-post-redirect' ),
            'manage_options',
            WP_Post_Redirect::OPTION_PAGE_SLUG,
            [ $this, 'render_options_page' ]
        );
    }

    public function render_options_page() {
        global $wpdb;
        $all_cpts = get_post_types( [ 'public' => true ], 'objects' );
        $enabled_cpts = get_option( WP_Post_Redirect::OPTION_CPTS, [ 'post' ] );
        $http_status = get_option( WP_Post_Redirect::OPTION_HTTP_STATUS, 301 );

        echo '<div class="wrap">';
        echo '<h1 style="display:flex;align-items:center;gap:10px;">' . esc_html__( 'WP Post Redirect', 'wp-post-redirect' ) . '</h1>';
        echo '<h2 style="font-weight:normal;color:#666;">' . esc_html__( 'Settings', 'wp-post-redirect' ) . '</h2>';

        // CPT selection form
        echo '<form method="post" action="options.php" style="margin-bottom:32px;">';
        settings_fields( 'wppr_options_group' );
        echo '<table class="form-table">
            <tr>
                <th scope="row">' . esc_html__( 'Enable Redirect Metabox for:', 'wp-post-redirect' ) . '</th>
                <td>';
        foreach ( $all_cpts as $cpt ) {
            $checked = in_array( $cpt->name, $enabled_cpts, true ) ? 'checked' : '';
            $disabled = $cpt->name === 'post' ? 'disabled' : '';
            echo '<label style="margin-right:20px;">';
            echo '<input type="checkbox" name="' . esc_attr( WP_Post_Redirect::OPTION_CPTS ) . '[]" value="' . esc_attr( $cpt->name ) . '" ' . $checked . ' ' . $disabled . ' />';
            echo esc_html( $cpt->labels->singular_name );
            if ( $cpt->name === 'post' ) {
                echo ' <span style="color:#666;">(' . esc_html__( 'always enabled', 'wp-post-redirect' ) . ')</span>';
            }
            echo '</label>';
        }
        echo '</td></tr>
            <tr>
                <th scope="row">' . esc_html__( 'Redirect HTTP Status:', 'wp-post-redirect' ) . '</th>
                <td>
                    <select name="' . esc_attr( WP_Post_Redirect::OPTION_HTTP_STATUS ) . '">
                        <option value="301" ' . selected( $http_status, 301, false ) . '>301 ' . esc_html__( 'Moved Permanently', 'wp-post-redirect' ) . '</option>
                        <option value="307" ' . selected( $http_status, 307, false ) . '>307 ' . esc_html__( 'Temporary Redirect', 'wp-post-redirect' ) . '</option>
                        <option value="308" ' . selected( $http_status, 308, false ) . '>308 ' . esc_html__( 'Permanent Redirect', 'wp-post-redirect' ) . '</option>
                    </select>
                    <p class="description">' . esc_html__( 'Select the HTTP status code to use for redirections. 301 is most common for SEO.', 'wp-post-redirect' ) . '</p>
                </td>
            </tr>
        </table>';
        submit_button();
        echo '</form>';

        echo '<h2 style="font-weight:normal;color:#666;">' . esc_html__( 'Active Redirections', 'wp-post-redirect' ) . '</h2>';
        echo '<div style="margin: 20px 0 24px 0;">';
        echo '<a href="' . esc_url( wp_nonce_url(admin_url('options-general.php?page=' . WP_Post_Redirect::OPTION_PAGE_SLUG . '&wppr_export=1'), 'wppr_export_action', 'wppr_export_nonce') ) . '" class="button button-secondary">' . esc_html__('Export Redirections (CSV)', 'wp-post-redirect') . '</a>';
        echo '</div>';

        // Query for all redirections with post type, title, date, and URL (Manual URL or Internal ID)
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID as post_id, p.post_type, p.post_title, p.post_date
                 FROM {$wpdb->posts} p
                 WHERE p.ID IN (
                    SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s
                 )
                 ORDER BY p.post_type ASC, p.ID ASC",
                WP_Post_Redirect::META_KEY
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
                        <th class="manage-column" style="width:60px;">' . esc_html__( 'ID', 'wp-post-redirect' ) . '</th>
                        <th class="manage-column">' . esc_html__( 'Title', 'wp-post-redirect' ) . '</th>
                        <th class="manage-column" style="width:100px;">' . esc_html__( 'Post Type', 'wp-post-redirect' ) . '</th>
                        <th class="manage-column" style="width:80px;">' . esc_html__( 'Type', 'wp-post-redirect' ) . '</th>
                        <th class="manage-column">' . esc_html__( 'Destination', 'wp-post-redirect' ) . '</th>
                        <th class="manage-column" style="width:60px; text-align:center;">' . esc_html__( 'Tab', 'wp-post-redirect' ) . '</th>
                        <th class="manage-column" style="width:60px; text-align:center;">' . esc_html__( 'Rel', 'wp-post-redirect' ) . '</th>
                        <th class="manage-column" style="width:70px; text-align:center;">' . esc_html__( 'Status', 'wp-post-redirect' ) . '</th>
                        <th class="manage-column" style="width:120px;">' . esc_html__( 'Date', 'wp-post-redirect' ) . '</th>
                    </tr>
                </thead>
                <tbody>';
            foreach ( $rows as $r ) {
                $post_id = intval( $r->post_id );
                $post_status_query = get_post_status( $post_id );
                if ( ! $post_status_query ) {
                    continue;
                }
                
                $redirect_url = $this->parent->get_redirect_url( $post_id );
                if ( ! $redirect_url ) continue;

                $raw_value = get_post_meta( $post_id, WP_Post_Redirect::META_KEY, true );
                $internal_id = is_numeric( $raw_value ) ? $raw_value : '';
                
                $title = get_the_title( $post_id );
                $edit_link = get_edit_post_link( $post_id );
                $post_type_obj = get_post_type_object( $r->post_type );
                $post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : esc_html( $r->post_type );
                $published_date = esc_html( mysql2date( get_option( 'date_format' ), $r->post_date ) );
                
                $blank = get_post_meta( $post_id, WP_Post_Redirect::META_TARGET_BLANK, true ) === '1' ? '&#10003;' : '-';
                $nofollow = get_post_meta( $post_id, WP_Post_Redirect::META_REL_NOFOLLOW, true ) === '1' ? '&#10003;' : '-';
                $meta_status = get_post_meta( $post_id, WP_Post_Redirect::META_HTTP_STATUS, true );
                $global_status = get_option( WP_Post_Redirect::OPTION_HTTP_STATUS, 301 );
                $display_status = $meta_status ? $meta_status : sprintf( __( 'Default (%d)', 'wp-post-redirect' ), $global_status );

                $dest_type = $internal_id ? '<span class="dashicons dashicons-admin-home" title="Internal"></span>' : '<span class="dashicons dashicons-admin-links" title="External"></span>';
                $display_url = $internal_id ? get_the_title( $internal_id ) : $redirect_url;

                echo '<tr>';
                echo '<td>' . esc_html( $post_id ) . '</td>';
                echo '<td><a href="' . esc_url( $edit_link ) . '" target="_blank">' . esc_html( $title ) . '</a></td>';
                echo '<td><span class="tag-post-type">' . esc_html( $post_type_label ) . '</span></td>';
                echo '<td style="text-align:center;">' . $dest_type . '</td>';
                echo '<td><a href="' . esc_url( $redirect_url ) . '" target="_blank" rel="noopener noreferrer" class="dest-link" title="' . esc_attr($redirect_url) . '">' . esc_html( $display_url ) . '</a></td>';
                echo '<td style="text-align:center;">' . $blank . '</td>';
                echo '<td style="text-align:center;">' . $nofollow . '</td>';
                echo '<td style="text-align:center;"><code style="font-size:11px;">' . esc_html( $display_status ) . '</code></td>';
                echo '<td><small>' . $published_date . '</small></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        };

        // Active redirects table
        $render_table( $active, __( 'Active Redirections', 'wp-post-redirect' ) );
        // Inactive redirects table
        $render_table( $inactive, __( 'Inactive Redirections (CPT disabled)', 'wp-post-redirect' ) );

        echo '<style>
            .wppr-table th, .wppr-table td { padding: 12px 10px; vertical-align: middle; }
            .wppr-table tr:nth-child(even) { background: #f9f9f9; }
            .wppr-table th { background: #f1f1f1; font-weight: 600; }
            .wppr-table a { color: #2271b1; text-decoration: none; font-weight: 500; }
            .wppr-table a:hover { color: #135e96; text-decoration: underline; }
            .tag-post-type { background: #eee; padding: 2px 6px; border-radius: 3px; font-size: 11px; text-transform: uppercase; color: #666; }
            .dest-link { display: block; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .dashicons-randomize { font-size: 32px; vertical-align: middle; }
            .wppr-table .dashicons { color: #666; }
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
                    "SELECT p.ID as post_id, p.post_type, p.post_title, p.post_date
                     FROM {$wpdb->posts} p
                     WHERE p.ID IN (
                        SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s
                     )
                     ORDER BY p.post_type ASC, p.ID ASC",
                    WP_Post_Redirect::META_KEY
                )
            );
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="wp-post-redirects.csv"');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Post ID', 'Post Type', 'Post Title', 'Published Date', 'Redirect URL', 'Internal Content', 'New Tab', 'Nofollow', 'HTTP Status']);
            foreach ($results as $row) {
                $post_id = $row->post_id;
                $redirect_url = $this->parent->get_redirect_url( $post_id );
                if( ! $redirect_url ) continue;

                $post_type_obj = get_post_type_object( $row->post_type );
                $post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : $row->post_type;
                $published_date = mysql2date( get_option( 'date_format' ), $row->post_date );
                
                $raw_value = get_post_meta( $post_id, WP_Post_Redirect::META_KEY, true );
                $internal_id = is_numeric( $raw_value ) ? $raw_value : '';
                $internal_title = $internal_id ? get_the_title( $internal_id ) : '-';
                
                $blank = get_post_meta( $post_id, WP_Post_Redirect::META_TARGET_BLANK, true ) === '1' ? 'Yes' : 'No';
                $nofollow = get_post_meta( $post_id, WP_Post_Redirect::META_REL_NOFOLLOW, true ) === '1' ? 'Yes' : 'No';
                $meta_status = get_post_meta( $post_id, WP_Post_Redirect::META_HTTP_STATUS, true );
                $status = $meta_status ? $meta_status : get_option( WP_Post_Redirect::OPTION_HTTP_STATUS, 301 );
                
                fputcsv($output, [
                    $post_id,
                    $post_type_label,
                    $row->post_title,
                    $published_date,
                    $redirect_url,
                    $internal_title,
                    $blank,
                    $nofollow,
                    $status
                ]);
            }
            fclose($output);
            exit;
        }
    }
}
