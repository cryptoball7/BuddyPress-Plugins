<?php
/**
 * Plugin Name: BuddyPress Activity Stream Enhancements (Reactions)
 * Description: Adds reactions (👍 ❤️ 😂) to BuddyPress activity items, stores activity meta, provides AJAX integration and UX improvements.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: bre-reactions
 *
 * Files included in this single-plugin package (below are separated by file markers):
 *  - bp-activity-reactions.php           (main plugin file)
 *  - assets/js/reactions.js              (frontend JS)
 *  - assets/css/reactions.css            (frontend CSS)
 *
 * Install: drop into wp-content/plugins/bp-activity-reactions/ and activate.
 */

// -----------------------------
// File: bp-activity-reactions.php
// -----------------------------

if ( ! defined( 'ABSPATH' ) ) exit;

class BRE_Reactions_Plugin {
    public $reactions = array( 'like' => '👍', 'love' => '❤️', 'laugh' => '😂' );
    public static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Add UI inside BuddyPress activity loop meta area
        add_action( 'bp_activity_entry_meta', array( $this, 'render_reaction_ui' ) );

        // AJAX endpoints
        add_action( 'wp_ajax_bre_toggle_reaction', array( $this, 'ajax_toggle_reaction' ) );

        // For backward compatibility: allow non-authenticated if you want (disabled by default)
        // add_action( 'wp_ajax_nopriv_bre_toggle_reaction', array( $this, 'ajax_toggle_reaction' ) );

        // Make sure BuddyPress functions exist before trying to use them
        add_action( 'bp_loaded', array( $this, 'maybe_setup' ) );

        // Add simple CSS class to activity item for styling
        add_filter( 'bp_get_activity_css_class', array( $this, 'add_activity_css' ), 10, 2 );

