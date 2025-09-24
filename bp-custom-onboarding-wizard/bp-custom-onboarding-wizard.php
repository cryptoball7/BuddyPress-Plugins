<?php
/*
Plugin Name: BuddyPress Custom Onboarding Wizard
Description: Replace default registration with a multi-step onboarding wizard (upload avatar, set interests, join groups). Works with BuddyPress. Single-file plugin.
Version: 1.0
Author: Cryptoball cryptoball7@gmail.com
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class BP_Custom_Onboarding_Wizard {

    private $page_slug = 'onboarding-wizard';

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
        add_shortcode( 'bp_onboarding_wizard', array( $this, 'shortcode_render' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_bp_onboard_create_user', array( $this, 'ajax_create_user' ) );
        add_action( 'wp_ajax_nopriv_bp_onboard_create_user', array( $this, 'ajax_create_user' ) );

        add_action( 'wp_ajax_bp_onboard_save_step', array( $this, 'ajax_save_step' ) );
        add_action( 'wp_ajax_bp_onboard_finalize', array( $this, 'ajax_finalize' ) );

        // redirect BuddyPress register to our onboarding page when possible
        add_action( 'template_redirect', array( $this, 'redirect_bp_register' ) );
    }

    public function activate_plugin() {
        // Create a page with the onboarding shortcode if not exists
        $exists = get_page_by_path( $this->page_slug );
        if ( ! $exists ) {
            wp_insert_post( array(
                'post_title'   => 'Onboarding Wizard',
                'post_name'    => $this->page_slug,
                'post_content' => '[bp_onboarding_wizard]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            ) );
        }
    }

    public function enqueue_assets() {
        if ( ! is_page() ) return;
        global $post;
        if ( ! $post ) return;
        if ( has_shortcode( $post->post_content, 'bp_onboarding_wizard' ) ) {
            wp_enqueue_script( 'bp-onboard-js', plugins_url( 'dummy.js', __FILE__ ), array('jquery'), '1.0', true );
            // We used a dummy script handle to allow inline script below.
            $local = array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'bp_onboard_nonce' ),
                'suggested_groups' => $this->get_suggested_groups(),
            );
            wp_localize_script( 'bp-onboard-js', 'BP_ONBOARD', $local );

            // Inline JS and CSS appended via wp_add_inline_script/style
            $inline_css = $this->get_inline_css();
            $inline_js  = $this->get_inline_js();
            wp_add_inline_style( 'wp-block-library', $inline_css );
            wp_add_inline_script( 'bp-onboard-js', $inline_js );
        }
    }

    private function get_suggested_groups() {
        if ( ! function_exists( 'groups_get_groups' ) ) return array();
        $groups = groups_get_groups( array( 'per_page' => 10, 'populate_extras' => false ) );
        $out = array();
        foreach ( $groups['groups'] as $g ) {
            $out[] = array( 'id' => $g->id, 'name' => $g->name );
        }
        return $out;
    }

    public function shortcode_render() {
        ob_start();
        if ( is_user_logged_in() ) {
            echo '<div class="bp-onboard-container"><h2>Welcome back — continue onboarding</h2></div>';
        }
        ?>
        <div id="bp-onboard" class="bp-onboard-container">
            <div class="bp-steps">
                <div class="bp-step" data-step="1">
                    <h2>Create account</h2>
                    <form id="bp-onboard-step1" class="bp-onboard-form">
                        <label>Username<input type="text" name="user_login" required></label>
                        <label>Email<input type="email" name="user_email" required></label>
                        <label>Password<input type="password" name="user_pass" required></label>
                        <div class="bp-form-msg" id="step1-msg"></div>
                        <button type="submit" class="button">Create account & continue</button>
                    </form>
                </div>

                <div class="bp-step hidden" data-step="2">
                    <h2>Upload avatar</h2>
                    <form id="bp-onboard-step2" class="bp-onboard-form">
                        <p>Choose an image file (jpg/png). Optional — you can skip and set it later.</p>
                        <input type="file" id="bp-avatar-file" accept="image/*">
                        <div id="bp-avatar-preview" style="margin-top:10px;"></div>
                        <div class="bp-form-msg" id="step2-msg"></div>
                        <button type="button" id="skip-avatar" class="button alt">Skip</button>
                        <button type="submit" class="button">Upload & continue</button>
                    </form>
                </div>

                <div class="bp-step hidden" data-step="3">
                    <h2>Set interests</h2>
                    <form id="bp-onboard-step3" class="bp-onboard-form">
                        <p>Pick topics you like (you can pick multiple)</p>
                        <div id="bp-interests-list">
                            <?php
                            // Default interest set (you can customize later)
                            $defaults = array( 'Technology','Sports','Music','Art','Travel','Food','Gaming','Science' );
                            foreach ( $defaults as $k => $label ) {
                                echo '<label class="bp-interest"><input type="checkbox" name="interests[]" value="'.esc_attr($label).'"> '.esc_html($label).'</label>';
                            }
                            ?>
                        </div>
                        <div class="bp-form-msg" id="step3-msg"></div>
                        <button type="submit" class="button">Save interests & continue</button>
                    </form>
                </div>

                <div class="bp-step hidden" data-step="4">
                    <h2>Join suggested groups</h2>
                    <form id="bp-onboard-step4" class="bp-onboard-form">
                        <p>Pick groups to join now.</p>
                        <div id="bp-groups-list"></div>
                        <div class="bp-form-msg" id="step4-msg"></div>
                        <button type="button" id="skip-groups" class="button alt">Skip</button>
                        <button type="submit" class="button">Join selected groups & finish</button>
                    </form>
                </div>

                <div class="bp-step hidden" data-step="5">
                    <h2>You're done!</h2>
                    <p>Account created and basic profile set. <a href="<?php echo esc_url( wp_login_url() ); ?>">Go to your profile</a> or continue to customize.</p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_inline_css() {
        return "
        #bp-onboard { max-width:760px; margin:24px auto; padding:18px; border:1px solid #e6e6e6; border-radius:6px; background:#fff; font-family:inherit; }
        .bp-step { display:block; }
        .bp-step.hidden { display:none; }
        .bp-onboard-form label { display:block; margin:10px 0; }
        .bp-onboard-form input[type='text'], .bp-onboard-form input[type='email'], .bp-onboard-form input[type='password'] { width:100%; padding:8px; border:1px solid #ddd; border-radius:4px; }
        .bp-interest { display:inline-block; margin:6px 8px; }
        .bp-form-msg { margin:8px 0; color:#d00; min-height:18px; }
        #bp-avatar-preview img { max-width:140px; border-radius:6px; display:block; margin-top:6px; }
        ";
    }

    private function get_inline_js() {
        // JS uses BP_ONBOARD (localized)
        return "
        (function($){
            var currentStep = 1;
            var avatarBase64 = '';
            var userId = 0;

            function showStep(n){
                $('.bp-step').addClass('hidden');
                $('.bp-step[data-step=\"'+n+'\"]').removeClass('hidden');
                currentStep = n;
            }

            // populate suggested groups
            function renderSuggestedGroups() {
                var list = BP_ONBOARD.suggested_groups || [];
                var container = $('#bp-groups-list');
                if (!list.length) {
                    container.html('<p>No groups found. You can join them later.</p>');
                    return;
                }
                var html = '';
                list.forEach(function(g){
                    html += '<label class=\"bp-group\"><input type=\"checkbox\" name=\"groups[]\" value=\"'+g.id+'\"> '+g.name+'</label><br>';
                });
                container.html(html);
            }
            renderSuggestedGroups();

            // Step 1: create user
            $('#bp-onboard-step1').on('submit', function(e){
                e.preventDefault();
                var form = $(this);
                var data = form.serializeArray();
                data.push({name:'action', value:'bp_onboard_create_user'});
                data.push({name:'nonce', value:BP_ONBOARD.nonce});
                $('#step1-msg').text('');
                $.post(BP_ONBOARD.ajax_url, data, function(resp){
                    if(!resp || !resp.success){
                        $('#step1-msg').text( resp && resp.data ? resp.data : 'Unknown error' );
                        return;
                    }
                    userId = resp.data.user_id;
                    showStep(2);
                }, 'json').fail(function(xhr){
                    $('#step1-msg').text('Request failed');
                });
            });

            // Avatar preview
            $('#bp-avatar-file').on('change', function(e){
                var f = this.files && this.files[0];
                if(!f) return;
                var reader = new FileReader();
                reader.onload = function(ev){
                    avatarBase64 = ev.target.result; // data:*/*;base64,...
                    $('#bp-avatar-preview').html('<img src=\"'+avatarBase64+'\">');
                };
                reader.readAsDataURL(f);
            });

            $('#skip-avatar').on('click', function(){
                // no avatar - just move on
                saveStep(2, {} , function(){ showStep(3); });
            });

            // Step 2 submit
            $('#bp-onboard-step2').on('submit', function(e){
                e.preventDefault();
                if(!avatarBase64){
                    $('#step2-msg').text('Please choose a file or click Skip.');
                    return;
                }
                $('#step2-msg').text('');
                saveStep(2, { avatar: avatarBase64 }, function(){ showStep(3); });
            });

            // Step 3 submit - interests
            $('#bp-onboard-step3').on('submit', function(e){
                e.preventDefault();
                var interests = [];
                $('#bp-interests-list input:checked').each(function(){ interests.push($(this).val()); });
                saveStep(3, { interests: interests }, function(){ showStep(4); });
            });

            $('#skip-groups').on('click', function(){
                saveStep(4, { groups: [] }, function(){ showStep(5); });
            });

            // Step 4 submit - groups
            $('#bp-onboard-step4').on('submit', function(e){
                e.preventDefault();
                var groups = [];
                $('#bp-groups-list input:checked').each(function(){ groups.push($(this).val()); });
                saveStep(4, { groups: groups }, function(){ showStep(5); });
            });

            // generic save step
            function saveStep(step, payload, onSuccess){
                payload.action = 'bp_onboard_save_step';
                payload.nonce  = BP_ONBOARD.nonce;
                payload.step   = step;
                $.post(BP_ONBOARD.ajax_url, payload, function(resp){
                    if(!resp || !resp.success){
                        $('#step'+step+'-msg').text( resp && resp.data ? resp.data : 'Unknown error' );
                        return;
                    }
                    if (onSuccess) onSuccess();
                }, 'json').fail(function(){ $('#step'+step+'-msg').text('Request failed'); });
            }

            // finalize -- triggers final save if needed
            $(document).on('click', '#bp-onboard-finalize', function(){
                $.post(BP_ONBOARD.ajax_url, { action:'bp_onboard_finalize', nonce:BP_ONBOARD.nonce }, function(){ showStep(5); }, 'json');
            });

            // If user is already logged in, skip step1
            $(document).ready(function(){
                // if the server indicated the user is logged in via wp object, could auto-advance.
                // We'll trust server-side: if user is logged in when hitting page, show step2.
                // Use ajax to check current status
                $.post(BP_ONBOARD.ajax_url, { action:'bp_onboard_check_loggedin', nonce:BP_ONBOARD.nonce }, function(resp){
                    if (resp && resp.success && resp.data.loggedin) {
                        userId = resp.data.user_id;
                        showStep(2);
                    } else {
                        showStep(1);
                    }
                }, 'json');
            });

        })(jQuery);
        ";
    }

    // AJAX: create user
    public function ajax_create_user() {
        check_ajax_referer( 'bp_onboard_nonce', 'nonce' );
        $login = sanitize_user( $_POST['user_login'] ?? '' );
        $email = sanitize_email( $_POST['user_email'] ?? '' );
        $pass  = $_POST['user_pass'] ?? '';

        if ( empty($login) || empty($email) || empty($pass) ) {
            wp_send_json_error( 'Missing fields' );
        }
        if ( username_exists( $login ) ) {
            wp_send_json_error( 'Username already exists' );
        }
        if ( email_exists( $email ) ) {
            wp_send_json_error( 'Email already registered' );
        }

        $user_id = wp_create_user( $login, $pass, $email );
        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( $user_id->get_error_message() );
        }

        // If BuddyPress: create BP xprofile if needed - leave to admin customization.

        // log the user in
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id );

        wp_send_json_success( array( 'user_id' => $user_id ) );
    }

    // AJAX: save step data
    public function ajax_save_step() {
        check_ajax_referer( 'bp_onboard_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'User not logged in' );
        }
        $user_id = get_current_user_id();
        $step = intval( $_POST['step'] ?? 0 );

        if ( $step === 2 ) { // avatar
            if ( empty( $_POST['avatar'] ) ) {
                wp_send_json_error( 'No avatar data' );
            }
            $img_data = $_POST['avatar']; // data:*/*;base64,...
            $attach_id = $this->save_base64_image_as_attachment( $img_data, $user_id );
            if ( is_wp_error( $attach_id ) ) {
                wp_send_json_error( $attach_id->get_error_message() );
            }
            update_user_meta( $user_id, 'onboard_avatar_attachment', $attach_id );

            // If BuddyPress function exists, attempt to move attachment file into BP avatar location
            if ( function_exists( 'bp_core_avatar_handle_upload' ) ) {
                // bp_core_avatar_handle_upload expects $_FILES; easier to call bp_core_avatar_handle_upload with temp file path
                // We'll attempt to reconstruct a faux file array
                $file = get_attached_file( $attach_id );
                if ( $file && file_exists( $file ) ) {
                    // create a temp FILES entry
                    $_FILES['file'] = array(
                        'name'     => basename( $file ),
                        'type'     => wp_check_filetype( $file )['type'] ?? 'image/jpeg',
                        'tmp_name' => $file,
                        'error'    => 0,
                        'size'     => filesize( $file ),
                    );
                    // call buddyPress upload handler
                    bp_core_avatar_handle_upload( array( 'item_id' => $user_id, 'object' => 'user' ) );
                }
            }

            wp_send_json_success( 'avatar saved' );
        }

        if ( $step === 3 ) { // interests
            $interests = isset( $_POST['interests'] ) ? array_map( 'sanitize_text_field', (array) $_POST['interests'] ) : array();
            update_user_meta( $user_id, 'onboard_interests', $interests );
            wp_send_json_success( 'interests saved' );
        }

        if ( $step === 4 ) { // groups
            $groups = isset( $_POST['groups'] ) ? array_map( 'absint', (array) $_POST['groups'] ) : array();
            if ( function_exists( 'groups_join_group' ) ) {
                foreach ( $groups as $g ) {
                    if ( ! groups_is_user_member( $user_id, $g ) ) {
                        groups_join_group( $g, $user_id );
                    }
                }
            }
            update_user_meta( $user_id, 'onboard_groups_joined', $groups );
            wp_send_json_success( 'groups processed' );
        }

        wp_send_json_error( 'Unknown step' );
    }

    // finalize endpoint (currently no op)
    public function ajax_finalize() {
        check_ajax_referer( 'bp_onboard_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( 'Not logged in' );
        wp_send_json_success( 'ok' );
    }

    // Helper: save base64 image to uploads and return attachment id
    private function save_base64_image_as_attachment( $datauri, $user_id = 0 ) {
        // Format: data:[<mediatype>][;base64],<data>
        if ( ! preg_match( '/^data:(.*?);base64,(.*)$/', $datauri, $matches ) ) {
            return new WP_Error( 'invalid_image', 'Invalid image data' );
        }
        $mime = $matches[1];
        $data = base64_decode( $matches[2] );
        if ( $data === false ) {
            return new WP_Error( 'decode_error', 'Could not decode image' );
        }

        $ext = '';
        if ( $mime === 'image/jpeg' ) $ext = 'jpg';
        elseif ( $mime === 'image/png' ) $ext = 'png';
        elseif ( $mime === 'image/gif' ) $ext = 'gif';
        else {
            // try to extract ext
            $parts = explode('/', $mime );
            $ext = end($parts);
        }
        $upload_dir = wp_upload_dir();
        if ( ! isset( $upload_dir['path'] ) ) return new WP_Error( 'upload_error', 'Upload path not available' );

        $filename = 'bp-onboard-avatar-' . $user_id . '-' . time() . '.' . $ext;
        $file_path = trailingslashit( $upload_dir['path'] ) . $filename;
        $written = file_put_contents( $file_path, $data );
        if ( $written === false ) return new WP_Error( 'write_error', 'Could not write file' );

        $filetype = wp_check_filetype( $filename, null );
        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name( $filename ),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );
        $attach_id = wp_insert_attachment( $attachment, $file_path );
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
        wp_update_attachment_metadata( $attach_id, $attach_data );

        return $attach_id;
    }

    // Redirect BuddyPress register url to our onboarding page
    public function redirect_bp_register() {
        if ( is_user_logged_in() ) return;

        // If BuddyPress register page is requested: detect by query var or by path 'register' for BP
        // We'll do a best-effort: if requested URI contains '/register' and our page exists, redirect
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if ( stripos( $request_uri, '/register' ) !== false || stripos( $request_uri, 'action=register' ) !== false ) {
            // Find our page URL
            $page = get_page_by_path( $this->page_slug );
            if ( $page ) {
                $url = get_permalink( $page->ID );
                wp_redirect( $url );
                exit;
            }
        }
    }
}

new BP_Custom_Onboarding_Wizard();

/**
 * Optional: lightweight "check logged in" AJAX action used by JS
 */
add_action( 'wp_ajax_bp_onboard_check_loggedin', function(){
    check_ajax_referer( 'bp_onboard_nonce', 'nonce' );
    if ( is_user_logged_in() ) {
        wp_send_json_success( array( 'loggedin' => true, 'user_id' => get_current_user_id() ) );
    } else {
        wp_send_json_success( array( 'loggedin' => false ) );
    }
} );
add_action( 'wp_ajax_nopriv_bp_onboard_check_loggedin', function(){
    check_ajax_referer( 'bp_onboard_nonce', 'nonce' );
    wp_send_json_success( array( 'loggedin' => false ) );
} );
