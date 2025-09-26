<?php
/**
 * REST API do Click2Pickup
 */

if (!defined('ABSPATH')) {
    exit;
}

class C2P_REST_API {

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route('click2pickup/v1', '/stock', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_stock'),
                'permission_callback' => array($this, 'permissions_check'),
                'args'                => array(
                    'product_id' => array(
                        'required' => true,
                        'type'     => 'integer'
                    )
                )
            )
        ));
    }

    public function permissions_check($request) {
        return current_user_can('manage_c2p_stock') || current_user_can('manage_woocommerce');
    }

    public function get_stock(WP_REST_Request $request) {
        global $wpdb;
        $product_id = intval($request->get_param('product_id'));
        if ($product_id <= 0) {
            return new WP_Error('invalid_product', __('Produto inválido', 'click2pickup'), array('status' => 400));
        }

        $sm = C2P_Stock_Manager::get_instance();
        $global = $sm->get_global_stock($product_id);

        $stock_table = $wpdb->prefix . 'c2p_stock';
        $loc_table = $wpdb->prefix . 'c2p_locations';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.location_id, l.name as location_name, s.stock_quantity, s.reserved_quantity
             FROM $stock_table s
             LEFT JOIN $loc_table l ON l.id = s.location_id
             WHERE s.product_id = %d
             ORDER BY l.name ASC",
            $product_id
        ), ARRAY_A);

        return rest_ensure_response(array(
            'product_id' => $product_id,
            'global_available' => $global,
            'locations' => $rows ?: array()
        ));
    }
}