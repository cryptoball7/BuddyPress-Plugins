<?php
/**
 * Plugin Name: BP Group Specific Features
 * Description: Adds group-specific pages, custom fields, and widgets to BuddyPress groups using group metadata and the Group Extension API.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: bp-gsf
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BP_GSF_Plugin {
    const VERSION = '1.0.0';
    const META_KEY = 'bp_gsf_fields'; // store an array of custom fields per group

    public static function init() {
        // Load textdomain
        add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );

        // Ensure BuddyPress is active before proceeding
        add_action( 'bp_include', array( __CLASS__, 'bootstrap' ) );

        // Register widget
        add_action( 'widgets_init', array( __CLASS__, 'register_widgets' ) );

        // Shortcode to output group meta
        add_shortcode( 'gsf_group_meta', array( __CLASS__, 'shortcode_group_meta' ) );
    }

    public static function load_textdomain() {
        load_plugin_textdomain( 'bp-gsf', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public static function bootstrap() {
        if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'groups' ) ) {
            return;
        }

        // Register a Group Extension (adds a group admin tab + front-end screen)
        bp_register_group_extension( 'BP_GSF_Group_Extension' );

        // Save custom fields when group is created/edited
        add_action( 'groups_edit_group', array( __CLASS__, 'save_group_meta_on_edit' ) );
        add_action( 'groups_create_group', array( __CLASS__, 'save_group_meta_on_create' ) );

        // Add REST-friendly helper if needed (minimal)
        add_action( 'bp_groups_setup_globals', array( __CLASS__, 'setup_globals' ) );
    }

    public static function setup_globals() {
        // Placeholder for extended REST or navigation integration if required later
    }

    public static function register_widgets() {
        register_widget( 'BP_GSF_Group_Widget' );
    }

    /**
     * Save group meta when editing
     */
    public static function save_group_meta_on_edit( $group_id ) {
        if ( ! isset( $_POST['bp_gsf_fields_nonce'] ) || ! wp_verify_nonce( $_POST['bp_gsf_fields_nonce'], 'bp_gsf_save_fields' ) ) {
            return;
        }

        $fields = isset( $_POST['bp_gsf_fields'] ) && is_array( $_POST['bp_gsf_fields'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['bp_gsf_fields'] ) ) : array();

        groups_update_groupmeta( $group_id, self::META_KEY, $fields );
    }

    /**
     * Save group meta on create (BuddyPress 1.6+ passes $group_id)
     */
    public static function save_group_meta_on_create( $group_id ) {
        // Create screen uses the same form field name when present on group creation screen
        if ( isset( $_POST['bp_gsf_fields'] ) && is_array( $_POST['bp_gsf_fields'] ) ) {
            $fields = array_map( 'sanitize_text_field', wp_unslash( $_POST['bp_gsf_fields'] ) );
            groups_update_groupmeta( $group_id, self::META_KEY, $fields );
        }
    }

    public static function shortcode_group_meta( $atts ) {
        $atts = shortcode_atts( array(
            'key' => '',
            'group_id' => 0,
            'before' => '',
            'after' => '',
        ), $atts, 'gsf_group_meta' );

        $group_id = intval( $atts['group_id'] );
        if ( $group_id <= 0 && function_exists( 'bp_get_current_group_id' ) ) {
            $group_id = bp_get_current_group_id();
        }

        if ( $group_id <= 0 ) {
            return '';
        }

        $fields = groups_get_groupmeta( $group_id, self::META_KEY );
        if ( empty( $fields ) || ! is_array( $fields ) ) {
            return '';
        }

        $key = $atts['key'];
        if ( $key === '' ) {
            // return all fields as a definition list
            $out = "<dl class=\"bp-gsf-fields\">";
            foreach ( $fields as $k => $v ) {
                $out .= "<dt>" . esc_html( $k ) . "</dt><dd>" . esc_html( $v ) . "</dd>";
            }
            $out .= "</dl>";
            return $out;
        }

        if ( isset( $fields[ $key ] ) ) {
            return $atts['before'] . esc_html( $fields[ $key ] ) . $atts['after'];
        }

        return '';
    }
}

/**
 * Group Extension class — uses BuddyPress BP_Group_Extension API
 */
class BP_GSF_Group_Extension extends BP_Group_Extension {
    public function __construct() {
        $this->name = __( 'Custom Features', 'bp-gsf' );
        $this->slug = 'custom-features';
        $this->create_step_position = 40; // shows on group creation step if desired
        $this->nav_item_position = 60;

        // show in group admin and in front-end
        $this->enable_nav_item = true;
        $this->enable_create_step = false; // set true to show on group creation wizard

        parent::__construct();
    }

