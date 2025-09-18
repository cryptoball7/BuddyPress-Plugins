<?php
/**
 * Plugin Name: BuddyPress Private Messages Attachments
 * Description: Adds secure file/image attachments to BuddyPress private messages (compose upload + display).
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BP_PM_Attachments {
	const NONCE = 'bp_pm_attach_nonce';
	const AJAX_ACTION = 'bp_pm_upload_attachment';
	const OPTION_MAX_SIZE = 5242880; // 5MB default

	public function __construct() {
		add_action( 'init', array( $this, 'maybe_load' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Inject attachment UI into compose form
		add_action( 'bp_after_messages_compose_content', array( $this, 'render_attach_ui' ) );

		// AJAX upload handler
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_handle_upload' ) );

		// When message is sent, save attachments meta
		add_action( 'messages_message_sent', array( $this, 'on_message_sent' ), 10, 1 );

		// Append attachments to displayed message content
		add_filter( 'bp_get_the_thread_message_content', array( $this, 'append_attachments_to_content' ), 10, 1 );
	}

	public function maybe_load() {
		// Make sure BuddyPress Messages component is active
		if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'messages' ) ) {
			return;
		}
	}

	public function enqueue_scripts() {
		if ( ! bp_is_active( 'messages' ) ) return;

		wp_enqueue_script( 'bp-pm-attachments', plugin_dir_url( __FILE__ ) . 'js/bp-pm-attachments.js', array( 'jquery' ), '1.0', true );
		wp_localize_script( 'bp-pm-attachments', 'BP_PM_ATTACH', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE ),
			'max_size' => apply_filters( 'bp_pm_attachments_max_size', self::OPTION_MAX_SIZE ),
			'max_files' => apply_filters( 'bp_pm_attachments_max_files', 5 ),
			'allowed_types' => array_values( $this->allowed_mime_types() ),
		) );
	}

	public function render_attach_ui() {
		echo '<div id="bp-pm-attachments-wrap" class="bp-pm-attachments">';
		echo '<label for="bp-pm-attach-file">' . esc_html__( 'Attach file', 'bp-pm-attachments' ) . '</label> ';
		echo '<input type="file" id="bp-pm-attach-file" />';
		echo '<button type="button" id="bp-pm-attach-upload" class="button">' . esc_html__( 'Upload', 'bp-pm-attachments' ) . '</button>';
		echo '<div id="bp-pm-attach-list"></div>';
		// Hidden input that will hold comma-separated attachment IDs so they are submitted with the message form
		echo '<input type="hidden" name="bp_pm_attachments" id="bp_pm_attachments" value="" />';
		echo '</div>';
	}

	/** AJAX upload handler */
	public function ajax_handle_upload() {
		// must be logged in
		if ( ! is_user_logged_in() ) wp_send_json_error( 'not_logged_in', 401 );

		check_ajax_referer( self::NONCE, 'nonce' );

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( 'no_file' );
		}

		$file = $_FILES['file'];

		// Basic validations
		$max_size = apply_filters( 'bp_pm_attachments_max_size', self::OPTION_MAX_SIZE );
		if ( $file['size'] > $max_size ) {
			wp_send_json_error( 'file_too_large' );
		}

		$allowed = $this->allowed_mime_types();
		$finfo = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( empty( $finfo['type'] ) || ! in_array( $finfo['type'], $allowed, true ) ) {
			wp_send_json_error( 'bad_type' );
		}

		// Use WP to handle upload safely
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$overrides = array( 'test_form' => false );

		$movefile = wp_handle_upload( $file, $overrides );
		if ( isset( $movefile['error'] ) ) {
			wp_send_json_error( array( 'error' => $movefile['error'] ) );
		}

		// Insert into media library
		$attachment = array(
			'post_mime_type' => $movefile['type'],
			'post_title'     => sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit'
		);

		$attach_id = wp_insert_attachment( $attachment, $movefile['file'] );
		if ( ! is_wp_error( $attach_id ) ) {
			$meta = wp_generate_attachment_metadata( $attach_id, $movefile['file'] );
			wp_update_attachment_metadata( $attach_id, $meta );

			// Set author to current user for accountability
			update_post_meta( $attach_id, '_bp_pm_uploaded_by', get_current_user_id() );

			wp_send_json_success( array( 'id' => $attach_id, 'url' => wp_get_attachment_url( $attach_id ), 'type' => $movefile['type'] ) );
		}

		wp_send_json_error( 'insert_failed' );
	}

	/** When a message is sent - save attachments to message meta */
	public function on_message_sent( $message ) {
		// $message may be object or array; try to extract id
		$message_id = 0;
		if ( is_array( $message ) && isset( $message['message_id'] ) ) {
			$message_id = (int) $message['message_id'];
		} elseif ( is_object( $message ) && isset( $message->id ) ) {
			$message_id = (int) $message->id;
		} elseif ( is_object( $message ) && isset( $message->message_id ) ) {
			$message_id = (int) $message->message_id;
		}

		if ( ! $message_id ) return;

		if ( ! empty( $_POST['bp_pm_attachments'] ) ) {
			$ids = array_filter( array_map( 'absint', explode( ',', wp_unslash( $_POST['bp_pm_attachments'] ) ) ) );
			foreach ( $ids as $aid ) {
				// sanity: ensure attachment exists and was uploaded by current user
				$uploader = get_post_meta( $aid, '_bp_pm_uploaded_by', true );
				if ( $uploader && intval( $uploader ) === get_current_user_id() && get_post( $aid ) ) {
					bp_messages_add_meta( $message_id, 'bp_pm_attachments', $aid );
				}
			}
		}
	}

	/** Append attachments to message content when displayed in thread */
	public function append_attachments_to_content( $content ) {
		// Try to get current message id from template helper
		$message_id = bp_get_the_thread_message_id();
		if ( ! $message_id ) return $content;

		$attachments = bp_messages_get_meta( $message_id, 'bp_pm_attachments', false );
		if ( empty( $attachments ) ) return $content;

		$output = "\n<div class=\"bp-pm-attachments-list\"><strong>" . esc_html__( 'Attachments:', 'bp-pm-attachments' ) . "</strong><ul>";
		foreach ( (array) $attachments as $aid ) {
			$att = get_post( $aid );
			if ( ! $att ) continue;
			$url = wp_get_attachment_url( $aid );
			$filename = esc_html( get_the_title( $aid ) );
			$mime = get_post_mime_type( $aid );
			if ( strpos( $mime, 'image/' ) === 0 ) {
				$output .= '<li class="bp-pm-attachment"><a href="' . esc_url( $url ) . '" target="_blank"><img alt="' . $filename . '" style="max-width:150px;height:auto;display:block;" src="' . esc_url( $url ) . '" /></a></li>';
			} else {
				$output .= '<li class="bp-pm-attachment"><a href="' . esc_url( $url ) . '" target="_blank">' . $filename . '</a></li>';
			}
		}
		$output .= '</ul></div>';

		return $content . $output;
	}

	/** Allowed mime types - filterable */
	protected function allowed_mime_types() {
		$types = array(
			'image/jpeg', 'image/png', 'image/gif',
			'application/pdf',
			'text/plain',
			'application/zip',
			'text/csv',
		);
		return apply_filters( 'bp_pm_attachments_allowed_mime_types', $types );
	}
}

