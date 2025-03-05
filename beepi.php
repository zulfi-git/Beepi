<?php
/**
 * Plugin Name: Beepi
 * Description: Vehicle information lookup service using Statens Vegvesen API
 * Version: 1.1.0
 * Author: Zulfiqar Ali Haidari
 * Author URI: https://beepi.no
 * Text Domain: beepi
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('BEEPI_VERSION', '1.0.0');
define('BEEPI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BEEPI_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * The code that runs during plugin activation.
 */
function activate_beepi() {
    // Create a product for the vehicle lookup if it doesn't exist
    if (class_exists('WooCommerce')) {
        require_once BEEPI_PLUGIN_DIR . 'includes/class-beepi-activator.php';
        Beepi_Activator::activate();
    }
}
register_activation_hook(__FILE__, 'activate_beepi');

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_beepi() {
    // Clear any transients we've set
    require_once BEEPI_PLUGIN_DIR . 'includes/class-svv-api-cache.php';
    SVV_API_Cache::cleanup();
}
register_deactivation_hook(__FILE__, 'deactivate_beepi');

/**
 * The core plugin class
 */
require_once BEEPI_PLUGIN_DIR . 'includes/class-beepi.php';

/**
 * Begins execution of the plugin.
 */
function run_beepi() {
    $plugin = new Beepi();
    $plugin->run();
}
run_beepi();
