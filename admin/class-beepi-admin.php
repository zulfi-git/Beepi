<?php
/**
 * The admin-specific functionality of the plugin.
 */
class Beepi_Admin {

    /**
     * Constructor - add this to the class
     */
    public function __construct() {
        // Register AJAX handlers
        add_action('wp_ajax_beepi_test_token', array($this, 'ajax_test_token'));
        add_action('wp_ajax_svv_run_diagnostics', array($this, 'ajax_run_diagnostics'));

        // Add admin pages and assets
        add_action('admin_menu', array($this, 'add_plugin_admin_menu'));
        add_action('admin_menu', array($this, 'add_svv_diagnostics_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_diagnostics_scripts'));
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our plugin's admin page
        if ($hook != 'toplevel_page_beepi') {
            return;
        }
        
        // Enqueue styles
        wp_enqueue_style(
            'beepi-admin',
            BEEPI_PLUGIN_URL . 'admin/css/beepi-admin.css',
            array(),
            BEEPI_VERSION
        );
        
        // Enqueue scripts
        wp_enqueue_script(
            'beepi-admin',
            BEEPI_PLUGIN_URL . 'admin/js/beepi-admin.js',
            array('jquery'),
            BEEPI_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('beepi-admin', 'beepiAdmin', array(
            'nonce' => wp_create_nonce('beepi_admin_nonce'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'diagnostic_labels' => array(
                'running' => __('Running diagnostics...', 'beepi'),
                'success' => __('Success', 'beepi'),
                'error' => __('Error', 'beepi'),
                'unknown' => __('Unknown', 'beepi')
            )
        ));
    }

    /**
     * Enqueue scripts for diagnostics page
     */
    public function enqueue_diagnostics_scripts($hook) {
        // Check if we're on our diagnostics page
        if ($hook !== 'beepi_page_svv-api-diagnostics') {
            return;
        }

        // Enqueue our diagnostic script
        wp_enqueue_script(
            'beepi-svv-diagnostics', 
            BEEPI_PLUGIN_URL . 'admin/js/svv-diagnostics.js', 
            array('jquery'), 
            BEEPI_VERSION, 
            true
        );

        // Localize script with nonce and ajax url
        wp_localize_script('beepi-svv-diagnostics', 'svvDiagnostics', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('beepi_admin_nonce'),
            'diagnostic_labels' => array(
                'running' => __('Running diagnostics...', 'beepi'),
                'success' => __('Diagnostics Completed', 'beepi'),
                'error' => __('Diagnostics Failed', 'beepi')
            )
        ));
    }

    /**
     * AJAX handler for testing token authentication
     */
    public function ajax_test_token() {
        // Verify nonce
        check_ajax_referer('beepi_admin_nonce', 'nonce');
        
        // Test authentication
        $api = new SVV_API_Integration();
        $results = $api->test_token_generation();
        
        if (!$results['success']) {
            // Authentication failed
            wp_send_json_error(array(
                'messages' => $results['messages']
            ));
        } else {
            // Authentication successful
            wp_send_json_success(array(
                'messages' => $results['messages']
            ));
        }
    }

    /**
     * AJAX handler for running API diagnostics
     */
    public function ajax_run_diagnostics() {
        check_ajax_referer('beepi_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $api = new SVV_API_Integration();
        $diagnostic_report = $api->run_full_diagnostics();
        
        wp_send_json_success($diagnostic_report);
    }

    /**
     * Add plugin admin menu
     */
    public function add_plugin_admin_menu() {
        add_menu_page(
            __('Beepi Settings', 'beepi'),
            'Beepi',
            'manage_options',
            'beepi',
            array($this, 'display_plugin_admin_page'),
            'dashicons-car',
            85
        );
    }

    /**
     * Add SVV API Diagnostics submenu page
     */
    public function add_svv_diagnostics_page() {
        add_submenu_page(
            'beepi', // Parent slug
            'SVV API Diagnostics', 
            'API Diagnostics', 
            'manage_options', 
            'svv-api-diagnostics', 
            array($this, 'render_diagnostics_page')
        );
    }

    /**
     * Render the SVV API Diagnostics page
     */
    public function render_diagnostics_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Statens Vegvesen API Diagnostics', 'beepi'); ?></h1>
            <p><?php _e('Run a comprehensive diagnostic test of the SVV API integration.', 'beepi'); ?></p>
            <button id="run-svv-diagnostics" class="button button-primary">
                <?php _e('Run Diagnostics', 'beepi'); ?>
            </button>
            <div id="diagnostic-results" style="margin-top: 20px; display: none;">
                <h3><?php _e('Diagnostic Results', 'beepi'); ?></h3>
                <pre class="diagnostic-content"></pre>
            </div>
        </div>
        <?php
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'beepi_options',
            'beepi_product_id',
            array(
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'default' => 0,
            )
        );
    }

