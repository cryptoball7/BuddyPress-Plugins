 	<?php
/**
 * Plugin Name: BuddyPress — WooCommerce Integration
 * Description: Adds a BuddyPress profile tab to show a user's WooCommerce purchases and (optional) wishlist items. Includes hooks for third-party wishlist integrations (YITH / TI examples).
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * Text Domain: bp-wc-integration
 * Domain Path: /languages
 *
 * Notes:
 * - Requires BuddyPress and WooCommerce to be active.
 * - Wishlist support can be added via the filter 'bwbi_get_wishlist_items'.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BWBI_Plugin {

    public function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ), 10 );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_action( 'bp_setup_nav', array( $this, 'add_bp_profile_tab' ), 100 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Optional: expose a shortcode as well
        add_shortcode( 'bwbi_profile_purchases', array( $this, 'shortcode_profile_purchases' ) );
    }

    public function init() {
        load_plugin_textdomain( 'bp-wc-integration', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public function admin_notices() {
        if ( ! is_plugin_active( 'buddypress/bp-loader.php' ) && ! function_exists( 'bp_core_load_template' ) ) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'BuddyPress — WooCommerce Integration plugin requires BuddyPress to be active.', 'bp-wc-integration' );
            echo '</p></div>';
        }
        if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) && ! class_exists( 'WooCommerce' ) ) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'BuddyPress — WooCommerce Integration plugin requires WooCommerce to be active.', 'bp-wc-integration' );
            echo '</p></div>';
        }
    }

    public function enqueue_assets() {
        // Minimal styling for the tab; override in theme/plugin if desired.
        wp_register_style( 'bwbi-style', plugins_url( 'assets/css/bwbi-style.css', __FILE__ ) );
        wp_enqueue_style( 'bwbi-style' );
    }

    /**
     * Add BuddyPress profile tab
     */
    public function add_bp_profile_tab() {
        if ( ! function_exists( 'bp_core_new_nav_item' ) ) {
            return;
        }

        bp_core_new_nav_item( array(
            'name'                => apply_filters( 'bwbi_nav_name', __( 'Purchases', 'bp-wc-integration' ) ),
            'slug'                => apply_filters( 'bwbi_nav_slug', 'purchases' ),
            'screen_function'     => array( $this, 'bp_profile_screen' ),
            'position'            => apply_filters( 'bwbi_nav_position', 50 ),
            'show_for_displayed_user' => true,
            'item_css_id'         => 'bp-wc-integration',
        ) );
    }

    /**
     * BuddyPress screen callback
     */
    public function bp_profile_screen() {
        add_action( 'bp_template_title', array( $this, 'bp_tab_title' ) );
        add_action( 'bp_template_content', array( $this, 'bp_tab_content' ) );
        bp_core_load_template( apply_filters( 'bwbi_template', 'members/single/plugins' ) );
    }

    public function bp_tab_title() {
        echo esc_html( apply_filters( 'bwbi_nav_name', __( 'Purchases', 'bp-wc-integration' ) ) );
    }

    /**
     * Main content renderer
     */
    public function bp_tab_content() {
        // which user profile we're viewing
        $displayed_user_id = bp_displayed_user_id();
        $current_user_id   = get_current_user_id();

        // Access control: default = only owner or admins can view another's purchases
        $can_view = false;
        if ( $current_user_id === (int) $displayed_user_id ) {
            $can_view = true;
        } elseif ( current_user_can( 'manage_options' ) ) {
            $can_view = true;
        } else {
            // If BuddyPress Friends component is active and you want friends to see each other, you can filter
            $can_view = apply_filters( 'bwbi_allow_view_other_profiles', false, $current_user_id, $displayed_user_id );
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            echo '<div class="bwbi-message">' . esc_html__( 'WooCommerce is not active.', 'bp-wc-integration' ) . '</div>';
            return;
        }

        if ( ! $can_view ) {
            echo '<div class="bwbi-message">' . esc_html__( 'You do not have permission to view this user\'s purchases.', 'bp-wc-integration' ) . '</div>';
            return;
        }

        // Show orders
        $this->render_orders_for_user( (int) $displayed_user_id );

        // Show wishlist (if any)
        $wishlist_items = apply_filters( 'bwbi_get_wishlist_items', null, $displayed_user_id );

        // If no filter provided wishlist_items is null -> attempt built-in integrations
        if ( null === $wishlist_items ) {
            $wishlist_items = $this->detect_wishlist_items( $displayed_user_id );
        }

        if ( ! empty( $wishlist_items ) ) {
            echo '<h3 class="bwbi-section-title">' . esc_html__( 'Wishlist', 'bp-wc-integration' ) . '</h3>';
            echo '<ul class="bwbi-wishlist">';
            foreach ( $wishlist_items as $item ) {
                // Expect item to have at least 'id' and 'title' optionally 'permalink' and 'price'
                $title = isset( $item['title'] ) ? $item['title'] : ( isset( $item['id'] ) ? get_the_title( $item['id'] ) : __( 'Unknown product', 'bp-wc-integration' ) );
                $link  = isset( $item['permalink'] ) ? $item['permalink'] : ( isset( $item['id'] ) ? get_permalink( $item['id'] ) : '' );
                $price = isset( $item['price'] ) ? $item['price'] : '';

                echo '<li class="bwbi-wishlist-item">';
                if ( $link ) {
                    echo '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>';
                } else {
                    echo esc_html( $title );
                }
                if ( $price ) {
                    echo ' — <span class="bwbi-price">' . wp_kses_post( $price ) . '</span>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
    }

    /**
     * Render orders for a given WP user id
     *
     * @param int $user_id
     */
    protected function render_orders_for_user( $user_id ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            echo '<div class="bwbi-message">' . esc_html__( 'WooCommerce order functions not available.', 'bp-wc-integration' ) . '</div>';
            return;
        }

        // Get orders: use WC_Order_Query via wc_get_orders
        $args = array(
            'customer_id' => $user_id,
            'limit'       => apply_filters( 'bwbi_orders_limit', 20 ),
            'orderby'     => 'date',
            'order'       => 'DESC',
        );

        $orders = wc_get_orders( $args );

        if ( empty( $orders ) ) {
            echo '<div class="bwbi-message">' . esc_html__( 'No orders found for this user.', 'bp-wc-integration' ) . '</div>';
            return;
        }

        echo '<div class="bwbi-orders-list">';
        foreach ( $orders as $order ) {
            // $order may be WC_Order or stdClass depending WP/WC version. Cast if needed.
            if ( is_callable( array( $order, 'get_id' ) ) ) {
                $order_id = $order->get_id();
                $order_date = $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '';
                $order_status = wc_get_order_status_name( $order->get_status() );
                $order_total = $order->get_formatted_order_total();
                $items = $order->get_items();
            } else {
                // fallback if older wc returns stdClass
                $order_id = isset( $order->id ) ? $order->id : '';
                $order_date = isset( $order->date_created ) ? $order->date_created : '';
                $order_status = isset( $order->status ) ? $order->status : '';
                $order_total = isset( $order->total ) ? wc_price( $order->total ) : '';
                $items = isset( $order->line_items ) ? $order->line_items : array();
            }

            echo '<div class="bwbi-order">';
            echo '<h4 class="bwbi-order-title">';
            echo esc_html__( 'Order', 'bp-wc-integration' ) . ' #' . esc_html( $order_id );
            if ( $order_date ) {
                echo ' <small class="bwbi-order-date">(' . esc_html( $order_date ) . ')</small>';
            }
            echo '</h4>';

            echo '<div class="bwbi-order-meta">';
            echo '<span class="bwbi-order-status"><strong>' . esc_html__( 'Status:', 'bp-wc-integration' ) . '</strong> ' . esc_html( $order_status ) . '</span>';
            echo ' &nbsp; <span class="bwbi-order-total"><strong>' . esc_html__( 'Total:', 'bp-wc-integration' ) . '</strong> ' . wp_kses_post( $order_total ) . '</span>';
            echo '</div>';

            // items
            if ( ! empty( $items ) ) {
                echo '<ul class="bwbi-order-items">';
                foreach ( $items as $item_id => $item ) {
                    // item object may differ by WC version
                    if ( is_object( $item ) && method_exists( $item, 'get_name' ) ) {
                        $prod_name = $item->get_name();
                        $qty = $item->get_quantity();
                        $subtotal = wc_price( $item->get_subtotal() );
                    } else {
                        // fallback array
                        $prod_name = isset( $item['name'] ) ? $item['name'] : '';
                        $qty = isset( $item['qty'] ) ? $item['qty'] : ( isset( $item['quantity'] ) ? $item['quantity'] : '' );
                        $subtotal = isset( $item['subtotal'] ) ? wc_price( $item['subtotal'] ) : '';
                    }

                    echo '<li>';
                    echo esc_html( $prod_name );
                    if ( $qty ) {
                        echo ' &times; ' . intval( $qty );
                    }
                    if ( $subtotal ) {
                        echo ' — ' . wp_kses_post( $subtotal );
                    }
                    echo '</li>';
                }
                echo '</ul>';
            }

            // Link to view order (if viewer can)
            if ( $this->current_user_can_view_order( get_current_user_id(), $user_id ) ) {
                // If WP uses "view-order" endpoint (WooCommerce), use my-account/view-order/{order_id}
                $view_url = wc_get_endpoint_url( 'view-order', $order_id, wc_get_page_permalink( 'myaccount' ) );
                if ( $view_url ) {
                    echo '<p><a class="bwbi-order-view" href="' . esc_url( $view_url ) . '">' . esc_html__( 'View order', 'bp-wc-integration' ) . '</a></p>';
                }
            }

            echo '</div>'; // .bwbi-order
        }
        echo '</div>'; // .bwbi-orders-list
    }

    protected function current_user_can_view_order( $current_user_id, $order_owner_id ) {
        if ( $current_user_id === (int) $order_owner_id ) {
            return true;
        }
        if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
            return true;
        }
        return apply_filters( 'bwbi_allow_view_order_link', false, $current_user_id, $order_owner_id );
    }

    /**
     * Attempt to detect wishlist items from popular wishlist plugins.
     * Returns array of items: each item = array( 'id' => int, 'title' => string, 'permalink' => string, 'price' => string )
     *
     * If nothing detected, returns empty array.
     */
    protected function detect_wishlist_items( $user_id ) {
        $items = array();

        // 1) YITH WooCommerce Wishlist integration (best-effort)
        if ( class_exists( 'YITH_WCWL' ) || function_exists( 'YITH_WCWL' ) ) {
            // Best-effort: try to use any available helper functions. YITH has a variety of functions and classes across versions.
            // We'll attempt to use yith_wcwl_get_users_wishlist or the YITH_WCWL_Wishlist class if available.
            if ( function_exists( 'yith_wcwl_get_users_wishlist' ) ) {
                $wishlist = yith_wcwl_get_users_wishlist( $user_id );
                if ( is_array( $wishlist ) ) {
                    foreach ( $wishlist as $witem ) {
                        if ( isset( $witem['prod_id'] ) ) {
                            $p_id = intval( $witem['prod_id'] );
                            $items[] = array(
                                'id'        => $p_id,
                                'title'     => get_the_title( $p_id ),
                                'permalink' => get_permalink( $p_id ),
                                'price'     => wc_price( get_post_meta( $p_id, '_price', true ) ),
                            );
                        }
                    }
                }
            } else {
                // try another class-based approach
                if ( class_exists( 'YITH_WCWL_Wishlist' ) ) {
                    // Attempt method names that exist in some versions (best-effort).
                    try {
                        $wishlist_object = new YITH_WCWL_Wishlist(); // may or may not require args
                        if ( method_exists( $wishlist_object, 'get_products' ) ) {
                            $y_items = $wishlist_object->get_products( $user_id );
                            if ( is_array( $y_items ) ) {
                                foreach ( $y_items as $prod ) {
                                    $p_id = isset( $prod['prod_id'] ) ? intval( $prod['prod_id'] ) : ( isset( $prod['product_id'] ) ? intval( $prod['product_id'] ) : 0 );
                                    if ( $p_id ) {
                                        $items[] = array(
                                            'id' => $p_id,
                                            'title' => get_the_title( $p_id ),
                                            'permalink' => get_permalink( $p_id ),
                                            'price' => wc_price( get_post_meta( $p_id, '_price', true ) ),
                                        );
                                    }
                                }
                            }
                        }
                    } catch ( Exception $e ) {
                        // swallow errors — we will fall back
                    }
                }
            }
        }

        // 2) TI WooCommerce Wishlist (best-effort)
        if ( empty( $items ) && class_exists( 'TI_WC_Wishlist' ) ) {
            // TI provides various functions; it also exposes REST endpoints. We'll try a common function if available.
            if ( function_exists( 'ti_wishlist_get_wishlist' ) ) {
                $ti_wishlist = ti_wishlist_get_wishlist( $user_id );
                if ( is_array( $ti_wishlist ) ) {
                    foreach ( $ti_wishlist as $p_id ) {
                        $p_id = intval( $p_id );
                        if ( $p_id ) {
                            $items[] = array(
                                'id'        => $p_id,
                                'title'     => get_the_title( $p_id ),
                                'permalink' => get_permalink( $p_id ),
                                'price'     => wc_price( get_post_meta( $p_id, '_price', true ) ),
                            );
                        }
                    }
                }
            }
        }

        // 3) Allow other plugins/theme to provide wishlist items via filter
        $items = apply_filters( 'bwbi_wishlist_items_after_detection', $items, $user_id );

        // Normalize: ensure each item has title/permalink/price where possible
        $items = array_map( array( $this, 'normalize_wishlist_item' ), $items );

        return $items;
    }

    protected function normalize_wishlist_item( $item ) {
        if ( is_scalar( $item ) ) {
            // If plugin passes a product ID
            $p_id = intval( $item );
            return array(
                'id' => $p_id,
                'title' => get_the_title( $p_id ),
                'permalink' => get_permalink( $p_id ),
                'price' => wc_price( get_post_meta( $p_id, '_price', true ) ),
            );
        }

        if ( is_array( $item ) ) {
            if ( isset( $item['id'] ) && empty( $item['title'] ) ) {
                $item['title'] = get_the_title( intval( $item['id'] ) );
            }
            if ( isset( $item['id'] ) && empty( $item['permalink'] ) ) {
                $item['permalink'] = get_permalink( intval( $item['id'] ) );
            }
            if ( isset( $item['id'] ) && empty( $item['price'] ) ) {
                $item['price'] = wc_price( get_post_meta( intval( $item['id'] ), '_price', true ) );
            }
            return $item;
        }

        return array();
    }

    /**
     * Shortcode wrapper: [bwbi_profile_purchases user_id="123"]
     */
    public function shortcode_profile_purchases( $atts ) {
        $atts = shortcode_atts( array(
            'user_id' => get_current_user_id(),
        ), $atts, 'bwbi_profile_purchases' );

        ob_start();
        $this->render_orders_for_user( intval( $atts['user_id'] ) );
        return ob_get_clean();
    }
}

new BWBI_Plugin();

