<?php
/**
 * Plugin Name: OC StoreOS Integration
 * Description: Two-way order sync between WooCommerce and external OC StoreOS system.
 * Version: 1.0.0
 * Author: OC
 * Text Domain: oc-storeos-integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OC_STOREOS_INTEGRATION_VERSION', '1.0.0' );
define( 'OC_STOREOS_INTEGRATION_PLUGIN_FILE', __FILE__ );
define( 'OC_STOREOS_INTEGRATION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once OC_STOREOS_INTEGRATION_PLUGIN_DIR . 'includes/class-oc-storeos-integration.php';

add_action(
    'plugins_loaded',
    static function () {
        if ( class_exists( 'WooCommerce' ) ) {
            OC_StoreOS_Integration::get_instance();
        }
    }
);