    /**
     * Render the settings page
     */
    public function display_plugin_admin_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Get current product ID
        $product_id = get_option('beepi_product_id');
        $product = $product_id ? wc_get_product($product_id) : null;
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('beepi_options');
                do_settings_sections('beepi_options');
                ?>
                
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="beepi_product_id"><?php _e('Lookup Product', 'beepi'); ?></label>
                        </th>
                        <td>
                            <select name="beepi_product_id" id="beepi_product_id">
                                <option value=""><?php _e('-- Select a product --', 'beepi'); ?></option>
                                <?php
                                // Get virtual products
                                $args = array(
                                    'status' => 'publish',
                                    'type' => 'simple',
                                    'virtual' => true,
                                    'limit' => -1,
                                );
                                $products = wc_get_products($args);
                                
                                foreach ($products as $prod) {
                                    $selected = $product_id == $prod->get_id() ? 'selected' : '';
                                    echo '<option value="' . esc_attr($prod->get_id()) . '" ' . $selected . '>' . esc_html($prod->get_name()) . ' (' . $prod->get_price_html() . ')</option>';
                                }
                                ?>
                            </select>
                            <p class="description">
                                <?php _e('Select the WooCommerce product to use for vehicle lookups.', 'beepi'); ?>
                                <?php if ($product): ?>
                                    <br>
                                    <a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>" target="_blank">
                                        <?php _e('Edit product', 'beepi'); ?>
                                    </a>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2><?php _e('API Information', 'beepi'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php _e('Integration ID', 'beepi'); ?></th>
                    <td><code>2d5adb28-0e61-46aa-9fc0-8772b5206c7c</code></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Organization Number', 'beepi'); ?></th>
                    <td><code>998453240</code></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Scope', 'beepi'); ?></th>
                    <td><code>svv:kjoretoy/kjoretoyopplysninger</code></td>
                </tr>
            </table>
            
            <h3><?php _e('Test Token', 'beepi'); ?></h3>
            <p><?php _e('Click the button below to test your Maskinporten authentication.', 'beepi'); ?></p>
            <button class="button" id="beepi-test-token"><?php _e('Test Authentication', 'beepi'); ?></button>
            <div id="beepi-test-result" style="margin-top: 10px; padding: 10px; background: #f8f8f8; border: 1px solid #ddd; display: none;"></div>
            
            <h2><?php _e('System Diagnostics', 'beepi'); ?></h2>
            <p><?php _e('Run a comprehensive diagnostic test of the SVV API integration.', 'beepi'); ?></p>
            <button class="button button-primary" id="beepi-run-diagnostics">
                <?php _e('Run Diagnostics', 'beepi'); ?>
            </button>
            
            <div id="beepi-diagnostic-results" style="margin-top: 20px; display: none;">
                <h3><?php _e('Diagnostic Results', 'beepi'); ?></h3>
                <div class="diagnostic-content"></div>
            </div>
        </div>
        <?php
    }
}