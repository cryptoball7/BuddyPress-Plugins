<?php
/**
 * Plugin Name: BuddyPress Profile Completeness Meter
 * Description: Adds a profile completeness progress bar for BuddyPress/WordPress users. Configurable fields (WP usermeta or xprofile fields). Shortcode: [bp_profile_meter]
 * Version: 1.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: bp-profile-meter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BP_Profile_Completeness_Meter {

    private static $instance = null;
    private $option_key = 'bp_pcm_options';

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'on_activate' ) );
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Shortcode
        add_shortcode( 'bp_profile_meter', array( $this, 'shortcode_meter' ) );

        // BuddyPress profile hook if available - try to display in profile header area
        add_action( 'bp_profile_header_meta', array( $this, 'display_meter_hook' ) );

        // Admin menu
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // AJAX endpoint to recalculate (used by front-end JS on edit screens)
        add_action( 'wp_ajax_bp_profile_meter_recalc', array( $this, 'ajax_recalc' ) );
    }

    public function on_activate() {
        $defaults = array(
            // Each line is a field descriptor. Format: meta:meta_key OR xprofile:Field Name
            // By default we'll include some common keys. Admin can edit later in settings.
            'fields' => implode("\n", array(
                'meta:first_name',
                'meta:last_name',
                'meta:description',
                'xprofile:About Me',
                'xprofile:Location'
            )),
            'show_percentage_label' => 1,
            'display_in_profile' => 1,
            'bar_height' => 8,
            'bar_color' => '#2b90d9'
        );
        add_option( $this->option_key, $defaults );
    }

    public function init() {
        load_plugin_textdomain( 'bp-profile-meter', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public function enqueue_assets() {
        wp_register_style( 'bp-pcm-style', plugins_url( 'css/bp-pcm.css', __FILE__ ) );
        wp_enqueue_style( 'bp-pcm-style' );

        wp_register_script( 'bp-pcm-js', plugins_url( 'js/bp-pcm.js', __FILE__ ), array( 'jquery' ), false, true );
        wp_localize_script( 'bp-pcm-js', 'BP_PCM', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'bp_pcm_nonce' ),
        ) );
        wp_enqueue_script( 'bp-pcm-js' );
    }

    public function get_options() {
        $opts = get_option( $this->option_key );
        if ( ! is_array( $opts ) ) {
            $this->on_activate();
            $opts = get_option( $this->option_key );
        }
        return $opts;
    }

    /**
     * Calculate completeness for a given user ID.
     * The fields option contains descriptors like "meta:first_name" or "xprofile:Location"
     * Returns an array with keys: percent (int), total, filled, details(array)
     */
    public function calculate_user_completion( $user_id ) {
        $opts = $this->get_options();
        $raw = isset( $opts['fields'] ) ? $opts['fields'] : '';
        $lines = preg_split( '/\r?\n/', trim( $raw ) );
        $lines = array_filter( array_map( 'trim', $lines ) );

        $total = count( $lines );
        $filled = 0;
        $details = array();

        foreach ( $lines as $line ) {
            // default: treat as meta if no prefix
            if ( strpos( $line, ':' ) !== false ) {
                list( $type, $name ) = array_map( 'trim', explode( ':', $line, 2 ) );
            } else {
                $type = 'meta';
                $name = $line;
            }

            $value = '';
            if ( $type === 'meta' ) {
                $value = get_user_meta( $user_id, $name, true );
            } elseif ( $type === 'xprofile' ) {
                if ( function_exists( 'xprofile_get_field_data' ) ) {
                    // xprofile_get_field_data accepts either field name or id
                    $value = xprofile_get_field_data( $name, $user_id );
                } else {
                    $value = '';
                }
            } else {
                // unknown type - skip
                $value = '';
            }

            $is_filled = $this->is_value_filled( $value );
            if ( $is_filled ) {
                $filled++;
            }
            $details[] = array(
                'descriptor' => $type . ':' . $name,
                'value'      => $value,
                'filled'     => (bool) $is_filled,
            );
        }

        $percent = $total > 0 ? intval( round( ( $filled / $total ) * 100 ) ) : 0;

        return array(
            'percent' => $percent,
            'total'   => $total,
            'filled'  => $filled,
            'details' => $details,
        );
    }

    private function is_value_filled( $value ) {
        if ( is_array( $value ) ) {
            // consider non-empty array items
            foreach ( $value as $v ) {
                if ( strlen( trim( (string) $v ) ) > 0 ) {
                    return true;
                }
            }
            return false;
        }
        return strlen( trim( (string) $value ) ) > 0;
    }

    public function render_meter( $user_id = 0, $args = array() ) {
        if ( $user_id == 0 ) {
            $user_id = get_current_user_id();
        }
        if ( ! $user_id ) {
            return ''; // nothing to show for guests
        }

        $opts = $this->get_options();
        $calc = $this->calculate_user_completion( $user_id );

        $percent = $calc['percent'];
        $show_label = isset( $opts['show_percentage_label'] ) ? (bool) $opts['show_percentage_label'] : true;
        $bar_height = isset( $opts['bar_height'] ) ? intval( $opts['bar_height'] ) : 8;
        $bar_color = isset( $opts['bar_color'] ) ? esc_attr( $opts['bar_color'] ) : '#2b90d9';

        ob_start();
        ?>
        <div class="bp-pcm-wrapper" data-user-id="<?php echo esc_attr( $user_id ); ?>">
            <div class="bp-pcm-bar" style="height:<?php echo $bar_height; ?>px; background:#eee; border-radius:4px; overflow:hidden;">
                <div class="bp-pcm-fill" style="width:<?php echo esc_attr( $percent ); ?>%; height:100%; background:<?php echo $bar_color; ?>; transition:width .6s ease;"></div>
            </div>
            <?php if ( $show_label ) : ?>
                <div class="bp-pcm-label"><?php echo sprintf( esc_html__( '%d%% complete', 'bp-profile-meter' ), $percent ); ?></div>
            <?php endif; ?>
            <div class="bp-pcm-actions">
                <?php if ( function_exists( 'bp_is_user_profile_edit' ) && bp_is_user_profile_edit() ) : ?>
                    <em><?php esc_html_e( 'Editing — changes update in real time.', 'bp-profile-meter' ); ?></em>
                <?php else : ?>
                    <a href="<?php echo esc_url( bp_loggedin_user_domain() . 'profile/edit' ); ?>"><?php esc_html_e( 'Edit profile', 'bp-profile-meter' ); ?></a>
                <?php endif; ?>
            </div>
            <div class="bp-pcm-breakdown">
                <strong><?php esc_html_e( 'Missing fields', 'bp-profile-meter' ); ?>:</strong>
                <ul>
                    <?php foreach ( $calc['details'] as $d ) : if ( ! $d['filled'] ) : ?>
                        <li><?php echo esc_html( $d['descriptor'] ); ?></li>
                    <?php endif; endforeach; ?>
                </ul>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function display_meter_hook() {
        $opts = $this->get_options();
        if ( empty( $opts['display_in_profile'] ) ) {
            return;
        }
        // Determine displayed user id if BuddyPress is available
        if ( function_exists( 'bp_displayed_user_id' ) ) {
            $uid = bp_displayed_user_id();
        } else {
            $uid = get_current_user_id();
        }
        echo $this->render_meter( $uid );
    }

    public function shortcode_meter( $atts ) {
        $a = shortcode_atts( array( 'user_id' => 0 ), $atts );
        $uid = intval( $a['user_id'] );
        if ( $uid <= 0 ) {
            $uid = get_current_user_id();
        }
        return $this->render_meter( $uid );
    }

    // Admin menu and settings
    public function admin_menu() {
        add_options_page( 'Profile Completeness Meter', 'Profile Completeness', 'manage_options', 'bp_pcm', array( $this, 'admin_page' ) );
    }

    public function register_settings() {
        register_setting( 'bp_pcm_group', $this->option_key );
    }

    public function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $opts = $this->get_options();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Profile Completeness Meter - Settings', 'bp-profile-meter' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'bp_pcm_group' ); ?>
                <?php do_settings_sections( 'bp_pcm_group' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="bp_pcm_fields"><?php esc_html_e( 'Fields (one per line)', 'bp-profile-meter' ); ?></label></th>
                        <td>
                            <textarea name="<?php echo esc_attr( $this->option_key ); ?>[fields]" id="bp_pcm_fields" rows="8" cols="60" class="large-text code"><?php echo esc_textarea( isset( $opts['fields'] ) ? $opts['fields'] : '' ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Format: meta:meta_key or xprofile:Field Name. Example: meta:first_name or xprofile:Location', 'bp-profile-meter' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'Display options', 'bp-profile-meter' ); ?></label></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr( $this->option_key ); ?>[show_percentage_label]" value="1" <?php checked( 1, isset( $opts['show_percentage_label'] ) ? $opts['show_percentage_label'] : 1 ); ?> /> <?php esc_html_e( 'Show percentage label', 'bp-profile-meter' ); ?></label><br />
                            <label><input type="checkbox" name="<?php echo esc_attr( $this->option_key ); ?>[display_in_profile]" value="1" <?php checked( 1, isset( $opts['display_in_profile'] ) ? $opts['display_in_profile'] : 1 ); ?> /> <?php esc_html_e( 'Show inside BuddyPress profile header (if available)', 'bp-profile-meter' ); ?></label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="bp_pcm_bar_height"><?php esc_html_e( 'Bar height (px)', 'bp-profile-meter' ); ?></label></th>
                        <td>
                            <input type="number" name="<?php echo esc_attr( $this->option_key ); ?>[bar_height]" id="bp_pcm_bar_height" value="<?php echo esc_attr( isset( $opts['bar_height'] ) ? $opts['bar_height'] : 8 ); ?>" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="bp_pcm_bar_color"><?php esc_html_e( 'Bar color', 'bp-profile-meter' ); ?></label></th>
                        <td>
                            <input type="text" name="<?php echo esc_attr( $this->option_key ); ?>[bar_color]" id="bp_pcm_bar_color" value="<?php echo esc_attr( isset( $opts['bar_color'] ) ? $opts['bar_color'] : '#2b90d9' ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Use hex color value (e.g. #2b90d9).', 'bp-profile-meter' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e( 'Shortcode & Usage', 'bp-profile-meter' ); ?></h2>
            <p><?php esc_html_e( 'Place the meter anywhere using the shortcode:', 'bp-profile-meter' ); ?> <code>[bp_profile_meter]</code></p>
            <p><?php esc_html_e( 'You can explicitly show a user by id: ', 'bp-profile-meter' ); ?> <code>[bp_profile_meter user_id="123"]</code></p>
        </div>
        <?php
    }

    // AJAX handler
    public function ajax_recalc() {
        check_ajax_referer( 'bp_pcm_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'not_logged_in' ) );
        }
        $user_id = get_current_user_id();
        if ( isset( $_POST['user_id'] ) && intval( $_POST['user_id'] ) > 0 ) {
            $user_id = intval( $_POST['user_id'] );
        }
        $calc = $this->calculate_user_completion( $user_id );
        wp_send_json_success( $calc );
    }
}

BP_Profile_Completeness_Meter::instance();

// Create simple CSS and JS files if missing when plugin runs. These are minimal - you can expand as needed.
// In real plugin development you'd ship css/ and js/ files. For this example we create them on-demand in plugin folder.
add_action( 'init', function() {
    $base = plugin_dir_path( __FILE__ );
    if ( ! file_exists( $base . 'css/bp-pcm.css' ) ) {
        @wp_mkdir_p( $base . 'css' );
        file_put_contents( $base . 'css/bp-pcm.css', ".bp-pcm-wrapper{font-family:Arial,Helvetica,sans-serif;margin:10px 0}.bp-pcm-label{margin-top:6px;font-size:13px}.bp-pcm-actions{margin-top:6px}.bp-pcm-breakdown{margin-top:8px;font-size:13px}.bp-pcm-breakdown ul{margin:6px 0 0 18px;padding:0}");
    }
    if ( ! file_exists( $base . 'js/bp-pcm.js' ) ) {
        @wp_mkdir_p( $base . 'js' );
        file_put_contents( $base . 'js/bp-pcm.js', "(function($){\n    $(document).ready(function(){\n        // Update meter after profile edit fields change (on BuddyPress edit screen)
        if( $('form#profile-edit-form').length ){\n            var timeout = null;\n            $('form#profile-edit-form').on('input change', 'input, textarea, select', function(){\n                clearTimeout(timeout);\n                timeout = setTimeout(function(){\n                    // request new percent via AJAX\n                    $.post(BP_PCM.ajax_url, { action: 'bp_profile_meter_recalc', nonce: BP_PCM.nonce }, function(resp){\n                        if( resp.success ){\n                            $('.bp-pcm-wrapper').each(function(){\n                                var $wrap = $(this);\n                                var uid = $wrap.data('user-id');\n                                var data = resp.data;\n                                // if multiple wrappers for different users exist, only update matching
                                if( !uid || uid == data.user_id || uid == " + get_current_user_id() + " ){\n                                    $wrap.find('.bp-pcm-fill').css('width', data.percent + '%');\n                                    $wrap.find('.bp-pcm-label').text(data.percent + '% complete');\n                                }\n                            });\n                        }\n                    }, 'json');\n                }, 700);\n            });\n        }\n    });\n})(jQuery);" );
    }
} );

?>
