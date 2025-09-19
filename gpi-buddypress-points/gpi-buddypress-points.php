<?php
/**
 * Plugin Name: Gamified Points Integration for BuddyPress (GPI)
 * Description: A lightweight points/rewards engine integrated with BuddyPress activity (posting, joining, liking). Stores points in user meta and keeps a points log.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: gpi-buddypress
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GPI_BuddyPress_Points {
    private static $instance = null;
    public $options = array();
    public $table_name;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'gpi_points_log';

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'bp_loaded', array( $this, 'maybe_load' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // shortcodes
        add_shortcode( 'gpi_points', array( $this, 'shortcode_points' ) );
    }

    public function maybe_load() {
        // Only hook into BuddyPress actions if BuddyPress is active
        if ( defined( 'BP_VERSION' ) ) {
            // Activity posted (status update)
            add_action( 'bp_activity_posted_update', array( $this, 'on_activity_posted_update' ), 10, 3 );
            // Generic activity add
            add_action( 'bp_activity_add', array( $this, 'on_activity_add' ), 10, 3 );
            // Mark activity favorite (like)
            add_action( 'bp_activity_mark_favorite', array( $this, 'on_activity_favorited' ), 10, 2 );
            add_action( 'bp_activity_unmark_favorite', array( $this, 'on_activity_unfavorited' ), 10, 2 );
            // Group join
            add_action( 'groups_join_group', array( $this, 'on_group_join' ), 10, 2 );
        }

        // User registration (site join)
        add_action( 'user_register', array( $this, 'on_user_register' ), 10, 1 );
    }

    public function activate() {
        $this->create_log_table();
        // default options
        $defaults = array(
            'points_post' => 5,
            'points_register' => 50,
            'points_group_join' => 10,
            'points_like_received' => 1,
            'points_like_given' => 0,
            'log_retention_days' => 3650,
        );
        add_option( 'gpi_options', $defaults );
    }

    public function deactivate() {
        // We keep data on deactivation. If you want to delete, add an uninstall handler.
    }

    private function create_log_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table = $this->table_name;

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            type varchar(60) NOT NULL,
            points int NOT NULL,
            related_id bigint(20) unsigned NULL,
            note text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY type (type)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function register_settings() {
        register_setting( 'gpi_settings', 'gpi_options', array( $this, 'sanitize_options' ) );

        add_settings_section( 'gpi_main', __( 'Points values', 'gpi-buddypress' ), null, 'gpi_settings' );

        add_settings_field( 'points_post', __( 'Points for posting an activity', 'gpi-buddypress' ), array( $this, 'render_field' ), 'gpi_settings', 'gpi_main', array( 'label_for' => 'points_post' ) );
        add_settings_field( 'points_register', __( 'Points for registering', 'gpi-buddypress' ), array( $this, 'render_field' ), 'gpi_settings', 'gpi_main', array( 'label_for' => 'points_register' ) );
        add_settings_field( 'points_group_join', __( 'Points for joining a group', 'gpi-buddypress' ), array( $this, 'render_field' ), 'gpi_settings', 'gpi_main', array( 'label_for' => 'points_group_join' ) );
        add_settings_field( 'points_like_received', __( 'Points for receiving a like', 'gpi-buddypress' ), array( $this, 'render_field' ), 'gpi_settings', 'gpi_main', array( 'label_for' => 'points_like_received' ) );
        add_settings_field( 'points_like_given', __( 'Points for giving a like', 'gpi-buddypress' ), array( $this, 'render_field' ), 'gpi_settings', 'gpi_main', array( 'label_for' => 'points_like_given' ) );

        add_settings_section( 'gpi_advanced', __( 'Advanced', 'gpi-buddypress' ), null, 'gpi_settings' );
        add_settings_field( 'log_retention_days', __( 'Log retention (days)', 'gpi-buddypress' ), array( $this, 'render_field' ), 'gpi_settings', 'gpi_advanced', array( 'label_for' => 'log_retention_days' ) );
    }

    public function sanitize_options( $input ) {
        $out = array();
        $out['points_post'] = intval( $input['points_post'] );
        $out['points_register'] = intval( $input['points_register'] );
        $out['points_group_join'] = intval( $input['points_group_join'] );
        $out['points_like_received'] = intval( $input['points_like_received'] );
        $out['points_like_given'] = intval( $input['points_like_given'] );
        $out['log_retention_days'] = max( 0, intval( $input['log_retention_days'] ) );
        return $out;
    }

    public function render_field( $args ) {
        $opts = get_option( 'gpi_options', array() );
        $id = $args['label_for'];
        $val = isset( $opts[ $id ] ) ? intval( $opts[ $id ] ) : '';
        printf( '<input type="number" id="%1$s" name="gpi_options[%1$s]" value="%2$s" class="small-text" />', esc_attr( $id ), esc_attr( $val ) );
    }

    public function admin_menu() {
        add_options_page( 'GPI Settings', 'GPI Points', 'manage_options', 'gpi-settings', array( $this, 'settings_page' ) );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>GPI - Gamified Points Integration</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'gpi_settings' );
                do_settings_sections( 'gpi_settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /* ------------------ Points API ------------------ */
    public function get_points( $user_id ) {
        return intval( get_user_meta( $user_id, 'gpi_points', true ) ) ?: 0;
    }

    public function set_points( $user_id, $points ) {
        update_user_meta( $user_id, 'gpi_points', intval( $points ) );
    }

    public function adjust_points( $user_id, $delta, $type = '', $related_id = 0, $note = '' ) {
        $user_id = intval( $user_id );
        if ( $user_id <= 0 ) return false;

        $current = $this->get_points( $user_id );
        $new = $current + intval( $delta );
        $this->set_points( $user_id, $new );

        // log
        global $wpdb;
        $wpdb->insert(
            $this->table_name,
            array(
                'user_id' => $user_id,
                'type' => substr( $type, 0, 60 ),
                'points' => intval( $delta ),
                'related_id' => intval( $related_id ),
                'note' => $note,
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%d', '%d', '%s', '%s' )
        );

        /**
         * Action hook fired after points change.
         * do_action( 'gpi_points_changed', $user_id, $delta, $type, $related_id );
         */
        do_action( 'gpi_points_changed', $user_id, $delta, $type, $related_id );

        return $new;
    }

    /* ------------------ BuddyPress hooks ------------------ */
    public function on_activity_posted_update( $content, $user_id, $activity_id ) {
        $opts = get_option( 'gpi_options' );
        $points = isset( $opts['points_post'] ) ? intval( $opts['points_post'] ) : 0;
        if ( $points !== 0 ) {
            $this->adjust_points( $user_id, $points, 'post_activity', $activity_id, 'Posted an activity' );
        }
    }

    // More generic activity add hook (for activities not covered by posted_update)
    public function on_activity_add( $args = array(), $activity_id = 0, $meta = null ) {
        // $args is array with 'user_id' etc when available
        $user_id = 0;
        if ( is_array( $args ) && isset( $args['user_id'] ) ) $user_id = intval( $args['user_id'] );
        if ( ! $user_id && isset( $args->user_id ) ) $user_id = intval( $args->user_id );
        if ( $user_id ) {
            // To avoid double-crediting, only credit if content present and different hook path
            // We'll credit by default via bp_activity_posted_update; skip here to avoid duplicates
        }
    }

    public function on_activity_favorited( $activity_id, $user_id ) {
        // who favorited (user_id) and activity owner
        $activity = bp_activity_get_specific( array( 'activity_ids' => array( $activity_id ), 'display_comments' => 'stream' ) );
        if ( empty( $activity['activities'] ) ) return;
        $act = $activity['activities'][0];
        $owner_id = intval( $act->user_id );

        $opts = get_option( 'gpi_options' );
        $points_received = isset( $opts['points_like_received'] ) ? intval( $opts['points_like_received'] ) : 0;
        $points_given = isset( $opts['points_like_given'] ) ? intval( $opts['points_like_given'] ) : 0;

        if ( $points_received !== 0 && $owner_id ) {
            $this->adjust_points( $owner_id, $points_received, 'like_received', $activity_id, 'Received a favorite' );
        }
        if ( $points_given !== 0 && $user_id ) {
            $this->adjust_points( $user_id, $points_given, 'like_given', $activity_id, 'Gave a favorite' );
        }
    }

    public function on_activity_unfavorited( $activity_id, $user_id ) {
        // remove previously given points (mirror of favorited)
        $activity = bp_activity_get_specific( array( 'activity_ids' => array( $activity_id ), 'display_comments' => 'stream' ) );
        if ( empty( $activity['activities'] ) ) return;
        $act = $activity['activities'][0];
        $owner_id = intval( $act->user_id );

        $opts = get_option( 'gpi_options' );
        $points_received = isset( $opts['points_like_received'] ) ? intval( $opts['points_like_received'] ) : 0;
        $points_given = isset( $opts['points_like_given'] ) ? intval( $opts['points_like_given'] ) : 0;

        if ( $points_received !== 0 && $owner_id ) {
            $this->adjust_points( $owner_id, - $points_received, 'like_removed', $activity_id, 'Favorite removed' );
        }
        if ( $points_given !== 0 && $user_id ) {
            $this->adjust_points( $user_id, - $points_given, 'like_unset', $activity_id, 'Removed a favorite' );
        }
    }

    public function on_group_join( $group_id, $user_id ) {
        $opts = get_option( 'gpi_options' );
        $points = isset( $opts['points_group_join'] ) ? intval( $opts['points_group_join'] ) : 0;
        if ( $points !== 0 ) {
            $this->adjust_points( $user_id, $points, 'group_join', $group_id, 'Joined a group' );
        }
    }

    public function on_user_register( $user_id ) {
        $opts = get_option( 'gpi_options' );
        $points = isset( $opts['points_register'] ) ? intval( $opts['points_register'] ) : 0;
        if ( $points !== 0 ) {
            $this->adjust_points( $user_id, $points, 'register', 0, 'User registered' );
        }
    }

    /* ------------------ Utility endpoints ------------------ */
    public function shortcode_points( $atts ) {
        $atts = shortcode_atts( array( 'user' => 0 ), $atts, 'gpi_points' );
        $user_id = intval( $atts['user'] );
        if ( ! $user_id ) $user_id = get_current_user_id();
        $points = $this->get_points( $user_id );
        return '<span class="gpi-points" data-userid="' . esc_attr( $user_id ) . '">' . intval( $points ) . '</span>';
    }

}

// Boot
GPI_BuddyPress_Points::instance();

// Uninstall handler (optional) - if you want complete removal, create an uninstall.php or register_uninstall_hook()
