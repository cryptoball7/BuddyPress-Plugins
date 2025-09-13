<?php
/**
 * Plugin Name: BuddyPress Custom Email Notifications
 * Description: Adds customizable BuddyPress emails for group-join, friend-request and profile-update events and sends personalized emails using the BuddyPress Email API.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: bp-custom-email-notifications
 *
 * Notes:
 * - Requires BuddyPress 2.5+ (Email API).
 * - This plugin registers 3 custom BuddyPress emails on activation (and on "Re-install emails").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BP_Custom_Email_Notifications {

	const EMAIL_TYPE_GROUP_JOINED = 'bp_group_joined';
	const EMAIL_TYPE_FRIEND_REQUEST = 'bp_friend_request';
	const EMAIL_TYPE_PROFILE_UPDATED = 'bp_profile_updated';

	public function __construct() {
		// Register install hook to create email posts
		add_action( 'bp_core_install_emails', array( $this, 'register_custom_emails' ) );

		// Send emails on actions
		add_action( 'groups_join_group', array( $this, 'on_groups_join_group' ), 10, 2 );
		add_action( 'friends_friendship_requested', array( $this, 'on_friends_friendship_requested' ), 10, 3 );

		/*
		 * xprofile_updated_profile:
		 * callback signature: function( $user_id, $posted_field_ids, $errors, $old_values, $new_values )
		 * Note: use 5 args for rich token support.
		 */
		add_action( 'xprofile_updated_profile', array( $this, 'on_xprofile_updated_profile' ), 10, 5 );

		// Allow customizing recipients/tokens via filters
		add_filter( 'bp_cen_get_tokens', array( $this, 'filter_tokens' ), 10, 2 );
	}

	/**
	 * Register the custom emails in BuddyPress email system.
	 * Runs during bp_core_install_emails so admin can re-install via Tools > Re-install Emails.
	 */
	public function register_custom_emails() {
		// Only create the emails if they don't already exist.
		// The 'bp-email' post_type is used by BuddyPress Email API.
		$this->maybe_create_email( self::EMAIL_TYPE_GROUP_JOINED,
			__( 'Group joined notification (custom)', 'bp-custom-email-notifications' ),
			__( "Subject: Welcome to {group.name}\n\nHello {user.displayname},\n\nThanks for joining {group.name}.\n\n{group.link}\n", 'bp-custom-email-notifications' ),
			array( 'group.joined' )
		);

		$this->maybe_create_email( self::EMAIL_TYPE_FRIEND_REQUEST,
			__( 'Friend request notification (custom)', 'bp-custom-email-notifications' ),
			__( "Subject: You have a friend request from {user.displayname}\n\nHi {recipient.displayname},\n\n{user.displayname} has sent you a friend request.\n\nVisit: {recipient.link}\n", 'bp-custom-email-notifications' ),
			array( 'friends.request' )
		);

		$this->maybe_create_email( self::EMAIL_TYPE_PROFILE_UPDATED,
			__( 'Profile updated notification (custom)', 'bp-custom-email-notifications' ),
			__( "Subject: Your profile was updated\n\nHi {user.displayname},\n\nYou updated your profile. Changed fields:\n{profile.changed}\n\nIf this wasn't you, contact the site admin.", 'bp-custom-email-notifications' ),
			array( 'profile.updated' )
		);
	}

	/**
	 * Helper to create bp_email posts if they don't exist.
	 *
	 * @param string $slug   Email type slug (we use it as post_name).
	 * @param string $title
	 * @param string $message
	 * @param array  $tax_terms Terms to assign in bp-email-type taxonomy.
	 */
	protected function maybe_create_email( $slug, $title, $message, $tax_terms = array() ) {
		if ( post_exists( $title, '', '', 'bp-email' ) ) {
			// don't duplicate based on title
			return;
		}

		$postarr = array(
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $message,
			'post_status'  => 'publish',
			'post_type'    => 'bp-email',
		);

		$post_id = wp_insert_post( $postarr );

		if ( $post_id && ! is_wp_error( $post_id ) ) {
			// Assign terms in the bp-email-type taxonomy so bp_send_email() can use this email type
			if ( ! empty( $tax_terms ) ) {
				wp_set_object_terms( $post_id, $tax_terms, 'bp-email-type', false );
			}
		}
	}

	/**
	 * Fired when a user joins a group.
	 *
	 * @param int $group_id
	 * @param int $user_id
	 */
	public function on_groups_join_group( $group_id, $user_id ) {
		// Build tokens
		$group = groups_get_group( array( 'group_id' => $group_id ) );
		$user  = get_userdata( $user_id );

		$tokens = array(
			'user.displayname' => bp_core_get_user_displayname( $user_id ),
			'user.user_login'  => $user->user_login,
			'user.email'       => isset( $user->user_email ) ? $user->user_email : '',
			'group.name'       => $group->name,
			'group.link'       => bp_get_group_permalink( $group ),
		);

		/**
		 * Allow altering tokens or recipients for group-joined email.
		 *
		 * - Return array with keys: 'to' and 'tokens'
		 */
		$data = apply_filters( 'bp_cen_group_joined_email_args', array(
			// default to the new member
			'to'     => $user_id,
			'tokens' => $tokens,
		), $group_id, $user_id );

		$bpargs = array(
			'tokens' => $data['tokens'],
		);

		// Send email using BuddyPress API. Use our registered email type slug.
		bp_send_email( self::EMAIL_TYPE_GROUP_JOINED, $data['to'], $bpargs );
	}

	/**
	 * Fired when a friend request is created.
	 *
	 * do_action( 'friends_friendship_requested', $friendship_id, $initiator_id, $friend_id );
	 *
	 * @param int $friendship_id
	 * @param int $initiator_id
	 * @param int $friend_id
	 */
	public function on_friends_friendship_requested( $friendship_id, $initiator_id, $friend_id ) {
		$initiator = get_userdata( $initiator_id );
		$recipient = get_userdata( $friend_id );

		$tokens = array(
			'user.displayname'       => bp_core_get_user_displayname( $initiator_id ),
			'user.user_login'        => $initiator->user_login,
			'user.email'             => isset( $initiator->user_email ) ? $initiator->user_email : '',
			'recipient.displayname'  => bp_core_get_user_displayname( $friend_id ),
			'recipient.email'        => isset( $recipient->user_email ) ? $recipient->user_email : '',
			'recipient.link'         => bp_core_get_user_domain( $friend_id ),
			'request.id'             => $friendship_id,
		);

		$data = apply_filters( 'bp_cen_friend_request_email_args', array(
			'to'     => $friend_id,
			'tokens' => $tokens,
		), $friendship_id, $initiator_id, $friend_id );

		$bpargs = array(
			'tokens' => $data['tokens'],
		);

		bp_send_email( self::EMAIL_TYPE_FRIEND_REQUEST, $data['to'], $bpargs );
	}

	/**
	 * Fired when user's xProfile is updated.
	 *
	 * @param int   $user_id
	 * @param array $posted_field_ids
	 * @param array $errors
	 * @param array $old_values
	 * @param array $new_values
	 */
	public function on_xprofile_updated_profile( $user_id, $posted_field_ids = array(), $errors = array(), $old_values = array(), $new_values = array() ) {
		// Build a readable list of changed fields
		$changed = array();
		if ( is_array( $posted_field_ids ) ) {
			foreach ( $posted_field_ids as $field_id ) {
				// field name:
				$field = xprofile_get_field( $field_id );
				if ( $field ) {
					$label = $field->name;
					$old   = isset( $old_values[ $field_id ] ) ? maybe_serialize( $old_values[ $field_id ] ) : '';
					$new   = isset( $new_values[ $field_id ] ) ? maybe_serialize( $new_values[ $field_id ] ) : '';
					$changed[] = sprintf( '%s: %s -> %s', $label, wp_strip_all_tags( $old ), wp_strip_all_tags( $new ) );
				}
			}
		}

		$tokens = array(
			'user.displayname'  => bp_core_get_user_displayname( $user_id ),
			'user.email'        => bp_core_get_user_email( $user_id ),
			'profile.changed'   => implode( "\n", $changed ),
		);

		$data = apply_filters( 'bp_cen_profile_updated_email_args', array(
			'to'     => $user_id,
			'tokens' => $tokens,
		), $user_id, $posted_field_ids, $old_values, $new_values );

		$bpargs = array(
			'tokens' => $data['tokens'],
		);

		bp_send_email( self::EMAIL_TYPE_PROFILE_UPDATED, $data['to'], $bpargs );
	}

	/**
	 * Example filter to allow tokens to be extended site-wide.
	 *
	 * @param array  $tokens
	 * @param string $context Optional context (slug or action).
	 * @return array
	 */
	public function filter_tokens( $tokens, $context = '' ) {
		// Example: add site name token
		$tokens['site.name'] = get_bloginfo( 'name' );
		return $tokens;
	}
}

new BP_Custom_Email_Notifications();
