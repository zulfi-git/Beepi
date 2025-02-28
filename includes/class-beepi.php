<?php
/**
 * The core plugin class.
 */
class Beepi {

    /**
     * Define the core functionality of the plugin.
     */
    public function __construct() {
        $this->load_dependencies();
        $this->define_public_hooks();
        $this->define_admin_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     */
    private function load_dependencies() {
        // API Classes
        require_once BEEPI_PLUGIN_DIR . 'includes/class-svv-api-integration.php';
        require_once BEEPI_PLUGIN_DIR . 'includes/class-svv-api-response.php';
        require_once BEEPI_PLUGIN_DIR . 'includes/class-svv-api-cache.php';
        
        // Admin Classes
        require_once BEEPI_PLUGIN_DIR . 'admin/class-beepi-admin.php';
    }

    /**
     * Register all of the hooks related to the public-facing functionality
     */
    private function define_public_hooks() {
        // Register shortcodes
        add_shortcode('vehicle_search', array($this, 'render_search_form'));
        add_shortcode('vehicle_results', array($this, 'render_results_page'));
        
        // Register scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Register AJAX handlers
        add_action('wp_ajax_vehicle_lookup', array($this, 'ajax_vehicle_lookup'));
        add_action('wp_ajax_nopriv_vehicle_lookup', array($this, 'ajax_vehicle_lookup'));
        
        // Register WooCommerce hooks
        add_action('woocommerce_order_status_completed', array($this, 'handle_completed_order'));
        add_filter('woocommerce_add_to_cart_redirect', array($this, 'redirect_to_checkout'));
    }

    /**
     * Register all of the hooks related to the admin area functionality
     */
    private function define_admin_hooks() {
        $plugin_admin = new Beepi_Admin();
        
        // Add menu item
        add_action('admin_menu', array($plugin_admin, 'add_plugin_admin_menu'));
        
        // Add settings
        add_action('admin_init', array($plugin_admin, 'register_settings'));
    }

    /**
     * Run the plugin.
     */
    public function run() {
        // Plugin is now loaded and ready
    }

    /**
     * Enqueue scripts and styles for the public-facing side
     */
    public function enqueue_scripts() {
        // Only load on pages with our shortcode
        global $post;
        if (is_a($post, 'WP_Post') && (
            has_shortcode($post->post_content, 'vehicle_search') || 
            has_shortcode($post->post_content, 'vehicle_results')
        )) {
            wp_enqueue_style(
                'beepi',
                BEEPI_PLUGIN_URL . 'public/css/beepi.css',
                array(),
                BEEPI_VERSION
            );
            
            wp_enqueue_script(
                'beepi',
                BEEPI_PLUGIN_URL . 'public/js/beepi.js',
                array('jquery'),
                BEEPI_VERSION,
                true
            );
            
            wp_localize_script('beepi', 'beepi', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('beepi_nonce'),
                'loading_text' => __('Searching...', 'beepi'),
                'error_text' => __('An error occurred. Please try again.', 'beepi')
            ));
        }
    }

    /**
     * Render search form shortcode
     */
    public function render_search_form($atts) {
        // Extract attributes
        $atts = shortcode_atts(array(
            'placeholder' => __('Enter registration number', 'beepi'),
            'button_text' => __('Search', 'beepi'),
            'partner_id' => '',
        ), $atts, 'vehicle_search');
        
        // Start output buffering
        ob_start();
        
        // Include template
        include BEEPI_PLUGIN_DIR . 'public/partials/search-form.php';
        
        // Return the buffered content
        return ob_get_clean();
    }

