<?php
/*
Plugin Name: BuddyPress Nearby Members Map
Description: Shows a map of BuddyPress members based on their location.
Version: 1.0
Author: Cryptoball cryptoball7@gmail.com
Text Domain: bp-nearby-members-map
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Enqueue scripts and styles
function bp_nearby_members_map_enqueue() {
    // Leaflet.js
    wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
    wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), null, true);
    
    // Plugin JS/CSS
    wp_enqueue_style('bp-nm-map-css', plugin_dir_url(__FILE__) . 'assets/css/style.css');
    wp_enqueue_script('bp-nm-map-js', plugin_dir_url(__FILE__) . 'assets/js/map.js', array('jquery', 'leaflet-js'), null, true);

    // Localize AJAX URL
    wp_localize_script('bp-nm-map-js', 'bpNMMap', array(
        'ajax_url' => admin_url('admin-ajax.php')
    ));
}
add_action('wp_enqueue_scripts', 'bp_nearby_members_map_enqueue');

// Shortcode to display map
function bp_nearby_members_map_shortcode() {
    return '<div id="bp-nearby-members-map" style="width:100%; height:500px;"></div>';
}
add_shortcode('bp_nearby_members_map', 'bp_nearby_members_map_shortcode');

// AJAX to fetch member locations
function bp_nearby_members_map_ajax() {
    $members = bp_core_get_users(array(
        'type' => 'active',
        'per_page' => -1,
    ));

    $locations = array();

    foreach ($members['users'] as $user) {
        $user_id = $user->ID;
        $location = bp_get_profile_field_data(array(
            'field'   => 'Location', // Adjust to your BuddyPress field name
            'user_id' => $user_id
        ));

        if ($location) {
            // Geocode location (using OpenStreetMap Nominatim)
            $response = wp_remote_get('https://nominatim.openstreetmap.org/search?format=json&q=' . urlencode($location));
            if (!is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response));
                if (!empty($data)) {
                    $locations[] = array(
                        'id' => $user_id,
                        'name' => $user->display_name,
                        'lat' => $data[0]->lat,
                        'lng' => $data[0]->lon,
                        'profile_url' => bp_core_get_user_domain($user_id)
                    );
                }
            }
        }
    }

    wp_send_json($locations);
}
add_action('wp_ajax_bp_nearby_members_map', 'bp_nearby_members_map_ajax');
add_action('wp_ajax_nopriv_bp_nearby_members_map', 'bp_nearby_members_map_ajax');