        // REST: If you want a REST route in future, here's a placeholder (not registered by default)
    }

    public function maybe_setup() {
        if ( ! function_exists( 'bp_activity_get' ) ) {
            // BuddyPress not active; do nothing.
            return;
        }
    }

    public function enqueue_assets() {
        $plugin_dir = plugin_dir_url( __FILE__ );
        wp_enqueue_style( 'bre-reactions-css', $plugin_dir . 'assets/css/reactions.css', array(), '1.0.0' );
        wp_enqueue_script( 'bre-reactions-js', $plugin_dir . 'assets/js/reactions.js', array( 'jquery' ), '1.0.0', true );

        // Localize needed data
        wp_localize_script( 'bre-reactions-js', 'BRE_Reactions', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bre_reactions_nonce' ),
            'reactions'=> $this->reactions,
            'labels'   => array(
                'react' => __( 'React', 'bre-reactions' ),
            ),
        ) );
    }

    public function add_activity_css( $classes, $activity ) {
        $classes .= ' bre-activity-item';
        return $classes;
    }

    /**
     * Render the reaction UI inside the activity meta area
     */
    public function render_reaction_ui() {
        if ( ! function_exists( 'bp_activity_get_meta' ) ) { return; }

        // Get current activity ID from global or template
        global $activities_template;
        if ( empty( $activities_template->activity ) || empty( $activities_template->activity->id ) ) {
            return;
        }
        $activity_id = intval( $activities_template->activity->id );

        $reactions = $this->get_reactions( $activity_id );
        $user_id = get_current_user_id();

        // Build HTML
        echo '<div class="bre-reactions" data-activity-id="' . esc_attr( $activity_id ) . '">';
        foreach ( $this->reactions as $key => $emoji ) {
            $count = isset( $reactions[ $key ] ) ? count( $reactions[ $key ] ) : 0;
            $user_has = ( $user_id && isset( $reactions[ $key ] ) && in_array( $user_id, $reactions[ $key ] ) );
            $active_class = $user_has ? ' bre-react-active' : '';

            // Button with accessible aria labels
            printf(
                '<button class="bre-reaction-btn%s" data-reaction="%s" aria-pressed="%s" title="%s">%s <span class="bre-count">%d</span></button>',
                esc_attr( $active_class ),
                esc_attr( $key ),
                $user_has ? 'true' : 'false',
                esc_attr( $emoji ),
                esc_html( $emoji ),
                intval( $count )
            );
        }
        // Small 'view' link to show tooltip or expanded list in future
        echo '<button class="bre-show-details" aria-expanded="false">' . esc_html__( 'Details', 'bre-reactions' ) . '</button>';
        echo '</div>';
    }

    /**
     * Get reactions array for an activity
     * returns associative array: reaction_key => array( user_id, ... )
     */
    public function get_reactions( $activity_id ) {
        $meta = bp_activity_get_meta( $activity_id, 'bre_reactions', true );
        if ( empty( $meta ) || ! is_array( $meta ) ) {
            return array();
        }
        return $meta;
    }

    /**
     * AJAX handler to toggle a reaction for the current user
     */
    public function ajax_toggle_reaction() {
        // Security
        check_ajax_referer( 'bre_reactions_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in to react.', 'bre-reactions' ) ), 403 );
        }

        $user_id = get_current_user_id();
        $activity_id = isset( $_POST['activity_id'] ) ? intval( $_POST['activity_id'] ) : 0;
        $reaction = isset( $_POST['reaction'] ) ? sanitize_text_field( wp_unslash( $_POST['reaction'] ) ) : '';

        if ( ! $activity_id || ! $reaction || ! array_key_exists( $reaction, $this->reactions ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid request.', 'bre-reactions' ) ), 400 );
        }

        // Load existing meta
        $meta = $this->get_reactions( $activity_id );
        if ( ! isset( $meta[ $reaction ] ) ) {
            $meta[ $reaction ] = array();
        }

        // Toggle: if user exists, remove; else add
        if ( in_array( $user_id, $meta[ $reaction ] ) ) {
            $meta[ $reaction ] = array_values( array_diff( $meta[ $reaction ], array( $user_id ) ) );
            $action = 'removed';
        } else {
            $meta[ $reaction ][] = $user_id;
            $action = 'added';

            // Optionally: ensure single reaction per user per activity by removing user from other reactions
            foreach ( $meta as $k => $users ) {
                if ( $k === $reaction ) continue;
                if ( in_array( $user_id, $users ) ) {
                    $meta[ $k ] = array_values( array_diff( $users, array( $user_id ) ) );
                }
            }
        }

        // Save meta: use bp_activity_update_meta if exists; else add
        $existing = bp_activity_get_meta( $activity_id, 'bre_reactions', true );
        if ( empty( $existing ) ) {
            bp_activity_add_meta( $activity_id, 'bre_reactions', $meta );
        } else {
            // There's no bp_activity_update_meta that takes activity_id and key directly in older BP versions; use bp_activity_update_meta if available
            if ( function_exists( 'bp_activity_update_meta' ) ) {
                // Need meta_id for update; try to fetch meta id via direct query fallback
                $updated = bp_activity_update_meta( $activity_id, 'bre_reactions', $meta );
                if ( false === $updated ) {
                    // fallback to delete + add
                    bp_activity_delete_meta( $activity_id, 'bre_reactions' );
                    bp_activity_add_meta( $activity_id, 'bre_reactions', $meta );
                }
            } else {
                // fallback: delete and add
                bp_activity_delete_meta( $activity_id, 'bre_reactions' );
                bp_activity_add_meta( $activity_id, 'bre_reactions', $meta );
            }
        }

        // Build response: counts and which reaction now active
        $counts = array();
        foreach ( $this->reactions as $k => $v ) {
            $counts[ $k ] = isset( $meta[ $k ] ) ? count( $meta[ $k ] ) : 0;
            $counts[ $k . '_active' ] = in_array( $user_id, isset( $meta[ $k ] ) ? $meta[ $k ] : array() );
        }

        do_action( 'bre_reaction_toggled', $activity_id, $reaction, $user_id, $action );

        wp_send_json_success( array( 'counts' => $counts, 'action' => $action ) );
    }
}

BRE_Reactions_Plugin::instance();