    /**
     * Render results page shortcode
     */
    public function render_results_page($atts) {
        // Extract attributes
        $atts = shortcode_atts(array(
            'partner_id' => '',
        ), $atts, 'vehicle_results');
        
        // Check if registration is provided
        if (!isset($_GET['reg'])) {
            return '<p>' . __('No registration number provided.', 'beepi') . '</p>';
        }
        
        $registration = sanitize_text_field($_GET['reg']);
        
        // Get vehicle data
        $api = new SVV_API_Integration();
        $response = $api->get_vehicle_by_registration($registration);
        $formatted_response = SVV_API_Response::format_response($response);
        
        // Start output buffering
        ob_start();
        
        if ($formatted_response['success']) {
            $vehicle_data = $formatted_response['data'];
            include BEEPI_PLUGIN_DIR . 'public/partials/teaser-results.php';
        } else {
            echo '<div class="vehicle-lookup-error">';
            echo '<p>' . esc_html($formatted_response['user_message']) . '</p>';
            echo '</div>';
        }
        
        // Return the buffered content
        return ob_get_clean();
    }

    /**
     * Handle AJAX vehicle lookup
     */
    public function ajax_vehicle_lookup() {
        // Verify nonce
        check_ajax_referer('beepi_nonce', 'nonce');
        
        // Get registration number
        if (!isset($_POST['registration'])) {
            wp_send_json_error(array(
                'message' => __('Registration number is required.', 'beepi'),
            ));
            return;
        }
        
        $registration = sanitize_text_field($_POST['registration']);
        $partner_id = isset($_POST['partner_id']) ? sanitize_text_field($_POST['partner_id']) : '';
        
        // Get vehicle data
        $api = new SVV_API_Integration();
        $response = $api->get_vehicle_by_registration($registration);
        $formatted_response = SVV_API_Response::format_response($response);
        
        // If error, return error message
        if (!$formatted_response['success']) {
            wp_send_json_error(array(
                'message' => $formatted_response['user_message'],
            ));
            return;
        }
        
        // Get product link for purchasing
        $product_id = $this->get_lookup_product_id();
        if (!$product_id) {
            wp_send_json_error(array(
                'message' => __('Product configuration error. Please contact support.', 'beepi'),
            ));
            return;
        }
        
        // Build checkout URL
        $args = array(
            'add-to-cart' => $product_id,
            'registration' => $registration,
        );
        
        // Add partner ID if provided
        if (!empty($partner_id)) {
            $args['partner_id'] = $partner_id;
        }
        
        $checkout_url = add_query_arg($args, wc_get_checkout_url());
        
        // Return success with teaser data and checkout URL
        wp_send_json_success(array(
            'teaser' => $formatted_response['data']['teaser'],
            'checkout_url' => $checkout_url,
        ));
    }

    /**
     * Get the product ID for vehicle lookup
     */
    private function get_lookup_product_id() {
        // Get from options
        $product_id = get_option('beepi_product_id');
        
        // If not set, try to find by SKU
        if (!$product_id) {
            $product_id = wc_get_product_id_by_sku('vehicle-lookup');
        }
        
        return $product_id;
    }

    /**
     * Redirect to checkout after adding to cart
     */
    public function redirect_to_checkout($redirect_url) {
        if (isset($_REQUEST['add-to-cart']) && $_REQUEST['add-to-cart'] == $this->get_lookup_product_id()) {
            $redirect_url = wc_get_checkout_url();
        }
        return $redirect_url;
    }

    /**
     * Handle completed order
     */
    public function handle_completed_order($order_id) {
        $order = wc_get_order($order_id);
        
        // Check if this is a vehicle lookup order
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if ($product_id == $this->get_lookup_product_id()) {
                // Get registration from order meta
                $registration = $order->get_meta('registration');
                if ($registration) {
                    // Store in user meta if user is logged in
                    $user_id = $order->get_user_id();
                    if ($user_id) {
                        $searches = get_user_meta($user_id, 'beepi_searches', true);
                        if (!is_array($searches)) {
                            $searches = array();
                        }
                        $searches[] = array(
                            'registration' => $registration,
                            'date' => current_time('mysql'),
                            'order_id' => $order_id,
                        );
                        update_user_meta($user_id, 'beepi_searches', $searches);
                    }
                }
                break;
            }
        }
    }
}
