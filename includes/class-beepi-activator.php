<?php
/**
 * Fired during plugin activation.
 */
class Beepi_Activator {

    /**
     * Create necessary database structures and default settings
     */
    public static function activate() {
        // Ensure WooCommerce is active
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('This plugin requires WooCommerce to be installed and activated.');
        }
        
        // Check if product already exists
        $product_id = get_option('beepi_product_id');
        
        if (!$product_id || !wc_get_product($product_id)) {
            // Create the product
            self::create_vehicle_lookup_product();
        }
    }
    
    /**
     * Create the vehicle lookup product in WooCommerce
     */
    private static function create_vehicle_lookup_product() {
        // Default product data
        $product_data = array(
            'name' => 'Vehicle Owner Lookup',
            'slug' => 'vehicle-owner-lookup',
            'type' => 'virtual', // No shipping needed
            'status' => 'publish',
            'catalog_visibility' => 'hidden', // Not shown in shop
            'description' => 'Provides access to vehicle owner information and detailed vehicle history.',
            'short_description' => 'Find out who owns a vehicle',
            'price' => '49', // Default price - can be changed later
            'regular_price' => '49',
            'virtual' => true,
            'downloadable' => false,
            'sold_individually' => true, // Only one per order
            'tax_status' => 'taxable',
            'manage_stock' => false,
        );
        
        // Create the product
        $product = new WC_Product_Simple();
        
        // Set product data
        $product->set_name($product_data['name']);
        $product->set_status($product_data['status']);
        $product->set_catalog_visibility($product_data['catalog_visibility']);
        $product->set_description($product_data['description']);
        $product->set_short_description($product_data['short_description']);
        $product->set_price($product_data['price']);
        $product->set_regular_price($product_data['regular_price']);
        $product->set_virtual($product_data['virtual']);
        $product->set_downloadable($product_data['downloadable']);
        $product->set_sold_individually($product_data['sold_individually']);
        $product->set_tax_status($product_data['tax_status']);
        $product->set_manage_stock($product_data['manage_stock']);
        $product->set_sku('vehicle-lookup');
        
        // Save the product
        $product_id = $product->save();
        
        // Store the product ID in options
        if ($product_id) {
            update_option('beepi_product_id', $product_id);
        }
        
        return $product_id;
    }
}