    /**
     * Front-end display of the group tab
     */
    public function display( $group_id = null ) {
        if ( empty( $group_id ) ) {
            $group_id = bp_get_current_group_id();
        }

        $fields = groups_get_groupmeta( $group_id, BP_GSF_Plugin::META_KEY );

        echo '<div class="bp-gsf-front">';
        if ( empty( $fields ) || ! is_array( $fields ) ) {
            echo '<p>' . esc_html__( 'No custom features configured for this group yet.', 'bp-gsf' ) . '</p>';
        } else {
            echo '<h3>' . esc_html__( 'Group Custom Fields', 'bp-gsf' ) . '</h3>';
            echo '<ul class="bp-gsf-field-list">';
            foreach ( $fields as $k => $v ) {
                printf( '<li><strong>%s:</strong> %s</li>', esc_html( $k ), esc_html( $v ) );
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    /**
     * Admin form inside the group admin screens (for admins/mods)
     */
    public function admin_screen( $group_id = null ) {
        if ( empty( $group_id ) ) {
            $group_id = bp_get_current_group_id();
        }

        $fields = groups_get_groupmeta( $group_id, BP_GSF_Plugin::META_KEY );
        if ( ! is_array( $fields ) ) {
            $fields = array();
        }

        wp_nonce_field( 'bp_gsf_save_fields', 'bp_gsf_fields_nonce' );

        ?>
        <p><?php esc_html_e( 'Add custom key/value fields that are saved to group metadata and visible on the group's Custom Features tab.', 'bp-gsf' ); ?></p>

        <table class="form-table bp-gsf-admin-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Key', 'bp-gsf' ); ?></th>
                    <th><?php esc_html_e( 'Value', 'bp-gsf' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'bp-gsf' ); ?></th>
                </tr>
            </thead>
            <tbody id="bp-gsf-fields-body">
                <?php if ( empty( $fields ) ) : ?>
                    <tr class="bp-gsf-field-row">
                        <td><input type="text" name="bp_gsf_fields[key][]" value="" /></td>
                        <td><input type="text" name="bp_gsf_fields[value][]" value="" /></td>
                        <td><button class="button bp-gsf-remove-row" type="button"><?php esc_html_e( 'Remove', 'bp-gsf' ); ?></button></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $fields as $k => $v ) : ?>
                        <tr class="bp-gsf-field-row">
                            <td><input type="text" name="bp_gsf_fields[key][]" value="<?php echo esc_attr( $k ); ?>" /></td>
                            <td><input type="text" name="bp_gsf_fields[value][]" value="<?php echo esc_attr( $v ); ?>" /></td>
                            <td><button class="button bp-gsf-remove-row" type="button"><?php esc_html_e( 'Remove', 'bp-gsf' ); ?></button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <p>
            <button id="bp-gsf-add-row" class="button" type="button"><?php esc_html_e( 'Add field', 'bp-gsf' ); ?></button>
        </p>

        <input type="hidden" name="bp_gsf_fields_nonce_stub" value="1" />

        <script>
        (function(){
            var tableBody = document.getElementById('bp-gsf-fields-body');
            var addBtn = document.getElementById('bp-gsf-add-row');

            function makeRow(key, value){
                var tr = document.createElement('tr');
                tr.className = 'bp-gsf-field-row';

                var td1 = document.createElement('td');
                var kInput = document.createElement('input');
                kInput.type = 'text';
                kInput.name = 'bp_gsf_fields[key][]';
                kInput.value = key || '';
                td1.appendChild(kInput);

                var td2 = document.createElement('td');
                var vInput = document.createElement('input');
                vInput.type = 'text';
                vInput.name = 'bp_gsf_fields[value][]';
                vInput.value = value || '';
                td2.appendChild(vInput);

                var td3 = document.createElement('td');
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'button bp-gsf-remove-row';
                remove.textContent = '<?php echo esc_js( __( 'Remove', 'bp-gsf' ) ); ?>';
                remove.addEventListener('click', function(){ tr.parentNode.removeChild(tr); });
                td3.appendChild(remove);

                tr.appendChild(td1);
                tr.appendChild(td2);
                tr.appendChild(td3);

                return tr;
            }

            addBtn.addEventListener('click', function(e){
                tableBody.appendChild(makeRow('',''));
            });

            // attach remove listeners to existing rows
            var removes = document.querySelectorAll('.bp-gsf-remove-row');
            Array.prototype.forEach.call(removes, function(btn){
                btn.addEventListener('click', function(e){
                    var tr = e.target.closest('tr');
                    if ( tr ) { tr.parentNode.removeChild(tr); }
                });
            });
        })();
        </script>

        <?php
    }

    /**
     * Handle saving from our admin_screen. BuddyPress will call this when saving group admin screens.
     */
    public function admin_screen_save( $group_id = null ) {
        if ( empty( $group_id ) ) {
            $group_id = bp_get_current_group_id();
        }

        // Our outer plugin handles nonce and raw saving on groups_edit_group hook.
        // But to be defensive, if fields exist here, persist them.
        if ( isset( $_POST['bp_gsf_fields'] ) ) {
            $keys = isset( $_POST['bp_gsf_fields']['key'] ) ? (array) $_POST['bp_gsf_fields']['key'] : array();
            $values = isset( $_POST['bp_gsf_fields']['value'] ) ? (array) $_POST['bp_gsf_fields']['value'] : array();

            $fields = array();
            for ( $i = 0; $i < count( $keys ); $i++ ) {
                $k = sanitize_text_field( wp_unslash( $keys[ $i ] ) );
                $v = sanitize_text_field( wp_unslash( $values[ $i ] ) );
                if ( $k !== '' ) {
                    $fields[ $k ] = $v;
                }
            }

            groups_update_groupmeta( $group_id, BP_GSF_Plugin::META_KEY, $fields );
        }
    }

    /**
     * Add the create screen if needed (not active by default)
     */
    public function create_screen( $group_id = null ) {
        // Optionally add fields to the group creation wizard
        echo '<p>' . esc_html__( 'Add group custom fields (optional).', 'bp-gsf' ) . '</p>';
        ?>
        <p>
            <label><?php esc_html_e( 'Field key', 'bp-gsf' ); ?> <input type="text" name="bp_gsf_fields[key][]" /></label>
            <label><?php esc_html_e( 'Value', 'bp-gsf' ); ?> <input type="text" name="bp_gsf_fields[value][]" /></label>
        </p>
        <?php
    }
}

/**
 * Widget that displays group-specific features when viewing a group
 */
class BP_GSF_Group_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'bp_gsf_group_widget',
            __( 'Group: Custom Features', 'bp-gsf' ),
            array( 'description' => __( 'Shows custom group metadata when viewing a BuddyPress group.', 'bp-gsf' ) )
        );
    }

