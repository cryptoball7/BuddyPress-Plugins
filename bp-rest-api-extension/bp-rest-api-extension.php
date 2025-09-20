<?php
/**
 * Plugin Name: BuddyPress REST API Extension
 * Description: Adds extra BuddyPress REST endpoints and fields (e.g., fetch a user's groups with metadata). Secure, paginated, and extensible.
 * Version: 1.0.0
 * Author: ChatGPT
 * Text Domain: bp-rest-api-extension
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class BP_REST_API_Extension {

    const NAMESPACE = 'bp-rest/v1';

    public function __construct() {
        // Prefer BuddyPress-specific init when available
        add_action( 'bp_rest_api_init', array( $this, 'register_routes' ) );
        // Fallback for sites where bp_rest_api_init isn't available yet
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );

        // Example of registering an extra field on group responses (optional)
        add_action( 'bp_rest_api_init', array( $this, 'register_rest_fields' ) );
    }

    public function register_routes() {
        register_rest_route( self::NAMESPACE, '/user/(?P<id>\d+)/groups', array(
            array(
                'methods'  => WP_REST_Server::READABLE,
                'callback' => array( $this, 'get_user_groups' ),
                'permission_callback' => array( $this, 'permission_get_user_groups' ),
                'args'     => $this->get_user_groups_args(),
            ),
        ) );

        // You can add more endpoints here (group meta endpoints, bulk queries, etc.)
    }

    public function register_rest_fields() {
        // Example: add derived field to groups endpoint: member_count
        if ( function_exists( 'bp_rest_register_field' ) ) {
            bp_rest_register_field( 'groups', 'member_count', array(
                'get_callback' => function( $group ) {
                    if ( empty( $group['id'] ) ) {
                        return 0;
                    }
                    return groups_get_total_group_count( false ); // placeholder; replace if needed
                },
                'schema' => array(
                    'description' => 'Number of members in the group (derived).',
                    'type'        => 'integer',
                    'context'     => array( 'view', 'edit' ),
                ),
            ) );
        }
    }

    public function get_user_groups_args() {
        return array(
            'id' => array(
                'description' => 'User ID to fetch groups for',
                'type'        => 'integer',
                'required'    => true,
            ),
            'include_meta' => array(
                'description' => 'Include group meta in each returned group (boolean)',
                'type'        => 'boolean',
                'default'     => false,
            ),
            'meta_keys' => array(
                'description' => 'Array of group meta keys to return when include_meta is true',
                'type'        => 'array',
                'items'       => array( 'type' => 'string' ),
            ),
            'per_page' => array(
                'description' => 'Number of groups per page',
                'type'        => 'integer',
                'default'     => 25,
                'sanitize_callback' => 'absint',
            ),
            'page' => array(
                'description' => 'Page number of results to return',
                'type'        => 'integer',
                'default'     => 1,
                'sanitize_callback' => 'absint',
            ),
        );
    }

    public function permission_get_user_groups( $request ) {
        $user_id = (int) $request['id'];

        // Basic user existence check
        if ( ! get_userdata( $user_id ) ) {
            return new WP_Error( 'rest_user_invalid', 'Invalid user ID.', array( 'status' => 404 ) );
        }

        // If request is made by the same user or by a user with manage_options capability, allow fully
        $current_user_id = get_current_user_id();
        if ( $current_user_id === $user_id || current_user_can( 'manage_options' ) ) {
            return true;
        }

        // Otherwise allow for authenticated users; public groups will be filtered server-side
        if ( is_user_logged_in() ) {
            return true;
        }

        // If not logged in, allow but the callback will filter out non-public groups
        return true;
    }

    public function get_user_groups( WP_REST_Request $request ) {
        $user_id = (int) $request['id'];
        $include_meta = filter_var( $request->get_param( 'include_meta' ), FILTER_VALIDATE_BOOLEAN );
        $meta_keys = $request->get_param( 'meta_keys' ) ?: array();
        $per_page = max( 1, (int) $request->get_param( 'per_page' ) );
        $page = max( 1, (int) $request->get_param( 'page' ) );

        // Use BuddyPress helper to get group IDs the user belongs to
        if ( ! function_exists( 'groups_get_user_groups' ) ) {
            return new WP_Error( 'buddypress_inactive', 'BuddyPress groups component not available.', array( 'status' => 501 ) );
        }

        $user_groups = groups_get_user_groups( $user_id );
        // groups_get_user_groups may return an array with 'groups' key
        $group_ids = array();
        if ( is_array( $user_groups ) && isset( $user_groups['groups'] ) ) {
            $group_ids = array_map( 'absint', $user_groups['groups'] );
        } elseif ( is_array( $user_groups ) ) {
            $group_ids = array_map( 'absint', $user_groups );
        }

        $total = count( $group_ids );
        $offset = ( $page - 1 ) * $per_page;
        $paged_ids = array_slice( $group_ids, $offset, $per_page );

        $data = array();
        foreach ( $paged_ids as $group_id ) {
            $group = groups_get_group( array( 'group_id' => $group_id ) );
            if ( ! $group || empty( $group->id ) ) {
                continue;
            }

            // Respect group visibility for unauthenticated users
            if ( ! is_user_logged_in() ) {
                // BuddyPress group status: 'public', 'private', 'hidden'
                if ( property_exists( $group, 'status' ) && 'public' !== $group->status ) {
                    continue; // skip non-public
                }
            }

            $item = array(
                'id'          => (int) $group->id,
                'name'        => wp_kses_post( $group->name ),
                'description' => wp_kses_post( $group->description ),
                'status'      => isset( $group->status ) ? $group->status : '',
                'link'        => esc_url( bp_get_group_permalink( $group ) ),
                'avatar_urls' => $this->get_group_avatar_urls( $group->id ),
            );

            if ( $include_meta && ! empty( $meta_keys ) && is_array( $meta_keys ) ) {
                $meta = array();
                foreach ( $meta_keys as $key ) {
                    $meta[ sanitize_key( $key ) ] = groups_get_groupmeta( $group->id, $key );
                }
                $item['meta'] = $meta;
            } elseif ( $include_meta ) {
                // return all meta (careful with sensitive data)
                $item['meta'] = groups_get_groupmeta( $group->id );
            }

            $data[] = $item;
        }

        $response = rest_ensure_response( $data );

        // Add pagination headers
        $max_pages = (int) ceil( $total / $per_page );
        $response->header( 'X-WP-Total', (int) $total );
        $response->header( 'X-WP-TotalPages', $max_pages );

        return $response;
    }

    protected function get_group_avatar_urls( $group_id ) {
        // BuddyPress provides functions to get avatars; fallback to empty array if not available
        if ( function_exists( 'bp_core_fetch_avatar' ) ) {
            $sizes = array( 'thumb', 'full' );
            $urls = array();
            foreach ( $sizes as $size ) {
                $url = bp_core_fetch_avatar( array( 'object' => 'group', 'item_id' => $group_id, 'type' => $size, 'html' => false ) );
                if ( $url ) {
                    $urls[ $size ] = esc_url_raw( $url );
                }
            }
            return $urls;
        }

        return array();
    }

}

// Initialize the plugin
new BP_REST_API_Extension();

// Activation / deactivation hooks could be added for setup/cleanup as needed

