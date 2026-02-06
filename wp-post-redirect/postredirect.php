<?php
/*
Plugin Name: WP Post Redirect
Description: Redirect your posts to an external link by adding the url into a new metabox. Simple and efficient!
Version: 2.2
Text Domain: wp-post-redirect
Author: Marco Milesi
Author Email: milesimarco@outlook.com
Author URI: http://www.marcomilesi.com
License: GPLv2 or later
*/

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WP_Post_Redirect' ) ) :

class WP_Post_Redirect {

    const PLUGIN_FILE = __FILE__;
    const META_KEY = '_prurl';
    const META_TARGET_BLANK = '_prurl_blank';
    const META_REL_NOFOLLOW = '_prurl_nofollow';
    const META_HTTP_STATUS = '_prurl_http_status';
    const OPTION_PAGE_SLUG = 'wp-redirect-option-page';
    const OPTION_HTTP_STATUS = 'wppr_http_status';
    const OPTION_CPTS = 'wppr_enabled_cpts';

    public function __construct() {
        // Core Redirection
        add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );
        add_filter( 'post_link', [ $this, 'filter_post_link' ], 10, 2 );

        // Link attributes for Menus
        add_filter( 'nav_menu_link_attributes', [ $this, 'filter_nav_menu_link_attributes' ], 10, 2 );

        // Admin-only logic initialization
        if ( is_admin() ) {
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-wp-post-redirect-admin.php';
            new WP_Post_Redirect_Admin( $this );
            
            // Plugin action link
            add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_plugin_action_link' ] );
        }
    }

    public function add_plugin_action_link( $links ) {
        $url = admin_url( 'options-general.php?page=' . self::OPTION_PAGE_SLUG );
        $links[] = '<a href="' . esc_url( $url ) . '">' . __( 'Redirections', 'wp-post-redirect' ) . '</a>';
        return $links;
    }

    public function maybe_redirect() {
        if ( is_singular() ) {
            $id = get_queried_object_id();
            $post = get_post( $id );
            $enabled = get_option( self::OPTION_CPTS, [ 'post' ] );
            if ( $post && in_array( $post->post_type, $enabled, true ) ) {
                $link = $this->get_redirect_url( $id );
                if ( $link ) {
                    $post_status = get_post_meta( $id, self::META_HTTP_STATUS, true );
                    $status = $post_status ? absint( $post_status ) : get_option( self::OPTION_HTTP_STATUS, 301 );
                    wp_redirect( $link, $status );
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
        if ( ! $redirect ) {
            return false;
        }

        // Check if value is a numeric ID (Internal Content)
        if ( is_numeric( $redirect ) && get_post_status( $redirect ) ) {
            return get_permalink( $redirect );
        }

        // Otherwise handle as External URL with placeholders
        if ( ! isset( $placeholders ) ) {
            $placeholders = apply_filters( 'redirect_placeholders', [
                '%home%' => get_home_url(),
                '%site%' => get_site_url(),
            ] );
        }
        return str_replace( array_keys( $placeholders ), array_values( $placeholders ), $redirect );
    }

    public function filter_nav_menu_link_attributes( $atts, $item ) {
        if ( $item->type === 'post_type' ) {
            $post_id = $item->object_id;
            $redirect_url = $this->get_redirect_url( $post_id );
            if ( $redirect_url ) {
                $blank = get_post_meta( $post_id, self::META_TARGET_BLANK, true );
                $nofollow = get_post_meta( $post_id, self::META_REL_NOFOLLOW, true );
                
                if ( $blank === '1' ) {
                    $atts['target'] = '_blank';
                    $atts['rel'] = isset( $atts['rel'] ) ? $atts['rel'] . ' noopener' : 'noopener';
                }
                if ( $nofollow === '1' ) {
                    $atts['rel'] = isset( $atts['rel'] ) ? $atts['rel'] . ' nofollow' : 'nofollow';
                }
            }
        }
        return $atts;
    }
}

new WP_Post_Redirect();

endif; // End if class_exists