    public function widget( $args, $instance ) {
        if ( function_exists( 'bp_is_group' ) && bp_is_group() ) {
            $group_id = bp_get_current_group_id();
            $fields = groups_get_groupmeta( $group_id, BP_GSF_Plugin::META_KEY );

            echo $args['before_widget'];
            $title = ! empty( $instance['title'] ) ? $instance['title'] : __( 'Group Info', 'bp-gsf' );
            echo $args['before_title'] . apply_filters( 'widget_title', $title ) . $args['after_title'];

            if ( ! empty( $fields ) && is_array( $fields ) ) {
                echo '<ul class="bp-gsf-widget-list">';
                foreach ( $fields as $k => $v ) {
                    printf( '<li><strong>%s:</strong> %s</li>', esc_html( $k ), esc_html( $v ) );
                }
                echo '</ul>';
            } else {
                echo '<p>' . esc_html__( 'No custom fields configured.', 'bp-gsf' ) . '</p>';
            }

            echo $args['after_widget'];
        }
    }

    public function form( $instance ) {
        $title = isset( $instance['title'] ) ? $instance['title'] : __( 'Group Info', 'bp-gsf' );
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        $instance = array();
        $instance['title'] = sanitize_text_field( $new_instance['title'] );
        return $instance;
    }
}

// Small helper to read group meta safely
function bp_gsf_get_group_fields( $group_id = 0 ) {
    if ( empty( $group_id ) && function_exists( 'bp_get_current_group_id' ) ) {
        $group_id = bp_get_current_group_id();
    }

    if ( empty( $group_id ) ) {
        return array();
    }

    $fields = groups_get_groupmeta( $group_id, BP_GSF_Plugin::META_KEY );
    if ( ! is_array( $fields ) ) {
        return array();
    }

    return $fields;
}

// Convert the legacy key/value pair fields into associative array when saving from admin table
add_action( 'groups_edit_group', function( $group_id ) {
    if ( ! isset( $_POST['bp_gsf_fields_nonce'] ) ) {
        return;
    }

    // If submitted via our admin_screen table where keys and values are arrays
    if ( isset( $_POST['bp_gsf_fields']['key'] ) && isset( $_POST['bp_gsf_fields']['value'] ) ) {
        $keys = (array) $_POST['bp_gsf_fields']['key'];
        $values = (array) $_POST['bp_gsf_fields']['value'];
        $fields = array();
        for ( $i = 0; $i < count( $keys ); $i++ ) {
            $k = sanitize_text_field( wp_unslash( $keys[ $i ] ) );
            $v = sanitize_text_field( wp_unslash( $values[ $i ] ) );
            if ( $k !== '' ) {
                $fields[ $k ] = $v;
            }
        }
        groups_update_groupmeta( $group_id, BP_GSF_Plugin::META_KEY, $fields );
    }
}, 10, 1 );

// Kick things off
BP_GSF_Plugin::init();

/*
Installation:
1. Save this file to wp-content/plugins/bp-group-specific-features/bp-group-specific-features.php
2. Activate the plugin in WP admin.
3. Visit any BuddyPress group's Admin > Custom Features tab to add key/value fields.
4. Use widget in Appearance > Widgets (it will only show inside group pages).
5. Use shortcode [gsf_group_meta key="your_key"] inside pages or group descriptions. If key omitted, shows all fields.

Notes & Extensibility:
- Uses groups_*meta to persist. You can replace storage with your own meta structure.
- The Group Extension API (BP_Group_Extension) is used to add a group tab and admin UI.
- Security: nonces are used. All inputs are sanitized.
- If you want custom pages instead of a tab, hook into bp_setup_nav or bp_core_new_subnav_item.
- You can extend this plugin to register REST fields or expose data to JavaScript by enqueueing localized scripts.
*/