new BP_PM_Attachments();


/* JS file (js/bp-pm-attachments.js) - inlined here for convenience; in production place in separate file */
add_action( 'wp_footer', function() {
	if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'messages' ) ) return;
	?>
	<script>
	( function( $ ) {
		$( document ).ready( function() {
			var attachIds = [];
			$('#bp-pm-attach-upload').on('click', function( e ) {
				e.preventDefault();
				var file = $('#bp-pm-attach-file')[0].files[0];
				if ( ! file ) { alert('Please choose a file'); return; }
				if ( typeof BP_PM_ATTACH === 'undefined' ) { alert('Attachment JS not configured'); return; }

				if ( file.size > BP_PM_ATTACH.max_size ) { alert('File is too large'); return; }

				var fd = new FormData();
				fd.append('action','<?php echo esc_js( BP_PM_Attachments::AJAX_ACTION ); ?>');
				fd.append('file', file);
				fd.append('nonce', BP_PM_ATTACH.nonce);

				$.ajax({
					url: BP_PM_ATTACH.ajax_url,
					type: 'POST',
					data: fd,
					processData: false,
					contentType: false,
					success: function( r ) {
						if ( r.success ) {
							attachIds.push( r.data.id );
							$('#bp-pm-attach-list').append('<div data-id="'+r.data.id+'"><a href="'+r.data.url+'" target="_blank">'+r.data.url+'</a> <button type="button" class="bp-pm-attach-remove">Remove</button></div>');
							$('#bp_pm_attachments').val( attachIds.join(',') );
						} else {
							alert('Upload failed: ' + JSON.stringify(r.data));
						}
					},
					error: function() { alert('AJAX error'); }
				});
			});

			// remove
			$(document).on('click', '.bp-pm-attach-remove', function(e){
				e.preventDefault();
				var id = $(this).parent().attr('data-id');
				attachIds = attachIds.filter(function(x){ return x != id; });
				$('#bp_pm_attachments').val( attachIds.join(',') );
				$(this).parent().remove();
			});
		});
	})(jQuery);
	</script>
	<?php
} );
